part of '../../main.dart';

class _DueReminderBanner extends StatelessWidget {
  const _DueReminderBanner({
    required this.reminder,
    required this.onDismiss,
    required this.onComplete,
  });

  final HermesReminder reminder;
  final VoidCallback onDismiss;
  final Future<void> Function() onComplete;

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.transparent,
    child: Container(
      decoration: BoxDecoration(
        color: HeyBeanTheme.accent.withValues(alpha: .95),
        borderRadius: BorderRadius.circular(24),
        boxShadow: [
          BoxShadow(
            color: HeyBeanTheme.accent.withValues(alpha: .22),
            blurRadius: 24,
            offset: const Offset(0, 12),
          ),
        ],
      ),
      padding: const EdgeInsets.fromLTRB(16, 14, 14, 14),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.notifications_active_rounded, color: Colors.white),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Reminder due now',
                      style: TextStyle(
                        color: Colors.white70,
                        fontWeight: FontWeight.w800,
                        fontSize: 12,
                      ),
                    ),
                    Text(
                      reminder.title,
                      key: const Key('due-reminder-banner-title'),
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 16,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ],
                ),
              ),
              IconButton(
                key: const Key('due-reminder-dismiss-icon'),
                onPressed: onDismiss,
                icon: Icon(Icons.close_rounded, color: Colors.white),
                tooltip: 'Dismiss reminder banner',
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              TextButton(
                key: const Key('due-reminder-dismiss'),
                onPressed: onDismiss,
                style: TextButton.styleFrom(foregroundColor: Colors.white),
                child: Text('Dismiss'),
              ),
              const Spacer(),
              FilledButton.icon(
                key: const Key('due-reminder-complete'),
                onPressed: onComplete,
                style: FilledButton.styleFrom(
                  backgroundColor: Colors.white,
                  foregroundColor: HeyBeanTheme.accent,
                ),
                icon: Icon(Icons.check_rounded),
                label: Text('Mark complete'),
              ),
            ],
          ),
        ],
      ),
    ),
  );
}

class _BetaFeedbackBanner extends StatelessWidget {
  const _BetaFeedbackBanner({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Material(
    color: HeyBeanTheme.accentStrong,
    child: InkWell(
      key: const Key('beta-feedback-banner'),
      onTap: onTap,
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 9),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: const [
              Icon(Icons.bug_report_rounded, color: Colors.white, size: 18),
              SizedBox(width: 8),
              Flexible(
                child: Text(
                  'You are in our Beta testing phase. If you have any issues, please report them here.',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w800,
                    fontSize: 13,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    ),
  );
}

class _BetaFeedbackDialog extends StatefulWidget {
  const _BetaFeedbackDialog({required this.onSubmit});

  final Future<void> Function(String message) onSubmit;

  @override
  State<_BetaFeedbackDialog> createState() => _BetaFeedbackDialogState();
}

class _BetaFeedbackDialogState extends State<_BetaFeedbackDialog> {
  final _controller = TextEditingController();
  bool _submitting = false;
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final message = _controller.text.trim();
    if (message.isEmpty || _submitting) return;
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      await widget.onSubmit(message);
      if (mounted) Navigator.of(context).pop(true);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _error = beanFriendlyErrorMessage(error, action: 'send that feedback');
      });
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    key: const Key('beta-feedback-dialog'),
    icon: Icon(Icons.bug_report_rounded, color: HeyBeanTheme.accent),
    title: Text('Report an issue'),
    content: Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Tell us what happened so we can fix it quickly.'),
        const SizedBox(height: 12),
        TextField(
          key: const Key('beta-feedback-message'),
          controller: _controller,
          enabled: !_submitting,
          minLines: 4,
          maxLines: 7,
          maxLength: 4000,
          textInputAction: TextInputAction.newline,
          decoration: _longFormInputDecoration(
            hintText:
                'Describe what you were doing, what went wrong, and what you expected instead.',
          ),
        ),
        if (_error != null) ...[
          const SizedBox(height: 8),
          Text(
            _error!,
            key: const Key('beta-feedback-error'),
            style: TextStyle(
              color: HeyBeanTheme.destructive,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ],
    ),
    actions: [
      TextButton(
        onPressed: _submitting ? null : () => Navigator.of(context).pop(false),
        child: Text('Cancel'),
      ),
      FilledButton.icon(
        key: const Key('beta-feedback-submit'),
        onPressed: _submitting ? null : _submit,
        icon: _submitting
            ? const SizedBox(
                width: 16,
                height: 16,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : Icon(Icons.send_rounded),
        label: Text(_submitting ? 'Sending...' : 'Send report'),
      ),
    ],
  );
}

class _BetaFeedbackThanksDialog extends StatelessWidget {
  const _BetaFeedbackThanksDialog();

  @override
  Widget build(BuildContext context) => AlertDialog(
    key: const Key('beta-feedback-thanks'),
    icon: Container(
      width: 58,
      height: 58,
      decoration: BoxDecoration(
        color: HeyBeanTheme.accent.withValues(alpha: .12),
        shape: BoxShape.circle,
        boxShadow: [
          BoxShadow(
            color: HeyBeanTheme.accent.withValues(alpha: .08),
            blurRadius: 18,
            spreadRadius: 8,
          ),
        ],
      ),
      child: Icon(
        Icons.check_circle_rounded,
        color: HeyBeanTheme.accentStrong,
        size: 34,
      ),
    ),
    title: Text('Thank you for helping improve HeyBean!'),
    content: Text(
      "We've received your feedback and will fix any issues ASAP!",
      textAlign: TextAlign.center,
    ),
    actionsAlignment: MainAxisAlignment.center,
    actions: [
      FilledButton(
        key: const Key('beta-feedback-thanks-done'),
        onPressed: () => Navigator.of(context).pop(),
        child: Text('Done'),
      ),
    ],
  );
}

enum _OnboardingTourTarget {
  commandCenterChat,
  commandCenterAgenda,
  createMenu,
  calendarControls,
  tasksView,
  remindersView,
  notesView,
}

class _OnboardingTourStepConfig {
  const _OnboardingTourStepConfig({
    required this.title,
    required this.caption,
    required this.destination,
    required this.targets,
  });

  final String title;
  final String caption;
  final _HomeDestination destination;
  final List<_OnboardingTourTarget> targets;
}

class _OnboardingTourHighlightMetrics {
  const _OnboardingTourHighlightMetrics({
    required this.rect,
    required this.borderRadius,
  });

  final Rect rect;
  final BorderRadius borderRadius;
}

class _OnboardingTourCardLayout {
  const _OnboardingTourCardLayout({
    required this.left,
    required this.top,
    required this.width,
  });

  final double left;
  final double top;
  final double width;
}

const List<_OnboardingTourStepConfig> _appOnboardingTourSteps = [
  _OnboardingTourStepConfig(
    title: 'Command center',
    caption:
        "This is your command center. I'm always here to help, just tell me what you need.",
    destination: _HomeDestination.bean,
    targets: [_OnboardingTourTarget.commandCenterChat],
  ),
  _OnboardingTourStepConfig(
    title: 'Today at a glance',
    caption:
        "Above the chat, you'll see today's events, tasks, and reminders in one running list.",
    destination: _HomeDestination.bean,
    targets: [_OnboardingTourTarget.commandCenterAgenda],
  ),
  _OnboardingTourStepConfig(
    title: 'Create items',
    caption:
        'Use the plus button to create new events, tasks, reminders, or notes from anywhere in the app.',
    destination: _HomeDestination.bean,
    targets: [_OnboardingTourTarget.createMenu],
  ),
  _OnboardingTourStepConfig(
    title: 'Calendar views',
    caption:
        'These controls bring you back to today or open the current month without losing your place.',
    destination: _HomeDestination.today,
    targets: [_OnboardingTourTarget.calendarControls],
  ),
  _OnboardingTourStepConfig(
    title: 'Tasks',
    caption:
        'Tasks are for things you need to complete. Bean can create them from a sentence, and you can check them off when done.',
    destination: _HomeDestination.tasks,
    targets: [_OnboardingTourTarget.tasksView],
  ),
  _OnboardingTourStepConfig(
    title: 'Reminders',
    caption:
        'Reminders are lightweight nudges. Use them for quick time-based follow-up without cluttering your task list.',
    destination: _HomeDestination.reminders,
    targets: [_OnboardingTourTarget.remindersView],
  ),
  _OnboardingTourStepConfig(
    title: 'Notes',
    caption:
        'Notes hold plans, lists, and longer writing. Keep longer work here when chat needs to turn into something durable.',
    destination: _HomeDestination.notes,
    targets: [_OnboardingTourTarget.notesView],
  ),
  _OnboardingTourStepConfig(
    title: 'Import your calendar',
    caption:
        'Bring in the calendar you already use. Choose Apple, Google, Outlook, Proton, Yahoo, Fastmail, Nextcloud, or any iCal link.',
    destination: _HomeDestination.settings,
    targets: [],
  ),
];

class _OnboardingTourOverlay extends StatelessWidget {
  const _OnboardingTourOverlay({
    required this.stepIndex,
    required this.step,
    required this.targetKeys,
    required this.primaryLabel,
    required this.onNext,
    required this.onSkip,
    required this.onFinish,
  });

  final int stepIndex;
  final _OnboardingTourStepConfig step;
  final Map<_OnboardingTourTarget, GlobalKey> targetKeys;
  final String primaryLabel;
  final VoidCallback onNext;
  final VoidCallback onSkip;
  final VoidCallback onFinish;

  bool get _isLast => stepIndex >= _appOnboardingTourSteps.length - 1;

  _OnboardingTourHighlightMetrics? _highlightMetrics(Size screenSize) {
    Rect? rect;
    for (final target in step.targets) {
      final context = targetKeys[target]?.currentContext;
      final renderObject = context?.findRenderObject();
      if (renderObject is! RenderBox || !renderObject.hasSize) continue;
      final origin = renderObject.localToGlobal(Offset.zero);
      final nextRect = origin & renderObject.size;
      rect = rect == null ? nextRect : rect.expandToInclude(nextRect);
    }
    if (rect == null) return null;
    final minDimension = math.min(rect.width, rect.height);
    final padding = minDimension <= 56
        ? 8.0
        : minDimension <= 120
        ? 10.0
        : 12.0;
    final adjusted = Rect.fromLTRB(
      math.max(8, rect.left - padding),
      math.max(8, rect.top - padding),
      math.min(screenSize.width - 8, rect.right + padding),
      math.min(screenSize.height - 8, rect.bottom + padding),
    );
    final radius = math.max(16.0, math.min(24.0, minDimension * .28));
    return _OnboardingTourHighlightMetrics(
      rect: adjusted,
      borderRadius: BorderRadius.circular(radius),
    );
  }

  _OnboardingTourCardLayout _cardLayout({
    required Size screenSize,
    required EdgeInsets safe,
    required double bottomMenuHeight,
    required _OnboardingTourHighlightMetrics? highlight,
  }) {
    const sideMargin = 16.0;
    const gap = 18.0;
    const estimatedHeight = 198.0;
    final width = math.min(420.0, screenSize.width - sideMargin * 2);
    final safeTop = safe.top + 16;
    final maxTop = math.max(
      safeTop,
      screenSize.height - bottomMenuHeight - estimatedHeight - 16,
    );
    final anchorRect = highlight?.rect;
    final left = anchorRect == null
        ? (screenSize.width - width) / 2
        : (anchorRect.center.dx - width / 2).clamp(
            sideMargin,
            screenSize.width - sideMargin - width,
          );
    if (anchorRect == null) {
      return _OnboardingTourCardLayout(left: left, top: maxTop, width: width);
    }
    final availableBelow =
        screenSize.height - bottomMenuHeight - anchorRect.bottom - 16;
    final availableAbove = anchorRect.top - safeTop;
    final preferredBelow = anchorRect.bottom + gap;
    final preferredAbove = anchorRect.top - estimatedHeight - gap;
    final top = availableBelow >= estimatedHeight + gap
        ? preferredBelow
        : availableAbove >= estimatedHeight + gap
        ? preferredAbove
        : maxTop;
    return _OnboardingTourCardLayout(
      left: left,
      top: top.clamp(safeTop, maxTop),
      width: width,
    );
  }

  @override
  Widget build(BuildContext context) {
    final media = MediaQuery.of(context);
    final safe = media.padding;
    final dockBottomPadding = safe.bottom > 0 ? safe.bottom + 2 : 6.0;
    final bottomMenuHeight = 74.0 + dockBottomPadding;

    return Positioned.fill(
      key: const Key('onboarding-tour-overlay'),
      child: Material(
        color: Colors.transparent,
        child: LayoutBuilder(
          builder: (context, constraints) {
            final highlight = _highlightMetrics(constraints.biggest);
            final cardLayout = _cardLayout(
              screenSize: constraints.biggest,
              safe: safe,
              bottomMenuHeight: bottomMenuHeight,
              highlight: highlight,
            );
            return Stack(
              children: [
                if (highlight != null)
                  _TourSpotlightScrim(
                    rect: highlight.rect,
                    borderRadius: highlight.borderRadius,
                  )
                else
                  Positioned.fill(
                    child: IgnorePointer(
                      child: DecoratedBox(
                        decoration: BoxDecoration(
                          color: Colors.black.withValues(alpha: .52),
                        ),
                      ),
                    ),
                  ),
                if (highlight != null)
                  _TourHighlight(
                    rect: highlight.rect,
                    borderRadius: highlight.borderRadius,
                  ),
                Positioned(
                  left: cardLayout.left,
                  top: cardLayout.top,
                  width: cardLayout.width,
                  child: _TourCaptionCard(
                    title: step.title,
                    progressLabel:
                        '${stepIndex + 1}/${_appOnboardingTourSteps.length}',
                    caption: step.caption,
                    isLast: _isLast,
                    primaryLabel: primaryLabel,
                    onNext: onNext,
                    onSkip: onSkip,
                    onFinish: onFinish,
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}

class _TourSpotlightScrim extends StatelessWidget {
  const _TourSpotlightScrim({required this.rect, required this.borderRadius});

  final Rect rect;
  final BorderRadius borderRadius;

  @override
  Widget build(BuildContext context) {
    final screen = MediaQuery.of(context).size;
    final scrimColor = Colors.black.withValues(alpha: .52);
    final topHeight = rect.top.clamp(0.0, screen.height);
    final leftWidth = rect.left.clamp(0.0, screen.width);
    final rightLeft = rect.right.clamp(0.0, screen.width);
    final holeHeight = rect.height.clamp(0.0, screen.height);
    final bottomTop = rect.bottom.clamp(0.0, screen.height);

    return IgnorePointer(
      child: Stack(
        children: [
          Positioned(
            left: 0,
            right: 0,
            top: 0,
            height: topHeight,
            child: DecoratedBox(decoration: BoxDecoration(color: scrimColor)),
          ),
          Positioned(
            left: 0,
            width: leftWidth,
            top: topHeight,
            height: holeHeight,
            child: DecoratedBox(decoration: BoxDecoration(color: scrimColor)),
          ),
          Positioned(
            left: rightLeft,
            right: 0,
            top: topHeight,
            height: holeHeight,
            child: DecoratedBox(decoration: BoxDecoration(color: scrimColor)),
          ),
          Positioned(
            left: 0,
            right: 0,
            top: bottomTop,
            bottom: 0,
            child: DecoratedBox(decoration: BoxDecoration(color: scrimColor)),
          ),
          Positioned.fromRect(
            rect: rect,
            child: DecoratedBox(
              decoration: BoxDecoration(borderRadius: borderRadius),
            ),
          ),
        ],
      ),
    );
  }
}

class _TourHighlight extends StatelessWidget {
  const _TourHighlight({required this.rect, required this.borderRadius});

  final Rect rect;
  final BorderRadius borderRadius;

  @override
  Widget build(BuildContext context) => Positioned.fromRect(
    rect: rect,
    child: IgnorePointer(
      child: Container(
        key: const Key('onboarding-tour-highlight'),
        decoration: BoxDecoration(
          borderRadius: borderRadius,
          border: Border.all(
            color: Colors.white.withValues(alpha: .92),
            width: 2,
          ),
          boxShadow: [
            BoxShadow(
              color: const Color(0x24020617),
              blurRadius: 16,
              offset: const Offset(0, 10),
            ),
            BoxShadow(
              color: HeyBeanTheme.accent.withValues(alpha: .18),
              blurRadius: 24,
              spreadRadius: 4,
            ),
          ],
        ),
      ),
    ),
  );
}

class _TourCaptionCard extends StatelessWidget {
  const _TourCaptionCard({
    required this.title,
    required this.progressLabel,
    required this.caption,
    required this.isLast,
    required this.primaryLabel,
    required this.onNext,
    required this.onSkip,
    required this.onFinish,
  });

  final String title;
  final String progressLabel;
  final String caption;
  final bool isLast;
  final String primaryLabel;
  final VoidCallback onNext;
  final VoidCallback onSkip;
  final VoidCallback onFinish;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.fromLTRB(18, 18, 18, 16),
    decoration: BoxDecoration(
      color: HeyBeanTheme.surface,
      borderRadius: BorderRadius.circular(18),
      border: Border.all(color: HeyBeanTheme.accent.withValues(alpha: .18)),
      boxShadow: const [
        BoxShadow(
          color: Color(0x22020617),
          blurRadius: 32,
          offset: Offset(0, 18),
        ),
      ],
    ),
    child: Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                title,
                style: TextStyle(
                  color: HeyBeanTheme.text,
                  decoration: TextDecoration.none,
                  fontSize: 17,
                  fontWeight: FontWeight.w900,
                ),
              ),
            ),
            Text(
              progressLabel,
              style: TextStyle(
                color: HeyBeanTheme.muted,
                decoration: TextDecoration.none,
                fontSize: 13,
                fontWeight: FontWeight.w800,
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        Text(
          caption,
          key: const Key('onboarding-tour-caption'),
          style: TextStyle(
            color: HeyBeanTheme.text,
            decoration: TextDecoration.none,
            fontSize: 16,
            fontWeight: FontWeight.w800,
            height: 1.32,
          ),
        ),
        const SizedBox(height: 12),
        Row(
          children: [
            TextButton(
              key: const Key('onboarding-tour-skip'),
              onPressed: onSkip,
              child: Text('Skip'),
            ),
            const Spacer(),
            FilledButton(
              key: Key(
                isLast ? 'onboarding-tour-finish' : 'onboarding-tour-next',
              ),
              onPressed: isLast ? onFinish : onNext,
              child: Text(primaryLabel),
            ),
          ],
        ),
      ],
    ),
  );
}

class _BeanIntroCallout extends StatelessWidget {
  const _BeanIntroCallout({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Material(
    type: MaterialType.transparency,
    child: GestureDetector(
      key: const Key('bean-intro-callout'),
      onTap: onTap,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: BoxDecoration(
              color: HeyBeanTheme.surface,
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: HeyBeanTheme.accent),
              boxShadow: [
                BoxShadow(
                  color: HeyBeanTheme.accent.withValues(alpha: .14),
                  blurRadius: 24,
                  offset: const Offset(0, 10),
                ),
              ],
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.eco_rounded, color: HeyBeanTheme.accentStrong),
                const SizedBox(width: 10),
                Flexible(
                  child: Text(
                    'Start by introducing yourself to Bean',
                    key: Key('bean-intro-callout-text'),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: HeyBeanTheme.text,
                      decoration: TextDecoration.none,
                      fontSize: 16,
                      fontWeight: FontWeight.w800,
                      height: 1.15,
                    ),
                  ),
                ),
              ],
            ),
          ),
          CustomPaint(
            key: const Key('bean-intro-callout-arrow'),
            size: const Size(28, 22),
            painter: _BeanIntroArrowPainter(),
          ),
        ],
      ),
    ),
  );
}

class _BeanIntroSpotlightOverlay extends StatelessWidget {
  const _BeanIntroSpotlightOverlay({required this.onBeanTap});

  final VoidCallback onBeanTap;

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.paddingOf(context).bottom;
    final dockBottomPadding = bottomInset > 0 ? bottomInset + 2 : 6.0;
    final bottomMenuHeight = 78.0 + dockBottomPadding;
    const beanFabTopOffset = 7.0;
    const beanFabSize = 98.0;
    const beanButtonDiameter = 72.0;
    const arrowGap = 4.0;
    final beanFabBottom =
        78.0 + dockBottomPadding - beanFabTopOffset - beanFabSize;
    final beanButtonTopFromBottom =
        beanFabBottom + (beanFabSize + beanButtonDiameter) / 2;
    final calloutBottom = beanButtonTopFromBottom + arrowGap;

    return Positioned.fill(
      key: const Key('bean-intro-spotlight-overlay'),
      child: Stack(
        alignment: Alignment.bottomCenter,
        children: [
          Positioned(
            left: 0,
            right: 0,
            top: 0,
            bottom: bottomMenuHeight,
            child: IgnorePointer(
              child: ColoredBox(color: Colors.black.withValues(alpha: .32)),
            ),
          ),
          Positioned(
            left: 24,
            right: 24,
            bottom: calloutBottom,
            child: _BeanIntroCallout(onTap: onBeanTap),
          ),
        ],
      ),
    );
  }
}

class _BeanIntroArrowPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = HeyBeanTheme.accentStrong
      ..style = PaintingStyle.fill;
    final path = Path()
      ..moveTo(size.width / 2, size.height)
      ..lineTo(0, 0)
      ..lineTo(size.width, 0)
      ..close();
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
