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

      expect(find.byKey(const Key('calendar-view')), findsOneWidget);
      expect(find.byKey(const Key('critical-task-count')), findsOneWidget);
      expect(find.text('Agent online'), findsNothing);
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

  testWidgets('sign in still succeeds when old API lacks past tasks endpoint', (
    WidgetTester tester,
  ) async {
    final api = _PastTasksUnavailableHermesApiClient();

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
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
    await tester.tap(find.byKey(const Key('auth-submit')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('calendar-view')), findsOneWidget);
    expect(find.textContaining('Session expired'), findsNothing);
    expect(api.pastTaskCalls, 1);
  });

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

      expect(find.text('HeyBean'), findsNothing);
      expect(find.text('Bean assistant'), findsNothing);
      expect(find.byKey(const Key('calendar-today-button')), findsOneWidget);
      expect(find.byKey(const Key('calendar-month-chevron')), findsOneWidget);
      expect(find.byKey(const Key('critical-task-count')), findsOneWidget);

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
        'Calendar',
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

      expect(find.byKey(const Key('calendar-view')), findsOneWidget);
      expect(find.byKey(const Key('critical-task-count')), findsOneWidget);
      expect(find.byKey(const Key('calendar-today-button')), findsOneWidget);
      expect(find.text('Today'), findsOneWidget);
      expect(find.text('2'), findsWidgets);
      expect(find.byKey(const Key('calendar-month-chevron')), findsOneWidget);
      expect(
        tester.getTopLeft(find.byKey(const Key('calendar-month-chevron'))).dx,
        lessThan(
          tester.getTopLeft(find.byKey(const Key('calendar-today-button'))).dx,
        ),
      );
      expect(
        find.byKey(const Key('apple-style-week-date-header')),
        findsOneWidget,
      );
      expect(
        _datePillColor(tester, 'week-date-pill-selected'),
        HeyBeanTheme.accent,
      );
      expect(
        _datePillColor(tester, 'week-date-pill-next-visible'),
        const Color(0xFFE5E7EB),
      );
      for (var index = 0; index < 5; index++) {
        expect(_datePillColor(tester, 'week-date-pill-neutral-$index'), isNull);
      }
      for (var index = 0; index < 7; index++) {
        expect(find.byKey(Key('week-date-cell-$index')), findsOneWidget);
      }
      expect(find.byKey(const Key('apple-style-day-timeline')), findsOneWidget);
      expect(
        find.byKey(const Key('calendar-current-time-marker')),
        findsOneWidget,
      );
      expect(find.text('9 AM'), findsOneWidget);
      expect(find.text('Noon'), findsOneWidget);
      expect(find.text('10 PM'), findsOneWidget);
      expect(find.byKey(const Key('apple-style-day-strip')), findsNothing);
      expect(find.text('Today / upcoming'), findsOneWidget);
      expect(find.text('Tasks for today'), findsOneWidget);
      expect(find.text('Plan launch'), findsOneWidget);
      expect(find.text('Design review'), findsWidgets);

      await tester.tap(find.byKey(const Key('calendar-month-chevron')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('apple-style-month-grid')), findsOneWidget);
      expect(find.byKey(const Key('calendar-day-chevron')), findsOneWidget);
      expect(find.text('Rest of month'), findsOneWidget);
      expect(find.text('Plan launch'), findsWidgets);
      expect(find.text('Stand up'), findsWidgets);
      await tester.tap(find.text('1').first);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('apple-style-day-timeline')), findsOneWidget);

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
      expect(find.text('Calendar preferences'), findsOneWidget);
      expect(find.text('Start hour'), findsOneWidget);
      expect(find.text('End hour'), findsOneWidget);
      expect(find.text('Approval rules'), findsOneWidget);
      expect(find.byKey(const Key('delete-account-action')), findsOneWidget);

      await tester.ensureVisible(
        find.byKey(const Key('calendar-start-hour-setting')),
      );
      await tester.tap(find.byKey(const Key('calendar-start-hour-setting')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('10 AM').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('calendar-end-hour-setting')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('9 PM').last);
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-today')));
      await tester.pumpAndSettle();
      expect(find.text('10 AM'), findsOneWidget);
      expect(find.text('9 PM'), findsOneWidget);
      expect(find.text('9 AM'), findsNothing);
      expect(find.text('10 PM'), findsNothing);
    },
  );

  testWidgets('calendar events stay inside their selected or next day column', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _TwoDayCalendarFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    final selectedHeading = tester.getRect(
      find.byKey(const Key('day-column-heading-selected')),
    );
    final nextHeading = tester.getRect(
      find.byKey(const Key('day-column-heading-next')),
    );
    final todayEvent = tester.getRect(
      find.byKey(const Key('calendar-event-block-today-workout')),
    );
    final tomorrowEvent = tester.getRect(
      find.byKey(const Key('calendar-event-block-tomorrow-workout')),
    );

    expect(todayEvent.left, greaterThanOrEqualTo(selectedHeading.left));
    expect(todayEvent.right, lessThanOrEqualTo(selectedHeading.right));
    expect(tomorrowEvent.left, greaterThanOrEqualTo(nextHeading.left));
    expect(tomorrowEvent.right, lessThanOrEqualTo(nextHeading.right));
    expect(
      find.byKey(const PageStorageKey<String>('apple-style-day-page-view')),
      findsOneWidget,
    );
    final fixedHourColumn = tester.getRect(
      find.byKey(const Key('calendar-fixed-hours-column')),
    );
    final scrollingDayColumns = tester.getRect(
      find.byKey(const PageStorageKey<String>('apple-style-day-page-view')),
    );
    expect(
      scrollingDayColumns.left,
      greaterThanOrEqualTo(fixedHourColumn.right),
    );

    final headingBeforeSwipe = _activeSelectedDayHeading(tester);
    final pageViewTopLeft = tester.getTopLeft(
      find.byKey(const PageStorageKey<String>('apple-style-day-page-view')),
    );
    await tester.dragFrom(
      pageViewTopLeft + const Offset(360, 120),
      const Offset(-420, 0),
    );
    await tester.pumpAndSettle();
    final headingAfterSwipe = _activeSelectedDayHeading(tester);

    expect(headingAfterSwipe, _headingDaysAfter(headingBeforeSwipe, 2));
  });

  testWidgets('week header horizontal scroll jumps by whole weeks', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _TwoDayCalendarFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    final before = _activeSelectedDayHeading(tester);
    final headerTopLeft = tester.getTopLeft(
      find.byKey(const Key('apple-style-week-date-header')),
    );
    await tester.dragFrom(
      headerTopLeft + const Offset(360, 24),
      const Offset(-420, 0),
    );
    await tester.pumpAndSettle();
    final after = _activeSelectedDayHeading(tester);

    expect(after, _headingDaysAfter(before, 7));
  });

  testWidgets(
    'tasks can be checked from the day view and drop below open tasks',
    (WidgetTester tester) async {
      final api = _ActiveTasksFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      expect(find.text('Yesterday one-off'), findsNothing);
      expect(find.text('Recurring vitamins'), findsOneWidget);

      final firstOpenTaskBefore = tester.getRect(find.text('Pack bags'));
      final secondOpenTaskBefore = tester.getRect(find.text('Call pharmacy'));
      expect(firstOpenTaskBefore.top, lessThan(secondOpenTaskBefore.top));

      await tester.ensureVisible(
        find.byKey(const Key('task-complete-checkbox-101')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('task-complete-checkbox-101')));
      await tester.pumpAndSettle();

      expect(api.completedTaskIds, [101]);
      final completedTaskAfter = tester.getRect(find.text('Pack bags'));
      final stillOpenTaskAfter = tester.getRect(find.text('Call pharmacy'));
      expect(stillOpenTaskAfter.top, lessThan(completedTaskAfter.top));
      expect(
        find.byKey(const Key('task-complete-checkbox-101')),
        findsOneWidget,
      );
    },
  );

  testWidgets('tasks can be checked from the tasks page', (
    WidgetTester tester,
  ) async {
    final api = _ActiveTasksFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-tasks')));
    await tester.pumpAndSettle();
    await tester.ensureVisible(
      find.byKey(const Key('task-complete-checkbox-102')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('task-complete-checkbox-102')));
    await tester.pumpAndSettle();

    expect(api.completedTaskIds, [102]);
    final openRecurring = tester.getRect(find.text('Recurring vitamins'));
    final completedTask = tester.getRect(find.text('Call pharmacy'));
    expect(openRecurring.top, lessThan(completedTask.top));

    await tester.tap(find.byKey(const Key('task-complete-checkbox-102')));
    await tester.pumpAndSettle();

    expect(api.reopenedTaskIds, [102]);
    final reopenedTask = tester.widget<CheckboxListTile>(
      find.byKey(const Key('task-complete-checkbox-102')),
    );
    expect(reopenedTask.value, isFalse);
  });

  testWidgets(
    'settings lists completed past tasks that dropped off active lists',
    (WidgetTester tester) async {
      final api = _ActiveTasksFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      expect(find.text('Archived oil change'), findsNothing);

      await tester.tap(find.byKey(const Key('nav-settings')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const Key('past-tasks-settings')));
      await tester.pumpAndSettle();

      expect(api.pastTaskListCalls, 1);
      expect(find.text('Past tasks'), findsOneWidget);
      expect(find.text('Archived oil change'), findsOneWidget);
      expect(
        find.text('Completed · Permanently deletes after 10 days'),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('task-complete-checkbox-201')));
      await tester.pumpAndSettle();

      expect(api.reopenedTaskIds, [201]);
      expect(find.text('Archived oil change'), findsNothing);
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

    expect(find.byKey(const Key('calendar-view')), findsOneWidget);
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

  testWidgets(
    'chat explains blocked Hermes requests with the backend reason and blocked state',
    (WidgetTester tester) async {
      final api = _BlockedRequestHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-bean')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('chat-input')),
        'Send the contract to Lauren',
      );
      await tester.ensureVisible(find.byKey(const Key('primary-chat-action')));
      await tester.tap(find.byKey(const Key('primary-chat-action')));
      await tester.pumpAndSettle();

      expect(api.sentMessages, ['Send the contract to Lauren']);
      expect(find.text('Blocked'), findsOneWidget);
      expect(
        find.text('Hermes is blocked: Gmail OAuth is not connected.'),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'chat failure message tells the user why Hermes could not complete the request',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _FailingRequestHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-bean')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('chat-input')),
        'Book a plumber for tomorrow',
      );
      await tester.ensureVisible(find.byKey(const Key('primary-chat-action')));
      await tester.tap(find.byKey(const Key('primary-chat-action')));
      await tester.pumpAndSettle();

      expect(find.text('Failed'), findsOneWidget);
      expect(
        find.text(
          'I could not complete that request because Hermes timed out. Please try again, or clarify any missing details so I can continue.',
        ),
        findsOneWidget,
      );
    },
  );

  testWidgets('pull to refresh reloads signed-in views', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    expect(api.todaySummaryCalls, 1);
    expect(
      find.byKey(const Key('signed-in-refresh-indicator')),
      findsOneWidget,
    );

    await tester.fling(
      find.byKey(const Key('signed-in-refresh-scroll')),
      const Offset(0, 320),
      1000,
    );
    await tester.pumpAndSettle();

    expect(api.todaySummaryCalls, greaterThan(1));
  });

  testWidgets(
    'calendar events open an editable detail sheet with category color recurrence and event reminders',
    (WidgetTester tester) async {
      final api = _EditableCalendarFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.ensureVisible(find.text('Today / upcoming'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Design review').last);
      await tester.pumpAndSettle();

      expect(
        find.byKey(const Key('calendar-event-detail-view')),
        findsOneWidget,
      );
      expect(find.text('Event details'), findsOneWidget);
      expect(find.byKey(const Key('event-title-field')), findsOneWidget);
      expect(find.byKey(const Key('event-start-field')), findsOneWidget);
      expect(find.byKey(const Key('event-end-field')), findsOneWidget);
      expect(find.byKey(const Key('event-category-field')), findsOneWidget);
      expect(find.byKey(const Key('event-color-field')), findsOneWidget);
      expect(find.byKey(const Key('event-recurrence-field')), findsOneWidget);
      expect(
        find.byKey(const Key('event-reminder-minutes-field')),
        findsOneWidget,
      );

      await tester.enterText(
        find.byKey(const Key('event-title-field')),
        'Design sync',
      );
      await tester.enterText(
        find.byKey(const Key('event-start-field')),
        '2026-05-14T16:00:00Z',
      );
      await tester.enterText(
        find.byKey(const Key('event-end-field')),
        '2026-05-14T17:00:00Z',
      );
      await tester.enterText(
        find.byKey(const Key('event-category-field')),
        'Work',
      );
      await tester.tap(find.byKey(const Key('event-color-field')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Orange').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('event-recurrence-field')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Weekly').last);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('event-reminder-minutes-field')),
        '15',
      );
      await tester.tap(find.byKey(const Key('event-save-action')));
      await tester.pumpAndSettle();

      expect(api.updatedEvent?.title, 'Design sync');
      expect(api.updatedEvent?.category, 'Work');
      expect(api.updatedEvent?.color, '#FF9500');
      expect(api.updatedEvent?.recurrence, 'weekly');
      expect(api.createdReminder?['calendar_event_id'], 3);
      expect(api.createdReminder?['title'], 'Reminder: Design sync');
      expect(find.text('Design sync'), findsWidgets);
    },
  );

  testWidgets('chat renders backend JSON message envelopes as natural language', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _JsonEnvelopeMessageHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('chat-input')),
      'Add Workout to my calendar Monday Wednesday and Friday 9-10am',
    );
    await tester.ensureVisible(find.byKey(const Key('primary-chat-action')));
    await tester.tap(find.byKey(const Key('primary-chat-action')));
    await tester.pumpAndSettle();

    expect(
      find.text(
        'Added Workout to this week on Monday, Wednesday, and Friday from 9:00 AM to 10:00 AM. Should I make it repeat every week?',
      ),
      findsOneWidget,
    );
    expect(find.textContaining('{"message"'), findsNothing);
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

class _PastTasksUnavailableHermesApiClient extends _FakeHermesApiClient {
  int pastTaskCalls = 0;

  @override
  Future<List<HermesTask>> listPastTasks() async {
    pastTaskCalls++;
    throw const HermesApiException(405, 'Method Not Allowed');
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
  int todaySummaryCalls = 0;

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
  Future<List<HermesTask>> listPastTasks() async => const [];

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
  Future<HermesTodaySummary> todaySummary() async {
    todaySummaryCalls++;
    return HermesTodaySummary(
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
  }

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

class _TwoDayCalendarFakeHermesApiClient extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents() async {
    final selectedDay = DateTime.now();
    final tomorrow = selectedDay.add(const Duration(days: 1));
    return [
      HermesCalendarEvent(
        id: 101,
        title: 'Today workout',
        startsAt: DateTime(
          selectedDay.year,
          selectedDay.month,
          selectedDay.day,
          10,
        ).toIso8601String(),
      ),
      HermesCalendarEvent(
        id: 102,
        title: 'Tomorrow workout',
        startsAt: DateTime(
          tomorrow.year,
          tomorrow.month,
          tomorrow.day,
          10,
        ).toIso8601String(),
      ),
    ];
  }
}

class _ActiveTasksFakeHermesApiClient extends _SignedInFakeHermesApiClient {
  final completedTaskIds = <int>[];
  final reopenedTaskIds = <int>[];
  int pastTaskListCalls = 0;
  bool _pastTaskReopened = false;
  late List<HermesTask> _activeTasks = [
    HermesTask(
      id: 101,
      title: 'Pack bags',
      status: 'open',
      dueAt: DateTime.now().toIso8601String(),
    ),
    HermesTask(
      id: 102,
      title: 'Call pharmacy',
      status: 'open',
      dueAt: DateTime.now().toIso8601String(),
    ),
    HermesTask(
      id: 103,
      title: 'Yesterday one-off',
      status: 'open',
      dueAt: DateTime.now().subtract(const Duration(days: 1)).toIso8601String(),
    ),
    HermesTask(
      id: 104,
      title: 'Recurring vitamins',
      status: 'open',
      dueAt: DateTime.now().subtract(const Duration(days: 1)).toIso8601String(),
      metadata: {'recurrence': 'daily'},
    ),
  ];

  @override
  Future<List<HermesTask>> listTasks() async => _activeTasks;

  @override
  Future<List<HermesTask>> listPastTasks() async {
    pastTaskListCalls++;
    if (_pastTaskReopened) return const [];
    return [
      HermesTask(
        id: 201,
        title: 'Archived oil change',
        status: 'completed',
        dueAt: DateTime.now()
            .subtract(const Duration(days: 2))
            .toIso8601String(),
        completedAt: DateTime.now()
            .subtract(const Duration(days: 2))
            .toIso8601String(),
      ),
    ];
  }

  @override
  Future<HermesTask> completeTask(int taskId) async {
    completedTaskIds.add(taskId);
    _activeTasks = _activeTasks
        .map(
          (task) => task.id == taskId
              ? task.copyWith(
                  status: 'completed',
                  completedAt: DateTime.now().toIso8601String(),
                )
              : task,
        )
        .toList();
    return _activeTasks.singleWhere((task) => task.id == taskId);
  }

  @override
  Future<HermesTask> reopenTask(int taskId) async {
    reopenedTaskIds.add(taskId);
    if (taskId == 201) {
      _pastTaskReopened = true;
      return const HermesTask(
        id: 201,
        title: 'Archived oil change',
        status: 'open',
      );
    }
    _activeTasks = _activeTasks
        .map(
          (task) => task.id == taskId
              ? task.copyWith(status: 'open', clearCompletedAt: true)
              : task,
        )
        .toList();
    return _activeTasks.singleWhere((task) => task.id == taskId);
  }
}

class _BlockedRequestHermesApiClient extends _SignedInFakeHermesApiClient {
  @override
  Future<HermesMessageResult> sendMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
  }) async {
    sentMessages.add(content);
    return const HermesMessageResult(
      status: 'blocked',
      session: HermesSession(
        id: 42,
        status: 'blocked',
        title: 'Session blocked',
      ),
      events: [],
      blocker: {'reason': 'Gmail OAuth is not connected.'},
    );
  }
}

class _EditableCalendarFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  HermesCalendarEvent? updatedEvent;
  Map<String, Object?>? createdReminder;

  @override
  Future<HermesCalendarEvent> updateCalendarEvent(
    int eventId, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? category,
    String? color,
    String? recurrence,
  }) async {
    updatedEvent = HermesCalendarEvent(
      id: eventId,
      title: title,
      startsAt: startsAt,
      endsAt: endsAt,
      category: category,
      color: color,
      recurrence: recurrence,
    );
    return updatedEvent!;
  }

  @override
  Future<HermesReminder> createEventReminder({
    required int calendarEventId,
    required String title,
    required String remindAt,
  }) async {
    createdReminder = {
      'calendar_event_id': calendarEventId,
      'title': title,
      'remind_at': remindAt,
    };
    return HermesReminder(id: 99, title: title, dueAt: remindAt);
  }

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents() async => [
    updatedEvent ??
        HermesCalendarEvent(
          id: 3,
          title: 'Design review',
          startsAt: DateTime.now()
              .copyWith(hour: 14, minute: 30)
              .toIso8601String(),
          endsAt: DateTime.now()
              .copyWith(hour: 15, minute: 00)
              .toIso8601String(),
          category: 'Personal',
          color: '#34C759',
          recurrence: 'none',
        ),
  ];
}

class _JsonEnvelopeMessageHermesApiClient extends _SignedInFakeHermesApiClient {
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
        id: 9,
        role: 'assistant',
        content:
            '{"message":"Added Workout to this week on Monday, Wednesday, and Friday from 9:00 AM to 10:00 AM. Should I make it repeat every week?"}',
      ),
      events: [],
    );
  }
}

Color? _datePillColor(WidgetTester tester, String key) {
  final container = tester.widget<Container>(find.byKey(Key(key)));
  final decoration = container.decoration as BoxDecoration?;
  return decoration?.color;
}

String? _activeSelectedDayHeading(WidgetTester tester) {
  final headingText = find.descendant(
    of: find.byKey(const Key('day-column-heading-selected')),
    matching: find.byType(Text),
  );
  return tester.widget<Text>(headingText).data;
}

String? _headingDaysAfter(String? heading, int days) {
  if (heading == null) return null;
  final parts = heading.split(' — ');
  if (parts.length != 2) return null;
  final monthAndDay = parts.last.split(' ');
  if (monthAndDay.length != 2) return null;
  final month = _testMonthNames.indexOf(monthAndDay.first) + 1;
  final day = int.tryParse(monthAndDay.last);
  if (month <= 0 || day == null) return null;
  final date = DateTime(2026, month, day).add(Duration(days: days));
  return '${_testShortWeekdayNames[date.weekday - 1]} — ${_testMonthNames[date.month - 1]} ${date.day}';
}

const _testMonthNames = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
];

const _testShortWeekdayNames = [
  'Mon',
  'Tue',
  'Wed',
  'Thu',
  'Fri',
  'Sat',
  'Sun',
];

class _FailingRequestHermesApiClient extends _SignedInFakeHermesApiClient {
  @override
  Future<HermesMessageResult> sendMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
  }) async {
    throw const HermesApiException(504, '{"message":"Hermes timed out."}');
  }
}
