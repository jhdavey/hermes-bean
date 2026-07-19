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
    );

    expect(requests.single.method, 'POST');
    expect(requests.single.path, '/bean/messages');
    expect(requests.single.headers['Authorization'], 'Bearer test-token');
    expect(requests.single.body, {
      'content': 'Create task call mom',
      'session_id': 42,
    });
    expect(turn.session.id, 42);
    expect(turn.run.status, 'completed');
    expect(turn.messages.last.content, 'I’ll add that task. Done.');
  });
}
