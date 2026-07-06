part of '../../main.dart';

enum _CreateItemAction { event, task, reminder, note }

class _CreateItemMenu extends StatelessWidget {
  const _CreateItemMenu({
    required this.onCreateEvent,
    required this.onCreateTask,
    required this.onCreateReminder,
    required this.onCreateNote,
  });

  final Future<void> Function() onCreateEvent;
  final Future<void> Function() onCreateTask;
  final Future<void> Function() onCreateReminder;
  final Future<void> Function() onCreateNote;

  @override
  Widget build(BuildContext context) => PopupMenuButton<_CreateItemAction>(
    key: const Key('create-item-menu'),
    tooltip: 'Create',
    position: PopupMenuPosition.under,
    offset: const Offset(0, 8),
    onSelected: (action) {
      switch (action) {
        case _CreateItemAction.event:
          unawaited(onCreateEvent());
          return;
        case _CreateItemAction.task:
          unawaited(onCreateTask());
          return;
        case _CreateItemAction.reminder:
          unawaited(onCreateReminder());
          return;
        case _CreateItemAction.note:
          unawaited(onCreateNote());
          return;
      }
    },
    itemBuilder: (context) => [
      const PopupMenuItem<_CreateItemAction>(
        key: Key('create-event-action'),
        value: _CreateItemAction.event,
        child: _CreateItemMenuRow(icon: Icons.event_rounded, label: 'Event'),
      ),
      const PopupMenuItem<_CreateItemAction>(
        key: Key('create-task-action'),
        value: _CreateItemAction.task,
        child: _CreateItemMenuRow(icon: Icons.task_alt_rounded, label: 'Task'),
      ),
      const PopupMenuItem<_CreateItemAction>(
        key: Key('create-reminder-action'),
        value: _CreateItemAction.reminder,
        child: _CreateItemMenuRow(
          icon: Icons.notifications_active_rounded,
          label: 'Reminder',
        ),
      ),
      PopupMenuItem<_CreateItemAction>(
        key: const Key('create-note-action'),
        value: _CreateItemAction.note,
        child: _CreateItemMenuRow(
          iconWidget: _BeanNotesIcon(
            size: 18,
            color: HeyBeanTheme.accentStrong,
          ),
          label: 'Note',
        ),
      ),
    ],
    child: const _ThemedPlusButtonChrome(key: Key('create-item-menu-button')),
  );
}

class _ThemedPlusButton extends StatelessWidget {
  const _ThemedPlusButton({
    super.key,
    required this.tooltip,
    required this.onPressed,
  });

  final String tooltip;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) => IconButton(
    tooltip: tooltip,
    onPressed: onPressed,
    icon: Icon(Icons.add_rounded),
    style: IconButton.styleFrom(
      backgroundColor: onPressed == null
          ? HeyBeanTheme.border.withValues(alpha: .32)
          : HeyBeanTheme.accent.withValues(alpha: .12),
      foregroundColor: onPressed == null
          ? HeyBeanTheme.muted
          : HeyBeanTheme.accentStrong,
      side: BorderSide(
        color: onPressed == null
            ? HeyBeanTheme.border
            : HeyBeanTheme.accent.withValues(alpha: .24),
      ),
      fixedSize: const Size.square(40),
      minimumSize: const Size.square(40),
      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
    ),
  );
}

class _ThemedPlusButtonChrome extends StatelessWidget {
  const _ThemedPlusButtonChrome({super.key});

  @override
  Widget build(BuildContext context) => Container(
    width: 40,
    height: 40,
    alignment: Alignment.center,
    child: Icon(Icons.add_rounded, color: HeyBeanTheme.accentStrong, size: 30),
  );
}

class _CreateItemMenuRow extends StatelessWidget {
  const _CreateItemMenuRow({this.icon, this.iconWidget, required this.label});

  final IconData? icon;
  final Widget? iconWidget;
  final String label;

  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      iconWidget ?? Icon(icon, size: 18, color: HeyBeanTheme.accentStrong),
      const SizedBox(width: 10),
      Text(label, style: TextStyle(fontWeight: FontWeight.w700)),
    ],
  );
}

typedef _ForgotPasswordHandler = Future<void> Function(String email);

const List<_SignupPlanOption> _signupPlanOptions = [
  _SignupPlanOption(
    key: 'base',
    label: 'Base',
    price: r'$4.99',
    yearlyPrice: r'$49.99',
    priceSuffix: '/mo',
    description: 'For getting your personal day into one organized place.',
    trialText: '14-day free trial, then billed monthly',
    actionLabel: 'Start Base trial',
    finePrint: 'A simple place to begin with Bean.',
    features: [
      '2 workspaces for personal and shared planning',
      'Tasks, reminders, and calendar in one daily view',
      'Bean chat and voice for everyday requests',
      '1 connected calendar',
      'Up to 10 Notes for plans, lists, and longer writing',
      'Push reminders for the things you cannot miss',
      'Recent history so Bean can follow the thread of your day',
    ],
  ),
  _SignupPlanOption(
    key: 'premium',
    label: 'Premium',
    price: r'$19.99',
    yearlyPrice: r'$199.99',
    priceSuffix: '/mo',
    description:
        'For families and power users who want Bean woven into the daily routine.',
    trialText: '14-day free trial, then billed monthly',
    actionLabel: 'Start Premium trial',
    finePrint: 'Cancel before day 15 to avoid being billed.',
    features: [
      '5 workspaces for home, work, school, and projects',
      'Expanded Bean capacity for everyday planning',
      'Push and email reminders working together',
      'Recurring tasks and reminders for repeating routines',
      'Unlimited Notes with folders for plans, lists, and longer writing',
      'Multiple calendar connections',
      '1 year of searchable context and history',
      'The best fit for most households and busy personal lives',
    ],
    popular: true,
  ),
  _SignupPlanOption(
    key: 'pro',
    label: 'Pro',
    price: r'$49.99',
    yearlyPrice: r'$499.99',
    priceSuffix: '/mo',
    description:
        'For people who want Bean to run across every workspace, account, and recurring workflow.',
    trialText: '14-day free trial, then billed monthly',
    actionLabel: 'Start Pro trial',
    finePrint:
        'Built for users who want Bean available across the whole operating system of their day.',
    features: [
      'Unlimited workspaces for every area of life',
      'Maximum Bean capacity for high-volume days',
      'More room for connected tools and background work',
      'Unlimited connected accounts',
      'Unlimited Notes across every workspace',
      "Full Bean's Knowledge and history",
      'Priority background work when Bean is handling more',
      'Priority support',
    ],
  ),
  _SignupPlanOption(
    key: 'enterprise',
    label: 'Enterprise',
    price: 'Custom',
    description:
        'For teams and organizations that need custom support, rollout planning, and account-level coordination.',
    trialText: 'Contact us for pricing',
    actionLabel: 'Contact us',
    finePrint: 'We will help shape the right plan for your team.',
    features: [
      'Custom workspace and connected-account needs',
      'Admin planning for larger groups',
      'Dedicated setup guidance',
      "Custom Bean's Knowledge and retention discussions",
      'Priority support and rollout help',
      'Room for future enterprise controls',
      'A direct path for teams with special requirements',
    ],
    startsCheckout: false,
  ),
];

String _subscriptionPlanLabel(String plan) =>
    switch (plan.trim().toLowerCase()) {
      'premium' => 'Premium',
      'pro' => 'Pro',
      'enterprise' => 'Enterprise',
      _ => 'Base',
    };

bool _isStripePaymentCanceled(Object error) =>
    error.toString().toLowerCase().contains('cancel');

String _normalizedBillingInterval(String value) =>
    value.trim().toLowerCase() == 'yearly' ? 'yearly' : 'monthly';

String _planDisplayPrice(_SignupPlanOption plan, String billingInterval) =>
    _normalizedBillingInterval(billingInterval) == 'yearly'
    ? plan.yearlyPrice ?? plan.price
    : plan.price;

String? _planDisplayPriceSuffix(
  _SignupPlanOption plan,
  String billingInterval,
) {
  if (plan.priceSuffix == null && plan.yearlyPrice == null) return null;
  return _normalizedBillingInterval(billingInterval) == 'yearly'
      ? '/yr'
      : '/mo';
}

String _planTrialText(String billingInterval) =>
    '14-day free trial, then billed ${_normalizedBillingInterval(billingInterval) == 'yearly' ? 'yearly' : 'monthly'}';

String _billingIntervalLabel(String billingInterval) =>
    _normalizedBillingInterval(billingInterval) == 'yearly'
    ? 'yearly'
    : 'monthly';
