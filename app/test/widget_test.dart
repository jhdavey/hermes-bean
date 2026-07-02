import 'dart:async';
import 'dart:io';
import 'dart:math' as math;

import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:hermes_bean_app/bean_realtime_conversation.dart';
import 'package:hermes_bean_app/hermes_api_client.dart';
import 'package:hermes_bean_app/main.dart';

Future<void> openSettingsFromBottomNav(WidgetTester tester) async {
  await tester.tap(find.byKey(const Key('nav-more')));
  await tester.pumpAndSettle();
  await tester.tap(find.text('Settings').last);
  await tester.pumpAndSettle();
}

Future<void> openNotesFromBottomNav(WidgetTester tester) async {
  await tester.tap(find.byKey(const Key('nav-notes')));
  await tester.pumpAndSettle();
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    HeyBeanTheme.useTheme('green', brightness: Brightness.light);
  });

  test('realtime voice cancellation phrases end the active conversation', () {
    expect(realtimeVoiceCancelForTesting('never mind'), isTrue);
    expect(realtimeVoiceCancelForTesting('stop talking Bean'), isTrue);
    expect(realtimeVoiceCancelForTesting('Hey Bean what time is it'), isFalse);
  });

  test('realtime dashboard refresh keeps the required session type', () {
    final payload = realtimeSessionUpdatePayloadForTesting('Fresh context');
    expect(payload['type'], 'session.update');
    expect(payload['session'], isA<Map<String, Object?>>());
    expect(payload['session'], containsPair('type', 'realtime'));
    expect(payload['session'], containsPair('instructions', 'Fresh context'));
  });

  testWidgets('notes screen opens as list and drills into note detail', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = const Size(400, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _NotesFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    await openNotesFromBottomNav(tester);

    expect(find.byKey(const Key('notes-view')), findsOneWidget);
    expect(find.byKey(const Key('notes-list-menu')), findsOneWidget);
    expect(find.byKey(const Key('notes-folder-title')), findsOneWidget);
    expect(find.text('All Notes'), findsOneWidget);
    expect(find.text('Meeting notes'), findsWidgets);
    expect(find.text('Pinned'), findsOneWidget);
    expect(find.byKey(const Key('note-detail-back')), findsNothing);

    await tester.tap(find.byKey(const Key('note-list-item-1')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('note-detail-back')), findsOneWidget);
    expect(find.byKey(const Key('note-detail-menu')), findsOneWidget);
    expect(find.text('Bring the launch plan.'), findsOneWidget);

    await tester.tap(find.byKey(const Key('note-detail-back')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('notes-list-screen')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('notes list shows selected folder title', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _NotesFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    await openNotesFromBottomNav(tester);
    await tester.tap(find.byKey(const Key('notes-list-menu')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('notes-filter-folder-1')));
    await tester.pumpAndSettle();

    expect(find.text('Work'), findsOneWidget);
    expect(find.byKey(const Key('notes-folder-title')), findsOneWidget);
  });

  testWidgets('note formatting toolbar saves rendered formatting metadata', (
    WidgetTester tester,
  ) async {
    final api = _NotesFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await openNotesFromBottomNav(tester);
    await tester.tap(find.byKey(const Key('note-list-item-1')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('note-body-field')), '');
    await tester.pump();
    await tester.tap(find.byKey(const Key('note-format-bold')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('note-body-field')),
      'Launch plan',
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('note-detail-back')));
    await tester.pumpAndSettle();

    expect(api.updatedNotes, isNotEmpty);
    expect(api.updatedNotes.last.plainText, 'Launch plan');
    expect(
      api.updatedNotes.last.bodyHtml,
      contains('<strong>Launch plan</strong>'),
    );
    expect(api.updatedNotes.last.bodyHtml, isNot(contains('**')));
    expect(api.updatedNotes.last.metadata['flutter_note_formats'], isA<List>());
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'note formatting toolbar formats selected text without placeholders',
    (WidgetTester tester) async {
      final api = _NotesFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await openNotesFromBottomNav(tester);
      await tester.tap(find.byKey(const Key('note-list-item-1')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('note-body-field')),
        'plain words',
      );
      tester.testTextInput.updateEditingValue(
        const TextEditingValue(
          text: 'plain words',
          selection: TextSelection(baseOffset: 0, extentOffset: 5),
        ),
      );
      await tester.pump();
      await tester.tap(find.byKey(const Key('note-format-bold')));
      await tester.pumpAndSettle();
      expect(find.text('H1').hitTestable(), findsOneWidget);
      await tester.tap(find.byKey(const Key('note-detail-back')));
      await tester.pumpAndSettle();

      expect(api.updatedNotes.last.plainText, 'plain words');
      expect(
        api.updatedNotes.last.bodyHtml,
        contains('<strong>plain</strong>'),
      );
      expect(api.updatedNotes.last.bodyHtml, isNot(contains('Bold text')));
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('note checkbox toolbar creates only unchecked checkbox items', (
    WidgetTester tester,
  ) async {
    final api = _NotesFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await openNotesFromBottomNav(tester);
    await tester.tap(find.byKey(const Key('note-list-item-1')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('note-body-field')),
      'Buy milk',
    );
    await tester.tap(find.byKey(const Key('note-format-checkbox')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('note-format-checkbox')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('note-detail-back')));
    await tester.pumpAndSettle();

    expect(api.updatedNotes.last.plainText, '☐ Buy milk');
    expect(tester.takeException(), isNull);
  });

  testWidgets('note checkbox formatting respects indented lines', (
    WidgetTester tester,
  ) async {
    final api = _NotesFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await openNotesFromBottomNav(tester);
    await tester.tap(find.byKey(const Key('note-list-item-1')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('note-body-field')),
      '  Buy milk',
    );
    await tester.tap(find.byKey(const Key('note-format-checkbox')));
    await tester.pumpAndSettle();

    expect(
      find.byWidgetPredicate(
        (widget) => widget.runtimeType.toString() == '_NoteCheckboxMarker',
      ),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const Key('note-detail-back')));
    await tester.pumpAndSettle();

    expect(api.updatedNotes.last.plainText, '  ☐ Buy milk');
    expect(tester.takeException(), isNull);
  });

  testWidgets('note list format buttons convert the current marker', (
    WidgetTester tester,
  ) async {
    final api = _NotesFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await openNotesFromBottomNav(tester);
    await tester.tap(find.byKey(const Key('note-list-item-1')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('note-body-field')),
      '  • Buy milk',
    );
    await tester.tap(find.byKey(const Key('note-format-checkbox')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('note-format-bullet-list')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('note-detail-back')));
    await tester.pumpAndSettle();

    expect(api.updatedNotes.last.plainText, '  • Buy milk');
    expect(
      api.updatedNotes.last.metadata['flutter_note_formats'],
      isNot(
        contains(
          allOf(
            containsPair('kind', 'checkbox_checked'),
            containsPair('start', 2),
          ),
        ),
      ),
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('note editor continues checkbox items after return', (
    WidgetTester tester,
  ) async {
    final api = _NotesFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await openNotesFromBottomNav(tester);
    await tester.tap(find.byKey(const Key('note-list-item-1')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('note-body-field')),
      '☐ Buy milk',
    );
    await tester.pump();
    tester.testTextInput.updateEditingValue(
      const TextEditingValue(
        text: '☐ Buy milk\n',
        selection: TextSelection.collapsed(offset: 11),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('note-detail-back')));
    await tester.pumpAndSettle();

    expect(api.updatedNotes.last.plainText, '☐ Buy milk\n☐ ');
    expect(tester.takeException(), isNull);
  });

  testWidgets('note editor keeps indentation after return', (
    WidgetTester tester,
  ) async {
    final api = _NotesFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await openNotesFromBottomNav(tester);
    await tester.tap(find.byKey(const Key('note-list-item-1')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('note-body-field')),
      '  Project',
    );
    await tester.pump();
    tester.testTextInput.updateEditingValue(
      const TextEditingValue(
        text: '  Project\n',
        selection: TextSelection.collapsed(offset: 10),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('note-detail-back')));
    await tester.pumpAndSettle();

    expect(api.updatedNotes.last.plainText, '  Project\n  ');
    expect(tester.takeException(), isNull);
  });

  testWidgets('note editor continues bullet items and indents current line', (
    WidgetTester tester,
  ) async {
    final api = _NotesFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await openNotesFromBottomNav(tester);
    await tester.tap(find.byKey(const Key('note-list-item-1')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('note-body-field')), '• First');
    await tester.pump();
    tester.testTextInput.updateEditingValue(
      const TextEditingValue(
        text: '• First\n',
        selection: TextSelection.collapsed(offset: 8),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('note-format-indent')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('note-detail-back')));
    await tester.pumpAndSettle();

    expect(api.updatedNotes.last.plainText, '• First\n  • ');
    expect(tester.takeException(), isNull);
  });

  testWidgets('tapping an existing note checkbox checks and unchecks it', (
    WidgetTester tester,
  ) async {
    final api = _NotesFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await openNotesFromBottomNav(tester);
    await tester.tap(find.byKey(const Key('note-list-item-1')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('note-body-field')),
      '☐ Buy milk',
    );
    await tester.pumpAndSettle();

    final checkboxTap =
        tester.getTopLeft(find.byKey(const Key('note-body-field'))) +
        const Offset(8, 14);
    await tester.tapAt(checkboxTap);
    await tester.pumpAndSettle();
    expect(find.text('☑ Buy milk'), findsNothing);
    expect(
      find.byWidgetPredicate(
        (widget) => widget.runtimeType.toString() == '_NoteCheckboxMarker',
      ),
      findsOneWidget,
    );

    await tester.tapAt(checkboxTap);
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('note-detail-back')));
    await tester.pumpAndSettle();

    expect(api.updatedNotes.last.plainText, '☐ Buy milk');
    expect(
      api.updatedNotes.last.metadata['flutter_note_formats'],
      isNot(
        contains(
          allOf(
            containsPair('kind', 'checkbox_checked'),
            containsPair('start', 0),
          ),
        ),
      ),
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'tapping outside a focused note editor closes the keyboard toolbar',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _NotesFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();

      await openNotesFromBottomNav(tester);
      await tester.tap(find.byKey(const Key('note-list-item-1')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('note-body-field')));
      await tester.pumpAndSettle();

      expect(find.text('H1').hitTestable(), findsOneWidget);

      await tester.tapAt(const Offset(400, 60));
      await tester.pumpAndSettle();

      expect(find.text('H1').hitTestable(), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('note menu can edit synced workspaces', (
    WidgetTester tester,
  ) async {
    final api = _NotesFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await openNotesFromBottomNav(tester);
    await tester.tap(find.byKey(const Key('note-list-item-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('note-detail-menu')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('note-workspaces-action')));
    await tester.pumpAndSettle();

    expect(find.text('Note workspaces'), findsOneWidget);
    expect(find.byKey(const Key('note-sync-workspace-2')), findsOneWidget);

    await tester.tap(find.byKey(const Key('note-sync-workspace-2')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('note-sync-workspaces-save')));
    await tester.pumpAndSettle();

    expect(api.updatedNoteSyncWorkspaceIds.last, isEmpty);
    expect(tester.takeException(), isNull);
  });

  testWidgets('top create menu can create a note and open its detail screen', (
    WidgetTester tester,
  ) async {
    final api = _NotesFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await _openCreateMenuAndChoose(tester, const Key('create-note-action'));

    expect(api.createdNotes, hasLength(1));
    expect(find.byKey(const Key('note-detail-back')), findsOneWidget);
    expect(find.text('New Note'), findsWidgets);
    expect(tester.takeException(), isNull);
  });

  testWidgets('notes list new folder dialog can be canceled safely', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _NotesFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    await openNotesFromBottomNav(tester);
    await tester.tap(find.byKey(const Key('notes-list-menu')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('notes-new-folder')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('new-note-folder-name')), findsOneWidget);

    await tester.tap(find.text('Cancel').last);
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('new-note-folder-name')), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('notes list options can delete a folder', (
    WidgetTester tester,
  ) async {
    final api = _NotesFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await openNotesFromBottomNav(tester);
    await tester.tap(find.byKey(const Key('notes-list-menu')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('notes-filter-folder-1')), findsOneWidget);

    await tester.tap(find.byKey(const Key('delete-note-folder-1')));
    await tester.pumpAndSettle();

    expect(find.text('Delete folder?'), findsOneWidget);

    await tester.tap(find.text('Delete').last);
    await tester.pumpAndSettle();

    expect(api.deletedFolderIds, [1]);
    expect(find.text('Notes options'), findsOneWidget);
    expect(find.byKey(const Key('notes-filter-folder-1')), findsNothing);
    expect(tester.takeException(), isNull);
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

  testWidgets('guided Bean signup creates account and starts plan checkout', (
    WidgetTester tester,
  ) async {
    final api = _FakeHermesApiClient();
    final tokenStore = _MemoryAuthTokenStore();
    final stripeHandler = _FakeStripePaymentHandler();
    final launchedUrls = <Uri>[];
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: api,
        tokenStore: tokenStore,
        stripePaymentHandler: stripeHandler,
        launchExternalUrl: (url) async {
          launchedUrls.add(url);
          return true;
        },
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('guided-signup-action')));
    await tester.pumpAndSettle();
    expect(find.text('Hello, please enter your name below.'), findsOneWidget);
    expect(find.text('Please hold to talk, or tap to text'), findsNothing);
    expect(find.text('Bean setup'), findsNothing);
    expect(find.byKey(const Key('guided-onboarding-input')), findsOneWidget);
    expect(find.text('Name'), findsOneWidget);
    expect(find.byKey(const Key('guided-initial-bean-button')), findsOneWidget);
    final initialBeanLogo = tester.widget<Image>(
      find.byKey(const Key('heybean-center-bean-logo')),
    );
    expect(
      (initialBeanLogo.image as AssetImage).assetName,
      'assets/images/bean/bean-logo.png',
    );

    await tester.enterText(
      find.byKey(const Key('guided-onboarding-input')),
      'testing',
    );
    await tester.tap(find.byKey(const Key('guided-onboarding-send')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('guided-initial-bean-button')), findsOneWidget);
    expect(find.byKey(const Key('guided-onboarding-input')), findsOneWidget);
    expect(
      find.text(
        'Nice to meet you, testing. Do you prefer light or dark mode? You can also choose Auto, and you can change this anytime in Appearance settings.',
      ),
      findsOneWidget,
    );
    expect(find.byKey(const Key('guided-theme-mode-light')), findsOneWidget);
    expect(find.byKey(const Key('guided-theme-mode-dark')), findsOneWidget);
    expect(find.byKey(const Key('guided-theme-mode-auto')), findsOneWidget);

    await tester.tap(find.byKey(const Key('guided-theme-mode-dark')));
    await tester.pump();
    expect(
      Theme.of(
        tester.element(find.byKey(const Key('guided-onboarding-input'))),
      ).brightness,
      Brightness.dark,
    );
    final darkBeanLogo = tester.widget<Image>(
      find.byKey(const Key('heybean-center-bean-logo')),
    );
    expect(
      (darkBeanLogo.image as AssetImage).assetName,
      'assets/images/bean/bean-logo-white-overlay.png',
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('What email address'), findsOneWidget);

    api.takenEmails.add('taken@example.com');
    await tester.enterText(
      find.byKey(const Key('guided-onboarding-input')),
      'taken@example.com',
    );
    await tester.tap(find.byKey(const Key('guided-onboarding-send')));
    await tester.pumpAndSettle();
    expect(find.textContaining('already'), findsOneWidget);
    expect(find.textContaining('choose a password'), findsNothing);
    expect(api.registeredUsers, isEmpty);

    await tester.enterText(
      find.byKey(const Key('guided-onboarding-input')),
      'test@email.com',
    );
    await tester.tap(find.byKey(const Key('guided-onboarding-send')));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const Key('guided-onboarding-input')),
      'password1234',
    );
    await tester.tap(find.byKey(const Key('guided-onboarding-send')));
    await tester.pumpAndSettle();

    expect(api.registeredUsers, [
      {
        'name': 'testing',
        'email': 'test@email.com',
        'password': 'password1234',
      },
    ]);
    expect(api.updatedThemeMode, 'dark');
    expect(find.byKey(const Key('guided-initial-bean-button')), findsOneWidget);
    expect(find.byKey(const Key('guided-onboarding-input')), findsOneWidget);
    expect(
      find.byKey(const Key('guided-personality-balanced')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('guided-personality-balanced')),
        matching: find.byIcon(Icons.check_circle_rounded),
      ),
      findsNothing,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('guided-personality-balanced')),
        matching: find.byIcon(Icons.radio_button_unchecked_rounded),
      ),
      findsOneWidget,
    );
    expect(find.text('Balanced helper'), findsOneWidget);
    expect(
      find.text(
        'A calm, practical default that keeps replies concise and useful.',
      ),
      findsOneWidget,
    );
    expect(
      find.text(
        'An encouraging style that gives gentle nudges and helps you keep momentum.',
      ),
      findsOneWidget,
    );

    await tester.ensureVisible(
      find.byKey(const Key('guided-personality-balanced')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Balanced helper'));
    await tester.pump(const Duration(seconds: 6));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('guided-location-skip')));
    await tester.pumpAndSettle();
    expect(api.updatedAgentPersonality, 'balanced');
    expect(api.updatedContext, contains('guided Bean signup onboarding'));

    await tester.ensureVisible(find.byKey(const Key('guided-tour-start')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('guided-tour-start')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('guided-tour-next')), findsOneWidget);
    expect(find.byKey(const Key('guided-tour-panel-skip')), findsNothing);
    expect(find.byKey(const Key('guided-bean-thinking')), findsNothing);
    expect(find.text('Command center'), findsOneWidget);
    final tourNext = tester.widget<FilledButton>(
      find.byKey(const Key('guided-tour-next')),
    );
    expect(tourNext.onPressed, isNotNull);

    await tester.tap(find.byKey(const Key('guided-tour-next')));
    await tester.pump(const Duration(milliseconds: 120));
    expect(find.byKey(const Key('guided-bean-thinking')), findsNothing);
    await tester.pumpAndSettle();
    expect(find.text('Calendar views'), findsOneWidget);

    for (var i = 0; i < 4; i++) {
      await tester.ensureVisible(find.byKey(const Key('guided-tour-next')));
      await tester.tap(find.byKey(const Key('guided-tour-next')));
      await tester.pumpAndSettle();
    }
    await tester.pump(const Duration(seconds: 6));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('signup-plan-base')), findsOneWidget);
    expect(
      find.text('14-day free trial, then billed monthly'),
      findsNWidgets(3),
    );
    expect(find.text('Start with a 7-day free trial.'), findsNothing);
    expect(find.byKey(const Key('calendar-view')), findsNothing);
    expect(tokenStore.token, 'fake-token');
    expect(tokenStore.rememberMe, isTrue);
    expect(find.textContaining('Bean could not create'), findsNothing);

    expect(find.byKey(const Key('signup-plan-base')), findsOneWidget);
    expect(find.byKey(const Key('signup-plan-base-action')), findsOneWidget);
    final viewportHeight =
        tester.view.physicalSize.height / tester.view.devicePixelRatio;
    final baseCardRect = tester.getRect(
      find.byKey(const Key('signup-plan-base')),
    );
    final enterpriseActionRect = tester.getRect(
      find.byKey(const Key('signup-plan-enterprise-action')),
    );
    expect(baseCardRect.top, greaterThanOrEqualTo(0));
    expect(baseCardRect.top, lessThan(viewportHeight));
    expect(enterpriseActionRect.top, greaterThan(viewportHeight));

    await tester.ensureVisible(
      find.byKey(const Key('signup-plan-enterprise-action')),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('signup-plan-enterprise')), findsOneWidget);
    expect(find.text('Custom'), findsOneWidget);
    expect(find.text('Contact us'), findsOneWidget);
    await tester.tap(find.byKey(const Key('signup-plan-enterprise-action')));
    await tester.pumpAndSettle();

    expect(api.checkoutRequests, isEmpty);
    expect(launchedUrls.single.scheme, 'mailto');
    expect(launchedUrls.single.path, 'support@heybean.org');

    await tester.ensureVisible(
      find.byKey(const Key('signup-plan-base-action')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('signup-plan-base-action')));
    await tester.pumpAndSettle();

    expect(api.checkoutRequests, isEmpty);
    expect(api.mobileSubscriptionSetupRequests, [
      {'plan': 'base', 'billingInterval': 'monthly'},
    ]);
    expect(api.mobileSubscriptionConfirmRequests, [
      {
        'plan': 'base',
        'billingInterval': 'monthly',
        'setupIntentId': 'seti_test_base',
      },
    ]);
    expect(stripeHandler.preparedSetupIntentIds, ['seti_test_base']);
    expect(stripeHandler.presentedSheets, 1);
    expect(find.byKey(const Key('command-center-home')), findsOneWidget);
  });

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
      expect(find.byKey(const Key('guided-signup-action')), findsOneWidget);
      expect(find.byKey(const Key('show-register-mode')), findsNothing);
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

      expect(api.registeredAgentPersonality, isNull);
      expect(api.registeredPriorities, isNull);
      expect(api.registeredContext, isNull);

      expect(find.byKey(const Key('agent-onboarding-overlay')), findsNothing);
      expect(
        find.byKey(const Key('bean-intro-spotlight-overlay')),
        findsNothing,
      );
      expect(find.byKey(const Key('bean-intro-callout')), findsNothing);
      expect(find.byKey(const Key('nav-bean')), findsOneWidget);
      expect(find.text("Hi, I'm Bean. What is your name?"), findsNothing);

      expect(api.sentMessages, isEmpty);
      expect(api.sendMessageCalls, 0);
      expect(api.queueMessageCalls, 0);
      expect(find.byKey(const Key('onboarding-tour-overlay')), findsNothing);

      await tester.tap(find.byKey(const Key('calendar-today-button')));
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
      await tester.drag(
        find.byKey(const Key('chat-message-list')),
        const Offset(0, -1000),
      );
      await tester.pumpAndSettle();
      expect(find.text('Done — I updated your day.'), findsOneWidget);
      expect(find.text('gpt-5.4'), findsNothing);
      expect(
        find.byKey(const Key('assistant-message-model-label')),
        findsNothing,
      );
      tester.testTextInput.hide();
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('chat-activity-menu')), findsNothing);

      await openSettingsFromBottomNav(tester);
      expect(find.byKey(const Key('open-bean-preferences')), findsOneWidget);
      expect(find.text('Bean'), findsOneWidget);
      expect(find.byKey(const Key('open-bean-knowledge')), findsOneWidget);
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

  testWidgets('login skips the retired Bean onboarding interview', (
    WidgetTester tester,
  ) async {
    final api = _FakeHermesApiClient(onboardingCompleted: false);
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

    expect(find.byKey(const Key('command-center-home')), findsOneWidget);
    expect(find.byKey(const Key('bean-intro-callout')), findsNothing);
    expect(find.text("Hi, I'm Bean. What is your name?"), findsNothing);
    expect(api.sentMessages, isEmpty);
    expect(api.queueMessageCalls, 0);
    await tester.pump(const Duration(milliseconds: 250));
  });

  testWidgets(
    'creating a household keeps settings mounted without Flutter tree errors',
    (WidgetTester tester) async {
      final api = _WorkspaceFakeHermesApiClient();

      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await openSettingsFromBottomNav(tester);
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

  testWidgets('single-workspace users do not see workspace choices in More', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _WorkspaceFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-more')));
    await tester.pumpAndSettle();

    expect(find.text('Workspace'), findsNothing);
    expect(find.byKey(const Key('more-workspace-option-1')), findsNothing);

    await tester.tap(find.text('Settings').last);
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('workspace-create-household-action')),
      findsOneWidget,
    );
  });

  testWidgets('workspace limit errors show upgrade CTA in Flutter settings', (
    WidgetTester tester,
  ) async {
    final api = _WorkspaceLimitFakeHermesApiClient();
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

    await openSettingsFromBottomNav(tester);
    await tester.ensureVisible(
      find.byKey(const Key('workspace-create-household-action')),
    );
    await tester.tap(
      find.byKey(const Key('workspace-create-household-action')),
    );
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('workspace-create-name-field')),
      'Work',
    );
    await tester.tap(find.byKey(const Key('workspace-create-save')));
    await tester.pumpAndSettle();

    expect(find.text('Upgrade to keep going'), findsOneWidget);
    expect(
      find.textContaining('Your current plan includes up to 2 workspaces.'),
      findsOneWidget,
    );
    await tester.ensureVisible(
      find.byKey(const Key('inline-plan-limit-upgrade-action')),
    );
    await tester.tap(find.byKey(const Key('inline-plan-limit-upgrade-action')));
    await tester.pumpAndSettle();

    expect(launchedUrls.single.host, 'heybean.org');
    expect(launchedUrls.single.path, '/pricing');
    expect(launchedUrls.single.queryParameters['source'], 'flutter');

    await tester.ensureVisible(
      find.byKey(const Key('inline-plan-limit-dismiss-action')),
    );
    await tester.tap(find.byKey(const Key('inline-plan-limit-dismiss-action')));
    await tester.pumpAndSettle();

    expect(find.text('Upgrade to keep going'), findsNothing);
    expect(
      find.textContaining('Your current plan includes up to 2 workspaces.'),
      findsNothing,
    );
  });

  testWidgets('beta users can submit feedback from the banner', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient()..betaUser = true;

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('beta-feedback-banner')), findsOneWidget);
    await tester.tap(find.byKey(const Key('beta-feedback-banner')));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const Key('beta-feedback-message')),
      'The chat response felt delayed after I asked about dinner.',
    );
    await tester.tap(find.byKey(const Key('beta-feedback-submit')));
    await tester.pumpAndSettle();

    expect(api.issueReports, [
      'The chat response felt delayed after I asked about dinner.',
    ]);
    expect(find.byKey(const Key('beta-feedback-thanks')), findsOneWidget);
    expect(find.text('Thank you for helping improve HeyBean!'), findsOneWidget);
  });

  testWidgets('signed in app resumes today chat session when available', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient()
      ..todaySession = const HermesSession(
        id: 77,
        status: 'active',
        title: 'Today with Bean',
      )
      ..todaySessionMessages = const [
        HermesMessage(id: 700, role: 'user', content: 'what did we discuss'),
        HermesMessage(
          id: 701,
          role: 'assistant',
          content: 'We talked about dinner.',
        ),
      ];

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();

    expect(find.text('what did we discuss'), findsOneWidget);
    expect(find.text('We talked about dinner.'), findsOneWidget);
    expect(api.startedSessionCount, 0);
  });

  testWidgets(
    'collapsed Bean response preview expires, pauses, and swipes away',
    (WidgetTester tester) async {
      Future<Finder> pumpCollapsedPreview() async {
        await tester.pumpWidget(const SizedBox.shrink());
        await tester.pumpAndSettle();
        final api = _SignedInFakeHermesApiClient()
          ..todaySession = const HermesSession(
            id: 77,
            status: 'active',
            title: 'Today with Bean',
          )
          ..todaySessionMessages = const [
            HermesMessage(
              id: 701,
              role: 'assistant',
              content: 'One two three four five six',
            ),
          ];
        await tester.pumpWidget(
          HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
        );
        await tester.pumpAndSettle();
        await tester.tap(
          find.byKey(const Key('command-center-chat-collapse-toggle')),
        );
        await tester.pump(const Duration(milliseconds: 100));
        return find.byKey(const Key('bean-collapsed-response-tag'));
      }

      var preview = await pumpCollapsedPreview();
      expect(preview, findsOneWidget);
      await tester.pump(const Duration(milliseconds: 1500));
      expect(preview, findsOneWidget);
      await tester.pump(const Duration(milliseconds: 600));
      expect(preview, findsNothing);

      preview = await pumpCollapsedPreview();
      expect(preview, findsOneWidget);
      final heldPreview = await tester.startGesture(tester.getCenter(preview));
      await tester.pump(const Duration(seconds: 3));
      expect(preview, findsOneWidget);
      await heldPreview.up();
      await tester.pump(const Duration(milliseconds: 2200));
      expect(preview, findsNothing);

      preview = await pumpCollapsedPreview();
      expect(preview, findsOneWidget);
      await tester.drag(preview, const Offset(80, 0));
      await tester.pumpAndSettle();
      expect(preview, findsNothing);
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

      await openSettingsFromBottomNav(tester);
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

  testWidgets('More menu workspace list switches active workspace', (
    WidgetTester tester,
  ) async {
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

    expect(find.byKey(const Key('more-workspace-option-1')), findsNothing);

    await tester.tap(find.byKey(const Key('nav-more')));
    await tester.pumpAndSettle();
    expect(find.text('Workspace'), findsOneWidget);
    expect(find.text('Personal'), findsOneWidget);
    await tester.tap(find.byKey(const Key('more-workspace-option-2')));
    await tester.pumpAndSettle();

    expect(api.defaultWorkspaceSetTo, 2);
  });

  testWidgets('inviting a household member shows a copyable invite link', (
    WidgetTester tester,
  ) async {
    String? copiedText;
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(SystemChannels.platform, (call) async {
          if (call.method == 'Clipboard.setData') {
            copiedText = (call.arguments as Map<Object?, Object?>)['text']
                ?.toString();
            return null;
          }
          if (call.method == 'Clipboard.getData') {
            return <String, Object?>{'text': copiedText};
          }
          return null;
        });
    addTearDown(
      () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(SystemChannels.platform, null),
    );

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

    await openSettingsFromBottomNav(tester);
    await tester.ensureVisible(find.byKey(const Key('workspace-invite-2')));
    await tester.tap(find.byKey(const Key('workspace-invite-2')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('workspace-invite-email-2')),
      'pending@example.com',
    );
    await tester.tap(find.byKey(const Key('workspace-invite-save-2')));
    await tester.pumpAndSettle();

    expect(api.invitedWorkspaceId, 2);
    expect(api.invitedEmail, 'pending@example.com');
    expect(
      find.text(
        'https://heybean.org/workspace-invitations/new-invite-token/accept',
      ),
      findsOneWidget,
    );
    expect(find.byKey(const Key('workspace-invite-copy-link')), findsOneWidget);

    await tester.tap(find.byKey(const Key('workspace-invite-copy-link')));
    await tester.pumpAndSettle();

    expect(
      copiedText,
      'https://heybean.org/workspace-invitations/new-invite-token/accept',
    );
    expect(find.text('Invite pending - pending@example.com'), findsOneWidget);
  });

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

      await openSettingsFromBottomNav(tester);

      expect(find.text('Displayed connected calendars'), findsNothing);
      expect(
        find.byKey(const Key('google-calendar-source-primary')),
        findsOneWidget,
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
    'workspace switch keeps settings visible while refresh is pending',
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

      await openSettingsFromBottomNav(tester);
      await tester.ensureVisible(find.byKey(const Key('workspace-switch-2')));
      await tester.tap(find.byKey(const Key('workspace-switch-2')));
      await tester.pump();

      expect(
        find.byKey(const Key('workspace-calendar-sync-progress')),
        findsNothing,
      );
      expect(
        find.byKey(const Key('full-screen-loading-message')),
        findsNothing,
      );
      expect(find.text('Syncing your calendars...'), findsNothing);
      expect(find.byKey(const Key('settings-view')), findsOneWidget);

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

    await openSettingsFromBottomNav(tester);
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

      await openSettingsFromBottomNav(tester);
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

  testWidgets('household member actions use membership ids for removal', (
    WidgetTester tester,
  ) async {
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
          memberships: [
            HermesWorkspaceMembership(
              id: 10,
              workspaceId: 2,
              userId: 1,
              role: 'owner',
              user: HermesWorkspaceMemberUser(
                id: 1,
                name: 'Bean User',
                email: 'bean@example.com',
              ),
            ),
            HermesWorkspaceMembership(
              id: 77,
              workspaceId: 2,
              userId: 42,
              role: 'member',
              user: HermesWorkspaceMemberUser(
                id: 42,
                name: 'Invited User',
                email: 'invited@example.com',
              ),
            ),
            HermesWorkspaceMembership(
              id: 88,
              workspaceId: 2,
              role: 'member',
              status: 'invited',
              invitedEmail: 'pending@example.com',
            ),
          ],
        ),
      ];

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await openSettingsFromBottomNav(tester);
    await tester.ensureVisible(
      find.byKey(const Key('workspace-member-actions-2-77')),
    );
    await tester.tap(find.byKey(const Key('workspace-member-actions-2-77')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Remove').last);
    await tester.pumpAndSettle();

    expect(api.removedWorkspaceMemberId, 77);
    expect(api.removedWorkspaceId, 2);
    expect(find.byIcon(Icons.check_circle_rounded), findsWidgets);
    expect(find.byIcon(Icons.schedule_send_rounded), findsOneWidget);
    expect(find.text('pending@example.com'), findsOneWidget);
    expect(find.text('Invite pending - pending@example.com'), findsOneWidget);
  });

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

      await openSettingsFromBottomNav(tester);

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

    await openSettingsFromBottomNav(tester);

    expect(find.textContaining('Coach'), findsOneWidget);
    expect(find.textContaining('Family, Focus'), findsOneWidget);

    await tester.ensureVisible(find.byKey(const Key('open-bean-preferences')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('open-bean-preferences')));
    await tester.pumpAndSettle();

    expect(find.text('Edit Bean preferences'), findsOneWidget);
    expect(find.text('Choose Bean’s personality'), findsOneWidget);
    expect(find.text('What should Bean prioritize?'), findsOneWidget);
    expect(find.text('Anything Bean should know?'), findsOneWidget);
    final contextField = tester.widget<TextField>(
      find.byKey(const Key('onboarding-context')),
    );
    final contextBorder =
        contextField.decoration!.enabledBorder! as OutlineInputBorder;
    expect(contextBorder.borderRadius, BorderRadius.circular(16));
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
    await tester.ensureVisible(
      find.byKey(const Key('onboarding-priority-Family')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('onboarding-priority-Family')));
    await tester.ensureVisible(
      find.byKey(const Key('onboarding-priority-Work')),
    );
    await tester.pumpAndSettle();
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

  testWidgets('settings appearance options expand from a dropdown', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _SignedInFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    await openSettingsFromBottomNav(tester);

    expect(find.byKey(const Key('theme-preferences-card')), findsOneWidget);
    expect(find.text('Appearance'), findsOneWidget);
    expect(find.text('Green accent · Auto · Command Center'), findsOneWidget);
    expect(find.byKey(const Key('theme-preferences-options')), findsNothing);
    expect(
      find.text('Choose the accent color used across HeyBean.'),
      findsNothing,
    );

    await tester.ensureVisible(find.byKey(const Key('theme-preferences-card')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('theme-preferences-toggle')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('theme-preferences-options')), findsOneWidget);
    expect(find.byKey(const Key('theme-mode-selector')), findsOneWidget);
    expect(
      find.text('Choose the accent color used across HeyBean.'),
      findsOneWidget,
    );
    expect(find.text('Choose when HeyBean uses dark mode.'), findsOneWidget);
    expect(find.text('Auto'), findsOneWidget);
    expect(find.text('Light'), findsOneWidget);
    expect(find.text('Dark'), findsOneWidget);
    expect(find.text('Green'), findsOneWidget);
    expect(find.text('Gray'), findsOneWidget);
  });

  testWidgets('Bean opens to command center with chronological agenda', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _CommandCenterAgendaFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('command-center-home')), findsOneWidget);
    expect(find.byKey(const Key('command-center-title')), findsNothing);
    expect(find.byKey(const Key('command-center-chat-panel')), findsOneWidget);
    expect(find.byKey(const Key('chat-view')), findsOneWidget);

    final task = find.byKey(const Key('command-center-agenda-task-501'));
    final event = find.byKey(const Key('command-center-agenda-event-503'));
    final reminder = find.byKey(
      const Key('command-center-agenda-reminder-502'),
    );
    expect(task, findsOneWidget);
    expect(event, findsOneWidget);
    expect(reminder, findsOneWidget);
    expect(tester.getTopLeft(task).dy, lessThan(tester.getTopLeft(event).dy));
    expect(
      tester.getTopLeft(event).dy,
      lessThan(tester.getTopLeft(reminder).dy),
    );

    await tester.tap(task);
    await tester.pumpAndSettle();
    expect(find.text('Edit task'), findsOneWidget);
    await tester.tap(find.text('Cancel').last);
    await tester.pumpAndSettle();

    await tester.tap(event);
    await tester.pumpAndSettle();
    expect(find.text('Event Details'), findsOneWidget);
    await tester.tap(find.byKey(const Key('event-detail-back-action')));
    await tester.pumpAndSettle();

    await tester.tap(reminder);
    await tester.pumpAndSettle();
    expect(find.text('Edit reminder'), findsOneWidget);
  });

  testWidgets(
    'Command Center pins overdue tasks and reminders above upcoming items',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _CommandCenterOverdueAgendaFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();

      final task = find.byKey(const Key('command-center-agenda-task-511'));
      final reminder = find.byKey(
        const Key('command-center-agenda-reminder-512'),
      );
      final event = find.byKey(const Key('command-center-agenda-event-513'));

      expect(task, findsOneWidget);
      expect(reminder, findsOneWidget);
      expect(event, findsOneWidget);
      expect(find.text('overdue'), findsNWidgets(2));
      expect(
        tester.getTopLeft(reminder).dy,
        lessThan(tester.getTopLeft(task).dy),
      );
      expect(tester.getTopLeft(task).dy, lessThan(tester.getTopLeft(event).dy));
      await tester.pump(const Duration(milliseconds: 200));
    },
  );

  testWidgets(
    'Command Center keeps remaining agenda items during partial silent refresh after event delete',
    (WidgetTester tester) async {
      final api = _CommandCenterDeleteRefreshEmptyFakeHermesApiClient();

      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const Key('command-center-agenda-reminder-601')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('command-center-agenda-event-602')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('command-center-agenda-event-603')),
        findsOneWidget,
      );

      await tester.tap(
        find.byKey(const Key('command-center-agenda-event-603')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('event-delete-action')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('destructive-confirm-action')));
      await tester.pumpAndSettle();

      expect(api.deletedEventId, 603);
      expect(
        find.byKey(const Key('command-center-agenda-event-603')),
        findsNothing,
      );
      expect(
        find.byKey(const Key('command-center-agenda-reminder-601')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('command-center-agenda-event-602')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('command-center-agenda-empty')),
        findsNothing,
      );
    },
  );

  testWidgets('settings can rename command center in appearance', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await openSettingsFromBottomNav(tester);
    await tester.ensureVisible(find.byKey(const Key('theme-preferences-card')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('theme-preferences-toggle')));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const Key('command-center-label-field')),
      'Mission Control',
    );
    await tester.tap(find.byKey(const Key('command-center-label-save')));
    await tester.pumpAndSettle();

    expect(api.updatedCommandCenterLabel, 'Mission Control');
  });

  testWidgets('settings edits reminder notification preferences', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient()..plannedToday = true;

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await openSettingsFromBottomNav(tester);

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

    await openSettingsFromBottomNav(tester);
    await tester.ensureVisible(find.byKey(const Key('open-bean-preferences')));
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
    expect(find.text('A concise operator'), findsOneWidget);
    expect(find.text('A calm companion'), findsOneWidget);
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
    expect(
      find.text('Best when you want Bean to be brief and efficient.'),
      findsOneWidget,
    );
    expect(
      find.text('Best when you want Bean to help without adding urgency.'),
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

    await openSettingsFromBottomNav(tester);

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

  testWidgets('billing settings sit above account actions at bottom', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient();

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await openSettingsFromBottomNav(tester);
    await tester.ensureVisible(find.byKey(const Key('sign-out-action')));
    await tester.pumpAndSettle();

    final billingRect = tester.getRect(
      find.byKey(const Key('billing-settings-card')),
    );
    final signOutRect = tester.getRect(
      find.byKey(const Key('sign-out-action')),
    );
    final deleteRect = tester.getRect(
      find.byKey(const Key('delete-account-action')),
    );

    expect(billingRect.bottom, lessThan(signOutRect.top));
    expect(billingRect.bottom, lessThan(deleteRect.top));
  });

  testWidgets(
    'stale onboarding profile responses do not reopen the retired interview',
    (WidgetTester tester) async {
      final api = _FakeHermesApiClient(
        onboardingCompleted: false,
        staleOnboardingAfterUpdate: true,
      );

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

      expect(find.byKey(const Key('command-center-home')), findsOneWidget);
      expect(find.byKey(const Key('agent-onboarding-overlay')), findsNothing);
      expect(find.byKey(const Key('bean-intro-callout')), findsNothing);

      await tester.tap(find.byKey(const Key('nav-bean')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('agent-onboarding-overlay')), findsNothing);
      expect(find.byKey(const Key('bean-intro-callout')), findsNothing);
      expect(find.text("Hi, I'm Bean. What is your name?"), findsNothing);
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

      await openSettingsFromBottomNav(tester);
      await tester.ensureVisible(
        find.byKey(const Key('open-bean-preferences')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('open-bean-preferences')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.byKey(const Key('agent-personality-coach')),
      );
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
      await openSettingsFromBottomNav(tester);
      expect(find.textContaining('Coach'), findsOneWidget);
      expect(find.textContaining('Family'), findsOneWidget);

      await tester.ensureVisible(
        find.byKey(const Key('open-bean-preferences')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('open-bean-preferences')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.byKey(const Key('agent-personality-coach')),
      );
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

    expect(find.byKey(const Key('chat-view')), findsOneWidget);
    expect(find.textContaining('Session expired'), findsNothing);
    expect(api.pastTaskCalls, 1);
  });

  testWidgets(
    'Hermes Bean command center renders chat, progress, surfaces, and approvals',
    (WidgetTester tester) async {
      final api = _SignedInFakeHermesApiClient()
        ..approvals = const [
          HermesApproval(
            id: 7,
            title: 'Review outgoing email before Bean sends it',
            status: 'pending',
            description: 'Bean wants to send an email to Lauren.',
            payload: {
              'action': {'type': 'email.send', 'risk': 'high'},
            },
          ),
        ];
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const Key('global-approval-bottom-sheet')),
        findsOneWidget,
      );
      expect(find.text('I need approval'), findsOneWidget);
      expect(find.text("Approve or deny Bean's next action"), findsOneWidget);
      expect(find.textContaining('high risk'), findsOneWidget);
      await tester.tap(find.byKey(const Key('approval-always-approve-action')));
      await tester.pumpAndSettle();
      expect(api.approvedApprovalId, 7);
      expect(api.alwaysApprovedApproval, isTrue);

      expect(find.text('HeyBean'), findsNothing);
      expect(find.text('Bean assistant'), findsNothing);
      expect(find.byKey(const Key('calendar-today-button')), findsOneWidget);
      expect(find.byKey(const Key('calendar-month-chevron')), findsOneWidget);
      expect(_topHeaderDayLabelFinder(), findsOneWidget);
      expect(_topHeaderDayMonthTextFinder(), findsNothing);
      expect(_topHeaderMonthLabelFinder(), findsOneWidget);
      expect(find.byKey(const Key('critical-task-count')), findsOneWidget);

      await tester.tap(find.byKey(const Key('nav-bean')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('chat-input-dock')), findsOneWidget);
      expect(find.byKey(const Key('chat-new-session-action')), findsNothing);
      expect(find.byKey(const Key('chat-activity-menu')), findsNothing);
      expect(find.text('Agent progress'), findsNothing);
      expect(find.text('Activity feed'), findsNothing);
      expect(find.text('Pending approvals'), findsNothing);
      expect(find.byKey(const Key('chat-approval-bottom-dock')), findsNothing);

      for (final label in <String>['Tasks', 'Reminders', 'Notes', 'More']) {
        expect(find.text(label), findsWidgets);
      }
      for (final key in const [
        Key('nav-tasks'),
        Key('nav-reminders'),
        Key('nav-notes'),
        Key('nav-more'),
      ]) {
        expect(find.byKey(key).hitTestable(), findsOneWidget);
      }

      await tester.tap(find.byKey(const Key('nav-more')));
      await tester.pumpAndSettle();

      expect(find.text("Bean's Knowledge"), findsNothing);
      expect(find.text('Memory'), findsNothing);

      await tester.tap(find.text('Settings').last);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('open-bean-knowledge')), findsOneWidget);
      await tester.ensureVisible(find.byKey(const Key('open-bean-knowledge')));
      await tester.tap(find.byKey(const Key('open-bean-knowledge')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('memory-view')), findsOneWidget);
      expect(find.text("Bean's Knowledge"), findsOneWidget);
    },
  );

  testWidgets(
    'empty Bean chat intro stays concise with saved Bean preferences',
    (WidgetTester tester) async {
      final api = _SignedInFakeHermesApiClient()
        ..updatedAgentPersonality = 'organizer'
        ..updatedPriorities = ['Family', 'Work']
        ..updatedContext = 'Protect dinner and batch errands before lunch.';
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-bean')));
      await tester.pumpAndSettle();

      expect(find.text('What should Bean organize first?'), findsOneWidget);
      expect(find.textContaining('Organizer style'), findsNothing);
      expect(find.textContaining('Family'), findsNothing);
      expect(find.textContaining('Protect dinner'), findsNothing);

      final decoration =
          tester
                  .widget<AnimatedContainer>(
                    find.byKey(const Key('heybean-center-bean-button')),
                  )
                  .decoration
              as BoxDecoration;
      expect(
        (decoration.border! as Border).top.color,
        HeyBeanTheme.accentStrong,
      );
    },
  );

  testWidgets(
    'holding the Bean button starts voice on the current Bean screen',
    (WidgetTester tester) async {
      final api = _SignedInFakeHermesApiClient();
      final realtime = _FakeBeanRealtimeConversation(api);
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: api,
          tokenStore: _MemoryAuthTokenStore(),
          realtimeConversation: realtime,
        ),
      );
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('chat-view')), findsOneWidget);

      final gesture = await tester.startGesture(
        tester.getCenter(find.byKey(const Key('nav-bean'))),
      );
      await tester.pump(const Duration(milliseconds: 650));

      expect(realtime.started, isTrue);
      expect(find.byKey(const Key('chat-view')), findsOneWidget);
      expect(find.byKey(const Key('heybean-recording-pulse')), findsOneWidget);
      expect(find.text('Listening'), findsWidgets);
      expect(
        tester
            .widget<TextField>(find.byKey(const Key('chat-input')))
            .decoration
            ?.hintText,
        'Listening',
      );
      final heldDecoration =
          tester
                  .widget<AnimatedContainer>(
                    find.byKey(const Key('heybean-center-bean-button')),
                  )
                  .decoration
              as BoxDecoration;
      expect(
        (heldDecoration.border! as Border).top.color,
        HeyBeanTheme.accentStrong,
      );

      await gesture.up();
      await tester.pump(const Duration(milliseconds: 250));

      expect(realtime.captureStarted, isTrue);
      expect(realtime.captureEnded, isTrue);
      expect(realtime.microphoneEnabled, isFalse);
    },
  );

  testWidgets('holding the selected Bean button records until release', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient();
    final realtime = _FakeBeanRealtimeConversation(api);
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: api,
        tokenStore: _MemoryAuthTokenStore(),
        realtimeConversation: realtime,
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();

    final gesture = await tester.startGesture(
      tester.getCenter(find.byKey(const Key('nav-bean'))),
    );
    await tester.pump(const Duration(milliseconds: 650));

    expect(realtime.captureStarted, isTrue);
    expect(realtime.captureEnded, isFalse);
    expect(realtime.microphoneEnabled, isTrue);

    await gesture.up();
    await tester.pump(const Duration(milliseconds: 250));

    expect(realtime.captureEnded, isTrue);
    expect(realtime.microphoneEnabled, isFalse);
  });

  testWidgets(
    'typed Bean chat uses queued model route without starting realtime',
    (WidgetTester tester) async {
      final api = _SignedInFakeHermesApiClient();
      final realtime = _FakeBeanRealtimeConversation(api);
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: api,
          tokenStore: _MemoryAuthTokenStore(),
          realtimeConversation: realtime,
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('nav-bean')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('chat-input')), 'Plan today');
      await tester.tap(find.byKey(const Key('primary-chat-action')));
      await tester.pumpAndSettle();

      expect(realtime.started, isFalse);
      expect(realtime.startMicrophoneValues, isEmpty);
      expect(realtime.sentTexts, isEmpty);
      expect(api.sentMessages, ['Plan today']);
      expect(find.text('Done — I updated your day.'), findsOneWidget);
      expect(find.text('Plan today'), findsOneWidget);
    },
  );

  testWidgets('Bean chat input wraps before scrolling', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();

    const longRequest =
        'Create a detailed plan for tomorrow morning, include prep notes, reminders, and a checklist for each thing I need to handle before lunch.';
    await tester.enterText(find.byKey(const Key('chat-input')), longRequest);
    await tester.pumpAndSettle();

    final input = tester.widget<TextField>(find.byKey(const Key('chat-input')));
    expect(input.minLines, 1);
    expect(input.maxLines, 4);
    expect(input.keyboardType, TextInputType.multiline);
  });

  testWidgets('sent Bean messages can be copied or edited and resent', (
    WidgetTester tester,
  ) async {
    String? copiedText;
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(SystemChannels.platform, (call) async {
          if (call.method == 'Clipboard.setData') {
            copiedText = (call.arguments as Map<Object?, Object?>)['text']
                ?.toString();
            return null;
          }
          if (call.method == 'Clipboard.getData') {
            return <String, Object?>{'text': copiedText};
          }
          return null;
        });
    addTearDown(
      () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(SystemChannels.platform, null),
    );

    final api = _SignedInFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('chat-input')), 'Plan today');
    await tester.tap(find.byKey(const Key('primary-chat-action')));
    await tester.pumpAndSettle();

    await tester.tap(
      find.byKey(const Key('sent-message-actions-trigger')).first,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('chat-copy-sent-message-action')));
    await tester.pumpAndSettle();
    expect(copiedText, 'Plan today');

    await tester.tap(
      find.byKey(const Key('sent-message-actions-trigger')).first,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('chat-edit-sent-message-action')));
    await tester.pumpAndSettle();
    expect(
      tester
          .widget<TextField>(find.byKey(const Key('chat-input')))
          .controller
          ?.text,
      'Plan today',
    );

    await tester.enterText(
      find.byKey(const Key('chat-input')),
      'Plan tomorrow',
    );
    await tester.tap(find.byKey(const Key('primary-chat-action')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 250));

    expect(api.branchMessageCalls, 1);
    expect(api.branchedFromMessageId, 7001);
    expect(api.sentMessages, ['Plan today', 'Plan tomorrow']);
    expect(find.text('Plan today'), findsNothing);
    expect(find.text('Plan tomorrow'), findsOneWidget);
  });

  testWidgets('declining optional Bean setup uses a direct reply', (
    WidgetTester tester,
  ) async {
    final api = _OptionalSetupDeclineFakeHermesApiClient();
    final realtime = _FakeBeanRealtimeConversation(api);
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: api,
        tokenStore: _MemoryAuthTokenStore(),
        realtimeConversation: realtime,
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    expect(
      find.text(
        'All set — I’ll use the Detail organizer personality. Want me to help set up a few reminders or import existing lists now?',
      ),
      findsOneWidget,
    );

    await tester.enterText(find.byKey(const Key('chat-input')), 'no thanks');
    await tester.tap(find.byKey(const Key('primary-chat-action')));
    await tester.pumpAndSettle();

    expect(realtime.started, isFalse);
    expect(api.sentMessages, ['no thanks']);
    expect(api.sendMessageCalls, 1);
    expect(api.queueMessageCalls, 0);
    expect(find.text('I’m working on that in the background.'), findsNothing);
    expect(find.text('No problem — we can skip that.'), findsOneWidget);
  });

  testWidgets('typed Bean chat does not start failing realtime', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient();
    final realtime = _FailingBeanRealtimeConversation(api);
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: api,
        tokenStore: _MemoryAuthTokenStore(),
        realtimeConversation: realtime,
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('chat-input')), 'Plan today');
    await tester.tap(find.byKey(const Key('primary-chat-action')));
    await tester.pumpAndSettle();

    expect(realtime.started, isFalse);
    expect(api.sentMessages, ['Plan today']);
    expect(find.text('Done — I updated your day.'), findsOneWidget);
  });

  testWidgets('queued Bean chat does not show empty working dialog', (
    WidgetTester tester,
  ) async {
    final api = _DelayedQueueFakeHermesApiClient();
    final realtime = _FailingBeanRealtimeConversation(api);
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: api,
        tokenStore: _MemoryAuthTokenStore(),
        realtimeConversation: realtime,
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('chat-input')),
      'what is the weather tomorrow in Orlando',
    );
    await tester.tap(find.byKey(const Key('primary-chat-action')));
    await tester.pump();

    expect(api.queueMessageCalls, 1);
    expect(find.text('working...'), findsNothing);
    expect(find.byKey(const Key('bean-work-dock-strip')), findsNothing);

    api.completeQueue();
    await tester.pumpAndSettle();

    expect(find.text('It should be warm and cloudy tomorrow.'), findsOneWidget);
  });

  testWidgets('Bean work dock uses backend work plan labels in order', (
    WidgetTester tester,
  ) async {
    final api = _BeanWorkPlanFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('chat-input')),
      'I need to do some deep work later. Let’s put a work block on my schedule for 8-11pm. Set a reminder 30 minutes before as well',
    );
    await tester.tap(find.byKey(const Key('primary-chat-action')));
    await tester.pumpAndSettle();

    expect(find.text('Create calendar event: Deep work'), findsOneWidget);
    expect(find.text('Create reminder: Deep work'), findsOneWidget);
    expect(
      find.textContaining('do some deep work let s a work block'),
      findsNothing,
    );

    final eventTop = tester.getTopLeft(
      find.text('Create calendar event: Deep work'),
    );
    final reminderTop = tester.getTopLeft(
      find.text('Create reminder: Deep work'),
    );
    expect(eventTop.dy, lessThan(reminderTop.dy));
    expect(find.text('2/2'), findsOneWidget);
  });

  testWidgets('dashboard refresh applies Bean work events during active runs', (
    WidgetTester tester,
  ) async {
    final api = _DashboardRefreshBeanWorkFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('chat-input')),
      'I need to do some deep work later. Let’s put a work block on my schedule for 8-11pm and set a reminder 30 minutes before.',
    );
    await tester.tap(find.byKey(const Key('primary-chat-action')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(api.queueMessageCalls, 1);

    await tester.pump(const Duration(seconds: 15));
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.byKey(const Key('bean-work-dock-strip')), findsOneWidget);

    await tester.pump(const Duration(seconds: 8));
    await tester.pump(const Duration(milliseconds: 250));
  });

  testWidgets('multi-action Bean requests show every local work item upfront', (
    WidgetTester tester,
  ) async {
    final api = _DashboardRefreshBeanWorkFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('chat-input')),
      'Please also add workout to my calendar for tomorrow morning at 6-7am, and set a reminder for 5:30am',
    );
    await tester.tap(find.byKey(const Key('primary-chat-action')));
    await tester.pump();

    expect(find.byKey(const Key('bean-work-dock-strip')), findsOneWidget);
    expect(find.text('Creating event: Workout'), findsOneWidget);
    expect(find.text('Creating reminder: Workout'), findsOneWidget);
    expect(find.text('0/2'), findsOneWidget);

    await tester.pump(const Duration(seconds: 15));
    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds: 8));
    await tester.pump(const Duration(milliseconds: 250));
  });

  testWidgets('schedule block and reminder request preserves work order', (
    WidgetTester tester,
  ) async {
    final api = _DashboardRefreshBeanWorkFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('chat-input')),
      'Can you put a block on my schedule for next Friday from 9am-3pm for Miata engine swap and set a reminder for Thursday evening at 8pm',
    );
    await tester.tap(find.byKey(const Key('primary-chat-action')));
    await tester.pump();

    expect(find.byKey(const Key('bean-work-dock-strip')), findsOneWidget);
    expect(find.text('Creating event: Miata engine swap'), findsOneWidget);
    expect(find.text('Creating reminder: Miata engine swap'), findsOneWidget);
    expect(find.text('0/2'), findsOneWidget);

    final eventTop = tester.getTopLeft(
      find.text('Creating event: Miata engine swap'),
    );
    final reminderTop = tester.getTopLeft(
      find.text('Creating reminder: Miata engine swap'),
    );
    expect(eventTop.dy, lessThan(reminderTop.dy));

    await tester.pump(const Duration(seconds: 15));
    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds: 8));
    await tester.pump(const Duration(milliseconds: 250));
  });

  testWidgets('plain create block request is labeled as a calendar event', (
    WidgetTester tester,
  ) async {
    final api = _DashboardRefreshBeanWorkFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('chat-input')),
      'Ok, I deleted those both, so please again, create a block for next Friday from 9-4pm for Miata engine swap, and set a reminder for that morning at 8am',
    );
    await tester.tap(find.byKey(const Key('primary-chat-action')));
    await tester.pump();

    expect(find.byKey(const Key('bean-work-dock-strip')), findsOneWidget);
    expect(find.text('Creating event: Miata engine swap'), findsOneWidget);
    expect(find.text('Creating reminder: Miata engine swap'), findsOneWidget);
    expect(find.text('Creating item'), findsNothing);
    expect(find.text('0/2'), findsOneWidget);

    final eventTop = tester.getTopLeft(
      find.text('Creating event: Miata engine swap'),
    );
    final reminderTop = tester.getTopLeft(
      find.text('Creating reminder: Miata engine swap'),
    );
    expect(eventTop.dy, lessThan(reminderTop.dy));

    await tester.pump(const Duration(seconds: 15));
    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds: 8));
    await tester.pump(const Duration(milliseconds: 250));
  });

  testWidgets('backend work events replace one local item at a time', (
    WidgetTester tester,
  ) async {
    final api = _PartialBeanWorkEventsFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('chat-input')),
      'Can you put a block on my schedule for next Friday from 9am-3pm for Miata engine swap and set a reminder for Thursday evening at 8pm',
    );
    await tester.tap(find.byKey(const Key('primary-chat-action')));
    await tester.pump();

    expect(find.text('Creating event: Miata engine swap'), findsOneWidget);
    expect(find.text('Creating reminder: Miata engine swap'), findsOneWidget);
    expect(find.text('0/2'), findsOneWidget);

    await tester.pump(const Duration(milliseconds: 600));
    await tester.pump(const Duration(milliseconds: 250));

    expect(
      find.text('Create calendar event: Miata engine swap'),
      findsOneWidget,
    );
    expect(find.text('Creating reminder: Miata engine swap'), findsOneWidget);
    expect(find.text('0/2'), findsOneWidget);

    await tester.pump(const Duration(seconds: 2));
    await tester.pump(const Duration(milliseconds: 250));
    await tester.pump(const Duration(seconds: 8));
    await tester.pump(const Duration(milliseconds: 250));
  });

  testWidgets('completed Bean work events refresh dashboard before run ends', (
    WidgetTester tester,
  ) async {
    final api = _BeanMutationRefreshFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('chat-input')),
      'add a task to take out trash tonight',
    );
    await tester.tap(find.byKey(const Key('primary-chat-action')));
    await tester.pump();

    expect(find.byKey(const Key('bean-work-dock-strip')), findsOneWidget);
    expect(find.text('Creating task: Take out trash'), findsOneWidget);
    expect(find.text('Take out trash'), findsNothing);

    await tester.pump(const Duration(seconds: 2));
    await tester.pump(const Duration(milliseconds: 250));

    expect(find.text('Create task: Take out trash'), findsOneWidget);
    expect(api.taskListIncludedCreatedTask, isTrue);

    await tester.tap(find.byKey(const Key('nav-tasks')));
    await tester.pump(const Duration(milliseconds: 250));

    expect(find.text('Take out trash'), findsWidgets);

    await tester.pump(const Duration(seconds: 2));
    await tester.pump(const Duration(milliseconds: 250));

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pump(const Duration(milliseconds: 250));

    expect(
      find.text('Done — I added the take out trash task.'),
      findsOneWidget,
    );

    await tester.pump(const Duration(seconds: 8));
    await tester.pump(const Duration(milliseconds: 250));
  });

  testWidgets('chat stop cancels an in-flight Bean request', (
    WidgetTester tester,
  ) async {
    final api = _SlowChatFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('chat-input')), 'Wait no');
    await tester.testTextInput.receiveAction(TextInputAction.send);
    await tester.pump();

    expect(find.byKey(const Key('primary-chat-stop-action')), findsOneWidget);
    await tester.tap(find.byKey(const Key('primary-chat-stop-action')));
    await tester.pump();

    expect(api.cancelledSessionCalls, 1);
    expect(
      find.text('Stopped. That request will not update your day.'),
      findsOneWidget,
    );
    expect(find.byKey(const Key('primary-chat-action')), findsOneWidget);

    api.completeMessage();
    await tester.pumpAndSettle();

    expect(find.text('Late response'), findsNothing);
  });

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
    expect(find.byKey(const Key('critical-reminder-item-2')), findsOneWidget);
    expect(find.byKey(const Key('critical-event-item-3')), findsOneWidget);
    expect(find.text('Plan launch'), findsWidgets);
    expect(find.text('Stand up'), findsWidgets);
    expect(find.textContaining('Design review'), findsWidgets);
  });

  testWidgets('critical count excludes future critical items', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _FutureCriticalFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('critical-task-count')), findsOneWidget);
    expect(find.text('3'), findsWidgets);

    await tester.tap(find.byKey(const Key('critical-task-count')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('critical-task-item-101')), findsNothing);
    expect(find.byKey(const Key('critical-reminder-item-102')), findsNothing);
    expect(find.byKey(const Key('critical-event-item-103')), findsNothing);
    expect(find.text('Future task'), findsNothing);
    expect(find.text('Future reminder'), findsNothing);
    expect(find.text('Future event'), findsNothing);
  });

  testWidgets('critical count excludes non-critical overdue items', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _NonCriticalOverdueFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('critical-task-count')), findsOneWidget);
    expect(find.text('3'), findsWidgets);

    await tester.tap(find.byKey(const Key('critical-task-count')));
    await tester.pumpAndSettle();

    final dropdown = find.byKey(const Key('critical-task-dropdown'));
    expect(find.byKey(const Key('critical-task-item-104')), findsNothing);
    expect(find.byKey(const Key('critical-reminder-item-105')), findsNothing);
    expect(
      find.descendant(
        of: dropdown,
        matching: find.text('Overdue regular task'),
      ),
      findsNothing,
    );
    expect(
      find.descendant(
        of: dropdown,
        matching: find.text('Overdue regular reminder'),
      ),
      findsNothing,
    );
  });

  testWidgets(
    'signed-in screens show inline loading while dashboard data loads',
    (WidgetTester tester) async {
      final api = _SlowDashboardFakeHermesApiClient();

      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pump();
      await tester.pump();

      expect(
        find.byKey(const Key('signed-in-loading-indicator')),
        findsNothing,
      );
      expect(find.text('Loading...'), findsNothing);
      expect(
        find.byKey(const Key('command-center-agenda-loading')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('command-center-agenda-empty')),
        findsNothing,
      );

      await tester.tap(find.byKey(const Key('nav-tasks')));
      await tester.pump();
      expect(
        find.byKey(const Key('signed-in-loading-indicator')),
        findsNothing,
      );
      expect(find.byKey(const Key('tasks-screen-loading')), findsOneWidget);
      expect(find.text('No active tasks'), findsNothing);

      await tester.tap(find.byKey(const Key('nav-reminders')));
      await tester.pump();
      expect(
        find.byKey(const Key('signed-in-loading-indicator')),
        findsNothing,
      );
      expect(find.byKey(const Key('reminders-screen-loading')), findsOneWidget);
      expect(find.text('No pending reminders'), findsNothing);

      api.completeDashboardLoad();
      await tester.pumpAndSettle();

      expect(
        find.byKey(const Key('signed-in-loading-indicator')),
        findsNothing,
      );
      expect(find.byKey(const Key('reminders-screen-loading')), findsNothing);
    },
  );

  testWidgets('iPhone app icon badge mirrors the visible critical count', (
    WidgetTester tester,
  ) async {
    final appBadgeCounts = <int>[];
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _SignedInFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
        updateAppIconBadge: (count) async => appBadgeCounts.add(count),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('critical-task-count')), findsOneWidget);
    expect(find.text('3'), findsWidgets);
    expect(appBadgeCounts, containsAllInOrder([0, 3]));
    expect(appBadgeCounts.last, 3);
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
    await tester.ensureVisible(
      find.byKey(const Key('title-time-editor-picker-button')),
    );
    await tester.pumpAndSettle();
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
      find.descendant(
        of: find.byKey(const Key('title-time-time-minute-dial')),
        matching: find.text('05'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('title-time-time-minute-dial')),
        matching: find.text('10'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('title-time-time-minute-dial')),
        matching: find.text('07'),
      ),
      findsNothing,
    );
    expect(
      find.byKey(const Key('title-time-time-meridiem-dial')),
      findsOneWidget,
    );
  });

  testWidgets(
    'month view has six-wide month scroller and shows month task scope',
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

      final now = DateTime.now();
      final tomorrow = DateTime(now.year, now.month, now.day + 1);
      final tomorrowInCurrentMonth = tomorrow.month == now.month;
      expect(find.byKey(const Key('calendar-month-scroller')), findsOneWidget);
      expect(find.byKey(const Key('apple-style-month-grid')), findsOneWidget);
      expect(
        find.text('Tasks for ${_testMonthNames[now.month - 1]} ${now.year}'),
        findsOneWidget,
      );
      expect(find.text('Today task'), findsOneWidget);
      expect(
        find.text('Tomorrow task'),
        tomorrowInCurrentMonth ? findsOneWidget : findsNothing,
      );
      expect(find.text('Rest of month'), findsNothing);

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
        find.text(
          'Tasks for ${_testMonthNames[nextMonth.month - 1]} ${nextMonth.year}',
        ),
        findsOneWidget,
      );
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
      expect(
        find.text('Tomorrow task'),
        tomorrow.month == nextMonth.month ? findsOneWidget : findsNothing,
      );
    },
  );

  testWidgets(
    'calendar history limit banner can be dismissed and clears on navigation',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _HistoryLimitedFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('calendar-month-chevron')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.byKey(const Key('calendar-month-pill-0')),
      );
      await tester.tap(find.byKey(const Key('calendar-month-pill-0')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('plan-limit-error-banner')), findsOneWidget);
      expect(
        find.text('Your current plan includes 30 days of calendar history.'),
        findsOneWidget,
      );

      await tester.tap(
        find.byKey(const Key('plan-limit-error-dismiss-action')),
      );
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('plan-limit-error-banner')), findsNothing);

      await tester.ensureVisible(
        find.byKey(const Key('calendar-month-pill-0')),
      );
      await tester.tap(find.byKey(const Key('calendar-month-pill-0')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('plan-limit-error-banner')), findsOneWidget);

      await tester.tap(find.byKey(const Key('nav-tasks')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('plan-limit-error-banner')), findsNothing);
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
    expect(
      tester.getTopLeft(find.byKey(const Key('title-time-editor-time'))).dy,
      lessThan(
        tester
            .getTopLeft(
              find.byKey(const Key('title-time-editor-category-add-action')),
            )
            .dy,
      ),
    );
    expect(
      find.byKey(const Key('title-time-editor-primary-workspace-select')),
      findsNothing,
    );
    expect(
      find.byKey(const Key('title-time-editor-recurrence-field')),
      findsOneWidget,
    );
    expect(find.text('Task recurrence'), findsOneWidget);
    await tester.ensureVisible(
      find.byKey(const Key('title-time-editor-category-add-action')),
    );
    await tester.pumpAndSettle();
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
      find.byKey(const Key('title-time-editor-category-select')),
      findsOneWidget,
    );
    expect(find.text('Errands'), findsWidgets);
    await _selectDropdownText(
      tester,
      dropdownKey: const Key('title-time-editor-category-select'),
      text: 'Work',
    );
    await tester.ensureVisible(
      find.byKey(const Key('title-time-editor-open-picker')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('title-time-editor-open-picker')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('title-time-time-dock')), findsOneWidget);
    await tester.tap(find.byKey(const Key('title-time-time-dock-done')));
    await tester.pumpAndSettle();
    await tester.dragFrom(const Offset(400, 500), const Offset(0, -520));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Weekly'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(
      find.byKey(const Key('title-time-editor-save-bottom')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('title-time-editor-save-bottom')));
    await tester.pumpAndSettle();

    expect(api.updatedTask?.category, 'Work');
    expect(api.updatedTask?.color, '#007AFF');
    expect(api.updatedTask?.metadata?['recurrence'], 'weekly');
    expect(api.updatedTask?.dueAt, isNotNull);
    expect(api.createdReminder, isNull);

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
    expect(find.text('Reminder repeats'), findsOneWidget);
    await _selectDropdownText(
      tester,
      dropdownKey: const Key('title-time-editor-category-select'),
      text: 'Work',
    );
    await tester.dragFrom(const Offset(400, 300), const Offset(0, -760));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Specific days'));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const Key('title-time-editor-specific-days')),
      findsOneWidget,
    );
    await tester.tap(find.text('Mon'));
    await tester.tap(find.text('Wed'));
    await tester.pumpAndSettle();
    await tester.dragFrom(const Offset(400, 500), const Offset(0, -120));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('title-time-editor-save-bottom')));
    await tester.pumpAndSettle();

    expect(api.updatedReminder?.category, 'Work');
    expect(api.updatedReminder?.color, '#007AFF');
    expect(api.updatedReminder?.metadata?['recurrence'], 'specific_days');
    expect(api.updatedReminder?.metadata?['days'], ['mon', 'wed']);
    expect(find.textContaining('Work'), findsWidgets);
  });

  testWidgets('uncategorized task and reminder saves use theme color', (
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
      find.byKey(const Key('title-time-editor-category-select')),
      findsOneWidget,
    );
    await tester.dragFrom(const Offset(400, 500), const Offset(0, -320));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('title-time-editor-save-bottom')));
    await tester.pumpAndSettle();

    expect(api.updatedTask?.category, isNull);
    expect(api.updatedTask?.color, '#7BC98C');

    await tester.tap(find.byKey(const Key('nav-reminders')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('reminder-edit-action-601')));
    await tester.pumpAndSettle();
    await _selectDropdownText(
      tester,
      dropdownKey: const Key('title-time-editor-category-select'),
      text: 'No category',
    );
    await tester.dragFrom(const Offset(400, 500), const Offset(0, -320));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('title-time-editor-save-bottom')));
    await tester.pumpAndSettle();

    expect(api.updatedReminder?.category, isNull);
    expect(api.updatedReminder?.color, '#7BC98C');
  });

  testWidgets('new task date saves on the task without creating a reminder', (
    WidgetTester tester,
  ) async {
    final api = _TaskReminderCategoryFakeHermesApiClient(
      includeWorkspaces: true,
    );
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-tasks')));
    await tester.pumpAndSettle();
    await _openCreateMenuAndChoose(tester, const Key('create-task-action'));
    expect(find.byKey(const Key('title-time-editor-save')), findsNothing);
    expect(
      find.byKey(const Key('title-time-editor-primary-workspace-1')),
      findsOneWidget,
    );
    expect(find.text('Personal (current)'), findsOneWidget);
    expect(
      find.byKey(const Key('title-time-editor-primary-workspace-2')),
      findsOneWidget,
    );
    await tester.enterText(
      find.byKey(const Key('title-time-editor-title')),
      'Order coffee beans',
    );
    await tester.tap(find.byKey(const Key('title-time-editor-open-picker')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('title-time-time-dock')), findsOneWidget);
    await tester.tap(find.byKey(const Key('title-time-time-dock-done')));
    await tester.pumpAndSettle();
    await tester.ensureVisible(
      find.byKey(const Key('title-time-editor-primary-workspace-1')),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const Key('title-time-editor-primary-workspace-1')),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const Key('title-time-editor-primary-workspace-2')),
    );
    await tester.pumpAndSettle();
    expect(find.text('Task recurrence'), findsOneWidget);
    await tester.dragFrom(const Offset(400, 500), const Offset(0, -320));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Daily'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('title-time-editor-save-bottom')));
    await tester.pumpAndSettle();

    expect(api.createdTask?.title, 'Order coffee beans');
    expect(api.createdTask?.dueAt, isNotNull);
    expect(api.createdTask?.metadata?['recurrence'], 'daily');
    expect(api.createdTaskWorkspaceId, 2);
    expect(api.createdTaskSyncWorkspaceIds, isEmpty);
    expect(api.createdReminder, isNull);

    await tester.tap(find.byKey(const Key('nav-reminders')));
    await tester.pumpAndSettle();
    expect(find.text('Order coffee beans'), findsNothing);

    await _openCreateMenuAndChoose(tester, const Key('create-reminder-action'));
    expect(find.byKey(const Key('title-time-editor-save')), findsNothing);
    expect(find.text('Reminder repeats'), findsOneWidget);
  });

  testWidgets('task editor shows saving state while save is pending', (
    WidgetTester tester,
  ) async {
    final api = _TaskReminderCategoryFakeHermesApiClient()
      ..updateTaskCompleter = Completer<void>();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-tasks')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('task-edit-action-501')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('title-time-editor-save')), findsNothing);
    await tester.tap(find.byKey(const Key('title-time-editor-save-bottom')));
    await tester.pump();

    expect(find.text('Saving...'), findsOneWidget);
    expect(api.updatedTask, isNull);

    api.updateTaskCompleter!.complete();
    await tester.pumpAndSettle();

    expect(api.updatedTask?.title, 'Categorize proposal');
    expect(find.byKey(const Key('title-time-editor-title')), findsNothing);
  });

  testWidgets('task save updates the list before the API call finishes', (
    WidgetTester tester,
  ) async {
    final api = _TaskReminderCategoryFakeHermesApiClient()
      ..updateTaskCompleter = Completer<void>();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-tasks')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('task-edit-action-501')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('title-time-editor-title')),
      'Updated proposal',
    );
    await tester.tap(find.byKey(const Key('title-time-editor-save-bottom')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('title-time-editor-title')), findsNothing);
    expect(find.text('Updated proposal'), findsOneWidget);
    expect(api.updatedTask, isNull);

    api.updateTaskCompleter!.complete();
    await tester.pumpAndSettle();

    expect(api.updatedTask?.title, 'Updated proposal');
  });

  testWidgets('reminder save updates the list before the API call finishes', (
    WidgetTester tester,
  ) async {
    final api = _TaskReminderCategoryFakeHermesApiClient()
      ..updateReminderCompleter = Completer<void>();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-reminders')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('reminder-edit-action-601')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('title-time-editor-title')),
      'Updated reminder',
    );
    await tester.tap(find.byKey(const Key('title-time-editor-save-bottom')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('title-time-editor-title')), findsNothing);
    expect(find.text('Updated reminder'), findsOneWidget);
    expect(api.updatedReminder, isNull);

    api.updateReminderCompleter!.complete();
    await tester.pumpAndSettle();

    expect(api.updatedReminder?.title, 'Updated reminder');
  });

  testWidgets('task delete removes the row before the API call finishes', (
    WidgetTester tester,
  ) async {
    final api = _TaskReminderCategoryFakeHermesApiClient()
      ..deleteTaskCompleter = Completer<void>();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-tasks')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('task-edit-action-501')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('title-time-editor-delete')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('destructive-confirm-action')));
    await tester.pumpAndSettle();

    expect(api.deletedTaskId, isNull);
    expect(find.byKey(const Key('title-time-editor-title')), findsNothing);
    expect(find.text('Categorize proposal'), findsNothing);

    api.deleteTaskCompleter!.complete();
    await tester.pumpAndSettle();

    expect(api.deletedTaskId, 501);
  });

  testWidgets('reminder delete removes the row before the API call finishes', (
    WidgetTester tester,
  ) async {
    final api = _TaskReminderCategoryFakeHermesApiClient()
      ..deleteReminderCompleter = Completer<void>();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-reminders')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('reminder-edit-action-601')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('title-time-editor-delete')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('destructive-confirm-action')));
    await tester.pumpAndSettle();

    expect(api.deletedReminderId, isNull);
    expect(find.byKey(const Key('title-time-editor-title')), findsNothing);
    expect(find.text('Categorize reminder'), findsNothing);

    api.deleteReminderCompleter!.complete();
    await tester.pumpAndSettle();

    expect(api.deletedReminderId, 601);
  });

  testWidgets(
    'focused old HeyBean views render home calendar, tasks, reminders, chat, and settings',
    (WidgetTester tester) async {
      final api = _SignedInFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('command-center-home')), findsOneWidget);
      expect(find.byKey(const Key('chat-view')), findsOneWidget);
      await tester.tap(find.byKey(const Key('calendar-today-button')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('calendar-view')), findsOneWidget);
      expect(find.byKey(const Key('critical-task-count')), findsOneWidget);
      expect(find.byKey(const Key('calendar-today-button')), findsOneWidget);
      expect(_topHeaderDayLabelFinder(), findsOneWidget);
      expect(_topHeaderDayMonthTextFinder(), findsNothing);
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
        greaterThan(
          tester.getTopLeft(find.byKey(const Key('calendar-today-button'))).dx,
        ),
      );
      expect(
        find.byKey(const Key('apple-style-week-date-header')),
        findsNothing,
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
      final selectedDayHeadingText = tester.widget<Text>(
        find.descendant(
          of: find.byKey(const Key('day-column-heading-selected')),
          matching: find.byType(Text),
        ),
      );
      expect(selectedDayHeadingText.style?.fontWeight, FontWeight.w800);
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
        find.byKey(const Key('chat-view')),
      );
      await tester.drag(
        find.byKey(const Key('chat-message-list')),
        const Offset(0, -180),
      );
      await tester.pumpAndSettle();
      expect(
        tester.getTopLeft(find.byKey(const Key('chat-view'))),
        chatTopBeforeDrag,
      );
      final composerOverlap =
          tester.getRect(find.byKey(const Key('chat-input-dock'))).bottom -
          tester.getRect(find.byKey(const Key('heybean-bottom-menu'))).top;
      expect(composerOverlap, greaterThanOrEqualTo(0));
      expect(composerOverlap, lessThanOrEqualTo(30));
      await tester.enterText(
        find.byKey(const Key('chat-input')),
        'Help me plan today',
      );
      await tester.tap(find.byKey(const Key('primary-chat-action')));
      await tester.pumpAndSettle();
      expect(api.sentMessages, ['Help me plan today']);
      expect(find.text('Done — I updated your day.'), findsOneWidget);

      await openSettingsFromBottomNav(tester);
      expect(find.byKey(const Key('settings-view')), findsOneWidget);
      expect(find.text('Settings'), findsWidgets);
      expect(find.text('Bean'), findsOneWidget);
      expect(find.byKey(const Key('open-bean-knowledge')), findsOneWidget);
      expect(find.text('Calendar preferences'), findsOneWidget);
      expect(find.text('Start hour'), findsOneWidget);
      expect(find.text('End hour'), findsOneWidget);
      expect(find.text('Approval rules'), findsNothing);
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

      await tester.tap(find.byKey(const Key('calendar-today-button')));
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
    await _openTodayView(tester);

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
    expect(eventBlockDecoration.color?.a, closeTo(.60, .01));
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
    final timelineScrollRect = tester.getRect(
      find.byKey(const Key('apple-style-day-timeline-scroll')),
    );
    expect(currentTimeLabel.top, greaterThanOrEqualTo(timelineScrollRect.top));
    expect(
      currentTimeLabel.bottom,
      lessThanOrEqualTo(timelineScrollRect.bottom),
    );
    expect(currentTimeLabel.left, greaterThanOrEqualTo(fixedHourColumn.left));
    expect(currentTimeLabel.right, lessThanOrEqualTo(fixedHourColumn.right));
    expect(fixedHourColumn.height, closeTo(16 * 80, .1));
    expect(
      tester.getSize(find.byKey(const Key('apple-style-day-timeline'))).height,
      closeTo(fixedHourColumn.height + 1, .1),
    );
    final scrollingDayColumns = tester.getRect(
      find.byKey(const PageStorageKey<String>('apple-style-day-page-view')),
    );
    expect(
      scrollingDayColumns.left,
      greaterThanOrEqualTo(fixedHourColumn.right),
    );
    final dayPageView = tester.widget<PageView>(
      find.byKey(const PageStorageKey<String>('apple-style-day-page-view')),
    );
    expect(dayPageView.pageSnapping, isFalse);
    expect(dayPageView.physics, isA<BouncingScrollPhysics>());
    expect(dayPageView.physics, isNot(isA<PageScrollPhysics>()));

    final headingBeforeSwipe = _activeSelectedDayHeading(tester);
    expect(_topHeaderDayLabelFinder(), findsOneWidget);
    expect(_topHeaderMonthLabelFinder(), findsOneWidget);
    final pageViewTopLeft = tester.getTopLeft(
      find.byKey(const PageStorageKey<String>('apple-style-day-page-view')),
    );
    await tester.dragFrom(
      Offset(
        pageViewTopLeft.dx + 360,
        (timelineScrollRect.center.dy + 80).clamp(
          timelineScrollRect.top + 24,
          timelineScrollRect.bottom - 24,
        ),
      ),
      const Offset(-420, 0),
    );
    await tester.pumpAndSettle();
    final headingAfterSwipe = _activeSelectedDayHeading(tester);

    expect(headingAfterSwipe, _headingDaysAfter(headingBeforeSwipe, 1));
    expect(_topHeaderDayLabelFinder(), findsOneWidget);
    expect(_topHeaderMonthLabelFinder(), findsOneWidget);
  });

  testWidgets(
    'half-page calendar scroll advances the visible column headings by one day',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _TwoDayCalendarFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();
      await _openTodayView(tester);

      final headingBeforeSwipe = _activeSelectedDayHeading(tester);
      final pageViewFinder = find.byKey(
        const PageStorageKey<String>('apple-style-day-page-view'),
      );
      final pageViewWidth = tester.getSize(pageViewFinder).width;

      await _dragCalendarDayPage(tester, Offset(-pageViewWidth / 2, 0));
      await tester.pumpAndSettle();

      expect(
        _activeSelectedDayHeading(tester),
        _headingDaysAfter(headingBeforeSwipe, 1),
      );
    },
  );

  testWidgets(
    'calendar day column headers stay visible while scrolling hours',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _TwoDayCalendarFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();
      await _openTodayView(tester);

      final selectedHeading = find.byKey(
        const Key('day-column-heading-selected'),
      );
      final nextHeading = find.byKey(const Key('day-column-heading-next'));
      final initialSelectedTop = tester.getTopLeft(selectedHeading).dy;
      final initialNextTop = tester.getTopLeft(nextHeading).dy;

      await tester.drag(
        find.byKey(const Key('apple-style-day-timeline-scroll')),
        const Offset(0, -500),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const Key('calendar-sticky-day-header')),
        findsOneWidget,
      );
      expect(
        tester.getTopLeft(selectedHeading).dy,
        closeTo(initialSelectedTop, 1),
      );
      expect(tester.getTopLeft(nextHeading).dy, closeTo(initialNextTop, 1));
    },
  );

  testWidgets('calendar bottom nav returns to the current day pair', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _TwoDayCalendarFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();
    await _openTodayView(tester);

    final currentDayHeading = _activeSelectedDayHeading(tester);
    final timelineScrollRect = tester.getRect(
      find.byKey(const Key('apple-style-day-timeline-scroll')),
    );
    final pageViewTopLeft = tester.getTopLeft(
      find.byKey(const PageStorageKey<String>('apple-style-day-page-view')),
    );
    await tester.dragFrom(
      Offset(
        pageViewTopLeft.dx + 360,
        (timelineScrollRect.center.dy + 80).clamp(
          timelineScrollRect.top + 24,
          timelineScrollRect.bottom - 24,
        ),
      ),
      const Offset(-420, 0),
    );
    await tester.pumpAndSettle();
    expect(_activeSelectedDayHeading(tester), isNot(currentDayHeading));

    await tester.tap(find.byKey(const Key('nav-tasks')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('tasks-view')), findsOneWidget);

    await tester.tap(find.byKey(const Key('calendar-today-button')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('calendar-view')), findsOneWidget);
    expect(_activeSelectedDayHeading(tester), currentDayHeading);
  });

  testWidgets('overlapping calendar events share the day column side by side', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _OverlappingCalendarFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();
    await _openTodayView(tester);

    final selectedHeading = tester.getRect(
      find.byKey(const Key('day-column-heading-selected')),
    );
    final firstOverlap = tester.getRect(
      find.byKey(const Key('calendar-event-block-overlap-one')),
    );
    final secondOverlap = tester.getRect(
      find.byKey(const Key('calendar-event-block-overlap-two')),
    );
    final laterOverlap = tester.getRect(
      find.byKey(const Key('calendar-event-block-overlap-three')),
    );

    expect(firstOverlap.left, closeTo(selectedHeading.left + 2, 1));
    expect(firstOverlap.right, lessThan(secondOverlap.left));
    expect(secondOverlap.right, closeTo(selectedHeading.right - 2, 1));
    expect(firstOverlap.width, closeTo(secondOverlap.width, 1));
    expect(laterOverlap.left, closeTo(firstOverlap.left, 1));
    expect(laterOverlap.right, closeTo(firstOverlap.right, 1));
  });

  testWidgets(
    'short back-to-back calendar events have vertical breathing room',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _BackToBackCalendarFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();
      await _openTodayView(tester);

      final firstEvent = tester.getRect(
        find.byKey(const Key('calendar-event-block-morning-check-in')),
      );
      final secondEvent = tester.getRect(
        find.byKey(const Key('calendar-event-block-morning-follow-up')),
      );

      expect(firstEvent.bottom, lessThan(secondEvent.top));
    },
  );

  testWidgets(
    'calendar top header keeps current month label while browsing another month',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _SignedInFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();

      expect(_topHeaderMonthLabelFinder(), findsOneWidget);
      expect(_topHeaderDayLabelFinder(), findsOneWidget);
      expect(_topHeaderDayMonthTextFinder(), findsNothing);

      await tester.tap(find.byKey(const Key('calendar-month-chevron')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('apple-style-month-grid')), findsOneWidget);

      final nextMonthPill = find.byKey(const Key('calendar-month-pill-13'));
      await tester.ensureVisible(nextMonthPill);
      await tester.tap(nextMonthPill);
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('apple-style-month-grid')), findsOneWidget);
      expect(_topHeaderMonthLabelFinder(), findsOneWidget);
      expect(_topHeaderDayLabelFinder(), findsOneWidget);
      expect(_topHeaderDayMonthTextFinder(), findsNothing);
    },
  );

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
      await _openTodayView(tester);

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
    await _openTodayView(tester);
    await tester.tap(find.byKey(const Key('calendar-today-button')));
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

  testWidgets('calendar uses summary events while full event list is empty', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _SummaryOnlyCalendarFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('calendar-today-button')));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('calendar-event-block-summary-workout')),
      findsOneWidget,
    );
  });

  testWidgets('materialized recurring occurrences render once per day', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _MaterializedRecurringCalendarFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('calendar-today-button')));
    await tester.pumpAndSettle();

    final today = DateTime.now();
    final tomorrow = today.add(const Duration(days: 1));
    expect(
      find.byKey(const Key('calendar-event-block-workout')),
      findsOneWidget,
    );
    expect(
      find.byKey(
        Key(
          'calendar-event-block-workout-${tomorrow.year}-${tomorrow.month}-${tomorrow.day}',
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
    await _openTodayView(tester);

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
      await _openTodayView(tester);

      expect(find.text('7 AM'), findsOneWidget);

      await openSettingsFromBottomNav(tester);
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
      await _openTodayView(tester);

      expect(find.text('10 AM'), findsOneWidget);
      expect(find.text('9 PM'), findsOneWidget);
      expect(find.text('7 AM'), findsNothing);
    },
  );

  testWidgets(
    'tasks can be checked from the day view and drop below open tasks',
    (WidgetTester tester) async {
      final api = _ActiveTasksFakeHermesApiClient()
        ..completeTaskCompleter = Completer<void>();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();
      await _openTodayView(tester);

      expect(find.text('Yesterday one-off'), findsOneWidget);
      expect(find.byKey(const Key('task-critical-star-103')), findsOneWidget);
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
      await tester.pump();

      expect(api.completedTaskIds, isEmpty);
      final completedTaskAfter = tester.getRect(find.text('Pack bags'));
      final stillOpenTaskAfter = tester.getRect(find.text('Call pharmacy'));
      expect(stillOpenTaskAfter.top, lessThan(completedTaskAfter.top));
      expect(
        find.byKey(const Key('task-complete-checkbox-101')),
        findsOneWidget,
      );

      api.completeTaskCompleter!.complete();
      await tester.pumpAndSettle();

      expect(api.completedTaskIds, [101]);
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

    await openSettingsFromBottomNav(tester);

    expect(
      find.byKey(const Key('google-calendar-sync-settings')),
      findsOneWidget,
    );
    expect(find.text('External Calendar Sync'), findsOneWidget);
    expect(find.text('Connect Calendar'), findsOneWidget);

    await tester.ensureVisible(
      find.byKey(const Key('google-calendar-connect-action')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Connect Calendar'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('external-calendar-connect-google')));
    await tester.pumpAndSettle();
    expect(api.googleCalendarConnected, isTrue);
    expect(launchedUrls, hasLength(1));
    expect(launchedUrls.single.host, 'accounts.google.com');
    expect(
      find.textContaining('Finish approving Google Calendar access'),
      findsOneWidget,
    );

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.googleCalendarSyncCalls, 1);
    expect(
      find.textContaining('Google Calendar connected and synced 2 events'),
      findsOneWidget,
    );

    await tester.ensureVisible(
      find.byKey(const Key('google-calendar-sync-action')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Sync now'));
    await tester.pumpAndSettle();
    expect(api.googleCalendarSyncCalls, 2);
    expect(
      find.textContaining('Google Calendar sync pulled 2 external events'),
      findsOneWidget,
    );

    expect(
      find.byKey(const Key('google-calendar-source-primary')),
      findsOneWidget,
    );
    expect(
      find.byKey(const Key('google-calendar-source-holidays@example.com')),
      findsOneWidget,
    );
  });

  testWidgets('settings manages subscription and payment method in app', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient();
    final stripeHandler = _FakeStripePaymentHandler();
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: api,
        tokenStore: _MemoryAuthTokenStore(),
        stripePaymentHandler: stripeHandler,
      ),
    );
    await tester.pumpAndSettle();

    await openSettingsFromBottomNav(tester);
    await tester.ensureVisible(
      find.byKey(const Key('settings-upgrade-plan-action')),
    );
    await tester.pumpAndSettle();

    expect(find.textContaining('Current plan: Base'), findsOneWidget);
    expect(find.textContaining('Visa ending 4242'), findsOneWidget);
    expect(
      find.byKey(const Key('settings-cancel-subscription-action')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const Key('settings-upgrade-plan-action')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('settings-plan-premium')), findsOneWidget);
    await tester.tap(find.byKey(const Key('settings-plan-premium')));
    await tester.pumpAndSettle();

    expect(api.mobileSubscriptionSetupRequests, [
      {'plan': 'premium', 'billingInterval': 'monthly'},
    ]);
    expect(api.mobileSubscriptionConfirmRequests, [
      {
        'plan': 'premium',
        'billingInterval': 'monthly',
        'setupIntentId': 'seti_test_premium',
      },
    ]);
    expect(stripeHandler.preparedSetupIntentIds, ['seti_test_premium']);
    expect(stripeHandler.presentedSheets, 1);
    expect(find.textContaining('Current plan: Premium'), findsOneWidget);

    await tester.ensureVisible(
      find.byKey(const Key('settings-update-payment-method-action')),
    );
    await tester.tap(
      find.byKey(const Key('settings-update-payment-method-action')),
    );
    await tester.pumpAndSettle();

    expect(api.paymentMethodSetupRequests, 1);
    expect(api.paymentMethodConfirmRequests, ['seti_payment_update']);
    expect(stripeHandler.preparedSetupIntentIds, [
      'seti_test_premium',
      'seti_payment_update',
    ]);
    expect(find.textContaining('Mastercard ending 4444'), findsOneWidget);

    await tester.tap(
      find.byKey(const Key('settings-cancel-subscription-action')),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('destructive-confirm-action')));
    await tester.pumpAndSettle();

    expect(api.cancelSubscriptionRequests, 1);
    expect(find.textContaining('Last day of access:'), findsOneWidget);
    expect(
      find.textContaining(
        'Once the final active period has ended, your HeyBean data will be deleted',
      ),
      findsOneWidget,
    );
    expect(
      find.byKey(const Key('settings-resume-subscription-action')),
      findsOneWidget,
    );

    await tester.tap(
      find.byKey(const Key('settings-resume-subscription-action')),
    );
    await tester.pumpAndSettle();

    expect(api.resumeSubscriptionRequests, 1);
    expect(find.textContaining('Last day of access:'), findsNothing);
    expect(
      find.byKey(const Key('settings-resume-subscription-action')),
      findsNothing,
    );
    expect(find.textContaining('Subscription restarted.'), findsOneWidget);
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

      await openSettingsFromBottomNav(tester);
      await tester.ensureVisible(
        find.byKey(const Key('google-calendar-connect-action')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Connect Calendar'));
      await tester.pumpAndSettle();
      await tester.tap(
        find.byKey(const Key('external-calendar-connect-google')),
      );
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

    await openSettingsFromBottomNav(tester);
    await tester.ensureVisible(
      find.byKey(const Key('google-calendar-connect-action')),
    );
    await tester.pump();
    await tester.tap(find.text('Connect Calendar'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(
      find.byKey(const Key('external-calendar-connect-google')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('external-calendar-connect-google')));
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
      find.textContaining('Google Calendar connected and synced 2 events'),
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

      await _openTodayView(tester);
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

    await _openCreateMenuAndChoose(tester, const Key('create-task-action'));
    await tester.enterText(
      find.byKey(const Key('title-time-editor-title')),
      'Buy printer paper',
    );
    await tester.tap(find.byKey(const Key('title-time-editor-save-bottom')));
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

    await openSettingsFromBottomNav(tester);

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

    await openSettingsFromBottomNav(tester);
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

  testWidgets('sign out ignores late signed-in refresh failures', (
    WidgetTester tester,
  ) async {
    final api = _RefreshFailsAfterLogoutFakeHermesApiClient();
    final tokenStore = _MemoryAuthTokenStore()..token = 'existing-token';

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: tokenStore),
    );
    await tester.pumpAndSettle();

    await openSettingsFromBottomNav(tester);
    expect(find.byKey(const Key('settings-view')), findsOneWidget);

    await tester.drag(
      find.byKey(const Key('signed-in-refresh-scroll')),
      const Offset(0, 500),
    );
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    expect(api.refreshStarted.isCompleted, isTrue);

    await tester.ensureVisible(find.byKey(const Key('sign-out-action')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('sign-out-action')));
    await tester.pump();

    expect(find.text('Login'), findsOneWidget);
    expect(find.byKey(const Key('settings-view')), findsNothing);

    api.failRefresh();
    await tester.pumpAndSettle();

    expect(find.text('Login'), findsOneWidget);
    expect(find.byKey(const Key('settings-view')), findsNothing);
    expect(find.textContaining('Session expired'), findsNothing);
    expect(tokenStore.token, isNull);
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

    expect(find.byKey(const Key('chat-view')), findsOneWidget);
    expect(tokenStore.token, 'fake-token');
    expect(tokenStore.rememberMe, isTrue);
  });

  testWidgets('post-login refresh timeout keeps the user signed in', (
    WidgetTester tester,
  ) async {
    final api = _PostLoginRefreshTimeoutHermesApiClient();
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

    expect(find.byKey(const Key('chat-view')), findsOneWidget);
    expect(find.text('Login'), findsNothing);
    expect(api.bearerToken, 'fake-token');
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

  testWidgets('uses landing-page green Material 3 styling indicators', (
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
    expect(materialApp.theme?.colorScheme.primary, const Color(0xFF7BC98C));
    expect(materialApp.theme?.canvasColor, const Color(0xFFFFFFFF));

    expect(
      find.byKey(const Key('heybean-background-gradient')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('green-glow-left')), findsOneWidget);
    expect(find.byKey(const Key('heybean-bottom-menu')), findsOneWidget);
    expect(find.byKey(const Key('heybean-center-bean-button')), findsOneWidget);
    expect(
      tester.getSize(find.byKey(const Key('heybean-center-bean-button'))),
      const Size(64, 64),
    );
    final beanLogo = tester.widget<Image>(
      find.byKey(const Key('heybean-center-bean-logo')),
    );
    expect(beanLogo.image, isA<AssetImage>());
    expect(
      (beanLogo.image as AssetImage).assetName,
      'assets/images/bean/bean-logo.png',
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

  testWidgets('dark mode uses dark calendar controls and white Bean logo', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient()..updatedThemeMode = 'dark';
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();
    await _openTodayView(tester);

    expect(HeyBeanTheme.isDark, isTrue);
    final beanLogo = tester.widget<Image>(
      find.byKey(const Key('heybean-center-bean-logo')),
    );
    expect(
      (beanLogo.image as AssetImage).assetName,
      'assets/images/bean/bean-logo-white-overlay.png',
    );

    await tester.tap(find.byKey(const Key('calendar-today-button')));
    await tester.pumpAndSettle();

    final todayButtonDecoration = tester
        .widgetList<Container>(
          find.descendant(
            of: find.byKey(const Key('calendar-today-button')),
            matching: find.byType(Container),
          ),
        )
        .map((container) => container.decoration)
        .whereType<BoxDecoration>()
        .first;
    expect(
      todayButtonDecoration.color,
      HeyBeanTheme.surface2.withValues(alpha: .94),
    );
    expect(
      (todayButtonDecoration.border! as Border).top.color,
      HeyBeanTheme.borderStrong,
    );

    await tester.tap(find.byKey(const Key('calendar-month-chevron')));
    await tester.pumpAndSettle();

    final monthPillDecoration = tester
        .widgetList<Container>(
          find.descendant(
            of: find.byKey(const Key('calendar-month-pill-0')),
            matching: find.byType(Container),
          ),
        )
        .map((container) => container.decoration)
        .whereType<BoxDecoration>()
        .first;
    expect(
      monthPillDecoration.color,
      HeyBeanTheme.surface2.withValues(alpha: .94),
    );
    expect(
      (monthPillDecoration.border! as Border).top.color,
      HeyBeanTheme.borderStrong,
    );
  });

  test('Flutter theme tokens mirror Laravel light and dark palettes', () {
    final lightGold = HeyBeanTheme.themeDataFor('gold', Brightness.light);
    expect(lightGold.useMaterial3, isTrue);
    expect(lightGold.canvasColor, const Color(0xFFFFFDF7));
    expect(lightGold.colorScheme.primary, const Color(0xFFFCD34D));
    expect(lightGold.colorScheme.onPrimary, const Color(0xFF3F2C07));
    expect(lightGold.colorScheme.surface, const Color(0xFFFFFFFF));
    expect(lightGold.colorScheme.onSurface, const Color(0xFF2D3748));

    final darkGold = HeyBeanTheme.themeDataFor('gold', Brightness.dark);
    expect(darkGold.canvasColor, const Color(0xFF0B0F14));
    expect(darkGold.colorScheme.primary, const Color(0xFFFCD34D));
    expect(darkGold.colorScheme.onPrimary, const Color(0xFF3F2C07));
    expect(darkGold.colorScheme.surface, const Color(0xFF141A20));
    expect(
      darkGold.colorScheme.surfaceContainerHighest,
      const Color(0xFF19212A),
    );
    expect(darkGold.colorScheme.onSurface, const Color(0xFFF4F7FB));
    expect(darkGold.colorScheme.onSurfaceVariant, const Color(0xFFA7B0BD));
    expect(darkGold.colorScheme.outlineVariant, const Color(0x2E94A3B8));
    expect(darkGold.colorScheme.outline, const Color(0x4D94A3B8));
    expect(darkGold.colorScheme.error, const Color(0xFFFB7185));
    expect(darkGold.inputDecorationTheme.fillColor, const Color(0xFF111820));
    expect(darkGold.dialogTheme.backgroundColor, const Color(0xFF141A20));
    expect(
      darkGold.bottomSheetTheme.modalBackgroundColor,
      const Color(0xFF141A20),
    );

    HeyBeanTheme.useTheme('gold', brightness: Brightness.dark);
    expect(HeyBeanTheme.bg0, const Color(0xFF0B0F14));
    expect(HeyBeanTheme.bg1, const Color(0xFF10151C));
    expect(HeyBeanTheme.bg2, const Color(0xFF151B23));
    expect(HeyBeanTheme.surface, const Color(0xFF141A20));
    expect(HeyBeanTheme.surface2, const Color(0xFF19212A));
    expect(HeyBeanTheme.surfaceSoft, const Color(0xFF1F2933));
    expect(HeyBeanTheme.accent, const Color(0xFFFCD34D));
    expect(HeyBeanTheme.warning, const Color(0xFFFBBF24));
    expect(HeyBeanTheme.destructive, const Color(0xFFFB7185));
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
      expect(
        find.text(
          'Bean is paused because Gmail OAuth is not connected. Please check Settings or approvals, then try again.',
        ),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'chat failure message tells the user Bean hit a recoverable snag',
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
      await tester.pumpAndSettle(const Duration(seconds: 2));

      expect(
        find.textContaining('I hit a snag while working on that.'),
        findsOneWidget,
      );
    },
  );

  testWidgets('chat retries transient queued Bean failures idempotently', (
    WidgetTester tester,
  ) async {
    final api = _TransientQueueFailureHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
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
    await tester.pumpAndSettle(const Duration(seconds: 2));

    expect(api.queueMessageCalls, 2);
    expect(api.queuedMetadata, hasLength(2));
    expect(api.queuedMetadata[0]?['client_request_id'], isNotNull);
    expect(
      api.queuedMetadata[0]?['client_request_id'],
      api.queuedMetadata[1]?['client_request_id'],
    );
    expect(find.text('Done - I queued that request.'), findsOneWidget);
    expect(
      find.textContaining('I hit a snag while working on that'),
      findsNothing,
    );
  });

  testWidgets('chat recovers queued Bean work after repeated lost responses', (
    WidgetTester tester,
  ) async {
    final api = _LostQueueResponseHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
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
    await tester.pumpAndSettle(const Duration(seconds: 3));

    expect(api.queueMessageCalls, 3);
    expect(api.lookupQueuedMessageCalls, 1);
    expect(api.queuedMetadata, hasLength(3));
    expect(
      api.lookupClientRequestId,
      api.queuedMetadata.first?['client_request_id'],
    );
    expect(find.text('Done - I found that queued request.'), findsOneWidget);
    expect(
      find.textContaining('I hit a snag while working on that'),
      findsNothing,
    );
  });

  testWidgets('chat queues direct replies after transient send failures', (
    WidgetTester tester,
  ) async {
    final api = _TransientDirectSendFailureHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-bean')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('chat-input')),
      'Can you create calendar events?',
    );
    await tester.ensureVisible(find.byKey(const Key('primary-chat-action')));
    await tester.tap(find.byKey(const Key('primary-chat-action')));
    await tester.pumpAndSettle(const Duration(seconds: 2));

    expect(api.sendMessageCalls, 1);
    expect(api.queueMessageCalls, 1);
    expect(api.sentMessageMetadata.single?['client_request_id'], isNotNull);
    expect(
      api.sentMessageMetadata.single?['client_request_id'],
      api.queuedMetadata.single?['client_request_id'],
    );
    expect(find.text('Done - I queued that request.'), findsOneWidget);
    expect(
      find.textContaining('I hit a snag while working on that'),
      findsNothing,
    );
  });

  testWidgets('pull to refresh reloads signed-in views', (
    WidgetTester tester,
  ) async {
    final api = _SignedInFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();
    await _openTodayView(tester);

    expect(api.todaySummaryCalls, 1);
    expect(
      find.byKey(const Key('signed-in-refresh-indicator')),
      findsOneWidget,
    );

    final refreshScrollTopLeft = tester.getTopLeft(
      find.byKey(const Key('signed-in-refresh-scroll')),
    );
    await tester.dragFrom(
      refreshScrollTopLeft + const Offset(40, 20),
      const Offset(0, 360),
    );
    await tester.pumpAndSettle();

    expect(api.todaySummaryCalls, greaterThan(1));
  });

  testWidgets(
    'connected Google Calendar status does not full-sync on pull refresh',
    (WidgetTester tester) async {
      final api = _GoogleCalendarAutoSyncFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();
      await _openTodayView(tester);

      expect(api.googleCalendarSyncCalls, 0);
      expect(find.textContaining('Imported Google event'), findsNothing);

      final refreshScrollTopLeft = tester.getTopLeft(
        find.byKey(const Key('signed-in-refresh-scroll')),
      );
      await tester.dragFrom(
        refreshScrollTopLeft + const Offset(40, 20),
        const Offset(0, 360),
      );
      await tester.pumpAndSettle();

      expect(api.googleCalendarSyncCalls, 0);
      expect(find.textContaining('Imported Google event'), findsNothing);
    },
  );

  testWidgets('create menu creates a new event', (WidgetTester tester) async {
    final api = _EditableCalendarFakeHermesApiClient();
    api.workspaceOverrides = const [
      HermesWorkspace(
        id: '1',
        name: 'Personal',
        type: 'personal',
        role: 'owner',
        active: true,
        isDefault: true,
      ),
      HermesWorkspace(id: '2', name: 'Household', role: 'owner'),
    ];
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('create-item-menu')), findsOneWidget);
    await tester.tap(find.byKey(const Key('create-item-menu')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('create-event-action')), findsOneWidget);
    expect(find.byKey(const Key('create-task-action')), findsOneWidget);
    expect(find.byKey(const Key('create-reminder-action')), findsOneWidget);
    await tester.tapAt(const Offset(8, 8));
    await tester.pumpAndSettle();
    final addButton = tester.getRect(
      find.byKey(const Key('create-item-menu-button')),
    );
    final criticalCount = tester.getRect(
      find.byKey(const Key('critical-task-count')),
    );
    expect(addButton.left, greaterThan(criticalCount.right));
    expect(addButton.center.dy, closeTo(criticalCount.center.dy, 2));
    final dayHeaderFinder = find.byKey(const Key('calendar-sticky-day-header'));
    if (dayHeaderFinder.evaluate().isNotEmpty) {
      final dayHeader = tester.getRect(dayHeaderFinder);
      expect(addButton.bottom, lessThan(dayHeader.top));
    }
    await _openCreateMenuAndChoose(tester, const Key('create-event-action'));

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
    final autoEndEditor = tester.widget<EditableText>(
      find.descendant(
        of: find.byKey(const Key('event-end-field')),
        matching: find.byType(EditableText),
      ),
    );
    expect(
      autoEndEditor.controller.text,
      'today at ${_testNaturalTimeLabel(DateTime(DateTime.now().year, DateTime.now().month, DateTime.now().day, 17))}',
    );
    await tester.enterText(find.byKey(const Key('event-end-field')), '5:00 PM');
    await tester.ensureVisible(
      find.byKey(const Key('event-google-calendar-sports@example.com')),
    );
    await tester.tap(
      find.byKey(const Key('event-google-calendar-sports@example.com')),
    );
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const Key('event-sync-workspace-1')));
    await tester.pumpAndSettle();
    expect(find.text('Personal (current)'), findsOneWidget);
    await tester.tap(find.byKey(const Key('event-sync-workspace-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('event-sync-workspace-2')));
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
      'sports@example.com',
    ]);
    expect(
      api.createdEvent?.metadata?['google_calendar_id'],
      'sports@example.com',
    );
    expect(api.createdEventWorkspaceId, 2);
    expect(api.createdEventSyncWorkspaceIds, isEmpty);
    expect(find.textContaining('Client kickoff'), findsOneWidget);
  });

  testWidgets('event notes field uses long form styling with top label icon', (
    WidgetTester tester,
  ) async {
    final api = _EditableCalendarFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await _openCreateMenuAndChoose(tester, const Key('create-event-action'));

    final notesField = tester.widget<TextField>(
      find.byKey(const Key('event-notes-field')),
    );
    final notesBorder =
        notesField.decoration!.enabledBorder! as OutlineInputBorder;
    expect(notesBorder.borderRadius, BorderRadius.circular(16));
    expect(find.widgetWithText(Row, 'Notes'), findsOneWidget);
    expect(find.byIcon(Icons.notes_rounded), findsOneWidget);
  });

  testWidgets('event reminders are optional and follow event recurrence', (
    WidgetTester tester,
  ) async {
    final api = _EditableCalendarFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await _openCreateMenuAndChoose(tester, const Key('create-event-action'));
    await tester.enterText(
      find.byKey(const Key('event-title-field')),
      'Recurring standup',
    );

    expect(find.byKey(const Key('event-reminder-minutes-field')), findsNothing);
    expect(
      find.byKey(const Key('event-reminder-recurrence-field')),
      findsNothing,
    );

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
    tester
        .widget<FilterChip>(
          find.descendant(
            of: find.byKey(const Key('event-specific-days')),
            matching: find.widgetWithText(FilterChip, 'Mon'),
          ),
        )
        .onSelected
        ?.call(true);
    tester
        .widget<FilterChip>(
          find.descendant(
            of: find.byKey(const Key('event-specific-days')),
            matching: find.widgetWithText(FilterChip, 'Wed'),
          ),
        )
        .onSelected
        ?.call(true);
    await tester.pumpAndSettle();

    await tester.ensureVisible(
      find.byKey(const Key('event-create-reminder-toggle')),
    );
    await tester.tap(find.byKey(const Key('event-create-reminder-toggle')));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('event-reminder-minutes-field')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('event-reminder-specific-days')), findsNothing);

    await tester.tap(find.byKey(const Key('event-save-action')));
    await tester.pumpAndSettle();

    expect(api.createdReminder?['calendar_event_id'], 44);
    expect(api.createdReminder?['metadata'], {
      'minutes_before': 15,
      'recurrence': 'specific_days',
      'days': ['mon', 'wed'],
      'interval': 1,
      'unit': 'days',
    });
  });

  testWidgets(
    'calendar event editor shows saving state while save is pending',
    (WidgetTester tester) async {
      final api = _EditableCalendarFakeHermesApiClient()
        ..createEventCompleter = Completer<void>();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await _openCreateMenuAndChoose(tester, const Key('create-event-action'));
      await tester.enterText(
        find.byKey(const Key('event-title-field')),
        'Waiting save',
      );
      await tester.tap(find.byKey(const Key('event-save-action')));
      await tester.pump();

      expect(find.text('Saving...'), findsOneWidget);
      expect(api.createdEvent, isNull);

      api.createEventCompleter!.complete();
      await tester.pumpAndSettle();

      expect(api.createdEvent?.title, 'Waiting save');
      expect(find.byKey(const Key('calendar-event-detail-page')), findsNothing);
    },
  );

  testWidgets(
    'create menu preserves unchanged default local time after reload',
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

      await _openCreateMenuAndChoose(tester, const Key('create-event-action'));
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

  testWidgets(
    'new shared workspace events keep no category theme color by default',
    (WidgetTester tester) async {
      final api = _SharedWorkspaceCategoryFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();

      await _openCreateMenuAndChoose(tester, const Key('create-event-action'));

      expect(find.byKey(const Key('event-category-dropdown')), findsOneWidget);
      await tester.ensureVisible(
        find.byKey(const Key('event-category-dropdown')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('event-category-dropdown')));
      await tester.pumpAndSettle();
      expect(find.text('Family'), findsOneWidget);
      expect(
        find.byKey(const Key('event-category-option-Personal')),
        findsNothing,
      );
      expect(find.byKey(const Key('event-category-none')), findsWidgets);
      await tester.tap(find.text('No category').last);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('event-title-field')),
        'Family dinner',
      );
      await tester.tap(find.byKey(const Key('event-save-action')));
      await tester.pumpAndSettle();

      expect(api.createdEvent?.category, isNull);
      expect(api.createdEvent?.color, '#7BC98C');
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

    await _openCreateMenuAndChoose(tester, const Key('create-event-action'));

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

  testWidgets(
    'critical event star persists immediately and updates header critical count',
    (WidgetTester tester) async {
      final api = _EditableCalendarFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();
      await _openTodayView(tester);

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

      expect(api.updatedEvent?.isCritical, isTrue);
      await tester.tap(find.byKey(const Key('event-detail-back-action')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('event-critical-star-3')), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const Key('critical-task-count')),
          matching: find.text('3'),
        ),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('critical-task-count')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('critical-event-item-3')), findsOneWidget);
      expect(find.textContaining('Design review'), findsWidgets);
      await tester.tapAt(const Offset(10, 10));
      await tester.pumpAndSettle();

      await tester.tap(eventBlock);
      await tester.pumpAndSettle();
      final criticalToggle = tester.widget<IconButton>(
        find.byKey(const Key('event-detail-critical-toggle')),
      );
      expect(criticalToggle.tooltip, 'Remove critical star');
    },
  );

  testWidgets('calendar event detail page can delete an event', (
    WidgetTester tester,
  ) async {
    final api = _EditableCalendarFakeHermesApiClient()
      ..deleteEventCompleter = Completer<void>();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('calendar-today-button')));
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

    expect(api.deletedEventId, isNull);
    expect(find.byKey(const Key('calendar-event-detail-page')), findsNothing);
    expect(
      find.byKey(const Key('calendar-event-block-design-review')),
      findsNothing,
    );

    api.deleteEventCompleter!.complete();
    await tester.pumpAndSettle();

    expect(api.deletedEventId, 3);
  });

  testWidgets('calendar event delete can target selected workspaces', (
    WidgetTester tester,
  ) async {
    final api = _LinkedWorkspaceEditableCalendarFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();
    await _openTodayView(tester);

    await tester.ensureVisible(
      find.byKey(const Key('calendar-event-block-design-review')),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const Key('calendar-event-block-design-review')),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('event-delete-action')));
    await tester.pumpAndSettle();

    expect(find.text('Delete event from'), findsOneWidget);
    expect(find.byKey(const Key('event-delete-workspace-1')), findsOneWidget);
    expect(find.byKey(const Key('event-delete-workspace-2')), findsOneWidget);

    await tester.tap(find.byKey(const Key('event-delete-workspace-2')));
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const Key('event-delete-selected-workspaces-action')),
    );
    await tester.pumpAndSettle();

    expect(api.deletedEventId, 3);
    expect(api.deletedEventWorkspaceIds, [1]);
  });

  testWidgets('recurring calendar event delete can stop future occurrences', (
    WidgetTester tester,
  ) async {
    final today = DateTime.now();
    final api = _EditableCalendarFakeHermesApiClient(
      initialEvent: HermesCalendarEvent(
        id: 3,
        title: 'Morning standup',
        startsAt: DateTime(
          today.year,
          today.month,
          today.day,
          9,
        ).toIso8601String(),
        endsAt: DateTime(
          today.year,
          today.month,
          today.day,
          9,
          30,
        ).toIso8601String(),
        recurrence: 'daily',
      ),
    );
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();
    await _openTodayView(tester);

    final eventKey = Key(
      'calendar-event-block-morning-standup-${today.year}-${today.month}-${today.day}',
    );
    await tester.ensureVisible(find.byKey(eventKey));
    await tester.tap(find.byKey(eventKey));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('event-delete-action')));
    await tester.pumpAndSettle();

    expect(find.text('Delete recurring event'), findsOneWidget);
    await tester.tap(find.byKey(const Key('event-delete-recurring-future')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('event-delete-recurring-action')));
    await tester.pumpAndSettle();

    expect(api.deletedEventId, 3);
    expect(api.deletedRecurringMode, 'future');
    expect(
      api.deletedRecurringOccurrenceDate,
      '${today.year.toString().padLeft(4, '0')}-${today.month.toString().padLeft(2, '0')}-${today.day.toString().padLeft(2, '0')}',
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
    await _openTodayView(tester);

    await tester.ensureVisible(
      find.byKey(const Key('calendar-event-block-design-review')),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const Key('calendar-event-block-design-review')),
    );
    await tester.pumpAndSettle();
    await tester.ensureVisible(
      find.byKey(const Key('event-category-dropdown')),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('event-category-dropdown')), findsOneWidget);
    expect(find.text('Studio'), findsWidgets);

    await tester.tap(find.byKey(const Key('event-save-action')));
    await tester.pumpAndSettle();

    expect(api.updatedEvent?.category, 'Studio');
    expect(api.updatedEvent?.color, '#123ABC');
  });

  testWidgets(
    'calendar event edit keeps new end time when immediate refresh is stale',
    (WidgetTester tester) async {
      final today = DateTime.now();
      final staleEvent = HermesCalendarEvent(
        id: 3,
        title: 'Design review',
        startsAt: DateTime(
          today.year,
          today.month,
          today.day,
          14,
          30,
        ).toIso8601String(),
        category: 'Personal',
        color: '#34C759',
        recurrence: 'none',
      );
      final api = _StaleCalendarRefreshAfterEditFakeHermesApiClient(staleEvent);
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();
      await _openTodayView(tester);

      const eventKey = Key('calendar-event-block-design-review');
      await Scrollable.ensureVisible(
        tester.element(find.byKey(eventKey)),
        alignment: .3,
      );
      await tester.pumpAndSettle();
      await _moveCalendarEventBelowHeader(tester, find.byKey(eventKey));
      final initialEventHeight = tester.getRect(find.byKey(eventKey)).height;

      await tester.tap(find.byKey(eventKey));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('event-end-field')), '5 PM');
      await tester.tap(find.byKey(const Key('event-save-action')));
      await tester.pumpAndSettle();

      expect(
        api.updatedEvent?.endsAt,
        DateTime(
          today.year,
          today.month,
          today.day,
          17,
        ).toUtc().toIso8601String(),
      );
      await tester.ensureVisible(find.byKey(eventKey));
      await tester.pumpAndSettle();
      expect(
        tester.getRect(find.byKey(eventKey)).height,
        greaterThan(initialEventHeight),
      );
    },
  );

  testWidgets('event save updates the calendar before the API call finishes', (
    WidgetTester tester,
  ) async {
    final api = _EditableCalendarFakeHermesApiClient()
      ..updateEventCompleter = Completer<void>();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();
    await _openTodayView(tester);

    const eventKey = Key('calendar-event-block-design-review');
    await Scrollable.ensureVisible(
      tester.element(find.byKey(eventKey)),
      alignment: .3,
    );
    await tester.pumpAndSettle();
    await _moveCalendarEventBelowHeader(tester, find.byKey(eventKey));

    await tester.tap(find.byKey(eventKey));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('event-title-field')),
      'Updated design review',
    );
    await tester.tap(find.byKey(const Key('event-save-action')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('calendar-event-detail-page')), findsNothing);
    expect(find.textContaining('Updated design review'), findsOneWidget);
    expect(api.updatedEvent, isNull);

    api.updateEventCompleter!.complete();
    await tester.pumpAndSettle();

    expect(api.updatedEvent?.title, 'Updated design review');
  });

  testWidgets(
    'calendar events open an editable detail page with friendly date times category color recurrence and event reminders',
    (WidgetTester tester) async {
      final api = _EditableCalendarFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('calendar-today-button')));
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
      expect(find.byKey(const Key('event-notes-field')), findsOneWidget);
      expect(find.text('Notes'), findsOneWidget);
      expect(find.byKey(const Key('event-location-field')), findsOneWidget);
      expect(find.byKey(const Key('event-status-field')), findsOneWidget);
      expect(find.byKey(const Key('event-category-dropdown')), findsOneWidget);
      expect(find.text('Categories'), findsNothing);
      expect(find.text('Category'), findsOneWidget);
      await tester.ensureVisible(
        find.byKey(const Key('event-category-dropdown')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('event-category-dropdown')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('event-category-none')), findsOneWidget);
      await tester.tap(find.text('Personal').last);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('event-category-manager')), findsNothing);
      expect(
        find.byKey(const Key('event-category-manager-toggle')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('event-category-delete-Work')), findsNothing);
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
      expect(find.text('External Calendar Sync'), findsOneWidget);
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
          .getTopLeft(find.byKey(const Key('event-category-dropdown')))
          .dy;
      expect(titleTop, lessThan(scheduleTop));
      expect(scheduleTop, lessThan(categoryTop));
      expect(categoryTop, lessThan(recurrenceTop));
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
      await tester.enterText(
        find.byKey(const Key('event-notes-field')),
        'Bring launch notes and room setup details.',
      );
      await tester.enterText(
        find.byKey(const Key('event-location-field')),
        'Conference Room B',
      );
      await tester.ensureVisible(find.byKey(const Key('event-status-field')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('event-status-field')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Tentative').last);
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.byKey(const Key('event-recurrence-field')),
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
        find.byKey(const Key('event-create-reminder-toggle')),
      );
      await tester.pumpAndSettle();
      expect(
        find.byKey(const Key('event-reminder-minutes-field')),
        findsNothing,
      );
      expect(
        find.byKey(const Key('event-reminder-recurrence-field')),
        findsNothing,
      );
      await tester.tap(find.byKey(const Key('event-create-reminder-toggle')));
      await tester.pumpAndSettle();
      expect(
        find.byKey(const Key('event-reminder-minutes-field')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('event-reminder-specific-days')),
        findsNothing,
      );
      await tester.scrollUntilVisible(
        find.byKey(const Key('event-category-dropdown')),
        200,
        scrollable: find.byType(Scrollable).last,
      );
      await tester.pumpAndSettle();
      await _selectDropdownText(
        tester,
        dropdownKey: const Key('event-category-dropdown'),
        text: 'Work',
      );
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
      expect(
        api.updatedEvent?.notes,
        'Bring launch notes and room setup details.',
      );
      expect(api.updatedEvent?.location, 'Conference Room B');
      expect(api.updatedEvent?.status, 'tentative');
      expect(api.updatedEvent?.color, '#007AFF');
      expect(api.updatedEvent?.recurrence, 'specific_days');
      expect(api.updatedEvent?.metadata, {
        'recurrence': 'specific_days',
        'google_calendar_ids': ['sports@example.com'],
        'google_calendar_id': 'sports@example.com',
        'outlook_calendar_ids': [],
        'outlook_calendar_id': null,
        'days': ['thu', 'tue'],
        'interval': 1,
        'unit': 'days',
        'place_id': null,
        'place_formatted_address': null,
        'place_lat': null,
        'place_lng': null,
        'google_maps_uri': null,
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
        'days': ['thu', 'tue'],
        'interval': 1,
        'unit': 'days',
      });
    },
  );

  testWidgets('calendar event editor saves all day events with date fields', (
    WidgetTester tester,
  ) async {
    final api = _EditableCalendarFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('calendar-today-button')));
    await tester.pumpAndSettle();

    await tester.ensureVisible(
      find.byKey(const Key('calendar-event-block-design-review')),
    );
    await tester.tap(
      find.byKey(const Key('calendar-event-block-design-review')),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('event-all-day-toggle')));
    await tester.pumpAndSettle();

    final today = DateTime.now();
    final dateLabel =
        '${today.year}-${today.month.toString().padLeft(2, '0')}-${today.day.toString().padLeft(2, '0')}';
    await tester.enterText(
      find.byKey(const Key('event-start-field')),
      dateLabel,
    );
    expect(find.byKey(const Key('event-end-field')), findsNothing);
    await tester.tap(find.byKey(const Key('event-save-action')));
    await tester.pumpAndSettle();

    expect(api.updatedEvent?.metadata?['all_day'], isTrue);
    expect(
      api.updatedEvent?.startsAt,
      DateTime(today.year, today.month, today.day).toUtc().toIso8601String(),
    );
    expect(
      api.updatedEvent?.endsAt,
      DateTime(
        today.year,
        today.month,
        today.day,
        23,
        59,
      ).toUtc().toIso8601String(),
    );
    expect(find.byKey(const Key('calendar-all-day-event-3')), findsOneWidget);
    expect(
      find.byKey(const Key('calendar-event-block-design-review')),
      findsNothing,
    );
  });

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
    await tester.tap(find.byKey(const Key('calendar-today-button')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('calendar-all-day-label')), findsOneWidget);
    expect(find.byKey(const Key('calendar-all-day-event-901')), findsOneWidget);
    expect(find.byKey(const Key('calendar-multi-day-label')), findsNothing);
    expect(find.text('All Day'), findsOneWidget);
    expect(find.text('Google holiday'), findsOneWidget);
    final allDayRowTop = tester
        .getTopLeft(find.byKey(const Key('calendar-all-day-event-901')))
        .dy;
    await tester.drag(
      find.byKey(const Key('apple-style-day-timeline-scroll')),
      const Offset(0, -420),
    );
    await tester.pumpAndSettle();
    expect(
      tester.getTopLeft(find.byKey(const Key('calendar-all-day-event-901'))).dy,
      closeTo(allDayRowTop, 1),
    );
    expect(
      find.byKey(const Key('calendar-event-block-google-holiday')),
      findsNothing,
    );
  });

  testWidgets('all day UTC midnight events stay on their stored date', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _UtcMidnightAllDayCalendarFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('calendar-today-button')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('calendar-all-day-event-905')), findsOneWidget);
    expect(find.text('Moving out of shop'), findsOneWidget);
  });

  testWidgets('multi-day timed events render in the multi-day row only', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _WeekendMultiDayCalendarFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();
    await _openTodayView(tester);

    final multiDayEvent = find.byKey(const Key('calendar-multi-day-event-904'));
    expect(find.byKey(const Key('calendar-multi-day-label')), findsOneWidget);
    expect(find.text('Multi-Day'), findsOneWidget);
    expect(multiDayEvent, findsOneWidget);
    expect(
      tester.getSize(multiDayEvent).width,
      greaterThan(
        tester
                .getSize(
                  find.byKey(
                    const PageStorageKey<String>('apple-style-day-page-view'),
                  ),
                )
                .width /
            2,
      ),
    );
    expect(
      find.descendant(
        of: multiDayEvent.first,
        matching: find.text('1pm Anniversary Trip'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: multiDayEvent.first,
        matching: find.text('Anniversary Trip'),
      ),
      findsWidgets,
    );
    expect(
      find.descendant(
        of: multiDayEvent.first,
        matching: find.text('Anniversary Trip 8pm'),
      ),
      findsOneWidget,
    );
    final selectedHeading = find.byKey(
      const Key('day-column-heading-selected'),
    );
    final headingBeforeDrag = tester.getRect(selectedHeading);
    final eventBeforeDrag = tester.getRect(multiDayEvent);
    await _dragCalendarDayPage(tester, const Offset(-120, 0));
    await tester.pumpAndSettle();
    final headingDuringDrag = tester.getRect(selectedHeading);
    final eventDuringDrag = tester.getRect(multiDayEvent);
    final headingTravel = headingBeforeDrag.left - headingDuringDrag.left;
    final eventTravel = eventBeforeDrag.left - eventDuringDrag.left;
    expect(headingTravel, greaterThan(40));
    expect(eventTravel, greaterThan(40));
    expect(eventTravel, closeTo(headingTravel, 8));
    expect(find.byKey(const Key('calendar-all-day-event-904')), findsNothing);
    expect(
      find.byKey(const Key('calendar-event-block-anniversary-trip')),
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
      expect(find.byKey(const Key('create-item-menu')), findsOneWidget);
      expect(find.byKey(const Key('task-critical-star-1')), findsOneWidget);

      await tester.tap(find.byKey(const Key('nav-reminders')));
      await tester.pumpAndSettle();
      expect(find.textContaining('Create, edit, and review'), findsNothing);
      expect(find.byKey(const Key('create-item-menu')), findsOneWidget);
      expect(find.byKey(const Key('reminder-critical-star-2')), findsNothing);
    },
  );

  testWidgets('multi-day event end edits move the event to the multi-day row', (
    WidgetTester tester,
  ) async {
    final api = _MultiDayEditableCalendarFakeHermesApiClient();
    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();
    await _openTodayView(tester);

    final eventBlock = find.byKey(const Key('calendar-event-block-conference'));
    await tester.ensureVisible(eventBlock);
    await tester.pumpAndSettle();

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
    expect(
      find.byKey(const Key('calendar-multi-day-event-40')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('calendar-all-day-event-40')), findsNothing);
    expect(eventBlock, findsNothing);
  });

  testWidgets('UTC calendar event timestamps render in the device timezone', (
    WidgetTester tester,
  ) async {
    final now = DateTime.now();
    final wallClockStart = DateTime.utc(
      now.year,
      now.month,
      now.day,
      14,
    ).toLocal();
    final tomorrow = now.add(const Duration(days: 1));
    final wallClockEnd = DateTime.utc(
      tomorrow.year,
      tomorrow.month,
      tomorrow.day,
      15,
    ).toLocal();
    final expectedStartLabel =
        '${_testNaturalTimeLabel(wallClockStart)} App project focus block';
    final expectedEndLabel =
        'App project focus block ${_testNaturalTimeLabel(wallClockEnd)}';

    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _UtcMultiDayCalendarFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();
    await _openTodayView(tester);

    final multiDayEvent = find.byKey(const Key('calendar-multi-day-event-31'));
    expect(multiDayEvent, findsOneWidget);
    expect(
      find.descendant(
        of: multiDayEvent.first,
        matching: find.text(expectedStartLabel),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: multiDayEvent.first,
        matching: find.text(expectedEndLabel),
      ),
      findsOneWidget,
    );
    expect(find.byKey(const Key('calendar-all-day-event-31')), findsNothing);
    expect(
      find.byKey(const Key('calendar-event-block-app-project-focus-block')),
      findsNothing,
    );

    await tester.drag(
      find.byKey(const Key('apple-style-day-timeline-scroll')),
      const Offset(0, 2000),
    );
    await tester.pumpAndSettle();
    await tester.tap(multiDayEvent.hitTestable().first);
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

  testWidgets(
    'offset calendar event timestamps render in the device timezone',
    (WidgetTester tester) async {
      final now = DateTime.now();
      final wallClockStart = DateTime.parse(
        _testIsoWithOffset(now, hour: 13, offset: '-04:00'),
      ).toLocal();
      final tomorrow = now.add(const Duration(days: 1));
      final wallClockEnd = DateTime.parse(
        _testIsoWithOffset(tomorrow, hour: 17, offset: '-04:00'),
      ).toLocal();
      final expectedStartLabel =
          '${_testNaturalTimeLabel(wallClockStart)} Google afternoon block';
      final expectedEndLabel =
          'Google afternoon block ${_testNaturalTimeLabel(wallClockEnd)}';

      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _OffsetCalendarFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();
      await _openTodayView(tester);

      final multiDayEvent = find.byKey(
        const Key('calendar-multi-day-event-32'),
      );
      expect(multiDayEvent, findsOneWidget);
      expect(
        find.descendant(
          of: multiDayEvent.first,
          matching: find.text(expectedStartLabel),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: multiDayEvent.first,
          matching: find.text(expectedEndLabel),
        ),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('calendar-event-block-google-afternoon-block')),
        findsNothing,
      );
    },
  );

  testWidgets(
    'saving an edited event preserves local times as UTC wire values',
    (WidgetTester tester) async {
      final api = _EditableCalendarFakeHermesApiClient();
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('calendar-today-button')));
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
    'critical event edits normalize local ISO fields to UTC wire values',
    (WidgetTester tester) async {
      final localStart = DateTime(
        DateTime.now().year,
        DateTime.now().month,
        DateTime.now().day,
        10,
        30,
      );
      final localEnd = DateTime(
        DateTime.now().year,
        DateTime.now().month,
        DateTime.now().day,
        11,
      );
      final api = _EditableCalendarFakeHermesApiClient(
        initialEvent: HermesCalendarEvent(
          id: 3,
          title: 'Local wire event',
          startsAt: localStart.toIso8601String(),
          endsAt: localEnd.toIso8601String(),
          category: 'Personal',
          color: '#34C759',
          recurrence: 'none',
        ),
      );
      await tester.pumpWidget(
        HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
      );
      await tester.pumpAndSettle();
      await _openTodayView(tester);

      await tester.ensureVisible(
        find.byKey(const Key('calendar-event-block-local-wire-event')),
      );
      await tester.tap(
        find.byKey(const Key('calendar-event-block-local-wire-event')),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('event-detail-critical-toggle')));
      await tester.pumpAndSettle();

      expect(api.updatedEvent?.startsAt, localStart.toUtc().toIso8601String());
      expect(api.updatedEvent?.endsAt, localEnd.toUtc().toIso8601String());
      expect(api.updatedEvent?.isCritical, isTrue);
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
      await tester.tap(find.byKey(const Key('calendar-today-button')));
      await tester.pumpAndSettle();

      await tester.ensureVisible(
        find.byKey(const Key('calendar-event-block-design-review')),
      );
      await tester.tap(
        find.byKey(const Key('calendar-event-block-design-review')),
      );
      await tester.pumpAndSettle();

      await tester.ensureVisible(
        find.byKey(const Key('event-category-dropdown')),
      );
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('event-category-dropdown')), findsOneWidget);
      expect(find.text('Categories'), findsNothing);
      expect(find.text('Category'), findsOneWidget);
      await tester.tap(find.byKey(const Key('event-category-dropdown')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('event-category-none')), findsOneWidget);
      await tester.tap(find.text('Personal').last);
      await tester.pumpAndSettle();
      expect(
        find.byKey(const Key('event-category-manager-toggle')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('event-category-delete-Work')), findsNothing);
      expect(
        find.byKey(const Key('event-category-add-action')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('event-category-field')), findsNothing);
      expect(find.byKey(const Key('event-category-manager')), findsNothing);
      expect(find.byKey(const Key('event-category-save-action')), findsNothing);

      await tester.tap(find.byKey(const Key('event-category-manager-toggle')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('event-category-manager')), findsOneWidget);
      expect(find.byKey(const Key('event-category-chip-Work')), findsOneWidget);
      expect(
        find.byKey(const Key('event-category-delete-Work')),
        findsOneWidget,
      );

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
      expect(
        find.descendant(
          of: find.byKey(const Key('event-time-minute-dial')),
          matching: find.text('25'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('event-time-minute-dial')),
          matching: find.text('30'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('event-time-minute-dial')),
          matching: find.text('35'),
        ),
        findsOneWidget,
      );
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
      await _openTodayView(tester);

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
    await _openTodayView(tester);

    expect(find.text('Tasks for Today'), findsOneWidget);
    expect(find.text('Today task'), findsOneWidget);
    expect(find.text('Today reminder'), findsNothing);
    expect(find.text('Tomorrow task'), findsNothing);
    expect(find.text('Tomorrow reminder'), findsNothing);
    expect(
      tester.getTopLeft(find.byKey(const Key('today-task-list'))).dy,
      lessThan(
        tester.getTopLeft(find.byKey(const Key('heybean-bottom-menu'))).dy,
      ),
    );

    await _dragCalendarDayPage(tester, const Offset(-400, 0));
    await tester.pumpAndSettle();

    expect(find.text('Tasks for Today'), findsOneWidget);
    expect(find.text('Today task'), findsOneWidget);
    expect(find.text('Today reminder'), findsNothing);
    expect(find.text('Tomorrow task'), findsNothing);
    expect(find.text('Tomorrow reminder'), findsNothing);
  });

  testWidgets(
    'calendar task list excludes future and unscheduled tasks while tasks screen keeps them',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        HermesBeanApp(
          apiClient: _CalendarTaskScopeFakeHermesApiClient(),
          tokenStore: _MemoryAuthTokenStore(),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('calendar-today-button')));
      await tester.pumpAndSettle();

      expect(find.text('Tasks for Today'), findsOneWidget);
      expect(find.text('Overdue task'), findsOneWidget);
      expect(find.text('Today task'), findsOneWidget);
      expect(find.text('Tomorrow task'), findsNothing);
      expect(find.text('Unscheduled task'), findsNothing);
      expect(find.text('Future recurring task'), findsNothing);
      expect(find.text('Next month task'), findsNothing);

      await tester.tap(find.byKey(const Key('calendar-month-chevron')));
      await tester.pumpAndSettle();

      final now = DateTime.now();
      expect(
        find.text('Tasks for ${_testMonthNames[now.month - 1]} ${now.year}'),
        findsOneWidget,
      );
      expect(find.text('Overdue task'), findsOneWidget);
      expect(find.text('Today task'), findsOneWidget);
      expect(find.text('Tomorrow task'), findsOneWidget);
      expect(find.text('Unscheduled task'), findsNothing);
      expect(find.text('Future recurring task'), findsOneWidget);
      expect(find.text('Next month task'), findsNothing);

      await tester.tap(find.byKey(const Key('nav-tasks')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('tasks-view')), findsOneWidget);
      expect(find.byKey(const Key('task-today-section')), findsOneWidget);
      expect(find.byKey(const Key('task-upcoming-section')), findsOneWidget);
      expect(find.text('Overdue task'), findsOneWidget);
      expect(find.text('Today task'), findsOneWidget);
      expect(find.text('Tomorrow task'), findsOneWidget);
      expect(find.text('Unscheduled task'), findsOneWidget);
      expect(find.text('Future recurring task'), findsOneWidget);
      expect(find.text('Ten day task'), findsNothing);
      expect(find.text('Next month task'), findsNothing);
      expect(find.byKey(const Key('task-future-seven-toggle')), findsOneWidget);
      expect(
        find.byKey(const Key('task-future-thirty-toggle')),
        findsOneWidget,
      );

      await tester.ensureVisible(
        find.byKey(const Key('task-future-seven-toggle')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('task-future-seven-toggle')));
      await tester.pumpAndSettle();

      expect(find.text('Ten day task'), findsOneWidget);
      expect(find.text('Next month task'), findsNothing);

      await tester.ensureVisible(
        find.byKey(const Key('task-future-thirty-toggle')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('task-future-thirty-toggle')));
      await tester.pumpAndSettle();

      expect(find.text('Next month task'), findsOneWidget);
    },
  );

  testWidgets('reminders screen uses today and upcoming date sections', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      HermesBeanApp(
        apiClient: _TomorrowReminderFakeHermesApiClient(),
        tokenStore: _MemoryAuthTokenStore(),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-reminders')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('reminders-view')), findsOneWidget);
    expect(find.byKey(const Key('reminder-today-section')), findsOneWidget);
    expect(find.byKey(const Key('reminder-upcoming-section')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const Key('reminder-today-section')),
        matching: find.text('Today reminder'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('reminder-upcoming-section')),
        matching: find.text('Tomorrow reminder'),
      ),
      findsOneWidget,
    );
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
    await tester.tap(find.byKey(const Key('title-time-editor-save-bottom')));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(api.updatedReminder?.title, 'Refill Bean food');
  });

  testWidgets('reminders view can check off reminders from the list', (
    WidgetTester tester,
  ) async {
    final api = _EditableReminderFakeHermesApiClient()
      ..updateReminderCompleter = Completer<void>();

    await tester.pumpWidget(
      HermesBeanApp(apiClient: api, tokenStore: _MemoryAuthTokenStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('nav-reminders')));
    await tester.pumpAndSettle();
    expect(find.text('Refill dog food'), findsOneWidget);

    await tester.tap(find.byKey(const Key('reminder-complete-checkbox-401')));
    await tester.pump();

    expect(api.updatedReminder, isNull);
    expect(find.text('Refill dog food'), findsNothing);

    api.updateReminderCompleter!.complete();
    await tester.pumpAndSettle();

    expect(api.updatedReminder?.status, 'completed');

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

class _FakeStripePaymentHandler implements StripePaymentHandler {
  final preparedSetupIntentIds = <String>[];
  int presentedSheets = 0;

  @override
  Future<void> preparePaymentSheet(
    HermesPaymentSheetSetup setup, {
    required HermesUser user,
    required String primaryButtonLabel,
  }) async {
    preparedSetupIntentIds.add(setup.setupIntentId);
  }

  @override
  Future<void> presentPaymentSheet() async {
    presentedSheets++;
  }
}

class _StaleTodayPersistedResourcesFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesTask>> listTasks() async => [
    HermesTask(
      id: 901,
      title: 'Persisted task',
      status: 'open',
      dueAt: DateTime.now().toIso8601String(),
    ),
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
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async => [
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

class _CommandCenterAgendaFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  DateTime _todayFutureTime(int offsetMinutes) {
    final now = DateTime.now();
    final latestToday = DateTime(now.year, now.month, now.day, 23, 59);
    final minutesLeft = math.max(1, latestToday.difference(now).inMinutes);
    final clampedOffset = math.min(offsetMinutes, minutesLeft);
    return now.add(Duration(minutes: clampedOffset));
  }

  @override
  Future<List<HermesTask>> listTasks() async {
    return [
      HermesTask(
        id: 501,
        title: 'Prep launch notes',
        status: 'open',
        dueAt: _todayFutureTime(20).toIso8601String(),
      ),
    ];
  }

  @override
  Future<List<HermesReminder>> listReminders() async {
    return [
      HermesReminder(
        id: 502,
        title: 'Send recap',
        status: 'pending',
        dueAt: _todayFutureTime(60).toIso8601String(),
      ),
    ];
  }

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
    final start = _todayFutureTime(40);
    return [
      HermesCalendarEvent(
        id: 503,
        title: 'Design review',
        startsAt: start.toIso8601String(),
        endsAt: start.add(const Duration(minutes: 30)).toIso8601String(),
      ),
    ];
  }
}

class _CommandCenterOverdueAgendaFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  DateTime _relativeTime(int minuteOffset) {
    final now = DateTime.now();
    return DateTime(
      now.year,
      now.month,
      now.day,
      now.hour,
      now.minute,
    ).add(Duration(minutes: minuteOffset));
  }

  DateTime _todayFutureTime(int offsetMinutes) {
    final now = DateTime.now();
    final latestToday = DateTime(now.year, now.month, now.day, 23, 59);
    final minutesLeft = math.max(1, latestToday.difference(now).inMinutes);
    final clampedOffset = math.min(offsetMinutes, minutesLeft);
    return now.add(Duration(minutes: clampedOffset));
  }

  @override
  Future<List<HermesTask>> listTasks() async {
    return [
      HermesTask(
        id: 511,
        title: 'Overdue check-in',
        status: 'open',
        dueAt: _relativeTime(-30).toIso8601String(),
      ),
    ];
  }

  @override
  Future<List<HermesReminder>> listReminders() async {
    return [
      HermesReminder(
        id: 512,
        title: 'Overdue nudge',
        status: 'pending',
        dueAt: _relativeTime(-60).toIso8601String(),
      ),
    ];
  }

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
    final start = _todayFutureTime(60);
    return [
      HermesCalendarEvent(
        id: 513,
        title: 'Upcoming sync',
        startsAt: start.toIso8601String(),
        endsAt: start.add(const Duration(minutes: 30)).toIso8601String(),
      ),
    ];
  }
}

class _CommandCenterDeleteRefreshEmptyFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  int? deletedEventId;

  DateTime _todayFutureTime(int offsetMinutes) {
    final now = DateTime.now();
    final latestToday = DateTime(now.year, now.month, now.day, 23, 59);
    final minutesLeft = math.max(1, latestToday.difference(now).inMinutes);
    final clampedOffset = math.min(offsetMinutes, minutesLeft);
    return now.add(Duration(minutes: clampedOffset));
  }

  @override
  Future<List<HermesTask>> listTasks() async => const [];

  @override
  Future<List<HermesReminder>> listReminders() async {
    if (deletedEventId != null) return const [];
    return [
      HermesReminder(
        id: 601,
        title: 'Grocery shopping reminder',
        status: 'pending',
        dueAt: _todayFutureTime(20).toIso8601String(),
        workspaceId: 1,
      ),
    ];
  }

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
    final groceryStart = _todayFutureTime(30);
    final cookingStart = _todayFutureTime(60);
    if (deletedEventId != null) {
      return [
        HermesCalendarEvent(
          id: deletedEventId!,
          title: 'Cooking dinner',
          workspaceId: 1,
          startsAt: cookingStart.toIso8601String(),
          endsAt: cookingStart
              .add(const Duration(minutes: 45))
              .toIso8601String(),
        ),
      ];
    }
    return [
      HermesCalendarEvent(
        id: 602,
        title: 'Grocery shopping',
        workspaceId: 1,
        startsAt: groceryStart.toIso8601String(),
        endsAt: groceryStart.add(const Duration(minutes: 45)).toIso8601String(),
      ),
      HermesCalendarEvent(
        id: 603,
        title: 'Cooking dinner',
        workspaceId: 1,
        startsAt: cookingStart.toIso8601String(),
        endsAt: cookingStart.add(const Duration(minutes: 45)).toIso8601String(),
      ),
    ];
  }

  @override
  Future<HermesTodaySummary> todaySummary({int? workspaceId}) async {
    todaySummaryCalls++;
    return HermesTodaySummary(
      tasks: const [],
      reminders: await listReminders(),
      calendarEvents: await listCalendarEvents(),
      activityEvents: const [],
      approvals: const [],
      blockers: const [],
    );
  }

  @override
  Future<void> deleteCalendarEvent(
    int eventId, {
    List<Object> deleteFromWorkspaceIds = const [],
    String? recurringDeleteMode,
    String? recurringOccurrenceDate,
  }) async {
    deletedEventId = eventId;
  }
}

class _WeekendMultiDayCalendarFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
    final start = DateTime.now();
    final end = start.add(const Duration(days: 3));
    return [
      HermesCalendarEvent(
        id: 904,
        title: 'Anniversary Trip',
        startsAt: DateTime(
          start.year,
          start.month,
          start.day,
          13,
        ).toIso8601String(),
        endsAt: DateTime(end.year, end.month, end.day, 20).toIso8601String(),
        category: 'Travel',
        color: '#FF2AD4',
        recurrence: 'none',
      ),
    ];
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
    String? notes,
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
      notes: notes,
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

class _HistoryLimitedFakeHermesApiClient extends _SignedInFakeHermesApiClient {
  @override
  Future<HermesUser> me() async {
    final user = await super.me();
    final cutoff = DateTime.now().subtract(const Duration(days: 30));
    return user.copyWith(
      planLimits: HermesPlanLimits(
        tier: 'base',
        workspaceLimit: 1,
        calendarConnectionLimit: 1,
        connectedAccountLimit: 1,
        historyDays: 30,
        historyCutoff: cutoff.toIso8601String(),
      ),
    );
  }
}

class _DelayedQueueFakeHermesApiClient extends _SignedInFakeHermesApiClient {
  final Completer<HermesMessageResult> _queueCompleter =
      Completer<HermesMessageResult>();

  @override
  Future<HermesMessageResult> queueMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
    String source = 'flutter',
  }) {
    queueMessageCalls++;
    sentMessages.add(content);
    return _queueCompleter.future;
  }

  void completeQueue() {
    if (_queueCompleter.isCompleted) return;
    _queueCompleter.complete(
      const HermesMessageResult(
        status: 'completed',
        session: HermesSession(id: 42, status: 'active', title: 'Today'),
        userMessage: HermesMessage(
          id: 7100,
          role: 'user',
          content: 'what is the weather tomorrow in Orlando',
        ),
        assistantMessage: HermesMessage(
          id: 7101,
          role: 'assistant',
          content: 'It should be warm and cloudy tomorrow.',
        ),
        events: [],
      ),
    );
  }
}

class _NotesFakeHermesApiClient extends _SignedInFakeHermesApiClient {
  static const personalWorkspace = HermesWorkspace(
    id: '1',
    name: 'Personal',
    type: 'personal',
    role: 'owner',
    active: true,
    isDefault: true,
  );
  static const familyWorkspace = HermesWorkspace(
    id: '2',
    name: 'Family',
    type: 'household',
    role: 'owner',
  );

  final createdNotes = <HermesNote>[];
  final updatedNotes = <HermesNote>[];
  final updatedNoteSyncWorkspaceIds = <List<Object>?>[];
  final deletedFolderIds = <int>[];

  @override
  Future<HermesUser> me() async {
    final user = await super.me();
    return user.copyWith(
      subscriptionTier: 'premium',
      planLimits: const HermesPlanLimits(
        tier: 'premium',
        workspaceLimit: 5,
        calendarConnectionLimit: 5,
        connectedAccountLimit: 3,
        historyDays: 365,
        recurringTasksEnabled: true,
        recurringRemindersEnabled: true,
        recurringCalendarEnabled: true,
        emailRemindersEnabled: true,
        notesEnabled: true,
      ),
      defaultWorkspaceId: 1,
      personalWorkspace: personalWorkspace,
      activeWorkspace: personalWorkspace,
      workspaces: const [personalWorkspace, familyWorkspace],
    );
  }

  @override
  Future<List<HermesWorkspace>> listWorkspaces() async => const [
    personalWorkspace,
    familyWorkspace,
  ];

  @override
  Future<List<HermesNoteFolder>> listNoteFolders() async => const [
    HermesNoteFolder(id: 1, name: 'Work'),
  ];

  @override
  Future<List<HermesNote>> listNotes() async => const [
    HermesNote(
      id: 1,
      title: 'Meeting notes',
      plainText: 'Bring the launch plan.',
      folderId: 1,
      isPinned: true,
      updatedAt: '2026-06-20T12:00:00Z',
      workspaceId: 1,
      linkedWorkspaceIds: [1, 2],
    ),
  ];

  @override
  Future<HermesNote> createNote({
    String title = 'New Note',
    String bodyHtml = '',
    String plainText = '',
    int? folderId,
    bool isPinned = false,
    Map<String, Object?>? metadata,
    List<Object> syncToWorkspaceIds = const [],
  }) async {
    final note = HermesNote(
      id: 2 + createdNotes.length,
      title: title,
      bodyHtml: bodyHtml,
      plainText: plainText,
      folderId: folderId,
      isPinned: isPinned,
      updatedAt: '2026-06-20T13:00:00Z',
      metadata: metadata ?? const {},
      workspaceId: 1,
      linkedWorkspaceIds: [1, ...syncToWorkspaceIds.whereType<int>()],
    );
    createdNotes.add(note);
    return note;
  }

  @override
  Future<HermesNote> updateNote(
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
    updatedNoteSyncWorkspaceIds.add(syncToWorkspaceIds);
    final note = HermesNote(
      id: noteId,
      title: title ?? 'Meeting notes',
      bodyHtml: bodyHtml,
      plainText: plainText,
      folderId: clearFolder ? null : folderId ?? 1,
      isPinned: isPinned ?? true,
      updatedAt: '2026-06-20T14:00:00Z',
      metadata: metadata ?? const {},
      workspaceId: 1,
      linkedWorkspaceIds: [1, ...?syncToWorkspaceIds?.whereType<int>()],
    );
    updatedNotes.add(note);
    return note;
  }

  @override
  Future<void> deleteNoteFolder(int folderId) async {
    deletedFolderIds.add(folderId);
  }
}

class _FutureCriticalFakeHermesApiClient extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesTask>> listTasks() async => [
    ...await super.listTasks(),
    HermesTask(
      id: 101,
      title: 'Future task',
      status: 'open',
      dueAt: DateTime.now().add(const Duration(days: 3)).toIso8601String(),
      isCritical: true,
    ),
  ];

  @override
  Future<List<HermesReminder>> listReminders() async => [
    ...await super.listReminders(),
    HermesReminder(
      id: 102,
      title: 'Future reminder',
      status: 'pending',
      dueAt: DateTime.now().add(const Duration(days: 3)).toIso8601String(),
      isCritical: true,
    ),
  ];

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async => [
    ...await super.listCalendarEvents(),
    HermesCalendarEvent(
      id: 103,
      title: 'Future event',
      startsAt: DateTime.now().add(const Duration(days: 3)).toIso8601String(),
      isCritical: true,
    ),
  ];
}

class _NonCriticalOverdueFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesTask>> listTasks() async => [
    ...await super.listTasks(),
    HermesTask(
      id: 104,
      title: 'Overdue regular task',
      status: 'open',
      dueAt: DateTime.now().subtract(const Duration(days: 2)).toIso8601String(),
      isCritical: false,
    ),
  ];

  @override
  Future<List<HermesReminder>> listReminders() async => [
    ...await super.listReminders(),
    HermesReminder(
      id: 105,
      title: 'Overdue regular reminder',
      status: 'pending',
      dueAt: DateTime.now().subtract(const Duration(days: 2)).toIso8601String(),
      isCritical: false,
    ),
  ];
}

class _SlowDashboardFakeHermesApiClient extends _SignedInFakeHermesApiClient {
  final Completer<HermesTodaySummary> _summaryCompleter =
      Completer<HermesTodaySummary>();

  void completeDashboardLoad() {
    if (_summaryCompleter.isCompleted) return;
    _summaryCompleter.complete(
      HermesTodaySummary(
        tasks: const [],
        reminders: const [],
        calendarEvents: const [],
        activityEvents: const [],
        approvals: approvals,
        blockers: const [],
      ),
    );
  }

  @override
  Future<HermesTodaySummary> todaySummary({int? workspaceId}) {
    todaySummaryCalls++;
    return _summaryCompleter.future;
  }
}

class _RefreshFailsAfterLogoutFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  final Completer<void> refreshStarted = Completer<void>();
  final Completer<void> _refreshRelease = Completer<void>();

  void failRefresh() {
    if (!_refreshRelease.isCompleted) _refreshRelease.complete();
  }

  @override
  Future<HermesTodaySummary> todaySummary({int? workspaceId}) async {
    todaySummaryCalls++;
    if (todaySummaryCalls == 1) {
      return super.todaySummary(workspaceId: workspaceId);
    }
    if (!refreshStarted.isCompleted) refreshStarted.complete();
    await _refreshRelease.future;
    throw const HermesApiException(401, '{"message":"Unauthenticated."}');
  }

  @override
  Future<void> logout({bool clearBearerToken = true}) async {
    if (clearBearerToken) bearerToken = null;
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
  int? removedWorkspaceId;
  int? removedWorkspaceMemberId;
  int? updatedWorkspaceMemberId;
  String? updatedWorkspaceMemberRole;
  int? invitedWorkspaceId;
  String? invitedEmail;
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
    subscriptionStatus: 'active',
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
        'onboarding': {
          'completed': true,
          'priorities': <String>['Planning'],
        },
      },
    ),
    activeWorkspaceAgentProfile: const HermesAgentProfile(
      settings: {
        'personality_type': 'balanced',
        'onboarding': {
          'completed': true,
          'priorities': <String>['Planning'],
        },
      },
    ),
    needsBeanOnboarding: false,
    beanPreferencesReady: true,
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
  Future<HermesWorkspaceMembership> inviteWorkspaceMember(
    int workspaceId, {
    required String email,
  }) async {
    invitedWorkspaceId = workspaceId;
    invitedEmail = email;
    final membership = HermesWorkspaceMembership(
      id: 99,
      workspaceId: workspaceId,
      role: 'member',
      status: 'invited',
      invitedEmail: email,
      invitationToken: 'new-invite-token',
      invitationAcceptUrl:
          'https://heybean.org/workspace-invitations/new-invite-token/accept',
    );
    workspaces = [
      for (final workspace in workspaces)
        workspace.numericId == workspaceId
            ? workspace.copyWith(
                memberships: [
                  ...workspace.memberships.where(
                    (existing) => existing.invitedEmail != email,
                  ),
                  membership,
                ],
              )
            : workspace,
    ];
    return membership;
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
  Future<HermesWorkspaceMembership> updateWorkspaceMember(
    int workspaceId,
    int memberId, {
    required String role,
  }) async {
    updatedWorkspaceMemberId = memberId;
    updatedWorkspaceMemberRole = role;
    return HermesWorkspaceMembership(
      id: memberId,
      workspaceId: workspaceId,
      role: role,
    );
  }

  @override
  Future<void> removeWorkspaceMember(int workspaceId, int memberId) async {
    removedWorkspaceId = workspaceId;
    removedWorkspaceMemberId = memberId;
    workspaces = [
      for (final workspace in workspaces)
        workspace.numericId == workspaceId
            ? workspace.copyWith(
                memberships: workspace.memberships
                    .where((membership) => membership.id != memberId)
                    .toList(),
              )
            : workspace,
    ];
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

class _WorkspaceLimitFakeHermesApiClient extends _WorkspaceFakeHermesApiClient {
  @override
  Future<HermesWorkspace> createWorkspace({required String name}) async {
    throw const HermesApiException(
      402,
      '{"message":"Your current plan includes up to 2 workspaces.","error":{"code":"subscription_limit_reached","message":"Your current plan includes up to 2 workspaces.","cta_label":"View plans","upgrade_url":"https://heybean.org/pricing"}}',
    );
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

class _PostLoginRefreshTimeoutHermesApiClient extends _FakeHermesApiClient {
  @override
  Future<HermesSession> startSession({
    String? title,
    String? runtimeMode,
    int? workspaceId,
    Map<String, Object?>? metadata,
  }) async {
    throw TimeoutException('session refresh timed out');
  }
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
  bool betaUser = false;
  bool plannedToday = false;
  int startedSessionCount = 0;
  HermesSession? todaySession;
  List<HermesMessage> todaySessionMessages = const [];
  int todaySummaryCalls = 0;
  bool googleCalendarConnected = false;
  bool outlookCalendarConnected = false;
  bool showDueReminderBanner = false;
  int googleCalendarSyncCalls = 0;
  int outlookCalendarSyncCalls = 0;
  List<String> selectedGoogleCalendarIds = <String>['primary'];
  List<String> selectedOutlookCalendarIds = <String>['outlook-primary'];
  String defaultGoogleCalendarId = 'primary';
  String defaultOutlookCalendarId = 'outlook-primary';
  int cancelledSessionCalls = 0;
  String? updatedName;
  String? registeredAgentPersonality;
  List<String>? registeredPriorities;
  String? registeredContext;
  String? updatedAgentPersonality;
  List<String>? updatedPriorities;
  String? updatedContext;
  HermesNotificationPreferences updatedNotificationPreferences =
      const HermesNotificationPreferences();
  String updatedTheme = 'green';
  String updatedThemeMode = 'auto';
  String updatedCommandCenterLabel = 'Command Center';
  String updatedPreferredMapApp = 'google';
  String subscriptionTier = 'base';
  String? currentSubscriptionStatus = 'active';
  String subscriptionCurrentPeriodEnd = '2026-06-25T00:00:00+00:00';
  String? subscriptionAccessEndsAt;
  bool subscriptionCancelAtPeriodEnd = false;
  bool subscriptionCanResume = false;
  HermesBillingPaymentMethod? billingPaymentMethod =
      const HermesBillingPaymentMethod(
        brand: 'visa',
        last4: '4242',
        expMonth: 12,
        expYear: 2032,
      );
  final passwordResetRequests = <String>[];
  final registeredUsers = <Map<String, String>>[];
  final takenEmails = <String>{};
  final checkoutRequests = <Map<String, String>>[];
  int sendMessageCalls = 0;
  int branchMessageCalls = 0;
  int? branchedFromMessageId;
  int queueMessageCalls = 0;
  final mobileSubscriptionSetupRequests = <Map<String, String>>[];
  final mobileSubscriptionConfirmRequests = <Map<String, String>>[];
  int paymentMethodSetupRequests = 0;
  final paymentMethodConfirmRequests = <String>[];
  int cancelSubscriptionRequests = 0;
  int resumeSubscriptionRequests = 0;
  final issueReports = <String>[];
  HermesReminder? bannerUpdatedReminder;
  List<HermesApproval> approvals = const [];
  int? approvedApprovalId;
  bool alwaysApprovedApproval = false;
  int? deniedApprovalId;

  HermesSubscriptionSummary get _billingSubscriptionSummary =>
      HermesSubscriptionSummary(
        tier: subscriptionTier,
        status: currentSubscriptionStatus,
        currentPeriodEnd: subscriptionCurrentPeriodEnd,
        accessEndsAt: subscriptionAccessEndsAt,
        cancelAtPeriodEnd: subscriptionCancelAtPeriodEnd,
        canUpgrade: subscriptionTier != 'pro',
        canCancel:
            currentSubscriptionStatus != null && !subscriptionCancelAtPeriodEnd,
        canResume: subscriptionCanResume,
      );

  HermesUser _user({
    required String name,
    required String email,
    String? subscriptionStatus,
    bool isAdmin = false,
  }) {
    final persistedPersonality = staleSettingsAfterUpdate
        ? null
        : updatedAgentPersonality;
    final persistedPriorities = staleSettingsAfterUpdate
        ? null
        : updatedPriorities;
    final persistedContext = staleSettingsAfterUpdate ? null : updatedContext;
    final onboardingComplete =
        !staleOnboardingAfterUpdate &&
        (updatedAgentPersonality != null || onboardingCompleted);
    final priorities =
        persistedPriorities ??
        (onboardingComplete ? <String>['Planning'] : <String>[]);
    final needsBeanOnboarding =
        !onboardingComplete ||
        (priorities.isEmpty && (persistedContext?.trim().isEmpty ?? true));
    final profile = HermesAgentProfile(
      settings: {
        'personality_type': persistedPersonality ?? 'balanced',
        'onboarding': {
          'completed': onboardingComplete,
          'priorities': priorities,
          'context': persistedContext,
        },
      },
    );

    return HermesUser(
      id: 1,
      name: name,
      email: email,
      subscriptionTier: subscriptionTier,
      subscriptionStatus: subscriptionStatus ?? currentSubscriptionStatus,
      theme: updatedTheme,
      themeMode: updatedThemeMode,
      commandCenterLabel: updatedCommandCenterLabel,
      preferredMapApp: updatedPreferredMapApp,
      onboardComplete: !needsBeanOnboarding,
      agentProfile: profile,
      activeWorkspaceAgentProfile: profile,
      needsBeanOnboarding: needsBeanOnboarding,
      beanPreferencesReady: !needsBeanOnboarding,
      isBeta: betaUser,
      isAdmin: isAdmin,
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
    registeredUsers.add({'name': name, 'email': email, 'password': password});
    bearerToken = 'fake-token';
    return HermesAuthResult(
      token: 'fake-token',
      user: _user(name: name, email: email, subscriptionStatus: 'incomplete'),
    );
  }

  @override
  Future<HermesEmailAvailability> checkEmailAvailability({
    required String email,
  }) async {
    final normalizedEmail = email.trim().toLowerCase();
    return HermesEmailAvailability(
      email: normalizedEmail,
      available: !takenEmails.contains(normalizedEmail),
    );
  }

  @override
  Future<void> requestPasswordReset({required String email}) async {
    passwordResetRequests.add(email.trim().toLowerCase());
  }

  @override
  Future<HermesCheckoutSession> createCheckoutSession({
    required String plan,
    String billingInterval = 'monthly',
    String source = 'flutter',
  }) async {
    checkoutRequests.add({
      'plan': plan,
      'billingInterval': billingInterval,
      'source': source,
    });
    return HermesCheckoutSession(
      id: 'cs_test_$plan',
      url: 'https://checkout.stripe.com/c/pay/cs_test_$plan',
      plan: plan,
      billingInterval: billingInterval,
      status: 'open',
    );
  }

  @override
  Future<HermesPaymentSheetSetup> createMobileSubscriptionSetup({
    required String plan,
    String billingInterval = 'monthly',
  }) async {
    mobileSubscriptionSetupRequests.add({
      'plan': plan,
      'billingInterval': billingInterval,
    });
    return HermesPaymentSheetSetup(
      publishableKey: 'pk_test_fake',
      customerId: 'cus_test_fake',
      customerEphemeralKeySecret: 'ek_test_fake',
      setupIntentId: 'seti_test_$plan',
      setupIntentClientSecret: 'seti_test_${plan}_secret_fake',
      plan: plan,
      billingInterval: billingInterval,
    );
  }

  @override
  Future<HermesSubscriptionResult> confirmMobileSubscription({
    required String plan,
    String billingInterval = 'monthly',
    required String setupIntentId,
  }) async {
    mobileSubscriptionConfirmRequests.add({
      'plan': plan,
      'billingInterval': billingInterval,
      'setupIntentId': setupIntentId,
    });
    subscriptionTier = plan;
    currentSubscriptionStatus = 'trialing';
    subscriptionCancelAtPeriodEnd = false;
    subscriptionAccessEndsAt = null;
    subscriptionCanResume = false;
    return HermesSubscriptionResult(
      plan: plan,
      billingInterval: billingInterval,
      subscription: _billingSubscriptionSummary,
      paymentMethod: billingPaymentMethod,
    );
  }

  @override
  Future<HermesSubscriptionSummary> getSubscriptionSummary() async =>
      _billingSubscriptionSummary;

  @override
  Future<HermesBillingPaymentMethod?> getBillingPaymentMethod() async =>
      billingPaymentMethod;

  @override
  Future<HermesPaymentSheetSetup> createPaymentMethodSetup() async {
    paymentMethodSetupRequests++;
    return const HermesPaymentSheetSetup(
      publishableKey: 'pk_test_fake',
      customerId: 'cus_test_fake',
      customerEphemeralKeySecret: 'ek_test_payment_update',
      setupIntentId: 'seti_payment_update',
      setupIntentClientSecret: 'seti_payment_update_secret_fake',
    );
  }

  @override
  Future<HermesBillingPaymentMethod?> confirmPaymentMethodSetup({
    required String setupIntentId,
  }) async {
    paymentMethodConfirmRequests.add(setupIntentId);
    billingPaymentMethod = const HermesBillingPaymentMethod(
      brand: 'mastercard',
      last4: '4444',
      expMonth: 10,
      expYear: 2031,
    );
    return billingPaymentMethod;
  }

  @override
  Future<HermesSubscriptionSummary> cancelSubscription() async {
    cancelSubscriptionRequests++;
    currentSubscriptionStatus ??= 'active';
    subscriptionCancelAtPeriodEnd = true;
    subscriptionAccessEndsAt = subscriptionCurrentPeriodEnd;
    subscriptionCanResume = true;
    return _billingSubscriptionSummary;
  }

  @override
  Future<HermesSubscriptionSummary> resumeSubscription() async {
    resumeSubscriptionRequests++;
    currentSubscriptionStatus ??= 'active';
    subscriptionCancelAtPeriodEnd = false;
    subscriptionAccessEndsAt = null;
    subscriptionCanResume = false;
    return _billingSubscriptionSummary;
  }

  @override
  Future<HermesUser> me() async =>
      _user(name: 'Bean User', email: updatedEmail ?? 'bean@example.com');

  @override
  Future<HermesUser> updateMe({
    String? name,
    String? email,
    String? theme,
    String? themeMode,
    String? commandCenterLabel,
    String? preferredMapApp,
    String? agentPersonality,
    List<String>? onboardingPriorities,
    String? onboardingContext,
    String? homeCity,
    HermesNotificationPreferences? notificationPreferences,
  }) async {
    updatedName = name ?? updatedName;
    updatedEmail = email ?? updatedEmail;
    updatedTheme = theme ?? updatedTheme;
    updatedThemeMode = themeMode ?? updatedThemeMode;
    updatedCommandCenterLabel = commandCenterLabel ?? updatedCommandCenterLabel;
    updatedPreferredMapApp = preferredMapApp ?? updatedPreferredMapApp;
    updatedAgentPersonality = agentPersonality ?? updatedAgentPersonality;
    updatedPriorities = onboardingPriorities ?? updatedPriorities;
    updatedContext = onboardingContext ?? updatedContext;
    updatedNotificationPreferences =
        notificationPreferences ?? updatedNotificationPreferences;
    return _user(
      name: updatedName ?? 'Bean User',
      email: updatedEmail ?? 'bean@example.com',
    );
  }

  @override
  Future<HermesSession> startSession({
    String? title,
    String? runtimeMode,
    int? workspaceId,
    Map<String, Object?>? metadata,
  }) async {
    startedSessionCount++;
    return HermesSession(
      id: 42,
      status: 'active',
      workspaceId: workspaceId,
      title: 'Today',
    );
  }

  @override
  Future<HermesSessionList> listConversationSessions({
    String? date,
    String? timezone,
    int? workspaceId,
    int limit = 30,
  }) async => HermesSessionList(
    sessions: [if (todaySession != null) todaySession!],
    todaySession: todaySession,
  );

  @override
  Future<HermesSessionDetails> resumeSessionDetails(int sessionId) async =>
      HermesSessionDetails(
        session: HermesSession(id: sessionId, status: 'active', title: 'Today'),
        messages: todaySessionMessages,
      );

  @override
  Future<void> submitIssueReport({
    required String message,
    int? workspaceId,
    String? pageUrl,
  }) async {
    issueReports.add(message);
  }

  @override
  Future<HermesSession> cancelSession(int sessionId) async {
    cancelledSessionCalls++;
    return HermesSession(id: sessionId, status: 'cancelling', title: 'Today');
  }

  @override
  Future<List<HermesTask>> listTasks() async {
    final today = DateTime.now().toIso8601String();
    return plannedToday
        ? [
            HermesTask(
              id: 10,
              title: 'Generated follow-up task',
              status: 'open',
              dueAt: today,
            ),
          ]
        : [
            HermesTask(
              id: 1,
              title: 'Plan launch',
              status: 'open',
              dueAt: today,
              isCritical: true,
            ),
          ];
  }

  @override
  Future<List<HermesTask>> listPastTasks() async => const [];

  @override
  Future<List<HermesReminder>> listReminders() async {
    final now = DateTime.now();
    if (plannedToday) {
      return [
        HermesReminder(
          id: 20,
          title: 'Stretch and hydrate',
          dueAt: now.add(const Duration(hours: 1)).toIso8601String(),
          category: 'Health',
          color: '#34C759',
        ),
      ];
    }
    final dueAt = showDueReminderBanner
        ? now.subtract(const Duration(hours: 1))
        : now
              .subtract(const Duration(days: 1))
              .copyWith(hour: 9, minute: 0, second: 0, millisecond: 0);
    return [
      HermesReminder(
        id: 2,
        title: 'Stand up',
        isCritical: true,
        dueAt: dueAt.toIso8601String(),
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
    List<Object>? syncToWorkspaceIds,
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
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async => plannedToday
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
      approvals: approvals,
      blockers: const [],
    );
  }

  @override
  Future<HermesApprovalResult> approveApproval(
    int approvalId, {
    bool alwaysApprove = false,
  }) async {
    approvedApprovalId = approvalId;
    alwaysApprovedApproval = alwaysApprove;
    final approval = approvals.firstWhere((item) => item.id == approvalId);
    approvals = approvals
        .where((item) => item.id != approvalId)
        .toList(growable: false);
    return HermesApprovalResult(
      approval: HermesApproval(
        id: approval.id,
        title: approval.title,
        status: 'approved',
        description: approval.description,
        payload: approval.payload,
      ),
    );
  }

  @override
  Future<HermesApprovalResult> denyApproval(int approvalId) async {
    deniedApprovalId = approvalId;
    final approval = approvals.firstWhere((item) => item.id == approvalId);
    approvals = approvals
        .where((item) => item.id != approvalId)
        .toList(growable: false);
    return HermesApprovalResult(
      approval: HermesApproval(
        id: approval.id,
        title: approval.title,
        status: 'denied',
        description: approval.description,
        payload: approval.payload,
      ),
    );
  }

  @override
  Future<List<HermesActivityEvent>> pollActivityEvents(
    int sessionId, {
    int? after,
    int waitSeconds = 0,
    int limit = 100,
  }) async => const [HermesActivityEvent(id: 1, eventType: 'assistant.ready')];

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
  Future<GoogleCalendarSyncStatus> outlookCalendarStatus() async =>
      GoogleCalendarSyncStatus(
        connected: outlookCalendarConnected,
        status: outlookCalendarConnected ? 'connected' : 'not_connected',
        calendarId: defaultOutlookCalendarId,
        defaultCalendarId: defaultOutlookCalendarId,
        selectedCalendarIds: selectedOutlookCalendarIds,
        calendars: outlookCalendarConnected
            ? [
                GoogleCalendarInfo(
                  id: 'outlook-primary',
                  summary: 'Outlook',
                  primary: true,
                  selected: selectedOutlookCalendarIds.contains(
                    'outlook-primary',
                  ),
                  accessRole: 'writer',
                ),
              ]
            : const [],
        lastSyncedAt: outlookCalendarConnected
            ? DateTime.now().toIso8601String()
            : null,
      );

  @override
  Future<GoogleCalendarSyncStatus> updateOutlookCalendarSelection({
    required List<String> selectedCalendarIds,
    String? defaultCalendarId,
  }) async {
    selectedOutlookCalendarIds = selectedCalendarIds;
    defaultOutlookCalendarId = defaultCalendarId ?? defaultOutlookCalendarId;
    outlookCalendarConnected = true;
    return outlookCalendarStatus();
  }

  @override
  Future<String> outlookCalendarAuthUrl() async {
    outlookCalendarConnected = true;
    return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?client_id=fake';
  }

  @override
  Future<GoogleCalendarSyncResult> syncOutlookCalendar() async {
    outlookCalendarSyncCalls++;
    outlookCalendarConnected = true;
    return GoogleCalendarSyncResult(
      imported: 1,
      deleted: 0,
      status: await outlookCalendarStatus(),
    );
  }

  @override
  Future<GoogleCalendarSyncStatus> disconnectOutlookCalendar() async {
    outlookCalendarConnected = false;
    return outlookCalendarStatus();
  }

  @override
  Future<HermesMessageResult> sendMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
  }) async {
    sendMessageCalls++;
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
    } else if (_looksLikeIntroMessage(content)) {
      updatedAgentPersonality = 'coach';
      updatedPriorities = ['Family', 'Reminders', 'Planning'];
      updatedContext = content;
    }
    final isIntroductionMessage = _looksLikeIntroMessage(content);
    if (!isPreferenceOnlyMessage && !isIntroductionMessage) {
      plannedToday = true;
    }
    return HermesMessageResult(
      status: 'completed',
      session: const HermesSession(id: 42, status: 'active', title: 'Today'),
      userMessage: HermesMessage(
        id: 7000 + sendMessageCalls + branchMessageCalls,
        role: 'user',
        content: content,
        metadata: metadata ?? const {},
      ),
      assistantMessage: HermesMessage(
        id: 8,
        role: 'assistant',
        metadata: const {'model': 'gpt-5.4'},
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
  Future<HermesMessageResult> branchMessage({
    required int sessionId,
    required int messageId,
    required String content,
    Map<String, Object?>? metadata,
  }) {
    branchMessageCalls++;
    branchedFromMessageId = messageId;
    return sendMessage(
      sessionId: sessionId,
      content: content,
      metadata: metadata,
    );
  }

  bool _looksLikeIntroMessage(String content) {
    final normalized = content.toLowerCase().replaceAll('’', "'");
    return normalized.contains('i am bean user') ||
        normalized.contains("i'm harley");
  }

  @override
  Future<HermesMessageResult> queueMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
    String source = 'flutter',
  }) {
    queueMessageCalls++;
    return sendMessage(
      sessionId: sessionId,
      content: content,
      metadata: metadata,
    );
  }

  @override
  Future<void> deleteAccount() async {
    deletedAccount = true;
    bearerToken = null;
  }
}

class _SlowChatFakeHermesApiClient extends _SignedInFakeHermesApiClient {
  final Completer<HermesMessageResult> _messageCompleter =
      Completer<HermesMessageResult>();

  @override
  Future<HermesMessageResult> sendMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
  }) {
    sentMessages.add(content);
    sentMessageMetadata.add(metadata);
    return _messageCompleter.future;
  }

  @override
  Future<HermesMessageResult> queueMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
    String source = 'flutter',
  }) {
    return sendMessage(
      sessionId: sessionId,
      content: content,
      metadata: metadata,
    );
  }

  void completeMessage() {
    if (_messageCompleter.isCompleted) return;
    _messageCompleter.complete(
      const HermesMessageResult(
        status: 'completed',
        session: HermesSession(id: 42, status: 'active', title: 'Today'),
        assistantMessage: HermesMessage(
          id: 80,
          role: 'assistant',
          content: 'Late response',
        ),
        events: [],
      ),
    );
  }
}

class _BeanWorkPlanFakeHermesApiClient extends _SignedInFakeHermesApiClient {
  bool _messageSent = false;

  static const _workEvents = [
    HermesActivityEvent(
      id: 2,
      eventType: 'assistant.work_item.planned',
      status: 'planned',
      toolName: 'assistant.work',
      payload: {
        'work_item_id': 'tool-call-event',
        'work_order': 0,
        'action_type': 'calendar_event.create',
        'label': 'Create calendar event: Deep work',
      },
    ),
    HermesActivityEvent(
      id: 3,
      eventType: 'assistant.work_item.planned',
      status: 'planned',
      toolName: 'assistant.work',
      payload: {
        'work_item_id': 'tool-call-reminder',
        'work_order': 1,
        'action_type': 'reminder.create',
        'label': 'Create reminder: Deep work',
      },
    ),
    HermesActivityEvent(
      id: 4,
      eventType: 'assistant.calendar_event.created',
      status: 'succeeded',
      toolName: 'calendar.create',
      payload: {
        'calendar_event_id': 30,
        'title': 'Deep work',
        'work_item_id': 'tool-call-event',
        'work_order': 0,
        'work_label': 'Create calendar event: Deep work',
        'action_type': 'calendar_event.create',
      },
    ),
    HermesActivityEvent(
      id: 5,
      eventType: 'assistant.reminder.created',
      status: 'succeeded',
      toolName: 'reminders.create',
      payload: {
        'reminder_id': 20,
        'title': 'Reminder: Deep work',
        'work_item_id': 'tool-call-reminder',
        'work_order': 1,
        'work_label': 'Create reminder: Deep work',
        'action_type': 'reminder.create',
      },
    ),
  ];

  @override
  Future<List<HermesActivityEvent>> pollActivityEvents(
    int sessionId, {
    int? after,
    int waitSeconds = 0,
    int limit = 100,
  }) async => _messageSent ? _workEvents : const [];

  @override
  Future<HermesMessageResult> queueMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
    String source = 'flutter',
  }) async {
    queueMessageCalls++;
    sentMessages.add(content);
    sentMessageMetadata.add(metadata);
    plannedToday = true;
    _messageSent = true;
    return HermesMessageResult(
      status: 'completed',
      session: const HermesSession(id: 42, status: 'active', title: 'Today'),
      userMessage: HermesMessage(
        id: 7100 + queueMessageCalls,
        role: 'user',
        content: content,
        metadata: metadata ?? const {},
      ),
      assistantMessage: const HermesMessage(
        id: 8100,
        role: 'assistant',
        content: 'Done — I added the deep work block and reminder.',
      ),
      events: _workEvents,
    );
  }
}

class _DashboardRefreshBeanWorkFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  bool _dashboardRefreshRequested = false;
  bool _markedCurrent = false;

  @override
  Future<HermesMessageResult> queueMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
    String source = 'flutter',
  }) async {
    queueMessageCalls++;
    sentMessages.add(content);
    sentMessageMetadata.add(metadata);
    return HermesMessageResult(
      status: 'queued',
      session: const HermesSession(id: 42, status: 'queued', title: 'Today'),
      userMessage: HermesMessage(
        id: 7200 + queueMessageCalls,
        role: 'user',
        content: content,
        metadata: metadata ?? const {},
      ),
      run: const HermesAssistantRun(
        id: 64,
        status: 'running',
        source: 'flutter',
      ),
      events: const [
        HermesActivityEvent(
          id: 2,
          eventType: 'runtime.run_queued',
          status: 'queued',
        ),
      ],
    );
  }

  @override
  Future<HermesAssistantRun> getAssistantRun(int runId) async =>
      _dashboardRefreshRequested
      ? const HermesAssistantRun(
          id: 64,
          status: 'completed',
          source: 'flutter',
          assistantMessage: HermesMessage(
            id: 8800,
            role: 'assistant',
            content: 'Done — I added the deep work block and reminder.',
          ),
        )
      : const HermesAssistantRun(id: 64, status: 'running', source: 'flutter');

  @override
  Future<HermesSessionDetails> resumeSessionDetails(int sessionId) async =>
      HermesSessionDetails(
        session: HermesSession(
          id: sessionId,
          status: _dashboardRefreshRequested ? 'active' : 'queued',
          title: 'Today',
        ),
        messages: [
          if (_dashboardRefreshRequested)
            const HermesMessage(
              id: 8800,
              role: 'assistant',
              content: 'Done — I added the deep work block and reminder.',
            ),
        ],
      );

  @override
  Future<HermesDashboardChangeFeed> dashboardChanges({
    required int after,
    int waitSeconds = 0,
    int limit = 100,
  }) async {
    if (!_markedCurrent) {
      _markedCurrent = true;
      return const HermesDashboardChangeFeed(changes: [], latestId: 1);
    }
    _dashboardRefreshRequested = true;
    return const HermesDashboardChangeFeed(
      latestId: 2,
      changes: [
        HermesDashboardChange(
          id: 2,
          resourceType: 'calendar_event',
          action: 'created',
        ),
      ],
    );
  }

  @override
  Future<List<HermesActivityEvent>> pollActivityEvents(
    int sessionId, {
    int? after,
    int waitSeconds = 0,
    int limit = 100,
  }) async => _dashboardRefreshRequested
      ? _BeanWorkPlanFakeHermesApiClient._workEvents
      : const [];
}

class _BeanMutationRefreshFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  bool _messageSent = false;
  bool _workEventsReturned = false;
  bool taskListIncludedCreatedTask = false;

  static const _workEvents = [
    HermesActivityEvent(
      id: 20,
      eventType: 'assistant.work_item.planned',
      status: 'planned',
      toolName: 'assistant.work',
      payload: {
        'work_item_id': 'task-create-trash',
        'work_order': 0,
        'action_type': 'task.create',
        'label': 'Create task: Take out trash',
      },
    ),
    HermesActivityEvent(
      id: 21,
      eventType: 'assistant.task.created',
      status: 'succeeded',
      toolName: 'tasks.create',
      payload: {
        'task_id': 440,
        'title': 'Take out trash',
        'work_item_id': 'task-create-trash',
        'work_order': 0,
        'work_label': 'Create task: Take out trash',
        'action_type': 'task.create',
      },
    ),
  ];

  @override
  Future<HermesMessageResult> queueMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
    String source = 'flutter',
  }) async {
    queueMessageCalls++;
    sentMessages.add(content);
    sentMessageMetadata.add(metadata);
    _messageSent = true;
    return HermesMessageResult(
      status: 'queued',
      session: const HermesSession(id: 42, status: 'queued', title: 'Today'),
      userMessage: HermesMessage(
        id: 7300 + queueMessageCalls,
        role: 'user',
        content: content,
        metadata: metadata ?? const {},
      ),
      run: const HermesAssistantRun(
        id: 92,
        status: 'running',
        source: 'flutter',
      ),
      events: const [
        HermesActivityEvent(
          id: 19,
          eventType: 'runtime.run_queued',
          status: 'queued',
        ),
      ],
    );
  }

  @override
  Future<List<HermesActivityEvent>> pollActivityEvents(
    int sessionId, {
    int? after,
    int waitSeconds = 0,
    int limit = 100,
  }) async {
    if (!_messageSent) return const [];
    _workEventsReturned = true;
    return _workEvents;
  }

  @override
  Future<List<HermesTask>> listTasks() async {
    if (!_workEventsReturned) return const [];
    taskListIncludedCreatedTask = true;
    return [
      HermesTask(
        id: 440,
        title: 'Take out trash',
        status: 'open',
        dueAt: DateTime.now().toIso8601String(),
      ),
    ];
  }

  @override
  Future<HermesTodaySummary> todaySummary({int? workspaceId}) async {
    todaySummaryCalls++;
    return HermesTodaySummary(
      tasks: await listTasks(),
      reminders: const [],
      calendarEvents: const [],
      activityEvents: await pollActivityEvents(42),
      approvals: const [],
      blockers: const [],
    );
  }

  @override
  Future<HermesAssistantRun> getAssistantRun(int runId) async =>
      taskListIncludedCreatedTask
      ? const HermesAssistantRun(
          id: 92,
          status: 'completed',
          source: 'flutter',
          assistantMessage: HermesMessage(
            id: 8900,
            role: 'assistant',
            content: 'Done — I added the take out trash task.',
          ),
        )
      : const HermesAssistantRun(id: 92, status: 'running', source: 'flutter');
}

class _PartialBeanWorkEventsFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  bool _messageSent = false;
  int _activityPollsAfterMessage = 0;
  int _runPolls = 0;

  static const _calendarPlanEvent = HermesActivityEvent(
    id: 30,
    eventType: 'assistant.work_item.planned',
    status: 'planned',
    toolName: 'assistant.work',
    payload: {
      'work_item_id': 'calendar-miata',
      'work_order': 0,
      'action_type': 'calendar_event.create',
      'label': 'Create calendar event: Miata engine swap',
    },
  );

  static const _allEvents = [
    _calendarPlanEvent,
    HermesActivityEvent(
      id: 31,
      eventType: 'assistant.work_item.planned',
      status: 'planned',
      toolName: 'assistant.work',
      payload: {
        'work_item_id': 'reminder-miata',
        'work_order': 1,
        'action_type': 'reminder.create',
        'label': 'Create reminder: Miata engine swap',
      },
    ),
    HermesActivityEvent(
      id: 32,
      eventType: 'assistant.calendar_event.created',
      status: 'succeeded',
      toolName: 'calendar.create',
      payload: {
        'calendar_event_id': 61,
        'title': 'Miata engine swap',
        'work_item_id': 'calendar-miata',
        'work_order': 0,
        'work_label': 'Create calendar event: Miata engine swap',
        'action_type': 'calendar_event.create',
      },
    ),
    HermesActivityEvent(
      id: 33,
      eventType: 'assistant.reminder.created',
      status: 'succeeded',
      toolName: 'reminders.create',
      payload: {
        'reminder_id': 62,
        'title': 'Miata engine swap',
        'work_item_id': 'reminder-miata',
        'work_order': 1,
        'work_label': 'Create reminder: Miata engine swap',
        'action_type': 'reminder.create',
      },
    ),
  ];

  @override
  Future<HermesMessageResult> queueMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
    String source = 'flutter',
  }) async {
    queueMessageCalls++;
    sentMessages.add(content);
    sentMessageMetadata.add(metadata);
    _messageSent = true;
    return HermesMessageResult(
      status: 'queued',
      session: const HermesSession(id: 42, status: 'queued', title: 'Today'),
      userMessage: HermesMessage(
        id: 7400 + queueMessageCalls,
        role: 'user',
        content: content,
        metadata: metadata ?? const {},
      ),
      run: const HermesAssistantRun(
        id: 94,
        status: 'running',
        source: 'flutter',
      ),
      events: const [
        HermesActivityEvent(
          id: 29,
          eventType: 'runtime.run_queued',
          status: 'queued',
        ),
      ],
    );
  }

  @override
  Future<List<HermesActivityEvent>> pollActivityEvents(
    int sessionId, {
    int? after,
    int waitSeconds = 0,
    int limit = 100,
  }) async {
    if (!_messageSent) return const [];
    _activityPollsAfterMessage++;
    if (_activityPollsAfterMessage == 1) {
      return const [_calendarPlanEvent];
    }
    if (_activityPollsAfterMessage < 4) {
      return const [];
    }
    return _allEvents;
  }

  @override
  Future<HermesAssistantRun> getAssistantRun(int runId) async {
    _runPolls++;
    return _runPolls >= 4
        ? const HermesAssistantRun(
            id: 94,
            status: 'completed',
            source: 'flutter',
            assistantMessage: HermesMessage(
              id: 8940,
              role: 'assistant',
              content:
                  'Done — I added the Miata engine swap block and reminder.',
            ),
          )
        : const HermesAssistantRun(
            id: 94,
            status: 'running',
            source: 'flutter',
          );
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

class _CalendarTaskScopeFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesTask>> listTasks() async {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day, 10);
    final tomorrow = today.add(const Duration(days: 1, hours: 4));
    final tenDaysAway = today.add(const Duration(days: 10));
    final moreThanThirtyDaysAway = today.add(const Duration(days: 45));
    return [
      HermesTask(
        id: 251,
        title: 'Overdue task',
        status: 'open',
        dueAt: today.subtract(const Duration(days: 1)).toIso8601String(),
      ),
      HermesTask(
        id: 252,
        title: 'Today task',
        status: 'open',
        dueAt: today.toIso8601String(),
      ),
      HermesTask(
        id: 253,
        title: 'Tomorrow task',
        status: 'open',
        dueAt: tomorrow.toIso8601String(),
      ),
      const HermesTask(id: 254, title: 'Unscheduled task', status: 'open'),
      HermesTask(
        id: 255,
        title: 'Future recurring task',
        status: 'open',
        dueAt: tomorrow.toIso8601String(),
        metadata: const {'recurrence': 'daily'},
      ),
      HermesTask(
        id: 256,
        title: 'Ten day task',
        status: 'open',
        dueAt: tenDaysAway.toIso8601String(),
      ),
      HermesTask(
        id: 257,
        title: 'Next month task',
        status: 'open',
        dueAt: moreThanThirtyDaysAway.toIso8601String(),
      ),
    ];
  }
}

class _EditableReminderFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  HermesReminder? updatedReminder;
  int? deletedReminderId;
  Completer<void>? updateReminderCompleter;
  Completer<void>? deleteReminderCompleter;
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
    List<Object>? syncToWorkspaceIds,
    bool clearCategory = false,
    bool clearColor = false,
  }) async {
    await updateReminderCompleter?.future;
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

  @override
  Future<void> deleteReminder(
    int reminderId, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    await deleteReminderCompleter?.future;
    deletedReminderId = reminderId;
    _reminders = _reminders
        .where((item) => item.id != reminderId)
        .toList(growable: false);
  }
}

class _TaskReminderCategoryFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  _TaskReminderCategoryFakeHermesApiClient({this.includeWorkspaces = false});

  static const personalWorkspace = HermesWorkspace(
    id: '1',
    name: 'Personal',
    type: 'personal',
    active: true,
    isDefault: true,
  );
  static const householdWorkspace = HermesWorkspace(
    id: '2',
    name: 'Household',
    type: 'household',
  );

  HermesTask? updatedTask;
  HermesTask? createdTask;
  HermesReminder? updatedReminder;
  HermesReminder? createdReminder;
  HermesEventCategory? savedCategory;
  int? createdTaskWorkspaceId;
  int? createdReminderWorkspaceId;
  int? deletedTaskId;
  int? deletedReminderId;
  List<Object> createdTaskSyncWorkspaceIds = const [];
  final bool includeWorkspaces;
  Completer<void>? createTaskCompleter;
  Completer<void>? updateTaskCompleter;
  Completer<void>? deleteTaskCompleter;
  Completer<void>? createReminderCompleter;
  Completer<void>? updateReminderCompleter;
  Completer<void>? deleteReminderCompleter;

  @override
  Future<HermesUser> me() async {
    final user = await super.me();
    if (!includeWorkspaces) return user;
    return user.copyWith(
      defaultWorkspaceId: 1,
      personalWorkspace: personalWorkspace,
      activeWorkspace: personalWorkspace,
      workspaces: const [personalWorkspace, householdWorkspace],
    );
  }

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
    if (deletedTaskId != 501)
      updatedTask ??
          HermesTask(
            id: 501,
            title: 'Categorize proposal',
            status: 'open',
            dueAt: DateTime.now()
                .add(const Duration(hours: 3))
                .toIso8601String(),
          ),
  ];

  @override
  Future<List<HermesReminder>> listReminders() async => [
    if (createdReminder != null) createdReminder!,
    if (deletedReminderId != 601)
      updatedReminder ??
          HermesReminder(
            id: 601,
            title: 'Categorize reminder',
            status: 'pending',
            category: 'Work',
            color: '#007AFF',
            dueAt: DateTime.now()
                .add(const Duration(hours: 4))
                .toIso8601String(),
          ),
  ];

  @override
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
    await createTaskCompleter?.future;
    createdTaskWorkspaceId = workspaceId;
    createdTaskSyncWorkspaceIds = syncToWorkspaceIds;
    createdTask = HermesTask(
      id: 801,
      title: title,
      status: status,
      dueAt: dueAt,
      notes: notes,
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
    await createReminderCompleter?.future;
    createdReminderWorkspaceId = workspaceId;
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
    await updateTaskCompleter?.future;
    final existing = (await listTasks()).firstWhere(
      (item) => item.id == taskId,
    );
    updatedTask = HermesTask(
      id: existing.id,
      title: title ?? existing.title,
      status: status ?? existing.status,
      dueAt: dueAt ?? existing.dueAt,
      notes: clearNotes ? null : notes ?? existing.notes,
      category: clearCategory ? null : category ?? existing.category,
      color: clearColor ? null : color ?? existing.color,
      isCritical: isCritical ?? existing.isCritical,
      completedAt: completedAt ?? existing.completedAt,
      metadata: metadata ?? existing.metadata,
    );
    return updatedTask!;
  }

  @override
  Future<void> deleteTask(
    int taskId, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    await deleteTaskCompleter?.future;
    deletedTaskId = taskId;
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
    List<Object>? syncToWorkspaceIds,
    bool clearCategory = false,
    bool clearColor = false,
  }) async {
    await updateReminderCompleter?.future;
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

  @override
  Future<void> deleteReminder(
    int reminderId, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    await deleteReminderCompleter?.future;
    deletedReminderId = reminderId;
  }
}

class _TwoDayCalendarFakeHermesApiClient extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
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

class _SummaryOnlyCalendarFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async => const [];

  @override
  Future<HermesTodaySummary> todaySummary({int? workspaceId}) async {
    final today = DateTime.now();
    return HermesTodaySummary(
      tasks: const [],
      reminders: const [],
      calendarEvents: [
        HermesCalendarEvent(
          id: 8101,
          title: 'Summary workout',
          startsAt: DateTime(
            today.year,
            today.month,
            today.day,
            5,
          ).toIso8601String(),
          endsAt: DateTime(
            today.year,
            today.month,
            today.day,
            6,
          ).toIso8601String(),
        ),
      ],
      activityEvents: const [],
      approvals: const [],
      blockers: const [],
    );
  }
}

class _MaterializedRecurringCalendarFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
    final today = DateTime.now();
    return [
      HermesCalendarEvent(
        id: 8199,
        title: 'Workout',
        startsAt: DateTime(
          today.year,
          today.month,
          today.day,
          5,
        ).toIso8601String(),
        endsAt: DateTime(
          today.year,
          today.month,
          today.day,
          6,
        ).toIso8601String(),
        recurrence: 'daily',
      ),
      for (var offset = 1; offset < 5; offset++)
        HermesCalendarEvent(
          id: 8200 + offset,
          title: 'Workout',
          startsAt: DateTime(
            today.year,
            today.month,
            today.day + offset,
            5,
          ).toIso8601String(),
          endsAt: DateTime(
            today.year,
            today.month,
            today.day + offset,
            6,
          ).toIso8601String(),
          recurrence: 'daily',
          metadata: {
            'recurrence': 'daily',
            'recurrence_generated': true,
            'recurrence_parent_event_id': 8199,
            'recurrence_occurrence_date': DateTime(
              today.year,
              today.month,
              today.day + offset,
            ).toIso8601String().substring(0, 10),
          },
        ),
    ];
  }
}

class _UtcMidnightAllDayCalendarFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
    final today = DateTime.now();
    final startUtc = DateTime.utc(today.year, today.month, today.day);
    return [
      HermesCalendarEvent(
        id: 905,
        title: 'Moving out of shop',
        startsAt: startUtc.toIso8601String(),
        endsAt: startUtc.add(const Duration(days: 1)).toIso8601String(),
        metadata: const {'all_day': true},
      ),
    ];
  }
}

class _OverlappingCalendarFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
    final selectedDay = DateTime.now();
    return [
      HermesCalendarEvent(
        id: 1061,
        title: 'Overlap one',
        startsAt: DateTime(
          selectedDay.year,
          selectedDay.month,
          selectedDay.day,
          10,
        ).toIso8601String(),
        endsAt: DateTime(
          selectedDay.year,
          selectedDay.month,
          selectedDay.day,
          11,
        ).toIso8601String(),
      ),
      HermesCalendarEvent(
        id: 1062,
        title: 'Overlap two',
        startsAt: DateTime(
          selectedDay.year,
          selectedDay.month,
          selectedDay.day,
          10,
          30,
        ).toIso8601String(),
        endsAt: DateTime(
          selectedDay.year,
          selectedDay.month,
          selectedDay.day,
          11,
          30,
        ).toIso8601String(),
      ),
      HermesCalendarEvent(
        id: 1063,
        title: 'Overlap three',
        startsAt: DateTime(
          selectedDay.year,
          selectedDay.month,
          selectedDay.day,
          11,
        ).toIso8601String(),
        endsAt: DateTime(
          selectedDay.year,
          selectedDay.month,
          selectedDay.day,
          12,
        ).toIso8601String(),
      ),
    ];
  }
}

class _BackToBackCalendarFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
    final selectedDay = DateTime.now();
    return [
      HermesCalendarEvent(
        id: 1064,
        title: 'Morning check-in',
        startsAt: DateTime(
          selectedDay.year,
          selectedDay.month,
          selectedDay.day,
          10,
        ).toIso8601String(),
      ),
      HermesCalendarEvent(
        id: 1065,
        title: 'Morning follow-up',
        startsAt: DateTime(
          selectedDay.year,
          selectedDay.month,
          selectedDay.day,
          10,
          30,
        ).toIso8601String(),
      ),
    ];
  }
}

class _EarlyCalendarFakeHermesApiClient extends _SignedInFakeHermesApiClient {
  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
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
  int? deletedTaskId;
  int pastTaskListCalls = 0;
  Completer<void>? completeTaskCompleter;
  Completer<void>? reopenTaskCompleter;
  Completer<void>? deleteTaskCompleter;
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
    await completeTaskCompleter?.future;
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
    await reopenTaskCompleter?.future;
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

  @override
  Future<void> deleteTask(
    int taskId, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    await deleteTaskCompleter?.future;
    deletedTaskId = taskId;
    _activeTasks = _activeTasks
        .where((task) => task.id != taskId)
        .toList(growable: false);
    if (taskId == 201) {
      _pastTaskReopened = true;
    }
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

class _OptionalSetupDeclineFakeHermesApiClient
    extends _SignedInFakeHermesApiClient {
  _OptionalSetupDeclineFakeHermesApiClient() {
    todaySession = const HermesSession(id: 42, status: 'active', title: 'Bean');
    todaySessionMessages = const [
      HermesMessage(
        id: 70,
        role: 'assistant',
        content:
            'All set — I’ll use the Detail organizer personality. Want me to help set up a few reminders or import existing lists now?',
      ),
    ];
  }

  @override
  Future<HermesMessageResult> sendMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
  }) async {
    sendMessageCalls++;
    sentMessages.add(content);
    sentMessageMetadata.add(metadata);
    return const HermesMessageResult(
      status: 'completed',
      session: HermesSession(id: 42, status: 'active', title: 'Bean'),
      assistantMessage: HermesMessage(
        id: 71,
        role: 'assistant',
        content: 'No problem — we can skip that.',
      ),
      events: [],
    );
  }

  @override
  Future<HermesMessageResult> queueMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
    String source = 'flutter',
  }) {
    queueMessageCalls++;
    throw StateError('declines should not be queued');
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
  _EditableCalendarFakeHermesApiClient({HermesCalendarEvent? initialEvent})
    : _initialEvent = initialEvent {
    googleCalendarConnected = true;
  }

  final HermesCalendarEvent? _initialEvent;
  HermesCalendarEvent? updatedEvent;
  HermesCalendarEvent? createdEvent;
  List<HermesWorkspace>? workspaceOverrides;
  int? createdEventWorkspaceId;
  List<Object> createdEventSyncWorkspaceIds = const [];
  Map<String, Object?>? createdReminder;
  HermesEventCategory? savedCategory;
  Completer<void>? createEventCompleter;
  Completer<void>? updateEventCompleter;
  Completer<void>? deleteEventCompleter;
  int? deletedCategoryId;
  int? deletedEventId;
  List<Object> deletedEventWorkspaceIds = const [];
  String? deletedRecurringMode;
  String? deletedRecurringOccurrenceDate;

  @override
  Future<HermesUser> me() async {
    final user = await super.me();
    final workspaces = workspaceOverrides;
    if (workspaces == null) return user;
    HermesWorkspace? activeWorkspace;
    HermesWorkspace? personalWorkspace;
    for (final workspace in workspaces) {
      if (workspace.active) activeWorkspace ??= workspace;
      if (workspace.isPersonal) personalWorkspace ??= workspace;
    }
    return user.copyWith(
      defaultWorkspaceId: activeWorkspace?.numericId ?? user.defaultWorkspaceId,
      personalWorkspace: personalWorkspace,
      activeWorkspace: activeWorkspace,
      workspaces: workspaces,
    );
  }

  @override
  Future<List<HermesWorkspace>> listWorkspaces() async =>
      workspaceOverrides ?? const [];

  @override
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
    String? recurrence,
    bool? isCritical,
    Map<String, Object?>? metadata,
    List<Object>? syncToWorkspaceIds,
    bool clearNotes = false,
    bool clearLocation = false,
  }) async {
    await updateEventCompleter?.future;
    updatedEvent = HermesCalendarEvent(
      id: eventId,
      title: title,
      startsAt: startsAt,
      endsAt: endsAt,
      notes: clearNotes ? null : notes,
      location: clearLocation ? null : location,
      status: status,
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
    await createEventCompleter?.future;
    createdEventWorkspaceId = workspaceId;
    createdEventSyncWorkspaceIds = syncToWorkspaceIds;
    createdEvent = HermesCalendarEvent(
      id: 44,
      title: title,
      startsAt: startsAt,
      endsAt: endsAt,
      notes: notes,
      location: location,
      status: status,
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
  Future<void> deleteEventCategory(
    int categoryId, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    deletedCategoryId = categoryId;
  }

  @override
  Future<void> deleteCalendarEvent(
    int eventId, {
    List<Object> deleteFromWorkspaceIds = const [],
    String? recurringDeleteMode,
    String? recurringOccurrenceDate,
  }) async {
    await deleteEventCompleter?.future;
    deletedEventId = eventId;
    deletedEventWorkspaceIds = deleteFromWorkspaceIds;
    deletedRecurringMode = recurringDeleteMode;
    deletedRecurringOccurrenceDate = recurringOccurrenceDate;
  }

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async => [
    if (createdEvent != null) createdEvent!,
    if (deletedEventId != 3)
      updatedEvent ??
          _initialEvent ??
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

class _StaleCalendarRefreshAfterEditFakeHermesApiClient
    extends _EditableCalendarFakeHermesApiClient {
  _StaleCalendarRefreshAfterEditFakeHermesApiClient(this.staleEvent)
    : super(initialEvent: staleEvent);

  final HermesCalendarEvent staleEvent;

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async => [staleEvent];
}

class _LinkedWorkspaceEditableCalendarFakeHermesApiClient
    extends _EditableCalendarFakeHermesApiClient {
  static const _personal = HermesWorkspace(
    id: '1',
    name: 'Personal',
    type: 'personal',
    role: 'owner',
    active: true,
    isDefault: true,
  );
  static const _family = HermesWorkspace(
    id: '2',
    name: 'Daveys',
    type: 'household',
    role: 'owner',
  );

  @override
  Future<HermesUser> me() async => const HermesUser(
    id: 1,
    name: 'Bean User',
    email: 'bean@example.com',
    subscriptionStatus: 'active',
    onboardComplete: true,
    defaultWorkspaceId: 1,
    personalWorkspace: _personal,
    activeWorkspace: _personal,
    workspaces: [_personal, _family],
    agentProfile: HermesAgentProfile(
      settings: {
        'personality_type': 'balanced',
        'onboarding': {'completed': true, 'priorities': <String>[]},
      },
    ),
  );

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async => [
    if (!deletedEventWorkspaceIds.map((id) => id.toString()).contains('1'))
      HermesCalendarEvent(
        id: 3,
        workspaceId: 1,
        linkedWorkspaceIds: [1, 2],
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
        ).toIso8601String(),
      ),
  ];
}

class _SharedWorkspaceCategoryFakeHermesApiClient
    extends _EditableCalendarFakeHermesApiClient {
  static const _personal = HermesWorkspace(
    id: '1',
    name: 'Personal',
    type: 'personal',
    role: 'owner',
    isDefault: true,
  );
  static const _family = HermesWorkspace(
    id: '2',
    name: 'Daveys',
    type: 'household',
    role: 'owner',
    active: true,
  );

  @override
  Future<HermesUser> me() async => const HermesUser(
    id: 1,
    name: 'Bean User',
    email: 'bean@example.com',
    subscriptionStatus: 'active',
    onboardComplete: true,
    defaultWorkspaceId: 2,
    personalWorkspace: _personal,
    activeWorkspace: _family,
    workspaces: [_personal, _family],
    agentProfile: HermesAgentProfile(
      settings: {
        'personality_type': 'balanced',
        'onboarding': {'completed': true, 'priorities': <String>[]},
      },
    ),
  );

  @override
  Future<List<HermesEventCategory>> listEventCategories() async => const [
    HermesEventCategory(id: 30, name: 'Family', color: '#AF52DE'),
  ];

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async => [if (createdEvent != null) createdEvent!];
}

class _CustomColorEditableCalendarFakeHermesApiClient
    extends _EditableCalendarFakeHermesApiClient {
  @override
  Future<List<HermesEventCategory>> listEventCategories() async => const [
    HermesEventCategory(id: 20, name: 'Studio', color: '#123ABC'),
  ];

  @override
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async => [
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
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async => plannedToday
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
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
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
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
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
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
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
  Future<List<HermesCalendarEvent>> listCalendarEvents({
    bool skipExternalSync = false,
  }) async {
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
            '{"id":75}\n'
            '{"tool":"calendar_events.create","ok":true}\n'
            'Added Workout to this week on Monday, Wednesday, and Friday from 9:00 AM to 10:00 AM. Should I make it repeat every week?',
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

String? _activeSelectedDayHeading(WidgetTester tester) {
  final headingText = find.descendant(
    of: find.byKey(const Key('day-column-heading-selected')),
    matching: find.byType(Text),
  );
  return tester.widget<Text>(headingText).data;
}

Future<void> _dragCalendarDayPage(WidgetTester tester, Offset offset) async {
  final pageViewRect = tester.getRect(
    find.byKey(const PageStorageKey<String>('apple-style-day-page-view')),
  );
  final timelineScrollRect = tester.getRect(
    find.byKey(const Key('apple-style-day-timeline-scroll')),
  );
  final minY = math.max(pageViewRect.top + 24, timelineScrollRect.top + 24);
  final maxY = math.min(
    pageViewRect.bottom - 24,
    timelineScrollRect.bottom - 24,
  );
  final startY = math.min(math.max(timelineScrollRect.top + 96, minY), maxY);
  await tester.dragFrom(
    Offset(pageViewRect.left + (pageViewRect.width * .75), startY),
    offset,
  );
}

Future<void> _openTodayView(WidgetTester tester) async {
  await tester.tap(find.byKey(const Key('calendar-today-button')));
  await tester.pumpAndSettle();
}

Future<void> _moveCalendarEventBelowHeader(
  WidgetTester tester,
  Finder eventFinder,
) async {
  final eventCenter = tester.getCenter(eventFinder);
  final headerBottom = tester
      .getRect(find.byKey(const Key('calendar-sticky-day-header')))
      .bottom;
  if (eventCenter.dy > headerBottom + 24) return;

  final timelineScrollRect = tester.getRect(
    find.byKey(const Key('apple-style-day-timeline-scroll')),
  );
  final dragDistance = (headerBottom + 48 - eventCenter.dy)
      .clamp(40.0, 160.0)
      .toDouble();
  await tester.dragFrom(timelineScrollRect.center, Offset(0, dragDistance));
  await tester.pumpAndSettle();
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
      '${_testShortWeekdayNames[now.weekday - 1]} ${_testOrdinalDay(now.day)}';
  return find.descendant(
    of: find.byKey(const Key('calendar-today-button')),
    matching: find.text(label),
  );
}

String _testOrdinalDay(int day) {
  final teen = day % 100;
  if (teen >= 11 && teen <= 13) return '${day}th';
  return '$day${switch (day % 10) {
    1 => 'st',
    2 => 'nd',
    3 => 'rd',
    _ => 'th',
  }}';
}

Finder _topHeaderDayMonthTextFinder() {
  final now = DateTime.now();
  final oldLabel =
      '${_testCompactWeekdayNames[now.weekday - 1]} ${_testShortMonthNames[now.month - 1]} ${now.day}';
  return find.descendant(
    of: find.byKey(const Key('calendar-today-button')),
    matching: find.text(oldLabel),
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

Future<void> _openCreateMenuAndChoose(
  WidgetTester tester,
  Key actionKey,
) async {
  await tester.tap(find.byKey(const Key('create-item-menu')));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(actionKey));
  await tester.pumpAndSettle();
}

Future<void> _selectDropdownText(
  WidgetTester tester, {
  required Key dropdownKey,
  required String text,
}) async {
  await tester.ensureVisible(find.byKey(dropdownKey));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(dropdownKey));
  await tester.pumpAndSettle();
  await tester.tap(find.text(text).last);
  await tester.pumpAndSettle();
}

const _testCompactWeekdayNames = [
  'Mon',
  'Tue',
  'Wed',
  'Thu',
  'Fri',
  'Sat',
  'Sun',
];

class _FakeBeanRealtimeConversation extends BeanRealtimeConversation {
  _FakeBeanRealtimeConversation(HermesApiClient apiClient)
    : super(apiClient: apiClient);

  bool started = false;
  bool stopped = false;
  bool microphoneEnabled = false;
  bool captureStarted = false;
  bool captureEnded = false;
  final startMicrophoneValues = <bool>[];
  final sentTexts = <String>[];

  @override
  Future<HermesSession> start({
    int? sessionId,
    int? workspaceId,
    Map<String, Object?> metadata = const {},
    bool microphoneEnabled = true,
  }) async {
    started = true;
    startMicrophoneValues.add(microphoneEnabled);
    this.microphoneEnabled = microphoneEnabled;
    return const HermesSession(id: 42, status: 'active', title: 'Realtime');
  }

  @override
  Future<void> sendText(String text, {bool audioResponse = false}) async {
    sentTexts.add(text);
  }

  @override
  void setMicrophoneEnabled(bool enabled) {
    microphoneEnabled = enabled;
  }

  @override
  void beginVoiceCapture() {
    captureStarted = true;
    microphoneEnabled = true;
  }

  void emitTranscript(String role, String text) {
    onTranscript?.call(role, text);
  }

  @override
  void endVoiceCapture() {
    captureEnded = true;
    microphoneEnabled = false;
  }

  @override
  Future<void> endVoiceCaptureForTranscriptionOnly() async {
    captureEnded = true;
    microphoneEnabled = false;
  }

  @override
  Future<void> stop() async {
    stopped = true;
    microphoneEnabled = false;
  }
}

class _FailingBeanRealtimeConversation extends _FakeBeanRealtimeConversation {
  _FailingBeanRealtimeConversation(super.apiClient);

  @override
  Future<HermesSession> start({
    int? sessionId,
    int? workspaceId,
    Map<String, Object?> metadata = const {},
    bool microphoneEnabled = true,
  }) async {
    started = true;
    startMicrophoneValues.add(microphoneEnabled);
    throw StateError('Realtime unavailable');
  }
}

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

class _TransientQueueFailureHermesApiClient
    extends _SignedInFakeHermesApiClient {
  final queuedMetadata = <Map<String, Object?>?>[];

  @override
  Future<HermesMessageResult> queueMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
    String source = 'flutter',
  }) async {
    queueMessageCalls++;
    sentMessages.add(content);
    queuedMetadata.add(metadata);
    if (queueMessageCalls == 1) {
      throw const HermesApiException(502, '{"message":"Bad gateway"}');
    }

    return const HermesMessageResult(
      status: 'completed',
      session: HermesSession(id: 42, status: 'active', title: 'Today'),
      userMessage: HermesMessage(
        id: 9100,
        role: 'user',
        content: 'Book a plumber for tomorrow',
      ),
      assistantMessage: HermesMessage(
        id: 9101,
        role: 'assistant',
        content: 'Done - I queued that request.',
      ),
      events: [],
    );
  }
}

class _LostQueueResponseHermesApiClient extends _SignedInFakeHermesApiClient {
  final queuedMetadata = <Map<String, Object?>?>[];
  int lookupQueuedMessageCalls = 0;
  String? lookupClientRequestId;

  @override
  Future<HermesMessageResult> queueMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
    String source = 'flutter',
  }) async {
    queueMessageCalls++;
    sentMessages.add(content);
    queuedMetadata.add(metadata);
    throw const HermesApiException(502, '{"message":"Bad gateway"}');
  }

  @override
  Future<HermesMessageResult> lookupQueuedMessage({
    required int sessionId,
    required String clientRequestId,
  }) async {
    lookupQueuedMessageCalls++;
    lookupClientRequestId = clientRequestId;
    return const HermesMessageResult(
      status: 'completed',
      session: HermesSession(id: 42, status: 'active', title: 'Today'),
      userMessage: HermesMessage(
        id: 9150,
        role: 'user',
        content: 'Book a plumber for tomorrow',
      ),
      assistantMessage: HermesMessage(
        id: 9151,
        role: 'assistant',
        content: 'Done - I found that queued request.',
      ),
      events: [],
    );
  }
}

class _TransientDirectSendFailureHermesApiClient
    extends _SignedInFakeHermesApiClient {
  final queuedMetadata = <Map<String, Object?>?>[];

  @override
  Future<HermesMessageResult> sendMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
  }) async {
    sendMessageCalls++;
    sentMessages.add(content);
    sentMessageMetadata.add(metadata);
    throw const HermesApiException(502, '{"message":"Bad gateway"}');
  }

  @override
  Future<HermesMessageResult> queueMessage({
    required int sessionId,
    required String content,
    Map<String, Object?>? metadata,
    String source = 'flutter',
  }) async {
    queueMessageCalls++;
    queuedMetadata.add(metadata);
    return const HermesMessageResult(
      status: 'completed',
      session: HermesSession(id: 42, status: 'active', title: 'Today'),
      userMessage: HermesMessage(
        id: 9200,
        role: 'user',
        content: 'Can you create calendar events?',
      ),
      assistantMessage: HermesMessage(
        id: 9201,
        role: 'assistant',
        content: 'Done - I queued that request.',
      ),
      events: [],
    );
  }
}
