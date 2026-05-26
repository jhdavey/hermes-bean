import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:hermes_bean_app/hermes_api_client.dart';

void main() {
  test('uses production API base by default', () {
    expect(hermesApiBaseUrl, 'https://heybean.org/api');
    expect(HermesApiClient().baseUrl, Uri.parse('https://heybean.org/api'));
  });

  test('maps Android emulator localhost API base to host loopback', () {
    expect(
      normalizeHermesApiBaseUrlForPlatform(
        Uri.parse('http://127.0.0.1:8000/api'),
        isAndroid: true,
      ),
      Uri.parse('http://10.0.2.2:8000/api'),
    );
    expect(
      normalizeHermesApiBaseUrlForPlatform(
        Uri.parse('http://localhost:8000/api'),
        isAndroid: true,
      ),
      Uri.parse('http://10.0.2.2:8000/api'),
    );
    expect(
      normalizeHermesApiBaseUrlForPlatform(
        Uri.parse('https://heybean.org/api'),
        isAndroid: true,
      ),
      Uri.parse('https://heybean.org/api'),
    );
  });

  test('requests a password reset link without authenticating', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'old-token',
      transport: (request) async {
        requests.add(request);
        expect(request.method, 'POST');
        expect(request.path, '/auth/forgot-password');
        expect(request.headers.containsKey('Authorization'), isFalse);
        expect(request.body, {'email': 'bean@example.com'});
        return HermesApiResponse(
          200,
          jsonEncode({'message': 'Password reset link sent.'}),
        );
      },
    );

    await client.requestPasswordReset(email: 'bean@example.com');

    expect(client.bearerToken, 'old-token');
    expect(requests, hasLength(1));
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

  test(
    'updates reminder notification preferences through auth profile update',
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
            'notification_preferences': {
              'reminder_push': false,
              'reminder_email': true,
            },
          });
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': {
                'id': 9,
                'name': 'Bean User',
                'email': 'bean@example.com',
                'notification_preferences': {
                  'reminder_push': false,
                  'reminder_email': true,
                },
              },
            }),
          );
        },
      );

      final user = await client.updateMe(
        notificationPreferences: const HermesNotificationPreferences(
          reminderPush: false,
          reminderEmail: true,
        ),
      );

      expect(user.notificationPreferences.reminderPush, isFalse);
      expect(user.notificationPreferences.reminderEmail, isTrue);
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

  test(
    'parses legacy string-encoded metadata from production records',
    () async {
      final task = HermesTask.fromJson({
        'id': 1,
        'title': 'Cut grass',
        'status': 'open',
        'notes': 'Trim near the fence.',
        'metadata':
            '{"google_calendar_ids":["family@example.com"],"parent_task_id":42}',
      });
      final reminder = HermesReminder.fromJson({
        'id': 2,
        'title': 'Stand up',
        'remind_at': '2026-05-10T09:00:00Z',
        'metadata': '{"color":"#34C759","is_critical":true}',
      });
      final event = HermesCalendarEvent.fromJson({
        'id': 3,
        'workspace_id': 1,
        'linked_workspace_ids': [1, 2],
        'title': 'Design review',
        'starts_at': '2026-05-10T14:30:00Z',
        'metadata': '{"google_calendar_id":"work@example.com"}',
      });

      expect(task.googleCalendarIds, ['family@example.com']);
      expect(task.notes, 'Trim near the fence.');
      expect(task.parentTaskId, 42);
      expect(reminder.color, '#34C759');
      expect(reminder.isCritical, isTrue);
      expect(event.googleCalendarId, 'work@example.com');
      expect(event.workspaceId, 1);
      expect(event.linkedWorkspaceIds, [1, 2]);
    },
  );

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
              request.path == '/assistant/sessions/42/cancel') {
            return HermesApiResponse(
              202,
              jsonEncode({
                'data': {'id': 42, 'status': 'cancelling'},
              }),
            );
          }

          if (request.method == 'POST' &&
              request.path == '/assistant/sessions/42/messages') {
            expect(request.responseTimeout, const Duration(seconds: 120));
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
                    'metadata': {
                      'model': 'gpt-5.4',
                      'model_route': {'tier': 'standard'},
                    },
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
            expect(request.responseTimeout, const Duration(seconds: 30));
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

          if (request.method == 'POST' &&
              request.path == '/approvals/7/approve') {
            expect(request.body, {'always_approve': true});
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {
                  'approval': {
                    'id': 7,
                    'title': 'Send launch email',
                    'status': 'approved',
                  },
                  'events': [
                    {'id': 3, 'event_type': 'assistant.approval.approved'},
                  ],
                },
              }),
            );
          }

          if (request.method == 'POST' && request.path == '/approvals/8/deny') {
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {
                  'approval': {
                    'id': 8,
                    'title': 'Deploy release',
                    'status': 'denied',
                  },
                },
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

      final cancelled = await client.cancelSession(42);
      expect(cancelled.id, 42);

      final messageResult = await client.sendMessage(
        sessionId: 42,
        content: 'Schedule dentist tomorrow at 3pm',
        metadata: {'source': 'flutter-test'},
      );
      expect(messageResult.status, 'completed');
      expect(messageResult.assistantMessage?.content, 'Done');
      expect(messageResult.assistantMessage?.modelName, 'gpt-5.4');
      expect(messageResult.events.single.eventType, 'runtime.message_received');

      final events = await client.pollActivityEvents(42);
      expect(events.map((event) => event.eventType), [
        'runtime.message_received',
        'assistant.calendar_event.created',
      ]);

      final approved = await client.approveApproval(7, alwaysApprove: true);
      expect(approved.approval.status, 'approved');
      expect(approved.events.single.eventType, 'assistant.approval.approved');

      final denied = await client.denyApproval(8);
      expect(denied.approval.status, 'denied');

      expect(requests.map((r) => '${r.method} ${r.uri}'), [
        'POST http://local.test/api/assistant/sessions',
        'GET http://local.test/api/assistant/sessions/42',
        'POST http://local.test/api/assistant/sessions/42/cancel',
        'POST http://local.test/api/assistant/sessions/42/messages',
        'GET http://local.test/api/assistant/sessions/42/events',
        'POST http://local.test/api/approvals/7/approve',
        'POST http://local.test/api/approvals/8/deny',
      ]);
    },
  );

  test('supports workspace endpoints and parses workspace fields', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        requests.add(request);
        expect(request.headers['Authorization'], 'Bearer token-123');

        if (request.method == 'GET' && request.path == '/workspaces') {
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': [
                {
                  'id': 1,
                  'type': 'personal',
                  'name': 'Bean Personal Workspace',
                  'status': 'active',
                  'memberships': [
                    {
                      'id': 10,
                      'workspace_id': 1,
                      'user_id': 9,
                      'role': 'owner',
                    },
                  ],
                },
              ],
            }),
          );
        }
        if (request.method == 'POST' && request.path == '/workspaces') {
          expect(request.body, {'name': 'Family'});
          return HermesApiResponse(
            201,
            jsonEncode({
              'data': {'id': 2, 'type': 'household', 'name': 'Family'},
            }),
          );
        }
        if (request.method == 'GET' && request.path == '/workspaces/2') {
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': {'id': 2, 'type': 'household', 'name': 'Family'},
            }),
          );
        }
        if (request.method == 'PATCH' && request.path == '/workspaces/2') {
          expect(request.body, {
            'name': 'Home',
            'settings': {'timezone': 'UTC'},
          });
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': {
                'id': 2,
                'type': 'household',
                'name': 'Home',
                'settings': {'timezone': 'UTC'},
              },
            }),
          );
        }
        if (request.method == 'POST' &&
            request.path == '/workspaces/2/invitations') {
          expect(request.body, {'email': 'parent@example.com'});
          return HermesApiResponse(
            201,
            jsonEncode({
              'data': {
                'id': 20,
                'workspace_id': 2,
                'role': 'member',
                'status': 'invited',
                'invited_email': 'parent@example.com',
                'invitation_token': 'invite-token',
                'invitation_accept_url':
                    'https://heybean.org/workspace-invitations/invite-token/accept',
              },
            }),
          );
        }
        if (request.method == 'POST' &&
            request.path == '/workspace-invitations/invite-token/accept') {
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': {
                'id': 20,
                'workspace_id': 2,
                'user_id': 9,
                'role': 'member',
                'status': 'active',
                'workspace': {'id': 2, 'name': 'Home'},
              },
            }),
          );
        }
        if (request.method == 'PATCH' &&
            request.path == '/workspaces/2/members/20') {
          expect(request.body, {'role': 'owner'});
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': {'id': 20, 'workspace_id': 2, 'role': 'owner'},
            }),
          );
        }
        if (request.method == 'DELETE' &&
            request.path == '/workspaces/2/members/20') {
          return const HermesApiResponse(204, '');
        }
        if (request.method == 'POST' && request.path == '/workspaces/2/leave') {
          return const HermesApiResponse(204, '');
        }
        if (request.method == 'PATCH' &&
            request.path == '/workspaces/default') {
          expect(request.body, {'workspace_id': 2});
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': {'id': 2, 'type': 'household', 'name': 'Home'},
            }),
          );
        }
        if (request.method == 'POST' &&
            request.path == '/workspaces/1/sync-all') {
          expect(request.body, {
            'target_workspace_id': 2,
            'resource_types': ['tasks', 'reminders'],
          });
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': {'tasks': 3, 'reminders': 2, 'calendar_events': 0},
            }),
          );
        }
        if (request.method == 'PATCH' &&
            request.path == '/workspaces/2/google-calendars') {
          expect(request.body, {
            'google_calendar_ids': ['primary', 'family@example.com'],
            'default_export_calendar_id': 'family@example.com',
          });
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': [
                {'id': 1, 'google_calendar_id': 'family@example.com'},
              ],
            }),
          );
        }
        if (request.method == 'GET' && request.path == '/auth/me') {
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': {
                'id': 9,
                'name': 'Bean User',
                'email': 'bean@example.com',
                'default_workspace_id': 2,
                'personal_workspace': {
                  'id': 1,
                  'name': 'Bean Personal Workspace',
                },
                'active_workspace': {'id': 2, 'name': 'Home'},
                'workspaces': [
                  {'id': 1, 'name': 'Bean Personal Workspace'},
                  {'id': 2, 'name': 'Home'},
                ],
              },
            }),
          );
        }
        if (request.method == 'GET' &&
            request.path == '/today?workspace_id=2') {
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': {
                'user': {
                  'id': 9,
                  'name': 'Bean User',
                  'email': 'bean@example.com',
                },
                'workspace': {'id': 2, 'name': 'Home'},
                'workspaces': [
                  {'id': 2, 'name': 'Home'},
                ],
                'session': null,
                'tasks': [],
                'reminders': [],
                'calendar_events': [],
                'activity_events': [],
                'approvals': [],
                'blockers': [],
              },
            }),
          );
        }

        fail('Unexpected request: ${request.method} ${request.path}');
      },
    );

    expect(
      (await client.listWorkspaces()).single.memberships.single.role,
      'owner',
    );
    expect((await client.createWorkspace(name: 'Family')).id, '2');
    expect((await client.getWorkspace(2)).name, 'Family');
    expect(
      (await client.updateWorkspace(
        2,
        name: 'Home',
        settings: {'timezone': 'UTC'},
      )).settings['timezone'],
      'UTC',
    );
    expect(
      (await client.inviteWorkspaceMember(
        2,
        email: 'parent@example.com',
      )).invitationToken,
      'invite-token',
    );
    expect(
      (await client.inviteWorkspaceMember(
        2,
        email: 'parent@example.com',
      )).invitationAcceptUrl,
      'https://heybean.org/workspace-invitations/invite-token/accept',
    );
    expect(
      (await client.acceptWorkspaceInvitation('invite-token')).workspace?.id,
      '2',
    );
    expect(
      (await client.updateWorkspaceMember(2, 20, role: 'owner')).role,
      'owner',
    );
    await client.removeWorkspaceMember(2, 20);
    await client.leaveWorkspace(2);
    expect((await client.setDefaultWorkspace(2)).name, 'Home');
    expect(
      (await client.syncWorkspaceAll(
        1,
        targetWorkspaceId: 2,
        resourceTypes: ['tasks', 'reminders'],
      )).tasks,
      3,
    );
    expect(
      (await client.updateWorkspaceGoogleCalendars(
        2,
        googleCalendarIds: ['primary', 'family@example.com'],
        defaultExportCalendarId: 'family@example.com',
      )).single['google_calendar_id'],
      'family@example.com',
    );
    final me = await client.me();
    expect(me.defaultWorkspaceId, 2);
    expect(me.activeWorkspace?.name, 'Home');
    final today = await client.todaySummary(workspaceId: 2);
    expect(today.workspace?.id, '2');
    expect(today.workspaces.single.name, 'Home');

    expect(
      requests.last.uri,
      Uri.parse('http://local.test/api/today?workspace_id=2'),
    );
  });

  test(
    'adds workspace and sync parameters to domain resource payloads',
    () async {
      final requests = <HermesApiRequest>[];
      final client = HermesApiClient(
        baseUrl: Uri.parse('http://local.test/api'),
        bearerToken: 'token-123',
        transport: (request) async {
          requests.add(request);
          if (request.path == '/tasks' && request.method == 'POST') {
            expect(request.body, {
              'title': 'Buy milk',
              'type': 'todo',
              'status': 'open',
              'due_at': null,
              'category': null,
              'color': null,
              'is_critical': false,
              'workspace_id': 1,
              'sync_to_workspace_ids': [2, 3],
            });
            return HermesApiResponse(
              201,
              jsonEncode({
                'data': {'id': 1, 'title': 'Buy milk'},
              }),
            );
          }
          if (request.path == '/tasks/1' && request.method == 'PATCH') {
            expect(request.body, {
              'due_at': null,
              'sync_to_workspace_ids': [2],
            });
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {'id': 1, 'title': 'Buy milk'},
              }),
            );
          }
          if (request.path == '/reminders' && request.method == 'POST') {
            expect(request.body, containsPair('workspace_id', 1));
            expect(request.body, containsPair('sync_to_workspace_ids', [2]));
            return HermesApiResponse(
              201,
              jsonEncode({
                'data': {'id': 2, 'title': 'Ping'},
              }),
            );
          }
          if (request.path == '/reminders/2' && request.method == 'PATCH') {
            expect(request.body, {
              'sync_to_workspace_ids': [3],
            });
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {'id': 2, 'title': 'Ping'},
              }),
            );
          }
          if (request.path == '/calendar-events' && request.method == 'POST') {
            expect(request.body, containsPair('workspace_id', 1));
            expect(request.body, containsPair('sync_to_workspace_ids', [2]));
            return HermesApiResponse(
              201,
              jsonEncode({
                'data': {'id': 3, 'title': 'Meet'},
              }),
            );
          }
          if (request.path == '/calendar-events/3' &&
              request.method == 'PATCH') {
            expect(request.body, containsPair('sync_to_workspace_ids', [2, 3]));
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {'id': 3, 'title': 'Meet'},
              }),
            );
          }
          if (request.path == '/tasks/1' && request.method == 'DELETE') {
            expect(
              request.body,
              containsPair('delete_from_workspace_ids', [1, 2, 3]),
            );
            return const HermesApiResponse(204, '');
          }
          if (request.path == '/reminders/2' && request.method == 'DELETE') {
            expect(
              request.body,
              containsPair('delete_from_workspace_ids', [1, 3]),
            );
            return const HermesApiResponse(204, '');
          }
          if (request.path == '/event-categories/4' &&
              request.method == 'DELETE') {
            expect(
              request.body,
              containsPair('delete_from_workspace_ids', [2, 3]),
            );
            return const HermesApiResponse(204, '');
          }
          if (request.path == '/calendar-events/3' &&
              request.method == 'DELETE') {
            expect(
              request.body,
              containsPair('delete_from_workspace_ids', [1, 2]),
            );
            expect(
              request.body,
              containsPair('recurring_delete_mode', 'single'),
            );
            expect(
              request.body,
              containsPair('recurring_occurrence_date', '2026-05-27'),
            );
            return const HermesApiResponse(204, '');
          }
          fail('Unexpected request: ${request.method} ${request.path}');
        },
      );

      await client.createTask(
        title: 'Buy milk',
        workspaceId: 1,
        syncToWorkspaceIds: [2, 3],
      );
      await client.updateTask(1, syncToWorkspaceIds: [2]);
      await client.createReminder(
        title: 'Ping',
        remindAt: '2026-05-20T10:00:00Z',
        workspaceId: 1,
        syncToWorkspaceIds: [2],
      );
      await client.updateReminder(2, syncToWorkspaceIds: [3]);
      await client.createCalendarEvent(
        title: 'Meet',
        startsAt: '2026-05-20T10:00:00Z',
        workspaceId: 1,
        syncToWorkspaceIds: [2],
      );
      await client.updateCalendarEvent(
        3,
        title: 'Meet',
        startsAt: '2026-05-20T10:00:00Z',
        syncToWorkspaceIds: [2, 3],
      );
      await client.deleteTask(1, deleteFromWorkspaceIds: [1, 2, 3]);
      await client.deleteReminder(2, deleteFromWorkspaceIds: [1, 3]);
      await client.deleteEventCategory(4, deleteFromWorkspaceIds: [2, 3]);
      await client.deleteCalendarEvent(
        3,
        deleteFromWorkspaceIds: [1, 2],
        recurringDeleteMode: 'single',
        recurringOccurrenceDate: '2026-05-27',
      );

      expect(requests, hasLength(10));
    },
  );

  test(
    'sends empty sync workspace arrays on updates so assignments can be cleared',
    () async {
      final requests = <HermesApiRequest>[];
      final client = HermesApiClient(
        baseUrl: Uri.parse('http://local.test/api'),
        bearerToken: 'token-123',
        transport: (request) async {
          requests.add(request);
          if (request.path == '/tasks/1' && request.method == 'PATCH') {
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {'id': 1, 'title': 'Task'},
              }),
            );
          }
          if (request.path == '/reminders/2' && request.method == 'PATCH') {
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {'id': 2, 'title': 'Reminder'},
              }),
            );
          }
          if (request.path == '/calendar-events/3' &&
              request.method == 'PATCH') {
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {'id': 3, 'title': 'Event'},
              }),
            );
          }
          fail('Unexpected request: ${request.method} ${request.path}');
        },
      );

      await client.updateTask(1, syncToWorkspaceIds: const []);
      await client.updateReminder(2, syncToWorkspaceIds: const []);
      await client.updateCalendarEvent(
        3,
        title: 'Event',
        startsAt: '2026-05-20T10:00:00Z',
        syncToWorkspaceIds: const [],
      );

      expect(requests[0].body, containsPair('sync_to_workspace_ids', const []));
      expect(requests[1].body, containsPair('sync_to_workspace_ids', const []));
      expect(requests[2].body, containsPair('sync_to_workspace_ids', const []));
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
