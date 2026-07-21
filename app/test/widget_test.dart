import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:heybean_app/bean_api_client.dart';
import 'package:heybean_app/main.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('login and account setup retain the original forms', (
    tester,
  ) async {
    await tester.pumpWidget(
      HeyBeanApp(
        apiClient: BeanApiClient(
          baseUrl: Uri.parse('https://example.test/api'),
        ),
        tokenStore: _TestTokenStore(),
        launchExternalUrl: (_) async => true,
        updateAppIconBadge: (_) async {},
        stripePaymentHandler: _TestStripePaymentHandler(),
      ),
    );
    await _pumpUntilFound(tester, find.byKey(const Key('login-card')));

    expect(find.byKey(const Key('login-card')), findsOneWidget);
    expect(find.byKey(const Key('auth-email')), findsOneWidget);
    expect(find.byKey(const Key('auth-password')), findsOneWidget);
    expect(find.byKey(const Key('remember-me-checkbox')), findsOneWidget);
    expect(find.byKey(const Key('forgot-login-action')), findsOneWidget);

    await tester.tap(find.byKey(const Key('guided-signup-action')));
    await _pumpUntilFound(tester, find.byKey(const Key('guided-name-input')));

    expect(find.text('Account setup'), findsOneWidget);
    expect(find.byKey(const Key('guided-name-input')), findsOneWidget);
    expect(find.text('Step 1 of 6'), findsOneWidget);
  });

  testWidgets(
    'signed-in command center and navigation keep their original shell',
    (tester) async {
      final api = _DashboardApiClient();
      await tester.pumpWidget(
        HeyBeanApp(
          apiClient: api,
          tokenStore: _TestTokenStore(token: 'test-token'),
          launchExternalUrl: (_) async => true,
          updateAppIconBadge: (_) async {},
          stripePaymentHandler: _TestStripePaymentHandler(),
        ),
      );
      await _pumpUntilFound(
        tester,
        find.byKey(const Key('command-center-home')),
      );

      expect(find.byKey(const Key('command-center-home')), findsOneWidget);
      expect(find.byKey(const Key('nav-command-center')), findsOneWidget);
      expect(find.byKey(const Key('nav-tasks')), findsOneWidget);
      expect(find.byKey(const Key('nav-reminders')), findsOneWidget);
      expect(find.byKey(const Key('nav-notes')), findsOneWidget);
      expect(find.byKey(const Key('nav-more')), findsOneWidget);

      await tester.tap(find.byKey(const Key('create-item-menu')));
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.byKey(const Key('create-event-action')), findsOneWidget);
      expect(find.byKey(const Key('create-task-action')), findsOneWidget);
      expect(find.byKey(const Key('create-reminder-action')), findsOneWidget);
      expect(find.byKey(const Key('create-note-action')), findsOneWidget);
    },
  );

  testWidgets(
    'signed-in Bean center action opens assistant panel and sends messages',
    (tester) async {
      final api = _DashboardApiClient();
      await tester.pumpWidget(
        HeyBeanApp(
          apiClient: api,
          tokenStore: _TestTokenStore(token: 'test-token'),
          launchExternalUrl: (_) async => true,
          updateAppIconBadge: (_) async {},
          stripePaymentHandler: _TestStripePaymentHandler(),
        ),
      );
      await _pumpUntilFound(
        tester,
        find.byKey(const Key('command-center-home')),
      );

      expect(find.byKey(const Key('bean-assistant-button')), findsOneWidget);
      expect(
        find.byKey(const Key('bean-assistant-button-logo')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('bean-assistant-status')), findsNothing);
      await tester.tap(find.byKey(const Key('bean-assistant-button')));
      await _pumpUntilFound(
        tester,
        find.byKey(const Key('bean-assistant-panel')),
      );
      expect(find.byKey(const Key('bean-assistant-status')), findsOneWidget);
      expect(find.text('Bean ready'), findsNothing);

      await tester.enterText(
        find.byKey(const Key('bean-assistant-input')),
        'Create task call mom',
      );
      await tester.tap(find.byKey(const Key('bean-assistant-send')));
      await _pumpUntilFound(tester, find.text('I’ll add that task. Done.'));

      expect(api.sentBeanMessages, ['Create task call mom']);
      expect(api.sentBeanMessageSources, ['flutter_text']);

      await tester.tap(find.byKey(const Key('bean-assistant-status')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('bean-assistant-panel')), findsNothing);
    },
  );

  testWidgets('dashboard parse failure does not sign the user out', (
    tester,
  ) async {
    final api = _DashboardBootstrapFailingApiClient();
    await tester.pumpWidget(
      HeyBeanApp(
        apiClient: api,
        tokenStore: _TestTokenStore(token: 'test-token'),
        launchExternalUrl: (_) async => true,
        updateAppIconBadge: (_) async {},
        stripePaymentHandler: _TestStripePaymentHandler(),
      ),
    );
    await _pumpUntilFound(tester, find.byKey(const Key('command-center-home')));

    expect(find.byKey(const Key('command-center-home')), findsOneWidget);
    expect(find.byKey(const Key('login-card')), findsNothing);
    expect(find.textContaining('Could not load your account'), findsNothing);
    expect(
      find.textContaining('Could not load your latest dashboard data'),
      findsNothing,
    );
    expect(find.textContaining('unreadable response'), findsNothing);
  });

  testWidgets('login continues when native token persistence is unavailable', (
    tester,
  ) async {
    final api = _LoginApiClient();
    await tester.pumpWidget(
      HeyBeanApp(
        apiClient: api,
        tokenStore: _FailingPersistTokenStore(),
        launchExternalUrl: (_) async => true,
        updateAppIconBadge: (_) async {},
        stripePaymentHandler: _TestStripePaymentHandler(),
      ),
    );
    await _pumpUntilFound(tester, find.byKey(const Key('login-card')));

    await tester.enterText(
      find.byKey(const Key('auth-email')),
      'taylor@example.test',
    );
    await tester.enterText(find.byKey(const Key('auth-password')), 'secret');
    await tester.tap(find.byKey(const Key('remember-me-checkbox')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('auth-submit')));
    await _pumpUntilFound(tester, find.byKey(const Key('command-center-home')));

    expect(api.loginCalls, 1);
    expect(find.byKey(const Key('login-card')), findsNothing);
    expect(find.textContaining('Could not sign you in'), findsNothing);
    expect(find.textContaining('That cannot be opened'), findsNothing);
  });

  test('restored screens retain forms, sheets, and modal editors', () {
    final sources = [
      File('lib/src/calendar/title_time_editor.dart').readAsStringSync(),
      File('lib/src/calendar/event_detail.dart').readAsStringSync(),
      File('lib/src/settings/settings_view.dart').readAsStringSync(),
      File('lib/src/notes/notes_view.dart').readAsStringSync(),
      File('lib/src/tasks/task_reminder_views.dart').readAsStringSync(),
    ].join('\n');

    for (final key in [
      'title-time-editor-title',
      'title-time-editor-time',
      'title-time-editor-notes',
      'settings-view',
      'theme-preferences-card',
      'notification-preferences-card',
      'preferred-map-selector',
      'workspace-create-name-field',
      'tasks-view',
      'reminders-view',
      'notes-view',
    ]) {
      expect(sources, contains(key));
    }
    expect(sources, isNot(contains('notes-filter-unfiled')));
    expect(sources, isNot(contains("label: 'Unfiled'")));
  });
}

Future<void> _pumpUntilFound(
  WidgetTester tester,
  Finder finder, {
  int attempts = 30,
}) async {
  for (var attempt = 0; attempt < attempts; attempt++) {
    await tester.pump(const Duration(milliseconds: 100));
    if (finder.evaluate().isNotEmpty) return;
  }
  fail('Timed out waiting for $finder.');
}

class _TestTokenStore implements AuthTokenStore {
  _TestTokenStore({this.token});

  String? token;

  @override
  Future<void> clearToken() async => token = null;

  @override
  Future<bool> loadRememberMe() async => token != null;

  @override
  Future<String?> loadToken() async => token;

  @override
  Future<void> saveRememberMe(bool rememberMe) async {}

  @override
  Future<void> saveToken(String value) async => token = value;
}

class _FailingPersistTokenStore implements AuthTokenStore {
  @override
  Future<void> clearToken() async {}

  @override
  Future<bool> loadRememberMe() async => false;

  @override
  Future<String?> loadToken() async => null;

  @override
  Future<void> saveRememberMe(bool rememberMe) async {}

  @override
  Future<void> saveToken(String value) async {
    throw MissingPluginException('flutter_secure_storage unavailable');
  }
}

class _LoginApiClient extends _DashboardApiClient {
  int loginCalls = 0;

  @override
  Future<BeanAuthResult> login({
    required String email,
    required String password,
  }) async {
    loginCalls++;
    bearerToken = 'test-token';
    return BeanAuthResult(token: 'test-token', user: await me());
  }
}

class _DashboardBootstrapFailingApiClient extends _DashboardApiClient {
  @override
  Future<List<BeanCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
    throw const FormatException('Expected test dashboard JSON shape');
  }
}

class _TestStripePaymentHandler implements StripePaymentHandler {
  @override
  Future<void> preparePaymentSheet(
    BeanPaymentSheetSetup setup, {
    required BeanUser user,
    required String primaryButtonLabel,
  }) async {}

  @override
  Future<void> presentPaymentSheet() async {}
}

class _DashboardApiClient extends BeanApiClient {
  _DashboardApiClient() : super(baseUrl: Uri.parse('https://example.test/api'));

  final List<String> sentBeanMessages = [];
  final List<String?> sentBeanMessageSources = [];

  static const workspace = BeanWorkspace(
    id: '1',
    name: 'Personal',
    type: 'personal',
    active: true,
    isDefault: true,
  );

  @override
  Future<BeanUser> me() async => const BeanUser(
    id: 1,
    name: 'Taylor',
    email: 'taylor@example.test',
    subscriptionStatus: 'active',
    defaultWorkspaceId: 1,
    personalWorkspace: workspace,
    activeWorkspace: workspace,
    workspaces: [workspace],
    planLimits: BeanPlanLimits(notesEnabled: true),
  );

  @override
  Future<List<BeanTask>> listTasks() async => const [];

  @override
  Future<List<BeanTask>> listPastTasks() async => const [];

  @override
  Future<List<BeanReminder>> listReminders() async => const [];

  @override
  Future<List<BeanCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async => const [];

  @override
  Future<List<BeanEventCategory>> listEventCategories() async => const [];

  @override
  Future<List<BeanNoteFolder>> listNoteFolders() async => const [];

  @override
  Future<List<BeanNote>> listNotes() async => const [];

  @override
  Future<GoogleCalendarSyncStatus> googleCalendarStatus() async =>
      const GoogleCalendarSyncStatus(connected: false, status: 'not_connected');

  @override
  Future<GoogleCalendarSyncStatus> outlookCalendarStatus() async =>
      const GoogleCalendarSyncStatus(connected: false, status: 'not_connected');

  @override
  Future<BeanDashboardChangeFeed> dashboardChanges({
    int after = 0,
    int waitSeconds = 0,
    int limit = 100,
  }) async => const BeanDashboardChangeFeed(changes: [], latestId: 0);

  @override
  Future<BeanAssistantTurn> sendBeanMessage({
    required String content,
    int? sessionId,
    int? workspaceId,
    String? clientTimezone,
    String? source,
  }) async {
    sentBeanMessages.add(content);
    sentBeanMessageSources.add(source);
    return BeanAssistantTurn(
      session: const BeanAssistantSession(id: 42),
      run: const BeanAssistantRun(
        id: 7,
        status: 'completed',
        model: 'hermes:custom/gpt-test',
      ),
      messages: [
        BeanAssistantMessage(role: 'user', content: content),
        const BeanAssistantMessage(
          role: 'assistant',
          content: 'I’ll add that task. Done.',
        ),
      ],
    );
  }
}
