import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:hermes_bean_app/hermes_api_client.dart';

void main() {
  test('defaults to the production HeyBean API', () {
    expect(hermesApiBaseUrl, 'https://heybean.org/api');
    expect(HermesApiClient().baseUrl, Uri.parse('https://heybean.org/api'));
  });

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
              'password': 'secret123456',
              'password_confirmation': 'secret123456',
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
        password: 'secret123456',
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

  test(
    'updates Bean onboarding preferences through auth profile update',
    () async {
      final requests = <HermesApiRequest>[];
      final client = HermesApiClient(
        baseUrl: Uri.parse('http://local.test/api'),
        bearerToken: 'token-123',
        transport: (request) async {
          requests.add(request);
          expect(request.method, 'PATCH');
          expect(request.path, '/auth/me');
          expect(request.headers['Authorization'], 'Bearer token-123');
          expect(request.body, {
            'agent_personality': 'coach',
            'onboarding_priorities': ['Family', 'Planning'],
            'onboarding_context': 'Protect dinner.',
          });
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': {
                'id': 9,
                'name': 'Bean User',
                'email': 'bean@example.com',
                'onboard_complete': true,
                'agent_profile': {
                  'settings': {
                    'personality_type': 'coach',
                    'onboarding': {
                      'completed': true,
                      'priorities': ['Family', 'Planning'],
                      'context': 'Protect dinner.',
                    },
                  },
                },
              },
            }),
          );
        },
      );

      final user = await client.updateMe(
        agentPersonality: 'coach',
        onboardingPriorities: ['Family', 'Planning'],
        onboardingContext: 'Protect dinner.',
      );

      expect(user.onboardComplete, isTrue);
      expect(user.agentProfile?.settings['personality_type'], 'coach');
      expect(requests, hasLength(1));
    },
  );

  test('marks tasks complete with bearer token', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        requests.add(request);
        expect(request.method, 'PATCH');
        expect(request.path, '/tasks/7');
        expect(request.headers['Authorization'], 'Bearer token-123');
        expect(request.body, {'status': 'completed'});
        return HermesApiResponse(
          200,
          jsonEncode({
            'data': {
              'id': 7,
              'title': 'Pack bags',
              'status': 'completed',
              'completed_at': '2026-05-12T12:00:00Z',
            },
          }),
        );
      },
    );

    final task = await client.completeTask(7);

    expect(task.status, 'completed');
    expect(task.completedAt, '2026-05-12T12:00:00Z');
    expect(requests, hasLength(1));
  });

  test('marks completed tasks open again with bearer token', () async {
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        expect(request.method, 'PATCH');
        expect(request.path, '/tasks/7');
        expect(request.headers['Authorization'], 'Bearer token-123');
        expect(request.body, {'status': 'open', 'completed_at': null});
        return HermesApiResponse(
          200,
          jsonEncode({
            'data': {'id': 7, 'title': 'Pack bags', 'status': 'open'},
          }),
        );
      },
    );

    final task = await client.reopenTask(7);

    expect(task.status, 'open');
    expect(task.completedAt, isNull);
  });

  test('lists past completed tasks with bearer token', () async {
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        expect(request.method, 'GET');
        expect(request.path, '/tasks/past');
        expect(request.headers['Authorization'], 'Bearer token-123');
        return HermesApiResponse(
          200,
          jsonEncode({
            'data': [
              {
                'id': 8,
                'title': 'Dropped-off task',
                'status': 'completed',
                'due_at': '2026-05-10T09:00:00Z',
                'completed_at': '2026-05-10T18:00:00Z',
              },
            ],
          }),
        );
      },
    );

    final tasks = await client.listPastTasks();

    expect(tasks.single.title, 'Dropped-off task');
    expect(tasks.single.completedAt, '2026-05-10T18:00:00Z');
  });

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

  test('parses and saves Google Calendar selection metadata', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        requests.add(request);
        expect(request.headers['Authorization'], 'Bearer token-123');
        if (request.method == 'GET' &&
            request.path == '/google-calendar/status') {
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': {
                'connected': true,
                'status': 'connected',
                'calendar_id': 'primary',
                'default_calendar_id': 'work@example.com',
                'selected_calendar_ids': ['primary', 'work@example.com'],
                'calendars': [
                  {
                    'id': 'primary',
                    'summary': 'Personal',
                    'primary': true,
                    'selected': true,
                    'access_role': 'owner',
                  },
                  {
                    'id': 'work@example.com',
                    'summary': 'Work',
                    'selected': true,
                    'access_role': 'writer',
                    'color': '#0B8043',
                  },
                  {
                    'id': 'readonly@example.com',
                    'summary': 'Readonly',
                    'access_role': 'reader',
                  },
                ],
              },
            }),
          );
        }
        if (request.method == 'PATCH' &&
            request.path == '/google-calendar/calendars') {
          expect(request.body, {
            'selected_calendar_ids': ['work@example.com'],
            'default_calendar_id': 'work@example.com',
          });
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': {
                'connected': true,
                'status': 'connected',
                'default_calendar_id': 'work@example.com',
                'selected_calendar_ids': ['work@example.com'],
                'calendars': [],
              },
            }),
          );
        }
        fail('Unexpected request: ${request.method} ${request.path}');
      },
    );

    final status = await client.googleCalendarStatus();
    expect(status.defaultCalendarId, 'work@example.com');
    expect(status.selectedCalendarIds, ['primary', 'work@example.com']);
    expect(status.calendars.map((calendar) => calendar.summary), [
      'Personal',
      'Work',
      'Readonly',
    ]);
    expect(status.writableCalendars.map((calendar) => calendar.id), [
      'primary',
      'work@example.com',
    ]);

    await client.updateGoogleCalendarSelection(
      selectedCalendarIds: ['work@example.com'],
      defaultCalendarId: 'work@example.com',
    );
    expect(requests.map((request) => '${request.method} ${request.path}'), [
      'GET /google-calendar/status',
      'PATCH /google-calendar/calendars',
    ]);
  });

  test(
    'creates calendar events with Google Calendar routing metadata',
    () async {
      final requests = <HermesApiRequest>[];
      final client = HermesApiClient(
        baseUrl: Uri.parse('http://local.test/api'),
        bearerToken: 'token-123',
        transport: (request) async {
          requests.add(request);
          expect(request.method, 'POST');
          expect(request.path, '/calendar-events');
          expect(request.headers['Authorization'], 'Bearer token-123');
          expect(request.body, {
            'title': 'Client kickoff',
            'starts_at': '2026-05-20T15:00:00Z',
            'ends_at': '2026-05-20T16:00:00Z',
            'category': 'Work',
            'color': '#007AFF',
            'recurrence': 'none',
            'is_critical': false,
            'metadata': {'google_calendar_id': 'work@example.com'},
          });
          return HermesApiResponse(
            201,
            jsonEncode({
              'data': {
                'id': 77,
                'title': 'Client kickoff',
                'starts_at': '2026-05-20T15:00:00Z',
                'ends_at': '2026-05-20T16:00:00Z',
                'category': 'Work',
                'color': '#007AFF',
                'recurrence': 'none',
                'google_calendar_id': 'work@example.com',
              },
            }),
          );
        },
      );

      final event = await client.createCalendarEvent(
        title: 'Client kickoff',
        startsAt: '2026-05-20T15:00:00Z',
        endsAt: '2026-05-20T16:00:00Z',
        category: 'Work',
        color: '#007AFF',
        recurrence: 'none',
        isCritical: false,
        metadata: {'google_calendar_id': 'work@example.com'},
      );

      expect(event.id, 77);
      expect(event.googleCalendarId, 'work@example.com');
      expect(requests, hasLength(1));
    },
  );

  test('parses calendar event Google calendar ids', () async {
    final event = HermesCalendarEvent.fromJson({
      'id': 3,
      'title': 'Design review',
      'starts_at': '2026-05-10T14:30:00Z',
      'google_calendar_id': 'work@example.com',
      'metadata': {'recurrence': 'none'},
    });

    expect(event.googleCalendarId, 'work@example.com');
    expect(event.metadata?['google_calendar_id'], 'work@example.com');
  });

  test('loads today summary with live surfaces and blockers', () async {
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        expect(request.method, 'GET');
        expect(request.path, '/today');
        expect(request.headers['Authorization'], 'Bearer token-123');
        return HermesApiResponse(
          200,
          jsonEncode({
            'data': {
              'session': {'id': 42, 'status': 'active', 'title': 'Today'},
              'tasks': [
                {'id': 1, 'title': 'Review launch notes', 'status': 'open'},
              ],
              'reminders': [
                {
                  'id': 2,
                  'title': 'pack laptop',
                  'remind_at': '2026-05-11T09:00:00Z',
                },
              ],
              'calendar_events': [
                {
                  'id': 3,
                  'title': 'Focus block',
                  'starts_at': '2026-05-11T09:00:00Z',
                },
              ],
              'activity_events': [
                {'id': 4, 'event_type': 'assistant.task.created'},
              ],
              'approvals': [
                {'id': 5, 'title': 'Approve draft reply', 'status': 'pending'},
              ],
              'blockers': [
                {'id': 6, 'reason': 'Connect calendar', 'status': 'open'},
              ],
              'counts': {'tasks': 1},
            },
          }),
        );
      },
    );

    final today = await client.todaySummary();
    expect(today.session?.id, 42);
    expect(today.tasks.single.title, 'Review launch notes');
    expect(today.reminders.single.title, 'pack laptop');
    expect(today.calendarEvents.single.title, 'Focus block');
    expect(today.activityEvents.single.eventType, 'assistant.task.created');
    expect(today.approvals.single.title, 'Approve draft reply');
    expect(today.blockers.single.reason, 'Connect calendar');
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
