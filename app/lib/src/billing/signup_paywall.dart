part of '../../main.dart';

class _SignupPlanOption {
  const _SignupPlanOption({
    required this.key,
    required this.label,
    required this.price,
    required this.description,
    required this.trialText,
    required this.actionLabel,
    required this.finePrint,
    required this.features,
    this.yearlyPrice,
    this.priceSuffix,
    this.popular = false,
    this.startsCheckout = true,
  });

  final String key;
  final String label;
  final String price;
  final String? yearlyPrice;
  final String description;
  final String trialText;
  final String actionLabel;
  final String finePrint;
  final List<String> features;
  final String? priceSuffix;
  final bool popular;
  final bool startsCheckout;
}

class _SignupPaywallScreen extends StatefulWidget {
  const _SignupPaywallScreen({
    required this.user,
    required this.busyPlan,
    required this.error,
    required this.onSelectPlan,
    required this.onContactEnterprise,
    required this.onContinue,
    required this.onSignOut,
  });

  final BeanUser user;
  final String? busyPlan;
  final String? error;
  final Future<void> Function(String plan, String billingInterval) onSelectPlan;
  final VoidCallback onContactEnterprise;
  final Future<void> Function() onContinue;
  final Future<void> Function() onSignOut;

  @override
  State<_SignupPaywallScreen> createState() => _SignupPaywallScreenState();
}

class _SignupPaywallScreenState extends State<_SignupPaywallScreen> {
  String _billingInterval = 'monthly';

  bool get _busy => widget.busyPlan != null;

  @override
  Widget build(BuildContext context) => SingleChildScrollView(
    key: const Key('signup-paywall-screen'),
    padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
    child: Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 560),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.only(left: 4, bottom: 8),
              child: Text(
                'Account created for ${widget.user.name}.',
                style: TextStyle(
                  color: HeyBeanTheme.muted,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
            _ShellCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        width: 30,
                        height: 30,
                        alignment: Alignment.center,
                        child: Icon(
                          Icons.workspace_premium_rounded,
                          color: HeyBeanTheme.accentStrong,
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Choose your HeyBean subscription',
                              style: Theme.of(context).textTheme.titleLarge
                                  ?.copyWith(fontWeight: FontWeight.w900),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),
                  Text(
                    'Start with a $_subscriptionTrialLabel. Pick the plan that best fits your needs.',
                    style: TextStyle(
                      color: HeyBeanTheme.muted,
                      height: 1.45,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 12),
            _BillingIntervalToggle(
              selected: _billingInterval,
              onChanged: (value) {
                setState(
                  () => _billingInterval = _normalizedBillingInterval(value),
                );
              },
            ),
            const SizedBox(height: 12),
            if (widget.error != null) ...[
              _InlinePlanLimitError(message: widget.error!),
              const SizedBox(height: 12),
            ],
            for (final plan in _signupPlanOptions) ...[
              _SignupPlanCard(
                plan: plan,
                billingInterval: _billingInterval,
                busy: widget.busyPlan == plan.key,
                disabled: _busy && widget.busyPlan != plan.key,
                onPressed: plan.startsCheckout
                    ? () => widget.onSelectPlan(plan.key, _billingInterval)
                    : widget.onContactEnterprise,
              ),
              const SizedBox(height: 12),
            ],
            _ShellCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Already subscribed?',
                    style: TextStyle(
                      color: HeyBeanTheme.text,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'If your account was updated on another device, refresh here to check the latest subscription status.',
                    style: TextStyle(
                      color: HeyBeanTheme.muted,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    key: const Key('signup-paywall-refresh-action'),
                    onPressed: _busy ? null : widget.onContinue,
                    icon: Icon(Icons.refresh_rounded),
                    label: Text('Refresh subscription status'),
                  ),
                  TextButton(
                    key: const Key('signup-paywall-sign-out-action'),
                    onPressed: _busy ? null : widget.onSignOut,
                    child: Text('Use a different account'),
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

class _SignupPlanCard extends StatelessWidget {
  const _SignupPlanCard({
    required this.plan,
    required this.billingInterval,
    required this.busy,
    required this.disabled,
    required this.onPressed,
  });

  final _SignupPlanOption plan;
  final String billingInterval;
  final bool busy;
  final bool disabled;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final prominent = plan.popular;
    final foreground = HeyBeanTheme.text;
    final muted = HeyBeanTheme.muted;
    final borderColor = prominent
        ? HeyBeanTheme.accent.withValues(alpha: .42)
        : HeyBeanTheme.border;
    return Container(
      key: Key('signup-plan-${plan.key}'),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.transparent,
        border: Border(
          top: BorderSide(color: borderColor, width: prominent ? 2 : 1),
          bottom: BorderSide(
            color: _quietBorderColor(alpha: prominent ? .48 : .34),
          ),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Flexible(
                          child: Text(
                            plan.label,
                            style: TextStyle(
                              color: foreground,
                              fontSize: 22,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                        ),
                        if (prominent) ...[
                          const SizedBox(width: 8),
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 8,
                              vertical: 4,
                            ),
                            decoration: BoxDecoration(
                              color: const Color(0xFFFDE68A),
                              borderRadius: BorderRadius.circular(
                                HeyBeanTheme.zeroChromeRadius,
                              ),
                            ),
                            child: Text(
                              'Most popular',
                              style: TextStyle(
                                color: Color(0xFF7C4A03),
                                fontSize: 11,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                          ),
                        ],
                      ],
                    ),
                    const SizedBox(height: 4),
                    Text(
                      plan.description,
                      style: TextStyle(
                        color: muted,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    _planDisplayPrice(plan, billingInterval),
                    style: TextStyle(
                      color: foreground,
                      fontSize: 24,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  if (_planDisplayPriceSuffix(plan, billingInterval) != null)
                    Text(
                      _planDisplayPriceSuffix(plan, billingInterval)!,
                      style: TextStyle(
                        color: muted,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                ],
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            plan.startsCheckout
                ? _planTrialText(billingInterval)
                : plan.trialText,
            style: TextStyle(
              color: HeyBeanTheme.accentStrong,
              fontSize: 13,
              fontWeight: FontWeight.w900,
            ),
          ),
          const SizedBox(height: 12),
          for (final feature in plan.features)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    Icons.check_circle_rounded,
                    size: 18,
                    color: HeyBeanTheme.accentStrong,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      feature,
                      style: TextStyle(
                        color: HeyBeanTheme.text,
                        height: 1.3,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          const SizedBox(height: 6),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              key: Key('signup-plan-${plan.key}-action'),
              style: prominent
                  ? FilledButton.styleFrom(
                      backgroundColor: HeyBeanTheme.accent,
                      foregroundColor: HeyBeanTheme.accentInk,
                    )
                  : null,
              onPressed: disabled || busy ? null : onPressed,
              icon: busy
                  ? const SizedBox.square(
                      dimension: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Icon(
                      plan.startsCheckout
                          ? Icons.lock_rounded
                          : Icons.mail_outline_rounded,
                    ),
              label: Text(
                busy ? 'Opening secure payment...' : plan.actionLabel,
              ),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            plan.finePrint,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: muted,
              fontSize: 12,
              height: 1.3,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}

class _BillingIntervalToggle extends StatelessWidget {
  const _BillingIntervalToggle({
    required this.selected,
    required this.onChanged,
  });

  final String selected;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    final current = _normalizedBillingInterval(selected);
    return Container(
      key: const Key('billing-interval-toggle'),
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: Colors.transparent,
        border: Border(
          bottom: BorderSide(color: _quietBorderColor(alpha: .58)),
        ),
      ),
      child: Row(
        children: [
          _BillingIntervalButton(
            label: 'Monthly',
            selected: current == 'monthly',
            onPressed: () => onChanged('monthly'),
          ),
          _BillingIntervalButton(
            label: 'Yearly',
            detail: 'Save over 16%',
            selected: current == 'yearly',
            onPressed: () => onChanged('yearly'),
          ),
        ],
      ),
    );
  }
}

class _BillingIntervalButton extends StatelessWidget {
  const _BillingIntervalButton({
    required this.label,
    required this.selected,
    required this.onPressed,
    this.detail,
  });

  final String label;
  final String? detail;
  final bool selected;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => Expanded(
    child: TextButton(
      onPressed: selected ? null : onPressed,
      style: TextButton.styleFrom(
        foregroundColor: selected
            ? HeyBeanTheme.accentStrong
            : HeyBeanTheme.muted,
        backgroundColor: Colors.transparent,
        disabledForegroundColor: selected
            ? HeyBeanTheme.accentStrong
            : HeyBeanTheme.muted,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(HeyBeanTheme.zeroChromeRadius),
          side: BorderSide(
            color: selected ? HeyBeanTheme.accentStrong : Colors.transparent,
            width: selected ? 1.5 : 0,
          ),
        ),
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 7),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(label, style: TextStyle(fontWeight: FontWeight.w900)),
          if (detail != null)
            Text(
              detail!,
              style: TextStyle(fontSize: 10, fontWeight: FontWeight.w900),
            ),
        ],
      ),
    ),
  );
}
