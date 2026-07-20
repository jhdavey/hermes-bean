part of '../../main.dart';

class _BeanAssistantPanel extends StatefulWidget {
  const _BeanAssistantPanel({
    required this.messages,
    required this.sending,
    required this.error,
    required this.onSend,
  });

  final List<BeanAssistantMessage> messages;
  final bool sending;
  final String? error;
  final Future<void> Function(String message) onSend;

  @override
  State<_BeanAssistantPanel> createState() => _BeanAssistantPanelState();
}

class _BeanAssistantPanelState extends State<_BeanAssistantPanel> {
  final TextEditingController _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final text = _controller.text.trim();
    if (text.isEmpty || widget.sending) return;
    _controller.clear();
    await widget.onSend(text);
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.viewInsetsOf(context).bottom;
    final messages = widget.messages;

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
        constraints: const BoxConstraints(maxWidth: 720, maxHeight: 500),
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
                                  'Try “Create task call mom” or “Show my notes”.',
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
