import 'dart:async';
import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter_webrtc/flutter_webrtc.dart';

import 'hermes_api_client.dart';

typedef RealtimeTranscriptCallback = void Function(String role, String text);
typedef RealtimeStatusCallback = void Function(String status);
typedef RealtimeRunQueuedCallback =
    void Function(int runId, String userContent);
typedef RealtimeSessionEndedCallback = void Function(String reason);

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

class _PendingBackgroundCompletion {
  const _PendingBackgroundCompletion({
    required this.runId,
    required this.spokenText,
    required this.priorSpokenClaim,
  });

  final int? runId;
  final String spokenText;
  final String priorSpokenClaim;
}

class _PendingProgressPrompt {
  const _PendingProgressPrompt({
    required this.elapsedMs,
    required this.instruction,
    required this.userRequest,
    required this.alreadySpoken,
  });

  final int elapsedMs;
  final String instruction;
  final String userRequest;
  final List<String> alreadySpoken;
}

bool _assistantMessageShouldStayOutOfRealtimeResult(HermesMessage? message) {
  if (message == null || message.role != 'assistant') return false;

  final runtime = message.metadata['runtime']?.toString();
  if (runtime == 'missing_run_bridge' ||
      runtime == 'direct_queue_bridge' ||
      runtime == 'async_queue_bridge' ||
      runtime == 'failed_run_bridge') {
    return true;
  }

  final normalized = (message.content ?? '')
      .toLowerCase()
      .replaceAll(RegExp(r'\s+'), ' ')
      .trim();

  return normalized ==
          'i’m checking the latest app state now. if i need one more detail, i’ll ask.' ||
      normalized ==
          "i'm checking the latest app state now. if i need one more detail, i'll ask." ||
      normalized ==
          'i didn’t receive that request cleanly. please send it once more and i’ll take it from there.' ||
      normalized ==
          "i didn't receive that request cleanly. please send it once more and i'll take it from there." ||
      normalized ==
          'i’m on it. i’m syncing against the latest app state now, and i’ll ask for one detail if i need it.' ||
      normalized ==
          "i'm on it. i'm syncing against the latest app state now, and i'll ask for one detail if i need it.";
}

class BeanRealtimeConversation {
  static const Duration _followUpIdleTimeout = Duration(seconds: 30);
  static const Duration _contextRefreshBudget = Duration(milliseconds: 220);
  static const Duration _bargeInConfirmationDelay = Duration(milliseconds: 70);
  static const Duration _toolFallbackTimeout = Duration(milliseconds: 650);
  static const Duration _pendingBackgroundDeliveryGrace = Duration(
    milliseconds: 260,
  );
  static const Duration _pendingResponseRecoveryGrace = Duration(
    milliseconds: 320,
  );

  BeanRealtimeConversation({
    required this.apiClient,
    this.onTranscript,
    this.onStatus,
    this.onRunQueued,
    this.onSessionEnded,
  });

  final HermesApiClient apiClient;
  RealtimeTranscriptCallback? onTranscript;
  RealtimeStatusCallback? onStatus;
  RealtimeRunQueuedCallback? onRunQueued;
  RealtimeSessionEndedCallback? onSessionEnded;

  void configureCallbacks({
    RealtimeTranscriptCallback? onTranscript,
    RealtimeStatusCallback? onStatus,
    RealtimeRunQueuedCallback? onRunQueued,
    RealtimeSessionEndedCallback? onSessionEnded,
  }) {
    if (onTranscript != null) this.onTranscript = onTranscript;
    if (onStatus != null) this.onStatus = onStatus;
    if (onRunQueued != null) this.onRunQueued = onRunQueued;
    if (onSessionEnded != null) this.onSessionEnded = onSessionEnded;
  }

  RTCPeerConnection? _peerConnection;
  RTCDataChannel? _dataChannel;
  Completer<void>? _dataChannelOpen;
  Completer<void>? _transcriptionOnlyReleaseCompleter;
  Completer<void>? _sessionUpdateAckCompleter;
  MediaStream? _localStream;
  HermesRealtimeSession? _session;
  Timer? _responseDebounce;
  Timer? _toolFallbackTimer;
  Timer? _releaseTranscriptGraceTimer;
  Timer? _followUpIdleTimer;
  Timer? _bargeInConfirmationTimer;
  Timer? _pendingBackgroundCompletionTimer;
  Timer? _pendingResponseRecoveryTimer;
  final _backgroundProgressTimers = <Timer>{};
  final _transcriptDrafts = <String, String>{};
  final _processedCalls = <String>{};
  final _activeBackgroundRunIds = <int>{};
  final _interruptedResponseIds = <String>{};
  final _pendingFunctionCalls = <_RealtimeFunctionCall>[];
  final _spokenSegments = <String>[];
  _PendingBackgroundCompletion? _pendingBackgroundCompletion;
  _PendingProgressPrompt? _pendingProgressPrompt;
  String _toolFallbackContent = '';
  String _fallbackQueuedUserContent = '';
  String? _currentUserContent;
  String? _pendingUserContent;
  String? _activeResponseUserContent;
  String? _pendingUserItemId;
  String _assistantDraft = '';
  String? _assistantItemId;
  String _lastAssistantText = '';
  String _backgroundUserContent = '';
  String _backgroundQuickReplyText = '';
  String _pendingFreshContextRecoveryContent = '';
  String _pendingFreshContextRecoveryReason = '';
  String? _currentResponseId;
  DateTime? _assistantAudioStartedAt;
  DateTime? _userSpeechStartedAt;
  DateTime? _turnStartedAt;
  DateTime? _responseCreateRequestedAt;
  DateTime? _firstAssistantSignalAt;
  DateTime? _lastResponseDoneAt;
  DateTime? _bargeInRecoveryStartedAt;
  DateTime? _pendingResponseDeferredAt;
  int _currentTurnToolCallCount = 0;
  bool _currentTurnIsFollowUp = false;
  bool _currentTurnIsContextualFollowUp = false;
  String _currentTurnContextualFollowUpKind = '';
  bool _currentTurnContextRefreshSucceeded = false;
  bool _active = false;
  bool _conversationActive = false;
  bool _voiceCaptureActive = false;
  bool _voiceReleasePending = false;
  bool _transcriptionOnlyReleasePending = false;
  bool _backgroundWorkActive = false;
  bool _suppressNextAssistantPersist = false;
  bool _voiceOnlyAssistant = false;
  bool _ignoreNextFunctionCalls = false;
  bool _assistantSpeaking = false;
  bool _assistantInterrupted = false;
  bool _interruptedResponseWithoutId = false;
  bool _transportDegraded = false;
  bool _userSpeechActive = false;
  bool _responseCreateInFlight = false;
  bool _pendingResponseInterruptedBySpeech = false;
  bool _bargeInRecoveryPending = false;
  bool _followUpReadyLoggedForTurn = false;
  bool _lastTurnNeededDashboardContext = false;
  bool _endConversationAfterResponse = false;

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
    _transportDegraded = false;

    final pc = await createPeerConnection({
      'sdpSemantics': 'unified-plan',
      'iceServers': const <Map<String, Object>>[],
    });
    _peerConnection = pc;

    pc.onConnectionState = (state) {
      if (state == RTCPeerConnectionState.RTCPeerConnectionStateConnected) {
        onStatus?.call('Bean voice ready');
      }
      if (_realtimePeerConnectionStateIsDegraded(state)) {
        _handleTransportDegraded('webrtc_connection_failure', {
          'connection_state': state.name,
        });
      }
    };
    pc.onIceConnectionState = (state) {
      if (_realtimeIceConnectionStateIsDegraded(state)) {
        _handleTransportDegraded('ice_webrtc_connection_failure', {
          'ice_connection_state': state.name,
        });
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
        _handleTransportDegraded('data_channel_close', {
          'ready_state': state.name,
        });
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

  Future<void> sendText(
    String text, {
    bool audioResponse = false,
    bool endConversationAfterResponse = false,
  }) async {
    final trimmed = text.trim();
    if (trimmed.isEmpty) return;
    final channel = _dataChannel;
    if (channel == null) {
      throw StateError('Realtime data channel is not connected.');
    }
    await _waitForDataChannelOpen(channel);
    _cancelFollowUpIdleTimeout();
    _conversationActive = true;
    _endConversationAfterResponse = endConversationAfterResponse;
    _pendingUserContent = trimmed;
    _pendingUserItemId = 'typed-${DateTime.now().microsecondsSinceEpoch}';
    _startTurnMetrics();
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
    _sendResponseCreate(
      audioResponse: audioResponse,
      textResponse: true,
      userContent: trimmed,
    );
  }

  void beginVoiceCapture() {
    _cancelFollowUpIdleTimeout();
    _conversationActive = true;
    _voiceCaptureActive = true;
    _voiceReleasePending = false;
    _releaseTranscriptGraceTimer?.cancel();
    setMicrophoneEnabled(true);
    onStatus?.call('listening');
  }

  void endVoiceCapture() {
    if (!_voiceCaptureActive && !_voiceReleasePending) return;
    _cancelFollowUpIdleTimeout();
    _voiceCaptureActive = false;
    _voiceReleasePending = true;
    _transcriptionOnlyReleasePending = false;
    _endConversationAfterResponse = true;
    setMicrophoneEnabled(false);
    final content = _pendingUserContent?.trim() ?? '';
    if (content.isNotEmpty) {
      _scheduleResponseCreate(
        delay: _voiceResponseDelayAfterFinalTranscript(
          releasePending: true,
          content: content,
        ),
      );
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
          _scheduleResponseCreate(
            delay: _voiceResponseDelayAfterFinalTranscript(
              releasePending: true,
              content: lateContent,
            ),
          );
          return;
        }
        _voiceReleasePending = false;
        onStatus?.call(_conversationActive ? 'listening' : 'Bean voice ready');
      },
    );
  }

  Future<void> endVoiceCaptureForTranscriptionOnly() {
    if (!_voiceCaptureActive && !_voiceReleasePending) {
      return Future<void>.value();
    }
    _responseDebounce?.cancel();
    _toolFallbackTimer?.cancel();
    _cancelFollowUpIdleTimeout();
    _voiceCaptureActive = false;
    _voiceReleasePending = true;
    _transcriptionOnlyReleasePending = true;
    _transcriptionOnlyReleaseCompleter = Completer<void>();
    setMicrophoneEnabled(false);
    onStatus?.call('thinking');
    _releaseTranscriptGraceTimer?.cancel();
    _releaseTranscriptGraceTimer = Timer(const Duration(milliseconds: 900), () {
      _releaseTranscriptGraceTimer = null;
      if (!_transcriptionOnlyReleasePending) return;
      _voiceReleasePending = false;
      _transcriptionOnlyReleasePending = false;
      _completeTranscriptionOnlyRelease();
      onStatus?.call('Bean voice ready');
    });
    return _transcriptionOnlyReleaseCompleter!.future;
  }

  void _completeTranscriptionOnlyRelease() {
    final completer = _transcriptionOnlyReleaseCompleter;
    _transcriptionOnlyReleaseCompleter = null;
    if (completer == null || completer.isCompleted) return;
    completer.complete();
  }

  void setMicrophoneEnabled(bool enabled) {
    for (final track
        in _localStream?.getAudioTracks() ?? const <MediaStreamTrack>[]) {
      track.enabled = enabled;
    }
  }

  int _microphoneAudioTrackCount() =>
      (_localStream?.getAudioTracks() ?? const <MediaStreamTrack>[]).length;

  bool _microphoneAudioTracksEnabled() {
    final tracks = _localStream?.getAudioTracks() ?? const <MediaStreamTrack>[];
    return tracks.isNotEmpty && tracks.every((track) => track.enabled);
  }

  Future<void> interrupt({
    bool endConversation = true,
    bool cancelBackgroundWork = false,
  }) async {
    _responseDebounce?.cancel();
    _toolFallbackTimer?.cancel();
    _releaseTranscriptGraceTimer?.cancel();
    _cancelFollowUpIdleTimeout();
    _cancelBargeInConfirmation();
    _cancelPendingBackgroundCompletionDelivery();
    _cancelPendingResponseRecovery();
    _completeSessionUpdateAck();
    _clearBackgroundProgressUpdates();
    _pendingProgressPrompt = null;
    String? interruptSignalError;
    try {
      _dataChannel?.send(
        RTCDataChannelMessage(jsonEncode({'type': 'response.cancel'})),
      );
    } catch (error) {
      interruptSignalError = error.toString();
    }
    try {
      _dataChannel?.send(
        RTCDataChannelMessage(
          jsonEncode({'type': 'output_audio_buffer.clear'}),
        ),
      );
    } catch (error) {
      interruptSignalError ??= error.toString();
    }
    if (interruptSignalError != null) {
      unawaited(
        _logClientEvent('flutter_realtime_interrupt_signal_failure', {
          'message': interruptSignalError,
          'end_conversation': endConversation,
          'cancel_background_work': cancelBackgroundWork,
        }),
      );
    }
    _pendingUserContent = null;
    _pendingUserItemId = null;
    _currentUserContent = null;
    _activeResponseUserContent = null;
    _pendingBackgroundCompletion = null;
    _fallbackQueuedUserContent = '';
    _pendingFreshContextRecoveryContent = '';
    _pendingFreshContextRecoveryReason = '';
    _assistantDraft = '';
    _assistantItemId = null;
    _toolFallbackContent = '';
    _pendingProgressPrompt = null;
    _pendingFunctionCalls.clear();
    if (cancelBackgroundWork) {
      unawaited(_cancelActiveBackgroundRuns());
    }
    _backgroundWorkActive = false;
    _voiceCaptureActive = false;
    _voiceReleasePending = false;
    _transcriptionOnlyReleasePending = false;
    _userSpeechActive = false;
    _userSpeechStartedAt = null;
    _pendingResponseInterruptedBySpeech = false;
    _clearBargeInRecovery();
    _completeTranscriptionOnlyRelease();
    _endConversationAfterResponse = false;
    _ignoreNextFunctionCalls = false;
    _assistantSpeaking = false;
    _assistantInterrupted = false;
    _interruptedResponseIds.clear();
    _interruptedResponseWithoutId = false;
    _currentResponseId = null;
    _assistantAudioStartedAt = null;
    _resetTurnMetrics();
    _lastTurnNeededDashboardContext = false;
    if (endConversation) _conversationActive = false;
    setMicrophoneEnabled(!endConversation);
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
      final instructions = await _fetchRealtimeDashboardInstructions(sessionId);
      return _sendRealtimeDashboardInstructions(instructions);
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
    _cancelFollowUpIdleTimeout();
    _cancelBargeInConfirmation();
    _cancelPendingBackgroundCompletionDelivery();
    _cancelPendingResponseRecovery();
    _completeSessionUpdateAck();
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
    _activeResponseUserContent = null;
    _assistantDraft = '';
    _assistantItemId = null;
    _toolFallbackContent = '';
    _pendingFunctionCalls.clear();
    _activeBackgroundRunIds.clear();
    _spokenSegments.clear();
    _fallbackQueuedUserContent = '';
    _pendingFreshContextRecoveryContent = '';
    _pendingFreshContextRecoveryReason = '';
    _lastAssistantText = '';
    _backgroundWorkActive = false;
    _voiceCaptureActive = false;
    _voiceReleasePending = false;
    _transcriptionOnlyReleasePending = false;
    _userSpeechActive = false;
    _userSpeechStartedAt = null;
    _pendingResponseInterruptedBySpeech = false;
    _clearBargeInRecovery();
    _completeTranscriptionOnlyRelease();
    _endConversationAfterResponse = false;
    _suppressNextAssistantPersist = false;
    _voiceOnlyAssistant = false;
    _ignoreNextFunctionCalls = false;
    _assistantSpeaking = false;
    _assistantInterrupted = false;
    _interruptedResponseIds.clear();
    _interruptedResponseWithoutId = false;
    _currentResponseId = null;
    _assistantAudioStartedAt = null;
    _transportDegraded = false;
    _resetTurnMetrics();
    _lastTurnNeededDashboardContext = false;
    onStatus?.call('Ready');
  }

  bool get _localStreamHasAudioTrack =>
      (_localStream?.getAudioTracks() ?? const <MediaStreamTrack>[]).isNotEmpty;

  void _handleTransportDegraded(
    String eventType,
    Map<String, Object?> details,
  ) {
    if (!_active || _transportDegraded) return;
    _transportDegraded = true;
    unawaited(_logClientEvent(eventType, details));
    unawaited(_closeDegradedTransport());
    onStatus?.call('Bean voice is reconnecting');
    onSessionEnded?.call(eventType);
  }

  Future<void> _closeDegradedTransport() async {
    _active = false;
    _conversationActive = false;
    _responseDebounce?.cancel();
    _toolFallbackTimer?.cancel();
    _releaseTranscriptGraceTimer?.cancel();
    _cancelFollowUpIdleTimeout();
    _cancelBargeInConfirmation();
    _cancelPendingBackgroundCompletionDelivery();
    _cancelPendingResponseRecovery();
    _completeSessionUpdateAck();
    _clearBackgroundProgressUpdates();
    _pendingProgressPrompt = null;
    setMicrophoneEnabled(false);
    _dataChannel?.close();
    await _peerConnection?.close();
    _localStream?.getTracks().forEach((track) => track.stop());
    _dataChannel = null;
    _dataChannelOpen = null;
    _peerConnection = null;
    _localStream = null;
    _activeBackgroundRunIds.clear();
    _fallbackQueuedUserContent = '';
    _pendingFreshContextRecoveryContent = '';
    _pendingFreshContextRecoveryReason = '';
    _voiceCaptureActive = false;
    _voiceReleasePending = false;
    _transcriptionOnlyReleasePending = false;
    _userSpeechActive = false;
    _userSpeechStartedAt = null;
    _pendingResponseInterruptedBySpeech = false;
    _clearBargeInRecovery();
    _completeTranscriptionOnlyRelease();
    _endConversationAfterResponse = false;
    _assistantSpeaking = false;
    _currentResponseId = null;
    _assistantAudioStartedAt = null;
    _resetTurnMetrics();
    _lastTurnNeededDashboardContext = false;
  }

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
        _handleRealtimeSpeechStarted();
        return;
      case 'session.updated':
        _completeSessionUpdateAck();
        return;
      case 'input_audio_buffer.speech_stopped':
        _userSpeechActive = false;
        _userSpeechStartedAt = null;
        _cancelBargeInConfirmation(reason: 'speech_stopped_before_confirmed');
        final status = _realtimeStatusAfterSpeechStopped(
          conversationActive: _conversationActive,
          assistantSpeaking: _assistantSpeaking,
        );
        if (status != null) onStatus?.call(status);
        _schedulePendingResponseRecoveryAfterSpeechStopped();
        _schedulePendingBackgroundCompletionDeliveryAfterSpeechStopped();
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
        _currentResponseId = _responseIdFromPayload(decoded);
        _responseCreateInFlight = _realtimeResponseCreateInFlightAfterEvent(
          current: _responseCreateInFlight,
          eventType: type,
        );
        _assistantSpeaking = true;
        if (!_realtimePayloadMatchesInterruptedResponse(decoded) &&
            !_interruptedResponseWithoutId) {
          _assistantInterrupted = false;
        }
        _assistantAudioStartedAt = DateTime.now();
        if (_userSpeechActive) {
          _requestBargeInStarted();
          return;
        }
        onStatus?.call("Bean's voice");
        return;
      case 'response.output_item.added':
        _handleResponseOutputItemAdded(decoded);
        return;
      case 'response.audio_transcript.delta':
      case 'response.output_audio_transcript.delta':
      case 'response.output_text.delta':
        _appendAssistantDelta(decoded);
        return;
      case 'response.audio_transcript.done':
      case 'response.output_audio_transcript.done':
      case 'response.output_text.done':
        _finishAssistantTranscript(decoded);
        return;
      case 'response.audio.done':
      case 'response.output_audio.done':
        _handleAssistantAudioDone(decoded);
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
        _responseCreateInFlight = _realtimeResponseCreateInFlightAfterEvent(
          current: _responseCreateInFlight,
          eventType: type,
        );
        onStatus?.call(
          _expectErrorMessage(decoded) ?? 'Bean voice is reconnecting',
        );
        unawaited(_logClientEvent('realtime_error', {'payload': decoded}));
        return;
    }
  }

  void _handleUserTranscript(Map<String, Object?> payload) {
    final raw = payload['transcript']?.toString().trim() ?? '';
    _cancelPendingResponseRecovery();
    _confirmBargeInFromTranscript(raw);
    _userSpeechActive = _realtimeUserSpeechActiveAfterFinalTranscript(
      wasActive: _userSpeechActive,
      transcript: raw,
    );
    _userSpeechStartedAt = null;
    if (raw.isEmpty) {
      _recoverDeferredResponseAfterNonActionableTranscript(raw);
      _logBargeInRecoveryFailureIfPending(
        transcript: raw,
        reason: 'empty_transcript',
      );
      return;
    }
    final key = _transcriptDraftKey(payload);
    if (key.isNotEmpty) _transcriptDrafts.remove(key);
    if (_transcriptLooksSynthetic(raw)) {
      _recoverDeferredResponseAfterNonActionableTranscript(raw);
      _logBargeInRecoveryFailureIfPending(
        transcript: raw,
        reason: 'synthetic_transcript',
      );
      onStatus?.call('Bean voice ready');
      return;
    }
    _cancelPendingBackgroundCompletionDelivery();

    final command = _commandAfterWakePhrase(raw);
    final isWakeTurn = command != null;
    final isFollowUpTurn = _isRealtimeFollowUpTranscript(
      isWakeTurn: isWakeTurn,
      conversationActive: _conversationActive,
      voiceCaptureActive: _voiceCaptureActive,
      voiceReleasePending: _voiceReleasePending,
      transcriptionOnlyReleasePending: _transcriptionOnlyReleasePending,
    );
    if (!isWakeTurn && !_conversationActive) {
      onStatus?.call('Bean voice ready');
      return;
    }
    _cancelFollowUpIdleTimeout();
    final endRequestContent = isWakeTurn ? command : raw;
    final politeConversationEnd =
        _politeConversationEndRequested(raw) ||
        _politeConversationEndRequested(endRequestContent);
    if (politeConversationEnd ||
        _voiceCancelRequested(raw) ||
        (isWakeTurn && _voiceCancelRequested(command))) {
      unawaited(
        interrupt(
          cancelBackgroundWork:
              !politeConversationEnd &&
              _voiceBackgroundWorkCancelRequested(endRequestContent),
        ),
      );
      if (!politeConversationEnd) {
        onStatus?.call('cancelled');
      }
      return;
    }
    if (isWakeTurn) _conversationActive = true;
    if (_voiceCaptureActive || _voiceReleasePending) _conversationActive = true;

    final content = (isWakeTurn ? command : raw).trim();
    if (content.isEmpty) {
      onStatus?.call('listening');
      return;
    }

    final appendToPending = _realtimeShouldAppendTranscriptToPendingTurn(
      responseDebounceActive: _responseDebounce?.isActive == true,
      pendingResponseInterruptedBySpeech: _pendingResponseInterruptedBySpeech,
      hasPendingUserContent: _pendingUserContent != null,
      isWakeTurn: isWakeTurn,
    );
    if (appendToPending) {
      _pendingUserContent = '$_pendingUserContent $content'
          .replaceAll(RegExp(r'\s+'), ' ')
          .trim();
    } else {
      _pendingUserContent = content;
      _pendingUserItemId =
          payload['item_id']?.toString() ??
          'audio-${DateTime.now().microsecondsSinceEpoch}';
      _startTurnMetrics(
        isFollowUp: isFollowUpTurn,
        isContextualFollowUp:
            isFollowUpTurn &&
            _realtimeTranscriptLooksContextualFollowUp(content),
        contextualFollowUpKind: isFollowUpTurn
            ? _realtimeContextualFollowUpKind(content)
            : '',
      );
    }
    _pendingResponseInterruptedBySpeech = false;
    _currentUserContent = _pendingUserContent;
    onTranscript?.call('user', _pendingUserContent!);
    if (_voiceCaptureActive) {
      onStatus?.call('listening');
      return;
    }
    if (_transcriptionOnlyReleasePending) {
      _releaseTranscriptGraceTimer?.cancel();
      _releaseTranscriptGraceTimer = null;
      _voiceReleasePending = false;
      _transcriptionOnlyReleasePending = false;
      _completeTranscriptionOnlyRelease();
      onStatus?.call('Bean voice ready');
      return;
    }
    _scheduleResponseCreate(
      delay: _voiceResponseDelayAfterFinalTranscript(
        releasePending: _voiceReleasePending,
        content: content,
      ),
    );
  }

  void _handleTranscriptDelta(Map<String, Object?> payload) {
    final delta = payload['delta']?.toString().trim() ?? '';
    if (delta.isEmpty) return;
    _confirmBargeInFromTranscript(delta);
    final key = _transcriptDraftKey(payload);
    final previous = key.isEmpty ? '' : (_transcriptDrafts[key] ?? '');
    final draft = _mergeTranscriptDelta(previous, delta);
    if (key.isNotEmpty) _transcriptDrafts[key] = draft;
    _showHeardTranscript(draft);
  }

  void _handleTranscriptSegment(Map<String, Object?> payload) {
    final text = payload['text']?.toString().trim() ?? '';
    if (text.isNotEmpty) {
      _confirmBargeInFromTranscript(text);
      _showHeardTranscript(text);
    }
  }

  void _showHeardTranscript(String text) {
    final raw = text.trim();
    if (raw.isEmpty || _transcriptLooksSynthetic(raw)) return;
    final command = _commandAfterWakePhrase(raw);
    if (!_conversationActive && command == null) return;
    final visibleDraft = (command ?? raw).trim();
    if (visibleDraft.isNotEmpty) {
      onTranscript?.call('user_draft', visibleDraft);
    }
    onStatus?.call('listening');
  }

  void _handleRealtimeSpeechStarted() {
    _cancelPendingBackgroundCompletionDelivery();
    _cancelPendingResponseRecovery();
    _userSpeechActive = true;
    _userSpeechStartedAt = DateTime.now();
    if (_assistantSpeaking) {
      _requestBargeInStarted();
    } else if (_conversationActive) {
      final pendingContent = _pendingUserContent?.trim() ?? '';
      if ((_responseDebounce?.isActive == true || _responseCreateInFlight) &&
          pendingContent.isNotEmpty) {
        final responseCreateWasInFlight = _responseCreateInFlight;
        _responseDebounce?.cancel();
        _responseDebounce = null;
        _completeSessionUpdateAck();
        _markPendingResponseDeferredBySpeech(
          userContent: pendingContent,
          responseCreateWasInFlight: responseCreateWasInFlight,
          source: 'speech_started',
        );
        if (_realtimeShouldCancelInFlightResponseOnSpeechStart(
          assistantSpeaking: _assistantSpeaking,
          conversationActive: _conversationActive,
          responseCreateInFlight: responseCreateWasInFlight,
          hasPendingUserContent: pendingContent.isNotEmpty,
        )) {
          _cancelInFlightResponseBeforeAssistantAudio();
        }
      }
    }
    if (_conversationActive) onStatus?.call('listening');
  }

  void _confirmBargeInFromTranscript(String transcript) {
    if (!_realtimeTranscriptConfirmsBargeIn(transcript)) return;
    if (!_assistantSpeaking) return;
    _requestBargeInStarted(transcriptConfirmed: true);
  }

  void _requestBargeInStarted({bool transcriptConfirmed = false}) {
    final now = DateTime.now();
    if (_realtimeShouldStartBargeInNow(
      assistantSpeaking: _assistantSpeaking,
      userSpeechActive: _userSpeechActive,
      speechStartedAt: _userSpeechStartedAt,
      now: now,
      transcriptConfirmed: transcriptConfirmed,
    )) {
      _cancelBargeInConfirmation();
      _handleBargeInStarted();
      return;
    }
    if (_bargeInConfirmationTimer?.isActive == true) return;
    final startedAt = _userSpeechStartedAt ?? now;
    final remaining = _bargeInConfirmationDelay - now.difference(startedAt);
    _bargeInConfirmationTimer = Timer(
      remaining.isNegative ? Duration.zero : remaining,
      () {
        _bargeInConfirmationTimer = null;
        if (_realtimeShouldStartBargeInNow(
          assistantSpeaking: _assistantSpeaking,
          userSpeechActive: _userSpeechActive,
          speechStartedAt: _userSpeechStartedAt,
          now: DateTime.now(),
          transcriptConfirmed: false,
        )) {
          _handleBargeInStarted();
        }
      },
    );
  }

  void _cancelBargeInConfirmation({String? reason}) {
    final timer = _bargeInConfirmationTimer;
    if (timer == null) return;
    timer.cancel();
    _bargeInConfirmationTimer = null;
    if (reason == null) return;
    final elapsedMs = _userSpeechStartedAt == null
        ? null
        : DateTime.now().difference(_userSpeechStartedAt!).inMilliseconds;
    unawaited(
      _logClientEvent('flutter_realtime_barge_in_ignored', {
        'reason': reason,
        if (elapsedMs != null) 'speech_elapsed_ms': elapsedMs,
      }),
    );
  }

  void _cancelInFlightResponseBeforeAssistantAudio() {
    final channel = _dataChannel;
    if (channel?.state != RTCDataChannelState.RTCDataChannelOpen) return;
    _assistantInterrupted = true;
    _interruptedResponseWithoutId = true;
    _responseCreateInFlight = false;
    try {
      channel!.send(
        RTCDataChannelMessage(jsonEncode(_realtimeResponseCancelPayload(null))),
      );
      channel.send(
        RTCDataChannelMessage(
          jsonEncode(_realtimeOutputAudioBufferClearPayload()),
        ),
      );
    } catch (error) {
      unawaited(
        _logClientEvent('flutter_realtime_in_flight_cancel_failure', {
          'message': error.toString(),
        }),
      );
    }
  }

  void _recoverDeferredResponseAfterNonActionableTranscript(String transcript) {
    final pendingContent = _pendingUserContent?.trim() ?? '';
    final recoveredAt = DateTime.now();
    if (!_realtimeShouldRecoverDeferredResponseAfterNonActionableTranscript(
      pendingResponseInterruptedBySpeech: _pendingResponseInterruptedBySpeech,
      pendingUserContent: _pendingUserContent,
      transcript: transcript,
    )) {
      return;
    }
    final deferredAt = _pendingResponseDeferredAt;
    _pendingResponseInterruptedBySpeech = false;
    _pendingResponseDeferredAt = null;
    unawaited(
      _logClientEvent(
        'flutter_realtime_pending_response_recovered_after_non_actionable_speech',
        _realtimePendingResponseRecoveryDetails(
          deferredAt: deferredAt,
          recoveredAt: recoveredAt,
          userContent: pendingContent,
          transcript: transcript,
          synthetic: _transcriptLooksSynthetic(transcript),
        ),
      ),
    );
    _scheduleResponseCreate(
      delay: _voiceResponseDelayAfterFinalTranscript(
        releasePending: false,
        content: pendingContent,
      ),
    );
  }

  void _markPendingResponseDeferredBySpeech({
    required String userContent,
    required bool responseCreateWasInFlight,
    required String source,
  }) {
    _pendingResponseInterruptedBySpeech = true;
    _pendingResponseDeferredAt ??= DateTime.now();
    unawaited(
      _logClientEvent(
        'flutter_realtime_pending_response_deferred_by_speech',
        _realtimePendingResponseDeferredDetails(
          userContent: userContent,
          responseCreateWasInFlight: responseCreateWasInFlight,
          source: source,
        ),
      ),
    );
  }

  void _scheduleResponseCreate({
    Duration delay = const Duration(milliseconds: 1200),
  }) {
    _responseDebounce?.cancel();
    _cancelFollowUpIdleTimeout();
    _cancelPendingBackgroundCompletionDelivery();
    _cancelPendingResponseRecovery();
    _clearToolFallback();
    _pendingResponseInterruptedBySpeech = false;
    _pendingResponseDeferredAt = null;
    onStatus?.call('listening');
    _responseDebounce = Timer(delay, () {
      final content = _pendingUserContent?.trim() ?? '';
      if (!_active || !_conversationActive || content.isEmpty) return;
      _voiceReleasePending = false;
      _armToolFallback(content);
      onStatus?.call('thinking');
      unawaited(_sendResponseCreateAfterContextRefresh(content));
    });
  }

  Future<void> _sendResponseCreateAfterContextRefresh(String content) async {
    final shouldRefreshDashboardContext =
        _realtimeShouldRefreshDashboardContextBeforeResponse(
          content,
          isFollowUpTurn: _currentTurnIsFollowUp,
          previousTurnNeededDashboardContext: _lastTurnNeededDashboardContext,
        );
    var freshContextReady = !shouldRefreshDashboardContext;
    String? freshContextUnavailableReason;
    if (shouldRefreshDashboardContext) {
      final refreshStartedAt = DateTime.now();
      try {
        final sessionId = _session?.session.id;
        final channel = _dataChannel;
        if (sessionId != null &&
            channel?.state == RTCDataChannelState.RTCDataChannelOpen) {
          final instructions = await _fetchRealtimeDashboardInstructions(
            sessionId,
          ).timeout(_contextRefreshBudget);
          final currentContent = _pendingUserContent?.trim() ?? '';
          if (_active &&
              _conversationActive &&
              currentContent == content &&
              !_assistantSpeaking) {
            final ackBudget = _remainingContextRefreshBudget(refreshStartedAt);
            final acked = await _sendRealtimeDashboardInstructionsAndWait(
              instructions,
              ackTimeout: ackBudget,
            );
            if (acked) {
              freshContextReady = true;
              _currentTurnContextRefreshSucceeded = true;
              unawaited(
                _logClientEvent('dashboard_context_pre_response_success', {
                  'elapsed_ms': DateTime.now()
                      .difference(refreshStartedAt)
                      .inMilliseconds,
                  'ack_budget_ms': ackBudget.inMilliseconds,
                }),
              );
            } else {
              unawaited(
                _logClientEvent('dashboard_context_pre_response_ack_timeout', {
                  'budget_ms': ackBudget.inMilliseconds,
                }),
              );
              freshContextUnavailableReason = 'ack_timeout';
            }
          }
        }
      } on TimeoutException {
        freshContextUnavailableReason = 'timeout';
        unawaited(
          _logClientEvent('dashboard_context_pre_response_timeout', {
            'budget_ms': _contextRefreshBudget.inMilliseconds,
          }),
        );
      } catch (error) {
        freshContextUnavailableReason = 'failure';
        unawaited(
          _logClientEvent('dashboard_context_pre_response_failure', {
            'message': error.toString(),
          }),
        );
      }
    }
    _lastTurnNeededDashboardContext = shouldRefreshDashboardContext;
    final currentContent = _pendingUserContent?.trim() ?? '';
    if (!_active ||
        !_conversationActive ||
        currentContent.isEmpty ||
        currentContent != content ||
        _userSpeechActive) {
      if (_userSpeechActive && currentContent == content) {
        unawaited(
          _logClientEvent(
            'flutter_realtime_response_create_deferred_by_speech',
            {'user_content': content},
          ),
        );
        if (!_pendingResponseInterruptedBySpeech) {
          _markPendingResponseDeferredBySpeech(
            userContent: content,
            responseCreateWasInFlight: false,
            source: 'context_refresh',
          );
        }
      }
      return;
    }
    if (shouldRefreshDashboardContext && !freshContextReady) {
      final reason = freshContextUnavailableReason ?? 'unavailable';
      _markFreshContextRecoveryPending(content, reason: reason);
      final sent = _sendFreshContextUnavailableItem(content, reason: reason);
      unawaited(
        _logClientEvent('dashboard_context_pre_response_routed_to_background', {
          'user_content': content,
          'reason': reason,
          'fallback_item_sent': sent,
        }),
      );
      if (!sent) {
        unawaited(_queueFallbackWork(content));
        return;
      }
    }
    _sendResponseCreate(
      audioResponse: true,
      textResponse: true,
      userContent: content,
    );
  }

  void _markFreshContextRecoveryPending(
    String userContent, {
    required String reason,
  }) {
    _pendingFreshContextRecoveryContent = userContent
        .replaceAll(RegExp(r'\s+'), ' ')
        .trim();
    _pendingFreshContextRecoveryReason = reason
        .replaceAll(RegExp(r'\s+'), ' ')
        .trim();
  }

  Future<String> _fetchRealtimeDashboardInstructions(int sessionId) async {
    final context = await apiClient.realtimeDashboardContext(
      sessionId: sessionId,
    );
    return context['instructions']?.toString().trim() ?? '';
  }

  bool _sendRealtimeDashboardInstructions(String instructions) {
    final channel = _dataChannel;
    if (instructions.trim().isEmpty ||
        channel?.state != RTCDataChannelState.RTCDataChannelOpen) {
      return false;
    }
    channel!.send(
      RTCDataChannelMessage(
        jsonEncode(_realtimeSessionUpdatePayload(instructions)),
      ),
    );
    return true;
  }

  bool _sendFreshContextUnavailableItem(
    String userContent, {
    String reason = '',
  }) {
    final channel = _dataChannel;
    if (channel?.state != RTCDataChannelState.RTCDataChannelOpen) {
      return false;
    }
    final payload = _realtimeFreshContextUnavailableItemPayload(
      userContent: userContent,
      reason: reason,
    );
    try {
      channel!.send(RTCDataChannelMessage(jsonEncode(payload)));
      return true;
    } catch (_) {
      return false;
    }
  }

  Future<bool> _sendRealtimeDashboardInstructionsAndWait(
    String instructions, {
    required Duration ackTimeout,
  }) async {
    if (instructions.trim().isEmpty) return false;
    final channel = _dataChannel;
    if (channel?.state != RTCDataChannelState.RTCDataChannelOpen) {
      return false;
    }
    if (ackTimeout <= Duration.zero) {
      _sendRealtimeDashboardInstructions(instructions);
      return false;
    }

    final completer = Completer<void>();
    _sessionUpdateAckCompleter = completer;
    late final bool sent;
    try {
      sent = _sendRealtimeDashboardInstructions(instructions);
    } catch (_) {
      if (identical(_sessionUpdateAckCompleter, completer)) {
        _sessionUpdateAckCompleter = null;
      }
      rethrow;
    }
    if (!sent) {
      if (identical(_sessionUpdateAckCompleter, completer)) {
        _sessionUpdateAckCompleter = null;
      }
      return false;
    }

    try {
      await completer.future.timeout(ackTimeout);
      return true;
    } on TimeoutException {
      return false;
    } finally {
      if (identical(_sessionUpdateAckCompleter, completer)) {
        _sessionUpdateAckCompleter = null;
      }
    }
  }

  void _appendAssistantDelta(Map<String, Object?> payload) {
    if (_realtimePayloadMatchesInterruptedResponse(payload)) return;
    final delta = payload['delta']?.toString() ?? '';
    if (delta.isEmpty) return;
    final id =
        payload['item_id']?.toString() ??
        payload['response_id']?.toString() ??
        _assistantItemId ??
        'assistant-${DateTime.now().microsecondsSinceEpoch}';
    if (_assistantItemId != id) {
      _assistantItemId = id;
      _assistantDraft = '';
    }
    _assistantDraft += delta;
    _assistantSpeaking = true;
    _assistantAudioStartedAt ??= DateTime.now();
    _firstAssistantSignalAt ??= DateTime.now();
    onStatus?.call("Bean's voice");
  }

  void _finishAssistantTranscript(Map<String, Object?> payload) {
    if (_realtimePayloadMatchesInterruptedResponse(payload)) return;
    final text =
        payload['transcript']?.toString().trim() ??
        payload['text']?.toString().trim() ??
        '';
    if (text.isEmpty) return;
    _assistantItemId =
        payload['item_id']?.toString() ?? payload['response_id']?.toString();
    _assistantDraft = text;
    _lastAssistantText = text;
    _firstAssistantSignalAt ??= DateTime.now();
    _recordSpokenSegment(text);
    onTranscript?.call('assistant', text);
  }

  void _handleAssistantAudioDone(Map<String, Object?> payload) {
    if (_realtimePayloadMatchesInterruptedResponse(payload)) return;
    if (!_realtimeAssistantAudioDoneMatchesCurrent(
      payloadResponseId: _responseIdFromPayload(payload),
      currentResponseId: _currentResponseId,
    )) {
      return;
    }
    final audioElapsedMs = _assistantAudioElapsedMs();
    _assistantSpeaking = false;
    _assistantAudioStartedAt = null;
    if (_conversationActive && !_transcriptionOnlyReleasePending) {
      setMicrophoneEnabled(!_endConversationAfterResponse);
    }
    final status = _realtimeStatusAfterAssistantAudioDone(
      conversationActive: _conversationActive,
      transcriptionOnlyReleasePending: _transcriptionOnlyReleasePending,
      backgroundWorkActive: _backgroundWorkActive,
      endConversationAfterResponse: _endConversationAfterResponse,
    );
    if (status != null) onStatus?.call(status);
    unawaited(
      _logClientEvent(
        'flutter_realtime_audio_done_ready',
        _realtimeAudioDoneReadyDetails(
          responseId: _responseIdFromPayload(payload) ?? _currentResponseId,
          status: status,
          conversationActive: _conversationActive,
          micEnabled: _microphoneAudioTracksEnabled(),
          microphoneTrackCount: _microphoneAudioTrackCount(),
          transcriptionOnlyReleasePending: _transcriptionOnlyReleasePending,
          backgroundWorkActive: _backgroundWorkActive,
          audioElapsedMs: audioElapsedMs,
        ),
      ),
    );
    _schedulePendingBackgroundCompletionDeliveryAfterSpeechStopped();
  }

  void _processResponseDone(Map<String, Object?> payload) {
    final payloadResponseId = _responseIdFromPayload(payload);
    final responseId = payloadResponseId ?? _currentResponseId;
    final interrupted = _consumeInterruptedResponse(
      payloadResponseId,
      consumePendingWhenResponseIdMissing:
          _missingResponseIdShouldConsumeInterruptedResponse(
            payloadResponseId: payloadResponseId,
            hasPendingInterruptedResponseIds:
                _interruptedResponseIds.isNotEmpty,
          ),
    );
    final responseModel = _responseModelFromPayload(payload);
    final responseUsage = _responseUsageFromPayload(payload);
    final responseDoneMatchesCurrent = _responseDoneMatchesCurrentResponse(
      payloadResponseId: payloadResponseId,
      currentResponseId: _currentResponseId,
      interrupted: interrupted,
    );
    if (responseDoneMatchesCurrent) {
      _responseCreateInFlight = _realtimeResponseCreateInFlightAfterEvent(
        current: _responseCreateInFlight,
        eventType: 'response.done',
        responseDoneMatchesCurrent: true,
      );
      _assistantSpeaking = false;
      _currentResponseId = null;
      _assistantAudioStartedAt = null;
    }

    if (interrupted) {
      if (responseDoneMatchesCurrent) {
        _assistantDraft = '';
        _assistantItemId = null;
        _pendingFunctionCalls.clear();
        _pendingProgressPrompt = null;
        _ignoreNextFunctionCalls = false;
      }
      if (responseDoneMatchesCurrent &&
          !_interruptedResponseShouldPreserveActiveTurn(
            pendingUserContent: _pendingUserContent,
            currentUserContent: _currentUserContent,
          )) {
        _resetTurnMetrics();
        _finishRealtimeTurnStatus();
      }
      return;
    }

    final responseStatus = _responseStatusFromPayload(payload);
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
    _currentTurnToolCallCount += functionCalls.length;

    final hasFunctionCall = functionCalls.isNotEmpty;
    final assistantAnswered = responseAssistantText.isNotEmpty;
    _lastResponseDoneAt = DateTime.now();
    final pendingUserContent = _realtimeActiveResponseUserContent(
      activeResponseUserContent: _activeResponseUserContent,
      pendingUserContent: _pendingUserContent,
      currentUserContent: _currentUserContent,
    );
    if (_realtimeResponseStatusIsFailure(responseStatus)) {
      _clearToolFallback();
      _pendingProgressPrompt = null;
      unawaited(
        _logClientEvent(
          'flutter_realtime_response_failed',
          _realtimeResponseFailureDetails(
            payload: payload,
            responseId: responseId,
            userContent: pendingUserContent,
            assistantText: responseAssistantText,
            functionCallCount: functionCalls.length,
            latencyMetrics: _currentLatencyMetrics(),
          ),
        ),
      );
      _assistantDraft = '';
      _assistantItemId = null;
      _suppressNextAssistantPersist = false;
      _voiceOnlyAssistant = false;
      _ignoreNextFunctionCalls = false;
      _pendingUserContent = null;
      _pendingUserItemId = null;
      _currentUserContent = null;
      if (_voiceCommandNeedsAgentWork(pendingUserContent)) {
        unawaited(
          _queueFallbackWork(
            pendingUserContent,
            quickReplyText: responseAssistantText,
          ),
        );
        return;
      }
      onStatus?.call(
        _realtimeResponseFailureUserMessage(responseStatus) ??
            'Bean voice is reconnecting',
      );
      _finishRealtimeTurnStatus();
      return;
    }

    final queueOnly =
        hasFunctionCall &&
        functionCalls.every((call) => call.name == 'queue_bean_work');
    final backgroundQueueAllowed = _realtimeSpokenAnswerAllowsBackgroundQueue(
      pendingUserContent,
      responseAssistantText,
    );
    final backgroundQueueShouldSkip =
        _realtimeSpokenAnswerShouldSkipBackgroundQueue(
          pendingUserContent,
          responseAssistantText,
        );
    final prematureCompletionClaim =
        queueOnly &&
        _realtimeSpokenAnswerClaimsCompletedWork(responseAssistantText);
    final unsupportedDirectAnswer = _realtimeUnsupportedDirectAnswer(
      userTranscript: pendingUserContent,
      assistantText: responseAssistantText,
      hasFunctionCall: hasFunctionCall,
      contextRefreshSucceeded: _currentTurnContextRefreshSucceeded,
    );
    if (unsupportedDirectAnswer != null) {
      unawaited(
        _logClientEvent('flutter_realtime_unsupported_direct_answer', {
          'user_content': pendingUserContent,
          'assistant_text': responseAssistantText,
          ...unsupportedDirectAnswer,
        }),
      );
    }

    _logBargeInRecoveryIfPending(
      userContent: pendingUserContent,
      assistantText: responseAssistantText,
      functionCallCount: functionCalls.length,
      responseId: responseId,
    );

    unawaited(
      _logClientEvent('flutter_realtime_response_done', {
        'user_content': pendingUserContent,
        'assistant_text': responseAssistantText,
        'assistant_answered': assistantAnswered,
        'voice_only_assistant': _voiceOnlyAssistant,
        'is_follow_up_turn': _currentTurnIsFollowUp,
        'is_contextual_follow_up_turn': _currentTurnIsContextualFollowUp,
        if (_currentTurnContextualFollowUpKind.isNotEmpty)
          'contextual_follow_up_kind': _currentTurnContextualFollowUpKind,
        'background_queue_allowed': backgroundQueueAllowed,
        'background_queue_should_skip': backgroundQueueShouldSkip,
        'premature_completion_claim': prematureCompletionClaim,
        ..._currentLatencyMetrics(),
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
    _logProgressPromptSpokenIfPending(
      responseAssistantText: responseAssistantText,
      responseId: responseId,
      responseDoneMatchesCurrent: responseDoneMatchesCurrent,
    );
    unawaited(
      _recordRealtimeUsage(
        responseId: responseId,
        model: responseModel,
        usage: responseUsage ?? const {},
        assistantText: responseAssistantText,
        usageMissing: responseUsage == null,
      ),
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

    if (prematureCompletionClaim && pendingUserContent.isNotEmpty) {
      unawaited(
        _logClientEvent('flutter_realtime_premature_completion_claim', {
          'user_content': pendingUserContent,
          'assistant_text': responseAssistantText,
        }),
      );
    }

    if (assistantAnswered && queueOnly && backgroundQueueShouldSkip) {
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
      if (_realtimeUnsupportedDirectAnswerNeedsBackgroundVerification(
            unsupportedDirectAnswer,
          ) &&
          pendingUserContent.isNotEmpty) {
        unawaited(_persistRealtimeTurn());
        unawaited(
          _logClientEvent('flutter_realtime_unsupported_direct_answer_queued', {
            'user_content': pendingUserContent,
            'assistant_text': responseAssistantText,
            ...?unsupportedDirectAnswer,
          }),
        );
        unawaited(
          _queueFallbackWork(
            pendingUserContent,
            verificationClaimText: responseAssistantText,
          ),
        );
        return;
      }
      if (backgroundQueueAllowed &&
          _voiceCommandRequiresBackgroundWork(pendingUserContent)) {
        unawaited(_persistRealtimeTurn());
        unawaited(
          _queueFallbackWork(
            pendingUserContent,
            quickReplyText: responseAssistantText,
          ),
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

  void _logProgressPromptSpokenIfPending({
    required String responseAssistantText,
    required String? responseId,
    required bool responseDoneMatchesCurrent,
  }) {
    final progressPrompt = _pendingProgressPrompt;
    if (progressPrompt == null ||
        !_voiceOnlyAssistant ||
        !responseDoneMatchesCurrent) {
      return;
    }

    _pendingProgressPrompt = null;
    unawaited(
      _logClientEvent(
        'flutter_realtime_progress_prompt_spoken',
        _realtimeProgressPromptSpokenDetails(
          responseId: responseId,
          elapsedMs: progressPrompt.elapsedMs,
          instruction: progressPrompt.instruction,
          userRequest: progressPrompt.userRequest,
          alreadySpoken: progressPrompt.alreadySpoken,
          spokenText: responseAssistantText,
        ),
      ),
    );
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
        _realtimeLateToolCallDuplicatesFallback(
          activeUserContent: activeUserContent,
          fallbackQueuedUserContent: _fallbackQueuedUserContent,
        )) {
      _processedCalls.add(callKey);
      _sendFunctionOutput(callId, {
        'ok': true,
        'skipped': true,
        'message':
            'This background request was already queued by voice recovery.',
      }, createResponse: false);
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
        _realtimeSpokenAnswerShouldSkipBackgroundQueue(
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
      final backgroundQuickReplyText = _realtimeBackgroundQuickReplyContext(
        quickReplyText,
      );
      _setBackgroundWorkActive(
        true,
        userContent: activeUserContent,
        quickReplyText: backgroundQuickReplyText,
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
        _activeBackgroundRunIds.add(runId);
        _pendingUserContent = null;
        _pendingUserItemId = null;
        _currentUserContent = null;
        _logBackgroundQueued(
          runId: runId,
          userContent: activeUserContent,
          quickReplyText: quickReplyText,
          source: 'tool_call',
        );
        onRunQueued?.call(runId, activeUserContent);
        unawaited(
          _watchRun(
            runId,
            userContent: activeUserContent,
            quickReplyText: _realtimeBackgroundQuickReplyContext(
              quickReplyText,
            ),
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
        'message': 'Bean is routing that request through chat now.',
      });
      onStatus?.call('checking...');
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
    _toolFallbackTimer = Timer(_toolFallbackTimeout, () {
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
    String content, {
    String quickReplyText = '',
    String verificationClaimText = '',
  }) async {
    final sessionId = _session?.session.id;
    if (sessionId == null) return;
    final cleanQuickReply = _realtimeBackgroundQuickReplyContext(
      quickReplyText,
    );
    if (cleanQuickReply.isNotEmpty &&
        _realtimeSpokenAnswerShouldSkipBackgroundQueue(
          content,
          cleanQuickReply,
        )) {
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
        _activeBackgroundRunIds.add(runId);
        _fallbackQueuedUserContent = content.trim();
        _logBackgroundQueued(
          runId: runId,
          userContent: content,
          quickReplyText: cleanQuickReply,
          source: 'fallback',
        );
        onRunQueued?.call(runId, content);
        unawaited(
          _watchRun(
            runId,
            userContent: content,
            quickReplyText: cleanQuickReply,
            verificationClaimText: verificationClaimText,
          ),
        );
      } else {
        _setBackgroundWorkActive(false);
      }
    } catch (error) {
      _setBackgroundWorkActive(false);
      final failureVoice = _realtimeBackgroundFailureVoice(content);
      final delivered = _deliverBackgroundResult(
        failureVoice,
        resultStatus: 'failed',
      );
      if (!delivered) {
        onStatus?.call('Bean voice is reconnecting');
      }
      unawaited(
        _logClientEvent('realtime_tool_fallback_failure', {
          'message': error.toString(),
          'user_content': content,
          'failure_voice_text': failureVoice,
          'failure_voice_acknowledged':
              _realtimeBackgroundFailureVoiceAcknowledgesFailure(failureVoice),
          'failure_voice_delivered': delivered,
        }),
      );
    }
  }

  void _logBackgroundQueued({
    required int runId,
    required String userContent,
    required String quickReplyText,
    required String source,
  }) {
    final cleanQuickReply = _realtimeBackgroundQuickReplyContext(
      quickReplyText,
    );
    unawaited(
      _logClientEvent('realtime_background_queued', {
        'run_id': runId,
        'source': source,
        'acknowledged': cleanQuickReply.isNotEmpty,
        'acknowledgement_character_count': cleanQuickReply.length,
        'queue_elapsed_ms': _elapsedMs(_turnStartedAt, DateTime.now()),
        'user_content': userContent,
        ..._consumeFreshContextRecoveryTelemetry(userContent),
      }),
    );
  }

  Map<String, Object?> _consumeFreshContextRecoveryTelemetry(
    String userContent,
  ) {
    final details = _realtimeFreshContextRecoveryTelemetry(
      pendingContent: _pendingFreshContextRecoveryContent,
      pendingReason: _pendingFreshContextRecoveryReason,
      userContent: userContent,
    );
    if (details.isEmpty) {
      return const {};
    }

    _pendingFreshContextRecoveryContent = '';
    _pendingFreshContextRecoveryReason = '';

    return details;
  }

  Future<void> _watchRun(
    int runId, {
    String userContent = '',
    String quickReplyText = '',
    String verificationClaimText = '',
    int attempt = 0,
  }) async {
    await Future<void>.delayed(
      attempt == 0
          ? const Duration(milliseconds: 900)
          : Duration(milliseconds: (1800 + (attempt * 450)).clamp(1800, 4500)),
    );
    if (!_active) return;
    if (!_activeBackgroundRunIds.contains(runId)) return;
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
              verificationClaimText: verificationClaimText,
              attempt: attempt + 1,
            ),
          );
        } else {
          _handleBackgroundWatchFailure(
            runId: runId,
            userContent: userContent,
            attempt: attempt,
            reason: 'max_poll_attempts',
            status: run.status,
          );
        }
        return;
      }
      if (run.status == 'completed') {
        final assistantMessage = run.assistantMessage;
        if (_assistantMessageShouldStayOutOfRealtimeResult(assistantMessage)) {
          unawaited(
            _logClientEvent('realtime_background_completed_without_voice', {
              'run_id': runId,
              'assistant_message_id': assistantMessage?.id,
            }),
          );
          _finishBackgroundRun(runId);
          _finishRealtimeTurnStatus();
          return;
        }
        final content = assistantMessage?.content?.trim() ?? '';
        if (content.isEmpty) {
          final failureVoice = _realtimeBackgroundFailureVoice(userContent);
          _finishBackgroundRun(runId);
          final delivered = _deliverBackgroundResult(
            failureVoice,
            runId: runId,
            resultStatus: 'failed',
          );
          if (!delivered) {
            _finishRealtimeTurnStatus();
          }
          unawaited(
            _logClientEvent('realtime_background_complete_empty', {
              'run_id': runId,
              'user_content': userContent,
              'failure_voice_text': failureVoice,
              'failure_voice_acknowledged':
                  _realtimeBackgroundFailureVoiceAcknowledgesFailure(
                    failureVoice,
                  ),
              'failure_voice_delivered': delivered,
            }),
          );
          return;
        }
        unawaited(refreshDashboardContext());
        final finalVoice = _finalVoiceForTurn(
          userContent,
          quickReplyText,
          content,
        );
        _finishBackgroundRun(runId);
        if (finalVoice.suppressFinal) {
          unawaited(
            _logClientEvent('realtime_background_completed_without_voice', {
              'run_id': runId,
              'suppressed_final_voice': true,
            }),
          );
          _finishRealtimeTurnStatus();
          return;
        }
        final finalSpokenText = finalVoice.text.isNotEmpty
            ? finalVoice.text
            : content;
        if (_backgroundResultDeliveryBusy()) {
          _pendingBackgroundCompletion = _PendingBackgroundCompletion(
            runId: runId,
            spokenText: finalSpokenText,
            priorSpokenClaim: verificationClaimText,
          );
          unawaited(
            _logClientEvent(
              'realtime_background_completion_deferred',
              _realtimeBackgroundDeliveryDeferredDetails(
                runId: runId,
                spokenText: finalSpokenText,
                priorSpokenClaim: verificationClaimText,
                assistantSpeaking: _assistantSpeaking,
                responseCreateInFlight: _responseCreateInFlight,
                userSpeechActive: _userSpeechActive,
              ),
            ),
          );
          return;
        }
        final delivered = _deliverBackgroundResult(
          finalSpokenText,
          runId: runId,
          priorSpokenClaim: verificationClaimText,
        );
        unawaited(
          _logClientEvent(
            _realtimeBackgroundCompletionEventType(deliveredVoice: delivered),
            {
              'run_id': runId,
              'spoken_character_count': delivered ? finalSpokenText.length : 0,
              if (delivered) 'spoken_text': finalSpokenText,
              ..._realtimeVerificationRepairTelemetry(verificationClaimText),
            },
          ),
        );
        return;
      }
      if (run.status == 'failed') {
        final failureVoice = _realtimeBackgroundFailureVoice(userContent);
        unawaited(
          _logClientEvent('realtime_background_failed', {
            'run_id': runId,
            'failure_voice_text': failureVoice,
            'failure_voice_acknowledged':
                _realtimeBackgroundFailureVoiceAcknowledgesFailure(
                  failureVoice,
                ),
          }),
        );
        _finishBackgroundRun(runId);
        _deliverBackgroundResult(
          failureVoice,
          runId: runId,
          resultStatus: 'failed',
        );
        return;
      }
      if (run.status == 'cancelled') {
        _finishBackgroundRun(runId);
        final delivered = _deliverBackgroundResult(
          'That request was cancelled.',
          runId: runId,
          resultStatus: 'cancelled',
        );
        if (!delivered) {
          _finishRealtimeTurnStatus();
        }
        unawaited(
          _logClientEvent(
            _realtimeBackgroundCancelEventType(deliveredVoice: delivered),
            {
              'run_id': runId,
              'spoken_character_count': delivered
                  ? 'That request was cancelled.'.length
                  : 0,
            },
          ),
        );
        return;
      }
      _handleBackgroundWatchFailure(
        runId: runId,
        userContent: userContent,
        attempt: attempt,
        reason: 'unexpected_status',
        status: run.status,
      );
    } catch (error) {
      if (attempt < 8) {
        unawaited(
          _watchRun(
            runId,
            userContent: userContent,
            quickReplyText: quickReplyText,
            verificationClaimText: verificationClaimText,
            attempt: attempt + 1,
          ),
        );
        return;
      }
      _handleBackgroundWatchFailure(
        runId: runId,
        userContent: userContent,
        attempt: attempt,
        reason: 'poll_error',
        message: error.toString(),
      );
    }
  }

  void _handleBackgroundWatchFailure({
    required int runId,
    required String userContent,
    required int attempt,
    required String reason,
    String status = '',
    String message = '',
  }) {
    final failureVoice = _realtimeBackgroundFailureVoice(userContent);
    _finishBackgroundRun(runId);
    final delivered = _deliverBackgroundResult(
      failureVoice,
      runId: runId,
      resultStatus: 'failed',
    );
    if (!delivered) {
      _finishRealtimeTurnStatus();
    }
    unawaited(
      _logClientEvent(
        'realtime_background_watch_failure',
        _realtimeBackgroundWatchFailureDetails(
          runId: runId,
          userContent: userContent,
          attempt: attempt,
          reason: reason,
          status: status,
          message: message,
          failureVoice: failureVoice,
          delivered: delivered,
        ),
      ),
    );
  }

  bool _deliverBackgroundResult(
    String content, {
    int? runId,
    String resultStatus = 'completed',
    String priorSpokenClaim = '',
  }) {
    if (!_conversationActive) return false;
    final channel = _dataChannel;
    if (channel?.state != RTCDataChannelState.RTCDataChannelOpen) {
      return false;
    }
    final text = _speechTextFromAssistant(content);
    if (text.isEmpty) return false;
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
                  'result_status': resultStatus,
                  'result': text,
                  if (priorSpokenClaim.trim().isNotEmpty)
                    'prior_spoken_claim': priorSpokenClaim
                        .replaceAll(RegExp(r'\s+'), ' ')
                        .trim(),
                  'already_spoken': alreadySpoken,
                  'instruction': _realtimeBackgroundResultInstruction(
                    resultStatus,
                    priorSpokenClaim: priorSpokenClaim,
                  ),
                  'rules': [
                    if (resultStatus == 'failed')
                      'Do not claim the request succeeded or is still being worked on.',
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
    _startTurnMetrics();
    _sendResponseCreate(
      audioResponse: true,
      textResponse: true,
      userContent: '',
    );
    return true;
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

  void _finishBackgroundRun(int runId) {
    _activeBackgroundRunIds.remove(runId);
    if (_activeBackgroundRunIds.isEmpty) {
      _fallbackQueuedUserContent = '';
      _setBackgroundWorkActive(false);
      return;
    }
    _showWorkingStatusWhenReady();
  }

  Future<void> _cancelActiveBackgroundRuns() async {
    final runIds = _activeBackgroundRunIds.toList(growable: false);
    if (runIds.isEmpty) return;
    _activeBackgroundRunIds.clear();
    _fallbackQueuedUserContent = '';
    _setBackgroundWorkActive(false);
    await Future.wait(
      runIds.map((runId) async {
        try {
          await apiClient.cancelAssistantRun(runId);
        } catch (error) {
          unawaited(
            _logClientEvent('realtime_background_cancel_failure', {
              'run_id': runId,
              'message': error.toString(),
            }),
          );
        }
      }),
    );
    unawaited(
      _logClientEvent('realtime_background_cancelled_by_voice', {
        'run_ids': runIds,
      }),
    );
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
    if (_assistantSpeaking || _responseCreateInFlight) {
      unawaited(
        _logClientEvent(
          'flutter_realtime_progress_prompt_skipped',
          _realtimeBackgroundProgressSkipDetails(
            elapsedMs: elapsedMs,
            instruction: instruction,
            assistantSpeaking: _assistantSpeaking,
            responseCreateInFlight: _responseCreateInFlight,
          ),
        ),
      );
      return;
    }
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
    _pendingProgressPrompt = _PendingProgressPrompt(
      elapsedMs: elapsedMs,
      instruction: instruction,
      userRequest: _backgroundUserContent,
      alreadySpoken: alreadySpoken,
    );
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
    _startTurnMetrics();
    _sendResponseCreate(
      audioResponse: true,
      textResponse: true,
      userContent: '',
    );
  }

  _FinalVoice _finalVoiceForTurn(
    String userContent,
    String quickReplyText,
    String assistantContent,
  ) =>
      _realtimeFinalVoiceForTurn(userContent, quickReplyText, assistantContent);

  void _finishRealtimeTurnStatus() {
    if (_deliverPendingBackgroundCompletionIfReady()) {
      return;
    }
    _activeResponseUserContent = null;
    if (_conversationActive && !_transcriptionOnlyReleasePending) {
      setMicrophoneEnabled(!_endConversationAfterResponse);
    }
    if (_backgroundWorkActive) {
      _cancelFollowUpIdleTimeout();
      _showWorkingStatusWhenReady();
      return;
    }
    if (_endConversationAfterResponse) {
      _endConversationAfterResponse = false;
      _conversationActive = false;
      setMicrophoneEnabled(false);
      _cancelFollowUpIdleTimeout();
      onStatus?.call('Bean voice ready');
      return;
    }
    _scheduleFollowUpIdleTimeout();
    _logFollowUpReadyIfNeeded();
    onStatus?.call(_conversationActive ? 'listening' : 'Bean voice ready');
  }

  void _logFollowUpReadyIfNeeded() {
    if (_followUpReadyLoggedForTurn ||
        !_conversationActive ||
        _lastResponseDoneAt == null ||
        _transcriptionOnlyReleasePending ||
        _backgroundWorkActive) {
      return;
    }

    final readyAt = DateTime.now();
    final details = _realtimeFollowUpReadyDetails(
      responseDoneAt: _lastResponseDoneAt,
      readyAt: readyAt,
      conversationActive: _conversationActive,
      micEnabled: _microphoneAudioTracksEnabled(),
      microphoneTrackCount: _microphoneAudioTrackCount(),
      isFollowUp: _currentTurnIsFollowUp,
      isContextualFollowUp: _currentTurnIsContextualFollowUp,
      contextualFollowUpKind: _currentTurnContextualFollowUpKind,
      latencyMetrics: _currentLatencyMetrics(),
    );
    _followUpReadyLoggedForTurn = true;
    unawaited(_logClientEvent('flutter_realtime_followup_ready', details));
  }

  void _scheduleFollowUpIdleTimeout() {
    _cancelFollowUpIdleTimeout();
    if (!_shouldCloseRealtimeFollowUpIdle(
      conversationActive: _conversationActive,
      voiceCaptureActive: _voiceCaptureActive,
      voiceReleasePending: _voiceReleasePending,
      transcriptionOnlyReleasePending: _transcriptionOnlyReleasePending,
      backgroundWorkActive: _backgroundWorkActive,
      assistantSpeaking: _assistantSpeaking,
      pendingUserContent: _pendingUserContent,
      currentUserContent: _currentUserContent,
    )) {
      return;
    }
    _followUpIdleTimer = Timer(_followUpIdleTimeout, _expireFollowUpIdle);
  }

  void _cancelFollowUpIdleTimeout() {
    _followUpIdleTimer?.cancel();
    _followUpIdleTimer = null;
  }

  void _schedulePendingResponseRecoveryAfterSpeechStopped() {
    _cancelPendingResponseRecovery();
    if (!_shouldRecoverDeferredResponseAfterSpeechStop(
      pendingResponseInterruptedBySpeech: _pendingResponseInterruptedBySpeech,
      hasPendingUserContent: _pendingUserContent?.trim().isNotEmpty ?? false,
      userSpeechActive: _userSpeechActive,
      responseCreateInFlight: _responseCreateInFlight,
      responseDebounceActive: _responseDebounce?.isActive == true,
    )) {
      return;
    }
    _pendingResponseRecoveryTimer = Timer(_pendingResponseRecoveryGrace, () {
      _pendingResponseRecoveryTimer = null;
      if (!_shouldRecoverDeferredResponseAfterSpeechStop(
        pendingResponseInterruptedBySpeech: _pendingResponseInterruptedBySpeech,
        hasPendingUserContent: _pendingUserContent?.trim().isNotEmpty ?? false,
        userSpeechActive: _userSpeechActive,
        responseCreateInFlight: _responseCreateInFlight,
        responseDebounceActive: _responseDebounce?.isActive == true,
      )) {
        return;
      }
      _recoverDeferredResponseAfterNonActionableTranscript('');
    });
  }

  void _cancelPendingResponseRecovery() {
    _pendingResponseRecoveryTimer?.cancel();
    _pendingResponseRecoveryTimer = null;
  }

  void _schedulePendingBackgroundCompletionDeliveryAfterSpeechStopped() {
    _cancelPendingBackgroundCompletionDelivery();
    if (!_shouldDeliverPendingBackgroundCompletionAfterSpeechStop(
      hasPendingCompletion: _pendingBackgroundCompletion != null,
      backgroundDeliveryBusy: _backgroundResultDeliveryBusy(),
      hasPendingUserContent: _pendingUserContent?.trim().isNotEmpty ?? false,
      responseDebounceActive: _responseDebounce?.isActive == true,
      voiceCaptureActive: _voiceCaptureActive,
      voiceReleasePending: _voiceReleasePending,
      transcriptionOnlyReleasePending: _transcriptionOnlyReleasePending,
    )) {
      return;
    }
    _pendingBackgroundCompletionTimer = Timer(
      _pendingBackgroundDeliveryGrace,
      () {
        _pendingBackgroundCompletionTimer = null;
        if (!_shouldDeliverPendingBackgroundCompletionAfterSpeechStop(
          hasPendingCompletion: _pendingBackgroundCompletion != null,
          backgroundDeliveryBusy: _backgroundResultDeliveryBusy(),
          hasPendingUserContent:
              _pendingUserContent?.trim().isNotEmpty ?? false,
          responseDebounceActive: _responseDebounce?.isActive == true,
          voiceCaptureActive: _voiceCaptureActive,
          voiceReleasePending: _voiceReleasePending,
          transcriptionOnlyReleasePending: _transcriptionOnlyReleasePending,
        )) {
          return;
        }
        _finishRealtimeTurnStatus();
      },
    );
  }

  void _cancelPendingBackgroundCompletionDelivery() {
    _pendingBackgroundCompletionTimer?.cancel();
    _pendingBackgroundCompletionTimer = null;
  }

  void _expireFollowUpIdle() {
    _followUpIdleTimer = null;
    if (!_shouldCloseRealtimeFollowUpIdle(
      conversationActive: _conversationActive,
      voiceCaptureActive: _voiceCaptureActive,
      voiceReleasePending: _voiceReleasePending,
      transcriptionOnlyReleasePending: _transcriptionOnlyReleasePending,
      backgroundWorkActive: _backgroundWorkActive,
      assistantSpeaking: _assistantSpeaking,
      pendingUserContent: _pendingUserContent,
      currentUserContent: _currentUserContent,
    )) {
      return;
    }
    _conversationActive = false;
    setMicrophoneEnabled(false);
    onStatus?.call('Bean voice ready');
    unawaited(
      _logClientEvent('flutter_realtime_followup_idle_timeout', {
        'timeout_ms': _followUpIdleTimeout.inMilliseconds,
      }),
    );
  }

  bool _sendFunctionOutput(
    String? callId,
    Map<String, Object?> result, {
    bool createResponse = true,
  }) {
    final channel = _dataChannel;
    if (callId == null ||
        callId.isEmpty ||
        channel?.state != RTCDataChannelState.RTCDataChannelOpen) {
      _logToolOutputDispatchFailure(
        callId: callId,
        reason: 'data_channel_unavailable',
        createResponse: createResponse,
      );
      return false;
    }
    try {
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
    } catch (error) {
      _logToolOutputDispatchFailure(
        callId: callId,
        reason: 'dispatch_error',
        message: error.toString(),
        createResponse: createResponse,
      );
      return false;
    }
    if (createResponse) {
      _sendResponseCreate(
        audioResponse: true,
        textResponse: true,
        userContent: _realtimeActiveResponseUserContent(
          activeResponseUserContent: _activeResponseUserContent,
          pendingUserContent: _pendingUserContent,
          currentUserContent: _currentUserContent,
        ),
      );
    }
    return true;
  }

  void _logToolOutputDispatchFailure({
    required String? callId,
    required String reason,
    String message = '',
    required bool createResponse,
  }) {
    if (_active && createResponse) {
      onStatus?.call('Bean voice is reconnecting');
    }
    unawaited(
      _logClientEvent(
        'realtime_tool_call_failure',
        _realtimeToolOutputDispatchFailureDetails(
          callId: callId,
          reason: reason,
          message: message,
          createResponse: createResponse,
          latencyMetrics: _currentLatencyMetrics(),
        ),
      ),
    );
  }

  bool _sendResponseCreate({
    bool audioResponse = true,
    bool textResponse = true,
    String? userContent,
  }) {
    final channel = _dataChannel;
    if (channel?.state != RTCDataChannelState.RTCDataChannelOpen) {
      _logResponseCreateDispatchFailure(
        reason: 'data_channel_unavailable',
        audioResponse: audioResponse,
        textResponse: textResponse,
      );
      return false;
    }
    _cancelFollowUpIdleTimeout();
    _responseCreateRequestedAt = DateTime.now();
    _responseCreateInFlight = true;
    _activeResponseUserContent = _cleanRealtimeUserContent(userContent);
    try {
      channel!.send(
        RTCDataChannelMessage(
          jsonEncode(
            _realtimeResponseCreatePayload(
              audioResponse: audioResponse,
              textResponse: textResponse,
            ),
          ),
        ),
      );
    } catch (error) {
      _responseCreateInFlight = false;
      _activeResponseUserContent = null;
      _logResponseCreateDispatchFailure(
        reason: 'dispatch_error',
        message: error.toString(),
        audioResponse: audioResponse,
        textResponse: textResponse,
      );
      return false;
    }
    return true;
  }

  void _logResponseCreateDispatchFailure({
    required String reason,
    String message = '',
    required bool audioResponse,
    required bool textResponse,
  }) {
    if (_active) {
      onStatus?.call('Bean voice is reconnecting');
    }
    unawaited(
      _logClientEvent(
        'flutter_realtime_response_failed',
        _realtimeResponseCreateDispatchFailureDetails(
          reason: reason,
          message: message,
          audioResponse: audioResponse,
          textResponse: textResponse,
          latencyMetrics: _currentLatencyMetrics(),
        ),
      ),
    );
  }

  void _handleResponseOutputItemAdded(Map<String, Object?> payload) {
    final id = _realtimeAssistantOutputItemId(
      payload['item'],
      interruptedPayload: _realtimePayloadMatchesInterruptedResponse(payload),
    );
    if (id == null) return;
    _assistantItemId = id;
    _assistantSpeaking = true;
    _assistantAudioStartedAt ??= DateTime.now();
  }

  void _handleBargeInStarted() {
    _cancelBargeInConfirmation();
    final channel = _dataChannel;
    final startedAt = DateTime.now();
    _responseDebounce?.cancel();
    _toolFallbackTimer?.cancel();
    _pendingFunctionCalls.clear();
    _pendingProgressPrompt = null;
    final interruptedInternalPrompt = _realtimeBargeInInterruptsInternalPrompt(
      suppressAssistant: _suppressNextAssistantPersist,
      voiceOnlyAssistant: _voiceOnlyAssistant,
      ignoreFunctionCalls: _ignoreNextFunctionCalls,
    );
    _suppressNextAssistantPersist = false;
    _voiceOnlyAssistant = false;
    _ignoreNextFunctionCalls = false;
    _assistantInterrupted = true;
    _assistantSpeaking = false;

    final responseId = _currentResponseId;
    final assistantItemId = _assistantItemId;
    final elapsedMs = _assistantAudioElapsedMs();
    if (responseId != null && responseId.isNotEmpty) {
      _interruptedResponseIds.add(responseId);
    } else {
      _interruptedResponseWithoutId = true;
    }
    _bargeInRecoveryPending = true;
    _bargeInRecoveryStartedAt = startedAt;

    if (channel?.state != RTCDataChannelState.RTCDataChannelOpen) {
      final dispatchMs = DateTime.now().difference(startedAt).inMilliseconds;
      unawaited(
        _logClientEvent(
          'flutter_realtime_barge_in',
          _realtimeBargeInDispatchDetails(
            responseId: responseId,
            assistantItemId: assistantItemId,
            elapsedMs: elapsedMs,
            cancelSent: false,
            outputAudioCleared: false,
            truncateAttempted:
                assistantItemId != null &&
                assistantItemId.isNotEmpty &&
                elapsedMs > 0,
            truncateSent: false,
            dispatchMs: dispatchMs,
            interruptedInternalPrompt: interruptedInternalPrompt,
            dispatchError: 'data_channel_unavailable',
          ),
        ),
      );
      _assistantDraft = '';
      _assistantItemId = null;
      _currentResponseId = null;
      _assistantAudioStartedAt = null;
      onStatus?.call('listening');
      return;
    }

    var cancelSent = false;
    var outputAudioCleared = false;
    var truncateAttempted = false;
    var truncateSent = false;
    String? dispatchError;
    try {
      channel!.send(
        RTCDataChannelMessage(
          jsonEncode(_realtimeResponseCancelPayload(responseId)),
        ),
      );
      cancelSent = true;
    } catch (error) {
      dispatchError = error.toString();
    }
    try {
      channel!.send(
        RTCDataChannelMessage(
          jsonEncode(_realtimeOutputAudioBufferClearPayload()),
        ),
      );
      outputAudioCleared = true;
    } catch (error) {
      dispatchError ??= error.toString();
    }
    if (assistantItemId != null &&
        assistantItemId.isNotEmpty &&
        elapsedMs > 0) {
      truncateAttempted = true;
      try {
        channel!.send(
          RTCDataChannelMessage(
            jsonEncode(
              _realtimeConversationItemTruncatePayload(
                assistantItemId,
                elapsedMs,
              ),
            ),
          ),
        );
        truncateSent = true;
      } catch (error) {
        dispatchError ??= error.toString();
      }
    }
    final dispatchMs = DateTime.now().difference(startedAt).inMilliseconds;

    unawaited(
      _logClientEvent(
        'flutter_realtime_barge_in',
        _realtimeBargeInDispatchDetails(
          responseId: responseId,
          assistantItemId: assistantItemId,
          elapsedMs: elapsedMs,
          cancelSent: cancelSent,
          outputAudioCleared: outputAudioCleared,
          truncateAttempted: truncateAttempted,
          truncateSent: truncateSent,
          dispatchMs: dispatchMs,
          interruptedInternalPrompt: interruptedInternalPrompt,
          dispatchError: dispatchError,
        ),
      ),
    );

    _assistantDraft = '';
    _assistantItemId = null;
    _currentResponseId = null;
    _assistantAudioStartedAt = null;
    onStatus?.call('listening');
  }

  bool _realtimePayloadMatchesInterruptedResponse(
    Map<String, Object?> payload,
  ) {
    final responseId = _responseIdFromPayload(payload);
    if (responseId != null && responseId.isNotEmpty) {
      return _interruptedResponseIds.contains(responseId);
    }

    return _assistantInterrupted && _interruptedResponseWithoutId;
  }

  bool _consumeInterruptedResponse(
    String? responseId, {
    bool consumePendingWhenResponseIdMissing = false,
  }) {
    if (responseId != null && responseId.isNotEmpty) {
      final removed = _interruptedResponseIds.remove(responseId);
      if (_interruptedResponseIds.isEmpty && !_interruptedResponseWithoutId) {
        _assistantInterrupted = false;
      }
      return removed;
    }

    if (consumePendingWhenResponseIdMissing &&
        _interruptedResponseIds.isNotEmpty) {
      _interruptedResponseIds.remove(_interruptedResponseIds.first);
      if (_interruptedResponseIds.isEmpty && !_interruptedResponseWithoutId) {
        _assistantInterrupted = false;
      }
      return true;
    }

    if (_assistantInterrupted && _interruptedResponseWithoutId) {
      _interruptedResponseWithoutId = false;
      if (_interruptedResponseIds.isEmpty) {
        _assistantInterrupted = false;
      }
      return true;
    }

    return false;
  }

  int _assistantAudioElapsedMs() {
    final started = _assistantAudioStartedAt;
    if (started == null) return 0;
    final elapsed = DateTime.now().difference(started).inMilliseconds;
    return elapsed.clamp(0, 300000).toInt();
  }

  void _startTurnMetrics({
    bool isFollowUp = false,
    bool isContextualFollowUp = false,
    String contextualFollowUpKind = '',
  }) {
    _turnStartedAt = DateTime.now();
    _responseCreateRequestedAt = null;
    _responseCreateInFlight = false;
    _activeResponseUserContent = null;
    _firstAssistantSignalAt = null;
    _lastResponseDoneAt = null;
    _pendingResponseDeferredAt = null;
    _pendingFreshContextRecoveryContent = '';
    _pendingFreshContextRecoveryReason = '';
    _currentTurnToolCallCount = 0;
    _currentTurnIsFollowUp = isFollowUp;
    _currentTurnIsContextualFollowUp = isContextualFollowUp;
    _currentTurnContextualFollowUpKind = isContextualFollowUp
        ? contextualFollowUpKind
        : '';
    _currentTurnContextRefreshSucceeded = false;
    _followUpReadyLoggedForTurn = false;
  }

  bool _deliverPendingBackgroundCompletionIfReady() {
    final pending = _pendingBackgroundCompletion;
    if (pending == null || _backgroundResultDeliveryBusy()) return false;
    _cancelPendingBackgroundCompletionDelivery();
    _pendingBackgroundCompletion = null;
    final delivered = _deliverBackgroundResult(
      pending.spokenText,
      runId: pending.runId,
      priorSpokenClaim: pending.priorSpokenClaim,
    );
    unawaited(
      _logClientEvent(
        _realtimeBackgroundCompletionEventType(deliveredVoice: delivered),
        {
          'run_id': pending.runId,
          'spoken_character_count': delivered ? pending.spokenText.length : 0,
          if (delivered) 'spoken_text': pending.spokenText,
          ..._realtimeVerificationRepairTelemetry(pending.priorSpokenClaim),
          'deferred': true,
        },
      ),
    );
    return delivered;
  }

  bool _backgroundResultDeliveryBusy() => _realtimeBackgroundResultDeliveryBusy(
    assistantSpeaking: _assistantSpeaking,
    responseCreateInFlight: _responseCreateInFlight,
    userSpeechActive: _userSpeechActive,
  );

  void _resetTurnMetrics() {
    _turnStartedAt = null;
    _responseCreateRequestedAt = null;
    _responseCreateInFlight = false;
    _activeResponseUserContent = null;
    _firstAssistantSignalAt = null;
    _lastResponseDoneAt = null;
    _pendingFreshContextRecoveryContent = '';
    _pendingFreshContextRecoveryReason = '';
    _currentTurnToolCallCount = 0;
    _currentTurnIsFollowUp = false;
    _currentTurnIsContextualFollowUp = false;
    _currentTurnContextualFollowUpKind = '';
    _currentTurnContextRefreshSucceeded = false;
    _followUpReadyLoggedForTurn = false;
  }

  void _clearBargeInRecovery() {
    _bargeInRecoveryPending = false;
    _bargeInRecoveryStartedAt = null;
  }

  void _logBargeInRecoveryIfPending({
    required String userContent,
    required String assistantText,
    required int functionCallCount,
    String? responseId,
  }) {
    if (!_bargeInRecoveryPending) return;

    final details = _realtimeBargeInRecoveryDetails(
      startedAt: _bargeInRecoveryStartedAt,
      completedAt: DateTime.now(),
      userContent: userContent,
      assistantText: assistantText,
      functionCallCount: functionCallCount,
      responseId: responseId,
      latencyMetrics: _currentLatencyMetrics(),
    );
    _clearBargeInRecovery();
    unawaited(_logClientEvent('flutter_realtime_barge_in_recovered', details));
  }

  void _logBargeInRecoveryFailureIfPending({
    required String transcript,
    required String reason,
  }) {
    if (!_bargeInRecoveryPending) return;

    final details = _realtimeBargeInRecoveryFailureDetails(
      startedAt: _bargeInRecoveryStartedAt,
      completedAt: DateTime.now(),
      transcript: transcript,
      reason: reason,
      latencyMetrics: _currentLatencyMetrics(),
    );
    _clearBargeInRecovery();
    unawaited(
      _logClientEvent('flutter_realtime_barge_in_recovery_failed', details),
    );
  }

  Map<String, Object?> _currentLatencyMetrics() => _realtimeLatencyMetrics(
    turnStartedAt: _turnStartedAt,
    responseCreateRequestedAt: _responseCreateRequestedAt,
    firstAssistantSignalAt: _firstAssistantSignalAt,
    completedAt: DateTime.now(),
  );

  Future<void> _recordRealtimeUsage({
    String? responseId,
    String? model,
    required Map<String, Object?> usage,
    String assistantText = '',
    bool usageMissing = false,
  }) async {
    final sessionId = _session?.session.id;
    if (sessionId == null) return;
    final metrics = _currentLatencyMetrics();
    try {
      await apiClient.recordRealtimeUsage(
        sessionId: sessionId,
        model: model,
        responseId: responseId,
        usage: usage,
        voiceSeconds: (metrics['voice_seconds'] as num?)?.toDouble(),
        latencyMetrics: _realtimeUsageLatencyPayload(metrics),
        speechMetrics: _realtimeSpokenAnswerQuality(assistantText),
        turnMetrics: _realtimeTurnQualityPayload(
          isFollowUp: _currentTurnIsFollowUp,
          isContextualFollowUp: _currentTurnIsContextualFollowUp,
          contextualFollowUpKind: _currentTurnContextualFollowUpKind,
          usageMissing: usageMissing,
        ),
        toolCallCount: _currentTurnToolCallCount,
        actionTypes: const ['realtime_voice'],
      );
    } catch (_) {
      // Usage telemetry must not interrupt the realtime conversation.
    }
  }

  Future<void> _persistRealtimeTurn() async {
    final sessionId = _session?.session.id;
    final userContent = _realtimePersistedUserContent(
      pendingUserContent: _pendingUserContent,
      currentUserContent: _currentUserContent,
    );
    final userItemId = _pendingUserItemId;
    final assistantContent = _assistantDraft.trim();
    final assistantItemId = _assistantItemId;
    final suppressAssistant = _suppressNextAssistantPersist;
    _pendingUserContent = null;
    _pendingUserItemId = null;
    _currentUserContent = null;
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

  static String realtimePersistedUserContentForTesting({
    String? pendingUserContent,
    String? currentUserContent,
  }) => _realtimePersistedUserContent(
    pendingUserContent: pendingUserContent,
    currentUserContent: currentUserContent,
  );

  static String realtimeActiveResponseUserContentForTesting({
    String? activeResponseUserContent,
    String? pendingUserContent,
    String? currentUserContent,
  }) => _realtimeActiveResponseUserContent(
    activeResponseUserContent: activeResponseUserContent,
    pendingUserContent: pendingUserContent,
    currentUserContent: currentUserContent,
  );

  static bool realtimeInterruptedResponsePreservesTurnForTesting({
    String? pendingUserContent,
    String? currentUserContent,
  }) => _interruptedResponseShouldPreserveActiveTurn(
    pendingUserContent: pendingUserContent,
    currentUserContent: currentUserContent,
  );

  static bool realtimeResponseDoneMatchesCurrentForTesting({
    required String? payloadResponseId,
    required String? currentResponseId,
    required bool interrupted,
  }) => _responseDoneMatchesCurrentResponse(
    payloadResponseId: payloadResponseId,
    currentResponseId: currentResponseId,
    interrupted: interrupted,
  );

  static bool realtimeMissingResponseIdConsumesInterruptedForTesting({
    required String? payloadResponseId,
    required bool hasPendingInterruptedResponseIds,
  }) => _missingResponseIdShouldConsumeInterruptedResponse(
    payloadResponseId: payloadResponseId,
    hasPendingInterruptedResponseIds: hasPendingInterruptedResponseIds,
  );

  static Map<String, Object?> realtimeFinalVoiceForTesting({
    required String userContent,
    required String quickReplyText,
    required String assistantContent,
  }) {
    final voice = _realtimeFinalVoiceForTurn(
      userContent,
      quickReplyText,
      assistantContent,
    );

    return {'text': voice.text, 'suppress_final': voice.suppressFinal};
  }

  static Map<String, Object?> realtimeSpokenAnswerQualityForTesting(
    String text,
  ) => _realtimeSpokenAnswerQuality(text);

  static bool realtimeShouldCloseFollowUpIdleForTesting({
    required bool conversationActive,
    bool voiceCaptureActive = false,
    bool voiceReleasePending = false,
    bool transcriptionOnlyReleasePending = false,
    bool backgroundWorkActive = false,
    bool assistantSpeaking = false,
    String? pendingUserContent,
    String? currentUserContent,
  }) => _shouldCloseRealtimeFollowUpIdle(
    conversationActive: conversationActive,
    voiceCaptureActive: voiceCaptureActive,
    voiceReleasePending: voiceReleasePending,
    transcriptionOnlyReleasePending: transcriptionOnlyReleasePending,
    backgroundWorkActive: backgroundWorkActive,
    assistantSpeaking: assistantSpeaking,
    pendingUserContent: pendingUserContent,
    currentUserContent: currentUserContent,
  );

  static Duration realtimeFollowUpIdleTimeoutForTesting() =>
      _followUpIdleTimeout;

  static Duration pendingBackgroundDeliveryGraceForTesting() =>
      _pendingBackgroundDeliveryGrace;

  static Duration pendingResponseRecoveryGraceForTesting() =>
      _pendingResponseRecoveryGrace;

  static Map<String, Object?> realtimePendingResponseRecoveryDetailsForTesting({
    required DateTime? deferredAt,
    required DateTime recoveredAt,
    required String userContent,
    required String transcript,
    required bool synthetic,
  }) => _realtimePendingResponseRecoveryDetails(
    deferredAt: deferredAt,
    recoveredAt: recoveredAt,
    userContent: userContent,
    transcript: transcript,
    synthetic: synthetic,
  );

  static bool shouldRecoverDeferredResponseAfterSpeechStopForTesting({
    required bool pendingResponseInterruptedBySpeech,
    required bool hasPendingUserContent,
    bool userSpeechActive = false,
    bool responseCreateInFlight = false,
    bool responseDebounceActive = false,
  }) => _shouldRecoverDeferredResponseAfterSpeechStop(
    pendingResponseInterruptedBySpeech: pendingResponseInterruptedBySpeech,
    hasPendingUserContent: hasPendingUserContent,
    userSpeechActive: userSpeechActive,
    responseCreateInFlight: responseCreateInFlight,
    responseDebounceActive: responseDebounceActive,
  );

  static bool
  shouldDeliverPendingBackgroundCompletionAfterSpeechStopForTesting({
    required bool hasPendingCompletion,
    required bool backgroundDeliveryBusy,
    bool hasPendingUserContent = false,
    bool responseDebounceActive = false,
    bool voiceCaptureActive = false,
    bool voiceReleasePending = false,
    bool transcriptionOnlyReleasePending = false,
  }) => _shouldDeliverPendingBackgroundCompletionAfterSpeechStop(
    hasPendingCompletion: hasPendingCompletion,
    backgroundDeliveryBusy: backgroundDeliveryBusy,
    hasPendingUserContent: hasPendingUserContent,
    responseDebounceActive: responseDebounceActive,
    voiceCaptureActive: voiceCaptureActive,
    voiceReleasePending: voiceReleasePending,
    transcriptionOnlyReleasePending: transcriptionOnlyReleasePending,
  );

  static Duration realtimeContextRefreshBudgetForTesting() =>
      _contextRefreshBudget;

  static bool realtimeIsFollowUpTranscriptForTesting({
    required bool isWakeTurn,
    required bool conversationActive,
    bool voiceCaptureActive = false,
    bool voiceReleasePending = false,
    bool transcriptionOnlyReleasePending = false,
  }) => _isRealtimeFollowUpTranscript(
    isWakeTurn: isWakeTurn,
    conversationActive: conversationActive,
    voiceCaptureActive: voiceCaptureActive,
    voiceReleasePending: voiceReleasePending,
    transcriptionOnlyReleasePending: transcriptionOnlyReleasePending,
  );

  static Map<String, Object?> realtimeTurnQualityPayloadForTesting({
    required bool isFollowUp,
    bool isContextualFollowUp = false,
    String contextualFollowUpKind = '',
    bool usageMissing = false,
  }) => _realtimeTurnQualityPayload(
    isFollowUp: isFollowUp,
    isContextualFollowUp: isContextualFollowUp,
    contextualFollowUpKind: contextualFollowUpKind,
    usageMissing: usageMissing,
  );

  static Map<String, Object?> realtimeFollowUpReadyDetailsForTesting({
    required DateTime? responseDoneAt,
    required DateTime readyAt,
    required bool conversationActive,
    required bool micEnabled,
    required int microphoneTrackCount,
    required bool isFollowUp,
    bool isContextualFollowUp = false,
    String contextualFollowUpKind = '',
    Map<String, Object?> latencyMetrics = const {},
  }) => _realtimeFollowUpReadyDetails(
    responseDoneAt: responseDoneAt,
    readyAt: readyAt,
    conversationActive: conversationActive,
    micEnabled: micEnabled,
    microphoneTrackCount: microphoneTrackCount,
    isFollowUp: isFollowUp,
    isContextualFollowUp: isContextualFollowUp,
    contextualFollowUpKind: contextualFollowUpKind,
    latencyMetrics: latencyMetrics,
  );

  static Map<String, Object?> realtimeFreshContextRecoveryTelemetryForTesting({
    required String pendingContent,
    required String pendingReason,
    required String userContent,
  }) => _realtimeFreshContextRecoveryTelemetry(
    pendingContent: pendingContent,
    pendingReason: pendingReason,
    userContent: userContent,
  );

  static bool realtimeTranscriptLooksContextualFollowUpForTesting(
    String transcript,
  ) => _realtimeTranscriptLooksContextualFollowUp(transcript);

  static String realtimeContextualFollowUpKindForTesting(String transcript) =>
      _realtimeContextualFollowUpKind(transcript);

  static bool realtimeSpokenAnswerAllowsBackgroundQueueForTesting(
    String userTranscript,
    String assistantText,
  ) =>
      _realtimeSpokenAnswerAllowsBackgroundQueue(userTranscript, assistantText);

  static bool realtimeSpokenAnswerShouldSkipBackgroundQueueForTesting(
    String userTranscript,
    String assistantText,
  ) => _realtimeSpokenAnswerShouldSkipBackgroundQueue(
    userTranscript,
    assistantText,
  );

  static String realtimeBackgroundQuickReplyContextForTesting(String text) =>
      _realtimeBackgroundQuickReplyContext(text);

  static String realtimeBackgroundFailureVoiceForTesting(String userContent) =>
      _realtimeBackgroundFailureVoice(userContent);

  static bool realtimeBackgroundFailureVoiceAcknowledgesFailureForTesting(
    String text,
  ) => _realtimeBackgroundFailureVoiceAcknowledgesFailure(text);

  static Map<String, Object?> realtimeBackgroundWatchFailureDetailsForTesting({
    required int runId,
    required String userContent,
    required int attempt,
    required String reason,
    String status = '',
    String message = '',
    required String failureVoice,
    required bool delivered,
  }) => _realtimeBackgroundWatchFailureDetails(
    runId: runId,
    userContent: userContent,
    attempt: attempt,
    reason: reason,
    status: status,
    message: message,
    failureVoice: failureVoice,
    delivered: delivered,
  );

  static String realtimeBackgroundResultInstructionForTesting(
    String resultStatus, {
    String priorSpokenClaim = '',
  }) => _realtimeBackgroundResultInstruction(
    resultStatus,
    priorSpokenClaim: priorSpokenClaim,
  );

  static String realtimeBackgroundCompletionEventTypeForTesting({
    required bool deliveredVoice,
  }) => _realtimeBackgroundCompletionEventType(deliveredVoice: deliveredVoice);

  static String realtimeBackgroundCancelEventTypeForTesting({
    required bool deliveredVoice,
  }) => _realtimeBackgroundCancelEventType(deliveredVoice: deliveredVoice);

  static bool realtimeBackgroundResultDeliveryBusyForTesting({
    required bool assistantSpeaking,
    required bool responseCreateInFlight,
    bool userSpeechActive = false,
  }) => _realtimeBackgroundResultDeliveryBusy(
    assistantSpeaking: assistantSpeaking,
    responseCreateInFlight: responseCreateInFlight,
    userSpeechActive: userSpeechActive,
  );

  static Map<String, Object?>
  realtimeBackgroundDeliveryDeferredDetailsForTesting({
    int? runId,
    required String spokenText,
    String priorSpokenClaim = '',
    required bool assistantSpeaking,
    required bool responseCreateInFlight,
    bool userSpeechActive = false,
  }) => _realtimeBackgroundDeliveryDeferredDetails(
    runId: runId,
    spokenText: spokenText,
    priorSpokenClaim: priorSpokenClaim,
    assistantSpeaking: assistantSpeaking,
    responseCreateInFlight: responseCreateInFlight,
    userSpeechActive: userSpeechActive,
  );

  static Map<String, Object?> realtimeBackgroundProgressSkipDetailsForTesting({
    required int elapsedMs,
    required String instruction,
    required bool assistantSpeaking,
    required bool responseCreateInFlight,
  }) => _realtimeBackgroundProgressSkipDetails(
    elapsedMs: elapsedMs,
    instruction: instruction,
    assistantSpeaking: assistantSpeaking,
    responseCreateInFlight: responseCreateInFlight,
  );

  static Map<String, Object?> realtimeProgressPromptSpokenDetailsForTesting({
    required int elapsedMs,
    required String instruction,
    required String userRequest,
    required List<String> alreadySpoken,
    required String spokenText,
    String? responseId,
  }) => _realtimeProgressPromptSpokenDetails(
    responseId: responseId,
    elapsedMs: elapsedMs,
    instruction: instruction,
    userRequest: userRequest,
    alreadySpoken: alreadySpoken,
    spokenText: spokenText,
  );

  static bool realtimeVoiceCommandNeedsAgentWorkForTesting(String transcript) =>
      _voiceCommandNeedsAgentWork(transcript);

  static bool realtimeVoiceCommandRequiresBackgroundWorkForTesting(
    String transcript,
  ) => _voiceCommandRequiresBackgroundWork(transcript);

  static bool realtimePoliteConversationEndForTesting(String transcript) =>
      _politeConversationEndRequested(transcript);

  static bool realtimeConversationEndForTesting(String transcript) =>
      _conversationEndRequested(transcript);

  static String? realtimeCommandAfterWakePhraseForTesting(String transcript) =>
      _commandAfterWakePhrase(transcript);

  static String realtimeNormalizedVoiceCommandForTesting(String transcript) =>
      _normalizedVoiceCommand(transcript);

  static Map<String, Object?>? realtimeUnsupportedDirectAnswerForTesting({
    required String userTranscript,
    required String assistantText,
    required bool hasFunctionCall,
    required bool contextRefreshSucceeded,
  }) => _realtimeUnsupportedDirectAnswer(
    userTranscript: userTranscript,
    assistantText: assistantText,
    hasFunctionCall: hasFunctionCall,
    contextRefreshSucceeded: contextRefreshSucceeded,
  );

  static bool
  realtimeUnsupportedDirectAnswerNeedsBackgroundVerificationForTesting(
    Map<String, Object?>? unsupportedDirectAnswer,
  ) => _realtimeUnsupportedDirectAnswerNeedsBackgroundVerification(
    unsupportedDirectAnswer,
  );

  static bool realtimeShouldRefreshDashboardContextBeforeResponseForTesting(
    String transcript, {
    bool isFollowUpTurn = false,
    bool previousTurnNeededDashboardContext = false,
  }) => _realtimeShouldRefreshDashboardContextBeforeResponse(
    transcript,
    isFollowUpTurn: isFollowUpTurn,
    previousTurnNeededDashboardContext: previousTurnNeededDashboardContext,
  );

  static bool realtimePeerConnectionStateIsDegradedForTesting(
    RTCPeerConnectionState state,
  ) => _realtimePeerConnectionStateIsDegraded(state);

  static bool realtimeIceConnectionStateIsDegradedForTesting(
    RTCIceConnectionState state,
  ) => _realtimeIceConnectionStateIsDegraded(state);

  static bool realtimeBargeInInterruptsInternalPromptForTesting({
    required bool suppressAssistant,
    required bool voiceOnlyAssistant,
    required bool ignoreFunctionCalls,
  }) => _realtimeBargeInInterruptsInternalPrompt(
    suppressAssistant: suppressAssistant,
    voiceOnlyAssistant: voiceOnlyAssistant,
    ignoreFunctionCalls: ignoreFunctionCalls,
  );

  static bool realtimeShouldStartBargeInNowForTesting({
    required bool assistantSpeaking,
    required bool userSpeechActive,
    required DateTime? speechStartedAt,
    required DateTime now,
    required bool transcriptConfirmed,
  }) => _realtimeShouldStartBargeInNow(
    assistantSpeaking: assistantSpeaking,
    userSpeechActive: userSpeechActive,
    speechStartedAt: speechStartedAt,
    now: now,
    transcriptConfirmed: transcriptConfirmed,
  );

  static bool realtimeTranscriptConfirmsBargeInForTesting(String transcript) =>
      _realtimeTranscriptConfirmsBargeIn(transcript);

  static String? realtimeStatusAfterSpeechStoppedForTesting({
    required bool conversationActive,
    required bool assistantSpeaking,
  }) => _realtimeStatusAfterSpeechStopped(
    conversationActive: conversationActive,
    assistantSpeaking: assistantSpeaking,
  );

  static Map<String, Object?> realtimeBargeInDispatchDetailsForTesting({
    String? responseId,
    String? assistantItemId,
    required int elapsedMs,
    required bool cancelSent,
    required bool outputAudioCleared,
    required bool truncateAttempted,
    required bool truncateSent,
    required int dispatchMs,
    required bool interruptedInternalPrompt,
    String? dispatchError,
  }) => _realtimeBargeInDispatchDetails(
    responseId: responseId,
    assistantItemId: assistantItemId,
    elapsedMs: elapsedMs,
    cancelSent: cancelSent,
    outputAudioCleared: outputAudioCleared,
    truncateAttempted: truncateAttempted,
    truncateSent: truncateSent,
    dispatchMs: dispatchMs,
    interruptedInternalPrompt: interruptedInternalPrompt,
    dispatchError: dispatchError,
  );

  static String? realtimeAssistantOutputItemIdForTesting(
    Object? item, {
    required bool interruptedPayload,
  }) => _realtimeAssistantOutputItemId(
    item,
    interruptedPayload: interruptedPayload,
  );

  static bool realtimeAssistantAudioDoneMatchesCurrentForTesting({
    required String? payloadResponseId,
    required String? currentResponseId,
  }) => _realtimeAssistantAudioDoneMatchesCurrent(
    payloadResponseId: payloadResponseId,
    currentResponseId: currentResponseId,
  );

  static String? realtimeStatusAfterAssistantAudioDoneForTesting({
    required bool conversationActive,
    bool transcriptionOnlyReleasePending = false,
    bool backgroundWorkActive = false,
    bool endConversationAfterResponse = false,
  }) => _realtimeStatusAfterAssistantAudioDone(
    conversationActive: conversationActive,
    transcriptionOnlyReleasePending: transcriptionOnlyReleasePending,
    backgroundWorkActive: backgroundWorkActive,
    endConversationAfterResponse: endConversationAfterResponse,
  );

  static Map<String, Object?> realtimeAudioDoneReadyDetailsForTesting({
    String? responseId,
    String? status,
    required bool conversationActive,
    required bool micEnabled,
    required int microphoneTrackCount,
    required bool transcriptionOnlyReleasePending,
    required bool backgroundWorkActive,
    required int audioElapsedMs,
  }) => _realtimeAudioDoneReadyDetails(
    responseId: responseId,
    status: status,
    conversationActive: conversationActive,
    micEnabled: micEnabled,
    microphoneTrackCount: microphoneTrackCount,
    transcriptionOnlyReleasePending: transcriptionOnlyReleasePending,
    backgroundWorkActive: backgroundWorkActive,
    audioElapsedMs: audioElapsedMs,
  );

  static int get realtimeToolFallbackTimeoutMsForTesting =>
      _toolFallbackTimeout.inMilliseconds;

  static bool realtimeLateToolCallDuplicatesFallbackForTesting({
    required String activeUserContent,
    required String fallbackQueuedUserContent,
  }) => _realtimeLateToolCallDuplicatesFallback(
    activeUserContent: activeUserContent,
    fallbackQueuedUserContent: fallbackQueuedUserContent,
  );

  static Map<String, Object?> realtimeBargeInRecoveryDetailsForTesting({
    required DateTime? startedAt,
    required DateTime completedAt,
    required String userContent,
    required String assistantText,
    required int functionCallCount,
    String? responseId,
    Map<String, Object?> latencyMetrics = const {},
  }) => _realtimeBargeInRecoveryDetails(
    startedAt: startedAt,
    completedAt: completedAt,
    userContent: userContent,
    assistantText: assistantText,
    functionCallCount: functionCallCount,
    responseId: responseId,
    latencyMetrics: latencyMetrics,
  );

  static Map<String, Object?> realtimeBargeInRecoveryFailureDetailsForTesting({
    required DateTime? startedAt,
    required DateTime completedAt,
    required String transcript,
    required String reason,
    Map<String, Object?> latencyMetrics = const {},
  }) => _realtimeBargeInRecoveryFailureDetails(
    startedAt: startedAt,
    completedAt: completedAt,
    transcript: transcript,
    reason: reason,
    latencyMetrics: latencyMetrics,
  );

  static bool realtimeShouldAppendTranscriptToPendingTurnForTesting({
    required bool responseDebounceActive,
    required bool pendingResponseInterruptedBySpeech,
    required bool hasPendingUserContent,
    required bool isWakeTurn,
  }) => _realtimeShouldAppendTranscriptToPendingTurn(
    responseDebounceActive: responseDebounceActive,
    pendingResponseInterruptedBySpeech: pendingResponseInterruptedBySpeech,
    hasPendingUserContent: hasPendingUserContent,
    isWakeTurn: isWakeTurn,
  );

  static bool realtimeShouldCancelInFlightResponseOnSpeechStartForTesting({
    required bool assistantSpeaking,
    required bool conversationActive,
    required bool responseCreateInFlight,
    required bool hasPendingUserContent,
  }) => _realtimeShouldCancelInFlightResponseOnSpeechStart(
    assistantSpeaking: assistantSpeaking,
    conversationActive: conversationActive,
    responseCreateInFlight: responseCreateInFlight,
    hasPendingUserContent: hasPendingUserContent,
  );

  static bool realtimeResponseCreateInFlightAfterEventForTesting({
    required bool current,
    required String eventType,
    bool responseDoneMatchesCurrent = false,
  }) => _realtimeResponseCreateInFlightAfterEvent(
    current: current,
    eventType: eventType,
    responseDoneMatchesCurrent: responseDoneMatchesCurrent,
  );

  static Map<String, Object?> realtimePendingResponseDeferredDetailsForTesting({
    required String userContent,
    required bool responseCreateWasInFlight,
    required String source,
  }) => _realtimePendingResponseDeferredDetails(
    userContent: userContent,
    responseCreateWasInFlight: responseCreateWasInFlight,
    source: source,
  );

  static bool
  realtimeShouldRecoverDeferredResponseAfterNonActionableTranscriptForTesting({
    required bool pendingResponseInterruptedBySpeech,
    required String? pendingUserContent,
    required String transcript,
  }) => _realtimeShouldRecoverDeferredResponseAfterNonActionableTranscript(
    pendingResponseInterruptedBySpeech: pendingResponseInterruptedBySpeech,
    pendingUserContent: pendingUserContent,
    transcript: transcript,
  );

  static bool realtimeResponseStatusIsFailureForTesting(String? status) =>
      _realtimeResponseStatusIsFailure(status);

  static Map<String, Object?> realtimeResponseFailureDetailsForTesting({
    required Map<String, Object?> payload,
    String? responseId,
    required String userContent,
    required String assistantText,
    required int functionCallCount,
    Map<String, Object?> latencyMetrics = const {},
  }) => _realtimeResponseFailureDetails(
    payload: payload,
    responseId: responseId,
    userContent: userContent,
    assistantText: assistantText,
    functionCallCount: functionCallCount,
    latencyMetrics: latencyMetrics,
  );

  static Map<String, Object?>
  realtimeResponseCreateDispatchFailureDetailsForTesting({
    required String reason,
    String message = '',
    required bool audioResponse,
    required bool textResponse,
    Map<String, Object?> latencyMetrics = const {},
  }) => _realtimeResponseCreateDispatchFailureDetails(
    reason: reason,
    message: message,
    audioResponse: audioResponse,
    textResponse: textResponse,
    latencyMetrics: latencyMetrics,
  );

  static Map<String, Object?>
  realtimeToolOutputDispatchFailureDetailsForTesting({
    String? callId,
    required String reason,
    String message = '',
    required bool createResponse,
    Map<String, Object?> latencyMetrics = const {},
  }) => _realtimeToolOutputDispatchFailureDetails(
    callId: callId,
    reason: reason,
    message: message,
    createResponse: createResponse,
    latencyMetrics: latencyMetrics,
  );

  static bool realtimeUserSpeechActiveAfterFinalTranscriptForTesting({
    required bool wasActive,
    required String transcript,
  }) => _realtimeUserSpeechActiveAfterFinalTranscript(
    wasActive: wasActive,
    transcript: transcript,
  );

  static Duration realtimeRemainingContextRefreshBudgetForTesting(
    DateTime startedAt,
    DateTime now,
  ) => _remainingContextRefreshBudget(startedAt, now: now);

  void _completeSessionUpdateAck() {
    final completer = _sessionUpdateAckCompleter;
    _sessionUpdateAckCompleter = null;
    if (completer == null || completer.isCompleted) return;
    completer.complete();
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

Duration realtimeVoiceResponseDelayForTesting({
  required bool releasePending,
  String content = '',
}) => _voiceResponseDelayAfterFinalTranscript(
  releasePending: releasePending,
  content: content,
);

Duration _voiceResponseDelayAfterFinalTranscript({
  required bool releasePending,
  required String content,
}) {
  if (releasePending) return const Duration(milliseconds: 60);
  if (_voiceTranscriptLikelyStillContinuing(content)) {
    return const Duration(milliseconds: 180);
  }
  return const Duration(milliseconds: 90);
}

bool _voiceTranscriptLikelyStillContinuing(String content) {
  final command = _normalizedVoiceCommand(content);
  if (command.isEmpty) return false;
  final words = command.split(RegExp(r'\s+')).where((word) => word.isNotEmpty);
  if (words.length >= 14) return true;

  return RegExp(
    r"(?:\b(?:and|also|then|plus)|\b(?:after that|before that|as well))$",
  ).hasMatch(command);
}

bool _realtimeShouldRefreshDashboardContextBeforeResponse(
  String transcript, {
  bool isFollowUpTurn = false,
  bool previousTurnNeededDashboardContext = false,
}) {
  final command = _normalizedVoiceCommand(transcript);
  if (command.isEmpty || _voiceCommandIsCapabilityQuestion(command)) {
    return false;
  }
  if (isFollowUpTurn &&
      previousTurnNeededDashboardContext &&
      _realtimeAppStateFollowUpNeedsFreshContext(command)) {
    return true;
  }
  if (RegExp(
    r'\b(?:calendar|calendars|event|events|agenda|schedule|schedules|meeting|meetings|appointment|appointments|task|tasks|todo|to do|reminder|reminders|approval|approvals|workspace|workspaces|google calendar)\b',
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
  if (RegExp(r'\b(?:plan|organize|prioritize)\b').hasMatch(command) &&
      RegExp(
        r'\b(?:day|today|tomorrow|week|schedule|work|tasks|calendar|morning|afternoon|evening)\b',
      ).hasMatch(command)) {
    return true;
  }
  return _voiceCommandLooksLikeAppStateRead(command);
}

bool _realtimeAppStateFollowUpNeedsFreshContext(String command) {
  if (_realtimeSimpleCurrentTimeOrDateQuestion(command)) {
    return false;
  }
  if (_realtimeContinuationFollowUp(command)) {
    return false;
  }
  if (_realtimeConfirmationFollowUp(command)) {
    return true;
  }
  if (_realtimeCorrectionFollowUp(command)) {
    return true;
  }
  if (RegExp(
    r'\b(?:today|tomorrow|tonight|this morning|this afternoon|this evening|this week|next week|this month|next month|last month|this year|next year|last year|weekend|earlier|later|after|before|next|previous|last|again|also|too|else|due|free|busy|open|available|then|there|that|those|them|it|same|instead|actually|undo|revert)\b',
  ).hasMatch(command)) {
    return true;
  }
  if (_realtimeTemporalFragmentFollowUp(command)) {
    return true;
  }
  if (_realtimeRecurrenceFragmentFollowUp(command)) {
    return true;
  }
  if (_realtimeNotificationFragmentFollowUp(command)) {
    return true;
  }
  if (_realtimePriorityFragmentFollowUp(command)) {
    return true;
  }
  if (_realtimeTaskStatusFragmentFollowUp(command)) {
    return true;
  }
  if (_realtimeSchedulingDetailFragmentFollowUp(command)) {
    return true;
  }
  return _realtimeReferenceFollowUp(command);
}

bool _realtimeReferenceFollowUp(String command) {
  return _realtimeContextualQuestionFollowUp(command) ||
      RegExp(
        r"\b(?:what about|how about|and|make that|move it|change it|reschedule it|cancel it|complete it|mark it|add that|do that|use that|undo that|undo it|revert that|revert it|take it back|take that back|reverse that|reverse it|first one|second one|third one|fourth one|top one|middle one|bottom one|the first|the second|the third|the fourth|the first one|the second one|the third one|the fourth one|the top one|the middle one|the bottom one|the last one|the previous one|the one before|the one after|the other one|the next one|both|both of them|all of them|all three|all four|the first two|the last two|first two|last two|first and second|second and third|option (?:one|two|three|four|1|2|3|4|a|b|c|d)|choice (?:one|two|three|four|1|2|3|4|a|b|c|d)|letter (?:a|b|c|d)|number (?:one|two|three|four|1|2|3|4)|(?:first|second|third|fourth|last|previous|next|top|middle|bottom) option|(?:choose|pick|select|use|take|go with)\s+(?:the\s+)?(?:first|second|third|fourth|last|previous|next|top|middle|bottom)(?:\s+(?:one|option|choice))?|(?:choose|pick|select|use|take|go with)\s+(?:both|all of them|the first two|the last two|first two|last two)|(?:choose|pick|select|use|take|go with)\s+(?:option|choice|number|letter)\s+(?:one|two|three|four|1|2|3|4|a|b|c|d)|(?:choose|pick|select|use|take|go with)\s+(?:that|this)(?:\s+one)?|that one|this one|those ones|same time|same place)\b",
      ).hasMatch(command);
}

bool _realtimeContextualQuestionFollowUp(String command) {
  if (_realtimeSimpleCurrentTimeOrDateQuestion(command)) return false;
  const target = r'(?:it|that|this|there|those|them)';
  return RegExp(
        '^(?:when|where|why)\\s+(?:is|are|was|were|does|do|did)\\s+$target\$',
      ).hasMatch(command) ||
      RegExp(
        '^(?:what\\s+time|what\\s+day|which\\s+one|how\\s+long|how\\s+far|how\\s+much)\\s+(?:is|are|was|were)\\s+$target\$',
      ).hasMatch(command) ||
      RegExp(
        '^(?:what|who)\\s+(?:is|are|was|were)\\s+$target\\s+(?:about|for|with)?\$',
      ).hasMatch(command) ||
      RegExp(
        r"^(?:who'?s|who is|who all is)\s+(?:going|coming|invited|there|on it|on that|on this)$",
      ).hasMatch(command) ||
      RegExp(
        r'^(?:who else|anyone else|what else about it|what else about that)$',
      ).hasMatch(command);
}

bool _realtimeSimpleCurrentTimeOrDateQuestion(String command) {
  return RegExp(
    r"^(?:what time is it|what'?s the time|whats the time|what is the time|current time|what day is it|what'?s today|whats today|what is today|what'?s today'?s date|whats todays date|what is today'?s date|what'?s the date|whats the date|what is the date)$",
  ).hasMatch(command);
}

bool _realtimeTemporalFragmentFollowUp(String command) {
  const weekday =
      r'(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)';
  const month =
      r'(?:january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sep|sept|oct|nov|dec)';
  const dayPeriod = r'(?:morning|afternoon|evening|night)';
  const numericTime =
      r'\d{1,2}(?:(?::|\s)\d{2})?\s*(?:a\.?m\.?|p\.?m\.?|am|pm)?';
  const wordTime =
      r'(?:one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve)(?:\s+(?:fifteen|thirty|forty five))?';
  const time = "(?:$numericTime|$wordTime|noon|midnight)(?:\\s+o'?clock)?";
  const timeWindow =
      '(?:(?:from\\s+)?$time\\s+(?:to|until|through|thru)\\s+$time|between\\s+$time\\s+and\\s+$time|until\\s+$time)';
  const ordinalDate = r'\d{1,2}(?:st|nd|rd|th)?';
  const dateFragment =
      '(?:(?:(?:this|next|last)\\s+)?$weekday|(?:the\\s+)?$ordinalDate(?:\\s+(?:of\\s+)?$month)?|$month\\s+$ordinalDate|today|tomorrow|tonight)';
  const deadline =
      '(?:by\\s+(?:$time|$dateFragment|end\\s+of\\s+(?:day|week|month)|eod|eow|close\\s+of\\s+business|cob)|before\\s+(?:$time|noon|midnight|lunch|dinner|work|school))';
  const durationAmount =
      r'(?:a|an|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|fifteen|twenty|thirty|forty five|\d{1,3})';
  const durationUnit =
      r'(?:minute|minutes|min|mins|hour|hours|hr|hrs|day|days|week|weeks)';
  const duration =
      '(?:half\\s+an?\\s+hour|a\\s+half\\s+hour|$durationAmount\\s+$durationUnit)';
  const relativeShift =
      '(?:(?:$duration|a\\s+(?:little|bit)|a\\s+few\\s+minutes)\\s+(?:earlier|later|before|after)|(?:earlier|later))';
  const relativeDaypart =
      '(?:(?:today|tomorrow|tonight)\\s+$dayPeriod|(?:this|next|last)\\s+weekend|this\\s+$dayPeriod|next\\s+$dayPeriod|later\\s+(?:today|tonight))';
  const relativeDateTime =
      '(?:(?:today|tomorrow|tonight|(?:the\\s+)?day\\s+after\\s+tomorrow)'
      '(?:\\s+$dayPeriod)?'
      '(?:\\s+(?:at|around)\\s+$time)?|'
      '(?:the\\s+)?day\\s+after\\s+tomorrow)';
  const anchorTime =
      '(?:(?:before|after|around|during)\\s+(?:breakfast|lunch|dinner|work|school|church)|(?:breakfast|lunch|dinner)time|noonish|midday|end\\s+of\\s+(?:day|work|school|the\\s+day))';

  return RegExp(
        '^(?:on\\s+)?(?:(?:this|next|last)\\s+)?$weekday'
        '(?:\\s+$dayPeriod)?'
        '(?:\\s+(?:at|around)\\s+$time)?\$',
      ).hasMatch(command) ||
      RegExp('^(?:at|around)\\s+$time\$').hasMatch(command) ||
      RegExp('^$timeWindow\$').hasMatch(command) ||
      RegExp('^$deadline\$').hasMatch(command) ||
      RegExp('^$time\$').hasMatch(command) ||
      RegExp(
        '^(?:on\\s+)?(?:the\\s+)?$ordinalDate(?:\\s+(?:of\\s+)?$month)?\$',
      ).hasMatch(command) ||
      RegExp('^(?:on\\s+)?$month\\s+$ordinalDate\$').hasMatch(command) ||
      RegExp('^$relativeDateTime\$').hasMatch(command) ||
      RegExp('^$relativeDaypart\$').hasMatch(command) ||
      RegExp('^$anchorTime\$').hasMatch(command) ||
      RegExp('^(?:in|for|after)\\s+$duration\$').hasMatch(command) ||
      RegExp('^$relativeShift\$').hasMatch(command) ||
      RegExp('^$duration\$').hasMatch(command);
}

bool _realtimeSchedulingDetailFragmentFollowUp(String command) {
  const person = r"(?:[a-z][a-z']+|dr\s+[a-z][a-z']+|doctor\s+[a-z][a-z']+)";
  const people = '$person(?:\\s+(?:and|&)\\s+$person){0,3}';
  const place =
      r'(?:home|work|office|the office|my office|their office|school|church|the house|my house|their house|conference room [a-z0-9]+|room [a-z0-9]+|zoom|google meet|meet|teams|facetime|phone|the phone|a phone call|phone call|in person)';

  return RegExp('^(?:with|for)\\s+$people\$').hasMatch(command) ||
      RegExp('^(?:at|in|on)\\s+$place\$').hasMatch(command) ||
      RegExp('^$place\$').hasMatch(command);
}

bool _realtimeNotificationFragmentFollowUp(String command) {
  const amount =
      r'(?:a|an|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|fifteen|twenty|thirty|forty five|\d{1,3})';
  const unit = r'(?:minute|minutes|min|mins|hour|hours|hr|hrs|day|days)';
  const offset = '(?:half\\s+an?\\s+hour|a\\s+half\\s+hour|$amount\\s+$unit)';
  const noAlert =
      r"(?:no alert|no alerts|no reminder|no reminders|no notification|no notifications|don't remind me|do not remind me)";

  return RegExp('^$offset\\s+before\$').hasMatch(command) ||
      RegExp(
        '^(?:remind me\\s+)?$offset\\s+before(?:hand)?\$',
      ).hasMatch(command) ||
      RegExp(
        '^(?:at\\s+)?(?:the\\s+)?start(?:\\s+time)?\$',
      ).hasMatch(command) ||
      RegExp('^$noAlert\$').hasMatch(command);
}

bool _realtimePriorityFragmentFollowUp(String command) {
  return RegExp(
    r'^(?:urgent|asap|high priority|highest priority|important|very important|medium priority|normal priority|normal|low priority|lowest priority|not urgent|no priority)$',
  ).hasMatch(command);
}

bool _realtimeTaskStatusFragmentFollowUp(String command) {
  return RegExp(
    r'^(?:done|completed|complete|finished|mark complete|mark completed|mark done|still open|keep open|leave open|not done|not done yet|not finished|in progress|still working on it)$',
  ).hasMatch(command);
}

bool _realtimeRecurrenceFragmentFollowUp(String command) {
  const weekday =
      r'(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)';
  const pluralWeekday =
      r'(?:mondays|tuesdays|wednesdays|thursdays|fridays|saturdays|sundays)';
  const simpleCadence =
      r'(?:daily|weekly|monthly|yearly|annually|weekdays|weekends)';
  const oneTimeCadence =
      r"(?:just once|one time|only once|once only|does not repeat|do not repeat|don'?t repeat|no repeat|no repeats|not recurring|no recurrence)";
  const everyTarget =
      r'(?:day|weekday|weekend|week|month|year|morning|afternoon|evening|night|other week|other month|two weeks|2 weeks|\d{1,2}\s+(?:days|weeks|months)|monday|tuesday|wednesday|thursday|friday|saturday|sunday)';

  return RegExp('^$simpleCadence\$').hasMatch(command) ||
      RegExp('^$oneTimeCadence\$').hasMatch(command) ||
      RegExp('^(?:on\\s+)?$pluralWeekday\$').hasMatch(command) ||
      RegExp('^every\\s+$everyTarget\$').hasMatch(command) ||
      RegExp('^every\\s+(?:other\\s+)?$weekday\$').hasMatch(command);
}

bool _realtimeTranscriptLooksContextualFollowUp(String transcript) {
  final command = _normalizedVoiceCommand(transcript);
  if (command.isEmpty || command.length > 180) return false;
  if (_realtimeContinuationFollowUp(command)) return true;
  if (_realtimeDeclineFollowUp(command)) return true;
  if (_realtimeCorrectionFollowUp(command)) return true;
  if (_realtimeAppStateFollowUpNeedsFreshContext(command)) return true;
  return _realtimeReferenceFollowUp(command);
}

String _realtimeContextualFollowUpKind(String transcript) {
  final command = _normalizedVoiceCommand(transcript);
  if (command.isEmpty || command.length > 180) return '';
  if (_realtimeConfirmationFollowUp(command)) return 'confirmation';
  if (_realtimeDeclineFollowUp(command)) return 'decline';
  if (_realtimeContinuationFollowUp(command)) return 'continuation';
  if (_realtimeCorrectionFollowUp(command)) return 'correction';
  if (_realtimeAppStateFollowUpNeedsFreshContext(command)) return 'reference';
  if (_realtimeReferenceFollowUp(command)) return 'reference';
  return '';
}

String _realtimeKnownContextualFollowUpKind(String kind) {
  final clean = kind.trim().toLowerCase();
  const known = {
    'confirmation',
    'decline',
    'correction',
    'continuation',
    'reference',
  };
  return known.contains(clean) ? clean : '';
}

bool _realtimeConfirmationFollowUp(String command) {
  return RegExp(
    r"^(?:yes|yeah|yep|yup|uh huh|mhm|mm hmm|please|yes please|yeah please|yep please|sure|sure please|sure thing|for sure|absolutely|definitely|exactly|correct|right|that'?s right|thats right|that is right|you got it|ok|okay|okay please|alright|all right|go ahead|please go ahead|go for it|please do|do it|do that|fine|that'?s fine|thats fine|that'?s okay|thats okay|sounds fine|fine by me|that sounds good|sounds good|sounds good to me|sounds right|sounds perfect|that works|works|works for me|that would be great|perfect|great|great thanks|looks good|that looks good|let'?s do it|lets do it)$",
  ).hasMatch(command);
}

bool _realtimeDeclineFollowUp(String command) {
  return RegExp(
    r"^(?:no|nope|nah|neither|none|none of them|not either|not either one|actually no|no thanks|no thank you|i'?m good|im good|i am good|all good|we'?re good|we are good|not now|not right now|not yet|not today|not anymore|maybe later|later maybe|let'?s not|lets not|skip it|leave it|don'?t|do not|don'?t do it|do not do it|don'?t do that|do not do that|don'?t worry about it|do not worry about it)$",
  ).hasMatch(command);
}

bool _realtimeCorrectionFollowUp(String command) {
  return RegExp(
        r"^(?:no\s+)?(?:i meant|i mean|i said|i wanted|i asked for)\b|^(?:actually|wait|no wait|hold on)\s+(?:make|move|change|reschedule|cancel|complete|mark|use|add|switch|set|put|undo|revert|reverse|take|not|the|this|that|it|them|those|tomorrow|today|tonight|monday|tuesday|wednesday|thursday|friday|saturday|sunday|\d)\b|^(?:make|move|change|reschedule|switch|set|put|cancel|complete|mark|use|add|undo|revert|reverse|take|try)\s+(?:it|that(?!\s+one)|this(?!\s+one)|them|those|the other one)\b|^(?:no\s+)?(?:that'?s wrong|thats wrong|that is wrong|wrong one|the wrong one|not that|not that one|not this one|not the first one|not the second one|not the third one|take it back|take that back|no the other one|no use the other one|different one|another one instead|use the other one instead|try the other one)$",
      ).hasMatch(command) ||
      _realtimeNoCorrectionFragmentFollowUp(command);
}

bool _realtimeNoCorrectionFragmentFollowUp(String command) {
  if (_realtimeDeclineFollowUp(command)) return false;
  final match = RegExp(r'^(?:no|nope|nah)\s+(.+)$').firstMatch(command);
  if (match == null) return false;
  final correction = (match.group(1) ?? '').trim();
  if (correction.isEmpty || _realtimeDeclineFollowUp(correction)) {
    return false;
  }

  return RegExp(
        r'^(?:make|move|change|reschedule|switch|set|put|cancel|complete|mark|use|add|undo|revert|reverse|take|try)\b',
      ).hasMatch(correction) ||
      _realtimeTemporalFragmentFollowUp(correction) ||
      _realtimeSchedulingDetailFragmentFollowUp(correction) ||
      _realtimePriorityFragmentFollowUp(correction) ||
      _realtimeTaskStatusFragmentFollowUp(correction) ||
      _realtimeReferenceFollowUp(correction);
}

bool _realtimeContinuationFollowUp(String command) {
  return RegExp(
    r"^(?:continue|please continue|keep going|go on|sorry go on|carry on|finish that|finish your thought|finish what you were saying|tell me more|more|more please|more details|a little more|can you expand on that|could you expand on that|expand on that|go into more detail|what else|anything else|shorter|make it shorter|say it shorter|quick version|short version|simpler|make it simpler|explain it simpler|more simply|slower|say it slower|say that slower|what were you saying|what did you say|what was that|i missed that|missed that|i didn'?t catch that|didn'?t catch that|i did not catch that|did not catch that|come again|pardon|run that by me again|say that again|can you say that again|could you say that again|say it again|repeat that|repeat it|can you repeat that|could you repeat that|repeat what you said|one more time|again please|say that one more time)$",
  ).hasMatch(command);
}

Duration _remainingContextRefreshBudget(DateTime startedAt, {DateTime? now}) {
  final elapsed = (now ?? DateTime.now()).difference(startedAt);
  final remaining = BeanRealtimeConversation._contextRefreshBudget - elapsed;
  return remaining.isNegative ? Duration.zero : remaining;
}

bool _realtimePeerConnectionStateIsDegraded(RTCPeerConnectionState state) =>
    state == RTCPeerConnectionState.RTCPeerConnectionStateFailed ||
    state == RTCPeerConnectionState.RTCPeerConnectionStateClosed ||
    state == RTCPeerConnectionState.RTCPeerConnectionStateDisconnected;

bool _realtimeIceConnectionStateIsDegraded(RTCIceConnectionState state) =>
    state == RTCIceConnectionState.RTCIceConnectionStateFailed ||
    state == RTCIceConnectionState.RTCIceConnectionStateClosed ||
    state == RTCIceConnectionState.RTCIceConnectionStateDisconnected;

bool _realtimeBargeInInterruptsInternalPrompt({
  required bool suppressAssistant,
  required bool voiceOnlyAssistant,
  required bool ignoreFunctionCalls,
}) => suppressAssistant || voiceOnlyAssistant || ignoreFunctionCalls;

bool _realtimeShouldStartBargeInNow({
  required bool assistantSpeaking,
  required bool userSpeechActive,
  required DateTime? speechStartedAt,
  required DateTime now,
  required bool transcriptConfirmed,
}) {
  if (!assistantSpeaking) return false;
  if (transcriptConfirmed) return true;
  if (!userSpeechActive) return false;
  if (speechStartedAt == null) return true;
  return now.difference(speechStartedAt) >=
      BeanRealtimeConversation._bargeInConfirmationDelay;
}

bool _realtimeTranscriptConfirmsBargeIn(String transcript) =>
    transcript.trim().isNotEmpty && !_transcriptLooksSynthetic(transcript);

String? _realtimeStatusAfterSpeechStopped({
  required bool conversationActive,
  required bool assistantSpeaking,
}) {
  if (assistantSpeaking) return "Bean's voice";
  if (conversationActive) return 'thinking';
  return null;
}

String? _realtimeStatusAfterAssistantAudioDone({
  required bool conversationActive,
  required bool transcriptionOnlyReleasePending,
  required bool backgroundWorkActive,
  bool endConversationAfterResponse = false,
}) {
  if (!conversationActive || transcriptionOnlyReleasePending) return null;
  if (backgroundWorkActive) return 'working...';
  if (endConversationAfterResponse) return 'Bean voice ready';
  return 'listening';
}

Map<String, Object?> _realtimeAudioDoneReadyDetails({
  String? responseId,
  String? status,
  required bool conversationActive,
  required bool micEnabled,
  required int microphoneTrackCount,
  required bool transcriptionOnlyReleasePending,
  required bool backgroundWorkActive,
  required int audioElapsedMs,
}) => {
  if ((responseId ?? '').trim().isNotEmpty) 'response_id': responseId!.trim(),
  'ready_elapsed_ms': 0,
  'status': status,
  'conversation_active': conversationActive,
  'mic_enabled': micEnabled,
  'microphone_track_count': microphoneTrackCount,
  'transcription_only_release_pending': transcriptionOnlyReleasePending,
  'background_work_active': backgroundWorkActive,
  'audio_elapsed_ms': audioElapsedMs,
};

bool _realtimeShouldAppendTranscriptToPendingTurn({
  required bool responseDebounceActive,
  required bool pendingResponseInterruptedBySpeech,
  required bool hasPendingUserContent,
  required bool isWakeTurn,
}) =>
    hasPendingUserContent &&
    !isWakeTurn &&
    (responseDebounceActive || pendingResponseInterruptedBySpeech);

bool _realtimeShouldCancelInFlightResponseOnSpeechStart({
  required bool assistantSpeaking,
  required bool conversationActive,
  required bool responseCreateInFlight,
  required bool hasPendingUserContent,
}) =>
    !assistantSpeaking &&
    conversationActive &&
    responseCreateInFlight &&
    hasPendingUserContent;

bool _realtimeResponseCreateInFlightAfterEvent({
  required bool current,
  required String eventType,
  bool responseDoneMatchesCurrent = false,
}) {
  switch (eventType) {
    case 'response.created':
    case 'error':
      return false;
    case 'response.done':
      return responseDoneMatchesCurrent ? false : current;
    default:
      return current;
  }
}

bool _realtimeShouldRecoverDeferredResponseAfterNonActionableTranscript({
  required bool pendingResponseInterruptedBySpeech,
  required String? pendingUserContent,
  required String transcript,
}) =>
    pendingResponseInterruptedBySpeech &&
    (pendingUserContent?.trim().isNotEmpty ?? false) &&
    (transcript.trim().isEmpty || _transcriptLooksSynthetic(transcript));

bool _realtimeUserSpeechActiveAfterFinalTranscript({
  required bool wasActive,
  required String transcript,
}) {
  if (wasActive || transcript.trim().isNotEmpty) return false;
  return false;
}

Map<String, Object?> realtimeResponseCreatePayloadForTesting({
  bool audioResponse = true,
  bool textResponse = true,
}) => _realtimeResponseCreatePayload(
  audioResponse: audioResponse,
  textResponse: textResponse,
);

Map<String, Object?> realtimeFreshContextUnavailableItemPayloadForTesting({
  required String userContent,
  String reason = '',
}) => _realtimeFreshContextUnavailableItemPayload(
  userContent: userContent,
  reason: reason,
);

Map<String, Object?> _realtimeFreshContextUnavailableItemPayload({
  required String userContent,
  String reason = '',
}) {
  final cleanContent = userContent.replaceAll(RegExp(r'\s+'), ' ').trim();
  final cleanReason = reason.replaceAll(RegExp(r'\s+'), ' ').trim();

  return {
    'type': 'conversation.item.create',
    'item': {
      'type': 'message',
      'role': 'user',
      'content': [
        {
          'type': 'input_text',
          'text': jsonEncode({
            'realtime_fresh_context_unavailable': true,
            'user_request': cleanContent,
            if (cleanReason.isNotEmpty) 'reason': cleanReason,
            'rules': [
              'Fresh app-state context was required but was not confirmed quickly.',
              'Speak one short acknowledgement, then call queue_bean_work with content set exactly to user_request. Do not summarize or alter user_request.',
              'Do not answer from stale calendar, task, reminder, schedule, agenda, or availability context.',
              'Do not mention tools, models, connections, or voice.',
            ],
          }),
        },
      ],
    },
  };
}

Map<String, Object?> _realtimeResponseCreatePayload({
  required bool audioResponse,
  required bool textResponse,
}) {
  final modalities = <String>[
    if (textResponse) 'text',
    if (audioResponse) 'audio',
  ];

  return {
    'type': 'response.create',
    'response': {
      'modalities': modalities.isEmpty ? ['text'] : modalities,
    },
  };
}

Map<String, Object?> realtimeResponseCancelPayloadForTesting(
  String? responseId,
) => _realtimeResponseCancelPayload(responseId);

Map<String, Object?> _realtimeResponseCancelPayload(String? responseId) => {
  'type': 'response.cancel',
  if (responseId != null && responseId.isNotEmpty) 'response_id': responseId,
};

Map<String, Object?> realtimeOutputAudioBufferClearPayloadForTesting() =>
    _realtimeOutputAudioBufferClearPayload();

Map<String, Object?> _realtimeOutputAudioBufferClearPayload() => {
  'type': 'output_audio_buffer.clear',
};

Map<String, Object?> realtimeConversationItemTruncatePayloadForTesting(
  String itemId,
  int audioEndMs,
) => _realtimeConversationItemTruncatePayload(itemId, audioEndMs);

Map<String, Object?> _realtimeConversationItemTruncatePayload(
  String itemId,
  int audioEndMs,
) => {
  'type': 'conversation.item.truncate',
  'item_id': itemId,
  'content_index': 0,
  'audio_end_ms': audioEndMs,
};

String? _responseIdFromPayload(Map<String, Object?> payload) {
  final response = payload['response'];
  if (response is Map && response['id'] != null) {
    return response['id'].toString();
  }
  return payload['response_id']?.toString();
}

String? _realtimeAssistantOutputItemId(
  Object? item, {
  required bool interruptedPayload,
}) {
  if (interruptedPayload || item is! Map) return null;
  final type = item['type']?.toString();
  final role = item['role']?.toString();
  final id = item['id']?.toString();
  if (type != 'message' || role != 'assistant' || id == null || id.isEmpty) {
    return null;
  }
  return id;
}

String _realtimeActiveResponseUserContent({
  required String? activeResponseUserContent,
  required String? pendingUserContent,
  required String? currentUserContent,
}) {
  final active = _cleanRealtimeUserContent(activeResponseUserContent);
  if (active.isNotEmpty) return active;
  return _realtimeActiveUserTurnContent(
    pendingUserContent: pendingUserContent,
    currentUserContent: currentUserContent,
  );
}

String _cleanRealtimeUserContent(String? content) =>
    content?.replaceAll(RegExp(r'\s+'), ' ').trim() ?? '';

bool _realtimeAssistantAudioDoneMatchesCurrent({
  required String? payloadResponseId,
  required String? currentResponseId,
}) {
  final payloadId = payloadResponseId?.trim() ?? '';
  final currentId = currentResponseId?.trim() ?? '';
  if (payloadId.isEmpty) return true;
  return currentId.isNotEmpty && payloadId == currentId;
}

String? _responseModelFromPayload(Map<String, Object?> payload) {
  final response = payload['response'];
  if (response is Map && response['model'] != null) {
    return response['model'].toString();
  }
  return payload['model']?.toString();
}

Map<String, Object?>? _responseUsageFromPayload(Map<String, Object?> payload) {
  final response = payload['response'];
  final usage = response is Map ? response['usage'] : payload['usage'];
  if (usage is Map<String, Object?>) return usage;
  if (usage is Map) return Map<String, Object?>.from(usage);
  return null;
}

String? _responseStatusFromPayload(Map<String, Object?> payload) {
  final response = payload['response'];
  final status = response is Map ? response['status'] : payload['status'];
  final normalized = status?.toString().trim();
  return normalized == null || normalized.isEmpty ? null : normalized;
}

bool _realtimeResponseStatusIsFailure(String? status) {
  final normalized = status?.trim().toLowerCase() ?? '';
  return normalized.isNotEmpty && normalized != 'completed';
}

String? _realtimeResponseFailureUserMessage(String? status) {
  final normalized = status?.trim().toLowerCase() ?? '';
  if (normalized == 'failed' || normalized == 'incomplete') {
    return 'Bean voice is reconnecting';
  }
  return null;
}

Map<String, Object?> realtimeLatencyMetricsForTesting({
  required DateTime? turnStartedAt,
  required DateTime? responseCreateRequestedAt,
  required DateTime? firstAssistantSignalAt,
  required DateTime completedAt,
}) => _realtimeLatencyMetrics(
  turnStartedAt: turnStartedAt,
  responseCreateRequestedAt: responseCreateRequestedAt,
  firstAssistantSignalAt: firstAssistantSignalAt,
  completedAt: completedAt,
);

Map<String, Object?> _realtimeLatencyMetrics({
  required DateTime? turnStartedAt,
  required DateTime? responseCreateRequestedAt,
  required DateTime? firstAssistantSignalAt,
  required DateTime completedAt,
}) {
  final transcriptToResponseMs = _elapsedMs(
    turnStartedAt,
    responseCreateRequestedAt,
  );
  final responseToFirstSignalMs = _elapsedMs(
    responseCreateRequestedAt,
    firstAssistantSignalAt,
  );
  final transcriptToFirstSignalMs = _elapsedMs(
    turnStartedAt,
    firstAssistantSignalAt,
  );
  final turnCompletedMs = _elapsedMs(turnStartedAt, completedAt);

  return {
    if (transcriptToResponseMs != null)
      'transcript_to_response_create_ms': transcriptToResponseMs,
    if (responseToFirstSignalMs != null)
      'response_create_to_first_assistant_ms': responseToFirstSignalMs,
    if (transcriptToFirstSignalMs != null)
      'transcript_to_first_assistant_ms': transcriptToFirstSignalMs,
    if (turnCompletedMs != null) 'turn_completed_ms': turnCompletedMs,
    if (turnCompletedMs != null) 'voice_seconds': turnCompletedMs / 1000.0,
  };
}

Map<String, Object?> _realtimeBargeInRecoveryDetails({
  required DateTime? startedAt,
  required DateTime completedAt,
  required String userContent,
  required String assistantText,
  required int functionCallCount,
  String? responseId,
  Map<String, Object?> latencyMetrics = const {},
}) {
  final cleanUserContent = userContent.replaceAll(RegExp(r'\s+'), ' ').trim();
  final cleanAssistantText = assistantText
      .replaceAll(RegExp(r'\s+'), ' ')
      .trim();
  final recoveryElapsedMs = _elapsedMs(startedAt, completedAt);

  return {
    'user_content': cleanUserContent,
    'assistant_answered': cleanAssistantText.isNotEmpty,
    'has_user_content': cleanUserContent.isNotEmpty,
    'function_call_count': functionCallCount,
    if (responseId != null && responseId.isNotEmpty) 'response_id': responseId,
    if (recoveryElapsedMs != null) 'recovery_elapsed_ms': recoveryElapsedMs,
    ..._realtimeUsageLatencyPayload(latencyMetrics),
  };
}

Map<String, Object?> _realtimeBargeInRecoveryFailureDetails({
  required DateTime? startedAt,
  required DateTime completedAt,
  required String transcript,
  required String reason,
  Map<String, Object?> latencyMetrics = const {},
}) {
  final cleanTranscript = transcript.replaceAll(RegExp(r'\s+'), ' ').trim();
  final recoveryElapsedMs = _elapsedMs(startedAt, completedAt);

  return {
    'reason': reason,
    'transcript': cleanTranscript,
    'assistant_answered': false,
    'has_user_content': false,
    if (recoveryElapsedMs != null) 'recovery_elapsed_ms': recoveryElapsedMs,
    ..._realtimeUsageLatencyPayload(latencyMetrics),
  };
}

Map<String, Object?> _realtimeBargeInDispatchDetails({
  required String? responseId,
  required String? assistantItemId,
  required int elapsedMs,
  required bool cancelSent,
  required bool outputAudioCleared,
  required bool truncateAttempted,
  required bool truncateSent,
  required int dispatchMs,
  required bool interruptedInternalPrompt,
  String? dispatchError,
}) => {
  'response_id': responseId,
  'assistant_item_id': assistantItemId,
  'audio_end_ms': elapsedMs,
  'cancel_sent': cancelSent,
  'output_audio_cleared': outputAudioCleared,
  'truncate_attempted': truncateAttempted,
  'truncate_sent': truncateSent,
  'cancel_dispatch_ms': dispatchMs,
  'interrupted_internal_prompt': interruptedInternalPrompt,
  if (dispatchError != null) 'dispatch_error': dispatchError,
};

Map<String, Object?> realtimeUsageLatencyPayloadForTesting(
  Map<String, Object?> metrics,
) => _realtimeUsageLatencyPayload(metrics);

Map<String, Object?> _realtimeUsageLatencyPayload(
  Map<String, Object?> metrics,
) {
  const allowed = {
    'transcript_to_response_create_ms',
    'response_create_to_first_assistant_ms',
    'transcript_to_first_assistant_ms',
    'turn_completed_ms',
  };
  return {
    for (final entry in metrics.entries)
      if (allowed.contains(entry.key) && entry.value is num)
        entry.key: entry.value,
  };
}

Map<String, Object?> _realtimeSpokenAnswerQuality(String text) {
  final spoken = text.replaceAll(RegExp(r'\s+'), ' ').trim();
  if (spoken.isEmpty) {
    return const {
      'spoken_character_count': 0,
      'spoken_sentence_count': 0,
      'spoken_brevity_violation': false,
    };
  }
  final sentenceCount = _spokenSentenceCount(spoken);

  return {
    'spoken_character_count': spoken.length,
    'spoken_sentence_count': sentenceCount,
    'spoken_brevity_violation': spoken.length > 260 || sentenceCount > 2,
  };
}

Map<String, Object?> _realtimeTurnQualityPayload({
  required bool isFollowUp,
  required bool isContextualFollowUp,
  String contextualFollowUpKind = '',
  required bool usageMissing,
}) {
  final cleanKind = isContextualFollowUp
      ? _realtimeKnownContextualFollowUpKind(contextualFollowUpKind)
      : '';

  return {
    'is_follow_up_turn': isFollowUp,
    'is_contextual_follow_up_turn': isContextualFollowUp,
    if (cleanKind.isNotEmpty) 'contextual_follow_up_kind': cleanKind,
    'realtime_usage_missing': usageMissing,
  };
}

Map<String, Object?> _realtimeFollowUpReadyDetails({
  required DateTime? responseDoneAt,
  required DateTime readyAt,
  required bool conversationActive,
  required bool micEnabled,
  required int microphoneTrackCount,
  required bool isFollowUp,
  required bool isContextualFollowUp,
  String contextualFollowUpKind = '',
  Map<String, Object?> latencyMetrics = const {},
}) {
  final readyElapsedMs = _elapsedMs(responseDoneAt, readyAt);
  final cleanKind = isContextualFollowUp
      ? _realtimeKnownContextualFollowUpKind(contextualFollowUpKind)
      : '';
  final filteredLatency = <String, Object?>{};
  for (final entry in latencyMetrics.entries) {
    if (entry.value is num) {
      filteredLatency[entry.key] = entry.value;
    }
  }

  return {
    'ready_elapsed_ms': readyElapsedMs,
    'conversation_active': conversationActive,
    'mic_enabled': micEnabled,
    'microphone_track_count': microphoneTrackCount,
    'is_follow_up_turn': isFollowUp,
    'is_contextual_follow_up_turn': isContextualFollowUp,
    if (cleanKind.isNotEmpty) 'contextual_follow_up_kind': cleanKind,
    ...filteredLatency,
  };
}

Map<String, Object?> _realtimePendingResponseRecoveryDetails({
  required DateTime? deferredAt,
  required DateTime recoveredAt,
  required String userContent,
  required String transcript,
  required bool synthetic,
}) => {
  'user_content': userContent,
  'transcript': transcript,
  'synthetic': synthetic,
  'recovery_elapsed_ms': _elapsedMs(deferredAt, recoveredAt),
};

Map<String, Object?> _realtimePendingResponseDeferredDetails({
  required String userContent,
  required bool responseCreateWasInFlight,
  required String source,
}) => {
  'user_content': userContent,
  'response_create_was_in_flight': responseCreateWasInFlight,
  'source': source,
};

int _spokenSentenceCount(String spoken) {
  final sentenceBreaks = RegExp(r'[.!?]+(?:\s+|$)')
      .allMatches(spoken)
      .where((match) => match.group(0)?.trim().isNotEmpty == true)
      .length;
  return sentenceBreaks == 0 ? 1 : sentenceBreaks;
}

int? _elapsedMs(DateTime? start, DateTime? end) {
  if (start == null || end == null) return null;
  final elapsed = end.difference(start).inMilliseconds;
  if (elapsed < 0) return null;
  return elapsed;
}

String _realtimePersistedUserContent({
  required String? pendingUserContent,
  required String? currentUserContent,
}) {
  return _realtimeActiveUserTurnContent(
    pendingUserContent: pendingUserContent,
    currentUserContent: currentUserContent,
  );
}

String _realtimeActiveUserTurnContent({
  required String? pendingUserContent,
  required String? currentUserContent,
}) {
  final pending = pendingUserContent?.trim() ?? '';
  if (pending.isNotEmpty) return pending;
  return currentUserContent?.trim() ?? '';
}

bool _interruptedResponseShouldPreserveActiveTurn({
  required String? pendingUserContent,
  required String? currentUserContent,
}) => _realtimeActiveUserTurnContent(
  pendingUserContent: pendingUserContent,
  currentUserContent: currentUserContent,
).isNotEmpty;

bool _shouldCloseRealtimeFollowUpIdle({
  required bool conversationActive,
  required bool voiceCaptureActive,
  required bool voiceReleasePending,
  required bool transcriptionOnlyReleasePending,
  required bool backgroundWorkActive,
  required bool assistantSpeaking,
  required String? pendingUserContent,
  required String? currentUserContent,
}) {
  if (!conversationActive) return false;
  if (voiceCaptureActive ||
      voiceReleasePending ||
      transcriptionOnlyReleasePending ||
      backgroundWorkActive ||
      assistantSpeaking) {
    return false;
  }
  return _realtimeActiveUserTurnContent(
    pendingUserContent: pendingUserContent,
    currentUserContent: currentUserContent,
  ).isEmpty;
}

bool _shouldRecoverDeferredResponseAfterSpeechStop({
  required bool pendingResponseInterruptedBySpeech,
  required bool hasPendingUserContent,
  required bool userSpeechActive,
  required bool responseCreateInFlight,
  required bool responseDebounceActive,
}) =>
    pendingResponseInterruptedBySpeech &&
    hasPendingUserContent &&
    !userSpeechActive &&
    !responseCreateInFlight &&
    !responseDebounceActive;

bool _shouldDeliverPendingBackgroundCompletionAfterSpeechStop({
  required bool hasPendingCompletion,
  required bool backgroundDeliveryBusy,
  required bool hasPendingUserContent,
  required bool responseDebounceActive,
  required bool voiceCaptureActive,
  required bool voiceReleasePending,
  required bool transcriptionOnlyReleasePending,
}) {
  if (!hasPendingCompletion || backgroundDeliveryBusy) return false;
  if (hasPendingUserContent ||
      responseDebounceActive ||
      voiceCaptureActive ||
      voiceReleasePending ||
      transcriptionOnlyReleasePending) {
    return false;
  }
  return true;
}

bool _isRealtimeFollowUpTranscript({
  required bool isWakeTurn,
  required bool conversationActive,
  required bool voiceCaptureActive,
  required bool voiceReleasePending,
  required bool transcriptionOnlyReleasePending,
}) {
  if (isWakeTurn || !conversationActive) return false;
  return !voiceCaptureActive &&
      !voiceReleasePending &&
      !transcriptionOnlyReleasePending;
}

bool _responseDoneMatchesCurrentResponse({
  required String? payloadResponseId,
  required String? currentResponseId,
  required bool interrupted,
}) {
  final payloadId = payloadResponseId?.trim() ?? '';
  final currentId = currentResponseId?.trim() ?? '';
  if (payloadId.isNotEmpty) return payloadId == currentId;
  if (interrupted) return currentId.isEmpty;
  return true;
}

bool _missingResponseIdShouldConsumeInterruptedResponse({
  required String? payloadResponseId,
  required bool hasPendingInterruptedResponseIds,
}) {
  final payloadId = payloadResponseId?.trim() ?? '';
  return payloadId.isEmpty && hasPendingInterruptedResponseIds;
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

Map<String, Object?> _realtimeResponseFailureDetails({
  required Map<String, Object?> payload,
  String? responseId,
  required String userContent,
  required String assistantText,
  required int functionCallCount,
  Map<String, Object?> latencyMetrics = const {},
}) {
  final response = payload['response'];
  final statusDetails = response is Map
      ? response['status_details']
      : payload['status_details'];
  final statusDetailsMap = statusDetails is Map
      ? Map<String, Object?>.from(statusDetails)
      : const <String, Object?>{};
  final error = statusDetailsMap['error'] ?? payload['error'];
  final errorMap = error is Map
      ? Map<String, Object?>.from(error)
      : const <String, Object?>{};
  final status = _responseStatusFromPayload(payload);

  return {
    if (responseId != null && responseId.isNotEmpty) 'response_id': responseId,
    if (status != null) 'status': status,
    if ((statusDetailsMap['type']?.toString() ?? '').isNotEmpty)
      'status_detail_type': statusDetailsMap['type'].toString(),
    if ((statusDetailsMap['reason']?.toString() ?? '').isNotEmpty)
      'status_detail_reason': statusDetailsMap['reason'].toString(),
    if ((errorMap['type']?.toString() ?? '').isNotEmpty)
      'error_type': errorMap['type'].toString(),
    if ((errorMap['code']?.toString() ?? '').isNotEmpty)
      'error_code': errorMap['code'].toString(),
    if ((errorMap['message']?.toString() ?? '').isNotEmpty)
      'error_message': errorMap['message'].toString(),
    'user_content': userContent,
    'assistant_text': assistantText,
    'assistant_answered': assistantText.trim().isNotEmpty,
    'function_call_count': functionCallCount,
    ...latencyMetrics,
  };
}

Map<String, Object?> _realtimeResponseCreateDispatchFailureDetails({
  required String reason,
  String message = '',
  required bool audioResponse,
  required bool textResponse,
  Map<String, Object?> latencyMetrics = const {},
}) {
  final cleanMessage = message.trim();
  return {
    'reason': reason,
    if (cleanMessage.isNotEmpty) 'message': cleanMessage,
    'audio_response': audioResponse,
    'text_response': textResponse,
    ..._realtimeUsageLatencyPayload(latencyMetrics),
  };
}

Map<String, Object?> _realtimeToolOutputDispatchFailureDetails({
  String? callId,
  required String reason,
  String message = '',
  required bool createResponse,
  Map<String, Object?> latencyMetrics = const {},
}) {
  final cleanCallId = callId?.trim() ?? '';
  final cleanMessage = message.trim();
  return {
    if (cleanCallId.isNotEmpty) 'call_id': cleanCallId,
    'reason': reason,
    if (cleanMessage.isNotEmpty) 'message': cleanMessage,
    'create_response': createResponse,
    'user_visible_recovery': createResponse,
    ..._realtimeUsageLatencyPayload(latencyMetrics),
  };
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
  return _stripLeadingVoiceFillers(
    text
        .substring(match.end)
        .toLowerCase()
        .replaceAll(RegExp(r'\s+'), ' ')
        .trim(),
  );
}

String _normalizedVoiceCommand(String transcript) {
  final normalized = transcript
      .toLowerCase()
      .replaceAll(RegExp(r"[^a-z0-9\s']"), ' ')
      .replaceAll(RegExp(r'\s+'), ' ')
      .trim();

  return _stripLeadingVoiceFillers(
    _stripNormalizedWakePhrase(_stripLeadingVoiceFillers(normalized)),
  ).replaceFirst(RegExp(r'\s+bean$'), '').trim();
}

String _stripLeadingVoiceFillers(String command) {
  if (command.isEmpty) return command;
  if (RegExp(r'^(?:uh huh|mm hmm|mhm)\b').hasMatch(command)) {
    return command;
  }
  return command
      .replaceFirst(
        RegExp(r"^(?:(?:uh|um|umm|erm|er|ah|hmm|hm|mm|mhm|well|so)\s+)+"),
        '',
      )
      .trim();
}

String _stripNormalizedWakePhrase(String command) {
  if (command.isEmpty) return command;
  final wakeStarter = r'(?:hey|hay|hi|hello|okay|ok|kay)';
  final beanVariant =
      r'(?:bean|beans|been|ben|beam|beem|bein|being|bin|bing|bien|bain|bane|dean|deen)';
  final compactBeanVariant =
      r'b(?:ean|eans|een|en|eam|eem|ein|eing|in|ing|ien|ain|ane)';

  return command
      .replaceFirst(
        RegExp(
          '^(?:'
          '$wakeStarter\\s+$beanVariant|'
          '$wakeStarter\\s*$compactBeanVariant|'
          '$wakeStarter\\s+(?:b|bee)|'
          r'a\s+bean|'
          r'heybean|'
          r'bean'
          r')\s+',
        ),
        '',
      )
      .trim();
}

bool _voiceCancelRequested(String transcript) {
  final command = _normalizedVoiceCommand(transcript);
  if (command.isEmpty) return false;
  if (RegExp(
    r"^(?:stop|stop it|stop talking|stop right there|stop for now|pause|pause it|pause for now|pause for a second|hold up|hold on|hold that thought|hang on|hang on a second|wait|wait stop|wait a second|one second|just a second|one sec|give me a second|give me one second|give me a moment|stop for a second|let me stop you|be quiet|quiet|cancel|cancel it|cancel that|cancel this|cancel response|cancel request|cancel the request|cancel my request|never\s*mind|nevermind|forget it|forget that|forget this|scratch that|that's all|that is all|stop listening|we'?re done|we are done)$",
  ).hasMatch(command)) {
    return true;
  }
  return RegExp(
    r"\b(?:stop talking|stop right there|stop for now|pause for now|pause for a second|hold up|hold on|hold that thought|hang on|hang on a second|wait stop|wait a second|one second|just a second|one sec|give me (?:a|one) second|give me a moment|stop for a second|let me stop you|be quiet|cancel (?:it|that|this|the request|my request)|never\s*mind|nevermind|forget (?:it|that|this)|scratch that|stop listening)\b",
  ).hasMatch(command);
}

bool _voiceBackgroundWorkCancelRequested(String transcript) {
  final command = _normalizedVoiceCommand(transcript);
  if (command.isEmpty) return false;
  if (RegExp(
    r"^(?:cancel|cancel it|cancel that|cancel this|cancel request|cancel the request|cancel my request|never\s*mind|nevermind|forget it|forget that|forget this|don'?t do that|do not do that|scratch that)$",
  ).hasMatch(command)) {
    return true;
  }
  return RegExp(
    r"\b(?:cancel (?:it|that|this|the request|my request)|never\s*mind|nevermind|forget (?:it|that|this)|don'?t do that|do not do that|scratch that)\b",
  ).hasMatch(command);
}

bool realtimeVoiceBackgroundWorkCancelForTesting(String transcript) =>
    _voiceBackgroundWorkCancelRequested(transcript);

bool _conversationEndRequested(String transcript) =>
    _voiceCancelRequested(transcript) ||
    _politeConversationEndRequested(transcript) ||
    RegExp(
      r"\b(?:thanks|thank you|that'?s all|stop listening|cancel)\s+(?:bean|been|beam|being)\b",
      caseSensitive: false,
    ).hasMatch(transcript) ||
    RegExp(
      r"\b(?:thanks|thank you),?\s*(?:that'?s all|we'?re done)\b",
      caseSensitive: false,
    ).hasMatch(transcript);

bool _politeConversationEndRequested(String transcript) {
  final command = _normalizedVoiceCommand(transcript);
  if (command.isEmpty) return false;

  return RegExp(
    r"^(?:thanks|thank you|thanks bean|thank you bean|that'?s all|that is all|that'?s enough|that is enough|that'?ll do|that will do|nothing else|all set|i'?m done|im done|i am done|we'?re done|we are done|stop listening|goodbye|good bye|bye|bye bean|see you|talk to you later)$",
  ).hasMatch(command);
}

bool _voiceCommandIsCapabilityQuestion(String transcript) {
  final command = _normalizedVoiceCommand(transcript);
  if (command.isEmpty) return false;
  final asksCapability = RegExp(
    r"^(?:can|could|would)\s+you\s+(?:really\s+|actually\s+)?(?:add|create|make|put|schedule|write|save|delete|remove|cancel|update|change|move|reschedule|complete|finish|mark|remind|remember|undo|revert|reverse|plan|organize|prioritize)\b|^(?:are you able to|do you know how to|is it possible (?:for you )?to|can bean|could bean|does bean know how to|does bean support)\s+(?:add|create|make|put|schedule|write|save|delete|remove|cancel|update|change|move|reschedule|complete|finish|mark|remind|remember|undo|revert|reverse|plan|organize|prioritize)\b",
  ).hasMatch(command);
  return asksCapability && !_voiceCommandLooksConcreteAction(command);
}

bool _voiceCommandLooksConcreteAction(String command) {
  if (RegExp(
    r'\b(?:called|named|titled|labelled|labeled|that says|saying|with title|with the title)\b',
  ).hasMatch(command)) {
    return true;
  }
  if (RegExp(
    r'\b(?:today|tonight|tomorrow|yesterday|this morning|this afternoon|this evening|next week|next month|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b',
  ).hasMatch(command)) {
    return true;
  }
  if (RegExp(
    r'\b(?:at|by|before|after|from|until)\s+\d{1,2}(?::\d{2})?\s*(?:am|pm)?\b',
  ).hasMatch(command)) {
    return true;
  }
  if (RegExp(
    r'\b\d{1,2}[/-]\d{1,2}(?:[/-]\d{2,4})?\b|\b\d{4}-\d{2}-\d{2}\b',
  ).hasMatch(command)) {
    return true;
  }
  if (RegExp(
        r'\b(?:for|about|to)\s+(?:me|my|the|a|an)\s+\w+',
      ).hasMatch(command) &&
      !RegExp(
        r'\b(?:something|anything|things|stuff|items)\b',
      ).hasMatch(command)) {
    return true;
  }
  return false;
}

bool _voiceCommandLooksLikeMutationAction(String command) {
  if (RegExp(
    r'\b(?:add|create|put|move|reschedule|schedule|update|change|delete|remove|cancel|complete|finish|mark|remind|remember|undo|revert)\b',
  ).hasMatch(command)) {
    return true;
  }
  return RegExp(
    r'\b(?:take it back|take that back|reverse that|reverse it)\b',
  ).hasMatch(command);
}

bool _voiceCommandNeedsAgentWork(String transcript) {
  final command = _normalizedVoiceCommand(transcript);
  if (command.isEmpty) return false;
  if (_voiceCommandIsCapabilityQuestion(command)) return false;
  if (RegExp(
    r'\b(?:calendar|calendars|event|events|agenda|schedule|schedules|meeting|meetings|appointment|appointments|task|tasks|todo|to do|reminder|reminders|approval|approvals|workspace|workspaces|google calendar)\b',
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
  if (_voiceCommandLooksLikeMutationAction(command)) {
    return true;
  }
  if (RegExp(r'\b(?:plan|organize|prioritize)\b').hasMatch(command) &&
      RegExp(
        r'\b(?:day|today|tomorrow|week|schedule|work|tasks|calendar|morning|afternoon|evening)\b',
      ).hasMatch(command)) {
    return true;
  }
  return _voiceCommandLooksLikeAppStateRead(command);
}

bool _voiceCommandRequiresBackgroundWork(String transcript) {
  final command = _normalizedVoiceCommand(transcript);
  if (command.isEmpty) return false;
  if (_voiceCommandIsCapabilityQuestion(command)) return false;
  if (RegExp(
    r'\b(?:calendar|calendars|event|events|agenda|schedule|schedules|meeting|meetings|appointment|appointments|task|tasks|todo|to do|reminder|reminders|approval|approvals|workspace|workspaces|google calendar)\b',
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
  if (_voiceCommandLooksLikeMutationAction(command)) {
    return true;
  }
  if (RegExp(r'\b(?:plan|organize|prioritize)\b').hasMatch(command) &&
      RegExp(
        r'\b(?:day|today|tomorrow|week|schedule|work|tasks|calendar|morning|afternoon|evening)\b',
      ).hasMatch(command)) {
    return true;
  }
  return _voiceCommandLooksLikeAppStateRead(command);
}

bool _voiceCommandLooksLikeAppStateRead(String command) {
  return RegExp(
    r"\b(?:what do i have|what have i got|do i have anything|anything on|anything coming up|what'?s coming up|whats coming up|what is coming up|what'?s on my plate|whats on my plate|what is on my plate|what'?s on deck|whats on deck|what is on deck|what'?s next|whats next|what is next|what should i do next|next up|what'?s due|whats due|what is due|what'?s overdue|whats overdue|what is overdue|anything overdue|overdue tasks?|overdue reminders?|tasks? due|reminders? due|due soon|due later|pending tasks?|pending reminders?|unfinished tasks?|open tasks?|open reminders?|what'?s pending|whats pending|what is pending|what'?s unfinished|whats unfinished|what is unfinished|am i free|am i busy|am i available|when am i free|when am i busy|when am i available|free after|free before|open after|open before|available after|available before|busy after|busy before|what'?s open|whats open|what is open|open slot|open slots|free slot|free slots|do i have (?:an? )?(?:opening|open slot|free slot|gap|conflict|notes?|note)|any conflicts?|schedule conflicts?|calendar conflicts?|double booked|overlap(?:ping|s)?|gap between|what do you remember about|what did you (?:just )?save about|what did i say about|what have i said about|what did i tell you about|what have i told you about|what (?:did|have) i mention(?:ed)?|did i mention|what notes? do i have|show my notes?|find my notes?|search my notes?|what are my preferences|what do you know about my preferences|what is my preference for|what did i ask you (?:earlier|before)|what was my (?:earlier|previous|last) request|request history)\b",
  ).hasMatch(command);
}

bool _realtimeLateToolCallDuplicatesFallback({
  required String activeUserContent,
  required String fallbackQueuedUserContent,
}) {
  final active = _normalizedVoiceCommand(activeUserContent);
  final fallback = _normalizedVoiceCommand(fallbackQueuedUserContent);
  return active.isNotEmpty && fallback.isNotEmpty && active == fallback;
}

Map<String, Object?>? _realtimeUnsupportedDirectAnswer({
  required String userTranscript,
  required String assistantText,
  required bool hasFunctionCall,
  required bool contextRefreshSucceeded,
}) {
  if (hasFunctionCall) return null;

  final command = _normalizedVoiceCommand(userTranscript);
  final spoken = _normalizedVoiceCommand(assistantText);
  if (command.isEmpty || spoken.isEmpty) return null;
  if (_voiceCommandIsCapabilityQuestion(command)) return null;

  final claimsCompleted = _spokenClaimsCompletedWork(spoken);
  final concreteAnswer =
      claimsCompleted || _spokenContainsConcreteAnswer(spoken, assistantText);
  final mustQueue = _voiceCommandMustQueueBackgroundWork(command);
  final appStateRead =
      _voiceCommandLooksLikeAppStateRead(command) ||
      _voiceCommandLooksLikeDashboardDomainRead(command);

  if (mustQueue &&
      (concreteAnswer ||
          !_realtimeSpokenAnswerAllowsBackgroundQueue(
            command,
            assistantText,
          ))) {
    return {
      'reason': 'background_required',
      'context_refresh_succeeded': contextRefreshSucceeded,
      'concrete_answer': concreteAnswer,
    };
  }

  if (appStateRead && concreteAnswer && !contextRefreshSucceeded) {
    return {
      'reason': 'missing_fresh_context',
      'context_refresh_succeeded': false,
      'concrete_answer': true,
    };
  }

  return null;
}

bool _realtimeUnsupportedDirectAnswerNeedsBackgroundVerification(
  Map<String, Object?>? unsupportedDirectAnswer,
) {
  final reason = unsupportedDirectAnswer?['reason']?.toString();
  return reason == 'background_required' || reason == 'missing_fresh_context';
}

bool _voiceCommandMustQueueBackgroundWork(String command) {
  if (_voiceCommandMentionsWeather(command)) {
    return !_voiceCommandCanUseWarmedCurrentWeather(command);
  }
  if (RegExp(
    r'\b(?:flight|flights|airfare|airfares|ticket|tickets|hotel|hotels|rental car|rentals|reservation|reservations|booking|bookings|price|prices|cheapest|available|availability|news|traffic|stock|stocks|sports|score|scores)\b',
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
  if (_voiceCommandLooksLikeMutationAction(command)) {
    return true;
  }
  if (RegExp(r'\b(?:plan|organize|prioritize)\b').hasMatch(command) &&
      RegExp(
        r'\b(?:day|today|tomorrow|week|schedule|work|tasks|calendar|morning|afternoon|evening)\b',
      ).hasMatch(command)) {
    return true;
  }
  return false;
}

bool _voiceCommandMentionsWeather(String command) {
  return RegExp(
    r'\b(?:weather|forecast|temperature|temp|rain|raining|storm|storming|snow|snowing|humidity|wind|windy|cloudy|sunny)\b',
  ).hasMatch(command);
}

bool _voiceCommandCanUseWarmedCurrentWeather(String command) {
  if (!_voiceCommandMentionsWeather(command)) return false;
  if (RegExp(
    r'\b(?:forecast|tomorrow|tonight|later|weekend|this week|next week|hourly|daily|high|low|chance|precipitation|rain chance|snow chance|will it|should i|bring|wear|run|errands|drive|commute|travel)\b',
  ).hasMatch(command)) {
    return false;
  }
  final localLocationReference = RegExp(
    r"\b(?:near me|around me|around here|where i am|where i'?m at|where im at|my area|my location|for me|at home|here|local)\b",
  ).hasMatch(command);
  final namedLocationReference = RegExp(
    r'\b(?:in|for|near|around|at)\s+[a-z][a-z]+(?:\s+[a-z][a-z]+){0,3}\b',
  ).hasMatch(command);
  if (namedLocationReference && !localLocationReference) {
    return false;
  }
  return true;
}

bool _voiceCommandLooksLikeDashboardDomainRead(String command) {
  return RegExp(
    r'\b(?:calendar|calendars|event|events|agenda|schedule|schedules|meeting|meetings|appointment|appointments|task|tasks|todo|to do|reminder|reminders|approval|approvals|workspace|workspaces|google calendar)\b',
  ).hasMatch(command);
}

bool _realtimeSpokenAnswerAllowsBackgroundQueue(
  String userTranscript,
  String assistantText,
) {
  final spoken = _normalizedVoiceCommand(assistantText);
  if (spoken.isEmpty) return true;
  if (spoken.length > 180) return false;
  if (_spokenClaimsCompletedWork(spoken)) return false;
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

bool _realtimeSpokenAnswerShouldSkipBackgroundQueue(
  String userTranscript,
  String assistantText,
) {
  final spoken = _normalizedVoiceCommand(assistantText);
  if (spoken.isEmpty) return false;
  if (_spokenClaimsCompletedWork(spoken)) return false;
  return !_realtimeSpokenAnswerAllowsBackgroundQueue(
    userTranscript,
    assistantText,
  );
}

bool _realtimeSpokenAnswerClaimsCompletedWork(String assistantText) {
  final spoken = _normalizedVoiceCommand(assistantText);
  return spoken.isNotEmpty && _spokenClaimsCompletedWork(spoken);
}

String _realtimeBackgroundQuickReplyContext(String assistantText) {
  final trimmed = assistantText.trim();
  if (trimmed.isEmpty) return '';
  return _realtimeSpokenAnswerClaimsCompletedWork(trimmed) ? '' : trimmed;
}

bool _spokenClaimsCompletedWork(String spoken) {
  if (RegExp(
    r"\b(?:done|all set|finished|completed|it'?s done|its done|that'?s done|thats done)\b",
  ).hasMatch(spoken)) {
    return true;
  }
  return RegExp(
    r"\b(?:i|i(?:'|’)?ve|i have|bean)\s+(?:added|created|scheduled|moved|rescheduled|updated|changed|deleted|removed|cancelled|canceled|completed|finished|marked|saved|remembered|planned|organized|prioritized)\b",
  ).hasMatch(spoken);
}

bool _spokenContainsConcreteAnswer(String spoken, String originalText) {
  final raw = originalText;
  if (RegExp(r'[;:]').hasMatch(raw) ||
      RegExp(r'\b\d+\b').hasMatch(spoken) ||
      RegExp(r'\d').hasMatch(raw)) {
    return true;
  }
  return RegExp(
    r"\b(?:you have|you ve got|you've got|you got|you have got|you have \w+ tasks|you ve \w+ tasks|you don t have|you do not have|you have no|you(?:'|’)?re free|you re free|you are free|you(?:'|’)?re busy|you re busy|you are busy|you have nothing|i found|i see|i can see|i(?:'|’)?m seeing|i am seeing|your calendar is|your schedule is|your agenda is|your next|next meeting|next event|next task|next reminder|first thing|nothing on|nothing scheduled|nothing else|nothing due|no meetings|no events|no tasks|no reminders|no conflicts?|no openings?|calendar is clear|schedule is clear|agenda is clear|looks clear|free after|free before|free until|busy after|busy before|busy until|there are|there is|there(?:'|’)?s|there s|here are|here s|here's|heres|it is|it s|it's|its|looks like|today you|today there|for today|on your list|todo list|to do list|tasks today|due|scheduled|starts|ends|temperature|degrees|degree|percent|humidity|wind|mph|clear skies|partly cloudy|cloudy|sunny|rain|raining|storm|storming|forecast says|weather is)\b",
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

Map<String, Object?> _realtimeFreshContextRecoveryTelemetry({
  required String pendingContent,
  required String pendingReason,
  required String userContent,
}) {
  final cleanPending = pendingContent.replaceAll(RegExp(r'\s+'), ' ').trim();
  if (cleanPending.isEmpty ||
      _normalizedVoiceCommand(cleanPending) !=
          _normalizedVoiceCommand(userContent)) {
    return const {};
  }

  final cleanReason = pendingReason.replaceAll(RegExp(r'\s+'), ' ').trim();

  return {
    'context_refresh_recovery': true,
    if (cleanReason.isNotEmpty) 'context_refresh_failure_reason': cleanReason,
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

_FinalVoice _realtimeFinalVoiceForTurn(
  String userContent,
  String quickReplyText,
  String assistantContent,
) {
  final text = _speechTextFromAssistant(assistantContent);
  if (text.isEmpty) return const _FinalVoice(text: '');
  if (_finalResponseIsDetailed(assistantContent, text)) {
    return _FinalVoice(text: _finalDetailNotice(userContent));
  }
  if (quickReplyText.trim().isEmpty) return _FinalVoice(text: text);
  if (_quickReplyCoversFinal(quickReplyText, text)) {
    return const _FinalVoice(text: '', suppressFinal: true);
  }
  final continuation = _finalContinuationAfterQuickReply(quickReplyText, text);
  if (continuation.isEmpty) {
    return const _FinalVoice(text: '', suppressFinal: true);
  }
  return _FinalVoice(text: continuation);
}

String _realtimeBackgroundFailureVoice(String userContent) {
  return "I couldn't complete that request. Please try again or type it in chat.";
}

bool _realtimeBackgroundFailureVoiceAcknowledgesFailure(String text) {
  final normalized = text.toLowerCase().replaceAll(RegExp(r'\s+'), ' ').trim();
  if (normalized.isEmpty) return false;
  return RegExp(
    r"\b(?:couldn'?t|could not|wasn'?t able|was not able|didn'?t complete|did not complete|failed|couldn'?t finish|could not finish)\b",
  ).hasMatch(normalized);
}

Map<String, Object?> _realtimeBackgroundWatchFailureDetails({
  required int runId,
  required String userContent,
  required int attempt,
  required String reason,
  String status = '',
  String message = '',
  required String failureVoice,
  required bool delivered,
}) {
  final cleanStatus = status.trim();
  final cleanMessage = message.trim();
  return {
    'run_id': runId,
    'attempt': attempt,
    'reason': reason,
    if (cleanStatus.isNotEmpty) 'status': cleanStatus,
    if (cleanMessage.isNotEmpty) 'message': cleanMessage,
    'user_content': userContent,
    'failure_voice_text': failureVoice,
    'failure_voice_acknowledged':
        _realtimeBackgroundFailureVoiceAcknowledgesFailure(failureVoice),
    'failure_voice_delivered': delivered,
  };
}

String _realtimeBackgroundResultInstruction(
  String resultStatus, {
  String priorSpokenClaim = '',
}) {
  switch (resultStatus) {
    case 'failed':
      return 'Tell the user the request did not complete. Do not imply success or ongoing work. Keep it to one short sentence.';
    case 'cancelled':
      return 'Confirm briefly that the request was cancelled. Do not imply success or ongoing work.';
    default:
      if (priorSpokenClaim.trim().isNotEmpty) {
        return 'Continue with the verified result. A prior spoken answer may have been unverified; correct it directly if the result differs, otherwise confirm briefly. Keep it to one short sentence and do not repeat the prior wording.';
      }
      return 'Continue naturally with the completed result. Do not repeat or paraphrase anything already spoken. If the result is long, give a concise voice summary and refer to chat.';
  }
}

String _realtimeBackgroundCompletionEventType({required bool deliveredVoice}) =>
    deliveredVoice
    ? 'realtime_background_completed'
    : 'realtime_background_completed_after_voice_closed';

String _realtimeBackgroundCancelEventType({required bool deliveredVoice}) =>
    deliveredVoice
    ? 'realtime_background_cancelled'
    : 'realtime_background_cancelled_after_voice_closed';

bool _realtimeBackgroundResultDeliveryBusy({
  required bool assistantSpeaking,
  required bool responseCreateInFlight,
  required bool userSpeechActive,
}) => assistantSpeaking || responseCreateInFlight || userSpeechActive;

Map<String, Object?> _realtimeBackgroundDeliveryDeferredDetails({
  int? runId,
  required String spokenText,
  String priorSpokenClaim = '',
  required bool assistantSpeaking,
  required bool responseCreateInFlight,
  required bool userSpeechActive,
}) => {
  if (runId != null) 'run_id': runId,
  'reason': userSpeechActive ? 'user_speaking' : 'assistant_busy',
  'spoken_character_count': spokenText.length,
  'spoken_text': spokenText,
  ..._realtimeVerificationRepairTelemetry(priorSpokenClaim),
  'assistant_speaking': assistantSpeaking,
  'response_create_in_flight': responseCreateInFlight,
  'user_speech_active': userSpeechActive,
};

Map<String, Object?> _realtimeVerificationRepairTelemetry(
  String priorSpokenClaim,
) {
  final clean = priorSpokenClaim.replaceAll(RegExp(r'\s+'), ' ').trim();
  if (clean.isEmpty) return const {};
  return {'verification_repair': true, 'prior_spoken_claim': clean};
}

Map<String, Object?> _realtimeBackgroundProgressSkipDetails({
  required int elapsedMs,
  required String instruction,
  required bool assistantSpeaking,
  required bool responseCreateInFlight,
}) => {
  'reason': 'assistant_busy',
  'elapsed_ms': elapsedMs,
  'instruction': instruction,
  'assistant_speaking': assistantSpeaking,
  'response_create_in_flight': responseCreateInFlight,
};

Map<String, Object?> _realtimeProgressPromptSpokenDetails({
  required String? responseId,
  required int elapsedMs,
  required String instruction,
  required String userRequest,
  required List<String> alreadySpoken,
  required String spokenText,
}) {
  final spoken = _speechTextFromAssistant(spokenText);
  return {
    if (responseId != null && responseId.trim().isNotEmpty)
      'response_id': responseId.trim(),
    'elapsed_ms': elapsedMs,
    'instruction': instruction,
    'user_request': userRequest,
    'already_spoken': alreadySpoken
        .map((item) => item.replaceAll(RegExp(r'\s+'), ' ').trim())
        .where((item) => item.isNotEmpty)
        .take(6)
        .toList(),
    'spoken_text': spoken,
    'spoken_character_count': spoken.length,
    'spoken_sentence_count': spoken.isEmpty ? 0 : _spokenSentenceCount(spoken),
  };
}

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
