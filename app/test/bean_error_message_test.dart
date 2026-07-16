import 'package:flutter_test/flutter_test.dart';
import 'package:heybean_app/bean_api_client.dart';
import 'package:heybean_app/main.dart';

void main() {
  test('API errors are converted to useful app guidance', () {
    final message = beanFriendlyErrorMessage(
      const BeanApiException(503, '{}'),
      action: 'save that task',
    );

    expect(message, contains('Could not save that task.'));
    expect(message, contains('temporarily unavailable'));
  });
}
