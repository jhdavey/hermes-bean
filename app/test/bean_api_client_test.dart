import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:heybean_app/bean_api_client.dart';

void main() {
  test('API paths retain existing query parameters', () {
    final client = BeanApiClient(
      baseUrl: Uri.parse('https://example.test/api'),
    );

    expect(
      client.resolveApiUri('/tasks?workspace_id=12').toString(),
      'https://example.test/api/tasks?workspace_id=12',
    );
    expect(
      client
          .resolveApiUri('/calendar-events?skip_google_sync=1&workspace_id=12')
          .toString(),
      'https://example.test/api/calendar-events?skip_google_sync=1&workspace_id=12',
    );
  });

  test('direct productivity models preserve server fields', () {
    final task = BeanTask.fromJson({
      'id': 7,
      'title': 'File taxes',
      'status': 'open',
      'due_at': '2026-07-17T14:00:00Z',
      'is_critical': true,
    });
    final reminder = BeanReminder.fromJson({
      'id': 8,
      'title': 'Call home',
      'status': 'scheduled',
      'remind_at': '2026-07-17T15:00:00Z',
    });
    final event = BeanCalendarEvent.fromJson({
      'id': 9,
      'title': 'Planning',
      'status': 'scheduled',
      'starts_at': '2026-07-17T16:00:00Z',
    });

    expect(task.title, 'File taxes');
    expect(task.isCritical, isTrue);
    expect(reminder.title, 'Call home');
    expect(event.title, 'Planning');
  });
  test('dashboard resource parsers tolerate legacy scalar shapes', () {
    final task = BeanTask.fromJson({
      'id': 7,
      'title': 'File taxes',
      'status': 'open',
      'due_at': 1784316000,
      'notes': 123,
      'metadata': {'category': 456, 'color': 789},
      'is_critical': 1,
      'completed_at': null,
      'linked_workspace_ids': ['1', 2],
    });
    final reminder = BeanReminder.fromJson({
      'id': 8,
      'title': 'Call home',
      'status': 'scheduled',
      'remind_at': 1784319600,
      'category': 456,
      'color': 789,
      'is_critical': 'true',
      'linked_workspace_ids': ['1', 2],
    });
    final event = BeanCalendarEvent.fromJson({
      'id': 9,
      'title': 'Planning',
      'status': 'scheduled',
      'starts_at': 1784323200,
      'ends_at': 1784326800,
      'description': 123,
      'location': 456,
      'all_day': 0,
      'category': 789,
      'color': 101112,
      'recurrence': 131415,
      'linked_workspace_ids': ['1', 2],
    });

    expect(task.dueAt, '1784316000');
    expect(task.notes, '123');
    expect(task.category, '456');
    expect(task.color, '789');
    expect(task.isCritical, isTrue);
    expect(task.linkedWorkspaceIds, [1, 2]);
    expect(reminder.dueAt, '1784319600');
    expect(reminder.category, '456');
    expect(reminder.color, '789');
    expect(reminder.isCritical, isTrue);
    expect(event.startsAt, '1784323200');
    expect(event.endsAt, '1784326800');
    expect(event.notes, '123');
    expect(event.location, '456');
    expect(event.metadata?['all_day'], isFalse);
    expect(event.category, '789');
    expect(event.color, '101112');
    expect(event.recurrence, '131415');
  });

  test('dashboard resource parsers tolerate stale mixed profile data', () {
    final task = BeanTask.fromJson({
      'id': '7',
      'title': 'Mixed task',
      'status': 'archived',
      'metadata': 'not json',
      'linked_workspace_ids': ['1', 2],
    });
    final reminder = BeanReminder.fromJson({
      'id': '8',
      'title': 'Mixed reminder',
      'status': null,
      'calendar_event_id': '99',
      'metadata': 123,
    });
    final event = BeanCalendarEvent.fromJson({
      'id': '9',
      'title': 'Mixed event',
      'status': 'deleted',
      'metadata': 'not json',
      'all_day': 'yes',
    });
    final category = BeanEventCategory.fromJson({
      'id': '10',
      'name': 123,
      'color': 456,
    });

    expect(task.id, 7);
    expect(task.status, 'open');
    expect(task.metadata, isNull);
    expect(reminder.id, 8);
    expect(reminder.status, 'scheduled');
    expect(reminder.calendarEventId, 99);
    expect(event.id, 9);
    expect(event.status, 'scheduled');
    expect(event.metadata?['all_day'], isTrue);
    expect(category.id, 10);
    expect(category.name, '123');
    expect(category.color, '456');
  });

  test('Flutter Bean API client posts messages to Laravel runtime', () async {
    final requests = <BeanApiRequest>[];
    final client = BeanApiClient(
      baseUrl: Uri.parse('https://example.test/api'),
      bearerToken: 'test-token',
      transport: (request) async {
        requests.add(request);
        return BeanApiResponse(
          200,
          jsonEncode({
            'data': {
              'session': {'id': 42},
              'run': {
                'id': 7,
                'status': 'completed',
                'model': 'hermes:custom/gpt-test',
              },
              'messages': [
                {'id': 1, 'role': 'user', 'content': 'Create task call mom'},
                {
                  'id': 2,
                  'role': 'assistant',
                  'content': 'I’ll add that task. Done.',
                },
              ],
              'confirmations': [],
            },
          }),
        );
      },
    );

    final turn = await client.sendBeanMessage(
      content: 'Create task call mom',
      sessionId: 42,
      clientTimezone: 'America/New_York',
    );

    expect(requests.single.method, 'POST');
    expect(requests.single.path, '/bean/messages');
    expect(requests.single.headers['Authorization'], 'Bearer test-token');
    expect(requests.single.body, {
      'content': 'Create task call mom',
      'session_id': 42,
      'client_timezone': 'America/New_York',
    });
    expect(turn.session.id, 42);
    expect(turn.run.status, 'completed');
    expect(turn.messages.last.content, 'I’ll add that task. Done.');
  });

  test('Flutter notes use Laravel markdown contract', () async {
    final requests = <BeanApiRequest>[];
    final client = BeanApiClient(
      baseUrl: Uri.parse('https://example.test/api'),
      bearerToken: 'test-token',
      transport: (request) async {
        requests.add(request);
        return BeanApiResponse(
          200,
          jsonEncode({
            'data': {
              'id': 11,
              'title': 'Plan',
              'body_markdown': '# Plan\n- [ ] ship flutter',
              'plain_text': null,
            },
          }),
        );
      },
    );

    final note = await client.createNote(
      title: 'Plan',
      bodyMarkdown: '# Plan\n- [ ] ship flutter',
    );

    expect(requests.single.path, '/notes');
    expect(
      requests.single.body?['body_markdown'],
      '# Plan\n- [ ] ship flutter',
    );
    expect(requests.single.body?.containsKey('body_html'), isFalse);
    expect(requests.single.body?.containsKey('plain_text'), isFalse);
    expect(note.bodyMarkdown, '# Plan\n- [ ] ship flutter');
  });

  test('daily sticky note client matches Laravel endpoint', () async {
    final requests = <BeanApiRequest>[];
    final client = BeanApiClient(
      baseUrl: Uri.parse('https://example.test/api'),
      bearerToken: 'test-token',
      transport: (request) async {
        requests.add(request);
        return BeanApiResponse(
          200,
          jsonEncode({
            'data': {
              'date': '2026-07-21',
              'content': 'today scratchpad',
              'updated_at': '2026-07-21T12:00:00Z',
            },
          }),
        );
      },
    );

    final note = await client.updateDailyStickyNote(
      date: '2026-07-21',
      content: 'today scratchpad',
      workspaceId: 5,
    );

    expect(requests.single.method, 'PUT');
    expect(requests.single.path, '/daily-sticky-note');
    expect(requests.single.body, {
      'date': '2026-07-21',
      'content': 'today scratchpad',
      'workspace_id': 5,
    });
    expect(note.content, 'today scratchpad');
  });

  test('Bean assistant lifecycle methods match Laravel routes', () async {
    final requests = <BeanApiRequest>[];
    final client = BeanApiClient(
      baseUrl: Uri.parse('https://example.test/api'),
      bearerToken: 'test-token',
      transport: (request) async {
        requests.add(request);
        final data = switch (request.path) {
          '/bean/sessions' => {
            'data': {'id': 44},
          },
          '/bean/sessions/44/activity' => {
            'data': {
              'messages': [
                {'id': 1, 'role': 'assistant', 'content': 'Ready'},
              ],
              'activity': [
                {'id': 2, 'type': 'tool_call'},
              ],
              'confirmations': [
                {'id': 3, 'action': 'task.delete', 'status': 'pending'},
              ],
            },
          },
          '/bean/confirmations/3/approve' => {
            'data': {
              'session': {'id': 44},
              'run': {'id': 5, 'status': 'completed'},
              'messages': [],
              'confirmations': [],
            },
          },
          _ => {'data': {}},
        };
        return BeanApiResponse(200, jsonEncode(data));
      },
    );

    final session = await client.createBeanSession(
      workspaceId: 9,
      clientTimezone: 'America/New_York',
    );
    final activity = await client.getBeanSessionActivity(session.id);
    await client.recordBeanVoiceEvent(
      eventType: 'voice_started',
      sessionId: session.id,
      source: 'flutter_native',
    );
    final approved = await client.approveBeanConfirmation(3);

    expect(requests[0].path, '/bean/sessions');
    expect(requests[0].body, {
      'workspace_id': 9,
      'client_timezone': 'America/New_York',
    });
    expect(requests[1].path, '/bean/sessions/44/activity');
    expect(activity.confirmations.single.action, 'task.delete');
    expect(requests[2].path, '/bean/voice-events');
    expect(requests[2].body?['event_type'], 'voice_started');
    expect(requests[3].path, '/bean/confirmations/3/approve');
    expect(approved.run.status, 'completed');
  });

  test('ElevenLabs conversation token uses Laravel Agent endpoint', () async {
    final requests = <BeanApiRequest>[];
    final client = BeanApiClient(
      baseUrl: Uri.parse('https://example.test/api'),
      bearerToken: 'test-token',
      transport: (request) async {
        requests.add(request);
        return BeanApiResponse(
          200,
          jsonEncode({
            'data': {
              'token': 'convai_test_token',
              'agent_id': 'agent_test',
              'bean_session_id': 44,
              'transport': 'elevenlabs_agent',
              'dashboard_context': {
                'timezone': 'America/New_York',
                'today': {'date': '2026-07-21'},
              },
            },
          }),
        );
      },
    );

    final realtime = await client.createBeanRealtimeSession(
      sessionId: 44,
      workspaceId: 9,
      clientTimezone: 'America/New_York',
    );

    expect(requests.single.path, '/bean/elevenlabs/conversation-token');
    expect(requests.single.body, {
      'session_id': 44,
      'workspace_id': 9,
      'client_timezone': 'America/New_York',
    });
    expect(realtime.token, 'convai_test_token');
    expect(realtime.agentId, 'agent_test');
    expect(realtime.beanSessionId, 44);
    expect(realtime.transport, 'elevenlabs_agent');
    expect(realtime.dashboardContext['timezone'], 'America/New_York');
  });
}
