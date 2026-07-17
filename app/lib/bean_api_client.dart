import 'dart:async';
import 'dart:convert';
import 'dart:io';

const String hermesApiBaseUrl = String.fromEnvironment(
  'HERMES_API_BASE_URL',
  defaultValue: 'https://heybean.org/api',
);

Uri normalizeBeanApiBaseUrlForPlatform(Uri baseUrl, {bool? isAndroid}) {
  final runningOnAndroid = isAndroid ?? Platform.isAndroid;
  if (!runningOnAndroid) return baseUrl;

  final host = baseUrl.host.toLowerCase();
  if (host != 'localhost' && host != '127.0.0.1') return baseUrl;

  return baseUrl.replace(host: '10.0.2.2');
}

const Duration _standardApiResponseTimeout = Duration(seconds: 30);

typedef BeanApiTransport =
    Future<BeanApiResponse> Function(BeanApiRequest request);

class BeanApiClient {
  BeanApiClient({Uri? baseUrl, BeanApiTransport? transport, this.bearerToken})
    : baseUrl = _validateBaseUrl(
        normalizeBeanApiBaseUrlForPlatform(
          baseUrl ?? Uri.parse(hermesApiBaseUrl),
        ),
      ),
      _transport = transport ?? _defaultTransport;

  static Uri _validateBaseUrl(Uri baseUrl) {
    if (const bool.fromEnvironment('dart.vm.product') &&
        baseUrl.scheme != 'https') {
      throw StateError(
        'HeyBean production builds require an HTTPS API base URL.',
      );
    }
    return baseUrl;
  }

  final Uri baseUrl;
  final BeanApiTransport _transport;
  String? bearerToken;

  Map<String, String> get authenticatedHeaders =>
      bearerToken == null ? const {} : {'Authorization': 'Bearer $bearerToken'};

  Uri resolveApiUri(String path) => _resolveApiPath(path);

  Future<BeanAuthResult> register({
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
    return _rememberAuth(BeanAuthResult.fromJson(_expectMap(data['data'])));
  }

  Future<BeanEmailAvailability> checkEmailAvailability({
    required String email,
  }) async {
    final data = await _sendJson(
      'POST',
      '/auth/email-availability',
      body: {'email': email},
      authenticated: false,
    );
    return BeanEmailAvailability.fromJson(_expectMap(data['data']));
  }

  Future<BeanAuthResult> login({
    required String email,
    required String password,
  }) async {
    final data = await _sendJson(
      'POST',
      '/auth/login',
      body: {'email': email, 'password': password},
      authenticated: false,
    );
    return _rememberAuth(BeanAuthResult.fromJson(_expectMap(data['data'])));
  }

  Future<void> requestPasswordReset({required String email}) async {
    await _sendJson(
      'POST',
      '/auth/forgot-password',
      body: {'email': email},
      authenticated: false,
    );
  }

  Future<BeanCheckoutSession> createCheckoutSession({
    required String plan,
    String billingInterval = 'monthly',
    String source = 'flutter',
  }) async {
    final data = await _sendJson(
      'POST',
      '/billing/checkout-sessions',
      body: {
        'plan': plan,
        'billing_interval': billingInterval,
        'source': source,
      },
    );
    return BeanCheckoutSession.fromJson(_expectMap(data['data']));
  }

  Future<BeanPaymentSheetSetup> createMobileSubscriptionSetup({
    required String plan,
    String billingInterval = 'monthly',
  }) async {
    final data = await _sendJson(
      'POST',
      '/billing/mobile-subscriptions/setup',
      body: {'plan': plan, 'billing_interval': billingInterval},
    );
    return BeanPaymentSheetSetup.fromJson(_expectMap(data['data']));
  }

  Future<BeanSubscriptionResult> confirmMobileSubscription({
    required String plan,
    String billingInterval = 'monthly',
    required String setupIntentId,
  }) async {
    final data = await _sendJson(
      'POST',
      '/billing/mobile-subscriptions/confirm',
      body: {
        'plan': plan,
        'billing_interval': billingInterval,
        'setup_intent_id': setupIntentId,
      },
    );
    return BeanSubscriptionResult.fromJson(_expectMap(data['data']));
  }

  Future<BeanBillingPaymentMethod?> getBillingPaymentMethod() async {
    final data = await _sendJson('GET', '/billing/payment-method');
    final method = _expectMap(data['data'])['payment_method'];
    return method is Map<String, Object?>
        ? BeanBillingPaymentMethod.fromJson(_expectMap(method))
        : null;
  }

  Future<BeanPaymentSheetSetup> createPaymentMethodSetup() async {
    final data = await _sendJson('POST', '/billing/payment-method/setup');
    return BeanPaymentSheetSetup.fromJson(_expectMap(data['data']));
  }

  Future<BeanBillingPaymentMethod?> confirmPaymentMethodSetup({
    required String setupIntentId,
  }) async {
    final data = await _sendJson(
      'POST',
      '/billing/payment-method/confirm',
      body: {'setup_intent_id': setupIntentId},
    );
    final method = _expectMap(data['data'])['payment_method'];
    return method is Map<String, Object?>
        ? BeanBillingPaymentMethod.fromJson(_expectMap(method))
        : null;
  }

  Future<BeanSubscriptionSummary> cancelSubscription() async {
    final data = await _sendJson('POST', '/billing/subscription/cancel');
    return BeanSubscriptionSummary.fromJson(
      _expectMap(_expectMap(data['data'])['subscription']),
    );
  }

  Future<BeanSubscriptionSummary> resumeSubscription() async {
    final data = await _sendJson('POST', '/billing/subscription/resume');
    return BeanSubscriptionSummary.fromJson(
      _expectMap(_expectMap(data['data'])['subscription']),
    );
  }

  Future<BeanSubscriptionSummary> getSubscriptionSummary() async {
    final data = await _sendJson('GET', '/billing/subscription');
    return BeanSubscriptionSummary.fromJson(_expectMap(data['data']));
  }

  Future<BeanCouponRedemptionResult> redeemCouponCode({
    required String code,
  }) async {
    final data = await _sendJson(
      'POST',
      '/billing/coupon-codes/redeem',
      body: {'code': code},
    );
    return BeanCouponRedemptionResult.fromJson(_expectMap(data['data']));
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

  Future<BeanUser> me() async {
    final data = await _sendJson('GET', '/auth/me');
    return BeanUser.fromJson(_expectMap(data['data']));
  }

  Future<BeanUser> updateMe({
    String? name,
    String? email,
    String? theme,
    String? themeMode,
    String? commandCenterLabel,
    String? preferredMapApp,
    BeanNotificationPreferences? notificationPreferences,
  }) async {
    final data = await _sendJson(
      'PATCH',
      '/auth/me',
      body: {
        if (name != null) 'name': name,
        if (email != null) 'email': email,
        if (theme != null) 'theme': theme,
        if (themeMode != null) 'theme_mode': themeMode,
        if (commandCenterLabel != null)
          'command_center_label': commandCenterLabel,
        if (preferredMapApp != null) 'preferred_map_app': preferredMapApp,
        if (notificationPreferences != null)
          'notification_preferences': notificationPreferences.toJson(),
      },
    );
    return BeanUser.fromJson(_expectMap(data['data']));
  }

  Future<void> deleteAccount() async {
    await _sendJson('DELETE', '/account');
    bearerToken = null;
  }

  Future<Map<String, Object?>> exportAccount() async {
    final data = await _sendJson('GET', '/account/export');
    return _expectMap(data['data']);
  }

  Future<BeanDashboardChangeFeed> dashboardChanges({
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
    return BeanDashboardChangeFeed.fromJson(_expectMap(data['data']));
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

  Future<List<BeanPlaceSuggestion>> autocompletePlaces({
    required String input,
    String? sessionToken,
  }) async {
    final data = await _sendJson(
      'GET',
      _pathWithQuery('/places/autocomplete', {
        'input': input,
        if (sessionToken != null) 'session_token': sessionToken,
      }),
    );
    final payload = _expectMap(data['data']);
    return _expectList(
      payload['suggestions'] ?? const [],
    ).map((json) => BeanPlaceSuggestion.fromJson(_expectMap(json))).toList();
  }

  Future<BeanPlaceDetails> placeDetails({
    required String placeId,
    String? sessionToken,
  }) async {
    final data = await _sendJson(
      'GET',
      _pathWithQuery('/places/details', {
        'place_id': placeId,
        if (sessionToken != null) 'session_token': sessionToken,
      }),
    );
    return BeanPlaceDetails.fromJson(_expectMap(data['data']));
  }

  Future<List<BeanWorkspace>> listWorkspaces() async {
    final data = await _sendJson('GET', '/workspaces');
    return _expectList(
      data['data'],
    ).map((json) => BeanWorkspace.fromJson(_expectMap(json))).toList();
  }

  Future<BeanWorkspace> createWorkspace({required String name}) async {
    final data = await _sendJson('POST', '/workspaces', body: {'name': name});
    return BeanWorkspace.fromJson(_expectMap(data['data']));
  }

  Future<BeanWorkspace> getWorkspace(int workspaceId) async {
    final data = await _sendJson('GET', '/workspaces/$workspaceId');
    return BeanWorkspace.fromJson(_expectMap(data['data']));
  }

  Future<BeanWorkspace> updateWorkspace(
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
    return BeanWorkspace.fromJson(_expectMap(data['data']));
  }

  Future<BeanWorkspaceMembership> inviteWorkspaceMember(
    int workspaceId, {
    required String email,
  }) async {
    final data = await _sendJson(
      'POST',
      '/workspaces/$workspaceId/invitations',
      body: {'email': email},
    );
    return BeanWorkspaceMembership.fromJson(_expectMap(data['data']));
  }

  Future<BeanWorkspaceMembership> acceptWorkspaceInvitation(
    String token,
  ) async {
    final data = await _sendJson(
      'POST',
      '/workspace-invitations/$token/accept',
    );
    return BeanWorkspaceMembership.fromJson(_expectMap(data['data']));
  }

  Future<BeanWorkspaceMembership> updateWorkspaceMember(
    int workspaceId,
    int memberId, {
    required String role,
  }) async {
    final data = await _sendJson(
      'PATCH',
      '/workspaces/$workspaceId/members/$memberId',
      body: {'role': role},
    );
    return BeanWorkspaceMembership.fromJson(_expectMap(data['data']));
  }

  Future<void> removeWorkspaceMember(int workspaceId, int memberId) async {
    await _sendJson('DELETE', '/workspaces/$workspaceId/members/$memberId');
  }

  Future<void> leaveWorkspace(int workspaceId) async {
    await _sendJson('POST', '/workspaces/$workspaceId/leave');
  }

  Future<BeanWorkspace> setDefaultWorkspace(int workspaceId) async {
    final data = await _sendJson(
      'PATCH',
      '/workspaces/default',
      body: {'workspace_id': workspaceId},
    );
    return BeanWorkspace.fromJson(_expectMap(data['data']));
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

  Future<List<BeanTask>> listTasks() async {
    final data = await _sendJson('GET', '/tasks');
    return _expectList(
      data['data'],
    ).map((json) => BeanTask.fromJson(_expectMap(json))).toList();
  }

  Future<List<BeanNoteFolder>> listNoteFolders() async {
    final data = await _sendJson('GET', '/note-folders');
    return _expectList(
      data['data'],
    ).map((json) => BeanNoteFolder.fromJson(_expectMap(json))).toList();
  }

  Future<BeanNoteFolder> createNoteFolder({required String name}) async {
    final data = await _sendJson('POST', '/note-folders', body: {'name': name});
    return BeanNoteFolder.fromJson(_expectMap(data['data']));
  }

  Future<BeanNoteFolder> updateNoteFolder({
    required int folderId,
    String? name,
    int? sortOrder,
  }) async {
    final body = <String, Object?>{
      if (name != null) 'name': name,
      if (sortOrder != null) 'sort_order': sortOrder,
    };
    final data = await _sendJson(
      'PATCH',
      '/note-folders/$folderId',
      body: body,
    );
    return BeanNoteFolder.fromJson(_expectMap(data['data']));
  }

  Future<void> deleteNoteFolder(int folderId) async {
    await _sendJson('DELETE', '/note-folders/$folderId');
  }

  Future<List<BeanNote>> listNotes() async {
    final data = await _sendJson('GET', '/notes');
    return _expectList(
      data['data'],
    ).map((json) => BeanNote.fromJson(_expectMap(json))).toList();
  }

  Future<BeanNote> createNote({
    String title = 'New Note',
    String bodyHtml = '',
    String plainText = '',
    int? folderId,
    bool isPinned = false,
    Map<String, Object?>? metadata,
    List<Object> syncToWorkspaceIds = const [],
  }) async {
    final data = await _sendJson(
      'POST',
      '/notes',
      body: {
        'title': _sanitizeVisibleText(title),
        'body_html': _sanitizeVisibleText(bodyHtml),
        'plain_text': _sanitizeVisibleText(plainText),
        'note_folder_id': folderId,
        'is_pinned': isPinned,
        if (metadata != null) 'metadata': metadata,
        if (syncToWorkspaceIds.isNotEmpty)
          'sync_to_workspace_ids': syncToWorkspaceIds,
      },
    );
    return BeanNote.fromJson(_expectMap(data['data']));
  }

  Future<BeanNote> updateNote(
    int noteId, {
    String? title,
    String? bodyHtml,
    String? plainText,
    int? folderId,
    bool clearFolder = false,
    bool? isPinned,
    Map<String, Object?>? metadata,
    List<Object>? syncToWorkspaceIds,
  }) async {
    final data = await _sendJson(
      'PATCH',
      '/notes/$noteId',
      body: {
        if (title != null) 'title': _sanitizeVisibleText(title),
        if (bodyHtml != null) 'body_html': _sanitizeVisibleText(bodyHtml),
        if (plainText != null) 'plain_text': _sanitizeVisibleText(plainText),
        if (folderId != null || clearFolder) 'note_folder_id': folderId,
        if (isPinned != null) 'is_pinned': isPinned,
        if (metadata != null) 'metadata': metadata,
        if (syncToWorkspaceIds != null)
          'sync_to_workspace_ids': syncToWorkspaceIds,
      },
    );
    return BeanNote.fromJson(_expectMap(data['data']));
  }

  Future<void> deleteNote(int noteId) async {
    await _sendJson('DELETE', '/notes/$noteId');
  }

  Future<List<BeanTask>> listPastTasks() async {
    final data = await _sendJson('GET', '/tasks/past');
    return _expectList(
      data['data'],
    ).map((json) => BeanTask.fromJson(_expectMap(json))).toList();
  }

  Future<BeanTask> createTask({
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
    return BeanTask.fromJson(_expectMap(data['data']));
  }

  Future<BeanTask> updateTask(
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
    return BeanTask.fromJson(_expectMap(data['data']));
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

  Future<BeanTask> completeTask(int taskId) async {
    final data = await _sendJson(
      'PATCH',
      '/tasks/$taskId',
      body: {'status': 'completed'},
    );
    return BeanTask.fromJson(_expectMap(data['data']));
  }

  Future<BeanTask> reopenTask(int taskId) async {
    final data = await _sendJson(
      'PATCH',
      '/tasks/$taskId',
      body: {'status': 'open', 'completed_at': null},
    );
    return BeanTask.fromJson(_expectMap(data['data']));
  }

  Future<List<BeanReminder>> listReminders() async {
    final data = await _sendJson('GET', '/reminders');
    return _expectList(
      data['data'],
    ).map((json) => BeanReminder.fromJson(_expectMap(json))).toList();
  }

  Future<BeanReminder> createReminder({
    required String title,
    required String remindAt,
    String status = 'scheduled',
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
    return BeanReminder.fromJson(_expectMap(data['data']));
  }

  Future<BeanReminder> updateReminder(
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
    return BeanReminder.fromJson(_expectMap(data['data']));
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

  Future<List<BeanCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
    final data = await _sendJson(
      'GET',
      _pathWithQuery('/calendar-events', {
        if (skipExternalSync) 'skip_google_sync': '1',
        if (skipExternalSync) 'skip_outlook_sync': '1',
      }),
    );
    return _expectList(
      data['data'],
    ).map((json) => BeanCalendarEvent.fromJson(_expectMap(json))).toList();
  }

  Future<BeanCalendarEvent> createCalendarEvent({
    required String title,
    required String startsAt,
    required bool allDay,
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
        'all_day': allDay,
        'ends_at': endsAt,
        'description': notes,
        if (location != null) 'location': location,
        'status': status ?? 'scheduled',
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
    return BeanCalendarEvent.fromJson(_expectMap(data['data']));
  }

  Future<GoogleCalendarSyncStatus> googleCalendarStatus() async {
    final data = await _sendJson('GET', '/google-calendar/status');
    return GoogleCalendarSyncStatus.fromJson(_expectMap(data['data']));
  }

  Future<GoogleCalendarSyncStatus> outlookCalendarStatus() async {
    final data = await _sendJson('GET', '/outlook-calendar/status');
    return GoogleCalendarSyncStatus.fromJson(_expectMap(data['data']));
  }

  Future<String> googleCalendarAuthUrl() async {
    final data = await _sendJson('POST', '/google-calendar/auth-url');
    return _expectString(_expectMap(data['data'])['auth_url']);
  }

  Future<String> outlookCalendarAuthUrl() async {
    final data = await _sendJson('POST', '/outlook-calendar/auth-url');
    return _expectString(_expectMap(data['data'])['auth_url']);
  }

  Future<GoogleCalendarSyncResult> syncGoogleCalendar() async {
    final data = await _sendJson('POST', '/google-calendar/sync');
    return GoogleCalendarSyncResult.fromJson(_expectMap(data['data']));
  }

  Future<GoogleCalendarSyncResult> syncOutlookCalendar() async {
    final data = await _sendJson('POST', '/outlook-calendar/sync');
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

  Future<GoogleCalendarSyncStatus> updateOutlookCalendarSelection({
    required List<String> selectedCalendarIds,
    String? defaultCalendarId,
  }) async {
    final data = await _sendJson(
      'PATCH',
      '/outlook-calendar/calendars',
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

  Future<GoogleCalendarSyncStatus> disconnectOutlookCalendar() async {
    final data = await _sendJson('DELETE', '/outlook-calendar');
    return GoogleCalendarSyncStatus.fromJson(_expectMap(data['data']));
  }

  Future<List<ExternalCalendarProviderPreset>>
  listExternalCalendarProviders() async {
    try {
      final data = await _sendJson('GET', '/external-calendars/providers');
      return _expectList(data['data'])
          .map(
            (json) => ExternalCalendarProviderPreset.fromJson(_expectMap(json)),
          )
          .toList();
    } on BeanApiException catch (error) {
      if (error.statusCode == 401 || error.statusCode == 403) {
        rethrow;
      }
      return ExternalCalendarProviderPreset.defaults;
    } on FormatException {
      return ExternalCalendarProviderPreset.defaults;
    }
  }

  Future<ExternalCalendarImportResult> importExternalCalendar({
    required String providerKey,
    required String url,
    int? workspaceId,
  }) async {
    final data = await _sendJson(
      'POST',
      '/external-calendars/import',
      body: {
        'provider_key': providerKey,
        'url': url,
        if (workspaceId != null) 'workspace_id': workspaceId,
      },
    );
    return ExternalCalendarImportResult.fromJson(_expectMap(data['data']));
  }

  Future<ExternalCalendarImportResult> importAppleCalendar({
    required String url,
    int? workspaceId,
  }) async {
    return importExternalCalendar(
      providerKey: 'apple',
      url: url,
      workspaceId: workspaceId,
    );
  }

  Future<List<BeanEventCategory>> listEventCategories() async {
    final data = await _sendJson('GET', '/event-categories');
    return _expectList(
      data['data'],
    ).map((json) => BeanEventCategory.fromJson(_expectMap(json))).toList();
  }

  Future<BeanEventCategory> createEventCategory({
    required String name,
    required String color,
  }) async {
    final data = await _sendJson(
      'POST',
      '/event-categories',
      body: {'name': name, 'color': color},
    );
    return BeanEventCategory.fromJson(_expectMap(data['data']));
  }

  Future<BeanEventCategory> updateEventCategory(
    int categoryId, {
    required String name,
    required String color,
  }) async {
    final data = await _sendJson(
      'PATCH',
      '/event-categories/$categoryId',
      body: {'name': name, 'color': color},
    );
    return BeanEventCategory.fromJson(_expectMap(data['data']));
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

  Future<BeanCalendarEvent> updateCalendarEvent(
    int eventId, {
    required String title,
    required String startsAt,
    required bool allDay,
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
        'all_day': allDay,
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
    return BeanCalendarEvent.fromJson(_expectMap(data['data']));
  }

  Future<BeanReminder> createEventReminder({
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
      'status': 'scheduled',
      if (workspaceId != null) 'workspace_id': workspaceId,
      if (syncToWorkspaceIds.isNotEmpty)
        'sync_to_workspace_ids': syncToWorkspaceIds,
    };
    if (metadata != null) body['metadata'] = metadata;
    final data = await _sendJson('POST', '/reminders', body: body);
    return BeanReminder.fromJson(_expectMap(data['data']));
  }

  Future<BeanAssistantTurn> sendBeanMessage({
    required String content,
    int? sessionId,
    int? workspaceId,
  }) async {
    final data = await _sendJson(
      'POST',
      '/bean/messages',
      body: {
        'content': content,
        if (sessionId != null) 'session_id': sessionId,
        if (workspaceId != null) 'workspace_id': workspaceId,
      },
    );
    return BeanAssistantTurn.fromJson(_expectMap(data['data']));
  }

  Future<BeanRealtimeSession> createBeanRealtimeSession() async {
    final data = await _sendJson('POST', '/bean/realtime/session');
    return BeanRealtimeSession.fromJson(data);
  }

  BeanAuthResult _rememberAuth(BeanAuthResult result) {
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
    final request = BeanApiRequest(
      method: method,
      uri: _resolveApiPath(path),
      path: path.startsWith('/') ? path : '/$path',
      headers: headers,
      body: body,
      responseTimeout: responseTimeout,
    );
    final response = await _transport(request);

    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw BeanApiException(response.statusCode, response.body);
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

  static Future<BeanApiResponse> _defaultTransport(
    BeanApiRequest request,
  ) async {
    final client = HttpClient()
      ..connectionTimeout = const Duration(seconds: 15);
    try {
      final ioRequest = await client
          .openUrl(request.method, request.uri)
          .timeout(const Duration(seconds: 15));
      request.headers.forEach(ioRequest.headers.set);
      if (request.body != null) {
        ioRequest.add(utf8.encode(jsonEncode(request.body)));
      }

      final ioResponse = await ioRequest.close().timeout(
        request.responseTimeout,
      );
      final responseBody = await utf8.decoder
          .bind(ioResponse)
          .join()
          .timeout(request.responseTimeout);
      return BeanApiResponse(ioResponse.statusCode, responseBody);
    } on TimeoutException catch (error) {
      throw BeanApiException(
        408,
        "Request timed out: ${error.message ?? 'network timeout'}",
      );
    } finally {
      client.close(force: true);
    }
  }
}

class BeanApiRequest {
  const BeanApiRequest({
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

class BeanApiResponse {
  const BeanApiResponse(this.statusCode, this.body);

  final int statusCode;
  final String body;
}

class BeanApiException implements Exception {
  const BeanApiException(this.statusCode, this.body);

  final int statusCode;
  final String body;

  @override
  String toString() => 'BeanApiException(statusCode: $statusCode)';
}

class BeanAssistantTurn {
  const BeanAssistantTurn({
    required this.session,
    required this.run,
    this.messages = const [],
    this.confirmations = const [],
  });

  final BeanAssistantSession session;
  final BeanAssistantRun run;
  final List<BeanAssistantMessage> messages;
  final List<BeanAssistantConfirmation> confirmations;

  factory BeanAssistantTurn.fromJson(Map<String, Object?> json) =>
      BeanAssistantTurn(
        session: BeanAssistantSession.fromJson(_expectMap(json['session'])),
        run: BeanAssistantRun.fromJson(_expectMap(json['run'])),
        messages: _expectList(json['messages'] ?? const [])
            .map((value) => BeanAssistantMessage.fromJson(_expectMap(value)))
            .toList(),
        confirmations: _expectList(json['confirmations'] ?? const [])
            .map(
              (value) => BeanAssistantConfirmation.fromJson(_expectMap(value)),
            )
            .toList(),
      );
}

class BeanAssistantSession {
  const BeanAssistantSession({required this.id});

  final int id;

  factory BeanAssistantSession.fromJson(Map<String, Object?> json) =>
      BeanAssistantSession(id: _expectInt(json['id']));
}

class BeanAssistantRun {
  const BeanAssistantRun({required this.id, required this.status, this.model});

  final int id;
  final String status;
  final String? model;

  factory BeanAssistantRun.fromJson(Map<String, Object?> json) =>
      BeanAssistantRun(
        id: _expectInt(json['id']),
        status: _expectString(json['status']),
        model: _readString(json['model']),
      );
}

class BeanAssistantMessage {
  const BeanAssistantMessage({
    this.id,
    required this.role,
    required this.content,
  });

  final int? id;
  final String role;
  final String content;

  factory BeanAssistantMessage.fromJson(Map<String, Object?> json) =>
      BeanAssistantMessage(
        id: _readIntOrNull(json['id']),
        role: _expectString(json['role']),
        content: _expectString(json['content']),
      );
}

class BeanAssistantConfirmation {
  const BeanAssistantConfirmation({
    required this.id,
    required this.action,
    required this.status,
    this.summary,
  });

  final int id;
  final String action;
  final String status;
  final String? summary;

  factory BeanAssistantConfirmation.fromJson(Map<String, Object?> json) =>
      BeanAssistantConfirmation(
        id: _expectInt(json['id']),
        action: _expectString(json['action']),
        status: _expectString(json['status']),
        summary: _readString(json['summary']),
      );
}

class BeanRealtimeSession {
  const BeanRealtimeSession({
    this.clientSecret,
    this.expiresAt,
    this.model,
    this.voice,
  });

  final String? clientSecret;
  final int? expiresAt;
  final String? model;
  final String? voice;

  factory BeanRealtimeSession.fromJson(Map<String, Object?> json) {
    final secret = _expectMapOrNull(json['client_secret']);
    return BeanRealtimeSession(
      clientSecret: _readString(secret?['value']),
      expiresAt: _readIntOrNull(secret?['expires_at']),
      model: _readString(json['model']),
      voice: _readString(json['voice']),
    );
  }
}

class BeanEmailAvailability {
  const BeanEmailAvailability({required this.email, required this.available});

  final String email;
  final bool available;

  factory BeanEmailAvailability.fromJson(Map<String, Object?> json) =>
      BeanEmailAvailability(
        email: _expectString(json['email']),
        available: json['available'] == true,
      );
}

class BeanAuthResult {
  const BeanAuthResult({required this.token, required this.user});

  final String token;
  final BeanUser user;

  factory BeanAuthResult.fromJson(Map<String, Object?> json) => BeanAuthResult(
    token: _expectString(json['token']),
    user: BeanUser.fromJson(_expectMap(json['user'])),
  );
}

class BeanCheckoutSession {
  const BeanCheckoutSession({
    required this.id,
    required this.url,
    required this.plan,
    this.billingInterval = 'monthly',
    this.status,
  });

  final String id;
  final String url;
  final String plan;
  final String billingInterval;
  final String? status;

  factory BeanCheckoutSession.fromJson(Map<String, Object?> json) =>
      BeanCheckoutSession(
        id: _expectString(json['id']),
        url: _expectString(json['url']),
        plan: _expectString(json['plan']),
        billingInterval: _readStringOrDefault(
          json['billing_interval'] ?? json['billingInterval'],
          'monthly',
        ),
        status: json['status']?.toString(),
      );
}

class BeanPaymentSheetSetup {
  const BeanPaymentSheetSetup({
    required this.publishableKey,
    required this.customerId,
    required this.customerEphemeralKeySecret,
    required this.setupIntentId,
    required this.setupIntentClientSecret,
    this.plan,
    this.billingInterval = 'monthly',
  });

  final String publishableKey;
  final String customerId;
  final String customerEphemeralKeySecret;
  final String setupIntentId;
  final String setupIntentClientSecret;
  final String? plan;
  final String billingInterval;

  factory BeanPaymentSheetSetup.fromJson(Map<String, Object?> json) =>
      BeanPaymentSheetSetup(
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
        billingInterval: _readStringOrDefault(
          json['billing_interval'] ?? json['billingInterval'],
          'monthly',
        ),
      );
}

class BeanSubscriptionSummary {
  const BeanSubscriptionSummary({
    required this.tier,
    this.billingInterval = 'monthly',
    this.status,
    this.currentPeriodEnd,
    this.baseCompExpiresAt,
    this.trialEndsAt,
    this.accessEndsAt,
    this.cancelAtPeriodEnd = false,
    this.canUpgrade = false,
    this.canCancel = false,
    this.canResume = false,
  });

  final String tier;
  final String billingInterval;
  final String? status;
  final String? currentPeriodEnd;
  final String? baseCompExpiresAt;
  final String? trialEndsAt;
  final String? accessEndsAt;
  final bool cancelAtPeriodEnd;
  final bool canUpgrade;
  final bool canCancel;
  final bool canResume;

  factory BeanSubscriptionSummary.fromJson(
    Map<String, Object?> json,
  ) => BeanSubscriptionSummary(
    tier: _readStringOrDefault(json['tier'], 'base'),
    billingInterval: _readStringOrDefault(
      json['billing_interval'] ?? json['billingInterval'],
      'monthly',
    ),
    status: json['status']?.toString(),
    currentPeriodEnd: json['current_period_end']?.toString(),
    baseCompExpiresAt:
        (json['base_comp_expires_at'] ?? json['baseCompExpiresAt'])?.toString(),
    trialEndsAt: json['trial_ends_at']?.toString(),
    accessEndsAt: (json['access_ends_at'] ?? json['accessEndsAt'])?.toString(),
    cancelAtPeriodEnd: _readBool(json['cancel_at_period_end'] ?? false),
    canUpgrade: _readBool(json['can_upgrade'] ?? false),
    canCancel: _readBool(json['can_cancel'] ?? json['canCancel'] ?? false),
    canResume: _readBool(json['can_resume'] ?? json['canResume'] ?? false),
  );
}

class BeanCouponRedemptionResult {
  const BeanCouponRedemptionResult({required this.subscription, this.coupon});

  final BeanSubscriptionSummary subscription;
  final BeanCouponCode? coupon;

  factory BeanCouponRedemptionResult.fromJson(Map<String, Object?> json) =>
      BeanCouponRedemptionResult(
        subscription: BeanSubscriptionSummary.fromJson(
          _expectMap(json['subscription']),
        ),
        coupon: json['coupon'] is Map<String, Object?>
            ? BeanCouponCode.fromJson(_expectMap(json['coupon']))
            : null,
      );
}

class BeanCouponCode {
  const BeanCouponCode({
    required this.code,
    required this.monthsFreeBase,
    this.used = false,
    this.baseAccessExpiresAt,
  });

  final String code;
  final int monthsFreeBase;
  final bool used;
  final String? baseAccessExpiresAt;

  factory BeanCouponCode.fromJson(Map<String, Object?> json) => BeanCouponCode(
    code: _expectString(json['code']),
    monthsFreeBase: _expectInt(json['months_free_base']),
    used: _readBool(json['used'] ?? false),
    baseAccessExpiresAt:
        (json['base_access_expires_at'] ?? json['baseAccessExpiresAt'])
            ?.toString(),
  );
}

class BeanBillingPaymentMethod {
  const BeanBillingPaymentMethod({
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
    final rawBrand = brand?.trim();
    final source = rawBrand == null || rawBrand.isEmpty
        ? (type == 'us_bank_account' ? 'Bank account' : type)
        : rawBrand;
    final normalized = source.replaceAll('_', ' ').trim();
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

  factory BeanBillingPaymentMethod.fromJson(Map<String, Object?> json) =>
      BeanBillingPaymentMethod(
        id: json['id']?.toString(),
        type: _readStringOrDefault(json['type'], 'card'),
        brand: (json['brand'] ?? json['bank_name'] ?? json['bankName'])
            ?.toString(),
        last4: json['last4']?.toString(),
        expMonth: _readIntOrNull(json['exp_month'] ?? json['expMonth']),
        expYear: _readIntOrNull(json['exp_year'] ?? json['expYear']),
      );
}

class BeanSubscriptionResult {
  const BeanSubscriptionResult({
    required this.subscription,
    this.plan,
    this.billingInterval = 'monthly',
    this.paymentMethod,
  });

  final BeanSubscriptionSummary subscription;
  final String? plan;
  final String billingInterval;
  final BeanBillingPaymentMethod? paymentMethod;

  factory BeanSubscriptionResult.fromJson(Map<String, Object?> json) =>
      BeanSubscriptionResult(
        subscription: BeanSubscriptionSummary.fromJson(
          _expectMap(json['subscription']),
        ),
        plan: json['plan']?.toString(),
        billingInterval: _readStringOrDefault(
          json['billing_interval'] ?? json['billingInterval'],
          'monthly',
        ),
        paymentMethod: json['payment_method'] is Map<String, Object?>
            ? BeanBillingPaymentMethod.fromJson(
                _expectMap(json['payment_method']),
              )
            : null,
      );
}

class BeanDashboardChangeFeed {
  const BeanDashboardChangeFeed({
    required this.changes,
    required this.latestId,
  });

  final List<BeanDashboardChange> changes;
  final int latestId;

  factory BeanDashboardChangeFeed.fromJson(Map<String, Object?> json) =>
      BeanDashboardChangeFeed(
        changes: _expectList(json['changes'] ?? const [])
            .map((change) => BeanDashboardChange.fromJson(_expectMap(change)))
            .toList(),
        latestId: _readIntOrNull(json['latest_id'] ?? json['latestId']) ?? 0,
      );
}

class BeanDashboardChange {
  const BeanDashboardChange({
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

  factory BeanDashboardChange.fromJson(
    Map<String, Object?> json,
  ) => BeanDashboardChange(
    id: _expectInt(json['id']),
    userId: _readIntOrNull(json['user_id'] ?? json['userId']),
    workspaceId: _readIntOrNull(json['workspace_id'] ?? json['workspaceId']),
    resourceType: _expectString(json['resource_type'] ?? json['resourceType']),
    action: _expectString(json['action']),
    resourceId: _readIntOrNull(json['resource_id'] ?? json['resourceId']),
    payload: _expectMapOrNull(json['payload']) ?? const {},
  );
}

class BeanNotificationPreferences {
  const BeanNotificationPreferences({
    this.reminderPush = true,
    this.reminderEmail = true,
  });

  final bool reminderPush;
  final bool reminderEmail;

  Map<String, Object?> toJson() => {
    'reminder_push': reminderPush,
    'reminder_email': reminderEmail,
  };

  factory BeanNotificationPreferences.fromJson(Map<String, Object?>? json) =>
      BeanNotificationPreferences(
        reminderPush: _readBool(
          json?['reminder_push'] ?? json?['reminderPush'] ?? true,
        ),
        reminderEmail: _readBool(
          json?['reminder_email'] ?? json?['reminderEmail'] ?? true,
        ),
      );

  BeanNotificationPreferences copyWith({
    bool? reminderPush,
    bool? reminderEmail,
  }) => BeanNotificationPreferences(
    reminderPush: reminderPush ?? this.reminderPush,
    reminderEmail: reminderEmail ?? this.reminderEmail,
  );
}

class BeanPlaceSuggestion {
  const BeanPlaceSuggestion({
    required this.placeId,
    required this.primaryText,
    this.secondaryText,
    this.fullText,
  });

  final String placeId;
  final String primaryText;
  final String? secondaryText;
  final String? fullText;

  factory BeanPlaceSuggestion.fromJson(Map<String, Object?> json) =>
      BeanPlaceSuggestion(
        placeId: _expectString(json['place_id'] ?? json['placeId']),
        primaryText: _readStringOrDefault(
          json['primary_text'] ?? json['primaryText'],
          '',
        ),
        secondaryText: (json['secondary_text'] ?? json['secondaryText'])
            ?.toString(),
        fullText: (json['full_text'] ?? json['fullText'])?.toString(),
      );
}

class BeanPlaceDetails {
  const BeanPlaceDetails({
    required this.placeId,
    this.name,
    this.formattedAddress,
    this.latitude,
    this.longitude,
    this.googleMapsUri,
  });

  final String placeId;
  final String? name;
  final String? formattedAddress;
  final double? latitude;
  final double? longitude;
  final String? googleMapsUri;

  String get displayAddress => (formattedAddress?.trim().isNotEmpty ?? false)
      ? formattedAddress!.trim()
      : (name?.trim().isNotEmpty ?? false)
      ? name!.trim()
      : '';

  factory BeanPlaceDetails.fromJson(Map<String, Object?> json) =>
      BeanPlaceDetails(
        placeId: _expectString(json['place_id'] ?? json['placeId']),
        name: json['name']?.toString(),
        formattedAddress:
            (json['formatted_address'] ?? json['formattedAddress'])?.toString(),
        latitude: _readDoubleOrNull(json['latitude']),
        longitude: _readDoubleOrNull(json['longitude']),
        googleMapsUri: (json['google_maps_uri'] ?? json['googleMapsUri'])
            ?.toString(),
      );
}

class BeanUser {
  const BeanUser({
    required this.id,
    required this.name,
    required this.email,
    this.subscriptionTier = 'base',
    this.subscriptionStatus,
    this.subscriptionTrialEndsAt,
    this.baseCompExpiresAt,
    this.theme = 'green',
    this.themeMode = 'auto',
    this.commandCenterLabel = 'Command Center',
    this.preferredMapApp = 'google',
    this.defaultWorkspaceId,
    this.personalWorkspace,
    this.activeWorkspace,
    this.workspaces = const [],
    this.isBeta = false,
    this.isAdmin = false,
    this.notificationPreferences = const BeanNotificationPreferences(),
    this.planLimits = const BeanPlanLimits(),
  });

  final int id;
  final String name;
  final String email;
  final String subscriptionTier;
  final String? subscriptionStatus;
  final String? subscriptionTrialEndsAt;
  final String? baseCompExpiresAt;
  final String theme;
  final String themeMode;
  final String commandCenterLabel;
  final String preferredMapApp;
  final int? defaultWorkspaceId;
  final BeanWorkspace? personalWorkspace;
  final BeanWorkspace? activeWorkspace;
  final List<BeanWorkspace> workspaces;
  final bool isBeta;
  final bool isAdmin;
  final BeanNotificationPreferences notificationPreferences;
  final BeanPlanLimits planLimits;

  BeanUser copyWith({
    String? name,
    String? email,
    String? subscriptionTier,
    String? subscriptionStatus,
    String? subscriptionTrialEndsAt,
    String? baseCompExpiresAt,
    String? theme,
    String? themeMode,
    String? commandCenterLabel,
    String? preferredMapApp,
    int? defaultWorkspaceId,
    BeanWorkspace? personalWorkspace,
    BeanWorkspace? activeWorkspace,
    List<BeanWorkspace>? workspaces,
    bool? isBeta,
    bool? isAdmin,
    BeanNotificationPreferences? notificationPreferences,
    BeanPlanLimits? planLimits,
  }) => BeanUser(
    id: id,
    name: name ?? this.name,
    email: email ?? this.email,
    subscriptionTier: subscriptionTier ?? this.subscriptionTier,
    subscriptionStatus: subscriptionStatus ?? this.subscriptionStatus,
    subscriptionTrialEndsAt:
        subscriptionTrialEndsAt ?? this.subscriptionTrialEndsAt,
    baseCompExpiresAt: baseCompExpiresAt ?? this.baseCompExpiresAt,
    theme: theme ?? this.theme,
    themeMode: themeMode ?? this.themeMode,
    commandCenterLabel: commandCenterLabel ?? this.commandCenterLabel,
    preferredMapApp: preferredMapApp ?? this.preferredMapApp,
    defaultWorkspaceId: defaultWorkspaceId ?? this.defaultWorkspaceId,
    personalWorkspace: personalWorkspace ?? this.personalWorkspace,
    activeWorkspace: activeWorkspace ?? this.activeWorkspace,
    workspaces: workspaces ?? this.workspaces,
    isBeta: isBeta ?? this.isBeta,
    isAdmin: isAdmin ?? this.isAdmin,
    notificationPreferences:
        notificationPreferences ?? this.notificationPreferences,
    planLimits: planLimits ?? this.planLimits,
  );

  factory BeanUser.fromJson(Map<String, Object?> json) => BeanUser(
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
    baseCompExpiresAt:
        (json['base_comp_expires_at'] ?? json['baseCompExpiresAt'])?.toString(),
    theme: _readStringOrDefault(json['theme'], 'green'),
    themeMode: _readStringOrDefault(
      json['theme_mode'] ?? json['themeMode'],
      'auto',
    ),
    commandCenterLabel: _readStringOrDefault(
      json['command_center_label'] ?? json['commandCenterLabel'],
      'Command Center',
    ),
    preferredMapApp: _readStringOrDefault(
      json['preferred_map_app'] ?? json['preferredMapApp'],
      'google',
    ),
    defaultWorkspaceId: _readIntOrNull(json['default_workspace_id']),
    personalWorkspace: json['personal_workspace'] is Map<String, Object?>
        ? BeanWorkspace.fromJson(_expectMap(json['personal_workspace']))
        : null,
    activeWorkspace: json['active_workspace'] is Map<String, Object?>
        ? BeanWorkspace.fromJson(_expectMap(json['active_workspace']))
        : null,
    workspaces: _expectList(json['workspaces'] ?? const [])
        .map((workspace) => BeanWorkspace.fromJson(_expectMap(workspace)))
        .toList(),
    isBeta:
        json['is_beta'] == true ||
        json['isBeta'] == true ||
        json['beta_user'] != null ||
        json['betaUser'] != null,
    isAdmin: json['is_admin'] == true || json['isAdmin'] == true,
    notificationPreferences: BeanNotificationPreferences.fromJson(
      _expectMapOrNull(json['notification_preferences']),
    ),
    planLimits: BeanPlanLimits.fromJson(_expectMapOrNull(json['plan_limits'])),
  );
}

class BeanPlanLimits {
  const BeanPlanLimits({
    this.tier = 'base',
    this.workspaceLimit,
    this.calendarConnectionLimit,
    this.connectedAccountLimit,
    this.historyDays,
    this.historyCutoff,
    this.noteLimit,
    this.recurringTasksEnabled = false,
    this.recurringRemindersEnabled = false,
    this.recurringCalendarEnabled = false,
    this.emailRemindersEnabled = false,
    this.notesEnabled = false,
  });

  final String tier;
  final int? workspaceLimit;
  final int? calendarConnectionLimit;
  final int? connectedAccountLimit;
  final int? historyDays;
  final String? historyCutoff;
  final int? noteLimit;
  final bool recurringTasksEnabled;
  final bool recurringRemindersEnabled;
  final bool recurringCalendarEnabled;
  final bool emailRemindersEnabled;
  final bool notesEnabled;

  factory BeanPlanLimits.fromJson(Map<String, Object?>? json) {
    if (json == null) return const BeanPlanLimits();
    return BeanPlanLimits(
      tier: _readStringOrDefault(json['tier'], 'base'),
      workspaceLimit: _readIntOrNull(
        json['workspace_limit'] ?? json['workspaceLimit'],
      ),
      calendarConnectionLimit: _readIntOrNull(
        json['calendar_connection_limit'] ?? json['calendarConnectionLimit'],
      ),
      connectedAccountLimit: _readIntOrNull(
        json['connected_account_limit'] ?? json['connectedAccountLimit'],
      ),
      historyDays: _readIntOrNull(json['history_days'] ?? json['historyDays']),
      historyCutoff: (json['history_cutoff'] ?? json['historyCutoff'])
          ?.toString(),
      noteLimit: _readIntOrNull(json['note_limit'] ?? json['noteLimit']),
      recurringTasksEnabled: _readBool(
        json['recurring_tasks_enabled'] ?? json['recurringTasksEnabled'],
      ),
      recurringRemindersEnabled: _readBool(
        json['recurring_reminders_enabled'] ??
            json['recurringRemindersEnabled'],
      ),
      recurringCalendarEnabled: _readBool(
        json['recurring_calendar_enabled'] ?? json['recurringCalendarEnabled'],
      ),
      emailRemindersEnabled: _readBool(
        json['email_reminders_enabled'] ?? json['emailRemindersEnabled'],
      ),
      notesEnabled: _readBool(json['notes_enabled'] ?? json['notesEnabled']),
    );
  }
}

class BeanWorkspace {
  const BeanWorkspace({
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
  final List<BeanWorkspaceMembership> memberships;
  final List<BeanWorkspaceMemberUser> members;
  final List<Map<String, Object?>> googleCalendarMappings;

  int? get numericId => _readIntOrNull(id);
  bool get isPersonal => type == 'personal' || id == 'personal';
  bool get canManageMembers => role == 'owner';

  BeanWorkspace copyWith({
    String? name,
    String? role,
    bool? active,
    bool? isDefault,
    String? googleCalendarId,
    List<BeanWorkspaceMembership>? memberships,
    List<BeanWorkspaceMemberUser>? members,
  }) => BeanWorkspace(
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
    googleCalendarMappings: googleCalendarMappings,
  );

  factory BeanWorkspace.fromJson(Map<String, Object?> json) {
    final rawId = json['id'] ?? json['workspace_id'] ?? 'personal';
    final parsedMemberships = _expectList(json['memberships'] ?? const [])
        .map(
          (membership) =>
              BeanWorkspaceMembership.fromJson(_expectMap(membership)),
        )
        .toList();
    final parsedMembers = _expectList(json['members'] ?? const [])
        .map((member) => BeanWorkspaceMemberUser.fromJson(_expectMap(member)))
        .toList();
    return BeanWorkspace(
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
      googleCalendarMappings:
          _expectList(json['google_calendar_mappings'] ?? const [])
              .map((mapping) => Map<String, Object?>.from(_expectMap(mapping)))
              .toList(),
    );
  }
}

class BeanWorkspaceMembership {
  const BeanWorkspaceMembership({
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
  final BeanWorkspaceMemberUser? user;
  final BeanWorkspace? workspace;

  factory BeanWorkspaceMembership.fromJson(Map<String, Object?> json) =>
      BeanWorkspaceMembership(
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
            ? BeanWorkspaceMemberUser.fromJson(_expectMap(json['user']))
            : null,
        workspace: json['workspace'] is Map<String, Object?>
            ? BeanWorkspace.fromJson(_expectMap(json['workspace']))
            : null,
      );
}

class BeanWorkspaceMemberUser {
  const BeanWorkspaceMemberUser({
    required this.id,
    required this.name,
    required this.email,
  });

  final int id;
  final String name;
  final String email;

  factory BeanWorkspaceMemberUser.fromJson(Map<String, Object?> json) =>
      BeanWorkspaceMemberUser(
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

class ExternalCalendarProviderPreset {
  const ExternalCalendarProviderPreset({
    required this.key,
    required this.label,
    required this.description,
    required this.linkLabel,
    required this.linkHint,
    this.instructions = const [],
  });

  final String key;
  final String label;
  final String description;
  final String linkLabel;
  final String linkHint;
  final List<String> instructions;

  static const defaults = [
    ExternalCalendarProviderPreset(
      key: 'apple',
      label: 'Apple Calendar',
      description: 'Paste an iCloud public calendar link from Apple Calendar.',
      linkLabel: 'iCloud public calendar link',
      linkHint: 'webcal://pXX-caldav.icloud.com/published/2/...',
      instructions: [
        'In Apple Calendar or iCloud.com, turn on Public Calendar for the calendar you want.',
        'Copy the generated webcal link.',
        'Paste the link here to import events into this workspace.',
      ],
    ),
    ExternalCalendarProviderPreset(
      key: 'google',
      label: 'Google Calendar',
      description: 'Paste a Google secret iCal address for a one-time import.',
      linkLabel: 'Google secret iCal address',
      linkHint: 'https://calendar.google.com/calendar/ical/...',
      instructions: [
        'Open Google Calendar settings for the calendar.',
        'Copy the Secret address in iCal format.',
        'Paste it here for a one-time import. Use Google sync for ongoing connected sync.',
      ],
    ),
    ExternalCalendarProviderPreset(
      key: 'outlook',
      label: 'Outlook Calendar',
      description: 'Paste an Outlook published ICS link for a one-time import.',
      linkLabel: 'Outlook published ICS link',
      linkHint: 'https://outlook.live.com/owa/calendar/.../calendar.ics',
      instructions: [
        'Publish the Outlook calendar as an ICS link.',
        'Copy the ICS link.',
        'Paste it here for a one-time import. Use Outlook sync for ongoing connected sync.',
      ],
    ),
    ExternalCalendarProviderPreset(
      key: 'proton',
      label: 'Proton Calendar',
      description:
          'Paste a Proton share link for calendars shared with anyone.',
      linkLabel: 'Proton calendar share link',
      linkHint: 'https://calendar.proton.me/api/calendar/v1/url/...',
      instructions: [
        'In Proton Calendar, share the calendar with anyone.',
        'Copy the generated calendar link.',
        'Paste it here to import the visible events.',
      ],
    ),
    ExternalCalendarProviderPreset(
      key: 'yahoo',
      label: 'Yahoo Calendar',
      description: 'Paste a Yahoo iCal link or exported calendar URL.',
      linkLabel: 'Yahoo iCal link',
      linkHint: 'https://calendar.yahoo.com/.../calendar.ics',
      instructions: [
        'Open Yahoo Calendar settings for the calendar.',
        'Copy the iCal link or export link.',
        'Paste it here to import the events.',
      ],
    ),
    ExternalCalendarProviderPreset(
      key: 'fastmail',
      label: 'Fastmail',
      description: 'Paste a Fastmail calendar sharing link.',
      linkLabel: 'Fastmail calendar link',
      linkHint: 'https://calendar.fastmail.com/.../calendar.ics',
      instructions: [
        'Share or publish the Fastmail calendar to get an iCalendar link.',
        'Copy the generated link.',
        'Paste it here for a one-time import.',
      ],
    ),
    ExternalCalendarProviderPreset(
      key: 'nextcloud',
      label: 'Nextcloud',
      description:
          'Paste a public Nextcloud/ownCloud calendar subscription link.',
      linkLabel: 'Nextcloud public calendar link',
      linkHint: 'https://cloud.example.com/remote.php/dav/public-calendars/...',
      instructions: [
        'In Nextcloud Calendar, copy the public subscription link.',
        'Use the webcal or download link for the calendar.',
        'Paste it here to import events into HeyBean.',
      ],
    ),
    ExternalCalendarProviderPreset(
      key: 'ics',
      label: 'Other iCal link',
      description: 'Use any public .ics or webcal calendar URL.',
      linkLabel: 'Public iCal or webcal link',
      linkHint: 'webcal://example.com/calendar.ics',
      instructions: [
        'Copy a public .ics, iCal, or webcal calendar URL.',
        'Paste it here to import events.',
        'Only use links you trust and intend to import into this workspace.',
      ],
    ),
  ];

  factory ExternalCalendarProviderPreset.fromJson(Map<String, Object?> json) =>
      ExternalCalendarProviderPreset(
        key: _expectString(json['key']),
        label: _expectString(json['label']),
        description: _readStringOrDefault(json['description'], ''),
        linkLabel: _readStringOrDefault(
          json['link_label'] ?? json['linkLabel'],
          'Calendar link',
        ),
        linkHint: _readStringOrDefault(
          json['link_hint'] ?? json['linkHint'],
          'webcal://example.com/calendar.ics',
        ),
        instructions: _expectList(
          json['instructions'] ?? const [],
        ).map((item) => item.toString()).toList(),
      );
}

class ExternalCalendarImportResult {
  const ExternalCalendarImportResult({
    required this.imported,
    required this.updated,
    required this.deleted,
    required this.skipped,
    required this.total,
    this.workspaceId,
    this.providerKey,
    this.providerLabel,
  });

  final int imported;
  final int updated;
  final int deleted;
  final int skipped;
  final int total;
  final int? workspaceId;
  final String? providerKey;
  final String? providerLabel;

  int get changed => imported + updated + deleted;

  factory ExternalCalendarImportResult.fromJson(Map<String, Object?> json) =>
      ExternalCalendarImportResult(
        imported: _expectInt(json['imported']),
        updated: _expectInt(json['updated']),
        deleted: _expectInt(json['deleted']),
        skipped: _expectInt(json['skipped']),
        total: _expectInt(json['total']),
        workspaceId: _readIntOrNull(json['workspace_id']),
        providerKey: json['provider_key']?.toString(),
        providerLabel: json['provider_label']?.toString(),
      );
}

typedef AppleCalendarImportResult = ExternalCalendarImportResult;

class BeanNoteFolder {
  const BeanNoteFolder({required this.id, required this.name, this.sortOrder});

  final int id;
  final String name;
  final int? sortOrder;

  factory BeanNoteFolder.fromJson(Map<String, Object?> json) => BeanNoteFolder(
    id: _readIntOrNull(json['id']) ?? 0,
    name: _readString(json['name']) ?? 'Notes',
    sortOrder: _readIntOrNull(json['sort_order'] ?? json['sortOrder']),
  );
}

class BeanNote {
  const BeanNote({
    required this.id,
    required this.title,
    this.bodyHtml,
    this.plainText,
    this.folderId,
    this.isPinned = false,
    this.updatedAt,
    this.metadata = const {},
    this.workspaceId,
    this.linkedWorkspaceIds = const [],
  });

  final int id;
  final String title;
  final String? bodyHtml;
  final String? plainText;
  final int? folderId;
  final bool isPinned;
  final String? updatedAt;
  final Map<String, Object?> metadata;
  final int? workspaceId;
  final List<int> linkedWorkspaceIds;

  factory BeanNote.fromJson(Map<String, Object?> json) => BeanNote(
    id: _readIntOrNull(json['id']) ?? 0,
    title: _readVisibleString(json['title']) ?? 'New Note',
    bodyHtml: _readVisibleString(json['body_html'] ?? json['bodyHtml']),
    plainText: _readVisibleString(json['plain_text'] ?? json['plainText']),
    folderId: _readIntOrNull(
      json['note_folder_id'] ?? json['noteFolderId'] ?? json['folder_id'],
    ),
    isPinned:
        json['is_pinned'] == true ||
        json['isPinned'] == true ||
        json['pinned'] == true,
    updatedAt: _readString(json['updated_at'] ?? json['updatedAt']),
    metadata: _expectMapOrNull(json['metadata']) ?? const {},
    workspaceId: _readIntOrNull(json['workspace_id'] ?? json['workspaceId']),
    linkedWorkspaceIds: _expectList(
      json['linked_workspace_ids'] ?? json['linkedWorkspaceIds'] ?? const [],
    ).map(_readIntOrNull).whereType<int>().toList(),
  );

  BeanNote copyWith({
    String? title,
    String? bodyHtml,
    String? plainText,
    int? folderId,
    bool clearFolder = false,
    bool? isPinned,
    String? updatedAt,
    Map<String, Object?>? metadata,
    int? workspaceId,
    List<int>? linkedWorkspaceIds,
  }) => BeanNote(
    id: id,
    title: title ?? this.title,
    bodyHtml: bodyHtml ?? this.bodyHtml,
    plainText: plainText ?? this.plainText,
    folderId: clearFolder ? null : folderId ?? this.folderId,
    isPinned: isPinned ?? this.isPinned,
    updatedAt: updatedAt ?? this.updatedAt,
    metadata: metadata ?? this.metadata,
    workspaceId: workspaceId ?? this.workspaceId,
    linkedWorkspaceIds: linkedWorkspaceIds ?? this.linkedWorkspaceIds,
  );
}

class BeanTask {
  const BeanTask({
    required this.id,
    required this.title,
    this.status = 'open',
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
  final String status;
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

  factory BeanTask.fromJson(Map<String, Object?> json) {
    final metadata = _expectMapOrNull(json['metadata']);
    return BeanTask(
      id: _expectInt(json['id']),
      title: _readTitle(json),
      status: _canonicalTaskStatus(json['status']),
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

  BeanTask copyWith({
    String? title,
    String? status,
    String? dueAt,
    String? notes,
    String? category,
    String? color,
    bool? isCritical,
    String? completedAt,
    Map<String, Object?>? metadata,
    int? workspaceId,
    List<int>? linkedWorkspaceIds,
    bool clearDueAt = false,
    bool clearNotes = false,
    bool clearCategory = false,
    bool clearColor = false,
    bool clearCompletedAt = false,
  }) => BeanTask(
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
    workspaceId: workspaceId ?? this.workspaceId,
    linkedWorkspaceIds: linkedWorkspaceIds ?? this.linkedWorkspaceIds,
  );
}

class BeanReminder {
  const BeanReminder({
    required this.id,
    required this.title,
    this.dueAt,
    this.category,
    this.color,
    this.isCritical = false,
    this.status = 'scheduled',
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
  final String status;
  final String? completedAt;
  final int? calendarEventId;
  final Map<String, Object?>? metadata;
  final int? workspaceId;
  final List<int> linkedWorkspaceIds;

  List<String> get googleCalendarIds =>
      _googleCalendarIdsFromMetadata(metadata);

  factory BeanReminder.fromJson(Map<String, Object?> json) {
    final metadata = _expectMapOrNull(json['metadata']);
    return BeanReminder(
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
      status: _canonicalReminderStatus(json['status']),
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

  BeanReminder copyWith({
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
  }) => BeanReminder(
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

class BeanEventCategory {
  const BeanEventCategory({
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

  factory BeanEventCategory.fromJson(Map<String, Object?> json) =>
      BeanEventCategory(
        id: _expectInt(json['id']),
        name: _expectString(json['name']),
        color: (json['color'] as String?) ?? '#34C759',
        workspaceId: _readIntOrNull(json['workspace_id']),
        linkedWorkspaceIds: _expectList(
          json['linked_workspace_ids'] ?? const [],
        ).map(_readIntOrNull).whereType<int>().toList(),
      );

  BeanEventCategory copyWith({
    String? name,
    String? color,
    int? workspaceId,
    List<int>? linkedWorkspaceIds,
  }) => BeanEventCategory(
    id: id,
    name: name ?? this.name,
    color: color ?? this.color,
    workspaceId: workspaceId ?? this.workspaceId,
    linkedWorkspaceIds: linkedWorkspaceIds ?? this.linkedWorkspaceIds,
  );
}

class BeanCalendarEvent {
  const BeanCalendarEvent({
    required this.id,
    required this.title,
    this.workspaceId,
    this.linkedWorkspaceIds = const [],
    this.startsAt,
    this.endsAt,
    this.notes,
    this.location,
    this.status = 'scheduled',
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
  final String status;
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

  String? get outlookCalendarId => outlookCalendarIds.firstOrNull;

  List<String> get outlookCalendarIds {
    final raw =
        metadata?['outlook_calendar_ids'] ?? metadata?['outlookCalendarIds'];
    if (raw is List) {
      return raw
          .map((value) => value.toString())
          .where((value) => value.isNotEmpty)
          .toList();
    }
    final single =
        metadata?['outlook_calendar_id'] ?? metadata?['outlookCalendarId'];
    return single == null || single.toString().isEmpty
        ? const []
        : [single.toString()];
  }

  factory BeanCalendarEvent.fromJson(Map<String, Object?> json) {
    final parsedMetadata = _expectMapOrNull(json['metadata']);
    final canonicalAllDay = json['all_day'];
    if (canonicalAllDay != null && canonicalAllDay is! bool) {
      throw FormatException('Expected all_day to be a boolean');
    }
    final isGeneratedOccurrence =
        parsedMetadata?['recurrence_generated'] == true ||
        parsedMetadata?['recurrence_parent_event_id'] != null;
    final googleCalendarId = json['google_calendar_id']?.toString();
    final metadata =
        parsedMetadata == null &&
            googleCalendarId == null &&
            canonicalAllDay == null
        ? null
        : <String, Object?>{
            ...?parsedMetadata,
            if (googleCalendarId != null)
              'google_calendar_id': googleCalendarId,
            if (canonicalAllDay != null) 'all_day': canonicalAllDay,
          };
    return BeanCalendarEvent(
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
      status: _canonicalCalendarStatus(json['status']),
      category:
          json['category'] as String? ?? (metadata?['category'] as String?),
      color: json['color'] as String? ?? (metadata?['color'] as String?),
      isCritical: _readBool(
        json['is_critical'] ??
            json['isCritical'] ??
            metadata?['is_critical'] ??
            metadata?['isCritical'],
      ),
      recurrence: isGeneratedOccurrence ? null : json['recurrence'] as String?,
      metadata: metadata,
    );
  }

  BeanCalendarEvent copyWith({
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
    bool clearRecurrence = false,
    bool clearMetadata = false,
  }) => BeanCalendarEvent(
    id: id,
    title: title ?? this.title,
    workspaceId: workspaceId,
    linkedWorkspaceIds: linkedWorkspaceIds,
    startsAt: startsAt ?? this.startsAt,
    endsAt: clearEndsAt ? null : endsAt ?? this.endsAt,
    notes: clearNotes ? null : notes ?? this.notes,
    location: clearLocation ? null : location ?? this.location,
    status: status ?? this.status,
    category: clearCategory ? null : category ?? this.category,
    color: clearColor ? null : color ?? this.color,
    isCritical: isCritical ?? this.isCritical,
    recurrence: clearRecurrence ? null : recurrence ?? this.recurrence,
    metadata: clearMetadata ? null : metadata ?? this.metadata,
  );
}

String _readTitle(Map<String, Object?> json) => _sanitizeVisibleText(
  (json['title'] ?? json['name'] ?? json['content'] ?? 'Untitled').toString(),
);

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
  if (value is List && value.isEmpty) return null;
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

double? _readDoubleOrNull(Object? value) {
  if (value == null) return null;
  if (value is num) return value.toDouble();
  if (value is String) return double.tryParse(value);
  throw FormatException('Expected number, got ${value.runtimeType}');
}

String _expectString(Object? value) {
  if (value is String) return value;
  throw FormatException('Expected string, got ${value.runtimeType}');
}

String _canonicalReminderStatus(Object? value) {
  final status = _expectString(value);
  if (status == 'scheduled' || status == 'completed') return status;
  throw FormatException('Expected canonical reminder status, got $status');
}

String _canonicalTaskStatus(Object? value) {
  final status = _expectString(value);
  if (status == 'open' || status == 'completed') return status;
  throw FormatException('Expected canonical task status, got $status');
}

String _canonicalCalendarStatus(Object? value) {
  final status = _expectString(value);
  if (status == 'scheduled' || status == 'cancelled') return status;
  throw FormatException('Expected canonical calendar status, got $status');
}

String _readStringOrDefault(Object? value, String fallback) {
  if (value is String && value.trim().isNotEmpty) return value;
  return fallback;
}

String? _readString(Object? value) {
  if (value == null) return null;
  if (value is String) return value;
  return value.toString();
}

String? _readVisibleString(Object? value) {
  final text = _readString(value);
  return text == null ? null : _sanitizeVisibleText(text);
}

String _sanitizeVisibleText(String value) {
  if (value.isEmpty) return value;
  return value
      .replaceAll(RegExp(r'[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]'), '')
      .replaceAll(
        RegExp('[\u{FFFD}\u{FE0E}\u{FE0F}\u{200B}-\u{200F}]', unicode: true),
        '',
      );
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
