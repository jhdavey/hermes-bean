import 'dart:convert';
import 'dart:io';

const String hermesApiBaseUrl = String.fromEnvironment(
  'HERMES_API_BASE_URL',
  defaultValue: 'https://heybean.org/api',
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

  Future<HermesUser> updateMe({
    String? name,
    String? email,
    String? agentPersonality,
    List<String>? onboardingPriorities,
    String? onboardingContext,
  }) async {
    final data = await _sendJson(
      'PATCH',
      '/auth/me',
      body: {
        if (name != null) 'name': name,
        if (email != null) 'email': email,
        if (agentPersonality != null) 'agent_personality': agentPersonality,
        if (onboardingPriorities != null)
          'onboarding_priorities': onboardingPriorities,
        if (onboardingContext != null) 'onboarding_context': onboardingContext,
      },
    );
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

  Future<HermesTodaySummary> todaySummary({int? workspaceId}) async {
    final data = await _sendJson(
      'GET',
      _pathWithQuery('/today', {
        if (workspaceId != null) 'workspace_id': workspaceId.toString(),
      }),
    );
    return HermesTodaySummary.fromJson(_expectMap(data['data']));
  }

  Future<List<HermesWorkspace>> listWorkspaces() async {
    final data = await _sendJson('GET', '/workspaces');
    return _expectList(
      data['data'],
    ).map((json) => HermesWorkspace.fromJson(_expectMap(json))).toList();
  }

  Future<HermesWorkspace> createWorkspace({required String name}) async {
    final data = await _sendJson('POST', '/workspaces', body: {'name': name});
    return HermesWorkspace.fromJson(_expectMap(data['data']));
  }

  Future<HermesWorkspace> getWorkspace(int workspaceId) async {
    final data = await _sendJson('GET', '/workspaces/$workspaceId');
    return HermesWorkspace.fromJson(_expectMap(data['data']));
  }

  Future<HermesWorkspace> updateWorkspace(
    int workspaceId, {
    String? name,
    Map<String, Object?>? settings,
  }) async {
    final data = await _sendJson(
      'PATCH',
      '/workspaces/$workspaceId',
      body: {
        if (name != null) 'name': name,
        if (settings != null) 'settings': settings,
      },
    );
    return HermesWorkspace.fromJson(_expectMap(data['data']));
  }

  Future<HermesWorkspaceMembership> inviteWorkspaceMember(
    int workspaceId, {
    required String email,
  }) async {
    final data = await _sendJson(
      'POST',
      '/workspaces/$workspaceId/invitations',
      body: {'email': email},
    );
    return HermesWorkspaceMembership.fromJson(_expectMap(data['data']));
  }

  Future<HermesWorkspaceMembership> acceptWorkspaceInvitation(
    String token,
  ) async {
    final data = await _sendJson(
      'POST',
      '/workspace-invitations/$token/accept',
    );
    return HermesWorkspaceMembership.fromJson(_expectMap(data['data']));
  }

  Future<HermesWorkspaceMembership> updateWorkspaceMember(
    int workspaceId,
    int memberId, {
    required String role,
  }) async {
    final data = await _sendJson(
      'PATCH',
      '/workspaces/$workspaceId/members/$memberId',
      body: {'role': role},
    );
    return HermesWorkspaceMembership.fromJson(_expectMap(data['data']));
  }

  Future<void> removeWorkspaceMember(int workspaceId, int memberId) async {
    await _sendJson('DELETE', '/workspaces/$workspaceId/members/$memberId');
  }

  Future<void> leaveWorkspace(int workspaceId) async {
    await _sendJson('POST', '/workspaces/$workspaceId/leave');
  }

  Future<HermesWorkspace> setDefaultWorkspace(int workspaceId) async {
    final data = await _sendJson(
      'PATCH',
      '/workspaces/default',
      body: {'workspace_id': workspaceId},
    );
    return HermesWorkspace.fromJson(_expectMap(data['data']));
  }

  Future<WorkspaceSyncResult> syncWorkspaceAll(
    int sourceWorkspaceId, {
    required int targetWorkspaceId,
    List<String>? resourceTypes,
  }) async {
    final data = await _sendJson(
      'POST',
      '/workspaces/$sourceWorkspaceId/sync-all',
      body: {
        'target_workspace_id': targetWorkspaceId,
        if (resourceTypes != null && resourceTypes.isNotEmpty)
          'resource_types': resourceTypes,
      },
    );
    return WorkspaceSyncResult.fromJson(_expectMap(data['data']));
  }

  Future<List<Map<String, Object?>>> updateWorkspaceGoogleCalendars(
    int workspaceId, {
    required List<String> googleCalendarIds,
    String? defaultExportCalendarId,
  }) async {
    final data = await _sendJson(
      'PATCH',
      '/workspaces/$workspaceId/google-calendars',
      body: {
        'google_calendar_ids': googleCalendarIds,
        if (defaultExportCalendarId != null)
          'default_export_calendar_id': defaultExportCalendarId,
      },
    );
    return _expectList(
      data['data'],
    ).map((json) => Map<String, Object?>.from(_expectMap(json))).toList();
  }

  Future<List<HermesTask>> listTasks() async {
    final data = await _sendJson('GET', '/tasks');
    return _expectList(
      data['data'],
    ).map((json) => HermesTask.fromJson(_expectMap(json))).toList();
  }

  Future<List<HermesTask>> listPastTasks() async {
    final data = await _sendJson('GET', '/tasks/past');
    return _expectList(
      data['data'],
    ).map((json) => HermesTask.fromJson(_expectMap(json))).toList();
  }

  Future<HermesTask> createTask({
    required String title,
    String type = 'todo',
    String status = 'open',
    String? dueAt,
    String? category,
    String? color,
    bool isCritical = false,
    Map<String, Object?>? metadata,
    int? workspaceId,
    List<Object> syncToWorkspaceIds = const [],
  }) async {
    final body = <String, Object?>{
      'title': title,
      'type': type,
      'status': status,
      'due_at': dueAt,
      'category': category,
      'color': color,
      'is_critical': isCritical,
      if (metadata != null) 'metadata': metadata,
      if (workspaceId != null) 'workspace_id': workspaceId,
      if (syncToWorkspaceIds.isNotEmpty)
        'sync_to_workspace_ids': syncToWorkspaceIds,
    };
    final data = await _sendJson('POST', '/tasks', body: body);
    return HermesTask.fromJson(_expectMap(data['data']));
  }

  Future<HermesTask> updateTask(
    int taskId, {
    String? title,
    String? status,
    String? dueAt,
    String? completedAt,
    String? category,
    String? color,
    bool? isCritical,
    Map<String, Object?>? metadata,
    List<Object> syncToWorkspaceIds = const [],
    bool clearCategory = false,
    bool clearColor = false,
  }) async {
    final body = <String, Object?>{
      if (title != null) 'title': title,
      if (status != null) 'status': status,
      'due_at': dueAt,
      if (completedAt != null || status == 'open') 'completed_at': completedAt,
      if (category != null || clearCategory) 'category': category,
      if (color != null || clearColor) 'color': color,
      if (isCritical != null) 'is_critical': isCritical,
      if (metadata != null) 'metadata': metadata,
      if (syncToWorkspaceIds.isNotEmpty)
        'sync_to_workspace_ids': syncToWorkspaceIds,
    };
    final data = await _sendJson('PATCH', '/tasks/$taskId', body: body);
    return HermesTask.fromJson(_expectMap(data['data']));
  }

  Future<void> deleteTask(int taskId) async {
    await _sendJson('DELETE', '/tasks/$taskId');
  }

  Future<HermesTask> completeTask(int taskId) async {
    final data = await _sendJson(
      'PATCH',
      '/tasks/$taskId',
      body: {'status': 'completed'},
    );
    return HermesTask.fromJson(_expectMap(data['data']));
  }

  Future<HermesTask> reopenTask(int taskId) async {
    final data = await _sendJson(
      'PATCH',
      '/tasks/$taskId',
      body: {'status': 'open', 'completed_at': null},
    );
    return HermesTask.fromJson(_expectMap(data['data']));
  }

  Future<List<HermesReminder>> listReminders() async {
    final data = await _sendJson('GET', '/reminders');
    return _expectList(
      data['data'],
    ).map((json) => HermesReminder.fromJson(_expectMap(json))).toList();
  }

  Future<HermesReminder> createReminder({
    required String title,
    required String remindAt,
    String status = 'pending',
    int? calendarEventId,
    String? category,
    String? color,
    bool isCritical = false,
    Map<String, Object?>? metadata,
    int? workspaceId,
    List<Object> syncToWorkspaceIds = const [],
  }) async {
    final body = <String, Object?>{
      'title': title,
      'remind_at': remindAt,
      'status': status,
      'category': category,
      'color': color,
      'is_critical': isCritical,
      if (calendarEventId != null) 'calendar_event_id': calendarEventId,
      if (metadata != null) 'metadata': metadata,
      if (workspaceId != null) 'workspace_id': workspaceId,
      if (syncToWorkspaceIds.isNotEmpty)
        'sync_to_workspace_ids': syncToWorkspaceIds,
    };
    final data = await _sendJson('POST', '/reminders', body: body);
    return HermesReminder.fromJson(_expectMap(data['data']));
  }

  Future<HermesReminder> updateReminder(
    int reminderId, {
    String? title,
    String? remindAt,
    String? status,
    int? calendarEventId,
    String? category,
    String? color,
    bool? isCritical,
    Map<String, Object?>? metadata,
    List<Object> syncToWorkspaceIds = const [],
    bool clearCategory = false,
    bool clearColor = false,
  }) async {
    final body = <String, Object?>{
      if (title != null) 'title': title,
      if (remindAt != null) 'remind_at': remindAt,
      if (status != null) 'status': status,
      if (calendarEventId != null) 'calendar_event_id': calendarEventId,
      if (category != null || clearCategory) 'category': category,
      if (color != null || clearColor) 'color': color,
      if (isCritical != null) 'is_critical': isCritical,
      if (metadata != null) 'metadata': metadata,
      if (syncToWorkspaceIds.isNotEmpty)
        'sync_to_workspace_ids': syncToWorkspaceIds,
    };
    final data = await _sendJson('PATCH', '/reminders/$reminderId', body: body);
    return HermesReminder.fromJson(_expectMap(data['data']));
  }

  Future<void> deleteReminder(int reminderId) async {
    await _sendJson('DELETE', '/reminders/$reminderId');
  }

  Future<List<HermesCalendarEvent>> listCalendarEvents() async {
    final data = await _sendJson('GET', '/calendar-events');
    return _expectList(
      data['data'],
    ).map((json) => HermesCalendarEvent.fromJson(_expectMap(json))).toList();
  }

  Future<HermesCalendarEvent> createCalendarEvent({
    required String title,
    required String startsAt,
    String? endsAt,
    String? category,
    String? color,
    bool? isCritical,
    String? recurrence,
    Map<String, Object?>? metadata,
    int? workspaceId,
    List<Object> syncToWorkspaceIds = const [],
  }) async {
    final data = await _sendJson(
      'POST',
      '/calendar-events',
      body: {
        'title': title,
        'starts_at': startsAt,
        'ends_at': endsAt,
        'category': category,
        'color': color,
        'recurrence': recurrence,
        if (isCritical != null) 'is_critical': isCritical,
        if (metadata != null) 'metadata': metadata,
        if (workspaceId != null) 'workspace_id': workspaceId,
        if (syncToWorkspaceIds.isNotEmpty)
          'sync_to_workspace_ids': syncToWorkspaceIds,
      },
    );
    return HermesCalendarEvent.fromJson(_expectMap(data['data']));
  }

  Future<GoogleCalendarSyncStatus> googleCalendarStatus() async {
    final data = await _sendJson('GET', '/google-calendar/status');
    return GoogleCalendarSyncStatus.fromJson(_expectMap(data['data']));
  }

  Future<String> googleCalendarAuthUrl() async {
    final data = await _sendJson('POST', '/google-calendar/auth-url');
    return _expectString(_expectMap(data['data'])['auth_url']);
  }

  Future<GoogleCalendarSyncResult> syncGoogleCalendar() async {
    final data = await _sendJson('POST', '/google-calendar/sync');
    return GoogleCalendarSyncResult.fromJson(_expectMap(data['data']));
  }

  Future<GoogleCalendarSyncStatus> updateGoogleCalendarSelection({
    required List<String> selectedCalendarIds,
    String? defaultCalendarId,
  }) async {
    final data = await _sendJson(
      'PATCH',
      '/google-calendar/calendars',
      body: {
        'selected_calendar_ids': selectedCalendarIds,
        if (defaultCalendarId != null) 'default_calendar_id': defaultCalendarId,
      },
    );
    return GoogleCalendarSyncStatus.fromJson(_expectMap(data['data']));
  }

  Future<GoogleCalendarSyncStatus> disconnectGoogleCalendar() async {
    final data = await _sendJson('DELETE', '/google-calendar');
    return GoogleCalendarSyncStatus.fromJson(_expectMap(data['data']));
  }

  Future<List<HermesEventCategory>> listEventCategories() async {
    final data = await _sendJson('GET', '/event-categories');
    return _expectList(
      data['data'],
    ).map((json) => HermesEventCategory.fromJson(_expectMap(json))).toList();
  }

  Future<HermesEventCategory> createEventCategory({
    required String name,
    required String color,
  }) async {
    final data = await _sendJson(
      'POST',
      '/event-categories',
      body: {'name': name, 'color': color},
    );
    return HermesEventCategory.fromJson(_expectMap(data['data']));
  }

  Future<HermesEventCategory> updateEventCategory(
    int categoryId, {
    required String name,
    required String color,
  }) async {
    final data = await _sendJson(
      'PATCH',
      '/event-categories/$categoryId',
      body: {'name': name, 'color': color},
    );
    return HermesEventCategory.fromJson(_expectMap(data['data']));
  }

  Future<void> deleteEventCategory(int categoryId) async {
    await _sendJson('DELETE', '/event-categories/$categoryId');
  }

  Future<HermesCalendarEvent> updateCalendarEvent(
    int eventId, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? category,
    String? color,
    bool? isCritical,
    String? recurrence,
    Map<String, Object?>? metadata,
    List<Object> syncToWorkspaceIds = const [],
  }) async {
    final data = await _sendJson(
      'PATCH',
      '/calendar-events/$eventId',
      body: {
        'title': title,
        'starts_at': startsAt,
        'ends_at': endsAt,
        'category': category,
        'color': color,
        'recurrence': recurrence,
        if (isCritical != null) 'is_critical': isCritical,
        if (metadata != null) 'metadata': metadata,
        if (syncToWorkspaceIds.isNotEmpty)
          'sync_to_workspace_ids': syncToWorkspaceIds,
      },
    );
    return HermesCalendarEvent.fromJson(_expectMap(data['data']));
  }

  Future<HermesReminder> createEventReminder({
    required int calendarEventId,
    required String title,
    required String remindAt,
    Map<String, Object?>? metadata,
    int? workspaceId,
    List<Object> syncToWorkspaceIds = const [],
  }) async {
    final body = <String, Object?>{
      'calendar_event_id': calendarEventId,
      'title': title,
      'remind_at': remindAt,
      if (workspaceId != null) 'workspace_id': workspaceId,
      if (syncToWorkspaceIds.isNotEmpty)
        'sync_to_workspace_ids': syncToWorkspaceIds,
    };
    if (metadata != null) body['metadata'] = metadata;
    final data = await _sendJson('POST', '/reminders', body: body);
    return HermesReminder.fromJson(_expectMap(data['data']));
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

  String _pathWithQuery(String path, Map<String, String> queryParameters) {
    if (queryParameters.isEmpty) return path;
    final uri = Uri(path: path, queryParameters: queryParameters);
    return uri.toString();
  }

  Uri _resolveApiPath(String path) {
    final basePath = baseUrl.path.endsWith('/')
        ? baseUrl.path.substring(0, baseUrl.path.length - 1)
        : baseUrl.path;
    final request = Uri.parse(path.startsWith('/') ? path : '/$path');
    return baseUrl.replace(
      path: '$basePath${request.path}',
      query: request.hasQuery ? request.query : null,
    );
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
  const HermesUser({
    required this.id,
    required this.name,
    required this.email,
    this.onboardComplete = false,
    this.agentProfile,
    this.defaultWorkspaceId,
    this.personalWorkspace,
    this.activeWorkspace,
    this.workspaces = const [],
    this.activeWorkspaceAgentProfile,
  });

  final int id;
  final String name;
  final String email;
  final bool onboardComplete;
  final HermesAgentProfile? agentProfile;
  final int? defaultWorkspaceId;
  final HermesWorkspace? personalWorkspace;
  final HermesWorkspace? activeWorkspace;
  final List<HermesWorkspace> workspaces;
  final HermesAgentProfile? activeWorkspaceAgentProfile;

  HermesUser copyWith({
    String? name,
    String? email,
    bool? onboardComplete,
    HermesAgentProfile? agentProfile,
    int? defaultWorkspaceId,
    HermesWorkspace? personalWorkspace,
    HermesWorkspace? activeWorkspace,
    List<HermesWorkspace>? workspaces,
    HermesAgentProfile? activeWorkspaceAgentProfile,
  }) => HermesUser(
    id: id,
    name: name ?? this.name,
    email: email ?? this.email,
    onboardComplete: onboardComplete ?? this.onboardComplete,
    agentProfile: agentProfile ?? this.agentProfile,
    defaultWorkspaceId: defaultWorkspaceId ?? this.defaultWorkspaceId,
    personalWorkspace: personalWorkspace ?? this.personalWorkspace,
    activeWorkspace: activeWorkspace ?? this.activeWorkspace,
    workspaces: workspaces ?? this.workspaces,
    activeWorkspaceAgentProfile:
        activeWorkspaceAgentProfile ?? this.activeWorkspaceAgentProfile,
  );

  factory HermesUser.fromJson(Map<String, Object?> json) => HermesUser(
    id: _expectInt(json['id']),
    name: _expectString(json['name']),
    email: _expectString(json['email']),
    onboardComplete: json['onboard_complete'] == true,
    agentProfile: json['agent_profile'] is Map<String, Object?>
        ? HermesAgentProfile.fromJson(_expectMap(json['agent_profile']))
        : null,
    defaultWorkspaceId: _readIntOrNull(json['default_workspace_id']),
    personalWorkspace: json['personal_workspace'] is Map<String, Object?>
        ? HermesWorkspace.fromJson(_expectMap(json['personal_workspace']))
        : null,
    activeWorkspace: json['active_workspace'] is Map<String, Object?>
        ? HermesWorkspace.fromJson(_expectMap(json['active_workspace']))
        : null,
    workspaces: _expectList(json['workspaces'] ?? const [])
        .map((workspace) => HermesWorkspace.fromJson(_expectMap(workspace)))
        .toList(),
    activeWorkspaceAgentProfile:
        json['active_workspace_agent_profile'] is Map<String, Object?>
        ? HermesAgentProfile.fromJson(
            _expectMap(json['active_workspace_agent_profile']),
          )
        : null,
  );
}

class HermesWorkspace {
  const HermesWorkspace({
    required this.id,
    required this.name,
    String? type,
    String? kind,
    this.slug,
    this.status = 'active',
    this.role = 'member',
    this.active = false,
    this.isDefault = false,
    this.googleCalendarId,
    this.personalOwnerUserId,
    this.createdByUserId,
    this.settings = const {},
    this.metadata = const {},
    this.memberships = const [],
    this.members = const [],
    this.agentProfile,
    this.googleCalendarMappings = const [],
  }) : type = type ?? kind ?? 'household';

  final String id;
  final String name;
  final String type;
  String get kind => type;
  final String? slug;
  final String status;
  final String role;
  final bool active;
  final bool isDefault;
  final String? googleCalendarId;
  final int? personalOwnerUserId;
  final int? createdByUserId;
  final Map<String, Object?> settings;
  final Map<String, Object?> metadata;
  final List<HermesWorkspaceMembership> memberships;
  final List<HermesWorkspaceMemberUser> members;
  final HermesAgentProfile? agentProfile;
  final List<Map<String, Object?>> googleCalendarMappings;

  int? get numericId => _readIntOrNull(id);
  bool get isPersonal => type == 'personal' || id == 'personal';
  bool get canManageMembers => role == 'owner';

  HermesWorkspace copyWith({
    String? name,
    String? role,
    bool? active,
    bool? isDefault,
    String? googleCalendarId,
    List<HermesWorkspaceMemberUser>? members,
  }) => HermesWorkspace(
    id: id,
    name: name ?? this.name,
    type: type,
    slug: slug,
    status: status,
    role: role ?? this.role,
    active: active ?? this.active,
    isDefault: isDefault ?? this.isDefault,
    googleCalendarId: googleCalendarId ?? this.googleCalendarId,
    personalOwnerUserId: personalOwnerUserId,
    createdByUserId: createdByUserId,
    settings: settings,
    metadata: metadata,
    memberships: memberships,
    members: members ?? this.members,
    agentProfile: agentProfile,
    googleCalendarMappings: googleCalendarMappings,
  );

  factory HermesWorkspace.fromJson(Map<String, Object?> json) {
    final rawId = json['id'] ?? json['workspace_id'] ?? 'personal';
    final parsedMemberships = _expectList(json['memberships'] ?? const [])
        .map(
          (membership) =>
              HermesWorkspaceMembership.fromJson(_expectMap(membership)),
        )
        .toList();
    final parsedMembers = _expectList(json['members'] ?? const [])
        .map((member) => HermesWorkspaceMemberUser.fromJson(_expectMap(member)))
        .toList();
    return HermesWorkspace(
      id: rawId.toString(),
      name: (json['name'] ?? 'Workspace').toString(),
      type: (json['type'] ?? json['kind'] ?? 'household').toString(),
      slug: json['slug']?.toString(),
      status: json['status']?.toString() ?? 'active',
      role: (json['role'] ?? json['membership_role'] ?? 'member').toString(),
      active: json['active'] == true || json['is_active'] == true,
      isDefault: json['default'] == true || json['is_default'] == true,
      googleCalendarId: (json['google_calendar_id'] ?? json['calendar_id'])
          ?.toString(),
      personalOwnerUserId: _readIntOrNull(json['personal_owner_user_id']),
      createdByUserId: _readIntOrNull(json['created_by_user_id']),
      settings: _expectMapOrNull(json['settings']) ?? const {},
      metadata: _expectMapOrNull(json['metadata']) ?? const {},
      memberships: parsedMemberships,
      members: parsedMembers.isNotEmpty
          ? parsedMembers
          : parsedMemberships
                .where((membership) => membership.user != null)
                .map((membership) => membership.user!)
                .toList(),
      agentProfile: json['agent_profile'] is Map<String, Object?>
          ? HermesAgentProfile.fromJson(_expectMap(json['agent_profile']))
          : null,
      googleCalendarMappings:
          _expectList(json['google_calendar_mappings'] ?? const [])
              .map((mapping) => Map<String, Object?>.from(_expectMap(mapping)))
              .toList(),
    );
  }
}

class HermesWorkspaceMembership {
  const HermesWorkspaceMembership({
    required this.id,
    required this.workspaceId,
    this.userId,
    this.role = 'member',
    this.status = 'active',
    this.invitedByUserId,
    this.invitedEmail,
    this.acceptedAt,
    this.metadata = const {},
    this.user,
    this.workspace,
  });

  final int id;
  final int workspaceId;
  final int? userId;
  final String role;
  final String status;
  final int? invitedByUserId;
  final String? invitedEmail;
  final String? acceptedAt;
  final Map<String, Object?> metadata;
  final HermesWorkspaceMemberUser? user;
  final HermesWorkspace? workspace;

  String? get invitationToken => metadata['invitation_token']?.toString();

  factory HermesWorkspaceMembership.fromJson(Map<String, Object?> json) =>
      HermesWorkspaceMembership(
        id: _expectInt(json['id']),
        workspaceId: _expectInt(json['workspace_id']),
        userId: _readIntOrNull(json['user_id']),
        role: json['role']?.toString() ?? 'member',
        status: json['status']?.toString() ?? 'active',
        invitedByUserId: _readIntOrNull(json['invited_by_user_id']),
        invitedEmail: json['invited_email']?.toString(),
        acceptedAt: json['accepted_at']?.toString(),
        metadata: _expectMapOrNull(json['metadata']) ?? const {},
        user: json['user'] is Map<String, Object?>
            ? HermesWorkspaceMemberUser.fromJson(_expectMap(json['user']))
            : null,
        workspace: json['workspace'] is Map<String, Object?>
            ? HermesWorkspace.fromJson(_expectMap(json['workspace']))
            : null,
      );
}

class HermesWorkspaceMemberUser {
  const HermesWorkspaceMemberUser({
    required this.id,
    required this.name,
    required this.email,
  });

  final int id;
  final String name;
  final String email;

  factory HermesWorkspaceMemberUser.fromJson(Map<String, Object?> json) =>
      HermesWorkspaceMemberUser(
        id: _expectInt(json['id']),
        name: _expectString(json['name']),
        email: _expectString(json['email']),
      );
}

class WorkspaceSyncResult {
  const WorkspaceSyncResult({
    this.tasks = 0,
    this.reminders = 0,
    this.calendarEvents = 0,
  });

  final int tasks;
  final int reminders;
  final int calendarEvents;

  factory WorkspaceSyncResult.fromJson(Map<String, Object?> json) =>
      WorkspaceSyncResult(
        tasks: _readIntOrNull(json['tasks']) ?? 0,
        reminders: _readIntOrNull(json['reminders']) ?? 0,
        calendarEvents: _readIntOrNull(json['calendar_events']) ?? 0,
      );
}

class HermesAgentProfile {
  const HermesAgentProfile({this.id, this.settings = const {}});

  final int? id;
  final Map<String, Object?> settings;

  bool get onboardingCompleted {
    final onboarding = settings['onboarding'];
    return onboarding is Map && onboarding['completed'] == true;
  }

  String get personalityType =>
      settings['personality_type']?.toString() ?? 'balanced';

  List<String> get onboardingPriorities {
    final onboarding = settings['onboarding'];
    if (onboarding is! Map) return const [];
    final priorities = onboarding['priorities'];
    if (priorities is! List) return const [];
    return priorities.map((priority) => priority.toString()).toList();
  }

  String get onboardingContext {
    final onboarding = settings['onboarding'];
    if (onboarding is! Map) return '';
    return onboarding['context']?.toString() ?? '';
  }

  factory HermesAgentProfile.fromJson(Map<String, Object?> json) =>
      HermesAgentProfile(
        id: json['id'] == null ? null : _expectInt(json['id']),
        settings: json['settings'] is Map<String, Object?>
            ? Map<String, Object?>.from(_expectMap(json['settings']))
            : const {},
      );
}

class GoogleCalendarSyncStatus {
  const GoogleCalendarSyncStatus({
    required this.connected,
    required this.status,
    this.email,
    this.calendarId,
    this.defaultCalendarId,
    this.selectedCalendarIds = const [],
    this.calendars = const [],
    this.lastSyncedAt,
    this.lastError,
  });

  final bool connected;
  final String status;
  final String? email;
  final String? calendarId;
  final String? defaultCalendarId;
  final List<String> selectedCalendarIds;
  final List<GoogleCalendarInfo> calendars;
  final String? lastSyncedAt;
  final String? lastError;

  List<GoogleCalendarInfo> get writableCalendars =>
      calendars.where((calendar) => calendar.canWrite).toList(growable: false);

  factory GoogleCalendarSyncStatus.fromJson(Map<String, Object?> json) =>
      GoogleCalendarSyncStatus(
        connected: json['connected'] == true,
        status: _expectString(json['status']),
        email: json['email']?.toString(),
        calendarId: json['calendar_id']?.toString(),
        defaultCalendarId: (json['default_calendar_id'] ?? json['calendar_id'])
            ?.toString(),
        selectedCalendarIds: _expectList(
          json['selected_calendar_ids'] ?? const [],
        ).map((item) => item.toString()).toList(),
        calendars: _expectList(
          json['calendars'] ?? const [],
        ).map((item) => GoogleCalendarInfo.fromJson(_expectMap(item))).toList(),
        lastSyncedAt: json['last_synced_at']?.toString(),
        lastError: json['last_error']?.toString(),
      );
}

class GoogleCalendarInfo {
  const GoogleCalendarInfo({
    required this.id,
    required this.summary,
    this.description,
    this.primary = false,
    this.selected = false,
    this.accessRole = 'reader',
    this.color = '#4285F4',
  });

  final String id;
  final String summary;
  final String? description;
  final bool primary;
  final bool selected;
  final String accessRole;
  final String color;

  bool get canWrite => accessRole == 'owner' || accessRole == 'writer';

  factory GoogleCalendarInfo.fromJson(Map<String, Object?> json) =>
      GoogleCalendarInfo(
        id: _expectString(json['id']),
        summary: (json['summary'] ?? json['name'] ?? json['id']).toString(),
        description: json['description']?.toString(),
        primary: json['primary'] == true,
        selected: json['selected'] == true,
        accessRole: (json['access_role'] ?? json['accessRole'] ?? 'reader')
            .toString(),
        color: (json['color'] ?? json['backgroundColor'] ?? '#4285F4')
            .toString(),
      );
}

class GoogleCalendarSyncResult {
  const GoogleCalendarSyncResult({
    required this.imported,
    required this.deleted,
    required this.status,
  });

  final int imported;
  final int deleted;
  final GoogleCalendarSyncStatus status;

  factory GoogleCalendarSyncResult.fromJson(Map<String, Object?> json) =>
      GoogleCalendarSyncResult(
        imported: _expectInt(json['imported']),
        deleted: _expectInt(json['deleted']),
        status: GoogleCalendarSyncStatus.fromJson(_expectMap(json['status'])),
      );
}

class HermesTodaySummary {
  const HermesTodaySummary({
    this.user,
    this.agentProfile,
    this.workspace,
    this.workspaces = const [],
    this.session,
    required this.tasks,
    required this.reminders,
    required this.calendarEvents,
    required this.activityEvents,
    required this.approvals,
    required this.blockers,
  });

  final HermesUser? user;
  final HermesAgentProfile? agentProfile;
  final HermesWorkspace? workspace;
  final List<HermesWorkspace> workspaces;
  final HermesSession? session;
  final List<HermesTask> tasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendarEvents;
  final List<HermesActivityEvent> activityEvents;
  final List<HermesApproval> approvals;
  final List<HermesBlocker> blockers;

  factory HermesTodaySummary.fromJson(Map<String, Object?> json) =>
      HermesTodaySummary(
        user: json['user'] == null
            ? null
            : HermesUser.fromJson(_expectMap(json['user'])),
        agentProfile: json['agent_profile'] == null
            ? null
            : HermesAgentProfile.fromJson(_expectMap(json['agent_profile'])),
        workspace: json['workspace'] == null
            ? null
            : HermesWorkspace.fromJson(_expectMap(json['workspace'])),
        workspaces: _expectList(json['workspaces'] ?? const [])
            .map((workspace) => HermesWorkspace.fromJson(_expectMap(workspace)))
            .toList(),
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
  const HermesTask({
    required this.id,
    required this.title,
    this.status,
    this.dueAt,
    this.category,
    this.color,
    this.isCritical = false,
    this.completedAt,
    this.metadata,
  });

  final int id;
  final String title;
  final String? status;
  final String? dueAt;
  final String? category;
  final String? color;
  final bool isCritical;
  final String? completedAt;
  final Map<String, Object?>? metadata;

  factory HermesTask.fromJson(Map<String, Object?> json) {
    final metadata = _expectMapOrNull(json['metadata']);
    return HermesTask(
      id: _expectInt(json['id']),
      title: _readTitle(json),
      status: json['status'] as String?,
      dueAt: (json['due_at'] ?? json['dueAt']) as String?,
      category:
          json['category'] as String? ?? (metadata?['category'] as String?),
      color: json['color'] as String? ?? (metadata?['color'] as String?),
      isCritical: _readBool(
        json['is_critical'] ??
            json['isCritical'] ??
            metadata?['is_critical'] ??
            metadata?['isCritical'],
      ),
      completedAt: (json['completed_at'] ?? json['completedAt']) as String?,
      metadata: metadata,
    );
  }

  HermesTask copyWith({
    String? title,
    String? status,
    String? dueAt,
    String? category,
    String? color,
    bool? isCritical,
    String? completedAt,
    Map<String, Object?>? metadata,
    bool clearDueAt = false,
    bool clearCategory = false,
    bool clearColor = false,
    bool clearCompletedAt = false,
  }) => HermesTask(
    id: id,
    title: title ?? this.title,
    status: status ?? this.status,
    dueAt: clearDueAt ? null : dueAt ?? this.dueAt,
    category: clearCategory ? null : category ?? this.category,
    color: clearColor ? null : color ?? this.color,
    isCritical: isCritical ?? this.isCritical,
    completedAt: clearCompletedAt ? null : completedAt ?? this.completedAt,
    metadata: metadata ?? this.metadata,
  );
}

class HermesReminder {
  const HermesReminder({
    required this.id,
    required this.title,
    this.dueAt,
    this.category,
    this.color,
    this.isCritical = false,
    this.status,
    this.completedAt,
    this.calendarEventId,
    this.metadata,
  });

  final int id;
  final String title;
  final String? dueAt;
  final String? category;
  final String? color;
  final bool isCritical;
  final String? status;
  final String? completedAt;
  final int? calendarEventId;
  final Map<String, Object?>? metadata;

  factory HermesReminder.fromJson(Map<String, Object?> json) {
    final metadata = _expectMapOrNull(json['metadata']);
    return HermesReminder(
      id: _expectInt(json['id']),
      title: _readTitle(json),
      dueAt: (json['due_at'] ?? json['remind_at'] ?? json['dueAt']) as String?,
      category:
          json['category'] as String? ?? (metadata?['category'] as String?),
      color: json['color'] as String? ?? (metadata?['color'] as String?),
      isCritical: _readBool(
        json['is_critical'] ??
            json['isCritical'] ??
            metadata?['is_critical'] ??
            metadata?['isCritical'],
      ),
      status: json['status'] as String?,
      completedAt: (json['completed_at'] ?? json['completedAt']) as String?,
      calendarEventId: json['calendar_event_id'] == null
          ? null
          : _expectInt(json['calendar_event_id']),
      metadata: metadata,
    );
  }

  HermesReminder copyWith({
    String? title,
    String? dueAt,
    String? category,
    String? color,
    bool? isCritical,
    String? status,
    String? completedAt,
    int? calendarEventId,
    Map<String, Object?>? metadata,
    bool clearDueAt = false,
    bool clearCategory = false,
    bool clearColor = false,
  }) => HermesReminder(
    id: id,
    title: title ?? this.title,
    dueAt: clearDueAt ? null : dueAt ?? this.dueAt,
    category: clearCategory ? null : category ?? this.category,
    color: clearColor ? null : color ?? this.color,
    isCritical: isCritical ?? this.isCritical,
    status: status ?? this.status,
    completedAt: completedAt ?? this.completedAt,
    calendarEventId: calendarEventId ?? this.calendarEventId,
    metadata: metadata ?? this.metadata,
  );
}

class HermesEventCategory {
  const HermesEventCategory({
    required this.id,
    required this.name,
    required this.color,
  });

  final int id;
  final String name;
  final String color;

  factory HermesEventCategory.fromJson(Map<String, Object?> json) =>
      HermesEventCategory(
        id: _expectInt(json['id']),
        name: _expectString(json['name']),
        color: (json['color'] as String?) ?? '#34C759',
      );

  HermesEventCategory copyWith({String? name, String? color}) =>
      HermesEventCategory(
        id: id,
        name: name ?? this.name,
        color: color ?? this.color,
      );
}

class HermesCalendarEvent {
  const HermesCalendarEvent({
    required this.id,
    required this.title,
    this.startsAt,
    this.endsAt,
    this.category,
    this.color,
    this.isCritical = false,
    this.recurrence,
    this.metadata,
  });

  final int id;
  final String title;
  final String? startsAt;
  final String? endsAt;
  final String? category;
  final String? color;
  final bool isCritical;
  final String? recurrence;
  final Map<String, Object?>? metadata;

  String? get googleCalendarId =>
      (metadata?['google_calendar_id'] ?? metadata?['googleCalendarId'])
          ?.toString();

  factory HermesCalendarEvent.fromJson(Map<String, Object?> json) {
    final parsedMetadata = _expectMapOrNull(json['metadata']);
    final googleCalendarId = json['google_calendar_id']?.toString();
    final metadata = googleCalendarId == null
        ? parsedMetadata
        : <String, Object?>{
            ...?parsedMetadata,
            'google_calendar_id': googleCalendarId,
          };
    return HermesCalendarEvent(
      id: _expectInt(json['id']),
      title: _readTitle(json),
      startsAt: (json['starts_at'] ?? json['startsAt']) as String?,
      endsAt: (json['ends_at'] ?? json['endsAt']) as String?,
      category:
          json['category'] as String? ?? (metadata?['category'] as String?),
      color: json['color'] as String? ?? (metadata?['color'] as String?),
      isCritical: _readBool(
        json['is_critical'] ??
            json['isCritical'] ??
            metadata?['is_critical'] ??
            metadata?['isCritical'],
      ),
      recurrence:
          json['recurrence'] as String? ?? (metadata?['recurrence'] as String?),
      metadata: metadata,
    );
  }

  HermesCalendarEvent copyWith({
    String? title,
    String? startsAt,
    String? endsAt,
    String? category,
    String? color,
    bool? isCritical,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool clearEndsAt = false,
    bool clearCategory = false,
    bool clearColor = false,
    bool clearRecurrence = false,
    bool clearMetadata = false,
  }) => HermesCalendarEvent(
    id: id,
    title: title ?? this.title,
    startsAt: startsAt ?? this.startsAt,
    endsAt: clearEndsAt ? null : endsAt ?? this.endsAt,
    category: clearCategory ? null : category ?? this.category,
    color: clearColor ? null : color ?? this.color,
    isCritical: isCritical ?? this.isCritical,
    recurrence: clearRecurrence ? null : recurrence ?? this.recurrence,
    metadata: clearMetadata ? null : metadata ?? this.metadata,
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

Map<String, Object?>? _expectMapOrNull(Object? value) {
  if (value == null) return null;
  return _expectMap(value);
}

List<Object?> _expectList(Object? value) {
  if (value is List<Object?>) return value;
  throw FormatException('Expected JSON array, got ${value.runtimeType}');
}

int _expectInt(Object? value) {
  if (value is int) return value;
  throw FormatException('Expected integer, got ${value.runtimeType}');
}

int? _readIntOrNull(Object? value) {
  if (value == null) return null;
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  throw FormatException('Expected integer, got ${value.runtimeType}');
}

String _expectString(Object? value) {
  if (value is String) return value;
  throw FormatException('Expected string, got ${value.runtimeType}');
}

bool _readBool(Object? value) {
  if (value is bool) return value;
  if (value is num) return value != 0;
  if (value is String) {
    final normalized = value.toLowerCase();
    return normalized == 'true' || normalized == '1' || normalized == 'yes';
  }
  return false;
}
