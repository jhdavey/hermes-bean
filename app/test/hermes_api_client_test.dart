import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:hermes_bean_app/hermes_api_client.dart';

void main() {
  test(
    'registers, logs in, sends bearer token, exports, and deletes account',
    () async {
      final requests = <HermesApiRequest>[];
      final client = HermesApiClient(
        baseUrl: Uri.parse('http://local.test/api'),
        transport: (request) async {
          requests.add(request);

          if (request.method == 'POST' && request.path == '/auth/register') {
            expect(request.body, {
              'name': 'Bean User',
              'email': 'bean@example.com',
              'password': 'secret123',
            });
            return HermesApiResponse(
              201,
              jsonEncode({
                'data': {
                  'token': 'register-token',
                  'user': {
                    'id': 9,
                    'name': 'Bean User',
                    'email': 'bean@example.com',
                  },
                },
              }),
            );
          }

          if (request.method == 'GET' && request.path == '/auth/me') {
            expect(request.headers['Authorization'], 'Bearer register-token');
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {
                  'id': 9,
                  'name': 'Bean User',
                  'email': 'bean@example.com',
                },
              }),
            );
          }

          if (request.method == 'GET' && request.path == '/account/export') {
            expect(request.headers['Authorization'], 'Bearer register-token');
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {
                  'user': {'email': 'bean@example.com'},
                  'tasks': [],
                },
              }),
            );
          }

          if (request.method == 'DELETE' && request.path == '/account') {
            expect(request.headers['Authorization'], 'Bearer register-token');
            return const HermesApiResponse(204, '');
          }

          fail('Unexpected request: ${request.method} ${request.path}');
        },
      );

      final auth = await client.register(
        name: 'Bean User',
        email: 'bean@example.com',
        password: 'secret123',
      );
      expect(auth.token, 'register-token');
      expect(client.bearerToken, 'register-token');

      final me = await client.me();
      expect(me.email, 'bean@example.com');

      final export = await client.exportAccount();
      expect(export['tasks'], isEmpty);

      await client.deleteAccount();
      expect(client.bearerToken, isNull);
      expect(requests.map((r) => '${r.method} ${r.path}'), [
        'POST /auth/register',
        'GET /auth/me',
        'GET /account/export',
        'DELETE /account',
      ]);
    },
  );

  test('lists live assistant resources with bearer token', () async {
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        expect(request.headers['Authorization'], 'Bearer token-123');
        if (request.path == '/tasks') {
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': [
                {'id': 1, 'title': 'Plan launch', 'status': 'open'},
              ],
            }),
          );
        }
        if (request.path == '/reminders') {
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': [
                {
                  'id': 2,
                  'title': 'Stand up',
                  'due_at': '2026-05-10T09:00:00Z',
                },
              ],
            }),
          );
        }
        if (request.path == '/calendar-events') {
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': [
                {
                  'id': 3,
                  'title': 'Design review',
                  'starts_at': '2026-05-10T14:30:00Z',
                },
              ],
            }),
          );
        }
        fail('Unexpected request: ${request.path}');
      },
    );

    expect((await client.listTasks()).single.title, 'Plan launch');
    expect((await client.listReminders()).single.title, 'Stand up');
    expect((await client.listCalendarEvents()).single.title, 'Design review');
  });

  test(
    'uses injected transport to start, resume, send, and poll activity',
    () async {
      final requests = <HermesApiRequest>[];
      final client = HermesApiClient(
        baseUrl: Uri.parse('http://local.test/api'),
        transport: (request) async {
          requests.add(request);

          if (request.method == 'POST' &&
              request.path == '/assistant/sessions') {
            expect(request.body, {
              'title': 'Demo session',
              'metadata': {'source': 'flutter-test'},
            });
            return HermesApiResponse(
              201,
              jsonEncode({
                'data': {'id': 42, 'status': 'active', 'title': 'Demo session'},
              }),
            );
          }

          if (request.method == 'GET' &&
              request.path == '/assistant/sessions/42') {
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {'id': 42, 'status': 'active', 'title': 'Demo session'},
              }),
            );
          }

          if (request.method == 'POST' &&
              request.path == '/assistant/sessions/42/messages') {
            expect(request.body, {
              'content': 'Schedule dentist tomorrow at 3pm',
              'metadata': {'source': 'flutter-test'},
            });
            return HermesApiResponse(
              201,
              jsonEncode({
                'data': {
                  'status': 'completed',
                  'session': {'id': 42, 'status': 'active'},
                  'user_message': {'id': 7, 'role': 'user'},
                  'assistant_message': {
                    'id': 8,
                    'role': 'assistant',
                    'content': 'Done',
                  },
                  'events': [
                    {'id': 1, 'event_type': 'runtime.message_received'},
                  ],
                  'blocker': null,
                },
              }),
            );
          }

          if (request.method == 'GET' &&
              request.path == '/assistant/sessions/42/events') {
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': [
                  {'id': 1, 'event_type': 'runtime.message_received'},
                  {'id': 2, 'event_type': 'assistant.calendar_event.created'},
                ],
              }),
            );
          }

          fail('Unexpected request: ${request.method} ${request.path}');
        },
      );

      final session = await client.startSession(
        title: 'Demo session',
        metadata: {'source': 'flutter-test'},
      );
      expect(session.id, 42);
      expect(session.status, 'active');

      final resumed = await client.resumeSession(42);
      expect(resumed.id, 42);

      final messageResult = await client.sendMessage(
        sessionId: 42,
        content: 'Schedule dentist tomorrow at 3pm',
        metadata: {'source': 'flutter-test'},
      );
      expect(messageResult.status, 'completed');
      expect(messageResult.assistantMessage?.content, 'Done');
      expect(messageResult.events.single.eventType, 'runtime.message_received');

      final events = await client.pollActivityEvents(42);
      expect(events.map((event) => event.eventType), [
        'runtime.message_received',
        'assistant.calendar_event.created',
      ]);

      expect(requests.map((r) => '${r.method} ${r.uri}'), [
        'POST http://local.test/api/assistant/sessions',
        'GET http://local.test/api/assistant/sessions/42',
        'POST http://local.test/api/assistant/sessions/42/messages',
        'GET http://local.test/api/assistant/sessions/42/events',
      ]);
    },
  );

  test('throws useful error for non-success API responses', () async {
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      transport: (_) async => HermesApiResponse(500, '{"message":"broken"}'),
    );

    expect(
      () => client.startSession(),
      throwsA(
        isA<HermesApiException>().having(
          (e) => e.statusCode,
          'statusCode',
          500,
        ),
      ),
    );
  });
}
