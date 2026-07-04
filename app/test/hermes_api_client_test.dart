import 'dart:convert';
import 'dart:io';

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

  test('default transport sends UTF-8 JSON request bodies', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    addTearDown(() => server.close(force: true));

    final requestFuture = server.first.then((request) async {
      expect(request.method, 'POST');
      expect(request.uri.path, '/api/assistant/sessions/42/messages');
      expect(
        request.headers.value(HttpHeaders.authorizationHeader),
        'Bearer t',
      );

      final body = await utf8.decoder.bind(request).join();
      expect(body, contains('I’m Harley'));
      expect(jsonDecode(body), {
        'content': 'I’m Harley',
        'metadata': {'source': 'flutter'},
      });

      request.response.headers.contentType = ContentType.json;
      request.response.write(
        jsonEncode({
          'data': {
            'status': 'completed',
            'session': {'id': 42, 'status': 'active'},
            'events': [],
          },
        }),
      );
      await request.response.close();
    });

    final client = HermesApiClient(
      baseUrl: Uri.parse('http://127.0.0.1:${server.port}/api'),
      bearerToken: 't',
    );

    final result = await client.sendMessage(
      sessionId: 42,
      content: 'I’m Harley',
      metadata: const {'source': 'flutter'},
    );
    await requestFuture;

    expect(result.status, 'completed');
  });

  test('branchMessage posts edited content to the branch endpoint', () async {
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        expect(request.method, 'POST');
        expect(request.path, '/assistant/sessions/42/messages/7001/branch');
        expect(request.body, {
          'content': 'Plan tomorrow',
          'metadata': {'source': 'flutter', 'edited_message_id': 7001},
        });
        return HermesApiResponse(
          201,
          jsonEncode({
            'data': {
              'status': 'completed',
              'session': {'id': 42, 'status': 'active'},
              'user_message': {
                'id': 7002,
                'role': 'user',
                'content': 'Plan tomorrow',
              },
              'assistant_message': {
                'id': 7003,
                'role': 'assistant',
                'content': 'Updated.',
              },
              'events': [],
            },
          }),
        );
      },
    );

    final result = await client.branchMessage(
      sessionId: 42,
      messageId: 7001,
      content: 'Plan tomorrow',
      metadata: const {'source': 'flutter', 'edited_message_id': 7001},
    );

    expect(result.status, 'completed');
    expect(result.userMessage?.id, 7002);
    expect(result.assistantMessage?.content, 'Updated.');
  });

  test('checks email availability before guided registration', () async {
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      transport: (request) async {
        expect(request.method, 'POST');
        expect(request.path, '/auth/email-availability');
        expect(request.headers.containsKey('Authorization'), isFalse);
        expect(request.body, {'email': 'Taken@Example.com '});
        return HermesApiResponse(
          200,
          jsonEncode({
            'data': {'email': 'taken@example.com', 'available': false},
          }),
        );
      },
    );

    final availability = await client.checkEmailAvailability(
      email: 'Taken@Example.com ',
    );

    expect(availability.email, 'taken@example.com');
    expect(availability.available, isFalse);
  });

  test('creates notes when empty metadata returns as a JSON array', () async {
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        expect(request.method, 'POST');
        expect(request.path, '/notes');
        return HermesApiResponse(
          201,
          jsonEncode({
            'data': {
              'id': 7,
              'title': 'New Note',
              'body_html': '',
              'plain_text': '',
              'metadata': [],
            },
          }),
        );
      },
    );

    final note = await client.createNote(metadata: const {});

    expect(note.id, 7);
    expect(note.metadata, isEmpty);
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

  test('polls dashboard changes for realtime refreshes', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        requests.add(request);
        expect(request.method, 'GET');
        expect(request.path, '/dashboard-changes?after=41&wait=0&limit=100');
        expect(request.headers['Authorization'], 'Bearer token-123');
        return HermesApiResponse(
          200,
          jsonEncode({
            'data': {
              'latest_id': 42,
              'changes': [
                {
                  'id': 42,
                  'user_id': 7,
                  'workspace_id': 3,
                  'resource_type': 'task',
                  'action': 'updated',
                  'resource_id': 9,
                  'payload': {
                    'title': 'Buy milk',
                    'changed_fields': ['status'],
                  },
                },
              ],
            },
          }),
        );
      },
    );

    final feed = await client.dashboardChanges(after: 41);

    expect(requests, hasLength(1));
    expect(feed.latestId, 42);
    expect(feed.changes.single.resourceType, 'task');
    expect(feed.changes.single.workspaceId, 3);
    expect(feed.changes.single.payload['title'], 'Buy milk');
  });

  test('records realtime usage and latency telemetry', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        requests.add(request);
        expect(request.headers['Authorization'], 'Bearer token-123');
        expect(request.method, 'POST');
        expect(request.path, '/assistant/realtime/usage');
        expect(request.body, {
          'session_id': 42,
          'model': 'gpt-realtime',
          'response_id': 'resp_123',
          'usage': {
            'input_tokens': 12,
            'output_tokens': 8,
            'input_token_details': {'audio_tokens': 6},
            'output_token_details': {'audio_tokens': 4},
          },
          'voice_seconds': 1.25,
          'transcript_to_response_create_ms': 180,
          'response_create_to_first_assistant_ms': 340,
          'transcript_to_first_assistant_ms': 520,
          'turn_completed_ms': 1250,
          'spoken_character_count': 38,
          'spoken_sentence_count': 1,
          'spoken_brevity_violation': false,
          'is_follow_up_turn': true,
          'is_contextual_follow_up_turn': true,
          'contextual_follow_up_kind': 'confirmation',
          'realtime_usage_missing': false,
          'tool_call_count': 1,
          'action_types': ['realtime_voice'],
        });
        return HermesApiResponse(
          201,
          jsonEncode({
            'data': {'ok': true},
          }),
        );
      },
    );

    await client.recordRealtimeUsage(
      sessionId: 42,
      model: 'gpt-realtime',
      responseId: 'resp_123',
      usage: {
        'input_tokens': 12,
        'output_tokens': 8,
        'input_token_details': {'audio_tokens': 6},
        'output_token_details': {'audio_tokens': 4},
      },
      voiceSeconds: 1.25,
      latencyMetrics: const {
        'transcript_to_response_create_ms': 180,
        'response_create_to_first_assistant_ms': 340,
        'transcript_to_first_assistant_ms': 520,
        'turn_completed_ms': 1250,
      },
      speechMetrics: const {
        'spoken_character_count': 38,
        'spoken_sentence_count': 1,
        'spoken_brevity_violation': false,
      },
      turnMetrics: const {
        'is_follow_up_turn': true,
        'is_contextual_follow_up_turn': true,
        'contextual_follow_up_kind': 'confirmation',
        'realtime_usage_missing': false,
      },
      toolCallCount: 1,
      actionTypes: const ['realtime_voice'],
    );

    expect(requests, hasLength(1));
  });

  test('fetches realtime voice quality benchmark telemetry', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        requests.add(request);
        expect(request.headers['Authorization'], 'Bearer token-123');
        expect(request.method, 'GET');
        expect(
          request.path,
          '/assistant/realtime/quality?days=7&session_id=42&workspace_id=3',
        );
        return HermesApiResponse(
          200,
          jsonEncode({
            'data': {
              'status': 'world_class',
              'benchmark': 'siri_alexa_voice_responsiveness',
              'metrics': {
                'transcript_to_first_assistant_ms': {'p50_ms': 520},
              },
            },
          }),
        );
      },
    );

    final quality = await client.realtimeVoiceQuality(
      sessionId: 42,
      workspaceId: 3,
    );

    expect(quality['status'], 'world_class');
    expect(quality['benchmark'], 'siri_alexa_voice_responsiveness');
    expect(requests, hasLength(1));
  });

  test('cancels assistant runs for voice background work', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        requests.add(request);
        expect(request.headers['Authorization'], 'Bearer token-123');
        expect(request.method, 'POST');
        expect(request.path, '/assistant/runs/77/cancel');
        return HermesApiResponse(
          202,
          jsonEncode({
            'data': {'id': 77, 'status': 'cancelled', 'source': 'realtime'},
          }),
        );
      },
    );

    final run = await client.cancelAssistantRun(77);

    expect(run.id, 77);
    expect(run.status, 'cancelled');
    expect(run.source, 'realtime');
    expect(requests, hasLength(1));
  });

  test('polls assistant activity events incrementally', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        requests.add(request);
        expect(request.method, 'GET');
        expect(
          request.path,
          '/assistant/sessions/42/events?after=10&wait=2&limit=25',
        );
        expect(request.headers['Authorization'], 'Bearer token-123');
        return HermesApiResponse(
          200,
          jsonEncode({
            'data': [
              {
                'id': 11,
                'event_type': 'assistant.task.created',
                'status': 'succeeded',
              },
            ],
          }),
        );
      },
    );

    final events = await client.pollActivityEvents(
      42,
      after: 10,
      waitSeconds: 2,
      limit: 25,
    );

    expect(requests, hasLength(1));
    expect(events.single.id, 11);
    expect(events.single.eventType, 'assistant.task.created');
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

  test('creates checkout sessions for trial plans', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'register-token',
      transport: (request) async {
        requests.add(request);
        expect(request.method, 'POST');
        expect(request.path, '/billing/checkout-sessions');
        expect(request.headers['Authorization'], 'Bearer register-token');
        expect(request.body, {
          'plan': 'premium',
          'billing_interval': 'monthly',
          'source': 'flutter',
        });
        return HermesApiResponse(
          200,
          jsonEncode({
            'data': {
              'id': 'cs_test_123',
              'url': 'https://checkout.stripe.com/c/pay/cs_test_123',
              'plan': 'premium',
              'status': 'open',
            },
          }),
        );
      },
    );

    final result = await client.createCheckoutSession(plan: 'premium');

    expect(result.id, 'cs_test_123');
    expect(result.url, 'https://checkout.stripe.com/c/pay/cs_test_123');
    expect(result.plan, 'premium');
    expect(requests.single.body, {
      'plan': 'premium',
      'billing_interval': 'monthly',
      'source': 'flutter',
    });
    expect(requests, hasLength(1));
  });

  test('creates and confirms mobile subscription setup', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'register-token',
      transport: (request) async {
        requests.add(request);
        expect(request.headers['Authorization'], 'Bearer register-token');
        if (request.path == '/billing/mobile-subscriptions/setup') {
          expect(request.method, 'POST');
          expect(request.body, {
            'plan': 'premium',
            'billing_interval': 'monthly',
          });
          return HermesApiResponse(
            201,
            jsonEncode({
              'data': {
                'publishable_key': 'pk_test_123',
                'customer_id': 'cus_test_123',
                'customer_ephemeral_key_secret': 'ek_test_123',
                'setup_intent_id': 'seti_test_123',
                'setup_intent_client_secret': 'seti_test_123_secret',
                'plan': 'premium',
                'billing_interval': 'monthly',
              },
            }),
          );
        }
        expect(request.path, '/billing/mobile-subscriptions/confirm');
        expect(request.method, 'POST');
        expect(request.body, {
          'plan': 'premium',
          'billing_interval': 'monthly',
          'setup_intent_id': 'seti_test_123',
        });
        return HermesApiResponse(
          200,
          jsonEncode({
            'data': {
              'plan': 'premium',
              'billing_interval': 'monthly',
              'subscription': {
                'tier': 'premium',
                'status': 'trialing',
                'cancel_at_period_end': false,
                'can_upgrade': true,
              },
              'payment_method': {
                'type': 'card',
                'brand': 'visa',
                'last4': '4242',
                'exp_month': 12,
                'exp_year': 2032,
              },
            },
          }),
        );
      },
    );

    final setup = await client.createMobileSubscriptionSetup(plan: 'premium');
    expect(setup.publishableKey, 'pk_test_123');
    expect(setup.setupIntentId, 'seti_test_123');

    final result = await client.confirmMobileSubscription(
      plan: 'premium',
      setupIntentId: setup.setupIntentId,
    );
    expect(result.subscription.tier, 'premium');
    expect(result.subscription.status, 'trialing');
    expect(
      result.paymentMethod?.displayLine,
      'Visa ending 4242 • expires 12/2032',
    );
    expect(requests.map((request) => request.path), [
      '/billing/mobile-subscriptions/setup',
      '/billing/mobile-subscriptions/confirm',
    ]);
  });

  test(
    'loads updates cancels and resumes billing payment method state',
    () async {
      final requests = <HermesApiRequest>[];
      final client = HermesApiClient(
        baseUrl: Uri.parse('http://local.test/api'),
        bearerToken: 'token-123',
        transport: (request) async {
          requests.add(request);
          switch (request.path) {
            case '/billing/payment-method':
              expect(request.method, 'GET');
              return HermesApiResponse(
                200,
                jsonEncode({
                  'data': {
                    'payment_method': {
                      'brand': 'mastercard',
                      'last4': '4444',
                      'exp_month': 10,
                      'exp_year': 2031,
                    },
                  },
                }),
              );
            case '/billing/payment-method/setup':
              expect(request.method, 'POST');
              return HermesApiResponse(
                201,
                jsonEncode({
                  'data': {
                    'publishable_key': 'pk_test_123',
                    'customer_id': 'cus_test_123',
                    'customer_ephemeral_key_secret': 'ek_test_123',
                    'setup_intent_id': 'seti_update_123',
                    'setup_intent_client_secret': 'seti_update_123_secret',
                  },
                }),
              );
            case '/billing/payment-method/confirm':
              expect(request.method, 'POST');
              expect(request.body, {'setup_intent_id': 'seti_update_123'});
              return HermesApiResponse(
                200,
                jsonEncode({
                  'data': {
                    'payment_method': {
                      'brand': 'visa',
                      'last4': '4242',
                      'exp_month': 12,
                      'exp_year': 2032,
                    },
                  },
                }),
              );
            case '/billing/subscription':
              expect(request.method, 'GET');
              expect(request.body, isNull);
              return HermesApiResponse(
                200,
                jsonEncode({
                  'data': {
                    'tier': 'premium',
                    'status': 'active',
                    'current_period_end': '2026-06-25T00:00:00+00:00',
                    'cancel_at_period_end': false,
                    'can_cancel': true,
                    'can_resume': false,
                  },
                }),
              );
            case '/billing/subscription/cancel':
              expect(request.method, 'POST');
              expect(request.body, isNull);
              return HermesApiResponse(
                200,
                jsonEncode({
                  'data': {
                    'subscription': {
                      'tier': 'premium',
                      'status': 'active',
                      'current_period_end': '2026-06-25T00:00:00+00:00',
                      'access_ends_at': '2026-06-25T00:00:00+00:00',
                      'cancel_at_period_end': true,
                      'can_upgrade': true,
                      'can_cancel': false,
                      'can_resume': true,
                    },
                  },
                }),
              );
            case '/billing/subscription/resume':
              expect(request.method, 'POST');
              expect(request.body, isNull);
              return HermesApiResponse(
                200,
                jsonEncode({
                  'data': {
                    'subscription': {
                      'tier': 'premium',
                      'status': 'active',
                      'current_period_end': '2026-06-25T00:00:00+00:00',
                      'cancel_at_period_end': false,
                      'can_upgrade': true,
                      'can_cancel': true,
                      'can_resume': false,
                    },
                  },
                }),
              );
          }
          fail('Unexpected request ${request.method} ${request.path}');
        },
      );

      final existing = await client.getBillingPaymentMethod();
      expect(existing?.displayLine, 'Mastercard ending 4444 • expires 10/2031');

      final setup = await client.createPaymentMethodSetup();
      final updated = await client.confirmPaymentMethodSetup(
        setupIntentId: setup.setupIntentId,
      );
      expect(updated?.displayLine, 'Visa ending 4242 • expires 12/2032');

      final summary = await client.getSubscriptionSummary();
      expect(summary.tier, 'premium');
      expect(summary.canCancel, isTrue);
      expect(summary.canResume, isFalse);

      final subscription = await client.cancelSubscription();
      expect(subscription.cancelAtPeriodEnd, isTrue);
      expect(subscription.accessEndsAt, '2026-06-25T00:00:00+00:00');
      expect(subscription.canCancel, isFalse);
      expect(subscription.canResume, isTrue);

      final resumed = await client.resumeSubscription();
      expect(resumed.cancelAtPeriodEnd, isFalse);
      expect(resumed.canCancel, isTrue);
      expect(resumed.canResume, isFalse);

      expect(requests.map((request) => request.path), [
        '/billing/payment-method',
        '/billing/payment-method/setup',
        '/billing/payment-method/confirm',
        '/billing/subscription',
        '/billing/subscription/cancel',
        '/billing/subscription/resume',
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

  test('updates home city through auth profile update', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        requests.add(request);
        expect(request.method, 'PATCH');
        expect(request.path, '/auth/me');
        expect(request.headers['Authorization'], 'Bearer token-123');
        expect(request.body, {'home_city': 'Orlando, FL'});
        return HermesApiResponse(
          200,
          jsonEncode({
            'data': {
              'id': 9,
              'name': 'Bean User',
              'email': 'bean@example.com',
              'home_city': 'Orlando, FL',
            },
          }),
        );
      },
    );

    final user = await client.updateMe(homeCity: 'Orlando, FL');

    expect(user.homeCity, 'Orlando, FL');
    expect(requests, hasLength(1));
  });

  test('registers and unregisters push notification tokens', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        requests.add(request);
        expect(request.headers['Authorization'], 'Bearer token-123');
        if (request.method == 'POST') {
          expect(request.path, '/push-notification-tokens');
          expect(request.body, {
            'token': 'fcm-token',
            'platform': 'ios',
            'device_id': 'device-1',
            'app_version': '1.0.3',
          });
          return HermesApiResponse(
            201,
            jsonEncode({
              'data': {'id': 1, 'token': 'fcm-token'},
            }),
          );
        }

        expect(request.method, 'DELETE');
        expect(request.path, '/push-notification-tokens');
        expect(request.body, {'token': 'fcm-token'});
        return const HermesApiResponse(204, '');
      },
    );

    await client.registerPushNotificationToken(
      token: 'fcm-token',
      platform: 'ios',
      deviceId: 'device-1',
      appVersion: '1.0.3',
    );
    await client.unregisterPushNotificationToken('fcm-token');

    expect(requests, hasLength(2));
  });

  test('updates user theme through auth profile update', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        requests.add(request);
        expect(request.method, 'PATCH');
        expect(request.path, '/auth/me');
        expect(request.headers['Authorization'], 'Bearer token-123');
        expect(request.body, {'theme': 'purple', 'theme_mode': 'dark'});
        return HermesApiResponse(
          200,
          jsonEncode({
            'data': {
              'id': 9,
              'name': 'Bean User',
              'email': 'bean@example.com',
              'theme': 'purple',
              'theme_mode': 'dark',
            },
          }),
        );
      },
    );

    final user = await client.updateMe(theme: 'purple', themeMode: 'dark');

    expect(user.theme, 'purple');
    expect(user.themeMode, 'dark');
    expect(requests, hasLength(1));
  });

  test('updates command center label through auth profile update', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        requests.add(request);
        expect(request.method, 'PATCH');
        expect(request.path, '/auth/me');
        expect(request.body, {'command_center_label': 'HQ'});
        return HermesApiResponse(
          200,
          jsonEncode({
            'data': {
              'id': 9,
              'name': 'Bean User',
              'email': 'bean@example.com',
              'command_center_label': 'HQ',
            },
          }),
        );
      },
    );

    final user = await client.updateMe(commandCenterLabel: 'HQ');

    expect(user.commandCenterLabel, 'HQ');
    expect(requests, hasLength(1));
  });

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

  test(
    'ignores source recurrence metadata on generated calendar occurrences',
    () {
      final event = HermesCalendarEvent.fromJson({
        'id': 4,
        'title': 'Workout',
        'starts_at': '2026-06-22T05:00:00Z',
        'ends_at': '2026-06-22T06:00:00Z',
        'recurrence': null,
        'metadata': {
          'recurrence': 'daily',
          'recurrence_generated': true,
          'recurrence_parent_event_id': 3,
          'recurrence_occurrence_date': '2026-06-22',
        },
      });

      expect(event.recurrence, isNull);
      expect(event.metadata?['recurrence'], 'daily');
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
          expect(
            request.path,
            '/calendar-events?sync_external_calendars_now=1',
          );
          expect(request.headers['Authorization'], 'Bearer token-123');
          expect(request.body, {
            'title': 'Client kickoff',
            'starts_at': '2026-05-20T15:00:00Z',
            'ends_at': '2026-05-20T16:00:00Z',
            'description': 'Bring the client brief.',
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
                'description': 'Bring the client brief.',
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
        notes: 'Bring the client brief.',
        category: 'Work',
        color: '#007AFF',
        recurrence: 'none',
        isCritical: false,
        metadata: {'google_calendar_id': 'work@example.com'},
      );

      expect(event.id, 77);
      expect(event.notes, 'Bring the client brief.');
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

  test('supports Bean memory endpoints and parses memory models', () async {
    final requests = <HermesApiRequest>[];
    final client = HermesApiClient(
      baseUrl: Uri.parse('http://local.test/api'),
      bearerToken: 'token-123',
      transport: (request) async {
        requests.add(request);
        expect(request.headers['Authorization'], 'Bearer token-123');

        if (request.method == 'GET' &&
            request.path == '/memory-items?query=miata&type=project&limit=5') {
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': [
                {
                  'id': 3,
                  'type': 'project',
                  'title': 'Miata',
                  'content': 'Joshua is working on the Miata.',
                  'status': 'active',
                  'confidence': 92,
                  'importance': 80,
                  'updated_at': '2026-06-20T12:00:00Z',
                },
              ],
            }),
          );
        }

        if (request.method == 'POST' && request.path == '/memory-items') {
          expect(request.body, {
            'content': 'Prefers short morning briefings.',
            'type': 'preference',
            'title': 'Briefings',
            'confidence': 95,
            'importance': 75,
            'metadata': {'source': 'flutter_memory_screen'},
          });
          return HermesApiResponse(
            201,
            jsonEncode({
              'data': {
                'id': 4,
                'type': 'preference',
                'title': 'Briefings',
                'content': 'Prefers short morning briefings.',
              },
            }),
          );
        }

        if (request.method == 'PATCH' && request.path == '/memory-items/4') {
          expect(request.body, {
            'content': 'Prefers concise morning briefings.',
            'type': 'preference',
            'title': 'Briefings',
          });
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': {
                'id': 4,
                'type': 'preference',
                'title': 'Briefings',
                'content': 'Prefers concise morning briefings.',
                'confidence': 95,
              },
            }),
          );
        }

        if (request.method == 'GET' && request.path == '/memory-summaries') {
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': [
                {
                  'id': 8,
                  'title': 'Daily memory activity',
                  'summary': 'Bean captured one durable preference.',
                  'summary_type': 'daily',
                  'period_key': '2026-06-20',
                },
              ],
            }),
          );
        }

        if (request.method == 'GET' &&
            request.path == '/memory/request-history?limit=10') {
          return HermesApiResponse(
            200,
            jsonEncode({
              'data': [
                {
                  'id': 9,
                  'content': 'What did I ask yesterday?',
                  'assistant_reply': 'You asked about memory.',
                  'created_at': '2026-06-20T12:05:00Z',
                },
              ],
            }),
          );
        }

        if (request.method == 'DELETE' && request.path == '/memory-items/4') {
          return HermesApiResponse(204, '');
        }

        throw StateError(
          'Unexpected request: ${request.method} ${request.path}',
        );
      },
    );

    final items = await client.listMemoryItems(
      query: 'miata',
      type: 'project',
      limit: 5,
    );
    expect(items.single.title, 'Miata');
    expect(items.single.importance, 80);

    final created = await client.createMemoryItem(
      content: 'Prefers short morning briefings.',
      type: 'preference',
      title: 'Briefings',
    );
    expect(created.type, 'preference');

    final updated = await client.updateMemoryItem(
      created.id,
      content: 'Prefers concise morning briefings.',
      type: 'preference',
      title: 'Briefings',
    );
    expect(updated.content, 'Prefers concise morning briefings.');
    expect(updated.confidence, 95);

    final summaries = await client.listMemorySummaries();
    expect(summaries.single.content, 'Bean captured one durable preference.');

    final history = await client.listRequestHistory();
    expect(history.single.assistantReply, 'You asked about memory.');

    await client.deleteMemoryItem(created.id);

    expect(requests.map((request) => '${request.method} ${request.path}'), [
      'GET /memory-items?query=miata&type=project&limit=5',
      'POST /memory-items',
      'PATCH /memory-items/4',
      'GET /memory-summaries',
      'GET /memory/request-history?limit=10',
      'DELETE /memory-items/4',
    ]);
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
          if (request.path ==
                  '/calendar-events?sync_external_calendars_now=1' &&
              request.method == 'POST') {
            expect(request.body, containsPair('workspace_id', 1));
            expect(request.body, containsPair('sync_to_workspace_ids', [2]));
            expect(request.body, containsPair('location', 'Conference Room B'));
            expect(request.body, containsPair('status', 'tentative'));
            return HermesApiResponse(
              201,
              jsonEncode({
                'data': {'id': 3, 'title': 'Meet'},
              }),
            );
          }
          if (request.path ==
                  '/calendar-events/3?sync_external_calendars_now=1' &&
              request.method == 'PATCH') {
            expect(request.body, containsPair('sync_to_workspace_ids', [2, 3]));
            expect(request.body, containsPair('location', 'Main Hall'));
            expect(request.body, containsPair('status', 'confirmed'));
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {'id': 3, 'title': 'Meet'},
              }),
            );
          }
          if (request.path == '/notes' && request.method == 'POST') {
            expect(request.body, containsPair('sync_to_workspace_ids', [2]));
            return HermesApiResponse(
              201,
              jsonEncode({
                'data': {'id': 5, 'title': 'Plan'},
              }),
            );
          }
          if (request.path == '/notes/5' && request.method == 'PATCH') {
            expect(request.body, containsPair('sync_to_workspace_ids', [2, 3]));
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {'id': 5, 'title': 'Plan'},
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
        location: 'Conference Room B',
        status: 'tentative',
        workspaceId: 1,
        syncToWorkspaceIds: [2],
      );
      await client.updateCalendarEvent(
        3,
        title: 'Meet',
        startsAt: '2026-05-20T10:00:00Z',
        location: 'Main Hall',
        status: 'confirmed',
        syncToWorkspaceIds: [2, 3],
      );
      await client.createNote(title: 'Plan', syncToWorkspaceIds: [2]);
      await client.updateNote(5, syncToWorkspaceIds: [2, 3]);
      await client.deleteTask(1, deleteFromWorkspaceIds: [1, 2, 3]);
      await client.deleteReminder(2, deleteFromWorkspaceIds: [1, 3]);
      await client.deleteEventCategory(4, deleteFromWorkspaceIds: [2, 3]);
      await client.deleteCalendarEvent(
        3,
        deleteFromWorkspaceIds: [1, 2],
        recurringDeleteMode: 'single',
        recurringOccurrenceDate: '2026-05-27',
      );

      expect(requests, hasLength(12));
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
          if (request.path ==
                  '/calendar-events/3?sync_external_calendars_now=1' &&
              request.method == 'PATCH') {
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {'id': 3, 'title': 'Event'},
              }),
            );
          }
          if (request.path == '/notes/4' && request.method == 'PATCH') {
            return HermesApiResponse(
              200,
              jsonEncode({
                'data': {'id': 4, 'title': 'Note'},
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
      await client.updateNote(4, syncToWorkspaceIds: const []);

      expect(requests[0].body, containsPair('sync_to_workspace_ids', const []));
      expect(requests[1].body, containsPair('sync_to_workspace_ids', const []));
      expect(requests[2].body, containsPair('sync_to_workspace_ids', const []));
      expect(requests[3].body, containsPair('sync_to_workspace_ids', const []));
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
