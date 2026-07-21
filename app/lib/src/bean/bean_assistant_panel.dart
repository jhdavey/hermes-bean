part of '../../main.dart';

class _BeanAssistantPanel extends StatefulWidget {
  const _BeanAssistantPanel({
    super.key,
    required this.messages,
    required this.confirmations,
    required this.sending,
    required this.error,
    required this.onSend,
    required this.onConfirm,
    required this.onCreateElevenLabsSession,
    required this.onElevenLabsSessionReady,
    required this.onAskBeanFromElevenLabsAgent,
    required this.onVoiceEvent,
    this.userId,
    this.workspaceId,
    this.clientTimezone,
  });

  final List<BeanAssistantMessage> messages;
  final List<BeanAssistantConfirmation> confirmations;
  final bool sending;
  final String? error;
  final Future<void> Function(String message, {String? source}) onSend;
  final Future<void> Function(BeanAssistantConfirmation confirmation) onConfirm;
  final Future<BeanRealtimeSession> Function() onCreateElevenLabsSession;
  final ValueChanged<BeanRealtimeSession> onElevenLabsSessionReady;
  final Future<String> Function(Map<String, dynamic> parameters)
  onAskBeanFromElevenLabsAgent;
  final Future<void> Function(
    String eventType, {
    String? label,
    Map<String, Object?>? payload,
  })
  onVoiceEvent;
  final int? userId;
  final int? workspaceId;
  final String? clientTimezone;

  @override
  State<_BeanAssistantPanel> createState() => _BeanAssistantPanelState();
}

class _BeanAssistantPanelState extends State<_BeanAssistantPanel> {
  final TextEditingController _controller = TextEditingController();
  elevenlabs.ConversationClient? _elevenLabsClient;
  Timer? _voiceIdleDisconnectTimer;
  bool _voiceConnecting = false;
  bool _voicePushToTalkHeld = false;
  String _voiceStatusText = 'Hold mic to talk to Bean';
  String? _voiceError;
  String? _voiceTranscript;
  int _voiceRequestCount = 0;

  bool get _voiceActive {
    final status = _elevenLabsClient?.status;
    return _voiceConnecting ||
        status == elevenlabs.ConversationStatus.connected ||
        status == elevenlabs.ConversationStatus.connecting;
  }

  bool get _voiceConnected =>
      _elevenLabsClient?.status == elevenlabs.ConversationStatus.connected;

  String _voiceReadyStatusText() {
    final client = _elevenLabsClient;
    if (_voiceConnecting) return 'Connecting ElevenLabs voice…';
    if (client?.isSpeaking == true) return 'Speaking…';
    if (_voicePushToTalkHeld && client?.isMuted == false) {
      return 'Listening — release to send';
    }
    if (_voiceConnected) return 'Hold mic to talk again';
    return 'Hold mic to talk to Bean';
  }

  @override
  void dispose() {
    _voiceIdleDisconnectTimer?.cancel();
    unawaited(_elevenLabsClient?.endSession());
    _elevenLabsClient?.dispose();
    _controller.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final text = _controller.text.trim();
    if (text.isEmpty || widget.sending) return;
    _controller.clear();
    await widget.onSend(text);
  }

  elevenlabs.ConversationClient _ensureElevenLabsClient() {
    final existing = _elevenLabsClient;
    if (existing != null) return existing;

    final client = elevenlabs.ConversationClient(
      clientTools: {
        'askBean': _AskBeanClientTool(widget.onAskBeanFromElevenLabsAgent),
      },
      callbacks: elevenlabs.ConversationCallbacks(
        onConnect: ({required conversationId}) {
          if (!mounted) return;
          setState(() {
            _voiceConnecting = false;
            _voiceError = null;
            _voiceStatusText = _voiceReadyStatusText();
          });
          unawaited(
            widget.onVoiceEvent(
              'voice_session_started',
              payload: {
                'transport': 'elevenlabs_agent',
                'conversation_id': conversationId,
              },
            ),
          );
        },
        onDisconnect: (details) {
          if (!mounted) return;
          setState(() {
            _voiceConnecting = false;
            _voicePushToTalkHeld = false;
            _voiceTranscript = null;
            _voiceStatusText = 'Hold mic to talk to Bean';
          });
          unawaited(
            widget.onVoiceEvent(
              'voice_session_closed',
              payload: {
                'transport': 'elevenlabs_agent',
                'reason': details.reason,
              },
            ),
          );
        },
        onStatusChange: ({required status}) {
          if (!mounted) return;
          setState(() {
            _voiceStatusText = switch (status) {
              elevenlabs.ConversationStatus.connecting =>
                'Connecting ElevenLabs voice…',
              elevenlabs.ConversationStatus.connected =>
                _voiceReadyStatusText(),
              elevenlabs.ConversationStatus.disconnecting =>
                'Ending voice session…',
              elevenlabs.ConversationStatus.disconnected =>
                'Hold mic to talk to Bean',
            };
          });
        },
        onModeChange: ({required mode}) {
          if (!mounted) return;
          setState(() {
            _voiceStatusText = mode == elevenlabs.ConversationMode.speaking
                ? 'Speaking…'
                : _voiceReadyStatusText();
          });
          if (mode == elevenlabs.ConversationMode.speaking) {
            unawaited(
              widget.onVoiceEvent(
                'assistant_speech_started',
                payload: {'transport': 'elevenlabs_agent'},
              ),
            );
          } else if (_voiceRequestCount > 0) {
            unawaited(
              widget.onVoiceEvent(
                'followup_window_opened',
                payload: {'transport': 'elevenlabs_agent'},
              ),
            );
          }
        },
        onMessage: ({required message, required source}) {
          final content = message.trim();
          if (content.isEmpty) return;
          if (source == elevenlabs.Role.user) {
            _voiceRequestCount += 1;
            if (mounted) {
              setState(() {
                _voiceTranscript = content;
                _voiceStatusText = 'Thinking…';
              });
            }
            unawaited(
              widget.onVoiceEvent(
                _voiceRequestCount > 1
                    ? 'followup_transcript_received'
                    : 'user_transcript_received',
                label: content,
                payload: {'transport': 'elevenlabs_agent'},
              ),
            );
            return;
          }
          if (source == elevenlabs.Role.ai) {
            if (mounted) {
              setState(() {
                _voiceTranscript = null;
                _voiceStatusText = 'Speaking…';
              });
            }
            unawaited(
              widget.onVoiceEvent(
                'bean_response_received',
                label: content,
                payload: {
                  'transport': 'elevenlabs_agent',
                  'agent_managed_response': true,
                },
              ),
            );
          }
        },
        onAgentToolRequest: ({required toolName, required toolCallId}) {
          unawaited(
            widget.onVoiceEvent(
              'bean_request_sent',
              label: toolName,
              payload: {
                'transport': 'elevenlabs_agent',
                'tool_call_id': toolCallId,
              },
            ),
          );
        },
        onAgentToolResponse: (response) {
          unawaited(
            widget.onVoiceEvent(
              'bean_response_received',
              label: response.toolName,
              payload: {
                'transport': 'elevenlabs_agent',
                'tool_call_id': response.toolCallId,
                'tool_type': response.toolType,
                'is_error': response.isError,
              },
            ),
          );
        },
        onInterruption: (_) {
          unawaited(
            widget.onVoiceEvent(
              'voice_interruption',
              payload: {'transport': 'elevenlabs_agent'},
            ),
          );
        },
        onError: (message, [context]) {
          if (!mounted) return;
          setState(() {
            _voiceConnecting = false;
            _voicePushToTalkHeld = false;
            _voiceError = message;
            _voiceStatusText = 'Voice hit a problem';
          });
          unawaited(
            widget.onVoiceEvent(
              'voice_session_error',
              label: message,
              payload: {
                'transport': 'elevenlabs_agent',
                'context': context?.toString(),
              },
            ),
          );
        },
      ),
    );
    client.addListener(() {
      if (mounted) setState(() {});
    });
    _elevenLabsClient = client;
    return client;
  }

  Future<void> _startElevenLabsPushToTalk() async {
    if (widget.sending) return;
    _voiceIdleDisconnectTimer?.cancel();

    final client = _ensureElevenLabsClient();
    if (_voicePushToTalkHeld) return;

    setState(() {
      _voicePushToTalkHeld = true;
      _voiceError = null;
      _voiceTranscript = null;
      _voiceStatusText = _voiceConnected
          ? 'Listening — release to send'
          : 'Connecting ElevenLabs voice…';
    });

    if (_voiceConnected) {
      await client.setMicMuted(false);
      if (!mounted) return;
      setState(() => _voiceStatusText = _voiceReadyStatusText());
      unawaited(
        widget.onVoiceEvent(
          'push_to_talk_started',
          payload: {'transport': 'elevenlabs_agent'},
        ),
      );
      return;
    }

    if (_voiceConnecting) return;

    setState(() => _voiceConnecting = true);

    try {
      final permission = await permissions.Permission.microphone.request();
      if (!permission.isGranted) {
        throw StateError('Microphone permission is required for Bean voice.');
      }

      final realtime = await widget.onCreateElevenLabsSession();
      if ((realtime.token ?? '').trim().isEmpty ||
          realtime.transport != 'elevenlabs_agent') {
        throw StateError(
          'ElevenLabs Agent session did not return credentials.',
        );
      }
      widget.onElevenLabsSessionReady(realtime);

      await client.startSession(
        conversationToken: realtime.token,
        userId: widget.userId == null ? null : 'bean-user-${widget.userId}',
        dynamicVariables: {
          'bean_session_id': realtime.beanSessionId ?? 0,
          'bean_client_timezone': widget.clientTimezone ?? '',
          'bean_workspace_id': widget.workspaceId ?? 0,
          'bean_dashboard_context': jsonEncode(realtime.dashboardContext),
        },
      );

      await client.setMicMuted(!_voicePushToTalkHeld);
      if (!mounted) return;
      setState(() {
        _voiceConnecting = false;
        _voiceStatusText = _voiceReadyStatusText();
      });
      if (_voicePushToTalkHeld) {
        unawaited(
          widget.onVoiceEvent(
            'push_to_talk_started',
            payload: {'transport': 'elevenlabs_agent'},
          ),
        );
      } else {
        _scheduleVoiceIdleDisconnect();
      }
    } catch (error) {
      await client.endSession();
      if (!mounted) return;
      setState(() {
        _voiceConnecting = false;
        _voicePushToTalkHeld = false;
        _voiceError = beanFriendlyErrorMessage(
          error,
          action: 'start Bean voice',
        );
        _voiceStatusText = 'Voice hit a problem';
      });
      unawaited(
        widget.onVoiceEvent(
          'voice_session_error',
          label: error.toString(),
          payload: {'transport': 'elevenlabs_agent'},
        ),
      );
    }
  }

  Future<void> _stopElevenLabsPushToTalk({bool cancelled = false}) async {
    if (!_voicePushToTalkHeld && !_voiceConnecting) return;

    final client = _elevenLabsClient;
    setState(() {
      _voicePushToTalkHeld = false;
      _voiceStatusText = cancelled
          ? 'Voice cancelled — hold mic to try again'
          : _voiceConnected
          ? 'Released — Bean is thinking…'
          : 'Hold mic to talk to Bean';
    });

    if (client == null) return;
    if (_voiceConnected) {
      await client.setMicMuted(true);
      if (!mounted) return;
      setState(() {
        _voiceStatusText = cancelled
            ? 'Voice cancelled — hold mic to try again'
            : 'Released — Bean is thinking…';
      });
      unawaited(
        widget.onVoiceEvent(
          cancelled ? 'push_to_talk_cancelled' : 'push_to_talk_released',
          payload: {'transport': 'elevenlabs_agent'},
        ),
      );
      _scheduleVoiceIdleDisconnect();
    }
  }

  void _scheduleVoiceIdleDisconnect() {
    _voiceIdleDisconnectTimer?.cancel();
    _voiceIdleDisconnectTimer = Timer(const Duration(minutes: 2), () {
      final client = _elevenLabsClient;
      if (!mounted || client == null || _voicePushToTalkHeld) return;
      if (client.status != elevenlabs.ConversationStatus.connected) return;
      unawaited(client.endSession());
    });
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.viewInsetsOf(context).bottom;
    final messages = widget.messages;
    final voiceButtonEnabled = !widget.sending;
    final voiceButtonListening =
        _voicePushToTalkHeld &&
        _voiceConnected &&
        _elevenLabsClient?.isMuted == false;

    return Align(
      alignment: Alignment.bottomCenter,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        key: const Key('bean-assistant-panel'),
        margin: EdgeInsets.fromLTRB(
          14,
          0,
          14,
          104 + MediaQuery.paddingOf(context).bottom + bottomInset,
        ),
        constraints: const BoxConstraints(maxWidth: 720, maxHeight: 520),
        decoration: BoxDecoration(
          color: HeyBeanTheme.surface,
          borderRadius: BorderRadius.circular(28),
          border: Border.all(color: _quietBorderColor(alpha: .42)),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: .18),
              blurRadius: 28,
              offset: const Offset(0, 14),
            ),
          ],
        ),
        child: Material(
          color: Colors.transparent,
          borderRadius: BorderRadius.circular(28),
          clipBehavior: Clip.antiAlias,
          child: SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(14, 14, 14, 16),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  _BeanElevenLabsVoiceStatus(
                    active: _voiceActive,
                    connected: _voiceConnected,
                    speaking: _elevenLabsClient?.isSpeaking == true,
                    statusText: _voiceStatusText,
                    transcript: _voiceTranscript,
                    error: _voiceError,
                  ),
                  const SizedBox(height: 10),
                  Flexible(
                    child: Container(
                      width: double.infinity,
                      constraints: const BoxConstraints(minHeight: 150),
                      decoration: BoxDecoration(
                        color: HeyBeanTheme.bg0.withValues(alpha: .72),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(
                          color: _quietBorderColor(alpha: .32),
                        ),
                      ),
                      child: messages.isEmpty
                          ? Center(
                              child: Padding(
                                padding: const EdgeInsets.all(18),
                                child: Text(
                                  _voicePushToTalkHeld
                                      ? 'Speak naturally. Release the mic when you’re done and Bean will answer.'
                                      : 'Try “Create task call mom” or hold the mic for ElevenLabs voice.',
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    color: HeyBeanTheme.muted,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ),
                            )
                          : ListView.builder(
                              key: const Key('bean-assistant-messages'),
                              shrinkWrap: true,
                              padding: const EdgeInsets.all(12),
                              itemCount: messages.length,
                              itemBuilder: (context, index) {
                                final message = messages[index];
                                final isUser = message.role == 'user';
                                return Align(
                                  alignment: isUser
                                      ? Alignment.centerRight
                                      : Alignment.centerLeft,
                                  child: Container(
                                    margin: const EdgeInsets.symmetric(
                                      vertical: 4,
                                    ),
                                    padding: const EdgeInsets.symmetric(
                                      horizontal: 12,
                                      vertical: 9,
                                    ),
                                    constraints: const BoxConstraints(
                                      maxWidth: 520,
                                    ),
                                    decoration: BoxDecoration(
                                      color: isUser
                                          ? HeyBeanTheme.accentStrong
                                          : HeyBeanTheme.surface,
                                      borderRadius: BorderRadius.circular(16),
                                      border: Border.all(
                                        color: isUser
                                            ? HeyBeanTheme.accentStrong
                                            : _quietBorderColor(alpha: .36),
                                      ),
                                    ),
                                    child: Text(
                                      message.content,
                                      style: TextStyle(
                                        color: isUser
                                            ? Colors.white
                                            : HeyBeanTheme.text,
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                  ),
                                );
                              },
                            ),
                    ),
                  ),
                  if (widget.confirmations.isNotEmpty) ...[
                    const SizedBox(height: 10),
                    for (final confirmation in widget.confirmations)
                      Container(
                        key: Key('bean-confirmation-${confirmation.id}'),
                        width: double.infinity,
                        margin: const EdgeInsets.only(bottom: 8),
                        padding: const EdgeInsets.all(12),
                        decoration: _quietSurfaceDecoration(radius: 16),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              confirmation.summary ?? confirmation.action,
                              style: TextStyle(
                                color: HeyBeanTheme.text,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            const SizedBox(height: 8),
                            Align(
                              alignment: Alignment.centerRight,
                              child: FilledButton(
                                key: Key('bean-confirm-${confirmation.id}'),
                                onPressed: widget.sending
                                    ? null
                                    : () => unawaited(
                                        widget.onConfirm(confirmation),
                                      ),
                                child: const Text('Approve'),
                              ),
                            ),
                          ],
                        ),
                      ),
                  ],
                  if (widget.error != null) ...[
                    const SizedBox(height: 10),
                    Text(
                      widget.error!,
                      key: const Key('bean-assistant-error'),
                      style: const TextStyle(
                        color: Color(0xFFB42318),
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          key: const Key('bean-assistant-input'),
                          controller: _controller,
                          enabled: !widget.sending,
                          textInputAction: TextInputAction.send,
                          onSubmitted: (_) => _send(),
                          decoration: InputDecoration(
                            hintText: 'Message Bean...',
                            filled: true,
                            fillColor: HeyBeanTheme.bg0,
                            border: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(999),
                              borderSide: BorderSide(
                                color: _quietBorderColor(alpha: .34),
                              ),
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Listener(
                        onPointerDown: voiceButtonEnabled
                            ? (_) => unawaited(_startElevenLabsPushToTalk())
                            : null,
                        onPointerUp: voiceButtonEnabled
                            ? (_) => unawaited(_stopElevenLabsPushToTalk())
                            : null,
                        onPointerCancel: voiceButtonEnabled
                            ? (_) => unawaited(
                                _stopElevenLabsPushToTalk(cancelled: true),
                              )
                            : null,
                        child: Tooltip(
                          message: 'Hold to talk to Bean. Release to send.',
                          child: Semantics(
                            button: true,
                            label: 'Hold to talk to Bean',
                            hint:
                                'Press and hold to speak, then release to send',
                            child: AnimatedContainer(
                              key: const Key('bean-assistant-voice-input'),
                              duration: const Duration(milliseconds: 140),
                              width: 48,
                              height: 48,
                              decoration: BoxDecoration(
                                color: voiceButtonListening || _voiceConnecting
                                    ? HeyBeanTheme.accentStrong
                                    : HeyBeanTheme.accent.withValues(
                                        alpha: .16,
                                      ),
                                shape: BoxShape.circle,
                                border: Border.all(
                                  color:
                                      voiceButtonListening || _voiceConnecting
                                      ? HeyBeanTheme.accentStrong
                                      : _quietBorderColor(alpha: .38),
                                ),
                              ),
                              child: Icon(
                                voiceButtonListening
                                    ? Icons.mic_rounded
                                    : Icons.mic_none_rounded,
                                color: voiceButtonListening || _voiceConnecting
                                    ? Colors.white
                                    : HeyBeanTheme.accentStrong,
                              ),
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      FilledButton(
                        key: const Key('bean-assistant-send'),
                        onPressed: widget.sending ? null : _send,
                        child: widget.sending
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              )
                            : const Icon(Icons.arrow_upward_rounded),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _AskBeanClientTool implements elevenlabs.ClientTool {
  const _AskBeanClientTool(this.askBean);

  final Future<String> Function(Map<String, dynamic> parameters) askBean;

  @override
  Future<elevenlabs.ClientToolResult?> execute(
    Map<String, dynamic> parameters,
  ) async {
    try {
      final answer = await askBean(parameters);
      return elevenlabs.ClientToolResult.success(answer);
    } catch (_) {
      return elevenlabs.ClientToolResult.failure(
        'I hit a problem checking Bean. Please try that again.',
      );
    }
  }
}

class _BeanElevenLabsVoiceStatus extends StatelessWidget {
  const _BeanElevenLabsVoiceStatus({
    required this.active,
    required this.connected,
    required this.speaking,
    required this.statusText,
    this.transcript,
    this.error,
  });

  final bool active;
  final bool connected;
  final bool speaking;
  final String statusText;
  final String? transcript;
  final String? error;

  @override
  Widget build(BuildContext context) => Container(
    key: const Key('bean-elevenlabs-voice-status'),
    width: double.infinity,
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
    decoration: BoxDecoration(
      color: active
          ? HeyBeanTheme.accent.withValues(alpha: .12)
          : HeyBeanTheme.bg0.withValues(alpha: .84),
      borderRadius: BorderRadius.circular(18),
      border: Border.all(
        color: active
            ? HeyBeanTheme.accentStrong.withValues(alpha: .42)
            : _quietBorderColor(alpha: .32),
      ),
    ),
    child: Row(
      children: [
        Container(
          width: 34,
          height: 34,
          decoration: BoxDecoration(
            color: active ? HeyBeanTheme.accentStrong : HeyBeanTheme.surface,
            shape: BoxShape.circle,
            border: Border.all(color: _quietBorderColor(alpha: .28)),
          ),
          child: Icon(
            speaking
                ? Icons.graphic_eq_rounded
                : connected
                ? Icons.hearing_rounded
                : Icons.mic_none_rounded,
            color: active ? Colors.white : HeyBeanTheme.muted,
            size: 19,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                statusText,
                style: TextStyle(
                  color: error == null
                      ? HeyBeanTheme.text
                      : const Color(0xFFB42318),
                  fontWeight: FontWeight.w800,
                  fontSize: 13,
                ),
              ),
              if ((transcript ?? '').trim().isNotEmpty) ...[
                const SizedBox(height: 2),
                Text(
                  transcript!,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: HeyBeanTheme.muted,
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ] else if ((error ?? '').trim().isNotEmpty) ...[
                const SizedBox(height: 2),
                Text(
                  error!,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Color(0xFFB42318),
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ],
          ),
        ),
      ],
    ),
  );
}
