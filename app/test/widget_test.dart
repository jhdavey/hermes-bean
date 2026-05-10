import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:hermes_bean_app/hermes_api_client.dart';
import 'package:hermes_bean_app/main.dart';

void main() {
  testWidgets(
    'starts signed out, logs in, loads live data, sends chat, and exposes delete account',
    (WidgetTester tester) async {
      final api = _FakeHermesApiClient();
      await tester.pumpWidget(HermesBeanApp(apiClient: api));
      await tester.pumpAndSettle();

      expect(find.text('Sign in to Hermes Bean'), findsOneWidget);
      await tester.enterText(
        find.byKey(const Key('auth-email')),
        'bean@example.com',
      );
      await tester.enterText(
        find.byKey(const Key('auth-password')),
        'secret123',
      );
      await tester.tap(find.byKey(const Key('auth-submit')));
      await tester.pumpAndSettle();

      expect(find.text('Welcome, Bean User'), findsOneWidget);
      expect(find.text('Plan launch'), findsOneWidget);
      expect(find.text('Stand up'), findsOneWidget);
      expect(find.text('Design review'), findsWidgets);
      expect(find.text('assistant.ready'), findsOneWidget);

      await tester.enterText(
        find.byKey(const Key('chat-input')),
        'Schedule dentist tomorrow at 3pm',
      );
      await tester.tap(find.byKey(const Key('primary-chat-action')));
      await tester.pumpAndSettle();

      expect(api.sentMessages, ['Schedule dentist tomorrow at 3pm']);
      expect(find.text('Done — I updated your day.'), findsOneWidget);
      expect(find.text('assistant.calendar_event.created'), findsOneWidget);

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
        HermesBeanApp(apiClient: _SignedInFakeHermesApiClient()),
      );
      await tester.pumpAndSettle();

      expect(find.text('Hermes Bean'), findsWidgets);
      expect(find.text('Command center'), findsOneWidget);
      expect(
        find.text('Ask Hermes to plan, schedule, or follow up...'),
        findsOneWidget,
      );
      expect(find.text('Agent progress'), findsOneWidget);
      expect(find.text('Activity feed'), findsOneWidget);
      expect(find.text('Approve draft reply'), findsOneWidget);

      for (final label in <String>[
        'Today',
        'Tasks',
        'Reminders',
        'Calendar',
        'Activity',
      ]) {
        expect(find.text(label), findsWidgets);
      }
    },
  );

  testWidgets('uses old HeyBean green Material 3 styling indicators', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(apiClient: _SignedInFakeHermesApiClient()),
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
    expect(find.byKey(const Key('primary-chat-action')), findsOneWidget);
  });
}

class _SignedInFakeHermesApiClient extends _FakeHermesApiClient {
  _SignedInFakeHermesApiClient() {
    bearerToken = 'existing-token';
  }
}

class _FakeHermesApiClient extends HermesApiClient {
  _FakeHermesApiClient()
    : super(transport: (_) async => const HermesApiResponse(500, 'unused'));

  final sentMessages = <String>[];
  bool deletedAccount = false;

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
  Future<HermesUser> me() async =>
      const HermesUser(id: 1, name: 'Bean User', email: 'bean@example.com');

  @override
  Future<HermesSession> startSession({
    String? title,
    String? runtimeMode,
    Map<String, Object?>? metadata,
  }) async => const HermesSession(id: 42, status: 'active', title: 'Today');

  @override
  Future<List<HermesTask>> listTasks() async => const [
    HermesTask(id: 1, title: 'Plan launch', status: 'open'),
  ];

  @override
  Future<List<HermesReminder>> listReminders() async => const [
    HermesReminder(id: 2, title: 'Stand up', dueAt: '9:00 AM'),
  ];

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents() async => const [
    HermesCalendarEvent(id: 3, title: 'Design review', startsAt: '2:30 PM'),
  ];

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
