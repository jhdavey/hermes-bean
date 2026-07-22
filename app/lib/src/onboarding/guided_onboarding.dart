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

class _GuidedOnboardingMessage {
  const _GuidedOnboardingMessage({
    required this.bean,
    required this.text,
    this.masked = false,
    this.widgetKey,
  });

  final bool bean;
  final String text;
  final bool masked;
  final Key? widgetKey;
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
    required this.onSkipToPlainSignup,
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
  final VoidCallback onSkipToPlainSignup;
  final ValueChanged<String> onPreviewThemeMode;

  @override
  State<_GuidedBeanOnboardingScreen> createState() =>
      _GuidedBeanOnboardingScreenState();
}

class _GuidedBeanOnboardingScreenState
    extends State<_GuidedBeanOnboardingScreen> {
  final _input = TextEditingController();
  final _inputFocus = FocusNode();
  final _scrollController = ScrollController();
  final List<_GuidedOnboardingMessage> _messages = [
    const _GuidedOnboardingMessage(
      bean: true,
      text:
          'Hi, I’m Bean. I’ll help get your HeyBean account set up. What name should I call you?',
    ),
  ];

  _GuidedOnboardingStep _step = _GuidedOnboardingStep.name;
  String _name = '';
  String _email = '';
  String _themeModeKey = 'auto';
  bool _busy = false;
  bool _beanThinking = false;
  String? _error;
  String _billingInterval = 'monthly';
  int _tourIndex = 0;

  bool get _inputLocked =>
      _busy ||
      _beanThinking ||
      _step == _GuidedOnboardingStep.tour ||
      _step == _GuidedOnboardingStep.plan;

  bool get _showComposer =>
      _step != _GuidedOnboardingStep.tour &&
      _step != _GuidedOnboardingStep.plan;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _inputFocus.requestFocus();
    });
  }

  @override
  void dispose() {
    _input.dispose();
    _inputFocus.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _addBean(String text, {Key? widgetKey}) => _addMessage(
    _GuidedOnboardingMessage(bean: true, text: text, widgetKey: widgetKey),
  );

  void _addUser(String text, {bool masked = false}) => _addMessage(
    _GuidedOnboardingMessage(bean: false, text: text, masked: masked),
  );

  void _addMessage(_GuidedOnboardingMessage message) {
    setState(() => _messages.add(message));
    _scrollToBottom();
  }

  Future<void> _respondBean(String text, {Key? widgetKey}) async {
    setState(() => _beanThinking = true);
    _scrollToBottom(duration: const Duration(milliseconds: 120));
    await Future<void>.delayed(const Duration(milliseconds: 260));
    if (!mounted) return;
    setState(() => _beanThinking = false);
    _addBean(text, widgetKey: widgetKey);
  }

  void _scrollToBottom({
    Duration duration = const Duration(milliseconds: 220),
  }) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scrollController.hasClients) return;
      _scrollController.animateTo(
        _scrollController.position.maxScrollExtent,
        duration: duration,
        curve: Curves.easeOut,
      );
    });
  }

  Future<void> _submitDraft([String? override]) async {
    if (_inputLocked) return;
    final value = (override ?? _input.text).trim();
    if (value.isEmpty) return;
    _input.clear();
    setState(() => _error = null);

    switch (_step) {
      case _GuidedOnboardingStep.name:
        await _handleName(value);
      case _GuidedOnboardingStep.themeMode:
        await _handleThemeMode(value);
      case _GuidedOnboardingStep.email:
        await _handleEmail(value);
      case _GuidedOnboardingStep.password:
        await _handlePassword(override ?? value);
      case _GuidedOnboardingStep.tourChoice:
        await _handleTourChoice(value);
      case _GuidedOnboardingStep.tour:
      case _GuidedOnboardingStep.plan:
        break;
    }
  }

  Future<void> _handleName(String value) async {
    final name = value.trim();
    if (name.length < 2) {
      _setError('Please enter the name you want Bean to use.');
      return;
    }
    _name = name;
    _addUser(name);
    await _respondBean(
      'Nice to meet you, $_name. Do you prefer Light, Dark, or Auto mode? You can change this later in Appearance settings.',
    );
    setState(() => _step = _GuidedOnboardingStep.themeMode);
    _focusInput();
  }

  Future<void> _handleThemeMode(String value) async {
    final mode = _themeModeFromText(value);
    if (mode == null) {
      _setError('Choose Light, Dark, or Auto.');
      return;
    }
    await _selectThemeMode(mode.key);
  }

  Future<void> _selectThemeMode(String themeModeKey) async {
    final mode = heyBeanThemeModeForKey(themeModeKey);
    setState(() {
      _themeModeKey = mode.key;
      _error = null;
    });
    widget.onPreviewThemeMode(mode.key);
    _addUser(mode.label);
    await _respondBean(
      '${mode.label} it is. What email address would you like to use for your account?',
    );
    setState(() => _step = _GuidedOnboardingStep.email);
    _focusInput();
  }

  Future<void> _handleEmail(String value) async {
    final email = value.trim().toLowerCase();
    if (!_looksLikeEmailAddress(email)) {
      _setError(
        'That email does not look valid. Please text the address you want to use.',
      );
      return;
    }
    _addUser(email);
    setState(() => _busy = true);
    try {
      final availability = await widget.apiClient.checkEmailAvailability(
        email: email,
      );
      if (!mounted) return;
      setState(() => _busy = false);
      if (!availability.available) {
        await _respondBean(
          'That email is already connected to an account. Please send me a different one and I’ll check it.',
        );
        _focusInput();
        return;
      }
      _email = availability.email;
      await _respondBean(
        'Great. Now choose a password. Please text it here and I’ll keep it hidden.',
      );
      setState(() => _step = _GuidedOnboardingStep.password);
      _focusInput();
    } catch (_) {
      if (!mounted) return;
      setState(() => _busy = false);
      await _respondBean(
        'I could not check that email right now. Please try the email again in a moment.',
      );
      _focusInput();
    }
  }

  Future<void> _handlePassword(String value) async {
    if (value.length < 12) {
      _setError('Use at least 12 characters so your account is protected.');
      return;
    }
    _addUser('Password saved', masked: true);
    setState(() => _busy = true);
    try {
      await widget.onCreateAccount(_name, _email, value, _themeModeKey);
      if (!mounted) return;
      setState(() => _busy = false);
      await _respondBean(
        'Your account has been created. Would you like me to show you how to use your dashboard, or should we go straight to plan setup?',
      );
      setState(() => _step = _GuidedOnboardingStep.tourChoice);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = beanFriendlyErrorMessage(error, action: 'create your account');
      });
    }
  }

  Future<void> _handleTourChoice(String value) async {
    final normalized = value.toLowerCase();
    final yes = RegExp(
      r'\b(yes|yeah|yep|sure|show|tour)\b',
    ).hasMatch(normalized);
    final no = RegExp(
      r'\b(no|skip|straight|dashboard|plan)\b',
    ).hasMatch(normalized);
    if (yes) {
      _addUser(value);
      _startTour();
      return;
    }
    if (no) {
      _addUser(value);
      await _goToPlan(skipTour: true);
      return;
    }
    _setError(
      'Please answer yes for a quick tour, or no to go straight to plan setup.',
    );
  }

  void _startTour() {
    setState(() {
      _step = _GuidedOnboardingStep.tour;
      _tourIndex = 0;
      _error = null;
    });
    _scrollToBottom();
  }

  Future<void> _nextTour() async {
    if (_tourIndex >= _guidedTourSteps.length - 1) {
      await _goToPlan();
      return;
    }
    setState(() => _tourIndex += 1);
    _scrollToBottom();
  }

  Future<void> _goToPlan({bool skipTour = false}) async {
    await _respondBean(
      skipTour
          ? 'No problem. Let’s finish your plan setup so your free trial is ready. Choose the option that fits best.'
          : 'That’s the quick tour. Last step: choose your plan so your free trial is ready.',
    );
    setState(() => _step = _GuidedOnboardingStep.plan);
    _scrollToBottom();
  }

  bool _looksLikeEmailAddress(String value) {
    if (value.length > 254) return false;
    return RegExp(
      r'^[a-z0-9._%+-]+@(?:[a-z0-9-]+\.)+[a-z]{2,}$',
      caseSensitive: false,
    ).hasMatch(value);
  }

  HeyBeanThemeModeOption? _themeModeFromText(String value) {
    final normalized = value.trim().toLowerCase();
    if (normalized.isEmpty) return null;
    if (normalized == 'system' ||
        normalized == 'device' ||
        normalized == 'automatic') {
      return heyBeanThemeModeForKey('auto');
    }
    for (final mode in heyBeanThemeModes) {
      if (mode.key == normalized || mode.label.toLowerCase() == normalized) {
        return mode;
      }
    }
    return null;
  }

  void _setError(String message) => setState(() => _error = message);

  void _focusInput() => WidgetsBinding.instance.addPostFrameCallback(
    (_) => _inputFocus.requestFocus(),
  );

  String get _inputHint => switch (_step) {
    _GuidedOnboardingStep.name => 'Name',
    _GuidedOnboardingStep.themeMode => 'Choose Light, Dark, or Auto...',
    _GuidedOnboardingStep.email => 'Text your email address...',
    _GuidedOnboardingStep.password => 'Text your password...',
    _GuidedOnboardingStep.tourChoice => 'Yes for tour, no for plan setup...',
    _GuidedOnboardingStep.tour => 'Tour in progress...',
    _GuidedOnboardingStep.plan => 'Select a plan above...',
  };

  @override
  Widget build(BuildContext context) => SafeArea(
    child: Stack(
      children: [
        Positioned.fill(
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
                    TextButton(
                      key: const Key('guided-plain-signup-action'),
                      onPressed: _busy ? null : widget.onSkipToPlainSignup,
                      child: const Text('Use plain signup form'),
                    ),
                  ],
                ),
              ),
              Expanded(
                child: ListView(
                  controller: _scrollController,
                  padding: EdgeInsets.fromLTRB(
                    20,
                    18,
                    20,
                    _showComposer ? 182 : 38,
                  ),
                  children: [
                    for (final message in _messages)
                      _GuidedOnboardingBubble(message: message),
                    if (_beanThinking) const _GuidedThinkingBubble(),
                    if (_step == _GuidedOnboardingStep.themeMode)
                      _GuidedInlinePanel(
                        child: _GuidedThemeModePicker(
                          selected: _themeModeKey,
                          enabled: !_inputLocked,
                          onSelected: (key) => unawaited(_selectThemeMode(key)),
                        ),
                      ),
                    if (_step == _GuidedOnboardingStep.tourChoice)
                      _GuidedInlinePanel(
                        child: _GuidedTourChoiceActions(
                          enabled: !_inputLocked,
                          onTour: _startTour,
                          onSkip: () => unawaited(_goToPlan(skipTour: true)),
                        ),
                      ),
                    if (_step == _GuidedOnboardingStep.tour)
                      _GuidedTourPanel(
                        step: _guidedTourSteps[_tourIndex],
                        index: _tourIndex,
                        total: _guidedTourSteps.length,
                        enabled: !_busy,
                        onNext: () => unawaited(_nextTour()),
                      ),
                    if (_step == _GuidedOnboardingStep.plan)
                      _GuidedPlanPicker(
                        billingInterval: _billingInterval,
                        busyPlan: widget.busyPlan,
                        error: widget.checkoutError,
                        onBillingChanged: (value) => setState(
                          () => _billingInterval = _normalizedBillingInterval(
                            value,
                          ),
                        ),
                        onSelect: (plan) =>
                            widget.onSelectPlan(plan, _billingInterval),
                        onRedeemCoupon: widget.onRedeemCoupon,
                        onContactEnterprise: widget.onContactEnterprise,
                      ),
                    if (_error != null) _GuidedError(message: _error!),
                  ],
                ),
              ),
            ],
          ),
        ),
        if (_showComposer)
          Positioned(
            left: 20,
            right: 20,
            bottom: 18,
            child: _GuidedFloatingInputPill(
              controller: _input,
              focusNode: _inputFocus,
              hint: _inputHint,
              enabled: !_inputLocked,
              obscureText: _step == _GuidedOnboardingStep.password,
              onSubmit: () => unawaited(_submitDraft()),
            ),
          ),
      ],
    ),
  );
}

class _PlainSignupScreen extends StatefulWidget {
  const _PlainSignupScreen({
    required this.onCreateAccount,
    required this.onAccountCreated,
    required this.onBackToBean,
    required this.onBackToLogin,
    required this.onPreviewThemeMode,
  });

  final _GuidedCreateAccount onCreateAccount;
  final VoidCallback onAccountCreated;
  final VoidCallback onBackToBean;
  final VoidCallback onBackToLogin;
  final ValueChanged<String> onPreviewThemeMode;

  @override
  State<_PlainSignupScreen> createState() => _PlainSignupScreenState();
}

class _PlainSignupScreenState extends State<_PlainSignupScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  String _themeModeKey = 'auto';
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy) return;
    final name = _name.text.trim();
    final email = _email.text.trim().toLowerCase();
    final password = _password.text;
    if (name.length < 2) {
      setState(() => _error = 'Enter your name.');
      return;
    }
    if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(email)) {
      setState(() => _error = 'Enter a valid email address.');
      return;
    }
    if (password.length < 12) {
      setState(() => _error = 'Use at least 12 characters.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.onCreateAccount(name, email, password, _themeModeKey);
      if (!mounted) return;
      widget.onAccountCreated();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = beanFriendlyErrorMessage(error, action: 'create your account');
      });
    }
  }

  void _selectThemeMode(String key) {
    final mode = heyBeanThemeModeForKey(key);
    setState(() => _themeModeKey = mode.key);
    widget.onPreviewThemeMode(mode.key);
  }

  @override
  Widget build(BuildContext context) => SafeArea(
    child: SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 14, 20, 28),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 460),
          child: _ShellCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Wrap(
                  alignment: WrapAlignment.spaceBetween,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  spacing: 8,
                  runSpacing: 4,
                  children: [
                    TextButton.icon(
                      onPressed: _busy ? null : widget.onBackToLogin,
                      icon: const Icon(Icons.arrow_back_rounded),
                      label: const Text('Login'),
                    ),
                    TextButton(
                      key: const Key('plain-signup-back-to-bean'),
                      onPressed: _busy ? null : widget.onBackToBean,
                      child: const Text('Start with Bean instead'),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Text(
                  'Plain signup',
                  key: const Key('plain-signup-title'),
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  'Create your account with a standard form. You can still use Bean once you are inside.',
                  style: TextStyle(color: HeyBeanTheme.muted, height: 1.4),
                ),
                const SizedBox(height: 18),
                TextField(
                  key: const Key('plain-signup-name'),
                  controller: _name,
                  enabled: !_busy,
                  textInputAction: TextInputAction.next,
                  autofillHints: const [AutofillHints.name],
                  decoration: const InputDecoration(labelText: 'Name'),
                ),
                const SizedBox(height: 12),
                TextField(
                  key: const Key('plain-signup-email'),
                  controller: _email,
                  enabled: !_busy,
                  keyboardType: TextInputType.emailAddress,
                  textInputAction: TextInputAction.next,
                  autofillHints: const [AutofillHints.email],
                  decoration: const InputDecoration(labelText: 'Email'),
                ),
                const SizedBox(height: 12),
                TextField(
                  key: const Key('plain-signup-password'),
                  controller: _password,
                  enabled: !_busy,
                  obscureText: true,
                  textInputAction: TextInputAction.done,
                  autofillHints: const [AutofillHints.newPassword],
                  onSubmitted: (_) => unawaited(_submit()),
                  decoration: const InputDecoration(labelText: 'Password'),
                ),
                const SizedBox(height: 14),
                _GuidedThemeModePicker(
                  selected: _themeModeKey,
                  enabled: !_busy,
                  onSelected: _selectThemeMode,
                ),
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  _GuidedError(message: _error!),
                ],
                const SizedBox(height: 16),
                FilledButton.icon(
                  key: const Key('plain-signup-submit'),
                  onPressed: _busy ? null : () => unawaited(_submit()),
                  icon: _busy
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.arrow_forward_rounded),
                  label: Text(_busy ? 'Creating account…' : 'Create account'),
                ),
              ],
            ),
          ),
        ),
      ),
    ),
  );
}

class _GuidedOnboardingBubble extends StatelessWidget {
  const _GuidedOnboardingBubble({required this.message});

  final _GuidedOnboardingMessage message;

  @override
  Widget build(BuildContext context) {
    final align = message.bean ? Alignment.centerLeft : Alignment.centerRight;
    final bg = message.bean
        ? HeyBeanTheme.surface2
        : HeyBeanTheme.accent.withValues(
            alpha: HeyBeanTheme.isDark ? .22 : .14,
          );
    final border = message.bean
        ? HeyBeanTheme.border
        : HeyBeanTheme.accent.withValues(alpha: .32);
    return Align(
      alignment: align,
      child: Container(
        constraints: const BoxConstraints(maxWidth: 340),
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13),
        decoration: BoxDecoration(
          color: bg,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: border),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              message.bean ? 'Bean' : 'You',
              style: TextStyle(
                color: message.bean
                    ? HeyBeanTheme.accentStrong
                    : HeyBeanTheme.accent,
                fontWeight: FontWeight.w900,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              message.masked ? '************' : message.text,
              style: TextStyle(
                color: HeyBeanTheme.text,
                fontSize: 16,
                height: 1.35,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _GuidedThinkingBubble extends StatelessWidget {
  const _GuidedThinkingBubble();

  @override
  Widget build(BuildContext context) => Align(
    alignment: Alignment.centerLeft,
    child: Container(
      key: const Key('guided-bean-thinking'),
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13),
      decoration: BoxDecoration(
        color: HeyBeanTheme.surface2,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: HeyBeanTheme.border),
      ),
      child: Text(
        'Bean is thinking…',
        style: TextStyle(
          color: HeyBeanTheme.muted,
          fontWeight: FontWeight.w800,
        ),
      ),
    ),
  );
}

class _GuidedError extends StatelessWidget {
  const _GuidedError({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => Container(
    key: const Key('guided-setup-error'),
    margin: const EdgeInsets.only(top: 4, bottom: 12),
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: HeyBeanTheme.destructive.withValues(alpha: .12),
      borderRadius: BorderRadius.circular(14),
      border: Border.all(
        color: HeyBeanTheme.destructive.withValues(alpha: .32),
      ),
    ),
    child: Text(
      message,
      style: TextStyle(
        color: HeyBeanTheme.destructive,
        fontWeight: FontWeight.w800,
      ),
    ),
  );
}

class _GuidedFloatingInputPill extends StatelessWidget {
  const _GuidedFloatingInputPill({
    required this.controller,
    required this.focusNode,
    required this.hint,
    required this.enabled,
    required this.onSubmit,
    this.obscureText = false,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final String hint;
  final bool enabled;
  final bool obscureText;
  final VoidCallback onSubmit;

  @override
  Widget build(BuildContext context) => Material(
    elevation: 10,
    color: Colors.transparent,
    borderRadius: BorderRadius.circular(24),
    child: Container(
      padding: const EdgeInsets.fromLTRB(14, 10, 10, 10),
      decoration: BoxDecoration(
        color: HeyBeanTheme.surface,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: HeyBeanTheme.accent.withValues(alpha: .26)),
      ),
      child: Row(
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: Image.asset(
              HeyBeanTheme.isDark
                  ? 'assets/images/bean/bean-logo-white-overlay.png'
                  : 'assets/images/bean/bean-logo.png',
              key: const Key('guided-initial-bean-button'),
              width: 42,
              height: 42,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: TextField(
              key: const Key('guided-onboarding-input'),
              controller: controller,
              focusNode: focusNode,
              enabled: enabled,
              obscureText: obscureText,
              textInputAction: TextInputAction.send,
              onSubmitted: (_) => onSubmit(),
              decoration: InputDecoration(
                hintText: hint,
                border: InputBorder.none,
                isDense: true,
              ),
            ),
          ),
          IconButton.filled(
            key: const Key('guided-onboarding-send'),
            onPressed: enabled ? onSubmit : null,
            icon: const Icon(Icons.arrow_upward_rounded),
            tooltip: 'Send to Bean',
          ),
        ],
      ),
    ),
  );
}

class _GuidedInlinePanel extends StatelessWidget {
  const _GuidedInlinePanel({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(bottom: 12),
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: HeyBeanTheme.surface,
      borderRadius: BorderRadius.circular(22),
      border: Border.all(color: HeyBeanTheme.border),
    ),
    child: child,
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

class _GuidedTourStep {
  const _GuidedTourStep({
    required this.title,
    required this.subtitle,
    required this.beanScript,
    required this.icon,
    required this.items,
  });

  final String title;
  final String subtitle;
  final String beanScript;
  final IconData icon;
  final List<String> items;
}

const List<_GuidedTourStep> _guidedTourSteps = [
  _GuidedTourStep(
    title: 'Command center',
    subtitle: 'Talk to Bean and see today update.',
    beanScript:
        "This is your command center. I'm always here to help. Tell me what you need, and above the chat you’ll see today’s events, tasks, and reminders.",
    icon: Icons.dashboard_customize_rounded,
    items: [
      '7:30 AM School drop-off',
      '12:15 PM Pay insurance',
      '6:00 PM Dinner reminder',
    ],
  ),
  _GuidedTourStep(
    title: 'Calendar views',
    subtitle: 'Jump between day and month.',
    beanScript:
        'Calendar buttons help you move between today, day view, and month view without losing your place.',
    icon: Icons.calendar_month_rounded,
    items: ['Today', 'Day view', 'Month view'],
  ),
  _GuidedTourStep(
    title: 'Tasks',
    subtitle: 'Create work, then check it off.',
    beanScript:
        'Tasks are for things you need to complete. I can create them from a sentence, and you can check them off when done.',
    icon: Icons.task_alt_rounded,
    items: ['Review launch notes', 'Order air filters', 'Send invoice'],
  ),
  _GuidedTourStep(
    title: 'Reminders',
    subtitle: 'Get nudged at the right time.',
    beanScript:
        'Reminders are lightweight nudges. I can set quick time-based follow-up without cluttering your task list.',
    icon: Icons.notifications_active_rounded,
    items: ['Take vitamins at 8 AM', 'Move laundry at 7 PM', 'Call Mom Sunday'],
  ),
  _GuidedTourStep(
    title: 'Notes',
    subtitle: 'Keep longer plans organized.',
    beanScript:
        'Notes hold plans, lists, and longer writing. Folders keep them organized, and formatting helps structure what matters.',
    icon: Icons.article_rounded,
    items: ['House projects', 'Trip plan', 'Meeting notes'],
  ),
];

class _GuidedTourPanel extends StatelessWidget {
  const _GuidedTourPanel({
    required this.step,
    required this.index,
    required this.total,
    required this.enabled,
    required this.onNext,
  });

  final _GuidedTourStep step;
  final int index;
  final int total;
  final bool enabled;
  final VoidCallback onNext;

  @override
  Widget build(BuildContext context) => _GuidedInlinePanel(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            CircleAvatar(
              backgroundColor: HeyBeanTheme.accent.withValues(alpha: .16),
              child: Icon(step.icon, color: HeyBeanTheme.accentStrong),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    step.title,
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  Text(
                    step.subtitle,
                    style: TextStyle(color: HeyBeanTheme.muted),
                  ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 14),
        _GuidedOnboardingBubble(
          message: _GuidedOnboardingMessage(bean: true, text: step.beanScript),
        ),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final item in step.items)
              Chip(label: Text(item), avatar: Icon(step.icon, size: 16)),
          ],
        ),
        const SizedBox(height: 14),
        Row(
          children: [
            Expanded(
              child: Row(
                children: [
                  for (var i = 0; i < total; i++) ...[
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 180),
                      width: i == index ? 24 : 8,
                      height: 8,
                      decoration: BoxDecoration(
                        color: i == index
                            ? HeyBeanTheme.accentStrong
                            : HeyBeanTheme.border,
                        borderRadius: BorderRadius.circular(999),
                      ),
                    ),
                    if (i != total - 1) const SizedBox(width: 6),
                  ],
                ],
              ),
            ),
            FilledButton.icon(
              key: const Key('guided-tour-next'),
              onPressed: enabled ? onNext : null,
              icon: Icon(
                index == total - 1
                    ? Icons.check_rounded
                    : Icons.arrow_forward_rounded,
              ),
              label: Text(index == total - 1 ? 'Plan setup' : 'Next'),
            ),
          ],
        ),
      ],
    ),
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
