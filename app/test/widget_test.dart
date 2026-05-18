import 'dart:async';
import 'dart:io';

import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:hermes_bean_app/hermes_api_client.dart';
import 'package:hermes_bean_app/main.dart';

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  testWidgets(
    'forgot password asks for account email and sends a reset link request',
    (WidgetTester tester) async {
      final api = _FakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: api,
          tokenStore: _MemoryAuthTokenStore(),
          launchExternalUrl: (_) async => false,
        ),
      );
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byKey(const Key('auth-email')),
        'bean@example.com',
      );
      await tester.tap(find.byKey(const Key('forgot-login-action')));
      await tester.pumpAndSettle();

      expect(find.text('Reset password'), findsOneWidget);
      expect(find.byKey(const Key('forgot-password-email')), findsOneWidget);
      expect(
        find.textContaining('email used for your account'),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('send-password-reset-link')));
      await tester.pumpAndSettle();

      expect(api.passwordResetRequests, ['bean@example.com']);
      expect(find.text('Check your email'), findsOneWidget);
      expect(find.textContaining('password reset link'), findsOneWidget);

      await tester.tap(find.text('Back to login'));
      await tester.pumpAndSettle();

      expect(find.text('Login'), findsOneWidget);
      expect(find.byKey(const Key('auth-password')), findsOneWidget);
      expect(find.text('Reset password'), findsNothing);
    },
  );

  testWidgets(
    'starts signed out, logs in, loads live data, sends chat, and exposes delete account',
    (WidgetTester tester) async {
      final api = _FakeHermesApiClient(onboardingCompleted: false);
      final tokenStore = _MemoryAuthTokenStore();
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: api,
          tokenStore: tokenStore,
          launchExternalUrl: (_) async => false,
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Login'), findsOneWidget);
      expect(find.text('Sign in to Hermes Bean'), findsNothing);
      expect(find.text('Live API-backed personal assistant'), findsNothing);
      expect(find.byKey(const Key('login-header-logo')), findsOneWidget);
      final loginLogo = tester.widget<Image>(
        find.byKey(const Key('login-header-logo')),
      );
      expect(
        (loginLogo.image as AssetImage).assetName,
        'assets/images/bean/bean-logo.png',
      );
      final screenSize =
          tester.view.physicalSize / tester.view.devicePixelRatio;
      final bodyRect = tester.getRect(find.byType(SafeArea).first);
      final loginHeaderRect = tester.getRect(
        find.byKey(const Key('login-header')),
      );
      final loginCardRect = tester.getRect(find.byKey(const Key('login-card')));
      expect(loginHeaderRect.center.dx, closeTo(screenSize.width / 2, 1));
      expect(loginCardRect.center.dy, closeTo(bodyRect.center.dy, 1));
      expect(find.byIcon(Icons.lock_rounded), findsNothing);
      expect(find.byKey(const Key('show-register-mode')), findsOneWidget);
      expect(find.byKey(const Key('forgot-login-action')), findsOneWidget);
      expect(find.byKey(const Key('remember-me-checkbox')), findsOneWidget);
      expect(find.text('Remember me'), findsOneWidget);
      expect(
        find.textContaining('Keeps you signed in on this device'),
        findsNothing,
      );

      await tester.tap(find.byKey(const Key('remember-me-checkbox')));
      await tester.pumpAndSettle();

      expect(find.text('Forgot password?'), findsOneWidget);

      await tester.tap(find.byKey(const Key('show-register-mode')));
      await tester.pumpAndSettle();
      expect(find.text('Create your Hermes Bean account'), findsOneWidget);
      expect(find.byKey(const Key('auth-name')), findsOneWidget);
      expect(find.text('Choose Bean’s personality'), findsNothing);
      expect(find.text('What should Bean prioritize?'), findsNothing);

      await tester.enterText(find.byKey(const Key('auth-name')), 'Bean User');
      await tester.enterText(
        find.byKey(const Key('auth-email')),
        'bean@example.com',
      );
      await tester.enterText(
        find.byKey(const Key('auth-password')),
        'correct-horse-battery-staple',
      );
      tester.testTextInput.hide();
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const Key('auth-submit')));
      await tester.tap(find.byKey(const Key('auth-submit')));
      await tester.pumpAndSettle();

      expect(api.registeredAgentPersonality, isNull);
      expect(api.registeredPriorities, isNull);
      expect(api.registeredContext, isNull);

      expect(find.byKey(const Key('agent-onboarding-overlay')), findsNothing);
      expect(find.byKey(const Key('bean-intro-callout')), findsOneWidget);
      expect(
        find.text('Start by introducing yourself to Bean'),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('bean-intro-callout')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('nav-bean')), findsOneWidget);
      expect(
        find.textContaining('Hi, I’m Bean. Start by introducing yourself'),
        findsOneWidget,
      );

      await tester.enterText(
        find.byKey(const Key('chat-input')),
        'I am Bean User. I care about family, reminders, and planning. Please be a motivating coach.',
      );
      await tester.testTextInput.receiveAction(TextInputAction.send);
      await tester.pumpAndSettle();

      expect(api.sentMessages.first, contains('I am Bean User'));
      expect(find.byKey(const Key('bean-intro-callout')), findsNothing);

      await tester.tap(find.byKey(const Key('nav-today')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('calendar-view')), findsOneWidget);
      expect(find.byKey(const Key('critical-task-count')), findsOneWidget);
      expect(find.text('Agent online'), findsNothing);
      expect(find.text('Plan launch'), findsOneWidget);
      expect(find.text('Stand up'), findsNothing);
      expect(find.textContaining('Design review'), findsWidgets);
      expect(find.text('assistant.ready'), findsNothing);

      await tester.tap(find.byKey(const Key('nav-bean')));
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byKey(const Key('chat-input')),
        'Schedule dentist tomorrow at 3pm',
      );
      await tester.testTextInput.receiveAction(TextInputAction.send);
      await tester.pumpAndSettle();

      expect(api.sentMessages, contains('Schedule dentist tomorrow at 3pm'));
      final scheduleMetadata = api.sentMessageMetadata.last;
      expect(scheduleMetadata?['source'], 'flutter');
      final clientContext = scheduleMetadata?['client_context'];
      expect(clientContext, isA<Map<String, Object?>>());
      final typedClientContext = clientContext! as Map<String, Object?>;
      expect(typedClientContext['current_local_time'], isA<String>());
      expect(typedClientContext['current_utc_time'], isA<String>());
      expect(typedClientContext['timezone_name'], isA<String>());
      expect(
        typedClientContext['timezone_offset'],
        matches(RegExp(r'^[+-]\d{2}:\d{2}$')),
      );
      expect(typedClientContext['timezone_offset_minutes'], isA<int>());
      expect(find.text('Done — I updated your day.'), findsOneWidget);
      tester.testTextInput.hide();
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('chat-activity-menu')), findsOneWidget);

      await tester.tap(find.byKey(const Key('nav-settings')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('open-bean-preferences')), findsOneWidget);
      expect(find.text('Bean preferences'), findsOneWidget);
      expect(find.byKey(const Key('delete-account-action')), findsOneWidget);
      await tester.ensureVisible(
        find.byKey(const Key('delete-account-action')),
      );
      await tester.tap(find.byKey(const Key('delete-account-action')));
      await tester.pumpAndSettle();
      expect(find.text('Delete account permanently?'), findsOneWidget);
      expect(api.deletedAccount, isFalse);
      await tester.enterText(
        find.byKey(const Key('delete-account-confirmation-field')),
        'DELETE',
      );
      await tester.tap(
        find.byKey(const Key('delete-account-confirmation-submit')),
      );
      await tester.pumpAndSettle();
      expect(api.deletedAccount, isTrue);
      expect(find.text('Login'), findsOneWidget);
    },
  );

  testWidgets(
    'creating a household keeps settings mounted without Flutter tree errors',
    (WidgetTester tester) async {
      final api = _WorkspaceFakeHermesApiClient();

      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-settings')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.byKey(const Key('workspace-create-household-action')),
      );
      await tester.tap(
        find.byKey(const Key('workspace-create-household-action')),
      );
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('workspace-create-name-field')),
        'Family',
      );
      await tester.tap(find.byKey(const Key('workspace-create-save')));
      await tester.pumpAndSettle();

      expect(api.createdWorkspaceNames, ['Family']);
      expect(find.byKey(const Key('settings-view')), findsOneWidget);
      expect(find.text('Family'), findsWidgets);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'accepting a workspace invitation from Settings joins and reloads workspaces',
    (WidgetTester tester) async {
      final api = _WorkspaceFakeHermesApiClient();

      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-settings')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.byKey(const Key('workspace-accept-invitation-action')),
      );
      await tester.tap(
        find.byKey(const Key('workspace-accept-invitation-action')),
      );
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('workspace-accept-invitation-token')),
        'https://heybean.org/workspace-invitations/family-token/accept',
      );
      await tester.tap(
        find.byKey(const Key('workspace-accept-invitation-save')),
      );
      await tester.pumpAndSettle();

      expect(api.acceptedInvitationToken, 'family-token');
      expect(find.byKey(const Key('settings-view')), findsOneWidget);
      expect(find.text('Invitation accepted.'), findsOneWidget);
      expect(find.text('Joined household'), findsWidgets);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'workspace calendar list shows Google access info without duplicate display list',
    (WidgetTester tester) async {
      final calendarSave = Completer<List<Map<String, Object?>>>();
      final api = _WorkspaceFakeHermesApiClient()
        ..googleCalendarConnected = true
        ..workspaceCalendarSaveCompleter = calendarSave
        ..workspaces = const [
          HermesWorkspace(
            id: '1',
            name: 'Personal',
            type: 'personal',
            role: 'owner',
            active: true,
            isDefault: true,
            googleCalendarMappings: [
              {'google_calendar_id': 'primary', 'is_default_export': true},
            ],
          ),
        ];

      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-settings')));
      await tester.pumpAndSettle();

      expect(find.text('Displayed connected calendars'), findsNothing);
      expect(
        find.byKey(const Key('google-calendar-source-primary')),
        findsNothing,
      );

      await tester.ensureVisible(
        find.byKey(const Key('workspace-google-calendar-1-primary')),
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('workspace-google-calendar-1-primary')),
          matching: find.text(
            'Can add local events · Default for new local events',
          ),
        ),
        findsOneWidget,
      );

      await tester.ensureVisible(
        find.byKey(
          const Key('workspace-google-calendar-1-holidays@example.com'),
        ),
      );
      expect(
        find.descendant(
          of: find.byKey(
            const Key('workspace-google-calendar-1-holidays@example.com'),
          ),
          matching: find.text('Read only'),
        ),
        findsOneWidget,
      );

      await tester.tap(
        find.byKey(
          const Key('workspace-google-calendar-1-holidays@example.com'),
        ),
      );
      await tester.pump();

      expect(
        find.byKey(const Key('workspace-calendar-sync-progress')),
        findsNothing,
      );
      expect(find.text('Syncing your calendars...'), findsNothing);

      calendarSave.complete(const <Map<String, Object?>>[]);
      await tester.pumpAndSettle();
      expect(find.text('Workspace calendar choices saved.'), findsOneWidget);
    },
  );

  testWidgets(
    'workspace switch skips inline progress and shows status under full-screen loader',
    (WidgetTester tester) async {
      final reloadUser = Completer<HermesUser>();
      final api = _WorkspaceFakeHermesApiClient()
        ..googleCalendarConnected = true
        ..workspaces = const [
          HermesWorkspace(
            id: '1',
            name: 'Personal',
            type: 'personal',
            role: 'owner',
            active: true,
            isDefault: true,
          ),
          HermesWorkspace(
            id: '2',
            name: 'Family',
            type: 'household',
            role: 'owner',
          ),
        ];

      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();
      api.nextMeCompleter = reloadUser;

      await tester.tap(find.byKey(const Key('nav-settings')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const Key('workspace-switch-2')));
      await tester.tap(find.byKey(const Key('workspace-switch-2')));
      await tester.pump();

      expect(
        find.byKey(const Key('workspace-calendar-sync-progress')),
        findsNothing,
      );
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(
        find.byKey(const Key('full-screen-loading-message')),
        findsOneWidget,
      );
      expect(find.text('Syncing your calendars...'), findsOneWidget);

      reloadUser.complete(await api.currentUser());
      await tester.pumpAndSettle();
      expect(api.defaultWorkspaceSetTo, 2);
    },
  );

  testWidgets('workspace switch refresh failure does not sign the user out', (
    WidgetTester tester,
  ) async {
    final reloadUser = Completer<HermesUser>();
    final tokenStore = _MemoryAuthTokenStore();
    final api = _WorkspaceFakeHermesApiClient()
      ..googleCalendarConnected = true
      ..workspaces = const [
        HermesWorkspace(
          id: '1',
          name: 'Personal',
          type: 'personal',
          role: 'owner',
          active: true,
          isDefault: true,
        ),
        HermesWorkspace(
          id: '2',
          name: 'Family',
          type: 'household',
          role: 'owner',
        ),
      ];

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: tokenStore),
    );
    await tester.pumpAndSettle();
    api.nextMeCompleter = reloadUser;

    await tester.tap(find.byKey(const Key('nav-settings')));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const Key('workspace-switch-2')));
    await tester.tap(find.byKey(const Key('workspace-switch-2')));
    await tester.pump();

    reloadUser.completeError(const HermesApiException(500, 'sync failed'));
    await tester.pumpAndSettle();

    expect(api.defaultWorkspaceSetTo, 2);
    expect(find.text('Sign in to Hermes Bean'), findsNothing);
    expect(find.byKey(const Key('settings-view')), findsOneWidget);
    expect(
      find.textContaining('Bean could not refresh your workspace'),
      findsOneWidget,
    );
  });

  testWidgets(
    'household Leave action sits in the workspace card header next to role',
    (WidgetTester tester) async {
      final api = _WorkspaceFakeHermesApiClient()
        ..workspaces = const [
          HermesWorkspace(
            id: '1',
            name: 'Personal',
            type: 'personal',
            role: 'owner',
            active: true,
            isDefault: true,
          ),
          HermesWorkspace(
            id: '2',
            name: 'Family',
            type: 'household',
            role: 'owner',
          ),
        ];

      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-settings')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const Key('workspace-leave-2')));
      await tester.pumpAndSettle();

      final role = tester.getRect(find.byKey(const Key('workspace-role-2')));
      final leave = tester.getRect(find.byKey(const Key('workspace-leave-2')));
      final invite = tester.getRect(
        find.byKey(const Key('workspace-invite-2')),
      );

      expect((leave.center.dy - role.center.dy).abs(), lessThan(8));
      expect(leave.left, greaterThan(role.right));
      expect(invite.top, greaterThan(leave.bottom));
    },
  );

  testWidgets(
    'household personal sync button copies Personal into that workspace',
    (WidgetTester tester) async {
      final api = _WorkspaceFakeHermesApiClient()
        ..googleCalendarConnected = true
        ..workspaces = const [
          HermesWorkspace(
            id: '1',
            name: 'Personal',
            type: 'personal',
            role: 'owner',
            isDefault: true,
          ),
          HermesWorkspace(
            id: '2',
            name: 'Family',
            type: 'household',
            role: 'owner',
            active: true,
          ),
        ];

      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-settings')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('workspace-sync-all-action')), findsNothing);
      await tester.ensureVisible(
        find.byKey(const Key('workspace-sync-personal-action-2')),
      );
      await tester.pumpAndSettle();
      final syncButton = tester.getRect(
        find.byKey(const Key('workspace-sync-personal-action-2')),
      );
      final googleHeading = tester.getRect(
        find.text('Connected calendars for this workspace').last,
      );
      expect(syncButton.bottom, lessThanOrEqualTo(googleHeading.top));

      await tester.tap(
        find.byKey(const Key('workspace-sync-personal-action-2')),
      );
      await tester.pumpAndSettle();
      expect(find.text('Sync all from personal'), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const Key('workspace-sync-personal-action-2')),
          matching: find.byIcon(Icons.refresh_rounded),
        ),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const Key('workspace-sync-personal-run-2')));
      await tester.pumpAndSettle();

      expect(api.syncedAllSourceWorkspaceId, 1);
      expect(api.syncedAllTargetWorkspaceId, 2);
      expect(
        find.text(
          'Copied 2 tasks, 1 reminders, and 3 events from Personal to Family.',
        ),
        findsOneWidget,
      );
    },
  );

  testWidgets('settings edits current Bean preferences in one form', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient()
      ..updatedAgentPersonality = 'coach'
      ..updatedPriorities = ['Family', 'Focus']
      ..updatedContext = 'Protect dinner and use gentle nudges.';

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-settings')));
    await tester.pumpAndSettle();

    expect(find.textContaining('Coach'), findsOneWidget);
    expect(find.textContaining('Family, Focus'), findsOneWidget);

    await tester.tap(find.byKey(const Key('open-bean-preferences')));
    await tester.pumpAndSettle();

    expect(find.text('Edit Bean preferences'), findsOneWidget);
    expect(find.text('Choose Bean’s personality'), findsOneWidget);
    expect(find.text('What should Bean prioritize?'), findsOneWidget);
    expect(find.text('Anything Bean should know?'), findsOneWidget);
    expect(
      tester
          .widget<ChoiceChip>(find.byKey(const Key('agent-personality-coach')))
          .selected,
      isTrue,
    );
    expect(
      tester
          .widget<FilterChip>(
            find.byKey(const Key('onboarding-priority-Family')),
          )
          .selected,
      isTrue,
    );
    expect(
      tester
          .widget<FilterChip>(
            find.byKey(const Key('onboarding-priority-Focus')),
          )
          .selected,
      isTrue,
    );

    await tester.tap(find.byKey(const Key('agent-personality-organizer')));
    await tester.tap(find.byKey(const Key('onboarding-priority-Family')));
    await tester.tap(find.byKey(const Key('onboarding-priority-Work')));
    await tester.enterText(
      find.byKey(const Key('onboarding-context')),
      'Prioritize work blocks before lunch.',
    );
    await tester.ensureVisible(find.byKey(const Key('agent-preferences-save')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('agent-preferences-save')));
    await tester.pumpAndSettle();

    expect(find.text('Edit Bean preferences'), findsNothing);
    expect(api.updatedAgentPersonality, 'organizer');
    expect(api.updatedPriorities, containsAll(['Focus', 'Work']));
    expect(api.updatedPriorities, isNot(contains('Family')));
    expect(api.updatedContext, 'Prioritize work blocks before lunch.');
    expect(find.textContaining('Organizer'), findsOneWidget);
    expect(find.textContaining('Focus, Work'), findsOneWidget);
  });

  testWidgets('settings edits reminder notification preferences', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient()..plannedToday = true;

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-settings')));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('notification-preferences-card')),
      findsOneWidget,
    );
    expect(find.text('Notification preferences'), findsOneWidget);
    expect(
      find.textContaining('Reminders are sent using these preferences'),
      findsNothing,
    );
    expect(find.textContaining('Shows an iOS push notification'), findsNothing);
    expect(find.textContaining('Sends an email reminder'), findsNothing);
    final notificationBottom = tester
        .getBottomLeft(find.byKey(const Key('notification-preferences-card')))
        .dy;
    final workspaceTop = tester
        .getTopLeft(find.byKey(const Key('workspaces-settings')))
        .dy;
    expect(workspaceTop - notificationBottom, greaterThanOrEqualTo(8));

    await tester.ensureVisible(
      find.byKey(const Key('reminder-push-preference')),
    );
    await tester.tap(find.byKey(const Key('reminder-push-preference')));
    await tester.pumpAndSettle();

    expect(api.updatedNotificationPreferences.reminderPush, isFalse);
    expect(api.updatedNotificationPreferences.reminderEmail, isTrue);

    await tester.ensureVisible(
      find.byKey(const Key('reminder-email-preference')),
    );
    await tester.tap(find.byKey(const Key('reminder-email-preference')));
    await tester.pumpAndSettle();

    expect(api.updatedNotificationPreferences.reminderPush, isFalse);
    expect(api.updatedNotificationPreferences.reminderEmail, isFalse);
  });

  testWidgets('due reminder banner can dismiss or complete reminders', (
    WidgetTester tester,
  ) async {
    final dismissApi = _SignedInFakeHermesApiClient()
      ..showDueReminderBanner = true;
    await tester.pumpWidget(
      HermesBeanApp(apiClient: dismissApi, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('due-reminder-banner')), findsOneWidget);
    expect(find.text('Stand up'), findsWidgets);
    await tester.tap(find.byKey(const Key('due-reminder-dismiss')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('due-reminder-banner')), findsNothing);
    expect(dismissApi.bannerUpdatedReminder, isNull);

    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pumpAndSettle();

    final completeApi = _SignedInFakeHermesApiClient()
      ..showDueReminderBanner = true;
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: completeApi,
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('due-reminder-banner')), findsOneWidget);
    await tester.tap(find.byKey(const Key('due-reminder-complete')));
    await tester.pumpAndSettle();

    expect(completeApi.bannerUpdatedReminder?.status, 'completed');
    expect(find.byKey(const Key('due-reminder-banner')), findsNothing);
  });

  testWidgets('Bean personality info explains customer-facing options', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient();

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-settings')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('open-bean-preferences')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('agent-personality-info')), findsOneWidget);
    expect(find.text('Bean personality options'), findsNothing);

    await tester.tap(find.byKey(const Key('agent-personality-info')));
    await tester.pumpAndSettle();

    expect(find.text('Bean personality options'), findsOneWidget);
    expect(find.text('A steady everyday helper'), findsOneWidget);
    expect(find.text('A motivating helper for momentum'), findsOneWidget);
    expect(find.text('A detail-focused planner'), findsOneWidget);
    expect(find.text('A warm brainstorming partner'), findsOneWidget);
    expect(
      find.text('Best when you want gentle nudges without guilt or pressure.'),
      findsOneWidget,
    );
    expect(
      find.text(
        'Turns brainstorms into real tasks, reminders, and calendar events.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('standard info icons explain Settings sections', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient();

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-settings')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('settings-info')));
    await tester.pumpAndSettle();
    expect(find.text('Settings help'), findsOneWidget);
    expect(
      find.text(
        'Workspaces keep household calendars, tasks, and reminders separated from Personal.',
      ),
      findsOneWidget,
    );
    await tester.tap(find.text('Got it'));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.byKey(const Key('workspaces-info')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('workspaces-info')));
    await tester.pumpAndSettle();
    expect(find.text('Workspaces'), findsWidgets);
    expect(
      find.text(
        'Personal is your private space. Household workspaces are shared spaces for family plans.',
      ),
      findsOneWidget,
    );
  });

  testWidgets(
    'finish closes onboarding even when save response returns stale profile',
    (WidgetTester tester) async {
      final api = _FakeHermesApiClient(
        onboardingCompleted: false,
        staleOnboardingAfterUpdate: true,
      );

      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('show-register-mode')));
      await tester.pumpAndSettle();
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

      expect(find.byKey(const Key('agent-onboarding-overlay')), findsNothing);
      expect(find.byKey(const Key('bean-intro-callout')), findsOneWidget);
      expect(find.byKey(const Key('calendar-view')), findsOneWidget);

      await tester.tap(find.byKey(const Key('nav-bean')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('agent-onboarding-overlay')), findsNothing);
      expect(
        find.textContaining('Hi, I’m Bean. Start by introducing yourself'),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'saved onboarding choices remain visible when save response returns stale profile',
    (WidgetTester tester) async {
      final api = _FakeHermesApiClient(
        onboardingCompleted: false,
        staleOnboardingAfterUpdate: true,
        staleSettingsAfterUpdate: true,
      );

      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('show-register-mode')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('auth-name')), 'Bean User');
      await tester.enterText(
        find.byKey(const Key('auth-email')),
        'bean@example.com',
      );
      await tester.enterText(
        find.byKey(const Key('auth-password')),
        'correct-horse-battery-staple',
      );
      tester.testTextInput.hide();
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('auth-submit')));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-settings')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('open-bean-preferences')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('agent-personality-coach')));
      await tester.tap(find.byKey(const Key('onboarding-priority-Family')));
      await tester.enterText(
        find.byKey(const Key('onboarding-context')),
        'Protect dinner and use gentle nudges.',
      );
      await tester.ensureVisible(
        find.byKey(const Key('agent-preferences-save')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('agent-preferences-save')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('agent-onboarding-overlay')), findsNothing);
      await tester.tap(find.byKey(const Key('nav-settings')));
      await tester.pumpAndSettle();
      expect(find.textContaining('Coach'), findsOneWidget);
      expect(find.textContaining('Family'), findsOneWidget);

      await tester.tap(find.byKey(const Key('open-bean-preferences')));
      await tester.pumpAndSettle();
      expect(
        tester
            .widget<ChoiceChip>(
              find.byKey(const Key('agent-personality-coach')),
            )
            .selected,
        isTrue,
      );
      expect(
        tester
            .widget<FilterChip>(
              find.byKey(const Key('onboarding-priority-Family')),
            )
            .selected,
        isTrue,
      );
      expect(
        find.text('Protect dinner and use gentle nudges.'),
        findsOneWidget,
      );
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
      expect(_topHeaderDayLabelFinder(), findsOneWidget);
      expect(_topHeaderMonthLabelFinder(), findsOneWidget);
      expect(find.byKey(const Key('critical-task-count')), findsOneWidget);

      await tester.tap(find.byKey(const Key('nav-bean')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('chat-input-dock')), findsOneWidget);
      expect(find.byKey(const Key('chat-new-session-action')), findsOneWidget);
      expect(find.byKey(const Key('chat-activity-menu')), findsOneWidget);
      expect(find.text('Agent progress'), findsNothing);
      expect(find.text('Activity feed'), findsNothing);
      expect(find.text('Pending approvals'), findsNothing);
      expect(
        find.byKey(const Key('chat-approval-bottom-dock')),
        findsOneWidget,
      );

      for (final label in <String>[
        'Calendar',
        'Tasks',
        'Reminders',
        'Settings',
      ]) {
        expect(find.text(label), findsWidgets);
      }
    },
  );

  testWidgets('critical count opens a dropdown with listed critical items', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _SignedInFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('critical-task-count')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('critical-task-dropdown')), findsOneWidget);
    expect(find.text('Critical'), findsNothing);
    expect(find.textContaining('Critical means open tasks'), findsNothing);
    expect(find.byKey(const Key('critical-task-item-1')), findsOneWidget);
    expect(find.byKey(const Key('critical-event-item-3')), findsOneWidget);
    expect(find.text('Plan launch'), findsWidgets);
    expect(find.textContaining('Design review'), findsWidgets);
    expect(find.byKey(const Key('critical-reminder-item-2')), findsNothing);
  });

  testWidgets('reminder editor uses the shared date/time picker dock', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _SignedInFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-reminders')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('reminder-edit-action-2')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const Key('title-time-editor-critical-toggle')),
      findsNothing,
    );
    await tester.tap(find.byKey(const Key('title-time-editor-picker-button')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('title-time-time-dock')), findsOneWidget);
    expect(find.text('Choose date and time'), findsWidgets);
    expect(find.byKey(const Key('title-time-date-month-dial')), findsOneWidget);
    expect(find.byKey(const Key('title-time-time-hour-dial')), findsOneWidget);
    expect(
      find.byKey(const Key('title-time-time-minute-dial')),
      findsOneWidget,
    );
    expect(
      find.byKey(const Key('title-time-time-meridiem-dial')),
      findsOneWidget,
    );
  });

  testWidgets(
    'month view has six-wide month scroller and keeps task list scoped to today',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _TomorrowReminderFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('calendar-month-chevron')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('calendar-month-scroller')), findsOneWidget);
      expect(find.byKey(const Key('apple-style-month-grid')), findsOneWidget);
      expect(find.text('Tasks for Today'), findsOneWidget);
      expect(find.text('Today task'), findsOneWidget);
      expect(find.text('Tomorrow task'), findsNothing);
      expect(find.text('Rest of month'), findsNothing);

      final now = DateTime.now();
      final firstAllowedMonth = DateTime(now.year, now.month - 12);
      final lastAllowedMonth = DateTime(now.year, now.month + 24);
      expect(
        find.descendant(
          of: find.byKey(const Key('calendar-month-pill-0')),
          matching: find.text(
            _testShortMonthNames[firstAllowedMonth.month - 1],
          ),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('calendar-month-pill-0')),
          matching: find.text('${firstAllowedMonth.year}'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('calendar-month-pill-36')),
          matching: find.text(_testShortMonthNames[lastAllowedMonth.month - 1]),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('calendar-month-pill-36')),
          matching: find.text('${lastAllowedMonth.year}'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('calendar-month-pill-selected')),
          matching: find.text('${now.year}'),
        ),
        findsOneWidget,
      );

      final monthScrollerWidth = tester
          .getSize(find.byKey(const Key('calendar-month-scroller')))
          .width;
      expect(
        tester
            .getSize(find.byKey(const Key('calendar-month-pill-selected')))
            .width,
        closeTo(monthScrollerWidth / 6, 1),
      );

      final nextMonth = DateTime(now.year, now.month + 1);
      await tester.tap(find.byKey(const Key('calendar-month-pill-13')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('apple-style-month-grid')), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const Key('calendar-month-pill-selected')),
          matching: find.text(_testShortMonthNames[nextMonth.month - 1]),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('calendar-month-pill-selected')),
          matching: find.text('${nextMonth.year}'),
        ),
        findsOneWidget,
      );
      expect(find.text('Today task'), findsOneWidget);
      expect(find.text('Tomorrow task'), findsNothing);
    },
  );

  testWidgets('task and reminder editors can assign event-style categories', (
    WidgetTester tester,
  ) async {
    final api = _TaskReminderCategoryFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-tasks')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('task-edit-action-501')));
    await tester.pumpAndSettle();
    expect(
      find.text('These changes sync to Bean and are available to the agent.'),
      findsNothing,
    );
    expect(
      find.byKey(const Key('title-time-editor-category-add-action')),
      findsOneWidget,
    );
    await tester.tap(
      find.byKey(const Key('title-time-editor-category-add-action')),
    );
    await tester.pumpAndSettle();
    expect(
      find.byKey(const Key('event-category-create-modal')),
      findsOneWidget,
    );
    await tester.enterText(
      find.byKey(const Key('event-category-modal-name-field')),
      'Errands',
    );
    await tester.tap(find.byKey(const Key('event-category-modal-save-action')));
    await tester.pumpAndSettle();
    expect(api.savedCategory?.name, 'Errands');
    expect(
      find.byKey(const Key('title-time-editor-category-errands')),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const Key('title-time-editor-category-work')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('title-time-editor-time')),
      'Today 6:00 PM',
    );
    await tester.tap(find.byKey(const Key('title-time-editor-save')));
    await tester.pumpAndSettle();

    expect(api.updatedTask?.category, 'Work');
    expect(api.updatedTask?.color, '#007AFF');
    expect(api.updatedTask?.dueAt, isNotNull);
    expect(api.createdReminder, isNull);
    expect(find.textContaining('Work'), findsWidgets);

    await tester.tap(find.byKey(const Key('nav-reminders')));
    await tester.pumpAndSettle();
    final reminderSurface = tester.widget<Container>(
      find.byKey(const Key('reminder-row-surface-601')),
    );
    final reminderDecoration = reminderSurface.decoration! as BoxDecoration;
    expect(reminderDecoration.color?.a, closeTo(.14, .001));
    expect(reminderDecoration.color?.b, 1);
    await tester.tap(find.byKey(const Key('reminder-edit-action-601')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('title-time-editor-time')), findsNothing);
    expect(find.text('Remind me at'), findsNothing);
    expect(
      find.byKey(const Key('title-time-editor-selected-time-label')),
      findsOneWidget,
    );
    expect(
      find.byKey(const Key('title-time-editor-picker-button')),
      findsOneWidget,
    );
    expect(
      find.byKey(const Key('title-time-editor-category-add-action')),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const Key('title-time-editor-category-work')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('title-time-editor-save')));
    await tester.pumpAndSettle();

    expect(api.updatedReminder?.category, 'Work');
    expect(api.updatedReminder?.color, '#007AFF');
    expect(find.textContaining('Work'), findsWidgets);
  });

  testWidgets('new task date saves on the task without creating a reminder', (
    WidgetTester tester,
  ) async {
    final api = _TaskReminderCategoryFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-tasks')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('task-add-action')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('title-time-editor-title')),
      'Order coffee beans',
    );
    await tester.enterText(
      find.byKey(const Key('title-time-editor-time')),
      'Today 6:00 PM',
    );
    await tester.tap(find.byKey(const Key('title-time-editor-save')));
    await tester.pumpAndSettle();

    expect(api.createdTask?.title, 'Order coffee beans');
    expect(api.createdTask?.dueAt, isNotNull);
    expect(api.createdReminder, isNull);

    await tester.tap(find.byKey(const Key('nav-reminders')));
    await tester.pumpAndSettle();
    expect(find.text('Order coffee beans'), findsNothing);
  });

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
      expect(_topHeaderDayLabelFinder(), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const Key('calendar-today-button')),
          matching: find.byIcon(Icons.today_rounded),
        ),
        findsNothing,
      );
      expect(find.byKey(const Key('calendar-month-chevron')), findsOneWidget);
      expect(_topHeaderMonthLabelFinder(), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const Key('calendar-month-chevron')),
          matching: find.byIcon(Icons.calendar_month_rounded),
        ),
        findsOneWidget,
      );
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
      expect(
        tester.getTopLeft(find.byKey(const Key('week-date-cell-0'))).dx,
        closeTo(
          tester
              .getTopLeft(find.byKey(const Key('apple-style-week-date-header')))
              .dx,
          1,
        ),
      );
      expect(find.byKey(const Key('apple-style-day-timeline')), findsOneWidget);
      expect(
        find.byKey(const Key('calendar-current-time-marker')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('calendar-current-time-label')),
        findsOneWidget,
      );
      final selectedWeekDateText = tester.widget<Text>(
        find
            .descendant(
              of: find.byKey(const Key('week-date-pill-selected')),
              matching: find.byType(Text),
            )
            .first,
      );
      expect(selectedWeekDateText.style?.fontWeight, FontWeight.w400);
      final selectedDayHeadingText = tester.widget<Text>(
        find.descendant(
          of: find.byKey(const Key('day-column-heading-selected')),
          matching: find.byType(Text),
        ),
      );
      expect(selectedDayHeadingText.style?.fontWeight, FontWeight.w400);
      expect(
        tester
            .getSize(find.byKey(const Key('day-column-heading-selected')))
            .height,
        lessThan(48),
      );

      expect(find.text('7 AM'), findsOneWidget);
      expect(find.text('Noon'), findsOneWidget);
      expect(find.text('10 PM'), findsOneWidget);
      expect(find.byKey(const Key('apple-style-day-strip')), findsNothing);
      expect(find.text('Today / upcoming'), findsNothing);
      expect(find.text('Tasks for Today'), findsOneWidget);
      expect(find.text('Plan launch'), findsOneWidget);
      expect(find.textContaining('Design review'), findsOneWidget);

      await tester.tap(find.byKey(const Key('calendar-month-chevron')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('apple-style-month-grid')), findsOneWidget);
      expect(find.byKey(const Key('calendar-month-scroller')), findsOneWidget);
      expect(
        find.byKey(const Key('calendar-month-pill-selected')),
        findsOneWidget,
      );
      final monthScrollerWidth = tester
          .getSize(find.byKey(const Key('calendar-month-scroller')))
          .width;
      expect(
        tester
            .getSize(find.byKey(const Key('calendar-month-pill-selected')))
            .width,
        lessThan(monthScrollerWidth / 5.5),
      );
      expect(find.byKey(const Key('calendar-day-chevron')), findsNothing);
      expect(find.text('Rest of month'), findsNothing);
      expect(find.text('Plan launch'), findsWidgets);
      final monthGridBefore = tester.widget<Text>(find.text('1').first).data;
      final scrollerTopLeft = tester.getTopLeft(
        find.byKey(const Key('calendar-month-scroller')),
      );
      await tester.dragFrom(
        scrollerTopLeft + const Offset(260, 20),
        const Offset(-260, 0),
      );
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('apple-style-month-grid')), findsOneWidget);
      expect(
        find.byKey(const Key('calendar-month-pill-selected')),
        findsOneWidget,
      );
      expect(_topHeaderMonthLabelFinder(), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const Key('calendar-month-chevron')),
          matching: find.byIcon(Icons.calendar_month_rounded),
        ),
        findsOneWidget,
      );
      expect(
        tester.widget<Text>(find.text(monthGridBefore!).first).data,
        monthGridBefore,
      );
      expect(find.text('Stand up'), findsNothing);
      await tester.tap(find.byKey(const Key('calendar-month-chevron')));
      await tester.pumpAndSettle();
      final currentMonthLabel = const [
        'Jan',
        'Feb',
        'Mar',
        'Apr',
        'May',
        'Jun',
        'Jul',
        'Aug',
        'Sep',
        'Oct',
        'Nov',
        'Dec',
      ][DateTime.now().month - 1];
      expect(
        find.descendant(
          of: find.byKey(const Key('calendar-month-pill-selected')),
          matching: find.text(currentMonthLabel),
        ),
        findsOneWidget,
      );
      await tester.tap(find.text('1').first);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('apple-style-day-timeline')), findsOneWidget);

      expect(find.byKey(const Key('nav-tasks')), findsOneWidget);
      await tester.tap(find.byKey(const Key('nav-tasks')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('tasks-view')), findsOneWidget);
      expect(find.text('Task list'), findsNothing);
      expect(find.text('Active'), findsWidgets);

      await tester.tap(find.byKey(const Key('nav-reminders')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('reminders-view')), findsOneWidget);
      expect(find.text('Reminders'), findsWidgets);
      expect(find.text('Stand up'), findsOneWidget);
      expect(find.text('Pending'), findsWidgets);

      await tester.tap(find.byKey(const Key('nav-bean')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('chat-view')), findsOneWidget);
      expect(find.byKey(const Key('signed-in-refresh-scroll')), findsNothing);
      expect(find.byKey(const Key('quick-plan-today')), findsNothing);
      final chatTopBeforeDrag = tester.getTopLeft(
        find.byKey(const Key('chat-top-bar')),
      );
      await tester.drag(
        find.byKey(const Key('chat-message-list')),
        const Offset(0, -180),
      );
      await tester.pumpAndSettle();
      expect(
        tester.getTopLeft(find.byKey(const Key('chat-top-bar'))),
        chatTopBeforeDrag,
      );
      expect(
        tester.getRect(find.byKey(const Key('chat-input-dock'))).bottom,
        lessThanOrEqualTo(
          tester.getRect(find.byKey(const Key('heybean-bottom-menu'))).top,
        ),
      );
      await tester.enterText(
        find.byKey(const Key('chat-input')),
        'Help me plan today',
      );
      await tester.tap(find.byKey(const Key('primary-chat-action')));
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
      expect(find.text('7 AM'), findsNothing);
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

    expect(todayEvent.left, closeTo(selectedHeading.left + 2, 1));
    expect(todayEvent.right, closeTo(selectedHeading.right - 2, 1));
    expect(tomorrowEvent.left, closeTo(nextHeading.left + 2, 1));
    expect(tomorrowEvent.right, closeTo(nextHeading.right - 2, 1));
    final eventBlockContainer = tester.widget<Container>(
      find
          .descendant(
            of: find.byKey(const Key('calendar-event-block-today-workout')),
            matching: find.byType(Container),
          )
          .last,
    );
    final eventBlockDecoration =
        eventBlockContainer.decoration! as BoxDecoration;
    expect(eventBlockDecoration.borderRadius, BorderRadius.circular(6));
    expect(
      find.byKey(const PageStorageKey<String>('apple-style-day-page-view')),
      findsOneWidget,
    );
    final fixedHourColumn = tester.getRect(
      find.byKey(const Key('calendar-fixed-hours-column')),
    );
    final currentTimeLabel = tester.getRect(
      find.byKey(const Key('calendar-current-time-label')),
    );
    expect(currentTimeLabel.left, greaterThanOrEqualTo(fixedHourColumn.left));
    expect(currentTimeLabel.right, lessThanOrEqualTo(fixedHourColumn.right));
    expect(fixedHourColumn.height, closeTo(36 + 42 + (16 * 52.5), .1));
    final scrollingDayColumns = tester.getRect(
      find.byKey(const PageStorageKey<String>('apple-style-day-page-view')),
    );
    expect(
      scrollingDayColumns.left,
      greaterThanOrEqualTo(fixedHourColumn.right),
    );

    final headingBeforeSwipe = _activeSelectedDayHeading(tester);
    expect(_topHeaderDayLabelFinder(), findsOneWidget);
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
    expect(_topHeaderDayLabelFinder(), findsOneWidget);
  });

  testWidgets(
    'early calendar events extend visible hours instead of disappearing',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _EarlyCalendarFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('6 AM'), findsOneWidget);
      expect(
        find.byKey(const Key('calendar-event-block-early-flight')),
        findsOneWidget,
      );
    },
  );

  testWidgets('recurring calendar events render on their occurrence days', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _TwoDayCalendarFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    final today = DateTime.now();
    final tomorrow = today.add(const Duration(days: 1));
    expect(
      find.byKey(
        Key(
          'calendar-event-block-daily-standup-${today.year}-${today.month}-${today.day}',
        ),
      ),
      findsOneWidget,
    );
    expect(
      find.byKey(
        Key(
          'calendar-event-block-daily-standup-${tomorrow.year}-${tomorrow.month}-${tomorrow.day}',
        ),
      ),
      findsOneWidget,
    );
  });

  testWidgets('calendar event blocks place time span under the title', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _TwoDayCalendarFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    final eventBlock = find.byKey(
      const Key('calendar-event-block-today-workout'),
    );
    final title = find.descendant(
      of: eventBlock,
      matching: find.text('Today workout'),
    );
    final time = find.descendant(of: eventBlock, matching: find.text('10am'));

    expect(
      find.descendant(
        of: eventBlock,
        matching: find.text('Today workout 10am'),
      ),
      findsNothing,
    );
    expect(title, findsOneWidget);
    expect(time, findsOneWidget);
    expect(
      tester.getTopLeft(time).dy,
      greaterThan(tester.getTopLeft(title).dy),
    );
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
    'calendar start and end hour preferences survive app relaunches',
    (WidgetTester tester) async {
      SharedPreferences.setMockInitialValues({});
      final tokenStore = _MemoryAuthTokenStore()..token = 'saved-token';
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _SignedInFakeHermesApiClient(),
          tokenStore: tokenStore,
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('7 AM'), findsOneWidget);

      await tester.tap(find.byKey(const Key('nav-settings')));
      await tester.pumpAndSettle();
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

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pumpAndSettle();
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _SignedInFakeHermesApiClient(),
          tokenStore: tokenStore,
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('10 AM'), findsOneWidget);
      expect(find.text('9 PM'), findsOneWidget);
      expect(find.text('7 AM'), findsNothing);
    },
  );

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
      expect(find.textContaining('Travel'), findsWidgets);
      expect(find.textContaining('Due today at'), findsWidgets);
      final taskSurface = tester.widget<Container>(
        find.byKey(const Key('task-row-surface-101')),
      );
      final taskDecoration = taskSurface.decoration! as BoxDecoration;
      expect(taskDecoration.color?.a, closeTo(.14, .001));
      expect(taskDecoration.color?.b, 1);

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

  testWidgets(
    'task and reminder timestamps render naturally without raw ISO text',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _ActiveTasksFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-tasks')));
      await tester.pumpAndSettle();

      expect(find.textContaining('Due today at'), findsWidgets);
      expect(find.textContaining(RegExp(r'\d{4}-\d{2}-\d{2}T')), findsNothing);
      expect(find.textContaining(RegExp(r'\.\d{3,6}Z')), findsNothing);
    },
  );

  testWidgets('task row tap opens editor while checkbox toggles completion', (
    WidgetTester tester,
  ) async {
    final api = _ActiveTasksFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-tasks')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('task-row-action-101')));
    await tester.pumpAndSettle();

    expect(find.text('Edit task'), findsOneWidget);
    expect(api.completedTaskIds, isEmpty);

    await tester.tapAt(const Offset(10, 10));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('task-complete-checkbox-101')));
    await tester.pumpAndSettle();

    expect(api.completedTaskIds, [101]);
    expect(find.text('Edit task'), findsNothing);
  });

  testWidgets('tasks page keeps overdue open tasks after a chat refresh', (
    WidgetTester tester,
  ) async {
    final api = _ChatRefreshOverdueTaskFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('chat-input')),
      'How does my day look?',
    );
    await tester.testTextInput.receiveAction(TextInputAction.send);
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-tasks')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('tasks-view')), findsOneWidget);
    expect(find.text('Cut grass'), findsOneWidget);
    expect(find.textContaining('Due '), findsOneWidget);
  });

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
    expect(find.text('Call pharmacy'), findsNothing);

    await tester.ensureVisible(find.byKey(const Key('task-filter-done')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('task-filter-done')));
    await tester.pumpAndSettle();
    expect(find.text('Call pharmacy'), findsOneWidget);

    await tester.tap(find.byKey(const Key('task-complete-checkbox-102')));
    await tester.pumpAndSettle();

    expect(api.reopenedTaskIds, [102]);
    await tester.tap(find.byKey(const Key('task-filter-open')));
    await tester.pumpAndSettle();
    final reopenedTask = tester.widget<Checkbox>(
      find.byKey(const Key('task-complete-checkbox-102')),
    );
    expect(reopenedTask.value, isFalse);
  });

  testWidgets('settings exposes calendar connect and sync controls', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient();
    final launchedUrls = <Uri>[];
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: api,
        tokenStore: _MemoryAuthTokenStore(),
        launchExternalUrl: (url) async {
          launchedUrls.add(url);
          return true;
        },
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-settings')));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('google-calendar-sync-settings')),
      findsOneWidget,
    );
    expect(find.text('Calendar sync'), findsOneWidget);
    expect(find.text('Connect calendar'), findsOneWidget);

    await tester.ensureVisible(
      find.byKey(const Key('google-calendar-connect-action')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Connect calendar'));
    await tester.pumpAndSettle();
    expect(api.googleCalendarConnected, isTrue);
    expect(launchedUrls, hasLength(1));
    expect(launchedUrls.single.host, 'accounts.google.com');
    expect(
      find.textContaining('Finish approving calendar access'),
      findsOneWidget,
    );

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.googleCalendarSyncCalls, 1);
    expect(
      find.textContaining('Calendar sync connected and synced 2 events'),
      findsOneWidget,
    );

    await tester.ensureVisible(
      find.byKey(const Key('google-calendar-sync-action')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Sync now'));
    await tester.pumpAndSettle();
    expect(api.googleCalendarSyncCalls, 2);
    expect(find.textContaining('Synced 2 connected events'), findsOneWidget);

    expect(find.text('Displayed connected calendars'), findsNothing);
    expect(
      find.byKey(const Key('google-calendar-source-primary')),
      findsNothing,
    );
    expect(
      find.byKey(const Key('google-calendar-source-holidays@example.com')),
      findsNothing,
    );
  });

  testWidgets(
    'Google Calendar connect opens automatically when iOS launcher channel fails',
    (WidgetTester tester) async {
      final api = _SignedInFakeHermesApiClient();
      final nativeOpenedUrls = <String>[];
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(const MethodChannel('heybean/platform'), (
            call,
          ) async {
            if (call.method == 'openUrl') {
              final args = call.arguments! as Map<Object?, Object?>;
              nativeOpenedUrls.add(args['url']! as String);
              return true;
            }
            return null;
          });
      addTearDown(() {
        TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
            .setMockMethodCallHandler(
              const MethodChannel('heybean/platform'),
              null,
            );
      });

      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: api,
          tokenStore: _MemoryAuthTokenStore(),
          launchExternalUrl: (_) async {
            throw PlatformException(
              code: 'channel-error',
              message:
                  'Unable to establish connection on channel: "dev.flutter.pigeon.url_launcher_ios.UrlLauncherApi.launchUrl".',
            );
          },
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-settings')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.byKey(const Key('google-calendar-connect-action')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Connect calendar'));
      await tester.pumpAndSettle();

      expect(
        find.textContaining('Could not start Google Calendar'),
        findsNothing,
      );
      expect(
        find.textContaining('Copy this link into your browser'),
        findsNothing,
      );
      expect(find.textContaining('Copy auth link'), findsWidgets);
      expect(nativeOpenedUrls, hasLength(1));
      expect(Uri.parse(nativeOpenedUrls.single).host, 'accounts.google.com');
    },
  );

  testWidgets('Google Calendar connect can be copied and checked manually', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient();
    api.googleCalendarConnected = false;

    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(const MethodChannel('heybean/platform'), (
          call,
        ) async {
          if (call.method == 'openUrl') return false;
          return null;
        });
    addTearDown(() {
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(
            const MethodChannel('heybean/platform'),
            null,
          );
    });

    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: api,
        tokenStore: _MemoryAuthTokenStore(),
        launchExternalUrl: (_) async => false,
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));

    await tester.tap(find.byKey(const Key('nav-settings')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));
    await tester.ensureVisible(
      find.byKey(const Key('google-calendar-connect-action')),
    );
    await tester.pump();
    await tester.tap(find.text('Connect calendar'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));

    expect(
      find.byKey(const Key('google-calendar-copy-link-action')),
      findsOneWidget,
    );
    expect(
      find.byKey(const Key('google-calendar-check-connection-action')),
      findsOneWidget,
    );
    expect(find.textContaining('finish it in any browser'), findsOneWidget);

    await tester.tap(
      find.byKey(const Key('google-calendar-check-connection-action')),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));
    expect(api.googleCalendarSyncCalls, 1);
    expect(
      find.textContaining('Calendar sync connected and synced 2 events'),
      findsOneWidget,
    );
  });

  testWidgets(
    'signed-in app loads persisted resources from table list endpoints',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _StaleTodayPersistedResourcesFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Persisted task'), findsOneWidget);
      expect(find.textContaining('Persisted calendar event'), findsOneWidget);

      await tester.tap(find.byKey(const Key('nav-reminders')));
      await tester.pumpAndSettle();
      expect(find.text('Persisted reminder'), findsOneWidget);
    },
  );

  testWidgets('new tasks remain visible after the post-save refresh', (
    WidgetTester tester,
  ) async {
    final api = _CreateTaskDatabaseTruthFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-tasks')));
    await tester.pumpAndSettle();
    expect(find.text('Buy printer paper'), findsNothing);

    await tester.tap(find.byKey(const Key('task-add-action')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('title-time-editor-title')),
      'Buy printer paper',
    );
    await tester.tap(find.byKey(const Key('title-time-editor-save')));
    await tester.pumpAndSettle();

    expect(api.createdTaskTitles, ['Buy printer paper']);
    expect(api.todaySummaryCalls, greaterThanOrEqualTo(2));
    expect(api.taskListCalls, greaterThanOrEqualTo(2));
    expect(find.text('Buy printer paper'), findsOneWidget);

    await tester.fling(
      find.byKey(const Key('signed-in-refresh-scroll')),
      const Offset(0, 320),
      1000,
    );
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();

    expect(api.todaySummaryCalls, greaterThanOrEqualTo(3));
    expect(api.taskListCalls, greaterThanOrEqualTo(3));
    expect(find.text('Buy printer paper'), findsOneWidget);
  });

  testWidgets('settings no longer shows completed past tasks section', (
    WidgetTester tester,
  ) async {
    final api = _ActiveTasksFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    expect(find.text('Archived oil change'), findsNothing);

    await tester.tap(find.byKey(const Key('nav-settings')));
    await tester.pumpAndSettle();

    expect(api.pastTaskListCalls, 1);
    expect(find.byKey(const Key('past-tasks-settings')), findsNothing);
    expect(find.text('Past tasks'), findsNothing);
    expect(find.text('Archived oil change'), findsNothing);
  });

  testWidgets('settings lets the signed-in user edit email', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-settings')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('agent-onboarding-overlay')), findsNothing);
    expect(find.byKey(const Key('open-bean-preferences')), findsOneWidget);

    expect(find.text('bean@example.com'), findsWidgets);
    await tester.ensureVisible(
      find.byKey(const Key('settings-edit-email-action')).last,
    );
    await tester.tap(find.byKey(const Key('settings-edit-email-action')).last);
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('settings-email-editor-field')),
      'new-bean@example.com',
    );
    await tester.tap(find.byKey(const Key('settings-email-editor-save')));
    await tester.pumpAndSettle();

    expect(api.updatedEmail, 'new-bean@example.com');
    expect(find.text('new-bean@example.com'), findsWidgets);
  });

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
      expect(find.text('Login'), findsOneWidget);
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
    expect(tokenStore.rememberMe, isTrue);
  });

  testWidgets(
    'remember me preference stays checked and survives transient API failures',
    (WidgetTester tester) async {
      final tokenStore = _MemoryAuthTokenStore()
        ..token = 'saved-token'
        ..rememberMe = true;

      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _NetworkDownRememberedTokenHermesApiClient(),
          tokenStore: tokenStore,
        ),
      );
      await tester.pumpAndSettle();

      expect(tokenStore.token, 'saved-token');
      expect(find.text('Login'), findsOneWidget);
      expect(
        find.textContaining('Remember me token is still saved'),
        findsOneWidget,
      );
      expect(
        tester
            .widget<CheckboxListTile>(
              find.byKey(const Key('remember-me-checkbox')),
            )
            .value,
        isTrue,
      );
    },
  );

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
    expect(
      tester.getSize(find.byKey(const Key('heybean-center-bean-button'))),
      const Size(72, 72),
    );
    final beanLogo = tester.widget<Image>(
      find.byKey(const Key('heybean-center-bean-logo')),
    );
    expect(beanLogo.image, isA<AssetImage>());
    expect(
      (beanLogo.image as AssetImage).assetName,
      'assets/images/bean/bean-logo-white-overlay.png',
    );
    expect(beanLogo.color, isNull);
    expect(beanLogo.colorBlendMode, isNull);
    expect(beanLogo.width, 38);
    expect(beanLogo.height, 38);
    expect(
      find.byKey(const Key('heybean-center-bean-logo-badge')),
      findsNothing,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('heybean-center-bean-button')),
        matching: find.byIcon(Icons.eco_rounded),
      ),
      findsNothing,
    );

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
        find.text(
          'Bean is paused because Gmail OAuth is not connected. Please check Settings or approvals, then try again.',
        ),
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
          'Bean could not finish that request. Bean’s service is having a moment on our side. Please try again in a bit. Please try again, or tell Bean any missing details and I’ll pick it back up. Don’t worry — if this keeps happening we’ll fix it as soon as possible.',
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

  testWidgets('connected Google Calendar syncs on app load and pull refresh', (
    WidgetTester tester,
  ) async {
    final api = _GoogleCalendarAutoSyncFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    expect(api.googleCalendarSyncCalls, 1);
    expect(find.textContaining('Imported Google event'), findsOneWidget);

    await tester.fling(
      find.byKey(const Key('signed-in-refresh-scroll')),
      const Offset(0, 320),
      1000,
    );
    await tester.pumpAndSettle();

    expect(api.googleCalendarSyncCalls, 2);
  });

  testWidgets('calendar plus action creates a new event', (
    WidgetTester tester,
  ) async {
    final api = _EditableCalendarFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('calendar-add-event-action')), findsOneWidget);
    final addButton = tester.getRect(
      find.byKey(const Key('calendar-add-event-action')),
    );
    final criticalCount = tester.getRect(
      find.byKey(const Key('critical-task-count')),
    );
    final weekHeader = tester.getRect(
      find.byKey(const Key('apple-style-week-date-header')),
    );
    expect(addButton.left, greaterThan(criticalCount.right));
    expect(addButton.center.dy, closeTo(criticalCount.center.dy, 2));
    expect(addButton.bottom, lessThan(weekHeader.top));
    await tester.tap(find.byKey(const Key('calendar-add-event-action')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('calendar-event-detail-page')), findsOneWidget);
    expect(find.text('Event Details'), findsOneWidget);
    await tester.enterText(
      find.byKey(const Key('event-title-field')),
      'Client kickoff',
    );
    await tester.enterText(
      find.byKey(const Key('event-start-field')),
      '4:00 PM',
    );
    await tester.enterText(find.byKey(const Key('event-end-field')), '5:00 PM');
    await tester.ensureVisible(
      find.byKey(const Key('event-google-calendar-sports@example.com')),
    );
    await tester.tap(
      find.byKey(const Key('event-google-calendar-sports@example.com')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('event-save-action')));
    await tester.pumpAndSettle();

    expect(api.createdEvent?.title, 'Client kickoff');
    final typedStart = DateTime(
      DateTime.now().year,
      DateTime.now().month,
      DateTime.now().day,
      16,
    ).toUtc().toIso8601String();
    final typedEnd = DateTime(
      DateTime.now().year,
      DateTime.now().month,
      DateTime.now().day,
      17,
    ).toUtc().toIso8601String();
    expect(api.createdEvent?.startsAt, typedStart);
    expect(api.createdEvent?.endsAt, typedEnd);
    expect(api.createdEvent?.metadata?['google_calendar_ids'], [
      'primary',
      'sports@example.com',
    ]);
    expect(api.createdEvent?.metadata?['google_calendar_id'], 'primary');
    expect(find.textContaining('Client kickoff'), findsOneWidget);
  });

  testWidgets(
    'calendar plus action preserves unchanged default local time after reload',
    (WidgetTester tester) async {
      final api = _EditableCalendarFakeHermesApiClient();
      final now = DateTime.now();
      final defaultStartHour = (now.hour + 1).clamp(7, 22);
      final defaultStart = DateTime(
        now.year,
        now.month,
        now.day,
        defaultStartHour,
      );
      final defaultEnd = defaultStart.add(const Duration(hours: 1));

      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('calendar-add-event-action')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('event-title-field')),
        'Default-time hold',
      );
      await tester.tap(find.byKey(const Key('event-save-action')));
      await tester.pumpAndSettle();

      expect(
        api.createdEvent?.startsAt,
        defaultStart.toUtc().toIso8601String(),
      );
      expect(api.createdEvent?.endsAt, defaultEnd.toUtc().toIso8601String());
      expect(find.textContaining('Default-time hold'), findsOneWidget);
    },
  );

  testWidgets('event detail info icons explain scheduling options', (
    WidgetTester tester,
  ) async {
    final api = _EditableCalendarFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('calendar-add-event-action')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('event-schedule-info')));
    await tester.pumpAndSettle();
    expect(find.text('Event schedule'), findsOneWidget);
    expect(
      find.text('Tap a time field to use the date and time picker.'),
      findsOneWidget,
    );
    await tester.tap(find.text('Got it'));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.byKey(const Key('event-recurrence-info')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('event-recurrence-info')));
    await tester.pumpAndSettle();
    expect(find.text('Event recurrence'), findsOneWidget);
    expect(
      find.text('Specific days repeats on the weekdays you select.'),
      findsOneWidget,
    );
  });

  testWidgets('critical event edits persist and update header critical count', (
    WidgetTester tester,
  ) async {
    final api = _EditableCalendarFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    expect(api.updatedEvent?.isCritical, isNull);
    final eventBlock = find.byKey(
      const Key('calendar-event-block-design-review'),
    );
    await tester.ensureVisible(eventBlock);
    await tester.pumpAndSettle();
    await tester.tap(eventBlock);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('event-detail-critical-toggle')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('event-save-action')));
    await tester.pumpAndSettle();

    expect(api.updatedEvent?.isCritical, isTrue);
    await tester.tap(find.byKey(const Key('critical-task-count')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('critical-event-item-3')), findsOneWidget);
    expect(find.textContaining('Design review'), findsWidgets);
  });

  testWidgets('calendar event detail page can delete an event', (
    WidgetTester tester,
  ) async {
    final api = _EditableCalendarFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.ensureVisible(
      find.byKey(const Key('calendar-event-block-design-review')),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const Key('calendar-event-block-design-review')),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('event-delete-action')), findsOneWidget);
    final deleteButton = tester.widget<IconButton>(
      find.byKey(const Key('event-delete-action')),
    );
    expect(
      deleteButton.style?.backgroundColor?.resolve(<WidgetState>{}),
      HeyBeanTheme.destructive,
    );

    await tester.tap(find.byKey(const Key('event-delete-action')));
    await tester.pumpAndSettle();

    expect(find.text('Delete event?'), findsOneWidget);
    expect(api.deletedEventId, isNull);
    expect(find.byKey(const Key('calendar-event-detail-page')), findsOneWidget);

    await tester.tap(find.byKey(const Key('destructive-confirm-action')));
    await tester.pumpAndSettle();

    expect(api.deletedEventId, 3);
    expect(find.byKey(const Key('calendar-event-detail-page')), findsNothing);
    expect(
      find.byKey(const Key('calendar-event-block-design-review')),
      findsNothing,
    );
  });

  testWidgets('event editor preserves custom category colors when resaving', (
    WidgetTester tester,
  ) async {
    final api = _CustomColorEditableCalendarFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.ensureVisible(
      find.byKey(const Key('calendar-event-block-design-review')),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const Key('calendar-event-block-design-review')),
    );
    await tester.pumpAndSettle();
    await tester.ensureVisible(
      find.byKey(const Key('event-category-chip-Studio')),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('event-category-chip-Studio')), findsOneWidget);

    await tester.tap(find.byKey(const Key('event-save-action')));
    await tester.pumpAndSettle();

    expect(api.updatedEvent?.category, 'Studio');
    expect(api.updatedEvent?.color, '#123ABC');
  });

  testWidgets(
    'calendar events open an editable detail page with friendly date times category color recurrence and event reminders',
    (WidgetTester tester) async {
      final api = _EditableCalendarFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.ensureVisible(
        find.byKey(const Key('calendar-event-block-design-review')),
      );
      await tester.pumpAndSettle();
      final initialEventHeight = tester
          .getRect(find.byKey(const Key('calendar-event-block-design-review')))
          .height;
      await tester.tap(
        find.byKey(const Key('calendar-event-block-design-review')),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const Key('calendar-event-detail-page')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('calendar-event-detail-view')), findsNothing);
      expect(find.text('Event Details'), findsOneWidget);
      expect(
        find.byKey(const Key('event-detail-header-title')),
        findsOneWidget,
      );
      expect(find.text('Event settings'), findsNothing);
      expect(
        find.text('Schedule, category, recurrence, and reminders'),
        findsNothing,
      );
      expect(find.text('Details'), findsNothing);
      expect(find.byKey(const Key('event-title-field')), findsOneWidget);
      expect(find.byKey(const Key('event-start-field')), findsOneWidget);
      expect(find.byKey(const Key('event-end-field')), findsOneWidget);
      expect(find.byKey(const Key('event-category-dropdown')), findsNothing);
      expect(find.text('Categories'), findsNothing);
      expect(find.text('Category'), findsOneWidget);
      expect(find.byKey(const Key('event-category-chip-list')), findsOneWidget);
      expect(find.byKey(const Key('event-category-none')), findsOneWidget);
      expect(find.byKey(const Key('event-category-chip-Work')), findsOneWidget);
      expect(
        find.byKey(const Key('event-category-chip-Personal')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('event-category-delete-Work')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('event-category-add-action')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('event-category-field')), findsNothing);
      expect(find.byKey(const Key('event-color-field')), findsNothing);
      expect(find.widgetWithText(ChoiceChip, 'Orange'), findsNothing);
      expect(find.byKey(const Key('event-recurrence-field')), findsOneWidget);
      expect(
        find.byKey(const Key('event-google-calendar-field')),
        findsOneWidget,
      );
      expect(find.text('Personal'), findsWidgets);
      final titleTop = tester
          .getTopLeft(find.byKey(const Key('event-title-field')))
          .dy;
      final scheduleTop = tester
          .getTopLeft(find.byKey(const Key('event-start-field')))
          .dy;
      final recurrenceTop = tester
          .getTopLeft(find.byKey(const Key('event-recurrence-field')))
          .dy;
      final categoryTop = tester
          .getTopLeft(find.byKey(const Key('event-category-chip-list')))
          .dy;
      expect(titleTop, lessThan(scheduleTop));
      expect(scheduleTop, lessThan(recurrenceTop));
      expect(recurrenceTop, lessThan(categoryTop));
      final startEditor = tester.widget<EditableText>(
        find.descendant(
          of: find.byKey(const Key('event-start-field')),
          matching: find.byType(EditableText),
        ),
      );
      final initialStartTime = _testNaturalTimeLabel(
        DateTime.utc(
          DateTime.now().year,
          DateTime.now().month,
          DateTime.now().day,
          14,
          30,
        ).toLocal(),
      );
      expect(startEditor.controller.text, 'today at $initialStartTime');
      expect(startEditor.controller.text, isNot(contains('T14:30')));

      await tester.enterText(
        find.byKey(const Key('event-title-field')),
        'Design sync',
      );
      final eventDate = DateTime.now();
      await tester.enterText(
        find.byKey(const Key('event-start-field')),
        '4:00 PM',
      );
      await tester.enterText(
        find.byKey(const Key('event-end-field')),
        '5:00 PM',
      );
      await tester.scrollUntilVisible(
        find.byKey(const Key('event-reminder-minutes-field')),
        200,
        scrollable: find.byType(Scrollable).last,
      );
      await tester.pumpAndSettle();
      tester
          .widget<ChoiceChip>(
            find.descendant(
              of: find.byKey(const Key('event-recurrence-field')),
              matching: find.widgetWithText(ChoiceChip, 'Specific days'),
            ),
          )
          .onSelected
          ?.call(true);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('event-specific-days')), findsOneWidget);
      tester
          .widget<FilterChip>(
            find.descendant(
              of: find.byKey(const Key('event-specific-days')),
              matching: find.widgetWithText(FilterChip, 'Tue'),
            ),
          )
          .onSelected
          ?.call(true);
      tester
          .widget<FilterChip>(
            find.descendant(
              of: find.byKey(const Key('event-specific-days')),
              matching: find.widgetWithText(FilterChip, 'Thu'),
            ),
          )
          .onSelected
          ?.call(true);
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.byKey(const Key('event-google-calendar-sports@example.com')),
      );
      await tester.tap(
        find.byKey(const Key('event-google-calendar-sports@example.com')),
      );
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.byKey(const Key('event-reminder-minutes-field')),
      );
      await tester.pumpAndSettle();
      expect(
        find.byKey(const Key('event-reminder-minutes-field')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('event-reminder-recurrence-field')),
        findsOneWidget,
      );
      await tester.enterText(
        find.byKey(const Key('event-reminder-minutes-field')),
        '15',
      );
      tester
          .widget<ChoiceChip>(
            find.descendant(
              of: find.byKey(const Key('event-reminder-recurrence-field')),
              matching: find.widgetWithText(ChoiceChip, 'Specific days'),
            ),
          )
          .onSelected
          ?.call(true);
      await tester.pumpAndSettle();
      expect(
        find.byKey(const Key('event-reminder-specific-days')),
        findsOneWidget,
      );
      tester
          .widget<FilterChip>(
            find.descendant(
              of: find.byKey(const Key('event-reminder-specific-days')),
              matching: find.widgetWithText(FilterChip, 'Mon'),
            ),
          )
          .onSelected
          ?.call(true);
      tester
          .widget<FilterChip>(
            find.descendant(
              of: find.byKey(const Key('event-reminder-specific-days')),
              matching: find.widgetWithText(FilterChip, 'Wed'),
            ),
          )
          .onSelected
          ?.call(true);
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.byKey(const Key('event-category-chip-Work')),
        200,
        scrollable: find.byType(Scrollable).last,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('event-category-chip-Work')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('event-save-action')));
      await tester.pumpAndSettle();

      expect(api.updatedEvent?.title, 'Design sync');
      expect(
        api.updatedEvent?.startsAt,
        DateTime(
          eventDate.year,
          eventDate.month,
          eventDate.day,
          16,
        ).toUtc().toIso8601String(),
      );
      expect(
        api.updatedEvent?.endsAt,
        DateTime(
          eventDate.year,
          eventDate.month,
          eventDate.day,
          17,
        ).toUtc().toIso8601String(),
      );
      expect(api.updatedEvent?.category, 'Work');
      expect(api.updatedEvent?.color, '#007AFF');
      expect(api.updatedEvent?.recurrence, 'specific_days');
      expect(api.updatedEvent?.metadata, {
        'recurrence': 'specific_days',
        'google_calendar_ids': ['primary', 'sports@example.com'],
        'google_calendar_id': 'primary',
        'days': ['thu', 'tue'],
        'interval': 1,
        'unit': 'days',
      });
      final updatedEventKey = Key(
        'calendar-event-block-design-sync-${eventDate.year}-${eventDate.month}-${eventDate.day}',
      );
      await tester.ensureVisible(find.byKey(updatedEventKey));
      await tester.pumpAndSettle();
      expect(
        tester.getRect(find.byKey(updatedEventKey)).height,
        greaterThan(initialEventHeight),
      );
      expect(api.createdReminder?['calendar_event_id'], 3);
      expect(api.createdReminder?['title'], 'Reminder: Design sync');
      expect(api.createdReminder?['metadata'], {
        'minutes_before': 15,
        'recurrence': 'specific_days',
        'days': ['mon', 'wed'],
        'interval': 1,
        'unit': 'days',
      });
    },
  );

  testWidgets('all day Google events render in the all-day row only', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _AllDayGoogleCalendarFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('calendar-all-day-label')), findsOneWidget);
    expect(find.byKey(const Key('calendar-all-day-event-901')), findsOneWidget);
    expect(find.text('All Day'), findsOneWidget);
    expect(find.text('Google holiday'), findsOneWidget);
    expect(
      find.byKey(const Key('calendar-event-block-google-holiday')),
      findsNothing,
    );
  });

  testWidgets(
    'task list cards show critical stars and reminders omit critical stars with compact headers',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _SignedInFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-tasks')));
      await tester.pumpAndSettle();
      expect(find.text('Task list'), findsNothing);
      expect(find.textContaining('Create, edit, complete'), findsNothing);
      expect(find.byKey(const Key('task-add-action')), findsOneWidget);
      expect(find.byKey(const Key('task-critical-star-1')), findsOneWidget);

      await tester.tap(find.byKey(const Key('nav-reminders')));
      await tester.pumpAndSettle();
      expect(find.textContaining('Create, edit, and review'), findsNothing);
      expect(find.byKey(const Key('reminder-add-action')), findsOneWidget);
      expect(find.byKey(const Key('reminder-critical-star-2')), findsNothing);
    },
  );

  testWidgets(
    'multi-day event end edits render through the rest of the start day and next morning',
    (WidgetTester tester) async {
      final api = _MultiDayEditableCalendarFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('week-date-pill-next-visible')));
      await tester.pumpAndSettle();

      final eventBlock = find.byKey(
        const Key('calendar-event-block-conference'),
      );
      await tester.ensureVisible(eventBlock);
      await tester.pumpAndSettle();
      final initialHeight = tester.getRect(eventBlock).height;

      await tester.tap(eventBlock);
      await tester.pumpAndSettle();
      final end = DateTime.now().add(const Duration(days: 2));
      const monthNames = [
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
      await tester.enterText(
        find.byKey(const Key('event-end-field')),
        '${monthNames[end.month - 1]} ${end.day} at 1pm',
      );
      await tester.tap(find.byKey(const Key('event-save-action')));
      await tester.pumpAndSettle();

      expect(
        api.updatedEvent?.endsAt,
        DateTime(end.year, end.month, end.day, 13).toUtc().toIso8601String(),
      );
      expect(eventBlock, findsNWidgets(2));
      final startDayHeight = tester.getRect(eventBlock.first).height;
      expect(startDayHeight, greaterThan(initialHeight * 6));

      final nextMorningSegment = tester.getRect(eventBlock.last).height;
      expect(nextMorningSegment, greaterThan(initialHeight * 5));
      expect(nextMorningSegment, lessThan(startDayHeight));
    },
  );

  testWidgets('UTC calendar event timestamps render in the device timezone', (
    WidgetTester tester,
  ) async {
    final now = DateTime.now();
    final tomorrow = now.add(const Duration(days: 1));
    final wallClockStart = DateTime.utc(
      now.year,
      now.month,
      now.day,
      14,
    ).toLocal();
    final wallClockEnd = DateTime.utc(
      tomorrow.year,
      tomorrow.month,
      tomorrow.day,
      15,
    ).toLocal();
    final expectedTimeLabel =
        '${_testNaturalTimeLabel(wallClockStart)} – ${_testNaturalTimeLabel(wallClockEnd)}';

    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _UtcMultiDayCalendarFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    final eventBlock = find.byKey(
      const Key('calendar-event-block-app-project-focus-block'),
    );
    expect(eventBlock, findsWidgets);
    expect(
      find.descendant(
        of: eventBlock.first,
        matching: find.text(expectedTimeLabel),
      ),
      findsOneWidget,
    );

    await tester.ensureVisible(eventBlock.first);
    await tester.pumpAndSettle();
    await tester.tap(eventBlock.first);
    await tester.pumpAndSettle();
    final startEditor = tester.widget<EditableText>(
      find.descendant(
        of: find.byKey(const Key('event-start-field')),
        matching: find.byType(EditableText),
      ),
    );
    expect(
      startEditor.controller.text,
      contains(_testNaturalTimeLabel(wallClockStart)),
    );
  });

  testWidgets('offset calendar event timestamps render in the device timezone', (
    WidgetTester tester,
  ) async {
    final now = DateTime.now();
    final tomorrow = now.add(const Duration(days: 1));
    final wallClockStart = DateTime.parse(
      _testIsoWithOffset(now, hour: 13, offset: '-04:00'),
    ).toLocal();
    final wallClockEnd = DateTime.parse(
      _testIsoWithOffset(tomorrow, hour: 17, offset: '-04:00'),
    ).toLocal();
    final expectedTimeLabel =
        '${_testNaturalTimeLabel(wallClockStart)} – ${_testNaturalTimeLabel(wallClockEnd)}';

    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _OffsetCalendarFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    final eventBlock = find.byKey(
      const Key('calendar-event-block-google-afternoon-block'),
    );
    expect(eventBlock, findsWidgets);
    expect(
      find.descendant(
        of: eventBlock.first,
        matching: find.text(expectedTimeLabel),
      ),
      findsOneWidget,
    );
  });

  testWidgets(
    'saving an edited event preserves local times as UTC wire values',
    (WidgetTester tester) async {
      final api = _EditableCalendarFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.ensureVisible(
        find.byKey(const Key('calendar-event-block-design-review')),
      );
      await tester.tap(
        find.byKey(const Key('calendar-event-block-design-review')),
      );
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('event-title-field')),
        'Design review updated',
      );
      await tester.tap(find.byKey(const Key('event-save-action')));
      await tester.pumpAndSettle();

      final expectedStart = DateTime(
        DateTime.now().year,
        DateTime.now().month,
        DateTime.now().day,
        10,
        30,
      ).toUtc().toIso8601String();
      final expectedEnd = DateTime(
        DateTime.now().year,
        DateTime.now().month,
        DateTime.now().day,
        11,
      ).toUtc().toIso8601String();
      expect(api.updatedEvent?.startsAt, expectedStart);
      expect(api.updatedEvent?.endsAt, expectedEnd);
    },
  );

  testWidgets(
    'calendar event editor creates categories from plus modal and uses bottom dock time dials with five minute increments',
    (WidgetTester tester) async {
      final api = _EditableCalendarFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.ensureVisible(
        find.byKey(const Key('calendar-event-block-design-review')),
      );
      await tester.tap(
        find.byKey(const Key('calendar-event-block-design-review')),
      );
      await tester.pumpAndSettle();

      await tester.ensureVisible(
        find.byKey(const Key('event-category-chip-list')),
      );
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('event-category-dropdown')), findsNothing);
      expect(find.text('Categories'), findsNothing);
      expect(find.text('Category'), findsOneWidget);
      expect(find.byKey(const Key('event-category-chip-list')), findsOneWidget);
      expect(find.byKey(const Key('event-category-none')), findsOneWidget);
      expect(find.byKey(const Key('event-category-chip-Work')), findsOneWidget);
      expect(
        find.byKey(const Key('event-category-delete-Work')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('event-category-add-action')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('event-category-field')), findsNothing);
      expect(find.byKey(const Key('event-category-manager')), findsNothing);
      expect(find.byKey(const Key('event-category-save-action')), findsNothing);

      final categoryDeleteButton = tester.widget<IconButton>(
        find.byKey(const Key('event-category-delete-Work')),
      );
      expect(
        categoryDeleteButton.style?.backgroundColor?.resolve(<WidgetState>{}),
        isNot(HeyBeanTheme.destructive),
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('event-category-delete-Work')),
          matching: find.byIcon(Icons.close_rounded),
        ),
        findsOneWidget,
      );
      final categoryDeleteIcon = tester.widget<Icon>(
        find.descendant(
          of: find.byKey(const Key('event-category-delete-Work')),
          matching: find.byIcon(Icons.close_rounded),
        ),
      );
      expect(categoryDeleteIcon.color, HeyBeanTheme.destructive);

      await tester.tap(find.byKey(const Key('event-category-delete-Work')));
      await tester.pumpAndSettle();
      expect(find.text('Delete category?'), findsOneWidget);
      expect(api.deletedCategoryId, isNull);

      await tester.tap(find.byKey(const Key('destructive-confirm-action')));
      await tester.pumpAndSettle();
      expect(api.deletedCategoryId, 10);
      expect(find.byKey(const Key('event-category-chip-Work')), findsNothing);

      await tester.tap(find.byKey(const Key('event-category-add-action')));
      await tester.pumpAndSettle();
      expect(
        find.byKey(const Key('event-category-create-modal')),
        findsOneWidget,
      );
      await tester.enterText(
        find.byKey(const Key('event-category-modal-name-field')),
        'Travel',
      );
      expect(
        find.byKey(const Key('event-category-color-slider')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('event-category-color-slider-gradient')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('event-category-color-slider-thumb')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('event-category-hue-slider')), findsNothing);
      expect(
        find.byKey(const Key('event-category-saturation-slider')),
        findsNothing,
      );
      expect(
        find.byKey(const Key('event-category-value-slider')),
        findsNothing,
      );
      tester
          .widget<Slider>(find.byKey(const Key('event-category-color-slider')))
          .onChanged
          ?.call(180);
      await tester.pumpAndSettle();
      expect(find.text('#24F2F2'), findsOneWidget);
      await tester.tap(
        find.byKey(const Key('event-category-modal-save-action')),
      );
      await tester.pumpAndSettle();
      expect(api.savedCategory?.name, 'Travel');
      expect(api.savedCategory?.color, '#24F2F2');

      await tester.ensureVisible(find.byKey(const Key('event-start-field')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('event-start-field')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('event-time-dock')), findsOneWidget);
      expect(find.byKey(const Key('event-date-month-dial')), findsOneWidget);
      expect(find.byKey(const Key('event-date-day-dial')), findsOneWidget);
      expect(find.byKey(const Key('event-date-year-dial')), findsOneWidget);
      expect(find.byKey(const Key('event-time-hour-dial')), findsOneWidget);
      expect(find.byKey(const Key('event-time-minute-dial')), findsOneWidget);
      expect(find.byKey(const Key('event-time-meridiem-dial')), findsOneWidget);
      final hourPicker = tester.widget<CupertinoPicker>(
        find.byKey(const Key('event-time-hour-dial')),
      );
      final minutePicker = tester.widget<CupertinoPicker>(
        find.byKey(const Key('event-time-minute-dial')),
      );
      expect(
        hourPicker.childDelegate,
        isA<ListWheelChildLoopingListDelegate>(),
      );
      expect(
        minutePicker.childDelegate,
        isA<ListWheelChildLoopingListDelegate>(),
      );
      expect(find.text('25'), findsOneWidget);
      expect(find.text('30'), findsOneWidget);
      expect(find.text('35'), findsOneWidget);
      expect(find.text('07'), findsNothing);
      tester
          .widget<CupertinoPicker>(
            find.byKey(const Key('event-date-year-dial')),
          )
          .onSelectedItemChanged
          ?.call(2);
      hourPicker.onSelectedItemChanged?.call(12);
      minutePicker.onSelectedItemChanged?.call(13);
      tester
          .widget<CupertinoPicker>(
            find.byKey(const Key('event-time-meridiem-dial')),
          )
          .onSelectedItemChanged
          ?.call(1);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('event-time-dock-done')));
      await tester.pumpAndSettle();
      final updatedStartEditor = tester.widget<EditableText>(
        find.descendant(
          of: find.byKey(const Key('event-start-field')),
          matching: find.byType(EditableText),
        ),
      );
      expect(
        updatedStartEditor.controller.text,
        contains('${DateTime.now().year + 1}'),
      );
      expect(updatedStartEditor.controller.text, contains('1:05pm'));
    },
  );

  testWidgets(
    'calendar event editor rejects end times that are not after start',
    (WidgetTester tester) async {
      final api = _EditableCalendarFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.ensureVisible(
        find.byKey(const Key('calendar-event-block-design-review')),
      );
      await tester.tap(
        find.byKey(const Key('calendar-event-block-design-review')),
      );
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byKey(const Key('event-start-field')),
        '4:00 PM',
      );
      await tester.enterText(
        find.byKey(const Key('event-end-field')),
        '4:00 PM',
      );
      await tester.tap(find.byKey(const Key('event-save-action')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('event-validation-error')), findsOneWidget);
      expect(
        find.text('End time must be after the start time.'),
        findsOneWidget,
      );
      expect(api.updatedEvent, isNull);
      expect(
        find.byKey(const Key('calendar-event-detail-page')),
        findsOneWidget,
      );
    },
  );

  testWidgets('day view task list stays pinned to today', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _TomorrowReminderFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Tasks for Today'), findsOneWidget);
    expect(find.text('Today task'), findsOneWidget);
    expect(find.text('Today reminder'), findsNothing);
    expect(find.text('Tomorrow task'), findsNothing);
    expect(find.text('Tomorrow reminder'), findsNothing);

    await tester.tap(find.byKey(const Key('week-date-pill-next-visible')));
    await tester.pumpAndSettle();

    expect(find.text('Tasks for Today'), findsOneWidget);
    expect(find.text('Today task'), findsOneWidget);
    expect(find.text('Today reminder'), findsNothing);
    expect(find.text('Tomorrow task'), findsNothing);
    expect(find.text('Tomorrow reminder'), findsNothing);
  });

  testWidgets('reminder editor keeps controllers alive while opened', (
    WidgetTester tester,
  ) async {
    final api = _EditableReminderFakeHermesApiClient();

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-reminders')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Refill dog food'));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const Key('title-time-editor-title')),
      'Refill Bean food',
    );
    await tester.tap(find.byKey(const Key('title-time-editor-save')));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(api.updatedReminder?.title, 'Refill Bean food');
  });

  testWidgets('reminders view can check off reminders from the list', (
    WidgetTester tester,
  ) async {
    final api = _EditableReminderFakeHermesApiClient();

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-reminders')));
    await tester.pumpAndSettle();
    expect(find.text('Refill dog food'), findsOneWidget);

    await tester.tap(find.byKey(const Key('reminder-complete-checkbox-401')));
    await tester.pumpAndSettle();

    expect(api.updatedReminder?.status, 'completed');
    expect(find.text('Refill dog food'), findsNothing);

    await tester.tap(find.byKey(const Key('reminder-filter-completed')));
    await tester.pumpAndSettle();
    expect(find.text('Refill dog food'), findsOneWidget);
    expect(
      tester
          .widget<Checkbox>(
            find.byKey(const Key('reminder-complete-checkbox-401')),
          )
          .value,
      isTrue,
    );
  });

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
  bool rememberMe = false;

  @override
  Future<String?> loadToken() async => token;

  @override
  Future<bool> loadRememberMe() async => rememberMe;

  @override
  Future<void> saveToken(String token) async {
    this.token = token;
  }

  @override
  Future<void> saveRememberMe(bool rememberMe) async {
    this.rememberMe = rememberMe;
  }

  @override
  Future<void> clearToken() async {
    token = null;
  }
}

class _StaleTodayPersistedResourcesFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesTask>> listTasks() async => const [
    HermesTask(id: 901, title: 'Persisted task', status: 'open'),
  ];

  @override
  Future<List<HermesReminder>> listReminders() async => [
    HermesReminder(
      id: 902,
      title: 'Persisted reminder',
      dueAt: DateTime.now().toIso8601String(),
      status: 'pending',
    ),
  ];

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents() async => [
    HermesCalendarEvent(
      id: 903,
      title: 'Persisted calendar event',
      startsAt: DateTime.now().copyWith(hour: 14).toIso8601String(),
      endsAt: DateTime.now().copyWith(hour: 15).toIso8601String(),
    ),
  ];

  @override
  Future<HermesTodaySummary> todaySummary({int? workspaceId}) async {
    todaySummaryCalls++;
    return HermesTodaySummary(
      tasks: const [],
      reminders: const [],
      calendarEvents: const [],
      activityEvents: await pollActivityEvents(42),
      approvals: const [],
      blockers: const [],
    );
  }
}

class _CreateTaskDatabaseTruthFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  final createdTaskTitles = <String>[];
  final _databaseTasks = <HermesTask>[
    const HermesTask(id: 1, title: 'Plan launch', status: 'open'),
  ];
  int taskListCalls = 0;

  @override
  Future<List<HermesTask>> listTasks() async {
    taskListCalls++;
    return List<HermesTask>.unmodifiable(_databaseTasks);
  }

  @override
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
    createdTaskTitles.add(title);
    final task = HermesTask(
      id: 901,
      title: title,
      status: status,
      dueAt: dueAt,
      category: category,
      color: color,
      isCritical: isCritical,
      metadata: metadata,
    );
    _databaseTasks.add(task);
    return task;
  }

  @override
  Future<HermesTodaySummary> todaySummary({int? workspaceId}) async {
    todaySummaryCalls++;
    return HermesTodaySummary(
      tasks: const [],
      reminders: await listReminders(),
      calendarEvents: await listCalendarEvents(),
      activityEvents: await pollActivityEvents(42),
      approvals: const [],
      blockers: const [],
    );
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

class _WorkspaceFakeHermesApiClient extends _SignedInFakeHermesApiClient {
  final createdWorkspaceNames = <String>[];
  Completer<List<Map<String, Object?>>>? workspaceCalendarSaveCompleter;
  Completer<HermesUser>? nextMeCompleter;
  var savedWorkspaceCalendarIds = <String>[];
  int? defaultWorkspaceSetTo;
  int? syncedAllSourceWorkspaceId;
  int? syncedAllTargetWorkspaceId;
  String? acceptedInvitationToken;
  var workspaces = <HermesWorkspace>[
    const HermesWorkspace(
      id: '1',
      name: 'Personal',
      type: 'personal',
      role: 'owner',
      active: true,
      isDefault: true,
    ),
  ];

  Future<HermesUser> currentUser() async => HermesUser(
    id: 1,
    name: 'Bean User',
    email: updatedEmail ?? 'bean@example.com',
    onboardComplete: true,
    defaultWorkspaceId: defaultWorkspaceSetTo ?? 1,
    personalWorkspace: workspaces.first,
    activeWorkspace: workspaces.firstWhere(
      (workspace) => workspace.active,
      orElse: () => workspaces.first,
    ),
    workspaces: workspaces,
    agentProfile: const HermesAgentProfile(
      settings: {
        'personality_type': 'balanced',
        'onboarding': {'completed': true, 'priorities': <String>[]},
      },
    ),
  );

  @override
  Future<HermesUser> me() {
    final pending = nextMeCompleter;
    if (pending != null) {
      nextMeCompleter = null;
      return pending.future;
    }
    return currentUser();
  }

  @override
  Future<List<HermesWorkspace>> listWorkspaces() async => workspaces;

  @override
  Future<HermesWorkspace> createWorkspace({required String name}) async {
    createdWorkspaceNames.add(name);
    final workspace = HermesWorkspace(
      id: (workspaces.length + 1).toString(),
      name: name,
      type: 'household',
      role: 'owner',
    );
    workspaces = [...workspaces, workspace];
    return workspace;
  }

  @override
  Future<HermesWorkspaceMembership> acceptWorkspaceInvitation(
    String token,
  ) async {
    acceptedInvitationToken = token;
    const workspace = HermesWorkspace(
      id: '2',
      name: 'Joined household',
      type: 'household',
      role: 'member',
      active: true,
    );
    workspaces = [
      for (final workspace in workspaces) workspace.copyWith(active: false),
      workspace,
    ];
    return const HermesWorkspaceMembership(
      id: 20,
      workspaceId: 2,
      userId: 1,
      role: 'member',
      status: 'active',
      workspace: workspace,
    );
  }

  @override
  Future<HermesWorkspace> setDefaultWorkspace(int workspaceId) async {
    defaultWorkspaceSetTo = workspaceId;
    workspaces = [
      for (final workspace in workspaces)
        workspace.copyWith(
          active: workspace.numericId == workspaceId,
          isDefault: workspace.numericId == workspaceId,
        ),
    ];
    return workspaces.firstWhere(
      (workspace) => workspace.numericId == workspaceId,
    );
  }

  @override
  Future<List<Map<String, Object?>>> updateWorkspaceGoogleCalendars(
    int workspaceId, {
    required List<String> googleCalendarIds,
    String? defaultExportCalendarId,
  }) async {
    savedWorkspaceCalendarIds = googleCalendarIds;
    final pending = workspaceCalendarSaveCompleter;
    if (pending != null) {
      return pending.future;
    }
    return [
      for (final id in googleCalendarIds)
        <String, Object?>{
          'workspace_id': workspaceId,
          'google_calendar_id': id,
          'is_default_export': id == defaultExportCalendarId,
        },
    ];
  }

  @override
  Future<WorkspaceSyncResult> syncWorkspaceAll(
    int sourceWorkspaceId, {
    required int targetWorkspaceId,
    List<String>? resourceTypes,
  }) async {
    syncedAllSourceWorkspaceId = sourceWorkspaceId;
    syncedAllTargetWorkspaceId = targetWorkspaceId;
    return const WorkspaceSyncResult(tasks: 2, reminders: 1, calendarEvents: 3);
  }
}

class _ExpiredTokenHermesApiClient extends HermesApiClient {
  _ExpiredTokenHermesApiClient()
    : super(transport: (_) async => const HermesApiResponse(500, 'unused'));

  @override
  Future<HermesUser> me() async =>
      throw const HermesApiException(401, '{"message":"Unauthenticated."}');
}

class _NetworkDownRememberedTokenHermesApiClient extends HermesApiClient {
  _NetworkDownRememberedTokenHermesApiClient()
    : super(transport: (_) async => const HermesApiResponse(500, 'unused'));

  @override
  Future<HermesUser> me() async => throw const SocketException('offline');
}

class _FakeHermesApiClient extends HermesApiClient {
  _FakeHermesApiClient({
    this.onboardingCompleted = true,
    this.staleOnboardingAfterUpdate = false,
    this.staleSettingsAfterUpdate = false,
  }) : super(transport: (_) async => const HermesApiResponse(500, 'unused'));

  final bool onboardingCompleted;
  final bool staleOnboardingAfterUpdate;
  final bool staleSettingsAfterUpdate;
  final sentMessages = <String>[];
  final sentMessageMetadata = <Map<String, Object?>?>[];
  String? updatedEmail;
  bool deletedAccount = false;
  bool plannedToday = false;
  int todaySummaryCalls = 0;
  bool googleCalendarConnected = false;
  bool showDueReminderBanner = false;
  int googleCalendarSyncCalls = 0;
  List<String> selectedGoogleCalendarIds = <String>['primary'];
  String defaultGoogleCalendarId = 'primary';
  String? registeredAgentPersonality;
  List<String>? registeredPriorities;
  String? registeredContext;
  String? updatedAgentPersonality;
  List<String>? updatedPriorities;
  String? updatedContext;
  HermesNotificationPreferences updatedNotificationPreferences =
      const HermesNotificationPreferences();
  final passwordResetRequests = <String>[];
  HermesReminder? bannerUpdatedReminder;

  HermesUser _user({required String name, required String email}) {
    final persistedPersonality = staleSettingsAfterUpdate
        ? null
        : updatedAgentPersonality;
    final persistedPriorities = staleSettingsAfterUpdate
        ? null
        : updatedPriorities;
    final persistedContext = staleSettingsAfterUpdate ? null : updatedContext;

    return HermesUser(
      id: 1,
      name: name,
      email: email,
      onboardComplete:
          !staleOnboardingAfterUpdate &&
          (updatedAgentPersonality != null || onboardingCompleted),
      agentProfile: HermesAgentProfile(
        settings: {
          'personality_type': persistedPersonality ?? 'balanced',
          'onboarding': {
            'completed':
                !staleOnboardingAfterUpdate &&
                (updatedAgentPersonality != null || onboardingCompleted),
            'priorities': persistedPriorities ?? <String>[],
            'context': persistedContext,
          },
        },
      ),
      notificationPreferences: updatedNotificationPreferences,
    );
  }

  @override
  Future<HermesAuthResult> login({
    required String email,
    required String password,
  }) async {
    bearerToken = 'fake-token';
    return HermesAuthResult(
      token: 'fake-token',
      user: _user(name: 'Bean User', email: 'bean@example.com'),
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
      user: _user(name: name, email: email),
    );
  }

  @override
  Future<void> requestPasswordReset({required String email}) async {
    passwordResetRequests.add(email.trim().toLowerCase());
  }

  @override
  Future<HermesUser> me() async =>
      _user(name: 'Bean User', email: updatedEmail ?? 'bean@example.com');

  @override
  Future<HermesUser> updateMe({
    String? name,
    String? email,
    String? agentPersonality,
    List<String>? onboardingPriorities,
    String? onboardingContext,
    HermesNotificationPreferences? notificationPreferences,
  }) async {
    updatedEmail = email ?? updatedEmail;
    updatedAgentPersonality = agentPersonality ?? updatedAgentPersonality;
    updatedPriorities = onboardingPriorities ?? updatedPriorities;
    updatedContext = onboardingContext ?? updatedContext;
    updatedNotificationPreferences =
        notificationPreferences ?? updatedNotificationPreferences;
    return _user(
      name: name ?? 'Bean User',
      email: updatedEmail ?? 'bean@example.com',
    );
  }

  @override
  Future<HermesSession> startSession({
    String? title,
    String? runtimeMode,
    int? workspaceId,
    Map<String, Object?>? metadata,
  }) async => HermesSession(
    id: 42,
    status: 'active',
    workspaceId: workspaceId,
    title: 'Today',
  );

  @override
  Future<List<HermesTask>> listTasks() async => plannedToday
      ? const [
          HermesTask(id: 10, title: 'Generated follow-up task', status: 'open'),
        ]
      : const [
          HermesTask(
            id: 1,
            title: 'Plan launch',
            status: 'open',
            isCritical: true,
          ),
        ];

  @override
  Future<List<HermesTask>> listPastTasks() async => const [];

  @override
  Future<List<HermesReminder>> listReminders() async {
    if (plannedToday) {
      return [
        HermesReminder(
          id: 20,
          title: 'Stretch and hydrate',
          dueAt: DateTime.now().add(const Duration(hours: 1)).toIso8601String(),
          category: 'Health',
          color: '#34C759',
        ),
      ];
    }
    return [
      HermesReminder(
        id: 2,
        title: 'Stand up',
        isCritical: true,
        dueAt: DateTime.now()
            .subtract(
              showDueReminderBanner
                  ? const Duration(hours: 1)
                  : const Duration(days: 1),
            )
            .copyWith(
              hour: showDueReminderBanner ? DateTime.now().hour : 9,
              minute: 0,
              second: 0,
              millisecond: 0,
            )
            .toIso8601String(),
        status: bannerUpdatedReminder?.status ?? 'pending',
      ),
    ];
  }

  @override
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
    bannerUpdatedReminder = HermesReminder(
      id: reminderId,
      title: title ?? 'Stand up',
      status: status ?? 'pending',
      dueAt: remindAt ?? DateTime.now().toIso8601String(),
      isCritical: isCritical ?? true,
      category: clearCategory ? null : category,
      color: clearColor ? null : color,
      calendarEventId: calendarEventId,
      metadata: metadata,
    );
    return bannerUpdatedReminder!;
  }

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
            isCritical: true,
          ),
        ];

  @override
  Future<HermesTodaySummary> todaySummary({int? workspaceId}) async {
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
  Future<GoogleCalendarSyncStatus> googleCalendarStatus() async =>
      GoogleCalendarSyncStatus(
        connected: googleCalendarConnected,
        status: googleCalendarConnected ? 'connected' : 'not_connected',
        calendarId: defaultGoogleCalendarId,
        defaultCalendarId: defaultGoogleCalendarId,
        selectedCalendarIds: selectedGoogleCalendarIds,
        calendars: googleCalendarConnected
            ? [
                GoogleCalendarInfo(
                  id: 'primary',
                  summary: 'Personal',
                  primary: true,
                  selected: selectedGoogleCalendarIds.contains('primary'),
                  accessRole: 'owner',
                ),
                GoogleCalendarInfo(
                  id: 'holidays@example.com',
                  summary: 'Holidays',
                  selected: selectedGoogleCalendarIds.contains(
                    'holidays@example.com',
                  ),
                  accessRole: 'reader',
                ),
                GoogleCalendarInfo(
                  id: 'sports@example.com',
                  summary: 'Sports',
                  selected: selectedGoogleCalendarIds.contains(
                    'sports@example.com',
                  ),
                  accessRole: 'writer',
                ),
              ]
            : const [],
        lastSyncedAt: googleCalendarConnected
            ? DateTime.now().toIso8601String()
            : null,
      );

  @override
  Future<GoogleCalendarSyncStatus> updateGoogleCalendarSelection({
    required List<String> selectedCalendarIds,
    String? defaultCalendarId,
  }) async {
    selectedGoogleCalendarIds = selectedCalendarIds;
    defaultGoogleCalendarId = defaultCalendarId ?? defaultGoogleCalendarId;
    googleCalendarConnected = true;
    return googleCalendarStatus();
  }

  @override
  Future<String> googleCalendarAuthUrl() async {
    googleCalendarConnected = true;
    return 'https://accounts.google.com/o/oauth2/v2/auth?client_id=fake';
  }

  @override
  Future<GoogleCalendarSyncResult> syncGoogleCalendar() async {
    googleCalendarSyncCalls++;
    googleCalendarConnected = true;
    return GoogleCalendarSyncResult(
      imported: 2,
      deleted: 0,
      status: await googleCalendarStatus(),
    );
  }

  @override
  Future<GoogleCalendarSyncStatus> disconnectGoogleCalendar() async {
    googleCalendarConnected = false;
    return googleCalendarStatus();
  }

  @override
  Future<HermesMessageResult> sendMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
  }) async {
    sentMessages.add(content);
    sentMessageMetadata.add(metadata);
    var isPreferenceOnlyMessage = false;
    if (metadata?['settings_update'] == true) {
      isPreferenceOnlyMessage = true;
      updatedAgentPersonality =
          metadata?['agent_personality']?.toString() ?? updatedAgentPersonality;
      final metadataPriorities = metadata?['onboarding_priorities'];
      if (metadataPriorities is List) {
        updatedPriorities = metadataPriorities
            .map((value) => value.toString())
            .toList();
      }
      updatedContext =
          metadata?['onboarding_context']?.toString() ?? updatedContext;
    } else if (content.toLowerCase().contains('i am bean user')) {
      updatedAgentPersonality = 'coach';
      updatedPriorities = ['Family', 'Reminders', 'Planning'];
      updatedContext = content;
    }
    final isIntroductionMessage = content.toLowerCase().contains(
      'i am bean user',
    );
    if (!isPreferenceOnlyMessage && !isIntroductionMessage) {
      plannedToday = true;
    }
    return HermesMessageResult(
      status: 'completed',
      session: const HermesSession(id: 42, status: 'active', title: 'Today'),
      assistantMessage: HermesMessage(
        id: 8,
        role: 'assistant',
        content: isIntroductionMessage
            ? 'Nice to meet you — I saved those Bean preferences.'
            : 'Done — I updated your day.',
      ),
      events: const [
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

class _TomorrowReminderFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesTask>> listTasks() async {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day, 10);
    final tomorrow = today.add(const Duration(days: 1, hours: 4));
    return [
      HermesTask(
        id: 201,
        title: 'Today task',
        status: 'open',
        dueAt: today.toIso8601String(),
      ),
      HermesTask(
        id: 202,
        title: 'Tomorrow task',
        status: 'open',
        dueAt: tomorrow.toIso8601String(),
      ),
    ];
  }

  @override
  Future<List<HermesReminder>> listReminders() async {
    final now = DateTime.now();
    final today = now.add(const Duration(minutes: 15));
    final tomorrow = DateTime(now.year, now.month, now.day + 1, 14);
    return [
      HermesReminder(
        id: 301,
        title: 'Today reminder',
        dueAt: today.toIso8601String(),
      ),
      HermesReminder(
        id: 302,
        title: 'Tomorrow reminder',
        dueAt: tomorrow.toIso8601String(),
      ),
    ];
  }
}

class _EditableReminderFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  HermesReminder? updatedReminder;
  late List<HermesReminder> _reminders = [
    HermesReminder(
      id: 401,
      title: 'Refill dog food',
      status: 'pending',
      dueAt: DateTime.now().add(const Duration(hours: 2)).toIso8601String(),
    ),
  ];

  @override
  Future<List<HermesReminder>> listReminders() async => _reminders;

  @override
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
    final existing = _reminders.firstWhere((item) => item.id == reminderId);
    final updated = HermesReminder(
      id: existing.id,
      title: title ?? existing.title,
      status: status ?? existing.status,
      dueAt: remindAt ?? existing.dueAt,
      category: clearCategory ? null : category ?? existing.category,
      color: clearColor ? null : color ?? existing.color,
      isCritical: isCritical ?? existing.isCritical,
      calendarEventId: calendarEventId ?? existing.calendarEventId,
      metadata: metadata ?? existing.metadata,
    );
    updatedReminder = updated;
    _reminders = _reminders
        .map((item) => item.id == reminderId ? updated : item)
        .toList();
    return updated;
  }
}

class _TaskReminderCategoryFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  HermesTask? updatedTask;
  HermesTask? createdTask;
  HermesReminder? updatedReminder;
  HermesReminder? createdReminder;
  HermesEventCategory? savedCategory;

  @override
  Future<List<HermesEventCategory>> listEventCategories() async => [
    const HermesEventCategory(id: 10, name: 'Work', color: '#007AFF'),
    if (savedCategory != null) savedCategory!,
  ];

  @override
  Future<HermesEventCategory> createEventCategory({
    required String name,
    required String color,
  }) async {
    savedCategory = HermesEventCategory(id: 11, name: name, color: color);
    return savedCategory!;
  }

  @override
  Future<List<HermesTask>> listTasks() async => [
    if (createdTask != null) createdTask!,
    updatedTask ??
        HermesTask(
          id: 501,
          title: 'Categorize proposal',
          status: 'open',
          dueAt: DateTime.now().add(const Duration(hours: 3)).toIso8601String(),
        ),
  ];

  @override
  Future<List<HermesReminder>> listReminders() async => [
    if (createdReminder != null) createdReminder!,
    updatedReminder ??
        HermesReminder(
          id: 601,
          title: 'Categorize reminder',
          status: 'pending',
          category: 'Work',
          color: '#007AFF',
          dueAt: DateTime.now().add(const Duration(hours: 4)).toIso8601String(),
        ),
  ];

  @override
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
    createdTask = HermesTask(
      id: 801,
      title: title,
      status: status,
      dueAt: dueAt,
      category: category,
      color: color,
      isCritical: isCritical,
      metadata: metadata,
    );
    return createdTask!;
  }

  @override
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
    createdReminder = HermesReminder(
      id: 701,
      title: title,
      dueAt: remindAt,
      category: category,
      color: color,
      isCritical: isCritical,
      status: status,
      calendarEventId: calendarEventId,
      metadata: metadata,
    );
    return createdReminder!;
  }

  @override
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
    final existing = (await listTasks()).firstWhere(
      (item) => item.id == taskId,
    );
    updatedTask = HermesTask(
      id: existing.id,
      title: title ?? existing.title,
      status: status ?? existing.status,
      dueAt: dueAt ?? existing.dueAt,
      category: clearCategory ? null : category ?? existing.category,
      color: clearColor ? null : color ?? existing.color,
      isCritical: isCritical ?? existing.isCritical,
      completedAt: completedAt ?? existing.completedAt,
      metadata: metadata ?? existing.metadata,
    );
    return updatedTask!;
  }

  @override
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
    final existing = (await listReminders()).firstWhere(
      (item) => item.id == reminderId,
    );
    updatedReminder = HermesReminder(
      id: existing.id,
      title: title ?? existing.title,
      dueAt: remindAt ?? existing.dueAt,
      category: clearCategory ? null : category ?? existing.category,
      color: clearColor ? null : color ?? existing.color,
      isCritical: isCritical ?? existing.isCritical,
      status: status ?? existing.status,
      completedAt: existing.completedAt,
      calendarEventId: calendarEventId ?? existing.calendarEventId,
      metadata: metadata ?? existing.metadata,
    );
    return updatedReminder!;
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
      HermesCalendarEvent(
        id: 103,
        title: 'Daily standup',
        startsAt: DateTime(
          selectedDay.year,
          selectedDay.month,
          selectedDay.day - 1,
          11,
        ).toIso8601String(),
        recurrence: 'daily',
      ),
    ];
  }
}

class _EarlyCalendarFakeHermesApiClient extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents() async {
    final selectedDay = DateTime.now();
    return [
      HermesCalendarEvent(
        id: 106,
        title: 'Early flight',
        startsAt: DateTime(
          selectedDay.year,
          selectedDay.month,
          selectedDay.day,
          6,
        ).toIso8601String(),
        endsAt: DateTime(
          selectedDay.year,
          selectedDay.month,
          selectedDay.day,
          6,
          45,
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
      category: 'Travel',
      color: '#007AFF',
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
  Future<List<HermesTask>> listTasks() async => [
    if (_pastTaskReopened)
      HermesTask(
        id: 201,
        title: 'Archived oil change',
        status: 'open',
        dueAt: DateTime.now().toIso8601String(),
      ),
    ..._activeTasks,
  ];

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

class _ChatRefreshOverdueTaskFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  bool _askedAboutDay = false;

  @override
  Future<List<HermesTask>> listTasks() async {
    if (!_askedAboutDay) return const [];
    return [
      HermesTask(
        id: 301,
        title: 'Cut grass',
        status: 'open',
        dueAt: DateTime.now()
            .subtract(const Duration(days: 1))
            .toIso8601String(),
      ),
    ];
  }

  @override
  Future<HermesTodaySummary> todaySummary({int? workspaceId}) async =>
      HermesTodaySummary(
        tasks: const [],
        reminders: const [],
        calendarEvents: await listCalendarEvents(),
        activityEvents: await pollActivityEvents(42),
        approvals: const [],
        blockers: const [],
      );

  @override
  Future<HermesMessageResult> sendMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
  }) async {
    sentMessages.add(content);
    _askedAboutDay = true;
    return const HermesMessageResult(
      status: 'completed',
      session: HermesSession(id: 42, status: 'active', title: 'Today'),
      assistantMessage: HermesMessage(
        id: 8,
        role: 'assistant',
        content: 'You have an overdue task: Cut grass.',
      ),
      events: [],
    );
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
  _EditableCalendarFakeHermesApiClient() {
    googleCalendarConnected = true;
  }

  HermesCalendarEvent? updatedEvent;
  HermesCalendarEvent? createdEvent;
  Map<String, Object?>? createdReminder;
  HermesEventCategory? savedCategory;
  int? deletedCategoryId;
  int? deletedEventId;

  @override
  Future<HermesCalendarEvent> updateCalendarEvent(
    int eventId, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? category,
    String? color,
    String? recurrence,
    bool? isCritical,
    Map<String, Object?>? metadata,
    List<Object> syncToWorkspaceIds = const [],
  }) async {
    updatedEvent = HermesCalendarEvent(
      id: eventId,
      title: title,
      startsAt: startsAt,
      endsAt: endsAt,
      category: category,
      color: color,
      recurrence: recurrence,
      isCritical: isCritical ?? false,
      metadata: metadata,
    );
    return updatedEvent!;
  }

  @override
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
    createdEvent = HermesCalendarEvent(
      id: 44,
      title: title,
      startsAt: startsAt,
      endsAt: endsAt,
      category: category,
      color: color,
      recurrence: recurrence,
      isCritical: isCritical ?? false,
      metadata: metadata,
    );
    return createdEvent!;
  }

  @override
  Future<HermesReminder> createEventReminder({
    required int calendarEventId,
    required String title,
    required String remindAt,
    Map<String, Object?>? metadata,
    int? workspaceId,
    List<Object> syncToWorkspaceIds = const [],
  }) async {
    createdReminder = {
      'calendar_event_id': calendarEventId,
      'title': title,
      'remind_at': remindAt,
      'metadata': metadata,
    };
    return HermesReminder(id: 99, title: title, dueAt: remindAt);
  }

  @override
  Future<List<HermesEventCategory>> listEventCategories() async => const [
    HermesEventCategory(id: 10, name: 'Work', color: '#007AFF'),
  ];

  @override
  Future<HermesEventCategory> createEventCategory({
    required String name,
    required String color,
  }) async {
    savedCategory = HermesEventCategory(id: 11, name: name, color: color);
    return savedCategory!;
  }

  @override
  Future<HermesEventCategory> updateEventCategory(
    int categoryId, {
    required String name,
    required String color,
  }) async {
    savedCategory = HermesEventCategory(
      id: categoryId,
      name: name,
      color: color,
    );
    return savedCategory!;
  }

  @override
  Future<void> deleteEventCategory(int categoryId) async {
    deletedCategoryId = categoryId;
  }

  @override
  Future<void> deleteCalendarEvent(int eventId) async {
    deletedEventId = eventId;
  }

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents() async => [
    if (createdEvent != null) createdEvent!,
    if (deletedEventId != 3)
      updatedEvent ??
          HermesCalendarEvent(
            id: 3,
            title: 'Design review',
            startsAt: DateTime.utc(
              DateTime.now().year,
              DateTime.now().month,
              DateTime.now().day,
              14,
              30,
            ).toIso8601String(),
            endsAt: DateTime.utc(
              DateTime.now().year,
              DateTime.now().month,
              DateTime.now().day,
              15,
              00,
            ).toIso8601String(),
            category: 'Personal',
            color: '#34C759',
            recurrence: 'none',
          ),
  ];
}

class _CustomColorEditableCalendarFakeHermesApiClient
    extends _EditableCalendarFakeHermesApiClient {
  @override
  Future<List<HermesEventCategory>> listEventCategories() async => const [
    HermesEventCategory(id: 20, name: 'Studio', color: '#123ABC'),
  ];

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents() async => [
    if (createdEvent != null) createdEvent!,
    updatedEvent ??
        HermesCalendarEvent(
          id: 3,
          title: 'Design review',
          startsAt: DateTime.utc(
            DateTime.now().year,
            DateTime.now().month,
            DateTime.now().day,
            14,
            30,
          ).toIso8601String(),
          endsAt: DateTime.utc(
            DateTime.now().year,
            DateTime.now().month,
            DateTime.now().day,
            15,
            00,
          ).toIso8601String(),
          category: 'Studio',
          color: '#123ABC',
          recurrence: 'none',
        ),
  ];
}

class _GoogleCalendarAutoSyncFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  _GoogleCalendarAutoSyncFakeHermesApiClient() {
    googleCalendarConnected = true;
  }

  @override
  Future<GoogleCalendarSyncResult> syncGoogleCalendar() async {
    googleCalendarSyncCalls++;
    googleCalendarConnected = true;
    plannedToday = true;
    return GoogleCalendarSyncResult(
      imported: 1,
      deleted: 0,
      status: await googleCalendarStatus(),
    );
  }

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents() async => plannedToday
      ? [
          HermesCalendarEvent(
            id: 909,
            title: 'Imported Google event',
            startsAt: DateTime(
              DateTime.now().year,
              DateTime.now().month,
              DateTime.now().day,
              9,
            ).toIso8601String(),
          ),
        ]
      : super.listCalendarEvents();
}

class _AllDayGoogleCalendarFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents() async {
    final today = DateTime.now();
    final start = DateTime(today.year, today.month, today.day);
    return [
      HermesCalendarEvent(
        id: 901,
        title: 'Google holiday',
        startsAt: start.toIso8601String(),
        endsAt: start.add(const Duration(days: 1)).toIso8601String(),
        category: 'Google Calendar',
        color: '#4285F4',
        metadata: const {
          'source': 'google_calendar',
          'google_calendar_id': 'holidays@example.com',
          'all_day': true,
        },
      ),
    ];
  }
}

class _MultiDayEditableCalendarFakeHermesApiClient
    extends _EditableCalendarFakeHermesApiClient {
  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents() async {
    final tomorrow = DateTime.now().add(const Duration(days: 1));
    return [
      updatedEvent ??
          HermesCalendarEvent(
            id: 40,
            title: 'Conference',
            startsAt: DateTime(
              tomorrow.year,
              tomorrow.month,
              tomorrow.day,
              9,
            ).toIso8601String(),
            endsAt: DateTime(
              tomorrow.year,
              tomorrow.month,
              tomorrow.day,
              10,
            ).toIso8601String(),
            category: 'Work',
            color: '#007AFF',
            recurrence: 'none',
          ),
    ];
  }
}

class _UtcMultiDayCalendarFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents() async {
    final today = DateTime.now();
    final tomorrow = today.add(const Duration(days: 1));
    return [
      HermesCalendarEvent(
        id: 31,
        title: 'App project focus block',
        startsAt: DateTime.utc(
          today.year,
          today.month,
          today.day,
          14,
        ).toIso8601String(),
        endsAt: DateTime.utc(
          tomorrow.year,
          tomorrow.month,
          tomorrow.day,
          15,
        ).toIso8601String(),
        category: 'Work',
        color: '#007AFF',
        recurrence: 'none',
      ),
    ];
  }
}

class _OffsetCalendarFakeHermesApiClient extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents() async {
    final today = DateTime.now();
    final tomorrow = today.add(const Duration(days: 1));
    final startDay =
        '${today.year.toString().padLeft(4, '0')}-${today.month.toString().padLeft(2, '0')}-${today.day.toString().padLeft(2, '0')}';
    final endDay =
        '${tomorrow.year.toString().padLeft(4, '0')}-${tomorrow.month.toString().padLeft(2, '0')}-${tomorrow.day.toString().padLeft(2, '0')}';
    return [
      HermesCalendarEvent(
        id: 32,
        title: 'Google afternoon block',
        startsAt: '${startDay}T13:00:00-04:00',
        endsAt: '${endDay}T17:00:00-04:00',
        category: 'Google Calendar',
        color: '#4285F4',
        recurrence: 'none',
      ),
    ];
  }
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

String _testNaturalTimeLabel(DateTime value) {
  var hour = value.hour % 12;
  if (hour == 0) hour = 12;
  final minute = value.minute == 0
      ? ''
      : ':${value.minute.toString().padLeft(2, '0')}';
  final meridiem = value.hour >= 12 ? 'pm' : 'am';
  return '$hour$minute$meridiem';
}

String _testIsoWithOffset(
  DateTime value, {
  required int hour,
  required String offset,
}) {
  final year = value.year.toString().padLeft(4, '0');
  final month = value.month.toString().padLeft(2, '0');
  final day = value.day.toString().padLeft(2, '0');
  final hourText = hour.toString().padLeft(2, '0');
  return '$year-$month-${day}T$hourText:00:00$offset';
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

Finder _topHeaderDayLabelFinder() {
  final now = DateTime.now();
  final label =
      '${_testCompactWeekdayNames[now.weekday - 1]} ${_testShortMonthNames[now.month - 1]} ${now.day}';
  return find.descendant(
    of: find.byKey(const Key('calendar-today-button')),
    matching: find.text(label),
  );
}

Finder _topHeaderMonthLabelFinder() {
  final now = DateTime.now();
  final label =
      "${_testShortMonthNames[now.month - 1]} '${(now.year % 100).toString().padLeft(2, '0')}";
  return find.descendant(
    of: find.byKey(const Key('calendar-month-chevron')),
    matching: find.text(label),
  );
}

const _testShortMonthNames = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
];

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
  'Tues',
  'Wed',
  'Thurs',
  'Fri',
  'Sat',
  'Sun',
];

const _testCompactWeekdayNames = [
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
