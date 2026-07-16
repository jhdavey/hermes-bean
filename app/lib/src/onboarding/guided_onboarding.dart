part of '../../main.dart';

enum _GuidedOnboardingStep {
  name,
  themeMode,
  email,
  password,
  tourChoice,
  tour,
  plan,
}

typedef _GuidedCreateAccount =
    Future<BeanAuthResult> Function(
      String name,
      String email,
      String password,
      String themeModeKey,
    );

class _GuidedBeanOnboardingScreen extends StatefulWidget {
  const _GuidedBeanOnboardingScreen({
    required this.apiClient,
    required this.stripePaymentHandler,
    required this.busyPlan,
    required this.checkoutError,
    required this.onCreateAccount,
    required this.onLaunchLiveTour,
    required this.onSelectPlan,
    required this.onRedeemCoupon,
    required this.onContactEnterprise,
    required this.onBackToLogin,
    required this.onPreviewThemeMode,
  });

  final BeanApiClient apiClient;
  final StripePaymentHandler stripePaymentHandler;
  final String? busyPlan;
  final String? checkoutError;
  final _GuidedCreateAccount onCreateAccount;
  final Future<void> Function() onLaunchLiveTour;
  final Future<void> Function(String plan, String billingInterval) onSelectPlan;
  final Future<void> Function(String code) onRedeemCoupon;
  final VoidCallback onContactEnterprise;
  final VoidCallback onBackToLogin;
  final ValueChanged<String> onPreviewThemeMode;

  @override
  State<_GuidedBeanOnboardingScreen> createState() =>
      _GuidedBeanOnboardingScreenState();
}

class _GuidedBeanOnboardingScreenState
    extends State<_GuidedBeanOnboardingScreen> {
  final _nameController = TextEditingController();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  final _nameFocus = FocusNode();
  final _emailFocus = FocusNode();
  final _passwordFocus = FocusNode();
  final _scrollController = ScrollController();

  _GuidedOnboardingStep _step = _GuidedOnboardingStep.name;
  String _name = '';
  String _email = '';
  String _themeModeKey = 'auto';
  bool _busy = false;
  String? _error;
  String _billingInterval = 'monthly';

  bool get _controlsEnabled => !_busy;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _nameFocus.requestFocus();
    });
  }

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    _passwordController.dispose();
    _nameFocus.dispose();
    _emailFocus.dispose();
    _passwordFocus.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _showStep(_GuidedOnboardingStep step) {
    setState(() {
      _step = step;
      _error = null;
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scrollController.hasClients) {
        _scrollController.animateTo(
          0,
          duration: const Duration(milliseconds: 220),
          curve: Curves.easeOut,
        );
      }
      switch (step) {
        case _GuidedOnboardingStep.name:
          _nameFocus.requestFocus();
        case _GuidedOnboardingStep.email:
          _emailFocus.requestFocus();
        case _GuidedOnboardingStep.password:
          _passwordFocus.requestFocus();
        case _GuidedOnboardingStep.themeMode:
        case _GuidedOnboardingStep.tourChoice:
        case _GuidedOnboardingStep.tour:
        case _GuidedOnboardingStep.plan:
          FocusManager.instance.primaryFocus?.unfocus();
      }
    });
  }

  void _setError(String message) {
    if (!mounted) return;
    setState(() => _error = message);
  }

  void _clearError() {
    if (_error != null) setState(() => _error = null);
  }

  void _submitName() {
    if (!_controlsEnabled) return;
    final name = _nameController.text.trim();
    if (name.length < 2) {
      _setError('Enter your name.');
      return;
    }
    _name = name;
    _showStep(_GuidedOnboardingStep.themeMode);
  }

  void _selectThemeMode(String key) {
    if (!_controlsEnabled) return;
    final matchingModes = heyBeanThemeModes.where((mode) => mode.key == key);
    if (matchingModes.length != 1) {
      _setError('Choose Light, Dark, or Auto.');
      return;
    }
    final mode = matchingModes.single;
    setState(() => _themeModeKey = mode.key);
    widget.onPreviewThemeMode(mode.key);
    _showStep(_GuidedOnboardingStep.email);
  }

  Future<void> _submitEmail() async {
    if (!_controlsEnabled) return;
    final email = _emailController.text.trim().toLowerCase();
    if (!_looksLikeEmailAddress(email)) {
      _setError(
        'Enter an email in the format name@example.com, without extra punctuation.',
      );
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final availability = await widget.apiClient.checkEmailAvailability(
        email: email,
      );
      if (!mounted) return;
      if (!availability.available) {
        setState(() {
          _busy = false;
          _error = 'That email is already connected to an account.';
        });
        _emailFocus.requestFocus();
        return;
      }
      _email = availability.email;
      setState(() => _busy = false);
      _showStep(_GuidedOnboardingStep.password);
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = 'Email availability could not be checked. Try again.';
      });
      _emailFocus.requestFocus();
    }
  }

  bool _looksLikeEmailAddress(String value) {
    if (value.length > 254) return false;
    return RegExp(
      r'^[a-z0-9._%+-]+@(?:[a-z0-9-]+\.)+[a-z]{2,}$',
      caseSensitive: false,
    ).hasMatch(value);
  }

  Future<void> _submitPassword() async {
    if (!_controlsEnabled) return;
    final password = _passwordController.text;
    if (password.length < 12) {
      _setError('Use at least 12 characters so your account is protected.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.onCreateAccount(_name, _email, password, _themeModeKey);
      if (!mounted) return;
      _passwordController.clear();
      setState(() => _busy = false);
      _showStep(_GuidedOnboardingStep.tourChoice);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = beanFriendlyErrorMessage(error, action: 'create your account');
      });
    }
  }

  Future<void> _startTour() async {
    if (!_controlsEnabled) return;
    setState(() {
      _step = _GuidedOnboardingStep.tour;
      _busy = true;
      _error = null;
    });
    try {
      await widget.onLaunchLiveTour();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _skipTour() {
    if (!_controlsEnabled) return;
    _showStep(_GuidedOnboardingStep.plan);
  }

  int get _stepNumber => switch (_step) {
    _GuidedOnboardingStep.name => 1,
    _GuidedOnboardingStep.themeMode => 2,
    _GuidedOnboardingStep.email => 3,
    _GuidedOnboardingStep.password => 4,
    _GuidedOnboardingStep.tourChoice || _GuidedOnboardingStep.tour => 5,
    _GuidedOnboardingStep.plan => 6,
  };

  String get _stepTitle => switch (_step) {
    _GuidedOnboardingStep.name => 'What is your name?',
    _GuidedOnboardingStep.themeMode => 'Choose your appearance',
    _GuidedOnboardingStep.email => 'Add your email',
    _GuidedOnboardingStep.password => 'Create a password',
    _GuidedOnboardingStep.tourChoice => 'Take a quick tour?',
    _GuidedOnboardingStep.tour => 'Preparing your tour',
    _GuidedOnboardingStep.plan => 'Choose your plan',
  };

  String get _stepDescription => switch (_step) {
    _GuidedOnboardingStep.name =>
      'Enter the name you want to see throughout the app.',
    _GuidedOnboardingStep.themeMode =>
      'Select one option. You can change it later in Appearance settings.',
    _GuidedOnboardingStep.email =>
      'This email will be used to sign in and verify your account.',
    _GuidedOnboardingStep.password =>
      'Use at least 12 characters. Your password stays hidden.',
    _GuidedOnboardingStep.tourChoice =>
      'Tour the dashboard now, or continue directly to plan setup.',
    _GuidedOnboardingStep.tour =>
      'The dashboard is loading. Plan setup follows the tour.',
    _GuidedOnboardingStep.plan =>
      'Select the subscription that fits how you plan to use HeyBean.',
  };

  Widget _buildStepContent() => switch (_step) {
    _GuidedOnboardingStep.name => _GuidedWizardTextField(
      fieldKey: const Key('guided-name-input'),
      actionKey: const Key('guided-name-continue'),
      controller: _nameController,
      focusNode: _nameFocus,
      label: 'Name',
      hint: 'Your name',
      enabled: _controlsEnabled,
      textInputAction: TextInputAction.next,
      autofillHints: const [AutofillHints.name],
      onChanged: (_) => _clearError(),
      onSubmit: _submitName,
    ),
    _GuidedOnboardingStep.themeMode => _GuidedThemeModePicker(
      selected: _themeModeKey,
      enabled: _controlsEnabled,
      onSelected: _selectThemeMode,
    ),
    _GuidedOnboardingStep.email => _GuidedWizardTextField(
      fieldKey: const Key('guided-email-input'),
      actionKey: const Key('guided-email-continue'),
      controller: _emailController,
      focusNode: _emailFocus,
      label: 'Email',
      hint: 'name@example.com',
      enabled: _controlsEnabled,
      keyboardType: TextInputType.emailAddress,
      textInputAction: TextInputAction.next,
      autofillHints: const [AutofillHints.email],
      onChanged: (_) => _clearError(),
      onSubmit: () => unawaited(_submitEmail()),
    ),
    _GuidedOnboardingStep.password => _GuidedWizardTextField(
      fieldKey: const Key('guided-password-input'),
      actionKey: const Key('guided-password-continue'),
      controller: _passwordController,
      focusNode: _passwordFocus,
      label: 'Password',
      hint: 'At least 12 characters',
      enabled: _controlsEnabled,
      obscureText: true,
      textInputAction: TextInputAction.done,
      autofillHints: const [AutofillHints.newPassword],
      onChanged: (_) => _clearError(),
      onSubmit: () => unawaited(_submitPassword()),
    ),
    _GuidedOnboardingStep.tourChoice => _GuidedTourChoiceActions(
      enabled: _controlsEnabled,
      onTour: () => unawaited(_startTour()),
      onSkip: _skipTour,
    ),
    _GuidedOnboardingStep.tour => const Center(
      child: Padding(
        padding: EdgeInsets.symmetric(vertical: 24),
        child: CircularProgressIndicator(),
      ),
    ),
    _GuidedOnboardingStep.plan => _GuidedPlanPicker(
      billingInterval: _billingInterval,
      busyPlan: widget.busyPlan,
      error: widget.checkoutError,
      onBillingChanged: (value) =>
          setState(() => _billingInterval = _normalizedBillingInterval(value)),
      onSelect: (plan) => widget.onSelectPlan(plan, _billingInterval),
      onRedeemCoupon: widget.onRedeemCoupon,
      onContactEnterprise: widget.onContactEnterprise,
    ),
  };

  @override
  Widget build(BuildContext context) => SafeArea(
    child: Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(18, 10, 18, 0),
          child: Row(
            children: [
              TextButton.icon(
                onPressed: _busy ? null : widget.onBackToLogin,
                icon: const Icon(Icons.arrow_back_rounded),
                label: const Text('Login'),
              ),
              const Spacer(),
              Text(
                'Step $_stepNumber of 6',
                key: const Key('guided-setup-progress-label'),
                style: TextStyle(
                  color: HeyBeanTheme.muted,
                  fontWeight: FontWeight.w800,
                  fontSize: 12,
                ),
              ),
            ],
          ),
        ),
        Expanded(
          child: ListView(
            controller: _scrollController,
            padding: const EdgeInsets.fromLTRB(20, 18, 20, 48),
            children: [
              _GuidedSetupHeader(stepNumber: _stepNumber),
              const SizedBox(height: 18),
              if (_step == _GuidedOnboardingStep.plan)
                _buildStepContent()
              else
                _GuidedWizardCard(
                  key: ValueKey(_step),
                  title: _stepTitle,
                  description: _stepDescription,
                  error: _error,
                  busy: _busy,
                  child: _buildStepContent(),
                ),
            ],
          ),
        ),
      ],
    ),
  );
}

class _GuidedSetupHeader extends StatelessWidget {
  const _GuidedSetupHeader({required this.stepNumber});

  final int stepNumber;

  @override
  Widget build(BuildContext context) => Column(
    children: [
      Image.asset(
        HeyBeanTheme.isDark
            ? 'assets/images/bean/bean-logo-white-overlay.png'
            : 'assets/images/bean/bean-logo.png',
        key: const Key('guided-setup-bean-logo'),
        width: 72,
        height: 72,
        fit: BoxFit.contain,
      ),
      const SizedBox(height: 10),
      Text(
        'Account setup',
        key: const Key('guided-setup-title'),
        style: Theme.of(
          context,
        ).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w900),
      ),
      const SizedBox(height: 12),
      ClipRRect(
        borderRadius: BorderRadius.circular(999),
        child: LinearProgressIndicator(
          key: const Key('guided-setup-progress'),
          value: stepNumber / 6,
          minHeight: 7,
          backgroundColor: HeyBeanTheme.surface2,
          color: HeyBeanTheme.accentStrong,
        ),
      ),
    ],
  );
}

class _GuidedWizardCard extends StatelessWidget {
  const _GuidedWizardCard({
    super.key,
    required this.title,
    required this.description,
    required this.error,
    required this.busy,
    required this.child,
  });

  final String title;
  final String description;
  final String? error;
  final bool busy;
  final Widget child;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          title,
          style: Theme.of(
            context,
          ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w900),
        ),
        const SizedBox(height: 7),
        Text(
          description,
          style: TextStyle(
            color: HeyBeanTheme.muted,
            height: 1.4,
            fontWeight: FontWeight.w600,
          ),
        ),
        if (error != null) ...[
          const SizedBox(height: 14),
          Container(
            key: const Key('guided-setup-error'),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: HeyBeanTheme.destructive.withValues(alpha: .12),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(
                color: HeyBeanTheme.destructive.withValues(alpha: .32),
              ),
            ),
            child: Text(
              error!,
              style: TextStyle(
                color: HeyBeanTheme.destructive,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
        ],
        const SizedBox(height: 20),
        child,
        if (busy) ...[
          const SizedBox(height: 16),
          const Center(
            child: SizedBox(
              width: 24,
              height: 24,
              child: CircularProgressIndicator(strokeWidth: 3),
            ),
          ),
        ],
      ],
    ),
  );
}

class _GuidedWizardTextField extends StatelessWidget {
  const _GuidedWizardTextField({
    required this.fieldKey,
    required this.actionKey,
    required this.controller,
    required this.focusNode,
    required this.label,
    required this.hint,
    required this.enabled,
    required this.textInputAction,
    required this.autofillHints,
    required this.onChanged,
    required this.onSubmit,
    this.keyboardType,
    this.obscureText = false,
  });

  final Key fieldKey;
  final Key actionKey;
  final TextEditingController controller;
  final FocusNode focusNode;
  final String label;
  final String hint;
  final bool enabled;
  final bool obscureText;
  final TextInputType? keyboardType;
  final TextInputAction textInputAction;
  final Iterable<String> autofillHints;
  final ValueChanged<String> onChanged;
  final VoidCallback onSubmit;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      TextField(
        key: fieldKey,
        controller: controller,
        focusNode: focusNode,
        enabled: enabled,
        obscureText: obscureText,
        keyboardType: keyboardType,
        textInputAction: textInputAction,
        autofillHints: autofillHints,
        onChanged: onChanged,
        onSubmitted: (_) => onSubmit(),
        decoration: InputDecoration(labelText: label, hintText: hint),
      ),
      const SizedBox(height: 14),
      FilledButton.icon(
        key: actionKey,
        onPressed: enabled ? onSubmit : null,
        icon: const Icon(Icons.arrow_forward_rounded),
        label: const Text('Continue'),
      ),
    ],
  );
}

class _GuidedThemeModePicker extends StatelessWidget {
  const _GuidedThemeModePicker({
    required this.selected,
    required this.enabled,
    required this.onSelected,
  });

  static const _orderedThemeModeKeys = ['light', 'dark', 'auto'];

  final String selected;
  final bool enabled;
  final ValueChanged<String> onSelected;

  @override
  Widget build(BuildContext context) => Wrap(
    spacing: 8,
    runSpacing: 8,
    children: [
      for (final key in _orderedThemeModeKeys)
        Builder(
          builder: (context) {
            final mode = heyBeanThemeModeForKey(key);
            return ChoiceChip(
              key: Key('guided-theme-mode-${mode.key}'),
              selected: selected == mode.key,
              label: Text(mode.label),
              avatar: Icon(_themeModeIcon(mode.key), size: 18),
              onSelected: enabled ? (_) => onSelected(mode.key) : null,
            );
          },
        ),
    ],
  );
}

class _GuidedTourChoiceActions extends StatelessWidget {
  const _GuidedTourChoiceActions({
    required this.enabled,
    required this.onTour,
    required this.onSkip,
  });

  final bool enabled;
  final VoidCallback onTour;
  final VoidCallback onSkip;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Expanded(
        child: FilledButton.icon(
          key: const Key('guided-tour-start'),
          onPressed: enabled ? onTour : null,
          icon: const Icon(Icons.play_circle_rounded),
          label: const Text('Take tour'),
        ),
      ),
      const SizedBox(width: 10),
      OutlinedButton(
        key: const Key('guided-tour-skip'),
        onPressed: enabled ? onSkip : null,
        child: const Text('Skip tour'),
      ),
    ],
  );
}

class _GuidedPlanPicker extends StatelessWidget {
  const _GuidedPlanPicker({
    required this.billingInterval,
    required this.busyPlan,
    required this.error,
    required this.onBillingChanged,
    required this.onSelect,
    required this.onRedeemCoupon,
    required this.onContactEnterprise,
  });

  final String billingInterval;
  final String? busyPlan;
  final String? error;
  final ValueChanged<String> onBillingChanged;
  final ValueChanged<String> onSelect;
  final Future<void> Function(String code) onRedeemCoupon;
  final VoidCallback onContactEnterprise;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      _ShellCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Start with a $_subscriptionTrialLabel.',
              style: Theme.of(
                context,
              ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 6),
            Text(
              'Choose the plan that fits your calendars, tasks, reminders, notes, workspaces, and history needs.',
              style: TextStyle(
                color: HeyBeanTheme.muted,
                height: 1.4,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
      ),
      const SizedBox(height: 12),
      _BillingIntervalToggle(
        selected: billingInterval,
        onChanged: onBillingChanged,
      ),
      const SizedBox(height: 12),
      if (error != null) ...[
        _InlinePlanLimitError(message: error!),
        const SizedBox(height: 12),
      ],
      for (final plan in _signupPlanOptions) ...[
        _SignupPlanCard(
          plan: plan,
          billingInterval: billingInterval,
          busy: busyPlan == plan.key,
          disabled: busyPlan != null && busyPlan != plan.key,
          onPressed: plan.startsCheckout
              ? () => onSelect(plan.key)
              : onContactEnterprise,
        ),
        const SizedBox(height: 12),
      ],
      _CouponCodeCard(
        key: const Key('guided-coupon-card'),
        busy: busyPlan == 'coupon',
        disabled: busyPlan != null && busyPlan != 'coupon',
        onRedeem: onRedeemCoupon,
      ),
    ],
  );
}
