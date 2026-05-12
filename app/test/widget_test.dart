import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:hermes_bean_app/hermes_api_client.dart';
import 'package:hermes_bean_app/main.dart';

void main() {
  testWidgets(
    'starts signed out, logs in, loads live data, sends chat, and exposes delete account',
    (WidgetTester tester) async {
      final api = _FakeHermesApiClient();
      final tokenStore = _MemoryAuthTokenStore();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: tokenStore),
      );
      await tester.pumpAndSettle();

      expect(find.text('Sign in to Hermes Bean'), findsOneWidget);
      expect(find.byKey(const Key('show-register-mode')), findsOneWidget);
      expect(find.byKey(const Key('forgot-login-action')), findsOneWidget);
      expect(find.byKey(const Key('remember-me-checkbox')), findsOneWidget);
      expect(find.text('Remember me'), findsOneWidget);

      await tester.tap(find.byKey(const Key('remember-me-checkbox')));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('forgot-login-action')));
      await tester.pumpAndSettle();
      expect(find.text('Forgot login?'), findsWidgets);
      expect(
        find.textContaining('Password reset is not wired yet'),
        findsOneWidget,
      );
      await tester.tap(find.text('OK'));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('show-register-mode')));
      await tester.pumpAndSettle();
      expect(find.text('Create your Hermes Bean account'), findsOneWidget);
      expect(find.byKey(const Key('auth-name')), findsOneWidget);

      await tester.enterText(find.byKey(const Key('auth-name')), 'Bean User');
      await tester.enterText(
        find.byKey(const Key('auth-email')),
        'bean@example.com',
      );
      await tester.enterText(
        find.byKey(const Key('auth-password')),
        'correct-horse-battery-staple',
      );
      await tester.tap(find.byKey(const Key('auth-submit')));
      await tester.pumpAndSettle();

      expect(find.text('Welcome, Bean User'), findsOneWidget);
      expect(find.text('Plan launch'), findsOneWidget);
      expect(find.text('Stand up'), findsOneWidget);
      expect(find.text('Design review'), findsWidgets);
      expect(find.text('assistant.ready'), findsNothing);

      await tester.tap(find.byKey(const Key('nav-bean')));
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byKey(const Key('chat-input')),
        'Schedule dentist tomorrow at 3pm',
      );
      await tester.testTextInput.receiveAction(TextInputAction.send);
      await tester.pumpAndSettle();

      expect(api.sentMessages, ['Schedule dentist tomorrow at 3pm']);
      expect(find.text('Done — I updated your day.'), findsOneWidget);
      expect(find.text('assistant.calendar_event.created'), findsOneWidget);
      expect(find.text('Generated follow-up task'), findsOneWidget);
      expect(find.text('Stretch and hydrate'), findsOneWidget);
      expect(find.text('Updated focus block'), findsWidgets);

      expect(find.byKey(const Key('delete-account-action')), findsOneWidget);
      await tester.ensureVisible(
        find.byKey(const Key('delete-account-action')),
      );
      await tester.tap(find.byKey(const Key('delete-account-action')));
      await tester.pumpAndSettle();
      expect(api.deletedAccount, isTrue);
      expect(find.text('Sign in to Hermes Bean'), findsOneWidget);
    },
  );

  testWidgets(
    'Hermes Bean command center renders chat, progress, surfaces, and approvals',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _SignedInFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('HeyBean'), findsWidgets);
      expect(find.text('Bean assistant'), findsOneWidget);

      await tester.tap(find.byKey(const Key('nav-bean')));
      await tester.pumpAndSettle();

      expect(
        find.text('Ask Bean to create tasks, reminders, or calendar events...'),
        findsOneWidget,
      );
      expect(find.text('Agent progress'), findsOneWidget);
      expect(find.text('Activity feed'), findsOneWidget);
      expect(find.text('Pending approvals'), findsOneWidget);
      expect(find.text('Approval needed'), findsOneWidget);

      for (final label in <String>[
        'Today',
        'Tasks',
        'Reminders',
        'Bean',
        'Settings',
      ]) {
        expect(find.text(label), findsWidgets);
      }
    },
  );

  testWidgets(
    'focused old HeyBean views render home calendar, tasks, reminders, chat, and settings',
    (WidgetTester tester) async {
      final api = _SignedInFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('today-month-calendar')), findsOneWidget);
      expect(find.text('Today'), findsWidgets);
      expect(find.text('This month'), findsOneWidget);
      expect(find.text('Tasks for today'), findsOneWidget);
      expect(find.text('Plan launch'), findsOneWidget);
      expect(find.text('Design review'), findsWidgets);

      expect(find.byKey(const Key('nav-tasks')), findsOneWidget);
      await tester.tap(find.byKey(const Key('nav-tasks')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('tasks-view')), findsOneWidget);
      expect(find.text('Task list'), findsOneWidget);
      expect(find.text('Open'), findsWidgets);

      await tester.tap(find.byKey(const Key('nav-reminders')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('reminders-view')), findsOneWidget);
      expect(find.text('Reminders'), findsWidgets);
      expect(find.text('Stand up'), findsOneWidget);
      expect(find.text('Pending'), findsWidgets);

      await tester.tap(find.byKey(const Key('nav-bean')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('chat-view')), findsOneWidget);
      expect(find.byKey(const Key('quick-plan-today')), findsOneWidget);
      await tester.tap(find.byKey(const Key('quick-plan-today')));
      await tester.pumpAndSettle();
      expect(api.sentMessages, ['Help me plan today']);
      expect(find.text('Done — I updated your day.'), findsOneWidget);

      await tester.tap(find.byKey(const Key('review-approval-action')));
      await tester.pumpAndSettle();
      expect(find.text('Pending approval'), findsOneWidget);
      expect(find.textContaining('Review outgoing email'), findsWidgets);
      await tester.tap(find.text('OK'));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-settings')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('settings-view')), findsOneWidget);
      expect(find.text('Settings'), findsWidgets);
      expect(find.text('Bean preferences'), findsOneWidget);
      expect(find.text('Approval rules'), findsOneWidget);
      expect(find.byKey(const Key('delete-account-action')), findsOneWidget);
    },
  );

  testWidgets(
    'invalid remembered tokens return to sign in instead of offline chat',
    (WidgetTester tester) async {
      final tokenStore = _MemoryAuthTokenStore()..token = 'expired-token';
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _ExpiredTokenHermesApiClient(),
          tokenStore: tokenStore,
        ),
      );
      await tester.pumpAndSettle();

      expect(tokenStore.token, isNull);
      expect(find.text('Sign in to Hermes Bean'), findsOneWidget);
      expect(find.textContaining('Session expired'), findsOneWidget);
      expect(
        find.text('I could not reach the API. Try again soon.'),
        findsNothing,
      );
    },
  );

  testWidgets('remember me saves login token for future launches', (
    WidgetTester tester,
  ) async {
    final api = _FakeHermesApiClient();
    final tokenStore = _MemoryAuthTokenStore();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: tokenStore),
    );
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const Key('auth-email')),
      'bean@example.com',
    );
    await tester.enterText(
      find.byKey(const Key('auth-password')),
      'correct-horse-battery-staple',
    );
    await tester.tap(find.byKey(const Key('remember-me-checkbox')));
    await tester.tap(find.byKey(const Key('auth-submit')));
    await tester.pumpAndSettle();

    expect(find.text('Welcome, Bean User'), findsOneWidget);
    expect(tokenStore.token, 'fake-token');
  });

  testWidgets('uses old HeyBean green Material 3 styling indicators', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _SignedInFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    final materialApp = tester.widget<MaterialApp>(find.byType(MaterialApp));
    expect(materialApp.theme?.useMaterial3, isTrue);
    expect(materialApp.theme?.colorScheme.primary, const Color(0xFF16A34A));
    expect(materialApp.theme?.canvasColor, const Color(0xFFF8FBF6));

    expect(
      find.byKey(const Key('heybean-background-gradient')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('green-glow-left')), findsOneWidget);
    expect(find.byKey(const Key('heybean-bottom-menu')), findsOneWidget);
    expect(find.byKey(const Key('heybean-center-bean-button')), findsOneWidget);

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('primary-chat-action')), findsOneWidget);
  });
}

class _MemoryAuthTokenStore implements AuthTokenStore {
  String? token;

  @override
  Future<String?> loadToken() async => token;

  @override
  Future<void> saveToken(String token) async {
    this.token = token;
  }

  @override
  Future<void> clearToken() async {
    token = null;
  }
}

class _SignedInFakeHermesApiClient extends _FakeHermesApiClient {
  _SignedInFakeHermesApiClient() {
    bearerToken = 'existing-token';
  }
}

class _ExpiredTokenHermesApiClient extends HermesApiClient {
  _ExpiredTokenHermesApiClient()
    : super(transport: (_) async => const HermesApiResponse(500, 'unused'));

  @override
  Future<HermesUser> me() async =>
      throw const HermesApiException(401, '{"message":"Unauthenticated."}');
}

class _FakeHermesApiClient extends HermesApiClient {
  _FakeHermesApiClient()
    : super(transport: (_) async => const HermesApiResponse(500, 'unused'));

  final sentMessages = <String>[];
  bool deletedAccount = false;
  bool plannedToday = false;

  @override
  Future<HermesAuthResult> login({
    required String email,
    required String password,
  }) async {
    bearerToken = 'fake-token';
    return const HermesAuthResult(
      token: 'fake-token',
      user: HermesUser(id: 1, name: 'Bean User', email: 'bean@example.com'),
    );
  }

  @override
  Future<HermesAuthResult> register({
    required String name,
    required String email,
    required String password,
    String? passwordConfirmation,
  }) async {
    bearerToken = 'fake-token';
    return HermesAuthResult(
      token: 'fake-token',
      user: HermesUser(id: 1, name: name, email: email),
    );
  }

  @override
  Future<HermesUser> me() async =>
      const HermesUser(id: 1, name: 'Bean User', email: 'bean@example.com');

  @override
  Future<HermesSession> startSession({
    String? title,
    String? runtimeMode,
    Map<String, Object?>? metadata,
  }) async => const HermesSession(id: 42, status: 'active', title: 'Today');

  @override
  Future<List<HermesTask>> listTasks() async => plannedToday
      ? const [
          HermesTask(id: 10, title: 'Generated follow-up task', status: 'open'),
        ]
      : const [HermesTask(id: 1, title: 'Plan launch', status: 'open')];

  @override
  Future<List<HermesReminder>> listReminders() async => plannedToday
      ? const [
          HermesReminder(
            id: 20,
            title: 'Stretch and hydrate',
            dueAt: '10:00 AM',
          ),
        ]
      : const [HermesReminder(id: 2, title: 'Stand up', dueAt: '9:00 AM')];

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents() async => plannedToday
      ? const [
          HermesCalendarEvent(
            id: 30,
            title: 'Updated focus block',
            startsAt: '2:30 PM',
          ),
        ]
      : const [
          HermesCalendarEvent(
            id: 3,
            title: 'Design review',
            startsAt: '2:30 PM',
          ),
        ];

  @override
  Future<HermesTodaySummary> todaySummary() async => HermesTodaySummary(
    tasks: await listTasks(),
    reminders: await listReminders(),
    calendarEvents: await listCalendarEvents(),
    activityEvents: await pollActivityEvents(42),
    approvals: const [
      HermesApproval(
        id: 7,
        title: 'Review outgoing email before Hermes sends it',
        status: 'pending',
      ),
    ],
    blockers: const [],
  );

  @override
  Future<List<HermesActivityEvent>> pollActivityEvents(int sessionId) async =>
      const [HermesActivityEvent(id: 1, eventType: 'assistant.ready')];

  @override
  Future<HermesMessageResult> sendMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
  }) async {
    sentMessages.add(content);
    plannedToday = true;
    return const HermesMessageResult(
      status: 'completed',
      session: HermesSession(id: 42, status: 'active', title: 'Today'),
      assistantMessage: HermesMessage(
        id: 8,
        role: 'assistant',
        content: 'Done — I updated your day.',
      ),
      events: [
        HermesActivityEvent(
          id: 2,
          eventType: 'assistant.calendar_event.created',
        ),
      ],
    );
  }

  @override
  Future<void> deleteAccount() async {
    deletedAccount = true;
    bearerToken = null;
  }
}
