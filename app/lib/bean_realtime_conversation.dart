import 'dart:async';
import 'dart:convert';

import 'package:flutter_webrtc/flutter_webrtc.dart';

import 'hermes_api_client.dart';

typedef RealtimeTranscriptCallback = void Function(String role, String text);
typedef RealtimeStatusCallback = void Function(String status);
typedef RealtimeRunQueuedCallback = void Function(int runId);

class BeanRealtimeConversation {
  BeanRealtimeConversation({
    required this.apiClient,
    this.onTranscript,
    this.onStatus,
    this.onRunQueued,
  });

  final HermesApiClient apiClient;
  final RealtimeTranscriptCallback? onTranscript;
  final RealtimeStatusCallback? onStatus;
  final RealtimeRunQueuedCallback? onRunQueued;

  RTCPeerConnection? _peerConnection;
  RTCDataChannel? _dataChannel;
  Completer<void>? _dataChannelOpen;
  MediaStream? _localStream;
  HermesRealtimeSession? _session;
  Timer? _responseDebounce;
  Timer? _toolFallbackTimer;
  final _transcriptDrafts = <String, String>{};
  final _processedCalls = <String>{};
  String _toolFallbackContent = '';
  String? _pendingUserContent;
  String? _pendingUserItemId;
  String _assistantDraft = '';
  String? _assistantItemId;
  bool _active = false;
  bool _conversationActive = false;
  bool _suppressNextAssistantPersist = false;
  bool _voiceOnlyAssistant = false;

  bool get active => _active;
  bool get conversationActive => _conversationActive;
  int? get localSessionId => _session?.session.id;

  Future<HermesSession> start({
    int? sessionId,
    int? workspaceId,
    Map<String, Object?> metadata = const {},
    bool microphoneEnabled = true,
  }) async {
    if (_active && _session != null) {
      if (!microphoneEnabled || _localStreamHasAudioTrack) {
        return _session!.session;
      }
      await stop();
    }

    onStatus?.call(
      microphoneEnabled ? 'Connecting Bean voice' : 'Starting realtime...',
    );

    final realtimeSession = await apiClient.startRealtimeSession(
      title: 'Realtime chat',
      runtimeMode: 'realtime',
      sessionId: sessionId,
      workspaceId: workspaceId,
      metadata: metadata,
    );
    if (realtimeSession.clientSecret.isEmpty) {
      throw StateError('Realtime session did not include a client secret.');
    }
    _session = realtimeSession;

    final pc = await createPeerConnection({
      'sdpSemantics': 'unified-plan',
      'iceServers': const <Map<String, Object>>[],
    });
    _peerConnection = pc;

    pc.onConnectionState = (state) {
      if (state == RTCPeerConnectionState.RTCPeerConnectionStateConnected) {
        onStatus?.call('Bean voice ready');
      }
      if (state == RTCPeerConnectionState.RTCPeerConnectionStateFailed ||
          state == RTCPeerConnectionState.RTCPeerConnectionStateClosed ||
          state == RTCPeerConnectionState.RTCPeerConnectionStateDisconnected) {
        unawaited(
          _logClientEvent('webrtc_connection_failure', {
            'connection_state': state.name,
          }),
        );
      }
    };
    pc.onIceConnectionState = (state) {
      if (state == RTCIceConnectionState.RTCIceConnectionStateFailed ||
          state == RTCIceConnectionState.RTCIceConnectionStateClosed ||
          state == RTCIceConnectionState.RTCIceConnectionStateDisconnected) {
        unawaited(
          _logClientEvent('ice_webrtc_connection_failure', {
            'ice_connection_state': state.name,
          }),
        );
      }
    };

    if (microphoneEnabled) {
      final stream = await navigator.mediaDevices.getUserMedia({
        'audio': {
          'echoCancellation': true,
          'noiseSuppression': true,
          'autoGainControl': true,
        },
        'video': false,
      });
      _localStream = stream;

      for (final track in stream.getAudioTracks()) {
        track.enabled = true;
        await pc.addTrack(track, stream);
      }
    }

    _dataChannelOpen = Completer<void>();
    final channel = await pc.createDataChannel(
      'oai-events',
      RTCDataChannelInit()..ordered = true,
    );
    _dataChannel = channel;
    channel.onMessage = _handleDataChannelMessage;
    channel.onDataChannelState = (state) {
      if (state == RTCDataChannelState.RTCDataChannelOpen) {
        if (!(_dataChannelOpen?.isCompleted ?? true)) {
          _dataChannelOpen?.complete();
        }
        onStatus?.call(microphoneEnabled ? 'Bean voice ready' : 'Ready');
        unawaited(refreshDashboardContext());
      }
      if (state == RTCDataChannelState.RTCDataChannelClosed) {
        unawaited(
          _logClientEvent('data_channel_close', {'ready_state': state.name}),
        );
      }
    };

    pc.onTrack = (RTCTrackEvent event) {
      if (event.track.kind == 'audio') {
        onStatus?.call("Bean's voice");
      }
    };

    final offer = await pc.createOffer({'offerToReceiveAudio': true});
    await pc.setLocalDescription(offer);
    final answer = await apiClient.createRealtimeCall(
      sessionId: realtimeSession.session.id,
      sdp: offer.sdp ?? '',
      voice: realtimeSession.voice,
      metadata: metadata,
    );
    await pc.setRemoteDescription(RTCSessionDescription(answer, 'answer'));
    if (channel.state == RTCDataChannelState.RTCDataChannelOpen &&
        !(_dataChannelOpen?.isCompleted ?? true)) {
      _dataChannelOpen?.complete();
    }

    _active = true;
    onStatus?.call(microphoneEnabled ? 'Bean voice ready' : 'Ready');
    return realtimeSession.session;
  }

  Future<void> sendText(String text, {bool audioResponse = false}) async {
    final trimmed = text.trim();
    if (trimmed.isEmpty) return;
    final channel = _dataChannel;
    if (channel == null) {
      throw StateError('Realtime data channel is not connected.');
    }
    await _waitForDataChannelOpen(channel);
    _conversationActive = true;
    _pendingUserContent = trimmed;
    _pendingUserItemId = 'typed-${DateTime.now().microsecondsSinceEpoch}';
    onTranscript?.call('user', trimmed);

    channel.send(
      RTCDataChannelMessage(
        jsonEncode({
          'type': 'conversation.item.create',
          'item': {
            'type': 'message',
            'role': 'user',
            'content': [
              {'type': 'input_text', 'text': trimmed},
            ],
          },
        }),
      ),
    );
    _sendResponseCreate(audioResponse: audioResponse, textResponse: true);
  }

  void setMicrophoneEnabled(bool enabled) {
    for (final track
        in _localStream?.getAudioTracks() ?? const <MediaStreamTrack>[]) {
      track.enabled = enabled;
    }
  }

  Future<void> interrupt({bool endConversation = true}) async {
    _responseDebounce?.cancel();
    _toolFallbackTimer?.cancel();
    _dataChannel?.send(
      RTCDataChannelMessage(jsonEncode({'type': 'response.cancel'})),
    );
    _dataChannel?.send(
      RTCDataChannelMessage(jsonEncode({'type': 'output_audio_buffer.clear'})),
    );
    _pendingUserContent = null;
    _pendingUserItemId = null;
    _assistantDraft = '';
    _assistantItemId = null;
    _toolFallbackContent = '';
    if (endConversation) _conversationActive = false;
    onStatus?.call(endConversation ? 'Bean voice ready' : 'Interrupted');
  }

  Future<bool> refreshDashboardContext() async {
    final sessionId = _session?.session.id;
    final channel = _dataChannel;
    if (sessionId == null ||
        channel?.state != RTCDataChannelState.RTCDataChannelOpen) {
      return false;
    }

    try {
      final context = await apiClient.realtimeDashboardContext(
        sessionId: sessionId,
      );
      final instructions = context['instructions']?.toString().trim() ?? '';
      if (instructions.isEmpty) return false;
      channel!.send(
        RTCDataChannelMessage(
          jsonEncode({
            'type': 'session.update',
            'session': {'instructions': instructions},
          }),
        ),
      );
      return true;
    } catch (error) {
      unawaited(
        _logClientEvent('dashboard_context_refresh_failure', {
          'message': error.toString(),
        }),
      );
      return false;
    }
  }

  Future<void> stop() async {
    _active = false;
    _conversationActive = false;
    _responseDebounce?.cancel();
    _toolFallbackTimer?.cancel();
    _dataChannel?.close();
    await _peerConnection?.close();
    _localStream?.getTracks().forEach((track) => track.stop());
    _dataChannel = null;
    _dataChannelOpen = null;
    _peerConnection = null;
    _localStream = null;
    _transcriptDrafts.clear();
    _processedCalls.clear();
    _pendingUserContent = null;
    _pendingUserItemId = null;
    _assistantDraft = '';
    _assistantItemId = null;
    _toolFallbackContent = '';
    _suppressNextAssistantPersist = false;
    _voiceOnlyAssistant = false;
    onStatus?.call('Ready');
  }

  bool get _localStreamHasAudioTrack =>
      (_localStream?.getAudioTracks() ?? const <MediaStreamTrack>[]).isNotEmpty;

  Future<void> _waitForDataChannelOpen(RTCDataChannel channel) async {
    if (channel.state == RTCDataChannelState.RTCDataChannelOpen) return;
    final open = _dataChannelOpen;
    if (open == null) {
      throw StateError('Realtime data channel is not ready.');
    }
    await open.future.timeout(
      const Duration(seconds: 5),
      onTimeout: () {
        throw TimeoutException('Realtime data channel did not open.');
      },
    );
  }

  void _handleDataChannelMessage(RTCDataChannelMessage message) {
    if (message.isBinary) return;
    final decoded = jsonDecode(message.text);
    if (decoded is! Map<String, Object?>) return;
    final type = decoded['type']?.toString() ?? '';

    switch (type) {
      case 'input_audio_buffer.speech_started':
        if (_conversationActive) onStatus?.call('listening');
        return;
      case 'input_audio_buffer.speech_stopped':
        if (_conversationActive) onStatus?.call('thinking');
        return;
      case 'conversation.item.input_audio_transcription.delta':
        _handleTranscriptDelta(decoded);
        return;
      case 'conversation.item.input_audio_transcription.segment':
        _handleTranscriptSegment(decoded);
        return;
      case 'conversation.item.input_audio_transcription.completed':
        _handleUserTranscript(decoded);
        return;
      case 'response.created':
        onStatus?.call("Bean's voice");
        return;
      case 'response.audio_transcript.delta':
      case 'response.output_text.delta':
        _appendAssistantDelta(decoded);
        return;
      case 'response.audio_transcript.done':
      case 'response.output_text.done':
        _finishAssistantTranscript(decoded);
        return;
      case 'response.function_call_arguments.done':
        unawaited(
          _processFunctionCall(
            decoded['name']?.toString(),
            decoded['call_id']?.toString(),
            decoded['arguments'],
          ),
        );
        return;
      case 'response.done':
        _processResponseDone(decoded);
        return;
      case 'error':
        onStatus?.call(
          _expectErrorMessage(decoded) ?? "Bean voice couldn't connect",
        );
        unawaited(_logClientEvent('realtime_error', {'payload': decoded}));
        return;
    }
  }

  void _handleUserTranscript(Map<String, Object?> payload) {
    final raw = payload['transcript']?.toString().trim() ?? '';
    if (raw.isEmpty) return;
    final key = _transcriptDraftKey(payload);
    if (key.isNotEmpty) _transcriptDrafts.remove(key);
    if (_transcriptLooksSynthetic(raw)) {
      onStatus?.call('Bean voice ready');
      return;
    }

    final command = _commandAfterWakePhrase(raw);
    final isWakeTurn = command != null;
    if (!isWakeTurn && !_conversationActive) {
      onStatus?.call('Bean voice ready');
      return;
    }
    if (_conversationEndRequested(raw) ||
        (isWakeTurn && _voiceCancelRequested(command))) {
      unawaited(interrupt());
      onStatus?.call('cancelled');
      return;
    }
    if (isWakeTurn) _conversationActive = true;

    final content = (isWakeTurn ? command : raw).trim();
    if (content.isEmpty) {
      onStatus?.call('listening');
      return;
    }

    final appendToPending =
        _responseDebounce?.isActive == true &&
        _pendingUserContent != null &&
        !isWakeTurn;
    if (appendToPending) {
      _pendingUserContent = '$_pendingUserContent $content'
          .replaceAll(RegExp(r'\s+'), ' ')
          .trim();
    } else {
      _pendingUserContent = content;
      _pendingUserItemId =
          payload['item_id']?.toString() ??
          'audio-${DateTime.now().microsecondsSinceEpoch}';
    }
    onTranscript?.call('user', _pendingUserContent!);
    _scheduleResponseCreate();
  }

  void _handleTranscriptDelta(Map<String, Object?> payload) {
    final delta = payload['delta']?.toString().trim() ?? '';
    if (delta.isEmpty) return;
    final key = _transcriptDraftKey(payload);
    final previous = key.isEmpty ? '' : (_transcriptDrafts[key] ?? '');
    final draft = _mergeTranscriptDelta(previous, delta);
    if (key.isNotEmpty) _transcriptDrafts[key] = draft;
    _showHeardTranscript(draft);
  }

  void _handleTranscriptSegment(Map<String, Object?> payload) {
    final text = payload['text']?.toString().trim() ?? '';
    if (text.isNotEmpty) _showHeardTranscript(text);
  }

  void _showHeardTranscript(String text) {
    final raw = text.trim();
    if (raw.isEmpty || _transcriptLooksSynthetic(raw)) return;
    final command = _commandAfterWakePhrase(raw);
    if (!_conversationActive && command == null) return;
    final preview = raw.length > 44 ? '${raw.substring(0, 41)}...' : raw;
    onStatus?.call('Heard: "$preview"');
  }

  void _scheduleResponseCreate() {
    _responseDebounce?.cancel();
    _clearToolFallback();
    onStatus?.call('listening');
    _responseDebounce = Timer(const Duration(milliseconds: 1200), () {
      final content = _pendingUserContent?.trim() ?? '';
      if (!_active || !_conversationActive || content.isEmpty) return;
      _armToolFallback(content);
      onStatus?.call('thinking');
      _sendResponseCreate(audioResponse: true, textResponse: true);
    });
  }

  void _appendAssistantDelta(Map<String, Object?> payload) {
    final delta = payload['delta']?.toString() ?? '';
    if (delta.isEmpty) return;
    final id =
        payload['response_id']?.toString() ??
        payload['item_id']?.toString() ??
        _assistantItemId ??
        'assistant-${DateTime.now().microsecondsSinceEpoch}';
    if (_assistantItemId != id) {
      _assistantItemId = id;
      _assistantDraft = '';
    }
    _assistantDraft += delta;
    onStatus?.call("Bean's voice");
  }

  void _finishAssistantTranscript(Map<String, Object?> payload) {
    final text =
        payload['transcript']?.toString().trim() ??
        payload['text']?.toString().trim() ??
        '';
    if (text.isEmpty) return;
    _assistantItemId =
        payload['response_id']?.toString() ?? payload['item_id']?.toString();
    _assistantDraft = text;
    if (!_voiceOnlyAssistant) {
      onTranscript?.call('assistant', text);
    }
  }

  void _processResponseDone(Map<String, Object?> payload) {
    final output = _responseOutput(payload);
    var hasFunctionCall = false;
    for (final item in output) {
      if (item['type']?.toString() == 'function_call') {
        hasFunctionCall = true;
        unawaited(
          _processFunctionCall(
            item['name']?.toString(),
            item['call_id']?.toString(),
            item['arguments'],
          ),
        );
      }
    }
    if (!hasFunctionCall && _toolFallbackContent.isEmpty) {
      unawaited(_persistRealtimeTurn());
    } else {
      _pendingUserContent = null;
      _pendingUserItemId = null;
    }
    if (_conversationActive) {
      onStatus?.call('listening');
    } else {
      onStatus?.call('Bean voice ready');
    }
  }

  Future<void> _processFunctionCall(
    String? name,
    String? callId,
    Object? rawArguments,
  ) async {
    _clearToolFallback();
    final callKey = callId ?? '$name-$rawArguments';
    if (name == null || name.isEmpty || _processedCalls.contains(callKey)) {
      return;
    }
    _processedCalls.add(callKey);
    final args = _decodeArguments(rawArguments);
    onStatus?.call('working in background');
    try {
      final result = await apiClient.submitRealtimeToolCall(
        sessionId: _session!.session.id,
        toolName: name,
        callId: callId,
        arguments: {
          ...args,
          'client_context': {
            if (args['client_context'] is Map)
              ...Map<String, Object?>.from(args['client_context'] as Map),
            ..._clientTemporalContext(),
          },
        },
      );
      _sendFunctionOutput(callId, result);
      final runId = result['run_id'];
      if (runId is int) {
        onRunQueued?.call(runId);
        unawaited(_watchRun(runId));
      }
    } catch (error) {
      _sendFunctionOutput(callId, {
        'ok': false,
        'message': 'Bean could not start that background work.',
      });
      onStatus?.call('work failed');
      unawaited(
        _logClientEvent('realtime_tool_call_failure', {
          'name': name,
          'message': error.toString(),
        }),
      );
    }
  }

  void _armToolFallback(String content) {
    _clearToolFallback();
    if (!_voiceCommandNeedsAgentWork(content)) return;
    _toolFallbackContent = content;
    _toolFallbackTimer = Timer(const Duration(milliseconds: 2600), () {
      final pending = _toolFallbackContent;
      _toolFallbackContent = '';
      if (pending.trim().isEmpty || !_active) return;
      unawaited(_queueFallbackWork(pending));
    });
  }

  void _clearToolFallback() {
    _toolFallbackTimer?.cancel();
    _toolFallbackTimer = null;
    _toolFallbackContent = '';
  }

  Future<void> _queueFallbackWork(String content) async {
    final sessionId = _session?.session.id;
    if (sessionId == null) return;
    onStatus?.call('working in background');
    try {
      final result = await apiClient.submitRealtimeToolCall(
        sessionId: sessionId,
        toolName: 'queue_bean_work',
        callId: 'client_fallback_${DateTime.now().microsecondsSinceEpoch}',
        arguments: {
          'content': content,
          'client_context': _clientTemporalContext(),
        },
      );
      final runId = result['run_id'];
      if (runId is int) {
        onRunQueued?.call(runId);
        unawaited(_watchRun(runId));
      }
    } catch (error) {
      onStatus?.call('work failed');
      unawaited(
        _logClientEvent('realtime_tool_fallback_failure', {
          'message': error.toString(),
        }),
      );
    }
  }

  Future<void> _watchRun(int runId, [int attempt = 0]) async {
    await Future<void>.delayed(
      attempt == 0
          ? const Duration(milliseconds: 900)
          : Duration(milliseconds: (1800 + (attempt * 450)).clamp(1800, 4500)),
    );
    if (!_active) return;
    try {
      final run = await apiClient.getAssistantRun(runId);
      if (run.status == 'queued' || run.status == 'running') {
        if (attempt < 45) unawaited(_watchRun(runId, attempt + 1));
        return;
      }
      if (run.status == 'completed') {
        final content = run.assistantMessage?.content?.trim() ?? '';
        if (content.isEmpty) return;
        unawaited(refreshDashboardContext());
        _deliverBackgroundResult(content);
      }
    } catch (_) {
      if (attempt < 8) unawaited(_watchRun(runId, attempt + 1));
    }
  }

  void _deliverBackgroundResult(String content) {
    if (!_conversationActive) return;
    final channel = _dataChannel;
    if (channel?.state != RTCDataChannelState.RTCDataChannelOpen) return;
    final text = _speechTextFromAssistant(content);
    if (text.isEmpty) return;
    _suppressNextAssistantPersist = true;
    _voiceOnlyAssistant = true;
    channel!.send(
      RTCDataChannelMessage(
        jsonEncode({
          'type': 'conversation.item.create',
          'item': {
            'type': 'message',
            'role': 'user',
            'content': [
              {
                'type': 'input_text',
                'text':
                    'Background work for my previous request is complete. Tell me this result naturally and concisely, without mentioning background work: $text',
              },
            ],
          },
        }),
      ),
    );
    _sendResponseCreate(audioResponse: true, textResponse: true);
  }

  void _sendFunctionOutput(String? callId, Map<String, Object?> result) {
    final channel = _dataChannel;
    if (callId == null ||
        callId.isEmpty ||
        channel?.state != RTCDataChannelState.RTCDataChannelOpen) {
      return;
    }
    channel!.send(
      RTCDataChannelMessage(
        jsonEncode({
          'type': 'conversation.item.create',
          'item': {
            'type': 'function_call_output',
            'call_id': callId,
            'output': jsonEncode(result),
          },
        }),
      ),
    );
    _sendResponseCreate(audioResponse: true, textResponse: true);
  }

  bool _sendResponseCreate({
    bool audioResponse = true,
    bool textResponse = true,
  }) {
    final channel = _dataChannel;
    if (channel?.state != RTCDataChannelState.RTCDataChannelOpen) return false;
    channel!.send(
      RTCDataChannelMessage(
        jsonEncode({
          'type': 'response.create',
          'response': {
            'modalities': [
              if (textResponse) 'text',
              if (audioResponse) 'audio',
            ],
          },
        }),
      ),
    );
    return true;
  }

  Future<void> _persistRealtimeTurn() async {
    final sessionId = _session?.session.id;
    final userContent = _pendingUserContent?.trim() ?? '';
    final userItemId = _pendingUserItemId;
    final assistantContent = _assistantDraft.trim();
    final assistantItemId = _assistantItemId;
    final suppressAssistant = _suppressNextAssistantPersist;
    _pendingUserContent = null;
    _pendingUserItemId = null;
    _assistantDraft = '';
    _assistantItemId = null;
    _suppressNextAssistantPersist = false;
    _voiceOnlyAssistant = false;
    if (sessionId == null) return;
    try {
      if (userContent.isNotEmpty) {
        await apiClient.persistRealtimeMessage(
          sessionId: sessionId,
          role: 'user',
          content: userContent,
          metadata: {
            'realtime': {'item_id': userItemId},
          },
        );
      }
      if (assistantContent.isNotEmpty && !suppressAssistant) {
        await apiClient.persistRealtimeMessage(
          sessionId: sessionId,
          role: 'assistant',
          content: assistantContent,
          metadata: {
            'realtime': {'item_id': assistantItemId},
          },
        );
      }
    } catch (_) {
      // Local realtime messages stay visible even if persistence races app state.
    }
  }

  Future<void> _logClientEvent(
    String eventType,
    Map<String, Object?> details,
  ) async {
    try {
      await apiClient.logRealtimeClientEvent(
        eventType: eventType,
        sessionId: _session?.session.id,
        phase: null,
        message: null,
        details: details,
      );
    } catch (_) {
      // Logging must not interrupt voice.
    }
  }
}

List<Map<String, Object?>> _responseOutput(Map<String, Object?> payload) {
  final response = payload['response'];
  if (response is! Map) return const [];
  final output = response['output'];
  if (output is! List) return const [];
  return output.whereType<Map>().map((item) {
    return Map<String, Object?>.from(item);
  }).toList();
}

Map<String, Object?> _decodeArguments(Object? rawArguments) {
  try {
    final decoded = rawArguments is String
        ? jsonDecode(rawArguments.isEmpty ? '{}' : rawArguments)
        : rawArguments;
    if (decoded is Map<String, Object?>) return decoded;
    if (decoded is Map) return Map<String, Object?>.from(decoded);
  } catch (_) {}
  return const {};
}

String? _expectErrorMessage(Map<String, Object?> payload) {
  final error = payload['error'];
  if (error is Map && error['message'] != null) {
    return error['message'].toString();
  }
  return null;
}

String _transcriptDraftKey(Map<String, Object?> payload) {
  final itemId = payload['item_id']?.toString() ?? '';
  if (itemId.isEmpty) return '';
  final contentIndex = int.tryParse(payload['content_index']?.toString() ?? '');
  return '$itemId:${contentIndex ?? 0}';
}

String _mergeTranscriptDelta(String previous, String delta) {
  final prior = previous.trim();
  final next = delta.trim();
  if (prior.isEmpty) return next;
  if (next.isEmpty) return prior;
  if (next.toLowerCase().startsWith(prior.toLowerCase())) return next;
  if (prior.toLowerCase().endsWith(next.toLowerCase())) return prior;
  return '$prior${RegExp(r'^\s|[,.!?;:]').hasMatch(next) ? '' : ' '}$next'
      .replaceAll(RegExp(r'\s+'), ' ')
      .trim();
}

String? _commandAfterWakePhrase(String transcript) {
  final text = transcript.replaceAll(RegExp(r'\s+'), ' ').trim();
  if (text.isEmpty) return null;
  final wakeStarter = r'(?:hey|hay|hi|hello|okay|ok|kay)';
  final beanVariant =
      r'(?:bean|beans|been|ben|beam|beem|bein|being|bin|bing|bien|bain|bane|dean|deen)';
  final compactBeanVariant =
      r'b(?:ean|eans|een|en|eam|eem|ein|eing|in|ing|ien|ain|ane)';
  final pattern = RegExp(
    [
      r'(?:^|\s)'
          '$wakeStarter'
          r'\s*,?\s*'
          '$beanVariant'
          r'\b[\s,.:;!?-]*',
      r'(?:^|\s)'
          '$wakeStarter'
          r'\s*'
          '$compactBeanVariant'
          r'\b[\s,.:;!?-]*',
      r'(?:^|\s)'
          '$wakeStarter'
          r'\s*,?\s*(?:b|bee)\b[\s,.:;!?-]*',
      r'^\s*a\s+bean\b[\s,.:;!?-]*',
    ].join('|'),
    caseSensitive: false,
  );
  final match = pattern.firstMatch(text);
  if (match == null) return null;
  return text.substring(match.end).replaceAll(RegExp(r'\s+'), ' ').trim();
}

String _normalizedVoiceCommand(String transcript) => transcript
    .toLowerCase()
    .replaceAll(RegExp(r"[^a-z0-9\s']"), ' ')
    .replaceAll(RegExp(r'\s+'), ' ')
    .replaceFirst(RegExp(r'^(hey\s+bean|heybean|bean)\s+'), '')
    .replaceFirst(RegExp(r'\s+bean$'), '')
    .trim();

bool _voiceCancelRequested(String transcript) {
  final command = _normalizedVoiceCommand(transcript);
  if (command.isEmpty) return false;
  if (RegExp(
    r"^(?:stop|stop it|stop talking|be quiet|quiet|cancel|cancel that|cancel this|cancel response|cancel request|never\s*mind|nevermind|forget it|that's all|that is all|stop listening|we'?re done|we are done)$",
  ).hasMatch(command)) {
    return true;
  }
  return RegExp(
    r"\b(?:stop talking|be quiet|never\s*mind|nevermind|forget it|stop listening)\b",
  ).hasMatch(command);
}

bool _conversationEndRequested(String transcript) =>
    _voiceCancelRequested(transcript) ||
    RegExp(
      r"\b(?:thanks|thank you|that'?s all|stop listening|cancel)\s+(?:bean|been|beam|being)\b",
      caseSensitive: false,
    ).hasMatch(transcript) ||
    RegExp(
      r"\b(?:thanks|thank you),?\s*(?:that'?s all|we'?re done)\b",
      caseSensitive: false,
    ).hasMatch(transcript);

bool _voiceCommandNeedsAgentWork(String transcript) {
  final command = _normalizedVoiceCommand(transcript);
  if (command.isEmpty) return false;
  if (RegExp(
    r'\b(?:calendar|calendars|event|events|task|tasks|todo|to do|reminder|reminders|agenda|approval|approvals|workspace|workspaces|google calendar)\b',
  ).hasMatch(command)) {
    return true;
  }
  if (RegExp(
    r'\b(?:flight|flights|airfare|airfares|ticket|tickets|hotel|hotels|rental car|rentals|reservation|reservations|booking|bookings|price|prices|cheapest|available|availability|weather|forecast|news|traffic|stock|stocks|sports|score|scores)\b',
  ).hasMatch(command)) {
    return true;
  }
  if (RegExp(
        r'\b(?:today|tonight|tomorrow|current|currently|latest|now|right now|near me|nearby|local)\b',
      ).hasMatch(command) &&
      RegExp(
        r'\b(?:open|opens|closed|closes|close|closing|hours|hour|available|availability|price|prices|cost|costs|status|delay|delays)\b',
      ).hasMatch(command)) {
    return true;
  }
  if (RegExp(
        r'\b(?:trash|garbage|recycling|recycle|pickup|pick up)\b',
      ).hasMatch(command) &&
      RegExp(
        r'\b(?:when|what|which|supposed|take out|put out|do i|should i)\b',
      ).hasMatch(command)) {
    return true;
  }
  if (RegExp(
    r'\b(?:add|create|put|move|reschedule|schedule|update|change|delete|remove|cancel|complete|finish|mark|remind|remember)\b',
  ).hasMatch(command)) {
    return true;
  }
  if (RegExp(r'\b(?:plan|organize|prioritize)\b').hasMatch(command) &&
      RegExp(
        r'\b(?:day|today|tomorrow|week|schedule|work|tasks|calendar|morning|afternoon|evening)\b',
      ).hasMatch(command)) {
    return true;
  }
  return RegExp(
    r"\b(?:what do i have|what have i got|do i have anything|anything on|what'?s next|whats next|what is next|next up)\b",
  ).hasMatch(command);
}

bool _transcriptLooksSynthetic(String transcript) {
  final normalized = transcript
      .toLowerCase()
      .replaceAll(RegExp(r'[^a-z0-9\s]'), ' ')
      .replaceAll(RegExp(r'\s+'), ' ')
      .trim();
  if (normalized.isEmpty) return true;
  if (normalized == 'bean heybean can you hear me calendar tasks reminders') {
    return true;
  }
  if (normalized ==
      'hey bean bean heybean can you hear me calendar tasks reminders') {
    return true;
  }
  return RegExp(
    r'^(?:bean\s+)?heybean\s+can you hear me calendar tasks reminders$',
  ).hasMatch(normalized);
}

Map<String, Object?> _clientTemporalContext() {
  final now = DateTime.now();
  final offset = now.timeZoneOffset;
  final offsetMinutes = offset.inMinutes;
  final sign = offsetMinutes < 0 ? '-' : '+';
  final absoluteMinutes = offsetMinutes.abs();
  final offsetLabel =
      '$sign${(absoluteMinutes ~/ 60).toString().padLeft(2, '0')}:${(absoluteMinutes % 60).toString().padLeft(2, '0')}';
  return {
    'current_local_time': now.toIso8601String(),
    'current_utc_time': now.toUtc().toIso8601String(),
    'timezone_name': now.timeZoneName,
    'timezone_offset': offsetLabel,
    'timezone_offset_minutes': offsetMinutes,
  };
}

String _speechTextFromAssistant(String content) => content
    .replaceAll(RegExp(r'```[\s\S]*?```'), ' ')
    .replaceAllMapped(RegExp(r'\[([^\]]+)\]\([^)]+\)'), (match) {
      return match.group(1) ?? '';
    })
    .replaceAll(RegExp(r'[#*_>`]'), '')
    .replaceAll(RegExp(r'\s+'), ' ')
    .trim();
