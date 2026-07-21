part of '../../main.dart';

enum _BeanDockActivity {
  idle,
  chat,
  listening,
  thinking,
  working,
  speaking,
  error,
}

class _BeanDockStatusSnapshot {
  const _BeanDockStatusSnapshot({
    required this.activity,
    this.label,
    this.detail,
  });

  final _BeanDockActivity activity;
  final String? label;
  final String? detail;

  bool get visible => activity != _BeanDockActivity.idle;

  static const idle = _BeanDockStatusSnapshot(activity: _BeanDockActivity.idle);
}

class _SignedInBottomDock extends StatelessWidget {
  const _SignedInBottomDock({required this.menu});

  final Widget menu;

  @override
  Widget build(BuildContext context) =>
      SizedBox(key: const Key('signed-in-bottom-dock'), child: menu);
}

class _HeyBeanBottomMenu extends StatelessWidget {
  const _HeyBeanBottomMenu({
    required this.selected,
    required this.beanOpen,
    required this.beanSending,
    required this.beanStatus,
    required this.beanHasError,
    required this.onSelected,
    required this.onBeanPressed,
    required this.onBeanPushToTalkStart,
    required this.onBeanPushToTalkEnd,
    required this.onMorePressed,
  });

  final _HomeDestination selected;
  final bool beanOpen;
  final bool beanSending;
  final _BeanDockStatusSnapshot beanStatus;
  final bool beanHasError;
  final ValueChanged<_HomeDestination> onSelected;
  final VoidCallback onBeanPressed;
  final VoidCallback onBeanPushToTalkStart;
  final void Function({bool cancelled}) onBeanPushToTalkEnd;
  final VoidCallback onMorePressed;

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.paddingOf(context).bottom;
    final dockBottomPadding = bottomInset > 0 ? bottomInset + 4 : 8.0;
    final status = _effectiveStatus();
    final statusVisible = status.visible;
    final statusHeight = statusVisible ? 46.0 : 0.0;
    final navHeight = 66.0 + dockBottomPadding;

    return SizedBox(
      key: const Key('heybean-bottom-menu'),
      height: statusHeight + navHeight,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          if (statusVisible)
            Positioned(
              left: 0,
              right: 0,
              top: 0,
              height: statusHeight,
              child: _BeanDockStatus(
                status: status,
                expanded: beanOpen,
                onPressed: onBeanPressed,
              ),
            ),
          Positioned(
            left: 0,
            right: 0,
            top: statusHeight,
            bottom: 0,
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: HeyBeanTheme.surface.withValues(alpha: .985),
                border: Border(
                  top: BorderSide(color: _quietBorderColor(alpha: .42)),
                ),
                boxShadow: const [
                  BoxShadow(
                    color: Color(0x10020617),
                    blurRadius: 12,
                    offset: Offset(0, -2),
                  ),
                ],
              ),
              child: Padding(
                padding: EdgeInsets.fromLTRB(10, 3, 10, dockBottomPadding),
                child: Row(
                  children: [
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-tasks'),
                        icon: Icons.task_alt_rounded,
                        label: 'Tasks',
                        selected: selected == _HomeDestination.tasks,
                        onPressed: () => onSelected(_HomeDestination.tasks),
                      ),
                    ),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-reminders'),
                        icon: Icons.notifications_active_rounded,
                        label: 'Reminders',
                        selected: selected == _HomeDestination.reminders,
                        onPressed: () => onSelected(_HomeDestination.reminders),
                      ),
                    ),
                    const SizedBox(width: 76),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-notes'),
                        iconWidget: _BeanNotesIcon(
                          size: 23,
                          color: selected == _HomeDestination.notes
                              ? HeyBeanTheme.accentStrong
                              : HeyBeanTheme.muted,
                        ),
                        label: 'Notes',
                        selected: selected == _HomeDestination.notes,
                        onPressed: () => onSelected(_HomeDestination.notes),
                      ),
                    ),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-more'),
                        icon: Icons.more_horiz_rounded,
                        label: 'More',
                        selected: selected == _HomeDestination.settings,
                        onPressed: onMorePressed,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Positioned(
            top: statusHeight - 19,
            left: 0,
            right: 0,
            child: Center(
              child: _CommandCenterFab(
                selected: selected == _HomeDestination.commandCenter,
                active: beanOpen || statusVisible,
                working:
                    beanSending ||
                    status.activity == _BeanDockActivity.thinking ||
                    status.activity == _BeanDockActivity.working ||
                    status.activity == _BeanDockActivity.speaking,
                listening: status.activity == _BeanDockActivity.listening,
                onPressed: onBeanPressed,
                onPushToTalkStart: onBeanPushToTalkStart,
                onPushToTalkEnd: onBeanPushToTalkEnd,
              ),
            ),
          ),
        ],
      ),
    );
  }

  _BeanDockStatusSnapshot _effectiveStatus() {
    if (beanHasError) {
      return const _BeanDockStatusSnapshot(
        activity: _BeanDockActivity.error,
        label: 'Bean needs attention',
      );
    }
    if (beanStatus.visible) return beanStatus;
    if (beanSending) {
      return const _BeanDockStatusSnapshot(
        activity: _BeanDockActivity.working,
        label: 'Working',
      );
    }
    if (beanOpen) {
      return const _BeanDockStatusSnapshot(
        activity: _BeanDockActivity.chat,
        label: 'Bean chat',
        detail: 'Tap to collapse',
      );
    }
    return _BeanDockStatusSnapshot.idle;
  }
}

class _MenuIconButton extends StatelessWidget {
  const _MenuIconButton({
    super.key,
    this.icon,
    this.iconWidget,
    required this.label,
    required this.onPressed,
    this.selected = false,
  });

  final IconData? icon;
  final Widget? iconWidget;
  final String label;
  final VoidCallback onPressed;
  final bool selected;

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.transparent,
    child: InkWell(
      borderRadius: BorderRadius.circular(999),
      onTap: onPressed,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 1),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          mainAxisSize: MainAxisSize.min,
          children: [
            iconWidget ??
                Icon(
                  icon,
                  color: selected ? HeyBeanTheme.text : HeyBeanTheme.muted,
                  size: 21,
                ),
            const SizedBox(height: 2),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: selected ? HeyBeanTheme.text : HeyBeanTheme.muted,
                fontSize: 10,
                fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _CommandCenterFab extends StatelessWidget {
  const _CommandCenterFab({
    required this.selected,
    required this.active,
    required this.working,
    required this.listening,
    required this.onPressed,
    required this.onPushToTalkStart,
    required this.onPushToTalkEnd,
  });

  final bool selected;
  final bool active;
  final bool working;
  final bool listening;
  final VoidCallback onPressed;
  final VoidCallback onPushToTalkStart;
  final void Function({bool cancelled}) onPushToTalkEnd;

  @override
  Widget build(BuildContext context) {
    final activeColor = listening
        ? const Color(0xFF16A34A)
        : working
        ? HeyBeanTheme.accentStrong
        : HeyBeanTheme.accentStrong;
    final highlighted = selected || active || working || listening;
    return GestureDetector(
      key: const Key('nav-command-center'),
      behavior: HitTestBehavior.opaque,
      onTap: onPressed,
      onLongPressStart: (_) => onPushToTalkStart(),
      onLongPressEnd: (_) => onPushToTalkEnd(),
      onLongPressCancel: () => onPushToTalkEnd(cancelled: true),
      child: SizedBox(
        key: const Key('bean-assistant-button'),
        width: 78,
        height: 78,
        child: Center(
          child: Material(
            color: Colors.transparent,
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 180),
              key: const Key('heybean-center-command-center-button'),
              width: listening ? 61 : 58,
              height: listening ? 61 : 58,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: highlighted
                    ? LinearGradient(
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                        colors: [
                          HeyBeanTheme.surface,
                          HeyBeanTheme.accent.withValues(alpha: .10),
                        ],
                      )
                    : null,
                color: highlighted ? null : HeyBeanTheme.surface,
                border: Border.all(
                  color: highlighted
                      ? activeColor
                      : _quietBorderColor(alpha: .54),
                  width: listening ? 2.4 : 1.5,
                ),
                boxShadow: [
                  BoxShadow(
                    color: activeColor.withValues(
                      alpha: highlighted ? .22 : .08,
                    ),
                    blurRadius: highlighted ? 20 : 12,
                    offset: const Offset(0, 7),
                  ),
                ],
              ),
              child: Semantics(
                label: 'Bean assistant',
                image: true,
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Image.asset(
                    HeyBeanTheme.isDark
                        ? 'assets/images/bean/bean-logo-white-overlay.png'
                        : 'assets/images/bean/bean-logo.png',
                    key: const Key('bean-assistant-button-logo'),
                    fit: BoxFit.contain,
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _BeanDockStatus extends StatefulWidget {
  const _BeanDockStatus({
    required this.status,
    required this.expanded,
    required this.onPressed,
  });

  final _BeanDockStatusSnapshot status;
  final bool expanded;
  final VoidCallback onPressed;

  @override
  State<_BeanDockStatus> createState() => _BeanDockStatusState();
}

class _BeanDockStatusState extends State<_BeanDockStatus>
    with SingleTickerProviderStateMixin {
  late final AnimationController _borderController = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 900),
  );

  bool get _animated =>
      widget.status.activity == _BeanDockActivity.listening ||
      widget.status.activity == _BeanDockActivity.thinking ||
      widget.status.activity == _BeanDockActivity.working ||
      widget.status.activity == _BeanDockActivity.speaking;

  @override
  void initState() {
    super.initState();
    if (_animated) _borderController.repeat();
  }

  @override
  void didUpdateWidget(covariant _BeanDockStatus oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (_animated && !_borderController.isAnimating) {
      _borderController.repeat();
    } else if (!_animated && _borderController.isAnimating) {
      _borderController
        ..stop()
        ..value = 0;
    }
  }

  @override
  void dispose() {
    _borderController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final color = _statusColor(widget.status.activity);
    final label = widget.status.label ?? _defaultLabel(widget.status.activity);
    final detail = widget.status.detail;
    return AnimatedBuilder(
      animation: _borderController,
      builder: (context, child) => CustomPaint(
        foregroundPainter: _BeanDockBorderPainter(
          progress: _borderController.value,
          active: _animated,
          color: color,
        ),
        child: child,
      ),
      child: Material(
        key: const Key('bean-assistant-status'),
        color: HeyBeanTheme.surface,
        child: InkWell(
          onTap: widget.onPressed,
          child: Container(
            height: 46,
            width: double.infinity,
            padding: const EdgeInsets.fromLTRB(18, 0, 18, 0),
            decoration: BoxDecoration(
              color: widget.status.activity == _BeanDockActivity.error
                  ? const Color(0xFFFFF1F1)
                  : HeyBeanTheme.surface.withValues(alpha: .99),
              border: Border(
                top: BorderSide(color: _quietBorderColor(alpha: .38)),
                bottom: BorderSide(color: _quietBorderColor(alpha: .30)),
              ),
            ),
            child: Row(
              children: [
                Container(
                  width: 9,
                  height: 9,
                  decoration: BoxDecoration(
                    color: color,
                    shape: BoxShape.circle,
                    boxShadow: _animated
                        ? [
                            BoxShadow(
                              color: color.withValues(alpha: .42),
                              blurRadius: 12,
                              spreadRadius: 2,
                            ),
                          ]
                        : null,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color:
                              widget.status.activity == _BeanDockActivity.error
                              ? const Color(0xFFB42318)
                              : HeyBeanTheme.text,
                          fontSize: 13,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      if ((detail ?? '').trim().isNotEmpty)
                        Text(
                          detail!,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: HeyBeanTheme.muted,
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                    ],
                  ),
                ),
                Icon(
                  widget.expanded
                      ? Icons.keyboard_arrow_down_rounded
                      : Icons.keyboard_arrow_up_rounded,
                  size: 22,
                  color: HeyBeanTheme.muted,
                  semanticLabel: widget.expanded
                      ? 'Collapse Bean chat'
                      : 'Expand Bean chat',
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  static String _defaultLabel(_BeanDockActivity activity) => switch (activity) {
    _BeanDockActivity.chat => 'Bean chat',
    _BeanDockActivity.listening => 'Listening',
    _BeanDockActivity.thinking => 'Thinking',
    _BeanDockActivity.working => 'Working',
    _BeanDockActivity.speaking => 'Speaking',
    _BeanDockActivity.error => 'Bean needs attention',
    _BeanDockActivity.idle => '',
  };

  static Color _statusColor(_BeanDockActivity activity) => switch (activity) {
    _BeanDockActivity.listening => const Color(0xFF16A34A),
    _BeanDockActivity.thinking => const Color(0xFF2563EB),
    _BeanDockActivity.working => HeyBeanTheme.accentStrong,
    _BeanDockActivity.speaking => const Color(0xFF7C3AED),
    _BeanDockActivity.error => const Color(0xFFB42318),
    _ => HeyBeanTheme.accentStrong,
  };
}

class _BeanDockBorderPainter extends CustomPainter {
  const _BeanDockBorderPainter({
    required this.progress,
    required this.active,
    required this.color,
  });

  final double progress;
  final bool active;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    if (!active) return;
    final rect = Offset.zero & size;
    final paint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.2
      ..shader = LinearGradient(
        begin: Alignment(-1 + progress * 2, 0),
        end: Alignment(progress * 2, 0),
        colors: [
          color.withValues(alpha: .08),
          color.withValues(alpha: .72),
          color.withValues(alpha: .08),
        ],
        stops: const [0, .5, 1],
      ).createShader(rect);
    canvas.drawRect(rect.deflate(1.1), paint);
  }

  @override
  bool shouldRepaint(covariant _BeanDockBorderPainter oldDelegate) =>
      oldDelegate.progress != progress ||
      oldDelegate.active != active ||
      oldDelegate.color != color;
}
