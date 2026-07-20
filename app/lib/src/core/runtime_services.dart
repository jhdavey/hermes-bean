part of '../../main.dart';

typedef ExternalUrlLauncher = Future<bool> Function(Uri url);
typedef AppIconBadgeUpdater = Future<void> Function(int count);

abstract class StripePaymentHandler {
  Future<void> preparePaymentSheet(
    BeanPaymentSheetSetup setup, {
    required BeanUser user,
    required String primaryButtonLabel,
  });
  Future<void> presentPaymentSheet();
}

class DefaultStripePaymentHandler implements StripePaymentHandler {
  @override
  Future<void> preparePaymentSheet(
    BeanPaymentSheetSetup setup, {
    required BeanUser user,
    required String primaryButtonLabel,
  }) async {
    stripe.Stripe.publishableKey = setup.publishableKey;
    await stripe.Stripe.instance.applySettings();
    await stripe.Stripe.instance.initPaymentSheet(
      paymentSheetParameters: stripe.SetupPaymentSheetParameters(
        setupIntentClientSecret: setup.setupIntentClientSecret,
        customerId: setup.customerId,
        customerEphemeralKeySecret: setup.customerEphemeralKeySecret,
        merchantDisplayName: 'HeyBean',
        primaryButtonLabel: primaryButtonLabel,
        allowsDelayedPaymentMethods: true,
        style: ThemeMode.system,
        billingDetails: stripe.BillingDetails(
          name: user.name,
          email: user.email,
        ),
      ),
    );
  }

  @override
  Future<void> presentPaymentSheet() async {
    await stripe.Stripe.instance.presentPaymentSheet();
  }
}

const MethodChannel _heyBeanPlatformChannel = MethodChannel('heybean/platform');
final Uri _privacyPolicyUrl = Uri.parse('https://heybean.org/privacy');
final Uri _termsOfServiceUrl = Uri.parse('https://heybean.org/terms');
final Uri _supportUrl = Uri.parse('https://heybean.org/support');
final Uri _pricingUrl = Uri.parse('https://heybean.org/pricing?source=flutter');
final Uri _enterpriseContactUrl = Uri.parse(
  'mailto:support@heybean.org?subject=HeyBean%20Enterprise',
);
const String _beanGreenCategoryColor = '#34C759';
const double _beanBottomMenuSurfaceInset = 36;

class _HeyBeanRuntimeServices {
  static BeanApiClient? apiClient;
  static ExternalUrlLauncher launchExternalUrl = _defaultLaunchExternalUrl;
  static String preferredMapApp = 'google';
}

Color _sectionDividerColor({double? alpha}) => HeyBeanTheme.border.withValues(
  alpha: alpha ?? (HeyBeanTheme.isDark ? .52 : .72),
);

BoxDecoration _sectionDividerDecoration({double? alpha}) => BoxDecoration(
  border: Border(
    top: BorderSide(color: _sectionDividerColor(alpha: alpha)),
  ),
);

Color _quietBorderColor({double alpha = .58}) =>
    HeyBeanTheme.border.withValues(alpha: HeyBeanTheme.isDark ? alpha : alpha);

Color _quietSurfaceColor({double alpha = 1}) => HeyBeanTheme.surface.withValues(
  alpha: HeyBeanTheme.isDark ? math.min(alpha, .92) : alpha,
);

Color _quietMutedSurfaceColor({double alpha = .68}) => HeyBeanTheme.surface2
    .withValues(alpha: HeyBeanTheme.isDark ? math.min(alpha, .62) : alpha);

BoxDecoration _quietSurfaceDecoration({
  double radius = 14,
  double borderAlpha = .54,
  Color? color,
}) => BoxDecoration(
  color: color ?? _quietSurfaceColor(),
  borderRadius: BorderRadius.circular(radius),
  border: Border.all(color: _quietBorderColor(alpha: borderAlpha)),
);

class _BeanNotesIcon extends StatelessWidget {
  const _BeanNotesIcon({this.size, this.color});

  final double? size;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final iconTheme = IconTheme.of(context);
    final resolvedSize = size ?? iconTheme.size ?? 24;
    final resolvedColor = color ?? iconTheme.color ?? HeyBeanTheme.text;

    return SizedBox.square(
      dimension: resolvedSize,
      child: CustomPaint(painter: _BeanNotesIconPainter(color: resolvedColor)),
    );
  }
}

class _BeanNotesIconPainter extends CustomPainter {
  const _BeanNotesIconPainter({required this.color});

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final scale = math.min(size.width, size.height) / 24;
    final offset = Offset(
      (size.width - (24 * scale)) / 2,
      (size.height - (24 * scale)) / 2,
    );
    canvas
      ..save()
      ..translate(offset.dx, offset.dy)
      ..scale(scale);

    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.1
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;

    final cover = Path()
      ..moveTo(7, 4)
      ..lineTo(17, 4)
      ..cubicTo(18.1046, 4, 19, 4.8954, 19, 6)
      ..lineTo(19, 20)
      ..lineTo(7, 20)
      ..cubicTo(5.8954, 20, 5, 19.1046, 5, 18)
      ..lineTo(5, 6)
      ..cubicTo(5, 4.8954, 5.8954, 4, 7, 4)
      ..close();
    canvas.drawPath(cover, paint);

    final lines = Path()
      ..moveTo(9, 4)
      ..lineTo(9, 20)
      ..moveTo(12, 8)
      ..lineTo(16, 8)
      ..moveTo(12, 12)
      ..lineTo(16, 12)
      ..moveTo(12, 16)
      ..lineTo(15, 16);
    canvas.drawPath(lines, paint);
    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant _BeanNotesIconPainter oldDelegate) =>
      oldDelegate.color != color;
}

@pragma('vm:entry-point')
Future<void> _firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  if (!HeyBeanFirebaseOptions.configured) return;
  try {
    if (Firebase.apps.isEmpty) {
      await Firebase.initializeApp(
        options: HeyBeanFirebaseOptions.currentPlatform,
      );
    }
  } catch (_) {
    // Background push handling must not crash the process if Firebase config is absent.
  }
}

class _ReminderNotificationService {
  final FlutterLocalNotificationsPlugin _plugin =
      FlutterLocalNotificationsPlugin();
  bool _initialized = false;

  Future<void> initialize() async {
    if (_initialized) return;
    const initializationSettings = InitializationSettings(
      iOS: DarwinInitializationSettings(
        requestAlertPermission: true,
        requestBadgePermission: true,
        requestSoundPermission: true,
      ),
      android: AndroidInitializationSettings('@mipmap/ic_launcher'),
    );
    try {
      await _plugin.initialize(initializationSettings);
      await _plugin
          .resolvePlatformSpecificImplementation<
            IOSFlutterLocalNotificationsPlugin
          >()
          ?.requestPermissions(alert: true, badge: true, sound: true);
      _initialized = true;
    } on MissingPluginException {
      _initialized = false;
    } on PlatformException {
      _initialized = false;
    } catch (_) {
      _initialized = false;
    }
  }

  Future<void> showReminder(BeanReminder reminder) async {
    await initialize();
    if (!_initialized) return;
    try {
      await _plugin.show(
        reminder.id,
        'Reminder: ${reminder.title}',
        'Open HeyBean to dismiss or mark it complete.',
        const NotificationDetails(
          iOS: DarwinNotificationDetails(
            presentAlert: true,
            presentBadge: true,
            presentSound: true,
          ),
          android: AndroidNotificationDetails(
            'heybean_reminders',
            'Reminders',
            channelDescription: 'HeyBean reminder alerts',
            importance: Importance.high,
            priority: Priority.high,
          ),
        ),
        payload: 'reminder:${reminder.id}',
      );
    } on MissingPluginException {
      // Widget tests and stale native shells can run without plugin registration.
    } on PlatformException {
      // Notification permissions may be denied; the in-app banner still appears.
    } catch (_) {
      // If the notification platform is not available, keep the app usable.
    }
  }
}

class _PushNotificationRegistrationService {
  bool _initialized = false;
  String? _registeredToken;
  StreamSubscription<String>? _tokenRefreshSubscription;

  Future<void> registerForUser(BeanApiClient apiClient) async {
    if (!HeyBeanFirebaseOptions.configured || apiClient.bearerToken == null) {
      return;
    }

    try {
      await _initializeFirebase();
      final messaging = FirebaseMessaging.instance;
      await messaging.requestPermission(alert: true, badge: true, sound: true);
      await messaging.setForegroundNotificationPresentationOptions(
        alert: true,
        badge: true,
        sound: true,
      );
      final token = await messaging.getToken();
      if (token != null && token.isNotEmpty) {
        await _sendToken(apiClient, token);
      }
      _tokenRefreshSubscription ??= messaging.onTokenRefresh.listen((token) {
        unawaited(_sendToken(apiClient, token));
      });
    } on MissingPluginException {
      // Firebase plugins are unavailable in widget tests and stale native shells.
    } on PlatformException {
      // Push permission/config can fail independently of the signed-in app.
    } catch (_) {
      // Keep the app usable; the server will still send email reminders.
    }
  }

  Future<void> unregister(BeanApiClient apiClient) async {
    final token = _registeredToken;
    _registeredToken = null;
    await _tokenRefreshSubscription?.cancel();
    _tokenRefreshSubscription = null;
    if (token == null || apiClient.bearerToken == null) return;
    try {
      await apiClient.unregisterPushNotificationToken(token);
    } catch (_) {
      // Logout should not be blocked by best-effort device-token cleanup.
    }
  }

  Future<void> dispose() async {
    await _tokenRefreshSubscription?.cancel();
    _tokenRefreshSubscription = null;
  }

  Future<void> _initializeFirebase() async {
    if (_initialized) return;
    if (Firebase.apps.isEmpty) {
      await Firebase.initializeApp(
        options: HeyBeanFirebaseOptions.currentPlatform,
      );
    }
    _initialized = true;
  }

  Future<void> _sendToken(BeanApiClient apiClient, String token) async {
    if (apiClient.bearerToken == null || token.isEmpty) return;
    await apiClient.registerPushNotificationToken(
      token: token,
      platform: _pushPlatformName(),
    );
    _registeredToken = token;
  }

  String? _pushPlatformName() {
    if (Platform.isAndroid) return 'android';
    if (Platform.isIOS) return 'ios';
    if (Platform.isMacOS) return 'macos';
    return null;
  }
}

bool _isAllowedExternalUrl(Uri url) {
  if (url.scheme == 'mailto') {
    return url.path.toLowerCase() == 'support@heybean.org';
  }
  if (url.scheme != 'https') return false;
  final host = url.host.toLowerCase();
  return host == 'heybean.org' ||
      host == 'accounts.google.com' ||
      host == 'oauth2.googleapis.com' ||
      host == 'calendar.google.com' ||
      host == 'maps.apple.com' ||
      host == 'google.com' ||
      host == 'www.google.com' ||
      host == 'www.googleapis.com';
}

Future<bool> _defaultLaunchExternalUrl(Uri url) async {
  if (!_isAllowedExternalUrl(url)) return false;

  for (final mode in [
    LaunchMode.platformDefault,
    LaunchMode.externalApplication,
    LaunchMode.inAppBrowserView,
  ]) {
    try {
      final launched = await launchUrl(url, mode: mode);
      if (launched) return true;
    } on PlatformException {
      // Some iOS builds can fail to attach the url_launcher_ios pigeon channel
      // after the plugin is added. Fall through to the next launch path instead
      // of surfacing a copy-link fallback to the user.
    } on MissingPluginException {
      // Same fallback path for stale/native shells that have not registered the
      // url_launcher plugin yet.
    } on ArgumentError {
      // A launch mode may be unavailable on a platform; try the next one.
    }
  }

  return _launchExternalUrlWithNativeFallback(url);
}

Future<bool> _launchExternalUrlWithNativeFallback(Uri url) async {
  if (!_isAllowedExternalUrl(url)) return false;

  try {
    return await _heyBeanPlatformChannel.invokeMethod<bool>('openUrl', {
          'url': url.toString(),
        }) ??
        false;
  } on PlatformException {
    return false;
  } on MissingPluginException {
    return false;
  }
}

Future<void> _defaultUpdateAppIconBadge(int count) async {
  final normalizedCount = math.max(0, count);
  try {
    await _heyBeanPlatformChannel.invokeMethod<void>('setAppBadge', {
      'count': normalizedCount,
    });
  } on PlatformException {
    // Badge support is platform/native-shell dependent. Keep the app usable if
    // the native channel cannot update the icon badge.
  } on MissingPluginException {
    // Widget tests, web/desktop, and stale native shells may not expose this.
  }
}

String _themeCategoryColorHex() {
  final value = HeyBeanTheme.accent.toARGB32() & 0xFFFFFF;
  return '#${value.toRadixString(16).padLeft(6, '0').toUpperCase()}';
}

String beanFriendlyErrorMessage(Object error, {String? action}) {
  final subscriptionLimitMessage = _subscriptionLimitMessageFromError(error);
  if (subscriptionLimitMessage != null) {
    return subscriptionLimitMessage;
  }
  final prefix = action == null || action.trim().isEmpty
      ? 'The request could not be completed.'
      : 'Could not ${action.trim()}.';
  final guidance = _beanErrorGuidance(error);
  return '$prefix $guidance';
}

String _beanErrorGuidance(Object error) {
  if (error is BeanApiException) {
    final subscriptionLimitMessage = _subscriptionLimitMessageFromApiBody(
      error.body,
    );
    if (subscriptionLimitMessage != null) return subscriptionLimitMessage;
    final validationMessage = error.statusCode == 400 || error.statusCode == 422
        ? _validationHintFromApiBody(error.body)
        : null;
    if (validationMessage != null) return validationMessage;
    return switch (error.statusCode) {
      400 =>
        'Something in the request did not look quite right. Please review what you entered and try again.',
      401 => 'Your session looks like it expired. Please sign in again.',
      403 =>
        'Your account does not have permission to do that. Check the workspace access and try again.',
      404 =>
        'I can’t find that item anymore. It may have been moved or deleted, so try refreshing the app.',
      408 =>
        'The connection took longer than expected. Refresh to check the latest state.',
      409 =>
        'That change bumped into something that was already updated. Please refresh and try once more.',
      422 =>
        'One of the details needs a quick fix. Please check the highlighted fields and try again.',
      423 =>
        'That action is temporarily blocked. Please check Settings and try again.',
      429 => 'Too many requests were sent. Wait a moment and try again.',
      >= 500 && < 600 =>
        'The service is temporarily unavailable. Try again shortly.',
      _ => 'Refresh the app and try again.',
    };
  }
  if (error is SocketException) {
    return 'The app cannot reach the internet right now. Check your connection and try again.';
  }
  if (error is TimeoutException) {
    return 'The connection took longer than expected. Refresh to check the latest state.';
  }
  if (error is FormatException || error is TypeError) {
    return 'The app received an unreadable response. Please refresh and try again.';
  }
  if (error is PlatformException || error is MissingPluginException) {
    return 'That cannot be opened on this device. Please update the app or try again.';
  }
  return 'Refresh the app and try again.';
}

String? _subscriptionLimitMessageFromError(Object error) {
  if (error is BeanApiException) {
    return _subscriptionLimitMessageFromApiBody(error.body);
  }
  return null;
}

String? _subscriptionLimitMessageFromApiBody(String body) {
  try {
    final decoded = jsonDecode(body);
    if (decoded is Map<String, Object?>) {
      final error = decoded['error'];
      final code = error is Map ? error['code']?.toString() : null;
      final message =
          (error is Map ? error['message'] : null) ?? decoded['message'];
      if (code == 'subscription_limit_reached' && message is String) {
        return _safeValidationSentence(message);
      }
    }
  } catch (_) {
    // Raw error bodies are intentionally never shown to users.
  }
  return null;
}

bool _isPlanLimitMessage(String? message) {
  final normalized = (message ?? '').toLowerCase();
  return normalized.contains('current plan includes') ||
      normalized.contains('current plan has limited') ||
      normalized.contains('available on premium');
}

String? _validationHintFromApiBody(String body) {
  try {
    final decoded = jsonDecode(body);
    if (decoded is Map<String, Object?>) {
      final errors = decoded['errors'];
      if (errors is Map && errors.isNotEmpty) {
        final first = errors.values.first;
        if (first is List && first.isNotEmpty && first.first is String) {
          final clean = _safeValidationSentence(first.first as String);
          if (clean != null) return '$clean Please adjust it and try again.';
        }
      }
      final message = decoded['message'];
      if (message is String) {
        final clean = _safeValidationSentence(message);
        if (clean != null) return '$clean Please adjust it and try again.';
      }
    }
  } catch (_) {
    // Raw error bodies are intentionally never shown to users.
  }
  return null;
}

String? _safeValidationSentence(String message) {
  final trimmed = message.trim();
  if (trimmed.isEmpty) return null;
  final lower = trimmed.toLowerCase();
  if (lower.contains('exception') ||
      lower.contains('sql') ||
      lower.contains('stack') ||
      lower.contains('trace') ||
      lower.contains('token') ||
      lower.contains('bearer') ||
      lower.contains('html') ||
      lower.contains('{') ||
      lower.contains('}')) {
    return null;
  }
  final sentence =
      trimmed.endsWith('.') || trimmed.endsWith('!') || trimmed.endsWith('?')
      ? trimmed
      : '$trimmed.';
  return sentence;
}

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  if (HeyBeanFirebaseOptions.configured) {
    FirebaseMessaging.onBackgroundMessage(_firebaseMessagingBackgroundHandler);
  }
  runApp(HeyBeanApp());
}

abstract class AuthTokenStore {
  Future<String?> loadToken();
  Future<bool> loadRememberMe();
  Future<void> saveToken(String token);
  Future<void> saveRememberMe(bool rememberMe);
  Future<void> clearToken();
}

class SharedPreferencesAuthTokenStore implements AuthTokenStore {
  const SharedPreferencesAuthTokenStore();

  static const String _tokenKey = 'auth_token';
  static const String _rememberMeKey = 'remember_me';
  static const String _tokenSavedAtKey = 'auth_token_saved_at';
  static const FlutterSecureStorage _secureStorage = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
    iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
  );

  @override
  Future<String?> loadToken() async {
    final preferences = await SharedPreferences.getInstance();
    if (preferences.getBool(_rememberMeKey) != true) return null;
    final secureToken = await _secureStorage.read(key: _tokenKey);
    if (secureToken != null && secureToken.isNotEmpty) return secureToken;
    final legacyToken = preferences.getString(_tokenKey);
    if (legacyToken != null && legacyToken.isNotEmpty) {
      await _secureStorage.write(key: _tokenKey, value: legacyToken);
      await preferences.remove(_tokenKey);
      return legacyToken;
    }
    return null;
  }

  @override
  Future<bool> loadRememberMe() async {
    final preferences = await SharedPreferences.getInstance();
    return preferences.getBool(_rememberMeKey) ?? false;
  }

  @override
  Future<void> saveToken(String token) async {
    final preferences = await SharedPreferences.getInstance();
    await _secureStorage.write(key: _tokenKey, value: token);
    await preferences.remove(_tokenKey);
    await preferences.setString(
      _tokenSavedAtKey,
      DateTime.now().toUtc().toIso8601String(),
    );
  }

  @override
  Future<void> saveRememberMe(bool rememberMe) async {
    final preferences = await SharedPreferences.getInstance();
    await preferences.setBool(_rememberMeKey, rememberMe);
  }

  @override
  Future<void> clearToken() async {
    final preferences = await SharedPreferences.getInstance();
    await _secureStorage.delete(key: _tokenKey);
    await preferences.remove(_tokenKey);
    await preferences.remove(_tokenSavedAtKey);
  }
}
