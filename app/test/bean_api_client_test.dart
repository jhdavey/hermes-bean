import 'package:flutter_test/flutter_test.dart';
import 'package:heybean_app/bean_api_client.dart';

void main() {
  test('workspace paths retain existing query parameters', () {
    final client = BeanApiClient(baseUrl: 'https://example.test/api');

    expect(client.workspacePath('/tasks', 12), '/tasks?workspace_id=12');
    expect(
      client.workspacePath('/calendar-events?skip_google_sync=1', 12),
      '/calendar-events?skip_google_sync=1&workspace_id=12',
    );

    client.close();
  });
}
