import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:hermes_bean_app/main.dart';

void main() {
  testWidgets(
    'Hermes Bean command center renders chat, progress, surfaces, and approvals',
    (WidgetTester tester) async {
      await tester.pumpWidget(const HermesBeanApp());

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
    await tester.pumpWidget(const HermesBeanApp());

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
