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
  MediaStream? _localStream;
  HermesRealtimeSession? _session;
  bool _active = false;

  bool get active => _active;
  int? get localSessionId => _session?.session.id;

  Future<HermesSession> start({
    int? workspaceId,
    Map<String, Object?> metadata = const {},
  }) async {
    if (_active && _session != null) return _session!.session;
    onStatus?.call('Starting realtime voice...');

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

    final stream = await navigator.mediaDevices.getUserMedia({
      'audio': true,
      'video': false,
    });
    _localStream = stream;

    final pc = await createPeerConnection({
      'sdpSemantics': 'unified-plan',
      'iceServers': const <Map<String, Object>>[],
    });
    _peerConnection = pc;

    for (final track in stream.getAudioTracks()) {
      await pc.addTrack(track, stream);
    }

    final channel = await pc.createDataChannel(
      'oai-events',
      RTCDataChannelInit()..ordered = true,
    );
    _dataChannel = channel;
    channel.onMessage = _handleDataChannelMessage;
    channel.onDataChannelState = (state) {
      if (state == RTCDataChannelState.RTCDataChannelOpen) {
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

    _active = true;
    onStatus?.call('Listening');
    return realtimeSession.session;
  }

  Future<void> sendText(String text) async {
    final trimmed = text.trim();
    if (trimmed.isEmpty) return;
    final channel = _dataChannel;
    if (channel == null) return;

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
      RTCDataChannelMessage(jsonEncode({'type': 'response.create'})),
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
    _peerConnection = null;
    _localStream = null;
    onStatus?.call('Ready');
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

    final transcript = _transcriptText(decoded);
    if (transcript != null && transcript.isNotEmpty) {
      final role = type.contains('input') ? 'user' : 'assistant';
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

  String? _transcriptText(Map<String, Object?> event) {
    for (final key in const ['transcript', 'delta', 'text']) {
      final value = event[key];
      if (value is String && value.trim().isNotEmpty) return value.trim();
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
