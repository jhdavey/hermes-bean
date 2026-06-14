import 'dart:async';
import 'dart:convert';
import 'dart:io';

const String hermesApiBaseUrl = String.fromEnvironment(
  'HERMES_API_BASE_URL',
  defaultValue: 'https://heybean.org/api',
);

Uri normalizeHermesApiBaseUrlForPlatform(Uri baseUrl, {bool? isAndroid}) {
  final runningOnAndroid = isAndroid ?? Platform.isAndroid;
  if (!runningOnAndroid) return baseUrl;

  final host = baseUrl.host.toLowerCase();
  if (host != 'localhost' && host != '127.0.0.1') return baseUrl;

  return baseUrl.replace(host: '10.0.2.2');
}

const Duration _standardApiResponseTimeout = Duration(seconds: 30);
const Duration _assistantApiResponseTimeout = Duration(seconds: 120);

typedef HermesApiTransport =
    Future<HermesApiResponse> Function(HermesApiRequest request);

class HermesApiClient {
  HermesApiClient({
    Uri? baseUrl,
    HermesApiTransport? transport,
    this.bearerToken,
  }) : baseUrl = _validateBaseUrl(
         normalizeHermesApiBaseUrlForPlatform(
           baseUrl ?? Uri.parse(hermesApiBaseUrl),
         ),
       ),
       _transport = transport ?? _defaultTransport;

  static Uri _validateBaseUrl(Uri baseUrl) {
    if (const bool.fromEnvironment('dart.vm.product') &&
        baseUrl.scheme != 'https') {
      throw StateError(
        'Hermes Bean production builds require an HTTPS API base URL.',
      );
    }
    return baseUrl;
  }

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

  Future<void> requestPasswordReset({required String email}) async {
    await _sendJson(
      'POST',
      '/auth/forgot-password',
      body: {'email': email},
      authenticated: false,
    );
  }

  Future<HermesCheckoutSession> createCheckoutSession({
    required String plan,
    String source = 'flutter',
  }) async {
    final data = await _sendJson(
      'POST',
      '/billing/checkout-sessions',
      body: {'plan': plan, 'source': source},
    );
    return HermesCheckoutSession.fromJson(_expectMap(data['data']));
  }

  Future<HermesPaymentSheetSetup> createMobileSubscriptionSetup({
    required String plan,
  }) async {
    final data = await _sendJson(
      'POST',
      '/billing/mobile-subscriptions/setup',
      body: {'plan': plan},
    );
    return HermesPaymentSheetSetup.fromJson(_expectMap(data['data']));
  }

  Future<HermesSubscriptionResult> confirmMobileSubscription({
    required String plan,
    required String setupIntentId,
  }) async {
    final data = await _sendJson(
      'POST',
      '/billing/mobile-subscriptions/confirm',
      body: {'plan': plan, 'setup_intent_id': setupIntentId},
    );
    return HermesSubscriptionResult.fromJson(_expectMap(data['data']));
  }

  Future<HermesBillingPaymentMethod?> getBillingPaymentMethod() async {
    final data = await _sendJson('GET', '/billing/payment-method');
    final method = _expectMap(data['data'])['payment_method'];
    return method is Map<String, Object?>
        ? HermesBillingPaymentMethod.fromJson(_expectMap(method))
        : null;
  }

  Future<HermesPaymentSheetSetup> createPaymentMethodSetup() async {
    final data = await _sendJson('POST', '/billing/payment-method/setup');
    return HermesPaymentSheetSetup.fromJson(_expectMap(data['data']));
  }

  Future<HermesBillingPaymentMethod?> confirmPaymentMethodSetup({
    required String setupIntentId,
  }) async {
    final data = await _sendJson(
      'POST',
      '/billing/payment-method/confirm',
      body: {'setup_intent_id': setupIntentId},
    );
    final method = _expectMap(data['data'])['payment_method'];
    return method is Map<String, Object?>
        ? HermesBillingPaymentMethod.fromJson(_expectMap(method))
        : null;
  }

  Future<HermesSubscriptionSummary> cancelSubscription() async {
    final data = await _sendJson('POST', '/billing/subscription/cancel');
    return HermesSubscriptionSummary.fromJson(
      _expectMap(_expectMap(data['data'])['subscription']),
    );
  }

  Future<void> logout({bool clearBearerToken = true}) async {
    await _sendJson('POST', '/auth/logout');
    if (clearBearerToken) bearerToken = null;
  }

  Future<void> registerPushNotificationToken({
    required String token,
    String? platform,
    String? deviceId,
    String? appVersion,
  }) async {
    await _sendJson(
      'POST',
      '/push-notification-tokens',
      body: {
        'token': token,
        if (platform != null) 'platform': platform,
        if (deviceId != null) 'device_id': deviceId,
        if (appVersion != null) 'app_version': appVersion,
      },
    );
  }

  Future<void> unregisterPushNotificationToken(String token) async {
    await _sendJson(
      'DELETE',
      '/push-notification-tokens',
      body: {'token': token},
    );
  }

  Future<HermesUser> me() async {
    final data = await _sendJson('GET', '/auth/me');
    return HermesUser.fromJson(_expectMap(data['data']));
  }

  Future<HermesUser> updateMe({
    String? name,
    String? email,
    String? theme,
    String? agentPersonality,
    List<String>? onboardingPriorities,
    String? onboardingContext,
    HermesNotificationPreferences? notificationPreferences,
  }) async {
    final data = await _sendJson(
      'PATCH',
      '/auth/me',
      body: {
        if (name != null) 'name': name,
        if (email != null) 'email': email,
        if (theme != null) 'theme': theme,
        if (agentPersonality != null) 'agent_personality': agentPersonality,
        if (onboardingPriorities != null)
          'onboarding_priorities': onboardingPriorities,
        if (onboardingContext != null) 'onboarding_context': onboardingContext,
        if (notificationPreferences != null)
          'notification_preferences': notificationPreferences.toJson(),
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

  Future<HermesDashboardChangeFeed> dashboardChanges({
    required int after,
    int waitSeconds = 0,
    int limit = 100,
  }) async {
    final data = await _sendJson(
      'GET',
      _pathWithQuery('/dashboard-changes', {
        'after': after.toString(),
        'wait': waitSeconds.toString(),
        'limit': limit.toString(),
      }),
      responseTimeout: Duration(seconds: waitSeconds + 10),
    );
    return HermesDashboardChangeFeed.fromJson(_expectMap(data['data']));
  }

  Future<void> submitIssueReport({
    required String message,
    int? workspaceId,
    String? pageUrl,
  }) async {
    await _sendJson(
      'POST',
      '/issue-reports',
      body: {
        'message': message,
        if (workspaceId != null) 'workspace_id': workspaceId,
        if (pageUrl != null) 'page_url': pageUrl,
      },
    );
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
    String? notes,
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
      if (notes != null) 'notes': notes,
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
    String? notes,
    String? completedAt,
    String? category,
    String? color,
    bool? isCritical,
    Map<String, Object?>? metadata,
    List<Object>? syncToWorkspaceIds,
    bool clearCategory = false,
    bool clearColor = false,
    bool clearNotes = false,
  }) async {
    final body = <String, Object?>{
      if (title != null) 'title': title,
      if (status != null) 'status': status,
      'due_at': dueAt,
      if (notes != null || clearNotes) 'notes': notes,
      if (completedAt != null || status == 'open') 'completed_at': completedAt,
      if (category != null || clearCategory) 'category': category,
      if (color != null || clearColor) 'color': color,
      if (isCritical != null) 'is_critical': isCritical,
      if (metadata != null) 'metadata': metadata,
      if (syncToWorkspaceIds != null)
        'sync_to_workspace_ids': syncToWorkspaceIds,
    };
    final data = await _sendJson('PATCH', '/tasks/$taskId', body: body);
    return HermesTask.fromJson(_expectMap(data['data']));
  }

  Future<void> deleteTask(
    int taskId, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    await _sendJson(
      'DELETE',
      '/tasks/$taskId',
      body: {
        if (deleteFromWorkspaceIds.isNotEmpty)
          'delete_from_workspace_ids': deleteFromWorkspaceIds,
      },
    );
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
    List<Object>? syncToWorkspaceIds,
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
      if (syncToWorkspaceIds != null)
        'sync_to_workspace_ids': syncToWorkspaceIds,
    };
    final data = await _sendJson('PATCH', '/reminders/$reminderId', body: body);
    return HermesReminder.fromJson(_expectMap(data['data']));
  }

  Future<void> deleteReminder(
    int reminderId, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    await _sendJson(
      'DELETE',
      '/reminders/$reminderId',
      body: {
        if (deleteFromWorkspaceIds.isNotEmpty)
          'delete_from_workspace_ids': deleteFromWorkspaceIds,
      },
    );
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
    String? notes,
    String? location,
    String? status,
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
        'description': notes,
        if (location != null) 'location': location,
        if (status != null) 'status': status,
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

  Future<void> deleteEventCategory(
    int categoryId, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    await _sendJson(
      'DELETE',
      '/event-categories/$categoryId',
      body: {
        if (deleteFromWorkspaceIds.isNotEmpty)
          'delete_from_workspace_ids': deleteFromWorkspaceIds,
      },
    );
  }

  Future<void> deleteCalendarEvent(
    int eventId, {
    List<Object> deleteFromWorkspaceIds = const [],
    String? recurringDeleteMode,
    String? recurringOccurrenceDate,
  }) async {
    await _sendJson(
      'DELETE',
      '/calendar-events/$eventId',
      body: {
        if (deleteFromWorkspaceIds.isNotEmpty)
          'delete_from_workspace_ids': deleteFromWorkspaceIds,
        if (recurringDeleteMode != null)
          'recurring_delete_mode': recurringDeleteMode,
        if (recurringOccurrenceDate != null)
          'recurring_occurrence_date': recurringOccurrenceDate,
      },
    );
  }

  Future<HermesCalendarEvent> updateCalendarEvent(
    int eventId, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    bool? isCritical,
    String? recurrence,
    Map<String, Object?>? metadata,
    List<Object>? syncToWorkspaceIds,
    bool clearNotes = false,
    bool clearLocation = false,
  }) async {
    final data = await _sendJson(
      'PATCH',
      '/calendar-events/$eventId',
      body: {
        'title': title,
        'starts_at': startsAt,
        'ends_at': endsAt,
        if (notes != null || clearNotes) 'description': notes,
        if (location != null || clearLocation) 'location': location,
        if (status != null) 'status': status,
        'category': category,
        'color': color,
        'recurrence': recurrence,
        if (isCritical != null) 'is_critical': isCritical,
        if (metadata != null) 'metadata': metadata,
        if (syncToWorkspaceIds != null)
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
    int? workspaceId,
    Map<String, Object?>? metadata,
  }) async {
    final body = <String, Object?>{};
    if (title != null) body['title'] = title;
    if (runtimeMode != null) body['runtime_mode'] = runtimeMode;
    if (workspaceId != null) body['workspace_id'] = workspaceId;
    if (metadata != null) body['metadata'] = metadata;

    final data = await _sendJson('POST', '/assistant/sessions', body: body);
    return HermesSession.fromJson(_expectMap(data['data']));
  }

  Future<HermesSessionList> listConversationSessions({
    String? date,
    String? timezone,
    int? workspaceId,
    int limit = 30,
  }) async {
    final data = await _sendJson(
      'GET',
      _pathWithQuery('/assistant/sessions', {
        if (date != null) 'date': date,
        if (timezone != null) 'timezone': timezone,
        if (workspaceId != null) 'workspace_id': workspaceId.toString(),
        'limit': limit.toString(),
      }),
    );
    return HermesSessionList.fromJson(_expectMap(data['data']));
  }

  Future<HermesSession> resumeSession(int sessionId) async {
    final data = await _sendJson('GET', '/assistant/sessions/$sessionId');
    return HermesSession.fromJson(_expectMap(data['data']));
  }

  Future<HermesSessionDetails> resumeSessionDetails(int sessionId) async {
    final data = await _sendJson('GET', '/assistant/sessions/$sessionId');
    return HermesSessionDetails.fromJson(_expectMap(data['data']));
  }

  Future<HermesSession> cancelSession(int sessionId) async {
    final data = await _sendJson(
      'POST',
      '/assistant/sessions/$sessionId/cancel',
    );
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
      responseTimeout: _assistantApiResponseTimeout,
    );
    return HermesMessageResult.fromJson(_expectMap(data['data']));
  }

  Future<HermesMessageResult> queueMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
    String source = 'flutter',
  }) async {
    final body = <String, Object?>{'content': content, 'source': source};
    if (metadata != null) body['metadata'] = metadata;

    final data = await _sendJson(
      'POST',
      '/assistant/sessions/$sessionId/runs',
      body: body,
    );
    return HermesMessageResult.fromJson(_expectMap(data['data']));
  }

  Future<HermesRealtimeSession> startRealtimeSession({
    String? title,
    String? runtimeMode,
    int? sessionId,
    int? workspaceId,
    Map<String, Object?>? metadata,
    String? voice,
  }) async {
    final body = <String, Object?>{};
    if (title != null) body['title'] = title;
    if (runtimeMode != null) body['runtime_mode'] = runtimeMode;
    if (sessionId != null) body['session_id'] = sessionId;
    if (workspaceId != null) body['workspace_id'] = workspaceId;
    if (metadata != null) body['metadata'] = metadata;
    if (voice != null) body['voice'] = voice;

    final data = await _sendJson(
      'POST',
      '/assistant/realtime/sessions',
      body: body,
    );
    return HermesRealtimeSession.fromJson(_expectMap(data['data']));
  }

  Future<String> createRealtimeCall({
    required int sessionId,
    required String sdp,
    String? voice,
    Map<String, Object?>? metadata,
  }) async {
    final headers = <String, String>{
      'Accept': 'application/sdp',
      'Content-Type': 'application/json',
    };
    if (bearerToken != null) {
      headers['Authorization'] = 'Bearer $bearerToken';
    }
    final response = await _transport(
      HermesApiRequest(
        method: 'POST',
        uri: _resolveApiPath('/assistant/realtime/calls'),
        path: '/assistant/realtime/calls',
        headers: headers,
        body: {
          'session_id': sessionId,
          'sdp': sdp,
          if (voice != null) 'voice': voice,
          if (metadata != null) 'metadata': metadata,
        },
        responseTimeout: const Duration(seconds: 25),
      ),
    );
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw HermesApiException(response.statusCode, response.body);
    }
    return response.body;
  }

  Future<Map<String, Object?>> realtimeDashboardContext({
    int? sessionId,
    int? workspaceId,
  }) async {
    final data = await _sendJson(
      'GET',
      _pathWithQuery('/assistant/realtime/dashboard-context', {
        if (sessionId != null) 'session_id': sessionId.toString(),
        if (workspaceId != null) 'workspace_id': workspaceId.toString(),
      }),
    );
    return _expectMap(data['data']);
  }

  Future<Map<String, Object?>> submitRealtimeToolCall({
    required int sessionId,
    required String toolName,
    String? callId,
    Map<String, Object?> arguments = const {},
  }) async {
    final data = await _sendJson(
      'POST',
      '/assistant/realtime/tool-calls',
      body: {
        'session_id': sessionId,
        'tool_name': toolName,
        if (callId != null) 'call_id': callId,
        'arguments': arguments,
      },
    );
    return _expectMap(data['data']);
  }

  Future<HermesMessage> persistRealtimeMessage({
    required int sessionId,
    required String role,
    required String content,
    Map<String, Object?>? metadata,
  }) async {
    final data = await _sendJson(
      'POST',
      '/assistant/realtime/messages',
      body: {
        'session_id': sessionId,
        'role': role,
        'content': content,
        if (metadata != null) 'metadata': metadata,
      },
    );
    return HermesMessage.fromJson(_expectMap(data['data']));
  }

  Future<void> logRealtimeClientEvent({
    required String eventType,
    int? sessionId,
    String? phase,
    String? message,
    Map<String, Object?> details = const {},
  }) async {
    await _sendJson(
      'POST',
      '/assistant/realtime/client-events',
      body: {
        'event_type': eventType,
        if (sessionId != null) 'session_id': sessionId,
        if (phase != null) 'phase': phase,
        if (message != null) 'message': message,
        if (details.isNotEmpty) 'details': details,
      },
    );
  }

  Future<HermesAssistantRun> getAssistantRun(int runId) async {
    final data = await _sendJson('GET', '/assistant/runs/$runId');
    return HermesAssistantRun.fromJson(_expectMap(data['data']));
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

  Future<HermesApprovalResult> approveApproval(
    int approvalId, {
    bool alwaysApprove = false,
  }) async {
    final data = await _sendJson(
      'POST',
      '/approvals/$approvalId/approve',
      body: {if (alwaysApprove) 'always_approve': true},
    );
    return HermesApprovalResult.fromJson(_expectMap(data['data']));
  }

  Future<HermesApprovalResult> denyApproval(int approvalId) async {
    final data = await _sendJson('POST', '/approvals/$approvalId/deny');
    return HermesApprovalResult.fromJson(_expectMap(data['data']));
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
    Duration responseTimeout = _standardApiResponseTimeout,
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
      responseTimeout: responseTimeout,
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
    final client = HttpClient()
      ..connectionTimeout = const Duration(seconds: 15);
    try {
      final ioRequest = await client
          .openUrl(request.method, request.uri)
          .timeout(const Duration(seconds: 15));
      request.headers.forEach(ioRequest.headers.set);
      if (request.body != null) ioRequest.write(jsonEncode(request.body));

      final ioResponse = await ioRequest.close().timeout(
        request.responseTimeout,
      );
      final responseBody = await utf8.decoder
          .bind(ioResponse)
          .join()
          .timeout(request.responseTimeout);
      return HermesApiResponse(ioResponse.statusCode, responseBody);
    } on TimeoutException catch (error) {
      throw HermesApiException(
        408,
        "Request timed out: ${error.message ?? 'network timeout'}",
      );
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
    this.responseTimeout = _standardApiResponseTimeout,
  });

  final String method;
  final Uri uri;
  final String path;
  final Map<String, String> headers;
  final Map<String, Object?>? body;
  final Duration responseTimeout;
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
  String toString() => 'HermesApiException(statusCode: $statusCode)';
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

class HermesCheckoutSession {
  const HermesCheckoutSession({
    required this.id,
    required this.url,
    required this.plan,
    this.status,
  });

  final String id;
  final String url;
  final String plan;
  final String? status;

  factory HermesCheckoutSession.fromJson(Map<String, Object?> json) =>
      HermesCheckoutSession(
        id: _expectString(json['id']),
        url: _expectString(json['url']),
        plan: _expectString(json['plan']),
        status: json['status']?.toString(),
      );
}

class HermesPaymentSheetSetup {
  const HermesPaymentSheetSetup({
    required this.publishableKey,
    required this.customerId,
    required this.customerEphemeralKeySecret,
    required this.setupIntentId,
    required this.setupIntentClientSecret,
    this.plan,
  });

  final String publishableKey;
  final String customerId;
  final String customerEphemeralKeySecret;
  final String setupIntentId;
  final String setupIntentClientSecret;
  final String? plan;

  factory HermesPaymentSheetSetup.fromJson(Map<String, Object?> json) =>
      HermesPaymentSheetSetup(
        publishableKey: _expectString(json['publishable_key']),
        customerId: _expectString(json['customer_id']),
        customerEphemeralKeySecret: _expectString(
          json['customer_ephemeral_key_secret'],
        ),
        setupIntentId: _expectString(json['setup_intent_id']),
        setupIntentClientSecret: _expectString(
          json['setup_intent_client_secret'],
        ),
        plan: json['plan']?.toString(),
      );
}

class HermesSubscriptionSummary {
  const HermesSubscriptionSummary({
    required this.tier,
    this.status,
    this.currentPeriodEnd,
    this.trialEndsAt,
    this.cancelAtPeriodEnd = false,
    this.canUpgrade = false,
  });

  final String tier;
  final String? status;
  final String? currentPeriodEnd;
  final String? trialEndsAt;
  final bool cancelAtPeriodEnd;
  final bool canUpgrade;

  factory HermesSubscriptionSummary.fromJson(Map<String, Object?> json) =>
      HermesSubscriptionSummary(
        tier: _readStringOrDefault(json['tier'], 'base'),
        status: json['status']?.toString(),
        currentPeriodEnd: json['current_period_end']?.toString(),
        trialEndsAt: json['trial_ends_at']?.toString(),
        cancelAtPeriodEnd: _readBool(json['cancel_at_period_end'] ?? false),
        canUpgrade: _readBool(json['can_upgrade'] ?? false),
      );
}

class HermesBillingPaymentMethod {
  const HermesBillingPaymentMethod({
    this.id,
    this.type = 'card',
    this.brand,
    this.last4,
    this.expMonth,
    this.expYear,
  });

  final String? id;
  final String type;
  final String? brand;
  final String? last4;
  final int? expMonth;
  final int? expYear;

  String get displayBrand {
    final normalized = (brand ?? type).replaceAll('_', ' ').trim();
    if (normalized.isEmpty) return 'Card';
    return normalized
        .split(RegExp(r'\s+'))
        .map(
          (word) => word.isEmpty
              ? word
              : '${word[0].toUpperCase()}${word.substring(1)}',
        )
        .join(' ');
  }

  String get displayLine {
    final suffix = last4 == null || last4!.isEmpty ? '' : ' ending $last4';
    final expiry = expMonth == null || expYear == null
        ? ''
        : ' • expires ${expMonth.toString().padLeft(2, '0')}/$expYear';
    return '$displayBrand$suffix$expiry';
  }

  factory HermesBillingPaymentMethod.fromJson(Map<String, Object?> json) =>
      HermesBillingPaymentMethod(
        id: json['id']?.toString(),
        type: _readStringOrDefault(json['type'], 'card'),
        brand: json['brand']?.toString(),
        last4: json['last4']?.toString(),
        expMonth: _readIntOrNull(json['exp_month'] ?? json['expMonth']),
        expYear: _readIntOrNull(json['exp_year'] ?? json['expYear']),
      );
}

class HermesSubscriptionResult {
  const HermesSubscriptionResult({
    required this.subscription,
    this.plan,
    this.paymentMethod,
  });

  final HermesSubscriptionSummary subscription;
  final String? plan;
  final HermesBillingPaymentMethod? paymentMethod;

  factory HermesSubscriptionResult.fromJson(Map<String, Object?> json) =>
      HermesSubscriptionResult(
        subscription: HermesSubscriptionSummary.fromJson(
          _expectMap(json['subscription']),
        ),
        plan: json['plan']?.toString(),
        paymentMethod: json['payment_method'] is Map<String, Object?>
            ? HermesBillingPaymentMethod.fromJson(
                _expectMap(json['payment_method']),
              )
            : null,
      );
}

class HermesDashboardChangeFeed {
  const HermesDashboardChangeFeed({
    required this.changes,
    required this.latestId,
  });

  final List<HermesDashboardChange> changes;
  final int latestId;

  factory HermesDashboardChangeFeed.fromJson(Map<String, Object?> json) =>
      HermesDashboardChangeFeed(
        changes: _expectList(json['changes'] ?? const [])
            .map((change) => HermesDashboardChange.fromJson(_expectMap(change)))
            .toList(),
        latestId: _readIntOrNull(json['latest_id'] ?? json['latestId']) ?? 0,
      );
}

class HermesDashboardChange {
  const HermesDashboardChange({
    required this.id,
    required this.resourceType,
    required this.action,
    this.userId,
    this.workspaceId,
    this.resourceId,
    this.payload = const {},
  });

  final int id;
  final int? userId;
  final int? workspaceId;
  final String resourceType;
  final String action;
  final int? resourceId;
  final Map<String, Object?> payload;

  factory HermesDashboardChange.fromJson(
    Map<String, Object?> json,
  ) => HermesDashboardChange(
    id: _expectInt(json['id']),
    userId: _readIntOrNull(json['user_id'] ?? json['userId']),
    workspaceId: _readIntOrNull(json['workspace_id'] ?? json['workspaceId']),
    resourceType: _expectString(json['resource_type'] ?? json['resourceType']),
    action: _expectString(json['action']),
    resourceId: _readIntOrNull(json['resource_id'] ?? json['resourceId']),
    payload: _expectMapOrNull(json['payload']) ?? const {},
  );
}

class HermesNotificationPreferences {
  const HermesNotificationPreferences({
    this.reminderPush = true,
    this.reminderEmail = true,
  });

  final bool reminderPush;
  final bool reminderEmail;

  Map<String, Object?> toJson() => {
    'reminder_push': reminderPush,
    'reminder_email': reminderEmail,
  };

  factory HermesNotificationPreferences.fromJson(Map<String, Object?>? json) =>
      HermesNotificationPreferences(
        reminderPush: _readBool(
          json?['reminder_push'] ?? json?['reminderPush'] ?? true,
        ),
        reminderEmail: _readBool(
          json?['reminder_email'] ?? json?['reminderEmail'] ?? true,
        ),
      );

  HermesNotificationPreferences copyWith({
    bool? reminderPush,
    bool? reminderEmail,
  }) => HermesNotificationPreferences(
    reminderPush: reminderPush ?? this.reminderPush,
    reminderEmail: reminderEmail ?? this.reminderEmail,
  );
}

class HermesUser {
  const HermesUser({
    required this.id,
    required this.name,
    required this.email,
    this.subscriptionTier = 'base',
    this.subscriptionStatus,
    this.subscriptionTrialEndsAt,
    this.theme = 'green',
    this.onboardComplete = false,
    this.agentProfile,
    this.defaultWorkspaceId,
    this.personalWorkspace,
    this.activeWorkspace,
    this.workspaces = const [],
    this.activeWorkspaceAgentProfile,
    this.needsBeanOnboarding,
    this.beanPreferencesReady,
    this.isBeta = false,
    this.isAdmin = false,
    this.notificationPreferences = const HermesNotificationPreferences(),
  });

  final int id;
  final String name;
  final String email;
  final String subscriptionTier;
  final String? subscriptionStatus;
  final String? subscriptionTrialEndsAt;
  final String theme;
  final bool onboardComplete;
  final HermesAgentProfile? agentProfile;
  final int? defaultWorkspaceId;
  final HermesWorkspace? personalWorkspace;
  final HermesWorkspace? activeWorkspace;
  final List<HermesWorkspace> workspaces;
  final HermesAgentProfile? activeWorkspaceAgentProfile;
  final bool? needsBeanOnboarding;
  final bool? beanPreferencesReady;
  final bool isBeta;
  final bool isAdmin;
  final HermesNotificationPreferences notificationPreferences;

  HermesAgentProfile? get currentAgentProfile =>
      activeWorkspaceAgentProfile ?? agentProfile;

  HermesUser copyWith({
    String? name,
    String? email,
    String? subscriptionTier,
    String? subscriptionStatus,
    String? subscriptionTrialEndsAt,
    String? theme,
    bool? onboardComplete,
    HermesAgentProfile? agentProfile,
    int? defaultWorkspaceId,
    HermesWorkspace? personalWorkspace,
    HermesWorkspace? activeWorkspace,
    List<HermesWorkspace>? workspaces,
    HermesAgentProfile? activeWorkspaceAgentProfile,
    bool? needsBeanOnboarding,
    bool? beanPreferencesReady,
    bool? isBeta,
    bool? isAdmin,
    HermesNotificationPreferences? notificationPreferences,
  }) => HermesUser(
    id: id,
    name: name ?? this.name,
    email: email ?? this.email,
    subscriptionTier: subscriptionTier ?? this.subscriptionTier,
    subscriptionStatus: subscriptionStatus ?? this.subscriptionStatus,
    subscriptionTrialEndsAt:
        subscriptionTrialEndsAt ?? this.subscriptionTrialEndsAt,
    theme: theme ?? this.theme,
    onboardComplete: onboardComplete ?? this.onboardComplete,
    agentProfile: agentProfile ?? this.agentProfile,
    defaultWorkspaceId: defaultWorkspaceId ?? this.defaultWorkspaceId,
    personalWorkspace: personalWorkspace ?? this.personalWorkspace,
    activeWorkspace: activeWorkspace ?? this.activeWorkspace,
    workspaces: workspaces ?? this.workspaces,
    activeWorkspaceAgentProfile:
        activeWorkspaceAgentProfile ?? this.activeWorkspaceAgentProfile,
    needsBeanOnboarding: needsBeanOnboarding ?? this.needsBeanOnboarding,
    beanPreferencesReady: beanPreferencesReady ?? this.beanPreferencesReady,
    isBeta: isBeta ?? this.isBeta,
    isAdmin: isAdmin ?? this.isAdmin,
    notificationPreferences:
        notificationPreferences ?? this.notificationPreferences,
  );

  factory HermesUser.fromJson(Map<String, Object?> json) => HermesUser(
    id: _expectInt(json['id']),
    name: _expectString(json['name']),
    email: _expectString(json['email']),
    subscriptionTier: _readStringOrDefault(
      json['subscription_tier'] ?? json['subscriptionTier'],
      'base',
    ),
    subscriptionStatus:
        (json['subscription_status'] ?? json['subscriptionStatus'])?.toString(),
    subscriptionTrialEndsAt:
        (json['subscription_trial_ends_at'] ?? json['subscriptionTrialEndsAt'])
            ?.toString(),
    theme: _readStringOrDefault(json['theme'], 'green'),
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
    needsBeanOnboarding: json['needs_bean_onboarding'] == null
        ? (json['needsBeanOnboarding'] is bool
              ? json['needsBeanOnboarding'] as bool
              : null)
        : json['needs_bean_onboarding'] == true,
    beanPreferencesReady: json['bean_preferences_ready'] == null
        ? (json['beanPreferencesReady'] is bool
              ? json['beanPreferencesReady'] as bool
              : null)
        : json['bean_preferences_ready'] == true,
    isBeta:
        json['is_beta'] == true ||
        json['isBeta'] == true ||
        json['beta_user'] != null ||
        json['betaUser'] != null,
    isAdmin: json['is_admin'] == true || json['isAdmin'] == true,
    notificationPreferences: HermesNotificationPreferences.fromJson(
      _expectMapOrNull(json['notification_preferences']),
    ),
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
    List<HermesWorkspaceMembership>? memberships,
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
    memberships: memberships ?? this.memberships,
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
    this.invitationToken,
    this.invitationAcceptUrl,
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
  final String? invitationToken;
  final String? invitationAcceptUrl;
  final Map<String, Object?> metadata;
  final HermesWorkspaceMemberUser? user;
  final HermesWorkspace? workspace;

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
        invitationToken:
            (json['invitation_token'] ??
                    _expectMapOrNull(json['metadata'])?['invitation_token'])
                ?.toString(),
        invitationAcceptUrl: json['invitation_accept_url']?.toString(),
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

  bool get preferencesReady =>
      onboardingCompleted &&
      (onboardingPriorities.isNotEmpty || onboardingContext.trim().isNotEmpty);

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
    this.notes,
    this.category,
    this.color,
    this.isCritical = false,
    this.completedAt,
    this.metadata,
    this.workspaceId,
    this.linkedWorkspaceIds = const [],
  });

  final int id;
  final String title;
  final String? status;
  final String? dueAt;
  final String? notes;
  final String? category;
  final String? color;
  final bool isCritical;
  final String? completedAt;
  final Map<String, Object?>? metadata;
  final int? workspaceId;
  final List<int> linkedWorkspaceIds;

  List<String> get googleCalendarIds =>
      _googleCalendarIdsFromMetadata(metadata);

  int? get parentTaskId {
    final raw =
        metadata?['parent_task_id'] ??
        metadata?['parentTaskId'] ??
        metadata?['parent_id'] ??
        metadata?['parentId'];
    if (raw is int) return raw;
    if (raw is num) return raw.toInt();
    if (raw is String) return int.tryParse(raw);
    return null;
  }

  factory HermesTask.fromJson(Map<String, Object?> json) {
    final metadata = _expectMapOrNull(json['metadata']);
    return HermesTask(
      id: _expectInt(json['id']),
      title: _readTitle(json),
      status: json['status'] as String?,
      dueAt: (json['due_at'] ?? json['dueAt']) as String?,
      notes: json['notes'] as String?,
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
      workspaceId: _readIntOrNull(json['workspace_id']),
      linkedWorkspaceIds: _expectList(
        json['linked_workspace_ids'] ?? const [],
      ).map(_readIntOrNull).whereType<int>().toList(),
    );
  }

  HermesTask copyWith({
    String? title,
    String? status,
    String? dueAt,
    String? notes,
    String? category,
    String? color,
    bool? isCritical,
    String? completedAt,
    Map<String, Object?>? metadata,
    bool clearDueAt = false,
    bool clearNotes = false,
    bool clearCategory = false,
    bool clearColor = false,
    bool clearCompletedAt = false,
  }) => HermesTask(
    id: id,
    title: title ?? this.title,
    status: status ?? this.status,
    dueAt: clearDueAt ? null : dueAt ?? this.dueAt,
    notes: clearNotes ? null : notes ?? this.notes,
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
    this.workspaceId,
    this.linkedWorkspaceIds = const [],
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
  final int? workspaceId;
  final List<int> linkedWorkspaceIds;

  List<String> get googleCalendarIds =>
      _googleCalendarIdsFromMetadata(metadata);

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
      workspaceId: _readIntOrNull(json['workspace_id']),
      linkedWorkspaceIds: _expectList(
        json['linked_workspace_ids'] ?? const [],
      ).map(_readIntOrNull).whereType<int>().toList(),
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
    int? workspaceId,
    List<int>? linkedWorkspaceIds,
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
    workspaceId: workspaceId ?? this.workspaceId,
    linkedWorkspaceIds: linkedWorkspaceIds ?? this.linkedWorkspaceIds,
  );
}

class HermesEventCategory {
  const HermesEventCategory({
    required this.id,
    required this.name,
    required this.color,
    this.workspaceId,
    this.linkedWorkspaceIds = const [],
  });

  final int id;
  final String name;
  final String color;
  final int? workspaceId;
  final List<int> linkedWorkspaceIds;

  factory HermesEventCategory.fromJson(Map<String, Object?> json) =>
      HermesEventCategory(
        id: _expectInt(json['id']),
        name: _expectString(json['name']),
        color: (json['color'] as String?) ?? '#34C759',
        workspaceId: _readIntOrNull(json['workspace_id']),
        linkedWorkspaceIds: _expectList(
          json['linked_workspace_ids'] ?? const [],
        ).map(_readIntOrNull).whereType<int>().toList(),
      );

  HermesEventCategory copyWith({
    String? name,
    String? color,
    int? workspaceId,
    List<int>? linkedWorkspaceIds,
  }) => HermesEventCategory(
    id: id,
    name: name ?? this.name,
    color: color ?? this.color,
    workspaceId: workspaceId ?? this.workspaceId,
    linkedWorkspaceIds: linkedWorkspaceIds ?? this.linkedWorkspaceIds,
  );
}

class HermesCalendarEvent {
  const HermesCalendarEvent({
    required this.id,
    required this.title,
    this.workspaceId,
    this.linkedWorkspaceIds = const [],
    this.startsAt,
    this.endsAt,
    this.notes,
    this.location,
    this.status,
    this.category,
    this.color,
    this.isCritical = false,
    this.recurrence,
    this.metadata,
  });

  final int id;
  final String title;
  final int? workspaceId;
  final List<int> linkedWorkspaceIds;
  final String? startsAt;
  final String? endsAt;
  final String? notes;
  final String? location;
  final String? status;
  final String? category;
  final String? color;
  final bool isCritical;
  final String? recurrence;
  final Map<String, Object?>? metadata;

  String? get googleCalendarId => googleCalendarIds.firstOrNull;

  List<String> get googleCalendarIds {
    final raw =
        metadata?['google_calendar_ids'] ?? metadata?['googleCalendarIds'];
    if (raw is List) {
      return raw
          .map((value) => value.toString())
          .where((value) => value.isNotEmpty)
          .toList();
    }
    final single =
        metadata?['google_calendar_id'] ?? metadata?['googleCalendarId'];
    return single == null || single.toString().isEmpty
        ? const []
        : [single.toString()];
  }

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
      workspaceId: _readIntOrNull(json['workspace_id']),
      linkedWorkspaceIds: _expectList(
        json['linked_workspace_ids'] ?? const [],
      ).map(_readIntOrNull).whereType<int>().toList(),
      startsAt: (json['starts_at'] ?? json['startsAt']) as String?,
      endsAt: (json['ends_at'] ?? json['endsAt']) as String?,
      notes: (json['description'] ?? json['notes']) as String?,
      location: json['location'] as String?,
      status: json['status'] as String?,
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
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    bool? isCritical,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool clearEndsAt = false,
    bool clearNotes = false,
    bool clearCategory = false,
    bool clearColor = false,
    bool clearLocation = false,
    bool clearStatus = false,
    bool clearRecurrence = false,
    bool clearMetadata = false,
  }) => HermesCalendarEvent(
    id: id,
    title: title ?? this.title,
    workspaceId: workspaceId,
    linkedWorkspaceIds: linkedWorkspaceIds,
    startsAt: startsAt ?? this.startsAt,
    endsAt: clearEndsAt ? null : endsAt ?? this.endsAt,
    notes: clearNotes ? null : notes ?? this.notes,
    location: clearLocation ? null : location ?? this.location,
    status: clearStatus ? null : status ?? this.status,
    category: clearCategory ? null : category ?? this.category,
    color: clearColor ? null : color ?? this.color,
    isCritical: isCritical ?? this.isCritical,
    recurrence: clearRecurrence ? null : recurrence ?? this.recurrence,
    metadata: clearMetadata ? null : metadata ?? this.metadata,
  );
}

class HermesSession {
  const HermesSession({
    required this.id,
    required this.status,
    this.workspaceId,
    this.title,
  });

  final int id;
  final String status;
  final int? workspaceId;
  final String? title;

  factory HermesSession.fromJson(Map<String, Object?> json) => HermesSession(
    id: _expectInt(json['id']),
    status: _expectString(json['status']),
    workspaceId: _readIntOrNull(json['workspace_id']),
    title: json['title'] as String?,
  );
}

class HermesSessionList {
  const HermesSessionList({required this.sessions, this.todaySession});

  final List<HermesSession> sessions;
  final HermesSession? todaySession;

  factory HermesSessionList.fromJson(Map<String, Object?> json) =>
      HermesSessionList(
        sessions: _expectList(json['sessions'] ?? const [])
            .map((session) => HermesSession.fromJson(_expectMap(session)))
            .toList(),
        todaySession: (json['today_session'] ?? json['todaySession']) == null
            ? null
            : HermesSession.fromJson(
                _expectMap(json['today_session'] ?? json['todaySession']),
              ),
      );
}

class HermesSessionDetails {
  const HermesSessionDetails({
    required this.session,
    this.messages = const [],
    this.activityEvents = const [],
  });

  final HermesSession session;
  final List<HermesMessage> messages;
  final List<HermesActivityEvent> activityEvents;

  factory HermesSessionDetails.fromJson(Map<String, Object?> json) {
    final session = HermesSession.fromJson(json);
    return HermesSessionDetails(
      session: session,
      messages: _expectList(
        json['messages'] ?? const [],
      ).map((message) => HermesMessage.fromJson(_expectMap(message))).toList(),
      activityEvents:
          _expectList(
                json['activity_events'] ?? json['activityEvents'] ?? const [],
              )
              .map((event) => HermesActivityEvent.fromJson(_expectMap(event)))
              .toList(),
    );
  }
}

class HermesMessageResult {
  const HermesMessageResult({
    required this.status,
    required this.session,
    required this.events,
    this.userMessage,
    this.assistantMessage,
    this.run,
    this.blocker,
  });

  final String status;
  final HermesSession session;
  final List<HermesActivityEvent> events;
  final HermesMessage? userMessage;
  final HermesMessage? assistantMessage;
  final HermesAssistantRun? run;
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
        run: json['run'] == null
            ? null
            : HermesAssistantRun.fromJson(_expectMap(json['run'])),
        events: _expectList(json['events'])
            .map((event) => HermesActivityEvent.fromJson(_expectMap(event)))
            .toList(),
        blocker: json['blocker'] == null ? null : _expectMap(json['blocker']),
      );
}

class HermesAssistantRun {
  const HermesAssistantRun({
    required this.id,
    required this.status,
    required this.source,
    this.assistantMessageId,
    this.assistantMessage,
  });

  final int id;
  final String status;
  final String source;
  final int? assistantMessageId;
  final HermesMessage? assistantMessage;

  factory HermesAssistantRun.fromJson(Map<String, Object?> json) =>
      HermesAssistantRun(
        id: _expectInt(json['id']),
        status: _expectString(json['status']),
        source: _expectString(json['source']),
        assistantMessageId: _readIntOrNull(json['assistant_message_id']),
        assistantMessage: json['assistant_message'] == null
            ? null
            : HermesMessage.fromJson(_expectMap(json['assistant_message'])),
      );
}

class HermesRealtimeSession {
  const HermesRealtimeSession({
    required this.session,
    required this.clientSecret,
    this.model,
    this.voice,
  });

  final HermesSession session;
  final String clientSecret;
  final String? model;
  final String? voice;

  factory HermesRealtimeSession.fromJson(Map<String, Object?> json) {
    final secret = json['client_secret'];
    final secretValue = secret is Map
        ? (secret['value'] ?? secret['client_secret'])?.toString()
        : secret?.toString();
    final openai = json['openai'] is Map
        ? Map<String, Object?>.from(json['openai'] as Map)
        : const <String, Object?>{};

    return HermesRealtimeSession(
      session: HermesSession.fromJson(_expectMap(json['session'])),
      clientSecret: secretValue ?? '',
      model: openai['model']?.toString(),
      voice: openai['voice']?.toString(),
    );
  }
}

class HermesMessage {
  const HermesMessage({
    required this.id,
    required this.role,
    this.content,
    this.metadata = const {},
  });

  final int id;
  final String role;
  final String? content;
  final Map<String, Object?> metadata;

  String? get modelName {
    final directModel = metadata['model'];
    if (directModel != null && directModel.toString().trim().isNotEmpty) {
      return directModel.toString().trim();
    }

    final route = metadata['model_route'];
    if (route is Map) {
      final routedModel = route['model'];
      if (routedModel != null && routedModel.toString().trim().isNotEmpty) {
        return routedModel.toString().trim();
      }
    }

    return null;
  }

  factory HermesMessage.fromJson(Map<String, Object?> json) => HermesMessage(
    id: _expectInt(json['id']),
    role: _expectString(json['role']),
    content: json['content'] as String?,
    metadata: json['metadata'] == null
        ? const {}
        : _expectMap(json['metadata']),
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
  const HermesApproval({
    required this.id,
    required this.title,
    this.status,
    this.description,
    this.payload = const {},
  });

  final int id;
  final String title;
  final String? status;
  final String? description;
  final Map<String, Object?> payload;

  factory HermesApproval.fromJson(Map<String, Object?> json) => HermesApproval(
    id: _expectInt(json['id']),
    title: _readTitle(json),
    status: json['status'] as String?,
    description: json['description']?.toString(),
    payload: json['payload'] == null ? const {} : _expectMap(json['payload']),
  );
}

class HermesApprovalResult {
  const HermesApprovalResult({required this.approval, this.events = const []});

  final HermesApproval approval;
  final List<HermesActivityEvent> events;

  factory HermesApprovalResult.fromJson(Map<String, Object?> json) =>
      HermesApprovalResult(
        approval: HermesApproval.fromJson(_expectMap(json['approval'])),
        events: _expectList(json['events'] ?? const [])
            .map((event) => HermesActivityEvent.fromJson(_expectMap(event)))
            .toList(),
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
  if (value is Map) {
    return value.map<String, Object?>(
      (key, value) => MapEntry(key.toString(), value),
    );
  }
  throw FormatException('Expected JSON object, got ${value.runtimeType}');
}

Map<String, Object?>? _expectMapOrNull(Object? value) {
  if (value == null) return null;
  if (value is String) {
    final trimmed = value.trim();
    if (trimmed.isEmpty || trimmed == 'null') return null;
    final decoded = jsonDecode(trimmed);
    return _expectMap(decoded);
  }
  return _expectMap(value);
}

List<Object?> _expectList(Object? value) {
  if (value is List<Object?>) return value;
  throw FormatException('Expected JSON array, got ${value.runtimeType}');
}

List<String> _googleCalendarIdsFromMetadata(Map<String, Object?>? metadata) {
  final raw =
      metadata?['google_calendar_ids'] ?? metadata?['googleCalendarIds'];
  if (raw is List) {
    return raw
        .map((value) => value.toString())
        .where((value) => value.isNotEmpty)
        .toList();
  }
  final single =
      metadata?['google_calendar_id'] ?? metadata?['googleCalendarId'];
  return single == null || single.toString().isEmpty
      ? const []
      : [single.toString()];
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

String _readStringOrDefault(Object? value, String fallback) {
  if (value is String && value.trim().isNotEmpty) return value;
  return fallback;
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
