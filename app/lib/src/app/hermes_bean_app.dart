part of '../../main.dart';

class HermesBeanApp extends StatefulWidget {
  HermesBeanApp({
    super.key,
    HermesApiClient? apiClient,
    AuthTokenStore? tokenStore,
    ExternalUrlLauncher? launchExternalUrl,
    AppIconBadgeUpdater? updateAppIconBadge,
    StripePaymentHandler? stripePaymentHandler,
    this.realtimeConversation,
  }) : apiClient = apiClient ?? HermesApiClient(),
       tokenStore = tokenStore ?? const SharedPreferencesAuthTokenStore(),
       launchExternalUrl = launchExternalUrl ?? _defaultLaunchExternalUrl,
       updateAppIconBadge = updateAppIconBadge ?? _defaultUpdateAppIconBadge,
       stripePaymentHandler =
           stripePaymentHandler ?? DefaultStripePaymentHandler();

  final HermesApiClient apiClient;
  final AuthTokenStore tokenStore;
  final ExternalUrlLauncher launchExternalUrl;
  final AppIconBadgeUpdater updateAppIconBadge;
  final StripePaymentHandler stripePaymentHandler;
  final BeanRealtimeConversation? realtimeConversation;

  @override
  State<HermesBeanApp> createState() => _HermesBeanAppState();
}

class _HermesBeanAppState extends State<HermesBeanApp> {
  String _themeKey = 'green';
  String _themeModeKey = 'auto';

  void _setThemeKey(String themeKey) {
    final normalizedThemeKey = heyBeanColorThemeForKey(themeKey).key;
    if (normalizedThemeKey == _themeKey) return;
    setState(() => _themeKey = normalizedThemeKey);
  }

  void _setThemeModeKey(String themeModeKey) {
    final normalizedThemeModeKey = heyBeanThemeModeForKey(themeModeKey).key;
    if (normalizedThemeModeKey == _themeModeKey) return;
    setState(() => _themeModeKey = normalizedThemeModeKey);
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Hermes Bean',
      debugShowCheckedModeBanner: false,
      themeAnimationDuration: Duration.zero,
      theme: HeyBeanTheme.themeDataFor(_themeKey, Brightness.light),
      darkTheme: HeyBeanTheme.themeDataFor(_themeKey, Brightness.dark),
      themeMode: heyBeanThemeModeForKey(_themeModeKey).materialThemeMode,
      builder: (context, child) => _HeyBeanThemeRuntime(
        themeKey: _themeKey,
        themeModeKey: _themeModeKey,
        child: _KeyboardDismissOnTapOutside(
          child: child ?? const SizedBox.shrink(),
        ),
      ),
      home: CommandCenterShell(
        apiClient: widget.apiClient,
        tokenStore: widget.tokenStore,
        launchExternalUrl: widget.launchExternalUrl,
        updateAppIconBadge: widget.updateAppIconBadge,
        stripePaymentHandler: widget.stripePaymentHandler,
        realtimeConversation: widget.realtimeConversation,
        onThemeChanged: _setThemeKey,
        onThemeModeChanged: _setThemeModeKey,
      ),
    );
  }
}

class _HeyBeanThemeRuntime extends StatelessWidget {
  const _HeyBeanThemeRuntime({
    required this.themeKey,
    required this.themeModeKey,
    required this.child,
  });

  final String themeKey;
  final String themeModeKey;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final configuredMode = heyBeanThemeModeForKey(themeModeKey);
    final platformBrightness = MediaQuery.platformBrightnessOf(context);
    final brightness = configuredMode.key == 'auto'
        ? platformBrightness
        : configuredMode.brightness!;
    HeyBeanTheme.useTheme(themeKey, brightness: brightness);
    return child;
  }
}

class _KeyboardDismissOnTapOutside extends StatelessWidget {
  const _KeyboardDismissOnTapOutside({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => Listener(
    behavior: HitTestBehavior.translucent,
    onPointerDown: _handlePointerDown,
    child: child,
  );

  void _handlePointerDown(PointerDownEvent event) {
    final focusedNode = FocusManager.instance.primaryFocus;
    if (focusedNode == null) return;

    final bounds = _focusedEditableTextBounds(focusedNode);
    if (bounds == null || bounds.inflate(8).contains(event.position)) return;
    if (_isBottomKeyboardDockTap(event.position)) return;
    FocusManager.instance.rootScope.unfocus();
    _unfocusTextInputChain(focusedNode.context, focusedNode);
  }

  void _unfocusTextInputChain(BuildContext? context, FocusNode focusedNode) {
    if (context != null && context.mounted) {
      void unfocusElement(Element element) {
        final widget = element.widget;
        if (widget is Focus && widget.focusNode?.hasFocus == true) {
          widget.focusNode?.unfocus();
        }
      }

      if (context is Element) {
        unfocusElement(context);
      }
      context.visitAncestorElements((element) {
        unfocusElement(element);
        return true;
      });
      FocusScope.of(context).unfocus();
    }
    if (focusedNode.hasFocus) {
      focusedNode.unfocus();
    }
  }

  bool _isBottomKeyboardDockTap(Offset position) {
    final view = WidgetsBinding.instance.platformDispatcher.views.firstOrNull;
    if (view == null) return false;
    final size = view.physicalSize / view.devicePixelRatio;
    return Rect.fromLTWH(
      0,
      size.height - 96,
      size.width,
      96,
    ).contains(position);
  }

  Rect? _focusedEditableTextBounds(FocusNode node) {
    final context = node.context;
    if (context == null) return null;

    if (context.widget is EditableText) {
      return _boundsForContext(context);
    }

    final ancestor = context.findAncestorStateOfType<EditableTextState>();
    if (ancestor != null) {
      return _boundsForContext(ancestor.context);
    }

    Rect? foundBounds;
    void visit(Element element) {
      if (foundBounds != null) return;
      if (element.widget is EditableText) {
        foundBounds = _boundsForContext(element);
        return;
      }
      element.visitChildren(visit);
    }

    context.visitChildElements(visit);
    return foundBounds;
  }

  Rect? _boundsForContext(BuildContext context) {
    final renderObject = context.findRenderObject();
    if (renderObject is! RenderBox || !renderObject.attached) return null;
    return renderObject.localToGlobal(Offset.zero) & renderObject.size;
  }
}
