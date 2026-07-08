part of '../../main.dart';

class _HeroChatCard extends StatefulWidget {
  const _HeroChatCard({
    required this.messages,
    required this.busy,
    required this.runState,
    required this.inputController,
    required this.inputFocusNode,
    required this.onMessageCopied,
    required this.onMessageEdited,
  });

  final List<HermesMessage> messages;
  final bool busy;
  final String runState;
  final TextEditingController inputController;
  final FocusNode inputFocusNode;
  final Future<void> Function(HermesMessage message) onMessageCopied;
  final ValueChanged<HermesMessage> onMessageEdited;

  @override
  State<_HeroChatCard> createState() => _HeroChatCardState();
}

class _HeroChatCardState extends State<_HeroChatCard> {
  final _scrollController = ScrollController();
  Timer? _scrollTimerShort;
  Timer? _scrollTimerLong;
  int _lastScrollSignature = 0;

  @override
  void initState() {
    super.initState();
    _lastScrollSignature = _chatScrollSignature();
    _scheduleScrollToBottom(immediate: true);
  }

  @override
  void didUpdateWidget(covariant _HeroChatCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    final nextSignature = _chatScrollSignature();
    if (nextSignature != _lastScrollSignature ||
        widget.busy != oldWidget.busy ||
        widget.runState != oldWidget.runState) {
      _lastScrollSignature = nextSignature;
      _scheduleScrollToBottom(
        immediate: widget.messages.length != oldWidget.messages.length,
      );
    }
  }

  @override
  void dispose() {
    _scrollTimerShort?.cancel();
    _scrollTimerLong?.cancel();
    _scrollController.dispose();
    super.dispose();
  }

  int _chatScrollSignature() {
    if (widget.messages.isEmpty) return 0;
    final last = widget.messages.last;
    return Object.hash(
      widget.messages.length,
      last.id,
      last.role,
      last.content,
    );
  }

  void _scheduleScrollToBottom({bool immediate = false}) {
    _scrollTimerShort?.cancel();
    _scrollTimerLong?.cancel();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _scrollToBottom(immediate: immediate);
    });
    _scrollTimerShort = Timer(const Duration(milliseconds: 60), () {
      if (!mounted) return;
      _scrollToBottom(immediate: immediate);
    });
    _scrollTimerLong = Timer(const Duration(milliseconds: 180), () {
      if (!mounted) return;
      _scrollToBottom(immediate: immediate);
    });
  }

  void _scrollToBottom({bool immediate = false}) {
    if (!_scrollController.hasClients) return;
    final maxScrollExtent = _scrollController.position.maxScrollExtent;
    final target = maxScrollExtent <= 180 ? 0.0 : maxScrollExtent;
    if (immediate) {
      _scrollController.jumpTo(target);
      return;
    }
    _scrollController.animateTo(
      target,
      duration: const Duration(milliseconds: 180),
      curve: Curves.easeOut,
    );
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox.expand(
      key: const Key('chat-view'),
      child: Stack(
        children: [
          Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: LayoutBuilder(
                  builder: (context, constraints) {
                    return _ChatMessageTopFade(
                      child: SingleChildScrollView(
                        key: const Key('chat-message-list'),
                        controller: _scrollController,
                        padding: const EdgeInsets.only(bottom: 8, top: 6),
                        child: ConstrainedBox(
                          constraints: BoxConstraints(
                            minWidth: constraints.maxWidth,
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              for (final message in widget.messages)
                                Padding(
                                  padding: const EdgeInsets.only(bottom: 5),
                                  child: _MessageBubble(
                                    sender: message.role == 'user'
                                        ? 'You'
                                        : 'Bean',
                                    message: message.content ?? '',
                                    alignRight: message.role == 'user',
                                    statusLabel: switch (message
                                        .metadata['client_queue_status']) {
                                      'queued' => 'Queued',
                                      'sending' => 'Sending',
                                      _ => null,
                                    },
                                    onCopy: message.role == 'user'
                                        ? () => unawaited(
                                            widget.onMessageCopied(message),
                                          )
                                        : null,
                                    onEdit:
                                        message.role == 'user' && !widget.busy
                                        ? () => widget.onMessageEdited(message)
                                        : null,
                                  ),
                                ),
                            ],
                          ),
                        ),
                      ),
                    );
                  },
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ChatMessageTopFade extends StatelessWidget {
  const _ChatMessageTopFade({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => ShaderMask(
    key: const Key('chat-message-top-fade'),
    blendMode: BlendMode.dstIn,
    shaderCallback: (bounds) => const LinearGradient(
      begin: Alignment.topCenter,
      end: Alignment.bottomCenter,
      colors: [Colors.transparent, Colors.black, Colors.black],
      stops: [0, .18, 1],
    ).createShader(bounds),
    child: child,
  );
}

class _DockedBeanChatComposer extends StatelessWidget {
  const _DockedBeanChatComposer({
    required this.controller,
    required this.focusNode,
    required this.busy,
    required this.attachedToWorkStrip,
    required this.onSend,
    required this.onStop,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final bool busy;
  final bool attachedToWorkStrip;
  final VoidCallback onSend;
  final Future<void> Function() onStop;

  @override
  Widget build(BuildContext context) => _ChatInputDock(
    controller: controller,
    focusNode: focusNode,
    busy: busy,
    attachedToWorkStrip: attachedToWorkStrip,
    onSend: onSend,
    onStop: onStop,
  );
}

class _ChatInputDock extends StatelessWidget {
  const _ChatInputDock({
    required this.controller,
    required this.focusNode,
    required this.busy,
    required this.attachedToWorkStrip,
    required this.onSend,
    required this.onStop,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final bool busy;
  final bool attachedToWorkStrip;
  final VoidCallback onSend;
  final Future<void> Function() onStop;

  @override
  Widget build(BuildContext context) => Container(
    key: const Key('chat-input-dock'),
    padding: const EdgeInsets.all(8),
    decoration: BoxDecoration(
      color: HeyBeanTheme.surface,
      borderRadius: BorderRadius.only(
        topLeft: Radius.circular(attachedToWorkStrip ? 0 : 18),
        topRight: Radius.circular(attachedToWorkStrip ? 0 : 18),
      ),
      border: Border.all(color: _quietBorderColor(alpha: .46), width: 1),
      boxShadow: const [
        BoxShadow(
          color: Color(0x0F000000),
          blurRadius: 14,
          offset: Offset(0, 6),
        ),
      ],
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Expanded(
          child: TextField(
            key: const Key('chat-input'),
            controller: controller,
            focusNode: focusNode,
            minLines: 1,
            maxLines: 4,
            keyboardType: TextInputType.multiline,
            textInputAction: TextInputAction.send,
            onSubmitted: (_) => onSend(),
            decoration: InputDecoration(
              hintText: 'Message Bean…',
              border: InputBorder.none,
              enabledBorder: InputBorder.none,
              focusedBorder: InputBorder.none,
              filled: false,
            ),
          ),
        ),
        const SizedBox(width: 8),
        if (busy) ...[
          FilledButton(
            key: const Key('primary-chat-stop-action'),
            style: FilledButton.styleFrom(
              backgroundColor: HeyBeanTheme.destructive,
              foregroundColor: Colors.white,
              minimumSize: const Size(44, 44),
              padding: EdgeInsets.zero,
            ),
            onPressed: () => unawaited(onStop()),
            child: Icon(Icons.stop_rounded, size: 18),
          ),
          const SizedBox(width: 6),
        ],
        FilledButton(
          key: const Key('primary-chat-action'),
          onPressed: onSend,
          child: Icon(Icons.arrow_upward_rounded, size: 18),
        ),
      ],
    ),
  );
}
