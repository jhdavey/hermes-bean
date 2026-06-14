import 'dart:async';
import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter_webrtc/flutter_webrtc.dart';

import 'hermes_api_client.dart';

typedef RealtimeTranscriptCallback = void Function(String role, String text);
typedef RealtimeStatusCallback = void Function(String status);
typedef RealtimeRunQueuedCallback = void Function(int runId);

class _RealtimeFunctionCall {
  const _RealtimeFunctionCall({
    required this.name,
    required this.callKey,
    this.callId,
    this.arguments,
  });

  final String name;
  final String callKey;
  final String? callId;
  final Object? arguments;
}

class _FinalVoice {
  const _FinalVoice({required this.text, this.suppressFinal = false});

  final String text;
  final bool suppressFinal;
}

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
  Timer? _releaseTranscriptGraceTimer;
  final _backgroundProgressTimers = <Timer>{};
  final _transcriptDrafts = <String, String>{};
  final _processedCalls = <String>{};
  final _pendingFunctionCalls = <_RealtimeFunctionCall>[];
  final _spokenSegments = <String>[];
  String _toolFallbackContent = '';
  String? _currentUserContent;
  String? _pendingUserContent;
  String? _pendingUserItemId;
  String _assistantDraft = '';
  String? _assistantItemId;
  String _lastAssistantText = '';
  String _backgroundUserContent = '';
  String _backgroundQuickReplyText = '';
  bool _active = false;
  bool _conversationActive = false;
  bool _voiceCaptureActive = false;
  bool _voiceReleasePending = false;
  bool _backgroundWorkActive = false;
  bool _suppressNextAssistantPersist = false;
  bool _voiceOnlyAssistant = false;
  bool _ignoreNextFunctionCalls = false;

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
      microphoneEnabled ? 'Connecting Bean voice' : 'Bean is waking up',
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

  void beginVoiceCapture() {
    _conversationActive = true;
    _voiceCaptureActive = true;
    _voiceReleasePending = false;
    _releaseTranscriptGraceTimer?.cancel();
    setMicrophoneEnabled(true);
    onStatus?.call('listening');
  }

  void endVoiceCapture() {
    if (!_voiceCaptureActive && !_voiceReleasePending) return;
    _voiceCaptureActive = false;
    _voiceReleasePending = true;
    setMicrophoneEnabled(false);
    final content = _pendingUserContent?.trim() ?? '';
    if (content.isNotEmpty) {
      _scheduleResponseCreate(delay: const Duration(milliseconds: 180));
      return;
    }
    onStatus?.call('thinking');
    _releaseTranscriptGraceTimer?.cancel();
    _releaseTranscriptGraceTimer = Timer(
      const Duration(milliseconds: 1400),
      () {
        _releaseTranscriptGraceTimer = null;
        if (!_voiceReleasePending) return;
        final lateContent = _pendingUserContent?.trim() ?? '';
        if (lateContent.isNotEmpty) {
          _scheduleResponseCreate(delay: const Duration(milliseconds: 120));
          return;
        }
        _voiceReleasePending = false;
        onStatus?.call(_conversationActive ? 'listening' : 'Bean voice ready');
      },
    );
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
    _releaseTranscriptGraceTimer?.cancel();
    _clearBackgroundProgressUpdates();
    _dataChannel?.send(
      RTCDataChannelMessage(jsonEncode({'type': 'response.cancel'})),
    );
    _dataChannel?.send(
      RTCDataChannelMessage(jsonEncode({'type': 'output_audio_buffer.clear'})),
    );
    _pendingUserContent = null;
    _pendingUserItemId = null;
    _currentUserContent = null;
    _assistantDraft = '';
    _assistantItemId = null;
    _toolFallbackContent = '';
    _pendingFunctionCalls.clear();
    _backgroundWorkActive = false;
    _voiceCaptureActive = false;
    _voiceReleasePending = false;
    _ignoreNextFunctionCalls = false;
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
          jsonEncode(_realtimeSessionUpdatePayload(instructions)),
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
    _releaseTranscriptGraceTimer?.cancel();
    _clearBackgroundProgressUpdates();
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
    _currentUserContent = null;
    _assistantDraft = '';
    _assistantItemId = null;
    _toolFallbackContent = '';
    _pendingFunctionCalls.clear();
    _spokenSegments.clear();
    _lastAssistantText = '';
    _backgroundWorkActive = false;
    _voiceCaptureActive = false;
    _voiceReleasePending = false;
    _suppressNextAssistantPersist = false;
    _voiceOnlyAssistant = false;
    _ignoreNextFunctionCalls = false;
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
        _queueFunctionCall(
          decoded['name']?.toString(),
          decoded['call_id']?.toString(),
          decoded['arguments'],
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
    if (_voiceCaptureActive || _voiceReleasePending) _conversationActive = true;

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
    _currentUserContent = _pendingUserContent;
    onTranscript?.call('user', _pendingUserContent!);
    if (_voiceCaptureActive) {
      onStatus?.call('listening');
      return;
    }
    _scheduleResponseCreate(
      delay: _voiceReleasePending
          ? const Duration(milliseconds: 180)
          : const Duration(milliseconds: 1200),
    );
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

  void _scheduleResponseCreate({
    Duration delay = const Duration(milliseconds: 1200),
  }) {
    _responseDebounce?.cancel();
    _clearToolFallback();
    onStatus?.call('listening');
    _responseDebounce = Timer(delay, () {
      final content = _pendingUserContent?.trim() ?? '';
      if (!_active || !_conversationActive || content.isEmpty) return;
      _voiceReleasePending = false;
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
    _lastAssistantText = text;
    _recordSpokenSegment(text);
    if (!_voiceOnlyAssistant) {
      onTranscript?.call('assistant', text);
    }
  }

  void _processResponseDone(Map<String, Object?> payload) {
    final output = _responseOutput(payload);
    final responseAssistantText =
        (_assistantDraft.trim().isNotEmpty
                ? _assistantDraft
                : _realtimeTextFromResponseOutput(output))
            .trim();
    final functionCalls = _mergeFunctionCalls([
      ..._pendingFunctionCalls,
      ...output
          .where((item) => item['type']?.toString() == 'function_call')
          .map(
            (item) => _RealtimeFunctionCall(
              name: item['name']?.toString() ?? '',
              callId: item['call_id']?.toString(),
              callKey:
                  item['call_id']?.toString() ??
                  '${item['name']}-${item['arguments']}',
              arguments: item['arguments'],
            ),
          ),
    ]);
    _pendingFunctionCalls.clear();

    final hasFunctionCall = functionCalls.isNotEmpty;
    final assistantAnswered = responseAssistantText.isNotEmpty;
    final pendingUserContent =
        (_pendingUserContent ?? _currentUserContent ?? '').trim();
    final queueOnly =
        hasFunctionCall &&
        functionCalls.every((call) => call.name == 'queue_bean_work');
    final backgroundQueueAllowed = _realtimeSpokenAnswerAllowsBackgroundQueue(
      pendingUserContent,
      responseAssistantText,
    );

    unawaited(
      _logClientEvent('flutter_realtime_response_done', {
        'user_content': pendingUserContent,
        'assistant_text': responseAssistantText,
        'assistant_answered': assistantAnswered,
        'background_queue_allowed': backgroundQueueAllowed,
        'function_calls': functionCalls
            .map(
              (call) => {
                'name': call.name,
                'call_id': call.callId,
                'arguments': call.arguments?.toString(),
              },
            )
            .toList(),
      }),
    );

    if (_ignoreNextFunctionCalls) {
      _ignoreNextFunctionCalls = false;
      if (hasFunctionCall) {
        for (final call in functionCalls) {
          _sendFunctionOutput(call.callId, {
            'ok': true,
            'skipped': true,
            'message': 'This speech-only update should not call tools.',
          }, createResponse: false);
        }
      }
      unawaited(_persistRealtimeTurn());
      _finishRealtimeTurnStatus();
      return;
    }

    if (hasFunctionCall && pendingUserContent.isEmpty) {
      _clearToolFallback();
      for (final call in functionCalls) {
        _sendFunctionOutput(call.callId, {
          'ok': true,
          'skipped': true,
          'message': 'No active user turn is available for this tool call.',
        }, createResponse: false);
      }
      _assistantDraft = '';
      _assistantItemId = null;
      _suppressNextAssistantPersist = false;
      _voiceOnlyAssistant = false;
      _finishRealtimeTurnStatus();
      return;
    }

    if (assistantAnswered && queueOnly && !backgroundQueueAllowed) {
      _clearToolFallback();
      for (final call in functionCalls) {
        _sendFunctionOutput(call.callId, {
          'ok': true,
          'skipped': true,
          'message': 'Bean already answered this turn directly.',
        }, createResponse: false);
      }
      unawaited(_persistRealtimeTurn());
      _finishRealtimeTurnStatus();
      return;
    }

    if (hasFunctionCall) {
      final preservePendingUserForDeferredQueue =
          queueOnly && responseAssistantText.isEmpty;
      for (final call in functionCalls) {
        unawaited(
          _processFunctionCall(
            call.name,
            call.callId,
            call.arguments,
            assistantText: responseAssistantText,
            userContent: pendingUserContent,
          ),
        );
      }
      if (!preservePendingUserForDeferredQueue) {
        _pendingUserContent = null;
        _pendingUserItemId = null;
        _currentUserContent = null;
      }
      return;
    }

    if (!hasFunctionCall && assistantAnswered) {
      _clearToolFallback();
      if (backgroundQueueAllowed &&
          _voiceCommandRequiresBackgroundWork(pendingUserContent)) {
        unawaited(_persistRealtimeTurn());
        unawaited(
          _queueFallbackWork(pendingUserContent, responseAssistantText),
        );
        return;
      }
      unawaited(_persistRealtimeTurn());
    } else if (!assistantAnswered &&
        _toolFallbackContent.isEmpty &&
        _voiceCommandNeedsAgentWork(pendingUserContent)) {
      _pendingUserContent = null;
      _pendingUserItemId = null;
      _currentUserContent = null;
      unawaited(_queueFallbackWork(pendingUserContent));
      return;
    } else if (_toolFallbackContent.isEmpty) {
      unawaited(_persistRealtimeTurn());
    } else {
      _pendingUserContent = null;
      _pendingUserItemId = null;
      _currentUserContent = null;
    }

    _finishRealtimeTurnStatus();
  }

  void _queueFunctionCall(String? name, String? callId, Object? arguments) {
    final normalizedName = name?.trim() ?? '';
    if (normalizedName.isEmpty) return;
    final callKey = callId ?? '$normalizedName-$arguments';
    final existingIndex = _pendingFunctionCalls.indexWhere(
      (call) => call.callKey == callKey,
    );
    final call = _RealtimeFunctionCall(
      name: normalizedName,
      callId: callId,
      callKey: callKey,
      arguments: arguments,
    );
    if (existingIndex >= 0) {
      _pendingFunctionCalls[existingIndex] = call;
      return;
    }
    _pendingFunctionCalls.add(call);
  }

  List<_RealtimeFunctionCall> _mergeFunctionCalls(
    List<_RealtimeFunctionCall> calls,
  ) {
    final byKey = <String, _RealtimeFunctionCall>{};
    for (final call in calls) {
      if (call.name.isEmpty) continue;
      byKey[call.callKey] = call;
    }
    return byKey.values.toList();
  }

  Future<void> _processFunctionCall(
    String? name,
    String? callId,
    Object? rawArguments, {
    String assistantText = '',
    String userContent = '',
    bool deferForTranscript = true,
  }) async {
    _clearToolFallback();
    final callKey = callId ?? '$name-$rawArguments';
    if (name == null || name.isEmpty || _processedCalls.contains(callKey)) {
      return;
    }
    final quickReplyText =
        (assistantText.isNotEmpty
                ? assistantText
                : (_assistantDraft.trim().isNotEmpty
                      ? _assistantDraft
                      : _lastAssistantText))
            .trim();
    final activeUserContent =
        (userContent.isNotEmpty
                ? userContent
                : (_pendingUserContent ?? _currentUserContent ?? ''))
            .trim();

    if (name == 'queue_bean_work' && activeUserContent.isEmpty) {
      _processedCalls.add(callKey);
      _sendFunctionOutput(callId, {
        'ok': true,
        'skipped': true,
        'message': 'No active user turn is available for this background work.',
      }, createResponse: false);
      _finishRealtimeTurnStatus();
      return;
    }

    if (name == 'queue_bean_work' &&
        quickReplyText.isEmpty &&
        deferForTranscript) {
      Timer(const Duration(milliseconds: 650), () {
        unawaited(
          _processFunctionCall(
            name,
            callId,
            rawArguments,
            assistantText: _assistantDraft.trim().isNotEmpty
                ? _assistantDraft
                : _lastAssistantText,
            userContent: activeUserContent,
            deferForTranscript: false,
          ),
        );
      });
      return;
    }

    _processedCalls.add(callKey);
    final args = _decodeArguments(rawArguments);

    if (name == 'queue_bean_work' &&
        quickReplyText.isNotEmpty &&
        !_realtimeSpokenAnswerAllowsBackgroundQueue(
          activeUserContent,
          quickReplyText,
        )) {
      _sendFunctionOutput(callId, {
        'ok': true,
        'skipped': true,
        'message': 'Bean already answered this turn directly.',
      }, createResponse: false);
      unawaited(_persistRealtimeTurn());
      _finishRealtimeTurnStatus();
      return;
    }

    if (name == 'queue_bean_work') {
      _setBackgroundWorkActive(
        true,
        userContent: activeUserContent,
        quickReplyText: quickReplyText,
      );
    }
    _showWorkingStatusWhenReady();
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
      _sendFunctionOutput(
        callId,
        result,
        createResponse: name != 'queue_bean_work' || result['run_id'] == null,
      );
      final runId = result['run_id'];
      if (runId is int) {
        _pendingUserContent = null;
        _pendingUserItemId = null;
        _currentUserContent = null;
        onRunQueued?.call(runId);
        unawaited(
          _watchRun(
            runId,
            userContent: activeUserContent,
            quickReplyText: quickReplyText,
          ),
        );
      } else if (name == 'queue_bean_work') {
        _setBackgroundWorkActive(false);
      }
    } catch (error) {
      if (name == 'queue_bean_work') {
        _setBackgroundWorkActive(false);
      }
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
    if (!_voiceCommandRequiresBackgroundWork(content)) return;
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

  Future<void> _queueFallbackWork(
    String content, [
    String quickReplyText = '',
  ]) async {
    final sessionId = _session?.session.id;
    if (sessionId == null) return;
    final cleanQuickReply = quickReplyText.trim();
    if (cleanQuickReply.isNotEmpty &&
        !_realtimeSpokenAnswerAllowsBackgroundQueue(content, cleanQuickReply)) {
      unawaited(_persistRealtimeTurn());
      _finishRealtimeTurnStatus();
      return;
    }
    _setBackgroundWorkActive(
      true,
      userContent: content,
      quickReplyText: cleanQuickReply,
    );
    _showWorkingStatusWhenReady();
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
        unawaited(
          _watchRun(
            runId,
            userContent: content,
            quickReplyText: cleanQuickReply,
          ),
        );
      } else {
        _setBackgroundWorkActive(false);
      }
    } catch (error) {
      _setBackgroundWorkActive(false);
      onStatus?.call('work failed');
      unawaited(
        _logClientEvent('realtime_tool_fallback_failure', {
          'message': error.toString(),
        }),
      );
    }
  }

  Future<void> _watchRun(
    int runId, {
    String userContent = '',
    String quickReplyText = '',
    int attempt = 0,
  }) async {
    await Future<void>.delayed(
      attempt == 0
          ? const Duration(milliseconds: 900)
          : Duration(milliseconds: (1800 + (attempt * 450)).clamp(1800, 4500)),
    );
    if (!_active) return;
    try {
      final run = await apiClient.getAssistantRun(runId);
      if (run.status == 'queued' || run.status == 'running') {
        if (attempt < 45) {
          _showWorkingStatusWhenReady();
          unawaited(
            _watchRun(
              runId,
              userContent: userContent,
              quickReplyText: quickReplyText,
              attempt: attempt + 1,
            ),
          );
        }
        return;
      }
      if (run.status == 'completed') {
        final content = run.assistantMessage?.content?.trim() ?? '';
        if (content.isEmpty) {
          _setBackgroundWorkActive(false);
          return;
        }
        unawaited(refreshDashboardContext());
        final finalVoice = _finalVoiceForTurn(
          userContent,
          quickReplyText,
          content,
        );
        _setBackgroundWorkActive(false);
        if (finalVoice.suppressFinal) {
          _finishRealtimeTurnStatus();
          return;
        }
        _deliverBackgroundResult(
          finalVoice.text.isNotEmpty ? finalVoice.text : content,
          runId,
        );
        return;
      }
      if (run.status == 'failed') {
        _setBackgroundWorkActive(false);
        _deliverBackgroundResult('I could not finish that request.', runId);
        return;
      }
      if (run.status == 'cancelled') {
        _setBackgroundWorkActive(false);
        _deliverBackgroundResult('That request was cancelled.', runId);
      }
    } catch (_) {
      if (attempt < 8) {
        unawaited(
          _watchRun(
            runId,
            userContent: userContent,
            quickReplyText: quickReplyText,
            attempt: attempt + 1,
          ),
        );
      }
    }
  }

  void _deliverBackgroundResult(String content, [int? runId]) {
    if (!_conversationActive) return;
    final channel = _dataChannel;
    if (channel?.state != RTCDataChannelState.RTCDataChannelOpen) return;
    final text = _speechTextFromAssistant(content);
    if (text.isEmpty) return;
    _suppressNextAssistantPersist = true;
    _voiceOnlyAssistant = true;
    _ignoreNextFunctionCalls = true;
    final alreadySpoken = {
      ..._spokenSegments,
      _lastAssistantText,
    }.where((item) => item.trim().isNotEmpty).take(6).toList();
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
                'text': jsonEncode({
                  'realtime_background_complete': true,
                  'result': text,
                  'already_spoken': alreadySpoken,
                  'instruction':
                      'Continue naturally with the completed result. Do not repeat or paraphrase anything already spoken. If the result is long, give a concise voice summary and refer to chat.',
                  'rules': [
                    'Do not call tools.',
                    'Do not mention tools, models, connections, or voice.',
                    'Do not use generic filler.',
                  ],
                }),
              },
            ],
          },
        }),
      ),
    );
    _sendResponseCreate(audioResponse: true, textResponse: true);
  }

  void _recordSpokenSegment(String text) {
    final clean = text.replaceAll(RegExp(r'\s+'), ' ').trim();
    if (clean.isEmpty) return;
    final last = _spokenSegments.isEmpty ? '' : _spokenSegments.last;
    if (_normalizeComparableSpeech(last) == _normalizeComparableSpeech(clean)) {
      return;
    }
    _spokenSegments.add(clean);
    if (_spokenSegments.length > 8) {
      _spokenSegments.removeRange(0, _spokenSegments.length - 8);
    }
  }

  void _setBackgroundWorkActive(
    bool active, {
    String userContent = '',
    String quickReplyText = '',
  }) {
    _backgroundWorkActive = active;
    if (!active) {
      _clearBackgroundProgressUpdates();
      _backgroundUserContent = '';
      _backgroundQuickReplyText = '';
      return;
    }
    _backgroundUserContent = userContent.trim();
    _backgroundQuickReplyText = quickReplyText.trim();
    _scheduleBackgroundProgressUpdates();
    _showWorkingStatusWhenReady();
  }

  void _showWorkingStatusWhenReady() {
    if (!_backgroundWorkActive) return;
    onStatus?.call('working...');
  }

  void _scheduleBackgroundProgressUpdates() {
    _clearBackgroundProgressUpdates();
    const checkpoints = <({Duration delay, String instruction})>[
      (
        delay: Duration(seconds: 8),
        instruction:
            'Give one brief, natural progress update. Reassure the user that Bean is still working. Do not repeat prior wording.',
      ),
      (
        delay: Duration(seconds: 18),
        instruction:
            'Give one brief progress update that acknowledges this is taking a little longer. Do not repeat prior wording.',
      ),
      (
        delay: Duration(seconds: 30),
        instruction:
            'Briefly say this is taking longer than expected and that the result will be placed in chat if needed. Do not repeat prior wording.',
      ),
    ];
    for (final checkpoint in checkpoints) {
      late Timer timer;
      timer = Timer(checkpoint.delay, () {
        _backgroundProgressTimers.remove(timer);
        _sendBackgroundProgressUpdate(
          elapsedMs: checkpoint.delay.inMilliseconds,
          instruction: checkpoint.instruction,
        );
      });
      _backgroundProgressTimers.add(timer);
    }
  }

  void _clearBackgroundProgressUpdates() {
    for (final timer in _backgroundProgressTimers) {
      timer.cancel();
    }
    _backgroundProgressTimers.clear();
  }

  void _sendBackgroundProgressUpdate({
    required int elapsedMs,
    required String instruction,
  }) {
    if (!_backgroundWorkActive || !_conversationActive) return;
    final channel = _dataChannel;
    if (channel?.state != RTCDataChannelState.RTCDataChannelOpen) return;
    final alreadySpoken = {
      ..._spokenSegments,
      _backgroundQuickReplyText,
      _lastAssistantText,
    }.where((item) => item.trim().isNotEmpty).take(6).toList();
    _suppressNextAssistantPersist = true;
    _voiceOnlyAssistant = true;
    _ignoreNextFunctionCalls = true;
    unawaited(
      _logClientEvent('flutter_realtime_progress_prompt', {
        'user_request': _backgroundUserContent,
        'elapsed_ms': elapsedMs,
        'already_spoken': alreadySpoken,
        'instruction': instruction,
      }),
    );
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
                'text': jsonEncode({
                  'realtime_progress_update': true,
                  'user_request': _backgroundUserContent,
                  'elapsed_ms': elapsedMs,
                  'already_spoken': alreadySpoken,
                  'instruction': instruction,
                  'rules': [
                    'Speak one short sentence only.',
                    'Do not call tools.',
                    'Do not mention tools, models, connections, or voice.',
                    'Do not repeat or paraphrase anything in already_spoken.',
                  ],
                }),
              },
            ],
          },
        }),
      ),
    );
    _sendResponseCreate(audioResponse: true, textResponse: true);
  }

  _FinalVoice _finalVoiceForTurn(
    String userContent,
    String quickReplyText,
    String assistantContent,
  ) {
    final text = _speechTextFromAssistant(assistantContent);
    if (text.isEmpty) return const _FinalVoice(text: '');
    if (quickReplyText.trim().isEmpty) return _FinalVoice(text: text);
    if (_quickReplyCoversFinal(quickReplyText, text)) {
      return const _FinalVoice(text: '', suppressFinal: true);
    }
    final continuation = _finalContinuationAfterQuickReply(
      quickReplyText,
      text,
    );
    if (continuation.isEmpty) {
      return const _FinalVoice(text: '', suppressFinal: true);
    }
    if (_finalResponseIsDetailed(assistantContent, text)) {
      return _FinalVoice(text: _finalDetailNotice(userContent));
    }
    return _FinalVoice(text: continuation);
  }

  void _finishRealtimeTurnStatus() {
    if (_backgroundWorkActive) {
      _showWorkingStatusWhenReady();
      return;
    }
    onStatus?.call(_conversationActive ? 'listening' : 'Bean voice ready');
  }

  void _sendFunctionOutput(
    String? callId,
    Map<String, Object?> result, {
    bool createResponse = true,
  }) {
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
    if (createResponse) {
      _sendResponseCreate(audioResponse: true, textResponse: true);
    }
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

Map<String, Object?> realtimeSessionUpdatePayloadForTesting(
  String instructions,
) => _realtimeSessionUpdatePayload(instructions);

Map<String, Object?> _realtimeSessionUpdatePayload(String instructions) => {
  'type': 'session.update',
  'session': {'type': 'realtime', 'instructions': instructions},
};

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

bool _voiceCommandRequiresBackgroundWork(String transcript) {
  final command = _normalizedVoiceCommand(transcript);
  if (command.isEmpty) return false;
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
    r'\b(?:add|create|put|move|reschedule|schedule|update|change|delete|remove|cancel|complete|finish|mark|remind|remember)\b',
  ).hasMatch(command)) {
    return true;
  }
  return RegExp(r'\b(?:plan|organize|prioritize)\b').hasMatch(command) &&
      RegExp(
        r'\b(?:day|today|tomorrow|week|schedule|work|tasks|calendar|morning|afternoon|evening)\b',
      ).hasMatch(command);
}

bool _realtimeSpokenAnswerAllowsBackgroundQueue(
  String userTranscript,
  String assistantText,
) {
  final spoken = _normalizedVoiceCommand(assistantText);
  if (spoken.isEmpty) return true;
  if (spoken.length > 180) return false;
  if (_spokenContainsConcreteAnswer(spoken, assistantText)) return false;
  if (RegExp(
    r"\b(?:i don t have|i do not have|i can t see|i cannot see|i don t know|i do not know|let me check|let me get|let me look|i(?:'|’)?ll check|i will check|i(?:'|’)?ll get|i will get|i(?:'|’)?m going to check|i am going to check|i need to check|i can check|checking|pulling|gathering|looking|working|finding|one moment|give me|hang on|hold on)\b",
  ).hasMatch(spoken)) {
    return true;
  }
  if (RegExp(
    r"\b(?:i(?:'|’)?ll|i will|i(?:'|’)?m going to|i am going to|let me|i(?:'|’)?m checking|i am checking)\b",
  ).hasMatch(spoken)) {
    return true;
  }
  if (RegExp(r'\b(?:sure|absolutely|yeah|okay|ok|got it)\b').hasMatch(spoken) &&
      RegExp(
        r'\b(?:check|look|pull|gather|find|work|handle|start|do that|take care)\b',
      ).hasMatch(spoken)) {
    return true;
  }
  final userWords = _comparableVoiceWords(userTranscript).toSet();
  final spokenWords = _comparableVoiceWords(spoken);
  final novelWords = spokenWords.where((word) => !userWords.contains(word));
  return novelWords.length <= 2 && spokenWords.length <= 10;
}

bool _spokenContainsConcreteAnswer(String spoken, String originalText) {
  final raw = originalText;
  if (RegExp(r'[;:]').hasMatch(raw) ||
      RegExp(r'\b\d+\b').hasMatch(spoken) ||
      RegExp(r'\d').hasMatch(raw)) {
    return true;
  }
  return RegExp(
    r"\b(?:you have|you ve got|you've got|you got|you have got|you have \w+ tasks|you ve \w+ tasks|there are|there is|here are|here s|here's|heres|it is|it s|it's|its|looks like|today you|today there|for today|on your list|todo list|to do list|tasks today|due|scheduled|starts|ends|temperature|degrees|degree|percent|humidity|wind|mph|clear skies|partly cloudy|cloudy|sunny|rain|raining|storm|storming|forecast says|weather is)\b",
  ).hasMatch(spoken);
}

List<String> _comparableVoiceWords(String value) {
  const stopWords = {
    'about',
    'after',
    'again',
    'also',
    'and',
    'are',
    'bean',
    'been',
    'being',
    'can',
    'could',
    'for',
    'from',
    'get',
    'got',
    'have',
    'here',
    'into',
    'just',
    'like',
    'now',
    'okay',
    'one',
    'out',
    'right',
    'sure',
    'that',
    'the',
    'then',
    'there',
    'this',
    'with',
    'you',
    'your',
    'youre',
    'ill',
    'ive',
    'its',
  };
  return _normalizedVoiceCommand(value)
      .split(' ')
      .map((word) => word.replaceFirst(RegExp(r"'s$"), ''))
      .where((word) => word.length > 2 && !stopWords.contains(word))
      .toList();
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

String _realtimeTextFromResponseOutput(List<Map<String, Object?>> output) {
  final strings = <String>[];
  void visit(Object? value) {
    if (strings.join(' ').length > 2000) return;
    if (value is String) {
      final clean = value.replaceAll(RegExp(r'\s+'), ' ').trim();
      if (clean.isNotEmpty) strings.add(clean);
      return;
    }
    if (value is List) {
      for (final item in value) {
        visit(item);
      }
      return;
    }
    if (value is Map) {
      visit(value['transcript']);
      visit(value['text']);
      visit(value['content']);
      visit(value['output']);
    }
  }

  for (final item in output) {
    visit(item);
  }
  return strings.join(' ').replaceAll(RegExp(r'\s+'), ' ').trim();
}

String _normalizeComparableSpeech(String value) => value
    .toLowerCase()
    .replaceAll(RegExp(r'[^a-z0-9\s]'), ' ')
    .replaceAll(RegExp(r'\s+'), ' ')
    .trim();

bool _quickReplyCoversFinal(String quickReplyText, String finalText) {
  final quick = _normalizeComparableSpeech(quickReplyText);
  final fin = _normalizeComparableSpeech(finalText);
  if (quick.isEmpty || fin.isEmpty) return false;
  if (fin.startsWith(
    quick.substring(0, quick.length < 100 ? quick.length : 100),
  )) {
    return fin.length <= quick.length + 100 ||
        _novelContentRatio(fin, quick) < 0.18;
  }
  if (quick.length >= 24 &&
      fin.length <= quick.length + 180 &&
      _quickSimilarity(quick, fin) > 0.58 &&
      _novelContentRatio(fin, quick) < 0.32) {
    return true;
  }
  return quick.length >= 40 &&
      _quickSimilarity(quick, fin) > 0.68 &&
      _novelContentRatio(fin, quick) < 0.24;
}

String _finalContinuationAfterQuickReply(
  String quickReplyText,
  String finalText,
) {
  final quick = _normalizeComparableSpeech(quickReplyText);
  final fin = _normalizeComparableSpeech(finalText);
  if (quick.isEmpty || fin.isEmpty) return finalText.trim();
  if (fin.startsWith(quick)) {
    return finalText
        .trim()
        .substring(
          quickReplyText.trim().length.clamp(0, finalText.trim().length),
        )
        .replaceFirst(RegExp(r'^[\s,.;:-]+'), '')
        .trim();
  }
  final sentences = RegExp(r'[^.!?]+[.!?]+|[^.!?]+$')
      .allMatches(finalText.replaceAll(RegExp(r'\s+'), ' ').trim())
      .map((match) => match.group(0) ?? '')
      .where((sentence) => sentence.trim().isNotEmpty)
      .toList();
  final kept = <String>[];
  for (final sentence in sentences) {
    final cleaned = _stripQuickReplyOverlap(sentence, quick);
    if (cleaned.isNotEmpty) kept.add(cleaned);
  }
  final continuation = kept.join(' ').trim();
  if (continuation.isEmpty) return '';
  final normalized = _normalizeComparableSpeech(continuation);
  if (normalized.isNotEmpty &&
      _quickSimilarity(quick, normalized) > 0.74 &&
      _novelContentRatio(normalized, quick) < 0.28) {
    return '';
  }
  return continuation;
}

String _stripQuickReplyOverlap(String sentence, String normalizedQuickReply) {
  final original = sentence.replaceAll(RegExp(r'\s+'), ' ').trim();
  final normalizedSentence = _normalizeComparableSpeech(original);
  if (original.isEmpty ||
      normalizedSentence.isEmpty ||
      normalizedQuickReply.isEmpty) {
    return original;
  }
  if (normalizedQuickReply.contains(normalizedSentence)) return '';
  final similarity = _quickSimilarity(normalizedQuickReply, normalizedSentence);
  final novelty = _novelContentRatio(normalizedSentence, normalizedQuickReply);
  if (similarity > 0.58 && novelty < 0.34) return '';
  if (similarity > 0.72 && normalizedSentence.split(' ').length <= 14) {
    return '';
  }
  final clauses = original
      .split(RegExp(r'(?<=,|;|:)\s+|\s+(?:and|then)\s+', caseSensitive: false))
      .map((part) => part.trim())
      .where((part) => part.isNotEmpty)
      .toList();
  if (clauses.length <= 1) return original;
  final kept = clauses.where((clause) {
    final normalizedClause = _normalizeComparableSpeech(clause);
    if (normalizedClause.isEmpty) return false;
    if (normalizedQuickReply.contains(normalizedClause)) return false;
    return !(_quickSimilarity(normalizedQuickReply, normalizedClause) > 0.6 &&
        _novelContentRatio(normalizedClause, normalizedQuickReply) < 0.3);
  });
  return kept.join(', ').replaceFirst(RegExp(r'^[\s,.;:-]+'), '').trim();
}

bool _finalResponseIsDetailed(String rawContent, String spokenText) =>
    spokenText.length > 520 ||
    rawContent.length > 700 ||
    RegExp(r'(?:^|\n)\s*(?:[-*]|\d+[.)])\s+\S').hasMatch(rawContent) ||
    RegExp(r'\n').allMatches(rawContent).length >= 3;

String _finalDetailNotice(String userContent) {
  final command = userContent.toLowerCase();
  if (RegExp(
    r'\b(?:workout|exercise|routine|training|stretch|stretches)\b',
  ).hasMatch(command)) {
    return "I've written the full workout plan in chat.";
  }
  if (RegExp(r'\b(?:recipe|cook|meal)\b').hasMatch(command)) {
    return "I've written the full recipe in chat.";
  }
  if (RegExp(r'\b(?:plan|guide|steps|instructions)\b').hasMatch(command)) {
    return "I've written the full plan in chat.";
  }
  return "I've written the full details in chat.";
}

double _quickSimilarity(String a, String b) {
  final aWords = _comparableContentWords(a).toSet();
  final bWords = _comparableContentWords(b).toSet();
  if (aWords.isEmpty || bWords.isEmpty) return 0;
  var overlap = 0;
  for (final word in aWords) {
    if (bWords.contains(word)) overlap++;
  }
  return overlap / math.min(aWords.length, bWords.length);
}

double _novelContentRatio(String candidate, String reference) {
  final candidateWords = _comparableContentWords(candidate);
  if (candidateWords.isEmpty) return 0;
  final referenceWords = _comparableContentWords(reference).toSet();
  final novelCount = candidateWords
      .where((word) => !referenceWords.contains(word))
      .length;
  return novelCount / candidateWords.length;
}

List<String> _comparableContentWords(String value) {
  const stopWords = {
    'about',
    'after',
    'again',
    'also',
    'and',
    'are',
    'bean',
    'been',
    'being',
    'can',
    'could',
    'for',
    'from',
    'get',
    'got',
    'have',
    'here',
    'into',
    'just',
    'like',
    'now',
    'okay',
    'one',
    'out',
    'right',
    'sure',
    'that',
    'the',
    'then',
    'there',
    'this',
    'with',
    'you',
    'your',
    'youre',
    'ill',
    'i',
    'll',
    'ive',
    've',
    'its',
    'it',
    's',
  };
  return _normalizeComparableSpeech(value)
      .split(' ')
      .map((word) => word.replaceFirst(RegExp(r"'s$"), ''))
      .where((word) => word.length > 2 && !stopWords.contains(word))
      .toList();
}
