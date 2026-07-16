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
}
