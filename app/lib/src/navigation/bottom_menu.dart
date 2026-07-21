part of '../../main.dart';

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
  final bool beanHasError;
  final ValueChanged<_HomeDestination> onSelected;
  final VoidCallback onBeanPressed;
  final VoidCallback onBeanPushToTalkStart;
  final void Function({bool cancelled}) onBeanPushToTalkEnd;
  final VoidCallback onMorePressed;

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.paddingOf(context).bottom;
    final dockBottomPadding = bottomInset > 0 ? bottomInset + 2 : 6.0;

    return SizedBox(
      key: const Key('heybean-bottom-menu'),
      height: 104 + dockBottomPadding,
      child: Stack(
        alignment: Alignment.topCenter,
        clipBehavior: Clip.none,
        children: [
          Positioned.fill(
            top: _beanBottomMenuSurfaceInset,
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: HeyBeanTheme.surface.withValues(alpha: .97),
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
                padding: EdgeInsets.fromLTRB(10, 7, 10, dockBottomPadding),
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
                    const SizedBox(width: 86),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-notes'),
                        iconWidget: _BeanNotesIcon(
                          size: 24,
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
            top: 24,
            child: _CommandCenterFab(
              selected: selected == _HomeDestination.commandCenter,
              active: beanOpen,
              working: beanSending,
              onPressed: onBeanPressed,
              onPushToTalkStart: onBeanPushToTalkStart,
              onPushToTalkEnd: onBeanPushToTalkEnd,
            ),
          ),
          Positioned(
            top: 0,
            child: _BeanDockStatus(
              expanded: beanOpen,
              working: beanSending,
              hasError: beanHasError,
              onPressed: onBeanPressed,
            ),
          ),
        ],
      ),
    );
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
                  size: 22,
                ),
            const SizedBox(height: 3),
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
    required this.onPressed,
    required this.onPushToTalkStart,
    required this.onPushToTalkEnd,
  });

  final bool selected;
  final bool active;
  final bool working;
  final VoidCallback onPressed;
  final VoidCallback onPushToTalkStart;
  final void Function({bool cancelled}) onPushToTalkEnd;

  @override
  Widget build(BuildContext context) {
    final activeColor = HeyBeanTheme.accentStrong;
    final highlighted = selected || active || working;
    return GestureDetector(
      key: const Key('nav-command-center'),
      behavior: HitTestBehavior.opaque,
      onTap: onPressed,
      onLongPressStart: (_) => onPushToTalkStart(),
      onLongPressEnd: (_) => onPushToTalkEnd(),
      onLongPressCancel: () => onPushToTalkEnd(cancelled: true),
      child: SizedBox(
        key: const Key('bean-assistant-button'),
        width: 88,
        height: 88,
        child: Center(
          child: Material(
            color: Colors.transparent,
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 180),
              key: const Key('heybean-center-command-center-button'),
              width: 64,
              height: 64,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: HeyBeanTheme.surface,
                border: Border.all(
                  color: highlighted
                      ? activeColor
                      : _quietBorderColor(alpha: .54),
                  width: 1.6,
                ),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: .08),
                    blurRadius: 14,
                    offset: const Offset(0, 6),
                  ),
                ],
              ),
              child: Icon(
                Icons.spa_rounded,
                size: 30,
                color: highlighted ? activeColor : HeyBeanTheme.muted,
                semanticLabel: 'Bean assistant',
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
    required this.expanded,
    required this.working,
    required this.hasError,
    required this.onPressed,
  });

  final bool expanded;
  final bool working;
  final bool hasError;
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

  @override
  void initState() {
    super.initState();
    if (widget.working) _borderController.repeat();
  }

  @override
  void didUpdateWidget(covariant _BeanDockStatus oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.working == oldWidget.working) return;
    if (widget.working) {
      _borderController.repeat();
    } else {
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
    final label = widget.working
        ? 'Bean is working'
        : widget.hasError
        ? 'Bean needs attention'
        : 'Bean ready';
    return AnimatedBuilder(
      animation: _borderController,
      builder: (context, child) => CustomPaint(
        foregroundPainter: _BeanDockBorderPainter(
          progress: _borderController.value,
          active: widget.working,
        ),
        child: child,
      ),
      child: Material(
        color: HeyBeanTheme.surface,
        borderRadius: BorderRadius.circular(999),
        child: InkWell(
          key: const Key('bean-assistant-status'),
          onTap: widget.onPressed,
          borderRadius: BorderRadius.circular(999),
          child: Container(
            height: 36,
            padding: const EdgeInsets.fromLTRB(14, 0, 10, 0),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(999),
              border: Border.all(
                color: widget.hasError
                    ? const Color(0xFFB42318).withValues(alpha: .48)
                    : _quietBorderColor(alpha: .54),
              ),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: .08),
                  blurRadius: 12,
                  offset: const Offset(0, 4),
                ),
              ],
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Flexible(
                  fit: FlexFit.loose,
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: widget.hasError
                          ? const Color(0xFFB42318)
                          : HeyBeanTheme.muted,
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                const SizedBox(width: 2),
                Icon(
                  widget.expanded
                      ? Icons.keyboard_arrow_down_rounded
                      : Icons.keyboard_arrow_up_rounded,
                  size: 20,
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
}

class _BeanDockBorderPainter extends CustomPainter {
  const _BeanDockBorderPainter({required this.progress, required this.active});

  final double progress;
  final bool active;

  @override
  void paint(Canvas canvas, Size size) {
    if (!active) return;
    final rect = Offset.zero & size;
    final paint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.4
      ..shader = SweepGradient(
        startAngle: progress * math.pi * 2,
        endAngle: progress * math.pi * 2 + math.pi * 2,
        colors: [
          HeyBeanTheme.accentStrong.withValues(alpha: .12),
          HeyBeanTheme.accentStrong,
          HeyBeanTheme.accentStrong.withValues(alpha: .12),
        ],
        stops: const [0, .28, 1],
      ).createShader(rect);
    canvas.drawRRect(
      RRect.fromRectAndRadius(rect.deflate(1.2), const Radius.circular(999)),
      paint,
    );
  }

  @override
  bool shouldRepaint(covariant _BeanDockBorderPainter oldDelegate) =>
      oldDelegate.progress != progress || oldDelegate.active != active;
}
