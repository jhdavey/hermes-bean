part of '../../main.dart';

enum _GuidedOnboardingStep {
  name,
  themeMode,
  email,
  password,
  personality,
  location,
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

enum _GuidedScrollBehavior { bottom, none }

class _GuidedLocationIssue implements Exception {
  const _GuidedLocationIssue(this.message);

  final String message;

  @override
  String toString() => message;
}

typedef _GuidedCreateAccount =
    Future<HermesAuthResult> Function(
      String name,
      String email,
      String password,
      String themeModeKey,
    );
typedef _GuidedSavePreferences =
    Future<HermesUser> Function({
      required String agentPersonality,
      required String onboardingContext,
      String? homeCity,
    });

class _GuidedBeanOnboardingScreen extends StatefulWidget {
  const _GuidedBeanOnboardingScreen({
    required this.apiClient,
    required this.stripePaymentHandler,
    required this.busyPlan,
    required this.checkoutError,
    required this.onCreateAccount,
    required this.onSavePreferences,
    required this.onLaunchLiveTour,
    required this.onSelectPlan,
    required this.onRedeemCoupon,
    required this.onContactEnterprise,
    required this.onBackToLogin,
    required this.onPreviewThemeMode,
  });

  final HermesApiClient apiClient;
  final StripePaymentHandler stripePaymentHandler;
  final String? busyPlan;
  final String? checkoutError;
  final _GuidedCreateAccount onCreateAccount;
  final _GuidedSavePreferences onSavePreferences;
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
  final _input = TextEditingController();
  final _inputFocus = FocusNode();
  final _scrollController = ScrollController();
  final _planIntroKey = GlobalKey();
  final _personalityIntroKey = GlobalKey();
  final _locationIntroKey = GlobalKey();
  final List<_GuidedOnboardingMessage> _messages = [
    const _GuidedOnboardingMessage(
      bean: true,
      text: 'Hello, please enter your name below.',
    ),
  ];
  _GuidedOnboardingStep _step = _GuidedOnboardingStep.name;
  String _name = '';
  String _email = '';
  String _password = '';
  String _themeModeKey = 'auto';
  String? _personality;
  String? _homeCity;
  bool _busy = false;
  bool _showNameComposer = true;
  bool _beanThinking = false;
  String? _error;
  String _billingInterval = 'monthly';
  int _responseVariationIndex = 0;

  bool get _inputLocked =>
      _busy || _beanThinking || _step == _GuidedOnboardingStep.plan;

  @override
  void dispose() {
    _input.dispose();
    _inputFocus.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _addBean(
    String text, {
    _GuidedScrollBehavior scrollBehavior = _GuidedScrollBehavior.bottom,
    Key? widgetKey,
  }) => _addMessage(
    _GuidedOnboardingMessage(bean: true, text: text, widgetKey: widgetKey),
    scrollBehavior: scrollBehavior,
  );

  void _addUser(
    String text, {
    bool masked = false,
    _GuidedScrollBehavior scrollBehavior = _GuidedScrollBehavior.bottom,
  }) => _addMessage(
    _GuidedOnboardingMessage(bean: false, text: text, masked: masked),
    scrollBehavior: scrollBehavior,
  );

  Future<void> _respondBean(
    String text, {
    _GuidedScrollBehavior scrollBehavior = _GuidedScrollBehavior.bottom,
    Key? widgetKey,
  }) async {
    await _showBeanThinking();
    if (!mounted) return;
    _addBean(text, scrollBehavior: scrollBehavior, widgetKey: widgetKey);
  }

  Future<void> _showBeanThinking() async {
    if (!mounted) return;
    setState(() => _beanThinking = true);
    _scrollGuidedConversationToBottom(
      duration: const Duration(milliseconds: 180),
    );
    await Future<void>.delayed(_nextBeanResponseDelay());
    if (!mounted) return;
    setState(() => _beanThinking = false);
  }

  Duration _nextBeanResponseDelay() {
    final delay = Duration(
      milliseconds: 2000 + ((_responseVariationIndex * 431) % 900),
    );
    return delay;
  }

  String _nextResponseVariation(List<String> options) {
    final value = options[_responseVariationIndex % options.length];
    _responseVariationIndex++;
    return value;
  }

  void _scrollGuidedConversationToBottom({
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

  void _scrollGuidedWidgetIntoView(
    GlobalKey key, {
    Duration duration = const Duration(milliseconds: 260),
    double alignment = 0.08,
  }) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final context = key.currentContext;
      if (context == null || !_scrollController.hasClients) return;
      Scrollable.ensureVisible(
        context,
        duration: duration,
        curve: Curves.easeOutCubic,
        alignment: alignment,
      );
    });
  }

  void _addMessage(
    _GuidedOnboardingMessage message, {
    _GuidedScrollBehavior scrollBehavior = _GuidedScrollBehavior.bottom,
  }) {
    setState(() => _messages.add(message));
    switch (scrollBehavior) {
      case _GuidedScrollBehavior.bottom:
        _scrollGuidedConversationToBottom();
      case _GuidedScrollBehavior.none:
        break;
    }
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
        await _handlePassword(value);
      case _GuidedOnboardingStep.personality:
        await _handlePersonality(value);
      case _GuidedOnboardingStep.location:
        if (_isSkip(value)) {
          await _skipLocation();
        } else {
          _setError('Tap Allow location or Skip so Bean handles this cleanly.');
        }
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
      _nextResponseVariation([
        'Nice to meet you, $_name. Do you prefer light or dark mode? You can also choose Auto, and you can change this anytime in Appearance settings.',
        'Hi $_name, it is good to meet you. Would you like Light, Dark, or Auto mode? You can change this anytime in Appearance settings.',
        'Got it, $_name. Pick Light, Dark, or Auto for your theme mode. You can always change it later in Appearance settings.',
      ]),
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
      _nextResponseVariation(_themeModeAcknowledgements(mode)),
    );
    setState(() => _step = _GuidedOnboardingStep.email);
    _focusInput();
  }

  List<String> _themeModeAcknowledgements(HeyBeanThemeModeOption mode) {
    if (mode.key == 'light') {
      return const [
        'Light mode it is. I will keep it there. What email address would you like to use for your account? Please text it here.',
        'Ok, I\'ll keep it in Light mode. What email address should I use for your account? Please text it here.',
        'You got it. I\'ll keep it in Light mode. What email address would you like tied to this account? Please send it here.',
      ];
    }

    return [
      '${mode.label} it is. What email address would you like to use for your account? Please text it here.',
      'Done, I switched to ${mode.label}. What email address should I use for your account? Please text it here.',
      'You got it — ${mode.label}. What email address would you like tied to this account? Please send it here.',
    ];
  }

  Future<void> _handleEmail(String value) async {
    final email = value.trim().toLowerCase();
    if (!_looksLikeEmailAddress(email)) {
      _addUser(email);
      await _respondBean(
        'That email format does not look right. Please send it like name@example.com, without extra punctuation.',
      );
      _focusInput();
      return;
    }
    _addUser(email);
    setState(() => _busy = true);
    HermesEmailAvailability availability;
    try {
      availability = await widget.apiClient.checkEmailAvailability(
        email: email,
      );
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = null;
      });
      await _respondBean(
        'I could not check that email right now. Please try the email again in a moment.',
      );
      _focusInput();
      return;
    }
    if (!mounted) return;
    setState(() => _busy = false);
    if (!availability.available) {
      await _respondBean(
        _nextResponseVariation([
          'That email is already taken. Please send a different email address for this account.',
          'There is already an account using that email. Please try another email address.',
          'That email is already connected to an account. Send me a different one and I will check it.',
        ]),
      );
      _focusInput();
      return;
    }

    _email = availability.email;
    await _respondBean(
      _nextResponseVariation([
        'Great. Now choose a password. Please text it here and I will keep it hidden.',
        'Perfect. Next, send the password you want to use. I will keep it hidden.',
        'Thanks. Now text the password you would like for this account. I will mask it here.',
      ]),
    );
    setState(() => _step = _GuidedOnboardingStep.password);
    _focusInput();
  }

  bool _looksLikeEmailAddress(String value) {
    if (value.length > 254) return false;
    return RegExp(
      r'^[a-z0-9._%+-]+@(?:[a-z0-9-]+\.)+[a-z]{2,}$',
      caseSensitive: false,
    ).hasMatch(value);
  }

  Future<void> _handlePassword(String value) async {
    if (value.length < 12) {
      _setError('Use at least 12 characters so your account is protected.');
      return;
    }
    _password = value;
    _addUser('Password saved', masked: true);
    setState(() => _busy = true);
    try {
      await widget.onCreateAccount(_name, _email, _password, _themeModeKey);
      if (!mounted) return;
      setState(() => _busy = false);
      await _respondBean(
        _nextResponseVariation([
          'Your account has been created. Check your email to verify. Next, what personality type would you like me to have?',
        ]),
        scrollBehavior: _GuidedScrollBehavior.none,
        widgetKey: _personalityIntroKey,
      );
      setState(() => _step = _GuidedOnboardingStep.personality);
      _scrollGuidedWidgetIntoView(_personalityIntroKey);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = beanFriendlyErrorMessage(error, action: 'create your account');
      });
    }
  }

  Future<void> _handlePersonality(String value) async {
    final selected = _personalityFromText(value);
    if (selected == null) {
      _setError(
        'Pick one of the personality options, or type the one you want.',
      );
      return;
    }
    await _selectPersonality(selected);
  }

  Future<void> _selectPersonality(String key) async {
    final option = _agentPersonalityOptions.firstWhere(
      (item) => item.key == key,
      orElse: () => _agentPersonalityOptions.first,
    );
    setState(() => _personality = option.key);
    _addUser(
      _guidedPersonalityLabel(option),
      scrollBehavior: _GuidedScrollBehavior.none,
    );
    await _respondBean(
      _nextResponseVariation([
        'Perfect. You can also adjust Bean preferences in the settings menu later. Next, can I access your location so I can see what city we are in? This helps with weather related questions and planning.',
        'Good choice. You can change my personality later in Settings. One more helpful setup step: may I check your city? It helps me answer weather questions and plan around local context.',
        'That works. I will use that style, and you can always adjust it later. Would you like to share your location now? I only need city-level context for weather and planning help.',
      ]),
      scrollBehavior: _GuidedScrollBehavior.none,
      widgetKey: _locationIntroKey,
    );
    setState(() => _step = _GuidedOnboardingStep.location);
    _scrollGuidedWidgetIntoView(_locationIntroKey);
  }

  Future<void> _allowLocation() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final city = await _currentCity();
      if (!mounted) return;
      _homeCity = city;
      _addUser(city == null ? 'Location skipped' : 'Shared city: $city');
      await _savePreferences();
      setState(() => _busy = false);
      await _respondBean(
        city == null
            ? _nextResponseVariation([
                'No problem. I will continue without location. Next, choose your plan so your $_subscriptionTrialLabel is ready. After that I will show you the dashboard and help import your calendar.',
                'That is okay. We can skip location for now. Let us set up your $_subscriptionTrialLabel first, then I will give you the quick tour and calendar import step.',
                'No worries. You can add location later. Next is plan setup, then I will show you around and help bring in your calendar.',
              ])
            : _nextResponseVariation([
                'Great, I will keep $city in mind for weather related topics. Next, choose your plan so your $_subscriptionTrialLabel is ready. After that I will show you the dashboard and help import your calendar.',
                'Thanks. I will remember $city for weather and local planning. Let us set up your $_subscriptionTrialLabel first, then I will give you the quick tour and calendar import step.',
                'Got it — I will use $city for local context. Next is plan setup, then I will show you around and help bring in your calendar.',
              ]),
        scrollBehavior: _GuidedScrollBehavior.none,
        widgetKey: _planIntroKey,
      );
      setState(() => _step = _GuidedOnboardingStep.plan);
      _scrollPlanIntroIntoView();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = error is _GuidedLocationIssue
            ? error.message
            : 'I could not read your city. You can skip this and add it later in Settings.';
      });
    }
  }

  Future<void> _skipLocation() async {
    _addUser('Skip location');
    setState(() {
      _homeCity = null;
      _busy = true;
      _error = null;
    });
    try {
      await _savePreferences();
      if (!mounted) return;
      setState(() => _busy = false);
      await _respondBean(
        _nextResponseVariation([
          'No problem. Next, choose your plan so your $_subscriptionTrialLabel is ready. After that I will show you the dashboard and help import your calendar.',
          'All good. You can add a city later. Let us set up your $_subscriptionTrialLabel first, then I will give you the quick tour and calendar import step.',
          'No worries. We can skip that for now. Next is plan setup, then I will show you around and help bring in your calendar.',
        ]),
        scrollBehavior: _GuidedScrollBehavior.none,
        widgetKey: _planIntroKey,
      );
      setState(() => _step = _GuidedOnboardingStep.plan);
      _scrollPlanIntroIntoView();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = beanFriendlyErrorMessage(
          error,
          action: 'save your Bean preferences',
        );
      });
    }
  }

  Future<void> _savePreferences() {
    final personality = _personality;
    if (personality == null) {
      _setError('Choose a personality before continuing.');
      return Future.value();
    }
    final personalityLabel = _agentPersonalityOptions
        .firstWhere((option) => option.key == personality)
        .label;
    final context = [
      'Completed guided Bean signup onboarding.',
      'Preferred Bean personality: $personalityLabel.',
      if (_homeCity != null) 'City-level location: $_homeCity.',
    ].join(' ');
    return widget.onSavePreferences(
      agentPersonality: personality,
      onboardingContext: context,
      homeCity: _homeCity,
    );
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
      await _startTour();
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

  Future<void> _startTour() async {
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

  Future<void> _goToPlan({bool skipTour = false}) async {
    await _respondBean(
      skipTour
          ? _nextResponseVariation([
              'Ok, no problem, let us set up your plan first. After checkout I will bring you into HeyBean.',
              'No problem. Let us finish your plan setup so your $_subscriptionTrialLabel is ready.',
              'Sounds good. Pick whichever plan fits your needs.',
            ])
          : _nextResponseVariation([
              'Now, choose your plan so your $_subscriptionTrialLabel is ready. After checkout I will show you the dashboard and help import your calendar.',
              'You are almost set. Pick the plan that fits you best, then I will show you around.',
              'Select the option that fits your needs. Then we will finish onboarding with the tour and calendar import.',
            ]),
      scrollBehavior: _GuidedScrollBehavior.none,
      widgetKey: _planIntroKey,
    );
    setState(() => _step = _GuidedOnboardingStep.plan);
    _scrollPlanIntroIntoView();
  }

  void _scrollPlanIntroIntoView() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final context = _planIntroKey.currentContext;
      if (context == null || !_scrollController.hasClients) return;
      Scrollable.ensureVisible(
        context,
        duration: const Duration(milliseconds: 260),
        curve: Curves.easeOutCubic,
        alignment: 0.06,
      );
    });
  }

  Future<String?> _currentCity() async {
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied) {
      throw const _GuidedLocationIssue(
        'Location permission was not granted. You can tap Allow location again, or skip and add a city later in Settings.',
      );
    }
    if (permission == LocationPermission.deniedForever) {
      await Geolocator.openAppSettings();
      throw const _GuidedLocationIssue(
        'Location permission is blocked for HeyBean. I opened app settings so you can allow it, or you can skip this for now.',
      );
    }

    final serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled) {
      await Geolocator.openLocationSettings();
      throw const _GuidedLocationIssue(
        'Location services are turned off on this device. I opened location settings so you can enable them, or you can skip this for now.',
      );
    }

    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.low,
        timeLimit: Duration(seconds: 8),
      ),
    );
    final placemarks = await placemarkFromCoordinates(
      position.latitude,
      position.longitude,
    ).timeout(const Duration(seconds: 8));
    if (placemarks.isEmpty) {
      throw const _GuidedLocationIssue(
        'I got permission, but could not identify the city from this location. You can skip and add a city later in Settings.',
      );
    }
    final place = placemarks.first;
    final city = [place.locality, place.administrativeArea]
        .whereType<String>()
        .map((part) => part.trim())
        .where((part) => part.isNotEmpty)
        .take(2)
        .join(', ');
    if (city.isEmpty) {
      throw const _GuidedLocationIssue(
        'I got permission, but could not identify the city from this location. You can skip and add a city later in Settings.',
      );
    }
    return city;
  }

  String? _personalityFromText(String value) {
    final normalized = value.toLowerCase();
    for (final option in _agentPersonalityOptions) {
      if (normalized.contains(option.key) ||
          normalized.contains(option.label.toLowerCase().split(' ').first)) {
        return option.key;
      }
    }
    if (normalized.contains('balanced')) return 'balanced';
    if (normalized.contains('coach') || normalized.contains('motivat')) {
      return 'coach';
    }
    if (normalized.contains('organizer') || normalized.contains('detail')) {
      return 'organizer';
    }
    if (normalized.contains('creative')) return 'creative';
    if (normalized.contains('direct') || normalized.contains('operator')) {
      return 'direct';
    }
    if (normalized.contains('gentle') || normalized.contains('companion')) {
      return 'gentle';
    }
    return null;
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

  bool _isSkip(String value) =>
      RegExp(r'\b(skip|no|not now|later)\b').hasMatch(value.toLowerCase());

  void _setError(String message) {
    setState(() {
      if (_step == _GuidedOnboardingStep.name) {
        _showNameComposer = true;
      }
      _error = message;
    });
  }

  void _focusInput() {
    if (_step == _GuidedOnboardingStep.name && !_showNameComposer) {
      setState(() => _showNameComposer = true);
    }
    WidgetsBinding.instance.addPostFrameCallback(
      (_) => _inputFocus.requestFocus(),
    );
  }

  String get _inputHint => switch (_step) {
    _GuidedOnboardingStep.name => 'Name',
    _GuidedOnboardingStep.themeMode => 'Choose Light, Dark, or Auto...',
    _GuidedOnboardingStep.email => 'Text your email address...',
    _GuidedOnboardingStep.password => 'Text your password...',
    _GuidedOnboardingStep.personality => 'Type a personality choice...',
    _GuidedOnboardingStep.location => 'Type skip, or tap Allow location...',
    _GuidedOnboardingStep.tourChoice => 'Yes for tour, no for plan setup...',
    _GuidedOnboardingStep.tour => 'Tour in progress...',
    _GuidedOnboardingStep.plan => 'Select a plan above...',
  };

  @override
  Widget build(BuildContext context) {
    const guidedBeanBottom = 10.0;
    const guidedBeanSize = 98.0;
    const guidedInputBottom = guidedBeanBottom + guidedBeanSize;
    const guidedInstructionBottom = guidedInputBottom + 14;
    const guidedComposerShieldHeight = guidedInputBottom + 122;
    const guidedConversationBottomPadding = guidedComposerShieldHeight + 24;
    final showInstruction =
        _messages.length == 1 &&
        _step == _GuidedOnboardingStep.name &&
        !_showNameComposer;
    final showNameStep = _step == _GuidedOnboardingStep.name;
    final showAnchoredComposer =
        _step != _GuidedOnboardingStep.tour &&
        _step != _GuidedOnboardingStep.plan;
    final showFloatingInput =
        showAnchoredComposer && (!showNameStep || _showNameComposer);
    return SafeArea(
      child: Stack(
        children: [
          Positioned.fill(
            child: Column(
              children: [
                if (!showAnchoredComposer)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(18, 10, 18, 0),
                    child: Align(
                      alignment: Alignment.centerLeft,
                      child: TextButton.icon(
                        onPressed: _busy ? null : widget.onBackToLogin,
                        icon: Icon(Icons.arrow_back_rounded),
                        label: Text('Login'),
                      ),
                    ),
                  ),
                Expanded(
                  child: ListView(
                    controller: _scrollController,
                    padding: EdgeInsets.fromLTRB(
                      20,
                      showAnchoredComposer ? 58 : 18,
                      20,
                      showAnchoredComposer
                          ? guidedConversationBottomPadding
                          : 220,
                    ),
                    children: [
                      for (final message in _messages)
                        _GuidedOnboardingBubble(
                          key: message.widgetKey,
                          message: message,
                        ),
                      if (_beanThinking) const _GuidedThinkingBubble(),
                      if (_step == _GuidedOnboardingStep.personality)
                        _GuidedPersonalityPicker(
                          selected: _personality,
                          enabled: !_inputLocked,
                          onSelected: (key) =>
                              unawaited(_selectPersonality(key)),
                        ),
                      if (_step == _GuidedOnboardingStep.themeMode)
                        _GuidedThemeModePicker(
                          selected: _themeModeKey,
                          enabled: !_inputLocked,
                          onSelected: (key) => unawaited(_selectThemeMode(key)),
                        ),
                      if (_step == _GuidedOnboardingStep.location)
                        _GuidedLocationActions(
                          enabled: !_inputLocked,
                          onAllow: () => unawaited(_allowLocation()),
                          onSkip: () => unawaited(_skipLocation()),
                        ),
                      if (_step == _GuidedOnboardingStep.tourChoice)
                        _GuidedTourChoiceActions(
                          enabled: !_inputLocked,
                          onTour: () => unawaited(_startTour()),
                          onSkip: () => unawaited(_goToPlan(skipTour: true)),
                        ),
                      if (_step == _GuidedOnboardingStep.tour)
                        Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Center(
                            child: SizedBox(
                              width: 28,
                              height: 28,
                              child: CircularProgressIndicator(
                                strokeWidth: 3,
                                color: HeyBeanTheme.accentStrong,
                              ),
                            ),
                          ),
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
                    ],
                  ),
                ),
              ],
            ),
          ),
          if (showAnchoredComposer)
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              height: guidedComposerShieldHeight,
              child: IgnorePointer(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [
                        HeyBeanTheme.bg0.withValues(alpha: 0),
                        HeyBeanTheme.bg0.withValues(alpha: .94),
                        HeyBeanTheme.bg0,
                      ],
                      stops: const [0, .32, .68],
                    ),
                  ),
                ),
              ),
            ),
          if (showInstruction)
            Positioned(
              left: 34,
              right: 34,
              bottom: guidedInstructionBottom,
              child: _GuidedBeanInstructionCard(),
            ),
          if (showFloatingInput)
            Positioned(
              left: 24,
              right: 24,
              bottom: guidedInputBottom,
              child: _GuidedFloatingInputPill(
                controller: _input,
                focusNode: _inputFocus,
                hint: _inputHint,
                enabled: !_inputLocked,
                obscureText: _step == _GuidedOnboardingStep.password,
                error: _error,
                onSubmit: () => unawaited(_submitDraft()),
              ),
            ),
          if (showAnchoredComposer)
            Positioned(
              left: 0,
              right: 0,
              bottom: guidedBeanBottom,
              child: Center(
                child: _BeanFab(
                  widgetKey: const Key('guided-initial-bean-button'),
                  selected: true,
                  semanticLabel: 'Start Bean onboarding',
                  onPressed: _inputLocked ? () {} : _focusInput,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _GuidedOnboardingBubble extends StatelessWidget {
  const _GuidedOnboardingBubble({super.key, required this.message});

  final _GuidedOnboardingMessage message;

  @override
  Widget build(BuildContext context) => Align(
    alignment: Alignment.centerLeft,
    child: FractionallySizedBox(
      widthFactor: 1,
      child: Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: Text.rich(
          TextSpan(
            children: [
              if (message.bean) const WidgetSpan(child: SizedBox(width: 34)),
              TextSpan(
                text: message.bean ? 'Bean' : 'You',
                style: TextStyle(
                  color: message.bean
                      ? HeyBeanTheme.accentStrong
                      : HeyBeanTheme.muted,
                  fontWeight: FontWeight.w700,
                ),
              ),
              TextSpan(
                text: ' - ',
                style: TextStyle(color: HeyBeanTheme.muted),
              ),
              TextSpan(
                text: message.masked ? '************' : message.text,
                style: TextStyle(
                  color: HeyBeanTheme.text,
                  fontWeight: FontWeight.w400,
                ),
              ),
            ],
          ),
          style: TextStyle(fontSize: 16, height: 1.35),
        ),
      ),
    ),
  );
}

class _GuidedThinkingBubble extends StatefulWidget {
  const _GuidedThinkingBubble();

  @override
  State<_GuidedThinkingBubble> createState() => _GuidedThinkingBubbleState();
}

class _GuidedThinkingBubbleState extends State<_GuidedThinkingBubble>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 900),
    )..repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Align(
    alignment: Alignment.centerLeft,
    child: Container(
      key: const Key('guided-bean-thinking'),
      constraints: const BoxConstraints(maxWidth: 220),
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13),
      decoration: BoxDecoration(
        color: HeyBeanTheme.surface2,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: HeyBeanTheme.border),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Flexible(
            child: Text(
              'Bean is thinking',
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: HeyBeanTheme.muted,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
          const SizedBox(width: 8),
          AnimatedBuilder(
            animation: _controller,
            builder: (context, child) {
              final activeDot = (_controller.value * 3).floor().clamp(0, 2);
              return Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  for (var index = 0; index < 3; index++)
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 180),
                      width: 6,
                      height: 6,
                      margin: EdgeInsets.only(left: index == 0 ? 0 : 4),
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: HeyBeanTheme.accentStrong.withValues(
                          alpha: index == activeDot ? .95 : .30,
                        ),
                      ),
                    ),
                ],
              );
            },
          ),
        ],
      ),
    ),
  );
}

class _GuidedBeanInstructionCard extends StatelessWidget {
  @override
  Widget build(BuildContext context) => Column(
    children: [
      Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13),
        decoration: BoxDecoration(
          color: HeyBeanTheme.surface,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: HeyBeanTheme.accent.withValues(alpha: .35)),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(
                alpha: HeyBeanTheme.isDark ? .24 : .10,
              ),
              blurRadius: 24,
              offset: const Offset(0, 12),
            ),
          ],
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.touch_app_rounded, color: HeyBeanTheme.accentStrong),
            const SizedBox(width: 10),
            Flexible(
              child: Text(
                'Tap to text Bean',
                style: TextStyle(fontWeight: FontWeight.w900),
              ),
            ),
          ],
        ),
      ),
      Icon(
        Icons.arrow_drop_down_rounded,
        color: HeyBeanTheme.accentStrong,
        size: 34,
      ),
    ],
  );
}

class _GuidedFloatingInputPill extends StatelessWidget {
  const _GuidedFloatingInputPill({
    required this.controller,
    required this.focusNode,
    required this.hint,
    required this.enabled,
    required this.obscureText,
    required this.onSubmit,
    this.error,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final String hint;
  final bool enabled;
  final bool obscureText;
  final String? error;
  final VoidCallback onSubmit;

  @override
  Widget build(BuildContext context) => Column(
    mainAxisSize: MainAxisSize.min,
    children: [
      if (error != null) ...[
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
          decoration: BoxDecoration(
            color: HeyBeanTheme.destructive.withValues(alpha: .12),
            borderRadius: BorderRadius.circular(999),
            border: Border.all(
              color: HeyBeanTheme.destructive.withValues(alpha: .32),
            ),
          ),
          child: Text(
            error!,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: HeyBeanTheme.destructive,
              fontSize: 13,
              fontWeight: FontWeight.w800,
            ),
          ),
        ),
        const SizedBox(height: 10),
      ],
      Container(
        constraints: const BoxConstraints(maxWidth: 390),
        decoration: BoxDecoration(
          color: HeyBeanTheme.surface,
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: HeyBeanTheme.border, width: 1.2),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(
                alpha: HeyBeanTheme.isDark ? .28 : .12,
              ),
              blurRadius: 24,
              offset: const Offset(0, 12),
            ),
          ],
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
                decoration: InputDecoration(
                  hintText: hint,
                  filled: false,
                  border: InputBorder.none,
                  enabledBorder: InputBorder.none,
                  focusedBorder: InputBorder.none,
                  contentPadding: const EdgeInsets.fromLTRB(18, 14, 10, 14),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.only(right: 6),
              child: IconButton.filled(
                key: const Key('guided-onboarding-send'),
                onPressed: enabled ? onSubmit : null,
                icon: Icon(Icons.arrow_upward_rounded),
                tooltip: 'Send',
              ),
            ),
          ],
        ),
      ),
    ],
  );
}

class _GuidedPersonalityPicker extends StatelessWidget {
  const _GuidedPersonalityPicker({
    required this.selected,
    required this.enabled,
    required this.onSelected,
  });

  final String? selected;
  final bool enabled;
  final ValueChanged<String> onSelected;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 14),
    child: Column(
      children: [
        for (var index = 0; index < _agentPersonalityOptions.length; index++)
          Padding(
            padding: EdgeInsets.only(
              bottom: index == _agentPersonalityOptions.length - 1 ? 0 : 10,
            ),
            child: _GuidedPersonalityOptionTile(
              option: _agentPersonalityOptions[index],
              selected: selected == _agentPersonalityOptions[index].key,
              enabled: enabled,
              onSelected: onSelected,
            ),
          ),
      ],
    ),
  );
}

class _GuidedPersonalityOptionTile extends StatelessWidget {
  const _GuidedPersonalityOptionTile({
    required this.option,
    required this.selected,
    required this.enabled,
    required this.onSelected,
  });

  final _AgentPersonalityOption option;
  final bool selected;
  final bool enabled;
  final ValueChanged<String> onSelected;

  @override
  Widget build(BuildContext context) {
    final borderColor = selected
        ? HeyBeanTheme.accentStrong
        : HeyBeanTheme.border;
    final iconColor = selected ? HeyBeanTheme.accentStrong : HeyBeanTheme.muted;
    final backgroundColor = selected
        ? HeyBeanTheme.accent.withValues(alpha: .14)
        : HeyBeanTheme.surface;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        key: Key('guided-personality-${option.key}'),
        borderRadius: BorderRadius.circular(18),
        onTap: enabled ? () => onSelected(option.key) : null,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 160),
          curve: Curves.easeOut,
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
          decoration: BoxDecoration(
            color: backgroundColor,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: borderColor, width: selected ? 1.4 : 1),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(option.icon, color: iconColor, size: 23),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      _guidedPersonalityLabel(option),
                      style: TextStyle(
                        color: HeyBeanTheme.text,
                        fontWeight: FontWeight.w800,
                        fontSize: 15,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      option.description,
                      style: TextStyle(
                        color: HeyBeanTheme.muted,
                        fontWeight: FontWeight.w600,
                        height: 1.25,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 10),
              AnimatedOpacity(
                duration: const Duration(milliseconds: 140),
                opacity: selected ? 1 : .45,
                child: Icon(
                  selected
                      ? Icons.check_circle_rounded
                      : Icons.radio_button_unchecked_rounded,
                  color: selected
                      ? HeyBeanTheme.accentStrong
                      : HeyBeanTheme.muted,
                  size: 21,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
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
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 14),
    child: Wrap(
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
    ),
  );
}

String _guidedPersonalityLabel(_AgentPersonalityOption option) {
  switch (option.key) {
    case 'balanced':
      return 'Balanced helper';
    case 'coach':
      return 'Motivating coach';
    case 'organizer':
      return 'Detail organizer';
    case 'creative':
      return 'Creative partner';
    case 'direct':
      return 'Direct operator';
    case 'gentle':
      return 'Gentle companion';
    default:
      return option.label;
  }
}

class _GuidedLocationActions extends StatelessWidget {
  const _GuidedLocationActions({
    required this.enabled,
    required this.onAllow,
    required this.onSkip,
  });

  final bool enabled;
  final VoidCallback onAllow;
  final VoidCallback onSkip;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 14),
    child: Row(
      children: [
        Expanded(
          child: FilledButton.icon(
            key: const Key('guided-location-allow'),
            onPressed: enabled ? onAllow : null,
            icon: Icon(Icons.location_on_rounded),
            label: Text('Allow location'),
          ),
        ),
        const SizedBox(width: 10),
        OutlinedButton(
          key: const Key('guided-location-skip'),
          onPressed: enabled ? onSkip : null,
          child: Text('Skip'),
        ),
      ],
    ),
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
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 14),
    child: Row(
      children: [
        Expanded(
          child: FilledButton.icon(
            key: const Key('guided-tour-start'),
            onPressed: enabled ? onTour : null,
            icon: Icon(Icons.play_circle_rounded),
            label: Text('Show me'),
          ),
        ),
        const SizedBox(width: 10),
        OutlinedButton(
          key: const Key('guided-tour-skip'),
          onPressed: enabled ? onSkip : null,
          child: Text('Skip tour'),
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
              'Choose the plan that fits how much of your calendar, tasks, reminders, and daily context you want Bean to handle.',
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
