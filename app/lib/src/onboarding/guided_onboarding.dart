part of '../../main.dart';

enum _GuidedOnboardingStep { name, themeMode, email, password, plan, waitlist }

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
    required this.onSelectPlan,
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
  final Future<void> Function(String plan, String billingInterval) onSelectPlan;
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
      text: 'What is your first and last name?',
    ),
  ];

  _GuidedOnboardingStep _step = _GuidedOnboardingStep.name;
  String _name = '';
  String _email = '';
  String _themeModeKey = 'auto';
  bool _busy = false;
  bool _beanThinking = false;
  bool _voiceEnabled = false;
  String? _error;
  String _billingInterval = 'monthly';

  bool get _inputLocked =>
      _busy ||
      _beanThinking ||
      _step == _GuidedOnboardingStep.plan ||
      _step == _GuidedOnboardingStep.waitlist;

  bool get _showComposer =>
      _step != _GuidedOnboardingStep.plan &&
      _step != _GuidedOnboardingStep.waitlist;

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
      case _GuidedOnboardingStep.plan:
      case _GuidedOnboardingStep.waitlist:
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
    _addUser('Name added');
    await _respondBean('Choose Light, Dark, or Auto.');
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
      '${mode.label} it is. What email should I use for your account?',
    );
    setState(() => _step = _GuidedOnboardingStep.email);
    _focusInput();
  }

  Future<void> _handleEmail(String value) async {
    final email = value.trim().toLowerCase();
    if (!_looksLikeEmailAddress(email)) {
      _setError(
        'That email does not look valid. Type the address you want to use.',
      );
      return;
    }
    _addUser('Email added');
    setState(() => _busy = true);
    try {
      final availability = await widget.apiClient.checkEmailAvailability(
        email: email,
      );
      if (!mounted) return;
      setState(() => _busy = false);
      if (!availability.available) {
        await _respondBean(
          'That email is already connected to an account. Type a different one and I’ll check it.',
        );
        _focusInput();
        return;
      }
      _email = availability.email;
      await _respondBean('Choose a password. Type it — don’t say it.');
      setState(() => _step = _GuidedOnboardingStep.password);
      _focusInput();
    } catch (_) {
      if (!mounted) return;
      setState(() => _busy = false);
      await _respondBean(
        'I could not check that email right now. Try again in a moment.',
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
      final auth = await widget.onCreateAccount(
        _name,
        _email,
        value,
        _themeModeKey,
      );
      if (!mounted) return;
      setState(() => _busy = false);
      if (auth.user.accessState == 'waitlisted') {
        setState(() => _step = _GuidedOnboardingStep.waitlist);
        await _respondBean(
          'Unfortunately, it looks like we’re currently at capacity. Since we’re doing a controlled rollout, I’ll add you to the waitlist and let you know when we can continue onboarding. It’s usually within 1–2 days.',
        );
        return;
      }
      await _respondBean('I’ll show you around your dashboard now.');
      setState(() => _step = _GuidedOnboardingStep.plan);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = beanFriendlyErrorMessage(error, action: 'create your account');
      });
    }
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
    _GuidedOnboardingStep.name => 'Type your name…',
    _GuidedOnboardingStep.themeMode => 'Light, Dark, or Auto…',
    _GuidedOnboardingStep.email => 'Type your email…',
    _GuidedOnboardingStep.password => 'Type your password…',
    _GuidedOnboardingStep.plan => 'Select a plan above...',
    _GuidedOnboardingStep.waitlist => 'We’ll email you when access opens.',
  };

  String get _currentBeanMessage {
    if (_beanThinking || _busy) return 'Bean is thinking…';
    for (final message in _messages.reversed) {
      if (message.bean) return message.text;
    }
    return 'What is your first and last name?';
  }

  String get _voiceStatus => _voiceEnabled
      ? (_beanThinking || _busy ? 'Speaking' : 'Listening')
      : 'Tap Bean for voice · volume on · allow mic';

  void _toggleVoiceChrome() => setState(() => _voiceEnabled = !_voiceEnabled);

  @override
  Widget build(BuildContext context) {
    final showRelevantControls =
        _step == _GuidedOnboardingStep.themeMode ||
        _step == _GuidedOnboardingStep.plan ||
        _step == _GuidedOnboardingStep.waitlist ||
        _error != null;
    final isCompactHeight = MediaQuery.of(context).size.height < 720;
    return SafeArea(
      child: DecoratedBox(
        decoration: BoxDecoration(
          gradient: HeyBeanTheme.isDark
              ? const LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [Color(0xFF050706), Color(0xFF090C0A)],
                )
              : const LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [Color(0xFFFFFFFF), Color(0xFFFCFDFB)],
                ),
        ),
        child: Align(
          alignment: Alignment.topCenter,
          child: SingleChildScrollView(
            padding: EdgeInsets.fromLTRB(30, isCompactHeight ? 40 : 54, 30, 40),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 390),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  _ZeroChromeBeanButton(
                    voiceEnabled: _voiceEnabled,
                    active: _voiceEnabled && (_beanThinking || _busy),
                    onTap: _toggleVoiceChrome,
                  ),
                  const SizedBox(height: 8),
                  Text(
                    _voiceStatus,
                    key: const Key('guided-zero-chrome-mic-copy'),
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: HeyBeanTheme.isDark
                          ? Colors.white.withValues(alpha: .44)
                          : const Color(0xFF111311).withValues(alpha: .34),
                      fontSize: 11,
                      fontWeight: FontWeight.w500,
                      height: 1.2,
                    ),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    _currentBeanMessage,
                    key: const Key('guided-zero-chrome-message'),
                    textAlign: TextAlign.center,
                    textScaler: MediaQuery.textScalerOf(
                      context,
                    ).clamp(minScaleFactor: 1, maxScaleFactor: 1.15),
                    style: TextStyle(
                      color: HeyBeanTheme.isDark
                          ? const Color(0xFFF8FBF8)
                          : const Color(0xFF111311),
                      fontSize: 21,
                      height: 1.23,
                      letterSpacing: -.42,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  if (showRelevantControls) ...[
                    const SizedBox(height: 14),
                    _zeroChromeRelevantControls(),
                  ],
                  if (_showComposer) ...[
                    const SizedBox(height: 16),
                    _GuidedFloatingInputPill(
                      controller: _input,
                      focusNode: _inputFocus,
                      hint: _inputHint,
                      enabled: !_inputLocked,
                      obscureText: _step == _GuidedOnboardingStep.password,
                      onSubmit: () => unawaited(_submitDraft()),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _zeroChromeRelevantControls() {
    if (_step == _GuidedOnboardingStep.themeMode) {
      return _GuidedThemeModePicker(
        selected: _themeModeKey,
        enabled: !_inputLocked,
        onSelected: (key) => unawaited(_selectThemeMode(key)),
      );
    }
    if (_step == _GuidedOnboardingStep.plan) {
      return _GuidedPlanPicker(
        billingInterval: _billingInterval,
        busyPlan: widget.busyPlan,
        error: widget.checkoutError,
        onBillingChanged: (value) => setState(
          () => _billingInterval = _normalizedBillingInterval(value),
        ),
        onSelect: (plan) => widget.onSelectPlan(plan, _billingInterval),
        onContactEnterprise: widget.onContactEnterprise,
      );
    }
    if (_error != null) return _GuidedError(message: _error!);
    if (_step == _GuidedOnboardingStep.waitlist) {
      return ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 340),
        child: Text(
          'Unfortunately, we’re currently at capacity. I’ll add you to the waitlist and let you know when we can continue onboarding.',
          textAlign: TextAlign.center,
          style: TextStyle(
            color: HeyBeanTheme.muted,
            fontSize: 13,
            height: 1.4,
            fontWeight: FontWeight.w600,
          ),
        ),
      );
    }
    return const SizedBox.shrink();
  }
}

class _ZeroChromeBeanButton extends StatefulWidget {
  const _ZeroChromeBeanButton({
    required this.voiceEnabled,
    required this.active,
    required this.onTap,
  });

  final bool voiceEnabled;
  final bool active;
  final VoidCallback onTap;

  @override
  State<_ZeroChromeBeanButton> createState() => _ZeroChromeBeanButtonState();
}

class _ZeroChromeBeanButtonState extends State<_ZeroChromeBeanButton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 5),
    )..repeat(reverse: true);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final shadowAlpha = widget.voiceEnabled ? (widget.active ? .24 : .16) : .10;
    return Semantics(
      button: true,
      label: 'Talk with Bean',
      child: GestureDetector(
        onTap: widget.onTap,
        child: AnimatedBuilder(
          animation: _controller,
          builder: (context, child) {
            final float = math.sin(_controller.value * math.pi) * -7;
            return Transform.translate(
              offset: Offset(0, float),
              child: SizedBox(
                width: 92,
                height: 82,
                child: Stack(
                  alignment: Alignment.center,
                  clipBehavior: Clip.none,
                  children: [
                    Positioned(
                      bottom: 2,
                      child: Container(
                        width: 54,
                        height: 7,
                        decoration: BoxDecoration(
                          color:
                              (HeyBeanTheme.isDark
                                      ? Colors.black
                                      : const Color(0xFF111311))
                                  .withValues(
                                    alpha: HeyBeanTheme.isDark ? .30 : .075,
                                  ),
                          borderRadius: BorderRadius.circular(999),
                        ),
                      ),
                    ),
                    if (widget.voiceEnabled)
                      Positioned.fill(
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            boxShadow: [
                              BoxShadow(
                                color: HeyBeanTheme.accent.withValues(
                                  alpha: shadowAlpha,
                                ),
                                blurRadius: widget.active ? 18 : 12,
                                spreadRadius: 0,
                              ),
                            ],
                          ),
                        ),
                      ),
                    Image.asset(
                      HeyBeanTheme.isDark
                          ? 'assets/images/bean/bean-logo-white-overlay.png'
                          : 'assets/images/bean/bean-logo.png',
                      key: const Key('guided-initial-bean-button'),
                      width: 62,
                      height: 62,
                      fit: BoxFit.contain,
                      filterQuality: FilterQuality.high,
                    ),
                  ],
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}

class _PlainSignupScreen extends StatefulWidget {
  const _PlainSignupScreen({
    required this.apiClient,
    required this.onCreateAccount,
    required this.onAccountCreated,
    required this.onBackToBean,
    required this.onBackToLogin,
    required this.onPreviewThemeMode,
  });

  final BeanApiClient apiClient;
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
  bool _waitlisted = false;

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
      final auth = await widget.onCreateAccount(
        name,
        email,
        password,
        _themeModeKey,
      );
      if (!mounted) return;
      if (auth.user.accessState == 'waitlisted') {
        setState(() {
          _busy = false;
          _waitlisted = true;
        });
        return;
      }
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
  Widget build(BuildContext context) => _waitlisted
      ? _EarlyAccessWaitlistScreen(onBack: widget.onBackToLogin)
      : SafeArea(
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
                        style: Theme.of(context).textTheme.headlineSmall
                            ?.copyWith(fontWeight: FontWeight.w900),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'Request one of the first 100 early-access spots, then create your account. 24 spots are left.',
                        style: TextStyle(
                          color: HeyBeanTheme.muted,
                          height: 1.4,
                        ),
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
                        decoration: const InputDecoration(
                          labelText: 'Password',
                        ),
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
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              )
                            : const Icon(Icons.arrow_forward_rounded),
                        label: Text(
                          _busy ? 'Creating account…' : 'Create account',
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        );
}

class _EarlyAccessWaitlistScreen extends StatelessWidget {
  const _EarlyAccessWaitlistScreen({required this.onBack});

  final VoidCallback onBack;

  @override
  Widget build(BuildContext context) => SafeArea(
    child: Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 480),
          child: _ShellCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Icon(
                  Icons.mark_email_read_rounded,
                  size: 46,
                  color: HeyBeanTheme.accentStrong,
                ),
                const SizedBox(height: 18),
                Text(
                  'Your account is created',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 12),
                Text(
                  'Unfortunately, it looks like we’re currently at capacity. Since we’re doing a controlled rollout, I’ll add you to the waitlist and let you know when we can continue onboarding. It’s usually within 1–2 days.',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: HeyBeanTheme.muted,
                    height: 1.5,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 22),
                OutlinedButton(
                  key: const Key('early-access-waitlist-back'),
                  onPressed: onBack,
                  child: const Text('Back to login'),
                ),
              ],
            ),
          ),
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
      color: Colors.transparent,
      border: Border(
        left: BorderSide(color: HeyBeanTheme.destructive, width: 2),
        top: BorderSide(color: HeyBeanTheme.destructive.withValues(alpha: .26)),
        bottom: BorderSide(
          color: HeyBeanTheme.destructive.withValues(alpha: .26),
        ),
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
  Widget build(BuildContext context) => Container(
    key: const Key('guided-zero-chrome-input-line'),
    padding: const EdgeInsets.only(bottom: 8),
    decoration: BoxDecoration(
      border: Border(
        bottom: BorderSide(
          color: HeyBeanTheme.isDark
              ? Colors.white.withValues(alpha: .16)
              : const Color(0xFF111311).withValues(alpha: .16),
        ),
      ),
    ),
    child: Row(
      children: [
        Expanded(
          child: TextField(
            key: const Key('guided-onboarding-input'),
            controller: controller,
            focusNode: focusNode,
            enabled: enabled,
            obscureText: obscureText,
            textInputAction: TextInputAction.send,
            onSubmitted: (_) => onSubmit(),
            style: TextStyle(
              color: HeyBeanTheme.isDark
                  ? const Color(0xFFF8FBF8)
                  : const Color(0xFF111311),
              fontSize: 15,
              fontWeight: FontWeight.w500,
            ),
            decoration: InputDecoration(
              hintText: hint,
              hintStyle: TextStyle(
                color: HeyBeanTheme.isDark
                    ? Colors.white.withValues(alpha: .44)
                    : const Color(0xFF6B7280).withValues(alpha: .72),
                fontWeight: FontWeight.w500,
              ),
              border: InputBorder.none,
              enabledBorder: InputBorder.none,
              focusedBorder: InputBorder.none,
              disabledBorder: InputBorder.none,
              errorBorder: InputBorder.none,
              focusedErrorBorder: InputBorder.none,
              filled: false,
              isDense: true,
              contentPadding: EdgeInsets.zero,
            ),
          ),
        ),
        TextButton(
          key: const Key('guided-onboarding-send'),
          onPressed: enabled ? onSubmit : null,
          style:
              TextButton.styleFrom(
                minimumSize: const Size(44, 34),
                padding: EdgeInsets.zero,
                tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                foregroundColor: HeyBeanTheme.accentStrong,
                backgroundColor: Colors.transparent,
                disabledBackgroundColor: Colors.transparent,
                shadowColor: Colors.transparent,
                surfaceTintColor: Colors.transparent,
                textStyle: const TextStyle(fontWeight: FontWeight.w800),
              ).copyWith(
                overlayColor: WidgetStateProperty.all(Colors.transparent),
                shape: WidgetStateProperty.all(
                  const RoundedRectangleBorder(borderRadius: BorderRadius.zero),
                ),
              ),
          child: const Text('Send'),
        ),
      ],
    ),
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
              onSelected: enabled ? (_) => onSelected(mode.key) : null,
            );
          },
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
    required this.onContactEnterprise,
  });

  final String billingInterval;
  final String? busyPlan;
  final String? error;
  final ValueChanged<String> onBillingChanged;
  final ValueChanged<String> onSelect;
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
    ],
  );
}
