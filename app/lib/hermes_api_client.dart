import 'dart:convert';
import 'dart:io';

const String hermesApiBaseUrl = String.fromEnvironment(
  'HERMES_API_BASE_URL',
  defaultValue: 'http://127.0.0.1:8000/api',
);

typedef HermesApiTransport =
    Future<HermesApiResponse> Function(HermesApiRequest request);

class HermesApiClient {
  HermesApiClient({
    Uri? baseUrl,
    HermesApiTransport? transport,
    this.bearerToken,
  }) : baseUrl = baseUrl ?? Uri.parse(hermesApiBaseUrl),
       _transport = transport ?? _defaultTransport;

  final Uri baseUrl;
  final HermesApiTransport _transport;
  String? bearerToken;

  Future<HermesAuthResult> register({
    required String name,
    required String email,
    required String password,
    String? passwordConfirmation,
  }) async {
    final data = await _sendJson(
      'POST',
      '/auth/register',
      body: {
        'name': name,
        'email': email,
        'password': password,
        'password_confirmation': passwordConfirmation ?? password,
      },
      authenticated: false,
    );
    return _rememberAuth(HermesAuthResult.fromJson(_expectMap(data['data'])));
  }

  Future<HermesAuthResult> login({
    required String email,
    required String password,
  }) async {
    final data = await _sendJson(
      'POST',
      '/auth/login',
      body: {'email': email, 'password': password},
      authenticated: false,
    );
    return _rememberAuth(HermesAuthResult.fromJson(_expectMap(data['data'])));
  }

  Future<void> logout() async {
    await _sendJson('POST', '/auth/logout');
    bearerToken = null;
  }

  Future<HermesUser> me() async {
    final data = await _sendJson('GET', '/auth/me');
    return HermesUser.fromJson(_expectMap(data['data']));
  }

  Future<void> deleteAccount() async {
    await _sendJson('DELETE', '/account');
    bearerToken = null;
  }

  Future<Map<String, Object?>> exportAccount() async {
    final data = await _sendJson('GET', '/account/export');
    return _expectMap(data['data']);
  }

  Future<HermesTodaySummary> todaySummary() async {
    final data = await _sendJson('GET', '/today');
    return HermesTodaySummary.fromJson(_expectMap(data['data']));
  }

  Future<List<HermesTask>> listTasks() async {
    final data = await _sendJson('GET', '/tasks');
    return _expectList(
      data['data'],
    ).map((json) => HermesTask.fromJson(_expectMap(json))).toList();
  }

  Future<List<HermesReminder>> listReminders() async {
    final data = await _sendJson('GET', '/reminders');
    return _expectList(
      data['data'],
    ).map((json) => HermesReminder.fromJson(_expectMap(json))).toList();
  }

  Future<List<HermesCalendarEvent>> listCalendarEvents() async {
    final data = await _sendJson('GET', '/calendar-events');
    return _expectList(
      data['data'],
    ).map((json) => HermesCalendarEvent.fromJson(_expectMap(json))).toList();
  }

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

  HermesAuthResult _rememberAuth(HermesAuthResult result) {
    bearerToken = result.token;
    return result;
  }

  Future<Map<String, Object?>> _sendJson(
    String method,
    String path, {
    Map<String, Object?>? body,
    bool authenticated = true,
  }) async {
    final headers = <String, String>{'Accept': 'application/json'};
    if (body != null) headers['Content-Type'] = 'application/json';
    if (authenticated && bearerToken != null) {
      headers['Authorization'] = 'Bearer $bearerToken';
    }
    final request = HermesApiRequest(
      method: method,
      uri: _resolveApiPath(path),
      path: path.startsWith('/') ? path : '/$path',
      headers: headers,
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
      request.headers.forEach(ioRequest.headers.set);
      if (request.body != null) ioRequest.write(jsonEncode(request.body));

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
    this.headers = const {},
    this.body,
  });

  final String method;
  final Uri uri;
  final String path;
  final Map<String, String> headers;
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

class HermesAuthResult {
  const HermesAuthResult({required this.token, required this.user});

  final String token;
  final HermesUser user;

  factory HermesAuthResult.fromJson(Map<String, Object?> json) =>
      HermesAuthResult(
        token: _expectString(json['token']),
        user: HermesUser.fromJson(_expectMap(json['user'])),
      );
}

class HermesUser {
  const HermesUser({required this.id, required this.name, required this.email});

  final int id;
  final String name;
  final String email;

  factory HermesUser.fromJson(Map<String, Object?> json) => HermesUser(
    id: _expectInt(json['id']),
    name: _expectString(json['name']),
    email: _expectString(json['email']),
  );
}

class HermesTodaySummary {
  const HermesTodaySummary({
    this.session,
    required this.tasks,
    required this.reminders,
    required this.calendarEvents,
    required this.activityEvents,
    required this.approvals,
    required this.blockers,
  });

  final HermesSession? session;
  final List<HermesTask> tasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendarEvents;
  final List<HermesActivityEvent> activityEvents;
  final List<HermesApproval> approvals;
  final List<HermesBlocker> blockers;

  factory HermesTodaySummary.fromJson(Map<String, Object?> json) =>
      HermesTodaySummary(
        session: json['session'] == null
            ? null
            : HermesSession.fromJson(_expectMap(json['session'])),
        tasks: _expectList(
          json['tasks'],
        ).map((task) => HermesTask.fromJson(_expectMap(task))).toList(),
        reminders: _expectList(json['reminders'])
            .map((reminder) => HermesReminder.fromJson(_expectMap(reminder)))
            .toList(),
        calendarEvents: _expectList(json['calendar_events'])
            .map((event) => HermesCalendarEvent.fromJson(_expectMap(event)))
            .toList(),
        activityEvents: _expectList(json['activity_events'])
            .map((event) => HermesActivityEvent.fromJson(_expectMap(event)))
            .toList(),
        approvals: _expectList(json['approvals'])
            .map((approval) => HermesApproval.fromJson(_expectMap(approval)))
            .toList(),
        blockers: _expectList(json['blockers'])
            .map((blocker) => HermesBlocker.fromJson(_expectMap(blocker)))
            .toList(),
      );
}

class HermesTask {
  const HermesTask({required this.id, required this.title, this.status});

  final int id;
  final String title;
  final String? status;

  factory HermesTask.fromJson(Map<String, Object?> json) => HermesTask(
    id: _expectInt(json['id']),
    title: _readTitle(json),
    status: json['status'] as String?,
  );
}

class HermesReminder {
  const HermesReminder({required this.id, required this.title, this.dueAt});

  final int id;
  final String title;
  final String? dueAt;

  factory HermesReminder.fromJson(Map<String, Object?> json) => HermesReminder(
    id: _expectInt(json['id']),
    title: _readTitle(json),
    dueAt: (json['due_at'] ?? json['remind_at'] ?? json['dueAt']) as String?,
  );
}

class HermesCalendarEvent {
  const HermesCalendarEvent({
    required this.id,
    required this.title,
    this.startsAt,
  });

  final int id;
  final String title;
  final String? startsAt;

  factory HermesCalendarEvent.fromJson(Map<String, Object?> json) =>
      HermesCalendarEvent(
        id: _expectInt(json['id']),
        title: _readTitle(json),
        startsAt: (json['starts_at'] ?? json['startsAt']) as String?,
      );
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

class HermesApproval {
  const HermesApproval({required this.id, required this.title, this.status});

  final int id;
  final String title;
  final String? status;

  factory HermesApproval.fromJson(Map<String, Object?> json) => HermesApproval(
    id: _expectInt(json['id']),
    title: _readTitle(json),
    status: json['status'] as String?,
  );
}

class HermesBlocker {
  const HermesBlocker({required this.id, required this.reason, this.status});

  final int id;
  final String reason;
  final String? status;

  factory HermesBlocker.fromJson(Map<String, Object?> json) => HermesBlocker(
    id: _expectInt(json['id']),
    reason: _expectString(json['reason']),
    status: json['status'] as String?,
  );
}

String _readTitle(Map<String, Object?> json) =>
    (json['title'] ?? json['name'] ?? json['content'] ?? 'Untitled').toString();

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
