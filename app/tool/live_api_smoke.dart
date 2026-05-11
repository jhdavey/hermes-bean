// ignore_for_file: avoid_print

import 'dart:math';

import 'package:hermes_bean_app/hermes_api_client.dart';

Future<void> main() async {
  final suffix = '${DateTime.now().millisecondsSinceEpoch}-${Random().nextInt(999999)}';
  final email = 'flutter-smoke+$suffix@example.com';
  const password = 'SmokeTestPassword123!';
  final client = HermesApiClient(baseUrl: Uri.parse(hermesApiBaseUrl));

  print('baseUrl=${client.baseUrl}');
  try {
    final auth = await client.register(
      name: 'Flutter Smoke',
      email: email,
      password: password,
    );
    print('registered=${auth.user.email == email}');

    final me = await client.me();
    print('me=${me.email == email}');

    final today = await client.todaySummary();
    print('today_tasks=${today.tasks.length} approvals=${today.approvals.length} calendar=${today.calendarEvents.length}');

    final session = await client.startSession(title: 'Flutter live smoke');
    final result = await client.sendMessage(
      sessionId: session.id,
      content: 'Please add workout to my calendar from 6pm-7pm',
    );
    final assistantContent = result.assistantMessage?.content ?? '';
    print('assistant=$assistantContent');
    print('events=${result.events.map((event) => event.eventType).join(',')}');

    final events = await client.pollActivityEvents(session.id);
    print('polled_events=${events.length}');

    final refreshed = await client.todaySummary();
    final hasWorkout = refreshed.calendarEvents.any(
      (event) => event.title.toLowerCase() == 'workout',
    );
    print('has_workout=$hasWorkout');

    if (!hasWorkout || !assistantContent.contains('workout')) {
      throw StateError('Flutter API smoke did not observe the created workout event.');
    }
  } finally {
    try {
      await client.deleteAccount();
      print('deleted=true');
    } catch (error) {
      print('deleted=false error=$error');
    }
  }
}
