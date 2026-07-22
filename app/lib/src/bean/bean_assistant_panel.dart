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
    required this.onVoiceDockChanged,
    this.voiceDockOnly = false,
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
  final ValueChanged<_BeanDockStatusSnapshot> onVoiceDockChanged;
  final bool voiceDockOnly;
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
  bool _voiceAwaitingResponse = false;
  bool _voiceSpeaking = false;
  int _voiceRequestCount = 0;

  bool get _voiceConnected =>
      _elevenLabsClient?.status == elevenlabs.ConversationStatus.connected;

  void _publishVoiceDock(
    _BeanDockActivity activity, {
    String? label,
    String? detail,
  }) {
    widget.onVoiceDockChanged(
      _BeanDockStatusSnapshot(activity: activity, label: label, detail: detail),
    );
  }

  void _clearVoiceDock() =>
      widget.onVoiceDockChanged(_BeanDockStatusSnapshot.idle);

  void _publishVoiceReadyOrIdle() {
    if (_voicePushToTalkHeld) {
      _publishVoiceDock(
        _BeanDockActivity.listening,
        label: 'Listening',
        detail: 'Release to send',
      );
      return;
    }
    if (_voiceAwaitingResponse) {
      _publishVoiceDock(_BeanDockActivity.thinking, label: 'Thinking');
      return;
    }
    _clearVoiceDock();
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
    await widget.onSend(text, source: 'flutter_text');
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
          });
          _publishVoiceReadyOrIdle();
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
            _voiceAwaitingResponse = false;
            _voiceSpeaking = false;
          });
          _clearVoiceDock();
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
          switch (status) {
            case elevenlabs.ConversationStatus.connecting:
              _publishVoiceDock(
                _BeanDockActivity.thinking,
                label: 'Connecting',
                detail: 'Starting Bean voice',
              );
            case elevenlabs.ConversationStatus.connected:
              _publishVoiceReadyOrIdle();
            case elevenlabs.ConversationStatus.disconnecting:
              _publishVoiceDock(
                _BeanDockActivity.thinking,
                label: 'Ending voice',
              );
            case elevenlabs.ConversationStatus.disconnected:
              _clearVoiceDock();
          }
        },
        onModeChange: ({required mode}) {
          if (!mounted) return;
          final wasSpeaking = _voiceSpeaking;
          if (mode == elevenlabs.ConversationMode.speaking) {
            _voiceSpeaking = true;
            _voiceIdleDisconnectTimer?.cancel();
            _publishVoiceDock(_BeanDockActivity.speaking, label: 'Speaking');
            unawaited(
              widget.onVoiceEvent(
                'assistant_speech_started',
                payload: {'transport': 'elevenlabs_agent'},
              ),
            );
          } else if (_voiceAwaitingResponse) {
            _voiceSpeaking = false;
            _publishVoiceDock(_BeanDockActivity.thinking, label: 'Thinking');
          } else if (_voiceRequestCount > 0) {
            _voiceSpeaking = false;
            _publishVoiceReadyOrIdle();
            unawaited(
              widget.onVoiceEvent(
                'followup_window_opened',
                payload: {'transport': 'elevenlabs_agent'},
              ),
            );
            if (wasSpeaking && !_voicePushToTalkHeld) {
              _scheduleVoiceIdleDisconnect();
            }
          } else {
            _voiceSpeaking = false;
            _publishVoiceReadyOrIdle();
          }
        },
        onMessage: ({required message, required source}) {
          final content = message.trim();
          if (content.isEmpty) return;
          if (source == elevenlabs.Role.user) {
            _voiceRequestCount += 1;
            _voiceAwaitingResponse = true;
            _publishVoiceDock(
              _BeanDockActivity.thinking,
              label: 'Thinking',
              detail: content,
            );
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
            _voiceAwaitingResponse = false;
            _publishVoiceDock(_BeanDockActivity.speaking, label: 'Speaking');
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
          _voiceAwaitingResponse = true;
          _publishVoiceDock(
            _BeanDockActivity.working,
            label: 'Working',
            detail: toolName,
          );
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
          if (response.isError) {
            _publishVoiceDock(
              _BeanDockActivity.error,
              label: 'Voice needs attention',
            );
          } else {
            _publishVoiceDock(_BeanDockActivity.speaking, label: 'Speaking');
          }
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
            _voiceAwaitingResponse = false;
            _voiceSpeaking = false;
          });
          _publishVoiceDock(
            _BeanDockActivity.attention,
            label: 'Voice reset',
            detail: 'Hold Bean and try again',
          );
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
      _voiceAwaitingResponse = false;
    });
    _publishVoiceDock(
      _voiceConnected
          ? _BeanDockActivity.listening
          : _BeanDockActivity.thinking,
      label: _voiceConnected ? 'Listening' : 'Connecting',
      detail: _voiceConnected ? 'Release to send' : 'Starting Bean voice',
    );

    if (_voiceConnected) {
      await client.setMicMuted(false);
      if (!mounted) return;
      _publishVoiceDock(
        _BeanDockActivity.listening,
        label: 'Listening',
        detail: 'Release to send',
      );
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
    _publishVoiceDock(
      _BeanDockActivity.thinking,
      label: 'Connecting',
      detail: 'Starting Bean voice',
    );

    try {
      final permission = await permissions.Permission.microphone.request();
      if (!permission.isGranted) {
        _handleMicrophonePermissionDenied(permission);
        return;
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
      });
      _publishVoiceReadyOrIdle();
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
        _voiceAwaitingResponse = false;
        _voiceSpeaking = false;
      });
      _publishVoiceDock(
        _BeanDockActivity.attention,
        label: 'Voice could not start',
        detail: 'Hold Bean and try again',
      );
      unawaited(
        widget.onVoiceEvent(
          'voice_session_error',
          label: error.toString(),
          payload: {'transport': 'elevenlabs_agent'},
        ),
      );
    }
  }

  void _handleMicrophonePermissionDenied(
    permissions.PermissionStatus permission,
  ) {
    if (!mounted) return;
    setState(() {
      _voiceConnecting = false;
      _voicePushToTalkHeld = false;
      _voiceAwaitingResponse = false;
      _voiceSpeaking = false;
    });
    final needsSettings =
        permission.isPermanentlyDenied || permission.isRestricted;
    _publishVoiceDock(
      _BeanDockActivity.attention,
      label: 'Microphone permission needed',
      detail: needsSettings
          ? 'Enable microphone in Settings'
          : 'Allow microphone to talk to Bean',
    );
    unawaited(
      widget.onVoiceEvent(
        'voice_microphone_permission_needed',
        label: permission.name,
        payload: {
          'transport': 'elevenlabs_agent',
          'permission_status': permission.name,
        },
      ),
    );
  }

  Future<void> _stopElevenLabsPushToTalk({bool cancelled = false}) async {
    if (!_voicePushToTalkHeld && !_voiceConnecting) return;

    final client = _elevenLabsClient;
    setState(() {
      _voicePushToTalkHeld = false;
      _voiceAwaitingResponse = !cancelled;
    });
    if (cancelled) {
      _clearVoiceDock();
    } else if (_voiceConnected || _voiceConnecting) {
      _publishVoiceDock(_BeanDockActivity.thinking, label: 'Thinking');
    }

    if (client == null) return;
    if (_voiceConnected) {
      await client.setMicMuted(true);
      if (!mounted) return;
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
    _voiceIdleDisconnectTimer = Timer(const Duration(seconds: 5), () {
      final client = _elevenLabsClient;
      if (!mounted || client == null || _voicePushToTalkHeld) return;
      if (client.status != elevenlabs.ConversationStatus.connected) return;
      if (_voiceAwaitingResponse || _voiceSpeaking) {
        _scheduleVoiceIdleDisconnect();
        return;
      }
      unawaited(client.endSession());
    });
  }

  @override
  Widget build(BuildContext context) {
    final keyboardInset = MediaQuery.viewInsetsOf(context).bottom;
    final bottomPadding = MediaQuery.paddingOf(context).bottom;
    final dockBottomPadding = bottomPadding > 0 ? bottomPadding + 4 : 8.0;
    final dockHeight = 46.0 + 66.0 + dockBottomPadding;
    final messages = widget.messages;

    if (widget.voiceDockOnly) {
      return const SizedBox.shrink(key: Key('bean-voice-dock-controller'));
    }

    return Align(
      alignment: Alignment.bottomCenter,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        key: const Key('bean-assistant-panel'),
        margin: EdgeInsets.only(
          bottom: keyboardInset > 0 ? keyboardInset : dockHeight,
        ),
        constraints: const BoxConstraints(maxHeight: 540),
        width: double.infinity,
        decoration: BoxDecoration(
          color: HeyBeanTheme.surface,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(28)),
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
                                  'Type to Bean here. Hold the Bean button for voice.',
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
