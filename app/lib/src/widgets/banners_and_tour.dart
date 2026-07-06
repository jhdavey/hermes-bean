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

class _OnboardingTourOverlay extends StatelessWidget {
  const _OnboardingTourOverlay({
    required this.step,
    required this.onNext,
    required this.onSkip,
    required this.onFinish,
  });

  final int step;
  final VoidCallback onNext;
  final VoidCallback onSkip;
  final VoidCallback onFinish;

  String get _caption => switch (step) {
    0 => 'Hold for voice to text, or tap to type',
    1 => 'Create new events, tasks, and reminders here',
    2 =>
      "Your critical count includes today's critical events, and tasks that have been marked critical, or are overdue",
    _ => 'These will snap you back to the current day or month at any point',
  };

  String get _highlightKey => switch (step) {
    0 => 'bean',
    1 => 'create',
    2 => 'critical',
    _ => 'date-month',
  };

  @override
  Widget build(BuildContext context) {
    final media = MediaQuery.of(context);
    final safe = media.padding;
    final dockBottomPadding = safe.bottom > 0 ? safe.bottom + 2 : 6.0;
    final bottomMenuHeight = 78.0 + dockBottomPadding;

    return Positioned.fill(
      key: const Key('onboarding-tour-overlay'),
      child: Material(
        color: Colors.transparent,
        child: LayoutBuilder(
          builder: (context, constraints) {
            final size = constraints.biggest;
            final topCenterY = safe.top + 28.0;
            final highlight = switch (step) {
              0 => Rect.fromCenter(
                center: Offset(
                  size.width / 2,
                  size.height - bottomMenuHeight + 56,
                ),
                width: 96,
                height: 96,
              ),
              1 => Rect.fromCenter(
                center: Offset(size.width - 36, topCenterY),
                width: 58,
                height: 58,
              ),
              2 => Rect.fromCenter(
                center: Offset(size.width - 82, topCenterY),
                width: 58,
                height: 58,
              ),
              _ => Rect.fromLTWH(
                10,
                safe.top + 6,
                math.min(248, math.max(210, size.width - 128)),
                48,
              ),
            };
            final captionTop = step == 0 ? null : highlight.bottom + 18;
            final captionBottom = step == 0
                ? math.max(bottomMenuHeight + 92, safe.bottom + 174)
                : null;

            return Stack(
              children: [
                Positioned.fill(
                  child: ModalBarrier(
                    color: Colors.black.withValues(alpha: .52),
                    dismissible: false,
                  ),
                ),
                _TourHighlight(rect: highlight, targetKey: _highlightKey),
                Positioned(
                  left: 22,
                  right: 22,
                  top: captionTop == null
                      ? null
                      : math.min(captionTop, size.height - 224),
                  bottom: captionBottom,
                  child: _TourCaptionCard(
                    caption: _caption,
                    isLast: step >= 3,
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

class _TourHighlight extends StatelessWidget {
  const _TourHighlight({required this.rect, required this.targetKey});

  final Rect rect;
  final String targetKey;

  @override
  Widget build(BuildContext context) => Positioned.fromRect(
    rect: rect,
    child: IgnorePointer(
      child: Container(
        key: Key('onboarding-tour-highlight-$targetKey'),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(
            targetKey == 'date-month' ? 18 : 999,
          ),
          border: Border.all(color: Colors.white, width: 3),
          boxShadow: [
            BoxShadow(
              color: HeyBeanTheme.accent.withValues(alpha: .45),
              blurRadius: 30,
              spreadRadius: 10,
            ),
          ],
        ),
      ),
    ),
  );
}

class _TourCaptionCard extends StatelessWidget {
  const _TourCaptionCard({
    required this.caption,
    required this.isLast,
    required this.onNext,
    required this.onSkip,
    required this.onFinish,
  });

  final String caption;
  final bool isLast;
  final VoidCallback onNext;
  final VoidCallback onSkip;
  final VoidCallback onFinish;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(16),
    decoration: BoxDecoration(
      color: HeyBeanTheme.surface,
      borderRadius: BorderRadius.circular(20),
      border: Border.all(color: HeyBeanTheme.accent.withValues(alpha: .28)),
      boxShadow: const [
        BoxShadow(
          color: Color(0x26020617),
          blurRadius: 28,
          offset: Offset(0, 16),
        ),
      ],
    ),
    child: Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          caption,
          key: const Key('onboarding-tour-caption'),
          style: TextStyle(
            color: HeyBeanTheme.text,
            decoration: TextDecoration.none,
            fontSize: 17,
            fontWeight: FontWeight.w800,
            height: 1.25,
          ),
        ),
        const SizedBox(height: 14),
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
              child: Text(isLast ? 'Finish' : 'Next'),
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
