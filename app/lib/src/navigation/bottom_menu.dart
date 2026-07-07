part of '../../main.dart';

class _SignedInBottomDock extends StatelessWidget {
  const _SignedInBottomDock({
    required this.showComposer,
    required this.beanWorkItems,
    required this.beanWorkStatus,
    required this.beanWorkActive,
    required this.composer,
    required this.menu,
  });

  final bool showComposer;
  final List<_BeanWorkItem> beanWorkItems;
  final String beanWorkStatus;
  final bool beanWorkActive;
  final Widget composer;
  final Widget menu;

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.paddingOf(context).bottom;
    final dockBottomPadding = bottomInset > 0 ? bottomInset + 2 : 6.0;
    final menuHeight = 78.0 + dockBottomPadding;

    return Stack(
      key: const Key('signed-in-bottom-dock'),
      clipBehavior: Clip.none,
      children: [
        Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            AnimatedSwitcher(
              duration: const Duration(milliseconds: 180),
              switchInCurve: Curves.easeOut,
              switchOutCurve: Curves.easeIn,
              transitionBuilder: (child, animation) => SizeTransition(
                sizeFactor: animation,
                axisAlignment: -1,
                child: FadeTransition(opacity: animation, child: child),
              ),
              child: beanWorkActive
                  ? _BeanWorkDockStrip(
                      key: Key(
                        showComposer
                            ? 'bean-work-dock-strip'
                            : 'bean-global-work-dock-strip',
                      ),
                      status: beanWorkStatus,
                      items: beanWorkItems,
                    )
                  : const SizedBox(
                      key: Key('bean-work-dock-strip-empty'),
                      height: 0,
                    ),
            ),
            if (showComposer)
              Align(
                key: const Key('bean-chat-composer-dock'),
                alignment: Alignment.bottomCenter,
                child: ConstrainedBox(
                  constraints: const BoxConstraints(
                    minHeight: _beanChatComposerReservedHeight,
                    maxHeight: _beanChatComposerMaxHeight,
                  ),
                  child: composer,
                ),
              ),
            SizedBox(height: menuHeight - _beanBottomMenuSurfaceInset),
          ],
        ),
        Positioned(left: 0, right: 0, bottom: 0, child: menu),
      ],
    );
  }
}

class _HeyBeanBottomMenu extends StatelessWidget {
  const _HeyBeanBottomMenu({
    required this.selected,
    required this.onSelected,
    required this.beanListening,
    required this.beanWorking,
    required this.onMorePressed,
    required this.onBeanLongPressStart,
    required this.onBeanLongPressEnd,
  });

  final _HomeDestination selected;
  final ValueChanged<_HomeDestination> onSelected;
  final bool beanListening;
  final bool beanWorking;
  final VoidCallback onMorePressed;
  final VoidCallback onBeanLongPressStart;
  final VoidCallback onBeanLongPressEnd;

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.paddingOf(context).bottom;
    final dockBottomPadding = bottomInset > 0 ? bottomInset + 2 : 6.0;

    return SizedBox(
      key: const Key('heybean-bottom-menu'),
      height: 74 + dockBottomPadding,
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
                        selected:
                            selected == _HomeDestination.settings ||
                            selected == _HomeDestination.memory,
                        onPressed: onMorePressed,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Positioned(
            top: 12,
            child: _BeanFab(
              selected: selected == _HomeDestination.bean,
              listening: beanListening,
              working: beanWorking,
              onPressed: () => onSelected(_HomeDestination.bean),
              onLongPressStart: onBeanLongPressStart,
              onLongPressEnd: onBeanLongPressEnd,
            ),
          ),
        ],
      ),
    );
  }
}

class _BeanWorkDockStrip extends StatelessWidget {
  const _BeanWorkDockStrip({
    super.key,
    required this.status,
    required this.items,
  });

  final String status;
  final List<_BeanWorkItem> items;

  @override
  Widget build(BuildContext context) {
    final title = _compactBeanStatusLabel(status);
    final displayItems = items
        .where((item) => item.label.trim().isNotEmpty)
        .take(6)
        .toList();
    final completed = displayItems.where((item) => item.done).length;
    final statusLooksDone = RegExp(
      r'\b(done|updated|completed|stopped|cancelled|failed)\b',
      caseSensitive: false,
    ).hasMatch(title);
    final hasActiveWork =
        displayItems.any((item) => !item.done) ||
        (displayItems.isEmpty && !statusLooksDone);

    return Padding(
      padding: EdgeInsets.zero,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: HeyBeanTheme.surface.withValues(alpha: .98),
          borderRadius: const BorderRadius.only(
            topLeft: Radius.circular(14),
            topRight: Radius.circular(14),
          ),
          border: Border.all(color: _quietBorderColor(alpha: .42)),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: .06),
              blurRadius: 12,
              offset: const Offset(0, 4),
            ),
          ],
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 9, 12, 10),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  SizedBox(
                    width: 18,
                    height: 18,
                    child: hasActiveWork
                        ? CircularProgressIndicator(
                            strokeWidth: 2,
                            valueColor: AlwaysStoppedAnimation<Color>(
                              HeyBeanTheme.accentStrong,
                            ),
                          )
                        : Icon(
                            Icons.check_circle_rounded,
                            size: 18,
                            color: HeyBeanTheme.accentStrong,
                          ),
                  ),
                  const SizedBox(width: 9),
                  Expanded(
                    child: Text(
                      title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: HeyBeanTheme.text,
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                  if (displayItems.isNotEmpty)
                    Text(
                      '$completed/${displayItems.length}',
                      style: TextStyle(
                        color: HeyBeanTheme.muted,
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                ],
              ),
              if (displayItems.isNotEmpty) const SizedBox(height: 7),
              for (final item in displayItems)
                Padding(
                  padding: const EdgeInsets.only(top: 3),
                  child: Row(
                    children: [
                      Icon(
                        item.done
                            ? Icons.check_box_rounded
                            : Icons.check_box_outline_blank_rounded,
                        size: 16,
                        color: item.done
                            ? HeyBeanTheme.accentStrong
                            : HeyBeanTheme.muted,
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          item.label,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: item.done
                                ? HeyBeanTheme.muted
                                : HeyBeanTheme.text,
                            fontSize: 12,
                            fontWeight: FontWeight.w500,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _BeanResponsePreviewTag extends StatefulWidget {
  const _BeanResponsePreviewTag({
    super.key,
    required this.text,
    this.onHoldStart,
    this.onHoldEnd,
    this.onDismissed,
  });

  final String text;
  final VoidCallback? onHoldStart;
  final VoidCallback? onHoldEnd;
  final VoidCallback? onDismissed;

  @override
  State<_BeanResponsePreviewTag> createState() =>
      _BeanResponsePreviewTagState();
}

class _BeanResponsePreviewTagState extends State<_BeanResponsePreviewTag> {
  static const double _dismissDistance = 48;
  Offset _dragOffset = Offset.zero;
  bool _dismissed = false;

  void _holdStart() => widget.onHoldStart?.call();

  void _holdEnd() {
    if (!_dismissed) widget.onHoldEnd?.call();
  }

  void _dismiss() {
    if (_dismissed) return;
    _dismissed = true;
    widget.onDismissed?.call();
  }

  bool _shouldDismiss(DragEndDetails details) {
    final velocity = details.velocity.pixelsPerSecond;
    return _dragOffset.dx.abs() >= _dismissDistance ||
        _dragOffset.dy >= _dismissDistance ||
        velocity.dx.abs() >= 450 ||
        velocity.dy >= 450;
  }

  @override
  Widget build(BuildContext context) => Listener(
    onPointerDown: (_) => _holdStart(),
    onPointerUp: (_) => _holdEnd(),
    onPointerCancel: (_) => _holdEnd(),
    child: GestureDetector(
      behavior: HitTestBehavior.opaque,
      onPanStart: (_) => _holdStart(),
      onPanUpdate: (details) {
        setState(() {
          _dragOffset += details.delta;
          if (_dragOffset.dy < 0) {
            _dragOffset = Offset(_dragOffset.dx, 0);
          }
        });
      },
      onPanEnd: (details) {
        if (_shouldDismiss(details)) {
          _dismiss();
          return;
        }
        setState(() => _dragOffset = Offset.zero);
        _holdEnd();
      },
      onPanCancel: () {
        setState(() => _dragOffset = Offset.zero);
        _holdEnd();
      },
      child: AnimatedSlide(
        duration: const Duration(milliseconds: 140),
        curve: Curves.easeOut,
        offset: Offset(_dragOffset.dx / 240, _dragOffset.dy / 160),
        child: AnimatedOpacity(
          duration: const Duration(milliseconds: 140),
          opacity: (1 - (_dragOffset.distance / 160)).clamp(.45, 1.0),
          child: ConstrainedBox(
            constraints: BoxConstraints(
              maxWidth: math.min(MediaQuery.sizeOf(context).width - 32, 360.0),
            ),
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: HeyBeanTheme.surface.withValues(alpha: .97),
                border: Border.all(color: _quietBorderColor(alpha: .42)),
                borderRadius: BorderRadius.circular(14),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: .08),
                    blurRadius: 14,
                    offset: const Offset(0, 6),
                  ),
                ],
              ),
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 10,
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: 6,
                      height: 6,
                      margin: const EdgeInsets.only(top: 5),
                      decoration: BoxDecoration(
                        color: HeyBeanTheme.accentStrong,
                        borderRadius: BorderRadius.circular(999),
                      ),
                    ),
                    const SizedBox(width: 9),
                    Flexible(
                      child: Text(
                        widget.text,
                        maxLines: 3,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: HeyBeanTheme.text,
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          height: 1.25,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    ),
  );
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

class _BeanFab extends StatefulWidget {
  const _BeanFab({
    required this.selected,
    required this.listening,
    this.working = false,
    required this.onPressed,
    required this.onLongPressStart,
    required this.onLongPressEnd,
    this.widgetKey = const Key('nav-bean'),
    this.semanticLabel = 'Bean chat',
    this.longPressEnabled = true,
  });

  final Key widgetKey;
  final bool selected;
  final bool listening;
  final bool working;
  final String semanticLabel;
  final bool longPressEnabled;
  final VoidCallback onPressed;
  final VoidCallback onLongPressStart;
  final VoidCallback onLongPressEnd;

  @override
  State<_BeanFab> createState() => _BeanFabState();
}

class _BeanFabState extends State<_BeanFab> with TickerProviderStateMixin {
  static const _pressHoldDelay = Duration(milliseconds: 180);

  late final AnimationController _pulseController;
  late final AnimationController _activityController;
  bool _pressRecording = false;
  Timer? _pressHoldTimer;
  int? _activePointer;

  bool get _visuallyListening => widget.listening || _pressRecording;
  bool get _visuallyWorking => widget.working && !_visuallyListening;

  @override
  void initState() {
    super.initState();
    _pulseController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 950),
    );
    _activityController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1350),
    );
    _syncFabAnimations();
  }

  @override
  void didUpdateWidget(covariant _BeanFab oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.listening != widget.listening ||
        oldWidget.working != widget.working) {
      final stoppedListening = oldWidget.listening && !widget.listening;
      if (!widget.listening &&
          (stoppedListening || _activePointer == null) &&
          _pressRecording) {
        _pressRecording = false;
      }
      _syncFabAnimations();
    }
  }

  void _syncFabAnimations() {
    if (_visuallyListening) {
      _pulseController.repeat(reverse: true);
    } else {
      _pulseController.stop();
      _pulseController.value = 0;
    }
    if (_visuallyWorking) {
      _activityController.repeat();
    } else {
      _activityController.stop();
      _activityController.value = 0;
    }
  }

  @override
  void dispose() {
    _pressHoldTimer?.cancel();
    _pulseController.dispose();
    _activityController.dispose();
    super.dispose();
  }

  void _handlePointerDown(PointerDownEvent event) {
    if (!widget.longPressEnabled) return;
    if (_activePointer != null) return;
    _activePointer = event.pointer;
    _pressHoldTimer?.cancel();
    _pressHoldTimer = Timer(_pressHoldDelay, _beginPressRecording);
  }

  void _handlePointerUp(PointerUpEvent event) {
    if (_activePointer != event.pointer) return;
    _activePointer = null;
    final wasRecording = _pressRecording || widget.listening;
    _pressHoldTimer?.cancel();
    _pressHoldTimer = null;
    if (wasRecording) {
      _endPressRecording();
    } else {
      widget.onPressed();
    }
  }

  void _handlePointerCancel(PointerCancelEvent event) {
    if (_activePointer != event.pointer) return;
    _activePointer = null;
    _pressHoldTimer?.cancel();
    _pressHoldTimer = null;
    _endPressRecording();
  }

  void _beginPressRecording() {
    if (!widget.longPressEnabled) return;
    _pressHoldTimer?.cancel();
    _pressHoldTimer = null;
    if (_pressRecording) return;
    setState(() => _pressRecording = true);
    _syncFabAnimations();
    widget.onLongPressStart();
  }

  void _endPressRecording() {
    if (!_pressRecording && !widget.listening) return;
    if (_pressRecording) {
      setState(() => _pressRecording = false);
      _syncFabAnimations();
    }
    widget.onLongPressEnd();
  }

  @override
  Widget build(BuildContext context) {
    final activeColor = HeyBeanTheme.accentStrong;
    final visuallyListening = _visuallyListening;
    final visuallyWorking = _visuallyWorking;
    return Listener(
      key: widget.widgetKey,
      behavior: HitTestBehavior.opaque,
      onPointerDown: _handlePointerDown,
      onPointerUp: _handlePointerUp,
      onPointerCancel: _handlePointerCancel,
      child: SizedBox(
        width: 88,
        height: 88,
        child: Stack(
          alignment: Alignment.center,
          clipBehavior: Clip.none,
          children: [
            if (visuallyListening)
              AnimatedBuilder(
                key: const Key('heybean-recording-pulse'),
                animation: _pulseController,
                builder: (context, child) {
                  final pulse = Curves.easeInOut.transform(
                    _pulseController.value,
                  );
                  return Container(
                    width: 72 + (pulse * 14),
                    height: 72 + (pulse * 14),
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: activeColor.withValues(alpha: .06 + (pulse * .06)),
                      boxShadow: [
                        BoxShadow(
                          color: activeColor.withValues(
                            alpha: .12 + (pulse * .08),
                          ),
                          blurRadius: 18 + (pulse * 14),
                          spreadRadius: 3 + (pulse * 6),
                        ),
                      ],
                    ),
                  );
                },
              ),
            Material(
              color: Colors.transparent,
              child: Ink(
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 180),
                  key: const Key('heybean-center-bean-button'),
                  width: 64,
                  height: 64,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: HeyBeanTheme.surface,
                    border: Border.all(
                      color: visuallyWorking
                          ? Colors.transparent
                          : visuallyListening || widget.selected
                          ? activeColor
                          : _quietBorderColor(alpha: .54),
                      width: visuallyWorking
                          ? 0
                          : visuallyListening
                          ? 3
                          : 1.6,
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: visuallyListening || visuallyWorking
                            ? activeColor.withValues(alpha: .14)
                            : Colors.black.withValues(alpha: .08),
                        blurRadius: visuallyListening || visuallyWorking
                            ? 24
                            : 14,
                        spreadRadius: visuallyListening || visuallyWorking
                            ? 2
                            : 0,
                        offset: const Offset(0, 6),
                      ),
                    ],
                  ),
                  child: Center(
                    child: Image.asset(
                      HeyBeanTheme.isDark
                          ? 'assets/images/bean/bean-logo-white-overlay.png'
                          : 'assets/images/bean/bean-logo.png',
                      key: const Key('heybean-center-bean-logo'),
                      width: 38,
                      height: 38,
                      fit: BoxFit.contain,
                      semanticLabel: widget.semanticLabel,
                    ),
                  ),
                ),
              ),
            ),
            if (visuallyWorking)
              IgnorePointer(
                child: AnimatedBuilder(
                  key: const Key('heybean-working-ring'),
                  animation: _activityController,
                  builder: (context, child) => CustomPaint(
                    size: const Size.square(68),
                    painter: _BeanActivityRingPainter(
                      color: activeColor,
                      progress: _activityController.value,
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _BeanActivityRingPainter extends CustomPainter {
  const _BeanActivityRingPainter({required this.color, required this.progress});

  final Color color;
  final double progress;

  @override
  void paint(Canvas canvas, Size size) {
    final strokeWidth = 4.0;
    final rect = Offset.zero & size;
    final arcRect = rect.deflate(strokeWidth / 2);
    final startAngle = (math.pi * 2 * progress) - math.pi / 2;
    const sweepAngle = math.pi * .82;

    final glowPaint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 8
      ..strokeCap = StrokeCap.round
      ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 8)
      ..color = color.withValues(alpha: .30);
    canvas.drawArc(arcRect, startAngle, sweepAngle, false, glowPaint);

    final arcPaint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth
      ..strokeCap = StrokeCap.round
      ..shader = SweepGradient(
        startAngle: startAngle,
        endAngle: startAngle + math.pi * 2,
        colors: [
          color.withValues(alpha: .08),
          color.withValues(alpha: .42),
          color,
          color.withValues(alpha: .18),
        ],
        stops: const [0, .48, .72, 1],
      ).createShader(arcRect);
    canvas.drawArc(arcRect, startAngle, sweepAngle, false, arcPaint);
  }

  @override
  bool shouldRepaint(covariant _BeanActivityRingPainter oldDelegate) =>
      oldDelegate.color != color || oldDelegate.progress != progress;
}
