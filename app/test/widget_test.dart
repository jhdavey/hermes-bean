import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:heybean_app/bean_api_client.dart';
import 'package:heybean_app/main.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('login starts Bean-led signup and exposes plain form fallback', (
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
    expect(find.text('Start with Bean'), findsOneWidget);

    await tester.tap(find.byKey(const Key('guided-signup-action')));
    await _pumpUntilFound(
      tester,
      find.byKey(const Key('guided-onboarding-input')),
    );

    expect(find.text('What is your first and last name?'), findsOneWidget);
    expect(
      find.text('Tap Bean for voice · volume on · allow mic'),
      findsOneWidget,
    );
    expect(find.text('Use plain signup form'), findsNothing);
    expect(find.byKey(const Key('guided-initial-bean-button')), findsOneWidget);
    expect(
      find.byKey(const Key('guided-zero-chrome-mic-copy')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('guided-zero-chrome-message')), findsOneWidget);
    expect(
      find.byKey(const Key('guided-zero-chrome-input-line')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('guided-onboarding-send')), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('guided-onboarding-input')),
      'Harley Davey',
    );
    await tester.tap(find.byKey(const Key('guided-onboarding-send')));
    await tester.pump(const Duration(milliseconds: 320));
    expect(find.text('Choose Light, Dark, or Auto.'), findsOneWidget);
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
    'command center upcoming rows use rails and contain time ranges',
    (tester) async {
      final api = _DashboardWithUpcomingEventApiClient();
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

      expect(find.textContaining('Today - '), findsOneWidget);
      expect(
        find.byKey(const Key('command-center-glance-dot-701')),
        findsNothing,
      );
      expect(find.text('9:15'), findsOneWidget);
      expect(find.text('11:45am'), findsOneWidget);
      expect(
        find.byKey(const Key('command-center-glance-critical-star-701')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('command-center-agenda-critical-star-task-702')),
        findsOneWidget,
      );
      expect(
        tester
            .getTopLeft(
              find.byKey(
                const Key('command-center-agenda-critical-star-task-702'),
              ),
            )
            .dx,
        lessThan(
          tester.getTopLeft(find.text('Critical command-center task')).dx,
        ),
      );
      expect(
        tester
            .getTopLeft(
              find.byKey(const Key('command-center-glance-critical-star-701')),
            )
            .dx,
        lessThan(tester.getTopLeft(find.text('Upcoming category rail')).dx),
      );
      final rail = tester.widget<Container>(
        find.byKey(const Key('command-center-glance-category-rail-701')),
      );
      final border = (rail.decoration! as BoxDecoration).border! as Border;
      expect(border.left.width, 3);
      expect(border.left.style, BorderStyle.solid);

      await tester.tap(find.byKey(const Key('nav-tasks')));
      await _pumpUntilFound(
        tester,
        find.byKey(const Key('task-critical-star-702')),
      );
      expect(
        tester.getTopLeft(find.byKey(const Key('task-critical-star-702'))).dx,
        lessThan(
          tester.getTopLeft(find.text('Critical command-center task')).dx,
        ),
      );

      await tester.tap(find.byKey(const Key('nav-reminders')));
      await _pumpUntilFound(
        tester,
        find.byKey(const Key('reminder-critical-star-703')),
      );
      expect(
        tester
            .getTopLeft(find.byKey(const Key('reminder-critical-star-703')))
            .dx,
        lessThan(tester.getTopLeft(find.text('Critical reminder')).dx),
      );
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

  test('Bean-triggered Flutter refresh reloads user settings and theme', () {
    final shellSource = File(
      'lib/src/shell/command_center_shell.dart',
    ).readAsStringSync();

    expect(shellSource, contains('Future<void> _refreshSignedInViews'));
    expect(shellSource, contains('widget.apiClient.me()'));
    expect(shellSource, contains('_applyUserTheme(user)'));
    expect(shellSource, contains('setState(() {\n      _user = user;'));
  });

  test('Flutter ElevenLabs startup waits for LiveKit data channel', () {
    final liveKitManagerSource = File(
      'packages/elevenlabs_agents/lib/src/connection/livekit_manager.dart',
    ).readAsStringSync();
    final pubspec = File('pubspec.yaml').readAsStringSync();

    expect(pubspec, contains('path: packages/elevenlabs_agents'));
    expect(
      liveKitManagerSource,
      contains('dataChannelGetBufferedAmountFailed'),
    );
    expect(
      liveKitManagerSource,
      contains('dataChannel not found or not opened'),
    );
    expect(liveKitManagerSource, contains('for (var attempt = 0; attempt < 8'));
    expect(liveKitManagerSource, contains('Duration(milliseconds: 150 *'));
  });

  test('Flutter Bean voice permission is not shown as generic red failure', () {
    final voiceSource = File(
      'lib/src/bean/bean_assistant_panel.dart',
    ).readAsStringSync();
    final dockSource = File(
      'lib/src/navigation/bottom_menu.dart',
    ).readAsStringSync();
    final podfile = File('ios/Podfile').readAsStringSync();

    expect(voiceSource, contains('voice_microphone_permission_needed'));
    expect(voiceSource, contains('Microphone permission needed'));
    expect(voiceSource, contains("_BeanDockActivity.attention"));
    expect(voiceSource, isNot(contains('Voice hit a problem')));
    expect(dockSource, contains('attention'));
    expect(dockSource, contains('0xFFFFF7E6'));
    expect(dockSource, contains('0xFFF59E0B'));
    expect(podfile, contains('PERMISSION_MICROPHONE=1'));
  });

  test('restored screens retain forms, sheets, and modal editors', () {
    final sources = [
      File('lib/src/calendar/title_time_editor.dart').readAsStringSync(),
      File('lib/src/calendar/event_detail.dart').readAsStringSync(),
      File('lib/src/settings/settings_view.dart').readAsStringSync(),
      File('lib/src/shell/command_center_shell.dart').readAsStringSync(),
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
      'post-tour-first-action-sheet',
      'post-tour-customize-dashboard',
      'post-tour-import-calendar',
      'post-tour-shared-workspace',
      'post-tour-bean-do-it',
      'post-tour-walkthrough',
      'post-tour-first-action-skip',
      'post-tour-workspace-create-name-field',
      'tasks-view',
      'reminders-view',
      'notes-view',
    ]) {
      expect(sources, contains(key));
    }
    expect(sources, contains('Walk me through it'));
    expect(sources, contains('Have Bean do it'));
    expect(
      sources.indexOf('post-tour-walkthrough'),
      lessThan(sources.indexOf('post-tour-bean-do-it')),
    );
    expect(sources, contains("child: const Text('Skip')"));
    expect(sources, isNot(contains('Skip this step')));
    expect(sources, isNot(contains('workspace label')));
    expect(sources, isNot(contains('command-center-label-field')));
    expect(sources, isNot(contains('Command Center name')));
    expect(sources, isNot(contains('notes-filter-unfiled')));
    expect(sources, isNot(contains("label: 'Unfiled'")));
  });

  test(
    'assignable item editors share the Personal-first workspace contract',
    () {
      final titleEditor = File(
        'lib/src/calendar/title_time_editor.dart',
      ).readAsStringSync();
      final eventEditor = File(
        'lib/src/calendar/event_detail.dart',
      ).readAsStringSync();
      final notesEditor = File(
        'lib/src/notes/notes_view.dart',
      ).readAsStringSync();
      final shell = File(
        'lib/src/shell/command_center_shell.dart',
      ).readAsStringSync();

      for (final source in [titleEditor, eventEditor, notesEditor]) {
        expect(source, contains('Workspaces'));
        expect(source, contains('(current)'));
      }
      expect(titleEditor, contains('CheckboxListTile'));
      expect(titleEditor, contains('lockPrimaryWorkspace'));
      expect(
        eventEditor,
        contains('_personalWorkspaceValue(widget.workspaces)'),
      );
      expect(eventEditor, contains('CheckboxListTile'));
      expect(notesEditor, contains('_showNoteWorkspaceAssignmentSheet'));
      expect(shell, contains('_personalWorkspaceValue'));
      expect(titleEditor, isNot(contains('Also assign to')));
      expect(eventEditor, isNot(contains('Local Workspace Sync')));
      expect(notesEditor, isNot(contains('Also sync to')));
    },
  );
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

class _DashboardWithUpcomingEventApiClient extends _DashboardApiClient {
  @override
  Future<List<BeanTask>> listTasks() async {
    final today = DateTime.now();
    final dueAt =
        '${today.year.toString().padLeft(4, '0')}-'
        '${today.month.toString().padLeft(2, '0')}-'
        '${today.day.toString().padLeft(2, '0')}';

    return [
      BeanTask(
        id: 702,
        title: 'Critical command-center task',
        dueAt: dueAt,
        isCritical: true,
        color: '#f59e0b',
      ),
    ];
  }

  @override
  Future<List<BeanReminder>> listReminders() async {
    final now = DateTime.now();
    return [
      BeanReminder(
        id: 703,
        title: 'Critical reminder',
        dueAt: DateTime(now.year, now.month, now.day, 23, 45).toIso8601String(),
        isCritical: true,
        color: '#ec4899',
      ),
    ];
  }

  @override
  Future<List<BeanCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
    final tomorrow = DateTime.now().add(const Duration(days: 1));
    final startsAt = DateTime(
      tomorrow.year,
      tomorrow.month,
      tomorrow.day,
      9,
      15,
    );

    return [
      BeanCalendarEvent(
        id: 701,
        title: 'Upcoming category rail',
        startsAt: startsAt.toIso8601String(),
        endsAt: startsAt
            .add(const Duration(hours: 2, minutes: 30))
            .toIso8601String(),
        color: '#2563eb',
        isCritical: true,
      ),
    ];
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
