import 'dart:async';
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hermes_bean_app/hermes_api_client.dart';
import 'package:hermes_bean_app/main.dart';

void main() {
  test(
    'API errors are translated into friendly Bean messages without raw bodies',
    () {
      const rawBody =
          '{"message":"SQLSTATE[23000]: Integrity constraint violation","trace":"/srv/app/stack"}';
      final message = beanFriendlyErrorMessage(
        const HermesApiException(500, rawBody),
        action: 'save that task',
      );

      expect(message, contains('Bean could not save that task.'));
      expect(message, contains('our side'));
      expect(message, contains('we’ll fix it as soon as possible'));
      expect(message, isNot(contains('SQLSTATE')));
      expect(message, isNot(contains('HermesApiException')));
      expect(message, isNot(contains('500')));
      expect(message, isNot(contains('trace')));
    },
  );

  test('validation errors may show safe natural-language field guidance', () {
    const body =
        '{"message":"The email field is required.","errors":{"email":["The email field is required."]}}';
    final message = beanFriendlyErrorMessage(
      const HermesApiException(422, body),
      action: 'create your account',
    );

    expect(message, contains('Bean could not create your account.'));
    expect(message, contains('The email field is required.'));
    expect(message, contains('Please adjust it and try again.'));
    expect(message, isNot(contains('422')));
  });

  test(
    'network, timeout, and platform failures are reassuring and actionable',
    () {
      expect(
        beanFriendlyErrorMessage(
          const SocketException('Connection refused 10.0.2.2:8000'),
          action: 'refresh your latest data',
        ),
        allOf(
          contains('Bean could not refresh your latest data.'),
          contains('check your connection'),
          isNot(contains('10.0.2.2')),
        ),
      );

      expect(
        beanFriendlyErrorMessage(TimeoutException('after 30s'), action: 'sync'),
        allOf(contains('took too long'), isNot(contains('30s'))),
      );

      expect(
        beanFriendlyErrorMessage(
          PlatformException(
            code: 'channel-error',
            message: 'Unable to establish connection on channel',
          ),
          action: 'open that link',
        ),
        allOf(contains('on this device'), isNot(contains('channel-error'))),
      );
    },
  );

  test('chat failures stay recoverable and hide status codes', () {
    final message = beanFriendlyChatFailureMessage(
      const HermesApiException(429, '{"message":"Too Many Attempts."}'),
    );

    expect(message, contains('I’m still checking that request.'));
    expect(message, isNot(contains('429')));
    expect(message, isNot(contains('HermesApiException')));
  });
}
