import 'dart:convert';
import 'dart:io';

const String hermesApiBaseUrl = String.fromEnvironment(
  'HERMES_API_BASE_URL',
  defaultValue: 'http://127.0.0.1:8000/api',
);

typedef HermesApiTransport =
    Future<HermesApiResponse> Function(HermesApiRequest request);

class HermesApiClient {
  HermesApiClient({Uri? baseUrl, HermesApiTransport? transport})
    : baseUrl = baseUrl ?? Uri.parse(hermesApiBaseUrl),
      _transport = transport ?? _defaultTransport;

  final Uri baseUrl;
  final HermesApiTransport _transport;

  Future<HermesSession> startSession({
    String? title,
    String? runtimeMode,
    Map<String, Object?>? metadata,
  }) async {
    final body = <String, Object?>{};
    if (title != null) body['title'] = title;
    if (runtimeMode != null) body['runtime_mode'] = runtimeMode;
    if (metadata != null) body['metadata'] = metadata;

    final data = await _sendJson('POST', '/assistant/sessions', body: body);
    return HermesSession.fromJson(_expectMap(data['data']));
  }

  Future<HermesSession> resumeSession(int sessionId) async {
    final data = await _sendJson('GET', '/assistant/sessions/$sessionId');
    return HermesSession.fromJson(_expectMap(data['data']));
  }

  Future<HermesMessageResult> sendMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
  }) async {
    final body = <String, Object?>{'content': content};
    if (metadata != null) body['metadata'] = metadata;

    final data = await _sendJson(
      'POST',
      '/assistant/sessions/$sessionId/messages',
      body: body,
    );
    return HermesMessageResult.fromJson(_expectMap(data['data']));
  }

  Future<List<HermesActivityEvent>> pollActivityEvents(int sessionId) async {
    final data = await _sendJson(
      'GET',
      '/assistant/sessions/$sessionId/events',
    );
    return _expectList(
      data['data'],
    ).map((json) => HermesActivityEvent.fromJson(_expectMap(json))).toList();
  }

  Future<Map<String, Object?>> _sendJson(
    String method,
    String path, {
    Map<String, Object?>? body,
  }) async {
    final request = HermesApiRequest(
      method: method,
      uri: _resolveApiPath(path),
      path: path.startsWith('/') ? path : '/$path',
      body: body,
    );
    final response = await _transport(request);

    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw HermesApiException(response.statusCode, response.body);
    }

    final decoded = response.body.isEmpty
        ? <String, Object?>{}
        : jsonDecode(response.body);
    if (decoded is! Map<String, Object?>) {
      throw const FormatException('Expected top-level JSON object');
    }
    return decoded;
  }

  Uri _resolveApiPath(String path) {
    final basePath = baseUrl.path.endsWith('/')
        ? baseUrl.path.substring(0, baseUrl.path.length - 1)
        : baseUrl.path;
    final requestPath = path.startsWith('/') ? path : '/$path';
    return baseUrl.replace(path: '$basePath$requestPath');
  }

  static Future<HermesApiResponse> _defaultTransport(
    HermesApiRequest request,
  ) async {
    final client = HttpClient();
    try {
      final ioRequest = await client.openUrl(request.method, request.uri);
      ioRequest.headers.set(HttpHeaders.acceptHeader, 'application/json');
      if (request.body != null) {
        ioRequest.headers.set(
          HttpHeaders.contentTypeHeader,
          'application/json',
        );
        ioRequest.write(jsonEncode(request.body));
      }

      final ioResponse = await ioRequest.close();
      final responseBody = await utf8.decoder.bind(ioResponse).join();
      return HermesApiResponse(ioResponse.statusCode, responseBody);
    } finally {
      client.close(force: true);
    }
  }
}

class HermesApiRequest {
  const HermesApiRequest({
    required this.method,
    required this.uri,
    required this.path,
    this.body,
  });

  final String method;
  final Uri uri;
  final String path;
  final Map<String, Object?>? body;
}

class HermesApiResponse {
  const HermesApiResponse(this.statusCode, this.body);

  final int statusCode;
  final String body;
}

class HermesApiException implements Exception {
  const HermesApiException(this.statusCode, this.body);

  final int statusCode;
  final String body;

  @override
  String toString() => 'HermesApiException($statusCode): $body';
}

class HermesSession {
  const HermesSession({required this.id, required this.status, this.title});

  final int id;
  final String status;
  final String? title;

  factory HermesSession.fromJson(Map<String, Object?> json) => HermesSession(
    id: _expectInt(json['id']),
    status: _expectString(json['status']),
    title: json['title'] as String?,
  );
}

class HermesMessageResult {
  const HermesMessageResult({
    required this.status,
    required this.session,
    required this.events,
    this.userMessage,
    this.assistantMessage,
    this.blocker,
  });

  final String status;
  final HermesSession session;
  final List<HermesActivityEvent> events;
  final HermesMessage? userMessage;
  final HermesMessage? assistantMessage;
  final Map<String, Object?>? blocker;

  factory HermesMessageResult.fromJson(Map<String, Object?> json) =>
      HermesMessageResult(
        status: _expectString(json['status']),
        session: HermesSession.fromJson(_expectMap(json['session'])),
        userMessage: json['user_message'] == null
            ? null
            : HermesMessage.fromJson(_expectMap(json['user_message'])),
        assistantMessage: json['assistant_message'] == null
            ? null
            : HermesMessage.fromJson(_expectMap(json['assistant_message'])),
        events: _expectList(json['events'])
            .map((event) => HermesActivityEvent.fromJson(_expectMap(event)))
            .toList(),
        blocker: json['blocker'] == null ? null : _expectMap(json['blocker']),
      );
}

class HermesMessage {
  const HermesMessage({required this.id, required this.role, this.content});

  final int id;
  final String role;
  final String? content;

  factory HermesMessage.fromJson(Map<String, Object?> json) => HermesMessage(
    id: _expectInt(json['id']),
    role: _expectString(json['role']),
    content: json['content'] as String?,
  );
}

class HermesActivityEvent {
  const HermesActivityEvent({
    required this.id,
    required this.eventType,
    this.status,
  });

  final int id;
  final String eventType;
  final String? status;

  factory HermesActivityEvent.fromJson(Map<String, Object?> json) =>
      HermesActivityEvent(
        id: _expectInt(json['id']),
        eventType: _expectString(json['event_type']),
        status: json['status'] as String?,
      );
}

Map<String, Object?> _expectMap(Object? value) {
  if (value is Map<String, Object?>) return value;
  throw FormatException('Expected JSON object, got ${value.runtimeType}');
}

List<Object?> _expectList(Object? value) {
  if (value is List<Object?>) return value;
  throw FormatException('Expected JSON array, got ${value.runtimeType}');
}

int _expectInt(Object? value) {
  if (value is int) return value;
  throw FormatException('Expected integer, got ${value.runtimeType}');
}

String _expectString(Object? value) {
  if (value is String) return value;
  throw FormatException('Expected string, got ${value.runtimeType}');
}
