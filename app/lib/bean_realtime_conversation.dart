import 'dart:async';
import 'dart:convert';
import 'dart:io';

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
  bool _active = false;

  bool get active => _active;
  int? get localSessionId => _session?.session.id;

  Future<HermesSession> start({
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
      microphoneEnabled ? 'Starting realtime voice...' : 'Starting realtime...',
    );

    final realtimeSession = await apiClient.startRealtimeSession(
      title: 'Realtime chat',
      runtimeMode: 'realtime',
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

    if (microphoneEnabled) {
      final stream = await navigator.mediaDevices.getUserMedia({
        'audio': true,
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
        onStatus?.call('Listening');
      }
    };

    pc.onTrack = (RTCTrackEvent event) {
      // Audio tracks are rendered by the platform WebRTC audio session.
      if (event.track.kind == 'audio') {
        onStatus?.call('Bean is connected');
      }
    };

    final offer = await pc.createOffer({'offerToReceiveAudio': true});
    await pc.setLocalDescription(offer);
    final answer = await _createOpenAiWebRtcCall(
      clientSecret: realtimeSession.clientSecret,
      model: realtimeSession.model,
      offerSdp: offer.sdp ?? '',
    );
    await pc.setRemoteDescription(RTCSessionDescription(answer, 'answer'));
    if (channel.state == RTCDataChannelState.RTCDataChannelOpen &&
        !(_dataChannelOpen?.isCompleted ?? true)) {
      _dataChannelOpen?.complete();
    }

    _active = true;
    onStatus?.call(microphoneEnabled ? 'Listening' : 'Ready');
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
    channel.send(
      RTCDataChannelMessage(
        jsonEncode({
          'type': 'response.create',
          'response': {
            'modalities': audioResponse ? ['text', 'audio'] : ['text'],
          },
        }),
      ),
    );
  }

  void setMicrophoneEnabled(bool enabled) {
    for (final track
        in _localStream?.getAudioTracks() ?? const <MediaStreamTrack>[]) {
      track.enabled = enabled;
    }
  }

  Future<void> interrupt() async {
    _dataChannel?.send(
      RTCDataChannelMessage(jsonEncode({'type': 'response.cancel'})),
    );
    onStatus?.call('Interrupted');
  }

  Future<void> stop() async {
    _active = false;
    _dataChannel?.close();
    await _peerConnection?.close();
    _localStream?.getTracks().forEach((track) => track.stop());
    _dataChannel = null;
    _dataChannelOpen = null;
    _peerConnection = null;
    _localStream = null;
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
      const Duration(seconds: 2),
      onTimeout: () {
        throw TimeoutException('Realtime data channel did not open.');
      },
    );
  }

  Future<String> _createOpenAiWebRtcCall({
    required String clientSecret,
    required String offerSdp,
    String? model,
  }) async {
    final uri = Uri.https('api.openai.com', '/v1/realtime/calls', {
      if (model != null && model.isNotEmpty) 'model': model,
    });
    final client = HttpClient()
      ..connectionTimeout = const Duration(seconds: 15);
    try {
      final request = await client.postUrl(uri);
      request.headers.set(
        HttpHeaders.authorizationHeader,
        'Bearer $clientSecret',
      );
      request.headers.set(HttpHeaders.contentTypeHeader, 'application/sdp');
      request.write(offerSdp);
      final response = await request.close().timeout(
        const Duration(seconds: 20),
      );
      final body = await utf8.decoder.bind(response).join();
      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw HttpException(
          'OpenAI Realtime WebRTC call failed: $body',
          uri: uri,
        );
      }
      return body;
    } finally {
      client.close(force: true);
    }
  }

  void _handleDataChannelMessage(RTCDataChannelMessage message) {
    if (message.isBinary) return;
    final decoded = jsonDecode(message.text);
    if (decoded is! Map<String, Object?>) return;
    final type = decoded['type']?.toString() ?? '';

    final transcript = _transcriptText(type, decoded);
    if (transcript != null && transcript.isNotEmpty) {
      final role = _transcriptRole(type, decoded);
      onTranscript?.call(role, transcript);
    }

    if (type == 'response.output_item.done' ||
        type == 'conversation.item.done') {
      final item = decoded['item'];
      if (item is Map && item['type'] == 'function_call') {
        unawaited(_handleFunctionCall(Map<String, Object?>.from(item)));
      }
    }
  }

  String _transcriptRole(String type, Map<String, Object?> event) {
    if (type.contains('input')) return 'user';
    final item = event['item'];
    if (item is Map && item['role'] != null) return item['role'].toString();
    return 'assistant';
  }

  String? _transcriptText(String type, Map<String, Object?> event) {
    if (type.endsWith('.delta')) return null;
    for (final key in const ['transcript', 'text']) {
      final value = event[key];
      if (value is String && value.trim().isNotEmpty) return value.trim();
    }
    final item = event['item'];
    if (item is Map && item['content'] is List) {
      final parts = <String>[];
      for (final content in item['content'] as List) {
        if (content is! Map) continue;
        final text = content['text'] ?? content['transcript'];
        if (text is String && text.trim().isNotEmpty) parts.add(text.trim());
      }
      if (parts.isNotEmpty) return parts.join('\n');
    }
    return null;
  }

  Future<void> _handleFunctionCall(Map<String, Object?> item) async {
    final sessionId = _session?.session.id;
    if (sessionId == null) return;
    final name = item['name']?.toString() ?? '';
    final callId = item['call_id']?.toString() ?? item['id']?.toString();
    final argumentsText = item['arguments']?.toString() ?? '{}';
    Map<String, Object?> arguments;
    try {
      final decoded = jsonDecode(argumentsText);
      arguments = decoded is Map<String, Object?>
          ? decoded
          : Map<String, Object?>.from(decoded as Map);
    } catch (_) {
      arguments = const {};
    }

    final output = await apiClient.submitRealtimeToolCall(
      sessionId: sessionId,
      toolName: name,
      callId: callId,
      arguments: arguments,
    );
    final runId = output['run_id'];
    if (runId is int) onRunQueued?.call(runId);

    _dataChannel?.send(
      RTCDataChannelMessage(
        jsonEncode({
          'type': 'conversation.item.create',
          'item': {
            'type': 'function_call_output',
            'call_id': callId,
            'output': jsonEncode(output),
          },
        }),
      ),
    );
    _dataChannel?.send(
      RTCDataChannelMessage(jsonEncode({'type': 'response.create'})),
    );
  }
}
