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

      expect(
        message,
        contains(
          'Bean is checking the latest app state while trying to save that task.',
        ),
      );
      expect(message, isNot(contains('Bean could not')));
      expect(message, contains('reconnecting on our side'));
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

    expect(
      message,
      contains(
        'Bean is checking the latest app state while trying to create your account.',
      ),
    );
    expect(message, isNot(contains('Bean could not')));
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
          contains(
            'Bean is checking the latest app state while trying to refresh your latest data.',
          ),
          contains('keep checking once the connection is back'),
          isNot(contains('Bean could not')),
          isNot(contains('10.0.2.2')),
        ),
      );

      expect(
        beanFriendlyErrorMessage(TimeoutException('after 30s'), action: 'sync'),
        allOf(contains('took longer than expected'), isNot(contains('30s'))),
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

  test('usage limit messages are shown directly without generic snag copy', () {
    const body =
        '{"message":"This account has reached today\'s AI usage limit.","code":"bean_usage_limit"}';
    final message = beanFriendlyErrorMessage(
      const HermesApiException(429, body),
      action: 'start Bean chat',
    );

    expect(message, 'This account has reached today\'s AI usage limit.');
    expect(message, isNot(contains('Bean hit a snag')));
    expect(message, isNot(contains('429')));
    expect(message, isNot(contains('HermesApiException')));
  });

  test('queued Bean work recovers transient transport failures', () {
    final retryableErrors = [
      const HermesApiException(408, '{"message":"Timeout"}'),
      const HermesApiException(500, '{"message":"Server error"}'),
      const HermesApiException(502, '{"message":"Bad gateway"}'),
      const HermesApiException(503, '{"message":"Unavailable"}'),
      const HermesApiException(504, '{"message":"Gateway timeout"}'),
      const SocketException('offline'),
      TimeoutException('slow network'),
    ];

    for (final error in retryableErrors) {
      expect(beanShouldRecoverQueuedRequest(error), isTrue);
    }

    expect(
      beanShouldRecoverQueuedRequest(
        const HermesApiException(422, '{"message":"Invalid"}'),
      ),
      isFalse,
    );
    expect(
      beanShouldRecoverQueuedRequest(
        const HermesApiException(429, '{"message":"Limit"}'),
      ),
      isFalse,
    );
  });
}
