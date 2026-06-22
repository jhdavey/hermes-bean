import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math' as math;

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_stripe/flutter_stripe.dart' as stripe;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import 'bean_realtime_conversation.dart';
import 'firebase_options.dart';
import 'hermes_api_client.dart';

typedef ExternalUrlLauncher = Future<bool> Function(Uri url);
typedef AppIconBadgeUpdater = Future<void> Function(int count);

abstract class StripePaymentHandler {
  Future<void> preparePaymentSheet(
    HermesPaymentSheetSetup setup, {
    required HermesUser user,
    required String primaryButtonLabel,
  });
  Future<void> presentPaymentSheet();
}

class DefaultStripePaymentHandler implements StripePaymentHandler {
  @override
  Future<void> preparePaymentSheet(
    HermesPaymentSheetSetup setup, {
    required HermesUser user,
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
        allowsDelayedPaymentMethods: false,
        style: ThemeMode.light,
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
const double _beanChatComposerReservedHeight = 66;
const double _beanChatComposerMaxHeight = 134;
const double _beanBottomMenuSurfaceInset = 22;

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

class _BeanWorkItem {
  const _BeanWorkItem({
    required this.id,
    required this.label,
    this.status = 'running',
    this.resolvedByEvent = false,
  });

  final String id;
  final String label;
  final String status;
  final bool resolvedByEvent;

  bool get done => const {
    'completed',
    'succeeded',
    'recorded',
    'cancelled',
    'failed',
    'skipped',
  }.contains(status.toLowerCase());

  _BeanWorkItem copyWith({
    String? label,
    String? status,
    bool? resolvedByEvent,
  }) => _BeanWorkItem(
    id: id,
    label: label ?? this.label,
    status: status ?? this.status,
    resolvedByEvent: resolvedByEvent ?? this.resolvedByEvent,
  );
}

class _BeanResponsePreview {
  const _BeanResponsePreview({
    required this.key,
    required this.text,
    required this.wordCount,
  });

  final String key;
  final String text;
  final int wordCount;
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

  Future<void> showReminder(HermesReminder reminder) async {
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

  Future<void> registerForUser(HermesApiClient apiClient) async {
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

  Future<void> unregister(HermesApiClient apiClient) async {
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

  Future<void> _sendToken(HermesApiClient apiClient, String token) async {
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

Map<String, Object?> _clientTemporalContext() {
  final now = DateTime.now();
  final offset = now.timeZoneOffset;
  final offsetMinutes = offset.inMinutes;
  final sign = offsetMinutes < 0 ? '-' : '+';
  final absoluteMinutes = offsetMinutes.abs();
  final offsetLabel =
      '$sign${(absoluteMinutes ~/ 60).toString().padLeft(2, '0')}:${(absoluteMinutes % 60).toString().padLeft(2, '0')}';
  return {
    'current_local_time': now.toIso8601String(),
    'current_utc_time': now.toUtc().toIso8601String(),
    'timezone_name': now.timeZoneName,
    'timezone_offset': offsetLabel,
    'timezone_offset_minutes': offsetMinutes,
  };
}

String _themeCategoryColorHex() {
  final value = HeyBeanTheme.accent.toARGB32() & 0xFFFFFF;
  return '#${value.toRadixString(16).padLeft(6, '0').toUpperCase()}';
}

Map<String, Object?> _flutterChatMetadata({
  Map<String, Object?> additional = const {},
}) => {
  'source': 'flutter',
  'client_context': _clientTemporalContext(),
  ...additional,
};

String _normalizedVoiceCommand(String transcript) {
  final normalized = transcript
      .toLowerCase()
      .replaceAll(RegExp(r"[^a-z0-9\s']"), ' ')
      .replaceAll(RegExp(r'\s+'), ' ')
      .trim();
  return normalized
      .replaceFirst(RegExp(r'^(hey\s+bean|heybean|bean)\s+'), '')
      .trim();
}

bool _voiceCommandIsCancel(String command) {
  final directCommand = command.replaceFirst(RegExp(r'\s+bean$'), '').trim();
  if (RegExp(
    r"^(?:stop|stop it|stop talking|be quiet|quiet|cancel|cancel that|cancel this|cancel response|cancel request|never\s*mind|nevermind|forget it|that's all|that is all)$",
  ).hasMatch(directCommand)) {
    return true;
  }
  return RegExp(
    r"\b(?:stop talking|be quiet|never\s*mind|nevermind|forget it)\b",
  ).hasMatch(command);
}

@visibleForTesting
bool realtimeVoiceCancelForTesting(String transcript) =>
    _voiceCommandIsCancel(_normalizedVoiceCommand(transcript));

String beanFriendlyErrorMessage(Object error, {String? action}) {
  final prefix = action == null || action.trim().isEmpty
      ? 'Bean hit a little snag.'
      : 'Bean could not ${action.trim()}.';
  final subscriptionLimitMessage = _subscriptionLimitMessageFromError(error);
  if (subscriptionLimitMessage != null) {
    return '$prefix $subscriptionLimitMessage';
  }
  final guidance = _beanErrorGuidance(error);
  return '$prefix $guidance Don’t worry — your data is safe, and if this keeps happening we’ll fix it as soon as possible.';
}

String beanFriendlyChatFailureMessage(Object error) {
  final guidance = _beanErrorGuidance(error);
  return 'Bean could not finish that request. $guidance Please try again, or tell Bean any missing details and I’ll pick it back up. Don’t worry — if this keeps happening we’ll fix it as soon as possible.';
}

String _beanErrorGuidance(Object error) {
  if (error is HermesApiException) {
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
      401 =>
        'Your session looks like it expired. Please sign in again and Bean will get right back to work.',
      403 =>
        'Bean does not have permission to do that yet. Please check the account or workspace access and try again.',
      404 =>
        'Bean could not find that item anymore. It may have been moved or deleted, so try refreshing the app.',
      408 =>
        'The connection took too long. Please check your internet connection and try again.',
      409 =>
        'That change bumped into something that was already updated. Please refresh and try once more.',
      422 =>
        'One of the details needs a quick fix. Please check the highlighted fields and try again.',
      423 =>
        'That action is temporarily blocked while Bean waits on a required connection or approval. Please check Settings and try again.',
      429 =>
        'Bean is getting too many requests at once. Please give it a moment and try again.',
      >= 500 && < 600 =>
        'Bean’s service is having a moment on our side. Please try again in a bit.',
      _ => 'Something unexpected happened. Please try again in a moment.',
    };
  }
  if (error is SocketException) {
    return 'Bean cannot reach the internet right now. Please check your connection and try again.';
  }
  if (error is TimeoutException) {
    return 'The connection took too long. Please check your internet connection and try again.';
  }
  if (error is FormatException || error is TypeError) {
    return 'Bean received something it could not read correctly. Please refresh and try again.';
  }
  if (error is PlatformException || error is MissingPluginException) {
    return 'Bean could not open that on this device. Please update the app or try again.';
  }
  return 'Something unexpected happened. Please try again in a moment.';
}

String? _subscriptionLimitMessageFromError(Object error) {
  if (error is HermesApiException) {
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
      if (code == 'bean_usage_limit' && message is String) {
        return _safeValidationSentence(message);
      }
      final topCode = decoded['code']?.toString();
      if (topCode == 'bean_usage_limit' && message is String) {
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
      normalized.contains('available on premium') ||
      normalized.contains('ai usage limit') ||
      normalized.contains('external lookup usage limit');
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
  runApp(HermesBeanApp());
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

  void _setThemeKey(String themeKey) {
    final normalizedThemeKey = heyBeanColorThemeForKey(themeKey).key;
    if (normalizedThemeKey == _themeKey) return;
    setState(() => _themeKey = normalizedThemeKey);
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Hermes Bean',
      debugShowCheckedModeBanner: false,
      theme: HeyBeanTheme.lightThemeFor(_themeKey),
      builder: (context, child) =>
          _KeyboardDismissOnTapOutside(child: child ?? const SizedBox.shrink()),
      home: CommandCenterShell(
        apiClient: widget.apiClient,
        tokenStore: widget.tokenStore,
        launchExternalUrl: widget.launchExternalUrl,
        updateAppIconBadge: widget.updateAppIconBadge,
        stripePaymentHandler: widget.stripePaymentHandler,
        realtimeConversation: widget.realtimeConversation,
        onThemeChanged: _setThemeKey,
      ),
    );
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

class HeyBeanColorTheme {
  const HeyBeanColorTheme({
    required this.key,
    required this.label,
    required this.bg0,
    required this.bg1,
    required this.bg2,
    required this.surface2,
    required this.accent,
    required this.accentStrong,
    required this.accentInk,
    required this.success,
  });

  final String key;
  final String label;
  final Color bg0;
  final Color bg1;
  final Color bg2;
  final Color surface2;
  final Color accent;
  final Color accentStrong;
  final Color accentInk;
  final Color success;
}

const List<HeyBeanColorTheme> heyBeanColorThemes = [
  HeyBeanColorTheme(
    key: 'green',
    label: 'Green',
    bg0: Color(0xFFFFFFFF),
    bg1: Color(0xFFF8FBF6),
    bg2: Color(0xFFF1F7EE),
    surface2: Color(0xFFFBFCFB),
    accent: Color(0xFF7BC98C),
    accentStrong: Color(0xFF52A869),
    accentInk: Color(0xFF173A28),
    success: Color(0xFF7BC98C),
  ),
  HeyBeanColorTheme(
    key: 'gray',
    label: 'Gray',
    bg0: Color(0xFFF9FAFB),
    bg1: Color(0xFFF1F5F9),
    bg2: Color(0xFFE2E8F0),
    surface2: Color(0xFFFBFCFD),
    accent: Color(0xFF94A3B8),
    accentStrong: Color(0xFF64748B),
    accentInk: Color(0xFF263241),
    success: Color(0xFF94A3B8),
  ),
  HeyBeanColorTheme(
    key: 'blue',
    label: 'Blue',
    bg0: Color(0xFFF8FBFF),
    bg1: Color(0xFFEFF6FF),
    bg2: Color(0xFFDBEAFE),
    surface2: Color(0xFFFBFDFF),
    accent: Color(0xFF8CC9FF),
    accentStrong: Color(0xFF3DA2F5),
    accentInk: Color(0xFF173451),
    success: Color(0xFF8CC9FF),
  ),
  HeyBeanColorTheme(
    key: 'purple',
    label: 'Purple',
    bg0: Color(0xFFFBF9FF),
    bg1: Color(0xFFF5F0FF),
    bg2: Color(0xFFEDE9FE),
    surface2: Color(0xFFFCFBFF),
    accent: Color(0xFFC4B5FD),
    accentStrong: Color(0xFF8B5CF6),
    accentInk: Color(0xFF2F1B54),
    success: Color(0xFFC4B5FD),
  ),
  HeyBeanColorTheme(
    key: 'pink',
    label: 'Pink',
    bg0: Color(0xFFFFF8FB),
    bg1: Color(0xFFFDF2F8),
    bg2: Color(0xFFFCE7F3),
    surface2: Color(0xFFFFFBFD),
    accent: Color(0xFFF9A8D4),
    accentStrong: Color(0xFFEC4899),
    accentInk: Color(0xFF4A1730),
    success: Color(0xFFF9A8D4),
  ),
  HeyBeanColorTheme(
    key: 'red',
    label: 'Red',
    bg0: Color(0xFFFFFAFA),
    bg1: Color(0xFFFEF2F2),
    bg2: Color(0xFFFEE2E2),
    surface2: Color(0xFFFFFAFA),
    accent: Color(0xFFFCA5A5),
    accentStrong: Color(0xFFEF4444),
    accentInk: Color(0xFF4F1717),
    success: Color(0xFFFCA5A5),
  ),
  HeyBeanColorTheme(
    key: 'orange',
    label: 'Orange',
    bg0: Color(0xFFFFFAF5),
    bg1: Color(0xFFFFF7ED),
    bg2: Color(0xFFFFEDD5),
    surface2: Color(0xFFFFFAF5),
    accent: Color(0xFFFDBA74),
    accentStrong: Color(0xFFF97316),
    accentInk: Color(0xFF4A2207),
    success: Color(0xFFFDBA74),
  ),
  HeyBeanColorTheme(
    key: 'gold',
    label: 'Gold',
    bg0: Color(0xFFFFFDF7),
    bg1: Color(0xFFFFFBEB),
    bg2: Color(0xFFFEF3C7),
    surface2: Color(0xFFFFFDF7),
    accent: Color(0xFFFCD34D),
    accentStrong: Color(0xFFD97706),
    accentInk: Color(0xFF3F2C07),
    success: Color(0xFFFCD34D),
  ),
  HeyBeanColorTheme(
    key: 'teal',
    label: 'Teal',
    bg0: Color(0xFFF7FFFD),
    bg1: Color(0xFFF0FDFA),
    bg2: Color(0xFFCCFBF1),
    surface2: Color(0xFFFBFFFE),
    accent: Color(0xFF7DD3C7),
    accentStrong: Color(0xFF14B8A6),
    accentInk: Color(0xFF113C38),
    success: Color(0xFF7DD3C7),
  ),
  HeyBeanColorTheme(
    key: 'indigo',
    label: 'Indigo',
    bg0: Color(0xFFF9FAFF),
    bg1: Color(0xFFEEF2FF),
    bg2: Color(0xFFE0E7FF),
    surface2: Color(0xFFFBFBFF),
    accent: Color(0xFFA5B4FC),
    accentStrong: Color(0xFF6366F1),
    accentInk: Color(0xFF202857),
    success: Color(0xFFA5B4FC),
  ),
];

HeyBeanColorTheme heyBeanColorThemeForKey(String key) =>
    heyBeanColorThemes.firstWhere(
      (theme) => theme.key == key.trim().toLowerCase(),
      orElse: () => heyBeanColorThemes.first,
    );

class HeyBeanTheme {
  const HeyBeanTheme._();

  static HeyBeanColorTheme _current = heyBeanColorThemes.first;
  static Color bg0 = _current.bg0;
  static Color bg1 = _current.bg1;
  static Color bg2 = _current.bg2;
  static const Color surface = Color(0xFFFFFFFF);
  static Color surface2 = _current.surface2;
  static const Color text = Color(0xFF2D3748);
  static const Color muted = Color(0xFF667085);
  static const Color border = Color(0xFFD9DDE3);
  static const Color borderStrong = Color(0xFFCBD1DA);
  static Color accent = _current.accent;
  static Color accentStrong = _current.accentStrong;
  static Color accentInk = _current.accentInk;
  static Color success = _current.success;
  static const Color warning = Color(0xFFF59E0B);
  static const Color destructive = Color(0xFFDC2626);

  static const SystemUiOverlayStyle lightSystemOverlayStyle =
      SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.dark,
        statusBarBrightness: Brightness.light,
        systemNavigationBarColor: Colors.transparent,
        systemNavigationBarIconBrightness: Brightness.dark,
      );

  static void useTheme(String key) {
    _current = heyBeanColorThemeForKey(key);
    bg0 = _current.bg0;
    bg1 = _current.bg1;
    bg2 = _current.bg2;
    surface2 = _current.surface2;
    accent = _current.accent;
    accentStrong = _current.accentStrong;
    accentInk = _current.accentInk;
    success = _current.success;
  }

  static ThemeData lightThemeFor(String key) {
    useTheme(key);
    final colorScheme =
        ColorScheme.fromSeed(
          brightness: Brightness.light,
          seedColor: accent,
        ).copyWith(
          primary: accent,
          onPrimary: accentInk,
          primaryContainer: accent,
          onPrimaryContainer: accentInk,
          secondary: accentStrong,
          tertiary: success,
          surface: surface,
          onSurface: text,
          outline: borderStrong,
          outlineVariant: border,
        );

    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.light,
      colorScheme: colorScheme,
      fontFamily: 'Plus Jakarta Sans',
      fontFamilyFallback: const ['Avenir Next', 'Inter', 'Roboto', 'Arial'],
      scaffoldBackgroundColor: Colors.transparent,
      canvasColor: bg0,
      appBarTheme: const AppBarTheme(
        centerTitle: false,
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        foregroundColor: text,
        systemOverlayStyle: lightSystemOverlayStyle,
      ),
      cardTheme: CardThemeData(
        color: surface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: const BorderSide(color: border),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white.withValues(alpha: .88),
        hintStyle: const TextStyle(color: muted),
        helperStyle: const TextStyle(
          color: muted,
          fontWeight: FontWeight.w600,
          height: 1.25,
        ),
        labelStyle: const TextStyle(color: muted, fontWeight: FontWeight.w800),
        floatingLabelStyle: TextStyle(
          color: accentStrong,
          fontWeight: FontWeight.w900,
        ),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 14,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(999),
          borderSide: const BorderSide(color: border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(999),
          borderSide: const BorderSide(color: border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(999),
          borderSide: BorderSide(
            color: accent.withValues(alpha: .56),
            width: 1.2,
          ),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: accent,
          foregroundColor: accentInk,
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(999),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: text,
          side: const BorderSide(color: borderStrong),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(999),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(foregroundColor: accentStrong),
      ),
    );
  }

  static ThemeData get lightTheme => lightThemeFor(_current.key);
}

InputDecoration _longFormInputDecoration({
  String? labelText,
  String? hintText,
  Widget? prefixIcon,
}) => InputDecoration(
  labelText: labelText,
  hintText: hintText,
  prefixIcon: prefixIcon,
  alignLabelWithHint: true,
  contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
  border: OutlineInputBorder(
    borderRadius: BorderRadius.circular(16),
    borderSide: const BorderSide(color: HeyBeanTheme.border),
  ),
  enabledBorder: OutlineInputBorder(
    borderRadius: BorderRadius.circular(16),
    borderSide: const BorderSide(color: HeyBeanTheme.border),
  ),
  focusedBorder: OutlineInputBorder(
    borderRadius: BorderRadius.circular(16),
    borderSide: BorderSide(
      color: HeyBeanTheme.accent.withValues(alpha: .56),
      width: 1.2,
    ),
  ),
);

ButtonStyle _destructiveFilledButtonStyle({double radius = 14}) =>
    FilledButton.styleFrom(
      backgroundColor: HeyBeanTheme.destructive,
      foregroundColor: Colors.white,
      disabledBackgroundColor: HeyBeanTheme.destructive.withValues(alpha: .36),
      disabledForegroundColor: Colors.white70,
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(radius),
      ),
    );

ButtonStyle _destructiveIconButtonStyle() => IconButton.styleFrom(
  backgroundColor: HeyBeanTheme.destructive,
  foregroundColor: Colors.white,
  disabledBackgroundColor: HeyBeanTheme.destructive.withValues(alpha: .28),
  disabledForegroundColor: Colors.white70,
);

Future<bool> _confirmDestructiveAction(
  BuildContext context, {
  required String title,
  required String message,
  required String confirmLabel,
}) async {
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(title),
      content: Text(message),
      actions: [
        TextButton(
          key: const Key('destructive-cancel-action'),
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('Cancel'),
        ),
        FilledButton.icon(
          key: const Key('destructive-confirm-action'),
          style: _destructiveFilledButtonStyle(),
          onPressed: () => Navigator.of(context).pop(true),
          icon: const Icon(Icons.delete_outline_rounded),
          label: Text(confirmLabel),
        ),
      ],
    ),
  );
  return confirmed == true;
}

List<Object> _initialSyncWorkspaceIds({
  required List<int> linkedWorkspaceIds,
  required int? workspaceId,
  required String? activeWorkspaceId,
}) {
  final activeNumericId = activeWorkspaceId == null
      ? null
      : int.tryParse(activeWorkspaceId);
  final currentWorkspaceId = activeNumericId ?? workspaceId;

  return linkedWorkspaceIds
      .where((id) => id != currentWorkspaceId)
      .map<Object>((id) => id)
      .toList();
}

Object _workspaceValue(HermesWorkspace workspace) =>
    workspace.numericId ?? workspace.id;

bool _workspaceValuesMatch(Object? first, Object? second) {
  if (first == null || second == null) return first == second;
  return first.toString() == second.toString();
}

int? _workspaceValueToInt(Object? value) {
  if (value == null) return null;
  if (value is int) return value;
  return int.tryParse(value.toString());
}

Object? _workspaceValueForId(
  List<HermesWorkspace> workspaces,
  String? workspaceId,
) {
  if (workspaceId == null) return null;
  for (final workspace in workspaces) {
    if (workspace.id == workspaceId ||
        workspace.numericId?.toString() == workspaceId) {
      return _workspaceValue(workspace);
    }
  }
  return int.tryParse(workspaceId) ?? workspaceId;
}

Future<List<Object>?> _confirmWorkspaceDeleteSelection(
  BuildContext context, {
  required String itemTitle,
  required String itemType,
  required List<HermesWorkspace> workspaces,
  required String? activeWorkspaceId,
  required int? workspaceId,
  required List<int> linkedWorkspaceIds,
}) async {
  final linkedIds = <int>{
    if (workspaceId != null) workspaceId,
    ...linkedWorkspaceIds,
  };
  if (linkedIds.isEmpty && activeWorkspaceId != null) {
    final activeId = int.tryParse(activeWorkspaceId);
    if (activeId != null) linkedIds.add(activeId);
  }

  final workspaceById = {
    for (final workspace in workspaces)
      if (workspace.numericId != null) workspace.numericId!: workspace,
  };
  final choices =
      linkedIds
          .map(
            (id) =>
                workspaceById[id] ??
                HermesWorkspace(
                  id: id.toString(),
                  name: id == workspaceId
                      ? 'Current workspace'
                      : 'Workspace $id',
                ),
          )
          .toList()
        ..sort((a, b) {
          if (a.numericId == workspaceId) return -1;
          if (b.numericId == workspaceId) return 1;
          return a.name.toLowerCase().compareTo(b.name.toLowerCase());
        });

  if (choices.isEmpty) {
    final confirmed = await _confirmDestructiveAction(
      context,
      title: 'Delete $itemType?',
      message: 'This will remove "$itemTitle".',
      confirmLabel: 'Delete',
    );
    return confirmed ? const [] : null;
  }

  final selectedIds = choices
      .map((workspace) => workspace.numericId ?? workspace.id)
      .toSet();
  return showDialog<List<Object>>(
    context: context,
    builder: (context) => StatefulBuilder(
      builder: (context, setDialogState) {
        final canDelete = selectedIds.isNotEmpty;
        return AlertDialog(
          title: Text('Delete $itemType from'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  '"$itemTitle" is linked across workspaces. Choose where to remove it.',
                ),
                const SizedBox(height: 10),
                for (final workspace in choices)
                  CheckboxListTile(
                    key: Key('$itemType-delete-workspace-${workspace.id}'),
                    contentPadding: EdgeInsets.zero,
                    value: selectedIds.contains(
                      workspace.numericId ?? workspace.id,
                    ),
                    onChanged: (value) => setDialogState(() {
                      final id = workspace.numericId ?? workspace.id;
                      if (value ?? false) {
                        selectedIds.add(id);
                      } else {
                        selectedIds.remove(id);
                      }
                    }),
                    title: Text(
                      workspace.isPersonal ? 'Personal' : workspace.name,
                    ),
                    subtitle: workspace.numericId == workspaceId
                        ? const Text('Current copy')
                        : null,
                  ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Cancel'),
            ),
            FilledButton(
              key: Key('$itemType-delete-selected-workspaces-action'),
              style: _destructiveFilledButtonStyle(),
              onPressed: canDelete
                  ? () => Navigator.of(context).pop(selectedIds.toList())
                  : null,
              child: Text('Delete $itemType'),
            ),
          ],
        );
      },
    ),
  );
}

enum _AuthPhase { loading, signedOut, planSelection, signedIn }

enum _HomeDestination { today, tasks, bean, reminders, notes, memory, settings }

const _dashboardChangePollInterval = Duration(seconds: 15);
const _pendingCalendarEventWriteTtl = Duration(minutes: 2);
const _pendingDashboardWriteTtl = Duration(minutes: 2);
const _onboardingTourSeenPreferencePrefix = 'heybean.onboarding_tour_seen';

class _DashboardSnapshot {
  const _DashboardSnapshot({
    required this.tasks,
    required this.pastTasks,
    required this.reminders,
    required this.calendar,
    required this.noteFolders,
    required this.notes,
    required this.memoryItems,
    required this.memorySummaries,
    required this.memoryHistory,
    required this.eventCategories,
    required this.approvals,
    required this.events,
    this.googleCalendarStatus,
  });

  final List<HermesTask> tasks;
  final List<HermesTask> pastTasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendar;
  final List<HermesNoteFolder> noteFolders;
  final List<HermesNote> notes;
  final List<HermesMemoryItem> memoryItems;
  final List<HermesMemorySummary> memorySummaries;
  final List<HermesRequestHistoryItem> memoryHistory;
  final List<HermesEventCategory> eventCategories;
  final List<HermesApproval> approvals;
  final List<HermesActivityEvent> events;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
}

class _PendingCalendarEventWrite {
  const _PendingCalendarEventWrite({
    this.event,
    required this.expiresAt,
    required this.workspaceId,
    required this.mutationVersion,
    this.deleted = false,
  });

  final HermesCalendarEvent? event;
  final DateTime expiresAt;
  final int? workspaceId;
  final int mutationVersion;
  final bool deleted;
}

class _PendingTaskWrite {
  const _PendingTaskWrite({
    this.task,
    required this.expiresAt,
    required this.workspaceId,
    required this.mutationVersion,
    this.deleted = false,
  });

  final HermesTask? task;
  final DateTime expiresAt;
  final int? workspaceId;
  final int mutationVersion;
  final bool deleted;
}

class _PendingReminderWrite {
  const _PendingReminderWrite({
    this.reminder,
    required this.expiresAt,
    required this.workspaceId,
    required this.mutationVersion,
    this.deleted = false,
  });

  final HermesReminder? reminder;
  final DateTime expiresAt;
  final int? workspaceId;
  final int mutationVersion;
  final bool deleted;
}

class CommandCenterShell extends StatefulWidget {
  const CommandCenterShell({
    super.key,
    required this.apiClient,
    required this.tokenStore,
    required this.launchExternalUrl,
    required this.updateAppIconBadge,
    required this.stripePaymentHandler,
    required this.onThemeChanged,
    this.realtimeConversation,
  });

  final HermesApiClient apiClient;
  final AuthTokenStore tokenStore;
  final ExternalUrlLauncher launchExternalUrl;
  final AppIconBadgeUpdater updateAppIconBadge;
  final StripePaymentHandler stripePaymentHandler;
  final ValueChanged<String> onThemeChanged;
  final BeanRealtimeConversation? realtimeConversation;

  @override
  State<CommandCenterShell> createState() => _CommandCenterShellState();
}

class _CommandCenterShellState extends State<CommandCenterShell>
    with WidgetsBindingObserver {
  _AuthPhase _phase = _AuthPhase.loading;
  HermesUser? _user;
  HermesSession? _session;
  List<HermesTask> _tasks = const [];
  List<HermesTask> _pastTasks = const [];
  List<HermesReminder> _reminders = const [];
  List<HermesCalendarEvent> _calendar = const [];
  List<HermesNoteFolder> _noteFolders = const [];
  List<HermesNote> _notes = const [];
  int? _noteToOpenId;
  List<HermesMemoryItem> _memoryItems = const [];
  List<HermesMemorySummary> _memorySummaries = const [];
  List<HermesRequestHistoryItem> _memoryHistory = const [];
  List<HermesEventCategory> _eventCategories = const [];
  GoogleCalendarSyncStatus? _googleCalendarStatus;
  List<HermesApproval> _approvals = const [];
  List<HermesActivityEvent> _events = const [];
  final List<HermesMessage> _messages = const [
    HermesMessage(
      id: 0,
      role: 'assistant',
      content: 'Hi — I can plan, schedule, remind, and follow up.',
    ),
  ].toList();
  String? _error;
  String? _authNotice;
  String? _loadingStatusText;
  String? _checkoutBusyPlan;
  String? _checkoutError;
  bool _busy = false;
  bool _dashboardDataLoading = false;
  String _chatRunState = 'Ready';
  int _chatRunToken = 0;
  List<_BeanWorkItem> _beanWorkItems = const [];
  int _beanWorkEventFloorId = 0;
  Timer? _beanWorkStatusClearTimer;
  Timer? _beanResponsePreviewTimer;
  DateTime? _beanWorkStatusHoldUntil;
  DateTime? _beanWorkStatusMinUntil;
  DateTime? _beanResponsePreviewExpiresAt;
  Duration? _beanResponsePreviewRemaining;
  String? _beanResponsePreviewTimerKey;
  String? _dismissedBeanResponsePreviewKey;
  bool _beanResponsePreviewHeld = false;
  _HomeDestination _selectedDestination = _HomeDestination.bean;
  bool _showCalendarMonth = false;
  DateTime _selectedCalendarDay = _dateOnly(DateTime.now());
  int _calendarStartHour = _defaultCalendarStartHour;
  int _calendarEndHour = _defaultCalendarEndHour;
  final Set<int> _pendingTaskIds = <int>{};
  bool _forceAgentOnboarding = false;
  bool _editingAgentPreferences = false;
  bool _onboardingTourVisible = false;
  int _onboardingTourStep = 0;
  final TextEditingController _chatInputController = TextEditingController();
  final FocusNode _chatInputFocusNode = FocusNode();
  bool _beanChatCollapsed = false;
  int? _editingChatMessageId;
  bool _beanVoiceListening = false;
  String? _beanVoiceDraft;
  int _localMessageSequence = -1;
  late final BeanRealtimeConversation _realtimeConversation;
  final Set<int> _dismissedReminderBannerIds = <int>{};
  final Set<int> _notifiedReminderIds = <int>{};
  int? _shownApprovalSheetId;
  bool _approvalSheetOpen = false;
  final _ReminderNotificationService _reminderNotifications =
      _ReminderNotificationService();
  final _PushNotificationRegistrationService _pushNotifications =
      _PushNotificationRegistrationService();
  Timer? _reminderDueTimer;
  Timer? _dashboardChangeTimer;
  bool _dashboardChangePollInFlight = false;
  int _dashboardChangePollGeneration = 0;
  int _dashboardChangeLastId = 0;
  int _dashboardRefreshGeneration = 0;
  int _dashboardDataVersion = 0;
  int _localResourceSequence = -1;
  int _workspaceRefreshGeneration = 0;
  int _authGeneration = 0;
  int? _lastScheduledAppIconBadgeCount;
  final Map<int, _DashboardSnapshot> _workspaceSnapshots = {};
  final Map<int, _PendingTaskWrite> _pendingTaskWrites = {};
  final Map<int, _PendingReminderWrite> _pendingReminderWrites = {};
  final Map<int, _PendingCalendarEventWrite> _pendingCalendarEventWrites = {};
  final Map<int, int> _latestTaskWriteVersions = {};
  final Map<int, int> _latestReminderWriteVersions = {};
  final Map<int, int> _latestCalendarEventWriteVersions = {};

  void _applyUserTheme(HermesUser? user) {
    widget.onThemeChanged(user?.theme ?? 'green');
  }

  void _markDashboardDataMutated() {
    _dashboardDataVersion++;
    _dashboardRefreshGeneration++;
  }

  bool _canApplyBackgroundSave(int mutationVersion) =>
      mounted &&
      _phase == _AuthPhase.signedIn &&
      mutationVersion <= _dashboardDataVersion;

  bool _isCurrentAuthGeneration(int generation) =>
      mounted && generation == _authGeneration;

  void _clearSignedInState() {
    _user = null;
    _session = null;
    _tasks = const [];
    _pastTasks = const [];
    _reminders = const [];
    _calendar = const [];
    _noteFolders = const [];
    _notes = const [];
    _memoryItems = const [];
    _memorySummaries = const [];
    _memoryHistory = const [];
    _eventCategories = const [];
    _googleCalendarStatus = null;
    _approvals = const [];
    _events = const [];
    _messages.clear();
    _pendingTaskIds.clear();
    _pendingTaskWrites.clear();
    _pendingReminderWrites.clear();
    _pendingCalendarEventWrites.clear();
    _latestTaskWriteVersions.clear();
    _latestReminderWriteVersions.clear();
    _latestCalendarEventWriteVersions.clear();
    _dismissedReminderBannerIds.clear();
    _notifiedReminderIds.clear();
    _shownApprovalSheetId = null;
    _approvalSheetOpen = false;
    _editingChatMessageId = null;
    _beanVoiceListening = false;
    _beanVoiceDraft = null;
    _cancelBeanResponsePreviewTimer();
    _dismissedBeanResponsePreviewKey = null;
    _beanResponsePreviewHeld = false;
    _loadingStatusText = null;
    _dashboardDataLoading = false;
    _onboardingTourVisible = false;
    _onboardingTourStep = 0;
  }

  void _rememberPendingTaskWrite(HermesTask task, int mutationVersion) {
    _pendingTaskWrites[task.id] = _PendingTaskWrite(
      task: task,
      expiresAt: DateTime.now().add(_pendingDashboardWriteTtl),
      workspaceId: _activeWorkspaceId(),
      mutationVersion: mutationVersion,
    );
    _latestTaskWriteVersions[task.id] = mutationVersion;
  }

  void _rememberPendingTaskDelete(int taskId, int mutationVersion) {
    _pendingTaskWrites[taskId] = _PendingTaskWrite(
      expiresAt: DateTime.now().add(_pendingDashboardWriteTtl),
      workspaceId: _activeWorkspaceId(),
      mutationVersion: mutationVersion,
      deleted: true,
    );
    _latestTaskWriteVersions[taskId] = mutationVersion;
  }

  void _forgetPendingTaskWrite(int taskId, {bool clearVersion = false}) {
    _pendingTaskWrites.remove(taskId);
    if (clearVersion) _latestTaskWriteVersions.remove(taskId);
  }

  List<HermesTask> _tasksWithPendingWrites(List<HermesTask> tasks) {
    if (_pendingTaskWrites.isEmpty) return tasks;

    final now = DateTime.now();
    final activeWorkspaceId = _activeWorkspaceId();
    final sourceIds = tasks.map((task) => task.id).toSet();
    final merged = List<HermesTask>.from(tasks);

    for (final entry in _pendingTaskWrites.entries.toList()) {
      final pending = entry.value;
      if (!pending.expiresAt.isAfter(now)) {
        _pendingTaskWrites.remove(entry.key);
        if (_latestTaskWriteVersions[entry.key] == pending.mutationVersion) {
          _latestTaskWriteVersions.remove(entry.key);
        }
        continue;
      }
      if (pending.workspaceId != null &&
          activeWorkspaceId != null &&
          pending.workspaceId != activeWorkspaceId) {
        continue;
      }

      if (pending.deleted) {
        merged.removeWhere((task) => task.id == entry.key);
        if (!sourceIds.contains(entry.key)) {
          _pendingTaskWrites.remove(entry.key);
        }
        continue;
      }

      final pendingTask = pending.task;
      if (pendingTask == null) continue;
      final index = merged.indexWhere((task) => task.id == entry.key);
      if (index < 0) {
        merged.add(pendingTask);
        continue;
      }

      if (_taskMatchesPendingWrite(merged[index], pendingTask)) {
        _pendingTaskWrites.remove(entry.key);
      } else {
        merged[index] = pendingTask;
      }
    }

    return merged;
  }

  bool _taskMatchesPendingWrite(HermesTask refreshed, HermesTask pending) =>
      refreshed.title == pending.title &&
      refreshed.status == pending.status &&
      refreshed.dueAt == pending.dueAt &&
      refreshed.notes == pending.notes &&
      refreshed.category == pending.category &&
      refreshed.color == pending.color &&
      refreshed.isCritical == pending.isCritical &&
      refreshed.completedAt == pending.completedAt;

  bool _pendingTaskWriteIsCurrent(
    int taskId,
    HermesTask optimisticTask,
    int mutationVersion,
  ) {
    final latestMutationVersion = _latestTaskWriteVersions[taskId];
    if (latestMutationVersion != null &&
        latestMutationVersion != mutationVersion) {
      return false;
    }
    final pending = _pendingTaskWrites[taskId];
    if (pending == null) return true;
    if (pending.mutationVersion != mutationVersion) return false;
    if (pending.deleted || pending.task == null) return false;
    return _taskMatchesPendingWrite(pending.task!, optimisticTask);
  }

  void _rememberPendingReminderWrite(
    HermesReminder reminder,
    int mutationVersion,
  ) {
    _pendingReminderWrites[reminder.id] = _PendingReminderWrite(
      reminder: reminder,
      expiresAt: DateTime.now().add(_pendingDashboardWriteTtl),
      workspaceId: _activeWorkspaceId(),
      mutationVersion: mutationVersion,
    );
    _latestReminderWriteVersions[reminder.id] = mutationVersion;
  }

  void _rememberPendingReminderDelete(int reminderId, int mutationVersion) {
    _pendingReminderWrites[reminderId] = _PendingReminderWrite(
      expiresAt: DateTime.now().add(_pendingDashboardWriteTtl),
      workspaceId: _activeWorkspaceId(),
      mutationVersion: mutationVersion,
      deleted: true,
    );
    _latestReminderWriteVersions[reminderId] = mutationVersion;
  }

  void _forgetPendingReminderWrite(
    int reminderId, {
    bool clearVersion = false,
  }) {
    _pendingReminderWrites.remove(reminderId);
    if (clearVersion) _latestReminderWriteVersions.remove(reminderId);
  }

  List<HermesReminder> _remindersWithPendingWrites(
    List<HermesReminder> reminders,
  ) {
    if (_pendingReminderWrites.isEmpty) return reminders;

    final now = DateTime.now();
    final activeWorkspaceId = _activeWorkspaceId();
    final sourceIds = reminders.map((reminder) => reminder.id).toSet();
    final merged = List<HermesReminder>.from(reminders);

    for (final entry in _pendingReminderWrites.entries.toList()) {
      final pending = entry.value;
      if (!pending.expiresAt.isAfter(now)) {
        _pendingReminderWrites.remove(entry.key);
        if (_latestReminderWriteVersions[entry.key] ==
            pending.mutationVersion) {
          _latestReminderWriteVersions.remove(entry.key);
        }
        continue;
      }
      if (pending.workspaceId != null &&
          activeWorkspaceId != null &&
          pending.workspaceId != activeWorkspaceId) {
        continue;
      }

      if (pending.deleted) {
        merged.removeWhere((reminder) => reminder.id == entry.key);
        if (!sourceIds.contains(entry.key)) {
          _pendingReminderWrites.remove(entry.key);
        }
        continue;
      }

      final pendingReminder = pending.reminder;
      if (pendingReminder == null) continue;
      final index = merged.indexWhere((reminder) => reminder.id == entry.key);
      if (index < 0) {
        merged.add(pendingReminder);
        continue;
      }

      if (_reminderMatchesPendingWrite(merged[index], pendingReminder)) {
        _pendingReminderWrites.remove(entry.key);
      } else {
        merged[index] = pendingReminder;
      }
    }

    return merged;
  }

  bool _reminderMatchesPendingWrite(
    HermesReminder refreshed,
    HermesReminder pending,
  ) =>
      refreshed.title == pending.title &&
      refreshed.status == pending.status &&
      refreshed.dueAt == pending.dueAt &&
      refreshed.category == pending.category &&
      refreshed.color == pending.color &&
      refreshed.isCritical == pending.isCritical &&
      refreshed.completedAt == pending.completedAt;

  bool _pendingReminderWriteIsCurrent(
    int reminderId,
    HermesReminder optimisticReminder,
    int mutationVersion,
  ) {
    final latestMutationVersion = _latestReminderWriteVersions[reminderId];
    if (latestMutationVersion != null &&
        latestMutationVersion != mutationVersion) {
      return false;
    }
    final pending = _pendingReminderWrites[reminderId];
    if (pending == null) return true;
    if (pending.mutationVersion != mutationVersion) return false;
    if (pending.deleted || pending.reminder == null) return false;
    return _reminderMatchesPendingWrite(pending.reminder!, optimisticReminder);
  }

  void _rememberPendingCalendarEventWrite(
    HermesCalendarEvent event,
    int mutationVersion,
  ) {
    _pendingCalendarEventWrites[event.id] = _PendingCalendarEventWrite(
      event: event,
      expiresAt: DateTime.now().add(_pendingCalendarEventWriteTtl),
      workspaceId: _activeWorkspaceId(),
      mutationVersion: mutationVersion,
    );
    _latestCalendarEventWriteVersions[event.id] = mutationVersion;
  }

  void _forgetPendingCalendarEventWrite(
    int eventId, {
    bool clearVersion = false,
  }) {
    _pendingCalendarEventWrites.remove(eventId);
    if (clearVersion) _latestCalendarEventWriteVersions.remove(eventId);
  }

  void _rememberPendingCalendarEventDelete(int eventId, int mutationVersion) {
    _pendingCalendarEventWrites[eventId] = _PendingCalendarEventWrite(
      expiresAt: DateTime.now().add(_pendingCalendarEventWriteTtl),
      workspaceId: _activeWorkspaceId(),
      mutationVersion: mutationVersion,
      deleted: true,
    );
    _latestCalendarEventWriteVersions[eventId] = mutationVersion;
  }

  List<HermesCalendarEvent> _calendarEventsWithPendingWrites(
    List<HermesCalendarEvent> events,
  ) {
    if (_pendingCalendarEventWrites.isEmpty) return events;

    final now = DateTime.now();
    final activeWorkspaceId = _activeWorkspaceId();
    final sourceIds = events.map((event) => event.id).toSet();
    final merged = List<HermesCalendarEvent>.from(events);

    for (final entry in _pendingCalendarEventWrites.entries.toList()) {
      final pending = entry.value;
      if (!pending.expiresAt.isAfter(now)) {
        _pendingCalendarEventWrites.remove(entry.key);
        if (_latestCalendarEventWriteVersions[entry.key] ==
            pending.mutationVersion) {
          _latestCalendarEventWriteVersions.remove(entry.key);
        }
        continue;
      }
      if (pending.workspaceId != null &&
          activeWorkspaceId != null &&
          pending.workspaceId != activeWorkspaceId) {
        continue;
      }

      if (pending.deleted) {
        merged.removeWhere((event) => event.id == entry.key);
        if (!sourceIds.contains(entry.key)) {
          _pendingCalendarEventWrites.remove(entry.key);
        }
        continue;
      }

      final pendingEvent = pending.event;
      if (pendingEvent == null) continue;
      final index = merged.indexWhere((event) => event.id == entry.key);
      if (index < 0) {
        merged.add(pendingEvent);
        continue;
      }

      if (_calendarEventMatchesPendingWrite(merged[index], pendingEvent)) {
        _pendingCalendarEventWrites.remove(entry.key);
      } else {
        merged[index] = pendingEvent;
      }
    }

    return merged;
  }

  bool _calendarEventMatchesPendingWrite(
    HermesCalendarEvent refreshed,
    HermesCalendarEvent pending,
  ) =>
      refreshed.title == pending.title &&
      _sameCalendarEventInstant(refreshed.startsAt, pending.startsAt) &&
      _sameCalendarEventInstant(
        refreshed.endsAt,
        pending.endsAt,
        pending.startsAt,
      ) &&
      refreshed.category == pending.category &&
      refreshed.notes == pending.notes &&
      refreshed.color == pending.color &&
      refreshed.recurrence == pending.recurrence &&
      refreshed.isCritical == pending.isCritical;

  bool _pendingCalendarEventWriteIsCurrent(
    int eventId,
    HermesCalendarEvent optimisticEvent,
    int mutationVersion,
  ) {
    final latestMutationVersion = _latestCalendarEventWriteVersions[eventId];
    if (latestMutationVersion != null &&
        latestMutationVersion != mutationVersion) {
      return false;
    }
    final pending = _pendingCalendarEventWrites[eventId];
    if (pending == null) return true;
    if (pending.mutationVersion != mutationVersion) return false;
    if (pending.deleted || pending.event == null) return false;
    return _calendarEventMatchesPendingWrite(pending.event!, optimisticEvent);
  }

  bool _sameCalendarEventInstant(
    String? left,
    String? right, [
    String? referenceValue,
  ]) {
    if (left == null || right == null) return left == right;
    final leftDate = _parseCalendarEventDateTime(left, referenceValue);
    final rightDate = _parseCalendarEventDateTime(right, referenceValue);
    if (leftDate == null || rightDate == null) return left == right;
    return leftDate.isAtSameMomentAs(rightDate);
  }

  int _nextLocalMessageId() => _localMessageSequence--;
  int _nextLocalResourceId() => _localResourceSequence--;

  bool _assistantResponseIsDetailed(String content) {
    final raw = content.trim();
    return raw.length > 700 ||
        raw.split('\n').length >= 4 ||
        RegExp(r'(?:^|\n)\s*(?:[-*]|\d+[.)])\s+\S').hasMatch(raw);
  }

  void _upsertBeanWorkItem(
    String id,
    String label, {
    String status = 'running',
    bool resolvedByEvent = false,
  }) {
    if (id.isEmpty || label.trim().isEmpty) return;
    final cleanLabel = label.trim();
    final cleanStatus = status.toLowerCase();
    if (!_beanWorkStatusDone(cleanStatus)) {
      _cancelBeanWorkStatusClear();
      final minimum = DateTime.now().add(const Duration(milliseconds: 700));
      if (_beanWorkStatusMinUntil == null ||
          minimum.isAfter(_beanWorkStatusMinUntil!)) {
        _beanWorkStatusMinUntil = minimum;
      }
    }
    final existingIndex = _beanWorkItems.indexWhere((item) => item.id == id);
    final next = _BeanWorkItem(
      id: id,
      label: cleanLabel,
      status: cleanStatus,
      resolvedByEvent: resolvedByEvent,
    );
    if (existingIndex >= 0) {
      _beanWorkItems = [
        for (var i = 0; i < _beanWorkItems.length; i++)
          if (i == existingIndex)
            _beanWorkItems[i].resolvedByEvent &&
                    id == 'realtime-request' &&
                    !resolvedByEvent &&
                    !_beanWorkItems[i].done
                ? _beanWorkItems[i].copyWith(status: next.status)
                : _beanWorkItems[i].copyWith(
                    label: next.label,
                    status: next.status,
                    resolvedByEvent: resolvedByEvent,
                  )
          else
            _beanWorkItems[i],
      ];
      _scheduleBeanWorkStatusClearIfDone();
      return;
    }
    final placeholderIndex = _beanWorkPlaceholderIndex(cleanLabel);
    if (placeholderIndex >= 0 && resolvedByEvent) {
      _beanWorkItems = [
        for (var i = 0; i < _beanWorkItems.length; i++)
          if (i == placeholderIndex)
            _beanWorkItems[i].copyWith(
              label: cleanLabel,
              status: cleanStatus,
              resolvedByEvent: true,
            )
          else
            _beanWorkItems[i],
      ];
      _scheduleBeanWorkStatusClearIfDone();
      return;
    }
    if (_isGenericBeanWorkLabel(cleanLabel)) return;
    _beanWorkItems = [..._beanWorkItems, next];
    if (_beanWorkItems.length > 8) {
      _beanWorkItems = _beanWorkItems.sublist(_beanWorkItems.length - 8);
    }
    _scheduleBeanWorkStatusClearIfDone();
  }

  void _completeActiveBeanWorkItems([String status = 'completed']) {
    if (_beanWorkItems.isEmpty) return;
    _beanWorkItems = [
      for (final item in _beanWorkItems)
        item.done ? item : item.copyWith(status: status),
    ];
    _scheduleBeanWorkStatusClearIfDone();
  }

  void _scheduleBeanWorkStatusClearIfDone() {
    if (_beanWorkItems.isEmpty || _beanWorkItems.any((item) => !item.done)) {
      return;
    }
    _scheduleBeanWorkStatusClear();
  }

  void _scheduleBeanWorkStatusClear([
    Duration delay = const Duration(milliseconds: 1900),
  ]) {
    if (_busy) return;
    _beanWorkStatusClearTimer?.cancel();
    final now = DateTime.now();
    var clearDelay = delay;
    final minimum = _beanWorkStatusMinUntil;
    if (minimum != null && minimum.isAfter(now)) {
      final minDelay = minimum.difference(now);
      if (minDelay > clearDelay) clearDelay = minDelay;
    }
    _beanWorkStatusHoldUntil = now.add(clearDelay);
    _beanWorkStatusClearTimer = Timer(clearDelay, () {
      if (!mounted) return;
      if (_busy) {
        _scheduleBeanWorkStatusClear(delay);
        return;
      }
      setState(() {
        _beanWorkStatusHoldUntil = null;
        _beanWorkStatusMinUntil = null;
        _beanWorkItems = const [];
      });
    });
  }

  void _cancelBeanWorkStatusClear() {
    _beanWorkStatusClearTimer?.cancel();
    _beanWorkStatusClearTimer = null;
    _beanWorkStatusHoldUntil = null;
  }

  void _clearCompletedBeanWorkItemsForFreshRequest() {
    if (_beanWorkItems.isEmpty || _beanWorkItems.any((item) => !item.done)) {
      return;
    }
    _beanWorkStatusClearTimer?.cancel();
    _beanWorkStatusClearTimer = null;
    _beanWorkStatusHoldUntil = null;
    _beanWorkStatusMinUntil = null;
    _beanWorkItems = const [];
  }

  void _prepareBeanWorkForFreshRequest() {
    _beanWorkEventFloorId = _events.fold<int>(
      0,
      (maxId, event) => math.max(maxId, event.id),
    );
    _clearCompletedBeanWorkItemsForFreshRequest();
  }

  bool _beanWorkStatusDone(String status) => const {
    'completed',
    'succeeded',
    'recorded',
    'cancelled',
    'failed',
    'skipped',
  }.contains(status.toLowerCase());

  int _beanWorkPlaceholderIndex(String label) {
    final eventCategory = _beanWorkCategoryForLabel(label);
    return _beanWorkItems.indexWhere((item) {
      if (item.id != 'realtime-request' || item.resolvedByEvent || item.done) {
        return false;
      }
      final placeholderCategory = _beanWorkCategoryForLabel(item.label);
      return eventCategory.isEmpty ||
          placeholderCategory.isEmpty ||
          eventCategory == placeholderCategory;
    });
  }

  String _beanWorkCategoryForLabel(String label) {
    final text = label.toLowerCase();
    if (text.trim().isEmpty) return '';
    final action =
        RegExp(
          r'\b(delete|deleting|remove|removing|cancel|canceling|cancelled)\b',
        ).hasMatch(text)
        ? 'delete'
        : RegExp(
            r'\b(create|creating|add|adding|schedule|scheduling)\b',
          ).hasMatch(text)
        ? 'create'
        : RegExp(
            r'\b(update|updating|change|changing|move|moving|reschedule|rescheduling)\b',
          ).hasMatch(text)
        ? 'update'
        : RegExp(r'\b(save|saving|remember|memory)\b').hasMatch(text)
        ? 'save'
        : '';
    final target =
        RegExp(
          r'\b(calendar event|event|calendar|appointment|meeting)\b',
        ).hasMatch(text)
        ? 'event'
        : RegExp(r'\breminder\b').hasMatch(text)
        ? 'reminder'
        : RegExp(r'\b(task|todo)\b').hasMatch(text)
        ? 'task'
        : RegExp(r'\b(note|notes|folder|folders)\b').hasMatch(text)
        ? 'note'
        : RegExp(r'\bmemory\b').hasMatch(text)
        ? 'memory'
        : '';
    return action.isNotEmpty || target.isNotEmpty ? '$action:$target' : '';
  }

  bool _isGenericBeanWorkLabel(String label) => RegExp(
    r'^(finish|finished|background work|finish background work|bean started working|read request|follow up on voice request|working on request|work on request)$',
    caseSensitive: false,
  ).hasMatch(label.trim());

  void _ensureBeanRequestWorkItem(
    String content, {
    String status = 'running',
    bool freshRequest = false,
  }) {
    if (_beanRequestIsCapabilityQuestion(content)) return;
    final label = _beanWorkLabelForRequest(content);
    if (label == null) return;
    if (freshRequest) {
      _prepareBeanWorkForFreshRequest();
    }
    _BeanWorkItem? existing;
    for (final item in _beanWorkItems) {
      if (item.id == 'realtime-request') {
        existing = item;
        break;
      }
    }
    if (existing != null && existing.resolvedByEvent && !existing.done) return;
    _upsertBeanWorkItem('realtime-request', label, status: status);
  }

  String? _beanWorkLabelForRequest(String content) {
    final command = _normalizedVoiceCommand(content);
    if (command.isEmpty) return null;
    if (_beanCommandIsCapabilityQuestion(command)) return null;
    final targetsEvent = RegExp(
      r'\b(calendar|event|events|appointment|appointments|meeting|meetings)\b',
    ).hasMatch(command);
    final targetsTask = RegExp(
      r'\b(task|tasks|todo|to do)\b',
    ).hasMatch(command);
    final targetsReminder = RegExp(
      r'\b(reminder|reminders|remind)\b',
    ).hasMatch(command);
    final targetsNote = RegExp(
      r'\b(note|notes|folder|folders|list|lists)\b',
    ).hasMatch(command);
    final targetsMemory = RegExp(
      r'\b(remember|memory|forget|preference|preferences)\b',
    ).hasMatch(command);
    if (RegExp(r'\b(delete|remove|cancel)\b').hasMatch(command)) {
      if (targetsMemory) return 'Forgetting knowledge';
      if (targetsEvent) return 'Deleting event';
      if (targetsReminder) return 'Deleting reminder';
      if (targetsTask) return 'Deleting task';
      if (targetsNote) return 'Deleting note';
      return 'Deleting item';
    }
    if (RegExp(r'\b(move|reschedule|update|change)\b').hasMatch(command)) {
      if (targetsMemory) return 'Updating knowledge';
      if (targetsEvent) return 'Updating event';
      if (targetsReminder) return 'Updating reminder';
      if (targetsTask) return 'Updating task';
      if (targetsNote) return 'Updating note';
      return 'Updating item';
    }
    if (RegExp(r'\b(add|create|put|schedule|write|save)\b').hasMatch(command)) {
      if (targetsMemory) return 'Saving knowledge';
      if (targetsEvent) return 'Creating event';
      if (targetsReminder) return 'Creating reminder';
      if (targetsTask) return 'Creating task';
      if (targetsNote) return 'Creating note';
      return 'Creating item';
    }
    if (RegExp(r'\b(complete|finish|mark)\b').hasMatch(command)) {
      if (targetsTask) return 'Updating task';
      if (targetsReminder) return 'Updating reminder';
      return 'Updating item';
    }
    if (RegExp(r'\b(remember|memory)\b').hasMatch(command)) {
      return 'Saving knowledge';
    }
    if (RegExp(r'\b(plan|organize|prioritize)\b').hasMatch(command)) {
      return 'Planning request';
    }
    return 'Working on request';
  }

  bool _beanRequestIsCapabilityQuestion(String content) =>
      _beanCommandIsCapabilityQuestion(_normalizedVoiceCommand(content));

  bool _beanCommandIsCapabilityQuestion(String command) {
    if (command.isEmpty) return false;
    final asksCapability = RegExp(
      r"^(?:can|could|would)\s+you\s+(?:really\s+|actually\s+)?(?:add|create|make|put|schedule|write|save|delete|remove|cancel|update|change|move|reschedule|complete|finish|mark|remind|remember|plan|organize|prioritize)\b|^(?:are you able to|do you know how to|is it possible (?:for you )?to|can bean|could bean|does bean know how to|does bean support)\s+(?:add|create|make|put|schedule|write|save|delete|remove|cancel|update|change|move|reschedule|complete|finish|mark|remind|remember|plan|organize|prioritize)\b",
    ).hasMatch(command);
    if (!asksCapability) return false;
    return !_beanCommandLooksConcreteAction(command);
  }

  bool _beanCommandLooksConcreteAction(String command) {
    if (RegExp(
      r'\b(?:called|named|titled|labelled|labeled|that says|saying|with title|with the title)\b',
    ).hasMatch(command)) {
      return true;
    }
    if (RegExp(
      r'\b(?:today|tonight|tomorrow|yesterday|this morning|this afternoon|this evening|next week|next month|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b',
    ).hasMatch(command)) {
      return true;
    }
    if (RegExp(
      r'\b(?:at|by|before|after|from|until)\s+\d{1,2}(?::\d{2})?\s*(?:am|pm)?\b',
    ).hasMatch(command)) {
      return true;
    }
    if (RegExp(
      r'\b\d{1,2}[/-]\d{1,2}(?:[/-]\d{2,4})?\b|\b\d{4}-\d{2}-\d{2}\b',
    ).hasMatch(command)) {
      return true;
    }
    if (RegExp(
          r'\b(?:for|about|to)\s+(?:me|my|the|a|an)\s+\w+',
        ).hasMatch(command) &&
        !RegExp(
          r'\b(?:something|anything|things|stuff|items)\b',
        ).hasMatch(command)) {
      return true;
    }
    return false;
  }

  void _applyBeanWorkEvents(List<HermesActivityEvent> events) {
    for (final event in events) {
      if (event.id <= _beanWorkEventFloorId) continue;
      final item = _beanWorkItemFromEvent(event);
      if (item == null) continue;
      _upsertBeanWorkItem(
        item.id,
        item.label,
        status: item.status,
        resolvedByEvent: true,
      );
    }
  }

  _BeanWorkItem? _beanWorkItemFromEvent(HermesActivityEvent event) {
    final type = event.eventType;
    final payload = event.payload;
    final status = (event.status ?? '').toLowerCase();
    if (type.isEmpty || type == 'runtime.run_queued') return null;
    if (type == 'runtime.run_started' || type == 'runtime.run_completed') {
      return null;
    }
    if (type == 'runtime.run_failed') {
      return _BeanWorkItem(
        id: 'event-${event.id}',
        label: 'Finish request',
        status: 'failed',
      );
    }
    if (!type.startsWith('assistant.')) return null;
    final label = _beanWorkEventLabel(type, payload);
    if (label == null) return null;
    return _BeanWorkItem(
      id: 'event-${event.id}',
      label: label,
      status: _beanWorkEventStatus(status),
    );
  }

  String _beanWorkEventStatus(String status) {
    if (const {
      'failed',
      'skipped',
      'cancelled',
      'succeeded',
      'recorded',
      'completed',
    }.contains(status)) {
      return status;
    }
    return 'completed';
  }

  String? _beanWorkEventLabel(String type, Map<String, Object?> payload) {
    final title =
        payload['title'] ??
        payload['summary'] ??
        payload['name'] ??
        payload['reason'] ??
        payload['display_name'] ??
        payload['displayName'];
    final cleanTitle = title?.toString().replaceAll(RegExp(r'\s+'), ' ').trim();
    final readable = cleanTitle == null || cleanTitle.isEmpty
        ? ''
        : ': ${cleanTitle.length > 72 ? '${cleanTitle.substring(0, 72)}...' : cleanTitle}';
    if (type.contains('.task.created')) return 'Create task$readable';
    if (type.contains('.task.updated')) return 'Update task$readable';
    if (type.contains('.task.deleted')) return 'Delete task$readable';
    if (type.contains('.reminder.created')) return 'Create reminder$readable';
    if (type.contains('.reminder.updated')) return 'Update reminder$readable';
    if (type.contains('.reminder.deleted')) return 'Delete reminder$readable';
    if (type.contains('.calendar_event.created')) {
      return 'Create calendar event$readable';
    }
    if (type.contains('.calendar_event.updated')) {
      return 'Update calendar event$readable';
    }
    if (type.contains('.calendar_event.deleted')) {
      return 'Delete calendar event$readable';
    }
    if (type.contains('.note.created')) return 'Create note$readable';
    if (type.contains('.note.updated')) return 'Update note$readable';
    if (type.contains('.note.deleted')) return 'Delete note$readable';
    if (type.contains('.note_folder.created')) return 'Create folder$readable';
    if (type.contains('.note_folder.updated')) return 'Update folder$readable';
    if (type.contains('.note_folder.deleted')) return 'Delete folder$readable';
    if (type.contains('.memory.created')) return 'Save knowledge$readable';
    if (type.contains('.memory.updated')) return 'Update knowledge$readable';
    if (type.contains('.memory.deleted')) return 'Forget knowledge$readable';
    if (type.contains('.approval.created')) return 'Prepare approval$readable';
    if (type.contains('.blocker.created')) return 'Flag blocker$readable';
    if (type.contains('.workspace_memory.noted')) return 'Save knowledge';
    if (type.contains('.google_calendar.')) return 'Sync Google Calendar';
    return null;
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _realtimeConversation =
        widget.realtimeConversation ??
        BeanRealtimeConversation(
          apiClient: widget.apiClient,
          onStatus: (status) {
            if (!mounted) return;
            setState(() => _chatRunState = status);
          },
          onTranscript: (role, text) {
            if (!mounted || text.trim().isEmpty) return;
            setState(() {
              final trimmed = text.trim();
              if (role == 'user') {
                final command = _normalizedVoiceCommand(trimmed);
                if (_beanVoiceListening && _voiceCommandIsCancel(command)) {
                  _chatRunState = 'Bean voice ready';
                  _beanVoiceDraft = null;
                  unawaited(_realtimeConversation.interrupt());
                  return;
                }
                _beanVoiceDraft = _beanVoiceListening ? trimmed : null;
                _chatRunState = _beanVoiceListening ? 'Listening' : 'Ready';
                if (_beanVoiceListening) return;
                final alreadyDisplayed =
                    _messages.isNotEmpty &&
                    _messages.last.role == 'user' &&
                    _messages.last.content?.trim() == trimmed;
                if (!alreadyDisplayed) {
                  _messages.add(
                    HermesMessage(
                      id: _nextLocalMessageId(),
                      role: 'user',
                      content: trimmed,
                      metadata: const {'realtime': true},
                    ),
                  );
                }
                return;
              }
              final alreadyDisplayed = _messages.any(
                (message) =>
                    message.role != 'user' &&
                    message.content?.trim() == trimmed &&
                    message.metadata['realtime'] == true,
              );
              if (alreadyDisplayed) return;
              _messages.add(
                HermesMessage(
                  id: _nextLocalMessageId(),
                  role: 'assistant',
                  content: trimmed,
                  metadata: const {'realtime': true},
                ),
              );
              _chatRunState = _assistantResponseIsDetailed(trimmed)
                  ? 'Full details are in chat'
                  : (_beanVoiceListening ? 'listening' : 'Ready');
            });
          },
          onRunQueued: (runId, userContent) {
            if (!mounted) return;
            setState(() {
              _chatRunState = 'working...';
              _ensureBeanRequestWorkItem(userContent, freshRequest: true);
            });
            unawaited(_pollQueuedRun(runId, _chatRunToken));
            unawaited(_pollDashboardChanges());
          },
        );
    unawaited(_reminderNotifications.initialize());
    _reminderDueTimer = Timer.periodic(
      const Duration(seconds: 30),
      (_) => _checkReminderDueState(),
    );
    _bootstrap();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _beanWorkStatusClearTimer?.cancel();
    _beanResponsePreviewTimer?.cancel();
    _reminderDueTimer?.cancel();
    _stopDashboardChangePolling();
    _chatInputController.dispose();
    _chatInputFocusNode.dispose();
    unawaited(_pushNotifications.dispose());
    unawaited(_realtimeConversation.stop());
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _scheduleAppIconBadgeSync(_criticalItemCountForToday());
      unawaited(_pollDashboardChanges());
    }
  }

  int _criticalItemCountForToday() {
    if (_phase != _AuthPhase.signedIn) return 0;
    return _criticalTasksForToday(_tasks).length +
        _criticalRemindersForToday(_reminders).length +
        _criticalEventsForToday(_calendar).length;
  }

  bool get _beanWorkStatusHolding {
    final holdUntil = _beanWorkStatusHoldUntil;
    return holdUntil != null && DateTime.now().isBefore(holdUntil);
  }

  List<_BeanWorkItem> get _beanVisibleWorkItems {
    final items = _beanWorkItems
        .where((item) => item.label.trim().isNotEmpty)
        .toList();
    return items.length <= 6 ? items : items.sublist(items.length - 6);
  }

  bool get _beanStatusTagVisible =>
      _beanVoiceListening ||
      _busy ||
      _beanWorkItems.isNotEmpty ||
      _beanWorkStatusHolding;

  bool get _beanStopAvailable =>
      _beanVoiceListening ||
      _busy ||
      _beanVisibleWorkItems.any((item) => !item.done) ||
      RegExp(
        r'\b(working|thinking|responding|queued|running)\b',
        caseSensitive: false,
      ).hasMatch(_chatRunState);

  String get _beanStatusTagLabel {
    final items = _beanVisibleWorkItems;
    if (items.isNotEmpty && items.every((item) => item.done)) return 'Done';
    if (items.any((item) => !item.done)) return 'Working...';
    if (_beanVoiceListening) {
      return _beanVoiceDraft?.trim().isNotEmpty == true
          ? 'Ready to send'
          : 'Listening';
    }
    if (_busy) {
      final compact = _compactBeanStatusLabel(_chatRunState);
      return compact == 'Ready' ? 'Thinking...' : compact;
    }
    final compact = _compactBeanStatusLabel(_chatRunState);
    return compact == 'Ready' ? 'Bean is ready' : compact;
  }

  _BeanResponsePreview? get _beanCollapsedResponsePreview {
    if (!_beanChatCollapsed ||
        _selectedDestination != _HomeDestination.bean ||
        _beanStatusTagVisible) {
      return null;
    }
    for (final message in _messages.reversed) {
      if (message.role == 'user') continue;
      final content = (message.content ?? '').trim();
      if (content.isEmpty) continue;
      final key = _beanResponsePreviewKey(message);
      if (key == _dismissedBeanResponsePreviewKey) return null;
      final cleaned = _cleanBeanResponsePreviewContent(content);
      if (cleaned.isEmpty) continue;
      return _BeanResponsePreview(
        key: key,
        text: _compactBeanResponsePreview(cleaned),
        wordCount: _beanResponsePreviewWordCount(cleaned),
      );
    }
    return null;
  }

  String _beanResponsePreviewKey(HermesMessage message) {
    final content = (message.content ?? '').trim();
    return '${message.id}:${content.length}:${content.hashCode}';
  }

  String _cleanBeanResponsePreviewContent(String content) => content
      .replaceAll(RegExp(r'```[\s\S]*?```'), ' ')
      .replaceAll(RegExp(r'[#*_>`]'), '')
      .replaceAll(RegExp(r'\s+'), ' ')
      .trim();

  String _compactBeanResponsePreview(String cleaned) {
    if (cleaned.length <= 150) return cleaned;
    return '${cleaned.substring(0, 147)}...';
  }

  int _beanResponsePreviewWordCount(String cleaned) {
    if (cleaned.isEmpty) return 0;
    return RegExp(r'\S+').allMatches(cleaned).length;
  }

  Duration _beanResponsePreviewDuration(_BeanResponsePreview preview) =>
      Duration(seconds: math.max(1, (preview.wordCount / 3).ceil()));

  void _cancelBeanResponsePreviewTimer() {
    _beanResponsePreviewTimer?.cancel();
    _beanResponsePreviewTimer = null;
    _beanResponsePreviewExpiresAt = null;
    _beanResponsePreviewRemaining = null;
    _beanResponsePreviewTimerKey = null;
  }

  void _syncBeanResponsePreviewTimer(_BeanResponsePreview? preview) {
    if (preview == null) {
      _cancelBeanResponsePreviewTimer();
      return;
    }
    if (_beanResponsePreviewHeld) return;
    if (_beanResponsePreviewTimerKey == preview.key &&
        _beanResponsePreviewTimer?.isActive == true) {
      return;
    }
    _startBeanResponsePreviewTimer(
      preview,
      duration: _beanResponsePreviewDuration(preview),
    );
  }

  void _startBeanResponsePreviewTimer(
    _BeanResponsePreview preview, {
    required Duration duration,
  }) {
    _beanResponsePreviewTimer?.cancel();
    final normalizedDuration = duration <= Duration.zero
        ? const Duration(seconds: 1)
        : duration;
    _beanResponsePreviewTimerKey = preview.key;
    _beanResponsePreviewExpiresAt = DateTime.now().add(normalizedDuration);
    _beanResponsePreviewRemaining = normalizedDuration;
    _beanResponsePreviewTimer = Timer(normalizedDuration, () {
      if (!mounted || _beanResponsePreviewHeld) return;
      if (_beanResponsePreviewTimerKey != preview.key) return;
      setState(() {
        _dismissedBeanResponsePreviewKey = preview.key;
        _cancelBeanResponsePreviewTimer();
      });
    });
  }

  void _holdBeanResponsePreview() {
    final timer = _beanResponsePreviewTimer;
    final expiresAt = _beanResponsePreviewExpiresAt;
    _beanResponsePreviewHeld = true;
    if (timer == null || !timer.isActive || expiresAt == null) return;
    final remaining = expiresAt.difference(DateTime.now());
    _beanResponsePreviewRemaining = remaining > Duration.zero
        ? remaining
        : const Duration(milliseconds: 1);
    timer.cancel();
    _beanResponsePreviewTimer = null;
  }

  void _releaseBeanResponsePreview() {
    if (!_beanResponsePreviewHeld) return;
    _beanResponsePreviewHeld = false;
    final preview = _beanCollapsedResponsePreview;
    if (preview == null) {
      _cancelBeanResponsePreviewTimer();
      return;
    }
    _startBeanResponsePreviewTimer(
      preview,
      duration:
          _beanResponsePreviewRemaining ??
          _beanResponsePreviewDuration(preview),
    );
  }

  void _dismissBeanResponsePreview() {
    final preview = _beanCollapsedResponsePreview;
    final key = preview?.key ?? _beanResponsePreviewTimerKey;
    if (key == null) return;
    setState(() {
      _dismissedBeanResponsePreviewKey = key;
      _beanResponsePreviewHeld = false;
      _cancelBeanResponsePreviewTimer();
    });
  }

  void _scheduleAppIconBadgeSync(int count) {
    final normalizedCount = math.max(0, count);
    if (_lastScheduledAppIconBadgeCount == normalizedCount) return;
    _lastScheduledAppIconBadgeCount = normalizedCount;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      unawaited(widget.updateAppIconBadge(normalizedCount));
    });
  }

  void _checkReminderDueState() {
    if (!mounted || _phase != _AuthPhase.signedIn) return;
    _syncReminderNotifications();
    if (_dueReminderBanner() != null) {
      setState(() {});
    }
  }

  void _syncReminderNotifications() {
    final user = _user;
    if (user == null || !user.notificationPreferences.reminderPush) return;
    for (final reminder in _reminders) {
      if (_isReminderCompleted(reminder) ||
          _notifiedReminderIds.contains(reminder.id)) {
        continue;
      }
      final dueAt = _parseReminderDueAt(reminder);
      if (dueAt != null &&
          !dueAt.isAfter(DateTime.now()) &&
          !dueAt.isBefore(DateTime.now().subtract(const Duration(hours: 2)))) {
        _notifiedReminderIds.add(reminder.id);
        unawaited(_reminderNotifications.showReminder(reminder));
      }
    }
  }

  HermesReminder? _dueReminderBanner() {
    final now = DateTime.now();
    for (final reminder in _reminders) {
      if (_dismissedReminderBannerIds.contains(reminder.id) ||
          _isReminderCompleted(reminder) ||
          !reminder.isCritical) {
        continue;
      }
      final dueAt = _parseReminderDueAt(reminder);
      if (dueAt != null &&
          !dueAt.isAfter(now) &&
          !dueAt.isBefore(now.subtract(const Duration(hours: 2)))) {
        return reminder;
      }
    }
    return null;
  }

  DateTime? _parseReminderDueAt(HermesReminder reminder) {
    final value = reminder.dueAt;
    if (value == null || value.trim().isEmpty) return null;
    return DateTime.tryParse(value)?.toLocal();
  }

  bool _isReminderCompleted(HermesReminder reminder) {
    final status = reminder.status?.toLowerCase();
    return status == 'completed' || status == 'complete' || status == 'done';
  }

  Future<void> _bootstrap() async {
    await _loadCalendarPreferences();
    final rememberedToken = await widget.tokenStore.loadToken();
    widget.apiClient.bearerToken ??= rememberedToken;
    if (widget.apiClient.bearerToken == null) {
      _stopDashboardChangePolling();
      _applyUserTheme(null);
      setState(() => _phase = _AuthPhase.signedOut);
      return;
    }
    await _loadSignedIn(launchedFromRememberedToken: rememberedToken != null);
  }

  bool _isInvalidTokenError(Object error) =>
      error is HermesApiException &&
      (error.statusCode == 401 || error.statusCode == 403);

  bool _userNeedsSignupPaywall(HermesUser user) {
    if (user.isAdmin) return false;
    if (user.subscriptionTier.trim().toLowerCase() == 'enterprise') {
      return false;
    }
    final status = user.subscriptionStatus?.trim().toLowerCase();
    return status != 'active' && status != 'trialing';
  }

  Future<GoogleCalendarSyncStatus> _syncGoogleCalendarIfConnected({
    GoogleCalendarSyncStatus? fallback,
    bool syncConnected = true,
  }) async {
    try {
      final status = await widget.apiClient.googleCalendarStatus();
      if (!status.connected || !syncConnected) return status;
      final result = await widget.apiClient.syncGoogleCalendar();
      return result.status;
    } catch (_) {
      return fallback ??
          _googleCalendarStatus ??
          const GoogleCalendarSyncStatus(
            connected: false,
            status: 'not_connected',
          );
    }
  }

  Future<void> _loadSignedIn({
    HermesUser? knownUser,
    bool launchedFromRememberedToken = false,
    String? loadingStatusText,
  }) async {
    _stopDashboardChangePolling();
    final authGeneration = ++_authGeneration;
    _workspaceRefreshGeneration++;
    setState(() {
      _phase = _AuthPhase.loading;
      _loadingStatusText = loadingStatusText;
      _dashboardDataLoading = false;
      _error = null;
    });
    Object? refreshError;

    Future<T> recover<T>(Future<T> future, T fallback) async {
      try {
        return await future;
      } catch (error) {
        refreshError ??= error;
        return fallback;
      }
    }

    try {
      final user = knownUser ?? await widget.apiClient.me();
      if (!_isCurrentAuthGeneration(authGeneration)) return;
      _applyUserTheme(user);
      if (_userNeedsSignupPaywall(user)) {
        setState(() {
          _user = user;
          _session = null;
          _tasks = const [];
          _pastTasks = const [];
          _reminders = const [];
          _calendar = const [];
          _noteFolders = const [];
          _notes = const [];
          _memoryItems = const [];
          _memorySummaries = const [];
          _memoryHistory = const [];
          _eventCategories = const [];
          _approvals = const [];
          _events = const [];
          _phase = _AuthPhase.planSelection;
          _loadingStatusText = null;
          _dashboardDataLoading = false;
          _error = null;
          _checkoutError = null;
        });
        return;
      }
      setState(() {
        _user = user;
        _session = null;
        _tasks = const [];
        _pastTasks = const [];
        _reminders = const [];
        _calendar = const [];
        _noteFolders = const [];
        _notes = const [];
        _memoryItems = const [];
        _memorySummaries = const [];
        _memoryHistory = const [];
        _eventCategories = const [];
        _approvals = const [];
        _events = const [];
        _phase = _AuthPhase.signedIn;
        _loadingStatusText = null;
        _dashboardDataLoading = true;
      });

      final sessionDetails = await recover<HermesSessionDetails?>(
        _loadDailySessionForUser(user, source: 'bootstrap'),
        null,
      );
      final session = sessionDetails?.session;
      final googleCalendarStatus = await _syncGoogleCalendarIfConnected(
        fallback:
            _googleCalendarStatus ??
            const GoogleCalendarSyncStatus(
              connected: false,
              status: 'not_connected',
            ),
        syncConnected: false,
      );
      final emptySummary = HermesTodaySummary(
        tasks: const [],
        reminders: const [],
        calendarEvents: const [],
        activityEvents: const [],
        approvals: const [],
        blockers: const [],
      );
      final results = await Future.wait<Object>([
        recover<HermesTodaySummary>(
          widget.apiClient.todaySummary(),
          emptySummary,
        ),
        recover<List<HermesTask>>(
          widget.apiClient.listTasks(),
          const <HermesTask>[],
        ),
        recover<List<HermesReminder>>(
          widget.apiClient.listReminders(),
          const <HermesReminder>[],
        ),
        recover<List<HermesCalendarEvent>>(
          widget.apiClient.listCalendarEvents(),
          const <HermesCalendarEvent>[],
        ),
        recover<List<HermesNoteFolder>>(
          widget.apiClient.listNoteFolders(),
          const <HermesNoteFolder>[],
        ),
        recover<List<HermesNote>>(
          widget.apiClient.listNotes(),
          const <HermesNote>[],
        ),
        recover<List<HermesMemoryItem>>(
          widget.apiClient.listMemoryItems(),
          const <HermesMemoryItem>[],
        ),
        recover<List<HermesMemorySummary>>(
          widget.apiClient.listMemorySummaries(),
          const <HermesMemorySummary>[],
        ),
        recover<List<HermesRequestHistoryItem>>(
          widget.apiClient.listRequestHistory(),
          const <HermesRequestHistoryItem>[],
        ),
        recover<List<HermesTask>>(
          widget.apiClient.listPastTasks(),
          const <HermesTask>[],
        ),
        recover<List<HermesEventCategory>>(
          widget.apiClient.listEventCategories(),
          const <HermesEventCategory>[],
        ),
        session == null
            ? Future<Object>.value(const <HermesActivityEvent>[])
            : recover<List<HermesActivityEvent>>(
                widget.apiClient.pollActivityEvents(session.id),
                const <HermesActivityEvent>[],
              ),
      ]);
      final summary = results[0] as HermesTodaySummary;
      final listedTasks = _tasksWithPendingWrites(
        results[1] as List<HermesTask>,
      );
      final summaryTasks = _tasksWithPendingWrites(summary.tasks);
      final listedReminders = _remindersWithPendingWrites(
        results[2] as List<HermesReminder>,
      );
      final summaryReminders = _remindersWithPendingWrites(summary.reminders);
      final listedCalendarEvents = _calendarEventsWithPendingWrites(
        results[3] as List<HermesCalendarEvent>,
      );
      if (!_isCurrentAuthGeneration(authGeneration)) return;
      _applyUserTheme(user);
      setState(() {
        _user = user;
        _session = session;
        _replaceMessagesFromSession(sessionDetails, user: user);
        _tasks = listedTasks.isEmpty ? summaryTasks : listedTasks;
        _noteFolders = _sortedNoteFolders(results[4] as List<HermesNoteFolder>);
        _notes = _sortedNotes(results[5] as List<HermesNote>);
        _memoryItems = _sortedMemoryItems(results[6] as List<HermesMemoryItem>);
        _memorySummaries = results[7] as List<HermesMemorySummary>;
        _memoryHistory = results[8] as List<HermesRequestHistoryItem>;
        _pastTasks = _tasksWithPendingWrites(results[9] as List<HermesTask>);
        _eventCategories = results[10] as List<HermesEventCategory>;
        _googleCalendarStatus = googleCalendarStatus;
        _reminders = listedReminders.isEmpty
            ? summaryReminders
            : listedReminders;
        _calendar = listedCalendarEvents;
        _approvals = summary.approvals;
        _events = results[11] as List<HermesActivityEvent>;
        _phase = _AuthPhase.signedIn;
        _loadingStatusText = null;
        _dashboardDataLoading = false;
        _error = refreshError == null
            ? null
            : 'You are signed in. ${beanFriendlyErrorMessage(refreshError!, action: 'refresh your latest data')}';
      });
      _syncReminderNotifications();
      unawaited(_pushNotifications.registerForUser(widget.apiClient));
      _cacheCurrentDashboardSnapshot();
      _startDashboardChangePolling(resetCursor: true);
    } catch (error) {
      _stopDashboardChangePolling();
      if (!_isCurrentAuthGeneration(authGeneration)) return;
      final invalidToken = _isInvalidTokenError(error);
      if (invalidToken) {
        await widget.tokenStore.clearToken();
        widget.apiClient.bearerToken = null;
        _applyUserTheme(null);
      }
      setState(() {
        _error = invalidToken
            ? 'Session expired or the saved sign-in is no longer valid. Please sign in again.'
            : launchedFromRememberedToken
            ? 'Bean could not refresh your saved sign-in. Your Remember me token is still saved, so please try again when the connection is back.'
            : 'Bean could not reach your account. Please sign in again and Bean will get right back to work.';
        _user = null;
        _session = null;
        _tasks = const [];
        _pastTasks = const [];
        _reminders = const [];
        _calendar = const [];
        _noteFolders = const [];
        _notes = const [];
        _memoryItems = const [];
        _memorySummaries = const [];
        _memoryHistory = const [];
        _eventCategories = const [];
        _googleCalendarStatus = null;
        _approvals = const [];
        _events = const [];
        _phase = _AuthPhase.signedOut;
        _loadingStatusText = null;
        _dashboardDataLoading = false;
      });
    }
  }

  Future<void> _login(
    String email,
    String password, {
    required bool rememberMe,
  }) async {
    setState(() {
      _busy = true;
      _error = null;
      _authNotice = null;
    });
    try {
      final auth = await widget.apiClient.login(
        email: email,
        password: password,
      );
      if (rememberMe) {
        await widget.tokenStore.saveRememberMe(true);
        await widget.tokenStore.saveToken(auth.token);
      } else {
        await widget.tokenStore.saveRememberMe(false);
        await widget.tokenStore.clearToken();
      }
      await _loadSignedIn(knownUser: auth.user);
    } catch (error) {
      setState(
        () => _error = beanFriendlyErrorMessage(error, action: 'sign you in'),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _register(String name, String email, String password) async {
    setState(() {
      _busy = true;
      _error = null;
      _authNotice = null;
    });
    try {
      final auth = await widget.apiClient.register(
        name: name,
        email: email,
        password: password,
      );
      await widget.tokenStore.saveRememberMe(true);
      await widget.tokenStore.saveToken(auth.token);
      if (!mounted) return;
      _applyUserTheme(auth.user);
      setState(() {
        _user = auth.user;
        _phase = _AuthPhase.planSelection;
        _authNotice = null;
        _error = null;
        _checkoutError = null;
        _dashboardDataLoading = false;
      });
    } catch (error) {
      setState(
        () => _error = beanFriendlyErrorMessage(
          error,
          action: 'create your account',
        ),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _startTrialCheckout(String plan) async {
    if (_checkoutBusyPlan != null) return;
    setState(() {
      _checkoutBusyPlan = plan;
      _checkoutError = null;
    });
    try {
      final setup = await widget.apiClient.createMobileSubscriptionSetup(
        plan: plan,
      );
      await widget.stripePaymentHandler.preparePaymentSheet(
        setup,
        user: _user!,
        primaryButtonLabel: 'Start ${_subscriptionPlanLabel(plan)} trial',
      );
      await widget.stripePaymentHandler.presentPaymentSheet();
      await widget.apiClient.confirmMobileSubscription(
        plan: plan,
        setupIntentId: setup.setupIntentId,
      );
      if (!mounted) return;
      setState(() {
        _checkoutBusyPlan = null;
        _checkoutError = null;
      });
      await _loadSignedIn(loadingStatusText: 'Preparing your dashboard...');
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _checkoutBusyPlan = null;
        _checkoutError = _isStripePaymentCanceled(error)
            ? null
            : beanFriendlyErrorMessage(
                error,
                action: 'start your subscription',
              );
      });
    }
  }

  Future<void> _continueAfterCheckout() async {
    setState(() => _checkoutError = null);
    await _loadSignedIn(loadingStatusText: 'Refreshing your subscription...');
  }

  Future<void> _requestPasswordReset(String email) async {
    await widget.apiClient.requestPasswordReset(email: email);
  }

  Future<void> _completeAgentOnboarding({
    required String agentPersonality,
    required List<String> onboardingPriorities,
    String? onboardingContext,
    String? name,
  }) async {
    final wasEditingAgentPreferences = _editingAgentPreferences;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final updatedUser = await widget.apiClient.updateMe(
        name: name,
        agentPersonality: agentPersonality,
        onboardingPriorities: onboardingPriorities,
        onboardingContext: onboardingContext,
      );
      if (!mounted) return;
      final savedPriorities = List<String>.from(onboardingPriorities);
      final updatedActiveProfile =
          updatedUser.activeWorkspaceAgentProfile ?? updatedUser.agentProfile;
      final previousActiveProfile =
          _user?.activeWorkspaceAgentProfile ?? _user?.agentProfile;
      final savedProfile = HermesAgentProfile(
        id: updatedActiveProfile?.id ?? previousActiveProfile?.id,
        settings: {
          ...?previousActiveProfile?.settings,
          ...?updatedActiveProfile?.settings,
          'personality_type': agentPersonality,
          'onboarding': {
            ...?((previousActiveProfile?.settings['onboarding'] is Map)
                ? Map<String, Object?>.from(
                    previousActiveProfile!.settings['onboarding'] as Map,
                  )
                : null),
            ...?((updatedActiveProfile?.settings['onboarding'] is Map)
                ? Map<String, Object?>.from(
                    updatedActiveProfile!.settings['onboarding'] as Map,
                  )
                : null),
            'completed': true,
            'priorities': savedPriorities,
            'context': onboardingContext,
          },
        },
      );
      setState(() {
        _user = updatedUser.copyWith(
          onboardComplete: true,
          agentProfile:
              updatedUser.agentProfile ?? _user?.agentProfile ?? savedProfile,
          activeWorkspaceAgentProfile: savedProfile,
          needsBeanOnboarding: false,
          beanPreferencesReady: true,
        );
        _forceAgentOnboarding = false;
        _editingAgentPreferences = false;
      });
      if (wasEditingAgentPreferences) {
        try {
          await _notifyAgentPreferencesUpdated(
            agentPersonality: agentPersonality,
            onboardingPriorities: savedPriorities,
            onboardingContext: onboardingContext,
          );
        } catch (_) {
          // Preferences are already persisted in settings; a runtime-memory sync
          // failure should not reopen the editor or make the save look lost.
        }
      }
    } catch (error) {
      if (!mounted) return;
      setState(
        () => _error = beanFriendlyErrorMessage(
          error,
          action: 'save your Bean preferences',
        ),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  bool get _needsBeanIntroduction {
    final user = _user;
    if (user == null) return false;
    return _userNeedsBeanIntroduction(user);
  }

  bool _userNeedsBeanIntroduction(HermesUser user) {
    final serverValue = user.needsBeanOnboarding;
    if (serverValue != null) return serverValue;
    return !user.onboardComplete ||
        !(user.currentAgentProfile?.preferencesReady ?? false);
  }

  bool get _showAgentOnboardingOverlay =>
      _forceAgentOnboarding || _editingAgentPreferences;

  bool get _showBeanIntroSpotlight =>
      _phase == _AuthPhase.signedIn &&
      _needsBeanIntroduction &&
      _selectedDestination != _HomeDestination.bean &&
      !_editingAgentPreferences &&
      !_forceAgentOnboarding;

  String _onboardingTourSeenPreferenceKey(HermesUser user) =>
      '$_onboardingTourSeenPreferencePrefix.${user.id}';

  Future<void> _startOnboardingTourAfterBeanIntroduction() async {
    final user = _user;
    if (user == null || _userNeedsBeanIntroduction(user)) return;
    final prefs = await SharedPreferences.getInstance();
    if (prefs.getBool(_onboardingTourSeenPreferenceKey(user)) == true) return;
    if (!mounted || _phase != _AuthPhase.signedIn) return;
    setState(() {
      _onboardingTourStep = 0;
      _onboardingTourVisible = true;
    });
  }

  void _advanceOnboardingTour() {
    setState(() {
      _onboardingTourStep = math.min(_onboardingTourStep + 1, 3);
    });
  }

  void _dismissOnboardingTour() {
    unawaited(_markOnboardingTourSeenAndClose());
  }

  Future<void> _markOnboardingTourSeenAndClose() async {
    final user = _user;
    if (user != null) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setBool(_onboardingTourSeenPreferenceKey(user), true);
    }
    if (!mounted) return;
    setState(() {
      _onboardingTourVisible = false;
      _onboardingTourStep = 0;
    });
  }

  Future<HermesSessionDetails?> _loadDailySessionForUser(
    HermesUser user, {
    String source = 'flutter',
  }) async {
    final onboarding = _userNeedsBeanIntroduction(user);
    if (!onboarding) {
      final today = DateTime.now().toIso8601String().substring(0, 10);
      final sessions = await widget.apiClient.listConversationSessions(
        date: today,
        workspaceId: user.activeWorkspace?.numericId,
        limit: 30,
      );
      final todaySession = sessions.todaySession;
      if (todaySession != null) {
        return widget.apiClient.resumeSessionDetails(todaySession.id);
      }
    }

    final session = await widget.apiClient.startSession(
      title: onboarding ? 'Welcome to Bean' : 'Today with Bean',
      runtimeMode: onboarding ? 'onboarding' : 'chat',
      workspaceId: user.activeWorkspace?.numericId,
      metadata: _flutterChatMetadata(additional: {'reason': source}),
    );
    return HermesSessionDetails(session: session);
  }

  void _replaceMessagesFromSession(
    HermesSessionDetails? details, {
    HermesUser? user,
  }) {
    _messages.clear();
    if (details != null && details.messages.isNotEmpty) {
      _messages.addAll(details.messages.map(_displayableAssistantMessage));
      return;
    }
    _messages.add(
      HermesMessage(
        id: 0,
        role: 'assistant',
        content: _personalizedBeanIntroMessage(user ?? _user),
      ),
    );
  }

  String _personalizedBeanIntroMessage(HermesUser? user) {
    final profile = user?.currentAgentProfile;
    switch (profile?.personalityType) {
      case 'coach':
        return 'Hey! What are we tackling?';
      case 'organizer':
        return 'What should Bean organize first?';
      case 'creative':
        return "What's on your mind?";
      case 'direct':
        return 'What should Bean handle?';
      case 'gentle':
        return "Hey, how's your day going?";
      case 'balanced':
      default:
        return 'Hey! How can I help?';
    }
  }

  void _selectDestination(_HomeDestination destination) {
    setState(() {
      _selectedDestination = destination;
      if (destination == _HomeDestination.today) {
        _selectedCalendarDay = _dateOnly(DateTime.now());
        _showCalendarMonth = false;
      }
      if (destination == _HomeDestination.bean && _needsBeanIntroduction) {
        _ensureBeanIntroductionPrompt();
      }
    });
  }

  void _ensureBeanIntroductionPrompt() {
    const prompt = "Hi, I'm Bean. What is your name?";
    final alreadyPrompted = _messages.any(
      (message) => message.role == 'assistant' && message.content == prompt,
    );
    if (!alreadyPrompted) {
      _messages.add(
        HermesMessage(
          id: _nextLocalMessageId(),
          role: 'assistant',
          content: prompt,
        ),
      );
    }
  }

  Future<void> _notifyAgentPreferencesUpdated({
    required String agentPersonality,
    required List<String> onboardingPriorities,
    String? onboardingContext,
  }) async {
    final session = _session;
    if (session == null) return;

    await widget.apiClient.sendMessage(
      sessionId: session.id,
      content:
          'Bean preferences were updated in Settings. Save these as your current memory: personality=$agentPersonality; priorities=${onboardingPriorities.join(', ')}; context=${onboardingContext ?? ''}',
      metadata: _flutterChatMetadata(
        additional: {
          'source': 'settings',
          'settings_update': true,
          'agent_personality': agentPersonality,
          'onboarding_priorities': onboardingPriorities,
          'onboarding_context': onboardingContext,
        },
      ),
    );
  }

  Future<void> _startBeanVoiceDraft() async {
    if (_busy || _beanVoiceListening) return;
    setState(() {
      _beanVoiceListening = true;
      _beanVoiceDraft = '';
      _editingChatMessageId = null;
      _chatInputController.clear();
      _error = null;
      _chatRunState = 'Connecting Bean voice';
    });

    try {
      final realtimeSession = await _realtimeConversation.start(
        sessionId: _session?.id,
        workspaceId: _user?.activeWorkspace?.numericId,
        metadata: _flutterChatMetadata(),
        microphoneEnabled: true,
      );
      if (!mounted || !_beanVoiceListening) return;
      _realtimeConversation.beginVoiceCapture();
      setState(() {
        _session = realtimeSession;
        _chatRunState = 'listening';
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _beanVoiceListening = false;
        _beanVoiceDraft = null;
        _chatRunState = 'Ready';
        _beanWorkItems = const [];
        _error = beanFriendlyErrorMessage(
          error,
          action: 'start realtime voice',
        );
      });
    }
  }

  void _updateBeanVoiceDraft(String draft) {
    setState(() => _beanVoiceDraft = draft);
  }

  void _replaceChatMessage(int localMessageId, HermesMessage message) {
    final index = _messages.indexWhere(
      (candidate) => candidate.id == localMessageId,
    );
    if (index == -1) return;
    _messages[index] = _displayableAssistantMessage(message);
  }

  void _beginEditingChatMessage(HermesMessage message) {
    if (_busy || _beanVoiceListening || message.role != 'user') return;
    setState(() {
      _editingChatMessageId = message.id;
      _chatInputController.text = message.content ?? '';
      _chatInputController.selection = TextSelection.collapsed(
        offset: _chatInputController.text.length,
      );
    });
    _chatInputFocusNode.requestFocus();
  }

  Future<void> _copyChatMessage(HermesMessage message) async {
    final content = (message.content ?? '').trim();
    if (content.isEmpty) return;
    await Clipboard.setData(ClipboardData(text: content));
    if (!mounted) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(const SnackBar(content: Text('Copied')));
  }

  Future<void> _stopAgent() async {
    final session = _session;
    if (_beanVoiceListening) {
      await _realtimeConversation.stop();
      if (!mounted) return;
      setState(() {
        _beanVoiceListening = false;
        _beanVoiceDraft = null;
        _editingChatMessageId = null;
        _chatInputController.clear();
        _chatRunState = 'Ready';
        _beanWorkItems = const [];
      });
      return;
    }

    if (!_beanStopAvailable) return;
    _chatRunToken++;
    if (mounted) {
      setState(() {
        _busy = false;
        _editingChatMessageId = null;
        _chatRunState = 'Stopped';
        _beanWorkItems = const [];
        _messages.add(
          HermesMessage(
            id: _messages.length + 1,
            role: 'assistant',
            content: 'Stopped. That request will not update your day.',
          ),
        );
      });
    }

    if (session == null) return;
    unawaited(
      widget.apiClient
          .cancelSession(session.id)
          .then((cancelledSession) {
            if (!mounted) return;
            setState(() => _session = cancelledSession);
          })
          .catchError((_) {}),
    );
  }

  Future<void> _finishBeanVoiceDraft() async {
    if (!_beanVoiceListening) return;
    await _realtimeConversation.endVoiceCaptureForTranscriptionOnly();
    final dictated = _beanVoiceDraft?.trim() ?? '';
    await _realtimeConversation.stop();
    if (!mounted) return;
    setState(() {
      _beanVoiceListening = false;
      _beanVoiceDraft = null;
      _chatInputController.clear();
      _chatRunState = dictated.isEmpty ? 'Ready' : 'Bean is working...';
      _beanWorkItems = const [];
    });
    if (dictated.isNotEmpty) {
      unawaited(_sendChat(dictated));
    }
  }

  Future<void> _sendChatInputDraft() async {
    final text = _chatInputController.text.trim();
    if (text.isEmpty || _busy) return;
    final editingMessageId = _editingChatMessageId;
    _chatInputController.clear();
    await _sendChat(text, editingMessageId: editingMessageId);
  }

  Future<void> _sendChat(String content, {int? editingMessageId}) async {
    final trimmed = content.trim();
    var session = _session;
    if (trimmed.isEmpty || session == null) return;
    final runToken = ++_chatRunToken;
    final capabilityQuestion = _beanRequestIsCapabilityQuestion(trimmed);
    final localUserMessageId = _nextLocalMessageId();
    final editingServerMessageId =
        editingMessageId != null && editingMessageId > 0
        ? editingMessageId
        : null;
    var chatPhase = 'preparing message';
    setState(() {
      _busy = true;
      _editingChatMessageId = null;
      _chatRunState = capabilityQuestion ? 'Thinking…' : 'Bean is working…';
      if (capabilityQuestion) {
        _prepareBeanWorkForFreshRequest();
      } else {
        _ensureBeanRequestWorkItem(trimmed, freshRequest: true);
      }
      if (editingMessageId != null) {
        final editIndex = _messages.indexWhere(
          (message) => message.id == editingMessageId && message.role == 'user',
        );
        if (editIndex != -1) {
          _messages.removeRange(editIndex, _messages.length);
        }
      }
      _messages.add(
        HermesMessage(id: localUserMessageId, role: 'user', content: trimmed),
      );
    });
    try {
      chatPhase = 'checking realtime text';
      final sentRealtime = await _trySendRealtimeText(trimmed);
      if (!mounted || runToken != _chatRunToken) return;
      if (sentRealtime) {
        setState(() {
          _busy = false;
          _chatRunState = 'Bean is responding...';
        });
        return;
      }
      session = _session ?? session;
      final needsBeanIntroduction = _needsBeanIntroduction;
      final useDirectConversationReply =
          !needsBeanIntroduction &&
          (capabilityQuestion || _shouldUseDirectConversationReply(trimmed));
      chatPhase = needsBeanIntroduction
          ? 'sending Bean introduction message'
          : editingServerMessageId != null
          ? 'branching Bean chat message'
          : useDirectConversationReply
          ? 'sending Bean conversation reply'
          : 'queueing Bean chat message';
      final messageMetadata = _flutterChatMetadata(
        additional: editingServerMessageId == null
            ? const {}
            : {'edited_message_id': editingServerMessageId},
      );
      final result = needsBeanIntroduction
          ? await _sendBeanIntroductionMessage(session.id, trimmed)
          : editingServerMessageId != null
          ? await widget.apiClient.branchMessage(
              sessionId: session.id,
              messageId: editingServerMessageId,
              content: trimmed,
              metadata: messageMetadata,
            )
          : useDirectConversationReply
          ? await widget.apiClient.sendMessage(
              sessionId: session.id,
              content: trimmed,
              metadata: messageMetadata,
            )
          : await widget.apiClient.queueMessage(
              sessionId: session.id,
              content: trimmed,
              metadata: messageMetadata,
            );
      if (!mounted || runToken != _chatRunToken) return;
      if (result.status == 'queued') {
        setState(() {
          if (result.userMessage != null) {
            _replaceChatMessage(localUserMessageId, result.userMessage!);
          }
          _session = result.session;
          _chatRunState = 'working...';
          _events = _mergeEvents(result.events, _events);
          _applyBeanWorkEvents(result.events);
          _messages.add(
            HermesMessage(
              id: _messages.length + 1,
              role: 'assistant',
              content: 'I’m working on that in the background.',
            ),
          );
        });
        final run = result.run;
        if (run != null) {
          setState(() {
            _ensureBeanRequestWorkItem(trimmed);
          });
          unawaited(_pollQueuedRun(run.id, runToken));
        }
        return;
      }

      chatPhase = 'refreshing Bean chat results';
      final refreshedEvents = await widget.apiClient
          .pollActivityEvents(session.id)
          .catchError((_) => result.events);
      final refreshedSummary = await widget.apiClient.todaySummary().catchError(
        (_) => HermesTodaySummary(
          tasks: _tasks,
          reminders: _reminders,
          calendarEvents: _calendar,
          activityEvents: _events,
          approvals: _approvals,
          blockers: const [],
        ),
      );
      final refreshedUser = await widget.apiClient.me().catchError(
        (_) => _user!,
      );
      final refreshedCalendar = await widget.apiClient
          .listCalendarEvents()
          .catchError((_) => _calendar);
      final refreshedTasks = await widget.apiClient.listTasks().catchError(
        (_) => refreshedSummary.tasks,
      );
      if (!mounted || runToken != _chatRunToken) return;
      final completedBeanIntroduction =
          needsBeanIntroduction && !_userNeedsBeanIntroduction(refreshedUser);
      setState(() {
        if (result.userMessage != null) {
          _replaceChatMessage(localUserMessageId, result.userMessage!);
        }
        _user = refreshedUser;
        _session = result.session;
        _error = null;
        if (result.status == 'cancelled') {
          _chatRunState = 'Stopped';
        } else if (result.assistantMessage != null) {
          _messages.add(_displayableAssistantMessage(result.assistantMessage!));
          final assistantContent = result.assistantMessage!.content;
          if (_isPlanLimitMessage(assistantContent)) {
            _error = assistantContent;
          }
        } else if (result.status == 'blocked' && result.blocker != null) {
          final reason = _readBlockerReason(result.blocker);
          _messages.add(
            HermesMessage(
              id: _messages.length + 1,
              role: 'assistant',
              content: reason == null || reason.isEmpty
                  ? 'Bean is paused because something needs attention before it can continue. Please check Settings or approvals, then try again.'
                  : 'Bean is paused because $reason Please check Settings or approvals, then try again.',
            ),
          );
        } else if (result.assistantMessage == null) {
          _messages.add(
            HermesMessage(
              id: _messages.length + 1,
              role: 'assistant',
              content:
                  'Bean finished the work, but the response did not come through cleanly. Please tell Bean what you want next and I’ll continue from here.',
            ),
          );
        }
        _chatRunState = switch (result.status) {
          'blocked' => 'Blocked',
          'cancelled' => 'Stopped',
          _ => 'Updated',
        };
        _tasks = _tasksWithPendingWrites(refreshedTasks);
        _reminders = _remindersWithPendingWrites(refreshedSummary.reminders);
        _calendar = refreshedCalendar;
        _approvals = refreshedSummary.approvals;
        _events = _mergeEvents(result.events, refreshedEvents);
        _applyBeanWorkEvents(_events);
        _completeActiveBeanWorkItems();
      });
      if (completedBeanIntroduction) {
        unawaited(_startOnboardingTourAfterBeanIntroduction());
      }
    } catch (error, stackTrace) {
      debugPrint('Bean chat failed during $chatPhase: $error\n$stackTrace');
      unawaited(
        _reportChatFailure(
          error: error,
          stackTrace: stackTrace,
          sessionId: session?.id,
          phase: chatPhase,
          beanIntroduction: _needsBeanIntroduction,
          contentLength: trimmed.length,
        ),
      );
      if (!mounted || runToken != _chatRunToken) return;
      setState(() {
        _chatRunState = 'Failed';
        _beanWorkItems = const [];
        _messages.add(
          HermesMessage(
            id: _messages.length + 1,
            role: 'assistant',
            content: beanFriendlyChatFailureMessage(error),
          ),
        );
        _error = beanFriendlyErrorMessage(error, action: 'send that message');
      });
    } finally {
      if (mounted && runToken == _chatRunToken) setState(() => _busy = false);
    }
  }

  bool _shouldUseDirectConversationReply(String content) {
    if (!_isConversationDecline(content)) return false;
    final lastAssistant = _messages.reversed.where(
      (message) => message.role == 'assistant',
    );
    if (lastAssistant.isEmpty) return false;
    final normalized = _normalizeChatRoutingText(
      lastAssistant.first.content ?? '',
    );
    return normalized.contains('want me to') ||
        normalized.contains('would you like') ||
        normalized.contains('do you want') ||
        normalized.contains('should i') ||
        normalized.contains('want help') ||
        normalized.contains('help set up');
  }

  bool _isConversationDecline(String content) {
    final normalized = _normalizeChatRoutingText(content);
    return RegExp(
      r"^(no|nope|nah|no thanks|no thank you|not now|not right now|skip|nothing else|all set|i'm good|im good|i am good|that's all|that is all)$",
    ).hasMatch(normalized);
  }

  String _normalizeChatRoutingText(String value) => value
      .toLowerCase()
      .replaceAll('’', "'")
      .replaceAll(RegExp(r"[^a-z0-9\s']"), ' ')
      .replaceAll(RegExp(r'\s+'), ' ')
      .trim();

  Future<void> _reportChatFailure({
    required Object error,
    required StackTrace stackTrace,
    required int? sessionId,
    required String phase,
    required bool beanIntroduction,
    required int contentLength,
  }) async {
    final stack = stackTrace.toString();
    final message =
        '''
Flutter Bean chat failure
phase: $phase
bean_introduction: $beanIntroduction
session_id: ${sessionId ?? 'unknown'}
workspace_id: ${_user?.activeWorkspace?.numericId ?? 'unknown'}
content_length: $contentLength
error_type: ${error.runtimeType}
error: ${_truncateDiagnostic(error.toString(), 1000)}
stack:
${_truncateDiagnostic(stack, 2200)}
'''
            .trim();

    try {
      await widget.apiClient.submitIssueReport(
        message: message,
        workspaceId: _user?.activeWorkspace?.numericId,
        pageUrl: 'flutter://bean/chat',
      );
    } catch (reportError) {
      debugPrint('Bean chat failure report failed: $reportError');
    }
  }

  String _truncateDiagnostic(String value, int maxLength) {
    if (value.length <= maxLength) return value;
    return '${value.substring(0, maxLength)}…';
  }

  Future<HermesMessageResult> _sendBeanIntroductionMessage(
    int sessionId,
    String content,
  ) async {
    final metadata = _flutterChatMetadata();
    try {
      return await widget.apiClient.sendMessage(
        sessionId: sessionId,
        content: content,
        metadata: metadata,
      );
    } catch (firstError) {
      debugPrint('Bean onboarding direct message failed: $firstError');
      try {
        return await widget.apiClient.sendMessage(
          sessionId: sessionId,
          content: content,
          metadata: metadata,
        );
      } catch (secondError) {
        debugPrint('Bean onboarding direct message retry failed: $secondError');
        return widget.apiClient.queueMessage(
          sessionId: sessionId,
          content: content,
          metadata: metadata,
        );
      }
    }
  }

  Future<bool> _trySendRealtimeText(String trimmed) async {
    if (!_beanVoiceListening || !_realtimeConversation.active) {
      return false;
    }
    try {
      await _realtimeConversation.sendText(trimmed, audioResponse: true);
      return true;
    } catch (_) {
      return false;
    }
  }

  Future<void> _pollQueuedRun(int runId, int runToken) async {
    for (var attempt = 0; attempt < 30; attempt++) {
      await Future<void>.delayed(const Duration(seconds: 2));
      if (!mounted || runToken != _chatRunToken) return;
      try {
        final run = await widget.apiClient.getAssistantRun(runId);
        if (!mounted || runToken != _chatRunToken) return;
        final sessionId = _session?.id;
        if (sessionId != null) {
          final events = await widget.apiClient
              .pollActivityEvents(sessionId)
              .catchError((_) => const <HermesActivityEvent>[]);
          if (!mounted || runToken != _chatRunToken) return;
          if (events.isNotEmpty) {
            setState(() {
              _events = _mergeEvents(events, _events);
              _applyBeanWorkEvents(events);
            });
          }
        }
        if (run.status == 'completed' ||
            run.status == 'failed' ||
            run.status == 'cancelled') {
          await _refreshSignedInViews();
          if (!mounted || runToken != _chatRunToken) return;
          setState(() {
            _chatRunState = switch (run.status) {
              'completed' => 'Updated',
              'cancelled' => 'Stopped',
              _ => 'Failed',
            };
            _completeActiveBeanWorkItems(switch (run.status) {
              'completed' => 'completed',
              'cancelled' => 'cancelled',
              _ => 'failed',
            });
            final message = run.assistantMessage;
            if (message != null &&
                !_messages.any((candidate) => candidate.id == message.id)) {
              _messages.add(_displayableAssistantMessage(message));
            }
          });
          return;
        }
      } catch (_) {
        // Background polling is opportunistic; dashboard polling still refreshes app state.
      }
    }
  }

  HermesMessage _displayableAssistantMessage(HermesMessage message) {
    return HermesMessage(
      id: message.id,
      role: message.role,
      content: _naturalLanguageContent(message.content),
      metadata: message.metadata,
    );
  }

  String? _naturalLanguageContent(String? content) {
    final trimmed = content?.trim();
    if (trimmed == null || trimmed.isEmpty) return content;
    try {
      final decoded = jsonDecode(trimmed);
      if (decoded is Map<String, Object?>) {
        for (final key in [
          'message',
          'content',
          'assistant_message',
          'response',
        ]) {
          final value = decoded[key];
          if (value is String && value.trim().isNotEmpty) {
            return value.trim();
          }
        }
      }
    } catch (_) {
      // Plain-text assistant messages are already displayable.
    }
    return content;
  }

  String? _readBlockerReason(Map<String, Object?>? blocker) {
    if (blocker == null) return null;
    for (final key in ['reason', 'message', 'title', 'description']) {
      final value = blocker[key];
      if (value is String) {
        final cleaned = _safeValidationSentence(value);
        if (cleaned != null) return cleaned;
      }
    }
    final context = blocker['context'];
    if (context is Map<String, Object?>) {
      final detail =
          context['message'] ?? context['error'] ?? context['failure_type'];
      if (detail is String) {
        final cleaned = _safeValidationSentence(detail);
        if (cleaned != null) return cleaned;
      }
    }
    return null;
  }

  List<HermesActivityEvent> _mergeEvents(
    List<HermesActivityEvent> resultEvents,
    List<HermesActivityEvent> refreshedEvents,
  ) {
    final byKey = <String, HermesActivityEvent>{};
    for (final event in [...refreshedEvents, ...resultEvents]) {
      byKey['${event.id}:${event.eventType}'] = event;
    }
    return byKey.values.toList();
  }

  void _returnToToday() {
    setState(() {
      _selectedDestination = _HomeDestination.today;
      _selectedCalendarDay = _dateOnly(DateTime.now());
      _showCalendarMonth = false;
    });
  }

  void _openCurrentCalendarMonth() {
    setState(() {
      _selectedDestination = _HomeDestination.today;
      _selectedCalendarDay = _dateOnly(DateTime.now());
      _showCalendarMonth = true;
    });
  }

  void _returnToCalendarDay() {
    setState(() => _showCalendarMonth = false);
  }

  void _selectCalendarDay(DateTime date) {
    final allowedDate = _allowedCalendarDate(date);
    final blocked = !_sameCalendarDay(_dateOnly(date), allowedDate);
    setState(() {
      _selectedCalendarDay = allowedDate;
      _showCalendarMonth = false;
      if (blocked) _error = _calendarHistoryLimitMessage();
    });
  }

  void _selectCalendarMonth(DateTime month) {
    final selected = _dateOnly(_selectedCalendarDay);
    final daysInTargetMonth = DateTime(month.year, month.month + 1, 0).day;
    final requested = DateTime(
      month.year,
      month.month,
      selected.day.clamp(1, daysInTargetMonth),
    );
    final allowedDate = _allowedCalendarDate(requested);
    final blocked = !_sameCalendarDay(_dateOnly(requested), allowedDate);
    setState(() {
      _selectedCalendarDay = allowedDate;
      _showCalendarMonth = true;
      if (blocked) _error = _calendarHistoryLimitMessage();
    });
  }

  DateTime? get _calendarHistoryCutoffDay {
    final cutoff = _parseCalendarEventDateTime(_user?.planLimits.historyCutoff);
    return cutoff == null ? null : _dateOnly(cutoff);
  }

  DateTime _allowedCalendarDate(DateTime date) {
    final requested = _dateOnly(date);
    final cutoff = _calendarHistoryCutoffDay;
    if (cutoff == null || !requested.isBefore(cutoff)) return requested;
    return cutoff;
  }

  String _calendarHistoryLimitMessage() {
    final days = _user?.planLimits.historyDays;
    if (days != null && days > 0) {
      return 'Your current plan includes $days days of calendar history.';
    }
    return 'Your current plan has limited calendar history access.';
  }

  Future<void> _loadCalendarPreferences() async {
    final preferences = await SharedPreferences.getInstance();
    final startHour = preferences.getInt(_calendarStartHourPreferenceKey);
    final endHour = preferences.getInt(_calendarEndHourPreferenceKey);
    final nextStart = (startHour ?? _defaultCalendarStartHour).clamp(0, 22);
    final nextEnd = (endHour ?? _defaultCalendarEndHour).clamp(
      nextStart + 1,
      23,
    );
    if (!mounted) return;
    setState(() {
      _calendarStartHour = nextStart;
      _calendarEndHour = nextEnd;
    });
  }

  Future<void> _persistCalendarPreferences() async {
    final preferences = await SharedPreferences.getInstance();
    await Future.wait([
      preferences.setInt(_calendarStartHourPreferenceKey, _calendarStartHour),
      preferences.setInt(_calendarEndHourPreferenceKey, _calendarEndHour),
    ]);
  }

  void _setCalendarStartHour(int hour) {
    setState(() {
      _calendarStartHour = hour.clamp(0, 22);
      if (_calendarEndHour <= _calendarStartHour) {
        _calendarEndHour = (_calendarStartHour + 1).clamp(1, 23);
      }
    });
    unawaited(_persistCalendarPreferences());
  }

  void _setCalendarEndHour(int hour) {
    setState(() {
      _calendarEndHour = hour.clamp(_calendarStartHour + 1, 23);
    });
    unawaited(_persistCalendarPreferences());
  }

  int? _activeWorkspaceId() =>
      _user?.activeWorkspace?.numericId ?? _user?.defaultWorkspaceId;

  void _cacheCurrentDashboardSnapshot({int? workspaceId}) {
    final id = workspaceId ?? _activeWorkspaceId();
    if (id == null || id <= 0) return;
    _workspaceSnapshots[id] = _DashboardSnapshot(
      tasks: List<HermesTask>.unmodifiable(_tasks),
      pastTasks: List<HermesTask>.unmodifiable(_pastTasks),
      reminders: List<HermesReminder>.unmodifiable(_reminders),
      calendar: List<HermesCalendarEvent>.unmodifiable(_calendar),
      noteFolders: List<HermesNoteFolder>.unmodifiable(_noteFolders),
      notes: List<HermesNote>.unmodifiable(_notes),
      memoryItems: List<HermesMemoryItem>.unmodifiable(_memoryItems),
      memorySummaries: List<HermesMemorySummary>.unmodifiable(_memorySummaries),
      memoryHistory: List<HermesRequestHistoryItem>.unmodifiable(
        _memoryHistory,
      ),
      eventCategories: List<HermesEventCategory>.unmodifiable(_eventCategories),
      approvals: List<HermesApproval>.unmodifiable(_approvals),
      events: List<HermesActivityEvent>.unmodifiable(_events),
      googleCalendarStatus: _googleCalendarStatus,
    );
  }

  void _restoreDashboardSnapshot(_DashboardSnapshot snapshot) {
    _tasks = snapshot.tasks;
    _pastTasks = snapshot.pastTasks;
    _reminders = snapshot.reminders;
    _calendar = snapshot.calendar;
    _noteFolders = snapshot.noteFolders;
    _notes = snapshot.notes;
    _memoryItems = snapshot.memoryItems;
    _memorySummaries = snapshot.memorySummaries;
    _memoryHistory = snapshot.memoryHistory;
    _eventCategories = snapshot.eventCategories;
    _approvals = snapshot.approvals;
    _events = snapshot.events;
    _googleCalendarStatus = snapshot.googleCalendarStatus;
  }

  void _clearDashboardData() {
    _tasks = const [];
    _pastTasks = const [];
    _reminders = const [];
    _calendar = const [];
    _noteFolders = const [];
    _notes = const [];
    _memoryItems = const [];
    _memorySummaries = const [];
    _memoryHistory = const [];
    _eventCategories = const [];
    _approvals = const [];
    _events = const [];
  }

  HermesUser _userWithActiveWorkspace(
    HermesUser user,
    HermesWorkspace workspace,
  ) {
    final workspaceId = workspace.numericId;
    return user.copyWith(
      defaultWorkspaceId: workspaceId ?? user.defaultWorkspaceId,
      activeWorkspace: workspace.copyWith(active: true, isDefault: true),
      workspaces: user.workspaces
          .map(
            (candidate) => candidate.copyWith(
              active: candidate.id == workspace.id,
              isDefault: candidate.id == workspace.id,
            ),
          )
          .toList(),
    );
  }

  void _startDashboardChangePolling({bool resetCursor = false}) {
    _dashboardChangeTimer?.cancel();
    _dashboardChangePollGeneration++;
    if (resetCursor) _dashboardChangeLastId = 0;
    if (_phase != _AuthPhase.signedIn) return;

    unawaited(_pollDashboardChanges(markCurrent: true));
    _dashboardChangeTimer = Timer.periodic(
      _dashboardChangePollInterval,
      (_) => unawaited(_pollDashboardChanges()),
    );
  }

  void _stopDashboardChangePolling() {
    _dashboardChangeTimer?.cancel();
    _dashboardChangeTimer = null;
    _dashboardChangePollInFlight = false;
    _dashboardChangePollGeneration++;
  }

  Future<void> _pollDashboardChanges({bool markCurrent = false}) async {
    if (_phase != _AuthPhase.signedIn || _dashboardChangePollInFlight) return;
    _dashboardChangePollInFlight = true;
    final generation = _dashboardChangePollGeneration;
    final authGeneration = _authGeneration;
    final previousLatestId = _dashboardChangeLastId;
    try {
      final feed = await widget.apiClient.dashboardChanges(
        after: previousLatestId,
      );
      if (!mounted ||
          _phase != _AuthPhase.signedIn ||
          authGeneration != _authGeneration ||
          generation != _dashboardChangePollGeneration) {
        return;
      }

      if (feed.latestId != _dashboardChangeLastId) {
        _dashboardChangeLastId = feed.latestId;
      }

      if (!markCurrent &&
          (feed.changes.isNotEmpty ||
              feed.latestId > previousLatestId ||
              (feed.latestId > 0 && feed.latestId < previousLatestId))) {
        await _refreshSignedInViews();
      }
    } catch (_) {
      // Realtime refresh is opportunistic; manual pull-to-refresh still works.
    } finally {
      _dashboardChangePollInFlight = false;
    }
  }

  Future<void> _refreshSignedInViews() async {
    final session = _session;
    if (_phase != _AuthPhase.signedIn || session == null) return;
    final authGeneration = _authGeneration;
    final refreshGeneration = ++_dashboardRefreshGeneration;
    final dataVersion = _dashboardDataVersion;
    try {
      final googleCalendarStatus = await _syncGoogleCalendarIfConnected();
      final results = await Future.wait<Object>([
        widget.apiClient.todaySummary(),
        widget.apiClient.listTasks().catchError((_) => const <HermesTask>[]),
        widget.apiClient.listReminders().catchError(
          (_) => const <HermesReminder>[],
        ),
        widget.apiClient.listCalendarEvents().catchError(
          (_) => const <HermesCalendarEvent>[],
        ),
        widget.apiClient.listNoteFolders().catchError(
          (_) => const <HermesNoteFolder>[],
        ),
        widget.apiClient.listNotes().catchError((_) => const <HermesNote>[]),
        widget.apiClient.listMemoryItems().catchError(
          (_) => const <HermesMemoryItem>[],
        ),
        widget.apiClient.listMemorySummaries().catchError(
          (_) => const <HermesMemorySummary>[],
        ),
        widget.apiClient.listRequestHistory().catchError(
          (_) => const <HermesRequestHistoryItem>[],
        ),
        widget.apiClient.listPastTasks().catchError(
          (_) => const <HermesTask>[],
        ),
        widget.apiClient.listEventCategories().catchError(
          (_) => const <HermesEventCategory>[],
        ),
        widget.apiClient.pollActivityEvents(session.id),
      ]);
      final summary = results[0] as HermesTodaySummary;
      final listedTasks = _tasksWithPendingWrites(
        results[1] as List<HermesTask>,
      );
      final summaryTasks = _tasksWithPendingWrites(summary.tasks);
      final listedReminders = _remindersWithPendingWrites(
        results[2] as List<HermesReminder>,
      );
      final summaryReminders = _remindersWithPendingWrites(summary.reminders);
      final listedCalendarEvents = _calendarEventsWithPendingWrites(
        results[3] as List<HermesCalendarEvent>,
      );
      if (!mounted ||
          _phase != _AuthPhase.signedIn ||
          authGeneration != _authGeneration ||
          refreshGeneration != _dashboardRefreshGeneration ||
          dataVersion != _dashboardDataVersion) {
        return;
      }
      setState(() {
        _tasks = listedTasks.isEmpty ? summaryTasks : listedTasks;
        _noteFolders = _sortedNoteFolders(results[4] as List<HermesNoteFolder>);
        _notes = _sortedNotes(results[5] as List<HermesNote>);
        _memoryItems = _sortedMemoryItems(results[6] as List<HermesMemoryItem>);
        _memorySummaries = results[7] as List<HermesMemorySummary>;
        _memoryHistory = results[8] as List<HermesRequestHistoryItem>;
        _pastTasks = _tasksWithPendingWrites(results[9] as List<HermesTask>);
        _eventCategories = results[10] as List<HermesEventCategory>;
        _googleCalendarStatus = googleCalendarStatus;
        _reminders = listedReminders.isEmpty
            ? summaryReminders
            : listedReminders;
        _calendar = listedCalendarEvents;
        _approvals = summary.approvals;
        _events = results[11] as List<HermesActivityEvent>;
        _dashboardDataLoading = false;
        _error = null;
      });
      _syncReminderNotifications();
      _cacheCurrentDashboardSnapshot();
    } catch (error) {
      if (!mounted ||
          _phase != _AuthPhase.signedIn ||
          authGeneration != _authGeneration ||
          refreshGeneration != _dashboardRefreshGeneration) {
        return;
      }
      setState(() {
        _error = beanFriendlyErrorMessage(
          error,
          action: 'refresh your latest data',
        );
      });
    }
  }

  Future<void> _refreshWorkspaceDataFromServer({
    bool syncConnectedCalendar = false,
    String errorAction = 'refresh your latest data',
  }) async {
    if (_phase != _AuthPhase.signedIn) return;
    final authGeneration = _authGeneration;
    final generation = ++_workspaceRefreshGeneration;
    final refreshGeneration = ++_dashboardRefreshGeneration;
    final dataVersion = _dashboardDataVersion;
    try {
      final user = await widget.apiClient.me();
      final sessionDetails = await _loadDailySessionForUser(
        user,
        source: 'workspace_refresh',
      );
      final session = sessionDetails?.session;
      final googleCalendarStatus = await _syncGoogleCalendarIfConnected(
        fallback:
            _googleCalendarStatus ??
            const GoogleCalendarSyncStatus(
              connected: false,
              status: 'not_connected',
            ),
        syncConnected: syncConnectedCalendar,
      );
      final results = await Future.wait<Object>([
        widget.apiClient.todaySummary(),
        widget.apiClient.listTasks().catchError((_) => const <HermesTask>[]),
        widget.apiClient.listReminders().catchError(
          (_) => const <HermesReminder>[],
        ),
        widget.apiClient.listCalendarEvents().catchError(
          (_) => const <HermesCalendarEvent>[],
        ),
        widget.apiClient.listNoteFolders().catchError(
          (_) => const <HermesNoteFolder>[],
        ),
        widget.apiClient.listNotes().catchError((_) => const <HermesNote>[]),
        widget.apiClient.listMemoryItems().catchError(
          (_) => const <HermesMemoryItem>[],
        ),
        widget.apiClient.listMemorySummaries().catchError(
          (_) => const <HermesMemorySummary>[],
        ),
        widget.apiClient.listRequestHistory().catchError(
          (_) => const <HermesRequestHistoryItem>[],
        ),
        widget.apiClient.listPastTasks().catchError(
          (_) => const <HermesTask>[],
        ),
        widget.apiClient.listEventCategories().catchError(
          (_) => const <HermesEventCategory>[],
        ),
        session == null
            ? Future<Object>.value(const <HermesActivityEvent>[])
            : widget.apiClient
                  .pollActivityEvents(session.id)
                  .catchError((_) => const <HermesActivityEvent>[]),
      ]);
      final summary = results[0] as HermesTodaySummary;
      final listedTasks = _tasksWithPendingWrites(
        results[1] as List<HermesTask>,
      );
      final summaryTasks = _tasksWithPendingWrites(summary.tasks);
      final listedReminders = _remindersWithPendingWrites(
        results[2] as List<HermesReminder>,
      );
      final summaryReminders = _remindersWithPendingWrites(summary.reminders);
      final listedCalendarEvents = _calendarEventsWithPendingWrites(
        results[3] as List<HermesCalendarEvent>,
      );
      if (!mounted ||
          _phase != _AuthPhase.signedIn ||
          authGeneration != _authGeneration ||
          generation != _workspaceRefreshGeneration ||
          refreshGeneration != _dashboardRefreshGeneration ||
          dataVersion != _dashboardDataVersion) {
        return;
      }
      _applyUserTheme(user);
      setState(() {
        _user = user;
        _session = session;
        _replaceMessagesFromSession(sessionDetails, user: user);
        _tasks = listedTasks.isEmpty ? summaryTasks : listedTasks;
        _noteFolders = _sortedNoteFolders(results[4] as List<HermesNoteFolder>);
        _notes = _sortedNotes(results[5] as List<HermesNote>);
        _memoryItems = _sortedMemoryItems(results[6] as List<HermesMemoryItem>);
        _memorySummaries = results[7] as List<HermesMemorySummary>;
        _memoryHistory = results[8] as List<HermesRequestHistoryItem>;
        _pastTasks = _tasksWithPendingWrites(results[9] as List<HermesTask>);
        _eventCategories = results[10] as List<HermesEventCategory>;
        _googleCalendarStatus = googleCalendarStatus;
        _reminders = listedReminders.isEmpty
            ? summaryReminders
            : listedReminders;
        _calendar = listedCalendarEvents;
        _approvals = summary.approvals;
        _events = results[11] as List<HermesActivityEvent>;
        _error = null;
      });
      _syncReminderNotifications();
      _cacheCurrentDashboardSnapshot();
    } catch (error) {
      if (!mounted ||
          _phase != _AuthPhase.signedIn ||
          authGeneration != _authGeneration ||
          generation != _workspaceRefreshGeneration) {
        return;
      }
      setState(() {
        _dashboardDataLoading = false;
        _error = beanFriendlyErrorMessage(error, action: errorAction);
      });
    }
  }

  HermesApproval? _nextPendingApproval() {
    for (final approval in _approvals) {
      if ((approval.status ?? 'pending') == 'pending') return approval;
    }

    return null;
  }

  void _scheduleApprovalSheet() {
    if (_phase != _AuthPhase.signedIn || _approvalSheetOpen) return;
    final approval = _nextPendingApproval();
    if (approval == null || _shownApprovalSheetId == approval.id) return;

    _shownApprovalSheetId = approval.id;
    _approvalSheetOpen = true;
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (!mounted) {
        _approvalSheetOpen = false;
        return;
      }

      final currentApproval = _approvals
          .where(
            (candidate) =>
                candidate.id == approval.id &&
                (candidate.status ?? 'pending') == 'pending',
          )
          .firstOrNull;
      if (currentApproval == null) {
        _approvalSheetOpen = false;
        return;
      }

      await showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        useSafeArea: true,
        backgroundColor: Colors.transparent,
        builder: (context) => _ApprovalRequestSheet(
          approval: currentApproval,
          onApprove: (approval) => _approveApproval(approval),
          onAlwaysApprove: (approval) =>
              _approveApproval(approval, alwaysApprove: true),
          onDeny: _denyApproval,
          onChange: _changeApproval,
        ),
      );
      _approvalSheetOpen = false;
    });
  }

  Future<void> _approveApproval(
    HermesApproval approval, {
    bool alwaysApprove = false,
  }) async {
    await widget.apiClient.approveApproval(
      approval.id,
      alwaysApprove: alwaysApprove,
    );
    if (!mounted) return;
    await _refreshSignedInViews();
  }

  Future<void> _denyApproval(HermesApproval approval) async {
    await widget.apiClient.denyApproval(approval.id);
    if (!mounted) return;
    await _refreshSignedInViews();
  }

  Future<void> _changeApproval(
    HermesApproval approval,
    String revisedRequest,
  ) async {
    await widget.apiClient.denyApproval(approval.id);
    if (!mounted) return;
    await _refreshSignedInViews();
    if (!mounted) return;
    _selectDestination(_HomeDestination.bean);
    await _sendChat(revisedRequest);
  }

  Future<void> _toggleTaskCompletion(HermesTask task) async {
    if (_pendingTaskIds.contains(task.id)) return;
    _pendingTaskIds.add(task.id);
    final wasCompleted = _taskIsCompleted(task);
    final previousTasks = _tasks;
    final previousPastTasks = _pastTasks;
    final optimisticTask = wasCompleted
        ? task.copyWith(status: 'open', clearCompletedAt: true)
        : task.copyWith(
            status: 'completed',
            completedAt: DateTime.now().toIso8601String(),
          );
    _markDashboardDataMutated();
    final mutationVersion = _dashboardDataVersion;
    _rememberPendingTaskWrite(optimisticTask, mutationVersion);
    setState(() {
      if (_tasks.any((candidate) => candidate.id == task.id)) {
        _tasks = _replaceTask(_tasks, optimisticTask);
      }
      if (_pastTasks.any((candidate) => candidate.id == task.id)) {
        _pastTasks = wasCompleted
            ? _removeTask(_pastTasks, task.id)
            : _replaceTask(_pastTasks, optimisticTask);
      }
      _error = null;
    });
    _cacheCurrentDashboardSnapshot();
    unawaited(
      _toggleTaskCompletionInBackground(
        task,
        wasCompleted: wasCompleted,
        optimisticTask: optimisticTask,
        previousTasks: previousTasks,
        previousPastTasks: previousPastTasks,
        mutationVersion: mutationVersion,
      ),
    );
    return Future<void>.value();
  }

  Future<void> _toggleTaskCompletionInBackground(
    HermesTask task, {
    required bool wasCompleted,
    required HermesTask optimisticTask,
    required List<HermesTask> previousTasks,
    required List<HermesTask> previousPastTasks,
    required int mutationVersion,
  }) async {
    try {
      final updatedTask = wasCompleted
          ? await widget.apiClient.reopenTask(task.id)
          : await widget.apiClient.completeTask(task.id);
      if (!_canApplyBackgroundSave(mutationVersion) ||
          !_pendingTaskWriteIsCurrent(
            optimisticTask.id,
            optimisticTask,
            mutationVersion,
          )) {
        return;
      }
      _forgetPendingTaskWrite(optimisticTask.id);
      _rememberPendingTaskWrite(updatedTask, mutationVersion);
      _markDashboardDataMutated();
      setState(() {
        if (_tasks.any((candidate) => candidate.id == updatedTask.id)) {
          _tasks = _replaceTask(_tasks, updatedTask);
        }
        if (_pastTasks.any((candidate) => candidate.id == updatedTask.id)) {
          _pastTasks = _replaceTask(_pastTasks, updatedTask);
        }
      });
      _cacheCurrentDashboardSnapshot();
      unawaited(_refreshSignedInViews());
    } catch (error) {
      if (!_canApplyBackgroundSave(mutationVersion) ||
          !_pendingTaskWriteIsCurrent(
            optimisticTask.id,
            optimisticTask,
            mutationVersion,
          )) {
        return;
      }
      _markDashboardDataMutated();
      _forgetPendingTaskWrite(optimisticTask.id);
      setState(() {
        _tasks = previousTasks;
        _pastTasks = previousPastTasks;
        _error = wasCompleted
            ? beanFriendlyErrorMessage(error, action: 'reopen that task')
            : beanFriendlyErrorMessage(error, action: 'complete that task');
      });
    } finally {
      _pendingTaskIds.remove(task.id);
    }
  }

  Future<void> _createOrUpdateTask(
    HermesTask? task, {
    required String title,
    String? dueAt,
    String? notes,
    String? category,
    String? color,
    bool? isCritical,
    int? parentTaskId,
    int? workspaceId,
    Map<String, Object?>? recurrenceMetadata,
    List<Object> syncToWorkspaceIds = const [],
    List<String> googleCalendarIds = const [],
  }) {
    final normalizedDueAt = _taskReminderInputToWireValue(dueAt);
    final normalizedColor = category == null ? _themeCategoryColorHex() : color;
    final metadata = <String, Object?>{
      ...?task?.metadata,
      ...?recurrenceMetadata,
      if (googleCalendarIds.isNotEmpty || task != null)
        'google_calendar_ids': googleCalendarIds,
      if (parentTaskId != null || task?.parentTaskId != null)
        'parent_task_id': parentTaskId ?? task!.parentTaskId,
    };
    final previousTasks = _tasks;
    final previousPastTasks = _pastTasks;
    final optimisticTask = task == null
        ? HermesTask(
            id: _nextLocalResourceId(),
            title: title,
            status: 'open',
            dueAt: normalizedDueAt,
            notes: notes,
            category: category,
            color: normalizedColor,
            isCritical: isCritical ?? false,
            metadata: metadata.isEmpty ? null : metadata,
            workspaceId: workspaceId,
          )
        : task.copyWith(
            title: title,
            status: task.status ?? 'open',
            dueAt: normalizedDueAt,
            notes: notes,
            category: category,
            color: normalizedColor,
            isCritical: isCritical,
            metadata: metadata,
            clearCategory: category == null,
            clearColor: false,
            clearNotes: notes == null,
          );
    _markDashboardDataMutated();
    final mutationVersion = _dashboardDataVersion;
    _rememberPendingTaskWrite(optimisticTask, mutationVersion);
    setState(() {
      if (task == null) {
        _tasks = [..._tasks, optimisticTask];
      } else {
        if (_tasks.any((item) => item.id == task.id)) {
          _tasks = _replaceTask(_tasks, optimisticTask);
        }
        if (_pastTasks.any((item) => item.id == task.id)) {
          _pastTasks = _replaceTask(_pastTasks, optimisticTask);
        }
      }
      _error = null;
    });
    _cacheCurrentDashboardSnapshot();
    unawaited(
      _saveTaskInBackground(
        task,
        title: title,
        normalizedDueAt: normalizedDueAt,
        notes: notes,
        category: category,
        normalizedColor: normalizedColor,
        isCritical: isCritical,
        metadata: metadata,
        workspaceId: workspaceId,
        syncToWorkspaceIds: syncToWorkspaceIds,
        optimisticTask: optimisticTask,
        previousTasks: previousTasks,
        previousPastTasks: previousPastTasks,
        mutationVersion: mutationVersion,
      ),
    );
    return Future<void>.value();
  }

  Future<void> _saveTaskInBackground(
    HermesTask? task, {
    required String title,
    required String? normalizedDueAt,
    required String? notes,
    required String? category,
    required String? normalizedColor,
    required bool? isCritical,
    required Map<String, Object?> metadata,
    required int? workspaceId,
    required List<Object> syncToWorkspaceIds,
    required HermesTask optimisticTask,
    required List<HermesTask> previousTasks,
    required List<HermesTask> previousPastTasks,
    required int mutationVersion,
  }) async {
    try {
      final saved = task == null
          ? await widget.apiClient.createTask(
              title: title,
              dueAt: normalizedDueAt,
              notes: notes,
              category: category,
              color: normalizedColor,
              isCritical: isCritical ?? false,
              metadata: metadata.isEmpty ? null : metadata,
              workspaceId: workspaceId,
              syncToWorkspaceIds: syncToWorkspaceIds,
            )
          : await widget.apiClient.updateTask(
              task.id,
              title: title,
              status: task.status ?? 'open',
              dueAt: normalizedDueAt,
              notes: notes,
              category: category,
              color: normalizedColor,
              isCritical: isCritical,
              metadata: metadata,
              clearCategory: category == null,
              clearColor: false,
              clearNotes: notes == null,
              syncToWorkspaceIds: syncToWorkspaceIds,
            );
      if (!_canApplyBackgroundSave(mutationVersion) ||
          !_pendingTaskWriteIsCurrent(
            optimisticTask.id,
            optimisticTask,
            mutationVersion,
          )) {
        return;
      }
      _forgetPendingTaskWrite(optimisticTask.id, clearVersion: task == null);
      _rememberPendingTaskWrite(saved, mutationVersion);
      setState(() {
        final replaceId = optimisticTask.id;
        if (_tasks.any((item) => item.id == replaceId)) {
          _tasks = _tasks
              .map((item) => item.id == replaceId ? saved : item)
              .toList(growable: false);
        } else if (_tasks.any((item) => item.id == saved.id)) {
          _tasks = _replaceTask(_tasks, saved);
        }
        if (_pastTasks.any((item) => item.id == replaceId)) {
          _pastTasks = _pastTasks
              .map((item) => item.id == replaceId ? saved : item)
              .toList(growable: false);
        } else if (_pastTasks.any((item) => item.id == saved.id)) {
          _pastTasks = _replaceTask(_pastTasks, saved);
        }
        _error = null;
      });
      _cacheCurrentDashboardSnapshot();
      unawaited(_refreshSignedInViews());
    } catch (error) {
      if (!_canApplyBackgroundSave(mutationVersion) ||
          !_pendingTaskWriteIsCurrent(
            optimisticTask.id,
            optimisticTask,
            mutationVersion,
          )) {
        return;
      }
      _forgetPendingTaskWrite(optimisticTask.id);
      _markDashboardDataMutated();
      setState(() {
        _tasks = previousTasks;
        _pastTasks = previousPastTasks;
        _error = beanFriendlyErrorMessage(error, action: 'save that task');
      });
    }
  }

  Future<void> _showNewTaskEditor() async {
    await _showTitleTimeEditor(
      context,
      title: 'New task',
      titleLabel: 'Task title',
      timeLabel: 'Due date',
      initialTitle: '',
      initialTime: '',
      initialNotes: '',
      allowEmptyTime: true,
      showNotes: true,
      categories: _eventCategories,
      initialCritical: false,
      onEventCategorySaved: _saveEventCategory,
      workspaces: _user?.workspaces ?? const [],
      activeWorkspaceId: _user?.activeWorkspace?.id,
      showPrimaryWorkspaceSelector: true,
      initialPrimaryWorkspaceId: _user?.activeWorkspace == null
          ? null
          : _workspaceValue(_user!.activeWorkspace!),
      googleCalendarStatus: _googleCalendarStatus,
      showRecurrence: true,
      recurrenceTitle: 'Task recurrence',
      recurrenceSubtitle: 'Repeat this task when needed.',
      recurrenceInfoTitle: 'Task recurrence',
      onSave: (result) async {
        final title = (result['title'] as String).trim();
        if (title.isEmpty) return;
        await _createOrUpdateTask(
          null,
          title: title,
          dueAt: result['time'] as String?,
          notes: result['notes'] as String?,
          category: result['category'] as String?,
          color: result['color'] as String?,
          isCritical: result['isCritical'] as bool?,
          workspaceId: result['workspaceId'] as int?,
          recurrenceMetadata:
              result['recurrenceMetadata'] as Map<String, Object?>?,
          syncToWorkspaceIds:
              (result['syncToWorkspaceIds'] as List?)
                  ?.whereType<Object>()
                  .toList() ??
              const [],
          googleCalendarIds:
              (result['googleCalendarIds'] as List?)
                  ?.map((value) => value.toString())
                  .toList() ??
              const [],
        );
      },
    );
  }

  Future<void> _showNewReminderEditor() async {
    await _showTitleTimeEditor(
      context,
      title: 'New reminder',
      titleLabel: 'Reminder title',
      timeLabel: 'Remind me at',
      initialTitle: '',
      initialTime: '',
      allowEmptyTime: false,
      categories: _eventCategories,
      showCritical: false,
      showTimeTextField: false,
      onEventCategorySaved: _saveEventCategory,
      workspaces: _user?.workspaces ?? const [],
      activeWorkspaceId: _user?.activeWorkspace?.id,
      showPrimaryWorkspaceSelector: true,
      initialPrimaryWorkspaceId: _user?.activeWorkspace == null
          ? null
          : _workspaceValue(_user!.activeWorkspace!),
      googleCalendarStatus: _googleCalendarStatus,
      showRecurrence: true,
      recurrenceTitle: 'Reminder repeats',
      recurrenceSubtitle: 'Repeat this reminder when needed.',
      recurrenceInfoTitle: 'Reminder recurrence',
      onSave: (result) async {
        final title = (result['title'] as String).trim();
        final time = (result['time'] as String?)?.trim() ?? '';
        if (title.isEmpty || time.isEmpty) return;
        await _createOrUpdateReminder(
          null,
          title: title,
          remindAt: time,
          status: 'pending',
          category: result['category'] as String?,
          color: result['color'] as String?,
          workspaceId: result['workspaceId'] as int?,
          recurrenceMetadata:
              result['recurrenceMetadata'] as Map<String, Object?>?,
          syncToWorkspaceIds:
              (result['syncToWorkspaceIds'] as List?)
                  ?.whereType<Object>()
                  .toList() ??
              const [],
          googleCalendarIds:
              (result['googleCalendarIds'] as List?)
                  ?.map((value) => value.toString())
                  .toList() ??
              const [],
        );
      },
    );
  }

  Future<void> _deleteTask(
    HermesTask task, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    final previousTasks = _tasks;
    final previousPastTasks = _pastTasks;
    _markDashboardDataMutated();
    final mutationVersion = _dashboardDataVersion;
    _rememberPendingTaskDelete(task.id, mutationVersion);
    setState(() {
      _tasks = _removeTask(_tasks, task.id);
      _pastTasks = _removeTask(_pastTasks, task.id);
      _error = null;
    });
    _cacheCurrentDashboardSnapshot();
    unawaited(
      _deleteTaskInBackground(
        task,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
        previousTasks: previousTasks,
        previousPastTasks: previousPastTasks,
        mutationVersion: mutationVersion,
      ),
    );
    return Future<void>.value();
  }

  Future<void> _deleteTaskInBackground(
    HermesTask task, {
    required List<Object> deleteFromWorkspaceIds,
    required List<HermesTask> previousTasks,
    required List<HermesTask> previousPastTasks,
    required int mutationVersion,
  }) async {
    try {
      await widget.apiClient.deleteTask(
        task.id,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
      );
      if (!_canApplyBackgroundSave(mutationVersion)) return;
      _forgetPendingTaskWrite(task.id);
      _cacheCurrentDashboardSnapshot();
      unawaited(_refreshSignedInViews());
    } catch (error) {
      if (_canApplyBackgroundSave(mutationVersion)) {
        _markDashboardDataMutated();
        _forgetPendingTaskWrite(task.id);
        setState(() {
          _tasks = previousTasks;
          _pastTasks = previousPastTasks;
          _error = beanFriendlyErrorMessage(error, action: 'delete that task');
        });
      }
    }
  }

  Future<void> _createOrUpdateReminder(
    HermesReminder? reminder, {
    required String title,
    required String remindAt,
    String status = 'pending',
    String? category,
    String? color,
    int? workspaceId,
    Map<String, Object?>? recurrenceMetadata,
    List<Object> syncToWorkspaceIds = const [],
    List<String> googleCalendarIds = const [],
  }) {
    final normalizedRemindAt = _taskReminderInputToWireValue(remindAt);
    if (normalizedRemindAt == null) {
      if (mounted) setState(() => _error = 'Reminder time is required.');
      return Future<void>.value();
    }
    final normalizedColor = category == null ? _themeCategoryColorHex() : color;
    final metadata = <String, Object?>{
      ...?reminder?.metadata,
      ...?recurrenceMetadata,
      if (googleCalendarIds.isNotEmpty || reminder != null)
        'google_calendar_ids': googleCalendarIds,
    };
    final previousReminders = _reminders;
    final optimisticReminder = reminder == null
        ? HermesReminder(
            id: _nextLocalResourceId(),
            title: title,
            dueAt: normalizedRemindAt,
            category: category,
            color: normalizedColor,
            status: status,
            metadata: metadata.isEmpty ? null : metadata,
            workspaceId: workspaceId,
          )
        : reminder.copyWith(
            title: title,
            dueAt: normalizedRemindAt,
            status: status,
            category: category,
            color: normalizedColor,
            metadata: metadata,
            clearCategory: category == null,
            clearColor: false,
          );
    _markDashboardDataMutated();
    final mutationVersion = _dashboardDataVersion;
    _rememberPendingReminderWrite(optimisticReminder, mutationVersion);
    setState(() {
      final existingId = reminder?.id;
      if (existingId == null) {
        _reminders = [..._reminders, optimisticReminder];
      } else {
        _reminders = _reminders
            .map((item) => item.id == existingId ? optimisticReminder : item)
            .toList(growable: false);
      }
      _error = null;
    });
    _cacheCurrentDashboardSnapshot();
    unawaited(
      _saveReminderInBackground(
        reminder,
        title: title,
        normalizedRemindAt: normalizedRemindAt,
        status: status,
        category: category,
        normalizedColor: normalizedColor,
        metadata: metadata,
        workspaceId: workspaceId,
        syncToWorkspaceIds: syncToWorkspaceIds,
        optimisticReminder: optimisticReminder,
        previousReminders: previousReminders,
        mutationVersion: mutationVersion,
      ),
    );
    return Future<void>.value();
  }

  Future<void> _saveReminderInBackground(
    HermesReminder? reminder, {
    required String title,
    required String normalizedRemindAt,
    required String status,
    required String? category,
    required String? normalizedColor,
    required Map<String, Object?> metadata,
    required int? workspaceId,
    required List<Object> syncToWorkspaceIds,
    required HermesReminder optimisticReminder,
    required List<HermesReminder> previousReminders,
    required int mutationVersion,
  }) async {
    try {
      final saved = reminder == null
          ? await widget.apiClient.createReminder(
              title: title,
              remindAt: normalizedRemindAt,
              status: status,
              category: category,
              color: normalizedColor,
              metadata: metadata.isEmpty ? null : metadata,
              workspaceId: workspaceId,
              syncToWorkspaceIds: syncToWorkspaceIds,
            )
          : await widget.apiClient.updateReminder(
              reminder.id,
              title: title,
              remindAt: normalizedRemindAt,
              status: status,
              category: category,
              color: normalizedColor,
              metadata: metadata,
              clearCategory: category == null,
              clearColor: false,
              syncToWorkspaceIds: syncToWorkspaceIds,
            );
      if (!_canApplyBackgroundSave(mutationVersion) ||
          !_pendingReminderWriteIsCurrent(
            optimisticReminder.id,
            optimisticReminder,
            mutationVersion,
          )) {
        return;
      }
      _forgetPendingReminderWrite(
        optimisticReminder.id,
        clearVersion: reminder == null,
      );
      _rememberPendingReminderWrite(saved, mutationVersion);
      setState(() {
        final replaceId = optimisticReminder.id;
        if (_reminders.any((item) => item.id == replaceId)) {
          _reminders = _reminders
              .map((item) => item.id == replaceId ? saved : item)
              .toList(growable: false);
        } else if (_reminders.any((item) => item.id == saved.id)) {
          _reminders = _reminders
              .map((item) => item.id == saved.id ? saved : item)
              .toList(growable: false);
        }
        _error = null;
      });
      _cacheCurrentDashboardSnapshot();
      unawaited(_refreshSignedInViews());
    } catch (error) {
      if (!_canApplyBackgroundSave(mutationVersion) ||
          !_pendingReminderWriteIsCurrent(
            optimisticReminder.id,
            optimisticReminder,
            mutationVersion,
          )) {
        return;
      }
      _forgetPendingReminderWrite(optimisticReminder.id);
      _markDashboardDataMutated();
      setState(() {
        _reminders = previousReminders;
        _error = beanFriendlyErrorMessage(error, action: 'save that reminder');
      });
    }
  }

  Future<void> _toggleReminderCompletion(HermesReminder reminder) async {
    final previousReminders = _reminders;
    final completed = _reminderIsCompleted(reminder);
    final updatedStatus = completed ? 'pending' : 'completed';
    final optimisticReminder = reminder.copyWith(status: updatedStatus);
    _markDashboardDataMutated();
    final mutationVersion = _dashboardDataVersion;
    _rememberPendingReminderWrite(optimisticReminder, mutationVersion);
    setState(() {
      _reminders = _reminders
          .map((item) => item.id == reminder.id ? optimisticReminder : item)
          .toList();
      _error = null;
    });
    _cacheCurrentDashboardSnapshot();
    unawaited(
      _toggleReminderCompletionInBackground(
        reminder,
        updatedStatus: updatedStatus,
        completed: completed,
        optimisticReminder: optimisticReminder,
        previousReminders: previousReminders,
        mutationVersion: mutationVersion,
      ),
    );
    return Future<void>.value();
  }

  Future<void> _toggleReminderCompletionInBackground(
    HermesReminder reminder, {
    required String updatedStatus,
    required bool completed,
    required HermesReminder optimisticReminder,
    required List<HermesReminder> previousReminders,
    required int mutationVersion,
  }) async {
    try {
      final saved = await widget.apiClient.updateReminder(
        reminder.id,
        status: updatedStatus,
      );
      if (!_canApplyBackgroundSave(mutationVersion) ||
          !_pendingReminderWriteIsCurrent(
            optimisticReminder.id,
            optimisticReminder,
            mutationVersion,
          )) {
        return;
      }
      _forgetPendingReminderWrite(optimisticReminder.id);
      _rememberPendingReminderWrite(saved, mutationVersion);
      _markDashboardDataMutated();
      setState(() {
        _reminders = _reminders
            .map((item) => item.id == saved.id ? saved : item)
            .toList();
      });
      _cacheCurrentDashboardSnapshot();
      unawaited(_refreshSignedInViews());
    } catch (error) {
      if (!_canApplyBackgroundSave(mutationVersion) ||
          !_pendingReminderWriteIsCurrent(
            optimisticReminder.id,
            optimisticReminder,
            mutationVersion,
          )) {
        return;
      }
      _markDashboardDataMutated();
      _forgetPendingReminderWrite(optimisticReminder.id);
      setState(() {
        _reminders = previousReminders;
        _error = completed
            ? beanFriendlyErrorMessage(error, action: 'reopen that reminder')
            : beanFriendlyErrorMessage(error, action: 'complete that reminder');
      });
    }
  }

  Future<void> _deleteReminder(
    HermesReminder reminder, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    final previousReminders = _reminders;
    _markDashboardDataMutated();
    final mutationVersion = _dashboardDataVersion;
    _rememberPendingReminderDelete(reminder.id, mutationVersion);
    setState(() {
      _reminders = _reminders.where((item) => item.id != reminder.id).toList();
      _error = null;
    });
    _cacheCurrentDashboardSnapshot();
    unawaited(
      _deleteReminderInBackground(
        reminder,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
        previousReminders: previousReminders,
        mutationVersion: mutationVersion,
      ),
    );
    return Future<void>.value();
  }

  Future<void> _deleteReminderInBackground(
    HermesReminder reminder, {
    required List<Object> deleteFromWorkspaceIds,
    required List<HermesReminder> previousReminders,
    required int mutationVersion,
  }) async {
    try {
      await widget.apiClient.deleteReminder(
        reminder.id,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
      );
      if (!_canApplyBackgroundSave(mutationVersion)) return;
      _forgetPendingReminderWrite(reminder.id);
      _cacheCurrentDashboardSnapshot();
      unawaited(_refreshSignedInViews());
    } catch (error) {
      if (_canApplyBackgroundSave(mutationVersion)) {
        _markDashboardDataMutated();
        _forgetPendingReminderWrite(reminder.id);
        setState(() {
          _reminders = previousReminders;
          _error = beanFriendlyErrorMessage(
            error,
            action: 'delete that reminder',
          );
        });
      }
    }
  }

  Future<HermesNoteFolder> _createNoteFolder(String name) async {
    final folder = await widget.apiClient.createNoteFolder(name: name);
    if (!mounted) return folder;
    setState(
      () => _noteFolders = _sortedNoteFolders([..._noteFolders, folder]),
    );
    return folder;
  }

  Future<void> _deleteNoteFolder(HermesNoteFolder folder) async {
    await widget.apiClient.deleteNoteFolder(folder.id);
    if (!mounted) return;
    setState(() {
      _noteFolders = _sortedNoteFolders(
        _noteFolders.where((candidate) => candidate.id != folder.id).toList(),
      );
      _notes = _notes
          .map(
            (note) => note.folderId == folder.id
                ? note.copyWith(clearFolder: true)
                : note,
          )
          .toList();
    });
  }

  Future<HermesNote> _saveNote(
    HermesNote? note, {
    required String title,
    required String bodyHtml,
    required String plainText,
    int? folderId,
    bool clearFolder = false,
    bool? isPinned,
    Map<String, Object?>? metadata,
    List<Object>? syncToWorkspaceIds,
  }) async {
    final saved = note == null
        ? await widget.apiClient.createNote(
            title: title,
            bodyHtml: bodyHtml,
            plainText: plainText,
            folderId: folderId,
            isPinned: isPinned ?? false,
            metadata: metadata,
            syncToWorkspaceIds: syncToWorkspaceIds ?? const [],
          )
        : await widget.apiClient.updateNote(
            note.id,
            title: title,
            bodyHtml: bodyHtml,
            plainText: plainText,
            folderId: folderId,
            clearFolder: clearFolder,
            isPinned: isPinned,
            metadata: metadata,
            syncToWorkspaceIds: syncToWorkspaceIds,
          );
    if (!mounted) return saved;
    setState(() => _notes = _upsertNote(_notes, saved));
    return saved;
  }

  Future<void> _createNoteFromTopMenu() async {
    if (mounted) {
      setState(() => _selectedDestination = _HomeDestination.notes);
    }
    final saved = await _saveNote(
      null,
      title: 'New Note',
      bodyHtml: '',
      plainText: '',
      clearFolder: true,
      metadata: const {},
    );
    if (!mounted) return;
    setState(() {
      _selectedDestination = _HomeDestination.notes;
      _noteToOpenId = saved.id;
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || _noteToOpenId != saved.id) return;
      setState(() => _noteToOpenId = null);
    });
  }

  Future<void> _deleteNote(HermesNote note) async {
    await widget.apiClient.deleteNote(note.id);
    if (!mounted) return;
    setState(
      () => _notes = _notes.where((item) => item.id != note.id).toList(),
    );
  }

  Future<void> _refreshMemory() async {
    final results = await Future.wait<Object>([
      widget.apiClient.listMemoryItems(),
      widget.apiClient.listMemorySummaries(),
      widget.apiClient.listRequestHistory(),
    ]);
    if (!mounted) return;
    setState(() {
      _memoryItems = _sortedMemoryItems(results[0] as List<HermesMemoryItem>);
      _memorySummaries = results[1] as List<HermesMemorySummary>;
      _memoryHistory = results[2] as List<HermesRequestHistoryItem>;
    });
    _cacheCurrentDashboardSnapshot();
  }

  Future<HermesMemoryItem> _createMemoryItem({
    required String content,
    String type = 'fact',
    String? title,
  }) async {
    final saved = await widget.apiClient.createMemoryItem(
      content: content,
      type: type,
      title: title,
    );
    if (!mounted) return saved;
    setState(() => _memoryItems = _upsertMemoryItem(_memoryItems, saved));
    _cacheCurrentDashboardSnapshot();
    return saved;
  }

  Future<HermesMemoryItem> _updateMemoryItem(
    HermesMemoryItem item, {
    required String content,
    required String type,
    String? title,
  }) async {
    final saved = await widget.apiClient.updateMemoryItem(
      item.id,
      content: content,
      type: type,
      title: title,
    );
    if (!mounted) return saved;
    setState(() => _memoryItems = _upsertMemoryItem(_memoryItems, saved));
    _cacheCurrentDashboardSnapshot();
    return saved;
  }

  Future<void> _deleteMemoryItem(HermesMemoryItem item) async {
    await widget.apiClient.deleteMemoryItem(item.id);
    if (!mounted) return;
    setState(
      () => _memoryItems = _memoryItems
          .where((candidate) => candidate.id != item.id)
          .toList(growable: false),
    );
    _cacheCurrentDashboardSnapshot();
  }

  Future<HermesEventCategory> _saveEventCategory({
    HermesEventCategory? category,
    required String name,
    required String color,
  }) async {
    final saved = category == null
        ? await widget.apiClient.createEventCategory(name: name, color: color)
        : await widget.apiClient.updateEventCategory(
            category.id,
            name: name,
            color: color,
          );
    if (!mounted) return saved;
    _markDashboardDataMutated();
    setState(() {
      final exists = _eventCategories.any((item) => item.id == saved.id);
      _eventCategories = exists
          ? _eventCategories
                .map((item) => item.id == saved.id ? saved : item)
                .toList()
          : [..._eventCategories, saved];
    });
    _cacheCurrentDashboardSnapshot();
    return saved;
  }

  Future<void> _deleteEventCategory(
    HermesEventCategory category, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    await widget.apiClient.deleteEventCategory(
      category.id,
      deleteFromWorkspaceIds: deleteFromWorkspaceIds,
    );
    if (!mounted) return;
    _markDashboardDataMutated();
    setState(() {
      _eventCategories = _eventCategories
          .where((item) => item.id != category.id)
          .toList();
      _calendar = _calendar
          .map(
            (event) => event.category == category.name
                ? event.copyWith(
                    clearCategory: true,
                    color: _themeCategoryColorHex(),
                  )
                : event,
          )
          .toList();
      _tasks = _tasks
          .map(
            (task) => task.category == category.name
                ? task.copyWith(
                    clearCategory: true,
                    color: _themeCategoryColorHex(),
                  )
                : task,
          )
          .toList();
      _reminders = _reminders
          .map(
            (reminder) => reminder.category == category.name
                ? reminder.copyWith(
                    clearCategory: true,
                    color: _themeCategoryColorHex(),
                  )
                : reminder,
          )
          .toList();
    });
    _cacheCurrentDashboardSnapshot();
  }

  Future<void> _createCalendarEvent({
    required String title,
    required String startsAt,
    String? endsAt,
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool? isCritical,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
    int? workspaceId,
    List<Object> syncToWorkspaceIds = const [],
  }) {
    final wireStartsAt = _calendarEventWireValueToUtcIso(startsAt) ?? startsAt;
    final wireEndsAt = _calendarEventWireValueToUtcIso(endsAt);
    final normalizedColor = category == null ? _themeCategoryColorHex() : color;
    final previousCalendar = _calendar;
    final optimisticEvent = HermesCalendarEvent(
      id: _nextLocalResourceId(),
      title: title,
      startsAt: wireStartsAt,
      endsAt: wireEndsAt,
      notes: notes,
      location: location,
      status: status,
      category: category,
      color: normalizedColor,
      recurrence: recurrence,
      metadata: metadata,
      isCritical: isCritical ?? false,
      workspaceId: workspaceId ?? _user?.activeWorkspace?.numericId,
    );
    _markDashboardDataMutated();
    final mutationVersion = _dashboardDataVersion;
    _rememberPendingCalendarEventWrite(optimisticEvent, mutationVersion);
    setState(() {
      _calendar = [..._calendar, optimisticEvent];
      _error = null;
    });
    _cacheCurrentDashboardSnapshot();
    unawaited(
      _createCalendarEventInBackground(
        title: title,
        wireStartsAt: wireStartsAt,
        wireEndsAt: wireEndsAt,
        notes: notes,
        location: location,
        status: status,
        category: category,
        normalizedColor: normalizedColor,
        recurrence: recurrence,
        metadata: metadata,
        isCritical: isCritical,
        reminderMinutesBefore: reminderMinutesBefore,
        reminderRecurrence: reminderRecurrence,
        reminderSpecificDays: reminderSpecificDays,
        reminderInterval: reminderInterval,
        reminderIntervalUnit: reminderIntervalUnit,
        workspaceId: workspaceId,
        syncToWorkspaceIds: syncToWorkspaceIds,
        optimisticEvent: optimisticEvent,
        previousCalendar: previousCalendar,
        mutationVersion: mutationVersion,
      ),
    );
    return Future<void>.value();
  }

  Future<void> _createCalendarEventInBackground({
    required String title,
    required String wireStartsAt,
    required String? wireEndsAt,
    required String? notes,
    required String? location,
    required String? status,
    required String? category,
    required String? normalizedColor,
    required String? recurrence,
    required Map<String, Object?>? metadata,
    required bool? isCritical,
    required int? reminderMinutesBefore,
    required String? reminderRecurrence,
    required List<String>? reminderSpecificDays,
    required int? reminderInterval,
    required String? reminderIntervalUnit,
    required int? workspaceId,
    required List<Object> syncToWorkspaceIds,
    required HermesCalendarEvent optimisticEvent,
    required List<HermesCalendarEvent> previousCalendar,
    required int mutationVersion,
  }) async {
    try {
      final createdEvent = await widget.apiClient.createCalendarEvent(
        title: title,
        startsAt: wireStartsAt,
        endsAt: wireEndsAt,
        notes: notes,
        location: location,
        status: status,
        category: category,
        color: normalizedColor,
        recurrence: recurrence,
        metadata: metadata,
        isCritical: isCritical ?? false,
        workspaceId: workspaceId ?? _user?.activeWorkspace?.numericId,
        syncToWorkspaceIds: syncToWorkspaceIds,
      );
      if (reminderMinutesBefore != null && reminderMinutesBefore >= 0) {
        final start = _parseCalendarEventDateTime(wireStartsAt);
        if (start != null) {
          await widget.apiClient.createEventReminder(
            calendarEventId: createdEvent.id,
            title: 'Reminder: $title',
            remindAt: start
                .subtract(Duration(minutes: reminderMinutesBefore))
                .toUtc()
                .toIso8601String(),
            metadata: _eventReminderMetadata(
              minutesBefore: reminderMinutesBefore,
              recurrence: recurrence,
              eventMetadata: metadata,
            ),
          );
        }
      }
      if (!_canApplyBackgroundSave(mutationVersion) ||
          !_pendingCalendarEventWriteIsCurrent(
            optimisticEvent.id,
            optimisticEvent,
            mutationVersion,
          )) {
        return;
      }
      _forgetPendingCalendarEventWrite(optimisticEvent.id, clearVersion: true);
      _rememberPendingCalendarEventWrite(createdEvent, mutationVersion);
      setState(() {
        _calendar = _calendar
            .map(
              (candidate) =>
                  candidate.id == optimisticEvent.id ? createdEvent : candidate,
            )
            .toList(growable: false);
        _error = null;
      });
      _cacheCurrentDashboardSnapshot();
      unawaited(_refreshSignedInViews());
    } catch (error) {
      if (!_canApplyBackgroundSave(mutationVersion) ||
          !_pendingCalendarEventWriteIsCurrent(
            optimisticEvent.id,
            optimisticEvent,
            mutationVersion,
          )) {
        return;
      }
      _markDashboardDataMutated();
      _forgetPendingCalendarEventWrite(optimisticEvent.id);
      setState(() {
        _calendar = previousCalendar;
        _error = beanFriendlyErrorMessage(
          error,
          action: 'create that calendar event',
        );
      });
    }
  }

  Map<String, Object?> _eventReminderMetadata({
    required int minutesBefore,
    required String? recurrence,
    required Map<String, Object?>? eventMetadata,
  }) {
    final rawRecurrence = recurrence?.trim() ?? '';
    final normalizedRecurrence = rawRecurrence.isEmpty ? 'none' : rawRecurrence;
    final metadata = <String, Object?>{
      'minutes_before': minutesBefore,
      'recurrence': normalizedRecurrence,
    };
    final days = eventMetadata?['days'];
    if (normalizedRecurrence == 'specific_days' && days is List) {
      final sortedDays = days.whereType<String>().toList()..sort();
      if (sortedDays.isNotEmpty) metadata['days'] = sortedDays;
    }
    if (normalizedRecurrence == 'specific_days' ||
        normalizedRecurrence == 'interval') {
      final interval = eventMetadata?['interval'];
      if (interval is int && interval > 0) {
        metadata['interval'] = interval;
      } else if (interval is String) {
        final parsed = int.tryParse(interval);
        if (parsed != null && parsed > 0) metadata['interval'] = parsed;
      }
      final unit = eventMetadata?['unit'];
      if (unit is String && unit.trim().isNotEmpty) {
        metadata['unit'] = unit;
      }
    }
    return metadata;
  }

  Future<void> _editCalendarEvent(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool? isCritical,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
    int? workspaceId,
    List<Object> syncToWorkspaceIds = const [],
  }) {
    final wireStartsAt = _calendarEventWireValueToUtcIso(startsAt) ?? startsAt;
    final wireEndsAt = _calendarEventWireValueToUtcIso(endsAt);
    final normalizedColor = category == null ? _themeCategoryColorHex() : color;
    final previousCalendar = _calendar;
    final optimisticEvent = event.copyWith(
      title: title,
      startsAt: wireStartsAt,
      endsAt: wireEndsAt,
      notes: notes,
      location: location,
      status: status,
      category: category,
      color: normalizedColor,
      recurrence: recurrence,
      metadata: metadata,
      isCritical: isCritical ?? event.isCritical,
      clearEndsAt: wireEndsAt == null,
      clearNotes: notes == null,
      clearLocation: location == null,
      clearCategory: category == null,
      clearColor: false,
      clearRecurrence: recurrence == null,
    );
    _markDashboardDataMutated();
    final mutationVersion = _dashboardDataVersion;
    _rememberPendingCalendarEventWrite(optimisticEvent, mutationVersion);
    setState(() {
      _calendar = _calendar
          .map(
            (candidate) =>
                candidate.id == event.id ? optimisticEvent : candidate,
          )
          .toList();
      _error = null;
    });
    _cacheCurrentDashboardSnapshot();
    unawaited(
      _editCalendarEventInBackground(
        event,
        title: title,
        wireStartsAt: wireStartsAt,
        wireEndsAt: wireEndsAt,
        notes: notes,
        location: location,
        status: status,
        category: category,
        normalizedColor: normalizedColor,
        recurrence: recurrence,
        metadata: metadata,
        isCritical: isCritical,
        reminderMinutesBefore: reminderMinutesBefore,
        reminderRecurrence: reminderRecurrence,
        reminderSpecificDays: reminderSpecificDays,
        reminderInterval: reminderInterval,
        reminderIntervalUnit: reminderIntervalUnit,
        syncToWorkspaceIds: syncToWorkspaceIds,
        optimisticEvent: optimisticEvent,
        previousCalendar: previousCalendar,
        mutationVersion: mutationVersion,
      ),
    );
    return Future<void>.value();
  }

  Future<void> _editCalendarEventInBackground(
    HermesCalendarEvent event, {
    required String title,
    required String wireStartsAt,
    required String? wireEndsAt,
    required String? notes,
    required String? location,
    required String? status,
    required String? category,
    required String? normalizedColor,
    required String? recurrence,
    required Map<String, Object?>? metadata,
    required bool? isCritical,
    required int? reminderMinutesBefore,
    required String? reminderRecurrence,
    required List<String>? reminderSpecificDays,
    required int? reminderInterval,
    required String? reminderIntervalUnit,
    required List<Object> syncToWorkspaceIds,
    required HermesCalendarEvent optimisticEvent,
    required List<HermesCalendarEvent> previousCalendar,
    required int mutationVersion,
  }) async {
    try {
      final updatedEvent = await widget.apiClient.updateCalendarEvent(
        event.id,
        title: title,
        startsAt: wireStartsAt,
        endsAt: wireEndsAt,
        notes: notes,
        location: location,
        status: status,
        category: category,
        color: normalizedColor,
        recurrence: recurrence,
        metadata: metadata,
        isCritical: isCritical,
        clearNotes: notes == null,
        clearLocation: location == null,
        syncToWorkspaceIds: syncToWorkspaceIds,
      );
      if (reminderMinutesBefore != null && reminderMinutesBefore >= 0) {
        final start = _parseCalendarEventDateTime(wireStartsAt);
        if (start != null) {
          await widget.apiClient.createEventReminder(
            calendarEventId: event.id,
            title: 'Reminder: $title',
            remindAt: start
                .subtract(Duration(minutes: reminderMinutesBefore))
                .toUtc()
                .toIso8601String(),
            metadata: _eventReminderMetadata(
              minutesBefore: reminderMinutesBefore,
              recurrence: recurrence,
              eventMetadata: metadata,
            ),
          );
        }
      }
      if (!_canApplyBackgroundSave(mutationVersion) ||
          !_pendingCalendarEventWriteIsCurrent(
            event.id,
            optimisticEvent,
            mutationVersion,
          )) {
        return;
      }
      _rememberPendingCalendarEventWrite(updatedEvent, mutationVersion);
      setState(() {
        _calendar = _calendar
            .map(
              (candidate) =>
                  candidate.id == event.id ? updatedEvent : candidate,
            )
            .toList();
      });
      _cacheCurrentDashboardSnapshot();
      unawaited(_refreshSignedInViews());
    } catch (error) {
      if (!_canApplyBackgroundSave(mutationVersion) ||
          !_pendingCalendarEventWriteIsCurrent(
            event.id,
            optimisticEvent,
            mutationVersion,
          )) {
        return;
      }
      _markDashboardDataMutated();
      _forgetPendingCalendarEventWrite(event.id);
      setState(() {
        _calendar = previousCalendar;
        _error = beanFriendlyErrorMessage(
          error,
          action: 'update that calendar event',
        );
      });
    }
  }

  Future<void> _deleteCalendarEvent(
    HermesCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    final previousCalendar = _calendar;
    final recurringDeleteMode = event.metadata?['_delete_recurring_mode']
        ?.toString();
    final recurringOccurrenceDate = event.metadata?['_delete_occurrence_date']
        ?.toString();
    final isRecurringOccurrenceDelete =
        recurringDeleteMode != null &&
        recurringDeleteMode != 'all' &&
        recurringOccurrenceDate != null;
    final deleteWorkspaceIdSet = deleteFromWorkspaceIds
        .map((id) => id.toString())
        .toSet();
    _markDashboardDataMutated();
    final mutationVersion = _dashboardDataVersion;
    if (!isRecurringOccurrenceDelete) {
      _rememberPendingCalendarEventDelete(event.id, mutationVersion);
    }
    setState(() {
      if (isRecurringOccurrenceDelete) {
        _calendar = _calendar
            .map(
              (candidate) => candidate.id == event.id
                  ? candidate.copyWith(
                      metadata: _metadataAfterRecurringDelete(
                        candidate,
                        recurringDeleteMode,
                        recurringOccurrenceDate,
                      ),
                    )
                  : candidate,
            )
            .toList();
      } else {
        _calendar = _calendar
            .where(
              (candidate) =>
                  candidate.id != event.id &&
                  (candidate.workspaceId == null ||
                      !deleteWorkspaceIdSet.contains(
                        candidate.workspaceId.toString(),
                      )),
            )
            .toList();
      }
      _error = null;
    });
    _cacheCurrentDashboardSnapshot();
    unawaited(
      _deleteCalendarEventInBackground(
        event,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
        recurringDeleteMode: recurringDeleteMode,
        recurringOccurrenceDate: recurringOccurrenceDate,
        previousCalendar: previousCalendar,
        mutationVersion: mutationVersion,
      ),
    );
    return Future<void>.value();
  }

  Future<void> _deleteCalendarEventInBackground(
    HermesCalendarEvent event, {
    required List<Object> deleteFromWorkspaceIds,
    required String? recurringDeleteMode,
    required String? recurringOccurrenceDate,
    required List<HermesCalendarEvent> previousCalendar,
    required int mutationVersion,
  }) async {
    try {
      await widget.apiClient.deleteCalendarEvent(
        event.id,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
        recurringDeleteMode: recurringDeleteMode,
        recurringOccurrenceDate: recurringOccurrenceDate,
      );
      if (!_canApplyBackgroundSave(mutationVersion)) return;
      _forgetPendingCalendarEventWrite(event.id);
      _cacheCurrentDashboardSnapshot();
      unawaited(_refreshSignedInViews());
    } catch (error) {
      if (!_canApplyBackgroundSave(mutationVersion)) return;
      _markDashboardDataMutated();
      _forgetPendingCalendarEventWrite(event.id);
      setState(() {
        _calendar = previousCalendar;
        _error = beanFriendlyErrorMessage(
          error,
          action: 'delete that calendar event',
        );
      });
    }
  }

  Future<void> _reloadSignedInViewsFromSettings() async {
    if (_phase != _AuthPhase.signedIn) return;
    setState(() => _error = null);
    await _refreshWorkspaceDataFromServer(
      syncConnectedCalendar: true,
      errorAction: 'refresh your workspace',
    );
  }

  Future<void> _updateAccountEmail(String email) async {
    final trimmedEmail = email.trim();
    if (trimmedEmail.isEmpty || _busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final updatedUser = await widget.apiClient.updateMe(email: trimmedEmail);
      if (!mounted) return;
      _applyUserTheme(updatedUser);
      setState(() {
        _user = updatedUser;
        _busy = false;
        _error = null;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = beanFriendlyErrorMessage(error, action: 'update your email');
      });
    }
  }

  Future<void> _showNewCalendarEventEditor() async {
    final selected = _dateOnly(_selectedCalendarDay);
    final now = DateTime.now();
    final defaultStartHour = _sameCalendarDay(selected, _dateOnly(now))
        ? (now.hour + 1).clamp(_calendarStartHour, _calendarEndHour)
        : _calendarStartHour.clamp(0, 23);
    final start = DateTime(
      selected.year,
      selected.month,
      selected.day,
      defaultStartHour,
    );
    final end = start.add(const Duration(hours: 1));
    final draft = HermesCalendarEvent(
      id: 0,
      title: '',
      startsAt: start.toUtc().toIso8601String(),
      endsAt: end.toUtc().toIso8601String(),
    );
    await _showCalendarEventDetails(
      context,
      draft,
      eventCategories: _eventCategories,
      googleCalendarStatus: _googleCalendarStatus,
      workspaces: _user?.workspaces ?? const [],
      activeWorkspaceId: _user?.activeWorkspace?.id,
      onSave:
          (
            _, {
            required String title,
            required String startsAt,
            String? endsAt,
            String? notes,
            String? location,
            String? status,
            String? category,
            String? color,
            String? recurrence,
            Map<String, Object?>? metadata,
            bool? isCritical,
            int? reminderMinutesBefore,
            String? reminderRecurrence,
            List<String>? reminderSpecificDays,
            int? reminderInterval,
            String? reminderIntervalUnit,
            int? workspaceId,
            List<Object> syncToWorkspaceIds = const [],
          }) => _createCalendarEvent(
            title: title,
            startsAt: startsAt,
            endsAt: endsAt,
            notes: notes,
            location: location,
            status: status,
            category: category,
            color: color,
            recurrence: recurrence,
            metadata: metadata,
            isCritical: isCritical,
            reminderMinutesBefore: reminderMinutesBefore,
            reminderRecurrence: reminderRecurrence,
            reminderSpecificDays: reminderSpecificDays,
            reminderInterval: reminderInterval,
            reminderIntervalUnit: reminderIntervalUnit,
            workspaceId: workspaceId,
            syncToWorkspaceIds: syncToWorkspaceIds,
          ),
      onEventCategorySaved: _saveEventCategory,
      onEventCategoryDeleted: _deleteEventCategory,
      onDelete: _deleteCalendarEvent,
    );
  }

  Future<void> _updateNotificationPreferences(
    HermesNotificationPreferences preferences,
  ) async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      final updatedUser = await widget.apiClient.updateMe(
        notificationPreferences: preferences,
      );
      if (!mounted) return;
      _applyUserTheme(updatedUser);
      setState(() {
        _user = updatedUser;
        _busy = false;
        _error = null;
      });
      _syncReminderNotifications();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = beanFriendlyErrorMessage(
          error,
          action: 'update notification preferences',
        );
      });
    }
  }

  Future<void> _updateTheme(String themeKey) async {
    if (_busy) return;
    final normalizedThemeKey = heyBeanColorThemeForKey(themeKey).key;
    final previousUser = _user;
    setState(() {
      _busy = true;
      _error = null;
      if (previousUser != null) {
        _user = previousUser.copyWith(theme: normalizedThemeKey);
      }
    });
    _applyUserTheme(_user);
    try {
      final updatedUser = await widget.apiClient.updateMe(
        theme: normalizedThemeKey,
      );
      if (!mounted) return;
      _applyUserTheme(updatedUser);
      setState(() {
        _user = updatedUser;
        _busy = false;
        _error = null;
      });
    } catch (error) {
      if (!mounted) return;
      _applyUserTheme(previousUser);
      setState(() {
        _user = previousUser;
        _busy = false;
        _error = beanFriendlyErrorMessage(error, action: 'update your theme');
      });
    }
  }

  Future<void> _updateCommandCenterLabel(String label) async {
    if (_busy) return;
    final normalizedLabel = label.trim().isEmpty
        ? 'Command Center'
        : label.trim();
    final previousUser = _user;
    setState(() {
      _busy = true;
      _error = null;
      if (previousUser != null) {
        _user = previousUser.copyWith(commandCenterLabel: normalizedLabel);
      }
    });
    try {
      final updatedUser = await widget.apiClient.updateMe(
        commandCenterLabel: normalizedLabel,
      );
      if (!mounted) return;
      _applyUserTheme(updatedUser);
      setState(() {
        _user = updatedUser;
        _busy = false;
        _error = null;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _user = previousUser;
        _busy = false;
        _error = beanFriendlyErrorMessage(
          error,
          action: 'update your command center name',
        );
      });
    }
  }

  Future<void> _logout() async {
    if (_busy) return;
    final authGeneration = ++_authGeneration;
    _stopDashboardChangePolling();
    _workspaceRefreshGeneration++;
    _dashboardRefreshGeneration++;
    _dashboardDataVersion++;
    _applyUserTheme(null);
    setState(() {
      _busy = true;
      _phase = _AuthPhase.signedOut;
      _error = null;
      _authNotice = null;
      _clearSignedInState();
    });
    try {
      await widget.tokenStore.clearToken();
      await _pushNotifications.unregister(widget.apiClient);
      await widget.apiClient.logout(clearBearerToken: false);
    } catch (_) {
      // Local sign-out already completed; server/device cleanup can be retried
      // next time the user signs in.
    } finally {
      if (_isCurrentAuthGeneration(authGeneration)) {
        widget.apiClient.bearerToken = null;
        setState(() {
          _busy = false;
          _phase = _AuthPhase.signedOut;
          _error = null;
          _authNotice = null;
          _clearSignedInState();
        });
      }
    }
  }

  Future<void> _deleteAccount() async {
    if (_busy) return;
    _stopDashboardChangePolling();
    setState(() => _busy = true);
    try {
      await widget.apiClient.deleteAccount();
      await widget.tokenStore.clearToken();
      if (mounted) {
        setState(() {
          _busy = false;
          _phase = _AuthPhase.signedOut;
          _loadingStatusText = null;
          _clearSignedInState();
          _error = null;
        });
      }
    } catch (error) {
      _startDashboardChangePolling();
      if (mounted) {
        setState(() {
          _busy = false;
          _error =
              'Could not delete your account. Please try again or contact support.';
        });
      }
    }
  }

  String _workspaceDisplayName(HermesWorkspace workspace) =>
      workspace.isPersonal ? 'Personal' : workspace.name;

  Future<void> _switchWorkspaceFromTopBar(HermesWorkspace workspace) async {
    final workspaceId = workspace.numericId;
    if (workspaceId == null || _busy) return;
    if ((_user?.activeWorkspace?.id ?? '').toString() == workspace.id ||
        _user?.activeWorkspace?.numericId == workspaceId) {
      return;
    }
    final previousUser = _user;
    final previousWorkspaceId = _activeWorkspaceId();
    _cacheCurrentDashboardSnapshot();
    final previousSnapshot = previousWorkspaceId == null
        ? null
        : _workspaceSnapshots[previousWorkspaceId];
    final cachedSnapshot = _workspaceSnapshots[workspaceId];
    _markDashboardDataMutated();
    setState(() {
      if (_user != null) {
        _user = _userWithActiveWorkspace(_user!, workspace);
      }
      if (cachedSnapshot != null) {
        _restoreDashboardSnapshot(cachedSnapshot);
      } else {
        _clearDashboardData();
      }
      _dashboardDataLoading = cachedSnapshot == null;
      _busy = false;
      _error = null;
    });
    _startDashboardChangePolling(resetCursor: true);
    try {
      final selectedWorkspace = await widget.apiClient.setDefaultWorkspace(
        workspaceId,
      );
      if (!mounted) return;
      _markDashboardDataMutated();
      setState(() {
        if (_user != null) {
          _user = _userWithActiveWorkspace(_user!, selectedWorkspace);
        }
        _error = null;
      });
      unawaited(
        _refreshWorkspaceDataFromServer(
          syncConnectedCalendar: false,
          errorAction: 'refresh your workspace',
        ),
      );
    } catch (error) {
      if (!mounted) return;
      _markDashboardDataMutated();
      setState(() {
        _user = previousUser;
        if (previousSnapshot != null) {
          _restoreDashboardSnapshot(previousSnapshot);
        }
        _dashboardDataLoading = false;
        _error = beanFriendlyErrorMessage(error, action: 'switch workspaces');
      });
    }
  }

  List<Widget> _moreMenuWorkspaceTiles(BuildContext context) {
    final user = _user;
    final workspaces = user?.workspaces ?? const <HermesWorkspace>[];
    if (_phase != _AuthPhase.signedIn || workspaces.length < 2) {
      return const [];
    }
    final activeWorkspace =
        user?.activeWorkspace ??
        workspaces.firstWhere(
          (workspace) => workspace.active || workspace.isDefault,
          orElse: () => workspaces.first,
        );

    return [
      const Padding(
        padding: EdgeInsets.fromLTRB(16, 8, 16, 6),
        child: Align(
          alignment: Alignment.centerLeft,
          child: Text(
            'Workspace',
            style: TextStyle(
              color: HeyBeanTheme.muted,
              fontSize: 12,
              fontWeight: FontWeight.w900,
              letterSpacing: 0,
            ),
          ),
        ),
      ),
      for (final workspace in workspaces)
        ListTile(
          key: Key('more-workspace-option-${workspace.id}'),
          enabled: !_busy && workspace.numericId != null,
          leading: Icon(
            workspace.id == activeWorkspace.id
                ? Icons.check_circle_rounded
                : Icons.grid_view_rounded,
            color: workspace.id == activeWorkspace.id
                ? HeyBeanTheme.accentStrong
                : HeyBeanTheme.muted,
          ),
          title: Text(
            _workspaceDisplayName(workspace),
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontWeight: FontWeight.w800),
          ),
          onTap: _busy || workspace.numericId == null
              ? null
              : () {
                  Navigator.pop(context);
                  unawaited(_switchWorkspaceFromTopBar(workspace));
                },
        ),
      const Padding(
        padding: EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        child: Divider(height: 1),
      ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final criticalItemCount = _criticalItemCountForToday();
    final showBeanIntroSpotlight = _showBeanIntroSpotlight;
    final beanResponsePreview = _beanCollapsedResponsePreview;
    _syncBeanResponsePreviewTimer(beanResponsePreview);
    _scheduleAppIconBadgeSync(criticalItemCount);
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: HeyBeanTheme.lightSystemOverlayStyle,
      child: Container(
        key: const Key('heybean-background-gradient'),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            stops: [0, .5, 1],
            colors: [HeyBeanTheme.bg0, HeyBeanTheme.bg1, HeyBeanTheme.bg2],
          ),
        ),
        child: Stack(
          children: [
            Positioned.fill(
              key: Key('green-glow-left'),
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: RadialGradient(
                    center: Alignment(-1.12, -1.2),
                    radius: 1.1,
                    colors: [
                      HeyBeanTheme.accent.withValues(alpha: .10),
                      Colors.transparent,
                    ],
                  ),
                ),
              ),
            ),
            Scaffold(
              appBar: AppBar(
                titleSpacing: 12,
                title: _phase == _AuthPhase.signedIn
                    ? Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          _CalendarHeaderButton(
                            key: const Key('calendar-today-button'),
                            label: _calendarHeaderDayLabel(DateTime.now()),
                            icon: null,
                            horizontalPadding: 10,
                            verticalPadding: 7,
                            labelStyle: const TextStyle(
                              fontSize: 13,
                              fontWeight: FontWeight.w800,
                            ),
                            onTap: _returnToToday,
                          ),
                          const SizedBox(width: 8),
                          Flexible(
                            child: _CalendarHeaderButton(
                              key: const Key('calendar-month-chevron'),
                              label: _calendarHeaderMonthLabel(DateTime.now()),
                              icon: Icons.calendar_month_rounded,
                              horizontalPadding: 10,
                              verticalPadding: 7,
                              labelStyle: const TextStyle(
                                fontSize: 13,
                                fontWeight: FontWeight.w800,
                              ),
                              onTap: _openCurrentCalendarMonth,
                            ),
                          ),
                        ],
                      )
                    : null,
                actions: [
                  if (_phase == _AuthPhase.signedIn) ...[
                    _CriticalTaskBadge(
                      tasks: _criticalTasksForToday(_tasks),
                      reminders: _criticalRemindersForToday(_reminders),
                      events: _criticalEventsForToday(_calendar),
                    ),
                    const SizedBox(width: 8),
                    _CreateItemMenu(
                      onCreateEvent: _showNewCalendarEventEditor,
                      onCreateTask: _showNewTaskEditor,
                      onCreateReminder: _showNewReminderEditor,
                      onCreateNote: _createNoteFromTopMenu,
                    ),
                  ],
                  const SizedBox(width: 16),
                ],
              ),
              body: SafeArea(child: _bodyWithBetaBanner()),
              bottomNavigationBar: _phase == _AuthPhase.signedIn
                  ? _SignedInBottomDock(
                      showComposer:
                          _selectedDestination == _HomeDestination.bean,
                      composer: _DockedBeanChatComposer(
                        controller: _chatInputController,
                        focusNode: _chatInputFocusNode,
                        busy: _beanStopAvailable,
                        listening: _beanVoiceListening,
                        voiceDraft: _beanVoiceDraft,
                        onChanged: _updateBeanVoiceDraft,
                        onSend: () => unawaited(_sendChatInputDraft()),
                        onStop: _stopAgent,
                      ),
                      menu: _HeyBeanBottomMenu(
                        selected: _selectedDestination,
                        beanListening: _beanVoiceListening,
                        beanWorkItems: _beanVisibleWorkItems,
                        beanWorkStatus: _beanStatusTagLabel,
                        beanWorkActive: _beanStatusTagVisible,
                        statusLift:
                            _selectedDestination == _HomeDestination.bean
                            ? _beanChatComposerReservedHeight
                            : 0,
                        onSelected: _selectDestination,
                        onMorePressed: _openMoreMenu,
                        onBeanLongPressStart: () =>
                            unawaited(_startBeanVoiceDraft()),
                        onBeanLongPressEnd: () =>
                            unawaited(_finishBeanVoiceDraft()),
                      ),
                    )
                  : null,
            ),
            if (beanResponsePreview != null)
              Positioned(
                left: 16,
                right: 16,
                bottom:
                    86 +
                    (MediaQuery.paddingOf(context).bottom > 0
                        ? MediaQuery.paddingOf(context).bottom + 2
                        : 6) +
                    (_selectedDestination == _HomeDestination.bean
                        ? _beanChatComposerReservedHeight
                        : 0),
                child: Center(
                  child: _BeanResponsePreviewTag(
                    key: const Key('bean-collapsed-response-tag'),
                    text: beanResponsePreview.text,
                    onHoldStart: _holdBeanResponsePreview,
                    onHoldEnd: _releaseBeanResponsePreview,
                    onDismissed: _dismissBeanResponsePreview,
                  ),
                ),
              ),
            if (showBeanIntroSpotlight)
              _BeanIntroSpotlightOverlay(
                onBeanTap: () => _selectDestination(_HomeDestination.bean),
              ),
            if (_onboardingTourVisible)
              _OnboardingTourOverlay(
                step: _onboardingTourStep,
                onNext: _advanceOnboardingTour,
                onSkip: _dismissOnboardingTour,
                onFinish: _dismissOnboardingTour,
              ),
          ],
        ),
      ),
    );
  }

  Widget _body() {
    if (_phase == _AuthPhase.loading) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const CircularProgressIndicator(),
            if (_loadingStatusText != null) ...[
              const SizedBox(height: 12),
              Text(
                _loadingStatusText!,
                key: const Key('full-screen-loading-message'),
                style: const TextStyle(
                  color: HeyBeanTheme.muted,
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ],
        ),
      );
    }
    if (_phase == _AuthPhase.signedOut) {
      return _SignedOutScreen(
        onLogin: _login,
        onRegister: _register,
        onForgotPassword: _requestPasswordReset,
        tokenStore: widget.tokenStore,
        launchExternalUrl: widget.launchExternalUrl,
        busy: _busy,
        error: _error,
        notice: _authNotice,
      );
    }
    if (_phase == _AuthPhase.planSelection) {
      return _SignupPaywallScreen(
        user: _user!,
        busyPlan: _checkoutBusyPlan,
        error: _checkoutError,
        onSelectPlan: _startTrialCheckout,
        onContactEnterprise: () {
          widget.launchExternalUrl(_enterpriseContactUrl);
        },
        onContinue: _continueAfterCheckout,
        onSignOut: _logout,
      );
    }
    final user = _user!;
    _scheduleApprovalSheet();
    final showAgentOnboarding = _showAgentOnboardingOverlay;
    final editingAgentPreferences = _editingAgentPreferences;
    final dueReminder = _dueReminderBanner();
    final signedInContent = _signedInContent(user);
    final beanScreenSelected = _selectedDestination == _HomeDestination.bean;
    final usesFullHeightSurface =
        beanScreenSelected || _selectedDestination == _HomeDestination.notes;
    final signedInSurface = usesFullHeightSurface
        ? Padding(
            padding: EdgeInsets.fromLTRB(
              20,
              8,
              20,
              beanScreenSelected ? 8 : 12,
            ),
            child: signedInContent,
          )
        : RefreshIndicator(
            key: const Key('signed-in-refresh-indicator'),
            onRefresh: _refreshSignedInViews,
            child: SingleChildScrollView(
              key: const Key('signed-in-refresh-scroll'),
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 112),
              child: signedInContent,
            ),
          );
    return Stack(
      children: [
        signedInSurface,
        if (dueReminder != null)
          Positioned(
            key: const Key('due-reminder-banner'),
            left: 20,
            right: 20,
            top: 8,
            child: _DueReminderBanner(
              reminder: dueReminder,
              onDismiss: () => setState(
                () => _dismissedReminderBannerIds.add(dueReminder.id),
              ),
              onComplete: () async {
                _dismissedReminderBannerIds.add(dueReminder.id);
                await _toggleReminderCompletion(dueReminder);
              },
            ),
          ),
        if (showAgentOnboarding)
          _AgentOnboardingOverlay(
            key: const Key('agent-onboarding-overlay'),
            initialPersonality:
                user.currentAgentProfile?.personalityType ?? 'balanced',
            initialPriorities:
                user.currentAgentProfile?.onboardingPriorities ?? const [],
            initialContext: user.currentAgentProfile?.onboardingContext ?? '',
            busy: _busy,
            editMode: editingAgentPreferences,
            onCancel: editingAgentPreferences
                ? () => setState(() => _editingAgentPreferences = false)
                : null,
            onComplete: _completeAgentOnboarding,
          ),
      ],
    );
  }

  Widget _bodyWithBetaBanner() {
    final body = _body();
    if (_phase != _AuthPhase.signedIn || _user?.isBeta != true) return body;
    return Column(
      children: [
        _BetaFeedbackBanner(onTap: () => unawaited(_openBetaFeedbackForm())),
        Expanded(child: body),
      ],
    );
  }

  Future<void> _openBetaFeedbackForm() async {
    final submitted = await showDialog<bool>(
      context: context,
      builder: (context) => _BetaFeedbackDialog(
        onSubmit: (message) => widget.apiClient.submitIssueReport(
          message: message,
          workspaceId: _user?.activeWorkspace?.numericId,
          pageUrl: 'heybean://flutter/${_selectedDestination.name}',
        ),
      ),
    );
    if (!mounted || submitted != true) return;
    await showDialog<void>(
      context: context,
      builder: (context) => const _BetaFeedbackThanksDialog(),
    );
  }

  Widget _signedInContent(HermesUser user) => _CommandCenterContent(
    apiClient: widget.apiClient,
    user: user,
    tasks: _tasks,
    pastTasks: _pastTasks,
    reminders: _reminders,
    calendar: _calendar,
    noteFolders: _noteFolders,
    notes: _notes,
    noteToOpenId: _noteToOpenId,
    memoryItems: _memoryItems,
    memorySummaries: _memorySummaries,
    memoryHistory: _memoryHistory,
    eventCategories: _eventCategories,
    googleCalendarStatus: _googleCalendarStatus,
    events: _events,
    messages: _messages,
    busy: _busy,
    dashboardDataLoading: _dashboardDataLoading,
    chatRunState: _chatRunState,
    chatInputController: _chatInputController,
    chatInputFocusNode: _chatInputFocusNode,
    onChatMessageCopied: _copyChatMessage,
    onChatMessageEdited: _beginEditingChatMessage,
    beanChatCollapsed: _beanChatCollapsed,
    onBeanChatCollapsedChanged: (collapsed) =>
        setState(() => _beanChatCollapsed = collapsed),
    error: _error,
    selectedDestination: _selectedDestination,
    selectedCalendarDay: _selectedCalendarDay,
    showCalendarMonth: _showCalendarMonth,
    calendarStartHour: _calendarStartHour,
    calendarEndHour: _calendarEndHour,
    onCalendarDaySelected: _selectCalendarDay,
    onCalendarMonthSelected: _selectCalendarMonth,
    calendarMinimumDay: _calendarHistoryCutoffDay,
    onCalendarHistoryLimitReached: () {
      setState(() => _error = _calendarHistoryLimitMessage());
    },
    onBackToCalendarDay: _returnToCalendarDay,
    onCalendarStartHourChanged: _setCalendarStartHour,
    onCalendarEndHourChanged: _setCalendarEndHour,
    onSelectDestination: _selectDestination,
    onTaskCompleted: _toggleTaskCompletion,
    pendingTaskIds: const <int>{},
    onTaskSaved: _createOrUpdateTask,
    onTaskDeleted: _deleteTask,
    onReminderSaved: _createOrUpdateReminder,
    onReminderCompleted: _toggleReminderCompletion,
    onReminderDeleted: _deleteReminder,
    onCalendarEventCreated: _createCalendarEvent,
    onCalendarEventEdited: _editCalendarEvent,
    onCalendarEventDeleted: _deleteCalendarEvent,
    onNoteFolderCreated: _createNoteFolder,
    onNoteFolderDeleted: _deleteNoteFolder,
    onNoteSaved: _saveNote,
    onNoteDeleted: _deleteNote,
    onMemoryRefresh: _refreshMemory,
    onMemoryCreated: _createMemoryItem,
    onMemoryUpdated: _updateMemoryItem,
    onMemoryDeleted: _deleteMemoryItem,
    onEventCategorySaved: _saveEventCategory,
    onEventCategoryDeleted: _deleteEventCategory,
    onDeleteAccount: _deleteAccount,
    onSignOut: _logout,
    onAccountEmailChanged: _updateAccountEmail,
    onNotificationPreferencesChanged: _updateNotificationPreferences,
    onThemeChanged: _updateTheme,
    onCommandCenterLabelChanged: _updateCommandCenterLabel,
    launchExternalUrl: widget.launchExternalUrl,
    stripePaymentHandler: widget.stripePaymentHandler,
    onBillingChanged: () =>
        _loadSignedIn(loadingStatusText: 'Refreshing your subscription...'),
    onEditAgentOnboarding: () {
      setState(() {
        _editingAgentPreferences = true;
        _forceAgentOnboarding = false;
      });
    },
    onWorkspacesChanged: _reloadSignedInViewsFromSettings,
  );

  void _openMoreMenu() {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 18),
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                ..._moreMenuWorkspaceTiles(context),
                ListTile(
                  leading: const Icon(Icons.psychology_alt_rounded),
                  title: const Text("Bean's Knowledge"),
                  onTap: () {
                    Navigator.pop(context);
                    _selectDestination(_HomeDestination.memory);
                  },
                ),
                ListTile(
                  leading: const Icon(Icons.settings_rounded),
                  title: const Text('Settings'),
                  onTap: () {
                    Navigator.pop(context);
                    _selectDestination(_HomeDestination.settings);
                  },
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

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
    icon: const Icon(Icons.add_rounded),
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
      Text(label, style: const TextStyle(fontWeight: FontWeight.w700)),
    ],
  );
}

typedef _RegisterHandler =
    Future<void> Function(String name, String email, String password);
typedef _ForgotPasswordHandler = Future<void> Function(String email);

const List<_SignupPlanOption> _signupPlanOptions = [
  _SignupPlanOption(
    key: 'base',
    label: 'Base',
    price: r'$4.99',
    priceSuffix: '/mo',
    description: 'For getting your personal day into one organized place.',
    trialText: '7-day free trial, then billed monthly',
    actionLabel: 'Start Base trial',
    finePrint: 'A simple place to begin with Bean.',
    features: [
      '2 workspaces for personal and shared planning',
      'Tasks, reminders, and calendar in one daily view',
      'Bean chat and voice for everyday requests',
      '1 connected calendar',
      'Push reminders for the things you cannot miss',
      'Recent history so Bean can follow the thread of your day',
      'A calm entry point for keeping daily logistics together',
    ],
  ),
  _SignupPlanOption(
    key: 'premium',
    label: 'Premium',
    price: r'$19.99',
    priceSuffix: '/mo',
    description:
        'For families and power users who want Bean woven into the daily routine.',
    trialText: '7-day free trial, then billed on day 8',
    actionLabel: 'Start Premium trial',
    finePrint: 'Cancel before day 8 to avoid being billed.',
    features: [
      '5 workspaces for home, work, school, and projects',
      'Expanded Bean capacity for everyday planning',
      'Push and email reminders working together',
      'Recurring tasks and reminders for repeating routines',
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
    priceSuffix: '/mo',
    description:
        'For people who want Bean to run across every workspace, account, and recurring workflow.',
    trialText: '7-day free trial, then billed on day 8',
    actionLabel: 'Start Pro trial',
    finePrint:
        'Built for users who want Bean available across the whole operating system of their day.',
    features: [
      'Unlimited workspaces for every area of life',
      'Maximum Bean capacity for high-volume days',
      'More room for connected tools and background work',
      'Unlimited connected accounts',
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
    this.priceSuffix,
    this.popular = false,
    this.startsCheckout = true,
  });

  final String key;
  final String label;
  final String price;
  final String description;
  final String trialText;
  final String actionLabel;
  final String finePrint;
  final List<String> features;
  final String? priceSuffix;
  final bool popular;
  final bool startsCheckout;
}

class _SignupPaywallScreen extends StatelessWidget {
  const _SignupPaywallScreen({
    required this.user,
    required this.busyPlan,
    required this.error,
    required this.onSelectPlan,
    required this.onContactEnterprise,
    required this.onContinue,
    required this.onSignOut,
  });

  final HermesUser user;
  final String? busyPlan;
  final String? error;
  final Future<void> Function(String plan) onSelectPlan;
  final VoidCallback onContactEnterprise;
  final Future<void> Function() onContinue;
  final Future<void> Function() onSignOut;

  bool get _busy => busyPlan != null;

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
                'Account created for ${user.name}.',
                style: const TextStyle(
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
                        width: 42,
                        height: 42,
                        decoration: BoxDecoration(
                          color: HeyBeanTheme.accent.withValues(alpha: .14),
                          borderRadius: BorderRadius.circular(14),
                        ),
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
                  const Text(
                    'Start your free 7-day trial today! Pick the plan that best fits your needs.',
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
            if (error != null) ...[
              _InlinePlanLimitError(message: error!),
              const SizedBox(height: 12),
            ],
            for (final plan in _signupPlanOptions) ...[
              _SignupPlanCard(
                plan: plan,
                busy: busyPlan == plan.key,
                disabled: _busy && busyPlan != plan.key,
                onPressed: plan.startsCheckout
                    ? () => onSelectPlan(plan.key)
                    : onContactEnterprise,
              ),
              const SizedBox(height: 12),
            ],
            _ShellCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Text(
                    'Already subscribed?',
                    style: TextStyle(
                      color: HeyBeanTheme.text,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  const SizedBox(height: 4),
                  const Text(
                    'If your account was updated on another device, refresh here and Bean will check your latest subscription status.',
                    style: TextStyle(
                      color: HeyBeanTheme.muted,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    key: const Key('signup-paywall-refresh-action'),
                    onPressed: _busy ? null : onContinue,
                    icon: const Icon(Icons.refresh_rounded),
                    label: const Text('Refresh subscription status'),
                  ),
                  TextButton(
                    key: const Key('signup-paywall-sign-out-action'),
                    onPressed: _busy ? null : onSignOut,
                    child: const Text('Use a different account'),
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
    required this.busy,
    required this.disabled,
    required this.onPressed,
  });

  final _SignupPlanOption plan;
  final bool busy;
  final bool disabled;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final prominent = plan.popular;
    final foreground = prominent ? Colors.white : HeyBeanTheme.text;
    final muted = prominent
        ? Colors.white.withValues(alpha: .76)
        : HeyBeanTheme.muted;
    return Container(
      key: Key('signup-plan-${plan.key}'),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: prominent
            ? HeyBeanTheme.text
            : Colors.white.withValues(alpha: .84),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(
          color: prominent
              ? HeyBeanTheme.accent.withValues(alpha: .4)
              : HeyBeanTheme.border,
        ),
        boxShadow: prominent
            ? [
                BoxShadow(
                  color: HeyBeanTheme.text.withValues(alpha: .18),
                  blurRadius: 28,
                  offset: const Offset(0, 14),
                ),
              ]
            : null,
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
                              borderRadius: BorderRadius.circular(999),
                            ),
                            child: const Text(
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
                    plan.price,
                    style: TextStyle(
                      color: foreground,
                      fontSize: 24,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  if (plan.priceSuffix != null)
                    Text(
                      plan.priceSuffix!,
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
            plan.trialText,
            style: TextStyle(
              color: prominent
                  ? const Color(0xFFBBF7D0)
                  : HeyBeanTheme.accentStrong,
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
                    color: prominent
                        ? const Color(0xFFBBF7D0)
                        : HeyBeanTheme.accentStrong,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      feature,
                      style: TextStyle(
                        color: prominent
                            ? Colors.white.withValues(alpha: .9)
                            : HeyBeanTheme.text,
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

class _AgentPersonalityOption {
  const _AgentPersonalityOption({
    required this.key,
    required this.label,
    required this.description,
    required this.infoTitle,
    required this.infoDetails,
    required this.icon,
  });

  final String key;
  final String label;
  final String description;
  final String infoTitle;
  final List<String> infoDetails;
  final IconData icon;
}

const List<_AgentPersonalityOption> _agentPersonalityOptions = [
  _AgentPersonalityOption(
    key: 'balanced',
    label: 'Balanced',
    description: 'Calm, practical, and concise.',
    infoTitle: 'A steady everyday helper',
    infoDetails: [
      'Keeps answers simple and low-drama.',
      'Gives clear confirmations and one helpful suggestion when it makes sense.',
      'Best when you want Bean to be useful without feeling too chatty.',
    ],
    icon: Icons.tune_rounded,
  ),
  _AgentPersonalityOption(
    key: 'coach',
    label: 'Coach',
    description: 'Encouraging with gentle accountability.',
    infoTitle: 'A motivating helper for momentum',
    infoDetails: [
      'Celebrates small wins and helps you move forward.',
      'Suggests the next small step when things feel overloaded.',
      'Best when you want gentle nudges without guilt or pressure.',
    ],
    icon: Icons.emoji_events_rounded,
  ),
  _AgentPersonalityOption(
    key: 'organizer',
    label: 'Organizer',
    description: 'Structured, precise, schedule-first.',
    infoTitle: 'A detail-focused planner',
    infoDetails: [
      'Keeps summaries tidy and schedule-aware.',
      'Asks for missing dates, times, categories, calendars, or reminders.',
      'Best when you want Bean to help keep the day clean and organized.',
    ],
    icon: Icons.view_agenda_rounded,
  ),
  _AgentPersonalityOption(
    key: 'creative',
    label: 'Creative',
    description: 'Idea-forward while staying useful.',
    infoTitle: 'A warm brainstorming partner',
    infoDetails: [
      'Helps with ideas, names, themes, checklists, and plans.',
      'Turns brainstorms into real tasks, reminders, and calendar events.',
      'Best when you want planning to feel a little more fun and imaginative.',
    ],
    icon: Icons.auto_awesome_rounded,
  ),
  _AgentPersonalityOption(
    key: 'direct',
    label: 'Direct',
    description: 'Crisp, decisive, and action-first.',
    infoTitle: 'A concise operator',
    infoDetails: [
      'Leads with the answer or completed action.',
      'Asks only the minimum follow-up needed to move work forward.',
      'Best when you want Bean to be brief and efficient.',
    ],
    icon: Icons.bolt_rounded,
  ),
  _AgentPersonalityOption(
    key: 'gentle',
    label: 'Gentle',
    description: 'Patient, reassuring, and low-pressure.',
    infoTitle: 'A calm companion',
    infoDetails: [
      'Keeps the tone soft and settled.',
      'Breaks busy days into manageable next steps.',
      'Best when you want Bean to help without adding urgency.',
    ],
    icon: Icons.spa_rounded,
  ),
];

const List<String> _onboardingPriorityOptions = [
  'Work',
  'Family',
  'Health',
  'Planning',
  'Reminders',
  'Focus',
];

class _AgentOnboardingOverlay extends StatefulWidget {
  const _AgentOnboardingOverlay({
    super.key,
    required this.initialPersonality,
    required this.initialPriorities,
    required this.initialContext,
    required this.busy,
    this.editMode = false,
    this.onCancel,
    required this.onComplete,
  });

  final String initialPersonality;
  final List<String> initialPriorities;
  final String initialContext;
  final bool busy;
  final bool editMode;
  final VoidCallback? onCancel;
  final Future<void> Function({
    required String agentPersonality,
    required List<String> onboardingPriorities,
    String? onboardingContext,
  })
  onComplete;

  @override
  State<_AgentOnboardingOverlay> createState() =>
      _AgentOnboardingOverlayState();
}

class _AgentOnboardingOverlayState extends State<_AgentOnboardingOverlay> {
  late String _selectedPersonality;
  late Set<String> _selectedPriorities;
  late TextEditingController _context;
  int _step = 0;

  @override
  void initState() {
    super.initState();
    _selectedPersonality = widget.initialPersonality;
    _selectedPriorities = widget.initialPriorities.isEmpty
        ? {'Planning', 'Reminders'}
        : widget.initialPriorities.toSet();
    _context = TextEditingController(text: widget.initialContext);
  }

  @override
  void dispose() {
    _context.dispose();
    super.dispose();
  }

  void _togglePriority(String priority) {
    setState(() {
      if (_selectedPriorities.contains(priority)) {
        _selectedPriorities.remove(priority);
      } else {
        _selectedPriorities.add(priority);
      }
    });
  }

  Future<void> _save() async {
    await widget.onComplete(
      agentPersonality: _selectedPersonality,
      onboardingPriorities: _selectedPriorities.toList(),
      onboardingContext: _context.text.trim().isEmpty
          ? null
          : _context.text.trim(),
    );
  }

  Future<void> _next() async {
    if (widget.editMode) {
      await _save();
      return;
    }
    if (_step < 3) {
      setState(() => _step += 1);
      return;
    }
    await _save();
  }

  @override
  Widget build(BuildContext context) {
    return Positioned.fill(
      child: ColoredBox(
        color: Colors.black.withValues(alpha: .45),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 440),
                child: _ShellCard(
                  child: AnimatedSwitcher(
                    duration: const Duration(milliseconds: 180),
                    child: Column(
                      key: ValueKey('agent-onboarding-step-$_step'),
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        _SectionTitle(
                          icon: widget.editMode
                              ? Icons.tune_rounded
                              : _step == 3
                              ? Icons.check_circle_rounded
                              : Icons.auto_awesome_rounded,
                          title: widget.editMode
                              ? 'Edit Bean preferences'
                              : _step == 3
                              ? 'You’re all set'
                              : 'Let’s personalize Bean',
                          subtitle: widget.editMode
                              ? 'Review the current settings and save only what you want to change.'
                              : _step == 3
                              ? 'You can update these settings any time in the Bean preferences section of Settings.'
                              : 'A few quick choices help Bean understand your style and priorities.',
                        ),
                        const SizedBox(height: 18),
                        if (widget.editMode) ...[
                          _personalityStep(),
                          const SizedBox(height: 18),
                          _prioritiesStep(),
                          const SizedBox(height: 18),
                          _contextStep(),
                        ] else ...[
                          if (_step == 0) _personalityStep(),
                          if (_step == 1) _prioritiesStep(),
                          if (_step == 2) _contextStep(),
                          if (_step == 3)
                            const Text(
                              'Bean will use your personality, priorities, and context to shape tone, planning, reminders, and follow-up. Look for Bean preferences in Settings whenever you want to change them.',
                              style: TextStyle(color: HeyBeanTheme.muted),
                            ),
                        ],
                        const SizedBox(height: 18),
                        if (widget.editMode)
                          Row(
                            children: [
                              Expanded(
                                child: OutlinedButton(
                                  key: const Key('agent-preferences-cancel'),
                                  onPressed: widget.busy
                                      ? null
                                      : widget.onCancel,
                                  child: const Text('Cancel'),
                                ),
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                child: FilledButton(
                                  key: const Key('agent-preferences-save'),
                                  onPressed: widget.busy ? null : _save,
                                  child: Text(widget.busy ? 'Saving…' : 'Save'),
                                ),
                              ),
                            ],
                          )
                        else
                          FilledButton(
                            key: Key(
                              _step == 3
                                  ? 'agent-onboarding-finish'
                                  : 'agent-onboarding-next',
                            ),
                            onPressed: widget.busy ? null : _next,
                            child: Text(
                              widget.busy
                                  ? 'Saving…'
                                  : _step == 3
                                  ? 'Finish'
                                  : 'Next',
                            ),
                          ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  void _showPersonalityInfo() {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) => SafeArea(
        child: ConstrainedBox(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.sizeOf(context).height * .86,
          ),
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Container(
                      width: 28,
                      height: 28,
                      decoration: BoxDecoration(
                        color: HeyBeanTheme.accent.withValues(alpha: .12),
                        shape: BoxShape.circle,
                      ),
                      child: Icon(
                        Icons.info_outline_rounded,
                        size: 18,
                        color: HeyBeanTheme.accent,
                      ),
                    ),
                    const SizedBox(width: 10),
                    const Expanded(
                      child: Text(
                        'Bean personality options',
                        style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                const Text(
                  'Choose the style that best matches how you want Bean to help. You can change this any time in Settings.',
                  style: TextStyle(color: HeyBeanTheme.muted),
                ),
                const SizedBox(height: 18),
                for (final option in _agentPersonalityOptions) ...[
                  _PersonalityInfoRow(option: option),
                  if (option != _agentPersonalityOptions.last)
                    const SizedBox(height: 14),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _personalityStep() => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Row(
        children: [
          const Expanded(
            child: Text(
              'Choose Bean’s personality',
              style: TextStyle(fontWeight: FontWeight.w800),
            ),
          ),
          IconButton(
            key: const Key('agent-personality-info'),
            tooltip: 'More info about Bean personalities',
            visualDensity: VisualDensity.compact,
            padding: EdgeInsets.zero,
            constraints: const BoxConstraints.tightFor(width: 32, height: 32),
            icon: const Icon(Icons.info_outline_rounded, size: 20),
            color: HeyBeanTheme.accent,
            onPressed: _showPersonalityInfo,
          ),
        ],
      ),
      const SizedBox(height: 10),
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: _agentPersonalityOptions.map((option) {
          final selected = option.key == _selectedPersonality;
          return ChoiceChip(
            key: Key('agent-personality-${option.key}'),
            selected: selected,
            avatar: Icon(
              option.icon,
              size: 18,
              color: selected ? Colors.white : HeyBeanTheme.accent,
            ),
            label: Text(option.label),
            onSelected: widget.busy
                ? null
                : (_) => setState(() => _selectedPersonality = option.key),
          );
        }).toList(),
      ),
      const SizedBox(height: 8),
      Text(
        _agentPersonalityOptions
            .firstWhere((option) => option.key == _selectedPersonality)
            .description,
        style: const TextStyle(color: HeyBeanTheme.muted),
      ),
    ],
  );

  Widget _prioritiesStep() => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      const Text(
        'What should Bean prioritize?',
        style: TextStyle(fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 10),
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: _onboardingPriorityOptions.map((priority) {
          final selected = _selectedPriorities.contains(priority);
          return FilterChip(
            key: Key('onboarding-priority-$priority'),
            selected: selected,
            label: Text(priority),
            onSelected: widget.busy ? null : (_) => _togglePriority(priority),
          );
        }).toList(),
      ),
    ],
  );

  Widget _contextStep() => TextField(
    key: const Key('onboarding-context'),
    controller: _context,
    minLines: 3,
    maxLines: 4,
    textInputAction: TextInputAction.newline,
    decoration: _longFormInputDecoration(
      labelText: 'Anything Bean should know?',
      hintText:
          'Example: I work nights, protect family time, and need gentle nudges.',
    ),
  );
}

class _PersonalityInfoRow extends StatelessWidget {
  const _PersonalityInfoRow({required this.option});

  final _AgentPersonalityOption option;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: HeyBeanTheme.surface2,
      borderRadius: BorderRadius.circular(18),
      border: Border.all(color: HeyBeanTheme.border),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(option.icon, size: 18, color: HeyBeanTheme.accent),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                option.label,
                style: const TextStyle(fontWeight: FontWeight.w800),
              ),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          option.infoTitle,
          style: const TextStyle(
            color: HeyBeanTheme.muted,
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 8),
        for (final detail in option.infoDetails)
          Padding(
            padding: const EdgeInsets.only(bottom: 5),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('• ', style: TextStyle(color: HeyBeanTheme.muted)),
                Expanded(
                  child: Text(
                    detail,
                    style: const TextStyle(color: HeyBeanTheme.muted),
                  ),
                ),
              ],
            ),
          ),
      ],
    ),
  );
}

class _SignedOutScreen extends StatefulWidget {
  const _SignedOutScreen({
    required this.onLogin,
    required this.onRegister,
    required this.onForgotPassword,
    required this.tokenStore,
    required this.launchExternalUrl,
    required this.busy,
    this.error,
    this.notice,
  });

  final Future<void> Function(
    String email,
    String password, {
    required bool rememberMe,
  })
  onLogin;
  final _RegisterHandler onRegister;
  final _ForgotPasswordHandler onForgotPassword;
  final AuthTokenStore tokenStore;
  final ExternalUrlLauncher launchExternalUrl;
  final bool busy;
  final String? error;
  final String? notice;

  @override
  State<_SignedOutScreen> createState() => _SignedOutScreenState();
}

class _SignedOutScreenState extends State<_SignedOutScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _registerMode = false;
  bool _rememberMe = false;

  @override
  void initState() {
    super.initState();
    widget.tokenStore.loadRememberMe().then((rememberMe) {
      if (mounted) setState(() => _rememberMe = rememberMe);
    });
  }

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _showForgotPasswordDialog() async {
    await showDialog<void>(
      context: context,
      builder: (context) => _ForgotPasswordDialog(
        initialEmail: _email.text,
        onSubmit: widget.onForgotPassword,
      ),
    );
  }

  void _toggleMode() {
    setState(() => _registerMode = !_registerMode);
  }

  Future<void> _submit() {
    if (_registerMode) {
      return widget.onRegister(_name.text, _email.text, _password.text);
    }
    return widget.onLogin(_email.text, _password.text, rememberMe: _rememberMe);
  }

  @override
  Widget build(BuildContext context) {
    final title = _registerMode ? 'Create your account' : 'Login';
    const subtitle = '';

    return LayoutBuilder(
      builder: (context, constraints) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 16),
        child: Center(
          child: ConstrainedBox(
            constraints: BoxConstraints(
              minHeight: constraints.maxHeight - 32,
              maxWidth: 440,
            ),
            child: Center(
              child: KeyedSubtree(
                key: const Key('login-card'),
                child: _ShellCard(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Column(
                        key: const Key('login-header'),
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.center,
                        children: [
                          Row(
                            mainAxisSize: MainAxisSize.max,
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              if (_registerMode)
                                Icon(
                                  Icons.person_add_alt_1_rounded,
                                  color: HeyBeanTheme.accentStrong,
                                )
                              else
                                ClipRRect(
                                  borderRadius: BorderRadius.circular(8),
                                  child: Image.asset(
                                    'assets/images/bean/bean-logo.png',
                                    key: const Key('login-header-logo'),
                                    width: 28,
                                    height: 28,
                                  ),
                                ),
                              const SizedBox(width: 10),
                              Flexible(
                                child: Text(
                                  title,
                                  textAlign: TextAlign.center,
                                  softWrap: true,
                                  style: Theme.of(context).textTheme.titleMedium
                                      ?.copyWith(fontWeight: FontWeight.w800),
                                ),
                              ),
                            ],
                          ),
                          if (subtitle.isNotEmpty) ...[
                            const SizedBox(height: 4),
                            Text(
                              subtitle,
                              textAlign: TextAlign.center,
                              style: Theme.of(context).textTheme.bodySmall
                                  ?.copyWith(color: HeyBeanTheme.muted),
                            ),
                          ],
                        ],
                      ),
                      const SizedBox(height: 16),
                      if (_registerMode) ...[
                        TextField(
                          key: const Key('auth-name'),
                          controller: _name,
                          textInputAction: TextInputAction.next,
                          decoration: const InputDecoration(labelText: 'Name'),
                        ),
                        const SizedBox(height: 12),
                      ],
                      TextField(
                        key: const Key('auth-email'),
                        controller: _email,
                        keyboardType: TextInputType.emailAddress,
                        textInputAction: TextInputAction.next,
                        decoration: const InputDecoration(labelText: 'Email'),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        key: const Key('auth-password'),
                        controller: _password,
                        obscureText: true,
                        textInputAction: TextInputAction.done,
                        onSubmitted: (_) => widget.busy ? null : _submit(),
                        decoration: InputDecoration(
                          labelText: 'Password',
                          helperText: _registerMode
                              ? 'Minimum 12 characters'
                              : null,
                        ),
                      ),
                      if (!_registerMode) ...[
                        const SizedBox(height: 8),
                        CheckboxListTile(
                          key: const Key('remember-me-checkbox'),
                          value: _rememberMe,
                          onChanged: widget.busy
                              ? null
                              : (value) => setState(
                                  () => _rememberMe = value ?? false,
                                ),
                          title: const Text('Remember me'),
                          contentPadding: EdgeInsets.zero,
                          controlAffinity: ListTileControlAffinity.leading,
                          dense: true,
                        ),
                      ],
                      if (widget.error != null) ...[
                        const SizedBox(height: 12),
                        Text(
                          widget.error!,
                          style: const TextStyle(color: Colors.redAccent),
                        ),
                      ],
                      if (widget.notice != null) ...[
                        const SizedBox(height: 12),
                        Text(
                          widget.notice!,
                          key: const Key('auth-notice'),
                          style: TextStyle(
                            color: HeyBeanTheme.accentStrong,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ],
                      const SizedBox(height: 16),
                      FilledButton(
                        key: const Key('auth-submit'),
                        onPressed: widget.busy ? null : _submit,
                        child: Text(
                          widget.busy
                              ? (_registerMode
                                    ? 'Creating account…'
                                    : 'Signing in…')
                              : (_registerMode ? 'Create account' : 'Sign in'),
                        ),
                      ),
                      const SizedBox(height: 8),
                      Wrap(
                        alignment: WrapAlignment.spaceBetween,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        spacing: 8,
                        runSpacing: 4,
                        children: [
                          TextButton(
                            key: Key(
                              _registerMode
                                  ? 'show-login-mode'
                                  : 'show-register-mode',
                            ),
                            onPressed: widget.busy ? null : _toggleMode,
                            child: Text(
                              _registerMode
                                  ? 'Already have an account? Sign in'
                                  : 'Create an account',
                            ),
                          ),
                          TextButton(
                            key: const Key('forgot-login-action'),
                            onPressed: widget.busy
                                ? null
                                : _showForgotPasswordDialog,
                            child: const Text('Forgot password?'),
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      Wrap(
                        alignment: WrapAlignment.center,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        spacing: 8,
                        runSpacing: 4,
                        children: [
                          TextButton(
                            key: const Key('privacy-policy-link'),
                            onPressed: widget.busy
                                ? null
                                : () => widget.launchExternalUrl(
                                    _privacyPolicyUrl,
                                  ),
                            child: const Text('Privacy'),
                          ),
                          TextButton(
                            key: const Key('terms-of-service-link'),
                            onPressed: widget.busy
                                ? null
                                : () => widget.launchExternalUrl(
                                    _termsOfServiceUrl,
                                  ),
                            child: const Text('Terms'),
                          ),
                          TextButton(
                            key: const Key('support-link'),
                            onPressed: widget.busy
                                ? null
                                : () => widget.launchExternalUrl(_supportUrl),
                            child: const Text('Support'),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _ForgotPasswordDialog extends StatefulWidget {
  const _ForgotPasswordDialog({
    required this.initialEmail,
    required this.onSubmit,
  });

  final String initialEmail;
  final _ForgotPasswordHandler onSubmit;

  @override
  State<_ForgotPasswordDialog> createState() => _ForgotPasswordDialogState();
}

class _ForgotPasswordDialogState extends State<_ForgotPasswordDialog> {
  late final TextEditingController _email;
  bool _sending = false;
  bool _sent = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _email = TextEditingController(text: widget.initialEmail.trim());
  }

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      await widget.onSubmit(_email.text.trim());
      if (!mounted) return;
      setState(() => _sent = true);
    } catch (error) {
      if (!mounted) return;
      setState(
        () => _error = beanFriendlyErrorMessage(
          error,
          action: 'send a password reset link',
        ),
      );
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_sent) {
      return AlertDialog(
        title: const Text('Check your email'),
        content: const Text(
          'If that email matches a HeyBean account, we sent a password reset link. After you reset it, come back here and sign in with your new password.',
        ),
        actions: [
          FilledButton(
            key: const Key('back-to-login-after-reset'),
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Back to login'),
          ),
        ],
      );
    }

    return AlertDialog(
      title: const Text('Reset password'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'Enter the email used for your account and we’ll send a password reset link.',
          ),
          const SizedBox(height: 16),
          TextField(
            key: const Key('forgot-password-email'),
            controller: _email,
            enabled: !_sending,
            keyboardType: TextInputType.emailAddress,
            textInputAction: TextInputAction.done,
            onSubmitted: (_) => _sending ? null : _submit(),
            decoration: const InputDecoration(labelText: 'Account email'),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, style: const TextStyle(color: Colors.redAccent)),
          ],
        ],
      ),
      actions: [
        TextButton(
          onPressed: _sending ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const Key('send-password-reset-link'),
          onPressed: _sending ? null : _submit,
          child: Text(_sending ? 'Sending…' : 'Send reset link'),
        ),
      ],
    );
  }
}

class _CommandCenterContent extends StatelessWidget {
  const _CommandCenterContent({
    required this.apiClient,
    required this.user,
    required this.tasks,
    required this.pastTasks,
    required this.reminders,
    required this.calendar,
    required this.noteFolders,
    required this.notes,
    required this.noteToOpenId,
    required this.memoryItems,
    required this.memorySummaries,
    required this.memoryHistory,
    required this.eventCategories,
    required this.googleCalendarStatus,
    required this.events,
    required this.messages,
    required this.busy,
    required this.dashboardDataLoading,
    required this.chatRunState,
    required this.chatInputController,
    required this.chatInputFocusNode,
    required this.onChatMessageCopied,
    required this.onChatMessageEdited,
    required this.beanChatCollapsed,
    required this.onBeanChatCollapsedChanged,
    required this.selectedDestination,
    required this.selectedCalendarDay,
    required this.showCalendarMonth,
    required this.calendarStartHour,
    required this.calendarEndHour,
    required this.onCalendarDaySelected,
    required this.onCalendarMonthSelected,
    required this.calendarMinimumDay,
    required this.onCalendarHistoryLimitReached,
    required this.onBackToCalendarDay,
    required this.onCalendarStartHourChanged,
    required this.onCalendarEndHourChanged,
    required this.onSelectDestination,
    required this.onTaskCompleted,
    required this.pendingTaskIds,
    required this.onTaskSaved,
    required this.onTaskDeleted,
    required this.onReminderSaved,
    required this.onReminderCompleted,
    required this.onReminderDeleted,
    required this.onCalendarEventCreated,
    required this.onCalendarEventEdited,
    required this.onCalendarEventDeleted,
    required this.onNoteFolderCreated,
    required this.onNoteFolderDeleted,
    required this.onNoteSaved,
    required this.onNoteDeleted,
    required this.onMemoryRefresh,
    required this.onMemoryCreated,
    required this.onMemoryUpdated,
    required this.onMemoryDeleted,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
    required this.onDeleteAccount,
    required this.onSignOut,
    required this.onAccountEmailChanged,
    required this.onNotificationPreferencesChanged,
    required this.onThemeChanged,
    required this.onCommandCenterLabelChanged,
    required this.launchExternalUrl,
    required this.stripePaymentHandler,
    required this.onBillingChanged,
    required this.onEditAgentOnboarding,
    required this.onWorkspacesChanged,
    this.error,
  });

  final HermesApiClient apiClient;
  final HermesUser user;
  final List<HermesTask> tasks;
  final List<HermesTask> pastTasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendar;
  final List<HermesNoteFolder> noteFolders;
  final List<HermesNote> notes;
  final int? noteToOpenId;
  final List<HermesMemoryItem> memoryItems;
  final List<HermesMemorySummary> memorySummaries;
  final List<HermesRequestHistoryItem> memoryHistory;
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final List<HermesActivityEvent> events;
  final List<HermesMessage> messages;
  final bool busy;
  final bool dashboardDataLoading;
  final String chatRunState;
  final TextEditingController chatInputController;
  final FocusNode chatInputFocusNode;
  final Future<void> Function(HermesMessage message) onChatMessageCopied;
  final ValueChanged<HermesMessage> onChatMessageEdited;
  final bool beanChatCollapsed;
  final ValueChanged<bool> onBeanChatCollapsedChanged;
  final _HomeDestination selectedDestination;
  final DateTime selectedCalendarDay;
  final bool showCalendarMonth;
  final int calendarStartHour;
  final int calendarEndHour;
  final ValueChanged<DateTime> onCalendarDaySelected;
  final ValueChanged<DateTime> onCalendarMonthSelected;
  final DateTime? calendarMinimumDay;
  final VoidCallback onCalendarHistoryLimitReached;
  final VoidCallback onBackToCalendarDay;
  final ValueChanged<int> onCalendarStartHourChanged;
  final ValueChanged<int> onCalendarEndHourChanged;
  final ValueChanged<_HomeDestination> onSelectDestination;
  final Future<void> Function(HermesTask task) onTaskCompleted;
  final Set<int> pendingTaskIds;
  final Future<void> Function(
    HermesTask? task, {
    required String title,
    String? dueAt,
    String? notes,
    String? category,
    String? color,
    bool? isCritical,
    int? parentTaskId,
    int? workspaceId,
    Map<String, Object?>? recurrenceMetadata,
    List<Object> syncToWorkspaceIds,
    List<String> googleCalendarIds,
  })
  onTaskSaved;
  final Future<void> Function(
    HermesTask task, {
    List<Object> deleteFromWorkspaceIds,
  })
  onTaskDeleted;
  final Future<void> Function(
    HermesReminder? reminder, {
    required String title,
    required String remindAt,
    String status,
    String? category,
    String? color,
    int? workspaceId,
    Map<String, Object?>? recurrenceMetadata,
    List<Object> syncToWorkspaceIds,
    List<String> googleCalendarIds,
  })
  onReminderSaved;
  final Future<void> Function(HermesReminder reminder) onReminderCompleted;
  final Future<void> Function(
    HermesReminder reminder, {
    List<Object> deleteFromWorkspaceIds,
  })
  onReminderDeleted;
  final Future<void> Function({
    required String title,
    required String startsAt,
    String? endsAt,
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool? isCritical,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
    int? workspaceId,
    List<Object> syncToWorkspaceIds,
  })
  onCalendarEventCreated;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool? isCritical,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
    int? workspaceId,
    List<Object> syncToWorkspaceIds,
  })
  onCalendarEventEdited;
  final Future<void> Function(
    HermesCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onCalendarEventDeleted;
  final Future<HermesNoteFolder> Function(String name) onNoteFolderCreated;
  final Future<void> Function(HermesNoteFolder folder) onNoteFolderDeleted;
  final Future<HermesNote> Function(
    HermesNote? note, {
    required String title,
    required String bodyHtml,
    required String plainText,
    int? folderId,
    bool clearFolder,
    bool? isPinned,
    Map<String, Object?>? metadata,
    List<Object>? syncToWorkspaceIds,
  })
  onNoteSaved;
  final Future<void> Function(HermesNote note) onNoteDeleted;
  final Future<void> Function() onMemoryRefresh;
  final Future<HermesMemoryItem> Function({
    required String content,
    String type,
    String? title,
  })
  onMemoryCreated;
  final Future<HermesMemoryItem> Function(
    HermesMemoryItem item, {
    required String content,
    required String type,
    String? title,
  })
  onMemoryUpdated;
  final Future<void> Function(HermesMemoryItem item) onMemoryDeleted;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    HermesEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;
  final Future<void> Function() onDeleteAccount;
  final Future<void> Function() onSignOut;
  final Future<void> Function(String email) onAccountEmailChanged;
  final Future<void> Function(HermesNotificationPreferences preferences)
  onNotificationPreferencesChanged;
  final Future<void> Function(String themeKey) onThemeChanged;
  final Future<void> Function(String label) onCommandCenterLabelChanged;
  final ExternalUrlLauncher launchExternalUrl;
  final StripePaymentHandler stripePaymentHandler;
  final Future<void> Function() onBillingChanged;
  final VoidCallback onEditAgentOnboarding;
  final Future<void> Function() onWorkspacesChanged;
  final String? error;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final activeTasks = _visibleSortedTasks(tasks);
        final calendarTasks = showCalendarMonth
            ? _tasksForMonthAgenda(tasks, selectedCalendarDay)
            : _tasksForTodayAgenda(tasks, DateTime.now());
        final beanPanel = _HeroChatCard(
          messages: messages,
          busy: busy,
          runState: chatRunState,
          inputController: chatInputController,
          inputFocusNode: chatInputFocusNode,
          onMessageCopied: onChatMessageCopied,
          onMessageEdited: onChatMessageEdited,
        );
        final selectedPanel = switch (selectedDestination) {
          _HomeDestination.today => _TodayHomeView(
            user: user,
            tasks: calendarTasks,
            calendar: calendar,
            loading: dashboardDataLoading,
            eventCategories: eventCategories,
            googleCalendarStatus: googleCalendarStatus,
            selectedDay: selectedCalendarDay,
            showMonth: showCalendarMonth,
            startHour: calendarStartHour,
            endHour: calendarEndHour,
            calendarMinimumDay: calendarMinimumDay,
            onCalendarHistoryLimitReached: onCalendarHistoryLimitReached,
            onDateSelected: onCalendarDaySelected,
            onMonthSelected: onCalendarMonthSelected,
            onBackToDay: onBackToCalendarDay,
            onTaskCompleted: onTaskCompleted,
            onTaskSaved: onTaskSaved,
            onTaskDeleted: onTaskDeleted,
            onCalendarEventCreated: onCalendarEventCreated,
            onCalendarEventEdited: onCalendarEventEdited,
            onCalendarEventDeleted: onCalendarEventDeleted,
            onEventCategorySaved: onEventCategorySaved,
            onEventCategoryDeleted: onEventCategoryDeleted,
          ),
          _HomeDestination.tasks => _TaskListCard(
            tasks: tasks,
            pastTasks: pastTasks,
            loading: dashboardDataLoading,
            eventCategories: eventCategories,
            pendingTaskIds: pendingTaskIds,
            onTaskCompleted: onTaskCompleted,
            onTaskSaved: onTaskSaved,
            onTaskDeleted: onTaskDeleted,
            onEventCategorySaved: onEventCategorySaved,
            workspaces: user.workspaces,
            activeWorkspaceId: user.activeWorkspace?.id,
          ),
          _HomeDestination.bean => _CommandCenterHome(
            tasks: tasks,
            reminders: reminders,
            calendar: calendar,
            loading: dashboardDataLoading,
            chat: beanPanel,
            chatCollapsed: beanChatCollapsed,
            onChatCollapsedChanged: onBeanChatCollapsedChanged,
          ),
          _HomeDestination.reminders => _ReminderListCard(
            reminders: reminders,
            loading: dashboardDataLoading,
            eventCategories: eventCategories,
            onReminderSaved: onReminderSaved,
            onReminderCompleted: onReminderCompleted,
            onReminderDeleted: onReminderDeleted,
            onEventCategorySaved: onEventCategorySaved,
            workspaces: user.workspaces,
            activeWorkspaceId: user.activeWorkspace?.id,
          ),
          _HomeDestination.notes => _NotesView(
            folders: noteFolders,
            notes: notes,
            workspaces: user.workspaces,
            activeWorkspaceId: user.activeWorkspace?.id,
            openNoteId: noteToOpenId,
            onFolderCreated: onNoteFolderCreated,
            onFolderDeleted: onNoteFolderDeleted,
            onNoteSaved: onNoteSaved,
            onNoteDeleted: onNoteDeleted,
          ),
          _HomeDestination.memory => _MemoryView(
            items: memoryItems,
            summaries: memorySummaries,
            history: memoryHistory,
            onRefresh: onMemoryRefresh,
            onCreated: onMemoryCreated,
            onUpdated: onMemoryUpdated,
            onDeleted: onMemoryDeleted,
          ),
          _HomeDestination.settings => _SettingsView(
            apiClient: apiClient,
            launchExternalUrl: launchExternalUrl,
            stripePaymentHandler: stripePaymentHandler,
            user: user,
            onBillingChanged: onBillingChanged,
            googleCalendarStatus: googleCalendarStatus,
            calendarStartHour: calendarStartHour,
            calendarEndHour: calendarEndHour,
            onCalendarStartHourChanged: onCalendarStartHourChanged,
            onCalendarEndHourChanged: onCalendarEndHourChanged,
            onDeleteAccount: onDeleteAccount,
            onSignOut: onSignOut,
            onAccountEmailChanged: onAccountEmailChanged,
            onNotificationPreferencesChanged: onNotificationPreferencesChanged,
            onThemeChanged: onThemeChanged,
            onCommandCenterLabelChanged: onCommandCenterLabelChanged,
            onEditAgentOnboarding: onEditAgentOnboarding,
            onWorkspacesChanged: onWorkspacesChanged,
            error: error,
          ),
        };
        final limitBanner = _isPlanLimitMessage(error)
            ? _PlanLimitErrorBanner(
                message: error,
                launchExternalUrl: launchExternalUrl,
              )
            : null;
        final panelChildren = <Widget>[
          if (limitBanner != null &&
              selectedDestination != _HomeDestination.settings) ...[
            limitBanner,
            const SizedBox(height: 12),
          ],
          if (selectedDestination == _HomeDestination.bean)
            Expanded(child: selectedPanel)
          else
            selectedPanel,
        ];
        final selectedPanelWithStatus =
            panelChildren.length == 1 && panelChildren.single == selectedPanel
            ? selectedPanel
            : Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: selectedDestination == _HomeDestination.bean
                    ? MainAxisSize.max
                    : MainAxisSize.min,
                children: panelChildren,
              );
        final right = Column(
          children: [
            _AccountCard(
              user: user,
              onEmailChanged: onAccountEmailChanged,
              onDeleteAccount: onDeleteAccount,
              onSignOut: onSignOut,
              launchExternalUrl: launchExternalUrl,
            ),
            const SizedBox(height: 16),
            _ProgressCard(
              user: user,
              error: error,
              taskCount: activeTasks.length,
            ),
            const SizedBox(height: 16),
            _ActivityCard(events: events),
            const SizedBox(height: 16),
            _ShellCard(
              child: _CalendarAgenda(
                calendar: calendar,
                eventCategories: eventCategories,
                googleCalendarStatus: googleCalendarStatus,
                workspaces: user.workspaces,
                activeWorkspaceId: user.activeWorkspace?.id,
                onEventTap: onCalendarEventEdited,
                onEventCategorySaved: onEventCategorySaved,
                onEventCategoryDeleted: onEventCategoryDeleted,
              ),
            ),
          ],
        );
        if (constraints.maxWidth < 900 ||
            selectedDestination != _HomeDestination.bean) {
          return selectedPanelWithStatus;
        }
        // The Bean chat tab owns the full screen; activity/approvals live inside
        // its top menu and bottom approval dock instead of side dashboard cards.
        if (selectedDestination == _HomeDestination.bean) {
          return selectedPanelWithStatus;
        }
        return Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(flex: 7, child: beanPanel),
            const SizedBox(width: 16),
            Expanded(flex: 5, child: right),
          ],
        );
      },
    );
  }
}

class _CommandCenterHome extends StatefulWidget {
  const _CommandCenterHome({
    required this.tasks,
    required this.reminders,
    required this.calendar,
    required this.loading,
    required this.chat,
    required this.chatCollapsed,
    required this.onChatCollapsedChanged,
  });

  final List<HermesTask> tasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendar;
  final bool loading;
  final Widget chat;
  final bool chatCollapsed;
  final ValueChanged<bool> onChatCollapsedChanged;

  @override
  State<_CommandCenterHome> createState() => _CommandCenterHomeState();
}

class _CommandCenterHomeState extends State<_CommandCenterHome> {
  double? _expandedChatHeight;

  void _toggleChatCollapsed(double fallbackHeight) {
    if (widget.chatCollapsed) {
      setState(() {
        _expandedChatHeight ??= fallbackHeight;
      });
      widget.onChatCollapsedChanged(false);
    } else {
      widget.onChatCollapsedChanged(true);
    }
  }

  void _resizeChat(double deltaY, double currentHeight, double maxHeight) {
    if (maxHeight <= 0) return;
    setState(() {
      _expandedChatHeight = (currentHeight - deltaY).clamp(0.0, maxHeight);
    });
    if (widget.chatCollapsed) widget.onChatCollapsedChanged(false);
  }

  @override
  Widget build(BuildContext context) {
    final items = _commandCenterAgendaItems(
      tasks: widget.tasks,
      reminders: widget.reminders,
      calendar: widget.calendar,
    );

    return LayoutBuilder(
      key: const Key('command-center-home'),
      builder: (context, constraints) {
        final maxChatHeight = math.max(0.0, constraints.maxHeight - 150.0);
        final minChatHeight = math.min(150.0, maxChatHeight);
        final fallbackChatHeight = math.min(
          math.max(220.0, constraints.maxHeight * .34),
          maxChatHeight,
        );
        final expandedChatHeight = (_expandedChatHeight ?? fallbackChatHeight)
            .clamp(minChatHeight, maxChatHeight)
            .toDouble();
        final chatHeight = widget.chatCollapsed ? 0.0 : expandedChatHeight;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(
              child: _CommandCenterAgendaList(
                items: items,
                loading: widget.loading,
              ),
            ),
            _CommandCenterSplitDivider(
              collapsed: widget.chatCollapsed,
              onToggle: () => _toggleChatCollapsed(fallbackChatHeight),
              onDragUpdate: (details) => _resizeChat(
                details.delta.dy,
                expandedChatHeight,
                maxChatHeight,
              ),
            ),
            if (!widget.chatCollapsed)
              SizedBox(
                key: const Key('command-center-chat-panel'),
                height: chatHeight,
                child: widget.chat,
              )
            else
              const SizedBox(
                key: Key('command-center-chat-panel-collapsed'),
                height: 0,
              ),
          ],
        );
      },
    );
  }
}

class _CommandCenterSplitDivider extends StatelessWidget {
  const _CommandCenterSplitDivider({
    required this.collapsed,
    required this.onToggle,
    required this.onDragUpdate,
  });

  final bool collapsed;
  final VoidCallback onToggle;
  final GestureDragUpdateCallback onDragUpdate;

  @override
  Widget build(BuildContext context) => GestureDetector(
    key: const Key('command-center-chat-resizer'),
    behavior: HitTestBehavior.opaque,
    onVerticalDragUpdate: onDragUpdate,
    child: SizedBox(
      height: 20,
      child: Row(
        children: [
          Expanded(
            child: Container(
              key: const Key('command-center-chat-divider-line'),
              height: 1,
              color: HeyBeanTheme.accent,
            ),
          ),
          const SizedBox(width: 4),
          SizedBox.square(
            dimension: 20,
            child: IconButton(
              key: const Key('command-center-chat-collapse-toggle'),
              tooltip: collapsed ? 'Expand chat' : 'Collapse chat',
              padding: EdgeInsets.zero,
              visualDensity: VisualDensity.compact,
              onPressed: onToggle,
              icon: Icon(
                collapsed
                    ? Icons.keyboard_arrow_up_rounded
                    : Icons.keyboard_arrow_down_rounded,
                size: 20,
                color: HeyBeanTheme.accentStrong,
              ),
            ),
          ),
        ],
      ),
    ),
  );
}

class _CommandCenterAgendaList extends StatelessWidget {
  const _CommandCenterAgendaList({required this.items, required this.loading});

  final List<_CommandCenterAgendaItem> items;
  final bool loading;

  @override
  Widget build(BuildContext context) {
    if (loading && items.isEmpty) {
      return const _InlineLoadingSurface(
        key: Key('command-center-agenda-loading'),
        label: 'Loading today',
        fillHeight: true,
      );
    }
    if (items.isEmpty) {
      return Container(
        key: const Key('command-center-agenda-empty'),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: HeyBeanTheme.surface.withValues(alpha: .62),
          borderRadius: BorderRadius.circular(24),
          border: Border.all(color: HeyBeanTheme.border),
        ),
        child: const Text(
          'Nothing else scheduled for today.',
          style: TextStyle(
            color: HeyBeanTheme.muted,
            fontWeight: FontWeight.w800,
          ),
        ),
      );
    }

    return ListView.separated(
      key: const Key('command-center-agenda-list'),
      padding: EdgeInsets.zero,
      itemCount: items.length,
      separatorBuilder: (context, index) => const SizedBox(height: 6),
      itemBuilder: (context, index) =>
          _CommandCenterAgendaRow(item: items[index]),
    );
  }
}

class _CommandCenterAgendaRow extends StatelessWidget {
  const _CommandCenterAgendaRow({required this.item});

  final _CommandCenterAgendaItem item;

  @override
  Widget build(BuildContext context) {
    final color = switch (item.kind) {
      _CommandCenterAgendaKind.event => HeyBeanTheme.accentStrong,
      _CommandCenterAgendaKind.task => HeyBeanTheme.warning,
      _CommandCenterAgendaKind.reminder => const Color(0xFF3B82F6),
    };
    final icon = switch (item.kind) {
      _CommandCenterAgendaKind.event => Icons.event_rounded,
      _CommandCenterAgendaKind.task => Icons.checklist_rounded,
      _CommandCenterAgendaKind.reminder => Icons.notifications_rounded,
    };
    final kindLabel = switch (item.kind) {
      _CommandCenterAgendaKind.event => 'Event',
      _CommandCenterAgendaKind.task => 'Task',
      _CommandCenterAgendaKind.reminder => 'Reminder',
    };

    return Container(
      key: Key('command-center-agenda-${item.key}'),
      padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 9),
      decoration: BoxDecoration(
        color: HeyBeanTheme.surface.withValues(alpha: .82),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: HeyBeanTheme.border),
      ),
      child: Row(
        children: [
          SizedBox(
            width: 58,
            child: Text(
              item.timeLabel,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                color: HeyBeanTheme.muted,
                fontSize: 12,
                fontWeight: FontWeight.w900,
              ),
            ),
          ),
          Container(
            width: 28,
            height: 28,
            decoration: BoxDecoration(
              color: color.withValues(alpha: .12),
              borderRadius: BorderRadius.circular(999),
            ),
            child: Icon(icon, color: color, size: 16),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  item.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: HeyBeanTheme.text,
                    fontSize: 13,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                if (item.subtitle.isNotEmpty)
                  Text(
                    item.subtitle,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: HeyBeanTheme.muted,
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                    ),
                  )
                else
                  Text(
                    kindLabel,
                    style: const TextStyle(
                      color: HeyBeanTheme.muted,
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

enum _CommandCenterAgendaKind { event, task, reminder }

class _CommandCenterAgendaItem {
  const _CommandCenterAgendaItem({
    required this.key,
    required this.kind,
    required this.title,
    required this.time,
    required this.timeLabel,
    this.subtitle = '',
  });

  final String key;
  final _CommandCenterAgendaKind kind;
  final String title;
  final DateTime time;
  final String timeLabel;
  final String subtitle;
}

class _HeroChatCard extends StatefulWidget {
  const _HeroChatCard({
    required this.messages,
    required this.busy,
    required this.runState,
    required this.inputController,
    required this.inputFocusNode,
    required this.onMessageCopied,
    required this.onMessageEdited,
  });

  final List<HermesMessage> messages;
  final bool busy;
  final String runState;
  final TextEditingController inputController;
  final FocusNode inputFocusNode;
  final Future<void> Function(HermesMessage message) onMessageCopied;
  final ValueChanged<HermesMessage> onMessageEdited;

  @override
  State<_HeroChatCard> createState() => _HeroChatCardState();
}

class _HeroChatCardState extends State<_HeroChatCard> {
  final _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _scrollToBottom());
  }

  @override
  void didUpdateWidget(covariant _HeroChatCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    WidgetsBinding.instance.addPostFrameCallback((_) => _scrollToBottom());
  }

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  void _scrollToBottom() {
    if (!_scrollController.hasClients) return;
    _scrollController.animateTo(
      _scrollController.position.maxScrollExtent,
      duration: const Duration(milliseconds: 180),
      curve: Curves.easeOut,
    );
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox.expand(
      key: const Key('chat-view'),
      child: Stack(
        children: [
          Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: Builder(
                  builder: (context) {
                    return ListView.builder(
                      key: const Key('chat-message-list'),
                      controller: _scrollController,
                      padding: const EdgeInsets.only(bottom: 12, top: 8),
                      itemCount: widget.messages.length + (widget.busy ? 1 : 0),
                      itemBuilder: (context, index) {
                        if (index >= widget.messages.length) {
                          return _MessageBubble(
                            sender: 'Bean',
                            message: widget.runState,
                            progress: true,
                          );
                        }
                        final message = widget.messages[index];
                        final isUser = message.role == 'user';
                        return Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: _MessageBubble(
                            sender: isUser ? 'You' : 'Bean',
                            message: message.content ?? '',
                            alignRight: isUser,
                            onCopy: isUser
                                ? () =>
                                      unawaited(widget.onMessageCopied(message))
                                : null,
                            onEdit: isUser && !widget.busy
                                ? () => widget.onMessageEdited(message)
                                : null,
                          ),
                        );
                      },
                    );
                  },
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _DockedBeanChatComposer extends StatefulWidget {
  const _DockedBeanChatComposer({
    required this.controller,
    required this.focusNode,
    required this.busy,
    required this.listening,
    required this.voiceDraft,
    required this.onChanged,
    required this.onSend,
    required this.onStop,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final bool busy;
  final bool listening;
  final String? voiceDraft;
  final ValueChanged<String> onChanged;
  final VoidCallback onSend;
  final Future<void> Function() onStop;

  @override
  State<_DockedBeanChatComposer> createState() =>
      _DockedBeanChatComposerState();
}

class _DockedBeanChatComposerState extends State<_DockedBeanChatComposer> {
  @override
  void initState() {
    super.initState();
    _syncVoiceDraftToInput();
  }

  @override
  void didUpdateWidget(covariant _DockedBeanChatComposer oldWidget) {
    super.didUpdateWidget(oldWidget);
    _syncVoiceDraftToInput();
  }

  void _syncVoiceDraftToInput() {
    if (!widget.listening) return;
    final draft = widget.voiceDraft;
    if (draft == null || draft == widget.controller.text) return;
    widget.controller.text = draft;
    widget.controller.selection = TextSelection.collapsed(
      offset: widget.controller.text.length,
    );
  }

  @override
  Widget build(BuildContext context) => _ChatInputDock(
    controller: widget.controller,
    focusNode: widget.focusNode,
    busy: widget.busy,
    listening: widget.listening,
    onChanged: widget.listening ? widget.onChanged : null,
    onSend: widget.onSend,
    onStop: widget.onStop,
  );
}

class _ChatInputDock extends StatelessWidget {
  const _ChatInputDock({
    required this.controller,
    required this.focusNode,
    required this.busy,
    required this.listening,
    required this.onSend,
    required this.onStop,
    this.onChanged,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final bool busy;
  final bool listening;
  final VoidCallback onSend;
  final Future<void> Function() onStop;
  final ValueChanged<String>? onChanged;

  @override
  Widget build(BuildContext context) => Container(
    key: const Key('chat-input-dock'),
    padding: const EdgeInsets.all(8),
    decoration: BoxDecoration(
      color: HeyBeanTheme.surface,
      borderRadius: const BorderRadius.only(
        topLeft: Radius.circular(22),
        topRight: Radius.circular(22),
      ),
      border: Border.all(
        color: listening ? HeyBeanTheme.accentStrong : HeyBeanTheme.border,
        width: listening ? 2 : 1,
      ),
      boxShadow: const [
        BoxShadow(
          color: Color(0x14000000),
          blurRadius: 22,
          offset: Offset(0, 10),
        ),
      ],
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Expanded(
          child: TextField(
            key: const Key('chat-input'),
            controller: controller,
            focusNode: focusNode,
            minLines: 1,
            maxLines: 4,
            keyboardType: TextInputType.multiline,
            onChanged: onChanged,
            textInputAction: TextInputAction.send,
            onSubmitted: busy ? null : (_) => onSend(),
            decoration: InputDecoration(
              hintText: listening ? 'Listening' : 'Message Bean…',
              border: InputBorder.none,
              enabledBorder: InputBorder.none,
              focusedBorder: InputBorder.none,
              filled: false,
            ),
          ),
        ),
        const SizedBox(width: 8),
        if (busy || listening)
          FilledButton(
            key: const Key('primary-chat-stop-action'),
            style: FilledButton.styleFrom(
              backgroundColor: HeyBeanTheme.destructive,
              foregroundColor: Colors.white,
              minimumSize: const Size(44, 44),
              padding: EdgeInsets.zero,
            ),
            onPressed: () => unawaited(onStop()),
            child: const Icon(Icons.stop_rounded, size: 18),
          )
        else
          FilledButton(
            key: const Key('primary-chat-action'),
            onPressed: onSend,
            child: const Icon(Icons.arrow_upward_rounded, size: 18),
          ),
      ],
    ),
  );
}

typedef _ApprovalAction = Future<void> Function(HermesApproval approval);
typedef _ApprovalChangeAction =
    Future<void> Function(HermesApproval approval, String revisedRequest);

class _ApprovalRequestSheet extends StatefulWidget {
  const _ApprovalRequestSheet({
    required this.approval,
    required this.onApprove,
    required this.onAlwaysApprove,
    required this.onDeny,
    required this.onChange,
  });

  final HermesApproval approval;
  final _ApprovalAction onApprove;
  final _ApprovalAction onAlwaysApprove;
  final _ApprovalAction onDeny;
  final _ApprovalChangeAction onChange;

  @override
  State<_ApprovalRequestSheet> createState() => _ApprovalRequestSheetState();
}

class _ApprovalRequestSheetState extends State<_ApprovalRequestSheet> {
  final TextEditingController _changeController = TextEditingController();
  bool _changing = false;
  bool _busy = false;

  @override
  void dispose() {
    _changeController.dispose();
    super.dispose();
  }

  Future<void> _run(Future<void> Function() action) async {
    setState(() => _busy = true);
    try {
      await action();
      if (mounted) Navigator.of(context).pop();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.viewInsetsOf(context).bottom;
    final approval = widget.approval;
    final actionDescription = _approvalActionDescription(approval);

    return Padding(
      padding: EdgeInsets.only(bottom: bottomInset),
      child: Container(
        key: const Key('global-approval-bottom-sheet'),
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
        decoration: const BoxDecoration(
          color: HeyBeanTheme.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
          boxShadow: [
            BoxShadow(
              color: Color(0x26000000),
              blurRadius: 30,
              offset: Offset(0, -12),
            ),
          ],
        ),
        child: SafeArea(
          top: false,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 42,
                  height: 4,
                  decoration: BoxDecoration(
                    color: HeyBeanTheme.border,
                    borderRadius: BorderRadius.circular(999),
                  ),
                ),
              ),
              const SizedBox(height: 18),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 42,
                    height: 42,
                    decoration: BoxDecoration(
                      color: HeyBeanTheme.warning.withValues(alpha: .14),
                      borderRadius: BorderRadius.circular(16),
                    ),
                    child: const Icon(
                      Icons.verified_user_rounded,
                      color: HeyBeanTheme.warning,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'I need approval',
                          style: Theme.of(context).textTheme.titleLarge
                              ?.copyWith(fontWeight: FontWeight.w900),
                        ),
                        const SizedBox(height: 3),
                        const Text(
                          "Approve or deny Bean's next action",
                          style: TextStyle(
                            color: HeyBeanTheme.muted,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: const Color(0xFFFFFBEB),
                  borderRadius: BorderRadius.circular(18),
                  border: Border.all(
                    color: HeyBeanTheme.warning.withValues(alpha: .28),
                  ),
                ),
                child: Text(
                  actionDescription,
                  key: const Key('approval-action-description'),
                  style: const TextStyle(
                    height: 1.35,
                    fontWeight: FontWeight.w700,
                    color: HeyBeanTheme.text,
                  ),
                ),
              ),
              const SizedBox(height: 14),
              if (_changing) ...[
                TextField(
                  key: const Key('approval-change-input'),
                  controller: _changeController,
                  minLines: 2,
                  maxLines: 4,
                  enabled: !_busy,
                  autofocus: true,
                  decoration: _longFormInputDecoration(
                    labelText: 'Change Bean’s instruction',
                    hintText: 'Tell Bean what to do instead…',
                  ),
                ),
                const SizedBox(height: 10),
              ],
              Wrap(
                spacing: 8,
                runSpacing: 8,
                alignment: WrapAlignment.end,
                children: [
                  TextButton(
                    key: const Key('approval-deny-action'),
                    onPressed: _busy
                        ? null
                        : () => _run(() => widget.onDeny(approval)),
                    child: const Text('Deny'),
                  ),
                  OutlinedButton(
                    key: const Key('approval-change-action'),
                    onPressed: _busy
                        ? null
                        : () {
                            if (!_changing) {
                              setState(() => _changing = true);
                              return;
                            }
                            final revised = _changeController.text.trim();
                            if (revised.isEmpty) return;
                            _run(() => widget.onChange(approval, revised));
                          },
                    child: Text(_changing ? 'Send change' : 'Change'),
                  ),
                  OutlinedButton(
                    key: const Key('approval-always-approve-action'),
                    onPressed: _busy
                        ? null
                        : () => _run(() => widget.onAlwaysApprove(approval)),
                    child: const Text('Always approve'),
                  ),
                  FilledButton(
                    key: const Key('approval-approve-action'),
                    onPressed: _busy
                        ? null
                        : () => _run(() => widget.onApprove(approval)),
                    child: _busy
                        ? const SizedBox.square(
                            dimension: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Text('Approve'),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

String _approvalActionDescription(HermesApproval approval) {
  final action = approval.payload['action'];
  final actionMap = action is Map
      ? action.map((key, value) => MapEntry(key.toString(), value))
      : const <String, Object?>{};
  final type = (actionMap['type'] ?? approval.title).toString();
  final risk = (actionMap['risk'] ?? 'unknown').toString().toLowerCase();
  final description = approval.description?.trim();
  if (description != null && description.isNotEmpty) {
    return '$description This action is marked $risk risk.';
  }

  return 'Bean wants to ${type.replaceAll('.', ' ')}. This action is marked $risk risk.';
}

String _compactBeanStatusLabel(String value) {
  final raw = value.trim();
  if (raw.isEmpty) return 'Ready';
  final lower = raw.toLowerCase();
  if (lower.contains("unknown parameter: 'response.modalities'") ||
      lower.contains('unknown parameter: response.modalities')) {
    return 'Bean voice issue';
  }
  return switch (lower) {
    'failed' => 'Failed',
    'blocked' => 'Blocked',
    'stopped' => 'Stopped',
    'updated' => 'Updated',
    _ => raw.length > 44 ? '${raw.substring(0, 41)}...' : raw,
  };
}

// ignore: unused_element
class _QuickPromptRail extends StatelessWidget {
  const _QuickPromptRail({required this.onPrompt});

  final Future<void> Function(String content) onPrompt;

  @override
  Widget build(BuildContext context) {
    const prompts = <({IconData icon, String label, String prompt, Key key})>[
      (
        icon: Icons.today_rounded,
        label: 'Plan today',
        prompt: 'Help me plan today',
        key: Key('quick-plan-today'),
      ),
      (
        icon: Icons.task_alt_rounded,
        label: 'Add task',
        prompt: 'Add a task',
        key: Key('quick-add-task'),
      ),
      (
        icon: Icons.notifications_active_rounded,
        label: 'Set reminder',
        prompt: 'Set a reminder',
        key: Key('quick-set-reminder'),
      ),
      (
        icon: Icons.calendar_month_rounded,
        label: 'Schedule event',
        prompt: 'Schedule an event',
        key: Key('quick-schedule-event'),
      ),
    ];

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final prompt in prompts)
          ActionChip(
            key: prompt.key,
            avatar: Icon(
              prompt.icon,
              size: 16,
              color: HeyBeanTheme.accentStrong,
            ),
            label: Text(prompt.label),
            onPressed: () => onPrompt(prompt.prompt),
            backgroundColor: HeyBeanTheme.accent.withValues(alpha: .08),
            side: const BorderSide(color: HeyBeanTheme.border),
            labelStyle: const TextStyle(
              color: HeyBeanTheme.text,
              fontWeight: FontWeight.w700,
            ),
          ),
      ],
    );
  }
}

class _MessageBubble extends StatelessWidget {
  const _MessageBubble({
    required this.sender,
    required this.message,
    this.alignRight = false,
    this.progress = false,
    this.onCopy,
    this.onEdit,
  });

  final String sender;
  final String message;
  final bool alignRight;
  final bool progress;
  final VoidCallback? onCopy;
  final VoidCallback? onEdit;

  @override
  Widget build(BuildContext context) {
    final hasActions = onCopy != null || onEdit != null;
    final bubble = Container(
      key: alignRight ? const Key('user-message-bubble') : null,
      constraints: const BoxConstraints(maxWidth: 560),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: alignRight
            ? HeyBeanTheme.accent.withValues(alpha: .12)
            : HeyBeanTheme.surface2,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: HeyBeanTheme.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              if (progress) ...[
                const SizedBox.square(
                  dimension: 12,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
                const SizedBox(width: 8),
              ],
              Text(
                sender,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: HeyBeanTheme.accentStrong,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(message),
        ],
      ),
    );

    return Align(
      alignment: alignRight ? Alignment.centerRight : Alignment.centerLeft,
      child: hasActions
          ? Material(
              color: Colors.transparent,
              child: InkWell(
                key: const Key('sent-message-actions-trigger'),
                borderRadius: BorderRadius.circular(18),
                onTap: () => _showSentMessageActions(context),
                child: bubble,
              ),
            )
          : bubble,
    );
  }

  void _showSentMessageActions(BuildContext context) {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (onCopy != null)
              ListTile(
                key: const Key('chat-copy-sent-message-action'),
                leading: const Icon(Icons.copy_rounded),
                title: const Text('Copy'),
                onTap: () {
                  Navigator.pop(context);
                  onCopy?.call();
                },
              ),
            if (onEdit != null)
              ListTile(
                key: const Key('chat-edit-sent-message-action'),
                leading: const Icon(Icons.edit_rounded),
                title: const Text('Edit'),
                onTap: () {
                  Navigator.pop(context);
                  onEdit?.call();
                },
              ),
          ],
        ),
      ),
    );
  }
}

// ignore: unused_element
class _ApprovalCard extends StatelessWidget {
  const _ApprovalCard({required this.approvals});

  final List<HermesApproval> approvals;

  @override
  Widget build(BuildContext context) {
    final hasApprovals = approvals.isNotEmpty;

    return _ShellCard(
      glow: hasApprovals,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionTitle(
            icon: Icons.verified_user_rounded,
            title: hasApprovals ? 'Pending approvals' : 'Approval queue clear',
            subtitle: hasApprovals
                ? 'Hermes will wait before risky/destructive actions'
                : 'Low-risk internal actions can run automatically',
          ),
          const SizedBox(height: 14),
          if (hasApprovals)
            for (final approval in approvals.take(3)) ...[
              _ApprovalListTile(approval: approval),
              if (approval != approvals.take(3).last)
                const SizedBox(height: 10),
            ]
          else
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: HeyBeanTheme.surface2,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: HeyBeanTheme.border),
              ),
              child: const Text(
                'Hermes Bean asks first for mail, payments, destructive edits, deployments, and other risky requests.',
              ),
            ),
        ],
      ),
    );
  }
}

class _ApprovalListTile extends StatelessWidget {
  const _ApprovalListTile({required this.approval});

  final HermesApproval approval;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: HeyBeanTheme.surface2,
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: HeyBeanTheme.border),
    ),
    child: Row(
      children: [
        const Icon(Icons.shield_rounded, color: HeyBeanTheme.warning),
        const SizedBox(width: 12),
        Expanded(
          child: Text(
            approval.title,
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
        ),
        Text(
          approval.status ?? 'pending',
          style: Theme.of(context).textTheme.labelMedium?.copyWith(
            color: HeyBeanTheme.muted,
            fontWeight: FontWeight.w700,
          ),
        ),
      ],
    ),
  );
}

// ignore: unused_element
class _TabSurface extends StatelessWidget {
  const _TabSurface({
    required this.tasks,
    required this.reminders,
    required this.calendar,
    required this.events,
  });

  final List<HermesTask> tasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendar;
  final List<HermesActivityEvent> events;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: DefaultTabController(
      length: 5,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          TabBar(
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            labelColor: HeyBeanTheme.accentStrong,
            unselectedLabelColor: HeyBeanTheme.muted,
            indicatorColor: HeyBeanTheme.accent,
            tabs: const [
              Tab(text: 'Today'),
              Tab(text: 'Tasks'),
              Tab(text: 'Reminders'),
              Tab(text: 'Calendar'),
              Tab(text: 'Activity'),
            ],
          ),
          const SizedBox(height: 16),
          Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [
              _MiniSurface(
                label: 'Today',
                value: '${tasks.length} tasks · ${calendar.length} events',
                icon: Icons.today_rounded,
              ),
              _MiniSurface(
                label: 'Tasks',
                value: tasks.isEmpty
                    ? 'No open tasks'
                    : tasks.map((t) => t.title).join(', '),
                icon: Icons.task_alt_rounded,
              ),
              _MiniSurface(
                label: 'Reminders',
                value: reminders.isEmpty
                    ? 'No reminders'
                    : reminders.map((r) => r.title).join(', '),
                icon: Icons.notifications_active_rounded,
              ),
              _MiniSurface(
                label: 'Calendar',
                value: calendar.isEmpty
                    ? 'Open calendar'
                    : calendar.map((e) => e.title).join(', '),
                icon: Icons.calendar_month_rounded,
              ),
              _MiniSurface(
                label: 'Activity',
                value: '${events.length} agent events',
                icon: Icons.auto_awesome_rounded,
              ),
            ],
          ),
        ],
      ),
    ),
  );
}

class _ProgressCard extends StatelessWidget {
  const _ProgressCard({
    required this.user,
    required this.taskCount,
    this.error,
  });

  final HermesUser user;
  final int taskCount;
  final String? error;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.trending_up_rounded,
          title: 'Agent progress',
          subtitle: 'Live run status',
        ),
        const SizedBox(height: 12),
        Text(
          'Welcome, ${user.name}',
          style: Theme.of(
            context,
          ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800),
        ),
        Text('$taskCount live tasks loaded'),
        if (error != null) _InlinePlanLimitError(message: error!),
      ],
    ),
  );
}

class _PlanLimitErrorBanner extends StatelessWidget {
  const _PlanLimitErrorBanner({
    required this.message,
    required this.launchExternalUrl,
  });

  final String? message;
  final ExternalUrlLauncher launchExternalUrl;

  @override
  Widget build(BuildContext context) {
    final text = message;
    if (!_isPlanLimitMessage(text)) return const SizedBox.shrink();

    return Container(
      key: const Key('plan-limit-error-banner'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: HeyBeanTheme.accent.withValues(alpha: .10),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: HeyBeanTheme.accent.withValues(alpha: .24)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                  color: HeyBeanTheme.accent.withValues(alpha: .14),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(
                  Icons.workspace_premium_rounded,
                  color: HeyBeanTheme.accentStrong,
                  size: 20,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Upgrade to keep going',
                      style: TextStyle(
                        color: HeyBeanTheme.text,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      text!,
                      style: const TextStyle(
                        color: HeyBeanTheme.muted,
                        fontWeight: FontWeight.w700,
                        height: 1.35,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              key: const Key('plan-limit-upgrade-action'),
              onPressed: () => launchExternalUrl(_pricingUrl),
              icon: const Icon(Icons.arrow_upward_rounded),
              label: const Text('Upgrade plan'),
            ),
          ),
        ],
      ),
    );
  }
}

class _InlinePlanLimitError extends StatelessWidget {
  const _InlinePlanLimitError({
    super.key,
    required this.message,
    this.launchExternalUrl = _defaultLaunchExternalUrl,
  });

  final String message;
  final ExternalUrlLauncher launchExternalUrl;

  @override
  Widget build(BuildContext context) {
    if (!_isPlanLimitMessage(message)) {
      return Text(message, style: const TextStyle(color: Colors.redAccent));
    }

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: HeyBeanTheme.accent.withValues(alpha: .10),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: HeyBeanTheme.accent.withValues(alpha: .24)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Upgrade to keep going',
            style: TextStyle(
              color: HeyBeanTheme.text,
              fontWeight: FontWeight.w900,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            message,
            style: const TextStyle(
              color: HeyBeanTheme.muted,
              fontWeight: FontWeight.w700,
              height: 1.35,
            ),
          ),
          const SizedBox(height: 10),
          Align(
            alignment: Alignment.centerLeft,
            child: FilledButton.icon(
              key: const Key('inline-plan-limit-upgrade-action'),
              onPressed: () => launchExternalUrl(_pricingUrl),
              icon: const Icon(Icons.arrow_upward_rounded),
              label: const Text('Upgrade plan'),
            ),
          ),
        ],
      ),
    );
  }
}

class _SuccessNotice extends StatelessWidget {
  const _SuccessNotice({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: HeyBeanTheme.accent.withValues(alpha: .10),
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: HeyBeanTheme.accent.withValues(alpha: .22)),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(
          Icons.check_circle_rounded,
          color: HeyBeanTheme.accentStrong,
          size: 20,
        ),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            message,
            style: const TextStyle(
              color: HeyBeanTheme.text,
              fontWeight: FontWeight.w800,
              height: 1.3,
            ),
          ),
        ),
      ],
    ),
  );
}

class _ActivityCard extends StatelessWidget {
  const _ActivityCard({required this.events});

  final List<HermesActivityEvent> events;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.history_rounded,
          title: 'Activity feed',
          subtitle: 'Grounded API events',
        ),
        const SizedBox(height: 12),
        for (final event in events)
          ListTile(
            dense: true,
            leading: const Icon(Icons.bolt_rounded),
            title: Text(event.eventType),
          ),
      ],
    ),
  );
}

class _TodayHomeView extends StatelessWidget {
  const _TodayHomeView({
    required this.user,
    required this.tasks,
    required this.calendar,
    required this.loading,
    required this.eventCategories,
    required this.googleCalendarStatus,
    required this.selectedDay,
    required this.showMonth,
    required this.startHour,
    required this.endHour,
    required this.calendarMinimumDay,
    required this.onCalendarHistoryLimitReached,
    required this.onDateSelected,
    required this.onMonthSelected,
    required this.onBackToDay,
    required this.onTaskCompleted,
    required this.onTaskSaved,
    required this.onTaskDeleted,
    required this.onCalendarEventCreated,
    required this.onCalendarEventEdited,
    required this.onCalendarEventDeleted,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final HermesUser user;
  final List<HermesTask> tasks;
  final List<HermesCalendarEvent> calendar;
  final bool loading;
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final DateTime selectedDay;
  final bool showMonth;
  final int startHour;
  final int endHour;
  final DateTime? calendarMinimumDay;
  final VoidCallback onCalendarHistoryLimitReached;
  final ValueChanged<DateTime> onDateSelected;
  final ValueChanged<DateTime> onMonthSelected;
  final VoidCallback onBackToDay;
  final Future<void> Function(HermesTask task) onTaskCompleted;
  final Future<void> Function(
    HermesTask? task, {
    required String title,
    String? dueAt,
    String? notes,
    String? category,
    String? color,
    bool? isCritical,
    int? parentTaskId,
    int? workspaceId,
    Map<String, Object?>? recurrenceMetadata,
    List<Object> syncToWorkspaceIds,
    List<String> googleCalendarIds,
  })
  onTaskSaved;
  final Future<void> Function(
    HermesTask task, {
    List<Object> deleteFromWorkspaceIds,
  })
  onTaskDeleted;
  final Future<void> Function({
    required String title,
    required String startsAt,
    String? endsAt,
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool? isCritical,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
    int? workspaceId,
    List<Object> syncToWorkspaceIds,
  })
  onCalendarEventCreated;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool? isCritical,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
    int? workspaceId,
    List<Object> syncToWorkspaceIds,
  })
  onCalendarEventEdited;
  final Future<void> Function(
    HermesCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onCalendarEventDeleted;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    HermesEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) {
    final taskListLabel = showMonth
        ? '${_monthName(selectedDay.month)} ${selectedDay.year}'
        : 'Today';
    final taskCountLabel = showMonth
        ? '${tasks.length} due or overdue'
        : '${tasks.length} tasks';
    final emptyTaskLabel = showMonth
        ? 'No tasks due or overdue in $taskListLabel'
        : 'No tasks scheduled for $taskListLabel';
    return Column(
      key: const Key('today-view'),
      children: [
        Column(
          key: const Key('calendar-view'),
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (showMonth) ...[
              _MonthScroller(
                selectedMonth: selectedDay,
                onMonthSelected: onMonthSelected,
              ),
              const SizedBox(height: 16),
              _MonthGrid(
                calendar: calendar,
                selectedDay: selectedDay,
                onDateSelected: onDateSelected,
              ),
            ] else ...[
              if (loading && calendar.isEmpty) ...[
                const _InlineLoadingSurface(
                  key: Key('today-calendar-loading'),
                  label: 'Loading calendar',
                ),
                const SizedBox(height: 10),
              ],
              _AppleStyleTodayTimeline(
                calendar: calendar,
                eventCategories: eventCategories,
                googleCalendarStatus: googleCalendarStatus,
                workspaces: user.workspaces,
                activeWorkspaceId: user.activeWorkspace?.id,
                selectedDay: selectedDay,
                startHour: startHour,
                endHour: endHour,
                minimumDay: calendarMinimumDay,
                onHistoryLimitReached: onCalendarHistoryLimitReached,
                onDayChanged: onDateSelected,
                onEventTap: onCalendarEventEdited,
                onEventDeleted: onCalendarEventDeleted,
                onEventCategorySaved: onEventCategorySaved,
                onEventCategoryDeleted: onEventCategoryDeleted,
              ),
            ],
          ],
        ),
        const SizedBox(height: 16),
        Column(
          key: const Key('today-task-list'),
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _SectionTitle(
              icon: Icons.task_alt_rounded,
              title: 'Tasks for $taskListLabel',
              subtitle: taskCountLabel,
              infoKey: const Key('today-tasks-info'),
              infoTitle: showMonth ? 'Tasks for month' : 'Tasks for today',
              infoBullets: const [
                'Use this list for the tasks Bean thinks belong on this calendar view.',
                'Tap the circle to complete or reopen a task. Tap the row to edit details.',
                'Star important tasks as Critical so they appear in the top count.',
              ],
            ),
            const SizedBox(height: 12),
            if (loading && tasks.isEmpty)
              const _InlineLoadingSurface(
                key: Key('today-tasks-loading'),
                label: 'Loading tasks',
              )
            else if (tasks.isEmpty)
              _EmptySurface(label: emptyTaskLabel)
            else ...[
              for (final task in tasks.where((task) => !_taskIsSubtask(task)))
                _TaskItemTile(
                  task: task,
                  subtitle: _taskSubtitle(task),
                  subtasks: _subtasksFor(task, tasks),
                  onCompleted: onTaskCompleted,
                  onTap: () => _showTaskEditor(context, task: task),
                  onSubtaskCompleted: onTaskCompleted,
                  onSubtaskTap: (subtask) =>
                      _showTaskEditor(context, task: subtask),
                  onAddSubtask: !_taskIsSubtask(task)
                      ? () => _showTaskEditor(context, parentTask: task)
                      : null,
                ),
            ],
          ],
        ),
      ],
    );
  }

  Future<void> _showTaskEditor(
    BuildContext context, {
    HermesTask? task,
    HermesTask? parentTask,
  }) async {
    var savedInsideEditor = false;
    final result = await _showTitleTimeEditor(
      context,
      title: parentTask != null
          ? 'New sub-task'
          : task == null
          ? 'New task'
          : 'Edit task',
      titleLabel: 'Task title',
      timeLabel: 'Due date',
      initialTitle: task?.title ?? '',
      initialTime: _formatCalendarEventDateTime(task?.dueAt),
      editorIcon: Icons.task_alt_rounded,
      editorSubtitle: parentTask != null
          ? 'Assigned to ${parentTask.title}'
          : 'Keep the task lightweight, dated, and organized',
      primarySectionTitle: 'Task basics',
      primarySectionSubtitle: 'Title and optional due date',
      initialNotes: task?.notes ?? '',
      allowEmptyTime: true,
      showNotes: true,
      categories: eventCategories,
      initialCategory: task?.category,
      initialColor: task?.color,
      initialCritical: task?.isCritical ?? false,
      deleteLabel: task == null ? null : 'Delete task',
      showRecurrence: true,
      recurrenceTitle: 'Task recurrence',
      recurrenceSubtitle: 'Repeat this task when needed.',
      recurrenceInfoTitle: 'Task recurrence',
      initialMetadata: task?.metadata,
      onEventCategorySaved: onEventCategorySaved,
      workspaces: user.workspaces,
      activeWorkspaceId: user.activeWorkspace?.id,
      showPrimaryWorkspaceSelector: task == null,
      initialPrimaryWorkspaceId: task == null
          ? (user.activeWorkspace == null
                ? null
                : _workspaceValue(user.activeWorkspace!))
          : null,
      googleCalendarStatus: googleCalendarStatus,
      initialGoogleCalendarIds: task?.googleCalendarIds ?? const [],
      initialSyncWorkspaceIds: task == null
          ? const []
          : _initialSyncWorkspaceIds(
              linkedWorkspaceIds: task.linkedWorkspaceIds,
              workspaceId: task.workspaceId,
              activeWorkspaceId: user.activeWorkspace?.id,
            ),
      onSave: (result) async {
        final title = (result['title'] as String).trim();
        if (title.isEmpty) return;
        await onTaskSaved(
          task,
          title: title,
          dueAt: result['time'] as String?,
          notes: result['notes'] as String?,
          category: result['category'] as String?,
          color: result['color'] as String?,
          isCritical: result['isCritical'] as bool?,
          parentTaskId: parentTask?.id,
          workspaceId: result['workspaceId'] as int?,
          recurrenceMetadata:
              result['recurrenceMetadata'] as Map<String, Object?>?,
          syncToWorkspaceIds:
              (result['syncToWorkspaceIds'] as List?)
                  ?.whereType<Object>()
                  .toList() ??
              const [],
          googleCalendarIds:
              (result['googleCalendarIds'] as List?)
                  ?.map((value) => value.toString())
                  .toList() ??
              const [],
        );
        savedInsideEditor = true;
      },
    );
    if (result == null || !context.mounted) return;
    if (result['delete'] == true && task != null) {
      final deleteFromWorkspaceIds = await _confirmWorkspaceDeleteSelection(
        context,
        itemTitle: task.title,
        itemType: 'task',
        workspaces: user.workspaces,
        activeWorkspaceId: user.activeWorkspace?.id,
        workspaceId: task.workspaceId,
        linkedWorkspaceIds: task.linkedWorkspaceIds,
      );
      if (!context.mounted || deleteFromWorkspaceIds == null) return;
      await onTaskDeleted(task, deleteFromWorkspaceIds: deleteFromWorkspaceIds);
      return;
    }
    if (savedInsideEditor) return;
    final title = (result['title'] as String).trim();
    if (title.isEmpty) return;
    await onTaskSaved(
      task,
      title: title,
      dueAt: result['time'] as String?,
      notes: result['notes'] as String?,
      category: result['category'] as String?,
      color: result['color'] as String?,
      isCritical: result['isCritical'] as bool?,
      parentTaskId: parentTask?.id,
      workspaceId: result['workspaceId'] as int?,
      recurrenceMetadata: result['recurrenceMetadata'] as Map<String, Object?>?,
      syncToWorkspaceIds:
          (result['syncToWorkspaceIds'] as List?)
              ?.whereType<Object>()
              .toList() ??
          const [],
      googleCalendarIds:
          (result['googleCalendarIds'] as List?)
              ?.map((value) => value.toString())
              .toList() ??
          const [],
    );
  }
}

class _CriticalTaskBadge extends StatelessWidget {
  const _CriticalTaskBadge({
    required this.tasks,
    required this.reminders,
    required this.events,
  });

  final List<HermesTask> tasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> events;

  int get count => tasks.length + reminders.length + events.length;

  @override
  Widget build(BuildContext context) => PopupMenuButton<void>(
    key: const Key('critical-task-count-menu'),
    tooltip: 'Critical items',
    position: PopupMenuPosition.under,
    offset: const Offset(0, 8),
    itemBuilder: (context) => [
      PopupMenuItem<void>(
        enabled: false,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 320, minWidth: 260),
          child: Column(
            key: const Key('critical-task-dropdown'),
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              if (count == 0)
                const _CriticalDropdownRow(
                  icon: Icons.check_circle_outline_rounded,
                  title: 'Nothing critical today',
                  subtitle: '',
                )
              else ...[
                for (final task in tasks)
                  _CriticalDropdownRow(
                    key: Key('critical-task-item-${task.id}'),
                    icon: Icons.checklist_rounded,
                    title: task.title,
                    subtitle: _taskSubtitle(task),
                  ),
                for (final reminder in reminders)
                  _CriticalDropdownRow(
                    key: Key('critical-reminder-item-${reminder.id}'),
                    icon: Icons.notifications_active_rounded,
                    title: reminder.title,
                    subtitle: _reminderSubtitle(reminder),
                  ),
                for (final event in events)
                  _CriticalDropdownRow(
                    key: Key('critical-event-item-${event.id}'),
                    icon: Icons.event_rounded,
                    title: event.title,
                    subtitle: _eventSubtitle(event),
                  ),
              ],
            ],
          ),
        ),
      ),
    ],
    child: Container(
      key: const Key('critical-task-count'),
      width: 40,
      height: 40,
      alignment: Alignment.center,
      child: Text(
        '$count',
        style: TextStyle(
          color: HeyBeanTheme.accentStrong,
          fontSize: 24,
          fontWeight: FontWeight.w900,
        ),
      ),
    ),
  );
}

class _CriticalDropdownRow extends StatelessWidget {
  const _CriticalDropdownRow({
    super.key,
    required this.icon,
    required this.title,
    required this.subtitle,
  });

  final IconData icon;
  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 8),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 18, color: HeyBeanTheme.accent),
        const SizedBox(width: 8),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  color: HeyBeanTheme.text,
                  fontWeight: FontWeight.w800,
                ),
              ),
              if (subtitle.isNotEmpty)
                Text(
                  subtitle,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: HeyBeanTheme.muted,
                    fontSize: 12,
                  ),
                ),
            ],
          ),
        ),
      ],
    ),
  );
}

const _calendarStartHourPreferenceKey = 'calendar_start_hour';
const _calendarEndHourPreferenceKey = 'calendar_end_hour';
const _defaultCalendarStartHour = 7;
const _defaultCalendarEndHour = 22;
const _calendarHourHeight = 80.0;
const _calendarTimeColumnWidth = 48.0;
const _calendarDayHeaderHeight = 36.0;
const _calendarMultiDayRowHeight = 42.0;
const _calendarAllDayRowHeight = 42.0;
const _calendarCurrentTimeLabelHeight = 14.0;

class _AppleStyleTodayTimeline extends StatefulWidget {
  const _AppleStyleTodayTimeline({
    required this.calendar,
    required this.eventCategories,
    required this.googleCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
    required this.selectedDay,
    required this.startHour,
    required this.endHour,
    this.minimumDay,
    this.onHistoryLimitReached,
    required this.onDayChanged,
    required this.onEventTap,
    required this.onEventDeleted,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final List<HermesCalendarEvent> calendar;
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
  final DateTime selectedDay;
  final int startHour;
  final int endHour;
  final DateTime? minimumDay;
  final VoidCallback? onHistoryLimitReached;
  final ValueChanged<DateTime> onDayChanged;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool? isCritical,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
    int? workspaceId,
    List<Object> syncToWorkspaceIds,
  })
  onEventTap;
  final Future<void> Function(
    HermesCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventDeleted;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    HermesEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;

  @override
  State<_AppleStyleTodayTimeline> createState() =>
      _AppleStyleTodayTimelineState();
}

class _AppleStyleTodayTimelineState extends State<_AppleStyleTodayTimeline> {
  static const int _initialDayPage = 10000;
  static const int _daysPerTimelinePage = 2;

  late final PageController _dayPageController;
  late final ScrollController _timelineScrollController;
  late DateTime _pageAnchorDay;
  int _visibleDayOffset = 0;
  String? _autoScrolledCurrentTimeDayKey;

  @override
  void initState() {
    super.initState();
    _pageAnchorDay = _dateOnly(widget.selectedDay);
    _dayPageController = PageController(
      initialPage: _initialDayPage,
      keepPage: false,
    );
    _dayPageController.addListener(_syncVisibleDayOffsetFromPage);
    _timelineScrollController = ScrollController();
  }

  @override
  void didUpdateWidget(covariant _AppleStyleTodayTimeline oldWidget) {
    super.didUpdateWidget(oldWidget);
    final selectedDay = _dateOnly(widget.selectedDay);
    if (_sameCalendarDay(selectedDay, _dateOnly(oldWidget.selectedDay))) {
      return;
    }
    final visiblePage = _dayPageController.hasClients
        ? _dayPageController.page?.round() ?? _initialDayPage
        : _initialDayPage;
    final visibleDay = _dateForPage(visiblePage);

    if (!_sameCalendarDay(selectedDay, visibleDay)) {
      _pageAnchorDay = selectedDay;
      _visibleDayOffset = 0;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted || !_dayPageController.hasClients) return;
        _dayPageController.jumpToPage(_initialDayPage);
      });
    }
  }

  @override
  void dispose() {
    _dayPageController.removeListener(_syncVisibleDayOffsetFromPage);
    _dayPageController.dispose();
    _timelineScrollController.dispose();
    super.dispose();
  }

  DateTime _dateForPage(int page) => _pageAnchorDay.add(
    Duration(days: (page - _initialDayPage) * _daysPerTimelinePage),
  );

  DateTime _dateForDayOffset(int dayOffset) =>
      _pageAnchorDay.add(Duration(days: dayOffset));

  DateTime? get _minimumDay =>
      widget.minimumDay == null ? null : _dateOnly(widget.minimumDay!);

  bool _isBeforeMinimumDay(DateTime date) {
    final minimumDay = _minimumDay;
    return minimumDay != null && _dateOnly(date).isBefore(minimumDay);
  }

  void _syncVisibleDayOffsetFromPage() {
    if (!_dayPageController.hasClients) return;
    final page = _dayPageController.page ?? _initialDayPage.toDouble();
    final nextOffset = ((page - _initialDayPage) * _daysPerTimelinePage)
        .round();
    if (nextOffset == _visibleDayOffset) return;
    setState(() => _visibleDayOffset = nextOffset);
  }

  void _handlePageChanged(int page) {
    final nextOffset = (page - _initialDayPage) * _daysPerTimelinePage;
    final nextSelectedDay = _dateForPage(page);
    if (_isBeforeMinimumDay(nextSelectedDay)) {
      final minimumDay = _minimumDay!;
      widget.onHistoryLimitReached?.call();
      if (!_sameCalendarDay(minimumDay, widget.selectedDay)) {
        widget.onDayChanged(minimumDay);
      }
      _pageAnchorDay = minimumDay;
      if (_visibleDayOffset != 0) {
        setState(() => _visibleDayOffset = 0);
      }
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted || !_dayPageController.hasClients) return;
        _dayPageController.jumpToPage(_initialDayPage);
      });
      return;
    }
    if (nextOffset != _visibleDayOffset) {
      setState(() => _visibleDayOffset = nextOffset);
    }
    if (!_sameCalendarDay(nextSelectedDay, widget.selectedDay)) {
      widget.onDayChanged(nextSelectedDay);
    }
  }

  void _scheduleInitialCurrentTimeScroll({
    required bool showCurrentTimeMarker,
    required double markerOffset,
    required double viewportHeight,
    required double timelineHeight,
  }) {
    if (!showCurrentTimeMarker) return;
    final selectedDayKey = _dateOnly(widget.selectedDay).toIso8601String();
    if (_autoScrolledCurrentTimeDayKey == selectedDayKey) return;
    _autoScrolledCurrentTimeDayKey = selectedDayKey;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || !_timelineScrollController.hasClients) return;
      final maxScrollExtent =
          _timelineScrollController.position.maxScrollExtent;
      final targetOffset = (markerOffset - (viewportHeight / 2))
          .clamp(0.0, math.max(0.0, math.min(maxScrollExtent, timelineHeight)))
          .toDouble();
      _timelineScrollController.jumpTo(targetOffset);
    });
  }

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    final today = _dateOnly(now);
    final visibleStartDay = _dateOnly(_dateForDayOffset(_visibleDayOffset));
    final visibleNextDay = visibleStartDay.add(const Duration(days: 1));
    final showMultiDayRow = widget.calendar.any(
      (event) =>
          _eventIsTimedMultiDay(event) &&
          (_eventFallsOnDay(event, visibleStartDay) ||
              _eventFallsOnDay(event, visibleNextDay)),
    );
    final visibleHours = _calendarVisibleHoursForEvents(
      widget.calendar,
      visibleStartDay,
      widget.startHour,
      widget.endHour,
    );
    final timelineContentHeight =
        (showMultiDayRow ? _calendarMultiDayRowHeight : 0) +
        _calendarAllDayRowHeight +
        (visibleHours.length * _calendarHourHeight);
    final timelineHeight = 1 + timelineContentHeight;
    final markerOffset =
        (showMultiDayRow ? _calendarMultiDayRowHeight : 0) +
        _calendarAllDayRowHeight +
        ((now.hour + (now.minute / 60)) - visibleHours.first).clamp(
              0.0,
              visibleHours.length.toDouble(),
            ) *
            _calendarHourHeight;
    final currentTimeLabelTop = markerOffset
        .clamp(
          0.0,
          math.max(0.0, timelineHeight - _calendarCurrentTimeLabelHeight - 1),
        )
        .toDouble();
    final showCurrentTimeMarker =
        _sameCalendarDay(visibleStartDay, today) ||
        _sameCalendarDay(visibleNextDay, today);
    final timelineViewportHeight = math.min(
      timelineHeight,
      math.max(
        250.0,
        MediaQuery.sizeOf(context).height - 360 - _calendarDayHeaderHeight,
      ),
    );
    _scheduleInitialCurrentTimeScroll(
      showCurrentTimeMarker: showCurrentTimeMarker,
      markerOffset: markerOffset,
      viewportHeight: timelineViewportHeight,
      timelineHeight: timelineHeight,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _ScrollableTimelineDayHeader(
          pageController: _dayPageController,
          initialDayPage: _initialDayPage,
          pageAnchorDay: _pageAnchorDay,
          today: today,
        ),
        SizedBox(
          height: timelineViewportHeight,
          child: SingleChildScrollView(
            key: const Key('apple-style-day-timeline-scroll'),
            controller: _timelineScrollController,
            child: Container(
              key: const Key('apple-style-day-timeline'),
              decoration: const BoxDecoration(
                border: Border(top: BorderSide(color: HeyBeanTheme.border)),
              ),
              height: timelineHeight,
              child: Stack(
                clipBehavior: Clip.none,
                children: [
                  SizedBox(
                    height: timelineContentHeight,
                    child: Row(
                      children: [
                        _FixedTimelineHoursColumn(
                          visibleHours: visibleHours,
                          showMultiDayRow: showMultiDayRow,
                        ),
                        Expanded(
                          child: Column(
                            children: [
                              if (showMultiDayRow)
                                SizedBox(
                                  height: _calendarMultiDayRowHeight,
                                  child: _MultiDayEventSpanRow(
                                    key: Key(
                                      'calendar-multi-day-row-${visibleStartDay.toIso8601String()}',
                                    ),
                                    pageController: _dayPageController,
                                    initialDayPage: _initialDayPage,
                                    pageAnchorDay: _pageAnchorDay,
                                    events: widget.calendar
                                        .where(
                                          (event) =>
                                              _eventIsTimedMultiDay(event),
                                        )
                                        .toList(),
                                    eventCategories: widget.eventCategories,
                                    googleCalendarStatus:
                                        widget.googleCalendarStatus,
                                    workspaces: widget.workspaces,
                                    activeWorkspaceId: widget.activeWorkspaceId,
                                    onEventTap: widget.onEventTap,
                                    onEventDeleted: widget.onEventDeleted,
                                    onEventCategorySaved:
                                        widget.onEventCategorySaved,
                                    onEventCategoryDeleted:
                                        widget.onEventCategoryDeleted,
                                  ),
                                ),
                              Expanded(
                                child: PageView.builder(
                                  key: const PageStorageKey<String>(
                                    'apple-style-day-page-view',
                                  ),
                                  controller: _dayPageController,
                                  pageSnapping: false,
                                  physics: const BouncingScrollPhysics(),
                                  allowImplicitScrolling: true,
                                  onPageChanged: _handlePageChanged,
                                  itemBuilder: (context, page) =>
                                      _TwoDayTimelinePage(
                                        key: ValueKey(
                                          'two-day-timeline-page-$page',
                                        ),
                                        calendar: widget.calendar,
                                        eventCategories: widget.eventCategories,
                                        googleCalendarStatus:
                                            widget.googleCalendarStatus,
                                        workspaces: widget.workspaces,
                                        activeWorkspaceId:
                                            widget.activeWorkspaceId,
                                        selectedDay: _dateForPage(page),
                                        startHour: visibleHours.first,
                                        endHour: visibleHours.last,
                                        visibleHours: visibleHours,
                                        onEventTap: widget.onEventTap,
                                        onEventDeleted: widget.onEventDeleted,
                                        onEventCategorySaved:
                                            widget.onEventCategorySaved,
                                        onEventCategoryDeleted:
                                            widget.onEventCategoryDeleted,
                                      ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                  if (showCurrentTimeMarker) ...[
                    Positioned(
                      key: const Key('calendar-current-time-marker'),
                      top: markerOffset,
                      left: 0,
                      right: 0,
                      child: Row(
                        children: [
                          const SizedBox(width: _calendarTimeColumnWidth + 4),
                          Expanded(
                            child: Container(
                              height: 2,
                              color: HeyBeanTheme.accent,
                            ),
                          ),
                        ],
                      ),
                    ),
                    Positioned(
                      top: currentTimeLabelTop,
                      left: 0,
                      width: _calendarTimeColumnWidth,
                      height: _calendarCurrentTimeLabelHeight,
                      child: Align(
                        alignment: Alignment.centerRight,
                        child: Container(
                          key: const Key('calendar-current-time-label'),
                          height: _calendarCurrentTimeLabelHeight,
                          margin: const EdgeInsets.only(right: 3),
                          padding: const EdgeInsets.symmetric(horizontal: 4),
                          decoration: BoxDecoration(
                            color: HeyBeanTheme.accent,
                            borderRadius: BorderRadius.circular(999),
                          ),
                          child: FittedBox(
                            fit: BoxFit.scaleDown,
                            child: Text(
                              _naturalTimeLabel(now),
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 9,
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _TwoDayTimelinePage extends StatelessWidget {
  const _TwoDayTimelinePage({
    super.key,
    required this.calendar,
    required this.eventCategories,
    required this.googleCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
    required this.selectedDay,
    required this.startHour,
    required this.endHour,
    required this.visibleHours,
    required this.onEventTap,
    required this.onEventDeleted,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final List<HermesCalendarEvent> calendar;
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
  final DateTime selectedDay;
  final int startHour;
  final int endHour;
  final List<int> visibleHours;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool? isCritical,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
    int? workspaceId,
    List<Object> syncToWorkspaceIds,
  })
  onEventTap;
  final Future<void> Function(
    HermesCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventDeleted;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    HermesEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) {
    final selectedNextDay = selectedDay.add(const Duration(days: 1));
    final selectedAllDayEvents = calendar
        .where(
          (event) =>
              _eventIsAllDay(event) && _eventFallsOnDay(event, selectedDay),
        )
        .toList();
    final nextAllDayEvents = calendar
        .where(
          (event) =>
              _eventIsAllDay(event) && _eventFallsOnDay(event, selectedNextDay),
        )
        .toList();
    final selectedTimedEventLayouts = _timelineEventLayoutsForDay(
      calendar,
      selectedDay,
      startHour,
      endHour,
    );
    final nextTimedEventLayouts = _timelineEventLayoutsForDay(
      calendar,
      selectedNextDay,
      startHour,
      endHour,
    );

    return Column(
      children: [
        SizedBox(
          height: _calendarAllDayRowHeight,
          child: Row(
            children: [
              Expanded(
                child: _AllDayEventRow(
                  key: Key(
                    'calendar-all-day-row-${selectedDay.toIso8601String()}',
                  ),
                  day: selectedDay,
                  events: selectedAllDayEvents,
                  eventCategories: eventCategories,
                  googleCalendarStatus: googleCalendarStatus,
                  workspaces: workspaces,
                  activeWorkspaceId: activeWorkspaceId,
                  onEventTap: onEventTap,
                  onEventDeleted: onEventDeleted,
                  onEventCategorySaved: onEventCategorySaved,
                  onEventCategoryDeleted: onEventCategoryDeleted,
                ),
              ),
              Expanded(
                child: _AllDayEventRow(
                  key: Key(
                    'calendar-all-day-row-${selectedNextDay.toIso8601String()}',
                  ),
                  day: selectedNextDay,
                  events: nextAllDayEvents,
                  eventCategories: eventCategories,
                  googleCalendarStatus: googleCalendarStatus,
                  workspaces: workspaces,
                  activeWorkspaceId: activeWorkspaceId,
                  onEventTap: onEventTap,
                  onEventDeleted: onEventDeleted,
                  onEventCategorySaved: onEventCategorySaved,
                  onEventCategoryDeleted: onEventCategoryDeleted,
                ),
              ),
            ],
          ),
        ),
        Expanded(
          child: LayoutBuilder(
            builder: (context, constraints) => Stack(
              clipBehavior: Clip.none,
              children: [
                Column(
                  children: [
                    for (var index = 0; index < visibleHours.length; index++)
                      const _TimelineDayGridRow(),
                  ],
                ),
                for (final layout in selectedTimedEventLayouts)
                  _TimelineEventBlock(
                    event: layout.event,
                    day: selectedDay,
                    startHour: startHour,
                    endHour: endHour,
                    columnIndex: 0,
                    laneIndex: layout.laneIndex,
                    laneCount: layout.laneCount,
                    timelineWidth: constraints.maxWidth,
                    eventCategories: eventCategories,
                    googleCalendarStatus: googleCalendarStatus,
                    workspaces: workspaces,
                    activeWorkspaceId: activeWorkspaceId,
                    onTap: onEventTap,
                    onDelete: onEventDeleted,
                    onEventCategorySaved: onEventCategorySaved,
                    onEventCategoryDeleted: onEventCategoryDeleted,
                  ),
                for (final layout in nextTimedEventLayouts)
                  _TimelineEventBlock(
                    event: layout.event,
                    day: selectedNextDay,
                    startHour: startHour,
                    endHour: endHour,
                    columnIndex: 1,
                    laneIndex: layout.laneIndex,
                    laneCount: layout.laneCount,
                    timelineWidth: constraints.maxWidth,
                    eventCategories: eventCategories,
                    googleCalendarStatus: googleCalendarStatus,
                    workspaces: workspaces,
                    activeWorkspaceId: activeWorkspaceId,
                    onTap: onEventTap,
                    onDelete: onEventDeleted,
                    onEventCategorySaved: onEventCategorySaved,
                    onEventCategoryDeleted: onEventCategoryDeleted,
                  ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _CalendarHeaderButton extends StatelessWidget {
  const _CalendarHeaderButton({
    super.key,
    required this.label,
    required this.icon,
    required this.onTap,
    this.horizontalPadding = 12,
    this.verticalPadding = 8,
    this.labelStyle = const TextStyle(fontWeight: FontWeight.w800),
  });

  final String label;
  final IconData? icon;
  final VoidCallback onTap;
  final double horizontalPadding;
  final double verticalPadding;
  final TextStyle labelStyle;

  @override
  Widget build(BuildContext context) => InkWell(
    borderRadius: BorderRadius.circular(22),
    onTap: onTap,
    child: ConstrainedBox(
      constraints: const BoxConstraints(minWidth: 0),
      child: Container(
        padding: EdgeInsets.symmetric(
          horizontal: horizontalPadding,
          vertical: verticalPadding,
        ),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(22),
          border: Border.all(color: HeyBeanTheme.border),
          boxShadow: const [
            BoxShadow(
              color: Color(0x12000000),
              blurRadius: 14,
              offset: Offset(0, 6),
            ),
          ],
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (icon != null) ...[
              const SizedBox(width: 4),
              Icon(icon, size: 16),
              const SizedBox(width: 4),
            ],
            Flexible(
              child: Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: labelStyle,
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _ScrollableTimelineDayHeader extends StatelessWidget {
  const _ScrollableTimelineDayHeader({
    required this.pageController,
    required this.initialDayPage,
    required this.pageAnchorDay,
    required this.today,
  });

  final PageController pageController;
  final int initialDayPage;
  final DateTime pageAnchorDay;
  final DateTime today;

  @override
  Widget build(BuildContext context) {
    return Container(
      key: const Key('calendar-sticky-day-header'),
      height: _calendarDayHeaderHeight,
      decoration: const BoxDecoration(
        border: Border(top: BorderSide(color: HeyBeanTheme.border)),
      ),
      child: Row(
        children: [
          Container(
            width: _calendarTimeColumnWidth,
            height: _calendarDayHeaderHeight,
            decoration: const BoxDecoration(
              border: Border(bottom: BorderSide(color: HeyBeanTheme.border)),
            ),
          ),
          Expanded(
            child: ClipRect(
              child: LayoutBuilder(
                builder: (context, constraints) {
                  return AnimatedBuilder(
                    animation: pageController,
                    builder: (context, _) {
                      final dayOffset = _timelineDayOffset(
                        pageController,
                        initialDayPage,
                      );
                      final columnWidth = constraints.maxWidth / 2;
                      final firstRenderedDayOffset = dayOffset.floor() - 1;
                      final activeDayOffset = dayOffset.round();
                      return Stack(
                        children: [
                          for (
                            var dayOffsetIndex = firstRenderedDayOffset;
                            dayOffsetIndex <= firstRenderedDayOffset + 4;
                            dayOffsetIndex++
                          )
                            Positioned(
                              left: (dayOffsetIndex - dayOffset) * columnWidth,
                              top: 0,
                              bottom: 0,
                              width: columnWidth,
                              child: _DayColumnHeading(
                                key: dayOffsetIndex == activeDayOffset
                                    ? const Key('day-column-heading-selected')
                                    : dayOffsetIndex == activeDayOffset + 1
                                    ? const Key('day-column-heading-next')
                                    : null,
                                date: pageAnchorDay.add(
                                  Duration(days: dayOffsetIndex),
                                ),
                                isToday: _sameCalendarDay(
                                  pageAnchorDay.add(
                                    Duration(days: dayOffsetIndex),
                                  ),
                                  today,
                                ),
                              ),
                            ),
                        ],
                      );
                    },
                  );
                },
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _DayColumnHeading extends StatelessWidget {
  const _DayColumnHeading({
    super.key,
    required this.date,
    required this.isToday,
  });

  final DateTime date;
  final bool isToday;

  @override
  Widget build(BuildContext context) => Container(
    height: _calendarDayHeaderHeight,
    alignment: Alignment.center,
    decoration: const BoxDecoration(
      border: Border(
        left: BorderSide(color: HeyBeanTheme.border),
        bottom: BorderSide(color: HeyBeanTheme.border),
      ),
    ),
    child: Text(
      '${_shortWeekdayName(date.weekday)} — ${_monthName(date.month)} ${date.day}',
      style: TextStyle(
        color: isToday ? HeyBeanTheme.accentStrong : HeyBeanTheme.text,
        fontWeight: FontWeight.w800,
      ),
    ),
  );
}

double _timelineDayOffset(PageController controller, int initialDayPage) {
  final page = controller.hasClients
      ? controller.page ?? initialDayPage.toDouble()
      : initialDayPage.toDouble();
  return (page - initialDayPage) * 2;
}

class _FixedTimelineHoursColumn extends StatelessWidget {
  const _FixedTimelineHoursColumn({
    required this.visibleHours,
    required this.showMultiDayRow,
  });

  final List<int> visibleHours;
  final bool showMultiDayRow;

  @override
  Widget build(BuildContext context) => SizedBox(
    key: const Key('calendar-fixed-hours-column'),
    width: _calendarTimeColumnWidth,
    child: Column(
      children: [
        if (showMultiDayRow)
          SizedBox(
            key: const Key('calendar-multi-day-label'),
            height: _calendarMultiDayRowHeight,
            child: const Padding(
              padding: EdgeInsets.only(top: 10, right: 6),
              child: Align(
                alignment: Alignment.topRight,
                child: Text(
                  'Multi-Day',
                  textAlign: TextAlign.right,
                  style: TextStyle(
                    color: HeyBeanTheme.muted,
                    fontSize: 11,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ),
          ),
        SizedBox(
          key: const Key('calendar-all-day-label'),
          height: _calendarAllDayRowHeight,
          child: const Padding(
            padding: EdgeInsets.only(top: 10, right: 6),
            child: Align(
              alignment: Alignment.topRight,
              child: Text(
                'All Day',
                textAlign: TextAlign.right,
                style: TextStyle(
                  color: HeyBeanTheme.muted,
                  fontSize: 11,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
          ),
        ),
        for (final hour in visibleHours)
          SizedBox(
            height: _calendarHourHeight,
            child: Padding(
              padding: const EdgeInsets.only(top: 4, right: 6),
              child: Align(
                alignment: Alignment.topRight,
                child: Text(
                  _hourLabel(hour),
                  textAlign: TextAlign.right,
                  style: const TextStyle(
                    color: HeyBeanTheme.muted,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ),
          ),
      ],
    ),
  );
}

class _TimelineDayGridRow extends StatelessWidget {
  const _TimelineDayGridRow();

  @override
  Widget build(BuildContext context) => SizedBox(
    height: _calendarHourHeight,
    child: Row(
      children: [
        for (var column = 0; column < 2; column++)
          Expanded(
            child: Container(
              decoration: const BoxDecoration(
                border: Border(
                  top: BorderSide(color: HeyBeanTheme.border),
                  left: BorderSide(color: HeyBeanTheme.border),
                ),
              ),
            ),
          ),
      ],
    ),
  );
}

class _MultiDayEventSpanRow extends StatelessWidget {
  const _MultiDayEventSpanRow({
    super.key,
    required this.pageController,
    required this.initialDayPage,
    required this.pageAnchorDay,
    required this.events,
    required this.eventCategories,
    required this.googleCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
    required this.onEventTap,
    required this.onEventDeleted,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final PageController pageController;
  final int initialDayPage;
  final DateTime pageAnchorDay;
  final List<HermesCalendarEvent> events;
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool? isCritical,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
    int? workspaceId,
    List<Object> syncToWorkspaceIds,
  })
  onEventTap;
  final Future<void> Function(
    HermesCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventDeleted;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    HermesEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) => Container(
    decoration: BoxDecoration(
      color: HeyBeanTheme.accent.withValues(alpha: .06),
      border: Border(
        left: BorderSide(color: HeyBeanTheme.border),
        bottom: BorderSide(color: HeyBeanTheme.border),
      ),
    ),
    child: LayoutBuilder(
      builder: (context, constraints) {
        final columnWidth = constraints.maxWidth / 2;
        return ClipRect(
          child: AnimatedBuilder(
            animation: pageController,
            builder: (context, _) {
              final dayOffset = _timelineDayOffset(
                pageController,
                initialDayPage,
              );
              final firstRenderedDayOffset = dayOffset.floor() - 1;
              final lastRenderedDayOffset = firstRenderedDayOffset + 4;
              return Stack(
                children: [
                  for (
                    var dayOffsetIndex = firstRenderedDayOffset + 1;
                    dayOffsetIndex <= lastRenderedDayOffset;
                    dayOffsetIndex++
                  )
                    Positioned(
                      left: (dayOffsetIndex - dayOffset) * columnWidth,
                      top: 0,
                      bottom: 0,
                      child: const VerticalDivider(
                        width: 1,
                        thickness: 1,
                        color: HeyBeanTheme.border,
                      ),
                    ),
                  for (final event in events)
                    Builder(
                      builder: (context) {
                        final startDay = _multiDayEventStartDay(event);
                        final endDay = _multiDayEventEndDay(event);
                        if (startDay == null || endDay == null) {
                          return const SizedBox.shrink();
                        }
                        final startOffset = startDay
                            .difference(pageAnchorDay)
                            .inDays;
                        final endOffset = endDay
                            .difference(pageAnchorDay)
                            .inDays;
                        if (endOffset < firstRenderedDayOffset ||
                            startOffset > lastRenderedDayOffset) {
                          return const SizedBox.shrink();
                        }
                        final daySpan = endOffset - startOffset + 1;
                        return Positioned(
                          left: ((startOffset - dayOffset) * columnWidth) + 6,
                          top: 6,
                          width: math.max(0.0, (daySpan * columnWidth) - 12),
                          height: 30,
                          child: _MultiDayEventSpan(
                            event: event,
                            startDay: startDay,
                            daySpan: daySpan,
                            columnWidth: columnWidth,
                            eventCategories: eventCategories,
                            googleCalendarStatus: googleCalendarStatus,
                            workspaces: workspaces,
                            activeWorkspaceId: activeWorkspaceId,
                            onEventTap: onEventTap,
                            onEventDeleted: onEventDeleted,
                            onEventCategorySaved: onEventCategorySaved,
                            onEventCategoryDeleted: onEventCategoryDeleted,
                          ),
                        );
                      },
                    ),
                ],
              );
            },
          ),
        );
      },
    ),
  );
}

class _MultiDayEventSpan extends StatelessWidget {
  const _MultiDayEventSpan({
    required this.event,
    required this.startDay,
    required this.daySpan,
    required this.columnWidth,
    required this.eventCategories,
    required this.googleCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
    required this.onEventTap,
    required this.onEventDeleted,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final HermesCalendarEvent event;
  final DateTime startDay;
  final int daySpan;
  final double columnWidth;
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool? isCritical,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
    int? workspaceId,
    List<Object> syncToWorkspaceIds,
  })
  onEventTap;
  final Future<void> Function(
    HermesCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventDeleted;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    HermesEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) {
    final color = _calendarEventColor(event);
    return InkWell(
      key: Key('calendar-multi-day-event-${event.id}'),
      borderRadius: BorderRadius.circular(12),
      onTap: () => _showCalendarEventDetails(
        context,
        event,
        eventCategories: eventCategories,
        googleCalendarStatus: googleCalendarStatus,
        workspaces: workspaces,
        activeWorkspaceId: activeWorkspaceId,
        onSave:
            (
              savedEvent, {
              required String title,
              required String startsAt,
              String? endsAt,
              String? notes,
              String? location,
              String? status,
              String? category,
              String? color,
              String? recurrence,
              Map<String, Object?>? metadata,
              bool? isCritical,
              int? reminderMinutesBefore,
              String? reminderRecurrence,
              List<String>? reminderSpecificDays,
              int? reminderInterval,
              String? reminderIntervalUnit,
              int? workspaceId,
              List<Object> syncToWorkspaceIds = const [],
            }) => onEventTap(
              savedEvent,
              title: title,
              startsAt: startsAt,
              endsAt: endsAt,
              notes: notes,
              location: location,
              status: status,
              category: category,
              color: color,
              recurrence: recurrence,
              metadata: metadata,
              isCritical: isCritical,
              reminderMinutesBefore: reminderMinutesBefore,
              reminderRecurrence: reminderRecurrence,
              reminderSpecificDays: reminderSpecificDays,
              reminderInterval: reminderInterval,
              reminderIntervalUnit: reminderIntervalUnit,
              syncToWorkspaceIds: syncToWorkspaceIds,
            ),
        onCriticalChanged: (savedEvent, isCritical) => onEventTap(
          savedEvent,
          title: savedEvent.title,
          startsAt:
              savedEvent.startsAt ?? DateTime.now().toUtc().toIso8601String(),
          endsAt: savedEvent.endsAt,
          notes: savedEvent.notes,
          location: savedEvent.location,
          status: savedEvent.status,
          category: savedEvent.category,
          color: savedEvent.color,
          recurrence: savedEvent.recurrence,
          metadata: savedEvent.metadata,
          isCritical: isCritical,
        ),
        onEventCategorySaved: onEventCategorySaved,
        onEventCategoryDeleted: onEventCategoryDeleted,
        onDelete: onEventDeleted,
      ),
      child: Container(
        decoration: BoxDecoration(
          color: color.withValues(alpha: .60),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: color.withValues(alpha: .35)),
        ),
        clipBehavior: Clip.hardEdge,
        child: Stack(
          children: [
            if (event.isCritical)
              Positioned(
                left: 8,
                top: 8,
                child: Icon(
                  Icons.star_rounded,
                  key: Key('event-critical-star-${event.id}'),
                  color: HeyBeanTheme.warning,
                  size: 14,
                ),
              ),
            for (var dayIndex = 0; dayIndex < daySpan; dayIndex++)
              Positioned(
                left: dayIndex == 0 ? 0 : (dayIndex * columnWidth) - 6,
                top: 0,
                bottom: 0,
                width: math.max(
                  0.0,
                  (dayIndex == daySpan - 1
                          ? (daySpan * columnWidth) - 12
                          : ((dayIndex + 1) * columnWidth) - 6) -
                      (dayIndex == 0 ? 0 : (dayIndex * columnWidth) - 6),
                ),
                child: Padding(
                  padding: EdgeInsets.only(
                    left: dayIndex == 0 && event.isCritical ? 26 : 10,
                    right: 10,
                  ),
                  child: Align(
                    alignment: dayIndex == daySpan - 1
                        ? Alignment.centerRight
                        : dayIndex == 0
                        ? Alignment.centerLeft
                        : Alignment.center,
                    child: Text(
                      _multiDayEventLabelForDay(
                        event,
                        startDay.add(Duration(days: dayIndex)),
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      textAlign: dayIndex == daySpan - 1
                          ? TextAlign.right
                          : dayIndex == 0
                          ? TextAlign.left
                          : TextAlign.center,
                      style: const TextStyle(
                        color: Colors.black,
                        fontSize: 12,
                        fontWeight: FontWeight.w800,
                        height: 1,
                      ),
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _AllDayEventRow extends StatelessWidget {
  const _AllDayEventRow({
    super.key,
    required this.day,
    required this.events,
    required this.eventCategories,
    required this.googleCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
    required this.onEventTap,
    required this.onEventDeleted,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final DateTime day;
  final List<HermesCalendarEvent> events;
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool? isCritical,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
    int? workspaceId,
    List<Object> syncToWorkspaceIds,
  })
  onEventTap;
  final Future<void> Function(
    HermesCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventDeleted;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    HermesEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) => Container(
    decoration: const BoxDecoration(
      border: Border(
        left: BorderSide(color: HeyBeanTheme.border),
        bottom: BorderSide(color: HeyBeanTheme.border),
      ),
    ),
    child: ListView.separated(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
      itemCount: events.length,
      separatorBuilder: (_, __) => const SizedBox(width: 6),
      itemBuilder: (context, index) {
        final event = events[index];
        final color = _calendarEventColor(event);
        return InkWell(
          key: Key('calendar-all-day-event-${event.id}'),
          borderRadius: BorderRadius.circular(12),
          onTap: () => _showCalendarEventDetails(
            context,
            event,
            occurrenceDate: _eventIsRecurring(event)
                ? _calendarDateKey(day)
                : null,
            eventCategories: eventCategories,
            googleCalendarStatus: googleCalendarStatus,
            workspaces: workspaces,
            activeWorkspaceId: activeWorkspaceId,
            onSave:
                (
                  savedEvent, {
                  required String title,
                  required String startsAt,
                  String? endsAt,
                  String? notes,
                  String? location,
                  String? status,
                  String? category,
                  String? color,
                  String? recurrence,
                  Map<String, Object?>? metadata,
                  bool? isCritical,
                  int? reminderMinutesBefore,
                  String? reminderRecurrence,
                  List<String>? reminderSpecificDays,
                  int? reminderInterval,
                  String? reminderIntervalUnit,
                  int? workspaceId,
                  List<Object> syncToWorkspaceIds = const [],
                }) => onEventTap(
                  savedEvent,
                  title: title,
                  startsAt: startsAt,
                  endsAt: endsAt,
                  notes: notes,
                  location: location,
                  status: status,
                  category: category,
                  color: color,
                  recurrence: recurrence,
                  metadata: metadata,
                  isCritical: isCritical,
                  reminderMinutesBefore: reminderMinutesBefore,
                  reminderRecurrence: reminderRecurrence,
                  reminderSpecificDays: reminderSpecificDays,
                  reminderInterval: reminderInterval,
                  reminderIntervalUnit: reminderIntervalUnit,
                  syncToWorkspaceIds: syncToWorkspaceIds,
                ),
            onCriticalChanged: (savedEvent, isCritical) => onEventTap(
              savedEvent,
              title: savedEvent.title,
              startsAt:
                  savedEvent.startsAt ??
                  DateTime.now().toUtc().toIso8601String(),
              endsAt: savedEvent.endsAt,
              notes: savedEvent.notes,
              location: savedEvent.location,
              status: savedEvent.status,
              category: savedEvent.category,
              color: savedEvent.color,
              recurrence: savedEvent.recurrence,
              metadata: savedEvent.metadata,
              isCritical: isCritical,
            ),
            onEventCategorySaved: onEventCategorySaved,
            onEventCategoryDeleted: onEventCategoryDeleted,
            onDelete: onEventDeleted,
          ),
          child: Container(
            constraints: const BoxConstraints(maxWidth: 180),
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: color.withValues(alpha: .60),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: color.withValues(alpha: .35)),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (event.isCritical) ...[
                  Icon(
                    Icons.star_rounded,
                    key: Key('event-critical-star-${event.id}'),
                    color: HeyBeanTheme.warning,
                    size: 14,
                  ),
                  const SizedBox(width: 4),
                ],
                Flexible(
                  child: Text(
                    event.title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: Colors.black,
                      fontSize: 12,
                      fontWeight: FontWeight.w800,
                      height: 1,
                    ),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    ),
  );
}

class _TimelineEventBlock extends StatelessWidget {
  const _TimelineEventBlock({
    required this.event,
    required this.day,
    required this.startHour,
    required this.endHour,
    required this.columnIndex,
    required this.laneIndex,
    required this.laneCount,
    required this.timelineWidth,
    required this.eventCategories,
    required this.googleCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
    required this.onTap,
    required this.onDelete,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final HermesCalendarEvent event;
  final DateTime day;
  final int startHour;
  final int endHour;
  final int columnIndex;
  final int laneIndex;
  final int laneCount;
  final double timelineWidth;
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool? isCritical,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
    int? workspaceId,
    List<Object> syncToWorkspaceIds,
  })
  onTap;
  final Future<void> Function(
    HermesCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onDelete;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    HermesEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) {
    final segment = _eventVisibleSegment(event, day, startHour, endHour);
    if (segment == null) return const SizedBox.shrink();
    final visibleStart = startHour.toDouble();
    final startDecimal = _decimalHoursFromDayStart(segment.start, day);
    final endDecimal = _decimalHoursFromDayStart(segment.end, day);
    final hourPosition = (startDecimal - visibleStart) * _calendarHourHeight;
    final eventHeight = ((endDecimal - startDecimal) * _calendarHourHeight - 4)
        .clamp(34.0, (endHour + 1 - startHour) * _calendarHourHeight);
    final dayColumnWidth = timelineWidth / 2;
    final normalizedLaneCount = math.max(1, laneCount);
    final normalizedLaneIndex = laneIndex.clamp(0, normalizedLaneCount - 1);
    final availableWidth = (dayColumnWidth - 4).clamp(0.0, double.infinity);
    final laneGap = normalizedLaneCount > 1 ? 2.0 : 0.0;
    final laneWidth = math.max(
      0.0,
      (availableWidth - (laneGap * (normalizedLaneCount - 1))) /
          normalizedLaneCount,
    );
    final left =
        (dayColumnWidth * columnIndex) +
        2 +
        ((laneWidth + laneGap) * normalizedLaneIndex);
    final width = laneWidth.clamp(0.0, double.infinity);
    final timeLabel = _eventTimeRangeShort(event);
    final compactEventBlock = eventHeight < 44;
    final titleFontSize = compactEventBlock ? 10.0 : 12.0;
    final timeFontSize = compactEventBlock ? 8.0 : 10.0;
    final eventPadding = EdgeInsets.symmetric(
      horizontal: compactEventBlock ? 6 : 8,
      vertical: compactEventBlock ? 2 : 4,
    );
    return Positioned(
      top: hourPosition + 2,
      left: left,
      width: width,
      child: InkWell(
        key: Key(_calendarEventBlockKeyForDay(event, day)),
        borderRadius: BorderRadius.circular(6),
        onTap: () => _showCalendarEventDetails(
          context,
          event,
          occurrenceDate: _eventIsRecurring(event)
              ? _calendarDateKey(day)
              : null,
          eventCategories: eventCategories,
          googleCalendarStatus: googleCalendarStatus,
          workspaces: workspaces,
          activeWorkspaceId: activeWorkspaceId,
          onSave:
              (
                savedEvent, {
                required String title,
                required String startsAt,
                String? endsAt,
                String? notes,
                String? location,
                String? status,
                String? category,
                String? color,
                String? recurrence,
                Map<String, Object?>? metadata,
                bool? isCritical,
                int? reminderMinutesBefore,
                String? reminderRecurrence,
                List<String>? reminderSpecificDays,
                int? reminderInterval,
                String? reminderIntervalUnit,
                int? workspaceId,
                List<Object> syncToWorkspaceIds = const [],
              }) => onTap(
                savedEvent,
                title: title,
                startsAt: startsAt,
                endsAt: endsAt,
                notes: notes,
                location: location,
                status: status,
                category: category,
                color: color,
                recurrence: recurrence,
                metadata: metadata,
                isCritical: isCritical,
                reminderMinutesBefore: reminderMinutesBefore,
                reminderRecurrence: reminderRecurrence,
                reminderSpecificDays: reminderSpecificDays,
                reminderInterval: reminderInterval,
                reminderIntervalUnit: reminderIntervalUnit,
                syncToWorkspaceIds: syncToWorkspaceIds,
              ),
          onCriticalChanged: (savedEvent, isCritical) => onTap(
            savedEvent,
            title: savedEvent.title,
            startsAt:
                savedEvent.startsAt ?? DateTime.now().toUtc().toIso8601String(),
            endsAt: savedEvent.endsAt,
            notes: savedEvent.notes,
            location: savedEvent.location,
            status: savedEvent.status,
            category: savedEvent.category,
            color: savedEvent.color,
            recurrence: savedEvent.recurrence,
            metadata: savedEvent.metadata,
            isCritical: isCritical,
          ),
          onEventCategorySaved: onEventCategorySaved,
          onEventCategoryDeleted: onEventCategoryDeleted,
          onDelete: onDelete,
        ),
        child: Container(
          height: eventHeight,
          padding: eventPadding,
          decoration: BoxDecoration(
            color: _calendarEventColor(event).withValues(alpha: .60),
            borderRadius: BorderRadius.circular(6),
            border: Border.all(
              color: _calendarEventColor(event).withValues(alpha: .35),
            ),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (event.isCritical) ...[
                Icon(
                  Icons.star_rounded,
                  key: Key('event-critical-star-${event.id}'),
                  color: HeyBeanTheme.warning,
                  size: 14,
                ),
                const SizedBox(width: 4),
              ],
              Expanded(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      event.title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: Colors.black,
                        fontWeight: FontWeight.w800,
                        fontSize: titleFontSize,
                        height: .98,
                      ),
                    ),
                    if (timeLabel.isNotEmpty)
                      Text(
                        timeLabel,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: Colors.black,
                          fontWeight: FontWeight.w700,
                          fontSize: timeFontSize,
                          height: .98,
                        ),
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
}

typedef _CalendarEventSaveCallback =
    Future<void> Function(
      HermesCalendarEvent event, {
      required String title,
      required String startsAt,
      String? endsAt,
      String? notes,
      String? location,
      String? status,
      String? category,
      String? color,
      String? recurrence,
      Map<String, Object?>? metadata,
      bool? isCritical,
      int? reminderMinutesBefore,
      String? reminderRecurrence,
      List<String>? reminderSpecificDays,
      int? reminderInterval,
      String? reminderIntervalUnit,
      int? workspaceId,
      List<Object> syncToWorkspaceIds,
    });

Future<void> _showCalendarEventDetails(
  BuildContext context,
  HermesCalendarEvent event, {
  required List<HermesEventCategory> eventCategories,
  GoogleCalendarSyncStatus? googleCalendarStatus,
  String? occurrenceDate,
  required _CalendarEventSaveCallback onSave,
  required Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved,
  required Future<void> Function(
    HermesEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted,
  Future<void> Function(HermesCalendarEvent event, bool isCritical)?
  onCriticalChanged,
  Future<void> Function(
    HermesCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })?
  onDelete,
  List<HermesWorkspace> workspaces = const [],
  String? activeWorkspaceId,
}) async {
  final result = await Navigator.of(context).push<Map<String, Object?>>(
    MaterialPageRoute(
      builder: (_) => _CalendarEventDetailPage(
        event: event,
        occurrenceDate: occurrenceDate,
        eventCategories: eventCategories,
        googleCalendarStatus: googleCalendarStatus,
        workspaces: workspaces,
        activeWorkspaceId: activeWorkspaceId,
        onSave: onSave,
        onEventCategorySaved: onEventCategorySaved,
        onEventCategoryDeleted: onEventCategoryDeleted,
        onCriticalChanged: onCriticalChanged,
        onDelete: onDelete,
      ),
    ),
  );

  if (result != null && result['action'] == 'delete') {
    final recurringDeleteMode = result['recurringDeleteMode'] as String?;
    final recurringOccurrenceDate =
        result['recurringOccurrenceDate'] as String?;
    final deleteEvent =
        recurringDeleteMode == null && recurringOccurrenceDate == null
        ? event
        : event.copyWith(
            metadata: {
              ...?event.metadata,
              if (recurringDeleteMode != null)
                '_delete_recurring_mode': recurringDeleteMode,
              if (recurringOccurrenceDate != null)
                '_delete_occurrence_date': recurringOccurrenceDate,
            },
          );
    await onDelete?.call(
      deleteEvent,
      deleteFromWorkspaceIds:
          (result['deleteFromWorkspaceIds'] as List?)
              ?.whereType<Object>()
              .toList() ??
          const [],
    );
    return;
  }

  return;
}

class _CalendarEventDetailPage extends StatefulWidget {
  const _CalendarEventDetailPage({
    required this.event,
    this.occurrenceDate,
    required this.eventCategories,
    this.googleCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
    required this.onSave,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
    this.onCriticalChanged,
    this.onDelete,
  });

  final HermesCalendarEvent event;
  final String? occurrenceDate;
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
  final _CalendarEventSaveCallback onSave;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    HermesEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;
  final Future<void> Function(HermesCalendarEvent event, bool isCritical)?
  onCriticalChanged;
  final Future<void> Function(
    HermesCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })?
  onDelete;

  @override
  State<_CalendarEventDetailPage> createState() =>
      _CalendarEventDetailPageState();
}

class _CalendarEventDetailPageState extends State<_CalendarEventDetailPage> {
  late final TextEditingController _title;
  late final TextEditingController _startsAt;
  late final TextEditingController _endsAt;
  late final TextEditingController _notes;
  late final TextEditingController _location;
  late final TextEditingController _category;
  late final TextEditingController _eventInterval;
  late String _color;
  late String _recurrence;
  late String _status;
  late List<HermesEventCategory> _categories;
  String _eventIntervalUnit = 'days';
  Object? _primaryWorkspaceId;
  final Set<String> _googleCalendarIds = <String>{};
  final Set<Object> _syncWorkspaceIds = <Object>{};
  String? _validationError;
  late bool _isCritical;
  late bool _allDay;
  bool _createEventReminder = false;
  int _reminderMinutesBefore = 15;
  final Set<String> _eventSpecificDays = <String>{};
  bool _saving = false;
  bool _savingCategory = false;
  bool _showCategoryManager = false;

  static const _colors = <({String value, String label})>[
    (value: _beanGreenCategoryColor, label: 'Green'),
    (value: '#007AFF', label: 'Blue'),
    (value: '#FF9500', label: 'Orange'),
    (value: '#AF52DE', label: 'Purple'),
    (value: '#FF3B30', label: 'Red'),
  ];

  static const _recurrences = <({String value, String label})>[
    (value: 'none', label: 'None'),
    (value: 'daily', label: 'Daily'),
    (value: 'weekly', label: 'Weekly'),
    (value: 'monthly', label: 'Monthly'),
    (value: 'yearly', label: 'Yearly'),
    (value: 'specific_days', label: 'Specific days'),
    (value: 'interval', label: 'Every X'),
  ];

  static const _statuses = <({String value, String label})>[
    (value: 'confirmed', label: 'Confirmed'),
    (value: 'tentative', label: 'Tentative'),
    (value: 'cancelled', label: 'Cancelled'),
  ];

  static const _reminderMinuteOptions = <({int value, String label})>[
    (value: 0, label: 'At start time'),
    (value: 5, label: '5 minutes before'),
    (value: 10, label: '10 minutes before'),
    (value: 15, label: '15 minutes before'),
    (value: 30, label: '30 minutes before'),
    (value: 60, label: '1 hour before'),
    (value: 120, label: '2 hours before'),
    (value: 1440, label: '1 day before'),
  ];

  static const _weekdays = <({String value, String label})>[
    (value: 'mon', label: 'Mon'),
    (value: 'tue', label: 'Tue'),
    (value: 'wed', label: 'Wed'),
    (value: 'thu', label: 'Thu'),
    (value: 'fri', label: 'Fri'),
    (value: 'sat', label: 'Sat'),
    (value: 'sun', label: 'Sun'),
  ];

  static const _intervalUnits = <({String value, String label})>[
    (value: 'days', label: 'days'),
    (value: 'weeks', label: 'weeks'),
    (value: 'months', label: 'months'),
  ];

  @override
  void initState() {
    super.initState();
    final event = widget.event;
    final eventMetadata = event.metadata ?? const <String, Object?>{};
    final initialPrimaryWorkspaceId =
        event.workspaceId ?? _workspaceValueToInt(widget.activeWorkspaceId);
    _primaryWorkspaceId =
        initialPrimaryWorkspaceId ??
        _workspaceValueForId(widget.workspaces, widget.activeWorkspaceId);
    _allDay = _eventIsAllDay(event);
    _title = TextEditingController(text: event.title);
    _startsAt = TextEditingController(
      text: _allDay
          ? _formatCalendarEventDate(event.startsAt)
          : _formatCalendarEventDateTime(event.startsAt),
    );
    _endsAt = TextEditingController(
      text: _allDay
          ? _formatCalendarEventEndDate(event.startsAt, event.endsAt)
          : _formatCalendarEventDateTime(event.endsAt),
    );
    _notes = TextEditingController(text: event.notes ?? '');
    _location = TextEditingController(text: event.location ?? '');
    _categories = [...widget.eventCategories];
    _category = TextEditingController(text: event.category ?? '');
    _eventInterval = TextEditingController(
      text: eventMetadata['interval']?.toString() ?? '1',
    );
    final writableGoogleCalendars =
        widget.googleCalendarStatus?.writableCalendars ??
        const <GoogleCalendarInfo>[];
    _googleCalendarIds.addAll(event.googleCalendarIds);
    _syncWorkspaceIds.addAll(
      _initialSyncWorkspaceIds(
        linkedWorkspaceIds: event.linkedWorkspaceIds,
        workspaceId: event.workspaceId,
        activeWorkspaceId: widget.activeWorkspaceId,
      ),
    );
    if (_googleCalendarIds.isEmpty &&
        widget.googleCalendarStatus?.defaultCalendarId != null) {
      _googleCalendarIds.add(widget.googleCalendarStatus!.defaultCalendarId!);
    }
    if (writableGoogleCalendars.isNotEmpty) {
      _googleCalendarIds.removeWhere(
        (calendarId) => !writableGoogleCalendars.any(
          (calendar) => calendar.id == calendarId,
        ),
      );
      if (_googleCalendarIds.isEmpty &&
          widget.googleCalendarStatus?.defaultCalendarId != null) {
        _googleCalendarIds.add(widget.googleCalendarStatus!.defaultCalendarId!);
      }
    }
    _isCritical = event.isCritical;
    final matchingCategoryColor = _categories
        .where(
          (category) =>
              category.name.toLowerCase() ==
              (event.category ?? '').trim().toLowerCase(),
        )
        .map((category) => category.color)
        .firstOrNull;
    final initialColor = (event.category ?? '').trim().isEmpty
        ? _themeCategoryColorHex()
        : event.color ?? matchingCategoryColor;
    _color = _isHexColor(initialColor)
        ? initialColor!.toUpperCase()
        : _themeCategoryColorHex();
    _recurrence =
        _recurrences.any((recurrence) => recurrence.value == event.recurrence)
        ? event.recurrence!
        : 'none';
    _status = _statuses.any((status) => status.value == event.status)
        ? event.status!
        : 'confirmed';
    _eventSpecificDays.addAll(
      ((eventMetadata['days'] as List?) ?? const <Object?>[])
          .whereType<String>(),
    );
    _eventIntervalUnit =
        _intervalUnits.any((unit) => unit.value == eventMetadata['unit'])
        ? eventMetadata['unit'] as String
        : 'days';
  }

  @override
  void dispose() {
    _title.dispose();
    _startsAt.dispose();
    _endsAt.dispose();
    _notes.dispose();
    _location.dispose();
    _category.dispose();
    _eventInterval.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_saving) return;
    late final String startsAt;
    String? endsAt;
    DateTime? parsedStart;
    DateTime? parsedEnd;

    if (_allDay) {
      final startDate = _calendarEventDateInputToDate(
        _startsAt.text,
        originalValue: widget.event.startsAt,
      );
      final endDate = _endsAt.text.trim().isEmpty
          ? startDate
          : _calendarEventDateInputToDate(
              _endsAt.text,
              originalValue: widget.event.endsAt,
              referenceValue: startDate,
            );
      if (startDate == null) {
        setState(
          () => _validationError = 'Enter a valid start date, like May 18.',
        );
        return;
      }
      if (endDate == null) {
        setState(
          () => _validationError = 'Enter a valid end date or leave it blank.',
        );
        return;
      }
      if (endDate.isBefore(startDate)) {
        setState(
          () =>
              _validationError = 'End date must be on or after the start date.',
        );
        return;
      }
      parsedStart = DateTime(startDate.year, startDate.month, startDate.day);
      parsedEnd = DateTime(
        endDate.year,
        endDate.month,
        endDate.day,
      ).add(const Duration(days: 1));
      startsAt = parsedStart.toUtc().toIso8601String();
      endsAt = parsedEnd.toUtc().toIso8601String();
    } else {
      final wireStartsAt = _calendarEventInputToWireValue(
        _startsAt.text,
        originalValue: widget.event.startsAt,
      );
      final wireEndsAt = _calendarEventInputToWireValue(
        _endsAt.text,
        originalValue: widget.event.endsAt,
        referenceValue: wireStartsAt,
        allowEmpty: true,
      );
      parsedStart = _parseCalendarEventDateTime(wireStartsAt);
      parsedEnd = _parseCalendarEventDateTime(wireEndsAt, wireStartsAt);
      if (wireStartsAt == null ||
          wireStartsAt.trim().isEmpty ||
          parsedStart == null) {
        setState(
          () => _validationError =
              'Enter a valid start time, like May 18 9:00 AM.',
        );
        return;
      }
      if (wireEndsAt != null && parsedEnd == null) {
        setState(
          () => _validationError = 'Enter a valid end time or leave it blank.',
        );
        return;
      }
      if (parsedEnd != null &&
          (parsedEnd.isBefore(parsedStart) ||
              parsedEnd.isAtSameMomentAs(parsedStart))) {
        setState(
          () => _validationError = 'End time must be after the start time.',
        );
        return;
      }
      startsAt = wireStartsAt;
      endsAt = wireEndsAt;
    }

    final eventInterval = int.tryParse(_eventInterval.text.trim()) ?? 1;
    final sortedGoogleCalendarIds = _googleCalendarIds.toList()..sort();
    final syncToWorkspaceIds = _syncWorkspaceIds.toList();
    Object? primaryWorkspaceId = _primaryWorkspaceId;
    if (widget.event.id == 0 && widget.workspaces.isNotEmpty) {
      if (primaryWorkspaceId == null && syncToWorkspaceIds.isNotEmpty) {
        primaryWorkspaceId = syncToWorkspaceIds.removeAt(0);
      }
      if (primaryWorkspaceId == null) {
        setState(() => _validationError = 'Choose at least one workspace.');
        return;
      }
      syncToWorkspaceIds.removeWhere(
        (workspaceId) => _workspaceValuesMatch(workspaceId, primaryWorkspaceId),
      );
    }
    final eventMetadata = <String, Object?>{
      ...?widget.event.metadata,
      'recurrence': _recurrence,
      if (_allDay ||
          (widget.event.metadata?.containsKey('all_day') ?? false) ||
          (widget.event.metadata?.containsKey('allDay') ?? false))
        'all_day': _allDay,
      if (sortedGoogleCalendarIds.isNotEmpty)
        'google_calendar_ids': sortedGoogleCalendarIds,
      if (sortedGoogleCalendarIds.isNotEmpty)
        'google_calendar_id': sortedGoogleCalendarIds.first,
      if (_recurrence == 'specific_days')
        'days': _eventSpecificDays.toList()..sort(),
      if (_recurrence == 'specific_days' || _recurrence == 'interval')
        'interval': eventInterval,
      if (_recurrence == 'specific_days' || _recurrence == 'interval')
        'unit': _eventIntervalUnit,
    };

    setState(() {
      _saving = true;
      _validationError = null;
    });
    try {
      await widget.onSave(
        widget.event,
        title: _title.text.trim().isEmpty
            ? widget.event.title
            : _title.text.trim(),
        startsAt: startsAt,
        endsAt: endsAt,
        notes: _notes.text.trim().isEmpty ? null : _notes.text.trim(),
        location: _location.text.trim().isEmpty ? null : _location.text.trim(),
        status: _status,
        category: _category.text.trim().isEmpty ? null : _category.text.trim(),
        color: _color,
        recurrence: _recurrence,
        metadata: eventMetadata,
        isCritical: _isCritical,
        reminderMinutesBefore: _createEventReminder
            ? _reminderMinutesBefore
            : null,
        reminderRecurrence: null,
        reminderSpecificDays: const [],
        reminderInterval: null,
        reminderIntervalUnit: null,
        workspaceId: _workspaceValueToInt(primaryWorkspaceId),
        syncToWorkspaceIds: syncToWorkspaceIds,
      );
      if (!mounted) return;
      Navigator.of(context).pop(<String, Object?>{'action': 'saved'});
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _validationError = beanFriendlyErrorMessage(
          error,
          action: 'save that event',
        );
      });
    }
  }

  Future<void> _saveCategoryValues({
    HermesEventCategory? category,
    required String name,
    required String color,
  }) async {
    final trimmedName = name.trim();
    if (trimmedName.isEmpty) return;
    setState(() {
      _savingCategory = true;
      _validationError = null;
    });
    try {
      final saved = await widget.onEventCategorySaved(
        category: category,
        name: trimmedName,
        color: color,
      );
      if (!mounted) return;
      setState(() {
        final exists = _categories.any((item) => item.id == saved.id);
        _categories = exists
            ? _categories
                  .map((item) => item.id == saved.id ? saved : item)
                  .toList()
            : [..._categories, saved];
        _category.text = saved.name;
        _color = saved.color;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _validationError = 'Could not save category. Try a different name.';
      });
    } finally {
      if (mounted) setState(() => _savingCategory = false);
    }
  }

  Future<void> _toggleCritical() async {
    final nextValue = !_isCritical;
    setState(() {
      _isCritical = nextValue;
      _validationError = null;
    });

    final onCriticalChanged = widget.onCriticalChanged;
    if (onCriticalChanged == null) return;

    try {
      await onCriticalChanged(widget.event, nextValue);
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _isCritical = !nextValue;
        _validationError = 'Could not update critical status. Try again.';
      });
    }
  }

  Future<void> _openCategoryCreationModal() async {
    final result = await showDialog<Map<String, String>>(
      context: context,
      builder: (context) =>
          _EventCategoryCreateDialog(initialColor: _color, colors: _colors),
    );
    if (result == null || !mounted) return;
    await _saveCategoryValues(
      name: result['name'] ?? '',
      color: result['color'] ?? _color,
    );
  }

  Future<void> _openCategoryEditModal(HermesEventCategory category) async {
    if (category.id < 0) return;
    final result = await showDialog<Map<String, String>>(
      context: context,
      builder: (context) => _EventCategoryCreateDialog(
        initialColor: category.color,
        initialName: category.name,
        editing: true,
        colors: _colors,
      ),
    );
    if (result == null || !mounted) return;
    await _saveCategoryValues(
      category: category,
      name: result['name'] ?? category.name,
      color: result['color'] ?? category.color,
    );
  }

  String _categoryKey(String name) => name.trim().replaceAll(' ', '-');

  Color _categoryColor(String value) =>
      Color(int.parse('FF${value.substring(1)}', radix: 16));

  List<HermesEventCategory> get _categoryChipValues {
    final byName = <String, HermesEventCategory>{};
    for (final category in _categories) {
      byName[category.name.toLowerCase()] = category;
    }
    final selectedName = _category.text.trim();
    if (selectedName.isNotEmpty &&
        !byName.containsKey(selectedName.toLowerCase())) {
      byName[selectedName.toLowerCase()] = HermesEventCategory(
        id: -1,
        name: selectedName,
        color: _color,
      );
    }
    final values = byName.values.toList()
      ..sort((a, b) => a.name.toLowerCase().compareTo(b.name.toLowerCase()));
    return values;
  }

  void _selectCategory(HermesEventCategory category) {
    setState(() {
      _category.text = category.name;
      _color = category.color;
    });
  }

  void _setEndOneHourAfterStart() {
    if (_allDay) return;
    final wireStart = _calendarEventInputToWireValue(
      _startsAt.text,
      originalValue: widget.event.startsAt,
    );
    final start = _parseCalendarEventDateTime(wireStart);
    if (start == null) return;
    _endsAt.text = _formatCalendarEventDateTime(
      start.add(const Duration(hours: 1)).toIso8601String(),
    );
    _validationError = null;
  }

  Future<void> _deleteCategoryValues(HermesEventCategory category) async {
    if (category.id < 0) {
      setState(() {
        if (_category.text.trim() == category.name) {
          _category.clear();
          _color = _themeCategoryColorHex();
        }
      });
      return;
    }
    final deleteFromWorkspaceIds = await _confirmWorkspaceDeleteSelection(
      context,
      itemTitle: category.name,
      itemType: 'category',
      workspaces: widget.workspaces,
      activeWorkspaceId: widget.activeWorkspaceId,
      workspaceId: category.workspaceId,
      linkedWorkspaceIds: category.linkedWorkspaceIds,
    );
    if (deleteFromWorkspaceIds == null || !mounted) return;
    setState(() => _savingCategory = true);
    try {
      await widget.onEventCategoryDeleted(
        category,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
      );
      if (!mounted) return;
      setState(() {
        _categories = _categories
            .where((item) => item.id != category.id)
            .toList();
        if (_category.text.trim() == category.name) {
          _category.clear();
          _color = _themeCategoryColorHex();
        }
      });
    } finally {
      if (mounted) setState(() => _savingCategory = false);
    }
  }

  Future<Map<String, Object?>?> _confirmCalendarEventDelete() async {
    final linkedIds = <int>{
      if (widget.event.workspaceId != null) widget.event.workspaceId!,
      ...widget.event.linkedWorkspaceIds,
    };
    if (linkedIds.isEmpty && widget.activeWorkspaceId != null) {
      final activeId = int.tryParse(widget.activeWorkspaceId!);
      if (activeId != null) linkedIds.add(activeId);
    }

    final workspaceById = {
      for (final workspace in widget.workspaces)
        if (workspace.numericId != null) workspace.numericId!: workspace,
    };
    final deleteChoices =
        linkedIds
            .map(
              (id) =>
                  workspaceById[id] ??
                  HermesWorkspace(
                    id: id.toString(),
                    name: id == widget.event.workspaceId
                        ? 'Current workspace'
                        : 'Workspace $id',
                  ),
            )
            .toList()
          ..sort((a, b) {
            if (a.numericId == widget.event.workspaceId) return -1;
            if (b.numericId == widget.event.workspaceId) return 1;
            return a.name.toLowerCase().compareTo(b.name.toLowerCase());
          });

    final isRecurring = _eventIsRecurring(widget.event);
    final occurrenceDate = widget.occurrenceDate;
    if (isRecurring && occurrenceDate != null) {
      var recurringMode = 'single';
      final selectedIds = deleteChoices
          .map((workspace) => workspace.numericId ?? workspace.id)
          .toSet();
      return showDialog<Map<String, Object?>>(
        context: context,
        builder: (context) => StatefulBuilder(
          builder: (context, setDialogState) {
            final canDelete = selectedIds.isNotEmpty || deleteChoices.isEmpty;

            return AlertDialog(
              title: const Text('Delete recurring event'),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    ListTile(
                      key: const Key('event-delete-recurring-single'),
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(
                        recurringMode == 'single'
                            ? Icons.radio_button_checked_rounded
                            : Icons.radio_button_unchecked_rounded,
                        color: recurringMode == 'single'
                            ? HeyBeanTheme.accentStrong
                            : HeyBeanTheme.muted,
                      ),
                      onTap: () =>
                          setDialogState(() => recurringMode = 'single'),
                      title: const Text('This event only'),
                      subtitle: const Text(
                        'The series resumes after this date.',
                      ),
                    ),
                    ListTile(
                      key: const Key('event-delete-recurring-future'),
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(
                        recurringMode == 'future'
                            ? Icons.radio_button_checked_rounded
                            : Icons.radio_button_unchecked_rounded,
                        color: recurringMode == 'future'
                            ? HeyBeanTheme.accentStrong
                            : HeyBeanTheme.muted,
                      ),
                      onTap: () =>
                          setDialogState(() => recurringMode = 'future'),
                      title: const Text('This and future events'),
                      subtitle: const Text(
                        'Earlier events stay on the calendar.',
                      ),
                    ),
                    ListTile(
                      key: const Key('event-delete-recurring-all'),
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(
                        recurringMode == 'all'
                            ? Icons.radio_button_checked_rounded
                            : Icons.radio_button_unchecked_rounded,
                        color: recurringMode == 'all'
                            ? HeyBeanTheme.accentStrong
                            : HeyBeanTheme.muted,
                      ),
                      onTap: () => setDialogState(() => recurringMode = 'all'),
                      title: const Text('Entire series'),
                      subtitle: const Text('Remove every occurrence.'),
                    ),
                    if (deleteChoices.length > 1) ...[
                      const Divider(height: 20),
                      for (final workspace in deleteChoices)
                        CheckboxListTile(
                          key: Key('event-delete-workspace-${workspace.id}'),
                          contentPadding: EdgeInsets.zero,
                          value: selectedIds.contains(
                            workspace.numericId ?? workspace.id,
                          ),
                          onChanged: (value) => setDialogState(() {
                            final id = workspace.numericId ?? workspace.id;
                            if (value ?? false) {
                              selectedIds.add(id);
                            } else {
                              selectedIds.remove(id);
                            }
                          }),
                          title: Text(
                            workspace.isPersonal ? 'Personal' : workspace.name,
                          ),
                          subtitle:
                              workspace.numericId == widget.event.workspaceId
                              ? const Text('Current copy')
                              : null,
                        ),
                    ],
                  ],
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.of(context).pop(),
                  child: const Text('Cancel'),
                ),
                FilledButton(
                  key: const Key('event-delete-recurring-action'),
                  style: FilledButton.styleFrom(
                    backgroundColor: HeyBeanTheme.destructive,
                    foregroundColor: Colors.white,
                  ),
                  onPressed: canDelete
                      ? () => Navigator.of(context).pop({
                          'deleteFromWorkspaceIds': selectedIds.toList(),
                          'recurringDeleteMode': recurringMode,
                          'recurringOccurrenceDate': occurrenceDate,
                        })
                      : null,
                  child: const Text('Delete'),
                ),
              ],
            );
          },
        ),
      );
    }

    if (deleteChoices.length <= 1) {
      final confirmed = await _confirmDestructiveAction(
        context,
        title: 'Delete event?',
        message: 'This removes "${widget.event.title}" from your calendar.',
        confirmLabel: 'Delete event',
      );
      if (!confirmed) return null;
      return {
        'deleteFromWorkspaceIds': [
          if (deleteChoices.isNotEmpty)
            deleteChoices.first.numericId ?? deleteChoices.first.id,
        ],
      };
    }

    final selectedIds = deleteChoices
        .map((workspace) => workspace.numericId ?? workspace.id)
        .toSet();
    return showDialog<Map<String, Object?>>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) {
          final canDelete = selectedIds.isNotEmpty;

          return AlertDialog(
            title: const Text('Delete event from'),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  '"${widget.event.title}" is linked across workspaces. Choose where to remove it.',
                ),
                const SizedBox(height: 10),
                for (final workspace in deleteChoices)
                  CheckboxListTile(
                    key: Key('event-delete-workspace-${workspace.id}'),
                    contentPadding: EdgeInsets.zero,
                    value: selectedIds.contains(
                      workspace.numericId ?? workspace.id,
                    ),
                    onChanged: (value) => setDialogState(() {
                      final id = workspace.numericId ?? workspace.id;
                      if (value ?? false) {
                        selectedIds.add(id);
                      } else {
                        selectedIds.remove(id);
                      }
                    }),
                    title: Text(
                      workspace.isPersonal ? 'Personal' : workspace.name,
                    ),
                    subtitle: workspace.numericId == widget.event.workspaceId
                        ? const Text('Current copy')
                        : null,
                  ),
              ],
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.of(context).pop(),
                child: const Text('Cancel'),
              ),
              FilledButton(
                key: const Key('event-delete-selected-workspaces-action'),
                style: FilledButton.styleFrom(
                  backgroundColor: HeyBeanTheme.destructive,
                  foregroundColor: Colors.white,
                ),
                onPressed: canDelete
                    ? () => Navigator.of(
                        context,
                      ).pop({'deleteFromWorkspaceIds': selectedIds.toList()})
                    : null,
                child: const Text('Delete event'),
              ),
            ],
          );
        },
      ),
    );
  }

  Future<void> _showTimeDock(
    TextEditingController controller, {
    required String? originalValue,
    String? referenceValue,
    bool updateEndFromStart = false,
  }) async {
    if (_allDay) {
      await _showDateDock(
        controller,
        originalValue: originalValue,
        referenceValue: referenceValue,
      );
      return;
    }

    final selected = await _showStandardDateTimeDock(
      context,
      initialText: controller.text,
      originalValue: originalValue,
      referenceValue: referenceValue,
      keyPrefix: 'event',
    );
    if (selected != null && mounted) {
      setState(() {
        controller.text = _formatCalendarEventDateTime(
          selected.toIso8601String(),
        );
        if (updateEndFromStart) _setEndOneHourAfterStart();
      });
    }
  }

  Future<void> _showDateDock(
    TextEditingController controller, {
    required String? originalValue,
    String? referenceValue,
  }) async {
    final selected = await _showStandardDateTimeDock(
      context,
      initialText: controller.text,
      originalValue: originalValue,
      referenceValue: referenceValue,
      keyPrefix: 'event-date',
      dateOnly: true,
    );
    if (selected != null && mounted) {
      setState(() {
        controller.text = _formatCalendarDateLabel(selected);
      });
    }
  }

  void _setAllDay(bool value) {
    if (_allDay == value) return;
    setState(() {
      _allDay = value;
      _validationError = null;
      final start =
          _parseCalendarEventDateTime(_startsAt.text, widget.event.startsAt) ??
          _parseCalendarEventDateTime(widget.event.startsAt) ??
          DateTime.now();
      final end =
          _parseCalendarEventDateTime(_endsAt.text, widget.event.endsAt) ??
          _parseCalendarEventDateTime(
            widget.event.endsAt,
            widget.event.startsAt,
          );

      if (value) {
        _startsAt.text = _formatCalendarDateLabel(start);
        final endDate = _displayEndDateForAllDay(start, end);
        _endsAt.text = _formatCalendarDateLabel(endDate);
      } else {
        final startDate =
            _calendarEventDateInputToDate(_startsAt.text) ?? start;
        final endDate = _calendarEventDateInputToDate(_endsAt.text);
        _startsAt.text = _formatCalendarEventDateTime(
          DateTime(
            startDate.year,
            startDate.month,
            startDate.day,
            start.hour == 0 ? 9 : start.hour,
            start.minute,
          ).toIso8601String(),
        );
        if (endDate == null) {
          _endsAt.clear();
        } else {
          _endsAt.text = _formatCalendarEventDateTime(
            DateTime(
              endDate.year,
              endDate.month,
              endDate.day,
              start.hour == 0 ? 17 : start.add(const Duration(hours: 1)).hour,
              start.minute,
            ).toIso8601String(),
          );
        }
      }
    });
  }

  bool get _creatingEvent => widget.event.id == 0;

  List<HermesWorkspace> get _eventWorkspaceChoices {
    if (_creatingEvent) return widget.workspaces;
    return widget.workspaces
        .where((workspace) => workspace.id != widget.activeWorkspaceId)
        .toList();
  }

  bool _eventWorkspaceSelected(HermesWorkspace workspace) {
    final value = _workspaceValue(workspace);
    return _workspaceValuesMatch(value, _primaryWorkspaceId) ||
        _syncWorkspaceIds.any(
          (workspaceId) => _workspaceValuesMatch(workspaceId, value),
        );
  }

  void _setEventWorkspaceSelected(HermesWorkspace workspace, bool selected) {
    final value = _workspaceValue(workspace);
    setState(() {
      _validationError = null;
      if (!_creatingEvent) {
        if (selected) {
          _syncWorkspaceIds.add(value);
        } else {
          _syncWorkspaceIds.removeWhere(
            (workspaceId) => _workspaceValuesMatch(workspaceId, value),
          );
        }
        return;
      }

      if (selected) {
        if (_primaryWorkspaceId == null) {
          _primaryWorkspaceId = value;
        } else {
          _syncWorkspaceIds.add(value);
        }
        return;
      }

      if (_workspaceValuesMatch(value, _primaryWorkspaceId)) {
        _primaryWorkspaceId = null;
        if (_syncWorkspaceIds.isNotEmpty) {
          final replacement = _syncWorkspaceIds.first;
          _primaryWorkspaceId = replacement;
          _syncWorkspaceIds.removeWhere(
            (workspaceId) => _workspaceValuesMatch(workspaceId, replacement),
          );
        }
      } else {
        _syncWorkspaceIds.removeWhere(
          (workspaceId) => _workspaceValuesMatch(workspaceId, value),
        );
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: const Key('calendar-event-detail-page'),
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [HeyBeanTheme.bg0, HeyBeanTheme.bg1],
          ),
        ),
        child: SafeArea(
          child: Column(
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 10),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    IconButton.filledTonal(
                      key: const Key('event-detail-back-action'),
                      onPressed: () => Navigator.of(context).pop(),
                      icon: const Icon(Icons.arrow_back_rounded),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _FormEditorHeader(
                        icon: Icons.calendar_month_rounded,
                        title: 'Event Details',
                        titleKey: const Key('event-detail-header-title'),
                        subtitle: 'Schedule, details, and calendar sync',
                        trailing: IconButton.filledTonal(
                          key: const Key('event-detail-critical-toggle'),
                          tooltip: _isCritical
                              ? 'Remove critical star'
                              : 'Mark critical',
                          onPressed: _toggleCritical,
                          icon: Icon(
                            _isCritical
                                ? Icons.star_rounded
                                : Icons.star_border_rounded,
                            color: _isCritical
                                ? HeyBeanTheme.warning
                                : HeyBeanTheme.muted,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              Expanded(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 120),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      _MobileFormSection(
                        title: 'Schedule',
                        subtitle:
                            'Use exact times or natural entries from the agent.',
                        icon: Icons.schedule_rounded,
                        infoKey: const Key('event-schedule-info'),
                        infoTitle: 'Event schedule',
                        infoBullets: const [
                          'Turn on All day for date-only events with no start or end time.',
                          'Tap a time field to use the date and time picker.',
                          'You can also type natural entries like today at 2pm or May 18 at 9am.',
                          'End time must be after the start time; leave it blank for a simple reminder-style event.',
                        ],
                        primary: true,
                        children: [
                          TextField(
                            key: const Key('event-title-field'),
                            controller: _title,
                            textInputAction: TextInputAction.next,
                            decoration: const InputDecoration(
                              labelText: 'Event title',
                              prefixIcon: Icon(Icons.title_rounded),
                            ),
                          ),
                          if (_validationError != null)
                            _InlinePlanLimitError(
                              key: const Key('event-validation-error'),
                              message: _validationError!,
                            ),
                          _MobileFormSwitch(
                            widgetKey: const Key('event-all-day-toggle'),
                            value: _allDay,
                            onChanged: _setAllDay,
                            icon: Icons.calendar_today_rounded,
                            title: 'All day',
                            subtitle:
                                'Use dates only instead of start and end times.',
                          ),
                          TextField(
                            key: const Key('event-start-field'),
                            controller: _startsAt,
                            onChanged: (_) =>
                                setState(_setEndOneHourAfterStart),
                            onTap: () => _showTimeDock(
                              _startsAt,
                              originalValue: widget.event.startsAt,
                              updateEndFromStart: true,
                            ),
                            decoration: InputDecoration(
                              labelText: _allDay ? 'Start date' : 'Start time',
                              prefixIcon: const Icon(Icons.play_arrow_rounded),
                              suffixIcon: const Icon(Icons.expand_less_rounded),
                            ),
                          ),
                          TextField(
                            key: const Key('event-end-field'),
                            controller: _endsAt,
                            onTap: () => _showTimeDock(
                              _endsAt,
                              originalValue: widget.event.endsAt,
                              referenceValue: _startsAt.text,
                            ),
                            decoration: InputDecoration(
                              labelText: _allDay ? 'End date' : 'End time',
                              prefixIcon: const Icon(Icons.stop_rounded),
                              suffixIcon: const Icon(Icons.expand_less_rounded),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 14),
                      _MobileFormSection(
                        title: 'Event details',
                        subtitle: 'Location, description, and status',
                        iconWidget: _BeanNotesIcon(
                          size: 18,
                          color: HeyBeanTheme.accentStrong,
                        ),
                        children: [
                          TextField(
                            key: const Key('event-location-field'),
                            controller: _location,
                            textInputAction: TextInputAction.next,
                            decoration: const InputDecoration(
                              labelText: 'Location',
                              prefixIcon: Icon(Icons.place_rounded),
                            ),
                          ),
                          DropdownButtonFormField<String>(
                            key: const Key('event-status-field'),
                            initialValue: _status,
                            decoration: const InputDecoration(
                              labelText: 'Status',
                              prefixIcon: Icon(Icons.event_available_rounded),
                            ),
                            items: [
                              for (final status in _statuses)
                                DropdownMenuItem(
                                  value: status.value,
                                  child: Text(status.label),
                                ),
                            ],
                            onChanged: (value) {
                              if (value == null) return;
                              setState(() => _status = value);
                            },
                          ),
                          TextField(
                            key: const Key('event-notes-field'),
                            controller: _notes,
                            minLines: 3,
                            maxLines: 6,
                            decoration: _longFormInputDecoration(
                              labelText: 'Description',
                              hintText: 'Add event description',
                              prefixIcon: const _BeanNotesIcon(size: 20),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 14),
                      _MobileFormSection(
                        title: 'Organize',
                        subtitle: 'Category, color, and workspace',
                        icon: Icons.category_outlined,
                        children: [
                          Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Expanded(
                                child: KeyedSubtree(
                                  key: ValueKey(
                                    'event-category-dropdown-${_category.text.trim().toLowerCase()}',
                                  ),
                                  child: DropdownButtonFormField<String>(
                                    key: const Key('event-category-dropdown'),
                                    initialValue: _category.text.trim().isEmpty
                                        ? ''
                                        : _category.text.trim(),
                                    decoration: const InputDecoration(
                                      labelText: 'Category',
                                      prefixIcon: Icon(Icons.category_outlined),
                                    ),
                                    isExpanded: true,
                                    items: [
                                      const DropdownMenuItem<String>(
                                        key: Key('event-category-none'),
                                        value: '',
                                        child: Text('No category'),
                                      ),
                                      for (final category
                                          in _categoryChipValues)
                                        DropdownMenuItem<String>(
                                          key: Key(
                                            'event-category-option-${_categoryKey(category.name)}',
                                          ),
                                          value: category.name,
                                          child: Row(
                                            mainAxisSize: MainAxisSize.min,
                                            children: [
                                              CircleAvatar(
                                                radius: 6,
                                                backgroundColor: _categoryColor(
                                                  category.color,
                                                ),
                                              ),
                                              const SizedBox(width: 8),
                                              Text(
                                                category.name,
                                                overflow: TextOverflow.ellipsis,
                                              ),
                                            ],
                                          ),
                                        ),
                                    ],
                                    onChanged: _savingCategory
                                        ? null
                                        : (value) {
                                            final nextValue = value ?? '';
                                            if (nextValue.isEmpty) {
                                              setState(() {
                                                _category.text = '';
                                                _color =
                                                    _themeCategoryColorHex();
                                              });
                                              return;
                                            }
                                            final selected = _categoryChipValues
                                                .where(
                                                  (category) =>
                                                      category.name ==
                                                      nextValue,
                                                )
                                                .firstOrNull;
                                            setState(() {
                                              _category.text = nextValue;
                                              _color =
                                                  selected?.color ?? _color;
                                            });
                                          },
                                  ),
                                ),
                              ),
                              const SizedBox(width: 10),
                              IconButton.filledTonal(
                                key: const Key('event-category-add-action'),
                                onPressed: _savingCategory
                                    ? null
                                    : _openCategoryCreationModal,
                                tooltip: 'Create category',
                                icon: const Icon(Icons.add_rounded),
                              ),
                            ],
                          ),
                          Align(
                            alignment: Alignment.centerLeft,
                            child: TextButton.icon(
                              key: const Key('event-category-manager-toggle'),
                              onPressed: _savingCategory
                                  ? null
                                  : () => setState(
                                      () => _showCategoryManager =
                                          !_showCategoryManager,
                                    ),
                              icon: Icon(
                                _showCategoryManager
                                    ? Icons.expand_less_rounded
                                    : Icons.tune_rounded,
                              ),
                              label: Text(
                                _showCategoryManager
                                    ? 'Hide category manager'
                                    : 'Manage categories',
                              ),
                            ),
                          ),
                          if (_showCategoryManager)
                            Wrap(
                              key: const Key('event-category-manager'),
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                for (final category in _categoryChipValues)
                                  _EventCategoryChip(
                                    chipKey: Key(
                                      'event-category-chip-${_categoryKey(category.name)}',
                                    ),
                                    deleteKey: Key(
                                      'event-category-delete-${_categoryKey(category.name)}',
                                    ),
                                    editKey: Key(
                                      'event-category-edit-${_categoryKey(category.name)}',
                                    ),
                                    category: category,
                                    color: _categoryColor(category.color),
                                    selected:
                                        _category.text.trim() == category.name,
                                    saving: _savingCategory,
                                    onSelected: () => _selectCategory(category),
                                    onEdited: () =>
                                        _openCategoryEditModal(category),
                                    onDeleted: () =>
                                        _deleteCategoryValues(category),
                                  ),
                              ],
                            ),
                        ],
                      ),
                      const SizedBox(height: 14),
                      _MobileFormSection(
                        key: const Key('event-recurrence-field'),
                        title: 'Repeat',
                        subtitle: 'Make this repeat when it should come back',
                        icon: Icons.repeat_rounded,
                        infoKey: const Key('event-recurrence-info'),
                        infoTitle: 'Event recurrence',
                        infoBullets: const [
                          'Choose None for a one-time event.',
                          'Specific days repeats on the weekdays you select.',
                          'Every X lets you build patterns like every 2 weeks or every 3 months.',
                        ],
                        children: [
                          const _EventFieldLabel(
                            icon: Icons.repeat_on_rounded,
                            label: 'Recurrence',
                          ),
                          const Text(
                            'Repeat this event when needed.',
                            style: TextStyle(
                              color: HeyBeanTheme.muted,
                              fontSize: 12,
                              height: 1.35,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              for (final recurrence in _recurrences)
                                ChoiceChip(
                                  label: Text(recurrence.label),
                                  selected: _recurrence == recurrence.value,
                                  onSelected: (_) => setState(() {
                                    _recurrence = recurrence.value;
                                  }),
                                ),
                            ],
                          ),
                          if (_recurrence == 'specific_days') ...[
                            const SizedBox(height: 10),
                            Wrap(
                              key: const Key('event-specific-days'),
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                for (final day in _weekdays)
                                  FilterChip(
                                    label: Text(day.label),
                                    selected: _eventSpecificDays.contains(
                                      day.value,
                                    ),
                                    onSelected: (selected) => setState(() {
                                      if (selected) {
                                        _eventSpecificDays.add(day.value);
                                      } else {
                                        _eventSpecificDays.remove(day.value);
                                      }
                                    }),
                                  ),
                              ],
                            ),
                          ],
                          if (_recurrence == 'interval') ...[
                            const SizedBox(height: 10),
                            Row(
                              key: const Key('event-interval-field'),
                              children: [
                                Expanded(
                                  child: TextField(
                                    controller: _eventInterval,
                                    keyboardType: TextInputType.number,
                                    decoration: const InputDecoration(
                                      labelText: 'Every',
                                      prefixIcon: Icon(Icons.numbers_rounded),
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 10),
                                DropdownButton<String>(
                                  value: _eventIntervalUnit,
                                  items: [
                                    for (final unit in _intervalUnits)
                                      DropdownMenuItem(
                                        value: unit.value,
                                        child: Text(unit.label),
                                      ),
                                  ],
                                  onChanged: (value) => setState(() {
                                    if (value != null) {
                                      _eventIntervalUnit = value;
                                    }
                                  }),
                                ),
                              ],
                            ),
                          ],
                        ],
                      ),
                      if ((widget
                              .googleCalendarStatus
                              ?.writableCalendars
                              .isNotEmpty ??
                          false)) ...[
                        const SizedBox(height: 14),
                        _MobileFormSection(
                          key: const Key('event-google-calendar-field'),
                          title: 'External Calendar Sync',
                          subtitle:
                              'Add or update this event on selected writable external calendars.',
                          icon: Icons.calendar_month_rounded,
                          infoKey: const Key('event-google-calendars-info'),
                          infoTitle: 'External Calendar Sync',
                          infoBullets: const [
                            'Checked external calendars receive a copy of this local Bean event.',
                            'Only writable connected external calendars are shown here.',
                            'Changing this list affects this event, not your whole account.',
                          ],
                          children: [
                            for (final calendar
                                in widget
                                    .googleCalendarStatus!
                                    .writableCalendars)
                              CheckboxListTile(
                                key: Key(
                                  'event-google-calendar-${calendar.id}',
                                ),
                                contentPadding: EdgeInsets.zero,
                                value: _googleCalendarIds.contains(calendar.id),
                                onChanged: (value) => setState(() {
                                  if (value ?? false) {
                                    _googleCalendarIds.add(calendar.id);
                                  } else {
                                    _googleCalendarIds.remove(calendar.id);
                                  }
                                }),
                                title: Text(calendar.summary),
                                subtitle:
                                    calendar.id ==
                                        widget
                                            .googleCalendarStatus!
                                            .defaultCalendarId
                                    ? const Text('Default for new local events')
                                    : null,
                              ),
                          ],
                        ),
                      ],
                      if (_eventWorkspaceChoices.isNotEmpty) ...[
                        const SizedBox(height: 14),
                        _MobileFormSection(
                          key: const Key('event-workspace-sync-field'),
                          title: 'Local Workspace Sync',
                          subtitle: _creatingEvent
                              ? 'Choose every workspace this event should be created in.'
                              : 'Copy this event only to selected HeyBean workspaces.',
                          icon: Icons.home_work_outlined,
                          infoKey: const Key('event-workspace-sync-info'),
                          infoTitle: 'Local Workspace Sync',
                          infoBullets: const [
                            'Use this when a Personal event should also appear in a household workspace.',
                            'Sync creates a copy for the selected workspace; future edits remain controlled by Bean.',
                            'Leave everything unchecked to keep the event only in the current workspace.',
                          ],
                          children: [
                            Wrap(
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                for (final workspace in _eventWorkspaceChoices)
                                  FilterChip(
                                    key: Key(
                                      'event-sync-workspace-${workspace.id}',
                                    ),
                                    label: Text(
                                      _workspaceValuesMatch(
                                            _workspaceValue(workspace),
                                            _workspaceValueForId(
                                              widget.workspaces,
                                              widget.activeWorkspaceId,
                                            ),
                                          )
                                          ? '${workspace.isPersonal ? 'Personal' : workspace.name} (current)'
                                          : workspace.isPersonal
                                          ? 'Personal'
                                          : workspace.name,
                                    ),
                                    selected: _eventWorkspaceSelected(
                                      workspace,
                                    ),
                                    onSelected: (selected) =>
                                        _setEventWorkspaceSelected(
                                          workspace,
                                          selected,
                                        ),
                                  ),
                              ],
                            ),
                          ],
                        ),
                      ],
                      const SizedBox(height: 14),
                      _MobileFormSection(
                        title: 'Create reminder',
                        subtitle: _recurrence == 'none'
                            ? 'Optionally remind me before this event.'
                            : 'Optionally remind me before every event in this series.',
                        icon: Icons.notifications_active_outlined,
                        infoKey: const Key('event-reminder-info'),
                        infoTitle: 'Event reminders',
                        infoBullets: const [
                          'Minutes before controls when Bean reminds you before the event starts.',
                          'For repeating events, the reminder follows the event repeat pattern.',
                          'Leave Create reminder off if you do not need a reminder for this event.',
                        ],
                        children: [
                          _MobileFormSwitch(
                            widgetKey: const Key(
                              'event-create-reminder-toggle',
                            ),
                            value: _createEventReminder,
                            onChanged: (value) =>
                                setState(() => _createEventReminder = value),
                            icon: Icons.alarm_rounded,
                            title: 'Create reminder',
                            subtitle: _recurrence == 'none'
                                ? 'Add a reminder before this event starts.'
                                : 'Add a reminder before each occurrence.',
                          ),
                          if (_createEventReminder)
                            DropdownButtonFormField<int>(
                              key: const Key('event-reminder-minutes-field'),
                              initialValue: _reminderMinutesBefore,
                              decoration: const InputDecoration(
                                labelText: 'Remind me',
                                prefixIcon: Icon(Icons.alarm_rounded),
                              ),
                              items: [
                                for (final option in _reminderMinuteOptions)
                                  DropdownMenuItem<int>(
                                    value: option.value,
                                    child: Text(option.label),
                                  ),
                              ],
                              onChanged: (value) {
                                if (value == null) return;
                                setState(() => _reminderMinutesBefore = value);
                              },
                            ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
      bottomNavigationBar: SafeArea(
        top: false,
        child: Container(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 16),
          decoration: const BoxDecoration(
            color: Color(0xEEF8FBF6),
            border: Border(top: BorderSide(color: HeyBeanTheme.border)),
          ),
          child: Row(
            children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: _saving ? null : () => Navigator.of(context).pop(),
                  child: const Text('Cancel'),
                ),
              ),
              if (widget.onDelete != null) ...[
                const SizedBox(width: 12),
                IconButton.filled(
                  key: const Key('event-delete-action'),
                  tooltip: 'Delete event',
                  style: _destructiveIconButtonStyle(),
                  onPressed: _saving
                      ? null
                      : () async {
                          final deleteOptions =
                              await _confirmCalendarEventDelete();
                          if (!context.mounted || deleteOptions == null) {
                            return;
                          }
                          Navigator.of(context).pop({
                            'action': 'delete',
                            'deleteFromWorkspaceIds':
                                deleteOptions['deleteFromWorkspaceIds'],
                            'recurringDeleteMode':
                                deleteOptions['recurringDeleteMode'],
                            'recurringOccurrenceDate':
                                deleteOptions['recurringOccurrenceDate'],
                          });
                        },
                  icon: const Icon(Icons.delete_outline_rounded),
                ),
              ],
              const SizedBox(width: 12),
              Expanded(
                child: FilledButton.icon(
                  key: const Key('event-save-action'),
                  onPressed: _saving ? null : _save,
                  icon: _saving
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.check_rounded),
                  label: Text(_saving ? 'Saving...' : 'Save event'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _EventCategoryChip extends StatelessWidget {
  const _EventCategoryChip({
    required this.chipKey,
    required this.deleteKey,
    required this.editKey,
    required this.category,
    required this.color,
    required this.selected,
    required this.saving,
    required this.onSelected,
    required this.onEdited,
    required this.onDeleted,
  });

  final Key chipKey;
  final Key deleteKey;
  final Key editKey;
  final HermesEventCategory category;
  final Color color;
  final bool selected;
  final bool saving;
  final VoidCallback onSelected;
  final VoidCallback onEdited;
  final VoidCallback onDeleted;

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.white,
    shape: StadiumBorder(
      side: BorderSide(
        color: selected ? HeyBeanTheme.accent : HeyBeanTheme.border,
        width: selected ? 1.8 : 1.2,
      ),
    ),
    child: Padding(
      padding: const EdgeInsets.only(left: 4, right: 4, top: 4, bottom: 4),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          InkWell(
            key: chipKey,
            borderRadius: BorderRadius.circular(999),
            onTap: saving ? null : onSelected,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  CircleAvatar(radius: 6, backgroundColor: color),
                  const SizedBox(width: 7),
                  Text(
                    category.name,
                    style: TextStyle(
                      color: HeyBeanTheme.text,
                      fontWeight: selected ? FontWeight.w900 : FontWeight.w700,
                    ),
                  ),
                  if (selected) ...[
                    const SizedBox(width: 5),
                    Icon(
                      Icons.check_circle_rounded,
                      size: 15,
                      color: HeyBeanTheme.accent,
                    ),
                  ],
                ],
              ),
            ),
          ),
          if (category.id >= 0)
            IconButton(
              key: editKey,
              visualDensity: VisualDensity.compact,
              padding: EdgeInsets.zero,
              constraints: const BoxConstraints.tightFor(width: 28, height: 28),
              tooltip: 'Edit ${category.name}',
              onPressed: saving ? null : onEdited,
              icon: Icon(
                Icons.edit_rounded,
                size: 16,
                color: saving ? HeyBeanTheme.muted : HeyBeanTheme.text,
              ),
            ),
          IconButton(
            key: deleteKey,
            visualDensity: VisualDensity.compact,
            padding: EdgeInsets.zero,
            constraints: const BoxConstraints.tightFor(width: 28, height: 28),
            tooltip: 'Delete ${category.name}',
            onPressed: saving ? null : onDeleted,
            icon: Icon(
              Icons.close_rounded,
              size: 18,
              color: saving ? HeyBeanTheme.muted : HeyBeanTheme.destructive,
            ),
          ),
        ],
      ),
    ),
  );
}

class _ColorSwatchButton extends StatelessWidget {
  const _ColorSwatchButton({
    required this.label,
    required this.color,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final Color color;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
    button: true,
    selected: selected,
    label: '$label color',
    child: Material(
      color: Colors.transparent,
      shape: const CircleBorder(),
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: Container(
          width: 36,
          height: 36,
          padding: const EdgeInsets.all(4),
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            border: Border.all(
              color: selected ? HeyBeanTheme.accent : HeyBeanTheme.border,
              width: selected ? 2 : 1,
            ),
          ),
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: color,
              shape: BoxShape.circle,
              boxShadow: [
                BoxShadow(
                  color: color.withValues(alpha: .24),
                  blurRadius: 8,
                  offset: const Offset(0, 3),
                ),
              ],
            ),
            child: selected
                ? const Icon(Icons.check_rounded, color: Colors.white, size: 16)
                : null,
          ),
        ),
      ),
    ),
  );
}

class _EventCategoryCreateDialog extends StatefulWidget {
  const _EventCategoryCreateDialog({
    required this.initialColor,
    required this.colors,
    this.initialName,
    this.editing = false,
  });

  final String initialColor;
  final String? initialName;
  final bool editing;
  final List<({String value, String label})> colors;

  @override
  State<_EventCategoryCreateDialog> createState() =>
      _EventCategoryCreateDialogState();
}

class _EventCategoryCreateDialogState
    extends State<_EventCategoryCreateDialog> {
  late final TextEditingController _nameController;
  late String _selectedColor;
  late double _hue;
  String? _validationError;

  @override
  void initState() {
    super.initState();
    _nameController = TextEditingController(text: widget.initialName ?? '');
    _selectedColor = widget.initialColor.toUpperCase();
    final hsv = HSVColor.fromColor(_colorFromHex(_selectedColor));
    _hue = hsv.hue;
  }

  @override
  void dispose() {
    _nameController.dispose();
    super.dispose();
  }

  void _selectColor(String color) {
    final normalized = color.toUpperCase();
    final hsv = HSVColor.fromColor(_colorFromHex(normalized));
    setState(() {
      _selectedColor = normalized;
      _hue = hsv.hue;
    });
  }

  void _setHue(double hue) {
    setState(() {
      _hue = hue.clamp(0, 360);
      _selectedColor = _colorHexFromHue(_hue);
    });
  }

  void _submit() {
    final name = _nameController.text.trim();
    if (name.isEmpty) {
      setState(() => _validationError = 'Enter a category name.');
      return;
    }
    Navigator.of(context).pop({'name': name, 'color': _selectedColor});
  }

  @override
  Widget build(BuildContext context) {
    final previewColor = _colorFromHex(_selectedColor);
    return AlertDialog(
      key: const Key('event-category-create-modal'),
      title: Text(widget.editing ? 'Edit category' : 'New category'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            TextField(
              key: const Key('event-category-modal-name-field'),
              controller: _nameController,
              autofocus: true,
              textInputAction: TextInputAction.done,
              onSubmitted: (_) => _submit(),
              decoration: InputDecoration(
                labelText: 'Category name',
                prefixIcon: const Icon(Icons.sell_outlined),
                errorText: _validationError,
              ),
            ),
            const SizedBox(height: 14),
            _EventFieldLabel(icon: Icons.palette_outlined, label: 'Color'),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final color in widget.colors)
                  ChoiceChip(
                    label: Text(color.label),
                    selected: _selectedColor == color.value.toUpperCase(),
                    avatar: CircleAvatar(
                      radius: 6,
                      backgroundColor: _colorFromHex(color.value),
                    ),
                    onSelected: (_) => _selectColor(color.value),
                  ),
              ],
            ),
            const SizedBox(height: 16),
            Container(
              key: const Key('event-category-custom-color-preview'),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: previewColor.withValues(alpha: .14),
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: previewColor, width: 1.4),
              ),
              child: Row(
                children: [
                  CircleAvatar(radius: 14, backgroundColor: previewColor),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      _selectedColor,
                      style: const TextStyle(
                        color: HeyBeanTheme.text,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 12),
            _RainbowColorSlider(
              value: _hue.clamp(0, 360),
              selectedColor: previewColor,
              onChanged: _setHue,
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        if (widget.editing)
          FilledButton(
            key: const Key('event-category-modal-save-action'),
            onPressed: _submit,
            child: const Text('Save'),
          )
        else
          _ThemedPlusButton(
            key: const Key('event-category-modal-save-action'),
            tooltip: 'Create category',
            onPressed: _submit,
          ),
      ],
    );
  }
}

class _RainbowColorSlider extends StatelessWidget {
  const _RainbowColorSlider({
    required this.value,
    required this.selectedColor,
    required this.onChanged,
  });

  final double value;
  final Color selectedColor;
  final ValueChanged<double> onChanged;

  static const double _width = 280;
  static const double _height = 48;
  static const double _horizontalInset = 12;
  static const double _thumbSize = 28;

  static const _gradientColors = <Color>[
    Color(0xFFFF2D2D),
    Color(0xFFFF9500),
    Color(0xFFFFFF00),
    Color(0xFF00E436),
    Color(0xFF00D5FF),
    Color(0xFF1F5BFF),
    Color(0xFF9B00FF),
    Color(0xFFFF00CC),
    Color(0xFFFF2D2D),
  ];

  @override
  Widget build(BuildContext context) {
    final trackWidth = _width - _horizontalInset * 2;
    final left = _horizontalInset + trackWidth * (value.clamp(0, 360) / 360);
    return SizedBox(
      width: _width,
      height: _height,
      child: Stack(
        alignment: Alignment.center,
        children: [
          Positioned.fill(
            left: _horizontalInset,
            right: _horizontalInset,
            child: Center(
              child: Container(
                key: const Key('event-category-color-slider-gradient'),
                height: 10,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(999),
                  gradient: const LinearGradient(colors: _gradientColors),
                  boxShadow: [
                    BoxShadow(
                      color: selectedColor.withValues(alpha: .24),
                      blurRadius: 10,
                      offset: const Offset(0, 3),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Positioned(
            left: (left - _thumbSize / 2).clamp(0.0, _width - _thumbSize),
            top: 10,
            child: Container(
              key: const Key('event-category-color-slider-thumb'),
              width: _thumbSize,
              height: _thumbSize,
              decoration: BoxDecoration(
                color: selectedColor,
                shape: BoxShape.circle,
                border: Border.all(color: Colors.white, width: 2),
                boxShadow: [
                  BoxShadow(
                    color: selectedColor.withValues(alpha: .45),
                    blurRadius: 12,
                    offset: const Offset(0, 3),
                  ),
                ],
              ),
            ),
          ),
          SliderTheme(
            data: SliderTheme.of(context).copyWith(
              trackHeight: 10,
              activeTrackColor: Colors.transparent,
              inactiveTrackColor: Colors.transparent,
              thumbColor: Colors.transparent,
              overlayColor: selectedColor.withValues(alpha: .10),
              thumbShape: const RoundSliderThumbShape(enabledThumbRadius: 14),
              overlayShape: const RoundSliderOverlayShape(overlayRadius: 20),
            ),
            child: Slider(
              key: const Key('event-category-color-slider'),
              min: 0,
              max: 360,
              divisions: 360,
              value: value.clamp(0, 360),
              onChanged: onChanged,
            ),
          ),
        ],
      ),
    );
  }
}

class _EventFieldLabel extends StatelessWidget {
  const _EventFieldLabel({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Icon(icon, size: 18, color: HeyBeanTheme.accentStrong),
      const SizedBox(width: 8),
      Text(
        label,
        style: Theme.of(context).textTheme.labelLarge?.copyWith(
          color: HeyBeanTheme.text,
          fontWeight: FontWeight.w800,
        ),
      ),
    ],
  );
}

bool _isHexColor(String? value) =>
    value != null && RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(value);

Color _colorFromHex(String value) {
  if (!_isHexColor(value)) {
    return HeyBeanTheme.accentStrong;
  }
  return Color(int.parse('FF${value.substring(1)}', radix: 16));
}

String _colorHexFromHue(double hue) =>
    _hexFromColor(HSVColor.fromAHSV(1, hue.clamp(0, 360), .85, .95).toColor());

String _hexFromColor(Color color) {
  final red = (color.r * 255).round().clamp(0, 255);
  final green = (color.g * 255).round().clamp(0, 255);
  final blue = (color.b * 255).round().clamp(0, 255);
  return '#${red.toRadixString(16).padLeft(2, '0')}'
          '${green.toRadixString(16).padLeft(2, '0')}'
          '${blue.toRadixString(16).padLeft(2, '0')}'
      .toUpperCase();
}

Color _calendarEventColor(HermesCalendarEvent event) {
  final value = event.color;
  if (value == null) return _colorFromHex(_beanGreenCategoryColor);
  return _colorFromHex(value);
}

String _eventSubtitle(HermesCalendarEvent event) {
  final parts = <String>[
    if (event.startsAt != null || event.endsAt != null)
      _eventDateRangeLabel(startsAt: event.startsAt, endsAt: event.endsAt),
    if (event.category != null && event.category!.isNotEmpty) event.category!,
    if (event.recurrence != null && event.recurrence != 'none')
      event.recurrence!,
  ];
  return parts.isEmpty ? 'Unscheduled' : parts.join(' · ');
}

String _calendarEventBlockKey(HermesCalendarEvent event) {
  final slug = event.title
      .toLowerCase()
      .replaceAll(RegExp(r'[^a-z0-9]+'), '-')
      .replaceAll(RegExp(r'^-+|-+$'), '');
  return 'calendar-event-block-${slug.isEmpty ? event.id : slug}';
}

String _calendarEventBlockKeyForDay(HermesCalendarEvent event, DateTime day) {
  final base = _calendarEventBlockKey(event);
  final recurrence = (event.recurrence ?? 'none').toLowerCase();
  if (recurrence == 'none' || recurrence.isEmpty) return base;
  return '$base-${day.year}-${day.month}-${day.day}';
}

List<int> _calendarVisibleHours(int startHour, int endHour) {
  final start = startHour.clamp(0, 22);
  final end = endHour.clamp(start + 1, 23);
  return [for (var hour = start; hour <= end; hour++) hour];
}

List<int> _calendarVisibleHoursForEvents(
  List<HermesCalendarEvent> events,
  DateTime selectedDay,
  int startHour,
  int endHour,
) {
  var start = startHour.clamp(0, 22);
  var end = endHour.clamp(start + 1, 23);
  final days = [
    _dateOnly(selectedDay),
    _dateOnly(selectedDay.add(const Duration(days: 1))),
  ];

  for (final event in events) {
    if (_eventRendersAboveTimeline(event)) continue;
    for (final day in days) {
      final segment = _eventVisibleSegment(event, day, 0, 23);
      if (segment == null) continue;
      final startDecimal = _decimalHoursFromDayStart(segment.start, day);
      final endDecimal = _decimalHoursFromDayStart(segment.end, day);
      final segmentStartHour = startDecimal.floor().clamp(0, 22);
      if (segmentStartHour < start) start = segmentStartHour;
      final segmentEndHour = endDecimal.ceil().clamp(start + 1, 24) - 1;
      final boundedEndHour = segmentEndHour.clamp(start + 1, 23);
      if (boundedEndHour > end) end = boundedEndHour;
    }
  }

  return _calendarVisibleHours(start, end);
}

class _TimelineEventLayout {
  const _TimelineEventLayout({
    required this.event,
    required this.laneIndex,
    required this.laneCount,
  });

  final HermesCalendarEvent event;
  final int laneIndex;
  final int laneCount;
}

class _TimelineEventLayoutCandidate {
  const _TimelineEventLayoutCandidate({
    required this.event,
    required this.start,
    required this.end,
  });

  final HermesCalendarEvent event;
  final DateTime start;
  final DateTime end;
}

List<_TimelineEventLayout> _timelineEventLayoutsForDay(
  List<HermesCalendarEvent> events,
  DateTime day,
  int startHour,
  int endHour,
) {
  final candidates = <_TimelineEventLayoutCandidate>[];
  for (final event in events) {
    if (_eventRendersAboveTimeline(event)) continue;
    final segment = _eventVisibleSegment(event, day, startHour, endHour);
    if (segment == null) continue;
    candidates.add(
      _TimelineEventLayoutCandidate(
        event: event,
        start: segment.start,
        end: segment.end,
      ),
    );
  }
  candidates.sort((a, b) {
    final startComparison = a.start.compareTo(b.start);
    if (startComparison != 0) return startComparison;
    return a.end.compareTo(b.end);
  });

  final layouts = <_TimelineEventLayout>[];
  final group = <_TimelineEventLayoutCandidate>[];
  DateTime? groupEnd;

  void flushGroup() {
    if (group.isEmpty) return;
    final laneEnds = <DateTime>[];
    final assigned = <({HermesCalendarEvent event, int laneIndex})>[];
    for (final candidate in group) {
      var laneIndex = laneEnds.indexWhere(
        (laneEnd) => !candidate.start.isBefore(laneEnd),
      );
      if (laneIndex == -1) {
        laneIndex = laneEnds.length;
        laneEnds.add(candidate.end);
      } else {
        laneEnds[laneIndex] = candidate.end;
      }
      assigned.add((event: candidate.event, laneIndex: laneIndex));
    }
    final laneCount = math.max(1, laneEnds.length);
    for (final item in assigned) {
      layouts.add(
        _TimelineEventLayout(
          event: item.event,
          laneIndex: item.laneIndex,
          laneCount: laneCount,
        ),
      );
    }
    group.clear();
    groupEnd = null;
  }

  for (final candidate in candidates) {
    if (groupEnd != null && !candidate.start.isBefore(groupEnd!)) {
      flushGroup();
    }
    group.add(candidate);
    if (groupEnd == null || candidate.end.isAfter(groupEnd!)) {
      groupEnd = candidate.end;
    }
  }
  flushGroup();

  return layouts;
}

({DateTime start, DateTime end})? _eventVisibleSegment(
  HermesCalendarEvent event,
  DateTime day,
  int startHour,
  int endHour,
) {
  var start = _parseCalendarEventDateTime(event.startsAt);
  if (start == null) return null;
  var end =
      _parseCalendarEventDateTime(event.endsAt, event.startsAt) ??
      start.add(const Duration(minutes: 30));
  if (!end.isAfter(start)) {
    end = start.add(const Duration(minutes: 30));
  }

  final dayStart = DateTime(day.year, day.month, day.day);
  final recurrence = (event.recurrence ?? 'none').toLowerCase();
  if (recurrence != 'none' && recurrence.isNotEmpty) {
    if (!_eventFallsOnDay(event, dayStart)) return null;
    final duration = end.difference(start);
    final occurrenceStart = DateTime(
      dayStart.year,
      dayStart.month,
      dayStart.day,
      start.hour,
      start.minute,
      start.second,
      start.millisecond,
      start.microsecond,
    );
    start = occurrenceStart;
    end = occurrenceStart.add(duration);
  }

  final visibleStart = DateTime(day.year, day.month, day.day, startHour);
  final visibleEnd = DateTime(day.year, day.month, day.day, endHour + 1);
  if (!end.isAfter(visibleStart) || !start.isBefore(visibleEnd)) return null;

  final segmentStart = start.isAfter(visibleStart) ? start : visibleStart;
  final segmentEnd = end.isBefore(visibleEnd) ? end : visibleEnd;
  if (!segmentEnd.isAfter(segmentStart)) return null;
  return (start: segmentStart, end: segmentEnd);
}

double _decimalHoursFromDayStart(DateTime value, DateTime day) {
  final dayStart = DateTime(day.year, day.month, day.day);
  return value.difference(dayStart).inMinutes / 60;
}

String _hourLabel(int hour) {
  if (hour == 12) return 'Noon';
  if (hour < 12) return '$hour AM';
  return '${hour - 12} PM';
}

String _monthName(int month) => const [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
][month - 1];

String _shortWeekdayName(int weekday) =>
    const ['Mon', 'Tues', 'Wed', 'Thurs', 'Fri', 'Sat', 'Sun'][weekday - 1];

String _calendarHeaderDayLabel(DateTime date) =>
    '${_shortWeekdayName(date.weekday)} ${_ordinalDay(date.day)}';

String _ordinalDay(int day) {
  final teen = day % 100;
  if (teen >= 11 && teen <= 13) return '${day}th';
  return '$day${switch (day % 10) {
    1 => 'st',
    2 => 'nd',
    3 => 'rd',
    _ => 'th',
  }}';
}

String _calendarHeaderMonthLabel(DateTime date) =>
    "${_shortMonthName(date.month)} '${(date.year % 100).toString().padLeft(2, '0')}";

int? _weekdayNumber(String name) {
  final normalized = name.toLowerCase().replaceAll('.', '');
  const aliases = <String, int>{
    'mon': DateTime.monday,
    'monday': DateTime.monday,
    'tue': DateTime.tuesday,
    'tues': DateTime.tuesday,
    'tuesday': DateTime.tuesday,
    'wed': DateTime.wednesday,
    'weds': DateTime.wednesday,
    'wednesday': DateTime.wednesday,
    'thu': DateTime.thursday,
    'thur': DateTime.thursday,
    'thurs': DateTime.thursday,
    'thursday': DateTime.thursday,
    'fri': DateTime.friday,
    'friday': DateTime.friday,
    'sat': DateTime.saturday,
    'saturday': DateTime.saturday,
    'sun': DateTime.sunday,
    'sunday': DateTime.sunday,
  };
  return aliases[normalized];
}

String _shortMonthName(int month) => const [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
][month - 1];

int? _monthNumber(String name) {
  final normalized = name.toLowerCase();
  for (var index = 1; index <= 12; index++) {
    final full = _monthName(index).toLowerCase();
    final short = _shortMonthName(index).toLowerCase();
    if (normalized == full || normalized == short) return index;
  }
  return null;
}

String _formatCalendarEventDateTime(String? value) =>
    _formatNaturalDateTime(value);

String _formatCalendarEventDate(String? value) {
  final parsed = _parseCalendarEventDateTime(value);
  return parsed == null ? '' : _formatCalendarDateLabel(parsed);
}

String _formatCalendarEventEndDate(String? startsAt, String? endsAt) {
  final start = _parseCalendarEventDateTime(startsAt);
  final end = _parseCalendarEventDateTime(endsAt, startsAt);
  if (end == null) return start == null ? '' : _formatCalendarDateLabel(start);
  return _formatCalendarDateLabel(_displayEndDateForAllDay(start, end));
}

DateTime _displayEndDateForAllDay(DateTime? start, DateTime? end) {
  if (end == null) return _dateOnly(start ?? DateTime.now());
  final normalizedEnd = _dateOnly(end);
  final isExclusiveMidnight =
      end.hour == 0 &&
      end.minute == 0 &&
      end.second == 0 &&
      end.millisecond == 0 &&
      start != null &&
      end.isAfter(_dateOnly(start));
  return isExclusiveMidnight
      ? normalizedEnd.subtract(const Duration(days: 1))
      : normalizedEnd;
}

String _formatCalendarDateLabel(DateTime value) {
  final date = _dateOnly(value);
  return '${_shortMonthName(date.month)} ${date.day}, ${date.year}';
}

String _formatNaturalDateTime(String? value, {DateTime? now}) {
  if (value == null || value.trim().isEmpty) return '';
  final parsed = _parseCalendarEventDateTime(value);
  if (parsed == null) return value.trim();
  final anchor = _dateOnly(now ?? DateTime.now());
  final date = _dateOnly(parsed);
  final daysFromToday = date.difference(anchor).inDays;
  final time = _naturalTimeLabel(parsed);

  if (daysFromToday == 0) return 'today at $time';
  if (daysFromToday == 1) return 'tomorrow at $time';
  if (daysFromToday > 1 && daysFromToday < 7) {
    return '${_shortWeekdayName(parsed.weekday)} at $time';
  }
  final dateLabel = parsed.year == anchor.year
      ? '${_shortMonthName(parsed.month)} ${parsed.day}'
      : '${_shortMonthName(parsed.month)} ${parsed.day}, ${parsed.year}';
  return '$dateLabel at $time';
}

String _naturalTimeLabel(DateTime value) {
  var hour = value.hour % 12;
  if (hour == 0) hour = 12;
  final minute = value.minute == 0
      ? ''
      : ':${value.minute.toString().padLeft(2, '0')}';
  final meridiem = value.hour >= 12 ? 'pm' : 'am';
  return '$hour$minute$meridiem';
}

String _eventDateRangeLabel({String? startsAt, String? endsAt}) {
  final start = _parseCalendarEventDateTime(startsAt);
  final end = _parseCalendarEventDateTime(endsAt, startsAt);
  if (start == null && end == null) return 'Unscheduled';
  if (start == null) return _formatNaturalDateTime(endsAt);
  final startLabel = _formatNaturalDateTime(startsAt);
  if (end == null) return startLabel;
  final endLabel = _sameCalendarDay(start, end)
      ? _naturalTimeLabel(end)
      : _formatNaturalDateTime(endsAt);
  return '$startLabel – $endLabel';
}

String? _calendarEventWireValueToUtcIso(String? value) {
  final trimmed = value?.trim() ?? '';
  if (trimmed.isEmpty) return null;
  final parsed = _parseCalendarEventDateTime(trimmed);
  return parsed == null ? trimmed : parsed.toUtc().toIso8601String();
}

String? _calendarEventInputToWireValue(
  String value, {
  required String? originalValue,
  String? referenceValue,
  bool allowEmpty = false,
}) {
  final trimmed = value.trim();
  if (trimmed.isEmpty) return allowEmpty ? null : trimmed;

  final originalDisplay = _formatCalendarEventDateTime(originalValue);
  if (originalValue != null && trimmed == originalDisplay) {
    return _calendarEventWireValueToUtcIso(originalValue) ?? originalValue;
  }

  final parsed = _parseCalendarEventDateTime(
    trimmed,
    referenceValue ?? originalValue,
  );
  return parsed?.toUtc().toIso8601String() ?? trimmed;
}

DateTime? _calendarEventDateInputToDate(
  String? value, {
  String? originalValue,
  DateTime? referenceValue,
}) {
  final trimmed = value?.trim() ?? '';
  if (trimmed.isEmpty) {
    final original = _parseCalendarEventDateTime(originalValue);
    return original == null ? null : _dateOnly(original);
  }

  final parsed = _parseCalendarDateOnly(
    trimmed,
    referenceValue: referenceValue,
  );
  if (parsed != null) return parsed;

  final originalDisplay = _formatCalendarEventDate(originalValue);
  if (originalValue != null && trimmed == originalDisplay) {
    final original = _parseCalendarEventDateTime(originalValue);
    return original == null ? null : _dateOnly(original);
  }

  final parsedDateTime = _parseCalendarEventDateTime(trimmed);
  return parsedDateTime == null ? null : _dateOnly(parsedDateTime);
}

DateTime? _parseCalendarDateOnly(String value, {DateTime? referenceValue}) {
  final trimmed = value.trim();
  if (trimmed.isEmpty) return null;

  final isoMatch = RegExp(r'^(\d{4})-(\d{1,2})-(\d{1,2})$').firstMatch(trimmed);
  if (isoMatch != null) {
    final year = int.tryParse(isoMatch.group(1)!);
    final month = int.tryParse(isoMatch.group(2)!);
    final day = int.tryParse(isoMatch.group(3)!);
    if (year != null && month != null && day != null) {
      return DateTime(year, month, day);
    }
  }

  final relative = trimmed.toLowerCase();
  if (relative == 'today' || relative == 'tomorrow') {
    return _dateOnly(
      DateTime.now().add(
        relative == 'tomorrow' ? const Duration(days: 1) : Duration.zero,
      ),
    );
  }

  final friendlyMatch = RegExp(
    r'^(?:[A-Za-z]{3,9},?\s+)?([A-Za-z]{3,9})\s+(\d{1,2})(?:,?\s+(\d{4}))?$',
    caseSensitive: false,
  ).firstMatch(trimmed);
  if (friendlyMatch != null) {
    final month = _monthNumber(friendlyMatch.group(1)!);
    final day = int.tryParse(friendlyMatch.group(2)!);
    final year =
        int.tryParse(friendlyMatch.group(3) ?? '') ??
        referenceValue?.year ??
        DateTime.now().year;
    if (month != null && day != null) {
      return DateTime(year, month, day);
    }
  }

  return null;
}

String _dateTimeToWireIsoString(DateTime value) {
  if (value.isUtc) return value.toIso8601String();
  final offset = value.timeZoneOffset;
  final totalMinutes = offset.inMinutes;
  final sign = totalMinutes < 0 ? '-' : '+';
  final absoluteMinutes = totalMinutes.abs();
  final offsetLabel =
      '$sign${(absoluteMinutes ~/ 60).toString().padLeft(2, '0')}:${(absoluteMinutes % 60).toString().padLeft(2, '0')}';
  return '${value.toIso8601String()}$offsetLabel';
}

DateTime? _parseIsoDeviceLocalDateTime(String value) {
  final match = RegExp(
    r'^(\d{4})-(\d{2})-(\d{2})(?:[T\s](\d{2})(?::(\d{2}))?(?::(\d{2})(?:\.(\d{1,6}))?)?)?$',
  ).firstMatch(value);
  if (match == null) return null;
  final year = int.tryParse(match.group(1)!);
  final month = int.tryParse(match.group(2)!);
  final day = int.tryParse(match.group(3)!);
  if (year == null || month == null || day == null) return null;
  final hour = int.tryParse(match.group(4) ?? '0') ?? 0;
  final minute = int.tryParse(match.group(5) ?? '0') ?? 0;
  final second = int.tryParse(match.group(6) ?? '0') ?? 0;
  final fraction = (match.group(7) ?? '').padRight(6, '0');
  final microsecond = int.tryParse(fraction) ?? 0;
  return DateTime(
    year,
    month,
    day,
    hour,
    minute,
    second,
    microsecond ~/ 1000,
    microsecond % 1000,
  );
}

DateTime? _parseCalendarEventDateTime(String? value, [String? referenceValue]) {
  if (value == null || value.trim().isEmpty) return null;
  final trimmed = value.trim();
  final parsed = DateTime.tryParse(trimmed);
  if (parsed != null) return parsed.isUtc ? parsed.toLocal() : parsed;
  final isoWallClock = _parseIsoDeviceLocalDateTime(trimmed);
  if (isoWallClock != null) return isoWallClock;

  final relativeMatch = RegExp(
    r'^(today|tomorrow)\s*(?:@|·|at)?\s*(\d{1,2})(?::(\d{2}))?\s*([AP]M)$',
    caseSensitive: false,
  ).firstMatch(trimmed);
  if (relativeMatch != null) {
    final base = DateTime.now().add(
      relativeMatch.group(1)!.toLowerCase() == 'tomorrow'
          ? const Duration(days: 1)
          : Duration.zero,
    );
    var hour = int.tryParse(relativeMatch.group(2)!);
    final minute = int.tryParse(relativeMatch.group(3) ?? '0') ?? 0;
    final meridiem = relativeMatch.group(4)!.toUpperCase();
    if (hour != null) {
      if (meridiem == 'PM' && hour != 12) hour += 12;
      if (meridiem == 'AM' && hour == 12) hour = 0;
      return DateTime(base.year, base.month, base.day, hour, minute);
    }
  }

  final weekdayMatch = RegExp(
    r'^(mon(?:day)?|tue(?:s|sday)?|wed(?:s|nesday)?|thu(?:r|rs|rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)\.?\s*(?:@|·|at)?\s*'
    r'(\d{1,2})(?::(\d{2}))?\s*([AP]M)$',
    caseSensitive: false,
  ).firstMatch(trimmed);
  if (weekdayMatch != null) {
    final weekday = _weekdayNumber(weekdayMatch.group(1)!);
    var hour = int.tryParse(weekdayMatch.group(2)!);
    final minute = int.tryParse(weekdayMatch.group(3) ?? '0') ?? 0;
    final meridiem = weekdayMatch.group(4)!.toUpperCase();
    if (weekday != null && hour != null) {
      if (meridiem == 'PM' && hour != 12) hour += 12;
      if (meridiem == 'AM' && hour == 12) hour = 0;
      final today = _dateOnly(DateTime.now());
      final daysUntil = (weekday - today.weekday) % DateTime.daysPerWeek;
      final base = today.add(Duration(days: daysUntil));
      return DateTime(base.year, base.month, base.day, hour, minute);
    }
  }

  final friendlyMatch = RegExp(
    r'^(?:[A-Za-z]{3,9},?\s+)?([A-Za-z]{3,9})\s+(\d{1,2})\s*(?:@|·|at)?\s*'
    r'(\d{1,2})(?::(\d{2}))?\s*([AP]M)$',
    caseSensitive: false,
  ).firstMatch(trimmed);
  if (friendlyMatch != null) {
    final month = _monthNumber(friendlyMatch.group(1)!);
    final day = int.tryParse(friendlyMatch.group(2)!);
    var hour = int.tryParse(friendlyMatch.group(3)!);
    final minute = int.tryParse(friendlyMatch.group(4) ?? '0') ?? 0;
    final meridiem = friendlyMatch.group(5)!.toUpperCase();
    if (month != null && day != null && hour != null) {
      if (meridiem == 'PM' && hour != 12) hour += 12;
      if (meridiem == 'AM' && hour == 12) hour = 0;
      final reference = _parseCalendarEventDateTime(referenceValue);
      final year = reference?.toLocal().year ?? DateTime.now().year;
      return DateTime(year, month, day, hour, minute);
    }
  }

  final match = RegExp(
    r'^(\d{1,2})(?::(\d{2}))?\s*([AP]M)$',
    caseSensitive: false,
  ).firstMatch(trimmed);
  if (match == null) return null;
  var hour = int.parse(match.group(1)!);
  final minute = int.tryParse(match.group(2) ?? '0') ?? 0;
  final meridiem = match.group(3)!.toUpperCase();
  if (meridiem == 'PM' && hour != 12) hour += 12;
  if (meridiem == 'AM' && hour == 12) hour = 0;
  final reference =
      _parseCalendarEventDateTime(referenceValue) ?? DateTime.now();
  return DateTime(reference.year, reference.month, reference.day, hour, minute);
}

bool _sameCalendarDay(DateTime a, DateTime b) =>
    a.year == b.year && a.month == b.month && a.day == b.day;

DateTime _dateOnly(DateTime date) => DateTime(date.year, date.month, date.day);

String _calendarDateKey(DateTime date) =>
    '${date.year.toString().padLeft(4, '0')}-'
    '${date.month.toString().padLeft(2, '0')}-'
    '${date.day.toString().padLeft(2, '0')}';

DateTime? _parseCalendarDateKey(String? value) {
  if (value == null || value.trim().isEmpty) return null;
  final parts = value.trim().split('-');
  if (parts.length != 3) return null;
  final year = int.tryParse(parts[0]);
  final month = int.tryParse(parts[1]);
  final day = int.tryParse(parts[2]);
  if (year == null || month == null || day == null) return null;
  return DateTime(year, month, day);
}

bool _eventIsRecurring(HermesCalendarEvent event) {
  final recurrence = (event.recurrence ?? 'none').toLowerCase();
  return recurrence.isNotEmpty && recurrence != 'none';
}

Set<String> _recurringExceptionDates(HermesCalendarEvent event) {
  final raw =
      event.metadata?['recurring_exception_dates'] ??
      event.metadata?['recurringExceptionDates'] ??
      event.metadata?['recurrence_exceptions'];
  if (raw is! List) return const <String>{};
  return raw
      .map((value) => value.toString().trim())
      .where((value) => value.isNotEmpty)
      .toSet();
}

Map<String, Object?> _metadataAfterRecurringDelete(
  HermesCalendarEvent event,
  String mode,
  String occurrenceDate,
) {
  final metadata = <String, Object?>{...?event.metadata}
    ..remove('_delete_recurring_mode')
    ..remove('_delete_occurrence_date');
  if (mode == 'single') {
    final exceptions = _recurringExceptionDates(event).toList()
      ..add(occurrenceDate);
    exceptions.sort();
    metadata['recurring_exception_dates'] = exceptions.toSet().toList()..sort();
  } else if (mode == 'future') {
    metadata['recurrence_until'] = occurrenceDate;
  }
  return metadata;
}

bool _eventFallsOnDay(HermesCalendarEvent event, DateTime day) {
  final start = _parseCalendarEventDateTime(event.startsAt);
  if (start == null) return false;
  var end =
      _parseCalendarEventDateTime(event.endsAt, event.startsAt) ??
      start.add(const Duration(minutes: 30));
  if (!end.isAfter(start)) {
    end = start.add(const Duration(minutes: 30));
  }
  final dayStart = DateTime(day.year, day.month, day.day);
  final dayEnd = dayStart.add(const Duration(days: 1));
  final recurrence = (event.recurrence ?? 'none').toLowerCase();
  if (recurrence != 'none' && recurrence.isNotEmpty) {
    return _recurringEventFallsOnDay(event, start, end, dayStart, dayEnd);
  }
  return end.isAfter(dayStart) && start.isBefore(dayEnd);
}

bool _recurringEventFallsOnDay(
  HermesCalendarEvent event,
  DateTime start,
  DateTime end,
  DateTime dayStart,
  DateTime dayEnd,
) {
  final originalStartDay = DateTime(start.year, start.month, start.day);
  final dayKey = _calendarDateKey(dayStart);
  if (_recurringExceptionDates(event).contains(dayKey)) {
    return false;
  }
  final recurrenceUntil = _parseCalendarDateKey(
    event.metadata?['recurrence_until']?.toString() ??
        event.metadata?['recurrenceUntil']?.toString(),
  );
  if (recurrenceUntil != null && !dayStart.isBefore(recurrenceUntil)) {
    return false;
  }
  if (dayEnd.isBefore(originalStartDay) ||
      dayStart.isBefore(originalStartDay)) {
    return false;
  }
  if (_sameCalendarDay(dayStart, originalStartDay)) {
    return true;
  }

  final recurrence = (event.recurrence ?? 'none').toLowerCase();
  final interval = (event.metadata?['interval'] is num)
      ? ((event.metadata!['interval'] as num).toInt()).clamp(1, 365)
      : int.tryParse(event.metadata?['interval']?.toString() ?? '') ?? 1;
  final daysSinceStart = dayStart.difference(_dateOnly(start)).inDays;

  switch (recurrence) {
    case 'daily':
      return daysSinceStart % interval == 0;
    case 'weekly':
      return daysSinceStart >= 0 && daysSinceStart % (7 * interval) == 0;
    case 'monthly':
      final months =
          (dayStart.year - start.year) * 12 + (dayStart.month - start.month);
      return months >= 0 && months % interval == 0 && dayStart.day == start.day;
    case 'yearly':
      final years = dayStart.year - start.year;
      return years >= 0 &&
          years % interval == 0 &&
          dayStart.month == start.month &&
          dayStart.day == start.day;
    case 'specific_days':
      final days = event.metadata?['days'];
      final selectedDays = days is List
          ? days.map((day) => day.toString()).toSet()
          : <String>{};
      const weekdayKeys = {
        DateTime.monday: 'mon',
        DateTime.tuesday: 'tue',
        DateTime.wednesday: 'wed',
        DateTime.thursday: 'thu',
        DateTime.friday: 'fri',
        DateTime.saturday: 'sat',
        DateTime.sunday: 'sun',
      };
      return selectedDays.contains(weekdayKeys[dayStart.weekday]);
    case 'interval':
      final unit = (event.metadata?['unit'] ?? 'days').toString();
      if (unit == 'weeks') {
        return daysSinceStart >= 0 && daysSinceStart % (7 * interval) == 0;
      }
      if (unit == 'months') {
        final months =
            (dayStart.year - start.year) * 12 + (dayStart.month - start.month);
        return months >= 0 &&
            months % interval == 0 &&
            dayStart.day == start.day;
      }
      return daysSinceStart >= 0 && daysSinceStart % interval == 0;
    default:
      return end.isAfter(dayStart) && start.isBefore(dayEnd);
  }
}

class _MonthScroller extends StatefulWidget {
  const _MonthScroller({
    required this.selectedMonth,
    required this.onMonthSelected,
  });

  final DateTime selectedMonth;
  final ValueChanged<DateTime> onMonthSelected;

  @override
  State<_MonthScroller> createState() => _MonthScrollerState();
}

class _MonthScrollerState extends State<_MonthScroller> {
  ScrollController? _scrollController;

  @override
  void dispose() {
    _scrollController?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final selected = DateTime(
      widget.selectedMonth.year,
      widget.selectedMonth.month,
    );
    final currentMonth = DateTime(DateTime.now().year, DateTime.now().month);
    final months = List<DateTime>.generate(
      37,
      (index) => DateTime(currentMonth.year, currentMonth.month - 12 + index),
    );
    return LayoutBuilder(
      builder: (context, constraints) {
        final pillWidth = constraints.maxWidth / 6;
        _scrollController ??= ScrollController(
          initialScrollOffset: pillWidth * 12,
        );
        return SizedBox(
          key: const Key('calendar-month-scroller'),
          height: 48,
          child: SingleChildScrollView(
            controller: _scrollController,
            scrollDirection: Axis.horizontal,
            physics: const BouncingScrollPhysics(),
            child: Row(
              children: [
                for (var index = 0; index < months.length; index++)
                  Builder(
                    builder: (context) {
                      final month = months[index];
                      final isSelected =
                          month.year == selected.year &&
                          month.month == selected.month;
                      return SizedBox(
                        key: isSelected
                            ? const Key('calendar-month-pill-selected')
                            : Key('calendar-month-pill-$index'),
                        width: pillWidth,
                        child: Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 3),
                          child: InkWell(
                            borderRadius: BorderRadius.circular(18),
                            onTap: () => widget.onMonthSelected(month),
                            child: Container(
                              alignment: Alignment.center,
                              decoration: BoxDecoration(
                                color: isSelected
                                    ? HeyBeanTheme.accent
                                    : Colors.white,
                                borderRadius: BorderRadius.circular(18),
                                border: Border.all(
                                  color: isSelected
                                      ? HeyBeanTheme.accentStrong
                                      : HeyBeanTheme.border,
                                ),
                              ),
                              child: Column(
                                mainAxisAlignment: MainAxisAlignment.center,
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Text(
                                    _shortMonthName(month.month),
                                    style: TextStyle(
                                      color: isSelected
                                          ? Colors.white
                                          : HeyBeanTheme.text,
                                      fontSize: 12,
                                      fontWeight: FontWeight.w900,
                                      height: 1,
                                    ),
                                  ),
                                  const SizedBox(height: 2),
                                  Text(
                                    '${month.year}',
                                    style: TextStyle(
                                      color: isSelected
                                          ? Colors.white.withValues(alpha: .88)
                                          : HeyBeanTheme.muted,
                                      fontSize: 10,
                                      fontWeight: FontWeight.w800,
                                      height: 1,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                      );
                    },
                  ),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _MonthGrid extends StatelessWidget {
  const _MonthGrid({
    required this.calendar,
    required this.selectedDay,
    required this.onDateSelected,
  });

  final List<HermesCalendarEvent> calendar;
  final DateTime selectedDay;
  final ValueChanged<DateTime> onDateSelected;

  @override
  Widget build(BuildContext context) {
    final today = _dateOnly(DateTime.now());
    final visibleMonth = _dateOnly(selectedDay);
    final first = DateTime(visibleMonth.year, visibleMonth.month);
    final daysInMonth = DateTime(
      visibleMonth.year,
      visibleMonth.month + 1,
      0,
    ).day;
    final leadingBlanks = first.weekday % 7;
    final totalCells = leadingBlanks + daysInMonth;
    final rowCount = (totalCells / 7).ceil();
    final eventDays = <int>{};
    for (var day = 1; day <= daysInMonth; day++) {
      final date = DateTime(visibleMonth.year, visibleMonth.month, day);
      for (final event in calendar) {
        if (_eventFallsOnDay(event, date)) {
          eventDays.add(day);
          break;
        }
      }
    }

    const weekdays = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
    return Column(
      key: const Key('apple-style-month-grid'),
      children: [
        Row(
          children: [
            for (final weekday in weekdays)
              Expanded(
                child: Center(
                  child: Text(
                    weekday,
                    style: const TextStyle(
                      color: HeyBeanTheme.muted,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
              ),
          ],
        ),
        const SizedBox(height: 8),
        for (var row = 0; row < rowCount; row++) ...[
          Row(
            children: [
              for (var column = 0; column < 7; column++)
                Expanded(
                  child: Builder(
                    builder: (context) {
                      final day = _dayForCell(
                        row * 7 + column,
                        leadingBlanks,
                        daysInMonth,
                      );
                      final date = day == null
                          ? null
                          : DateTime(
                              visibleMonth.year,
                              visibleMonth.month,
                              day,
                            );
                      return _MonthDayCell(
                        day: day,
                        isToday: date != null && _sameCalendarDay(date, today),
                        isSelected:
                            date != null && _sameCalendarDay(date, selectedDay),
                        hasEvent: day != null && eventDays.contains(day),
                        onTap: date == null ? null : () => onDateSelected(date),
                      );
                    },
                  ),
                ),
            ],
          ),
          const SizedBox(height: 6),
        ],
      ],
    );
  }

  int? _dayForCell(int cell, int leadingBlanks, int daysInMonth) {
    final day = cell - leadingBlanks + 1;
    if (day < 1 || day > daysInMonth) return null;
    return day;
  }
}

class _MonthDayCell extends StatelessWidget {
  const _MonthDayCell({
    required this.day,
    required this.isToday,
    required this.isSelected,
    required this.hasEvent,
    required this.onTap,
  });

  final int? day;
  final bool isToday;
  final bool isSelected;
  final bool hasEvent;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final backgroundColor = isToday
        ? HeyBeanTheme.accent
        : isSelected
        ? HeyBeanTheme.accent.withValues(alpha: .10)
        : HeyBeanTheme.surface2;
    final borderColor = isToday || isSelected
        ? HeyBeanTheme.accentStrong
        : HeyBeanTheme.border;

    return InkWell(
      borderRadius: BorderRadius.circular(999),
      onTap: onTap,
      child: Container(
        height: 42,
        margin: const EdgeInsets.symmetric(horizontal: 2),
        decoration: BoxDecoration(
          color: backgroundColor,
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: borderColor),
        ),
        child: day == null
            ? const SizedBox.shrink()
            : Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    '$day',
                    style: TextStyle(
                      color: isToday ? Colors.white : HeyBeanTheme.text,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  if (hasEvent)
                    Container(
                      width: 5,
                      height: 5,
                      decoration: BoxDecoration(
                        color: isToday ? Colors.white : HeyBeanTheme.accent,
                        shape: BoxShape.circle,
                      ),
                    ),
                ],
              ),
      ),
    );
  }
}

class _CalendarAgenda extends StatelessWidget {
  const _CalendarAgenda({
    required this.calendar,
    required this.eventCategories,
    this.googleCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
    this.onEventTap,
    this.onEventCategorySaved,
    this.onEventCategoryDeleted,
  });

  final List<HermesCalendarEvent> calendar;
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? notes,
    String? location,
    String? status,
    String? category,
    String? color,
    String? recurrence,
    Map<String, Object?>? metadata,
    bool? isCritical,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
    int? workspaceId,
    List<Object> syncToWorkspaceIds,
  })?
  onEventTap;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })?
  onEventCategorySaved;
  final Future<void> Function(
    HermesEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })?
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(
        'Today / upcoming',
        style: Theme.of(
          context,
        ).textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 8),
      if (calendar.isEmpty)
        const _EmptySurface(label: 'No calendar events')
      else
        for (final event in calendar)
          _CompactItemTile(
            icon: Icons.event_available_rounded,
            title: event.title,
            subtitle: _eventSubtitle(event),
            onTap:
                onEventTap == null ||
                    onEventCategorySaved == null ||
                    onEventCategoryDeleted == null
                ? null
                : () => _showCalendarEventDetails(
                    context,
                    event,
                    eventCategories: eventCategories,
                    googleCalendarStatus: googleCalendarStatus,
                    workspaces: workspaces,
                    activeWorkspaceId: activeWorkspaceId,
                    onSave:
                        (
                          savedEvent, {
                          required String title,
                          required String startsAt,
                          String? endsAt,
                          String? notes,
                          String? location,
                          String? status,
                          String? category,
                          String? color,
                          String? recurrence,
                          Map<String, Object?>? metadata,
                          bool? isCritical,
                          int? reminderMinutesBefore,
                          String? reminderRecurrence,
                          List<String>? reminderSpecificDays,
                          int? reminderInterval,
                          String? reminderIntervalUnit,
                          int? workspaceId,
                          List<Object> syncToWorkspaceIds = const [],
                        }) => onEventTap!(
                          savedEvent,
                          title: title,
                          startsAt: startsAt,
                          endsAt: endsAt,
                          notes: notes,
                          location: location,
                          status: status,
                          category: category,
                          color: color,
                          recurrence: recurrence,
                          metadata: metadata,
                          isCritical: isCritical,
                          reminderMinutesBefore: reminderMinutesBefore,
                          reminderRecurrence: reminderRecurrence,
                          reminderSpecificDays: reminderSpecificDays,
                          reminderInterval: reminderInterval,
                          reminderIntervalUnit: reminderIntervalUnit,
                          syncToWorkspaceIds: syncToWorkspaceIds,
                        ),
                    onCriticalChanged: (savedEvent, isCritical) => onEventTap!(
                      savedEvent,
                      title: savedEvent.title,
                      startsAt:
                          savedEvent.startsAt ??
                          DateTime.now().toUtc().toIso8601String(),
                      endsAt: savedEvent.endsAt,
                      notes: savedEvent.notes,
                      location: savedEvent.location,
                      status: savedEvent.status,
                      category: savedEvent.category,
                      color: savedEvent.color,
                      recurrence: savedEvent.recurrence,
                      metadata: savedEvent.metadata,
                      isCritical: isCritical,
                    ),
                    onEventCategorySaved: onEventCategorySaved!,
                    onEventCategoryDeleted: onEventCategoryDeleted!,
                  ),
          ),
    ],
  );
}

Future<DateTime?> _showStandardDateTimeDock(
  BuildContext context, {
  required String initialText,
  String? originalValue,
  String? referenceValue,
  String keyPrefix = 'standard',
  bool dateOnly = false,
}) async {
  final parsed =
      _parseCalendarEventDateTime(initialText, referenceValue) ??
      _parseCalendarEventDateTime(originalValue, referenceValue) ??
      _parseCalendarEventDateTime(referenceValue) ??
      DateTime.now();
  final initialYear = parsed.year;
  final yearStart = initialYear - 1;
  final initialMonthIndex = parsed.month - 1;
  final initialDayIndex = parsed.day - 1;
  final initialYearIndex = initialYear - yearStart;
  final initialHourIndex = (parsed.hour % 12 == 0 ? 12 : parsed.hour % 12) - 1;
  final initialMinuteIndex = (parsed.minute / 5).round().clamp(0, 11);
  final initialMeridiemIndex = parsed.hour >= 12 ? 1 : 0;
  var selectedMonthIndex = initialMonthIndex;
  var selectedDayIndex = initialDayIndex;
  var selectedYearIndex = initialYearIndex;
  var selectedHourIndex = initialHourIndex;
  var selectedMinuteIndex = initialMinuteIndex;
  var selectedMeridiemIndex = initialMeridiemIndex;
  final monthController = FixedExtentScrollController(
    initialItem: initialMonthIndex,
  );
  final dayController = FixedExtentScrollController(
    initialItem: initialDayIndex,
  );
  final yearController = FixedExtentScrollController(
    initialItem: initialYearIndex,
  );
  final hourController = FixedExtentScrollController(
    initialItem: initialHourIndex,
  );
  final minuteController = FixedExtentScrollController(
    initialItem: initialMinuteIndex,
  );
  final meridiemController = FixedExtentScrollController(
    initialItem: initialMeridiemIndex,
  );

  try {
    return await showModalBottomSheet<DateTime>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (context) => SafeArea(
        top: false,
        child: Container(
          key: Key('$keyPrefix-time-dock'),
          margin: const EdgeInsets.all(12),
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
          decoration: BoxDecoration(
            color: HeyBeanTheme.surface,
            borderRadius: BorderRadius.circular(28),
            border: Border.all(color: HeyBeanTheme.border),
            boxShadow: const [
              BoxShadow(
                color: Color(0x26000000),
                blurRadius: 30,
                offset: Offset(0, 16),
              ),
            ],
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 44,
                height: 5,
                decoration: BoxDecoration(
                  color: HeyBeanTheme.border,
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
              const SizedBox(height: 14),
              Text(
                dateOnly ? 'Choose date' : 'Choose date and time',
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  color: HeyBeanTheme.text,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 12),
              SizedBox(
                height: 128,
                child: Row(
                  children: [
                    Expanded(
                      child: CupertinoPicker(
                        key: Key('$keyPrefix-date-month-dial'),
                        scrollController: monthController,
                        itemExtent: 36,
                        magnification: 1.05,
                        useMagnifier: true,
                        onSelectedItemChanged: (index) =>
                            selectedMonthIndex = index,
                        children: [
                          for (var month = 1; month <= 12; month++)
                            Center(child: Text(_monthName(month))),
                        ],
                      ),
                    ),
                    Expanded(
                      child: CupertinoPicker(
                        key: Key('$keyPrefix-date-day-dial'),
                        scrollController: dayController,
                        itemExtent: 36,
                        magnification: 1.05,
                        useMagnifier: true,
                        onSelectedItemChanged: (index) =>
                            selectedDayIndex = index,
                        children: [
                          for (var day = 1; day <= 31; day++)
                            Center(child: Text(day.toString())),
                        ],
                      ),
                    ),
                    Expanded(
                      child: CupertinoPicker(
                        key: Key('$keyPrefix-date-year-dial'),
                        scrollController: yearController,
                        itemExtent: 36,
                        magnification: 1.05,
                        useMagnifier: true,
                        onSelectedItemChanged: (index) =>
                            selectedYearIndex = index,
                        children: [
                          for (
                            var year = yearStart;
                            year <= yearStart + 4;
                            year++
                          )
                            Center(child: Text(year.toString())),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              if (!dateOnly) ...[
                const SizedBox(height: 12),
                SizedBox(
                  height: 190,
                  child: Row(
                    children: [
                      Expanded(
                        child: CupertinoPicker(
                          key: Key('$keyPrefix-time-hour-dial'),
                          scrollController: hourController,
                          itemExtent: 42,
                          magnification: 1.08,
                          useMagnifier: true,
                          looping: true,
                          onSelectedItemChanged: (index) =>
                              selectedHourIndex = index % 12,
                          children: [
                            for (var hour = 1; hour <= 12; hour++)
                              Center(child: Text(hour.toString())),
                          ],
                        ),
                      ),
                      Text(
                        ':',
                        style: Theme.of(context).textTheme.headlineSmall
                            ?.copyWith(fontWeight: FontWeight.w900),
                      ),
                      Expanded(
                        child: CupertinoPicker(
                          key: Key('$keyPrefix-time-minute-dial'),
                          scrollController: minuteController,
                          itemExtent: 42,
                          magnification: 1.08,
                          useMagnifier: true,
                          looping: true,
                          onSelectedItemChanged: (index) =>
                              selectedMinuteIndex = index % 12,
                          children: [
                            for (var minute = 0; minute < 60; minute += 5)
                              Center(
                                child: Text(minute.toString().padLeft(2, '0')),
                              ),
                          ],
                        ),
                      ),
                      Expanded(
                        child: CupertinoPicker(
                          key: Key('$keyPrefix-time-meridiem-dial'),
                          scrollController: meridiemController,
                          itemExtent: 42,
                          magnification: 1.08,
                          useMagnifier: true,
                          onSelectedItemChanged: (index) =>
                              selectedMeridiemIndex = index,
                          children: const [
                            Center(child: Text('AM')),
                            Center(child: Text('PM')),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ],
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () => Navigator.of(context).pop(),
                      child: const Text('Cancel'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: FilledButton(
                      key: Key('$keyPrefix-time-dock-done'),
                      onPressed: () {
                        final year = yearStart + selectedYearIndex;
                        final month = selectedMonthIndex + 1;
                        final maxDay = DateTime(year, month + 1, 0).day;
                        final day = (selectedDayIndex + 1).clamp(1, maxDay);
                        if (dateOnly) {
                          Navigator.of(context).pop(DateTime(year, month, day));
                          return;
                        }
                        final hour12 = selectedHourIndex + 1;
                        final minute = selectedMinuteIndex * 5;
                        var hour24 = hour12 % 12;
                        if (selectedMeridiemIndex == 1) hour24 += 12;
                        Navigator.of(
                          context,
                        ).pop(DateTime(year, month, day, hour24, minute));
                      },
                      child: const Text('Done'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  } finally {
    monthController.dispose();
    dayController.dispose();
    yearController.dispose();
    hourController.dispose();
    minuteController.dispose();
    meridiemController.dispose();
  }
}

const _titleTimeEditorRecurrences = <({String value, String label})>[
  (value: 'none', label: 'None'),
  (value: 'daily', label: 'Daily'),
  (value: 'weekly', label: 'Weekly'),
  (value: 'monthly', label: 'Monthly'),
  (value: 'yearly', label: 'Yearly'),
  (value: 'specific_days', label: 'Specific days'),
  (value: 'interval', label: 'Every X'),
];

const _titleTimeEditorWeekdays = <({String value, String label})>[
  (value: 'mon', label: 'Mon'),
  (value: 'tue', label: 'Tue'),
  (value: 'wed', label: 'Wed'),
  (value: 'thu', label: 'Thu'),
  (value: 'fri', label: 'Fri'),
  (value: 'sat', label: 'Sat'),
  (value: 'sun', label: 'Sun'),
];

const _titleTimeEditorIntervalUnits = <({String value, String label})>[
  (value: 'days', label: 'days'),
  (value: 'weeks', label: 'weeks'),
  (value: 'months', label: 'months'),
];

const _titleTimeEditorCategoryColors = <({String value, String label})>[
  (value: _beanGreenCategoryColor, label: 'Green'),
  (value: '#007AFF', label: 'Blue'),
  (value: '#FF9500', label: 'Orange'),
  (value: '#AF52DE', label: 'Purple'),
  (value: '#FF3B30', label: 'Red'),
];

String _recurrenceFromMetadata(Map<String, Object?>? metadata) {
  final value = metadata?['recurrence']?.toString() ?? 'none';
  return _titleTimeEditorRecurrences.any(
        (recurrence) => recurrence.value == value,
      )
      ? value
      : 'none';
}

Set<String> _recurrenceDaysFromMetadata(Map<String, Object?>? metadata) =>
    ((metadata?['days'] ??
                    metadata?['specific_days'] ??
                    metadata?['specificDays'])
                as List? ??
            const <Object?>[])
        .map((value) => value.toString())
        .where(
          (value) => _titleTimeEditorWeekdays.any((day) => day.value == value),
        )
        .toSet();

String _recurrenceIntervalUnitFromMetadata(Map<String, Object?>? metadata) {
  final value =
      metadata?['unit']?.toString() ??
      metadata?['interval_unit']?.toString() ??
      metadata?['intervalUnit']?.toString() ??
      'days';
  return _titleTimeEditorIntervalUnits.any((unit) => unit.value == value)
      ? value
      : 'days';
}

Map<String, Object?> _metadataWithRecurrence(
  Map<String, Object?>? existing, {
  required String recurrence,
  required Iterable<String> days,
  required int interval,
  required String unit,
}) {
  final metadata = <String, Object?>{...?existing};
  metadata
    ..remove('days')
    ..remove('specific_days')
    ..remove('specificDays')
    ..remove('interval')
    ..remove('unit')
    ..remove('interval_unit')
    ..remove('intervalUnit')
    ..['recurrence'] = recurrence;
  if (recurrence == 'specific_days') {
    metadata['days'] = days.toList()..sort();
  }
  if (recurrence == 'specific_days' || recurrence == 'interval') {
    metadata['interval'] = interval;
    metadata['unit'] = unit;
  }
  return metadata;
}

Future<Map<String, Object?>?> _showTitleTimeEditor(
  BuildContext context, {
  required String title,
  required String titleLabel,
  required String timeLabel,
  required String initialTitle,
  required String initialTime,
  IconData? editorIcon,
  String? editorSubtitle,
  String? primarySectionTitle,
  String? primarySectionSubtitle,
  String initialNotes = '',
  required bool allowEmptyTime,
  List<HermesEventCategory> categories = const [],
  String? initialCategory,
  String? initialColor,
  String? deleteLabel,
  String? completeLabel,
  bool initialCritical = false,
  bool showCritical = true,
  bool showNotes = false,
  bool showTimeTextField = true,
  bool showRecurrence = false,
  String recurrenceTitle = 'Recurrence',
  String recurrenceSubtitle = 'Repeat this item when needed.',
  String recurrenceInfoTitle = 'Recurrence',
  Map<String, Object?>? initialMetadata,
  Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })?
  onEventCategorySaved,
  List<HermesWorkspace> workspaces = const [],
  String? activeWorkspaceId,
  bool showPrimaryWorkspaceSelector = false,
  Object? initialPrimaryWorkspaceId,
  GoogleCalendarSyncStatus? googleCalendarStatus,
  List<String> initialGoogleCalendarIds = const [],
  List<Object> initialSyncWorkspaceIds = const [],
  Future<void> Function(Map<String, Object?> result)? onSave,
}) async {
  final titleController = TextEditingController(text: initialTitle);
  final timeController = TextEditingController(text: initialTime);
  final notesController = TextEditingController(text: initialNotes);
  var selectedCategory = initialCategory?.trim() ?? '';
  var selectedColor = selectedCategory.isEmpty
      ? _themeCategoryColorHex()
      : initialColor?.trim() ?? _themeCategoryColorHex();
  var modalCategories = [...categories];
  var savingCategory = false;
  var saving = false;
  var isCritical = initialCritical;
  var recurrence = _recurrenceFromMetadata(initialMetadata);
  final recurrenceSpecificDays = _recurrenceDaysFromMetadata(initialMetadata);
  final recurrenceIntervalController = TextEditingController(
    text: initialMetadata?['interval']?.toString() ?? '1',
  );
  var recurrenceIntervalUnit = _recurrenceIntervalUnitFromMetadata(
    initialMetadata,
  );
  final syncWorkspaceIds = <Object>{...initialSyncWorkspaceIds};
  Object? selectedPrimaryWorkspaceId = initialPrimaryWorkspaceId;
  final googleCalendarIds = <String>{...initialGoogleCalendarIds};
  final writableGoogleCalendars =
      googleCalendarStatus?.writableCalendars ?? const <GoogleCalendarInfo>[];
  String? validationError;
  final isReminderEditor = titleLabel.toLowerCase().contains('reminder');
  final resolvedEditorIcon =
      editorIcon ??
      (isReminderEditor
          ? Icons.notifications_active_outlined
          : Icons.task_alt_rounded);
  final resolvedEditorSubtitle = (editorSubtitle?.trim().isNotEmpty ?? false)
      ? editorSubtitle!.trim()
      : isReminderEditor
      ? 'Time-sensitive nudge with optional repeat'
      : title.toLowerCase().contains('sub-task')
      ? 'Assigned to its parent task'
      : 'Keep the task lightweight, dated, and organized';
  final resolvedPrimarySectionTitle =
      primarySectionTitle ??
      (isReminderEditor ? 'Reminder basics' : 'Task basics');
  final resolvedPrimarySectionSubtitle =
      primarySectionSubtitle ??
      (isReminderEditor
          ? 'Title and required reminder time'
          : 'Title and optional due date');
  final actionLabel = deleteLabel == null ? 'Create' : 'Save';

  Map<String, Object?>? buildPayload(
    StateSetter setModalState, {
    bool complete = false,
  }) {
    final title = titleController.text.trim();
    final time = timeController.text.trim();
    if (title.isEmpty) {
      setModalState(() => validationError = 'A title is required.');
      return null;
    }
    if (!allowEmptyTime && time.isEmpty) {
      setModalState(() => validationError = 'A time is required.');
      return null;
    }
    if (time.isNotEmpty && _taskReminderInputToWireValue(time) == null) {
      setModalState(
        () => validationError =
            'Use a recognizable date/time, like Today 5:00 PM.',
      );
      return null;
    }
    Object? payloadPrimaryWorkspaceId = selectedPrimaryWorkspaceId;
    final payloadSyncWorkspaceIds = syncWorkspaceIds.toList();
    if (showPrimaryWorkspaceSelector && workspaces.isNotEmpty) {
      if (payloadPrimaryWorkspaceId == null &&
          payloadSyncWorkspaceIds.isNotEmpty) {
        payloadPrimaryWorkspaceId = payloadSyncWorkspaceIds.removeAt(0);
      }
      if (payloadPrimaryWorkspaceId == null) {
        setModalState(() => validationError = 'Choose at least one workspace.');
        return null;
      }
      payloadSyncWorkspaceIds.removeWhere(
        (workspaceId) =>
            _workspaceValuesMatch(workspaceId, payloadPrimaryWorkspaceId),
      );
    }

    return {
      'title': title,
      'time': time.isEmpty ? null : time,
      'notes': notesController.text.trim().isEmpty
          ? null
          : notesController.text.trim(),
      if (complete) 'complete': true,
      'category': selectedCategory.isEmpty ? null : selectedCategory,
      'color': selectedCategory.isEmpty
          ? _themeCategoryColorHex()
          : (selectedColor.isEmpty ? _themeCategoryColorHex() : selectedColor),
      'isCritical': isCritical,
      if (showRecurrence)
        'recurrenceMetadata': _metadataWithRecurrence(
          initialMetadata,
          recurrence: recurrence,
          days: recurrenceSpecificDays,
          interval: int.tryParse(recurrenceIntervalController.text.trim()) ?? 1,
          unit: recurrenceIntervalUnit,
        ),
      'syncToWorkspaceIds': payloadSyncWorkspaceIds,
      if (showPrimaryWorkspaceSelector)
        'workspaceId': _workspaceValueToInt(payloadPrimaryWorkspaceId),
      'googleCalendarIds': googleCalendarIds.toList()..sort(),
    };
  }

  Future<void> submitPayload(
    BuildContext context,
    StateSetter setModalState, {
    bool complete = false,
  }) async {
    if (saving) return;
    final payload = buildPayload(setModalState, complete: complete);
    if (payload == null) return;
    if (onSave == null) {
      Navigator.of(context).pop(payload);
      return;
    }

    setModalState(() {
      saving = true;
      validationError = null;
    });
    try {
      await onSave(payload);
      if (context.mounted) {
        Navigator.of(context).pop(payload);
      }
    } catch (error) {
      if (!context.mounted) return;
      setModalState(() {
        saving = false;
        validationError = beanFriendlyErrorMessage(
          error,
          action: 'save that change',
        );
      });
    }
  }

  Future<void> chooseDateTime(
    BuildContext pickerContext,
    StateSetter setModalState,
  ) async {
    final selected = await _showStandardDateTimeDock(
      pickerContext,
      initialText: timeController.text,
      originalValue: initialTime,
      keyPrefix: 'title-time',
    );
    if (selected == null) return;
    setModalState(() {
      timeController.text = _formatCalendarEventDateTime(
        selected.toIso8601String(),
      );
      validationError = null;
    });
  }

  return showModalBottomSheet<Map<String, Object?>>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    backgroundColor: Colors.transparent,
    builder: (context) => StatefulBuilder(
      builder: (context, setModalState) {
        final syncPrimaryWorkspaceId = showPrimaryWorkspaceSelector
            ? selectedPrimaryWorkspaceId
            : _workspaceValueForId(workspaces, activeWorkspaceId);
        final syncTargets = workspaces
            .where(
              (workspace) => !_workspaceValuesMatch(
                _workspaceValue(workspace),
                syncPrimaryWorkspaceId,
              ),
            )
            .toList();
        final workspaceChoices = showPrimaryWorkspaceSelector
            ? workspaces
            : syncTargets;
        final categoryDropdownValues = <HermesEventCategory>[
          ...modalCategories,
        ];
        if (selectedCategory.isNotEmpty &&
            !categoryDropdownValues.any(
              (category) =>
                  category.name.toLowerCase() == selectedCategory.toLowerCase(),
            )) {
          categoryDropdownValues.add(
            HermesEventCategory(
              id: -1,
              name: selectedCategory,
              color: selectedColor,
            ),
          );
        }
        categoryDropdownValues.sort(
          (a, b) => a.name.toLowerCase().compareTo(b.name.toLowerCase()),
        );
        final mediaQuery = MediaQuery.of(context);
        final topInset = mediaQuery.padding.top;
        return Padding(
          padding: EdgeInsets.only(bottom: mediaQuery.viewInsets.bottom),
          child: Container(
            height: mediaQuery.size.height - topInset,
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
            decoration: const BoxDecoration(
              color: HeyBeanTheme.surface,
              borderRadius: BorderRadius.vertical(top: Radius.circular(26)),
              border: Border(top: BorderSide(color: HeyBeanTheme.border)),
            ),
            child: SafeArea(
              top: false,
              child: Stack(
                children: [
                  Positioned.fill(
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.only(bottom: 120),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          _FormEditorHeader(
                            icon: resolvedEditorIcon,
                            title: title,
                            subtitle: resolvedEditorSubtitle,
                          ),
                          const SizedBox(height: 14),
                          _MobileFormSection(
                            title: resolvedPrimarySectionTitle,
                            subtitle: resolvedPrimarySectionSubtitle,
                            icon: resolvedEditorIcon,
                            primary: true,
                            children: [
                              TextFormField(
                                key: const Key('title-time-editor-title'),
                                controller: titleController,
                                textInputAction: TextInputAction.next,
                                decoration: InputDecoration(
                                  labelText: titleLabel,
                                ),
                              ),
                              if (showTimeTextField)
                                TextFormField(
                                  key: const Key('title-time-editor-time'),
                                  controller: timeController,
                                  readOnly: true,
                                  onTap: () =>
                                      chooseDateTime(context, setModalState),
                                  decoration: InputDecoration(
                                    labelText: timeLabel,
                                    helperText: allowEmptyTime
                                        ? 'Optional · tap to choose date and time'
                                        : 'Required · tap to choose date and time',
                                    suffixIcon: IconButton(
                                      key: const Key(
                                        'title-time-editor-open-picker',
                                      ),
                                      tooltip: 'Choose date and time',
                                      onPressed: () => chooseDateTime(
                                        context,
                                        setModalState,
                                      ),
                                      icon: const Icon(
                                        Icons.calendar_month_rounded,
                                      ),
                                    ),
                                  ),
                                )
                              else
                                Material(
                                  key: const Key(
                                    'title-time-editor-selected-time-label',
                                  ),
                                  borderRadius: BorderRadius.circular(999),
                                  color: Colors.transparent,
                                  child: InkWell(
                                    borderRadius: BorderRadius.circular(999),
                                    onTap: () =>
                                        chooseDateTime(context, setModalState),
                                    child: Container(
                                      padding: const EdgeInsets.symmetric(
                                        horizontal: 12,
                                        vertical: 10,
                                      ),
                                      decoration: BoxDecoration(
                                        color: Colors.white.withValues(
                                          alpha: .72,
                                        ),
                                        borderRadius: BorderRadius.circular(
                                          999,
                                        ),
                                        border: Border.all(
                                          color: const Color(0x1A1C314E),
                                        ),
                                      ),
                                      child: Row(
                                        children: [
                                          Icon(
                                            Icons.schedule_rounded,
                                            size: 18,
                                            color: HeyBeanTheme.accentStrong,
                                          ),
                                          const SizedBox(width: 8),
                                          Expanded(
                                            child: Text(
                                              timeController.text.trim().isEmpty
                                                  ? 'No date and time selected'
                                                  : timeController.text.trim(),
                                              style: TextStyle(
                                                color:
                                                    timeController.text
                                                        .trim()
                                                        .isEmpty
                                                    ? HeyBeanTheme.muted
                                                    : HeyBeanTheme.text,
                                                fontWeight: FontWeight.w700,
                                              ),
                                            ),
                                          ),
                                          const Icon(
                                            Icons.calendar_month_rounded,
                                            size: 18,
                                            color: HeyBeanTheme.muted,
                                          ),
                                        ],
                                      ),
                                    ),
                                  ),
                                ),
                              if (showCritical)
                                _MobileFormSwitch(
                                  widgetKey: const Key(
                                    'title-time-editor-critical-toggle',
                                  ),
                                  value: isCritical,
                                  onChanged: (selected) => setModalState(
                                    () => isCritical = selected,
                                  ),
                                  icon: isCritical
                                      ? Icons.star_rounded
                                      : Icons.star_border_rounded,
                                  title: 'Critical',
                                  subtitle:
                                      'Keep this visible in today’s priority view.',
                                ),
                            ],
                          ),
                          if (showNotes) ...[
                            const SizedBox(height: 12),
                            _MobileFormSection(
                              title: 'Details',
                              subtitle: 'Notes and importance',
                              iconWidget: _BeanNotesIcon(
                                size: 18,
                                color: HeyBeanTheme.accentStrong,
                              ),
                              children: [
                                TextFormField(
                                  key: const Key('title-time-editor-notes'),
                                  controller: notesController,
                                  minLines: 3,
                                  maxLines: 6,
                                  decoration: _longFormInputDecoration(
                                    labelText: 'Notes',
                                    hintText: 'Add task details',
                                    prefixIcon: const _BeanNotesIcon(),
                                  ),
                                ),
                              ],
                            ),
                          ],
                          if (modalCategories.isNotEmpty ||
                              onEventCategorySaved != null) ...[
                            const SizedBox(height: 12),
                            _MobileFormSection(
                              title: 'Organize',
                              subtitle: 'Category, color, and workspace',
                              icon: Icons.category_outlined,
                              children: [
                                Row(
                                  children: [
                                    const Expanded(
                                      child: Text(
                                        'Category',
                                        style: TextStyle(
                                          color: HeyBeanTheme.text,
                                          fontWeight: FontWeight.w900,
                                        ),
                                      ),
                                    ),
                                    if (onEventCategorySaved != null)
                                      IconButton.filledTonal(
                                        key: const Key(
                                          'title-time-editor-category-add-action',
                                        ),
                                        tooltip: 'Create category',
                                        onPressed: savingCategory
                                            ? null
                                            : () async {
                                                final categoryValues =
                                                    await showDialog<
                                                      Map<String, String>
                                                    >(
                                                      context: context,
                                                      builder: (context) =>
                                                          const _EventCategoryCreateDialog(
                                                            initialColor:
                                                                _beanGreenCategoryColor,
                                                            colors:
                                                                _titleTimeEditorCategoryColors,
                                                          ),
                                                    );
                                                if (categoryValues == null) {
                                                  return;
                                                }
                                                final name =
                                                    categoryValues['name']
                                                        ?.trim() ??
                                                    '';
                                                final color =
                                                    categoryValues['color']
                                                        ?.trim() ??
                                                    _beanGreenCategoryColor;
                                                if (name.isEmpty) return;
                                                setModalState(
                                                  () => savingCategory = true,
                                                );
                                                try {
                                                  final saved =
                                                      await onEventCategorySaved(
                                                        name: name,
                                                        color: color,
                                                      );
                                                  setModalState(() {
                                                    modalCategories = [
                                                      ...modalCategories.where(
                                                        (item) =>
                                                            item.id != saved.id,
                                                      ),
                                                      saved,
                                                    ];
                                                    selectedCategory =
                                                        saved.name;
                                                    selectedColor = saved.color;
                                                    savingCategory = false;
                                                  });
                                                } catch (_) {
                                                  setModalState(() {
                                                    savingCategory = false;
                                                    validationError =
                                                        'Could not create category.';
                                                  });
                                                }
                                              },
                                        icon: const Icon(Icons.add_rounded),
                                      ),
                                  ],
                                ),
                                KeyedSubtree(
                                  key: ValueKey(
                                    'title-time-editor-category-${selectedCategory.toLowerCase()}',
                                  ),
                                  child: DropdownButtonFormField<String>(
                                    key: const Key(
                                      'title-time-editor-category-select',
                                    ),
                                    initialValue: selectedCategory.isEmpty
                                        ? ''
                                        : selectedCategory,
                                    decoration: const InputDecoration(
                                      labelText: 'Category',
                                      prefixIcon: Icon(Icons.category_outlined),
                                    ),
                                    isExpanded: true,
                                    items: [
                                      const DropdownMenuItem<String>(
                                        key: Key(
                                          'title-time-editor-category-none',
                                        ),
                                        value: '',
                                        child: Text('No category'),
                                      ),
                                      for (final category
                                          in categoryDropdownValues)
                                        DropdownMenuItem<String>(
                                          key: Key(
                                            'title-time-editor-category-${category.name.toLowerCase().replaceAll(' ', '-')}',
                                          ),
                                          value: category.name,
                                          child: Row(
                                            mainAxisSize: MainAxisSize.min,
                                            children: [
                                              CircleAvatar(
                                                key: Key(
                                                  'title-time-editor-category-dot-${category.name.toLowerCase().replaceAll(' ', '-')}',
                                                ),
                                                radius: 6,
                                                backgroundColor:
                                                    _safeCategoryColor(
                                                      category.color,
                                                    ),
                                              ),
                                              const SizedBox(width: 8),
                                              Text(
                                                category.name,
                                                overflow: TextOverflow.ellipsis,
                                              ),
                                            ],
                                          ),
                                        ),
                                    ],
                                    onChanged: saving
                                        ? null
                                        : (value) => setModalState(() {
                                            final nextValue = value ?? '';
                                            if (nextValue.isEmpty) {
                                              selectedCategory = '';
                                              selectedColor =
                                                  _themeCategoryColorHex();
                                              return;
                                            }
                                            final category =
                                                categoryDropdownValues
                                                    .where(
                                                      (item) =>
                                                          item.name ==
                                                          nextValue,
                                                    )
                                                    .firstOrNull;
                                            selectedCategory = nextValue;
                                            selectedColor =
                                                category?.color ??
                                                selectedColor;
                                          }),
                                  ),
                                ),
                                _EventFieldLabel(
                                  icon: Icons.palette_outlined,
                                  label: 'Color',
                                ),
                                Wrap(
                                  spacing: 10,
                                  runSpacing: 10,
                                  children: [
                                    for (final color
                                        in _titleTimeEditorCategoryColors)
                                      _ColorSwatchButton(
                                        label: color.label,
                                        color: _colorFromHex(color.value),
                                        selected:
                                            selectedColor.toUpperCase() ==
                                            color.value.toUpperCase(),
                                        onTap: () => setModalState(() {
                                          selectedColor = color.value;
                                        }),
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          ],
                          if (showRecurrence) ...[
                            const SizedBox(height: 12),
                            _MobileFormSection(
                              key: const Key(
                                'title-time-editor-recurrence-field',
                              ),
                              title: 'Repeat',
                              subtitle:
                                  'Make this repeat when it should come back',
                              icon: Icons.repeat_rounded,
                              infoKey: const Key(
                                'title-time-editor-recurrence-info',
                              ),
                              infoTitle: recurrenceInfoTitle,
                              infoBullets: const [
                                'Choose None for a one-time item.',
                                'Specific days repeats on the weekdays you select.',
                                'Every X lets you build patterns like every 2 weeks or every 3 months.',
                              ],
                              children: [
                                _EventFieldLabel(
                                  icon: Icons.repeat_on_rounded,
                                  label: recurrenceTitle,
                                ),
                                Text(
                                  recurrenceSubtitle,
                                  style: const TextStyle(
                                    color: HeyBeanTheme.muted,
                                    fontSize: 12,
                                    height: 1.35,
                                    fontWeight: FontWeight.w700,
                                  ),
                                ),
                                Wrap(
                                  spacing: 8,
                                  runSpacing: 8,
                                  children: [
                                    for (final option
                                        in _titleTimeEditorRecurrences)
                                      ChoiceChip(
                                        label: Text(option.label),
                                        selected: recurrence == option.value,
                                        onSelected: (_) => setModalState(() {
                                          recurrence = option.value;
                                        }),
                                      ),
                                  ],
                                ),
                                if (recurrence == 'specific_days')
                                  Wrap(
                                    key: const Key(
                                      'title-time-editor-specific-days',
                                    ),
                                    spacing: 8,
                                    runSpacing: 8,
                                    children: [
                                      for (final day
                                          in _titleTimeEditorWeekdays)
                                        FilterChip(
                                          label: Text(day.label),
                                          selected: recurrenceSpecificDays
                                              .contains(day.value),
                                          onSelected: (selected) =>
                                              setModalState(() {
                                                if (selected) {
                                                  recurrenceSpecificDays.add(
                                                    day.value,
                                                  );
                                                } else {
                                                  recurrenceSpecificDays.remove(
                                                    day.value,
                                                  );
                                                }
                                              }),
                                        ),
                                    ],
                                  ),
                                if (recurrence == 'interval')
                                  Row(
                                    key: const Key(
                                      'title-time-editor-interval-field',
                                    ),
                                    children: [
                                      Expanded(
                                        child: TextField(
                                          key: const Key(
                                            'title-time-editor-interval-count',
                                          ),
                                          controller:
                                              recurrenceIntervalController,
                                          keyboardType: TextInputType.number,
                                          decoration: const InputDecoration(
                                            labelText: 'Every',
                                            prefixIcon: Icon(
                                              Icons.numbers_rounded,
                                            ),
                                          ),
                                        ),
                                      ),
                                      const SizedBox(width: 10),
                                      DropdownButton<String>(
                                        key: const Key(
                                          'title-time-editor-interval-unit',
                                        ),
                                        value: recurrenceIntervalUnit,
                                        items: [
                                          for (final unit
                                              in _titleTimeEditorIntervalUnits)
                                            DropdownMenuItem(
                                              value: unit.value,
                                              child: Text(unit.label),
                                            ),
                                        ],
                                        onChanged: (value) => setModalState(() {
                                          if (value != null) {
                                            recurrenceIntervalUnit = value;
                                          }
                                        }),
                                      ),
                                    ],
                                  ),
                              ],
                            ),
                          ],
                          Align(
                            alignment: Alignment.centerLeft,
                            child: TextButton.icon(
                              key: const Key('title-time-editor-picker-button'),
                              onPressed: () =>
                                  chooseDateTime(context, setModalState),
                              icon: const Icon(Icons.schedule_rounded),
                              label: const Text('Choose date and time'),
                            ),
                          ),
                          if (writableGoogleCalendars.isNotEmpty) ...[
                            const SizedBox(height: 8),
                            _MobileFormSection(
                              key: const Key(
                                'title-time-editor-google-calendar-sync',
                              ),
                              title: 'Connected calendars',
                              subtitle:
                                  'Create or update this item on selected writable connected calendars.',
                              icon: Icons.calendar_month_rounded,
                              children: [
                                Wrap(
                                  spacing: 8,
                                  runSpacing: 8,
                                  children: [
                                    for (final calendar
                                        in writableGoogleCalendars)
                                      FilterChip(
                                        key: Key(
                                          'title-time-editor-google-calendar-${calendar.id}',
                                        ),
                                        label: Text(calendar.summary),
                                        selected: googleCalendarIds.contains(
                                          calendar.id,
                                        ),
                                        onSelected: (selected) =>
                                            setModalState(() {
                                              if (selected) {
                                                googleCalendarIds.add(
                                                  calendar.id,
                                                );
                                              } else {
                                                googleCalendarIds.remove(
                                                  calendar.id,
                                                );
                                              }
                                            }),
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          ],
                          if (showPrimaryWorkspaceSelector &&
                              workspaceChoices.isNotEmpty) ...[
                            const SizedBox(height: 8),
                            _MobileFormSection(
                              key: const Key(
                                'title-time-editor-primary-workspace',
                              ),
                              title: 'Workspaces',
                              subtitle:
                                  'Choose every workspace this item should be created in.',
                              icon: Icons.home_work_outlined,
                              children: [
                                Wrap(
                                  spacing: 8,
                                  runSpacing: 8,
                                  children: [
                                    for (final workspace in workspaceChoices)
                                      Builder(
                                        builder: (context) {
                                          final value = _workspaceValue(
                                            workspace,
                                          );
                                          final selected =
                                              _workspaceValuesMatch(
                                                value,
                                                selectedPrimaryWorkspaceId,
                                              ) ||
                                              syncWorkspaceIds.any(
                                                (workspaceId) =>
                                                    _workspaceValuesMatch(
                                                      workspaceId,
                                                      value,
                                                    ),
                                              );
                                          final isCurrent =
                                              _workspaceValuesMatch(
                                                value,
                                                _workspaceValueForId(
                                                  workspaces,
                                                  activeWorkspaceId,
                                                ),
                                              );
                                          final label = workspace.isPersonal
                                              ? 'Personal'
                                              : workspace.name;
                                          return FilterChip(
                                            key: Key(
                                              'title-time-editor-primary-workspace-${workspace.id}',
                                            ),
                                            label: Text(
                                              isCurrent
                                                  ? '$label (current)'
                                                  : label,
                                            ),
                                            selected: selected,
                                            onSelected: saving
                                                ? null
                                                : (
                                                    nextSelected,
                                                  ) => setModalState(() {
                                                    validationError = null;
                                                    if (nextSelected) {
                                                      if (selectedPrimaryWorkspaceId ==
                                                          null) {
                                                        selectedPrimaryWorkspaceId =
                                                            value;
                                                      } else {
                                                        syncWorkspaceIds.add(
                                                          value,
                                                        );
                                                      }
                                                      return;
                                                    }

                                                    if (_workspaceValuesMatch(
                                                      value,
                                                      selectedPrimaryWorkspaceId,
                                                    )) {
                                                      selectedPrimaryWorkspaceId =
                                                          null;
                                                      if (syncWorkspaceIds
                                                          .isNotEmpty) {
                                                        final replacement =
                                                            syncWorkspaceIds
                                                                .first;
                                                        selectedPrimaryWorkspaceId =
                                                            replacement;
                                                        syncWorkspaceIds
                                                            .removeWhere(
                                                              (workspaceId) =>
                                                                  _workspaceValuesMatch(
                                                                    workspaceId,
                                                                    replacement,
                                                                  ),
                                                            );
                                                      }
                                                    } else {
                                                      syncWorkspaceIds.removeWhere(
                                                        (workspaceId) =>
                                                            _workspaceValuesMatch(
                                                              workspaceId,
                                                              value,
                                                            ),
                                                      );
                                                    }
                                                  }),
                                          );
                                        },
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          ],
                          if (!showPrimaryWorkspaceSelector &&
                              syncTargets.isNotEmpty) ...[
                            const SizedBox(height: 8),
                            _MobileFormSection(
                              key: const Key(
                                'title-time-editor-workspace-sync',
                              ),
                              title: 'Also assign to',
                              subtitle:
                                  'Copy this item only to selected workspaces.',
                              icon: Icons.account_tree_outlined,
                              children: [
                                Wrap(
                                  spacing: 8,
                                  runSpacing: 8,
                                  children: [
                                    for (final workspace in syncTargets)
                                      FilterChip(
                                        key: Key(
                                          'title-time-editor-sync-workspace-${workspace.id}',
                                        ),
                                        label: Text(workspace.name),
                                        selected: syncWorkspaceIds.any(
                                          (workspaceId) =>
                                              _workspaceValuesMatch(
                                                workspaceId,
                                                _workspaceValue(workspace),
                                              ),
                                        ),
                                        onSelected: (selected) =>
                                            setModalState(() {
                                              final value = _workspaceValue(
                                                workspace,
                                              );
                                              if (selected) {
                                                syncWorkspaceIds.add(value);
                                              } else {
                                                syncWorkspaceIds.removeWhere(
                                                  (workspaceId) =>
                                                      _workspaceValuesMatch(
                                                        workspaceId,
                                                        value,
                                                      ),
                                                );
                                              }
                                            }),
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          ],
                          if (validationError != null) ...[
                            const SizedBox(height: 8),
                            _InlinePlanLimitError(message: validationError!),
                          ],
                          const SizedBox(height: 12),
                        ],
                      ),
                    ),
                  ),
                  Positioned(
                    left: 0,
                    right: 0,
                    bottom: 0,
                    child: Container(
                      padding: const EdgeInsets.fromLTRB(0, 10, 0, 0),
                      decoration: BoxDecoration(
                        color: HeyBeanTheme.surface.withValues(alpha: .94),
                        border: const Border(
                          top: BorderSide(color: HeyBeanTheme.border),
                        ),
                      ),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: OutlinedButton(
                                  onPressed: saving
                                      ? null
                                      : () => Navigator.of(context).pop(),
                                  child: const Text('Cancel'),
                                ),
                              ),
                              if (deleteLabel != null) ...[
                                const SizedBox(width: 10),
                                IconButton.filled(
                                  key: const Key('title-time-editor-delete'),
                                  tooltip: deleteLabel,
                                  style: _destructiveIconButtonStyle(),
                                  onPressed: saving
                                      ? null
                                      : () => Navigator.of(
                                          context,
                                        ).pop({'delete': true}),
                                  icon: const Icon(
                                    Icons.delete_outline_rounded,
                                  ),
                                ),
                              ],
                              const SizedBox(width: 10),
                              Expanded(
                                child: FilledButton.icon(
                                  key: const Key(
                                    'title-time-editor-save-bottom',
                                  ),
                                  onPressed: saving
                                      ? null
                                      : () => submitPayload(
                                          context,
                                          setModalState,
                                        ),
                                  icon: saving
                                      ? const SizedBox(
                                          width: 18,
                                          height: 18,
                                          child: CircularProgressIndicator(
                                            strokeWidth: 2,
                                          ),
                                        )
                                      : const Icon(Icons.check_rounded),
                                  label: Text(
                                    saving ? 'Saving...' : actionLabel,
                                  ),
                                ),
                              ),
                            ],
                          ),
                          if (completeLabel != null) ...[
                            const SizedBox(height: 8),
                            Align(
                              alignment: Alignment.centerRight,
                              child: TextButton.icon(
                                key: const Key('title-time-editor-complete'),
                                onPressed: saving
                                    ? null
                                    : () => submitPayload(
                                        context,
                                        setModalState,
                                        complete: true,
                                      ),
                                icon: const Icon(Icons.done_all_rounded),
                                label: Text(completeLabel),
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    ),
  );
}

class _TaskListCard extends StatefulWidget {
  const _TaskListCard({
    required this.tasks,
    required this.pastTasks,
    required this.loading,
    required this.eventCategories,
    required this.pendingTaskIds,
    required this.onTaskCompleted,
    required this.onTaskSaved,
    required this.onTaskDeleted,
    required this.onEventCategorySaved,
    this.workspaces = const [],
    this.activeWorkspaceId,
  });

  final List<HermesTask> tasks;
  final List<HermesTask> pastTasks;
  final bool loading;
  final List<HermesEventCategory> eventCategories;
  final Set<int> pendingTaskIds;
  final Future<void> Function(HermesTask task) onTaskCompleted;
  final Future<void> Function(
    HermesTask? task, {
    required String title,
    String? dueAt,
    String? notes,
    String? category,
    String? color,
    bool? isCritical,
    int? parentTaskId,
    int? workspaceId,
    Map<String, Object?>? recurrenceMetadata,
    List<Object> syncToWorkspaceIds,
    List<String> googleCalendarIds,
  })
  onTaskSaved;
  final Future<void> Function(
    HermesTask task, {
    List<Object> deleteFromWorkspaceIds,
  })
  onTaskDeleted;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;

  @override
  State<_TaskListCard> createState() => _TaskListCardState();
}

class _TaskListCardState extends State<_TaskListCard> {
  bool _showCompleted = false;
  bool _showAll = false;
  bool _showMoreThanSevenDays = false;
  bool _showMoreThanThirtyDays = false;

  @override
  Widget build(BuildContext context) {
    final allTasks = _mergeTaskLists(widget.tasks, widget.pastTasks);
    final visibleTasks = allTasks
        .where(
          (task) =>
              (_showAll || _taskIsCompleted(task) == _showCompleted) &&
              (_showCompleted || !_taskIsSubtask(task)),
        )
        .toList();
    visibleTasks.sort(_compareTasksByCompletionAndDueDate);
    final todayTasks = <HermesTask>[];
    final upcomingTasks = <HermesTask>[];
    final moreThanSevenDaysTasks = <HermesTask>[];
    final moreThanThirtyDaysTasks = <HermesTask>[];
    for (final task in visibleTasks) {
      final daysAway = _taskDaysAway(task);
      if (daysAway != null && daysAway > 30) {
        moreThanThirtyDaysTasks.add(task);
      } else if (daysAway != null && daysAway > 7) {
        moreThanSevenDaysTasks.add(task);
      } else if (daysAway != null && daysAway >= 1) {
        upcomingTasks.add(task);
      } else {
        todayTasks.add(task);
      }
    }
    final activeSubtasks = widget.tasks
        .where((task) => !_taskIsCompleted(task) && _taskIsSubtask(task))
        .toList();
    Widget taskTile(HermesTask task) => _TaskItemTile(
      task: task,
      subtitle: _taskSubtitle(task),
      subtasks: _subtasksFor(task, activeSubtasks),
      pending: widget.pendingTaskIds.contains(task.id),
      onTap: () => _showTaskEditor(context, task: task),
      onCompleted: widget.onTaskCompleted,
      onSubtaskCompleted: widget.onTaskCompleted,
      onSubtaskTap: (subtask) => _showTaskEditor(context, task: subtask),
      pendingTaskIds: widget.pendingTaskIds,
      onAddSubtask: !_showCompleted && !_showAll && !_taskIsSubtask(task)
          ? () => _showTaskEditor(context, parentTask: task)
          : null,
    );
    return Column(
      key: const Key('tasks-view'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            ChoiceChip(
              key: const Key('task-filter-open'),
              label: const Text('Active'),
              selected: !_showCompleted && !_showAll,
              onSelected: (_) => setState(() {
                _showCompleted = false;
                _showAll = false;
              }),
            ),
            ChoiceChip(
              key: const Key('task-filter-done'),
              label: const Text('Done'),
              selected: _showCompleted && !_showAll,
              onSelected: (_) => setState(() {
                _showCompleted = true;
                _showAll = false;
              }),
            ),
            ChoiceChip(
              key: const Key('task-filter-all'),
              label: const Text('All tasks'),
              selected: _showAll,
              onSelected: (_) => setState(() {
                _showCompleted = false;
                _showAll = true;
              }),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (widget.loading && visibleTasks.isEmpty)
          const _InlineLoadingSurface(
            key: Key('tasks-screen-loading'),
            label: 'Loading tasks',
          )
        else if (visibleTasks.isEmpty)
          _EmptySurface(
            label: _showAll
                ? 'No tasks yet'
                : _showCompleted
                ? 'No completed tasks'
                : 'No active tasks',
          )
        else ...[
          if (todayTasks.isNotEmpty)
            _DatedListSection(
              key: const Key('task-today-section'),
              title: 'Today',
              count: todayTasks.length,
              itemLabel: 'task',
              children: [for (final task in todayTasks) taskTile(task)],
            ),
          if (upcomingTasks.isNotEmpty)
            _DatedListSection(
              key: const Key('task-upcoming-section'),
              title: 'Upcoming',
              count: upcomingTasks.length,
              itemLabel: 'task',
              children: [for (final task in upcomingTasks) taskTile(task)],
            ),
          if (moreThanSevenDaysTasks.isNotEmpty)
            _FutureTaskBucket(
              key: const Key('task-future-seven-section'),
              label: 'More than 7 days away',
              count: moreThanSevenDaysTasks.length,
              itemLabel: 'task',
              expanded: _showMoreThanSevenDays,
              toggleKey: const Key('task-future-seven-toggle'),
              onTap: () => setState(
                () => _showMoreThanSevenDays = !_showMoreThanSevenDays,
              ),
              children: [
                for (final task in moreThanSevenDaysTasks) taskTile(task),
              ],
            ),
          if (moreThanThirtyDaysTasks.isNotEmpty)
            _FutureTaskBucket(
              key: const Key('task-future-thirty-section'),
              label: 'More than 30 days away',
              count: moreThanThirtyDaysTasks.length,
              itemLabel: 'task',
              expanded: _showMoreThanThirtyDays,
              toggleKey: const Key('task-future-thirty-toggle'),
              onTap: () => setState(
                () => _showMoreThanThirtyDays = !_showMoreThanThirtyDays,
              ),
              children: [
                for (final task in moreThanThirtyDaysTasks) taskTile(task),
              ],
            ),
        ],
      ],
    );
  }

  Future<void> _showTaskEditor(
    BuildContext context, {
    HermesTask? task,
    HermesTask? parentTask,
  }) async {
    var savedInsideEditor = false;
    final result = await _showTitleTimeEditor(
      context,
      title: parentTask != null
          ? 'New sub-task'
          : task == null
          ? 'New task'
          : 'Edit task',
      titleLabel: 'Task title',
      timeLabel: 'Due date',
      initialTitle: task?.title ?? '',
      initialTime: _formatCalendarEventDateTime(task?.dueAt),
      initialNotes: task?.notes ?? '',
      allowEmptyTime: true,
      showNotes: true,
      categories: widget.eventCategories,
      initialCategory: task?.category,
      initialColor: task?.color,
      initialCritical: task?.isCritical ?? false,
      deleteLabel: task == null ? null : 'Delete task',
      showRecurrence: true,
      recurrenceTitle: 'Task recurrence',
      recurrenceSubtitle: 'Repeat this task when needed.',
      recurrenceInfoTitle: 'Task recurrence',
      initialMetadata: task?.metadata,
      onEventCategorySaved: widget.onEventCategorySaved,
      workspaces: widget.workspaces,
      activeWorkspaceId: widget.activeWorkspaceId,
      showPrimaryWorkspaceSelector: task == null,
      initialPrimaryWorkspaceId: task == null
          ? _workspaceValueForId(widget.workspaces, widget.activeWorkspaceId)
          : null,
      initialGoogleCalendarIds: task?.googleCalendarIds ?? const [],
      initialSyncWorkspaceIds: task == null
          ? const []
          : _initialSyncWorkspaceIds(
              linkedWorkspaceIds: task.linkedWorkspaceIds,
              workspaceId: task.workspaceId,
              activeWorkspaceId: widget.activeWorkspaceId,
            ),
      onSave: (result) async {
        final title = (result['title'] as String).trim();
        if (title.isEmpty) return;
        await widget.onTaskSaved(
          task,
          title: title,
          dueAt: result['time'] as String?,
          notes: result['notes'] as String?,
          category: result['category'] as String?,
          color: result['color'] as String?,
          isCritical: result['isCritical'] as bool?,
          parentTaskId: parentTask?.id,
          workspaceId: result['workspaceId'] as int?,
          recurrenceMetadata:
              result['recurrenceMetadata'] as Map<String, Object?>?,
          syncToWorkspaceIds:
              (result['syncToWorkspaceIds'] as List?)
                  ?.whereType<Object>()
                  .toList() ??
              const [],
          googleCalendarIds:
              (result['googleCalendarIds'] as List?)
                  ?.map((value) => value.toString())
                  .toList() ??
              const [],
        );
        savedInsideEditor = true;
      },
    );
    if (result == null || !context.mounted) return;
    if (result['delete'] == true && task != null) {
      final deleteFromWorkspaceIds = await _confirmWorkspaceDeleteSelection(
        context,
        itemTitle: task.title,
        itemType: 'task',
        workspaces: widget.workspaces,
        activeWorkspaceId: widget.activeWorkspaceId,
        workspaceId: task.workspaceId,
        linkedWorkspaceIds: task.linkedWorkspaceIds,
      );
      if (!context.mounted || deleteFromWorkspaceIds == null) return;
      await widget.onTaskDeleted(
        task,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
      );
      return;
    }
    if (savedInsideEditor) return;
    final title = (result['title'] as String).trim();
    if (title.isEmpty) return;
    await widget.onTaskSaved(
      task,
      title: title,
      dueAt: result['time'] as String?,
      notes: result['notes'] as String?,
      category: result['category'] as String?,
      color: result['color'] as String?,
      isCritical: result['isCritical'] as bool?,
      parentTaskId: parentTask?.id,
      workspaceId: result['workspaceId'] as int?,
      recurrenceMetadata: result['recurrenceMetadata'] as Map<String, Object?>?,
      syncToWorkspaceIds:
          (result['syncToWorkspaceIds'] as List?)
              ?.whereType<Object>()
              .toList() ??
          const [],
      googleCalendarIds:
          (result['googleCalendarIds'] as List?)
              ?.map((value) => value.toString())
              .toList() ??
          const [],
    );
  }
}

class _FutureTaskBucket extends StatelessWidget {
  const _FutureTaskBucket({
    super.key,
    required this.label,
    required this.count,
    required this.itemLabel,
    required this.expanded,
    required this.toggleKey,
    required this.onTap,
    required this.children,
  });

  final String label;
  final int count;
  final String itemLabel;
  final bool expanded;
  final Key toggleKey;
  final VoidCallback onTap;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final countLabel = '$count $itemLabel${count == 1 ? '' : 's'}';
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Material(
            color: HeyBeanTheme.surface2,
            borderRadius: BorderRadius.circular(14),
            child: InkWell(
              key: toggleKey,
              borderRadius: BorderRadius.circular(14),
              onTap: onTap,
              child: Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 10,
                ),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: HeyBeanTheme.border),
                ),
                child: Row(
                  children: [
                    Icon(
                      expanded
                          ? Icons.keyboard_arrow_up_rounded
                          : Icons.keyboard_arrow_down_rounded,
                      color: HeyBeanTheme.muted,
                      size: 20,
                    ),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text(
                        label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: HeyBeanTheme.text,
                          fontSize: 13,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(
                      countLabel,
                      style: const TextStyle(
                        color: HeyBeanTheme.muted,
                        fontSize: 12,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          if (expanded) ...[
            const SizedBox(height: 10),
            Padding(
              padding: const EdgeInsets.only(left: 8),
              child: DecoratedBox(
                decoration: const BoxDecoration(
                  border: Border(
                    left: BorderSide(color: HeyBeanTheme.border, width: 2),
                  ),
                ),
                child: Padding(
                  padding: const EdgeInsets.only(left: 8),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: children,
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _DatedListSection extends StatelessWidget {
  const _DatedListSection({
    super.key,
    required this.title,
    required this.count,
    required this.itemLabel,
    required this.children,
  });

  final String title;
  final int count;
  final String itemLabel;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final countLabel = '$count $itemLabel${count == 1 ? '' : 's'}';
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(2, 2, 2, 8),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    title,
                    style: const TextStyle(
                      color: HeyBeanTheme.text,
                      fontSize: 13,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ),
                Text(
                  countLabel,
                  style: const TextStyle(
                    color: HeyBeanTheme.muted,
                    fontSize: 12,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ],
            ),
          ),
          ...children,
        ],
      ),
    );
  }
}

class _ReminderListCard extends StatefulWidget {
  const _ReminderListCard({
    required this.reminders,
    required this.loading,
    required this.eventCategories,
    required this.onReminderSaved,
    required this.onReminderCompleted,
    required this.onReminderDeleted,
    required this.onEventCategorySaved,
    this.workspaces = const [],
    this.activeWorkspaceId,
  });

  final List<HermesReminder> reminders;
  final bool loading;
  final List<HermesEventCategory> eventCategories;
  final Future<void> Function(
    HermesReminder? reminder, {
    required String title,
    required String remindAt,
    String status,
    String? category,
    String? color,
    int? workspaceId,
    Map<String, Object?>? recurrenceMetadata,
    List<Object> syncToWorkspaceIds,
    List<String> googleCalendarIds,
  })
  onReminderSaved;
  final Future<void> Function(HermesReminder reminder) onReminderCompleted;
  final Future<void> Function(
    HermesReminder reminder, {
    List<Object> deleteFromWorkspaceIds,
  })
  onReminderDeleted;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;

  @override
  State<_ReminderListCard> createState() => _ReminderListCardState();
}

class _ReminderListCardState extends State<_ReminderListCard> {
  bool _showCompleted = false;
  bool _showAll = false;
  bool _showMoreThanSevenDays = false;
  bool _showMoreThanThirtyDays = false;

  @override
  Widget build(BuildContext context) {
    final visibleReminders = widget.reminders
        .where(
          (reminder) =>
              _showAll || _reminderIsCompleted(reminder) == _showCompleted,
        )
        .toList();
    visibleReminders.sort(_compareRemindersByCompletionAndDueDate);
    final todayReminders = <HermesReminder>[];
    final upcomingReminders = <HermesReminder>[];
    final moreThanSevenDaysReminders = <HermesReminder>[];
    final moreThanThirtyDaysReminders = <HermesReminder>[];
    for (final reminder in visibleReminders) {
      final daysAway = _reminderDaysAway(reminder);
      if (daysAway != null && daysAway > 30) {
        moreThanThirtyDaysReminders.add(reminder);
      } else if (daysAway != null && daysAway > 7) {
        moreThanSevenDaysReminders.add(reminder);
      } else if (daysAway != null && daysAway >= 1) {
        upcomingReminders.add(reminder);
      } else {
        todayReminders.add(reminder);
      }
    }
    Widget reminderTile(HermesReminder reminder) => _ReminderItemTile(
      reminder: reminder,
      subtitle: _reminderSubtitle(reminder),
      onTap: () => _showReminderEditor(context, reminder: reminder),
      onCompleted: widget.onReminderCompleted,
    );
    return Column(
      key: const Key('reminders-view'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            ChoiceChip(
              key: const Key('reminder-filter-pending'),
              label: const Text('Pending'),
              selected: !_showCompleted && !_showAll,
              onSelected: (_) => setState(() {
                _showCompleted = false;
                _showAll = false;
              }),
            ),
            ChoiceChip(
              key: const Key('reminder-filter-completed'),
              label: const Text('Completed'),
              selected: _showCompleted && !_showAll,
              onSelected: (_) => setState(() {
                _showCompleted = true;
                _showAll = false;
              }),
            ),
            ChoiceChip(
              key: const Key('reminder-filter-all'),
              label: const Text('All reminders'),
              selected: _showAll,
              onSelected: (_) => setState(() {
                _showCompleted = false;
                _showAll = true;
              }),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (widget.loading && visibleReminders.isEmpty)
          const _InlineLoadingSurface(
            key: Key('reminders-screen-loading'),
            label: 'Loading reminders',
          )
        else if (visibleReminders.isEmpty)
          _EmptySurface(
            label: _showAll
                ? 'No reminders yet'
                : _showCompleted
                ? 'No completed reminders'
                : 'No pending reminders',
          )
        else ...[
          if (todayReminders.isNotEmpty)
            _DatedListSection(
              key: const Key('reminder-today-section'),
              title: 'Today',
              count: todayReminders.length,
              itemLabel: 'reminder',
              children: [
                for (final reminder in todayReminders) reminderTile(reminder),
              ],
            ),
          if (upcomingReminders.isNotEmpty)
            _DatedListSection(
              key: const Key('reminder-upcoming-section'),
              title: 'Upcoming',
              count: upcomingReminders.length,
              itemLabel: 'reminder',
              children: [
                for (final reminder in upcomingReminders)
                  reminderTile(reminder),
              ],
            ),
          if (moreThanSevenDaysReminders.isNotEmpty)
            _FutureTaskBucket(
              key: const Key('reminder-future-seven-section'),
              label: 'More than 7 days away',
              count: moreThanSevenDaysReminders.length,
              itemLabel: 'reminder',
              expanded: _showMoreThanSevenDays,
              toggleKey: const Key('reminder-future-seven-toggle'),
              onTap: () => setState(
                () => _showMoreThanSevenDays = !_showMoreThanSevenDays,
              ),
              children: [
                for (final reminder in moreThanSevenDaysReminders)
                  reminderTile(reminder),
              ],
            ),
          if (moreThanThirtyDaysReminders.isNotEmpty)
            _FutureTaskBucket(
              key: const Key('reminder-future-thirty-section'),
              label: 'More than 30 days away',
              count: moreThanThirtyDaysReminders.length,
              itemLabel: 'reminder',
              expanded: _showMoreThanThirtyDays,
              toggleKey: const Key('reminder-future-thirty-toggle'),
              onTap: () => setState(
                () => _showMoreThanThirtyDays = !_showMoreThanThirtyDays,
              ),
              children: [
                for (final reminder in moreThanThirtyDaysReminders)
                  reminderTile(reminder),
              ],
            ),
        ],
      ],
    );
  }

  Future<void> _showReminderEditor(
    BuildContext context, {
    HermesReminder? reminder,
  }) async {
    var savedInsideEditor = false;
    final result = await _showTitleTimeEditor(
      context,
      title: reminder == null ? 'New reminder' : 'Edit reminder',
      titleLabel: 'Reminder title',
      timeLabel: 'Remind me at',
      initialTitle: reminder?.title ?? '',
      initialTime: _formatCalendarEventDateTime(reminder?.dueAt),
      editorIcon: Icons.notifications_active_outlined,
      editorSubtitle: 'Time-sensitive nudge with optional repeat',
      primarySectionTitle: 'Reminder basics',
      primarySectionSubtitle: 'Title and required reminder time',
      allowEmptyTime: false,
      categories: widget.eventCategories,
      initialCategory: reminder?.category,
      initialColor: reminder?.color,
      showCritical: false,
      showTimeTextField: false,
      showRecurrence: true,
      recurrenceTitle: 'Reminder repeats',
      recurrenceSubtitle: 'Repeat this reminder when needed.',
      recurrenceInfoTitle: 'Reminder recurrence',
      initialMetadata: reminder?.metadata,
      onEventCategorySaved: widget.onEventCategorySaved,
      deleteLabel: reminder == null ? null : 'Delete reminder',
      completeLabel: reminder == null
          ? null
          : (_reminderIsCompleted(reminder) ? 'Mark pending' : 'Mark complete'),
      workspaces: widget.workspaces,
      activeWorkspaceId: widget.activeWorkspaceId,
      showPrimaryWorkspaceSelector: reminder == null,
      initialPrimaryWorkspaceId: reminder == null
          ? _workspaceValueForId(widget.workspaces, widget.activeWorkspaceId)
          : null,
      initialGoogleCalendarIds: reminder?.googleCalendarIds ?? const [],
      initialSyncWorkspaceIds: reminder == null
          ? const []
          : _initialSyncWorkspaceIds(
              linkedWorkspaceIds: reminder.linkedWorkspaceIds,
              workspaceId: reminder.workspaceId,
              activeWorkspaceId: widget.activeWorkspaceId,
            ),
      onSave: (result) async {
        final title = (result['title'] as String).trim();
        final time = (result['time'] as String?)?.trim() ?? '';
        if (title.isEmpty || time.isEmpty) return;
        final status = result['complete'] == true
            ? (reminder != null && _reminderIsCompleted(reminder)
                  ? 'pending'
                  : 'completed')
            : (reminder?.status ?? 'pending');
        await widget.onReminderSaved(
          reminder,
          title: title,
          remindAt: time,
          status: status,
          category: result['category'] as String?,
          color: result['color'] as String?,
          workspaceId: result['workspaceId'] as int?,
          recurrenceMetadata:
              result['recurrenceMetadata'] as Map<String, Object?>?,
          syncToWorkspaceIds:
              (result['syncToWorkspaceIds'] as List?)
                  ?.whereType<Object>()
                  .toList() ??
              const [],
          googleCalendarIds:
              (result['googleCalendarIds'] as List?)
                  ?.map((value) => value.toString())
                  .toList() ??
              const [],
        );
        savedInsideEditor = true;
      },
    );
    if (result == null || !context.mounted) return;
    if (result['delete'] == true && reminder != null) {
      final deleteFromWorkspaceIds = await _confirmWorkspaceDeleteSelection(
        context,
        itemTitle: reminder.title,
        itemType: 'reminder',
        workspaces: widget.workspaces,
        activeWorkspaceId: widget.activeWorkspaceId,
        workspaceId: reminder.workspaceId,
        linkedWorkspaceIds: reminder.linkedWorkspaceIds,
      );
      if (!context.mounted || deleteFromWorkspaceIds == null) return;
      await widget.onReminderDeleted(
        reminder,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
      );
      return;
    }
    if (savedInsideEditor) return;
    final title = (result['title'] as String).trim();
    final time = (result['time'] as String?)?.trim() ?? '';
    if (title.isEmpty || time.isEmpty) return;
    final status = result['complete'] == true
        ? (reminder != null && _reminderIsCompleted(reminder)
              ? 'pending'
              : 'completed')
        : (reminder?.status ?? 'pending');
    await widget.onReminderSaved(
      reminder,
      title: title,
      remindAt: time,
      status: status,
      category: result['category'] as String?,
      color: result['color'] as String?,
      workspaceId: result['workspaceId'] as int?,
      recurrenceMetadata: result['recurrenceMetadata'] as Map<String, Object?>?,
      syncToWorkspaceIds:
          (result['syncToWorkspaceIds'] as List?)
              ?.whereType<Object>()
              .toList() ??
          const [],
      googleCalendarIds:
          (result['googleCalendarIds'] as List?)
              ?.map((value) => value.toString())
              .toList() ??
          const [],
    );
  }
}

String _agentPreferencesSummary(HermesAgentProfile? profile) {
  final personalityKey = profile?.personalityType ?? 'balanced';
  final personality = _agentPersonalityOptions.firstWhere(
    (option) => option.key == personalityKey,
    orElse: () => _agentPersonalityOptions.first,
  );
  final priorities = profile?.onboardingPriorities ?? const <String>[];
  final prioritySummary = priorities.isEmpty
      ? 'No priorities selected yet'
      : priorities.join(', ');
  return '${personality.label} • $prioritySummary';
}

const List<String> _memoryTypeOptions = [
  'fact',
  'preference',
  'instruction',
  'project',
  'decision',
  'routine',
  'identity',
  'summary',
];

class _MemoryView extends StatefulWidget {
  const _MemoryView({
    required this.items,
    required this.summaries,
    required this.history,
    required this.onRefresh,
    required this.onCreated,
    required this.onUpdated,
    required this.onDeleted,
  });

  final List<HermesMemoryItem> items;
  final List<HermesMemorySummary> summaries;
  final List<HermesRequestHistoryItem> history;
  final Future<void> Function() onRefresh;
  final Future<HermesMemoryItem> Function({
    required String content,
    String type,
    String? title,
  })
  onCreated;
  final Future<HermesMemoryItem> Function(
    HermesMemoryItem item, {
    required String content,
    required String type,
    String? title,
  })
  onUpdated;
  final Future<void> Function(HermesMemoryItem item) onDeleted;

  @override
  State<_MemoryView> createState() => _MemoryViewState();
}

class _MemoryViewState extends State<_MemoryView> {
  final _searchController = TextEditingController();
  final _newContentController = TextEditingController();
  final _newTitleController = TextEditingController();
  String _typeFilter = 'all';
  String _newType = 'fact';
  bool _saving = false;
  bool _refreshing = false;

  @override
  void dispose() {
    _searchController.dispose();
    _newContentController.dispose();
    _newTitleController.dispose();
    super.dispose();
  }

  List<HermesMemoryItem> get _filteredItems {
    final search = _searchController.text.trim().toLowerCase();
    return _sortedMemoryItems(widget.items)
        .where((item) {
          if (_typeFilter != 'all' && item.type != _typeFilter) return false;
          if (search.isEmpty) return true;
          return [item.title, item.content, item.type].whereType<String>().any(
            (value) => value.toLowerCase().contains(search),
          );
        })
        .toList(growable: false);
  }

  Future<void> _create() async {
    final content = _newContentController.text.trim();
    if (content.isEmpty || _saving) return;
    setState(() => _saving = true);
    try {
      await widget.onCreated(
        content: content,
        type: _newType,
        title: _newTitleController.text.trim(),
      );
      if (!mounted) return;
      _newContentController.clear();
      _newTitleController.clear();
      setState(() {});
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _refresh() async {
    if (_refreshing) return;
    setState(() => _refreshing = true);
    try {
      await widget.onRefresh();
    } finally {
      if (mounted) setState(() => _refreshing = false);
    }
  }

  Future<void> _edit(HermesMemoryItem item) async {
    final edited = await showModalBottomSheet<_MemoryEditResult>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => _MemoryEditSheet(item: item),
    );
    if (edited == null || !mounted) return;
    await widget.onUpdated(
      item,
      content: edited.content,
      type: edited.type,
      title: edited.title,
    );
  }

  Future<void> _forget(HermesMemoryItem item) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Forget knowledge?'),
        content: Text(
          item.title?.trim().isNotEmpty == true ? item.title! : item.content,
          maxLines: 3,
          overflow: TextOverflow.ellipsis,
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            style: _destructiveFilledButtonStyle(),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Forget'),
          ),
        ],
      ),
    );
    if (confirmed == true) await widget.onDeleted(item);
  }

  @override
  Widget build(BuildContext context) {
    final activeCount = widget.items
        .where((item) => item.status == 'active')
        .length;
    final highConfidence = widget.items
        .where((item) => (item.confidence ?? 0) >= 85)
        .length;
    final items = _filteredItems;
    return Column(
      key: const Key('memory-view'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _ShellCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _SectionTitle(
                icon: Icons.psychology_alt_rounded,
                title: "Bean's Knowledge",
                subtitle:
                    '$activeCount active facts • $highConfidence high confidence',
                infoKey: const Key('memory-info'),
                infoTitle: "Bean's Knowledge help",
                infoBullets: const [
                  'Save durable facts, preferences, instructions, projects, and routines that Bean should remember.',
                  "Use Notes for documents and longer writing; use Bean's Knowledge for concise assistant context.",
                  "Recent request history helps Bean answer recall questions without turning every request into durable knowledge.",
                ],
              ),
              const SizedBox(height: 14),
              Wrap(
                spacing: 10,
                runSpacing: 10,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  SizedBox(
                    width: 240,
                    child: TextField(
                      controller: _searchController,
                      decoration: const InputDecoration(
                        prefixIcon: Icon(Icons.search_rounded),
                        hintText: 'Search knowledge',
                      ),
                      onChanged: (_) => setState(() {}),
                    ),
                  ),
                  DropdownButton<String>(
                    value: _typeFilter,
                    items: [
                      const DropdownMenuItem(
                        value: 'all',
                        child: Text('All types'),
                      ),
                      ..._memoryTypeOptions.map(
                        (type) => DropdownMenuItem(
                          value: type,
                          child: Text(_memoryTypeLabel(type)),
                        ),
                      ),
                    ],
                    onChanged: (value) =>
                        setState(() => _typeFilter = value ?? 'all'),
                  ),
                  OutlinedButton.icon(
                    onPressed: _refreshing ? null : _refresh,
                    icon: _refreshing
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.refresh_rounded),
                    label: Text(_refreshing ? 'Refreshing' : 'Refresh'),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              _MemoryComposer(
                titleController: _newTitleController,
                contentController: _newContentController,
                type: _newType,
                saving: _saving,
                onTypeChanged: (value) => setState(() => _newType = value),
                onSubmit: _create,
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        if (items.isEmpty)
          const _EmptySurface(
            label:
                'No matching knowledge. Add knowledge above or adjust the search/filter.',
          )
        else
          ...items.map(
            (item) => Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: _MemoryItemTile(
                item: item,
                onEdit: () => _edit(item),
                onForget: () => _forget(item),
              ),
            ),
          ),
        if (widget.summaries.isNotEmpty) ...[
          const SizedBox(height: 8),
          _MemorySummarySection(summaries: widget.summaries),
        ],
        if (widget.history.isNotEmpty) ...[
          const SizedBox(height: 14),
          _RequestHistorySection(history: widget.history),
        ],
      ],
    );
  }
}

class _MemoryComposer extends StatelessWidget {
  const _MemoryComposer({
    required this.titleController,
    required this.contentController,
    required this.type,
    required this.saving,
    required this.onTypeChanged,
    required this.onSubmit,
  });

  final TextEditingController titleController;
  final TextEditingController contentController;
  final String type;
  final bool saving;
  final ValueChanged<String> onTypeChanged;
  final VoidCallback onSubmit;

  @override
  Widget build(BuildContext context) => DecoratedBox(
    decoration: BoxDecoration(
      color: HeyBeanTheme.accent.withValues(alpha: .10),
      borderRadius: BorderRadius.circular(18),
      border: Border.all(color: HeyBeanTheme.border),
    ),
    child: Padding(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Add knowledge',
            style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: titleController,
            decoration: const InputDecoration(hintText: 'Optional title'),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: contentController,
            minLines: 2,
            maxLines: 5,
            decoration: _longFormInputDecoration(
              hintText: 'Something Bean should remember',
            ),
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              DropdownButton<String>(
                value: type,
                items: _memoryTypeOptions
                    .map(
                      (option) => DropdownMenuItem(
                        value: option,
                        child: Text(_memoryTypeLabel(option)),
                      ),
                    )
                    .toList(),
                onChanged: (value) {
                  if (value != null) onTypeChanged(value);
                },
              ),
              const Spacer(),
              FilledButton.icon(
                onPressed: saving ? null : onSubmit,
                icon: saving
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.add_rounded),
                label: Text(saving ? 'Saving' : 'Remember'),
              ),
            ],
          ),
        ],
      ),
    ),
  );
}

class _MemoryItemTile extends StatelessWidget {
  const _MemoryItemTile({
    required this.item,
    required this.onEdit,
    required this.onForget,
  });

  final HermesMemoryItem item;
  final VoidCallback onEdit;
  final VoidCallback onForget;

  @override
  Widget build(BuildContext context) {
    final title = item.title?.trim().isNotEmpty == true
        ? item.title!.trim()
        : _memoryTypeLabel(item.type);
    final updated = _formatNaturalDateTime(item.updatedAt);
    final confidence = item.confidence == null ? null : '${item.confidence}%';
    return _ShellCard(
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: onEdit,
        child: Padding(
          padding: const EdgeInsets.all(2),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  _MemoryTypeChip(type: item.type),
                  if (confidence != null) ...[
                    const SizedBox(width: 8),
                    Text(
                      confidence,
                      style: const TextStyle(
                        color: HeyBeanTheme.muted,
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                  const Spacer(),
                  IconButton(
                    tooltip: 'Edit knowledge',
                    onPressed: onEdit,
                    icon: const Icon(Icons.edit_rounded),
                  ),
                  IconButton(
                    tooltip: 'Forget knowledge',
                    onPressed: onForget,
                    icon: const Icon(Icons.delete_outline_rounded),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Text(
                title,
                style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 6),
              Text(item.content, style: const TextStyle(height: 1.35)),
              if (updated.isNotEmpty) ...[
                const SizedBox(height: 8),
                Text(
                  'Updated $updated',
                  style: const TextStyle(
                    color: HeyBeanTheme.muted,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _MemoryTypeChip extends StatelessWidget {
  const _MemoryTypeChip({required this.type});

  final String type;

  @override
  Widget build(BuildContext context) => DecoratedBox(
    decoration: BoxDecoration(
      color: HeyBeanTheme.accent.withValues(alpha: .12),
      borderRadius: BorderRadius.circular(999),
      border: Border.all(color: HeyBeanTheme.border),
    ),
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      child: Text(
        _memoryTypeLabel(type),
        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w800),
      ),
    ),
  );
}

class _MemoryEditResult {
  const _MemoryEditResult({
    required this.title,
    required this.content,
    required this.type,
  });

  final String title;
  final String content;
  final String type;
}

class _MemoryEditSheet extends StatefulWidget {
  const _MemoryEditSheet({required this.item});

  final HermesMemoryItem item;

  @override
  State<_MemoryEditSheet> createState() => _MemoryEditSheetState();
}

class _MemoryEditSheetState extends State<_MemoryEditSheet> {
  late final TextEditingController _titleController;
  late final TextEditingController _contentController;
  late String _type;

  @override
  void initState() {
    super.initState();
    _titleController = TextEditingController(text: widget.item.title ?? '');
    _contentController = TextEditingController(text: widget.item.content);
    _type = _memoryTypeOptions.contains(widget.item.type)
        ? widget.item.type
        : 'fact';
  }

  @override
  void dispose() {
    _titleController.dispose();
    _contentController.dispose();
    super.dispose();
  }

  void _save() {
    final content = _contentController.text.trim();
    if (content.isEmpty) return;
    Navigator.pop(
      context,
      _MemoryEditResult(
        title: _titleController.text.trim(),
        content: content,
        type: _type,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.viewInsetsOf(context).bottom;
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(18, 0, 18, bottom + 18),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Edit knowledge',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _titleController,
              decoration: const InputDecoration(hintText: 'Optional title'),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _contentController,
              autofocus: true,
              minLines: 4,
              maxLines: 8,
              decoration: _longFormInputDecoration(
                hintText: 'Knowledge content',
              ),
            ),
            const SizedBox(height: 10),
            DropdownButton<String>(
              value: _type,
              items: _memoryTypeOptions
                  .map(
                    (option) => DropdownMenuItem(
                      value: option,
                      child: Text(_memoryTypeLabel(option)),
                    ),
                  )
                  .toList(),
              onChanged: (value) {
                if (value != null) setState(() => _type = value);
              },
            ),
            const SizedBox(height: 10),
            FilledButton(onPressed: _save, child: const Text('Save')),
          ],
        ),
      ),
    );
  }
}

class _MemorySummarySection extends StatelessWidget {
  const _MemorySummarySection({required this.summaries});

  final List<HermesMemorySummary> summaries;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Summaries',
          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w900),
        ),
        const SizedBox(height: 10),
        ...summaries
            .take(4)
            .map(
              (summary) => Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      summary.title,
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      summary.content,
                      style: const TextStyle(color: HeyBeanTheme.muted),
                    ),
                  ],
                ),
              ),
            ),
      ],
    ),
  );
}

class _RequestHistorySection extends StatelessWidget {
  const _RequestHistorySection({required this.history});

  final List<HermesRequestHistoryItem> history;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Recent requests',
          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w900),
        ),
        const SizedBox(height: 10),
        ...history.take(10).map((item) {
          final created = _formatNaturalDateTime(item.createdAt);
          return Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Padding(
                  padding: EdgeInsets.only(top: 2),
                  child: Icon(
                    Icons.history_rounded,
                    size: 17,
                    color: HeyBeanTheme.muted,
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        item.content,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                      if (created.isNotEmpty)
                        Text(
                          created,
                          style: const TextStyle(
                            color: HeyBeanTheme.muted,
                            fontSize: 12,
                          ),
                        ),
                    ],
                  ),
                ),
              ],
            ),
          );
        }),
      ],
    ),
  );
}

class _NotesView extends StatefulWidget {
  const _NotesView({
    required this.folders,
    required this.notes,
    required this.workspaces,
    this.activeWorkspaceId,
    this.openNoteId,
    required this.onFolderCreated,
    required this.onFolderDeleted,
    required this.onNoteSaved,
    required this.onNoteDeleted,
  });

  final List<HermesNoteFolder> folders;
  final List<HermesNote> notes;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
  final int? openNoteId;
  final Future<HermesNoteFolder> Function(String name) onFolderCreated;
  final Future<void> Function(HermesNoteFolder folder) onFolderDeleted;
  final Future<HermesNote> Function(
    HermesNote? note, {
    required String title,
    required String bodyHtml,
    required String plainText,
    int? folderId,
    bool clearFolder,
    bool? isPinned,
    Map<String, Object?>? metadata,
    List<Object>? syncToWorkspaceIds,
  })
  onNoteSaved;
  final Future<void> Function(HermesNote note) onNoteDeleted;

  @override
  State<_NotesView> createState() => _NotesViewState();
}

class _NotesViewState extends State<_NotesView> {
  final _titleController = TextEditingController();
  final _bodyController = _FormattedNoteTextController();
  final _searchController = TextEditingController();
  final _titleFocusNode = FocusNode();
  final _bodyFocusNode = FocusNode();
  final Set<String> _activeTypingFormats = {};
  String _folderFilter = 'all';
  String _noteSort = 'recent';
  int? _selectedId;
  int? _editingFolderId;
  bool _saving = false;
  Timer? _autosaveTimer;
  String _lastBodyText = '';

  @override
  void initState() {
    super.initState();
    _titleFocusNode.addListener(_handleEditorFocusChanged);
    _bodyFocusNode.addListener(_handleEditorFocusChanged);
    _selectRequestedNote();
  }

  @override
  void didUpdateWidget(covariant _NotesView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!widget.notes.any((note) => note.id == _selectedId)) {
      _autosaveTimer?.cancel();
      _selectedId = null;
      _editingFolderId = null;
    }
    final selectedFolderId = int.tryParse(_folderFilter);
    if (selectedFolderId != null &&
        !widget.folders.any((folder) => folder.id == selectedFolderId)) {
      _folderFilter = 'all';
    }
    if (oldWidget.openNoteId != widget.openNoteId) {
      _selectRequestedNote();
    }
  }

  @override
  void dispose() {
    _autosaveTimer?.cancel();
    _titleController.dispose();
    _bodyController.dispose();
    _searchController.dispose();
    _titleFocusNode.removeListener(_handleEditorFocusChanged);
    _bodyFocusNode.removeListener(_handleEditorFocusChanged);
    _titleFocusNode.dispose();
    _bodyFocusNode.dispose();
    super.dispose();
  }

  List<HermesNote> get _filteredNotes {
    final search = _searchController.text.trim().toLowerCase();
    final filtered = _sortedNotes(widget.notes).where((note) {
      final folderMatches =
          _folderFilter == 'all' ||
          (_folderFilter == 'pinned' && note.isPinned) ||
          (_folderFilter == 'unfiled' && note.folderId == null) ||
          note.folderId?.toString() == _folderFilter;
      if (!folderMatches) return false;
      if (search.isEmpty) return true;
      final folder = _folderFor(note.folderId);
      return [note.title, note.plainText, folder?.name].whereType<String>().any(
        (value) => value.toLowerCase().contains(search),
      );
    }).toList();
    return _sortNotesForList(filtered);
  }

  HermesNote? get _selectedNote => widget.notes
      .where((note) => note.id == _selectedId)
      .cast<HermesNote?>()
      .firstOrNull;

  void _selectNote(HermesNote? note) {
    final noteFormats = _noteFormatsFromMetadata(note?.metadata);
    final normalizedBody = _normalizeCheckedCheckboxMarkers(
      note?.plainText ?? _plainTextFromHtml(note?.bodyHtml),
    );
    _selectedId = note?.id;
    _editingFolderId = note?.folderId;
    _titleController.text = note?.title ?? '';
    _bodyController.text = normalizedBody.text;
    _bodyController.setFormats([...noteFormats, ...normalizedBody.formats]);
    _lastBodyText = _bodyController.text;
    _activeTypingFormats.clear();
  }

  HermesNoteFolder? _folderFor(int? id) =>
      widget.folders.where((folder) => folder.id == id).firstOrNull;

  String get _currentFolderTitle {
    switch (_folderFilter) {
      case 'pinned':
        return 'Pinned';
      case 'unfiled':
        return 'Unfiled';
      case 'all':
        return 'All Notes';
    }
    return _folderFor(int.tryParse(_folderFilter))?.name ?? 'All Notes';
  }

  void _selectRequestedNote() {
    final noteId = widget.openNoteId;
    if (noteId == null) return;
    final note = widget.notes
        .where((candidate) => candidate.id == noteId)
        .cast<HermesNote?>()
        .firstOrNull;
    if (note == null) return;
    _selectNote(note);
    _focusBodyOnNextFrame();
  }

  List<HermesNote> _sortNotesForList(List<HermesNote> notes) {
    final sorted = [...notes];
    switch (_noteSort) {
      case 'title':
        sorted.sort(
          (a, b) => a.title.toLowerCase().compareTo(b.title.toLowerCase()),
        );
      default:
        sorted.sort((a, b) {
          final aTime = DateTime.tryParse(a.updatedAt ?? '') ?? DateTime(1970);
          final bTime = DateTime.tryParse(b.updatedAt ?? '') ?? DateTime(1970);
          return bTime.compareTo(aTime);
        });
    }
    return sorted;
  }

  Future<void> _newNote() async {
    await _flushAutosave();
    final saved = await widget.onNoteSaved(
      null,
      title: 'New Note',
      bodyHtml: '',
      plainText: '',
      folderId: int.tryParse(_folderFilter),
      clearFolder: int.tryParse(_folderFilter) == null,
      metadata: const {},
    );
    if (!mounted) return;
    setState(() => _selectNote(saved));
    _focusBodyOnNextFrame();
  }

  Future<void> _save({
    bool? isPinned,
    Map<String, Object?>? metadata,
    List<Object>? syncToWorkspaceIds,
  }) async {
    final note = _selectedNote;
    if (note == null || (_noteIsLocked(note) && metadata == null)) return;
    setState(() => _saving = true);
    final title = _titleController.text.trim().isEmpty
        ? 'New Note'
        : _titleController.text.trim();
    final plain = _normalizedNotePlainText(_bodyController.text);
    try {
      final saved = await widget.onNoteSaved(
        note,
        title: title,
        bodyHtml: _htmlFromFormattedPlainText(plain, _bodyController.formats),
        plainText: plain,
        folderId: _editingFolderId,
        clearFolder: _editingFolderId == null,
        isPinned: isPinned ?? note.isPinned,
        metadata: _metadataWithNoteFormats(
          metadata ?? note.metadata,
          _bodyController.formats,
        ),
        syncToWorkspaceIds: syncToWorkspaceIds,
      );
      if (mounted && _selectedId == note.id) {
        setState(() {
          _selectedId = saved.id;
          _editingFolderId = saved.folderId;
        });
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _togglePin() async {
    final note = _selectedNote;
    if (note == null) return;
    await _flushAutosave();
    await _save(isPinned: !note.isPinned, metadata: note.metadata);
  }

  Future<void> _toggleLock() async {
    final note = _selectedNote;
    if (note == null) return;
    await _flushAutosave();
    final metadata = {...note.metadata, 'locked': !_noteIsLocked(note)};
    await _save(metadata: metadata);
    if (!mounted) return;
    if (metadata['locked'] == true) FocusScope.of(context).unfocus();
  }

  Future<void> _moveSelectedNoteToFolder(int? folderId) async {
    final note = _selectedNote;
    if (note == null) return;
    await _flushAutosave();
    setState(() => _editingFolderId = folderId);
    await _save(metadata: note.metadata);
  }

  Future<void> _showNoteWorkspaceSheet() async {
    final note = _selectedNote;
    if (note == null) return;
    await _flushAutosave();
    if (!mounted) return;
    final selectedIds = _initialSyncWorkspaceIds(
      linkedWorkspaceIds: note.linkedWorkspaceIds,
      workspaceId: note.workspaceId,
      activeWorkspaceId: widget.activeWorkspaceId,
    ).toSet();
    final selected = await showModalBottomSheet<List<Object>>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) => _NoteWorkspaceSyncSheet(
        note: note,
        workspaces: widget.workspaces,
        activeWorkspaceId: widget.activeWorkspaceId,
        initialSyncWorkspaceIds: selectedIds,
      ),
    );
    if (selected == null) return;
    await _save(metadata: note.metadata, syncToWorkspaceIds: selected);
  }

  Future<void> _deleteNote() async {
    final note = _selectedNote;
    if (note == null) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Delete note?'),
        content: Text('This will permanently delete "${note.title}".'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    _autosaveTimer?.cancel();
    await widget.onNoteDeleted(note);
    if (!mounted) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _selectedId = null;
      _editingFolderId = null;
    });
  }

  Future<void> _newFolder() async {
    final name = await showDialog<String>(
      context: context,
      builder: (context) => const _NewNoteFolderDialog(),
    );
    if (name == null || name.isEmpty) return;
    final folder = await widget.onFolderCreated(name);
    if (mounted) setState(() => _folderFilter = folder.id.toString());
  }

  Future<bool> _deleteFolder(HermesNoteFolder folder) async {
    final noteCount = widget.notes
        .where((note) => note.folderId == folder.id)
        .length;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Delete folder?'),
        content: Text(
          noteCount == 0
              ? 'This will delete "${folder.name}".'
              : 'This will delete "${folder.name}". The $noteCount ${noteCount == 1 ? 'note' : 'notes'} in it will stay in All Notes.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (confirmed != true) return false;
    await widget.onFolderDeleted(folder);
    if (!mounted) return true;
    if (_folderFilter == folder.id.toString()) {
      setState(() => _folderFilter = 'all');
    }
    return true;
  }

  Future<void> _showNotesListOptionsSheet() async {
    await showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (sheetContext) => _NotesListOptionsSheet(
        folders: widget.folders,
        notes: widget.notes,
        selectedFolder: _folderFilter,
        selectedSort: _noteSort,
        onFilterSelected: (value) {
          Navigator.pop(sheetContext);
          if (mounted) setState(() => _folderFilter = value);
        },
        onSortSelected: (value) {
          Navigator.pop(sheetContext);
          if (mounted) setState(() => _noteSort = value);
        },
        onNewFolder: () {
          Navigator.pop(sheetContext);
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) unawaited(_newFolder());
          });
        },
        onDeleteFolder: _deleteFolder,
      ),
    );
  }

  Future<void> _showMoveFolderSheet() async {
    final selectedFolderId = await showModalBottomSheet<int?>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.folder_open_rounded),
              title: const Text('All Notes'),
              onTap: () => Navigator.pop(context, -2),
            ),
            ...widget.folders.map(
              (folder) => ListTile(
                leading: const Icon(Icons.folder_rounded),
                title: Text(folder.name),
                onTap: () => Navigator.pop(context, folder.id),
              ),
            ),
            ListTile(
              leading: const Icon(Icons.create_new_folder_rounded),
              title: const Text('New folder'),
              onTap: () async {
                Navigator.pop(context, -1);
              },
            ),
          ],
        ),
      ),
    );
    if (!mounted) return;
    if (selectedFolderId == -1) {
      await _newFolder();
      final folderId = int.tryParse(_folderFilter);
      if (folderId != null) await _moveSelectedNoteToFolder(folderId);
      return;
    }
    if (selectedFolderId == -2) {
      await _moveSelectedNoteToFolder(null);
      return;
    }
    if (selectedFolderId == null) return;
    await _moveSelectedNoteToFolder(selectedFolderId);
  }

  void _handleEditorFocusChanged() {
    if (mounted) setState(() {});
  }

  void _dismissEditorFocus(PointerDownEvent event) {
    _titleFocusNode.unfocus();
    _bodyFocusNode.unfocus();
  }

  void _openNote(HermesNote note) {
    unawaited(_flushAutosave());
    setState(() => _selectNote(note));
    _focusBodyOnNextFrame();
  }

  Future<void> _closeNote() async {
    await _flushAutosave();
    if (!mounted) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _selectedId = null;
      _editingFolderId = null;
    });
  }

  void _focusBodyOnNextFrame() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _bodyFocusNode.requestFocus();
    });
  }

  void _queueAutosave() {
    final note = _selectedNote;
    if (note == null || _noteIsLocked(note)) return;
    _autosaveTimer?.cancel();
    _autosaveTimer = Timer(const Duration(milliseconds: 700), () {
      if (mounted) unawaited(_save());
    });
    if (!_saving) setState(() => _saving = true);
  }

  Future<void> _flushAutosave() async {
    final note = _selectedNote;
    if (note == null || _noteIsLocked(note)) return;
    final hadPending = _autosaveTimer?.isActive ?? false;
    _autosaveTimer?.cancel();
    if (hadPending) await _save();
  }

  bool _noteIsLocked(HermesNote? note) {
    final locked = note?.metadata['locked'] ?? note?.metadata['is_locked'];
    return locked == true || locked?.toString() == 'true';
  }

  void _toggleInlineFormat(String kind) {
    if (_noteIsLocked(_selectedNote)) return;
    final selection = _bodyController.selection;
    final text = _bodyController.text;
    final start = selection.start < 0 ? text.length : selection.start;
    final end = selection.end < 0 ? text.length : selection.end;
    final range = TextRange(
      start: math.min(start, end).clamp(0, text.length),
      end: math.max(start, end).clamp(0, text.length),
    );
    if (range.isCollapsed) {
      setState(() {
        if (_activeTypingFormats.contains(kind)) {
          _activeTypingFormats.remove(kind);
        } else {
          _activeTypingFormats.add(kind);
        }
      });
      _bodyFocusNode.requestFocus();
      return;
    }

    if (_bodyController.rangeFullyHasFormat(kind, range.start, range.end)) {
      _bodyController.removeFormat(kind, range);
    } else {
      _bodyController.addFormat(_NoteTextFormat(range.start, range.end, kind));
    }
    _bodyController.selection = TextSelection(
      baseOffset: range.start,
      extentOffset: range.end,
    );
    _queueAutosave();
    _keepBodyToolbarOpen();
  }

  void _applyLineFormat(String kind) {
    if (_noteIsLocked(_selectedNote)) return;
    final lineRange = _currentLineRange();
    if (lineRange == null) return;
    if (lineRange.isCollapsed) {
      setState(() {
        if (_activeTypingFormats.contains(kind)) {
          _activeTypingFormats.remove(kind);
        } else {
          _activeTypingFormats.add(kind);
        }
      });
      _bodyFocusNode.requestFocus();
      return;
    }
    if (_bodyController.rangeFullyHasFormat(
      kind,
      lineRange.start,
      lineRange.end,
    )) {
      _bodyController.removeFormat(kind, lineRange);
    } else {
      _bodyController.addFormat(
        _NoteTextFormat(lineRange.start, lineRange.end, kind),
        replaceKinds: {'heading'},
      );
    }
    _queueAutosave();
    _keepBodyToolbarOpen();
  }

  void _insertListPrefix(String prefix) {
    if (_noteIsLocked(_selectedNote)) return;
    final lineRange = _currentLineRange();
    if (lineRange == null) return;
    final text = _bodyController.text;
    final line = text.substring(lineRange.start, lineRange.end);
    final marker = _noteLineMarkerForLine(line, lineRange.start);
    final indentationLength = _noteLineIndentationLength(line);
    final insertion =
        marker?.markerStart ?? lineRange.start + indentationLength;
    final nextText = marker == null
        ? text.replaceRange(insertion, insertion, prefix)
        : marker.marker == prefix
        ? text
        : text.replaceRange(marker.markerStart, marker.markerEnd, prefix);
    if (nextText == text) {
      _keepBodyToolbarOpen();
      return;
    }
    _replaceBodyTextFromFormatter(
      previousText: text,
      nextText: nextText,
      selectionOffset: insertion + prefix.length,
    );
    if (prefix == '• ') {
      _bodyController.removeFormat(
        'checkbox_checked',
        TextRange(start: insertion, end: insertion + 1),
      );
    }
    _queueAutosave();
    _keepBodyToolbarOpen();
  }

  void _insertCheckboxPrefix() {
    if (_noteIsLocked(_selectedNote)) return;
    final lineRange = _currentLineRange();
    if (lineRange == null) return;
    final text = _bodyController.text;
    final line = text.substring(lineRange.start, lineRange.end);
    final marker = _noteLineMarkerForLine(line, lineRange.start);
    final indentationLength = _noteLineIndentationLength(line);
    final insertion =
        marker?.markerStart ?? lineRange.start + indentationLength;
    final nextText = marker == null
        ? text.replaceRange(insertion, insertion, '☐ ')
        : marker.isUncheckedCheckbox
        ? text
        : text.replaceRange(marker.markerStart, marker.markerEnd, '☐ ');
    if (nextText == text) {
      _keepBodyToolbarOpen();
      return;
    }
    _replaceBodyTextFromFormatter(
      previousText: text,
      nextText: nextText,
      selectionOffset: insertion + 2,
    );
    _bodyController.removeFormat(
      'checkbox_checked',
      TextRange(start: insertion, end: insertion + 1),
    );
    _queueAutosave();
    _keepBodyToolbarOpen();
  }

  void _handleBodyPointerUp(PointerUpEvent event) {
    if (_noteIsLocked(_selectedNote)) return;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || !_bodyFocusNode.hasFocus) return;
      _toggleCheckboxAtSelection();
    });
  }

  void _toggleCheckboxAtSelection() {
    final text = _bodyController.text;
    final offset = _bodyController.selection.baseOffset;
    if (offset < 0 || offset > text.length) return;
    final before = text.lastIndexOf('\n', math.max(0, offset - 1));
    final lineStart = before == -1 ? 0 : before + 1;
    final after = text.indexOf('\n', offset);
    final lineEnd = after == -1 ? text.length : after;
    final line = text.substring(lineStart, lineEnd);
    final marker = _noteLineMarkerForLine(line, lineStart);
    if (marker == null || !marker.isCheckbox) return;
    if (offset < marker.markerStart || offset > marker.markerEnd) return;
    if (marker.isCheckedCheckbox) {
      _bodyController.text = text.replaceRange(
        marker.markerStart,
        marker.markerEnd,
        '☐ ',
      );
      _lastBodyText = _bodyController.text;
    }
    final range = TextRange(
      start: marker.markerStart,
      end: marker.markerStart + 1,
    );
    if (_bodyController.rangeFullyHasFormat(
      'checkbox_checked',
      range.start,
      range.end,
    )) {
      _bodyController.removeFormat('checkbox_checked', range);
    } else {
      _bodyController.addFormat(
        _NoteTextFormat(range.start, range.end, 'checkbox_checked'),
      );
    }
    _bodyController.selection = TextSelection.collapsed(
      offset: math.min(marker.markerEnd, _bodyController.text.length),
    );
    _queueAutosave();
    _keepBodyToolbarOpen();
  }

  void _replaceBodyTextFromFormatter({
    required String previousText,
    required String nextText,
    required int selectionOffset,
  }) {
    _bodyController.value = _bodyController.value.copyWith(
      text: nextText,
      selection: TextSelection.collapsed(
        offset: selectionOffset.clamp(0, nextText.length),
      ),
      composing: TextRange.empty,
    );
    _bodyController.reconcileTextChange(
      previousText: previousText,
      activeFormats: const {},
    );
    _lastBodyText = _bodyController.text;
    _bodyController.clampFormats();
  }

  void _keepBodyToolbarOpen() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || _noteIsLocked(_selectedNote)) return;
      _bodyFocusNode.requestFocus();
    });
  }

  TextRange? _currentLineRange() {
    final text = _bodyController.text;
    if (text.isEmpty) return const TextRange(start: 0, end: 0);
    final selection = _bodyController.selection;
    final offset =
        (selection.baseOffset < 0 ? text.length : selection.baseOffset).clamp(
          0,
          text.length,
        );
    final before = text.lastIndexOf('\n', math.max(0, offset - 1));
    final after = text.indexOf('\n', offset);
    return TextRange(
      start: before == -1 ? 0 : before + 1,
      end: after == -1 ? text.length : after,
    );
  }

  void _insertDivider() {
    if (_noteIsLocked(_selectedNote)) return;
    final text = _bodyController.text;
    final selection = _bodyController.selection;
    final start = selection.start < 0 ? text.length : selection.start;
    final end = selection.end < 0 ? text.length : selection.end;
    final prefix = start == 0 || text.substring(0, start).endsWith('\n')
        ? ''
        : '\n';
    final divider = '$prefix────────────\n';
    _bodyController.text = text.replaceRange(start, end, divider);
    _bodyController.selection = TextSelection.collapsed(
      offset: start + divider.length,
    );
    _queueAutosave();
  }

  void _handleBodyChanged(String _) {
    final previousText = _lastBodyText;
    _continueListAfterLineBreak(previousText);
    _bodyController.reconcileTextChange(
      previousText: previousText,
      activeFormats: _activeTypingFormats,
    );
    _lastBodyText = _bodyController.text;
    _bodyController.clampFormats();
    _queueAutosave();
  }

  void _continueListAfterLineBreak(String previousText) {
    final currentText = _bodyController.text;
    if (previousText == currentText) return;

    var prefix = 0;
    while (prefix < previousText.length &&
        prefix < currentText.length &&
        previousText.codeUnitAt(prefix) == currentText.codeUnitAt(prefix)) {
      prefix += 1;
    }

    var previousSuffix = previousText.length;
    var currentSuffix = currentText.length;
    while (previousSuffix > prefix &&
        currentSuffix > prefix &&
        previousText.codeUnitAt(previousSuffix - 1) ==
            currentText.codeUnitAt(currentSuffix - 1)) {
      previousSuffix -= 1;
      currentSuffix -= 1;
    }

    if (previousSuffix != prefix) return;
    final inserted = currentText.substring(prefix, currentSuffix);
    if (inserted != '\n') return;

    final beforeLineBreak = previousText.lastIndexOf(
      '\n',
      math.max(0, prefix - 1),
    );
    final lineStart = beforeLineBreak == -1 ? 0 : beforeLineBreak + 1;
    final previousLine = previousText.substring(lineStart, prefix);
    final continuation = _continuedLinePrefix(previousLine);
    if (continuation == null) return;

    final nextText = currentText.replaceRange(
      prefix,
      prefix + 1,
      '\n$continuation',
    );
    final nextOffset = prefix + 1 + continuation.length;
    _bodyController.value = _bodyController.value.copyWith(
      text: nextText,
      selection: TextSelection.collapsed(offset: nextOffset),
      composing: TextRange.empty,
    );
  }

  String? _continuedLinePrefix(String line) {
    final match = RegExp(r'^(\s*)(☐ |☑ |• )').firstMatch(line);
    if (match == null) {
      if (line.trim().isEmpty) return null;
      final indentationLength = _noteLineIndentationLength(line);
      return indentationLength == 0
          ? null
          : line.substring(0, indentationLength);
    }
    final indentation = match.group(1) ?? '';
    final marker = match.group(2);
    if (marker == '• ') return '$indentation• ';
    return '$indentation☐ ';
  }

  void _indentSelectedLines(int amount) {
    if (_noteIsLocked(_selectedNote)) return;
    final previousText = _bodyController.text;
    if (previousText.isEmpty) return;
    final selection = _bodyController.selection;
    final baseOffset =
        (selection.baseOffset < 0 ? previousText.length : selection.baseOffset)
            .clamp(0, previousText.length);
    final extentOffset =
        (selection.extentOffset < 0
                ? previousText.length
                : selection.extentOffset)
            .clamp(0, previousText.length);
    final start = math.min(baseOffset, extentOffset);
    final end = math.max(baseOffset, extentOffset);
    final firstLineBreak = previousText.lastIndexOf(
      '\n',
      math.max(0, start - 1),
    );
    final firstLineStart = firstLineBreak == -1 ? 0 : firstLineBreak + 1;
    final affectedEnd = end > start && end > 0 ? end - 1 : end;

    final lineStarts = <int>[];
    var cursor = firstLineStart;
    while (cursor <= affectedEnd && cursor < previousText.length) {
      lineStarts.add(cursor);
      final nextBreak = previousText.indexOf('\n', cursor);
      if (nextBreak == -1) break;
      cursor = nextBreak + 1;
    }
    if (lineStarts.isEmpty) lineStarts.add(firstLineStart);

    var nextText = previousText;
    var nextBase = baseOffset;
    var nextExtent = extentOffset;
    for (final lineStart in lineStarts.reversed) {
      if (amount > 0) {
        nextText = nextText.replaceRange(lineStart, lineStart, '  ');
        if (lineStart <= nextBase) nextBase += 2;
        if (lineStart <= nextExtent) nextExtent += 2;
        continue;
      }

      final available = nextText.length - lineStart;
      if (available <= 0) continue;
      final removeLength = nextText.startsWith('  ', lineStart)
          ? 2
          : nextText.startsWith(' ', lineStart) ||
                nextText.startsWith('\t', lineStart)
          ? 1
          : 0;
      if (removeLength == 0) continue;
      nextText = nextText.replaceRange(lineStart, lineStart + removeLength, '');
      if (lineStart < nextBase) {
        nextBase -= math.min(removeLength, nextBase - lineStart);
      }
      if (lineStart < nextExtent) {
        nextExtent -= math.min(removeLength, nextExtent - lineStart);
      }
    }

    if (nextText == previousText) {
      _keepBodyToolbarOpen();
      return;
    }
    _bodyController.value = _bodyController.value.copyWith(
      text: nextText,
      selection: TextSelection(
        baseOffset: nextBase.clamp(0, nextText.length),
        extentOffset: nextExtent.clamp(0, nextText.length),
      ),
      composing: TextRange.empty,
    );
    _bodyController.reconcileTextChange(
      previousText: previousText,
      activeFormats: const {},
    );
    _lastBodyText = _bodyController.text;
    _bodyController.clampFormats();
    _queueAutosave();
    _keepBodyToolbarOpen();
  }

  @override
  Widget build(BuildContext context) {
    final selected = _selectedNote;
    final notes = _filteredNotes;
    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 180),
      switchInCurve: Curves.easeOut,
      switchOutCurve: Curves.easeIn,
      child: selected == null
          ? _buildListScreen(notes)
          : _buildDetailScreen(selected),
    );
  }

  Widget _buildListScreen(List<HermesNote> notes) {
    final pinned = notes.where((note) => note.isPinned).toList();
    final unpinned = notes.where((note) => !note.isPinned).toList();
    return Container(
      key: const Key('notes-view'),
      color: HeyBeanTheme.surface2,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 14, 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(6, 0, 6, 8),
                  child: Text(
                    _currentFolderTitle,
                    key: const Key('notes-folder-title'),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: HeyBeanTheme.text,
                      fontSize: 24,
                      fontWeight: FontWeight.w900,
                      letterSpacing: 0,
                    ),
                  ),
                ),
                Row(
                  children: [
                    IconButton.outlined(
                      key: const Key('notes-list-menu'),
                      onPressed: _showNotesListOptionsSheet,
                      icon: const Icon(Icons.more_vert_rounded),
                      tooltip: 'Notes options',
                      style: IconButton.styleFrom(
                        foregroundColor: HeyBeanTheme.text,
                        side: const BorderSide(color: HeyBeanTheme.border),
                        backgroundColor: HeyBeanTheme.surface,
                        fixedSize: const Size(38, 38),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(child: _searchField()),
                  ],
                ),
              ],
            ),
          ),
          Expanded(
            child: notes.isEmpty
                ? _emptyNotesList()
                : ListView.builder(
                    key: const Key('notes-list-screen'),
                    padding: const EdgeInsets.fromLTRB(0, 0, 0, 96),
                    itemCount:
                        (pinned.isNotEmpty ? 1 : 0) +
                        (unpinned.isNotEmpty ? 1 : 0),
                    itemBuilder: (context, index) {
                      if (pinned.isNotEmpty && index == 0) {
                        return _NoteSection(
                          title: 'Pinned',
                          notes: pinned,
                          onTap: _openNote,
                        );
                      }
                      return _NoteSection(
                        title: null,
                        notes: unpinned,
                        onTap: _openNote,
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }

  Widget _buildDetailScreen(HermesNote selected) {
    final locked = _noteIsLocked(selected);
    final toolbarVisible =
        !locked && (_titleFocusNode.hasFocus || _bodyFocusNode.hasFocus);
    return Container(
      key: ValueKey('note-detail-${selected.id}'),
      color: HeyBeanTheme.surface,
      child: Stack(
        children: [
          Column(
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(8, 8, 8, 6),
                child: Row(
                  children: [
                    IconButton(
                      key: const Key('note-detail-back'),
                      onPressed: _closeNote,
                      icon: const Icon(Icons.arrow_back_ios_new_rounded),
                      tooltip: 'Notes',
                    ),
                    Expanded(
                      child: Text(
                        _saving
                            ? 'Saving...'
                            : locked
                            ? 'Locked'
                            : 'Notes',
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          color: HeyBeanTheme.muted,
                          fontSize: 13,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ),
                    PopupMenuButton<String>(
                      key: const Key('note-detail-menu'),
                      icon: const Icon(Icons.more_vert_rounded),
                      tooltip: 'Note actions',
                      onSelected: (value) async {
                        switch (value) {
                          case 'pin':
                            await _togglePin();
                            break;
                          case 'move':
                            await _showMoveFolderSheet();
                            break;
                          case 'workspaces':
                            await _showNoteWorkspaceSheet();
                            break;
                          case 'lock':
                            await _toggleLock();
                            break;
                          case 'delete':
                            await _deleteNote();
                            break;
                        }
                      },
                      itemBuilder: (context) => [
                        PopupMenuItem<String>(
                          value: 'pin',
                          child: Text(
                            selected.isPinned ? 'Unpin Note' : 'Pin Note',
                          ),
                        ),
                        const PopupMenuItem<String>(
                          value: 'move',
                          child: Text('Move to Folder'),
                        ),
                        const PopupMenuItem<String>(
                          key: Key('note-workspaces-action'),
                          value: 'workspaces',
                          child: Text('Workspaces'),
                        ),
                        PopupMenuItem<String>(
                          value: 'lock',
                          child: Text(locked ? 'Unlock Note' : 'Lock Note'),
                        ),
                        const PopupMenuDivider(),
                        const PopupMenuItem<String>(
                          value: 'delete',
                          child: Text(
                            'Delete Note',
                            style: TextStyle(color: HeyBeanTheme.destructive),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              if (locked)
                Container(
                  margin: const EdgeInsets.fromLTRB(18, 4, 18, 8),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: HeyBeanTheme.accent.withValues(alpha: .12),
                    border: Border.all(
                      color: HeyBeanTheme.accent.withValues(alpha: .25),
                    ),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Row(
                    children: const [
                      Icon(Icons.lock_rounded, size: 18),
                      SizedBox(width: 10),
                      Expanded(child: Text('This note is locked for editing.')),
                    ],
                  ),
                ),
              Expanded(
                child: ListView(
                  padding: EdgeInsets.fromLTRB(
                    18,
                    0,
                    18,
                    toolbarVisible ? 88 : 28,
                  ),
                  children: [
                    TextField(
                      controller: _titleController,
                      focusNode: _titleFocusNode,
                      readOnly: locked,
                      textInputAction: TextInputAction.next,
                      onChanged: (_) => _queueAutosave(),
                      onTapOutside: _dismissEditorFocus,
                      style: const TextStyle(
                        fontSize: 30,
                        fontWeight: FontWeight.w900,
                      ),
                      decoration: const InputDecoration(
                        border: InputBorder.none,
                        enabledBorder: InputBorder.none,
                        focusedBorder: InputBorder.none,
                        disabledBorder: InputBorder.none,
                        errorBorder: InputBorder.none,
                        focusedErrorBorder: InputBorder.none,
                        hintText: 'New Note',
                      ),
                    ),
                    const Divider(
                      height: 1,
                      thickness: 1,
                      color: HeyBeanTheme.border,
                    ),
                    Listener(
                      behavior: HitTestBehavior.translucent,
                      onPointerUp: _handleBodyPointerUp,
                      child: TextField(
                        key: const Key('note-body-field'),
                        controller: _bodyController,
                        focusNode: _bodyFocusNode,
                        readOnly: locked,
                        minLines: 18,
                        maxLines: null,
                        keyboardType: TextInputType.multiline,
                        textAlignVertical: TextAlignVertical.top,
                        onChanged: _handleBodyChanged,
                        onTapOutside: _dismissEditorFocus,
                        decoration: const InputDecoration(
                          border: InputBorder.none,
                          enabledBorder: InputBorder.none,
                          focusedBorder: InputBorder.none,
                          disabledBorder: InputBorder.none,
                          errorBorder: InputBorder.none,
                          focusedErrorBorder: InputBorder.none,
                          hintText: 'Start writing',
                        ),
                        style: const TextStyle(fontSize: 17, height: 1.55),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          AnimatedPositioned(
            duration: const Duration(milliseconds: 180),
            curve: Curves.easeOut,
            left: 0,
            right: 0,
            bottom: toolbarVisible ? 0 : -70,
            child: IgnorePointer(
              ignoring: !toolbarVisible,
              child: AnimatedOpacity(
                duration: const Duration(milliseconds: 140),
                opacity: toolbarVisible ? 1 : 0,
                child: TextFieldTapRegion(child: _keyboardToolbar()),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _emptyNotesList() => Center(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        const _BeanNotesIcon(color: HeyBeanTheme.muted, size: 42),
        const SizedBox(height: 10),
        const Text('No notes', style: TextStyle(fontWeight: FontWeight.w800)),
        const SizedBox(height: 12),
        _ThemedPlusButton(onPressed: _newNote, tooltip: 'New note'),
      ],
    ),
  );

  Widget _keyboardToolbar() => SafeArea(
    top: false,
    child: Material(
      elevation: 10,
      color: HeyBeanTheme.surface,
      child: Container(
        height: 54,
        decoration: const BoxDecoration(
          border: Border(top: BorderSide(color: HeyBeanTheme.border)),
        ),
        child: ListView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
          children: [
            _formatButton(
              'H1',
              () => _applyLineFormat('heading'),
              active: _activeTypingFormats.contains('heading'),
              key: const Key('note-format-heading'),
            ),
            _formatButton(
              'B',
              () => _toggleInlineFormat('bold'),
              active: _activeTypingFormats.contains('bold'),
              key: const Key('note-format-bold'),
            ),
            _formatButton(
              'I',
              () => _toggleInlineFormat('italic'),
              active: _activeTypingFormats.contains('italic'),
              key: const Key('note-format-italic'),
            ),
            _formatIconButton(
              Icons.check_box_outlined,
              _insertCheckboxPrefix,
              key: const Key('note-format-checkbox'),
            ),
            _formatIconButton(
              Icons.format_list_bulleted_rounded,
              () => _insertListPrefix('• '),
              key: const Key('note-format-bullet-list'),
            ),
            _formatIconButton(
              Icons.format_indent_decrease_rounded,
              () => _indentSelectedLines(-1),
              key: const Key('note-format-outdent'),
            ),
            _formatIconButton(
              Icons.format_indent_increase_rounded,
              () => _indentSelectedLines(1),
              key: const Key('note-format-indent'),
            ),
            _formatIconButton(
              Icons.horizontal_rule_rounded,
              _insertDivider,
              key: const Key('note-format-divider'),
            ),
          ],
        ),
      ),
    ),
  );

  Widget _formatButton(
    String label,
    VoidCallback onPressed, {
    bool active = false,
    Key? key,
  }) => Padding(
    padding: const EdgeInsets.only(right: 8),
    child: OutlinedButton(
      key: key,
      onPressed: onPressed,
      style: OutlinedButton.styleFrom(
        backgroundColor: active
            ? HeyBeanTheme.accent.withValues(alpha: .14)
            : null,
        foregroundColor: active ? HeyBeanTheme.accentStrong : null,
        side: BorderSide(
          color: active ? HeyBeanTheme.accentStrong : HeyBeanTheme.border,
        ),
        minimumSize: const Size(42, 38),
        padding: const EdgeInsets.symmetric(horizontal: 12),
      ),
      child: Text(label, style: const TextStyle(fontWeight: FontWeight.w900)),
    ),
  );

  Widget _formatIconButton(IconData icon, VoidCallback onPressed, {Key? key}) =>
      Padding(
        padding: const EdgeInsets.only(right: 8),
        child: IconButton.outlined(
          key: key,
          onPressed: onPressed,
          icon: Icon(icon),
        ),
      );

  Widget _searchField() => TextField(
    key: const Key('notes-search-field'),
    controller: _searchController,
    textAlignVertical: TextAlignVertical.center,
    decoration: InputDecoration(
      prefixIcon: const Icon(Icons.search),
      hintText: 'Search',
      isDense: true,
      filled: true,
      fillColor: HeyBeanTheme.surface,
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      border: const OutlineInputBorder(
        borderRadius: BorderRadius.all(Radius.circular(999)),
        borderSide: BorderSide(color: HeyBeanTheme.border),
      ),
      enabledBorder: const OutlineInputBorder(
        borderRadius: BorderRadius.all(Radius.circular(999)),
        borderSide: BorderSide(color: HeyBeanTheme.border),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: const BorderRadius.all(Radius.circular(999)),
        borderSide: BorderSide(color: HeyBeanTheme.accentStrong, width: 1.5),
      ),
    ),
    onChanged: (_) => setState(() {}),
  );
}

class _NotesListOptionsSheet extends StatefulWidget {
  const _NotesListOptionsSheet({
    required this.folders,
    required this.notes,
    required this.selectedFolder,
    required this.selectedSort,
    required this.onFilterSelected,
    required this.onSortSelected,
    required this.onNewFolder,
    required this.onDeleteFolder,
  });

  final List<HermesNoteFolder> folders;
  final List<HermesNote> notes;
  final String selectedFolder;
  final String selectedSort;
  final ValueChanged<String> onFilterSelected;
  final ValueChanged<String> onSortSelected;
  final VoidCallback onNewFolder;
  final Future<bool> Function(HermesNoteFolder folder) onDeleteFolder;

  @override
  State<_NotesListOptionsSheet> createState() => _NotesListOptionsSheetState();
}

class _NotesListOptionsSheetState extends State<_NotesListOptionsSheet> {
  final Set<int> _deletedFolderIds = {};
  final Set<int> _deletingFolderIds = {};

  int _countForFolder(int? folderId) =>
      widget.notes.where((note) => note.folderId == folderId).length;

  Future<void> _deleteFolder(HermesNoteFolder folder) async {
    if (_deletingFolderIds.contains(folder.id)) return;
    setState(() => _deletingFolderIds.add(folder.id));
    try {
      final deleted = await widget.onDeleteFolder(folder);
      if (!mounted) return;
      if (deleted) {
        setState(() => _deletedFolderIds.add(folder.id));
      }
    } finally {
      if (mounted) setState(() => _deletingFolderIds.remove(folder.id));
    }
  }

  @override
  Widget build(BuildContext context) {
    final maxHeight = MediaQuery.sizeOf(context).height * 0.82;
    final visibleFolders = widget.folders
        .where((folder) => !_deletedFolderIds.contains(folder.id))
        .toList();
    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: maxHeight),
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(16, 2, 16, 18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                children: [
                  const Expanded(
                    child: Text(
                      'Notes options',
                      style: TextStyle(
                        color: HeyBeanTheme.text,
                        fontSize: 20,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ),
                  _ThemedPlusButton(
                    key: const Key('notes-new-folder'),
                    onPressed: widget.onNewFolder,
                    tooltip: 'New folder',
                  ),
                ],
              ),
              const SizedBox(height: 12),
              _NotesOptionsSection(
                title: 'View',
                children: [
                  _NotesOptionRow(
                    key: const Key('notes-filter-all'),
                    iconWidget: _BeanNotesIcon(
                      size: 22,
                      color: widget.selectedFolder == 'all'
                          ? HeyBeanTheme.accentStrong
                          : HeyBeanTheme.muted,
                    ),
                    label: 'All Notes',
                    count: widget.notes.length,
                    selected: widget.selectedFolder == 'all',
                    onTap: () => widget.onFilterSelected('all'),
                  ),
                  _NotesOptionRow(
                    key: const Key('notes-filter-pinned'),
                    icon: Icons.push_pin_rounded,
                    label: 'Pinned',
                    count: widget.notes.where((note) => note.isPinned).length,
                    selected: widget.selectedFolder == 'pinned',
                    onTap: () => widget.onFilterSelected('pinned'),
                  ),
                  _NotesOptionRow(
                    key: const Key('notes-filter-unfiled'),
                    icon: Icons.folder_open_rounded,
                    label: 'Unfiled',
                    count: _countForFolder(null),
                    selected: widget.selectedFolder == 'unfiled',
                    onTap: () => widget.onFilterSelected('unfiled'),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              _NotesOptionsSection(
                title: 'Folders',
                emptyText: visibleFolders.isEmpty ? 'No folders yet' : null,
                children: [
                  for (final folder in visibleFolders)
                    _NotesOptionRow(
                      key: Key('notes-filter-folder-${folder.id}'),
                      icon: Icons.folder_rounded,
                      label: folder.name,
                      count: _countForFolder(folder.id),
                      selected: widget.selectedFolder == folder.id.toString(),
                      onTap: () =>
                          widget.onFilterSelected(folder.id.toString()),
                      trailing: IconButton(
                        key: Key('delete-note-folder-${folder.id}'),
                        tooltip: 'Delete ${folder.name}',
                        onPressed: _deletingFolderIds.contains(folder.id)
                            ? null
                            : () => unawaited(_deleteFolder(folder)),
                        icon: const Icon(Icons.delete_outline_rounded),
                        color: HeyBeanTheme.destructive,
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 14),
              _NotesOptionsSection(
                title: 'Sort',
                children: [
                  _NotesOptionRow(
                    key: const Key('notes-sort-recent'),
                    icon: Icons.schedule_rounded,
                    label: 'Most recently edited',
                    selected: widget.selectedSort == 'recent',
                    onTap: () => widget.onSortSelected('recent'),
                  ),
                  _NotesOptionRow(
                    key: const Key('notes-sort-title'),
                    icon: Icons.sort_by_alpha_rounded,
                    label: 'Title',
                    selected: widget.selectedSort == 'title',
                    onTap: () => widget.onSortSelected('title'),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _NotesOptionsSection extends StatelessWidget {
  const _NotesOptionsSection({
    required this.title,
    required this.children,
    this.emptyText,
  });

  final String title;
  final List<Widget> children;
  final String? emptyText;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(2, 0, 2, 8),
        child: Text(
          title,
          style: const TextStyle(
            color: HeyBeanTheme.muted,
            fontSize: 12,
            fontWeight: FontWeight.w900,
            letterSpacing: 0,
          ),
        ),
      ),
      DecoratedBox(
        decoration: BoxDecoration(
          color: HeyBeanTheme.surface,
          border: Border.all(color: HeyBeanTheme.border),
          borderRadius: BorderRadius.circular(999),
        ),
        child: children.isEmpty
            ? Padding(
                padding: const EdgeInsets.all(16),
                child: Text(
                  emptyText ?? 'Nothing to show',
                  style: const TextStyle(
                    color: HeyBeanTheme.muted,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              )
            : Column(
                children: [
                  for (var index = 0; index < children.length; index++) ...[
                    children[index],
                    if (index != children.length - 1)
                      const Divider(height: 1, indent: 56, endIndent: 12),
                  ],
                ],
              ),
      ),
    ],
  );
}

class _NotesOptionRow extends StatelessWidget {
  const _NotesOptionRow({
    super.key,
    this.icon,
    this.iconWidget,
    required this.label,
    required this.selected,
    required this.onTap,
    this.count,
    this.trailing,
  });

  final IconData? icon;
  final Widget? iconWidget;
  final String label;
  final int? count;
  final bool selected;
  final VoidCallback onTap;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Material(
    color: selected
        ? HeyBeanTheme.accent.withValues(alpha: 0.14)
        : Colors.white,
    child: InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 6, 8, 6),
        child: SizedBox(
          height: 46,
          child: Row(
            children: [
              iconWidget ??
                  Icon(
                    icon,
                    color: selected
                        ? HeyBeanTheme.accentStrong
                        : HeyBeanTheme.muted,
                    size: 22,
                  ),
              const SizedBox(width: 14),
              Expanded(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: selected
                        ? HeyBeanTheme.accentInk
                        : HeyBeanTheme.text,
                    fontWeight: selected ? FontWeight.w900 : FontWeight.w800,
                  ),
                ),
              ),
              if (count != null)
                Padding(
                  padding: const EdgeInsets.only(left: 8),
                  child: Text(
                    '$count',
                    style: const TextStyle(
                      color: HeyBeanTheme.muted,
                      fontSize: 13,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
              if (selected)
                Padding(
                  padding: const EdgeInsets.only(left: 10),
                  child: Icon(
                    Icons.check_rounded,
                    color: HeyBeanTheme.accentStrong,
                    size: 20,
                  ),
                ),
              if (trailing != null)
                Padding(
                  padding: const EdgeInsets.only(left: 4),
                  child: trailing,
                ),
            ],
          ),
        ),
      ),
    ),
  );
}

class _NoteWorkspaceSyncSheet extends StatefulWidget {
  const _NoteWorkspaceSyncSheet({
    required this.note,
    required this.workspaces,
    required this.activeWorkspaceId,
    required this.initialSyncWorkspaceIds,
  });

  final HermesNote note;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
  final Set<Object> initialSyncWorkspaceIds;

  @override
  State<_NoteWorkspaceSyncSheet> createState() =>
      _NoteWorkspaceSyncSheetState();
}

class _NoteWorkspaceSyncSheetState extends State<_NoteWorkspaceSyncSheet> {
  late final Set<int> _selectedWorkspaceIds;

  @override
  void initState() {
    super.initState();
    _selectedWorkspaceIds = widget.initialSyncWorkspaceIds
        .map(_workspaceValueToInt)
        .whereType<int>()
        .toSet();
  }

  @override
  Widget build(BuildContext context) {
    final primaryWorkspaceId =
        widget.note.workspaceId ??
        _workspaceValueToInt(widget.activeWorkspaceId);
    final primaryWorkspace = widget.workspaces
        .where((workspace) => workspace.numericId == primaryWorkspaceId)
        .cast<HermesWorkspace?>()
        .firstOrNull;
    final syncTargets =
        widget.workspaces
            .where((workspace) => workspace.numericId != null)
            .where((workspace) => workspace.numericId != primaryWorkspaceId)
            .toList()
          ..sort(
            (a, b) => a.name.toLowerCase().compareTo(b.name.toLowerCase()),
          );

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 2, 16, 18),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Note workspaces',
              style: TextStyle(
                color: HeyBeanTheme.text,
                fontSize: 20,
                fontWeight: FontWeight.w900,
              ),
            ),
            const SizedBox(height: 6),
            const Text(
              'Choose which additional workspaces this note is synced to.',
              style: TextStyle(
                color: HeyBeanTheme.muted,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 14),
            _NotesOptionsSection(
              title: 'Current copy',
              children: [
                _NotesOptionRow(
                  icon: Icons.home_work_outlined,
                  label: primaryWorkspace == null
                      ? 'Current workspace'
                      : primaryWorkspace.isPersonal
                      ? 'Personal'
                      : primaryWorkspace.name,
                  selected: true,
                  onTap: () {},
                  trailing: const Padding(
                    padding: EdgeInsets.only(left: 8, right: 8),
                    child: Text(
                      'Fixed',
                      style: TextStyle(
                        color: HeyBeanTheme.muted,
                        fontSize: 12,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 14),
            _NotesOptionsSection(
              title: 'Also sync to',
              emptyText: syncTargets.isEmpty
                  ? 'No other workspaces available'
                  : null,
              children: [
                for (final workspace in syncTargets)
                  _NotesOptionRow(
                    key: Key('note-sync-workspace-${workspace.id}'),
                    icon: Icons.account_tree_outlined,
                    label: workspace.isPersonal ? 'Personal' : workspace.name,
                    selected: _selectedWorkspaceIds.contains(
                      workspace.numericId,
                    ),
                    onTap: () {
                      final workspaceId = workspace.numericId;
                      if (workspaceId == null) return;
                      setState(() {
                        if (_selectedWorkspaceIds.contains(workspaceId)) {
                          _selectedWorkspaceIds.remove(workspaceId);
                        } else {
                          _selectedWorkspaceIds.add(workspaceId);
                        }
                      });
                    },
                  ),
              ],
            ),
            const SizedBox(height: 18),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => Navigator.pop(context),
                    child: const Text('Cancel'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: FilledButton(
                    key: const Key('note-sync-workspaces-save'),
                    onPressed: () => Navigator.pop(
                      context,
                      _selectedWorkspaceIds.toList()..sort(),
                    ),
                    child: const Text('Save'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _NoteTextFormat {
  const _NoteTextFormat(this.start, this.end, this.kind);

  final int start;
  final int end;
  final String kind;

  bool get isValid => end > start && start >= 0;

  Map<String, Object?> toJson() => {'start': start, 'end': end, 'kind': kind};

  static _NoteTextFormat? fromJson(Object? value) {
    if (value is! Map) return null;
    final start = _readIntFromObject(value['start']);
    final end = _readIntFromObject(value['end']);
    final kind = value['kind']?.toString();
    if (start == null || end == null || kind == null || kind.isEmpty) {
      return null;
    }
    final format = _NoteTextFormat(start, end, kind);
    return format.isValid ? format : null;
  }
}

class _NormalizedNoteText {
  const _NormalizedNoteText(this.text, this.formats);

  final String text;
  final List<_NoteTextFormat> formats;
}

class _NoteLineMarker {
  const _NoteLineMarker({
    required this.lineStart,
    required this.indentationLength,
    required this.marker,
  });

  final int lineStart;
  final int indentationLength;
  final String marker;

  int get markerStart => lineStart + indentationLength;
  int get markerEnd => markerStart + marker.length;
  bool get isBullet => marker == '• ';
  bool get isCheckbox => marker == '☐ ' || marker == '☑ ';
  bool get isUncheckedCheckbox => marker == '☐ ';
  bool get isCheckedCheckbox => marker == '☑ ';
}

_NoteLineMarker? _noteLineMarkerForLine(String line, int lineStart) {
  final indentationLength = _noteLineIndentationLength(line);
  if (line.length < indentationLength + 2) return null;
  final marker = line.substring(indentationLength, indentationLength + 2);
  if (marker != '• ' && marker != '☐ ' && marker != '☑ ') return null;
  return _NoteLineMarker(
    lineStart: lineStart,
    indentationLength: indentationLength,
    marker: marker,
  );
}

int _noteLineIndentationLength(String line) {
  var index = 0;
  while (index < line.length) {
    final codeUnit = line.codeUnitAt(index);
    if (codeUnit != 32 && codeUnit != 9) break;
    index += 1;
  }
  return index;
}

_NormalizedNoteText _normalizeCheckedCheckboxMarkers(String? value) {
  final text = value ?? '';
  if (!text.contains('☑ ')) return _NormalizedNoteText(text, const []);
  final buffer = StringBuffer();
  final formats = <_NoteTextFormat>[];
  var sourceOffset = 0;
  for (final line in text.split('\n')) {
    if (sourceOffset > 0) buffer.write('\n');
    final outputLineStart = buffer.length;
    final marker = _noteLineMarkerForLine(line, outputLineStart);
    if (marker?.isCheckedCheckbox == true) {
      final markerOffset = marker!.markerStart - outputLineStart;
      buffer.write(line.substring(0, markerOffset));
      buffer.write('☐ ');
      buffer.write(line.substring(markerOffset + 2));
      formats.add(
        _NoteTextFormat(
          marker.markerStart,
          marker.markerStart + 1,
          'checkbox_checked',
        ),
      );
    } else {
      buffer.write(line);
    }
    sourceOffset += line.length + 1;
  }
  return _NormalizedNoteText(buffer.toString(), formats);
}

class _FormattedNoteTextController extends TextEditingController {
  List<_NoteTextFormat> _formats = const [];

  List<_NoteTextFormat> get formats => List.unmodifiable(_formats);

  void setFormats(List<_NoteTextFormat> formats) {
    _formats = _clampedFormats(formats, text.length);
    notifyListeners();
  }

  void addFormat(
    _NoteTextFormat format, {
    Set<String> replaceKinds = const {},
  }) {
    final clamped = _clampedFormat(format, text.length);
    if (clamped == null) return;
    _formats = [
      for (final existing in _formats)
        if (!_formatOverlaps(existing, clamped) ||
            (!replaceKinds.contains(existing.kind) &&
                existing.kind != clamped.kind))
          existing,
      clamped,
    ];
    notifyListeners();
  }

  void removeFormat(String kind, TextRange range) {
    if (range.isCollapsed) return;
    final next = <_NoteTextFormat>[];
    for (final existing in _formats) {
      if (existing.kind != kind ||
          existing.end <= range.start ||
          existing.start >= range.end) {
        next.add(existing);
        continue;
      }
      if (existing.start < range.start) {
        next.add(_NoteTextFormat(existing.start, range.start, existing.kind));
      }
      if (existing.end > range.end) {
        next.add(_NoteTextFormat(range.end, existing.end, existing.kind));
      }
    }
    _formats = _clampedFormats(next, text.length);
    notifyListeners();
  }

  bool rangeFullyHasFormat(String kind, int start, int end) {
    if (start >= end) return false;
    final clampedStart = start.clamp(0, text.length);
    final clampedEnd = end.clamp(0, text.length);
    if (clampedStart >= clampedEnd) return false;
    var coveredUntil = clampedStart;
    final matching =
        _formats
            .where(
              (format) =>
                  format.kind == kind &&
                  format.end > clampedStart &&
                  format.start < clampedEnd,
            )
            .toList()
          ..sort((a, b) => a.start.compareTo(b.start));
    for (final format in matching) {
      if (format.start > coveredUntil) return false;
      if (format.end > coveredUntil) coveredUntil = format.end;
      if (coveredUntil >= clampedEnd) return true;
    }
    return false;
  }

  void reconcileTextChange({
    required String previousText,
    required Set<String> activeFormats,
  }) {
    final nextText = text;
    if (previousText == nextText) {
      clampFormats();
      return;
    }

    var prefix = 0;
    while (prefix < previousText.length &&
        prefix < nextText.length &&
        previousText.codeUnitAt(prefix) == nextText.codeUnitAt(prefix)) {
      prefix += 1;
    }

    var previousSuffix = previousText.length;
    var nextSuffix = nextText.length;
    while (previousSuffix > prefix &&
        nextSuffix > prefix &&
        previousText.codeUnitAt(previousSuffix - 1) ==
            nextText.codeUnitAt(nextSuffix - 1)) {
      previousSuffix -= 1;
      nextSuffix -= 1;
    }

    final removedLength = previousSuffix - prefix;
    final insertedLength = nextSuffix - prefix;
    final delta = insertedLength - removedLength;
    final shifted = <_NoteTextFormat>[];
    for (final format in _formats) {
      if (format.end <= prefix) {
        shifted.add(format);
      } else if (format.start >= previousSuffix) {
        shifted.add(
          _NoteTextFormat(
            format.start + delta,
            format.end + delta,
            format.kind,
          ),
        );
      } else {
        final start = math.min(format.start, prefix);
        final end = format.end > previousSuffix
            ? format.end + delta
            : math.max(start, prefix);
        if (end > start) shifted.add(_NoteTextFormat(start, end, format.kind));
      }
    }

    for (final kind in activeFormats) {
      if (insertedLength <= 0) continue;
      shifted.add(_NoteTextFormat(prefix, prefix + insertedLength, kind));
    }

    _formats = _normalizeFormats(shifted, nextText.length);
    notifyListeners();
  }

  void clampFormats() {
    _formats = _clampedFormats(_formats, text.length);
  }

  @override
  TextSpan buildTextSpan({
    required BuildContext context,
    TextStyle? style,
    required bool withComposing,
  }) {
    final baseStyle = style ?? const TextStyle();
    final value = text;
    if (value.isEmpty) {
      return TextSpan(style: baseStyle, text: value);
    }

    final boundaries = <int>{0, value.length};
    for (final format in _clampedFormats(_formats, value.length)) {
      boundaries
        ..add(format.start)
        ..add(format.end);
    }
    for (final markerStart in _checkboxMarkerStarts(value)) {
      boundaries
        ..add(markerStart)
        ..add(markerStart + 1)
        ..add(markerStart + 2);
    }
    final sorted = boundaries.toList()..sort();
    final spans = <InlineSpan>[];
    for (var index = 0; index < sorted.length - 1; index++) {
      final start = sorted[index];
      final end = sorted[index + 1];
      if (start == end) continue;
      if (_isCheckboxMarkerStart(value, start) && end == start + 1) {
        spans.add(
          WidgetSpan(
            alignment: PlaceholderAlignment.middle,
            child: _NoteCheckboxMarker(
              checked:
                  value.startsWith('☑ ', start) ||
                  rangeFullyHasFormat('checkbox_checked', start, start + 1),
            ),
          ),
        );
        continue;
      }
      spans.add(
        TextSpan(
          text: value.substring(start, end),
          style: _styleForOffset(baseStyle, start),
        ),
      );
    }
    return TextSpan(style: baseStyle, children: spans);
  }

  Iterable<int> _checkboxMarkerStarts(String value) sync* {
    var lineStart = 0;
    while (lineStart < value.length) {
      final lineEndIndex = value.indexOf('\n', lineStart);
      final lineEnd = lineEndIndex == -1 ? value.length : lineEndIndex;
      final marker = _noteLineMarkerForLine(
        value.substring(lineStart, lineEnd),
        lineStart,
      );
      if (marker != null && marker.isCheckbox) yield marker.markerStart;
      if (lineEndIndex == -1) break;
      lineStart = lineEndIndex + 1;
    }
  }

  bool _isCheckboxMarkerStart(String value, int index) {
    if (index < 0 || index >= value.length - 1) return false;
    final before = value.lastIndexOf('\n', math.max(0, index - 1));
    final lineStart = before == -1 ? 0 : before + 1;
    final after = value.indexOf('\n', index);
    final lineEnd = after == -1 ? value.length : after;
    final marker = _noteLineMarkerForLine(
      value.substring(lineStart, lineEnd),
      lineStart,
    );
    return marker != null && marker.isCheckbox && marker.markerStart == index;
  }

  TextStyle _styleForOffset(TextStyle baseStyle, int offset) {
    var next = baseStyle;
    for (final format in _formats) {
      if (format.start > offset || format.end <= offset) continue;
      switch (format.kind) {
        case 'bold':
          next = next.merge(const TextStyle(fontWeight: FontWeight.w900));
          break;
        case 'italic':
          next = next.merge(const TextStyle(fontStyle: FontStyle.italic));
          break;
        case 'heading':
          next = next.merge(
            const TextStyle(fontSize: 25, fontWeight: FontWeight.w900),
          );
          break;
      }
    }
    return next;
  }
}

class _NoteCheckboxMarker extends StatelessWidget {
  const _NoteCheckboxMarker({required this.checked});

  final bool checked;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(right: 2),
    child: SizedBox(
      width: 17,
      height: 17,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: checked ? HeyBeanTheme.accent : Colors.transparent,
          border: Border.all(
            color: checked ? HeyBeanTheme.accentStrong : HeyBeanTheme.muted,
            width: 1.6,
          ),
          borderRadius: BorderRadius.circular(4),
        ),
        child: checked
            ? const Icon(Icons.check_rounded, size: 14, color: Colors.white)
            : null,
      ),
    ),
  );
}

class _NewNoteFolderDialog extends StatefulWidget {
  const _NewNoteFolderDialog();

  @override
  State<_NewNoteFolderDialog> createState() => _NewNoteFolderDialogState();
}

class _NewNoteFolderDialogState extends State<_NewNoteFolderDialog> {
  late final TextEditingController _controller;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('New folder'),
    content: TextField(
      key: const Key('new-note-folder-name'),
      controller: _controller,
      autofocus: true,
      textCapitalization: TextCapitalization.sentences,
      textInputAction: TextInputAction.done,
      onSubmitted: (_) => _submit(),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Cancel'),
      ),
      _ThemedPlusButton(
        key: const Key('new-note-folder-create'),
        tooltip: 'Create folder',
        onPressed: _submit,
      ),
    ],
  );

  void _submit() {
    Navigator.pop(context, _controller.text.trim());
  }
}

class _NoteSection extends StatelessWidget {
  const _NoteSection({
    required this.title,
    required this.notes,
    required this.onTap,
  });

  final String? title;
  final List<HermesNote> notes;
  final ValueChanged<HermesNote> onTap;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 14),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (title != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 10, 18, 8),
            child: Text(
              title!,
              style: const TextStyle(
                color: HeyBeanTheme.muted,
                fontSize: 13,
                fontWeight: FontWeight.w900,
              ),
            ),
          ),
        Column(
          children: [
            for (var index = 0; index < notes.length; index++) ...[
              _NoteListTile(note: notes[index], onTap: onTap),
              if (index != notes.length - 1)
                const Divider(height: 1, indent: 18, endIndent: 18),
            ],
          ],
        ),
      ],
    ),
  );
}

class _NoteListTile extends StatelessWidget {
  const _NoteListTile({required this.note, required this.onTap});

  final HermesNote note;
  final ValueChanged<HermesNote> onTap;

  @override
  Widget build(BuildContext context) {
    final text = (note.plainText ?? '').trim();
    return ListTile(
      key: Key('note-list-item-${note.id}'),
      onTap: () => onTap(note),
      contentPadding: const EdgeInsets.fromLTRB(18, 4, 12, 4),
      minVerticalPadding: 10,
      title: Row(
        children: [
          Expanded(
            child: Text(
              note.title,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
          ),
          if (note.isPinned)
            const Padding(
              padding: EdgeInsets.only(left: 8),
              child: Icon(Icons.push_pin_rounded, size: 15),
            ),
        ],
      ),
      subtitle: Text(
        text.isEmpty ? 'No additional text' : text,
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
      trailing: const Icon(
        Icons.chevron_right_rounded,
        color: HeyBeanTheme.muted,
      ),
    );
  }
}

String _plainTextFromHtml(String? html) => (html ?? '')
    .replaceAll(RegExp(r'<br\s*/?>', caseSensitive: false), '\n')
    .replaceAll(RegExp(r'<[^>]+>'), '')
    .trim();

String _normalizedNotePlainText(String value) {
  if (value.trim().isEmpty) return '';
  return value
      .replaceFirst(RegExp(r'^\n+'), '')
      .replaceFirst(RegExp(r'\n+$'), '');
}

String _htmlFromFormattedPlainText(
  String plain,
  List<_NoteTextFormat> formats,
) {
  final safeFormats = _clampedFormats(formats, plain.length);
  final lines = plain.split('\n');
  var offset = 0;
  final html = <String>[];
  for (final line in lines) {
    final lineStart = offset;
    final lineEnd = offset + line.length;
    final rendered = _formattedInlineHtml(line, lineStart, safeFormats);
    final isHeading = safeFormats.any(
      (format) =>
          format.kind == 'heading' &&
          format.start < lineEnd &&
          format.end > lineStart,
    );
    html.add(isHeading ? '<h1>$rendered</h1>' : '<div>$rendered</div>');
    offset = lineEnd + 1;
  }
  return html.join();
}

String _formattedInlineHtml(
  String line,
  int lineStart,
  List<_NoteTextFormat> formats,
) {
  if (line.isEmpty) return '';
  final lineEnd = lineStart + line.length;
  final boundaries = <int>{lineStart, lineEnd};
  for (final format in formats) {
    if (format.end <= lineStart || format.start >= lineEnd) continue;
    boundaries
      ..add(format.start.clamp(lineStart, lineEnd))
      ..add(format.end.clamp(lineStart, lineEnd));
  }
  final sorted = boundaries.toList()..sort();
  final chunks = <String>[];
  for (var index = 0; index < sorted.length - 1; index++) {
    final start = sorted[index];
    final end = sorted[index + 1];
    if (start == end) continue;
    var chunk = _escapeHtml(line.substring(start - lineStart, end - lineStart));
    final active = formats.where(
      (format) =>
          format.start < end &&
          format.end > start &&
          (format.kind == 'bold' || format.kind == 'italic'),
    );
    if (active.any((format) => format.kind == 'bold')) {
      chunk = '<strong>$chunk</strong>';
    }
    if (active.any((format) => format.kind == 'italic')) {
      chunk = '<em>$chunk</em>';
    }
    chunks.add(chunk);
  }
  return chunks.join();
}

String _escapeHtml(String value) => value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;');

Map<String, Object?> _metadataWithNoteFormats(
  Map<String, Object?> metadata,
  List<_NoteTextFormat> formats,
) {
  final next = Map<String, Object?>.from(metadata);
  final validFormats = _clampedFormats(formats, 1 << 30);
  if (validFormats.isEmpty) {
    next.remove('flutter_note_formats');
  } else {
    next['flutter_note_formats'] = validFormats
        .map((format) => format.toJson())
        .toList();
  }
  return next;
}

List<_NoteTextFormat> _noteFormatsFromMetadata(Map<String, Object?>? metadata) {
  final raw = metadata?['flutter_note_formats'];
  if (raw is! List) return const [];
  return _clampedFormats(
    raw.map(_NoteTextFormat.fromJson).whereType<_NoteTextFormat>().toList(),
    1 << 30,
  );
}

List<_NoteTextFormat> _clampedFormats(
  List<_NoteTextFormat> formats,
  int textLength,
) => [
  for (final format in formats)
    if (_clampedFormat(format, textLength) case final clamped?) clamped,
];

List<_NoteTextFormat> _normalizeFormats(
  List<_NoteTextFormat> formats,
  int textLength,
) {
  final clamped = _clampedFormats(formats, textLength)
    ..sort((a, b) {
      final kindCompare = a.kind.compareTo(b.kind);
      if (kindCompare != 0) return kindCompare;
      return a.start.compareTo(b.start);
    });
  final normalized = <_NoteTextFormat>[];
  for (final format in clamped) {
    if (normalized.isNotEmpty) {
      final previous = normalized.last;
      if (previous.kind == format.kind && previous.end >= format.start) {
        normalized[normalized.length - 1] = _NoteTextFormat(
          previous.start,
          math.max(previous.end, format.end),
          previous.kind,
        );
        continue;
      }
    }
    normalized.add(format);
  }
  return normalized;
}

_NoteTextFormat? _clampedFormat(_NoteTextFormat format, int textLength) {
  final start = format.start.clamp(0, textLength);
  final end = format.end.clamp(0, textLength);
  final clamped = _NoteTextFormat(start, end, format.kind);
  return clamped.isValid ? clamped : null;
}

bool _formatOverlaps(_NoteTextFormat a, _NoteTextFormat b) =>
    a.start < b.end && b.start < a.end;

int? _readIntFromObject(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  return int.tryParse(value?.toString() ?? '');
}

class _SettingsView extends StatelessWidget {
  const _SettingsView({
    required this.apiClient,
    required this.launchExternalUrl,
    required this.stripePaymentHandler,
    required this.user,
    required this.onBillingChanged,
    this.googleCalendarStatus,
    required this.calendarStartHour,
    required this.calendarEndHour,
    required this.onCalendarStartHourChanged,
    required this.onCalendarEndHourChanged,
    required this.onDeleteAccount,
    required this.onSignOut,
    required this.onAccountEmailChanged,
    required this.onNotificationPreferencesChanged,
    required this.onThemeChanged,
    required this.onCommandCenterLabelChanged,
    required this.onEditAgentOnboarding,
    required this.onWorkspacesChanged,
    this.error,
  });

  final HermesApiClient apiClient;
  final ExternalUrlLauncher launchExternalUrl;
  final StripePaymentHandler stripePaymentHandler;
  final HermesUser user;
  final Future<void> Function() onBillingChanged;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final int calendarStartHour;
  final int calendarEndHour;
  final ValueChanged<int> onCalendarStartHourChanged;
  final ValueChanged<int> onCalendarEndHourChanged;
  final Future<void> Function() onDeleteAccount;
  final Future<void> Function() onSignOut;
  final Future<void> Function(String email) onAccountEmailChanged;
  final Future<void> Function(HermesNotificationPreferences preferences)
  onNotificationPreferencesChanged;
  final Future<void> Function(String themeKey) onThemeChanged;
  final Future<void> Function(String label) onCommandCenterLabelChanged;
  final VoidCallback onEditAgentOnboarding;
  final Future<void> Function() onWorkspacesChanged;
  final String? error;

  @override
  Widget build(BuildContext context) => Column(
    key: const Key('settings-view'),
    children: [
      _ShellCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const _SectionTitle(
              icon: Icons.settings_rounded,
              title: 'Settings',
              subtitle: 'Focused Hermes Bean preferences',
              infoKey: Key('settings-info'),
              infoTitle: 'Settings help',
              infoBullets: [
                'Update Bean preferences when you want the assistant to plan, speak, or prioritize differently.',
                'Workspaces keep household calendars, tasks, and reminders separated from Personal.',
                'Account controls, legal links, and sign out live at the bottom of Settings.',
              ],
            ),
            const SizedBox(height: 12),
            if (error != null) ...[
              _InlinePlanLimitError(
                message: error!,
                launchExternalUrl: launchExternalUrl,
              ),
              const SizedBox(height: 12),
            ],
            _CompactItemTile(
              icon: Icons.person_outline_rounded,
              title: user.name,
              subtitle: user.email,
            ),
            _CompactItemTile(
              icon: Icons.tune_rounded,
              title: 'Bean preferences',
              subtitle: _agentPreferencesSummary(user.currentAgentProfile),
              trailing: TextButton(
                key: const Key('open-bean-preferences'),
                onPressed: onEditAgentOnboarding,
                child: const Text('Update'),
              ),
            ),
            _ThemePreferencesCard(
              selectedThemeKey: user.theme,
              commandCenterLabel: user.commandCenterLabel,
              onChanged: onThemeChanged,
              onCommandCenterLabelChanged: onCommandCenterLabelChanged,
            ),
            _NotificationPreferencesCard(
              preferences: user.notificationPreferences,
              onChanged: onNotificationPreferencesChanged,
            ),
            const SizedBox(height: 8),
            _WorkspacesSettingsCard(
              apiClient: apiClient,
              launchExternalUrl: launchExternalUrl,
              user: user,
              googleCalendarStatus: googleCalendarStatus,
              onChanged: onWorkspacesChanged,
            ),
            _GoogleCalendarSyncCard(
              apiClient: apiClient,
              launchExternalUrl: launchExternalUrl,
            ),
            _CalendarPreferencesCard(
              startHour: calendarStartHour,
              endHour: calendarEndHour,
              onStartHourChanged: onCalendarStartHourChanged,
              onEndHourChanged: onCalendarEndHourChanged,
            ),
          ],
        ),
      ),
      const SizedBox(height: 16),
      _AccountCard(
        user: user,
        onEmailChanged: onAccountEmailChanged,
        onDeleteAccount: onDeleteAccount,
        onSignOut: onSignOut,
        launchExternalUrl: launchExternalUrl,
        showLegalLinks: false,
        beforeAccountActions: _BillingSettingsCard(
          apiClient: apiClient,
          user: user,
          stripePaymentHandler: stripePaymentHandler,
          onBillingChanged: onBillingChanged,
        ),
      ),
      const SizedBox(height: 10),
      _SettingsLegalLinksRow(launchExternalUrl: launchExternalUrl),
    ],
  );
}

class _BillingSettingsCard extends StatefulWidget {
  const _BillingSettingsCard({
    required this.apiClient,
    required this.user,
    required this.stripePaymentHandler,
    required this.onBillingChanged,
  });

  final HermesApiClient apiClient;
  final HermesUser user;
  final StripePaymentHandler stripePaymentHandler;
  final Future<void> Function() onBillingChanged;

  @override
  State<_BillingSettingsCard> createState() => _BillingSettingsCardState();
}

class _BillingSettingsCardState extends State<_BillingSettingsCard> {
  HermesBillingPaymentMethod? _paymentMethod;
  HermesSubscriptionSummary? _subscription;
  bool _loadingPaymentMethod = true;
  bool _loadingSubscription = true;
  bool _busy = false;
  String? _error;
  String? _message;

  String get _planLabel => _subscriptionPlanLabel(
    _subscription?.tier ?? widget.user.subscriptionTier,
  );

  @override
  void initState() {
    super.initState();
    unawaited(_loadPaymentMethod());
    unawaited(_loadSubscriptionSummary());
  }

  @override
  void didUpdateWidget(covariant _BillingSettingsCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.user.subscriptionTier != widget.user.subscriptionTier ||
        oldWidget.user.subscriptionStatus != widget.user.subscriptionStatus) {
      unawaited(_loadPaymentMethod());
      unawaited(_loadSubscriptionSummary());
    }
  }

  HermesSubscriptionSummary get _fallbackSubscription =>
      HermesSubscriptionSummary(
        tier: widget.user.subscriptionTier,
        status: widget.user.subscriptionStatus,
        trialEndsAt: widget.user.subscriptionTrialEndsAt,
      );

  HermesSubscriptionSummary get _currentSubscription =>
      _subscription ?? _fallbackSubscription;

  Future<void> _loadPaymentMethod() async {
    setState(() {
      _loadingPaymentMethod = true;
      _error = null;
    });
    try {
      final paymentMethod = await widget.apiClient.getBillingPaymentMethod();
      if (!mounted) return;
      setState(() => _paymentMethod = paymentMethod);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = beanFriendlyErrorMessage(
          error,
          action: 'load your payment method',
        );
      });
    } finally {
      if (mounted) setState(() => _loadingPaymentMethod = false);
    }
  }

  Future<void> _loadSubscriptionSummary() async {
    setState(() {
      _loadingSubscription = true;
      _error = null;
    });
    try {
      final summary = await widget.apiClient.getSubscriptionSummary();
      if (!mounted) return;
      setState(() => _subscription = summary);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = beanFriendlyErrorMessage(
          error,
          action: 'load your subscription',
        );
      });
    } finally {
      if (mounted) setState(() => _loadingSubscription = false);
    }
  }

  Future<void> _refreshBillingAfterChange({String? message}) async {
    final summary = await widget.apiClient.getSubscriptionSummary();
    if (!mounted) return;
    setState(() {
      _subscription = summary;
      _message = message;
    });
    await widget.onBillingChanged();
  }

  Future<void> _updatePaymentMethod() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
      _message = null;
    });
    try {
      final setup = await widget.apiClient.createPaymentMethodSetup();
      await widget.stripePaymentHandler.preparePaymentSheet(
        setup,
        user: widget.user,
        primaryButtonLabel: 'Save payment method',
      );
      await widget.stripePaymentHandler.presentPaymentSheet();
      final paymentMethod = await widget.apiClient.confirmPaymentMethodSetup(
        setupIntentId: setup.setupIntentId,
      );
      if (!mounted) return;
      setState(() => _paymentMethod = paymentMethod);
      await widget.onBillingChanged();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = _isStripePaymentCanceled(error)
            ? null
            : beanFriendlyErrorMessage(
                error,
                action: 'update your payment method',
              );
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _subscribeToPlan(String plan) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
      _message = null;
    });
    try {
      final setup = await widget.apiClient.createMobileSubscriptionSetup(
        plan: plan,
      );
      await widget.stripePaymentHandler.preparePaymentSheet(
        setup,
        user: widget.user,
        primaryButtonLabel: 'Start ${_subscriptionPlanLabel(plan)} trial',
      );
      await widget.stripePaymentHandler.presentPaymentSheet();
      final result = await widget.apiClient.confirmMobileSubscription(
        plan: plan,
        setupIntentId: setup.setupIntentId,
      );
      if (!mounted) return;
      setState(() => _paymentMethod = result.paymentMethod ?? _paymentMethod);
      await _refreshBillingAfterChange(message: 'Subscription updated.');
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = _isStripePaymentCanceled(error)
            ? null
            : beanFriendlyErrorMessage(
                error,
                action: 'change your subscription',
              );
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _choosePlan() async {
    final plan = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) =>
          _PlanManagementSheet(currentPlan: widget.user.subscriptionTier),
    );
    if (plan != null) await _subscribeToPlan(plan);
  }

  Future<void> _cancelSubscription() async {
    if (_busy) return;
    final confirmed = await _confirmDestructiveAction(
      context,
      title: 'Cancel subscription?',
      message:
          'Your current access stays active until the end of the paid period or trial. Once the final active period has ended, your HeyBean data will be deleted and you will need to create a new account if you choose to keep using the app in the future.',
      confirmLabel: 'Cancel renewal',
    );
    if (!confirmed) return;
    setState(() {
      _busy = true;
      _error = null;
      _message = 'Canceling renewal...';
    });
    try {
      final summary = await widget.apiClient.cancelSubscription();
      if (!mounted) return;
      setState(() => _subscription = summary);
      await _refreshBillingAfterChange(
        message:
            'Subscription renewal canceled. Current access stays active through the end of this period.',
      );
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = beanFriendlyErrorMessage(
          error,
          action: 'cancel your subscription',
        );
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resumeSubscription() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
      _message = 'Restarting subscription...';
    });
    try {
      final summary = await widget.apiClient.resumeSubscription();
      if (!mounted) return;
      setState(() => _subscription = summary);
      await _refreshBillingAfterChange(
        message: 'Subscription restarted. Renewal is active again.',
      );
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = beanFriendlyErrorMessage(
          error,
          action: 'restart your subscription',
        );
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final subscription = _currentSubscription;
    final status = subscription.status;
    final canceled = subscription.cancelAtPeriodEnd;
    final statusLine = status == null || status.isEmpty
        ? 'Current plan: $_planLabel'
        : canceled
        ? 'Current plan: $_planLabel • renewal canceled'
        : 'Current plan: $_planLabel • ${status.replaceAll('_', ' ')}';
    final paymentLine = _loadingPaymentMethod
        ? 'Loading payment method...'
        : _paymentMethod?.displayLine ?? 'No saved payment method yet';
    final accessEndLine = _subscriptionAccessEndLine(subscription);
    final renewalLine = _subscriptionRenewalLine(subscription);

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: HeyBeanTheme.surface2,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: HeyBeanTheme.border),
      ),
      child: Column(
        key: const Key('billing-settings-card'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: HeyBeanTheme.accent.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(
                  Icons.credit_card_rounded,
                  color: HeyBeanTheme.accentStrong,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Billing',
                      style: TextStyle(
                        color: HeyBeanTheme.text,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      _loadingSubscription
                          ? 'Loading subscription...'
                          : statusLine,
                      style: const TextStyle(
                        color: HeyBeanTheme.muted,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      paymentLine,
                      key: const Key('settings-payment-method-summary'),
                      style: const TextStyle(
                        color: HeyBeanTheme.muted,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    if (accessEndLine != null) ...[
                      const SizedBox(height: 4),
                      Text(
                        accessEndLine,
                        key: const Key('settings-subscription-access-end'),
                        style: const TextStyle(
                          color: HeyBeanTheme.destructive,
                          fontSize: 12,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      const SizedBox(height: 4),
                      const Text(
                        'Once the final active period has ended, your HeyBean data will be deleted and you will need to create a new account to keep using the app.',
                        style: TextStyle(
                          color: HeyBeanTheme.muted,
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          height: 1.25,
                        ),
                      ),
                    ] else if (renewalLine != null) ...[
                      const SizedBox(height: 4),
                      Text(
                        renewalLine,
                        key: const Key('settings-subscription-renewal-summary'),
                        style: const TextStyle(
                          color: HeyBeanTheme.muted,
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ] else if (subscription.status == 'trialing') ...[
                      const SizedBox(height: 4),
                      const Text(
                        'Trial renewal uses the saved Stripe payment method.',
                        style: TextStyle(
                          color: HeyBeanTheme.muted,
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
          if (_error != null) ...[
            const SizedBox(height: 10),
            _InlinePlanLimitError(message: _error!),
          ],
          if (_message != null) ...[
            const SizedBox(height: 10),
            _SuccessNotice(message: _message!),
          ],
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              FilledButton.icon(
                key: const Key('settings-upgrade-plan-action'),
                onPressed: _busy ? null : _choosePlan,
                icon: _busy
                    ? const SizedBox.square(
                        dimension: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.swap_vert_rounded),
                label: const Text('Change plan'),
              ),
              OutlinedButton.icon(
                key: const Key('settings-update-payment-method-action'),
                onPressed: _busy ? null : _updatePaymentMethod,
                icon: const Icon(Icons.credit_card_rounded),
                label: const Text('Update payment'),
              ),
              OutlinedButton.icon(
                key: const Key('settings-cancel-subscription-action'),
                onPressed: _busy || !subscription.canCancel
                    ? null
                    : _cancelSubscription,
                icon: const Icon(Icons.event_busy_rounded),
                label: Text(canceled ? 'Renewal canceled' : 'Cancel renewal'),
              ),
              if (subscription.canResume)
                FilledButton.icon(
                  key: const Key('settings-resume-subscription-action'),
                  onPressed: _busy ? null : _resumeSubscription,
                  icon: _busy
                      ? const SizedBox.square(
                          dimension: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.restart_alt_rounded),
                  label: const Text('Restart subscription'),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

String? _subscriptionAccessEndLine(HermesSubscriptionSummary subscription) {
  if (!subscription.cancelAtPeriodEnd) return null;
  final accessEndsAt =
      subscription.accessEndsAt ?? subscription.currentPeriodEnd;
  final label = _formatBillingDate(accessEndsAt);
  return label == null
      ? 'Access ends at the end of this period'
      : 'Last day of access: $label';
}

String? _subscriptionRenewalLine(HermesSubscriptionSummary subscription) {
  if (subscription.cancelAtPeriodEnd) return null;
  if (subscription.trialEndsAt != null &&
      subscription.trialEndsAt!.isNotEmpty) {
    final label = _formatBillingDate(subscription.trialEndsAt);
    return label == null
        ? 'Trial renewal uses the saved Stripe payment method.'
        : 'Trial runs through $label.';
  }
  final currentPeriodEnd = subscription.currentPeriodEnd;
  if (currentPeriodEnd == null || currentPeriodEnd.isEmpty) return null;
  final label = _formatBillingDate(currentPeriodEnd);
  return label == null ? null : 'Renews around $label.';
}

String? _formatBillingDate(String? value) {
  final parsed = _parseCalendarEventDateTime(value);
  return parsed == null ? null : _formatCalendarDateLabel(parsed);
}

class _PlanManagementSheet extends StatelessWidget {
  const _PlanManagementSheet({required this.currentPlan});

  final String currentPlan;

  @override
  Widget build(BuildContext context) {
    final current = currentPlan.trim().toLowerCase();
    final plans = _signupPlanOptions.where((plan) => plan.startsCheckout);
    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'Change plan',
              style: Theme.of(
                context,
              ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 6),
            const Text(
              'Payment stays inside HeyBean with Stripe handling secure card entry and storage.',
              style: TextStyle(
                color: HeyBeanTheme.muted,
                fontWeight: FontWeight.w700,
                height: 1.35,
              ),
            ),
            const SizedBox(height: 14),
            for (final plan in plans) ...[
              _PlanManagementTile(
                plan: plan,
                selected: plan.key == current,
                onTap: plan.key == current
                    ? null
                    : () => Navigator.of(context).pop(plan.key),
              ),
              const SizedBox(height: 10),
            ],
          ],
        ),
      ),
    );
  }
}

class _PlanManagementTile extends StatelessWidget {
  const _PlanManagementTile({
    required this.plan,
    required this.selected,
    required this.onTap,
  });

  final _SignupPlanOption plan;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => InkWell(
    key: Key('settings-plan-${plan.key}'),
    borderRadius: BorderRadius.circular(18),
    onTap: onTap,
    child: Ink(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: selected
            ? HeyBeanTheme.accent.withValues(alpha: .10)
            : Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(
          color: selected
              ? HeyBeanTheme.accent.withValues(alpha: .32)
              : HeyBeanTheme.border,
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            selected
                ? Icons.check_circle_rounded
                : Icons.radio_button_unchecked_rounded,
            color: selected ? HeyBeanTheme.accentStrong : HeyBeanTheme.muted,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  plan.label,
                  style: const TextStyle(
                    color: HeyBeanTheme.text,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  '${plan.price}${plan.priceSuffix ?? ''} • ${plan.trialText}',
                  style: const TextStyle(
                    color: HeyBeanTheme.muted,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    ),
  );
}

class _ThemePreferencesCard extends StatefulWidget {
  const _ThemePreferencesCard({
    required this.selectedThemeKey,
    required this.commandCenterLabel,
    required this.onChanged,
    required this.onCommandCenterLabelChanged,
  });

  final String selectedThemeKey;
  final String commandCenterLabel;
  final Future<void> Function(String themeKey) onChanged;
  final Future<void> Function(String label) onCommandCenterLabelChanged;

  @override
  State<_ThemePreferencesCard> createState() => _ThemePreferencesCardState();
}

class _ThemePreferencesCardState extends State<_ThemePreferencesCard> {
  late String _selectedThemeKey;
  late final TextEditingController _commandCenterLabelController;
  bool _saving = false;
  bool _expanded = false;
  bool _savingLabel = false;

  @override
  void initState() {
    super.initState();
    _selectedThemeKey = heyBeanColorThemeForKey(widget.selectedThemeKey).key;
    _commandCenterLabelController = TextEditingController(
      text: widget.commandCenterLabel,
    );
  }

  @override
  void didUpdateWidget(covariant _ThemePreferencesCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!_saving) {
      _selectedThemeKey = heyBeanColorThemeForKey(widget.selectedThemeKey).key;
    }
    if (!_savingLabel &&
        oldWidget.commandCenterLabel != widget.commandCenterLabel &&
        _commandCenterLabelController.text != widget.commandCenterLabel) {
      _commandCenterLabelController.text = widget.commandCenterLabel;
    }
  }

  @override
  void dispose() {
    _commandCenterLabelController.dispose();
    super.dispose();
  }

  Future<void> _save(String themeKey) async {
    final normalizedThemeKey = heyBeanColorThemeForKey(themeKey).key;
    if (_saving || normalizedThemeKey == _selectedThemeKey) return;
    setState(() {
      _selectedThemeKey = normalizedThemeKey;
      _saving = true;
    });
    try {
      await widget.onChanged(normalizedThemeKey);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _saveCommandCenterLabel() async {
    if (_savingLabel) return;
    final label = _commandCenterLabelController.text.trim().isEmpty
        ? 'Command Center'
        : _commandCenterLabelController.text.trim();
    if (label == widget.commandCenterLabel) return;
    setState(() {
      _savingLabel = true;
      _commandCenterLabelController.text = label;
    });
    try {
      await widget.onCommandCenterLabelChanged(label);
    } finally {
      if (mounted) setState(() => _savingLabel = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final selectedTheme = heyBeanColorThemeForKey(_selectedThemeKey);
    return Container(
      key: const Key('theme-preferences-card'),
      margin: const EdgeInsets.only(top: 10),
      decoration: BoxDecoration(
        color: HeyBeanTheme.surface2,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: HeyBeanTheme.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Material(
            color: Colors.transparent,
            child: InkWell(
              key: const Key('theme-preferences-toggle'),
              borderRadius: BorderRadius.circular(20),
              onTap: () => setState(() => _expanded = !_expanded),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Row(
                  children: [
                    Icon(
                      Icons.palette_outlined,
                      color: HeyBeanTheme.accentStrong,
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Appearance',
                            style: TextStyle(fontWeight: FontWeight.w800),
                          ),
                          const SizedBox(height: 3),
                          Text(
                            '${selectedTheme.label} accent · ${widget.commandCenterLabel}',
                            style: const TextStyle(
                              color: HeyBeanTheme.muted,
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ],
                      ),
                    ),
                    Container(
                      width: 22,
                      height: 22,
                      decoration: BoxDecoration(
                        color: selectedTheme.accent,
                        shape: BoxShape.circle,
                        border: Border.all(color: Colors.white, width: 2),
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withValues(alpha: .14),
                            blurRadius: 8,
                            offset: const Offset(0, 3),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 10),
                    if (_saving)
                      SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: HeyBeanTheme.accent,
                        ),
                      )
                    else
                      Icon(
                        _expanded
                            ? Icons.keyboard_arrow_up_rounded
                            : Icons.keyboard_arrow_down_rounded,
                        color: HeyBeanTheme.muted,
                      ),
                  ],
                ),
              ),
            ),
          ),
          if (_expanded)
            Padding(
              key: const Key('theme-preferences-options'),
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Divider(height: 1, color: HeyBeanTheme.border),
                  const SizedBox(height: 12),
                  Text(
                    'Choose the accent color used across HeyBean.',
                    style: TextStyle(color: HeyBeanTheme.muted, fontSize: 12),
                  ),
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final theme in heyBeanColorThemes)
                        _ThemeSwatchButton(
                          theme: theme,
                          selected: theme.key == _selectedThemeKey,
                          disabled: _saving,
                          onTap: () => _save(theme.key),
                        ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    key: const Key('command-center-label-field'),
                    controller: _commandCenterLabelController,
                    enabled: !_savingLabel,
                    textInputAction: TextInputAction.done,
                    onSubmitted: (_) => unawaited(_saveCommandCenterLabel()),
                    decoration: const InputDecoration(
                      labelText: 'Command Center name',
                      hintText: 'Command Center',
                    ),
                  ),
                  const SizedBox(height: 10),
                  Align(
                    alignment: Alignment.centerRight,
                    child: FilledButton(
                      key: const Key('command-center-label-save'),
                      onPressed: _savingLabel
                          ? null
                          : () => unawaited(_saveCommandCenterLabel()),
                      child: _savingLabel
                          ? const SizedBox.square(
                              dimension: 17,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Text('Save'),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _ThemeSwatchButton extends StatelessWidget {
  const _ThemeSwatchButton({
    required this.theme,
    required this.selected,
    required this.disabled,
    required this.onTap,
  });

  final HeyBeanColorTheme theme;
  final bool selected;
  final bool disabled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
    button: true,
    selected: selected,
    label: '${theme.label} theme',
    child: InkWell(
      borderRadius: BorderRadius.circular(999),
      onTap: disabled ? null : onTap,
      child: Container(
        width: 112,
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
        decoration: BoxDecoration(
          color: selected
              ? theme.accent.withValues(alpha: .11)
              : Colors.white.withValues(alpha: .72),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: selected
                ? theme.accentStrong.withValues(alpha: .46)
                : HeyBeanTheme.border,
          ),
          boxShadow: selected
              ? [
                  BoxShadow(
                    color: theme.accent.withValues(alpha: .10),
                    blurRadius: 12,
                    offset: const Offset(0, 6),
                  ),
                ]
              : null,
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 22,
              height: 22,
              decoration: BoxDecoration(
                color: theme.accent,
                shape: BoxShape.circle,
                border: Border.all(color: Colors.white, width: 2),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: .14),
                    blurRadius: 8,
                    offset: const Offset(0, 3),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                theme.label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontWeight: FontWeight.w800),
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _NotificationPreferencesCard extends StatefulWidget {
  const _NotificationPreferencesCard({
    required this.preferences,
    required this.onChanged,
  });

  final HermesNotificationPreferences preferences;
  final Future<void> Function(HermesNotificationPreferences preferences)
  onChanged;

  @override
  State<_NotificationPreferencesCard> createState() =>
      _NotificationPreferencesCardState();
}

class _NotificationPreferencesCardState
    extends State<_NotificationPreferencesCard> {
  late HermesNotificationPreferences _preferences;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _preferences = widget.preferences;
  }

  @override
  void didUpdateWidget(covariant _NotificationPreferencesCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!_saving) _preferences = widget.preferences;
  }

  Future<void> _save(HermesNotificationPreferences preferences) async {
    setState(() {
      _preferences = preferences;
      _saving = true;
    });
    try {
      await widget.onChanged(preferences);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => Container(
    key: const Key('notification-preferences-card'),
    margin: const EdgeInsets.only(top: 10),
    decoration: BoxDecoration(
      color: HeyBeanTheme.accent.withValues(alpha: .06),
      borderRadius: BorderRadius.circular(20),
      border: Border.all(color: HeyBeanTheme.accent.withValues(alpha: .15)),
    ),
    child: Column(
      children: [
        Container(
          margin: const EdgeInsets.only(bottom: 4),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: HeyBeanTheme.surface2,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: HeyBeanTheme.border),
          ),
          child: Row(
            children: [
              Icon(
                Icons.notifications_active_outlined,
                color: HeyBeanTheme.accentStrong,
              ),
              const SizedBox(width: 12),
              const Expanded(
                child: Text(
                  'Notification preferences',
                  style: TextStyle(fontWeight: FontWeight.w800),
                ),
              ),
            ],
          ),
        ),
        SwitchListTile.adaptive(
          key: const Key('reminder-push-preference'),
          value: _preferences.reminderPush,
          onChanged: _saving
              ? null
              : (value) => _save(_preferences.copyWith(reminderPush: value)),
          title: const Text('Reminder push notifications'),
          secondary: const Icon(Icons.phone_iphone_rounded),
        ),
        SwitchListTile.adaptive(
          key: const Key('reminder-email-preference'),
          value: _preferences.reminderEmail,
          onChanged: _saving
              ? null
              : (value) => _save(_preferences.copyWith(reminderEmail: value)),
          title: const Text('Reminder emails'),
          secondary: const Icon(Icons.email_outlined),
        ),
      ],
    ),
  );
}

class _WorkspacesSettingsCard extends StatefulWidget {
  const _WorkspacesSettingsCard({
    required this.apiClient,
    required this.launchExternalUrl,
    required this.user,
    required this.onChanged,
    this.googleCalendarStatus,
  });

  final HermesApiClient apiClient;
  final ExternalUrlLauncher launchExternalUrl;
  final HermesUser user;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final Future<void> Function() onChanged;

  @override
  State<_WorkspacesSettingsCard> createState() =>
      _WorkspacesSettingsCardState();
}

class _WorkspacesSettingsCardState extends State<_WorkspacesSettingsCard> {
  late Future<List<HermesWorkspace>> _workspacesFuture;
  String? _message;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _workspacesFuture = _loadWorkspaces();
  }

  Future<List<HermesWorkspace>> _loadWorkspaces() async {
    try {
      final workspaces = await widget.apiClient.listWorkspaces();
      if (workspaces.isNotEmpty) return workspaces;
    } catch (_) {}
    if (widget.user.workspaces.isNotEmpty) return widget.user.workspaces;
    final personal =
        widget.user.personalWorkspace ??
        HermesWorkspace(
          id: (widget.user.defaultWorkspaceId ?? 0).toString(),
          name: 'Personal',
          type: 'personal',
          role: 'owner',
          active: true,
          isDefault: true,
        );
    return [personal];
  }

  void _reload() {
    setState(() {
      _workspacesFuture = _loadWorkspaces();
    });
  }

  Future<T?> _run<T>(Future<T> Function() action, String success) async {
    setState(() {
      _busy = true;
      _message = null;
    });
    try {
      final result = await action();
      if (!mounted) return result;
      setState(() => _message = success);
      _reload();
      await widget.onChanged();
      return result;
    } catch (error) {
      if (mounted) {
        setState(
          () => _message = beanFriendlyErrorMessage(
            error,
            action: 'finish that workspace action',
          ),
        );
      }
      return null;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _createHousehold() async {
    final name = await showDialog<String>(
      context: context,
      builder: (context) => const _WorkspaceTextInputDialog(
        title: 'Create household',
        labelText: 'Household name',
        fieldKey: Key('workspace-create-name-field'),
        submitKey: Key('workspace-create-save'),
        submitLabel: 'Create',
      ),
    );
    if (name == null || name.trim().isEmpty) return;
    await _run(
      () => widget.apiClient.createWorkspace(name: name.trim()),
      'Household created.',
    );
  }

  Future<void> _inviteMember(HermesWorkspace workspace) async {
    final workspaceId = workspace.numericId;
    if (workspaceId == null) return;
    final email = await showDialog<String>(
      context: context,
      builder: (context) => _WorkspaceTextInputDialog(
        title: 'Invite to ${workspace.name}',
        labelText: 'Email',
        fieldKey: Key('workspace-invite-email-${workspace.id}'),
        submitKey: Key('workspace-invite-save-${workspace.id}'),
        submitLabel: 'Invite',
        keyboardType: TextInputType.emailAddress,
      ),
    );
    if (email == null || email.trim().isEmpty) return;
    final membership = await _run(
      () => widget.apiClient.inviteWorkspaceMember(
        workspaceId,
        email: email.trim(),
      ),
      'Invitation sent.',
    );
    if (!mounted || membership == null) return;
    await _showInvitationLinkDialog(membership);
  }

  Future<void> _showInvitationLinkDialog(
    HermesWorkspaceMembership membership,
  ) async {
    final link = membership.invitationAcceptUrl;
    if (link == null || link.trim().isEmpty) return;
    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Invitation sent'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Share this invite link if the email does not arrive.'),
            const SizedBox(height: 12),
            SelectableText(
              link,
              key: const Key('workspace-invite-share-link'),
              style: const TextStyle(fontSize: 13),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Done'),
          ),
          FilledButton.icon(
            key: const Key('workspace-invite-copy-link'),
            onPressed: () async {
              await Clipboard.setData(ClipboardData(text: link));
              if (context.mounted) Navigator.of(context).pop();
              if (mounted) {
                setState(() => _message = 'Invitation link copied.');
              }
            },
            icon: const Icon(Icons.copy_rounded),
            label: const Text('Copy link'),
          ),
        ],
      ),
    );
  }

  Future<void> _acceptInvitation() async {
    final input = await showDialog<String>(
      context: context,
      builder: (context) => const _WorkspaceTextInputDialog(
        title: 'Accept workspace invitation',
        labelText: 'Invitation token or link',
        fieldKey: Key('workspace-accept-invitation-token'),
        submitKey: Key('workspace-accept-invitation-save'),
        submitLabel: 'Accept',
        keyboardType: TextInputType.url,
      ),
    );
    final token = _workspaceInvitationTokenFromInput(input ?? '');
    if (token == null) return;
    await _run(
      () => widget.apiClient.acceptWorkspaceInvitation(token),
      'Invitation accepted.',
    );
  }

  String? _workspaceInvitationTokenFromInput(String input) {
    final trimmed = input.trim();
    if (trimmed.isEmpty) return null;
    final uri = Uri.tryParse(trimmed);
    final segments = uri?.pathSegments ?? const <String>[];
    final invitationIndex = segments.indexOf('workspace-invitations');
    if (invitationIndex >= 0 && invitationIndex + 1 < segments.length) {
      final token = segments[invitationIndex + 1].trim();
      if (token.isNotEmpty) return token;
    }
    return trimmed;
  }

  Future<void> _renameWorkspace(HermesWorkspace workspace) async {
    final workspaceId = workspace.numericId;
    if (workspaceId == null || workspace.isPersonal) return;
    final name = await showDialog<String>(
      context: context,
      builder: (context) => _WorkspaceTextInputDialog(
        title: 'Rename household',
        labelText: 'Household name',
        fieldKey: Key('workspace-rename-field-${workspace.id}'),
        submitKey: Key('workspace-rename-save-${workspace.id}'),
        submitLabel: 'Save',
        initialValue: workspace.name,
      ),
    );
    if (name == null || name.trim().isEmpty) return;
    await _run(
      () => widget.apiClient.updateWorkspace(workspaceId, name: name.trim()),
      'Workspace renamed.',
    );
  }

  Future<void> _syncAllFromPersonal(
    HermesWorkspace target,
    List<HermesWorkspace> workspaces,
  ) async {
    final source =
        widget.user.personalWorkspace ??
        workspaces.firstWhere(
          (workspace) => workspace.isPersonal,
          orElse: () => workspaces.first,
        );
    if (source.numericId == null || target.numericId == null) return;
    if (source.id == target.id) return;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Sync all from my personal workspace'),
        content: Text(
          'Copy all current Personal tasks, reminders, and events to ${target.name}. This is a one-time sync and will not automatically sync future items.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: Key('workspace-sync-personal-run-${target.id}'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Sync'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;

    final result = await _run(
      () => widget.apiClient.syncWorkspaceAll(
        source.numericId!,
        targetWorkspaceId: target.numericId!,
        resourceTypes: const ['tasks', 'reminders', 'calendar_events'],
      ),
      'Personal workspace sync completed.',
    );
    if (result != null && mounted) {
      setState(() {
        _message =
            'Copied ${result.tasks} tasks, ${result.reminders} reminders, and ${result.calendarEvents} events from Personal to ${target.name}.';
      });
    }
  }

  Future<void> _toggleGoogleCalendar(
    HermesWorkspace workspace,
    String calendarId,
    bool selected,
  ) async {
    final workspaceId = workspace.numericId;
    if (workspaceId == null) return;
    final current = workspace.googleCalendarMappings
        .map((mapping) => mapping['google_calendar_id']?.toString())
        .whereType<String>()
        .toSet();
    if (selected) {
      current.add(calendarId);
    } else {
      current.remove(calendarId);
    }
    await _run(
      () => widget.apiClient.updateWorkspaceGoogleCalendars(
        workspaceId,
        googleCalendarIds: current.toList(),
        defaultExportCalendarId: current.isEmpty ? null : current.first,
      ),
      'Workspace calendar choices saved.',
    );
  }

  String _googleCalendarAccessLabel(
    GoogleCalendarInfo calendar,
    HermesWorkspace workspace,
  ) {
    final defaultForWorkspace = workspace.googleCalendarMappings.any(
      (mapping) =>
          mapping['google_calendar_id']?.toString() == calendar.id &&
          mapping['is_default_export'] == true,
    );
    final access = calendar.canWrite ? 'Can add local events' : 'Read only';
    return defaultForWorkspace
        ? '$access · Default for new local events'
        : access;
  }

  Iterable<HermesWorkspaceMembership> _visibleMemberships(
    HermesWorkspace workspace,
  ) => workspace.memberships.where(
    (membership) =>
        membership.status != 'removed' && membership.status != 'left',
  );

  String _membershipTitle(HermesWorkspaceMembership membership) {
    final name = membership.user?.name.trim();
    if (name != null && name.isNotEmpty) return name;
    final email = membership.invitedEmail?.trim();
    if (email != null && email.isNotEmpty) return email;
    return 'Invited member';
  }

  String _membershipSubtitle(HermesWorkspaceMembership membership) {
    final email = membership.user?.email.trim().isNotEmpty == true
        ? membership.user!.email.trim()
        : membership.invitedEmail?.trim();
    if (membership.status == 'invited' || membership.status == 'pending') {
      return email == null || email.isEmpty
          ? 'Invite pending'
          : 'Invite pending - $email';
    }
    return email == null || email.isEmpty ? membership.role : email;
  }

  Future<void> _copyInviteLinkForMembership(
    HermesWorkspace workspace,
    HermesWorkspaceMembership membership,
  ) async {
    final workspaceId = workspace.numericId;
    final email = membership.invitedEmail?.trim();
    if (workspaceId == null || email == null || email.isEmpty) return;

    final refreshedMembership = await _run(
      () => widget.apiClient.inviteWorkspaceMember(workspaceId, email: email),
      'Invite link ready.',
    );
    final link = refreshedMembership?.invitationAcceptUrl;
    if (link == null || link.trim().isEmpty) return;
    await Clipboard.setData(ClipboardData(text: link));
    if (mounted) setState(() => _message = 'Invitation link copied.');
  }

  Widget _membershipStatusIcon(HermesWorkspaceMembership membership) {
    if (membership.status == 'active') {
      return Icon(
        Icons.check_circle_rounded,
        color: HeyBeanTheme.accent,
        size: 18,
      );
    }
    return const Icon(
      Icons.schedule_send_rounded,
      color: HeyBeanTheme.muted,
      size: 18,
    );
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<List<HermesWorkspace>>(
    future: _workspacesFuture,
    builder: (context, snapshot) {
      final workspaces = snapshot.data ?? widget.user.workspaces;
      final activeId =
          widget.user.activeWorkspace?.id ??
          widget.user.defaultWorkspaceId?.toString();
      final googleCalendars =
          widget.googleCalendarStatus?.calendars ??
          const <GoogleCalendarInfo>[];
      return Container(
        key: const Key('workspaces-settings'),
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: HeyBeanTheme.surface2,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: HeyBeanTheme.border),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  Icons.home_work_outlined,
                  color: HeyBeanTheme.accentStrong,
                ),
                const SizedBox(width: 12),
                const Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Workspaces',
                        style: TextStyle(fontWeight: FontWeight.w800),
                      ),
                      Text(
                        'Personal and household spaces with their own Bean, calendar, tasks, reminders, and settings.',
                        style: TextStyle(color: HeyBeanTheme.muted),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                const _InfoIconButton(
                  key: Key('workspaces-info'),
                  title: 'Workspaces',
                  bullets: [
                    'Personal is your private space. Household workspaces are shared spaces for family plans.',
                    "Switch workspaces to see that space's Bean, calendar, tasks, reminders, and settings.",
                    'Workspace calendar choices control which connected calendars appear in that workspace.',
                  ],
                ),
              ],
            ),
            if (_message != null) ...[
              const SizedBox(height: 8),
              _InlinePlanLimitError(
                message: _message!,
                launchExternalUrl: widget.launchExternalUrl,
              ),
            ],
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final workspace in workspaces)
                  ChoiceChip(
                    key: Key('workspace-switch-${workspace.id}'),
                    label: Text(
                      workspace.isPersonal ? 'Personal' : workspace.name,
                    ),
                    selected: workspace.id == activeId || workspace.active,
                    onSelected: _busy || workspace.numericId == null
                        ? null
                        : (_) => _run(
                            () => widget.apiClient.setDefaultWorkspace(
                              workspace.numericId!,
                            ),
                            'Switched to ${workspace.name}.',
                          ),
                  ),
              ],
            ),
            const SizedBox(height: 10),
            for (final workspace in workspaces)
              Container(
                key: Key('workspace-row-${workspace.id}'),
                margin: const EdgeInsets.only(top: 8),
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: HeyBeanTheme.surface,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: HeyBeanTheme.border),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            workspace.isPersonal ? 'Personal' : workspace.name,
                            style: const TextStyle(fontWeight: FontWeight.w800),
                          ),
                        ),
                        Text(
                          workspace.role,
                          key: Key('workspace-role-${workspace.id}'),
                          style: const TextStyle(color: HeyBeanTheme.muted),
                        ),
                        if (!workspace.isPersonal &&
                            workspace.numericId != null) ...[
                          const SizedBox(width: 8),
                          TextButton(
                            key: Key('workspace-leave-${workspace.id}'),
                            onPressed: _busy
                                ? null
                                : () => _run(
                                    () => widget.apiClient.leaveWorkspace(
                                      workspace.numericId!,
                                    ),
                                    'Left ${workspace.name}.',
                                  ),
                            child: const Text('Leave'),
                          ),
                        ],
                      ],
                    ),
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        if (!workspace.isPersonal && workspace.canManageMembers)
                          TextButton(
                            key: Key('workspace-rename-${workspace.id}'),
                            onPressed: _busy
                                ? null
                                : () => _renameWorkspace(workspace),
                            child: const Text('Rename'),
                          ),
                        if (workspace.canManageMembers && !workspace.isPersonal)
                          TextButton(
                            key: Key('workspace-invite-${workspace.id}'),
                            onPressed: _busy
                                ? null
                                : () => _inviteMember(workspace),
                            child: const Text('Invite'),
                          ),
                      ],
                    ),
                    for (final membership in _visibleMemberships(workspace))
                      ListTile(
                        key: Key(
                          'workspace-member-${workspace.id}-${membership.id}',
                        ),
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        leading: _membershipStatusIcon(membership),
                        title: Text(_membershipTitle(membership)),
                        subtitle: Text(_membershipSubtitle(membership)),
                        trailing:
                            workspace.canManageMembers &&
                                !workspace.isPersonal &&
                                workspace.numericId != null
                            ? PopupMenuButton<String>(
                                key: Key(
                                  'workspace-member-actions-${workspace.id}-${membership.id}',
                                ),
                                onSelected: (value) {
                                  if (value == 'owner') {
                                    _run(
                                      () => widget.apiClient
                                          .updateWorkspaceMember(
                                            workspace.numericId!,
                                            membership.id,
                                            role: 'owner',
                                          ),
                                      'Member is now an owner.',
                                    );
                                  } else if (value == 'copy_link') {
                                    _copyInviteLinkForMembership(
                                      workspace,
                                      membership,
                                    );
                                  } else if (value == 'remove') {
                                    _run(
                                      () => widget.apiClient
                                          .removeWorkspaceMember(
                                            workspace.numericId!,
                                            membership.id,
                                          ),
                                      'Member removed.',
                                    );
                                  }
                                },
                                itemBuilder: (context) => [
                                  if (membership.status == 'active')
                                    const PopupMenuItem(
                                      value: 'owner',
                                      child: Text('Make owner'),
                                    ),
                                  if (membership.status == 'invited' ||
                                      membership.status == 'pending')
                                    const PopupMenuItem(
                                      value: 'copy_link',
                                      child: Text('Copy invite link'),
                                    ),
                                  const PopupMenuItem(
                                    value: 'remove',
                                    child: Text('Remove'),
                                  ),
                                ],
                              )
                            : null,
                      ),
                    if (!workspace.isPersonal &&
                        workspace.numericId != null) ...[
                      const SizedBox(height: 6),
                      OutlinedButton.icon(
                        key: Key(
                          'workspace-sync-personal-action-${workspace.id}',
                        ),
                        onPressed: _busy
                            ? null
                            : () => _syncAllFromPersonal(workspace, workspaces),
                        icon: const Icon(Icons.refresh_rounded),
                        label: const Text('Sync all from personal'),
                      ),
                    ],
                    if (googleCalendars.isNotEmpty) ...[
                      const SizedBox(height: 6),
                      const Text(
                        'Connected calendars for this workspace',
                        style: TextStyle(fontWeight: FontWeight.w700),
                      ),
                      for (final calendar in googleCalendars)
                        CheckboxListTile(
                          key: Key(
                            'workspace-google-calendar-${workspace.id}-${calendar.id}',
                          ),
                          dense: true,
                          contentPadding: EdgeInsets.zero,
                          value: workspace.googleCalendarMappings.any(
                            (mapping) =>
                                mapping['google_calendar_id']?.toString() ==
                                calendar.id,
                          ),
                          onChanged: _busy || workspace.numericId == null
                              ? null
                              : (value) => _toggleGoogleCalendar(
                                  workspace,
                                  calendar.id,
                                  value ?? false,
                                ),
                          title: Text(calendar.summary),
                          subtitle: Text(
                            _googleCalendarAccessLabel(calendar, workspace),
                            key: Key(
                              'workspace-google-calendar-access-${workspace.id}-${calendar.id}',
                            ),
                            style: const TextStyle(
                              color: HeyBeanTheme.muted,
                              fontSize: 12,
                            ),
                          ),
                        ),
                    ],
                  ],
                ),
              ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _ThemedPlusButton(
                  key: const Key('workspace-create-household-action'),
                  onPressed: _busy ? null : _createHousehold,
                  tooltip: 'Add household',
                ),
                OutlinedButton.icon(
                  key: const Key('workspace-accept-invitation-action'),
                  onPressed: _busy ? null : _acceptInvitation,
                  icon: const Icon(Icons.mark_email_read_rounded),
                  label: const Text('Accept invitation'),
                ),
              ],
            ),
          ],
        ),
      );
    },
  );
}

class _WorkspaceTextInputDialog extends StatefulWidget {
  const _WorkspaceTextInputDialog({
    required this.title,
    required this.labelText,
    required this.fieldKey,
    required this.submitKey,
    required this.submitLabel,
    this.initialValue = '',
    this.keyboardType,
  });

  final String title;
  final String labelText;
  final Key fieldKey;
  final Key submitKey;
  final String submitLabel;
  final String initialValue;
  final TextInputType? keyboardType;

  @override
  State<_WorkspaceTextInputDialog> createState() =>
      _WorkspaceTextInputDialogState();
}

class _WorkspaceTextInputDialogState extends State<_WorkspaceTextInputDialog> {
  late final TextEditingController _controller;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: widget.initialValue);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(widget.title),
    content: TextField(
      key: widget.fieldKey,
      controller: _controller,
      autofocus: true,
      keyboardType: widget.keyboardType,
      decoration: InputDecoration(labelText: widget.labelText),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(),
        child: const Text('Cancel'),
      ),
      FilledButton(
        key: widget.submitKey,
        onPressed: () => Navigator.of(context).pop(_controller.text.trim()),
        child: Text(widget.submitLabel),
      ),
    ],
  );
}

class _CalendarPreferencesCard extends StatelessWidget {
  const _CalendarPreferencesCard({
    required this.startHour,
    required this.endHour,
    required this.onStartHourChanged,
    required this.onEndHourChanged,
  });

  final int startHour;
  final int endHour;
  final ValueChanged<int> onStartHourChanged;
  final ValueChanged<int> onEndHourChanged;

  @override
  Widget build(BuildContext context) {
    final startOptions = [for (var hour = 0; hour <= 22; hour++) hour];
    final endOptions = [
      for (var hour = startHour + 1; hour <= 23; hour++) hour,
    ];

    return Container(
      key: const Key('calendar-preferences-settings'),
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: HeyBeanTheme.surface2,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: HeyBeanTheme.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                Icons.calendar_view_day_rounded,
                color: HeyBeanTheme.accentStrong,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Calendar preferences',
                      style: TextStyle(fontWeight: FontWeight.w800),
                    ),
                    Text(
                      'Day view visible hours: ${_hourLabel(startHour)} – ${_hourLabel(endHour)}',
                      style: const TextStyle(color: HeyBeanTheme.muted),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              const _InfoIconButton(
                key: Key('calendar-preferences-info'),
                title: 'Calendar preferences',
                bullets: [
                  'Start and end hours only change the visible day timeline window.',
                  'Events outside this range are still saved and can show when you expand the day window.',
                  'Use this to keep the daily view focused on the hours you actually plan around.',
                ],
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: DropdownButtonFormField<int>(
                  key: const Key('calendar-start-hour-setting'),
                  initialValue: startHour,
                  decoration: const InputDecoration(
                    labelText: 'Start hour',
                    isDense: true,
                  ),
                  items: [
                    for (final hour in startOptions)
                      DropdownMenuItem(
                        value: hour,
                        child: Text(_hourLabel(hour)),
                      ),
                  ],
                  onChanged: (value) {
                    if (value != null) onStartHourChanged(value);
                  },
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: DropdownButtonFormField<int>(
                  key: const Key('calendar-end-hour-setting'),
                  initialValue: endHour,
                  decoration: const InputDecoration(
                    labelText: 'End hour',
                    isDense: true,
                  ),
                  items: [
                    for (final hour in endOptions)
                      DropdownMenuItem(
                        value: hour,
                        child: Text(_hourLabel(hour)),
                      ),
                  ],
                  onChanged: (value) {
                    if (value != null) onEndHourChanged(value);
                  },
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _GoogleCalendarSyncCard extends StatefulWidget {
  const _GoogleCalendarSyncCard({
    required this.apiClient,
    required this.launchExternalUrl,
  });

  final HermesApiClient apiClient;
  final ExternalUrlLauncher launchExternalUrl;

  @override
  State<_GoogleCalendarSyncCard> createState() =>
      _GoogleCalendarSyncCardState();
}

class _GoogleCalendarSyncCardState extends State<_GoogleCalendarSyncCard>
    with WidgetsBindingObserver {
  late Future<GoogleCalendarSyncStatus> _statusFuture;
  String? _message;
  String? _googleAuthUrl;
  bool _busy = false;
  bool _waitingForGoogleReturn = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _statusFuture = widget.apiClient.googleCalendarStatus();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && _waitingForGoogleReturn) {
      _syncAfterGoogleReturn();
    }
  }

  void _reload() {
    setState(() {
      _statusFuture = widget.apiClient.googleCalendarStatus();
    });
  }

  Future<void> _connect() async {
    setState(() {
      _busy = true;
      _message = null;
    });
    try {
      final rawUrl = await widget.apiClient.googleCalendarAuthUrl();
      final url = Uri.parse(rawUrl);
      _googleAuthUrl = rawUrl;
      var launched = false;
      try {
        launched = await widget.launchExternalUrl(url);
      } catch (_) {
        launched = false;
      }
      if (!launched) {
        launched = await _launchExternalUrlWithNativeFallback(url);
      }
      if (!mounted) return;
      setState(() {
        _waitingForGoogleReturn = launched;
        _message = launched
            ? 'Finish approving calendar access in the browser. If a QR prompt appears in the simulator, tap Copy auth link, finish it in your browser, then tap Check connection here.'
            : 'Could not open calendar authorization automatically. Tap Copy auth link, finish it in any browser, then tap Check connection here.';
      });
      _reload();
    } catch (error) {
      if (mounted) {
        setState(
          () => _message = beanFriendlyErrorMessage(
            error,
            action: 'start calendar connection',
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _syncAfterGoogleReturn() async {
    setState(() {
      _busy = true;
      _message = 'Checking calendar connection…';
    });
    try {
      final status = await widget.apiClient.googleCalendarStatus();
      if (!mounted) return;
      if (!status.connected) {
        setState(() {
          _statusFuture = Future.value(status);
          _message =
              'Calendar sync is not connected yet. Finish approval in the browser, then return to HeyBean.';
        });
        return;
      }
      final result = await widget.apiClient.syncGoogleCalendar();
      if (!mounted) return;
      setState(() {
        _waitingForGoogleReturn = false;
        _googleAuthUrl = null;
        _message =
            'Calendar sync connected and synced ${result.imported} event${result.imported == 1 ? '' : 's'}${result.deleted > 0 ? ', removed ${result.deleted}' : ''}.';
        _statusFuture = Future.value(result.status);
      });
    } catch (error) {
      if (mounted) {
        setState(
          () => _message = beanFriendlyErrorMessage(
            error,
            action: 'sync calendar after connecting',
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _copyGoogleAuthLink() async {
    final rawUrl = _googleAuthUrl;
    if (rawUrl == null) return;
    await Clipboard.setData(ClipboardData(text: rawUrl));
    if (!mounted) return;
    setState(() {
      _message =
          'Copied calendar authorization link. Open it in your browser, approve calendar access, then tap Check connection here.';
    });
  }

  Future<void> _checkGoogleConnection() => _syncAfterGoogleReturn();

  Future<void> _sync() async {
    setState(() {
      _busy = true;
      _message = null;
    });
    try {
      final result = await widget.apiClient.syncGoogleCalendar();
      if (!mounted) return;
      setState(() {
        _message =
            'Synced ${result.imported} connected event${result.imported == 1 ? '' : 's'}${result.deleted > 0 ? ', removed ${result.deleted}' : ''}.';
        _googleAuthUrl = null;
        _statusFuture = Future.value(result.status);
      });
    } catch (error) {
      if (mounted) {
        setState(
          () => _message = beanFriendlyErrorMessage(
            error,
            action: 'sync calendar',
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _disconnect() async {
    setState(() {
      _busy = true;
      _message = null;
    });
    try {
      final status = await widget.apiClient.disconnectGoogleCalendar();
      if (!mounted) return;
      setState(() {
        _message = 'Calendar sync disconnected.';
        _statusFuture = Future.value(status);
      });
    } catch (error) {
      if (mounted) {
        setState(
          () => _message = beanFriendlyErrorMessage(
            error,
            action: 'disconnect calendar sync',
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<GoogleCalendarSyncStatus>(
    future: _statusFuture,
    builder: (context, snapshot) {
      final status = snapshot.data;
      final connected = status?.connected ?? false;
      return Container(
        key: const Key('google-calendar-sync-settings'),
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: HeyBeanTheme.surface2,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: HeyBeanTheme.border),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.sync_rounded, color: HeyBeanTheme.accentStrong),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Calendar sync',
                        style: TextStyle(fontWeight: FontWeight.w800),
                      ),
                      Text(
                        connected
                            ? 'Connected${status?.lastSyncedAt == null ? '' : ' · last sync ${_formatCalendarEventDateTime(status?.lastSyncedAt)}'}'
                            : 'Connect your calendar to import events into HeyBean.',
                        style: const TextStyle(color: HeyBeanTheme.muted),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                const _InfoIconButton(
                  key: Key('google-calendar-sync-info'),
                  title: 'Calendar sync',
                  bullets: [
                    'Connecting imports your calendar events so Bean can plan around them.',
                    'Writable calendars can also receive local Bean events when you choose them on an item.',
                    'Disconnecting stops future sync. It does not delete your external account or calendar.',
                  ],
                ),
              ],
            ),
            if (status?.lastError != null && status!.lastError!.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(
                status.lastError!,
                style: const TextStyle(color: Colors.redAccent),
              ),
            ],
            if (_message != null) ...[
              const SizedBox(height: 8),
              _InlinePlanLimitError(message: _message!),
            ],
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                OutlinedButton.icon(
                  key: const Key('google-calendar-connect-action'),
                  onPressed: _busy ? null : _connect,
                  icon: const Icon(Icons.login_rounded),
                  label: Text(connected ? 'Reconnect' : 'Connect calendar'),
                ),
                if (_googleAuthUrl != null) ...[
                  OutlinedButton.icon(
                    key: const Key('google-calendar-copy-link-action'),
                    onPressed: _busy ? null : _copyGoogleAuthLink,
                    icon: const Icon(Icons.copy_rounded),
                    label: const Text('Copy auth link'),
                  ),
                  OutlinedButton.icon(
                    key: const Key('google-calendar-check-connection-action'),
                    onPressed: _busy ? null : _checkGoogleConnection,
                    icon: const Icon(Icons.verified_rounded),
                    label: const Text('Check connection'),
                  ),
                ],
                FilledButton.icon(
                  key: const Key('google-calendar-sync-action'),
                  onPressed: _busy || !connected ? null : _sync,
                  icon: _busy
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.refresh_rounded),
                  label: const Text('Sync now'),
                ),
                if (connected)
                  TextButton(
                    key: const Key('google-calendar-disconnect-action'),
                    onPressed: _busy ? null : _disconnect,
                    child: const Text('Disconnect'),
                  ),
              ],
            ),
          ],
        ),
      );
    },
  );
}

class _TaskItemTile extends StatefulWidget {
  const _TaskItemTile({
    required this.task,
    required this.subtitle,
    required this.onCompleted,
    this.pending = false,
    this.onTap,
    this.subtasks = const [],
    this.onSubtaskCompleted,
    this.onSubtaskTap,
    this.pendingTaskIds = const {},
    this.onAddSubtask,
  });

  final HermesTask task;
  final String subtitle;
  final Future<void> Function(HermesTask task) onCompleted;
  final bool pending;
  final VoidCallback? onTap;
  final List<HermesTask> subtasks;
  final Future<void> Function(HermesTask task)? onSubtaskCompleted;
  final ValueChanged<HermesTask>? onSubtaskTap;
  final Set<int> pendingTaskIds;
  final VoidCallback? onAddSubtask;

  @override
  State<_TaskItemTile> createState() => _TaskItemTileState();
}

class _TaskItemTileState extends State<_TaskItemTile> {
  bool _expanded = false;

  @override
  Widget build(BuildContext context) {
    final task = widget.task;
    final completed = _taskIsCompleted(task);
    final categoryColor = _safeCategoryColor(task.color);
    final surfaceColor = completed
        ? HeyBeanTheme.surface
        : categoryColor.withValues(alpha: .14);
    final borderColor = completed
        ? HeyBeanTheme.border
        : categoryColor.withValues(alpha: .34);
    return Container(
      key: Key('task-row-surface-${task.id}'),
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
      decoration: BoxDecoration(
        color: surfaceColor,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: borderColor),
      ),
      child: Stack(
        children: [
          Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  widget.pending
                      ? const Padding(
                          padding: EdgeInsets.all(12),
                          child: SizedBox.square(
                            dimension: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          ),
                        )
                      : Checkbox(
                          key: Key('task-complete-checkbox-${task.id}'),
                          value: completed,
                          onChanged: (_) => widget.onCompleted(task),
                          activeColor: HeyBeanTheme.accentStrong,
                        ),
                  Expanded(
                    child: InkWell(
                      key: Key('task-row-action-${task.id}'),
                      borderRadius: BorderRadius.circular(12),
                      onTap: widget.onTap,
                      child: Padding(
                        padding: const EdgeInsets.symmetric(
                          vertical: 9,
                          horizontal: 2,
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Expanded(
                                  child: Text(
                                    task.title,
                                    style: TextStyle(
                                      fontWeight: FontWeight.w500,
                                      fontSize: 14,
                                      decoration: completed
                                          ? TextDecoration.lineThrough
                                          : null,
                                      color: completed
                                          ? HeyBeanTheme.muted
                                          : HeyBeanTheme.text,
                                    ),
                                  ),
                                ),
                                if (_canExpand)
                                  InkWell(
                                    key: Key('task-expand-action-${task.id}'),
                                    borderRadius: BorderRadius.circular(999),
                                    onTap: () =>
                                        setState(() => _expanded = !_expanded),
                                    child: Padding(
                                      padding: const EdgeInsets.all(2),
                                      child: Icon(
                                        _expanded
                                            ? Icons.keyboard_arrow_up_rounded
                                            : Icons.keyboard_arrow_down_rounded,
                                        color: Colors.black,
                                        size: 18,
                                      ),
                                    ),
                                  ),
                              ],
                            ),
                            if (widget.subtitle.isNotEmpty) ...[
                              const SizedBox(height: 3),
                              Align(
                                alignment: Alignment.centerRight,
                                child: Text(
                                  widget.subtitle,
                                  style: const TextStyle(
                                    color: HeyBeanTheme.muted,
                                    fontSize: 12,
                                  ),
                                ),
                              ),
                            ],
                          ],
                        ),
                      ),
                    ),
                  ),
                  if (widget.onTap != null)
                    IconButton(
                      key: Key('task-edit-action-${task.id}'),
                      tooltip: 'Edit task',
                      onPressed: widget.onTap,
                      icon: const Icon(Icons.edit_outlined),
                    ),
                ],
              ),
              if (_expanded) ...[
                Padding(
                  padding: const EdgeInsets.fromLTRB(54, 0, 8, 8),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      if ((task.notes ?? '').trim().isNotEmpty) ...[
                        Container(
                          key: Key('task-notes-${task.id}'),
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(
                            color: HeyBeanTheme.surface.withValues(alpha: .62),
                            borderRadius: BorderRadius.circular(12),
                            border: Border.all(color: HeyBeanTheme.border),
                          ),
                          child: Text(
                            task.notes!.trim(),
                            style: const TextStyle(fontSize: 13, height: 1.35),
                          ),
                        ),
                        const SizedBox(height: 8),
                      ],
                      Row(
                        children: [
                          const Expanded(
                            child: Text(
                              'Sub-tasks',
                              style: TextStyle(
                                color: HeyBeanTheme.muted,
                                fontSize: 12,
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                          ),
                          if (widget.onAddSubtask != null)
                            TextButton.icon(
                              key: Key('task-add-subtask-${task.id}'),
                              onPressed: widget.onAddSubtask,
                              icon: const Icon(Icons.add_rounded, size: 16),
                              label: const Text('Add'),
                            ),
                        ],
                      ),
                      if (widget.subtasks.isEmpty)
                        const Text(
                          'No active sub-tasks',
                          style: TextStyle(
                            color: HeyBeanTheme.muted,
                            fontSize: 12,
                          ),
                        )
                      else
                        for (final subtask in widget.subtasks)
                          _SubtaskRow(
                            task: subtask,
                            pending: widget.pendingTaskIds.contains(subtask.id),
                            onCompleted:
                                widget.onSubtaskCompleted ?? widget.onCompleted,
                            onTap: widget.onSubtaskTap == null
                                ? null
                                : () => widget.onSubtaskTap!(subtask),
                          ),
                    ],
                  ),
                ),
              ],
            ],
          ),
          if (_taskIsCritical(task))
            Positioned(
              key: Key('task-critical-star-${task.id}'),
              top: 1,
              right: 4,
              child: const Icon(
                Icons.star_rounded,
                color: HeyBeanTheme.warning,
                size: 16,
              ),
            ),
        ],
      ),
    );
  }

  bool get _canExpand =>
      (widget.task.notes ?? '').trim().isNotEmpty ||
      widget.subtasks.isNotEmpty ||
      widget.onAddSubtask != null;
}

class _SubtaskRow extends StatelessWidget {
  const _SubtaskRow({
    required this.task,
    required this.onCompleted,
    this.pending = false,
    this.onTap,
  });

  final HermesTask task;
  final Future<void> Function(HermesTask task) onCompleted;
  final bool pending;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final completed = _taskIsCompleted(task);
    return InkWell(
      key: Key('subtask-row-${task.id}'),
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          children: [
            pending
                ? const SizedBox.square(
                    dimension: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : Checkbox(
                    key: Key('subtask-complete-checkbox-${task.id}'),
                    value: completed,
                    onChanged: (_) => onCompleted(task),
                    visualDensity: VisualDensity.compact,
                    activeColor: HeyBeanTheme.accentStrong,
                  ),
            if (_taskIsCritical(task))
              const Icon(
                Icons.star_rounded,
                size: 14,
                color: HeyBeanTheme.warning,
              ),
            Expanded(
              child: Text(
                task.title,
                style: TextStyle(
                  fontSize: 13,
                  decoration: completed ? TextDecoration.lineThrough : null,
                  color: completed ? HeyBeanTheme.muted : HeyBeanTheme.text,
                ),
              ),
            ),
            if ((task.dueAt ?? '').trim().isNotEmpty)
              Text(
                _formatCalendarEventDateTime(task.dueAt),
                style: const TextStyle(color: HeyBeanTheme.muted, fontSize: 11),
              ),
          ],
        ),
      ),
    );
  }
}

class _ReminderItemTile extends StatelessWidget {
  const _ReminderItemTile({
    required this.reminder,
    required this.subtitle,
    required this.onCompleted,
    this.onTap,
  });

  final HermesReminder reminder;
  final String subtitle;
  final Future<void> Function(HermesReminder reminder) onCompleted;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final completed = _reminderIsCompleted(reminder);
    final critical = _reminderIsCritical(reminder);
    final categoryColor = _safeCategoryColor(reminder.color);
    final surfaceColor = completed
        ? HeyBeanTheme.surface
        : categoryColor.withValues(alpha: .14);
    final borderColor = completed
        ? HeyBeanTheme.border
        : categoryColor.withValues(alpha: .34);
    return Container(
      key: Key('reminder-row-surface-${reminder.id}'),
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
      decoration: BoxDecoration(
        color: surfaceColor,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: borderColor),
      ),
      child: Row(
        children: [
          Checkbox(
            key: Key('reminder-complete-checkbox-${reminder.id}'),
            value: completed,
            onChanged: (_) => onCompleted(reminder),
            activeColor: HeyBeanTheme.accentStrong,
          ),
          Expanded(
            child: InkWell(
              key: Key('reminder-row-action-${reminder.id}'),
              borderRadius: BorderRadius.circular(12),
              onTap: onTap,
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        if (critical) ...[
                          const Icon(
                            Icons.star_rounded,
                            size: 15,
                            color: HeyBeanTheme.warning,
                          ),
                          const SizedBox(width: 4),
                        ],
                        Expanded(
                          child: Text(
                            reminder.title,
                            style: TextStyle(
                              fontWeight: FontWeight.w800,
                              decoration: completed
                                  ? TextDecoration.lineThrough
                                  : null,
                              color: completed
                                  ? HeyBeanTheme.muted
                                  : HeyBeanTheme.text,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 2),
                    Text(
                      subtitle,
                      style: const TextStyle(
                        color: HeyBeanTheme.muted,
                        fontSize: 12,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          IconButton(
            key: Key('reminder-edit-action-${reminder.id}'),
            tooltip: 'Edit reminder',
            onPressed: onTap,
            icon: const Icon(Icons.edit_outlined),
          ),
        ],
      ),
    );
  }
}

class _CompactItemTile extends StatelessWidget {
  const _CompactItemTile({
    required this.icon,
    required this.title,
    required this.subtitle,
    this.onTap,
    this.trailing,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback? onTap;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => InkWell(
    borderRadius: BorderRadius.circular(16),
    onTap: onTap,
    child: Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: HeyBeanTheme.surface2,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: HeyBeanTheme.border),
      ),
      child: Row(
        children: [
          Icon(icon, color: HeyBeanTheme.accentStrong),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
                Text(
                  subtitle,
                  style: const TextStyle(color: HeyBeanTheme.muted),
                ),
              ],
            ),
          ),
          if (trailing != null) ...[const SizedBox(width: 8), trailing!],
        ],
      ),
    ),
  );
}

class _EmptySurface extends StatelessWidget {
  const _EmptySurface({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) => Container(
    width: double.infinity,
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: HeyBeanTheme.surface2,
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: HeyBeanTheme.border),
    ),
    child: Text(label, style: const TextStyle(color: HeyBeanTheme.muted)),
  );
}

class _InlineLoadingSurface extends StatelessWidget {
  const _InlineLoadingSurface({
    super.key,
    required this.label,
    this.fillHeight = false,
  });

  final String label;
  final bool fillHeight;

  @override
  Widget build(BuildContext context) {
    final content = Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
      decoration: BoxDecoration(
        color: HeyBeanTheme.surface2,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: HeyBeanTheme.border),
      ),
      child: Row(
        mainAxisSize: fillHeight ? MainAxisSize.min : MainAxisSize.max,
        mainAxisAlignment: fillHeight
            ? MainAxisAlignment.center
            : MainAxisAlignment.start,
        children: [
          SizedBox.square(
            dimension: 16,
            child: CircularProgressIndicator(
              strokeWidth: 2,
              color: HeyBeanTheme.accentStrong,
              backgroundColor: HeyBeanTheme.accent.withValues(alpha: .14),
            ),
          ),
          const SizedBox(width: 10),
          Text(
            label,
            style: const TextStyle(
              color: HeyBeanTheme.muted,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
    if (!fillHeight) return content;
    return Container(
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: HeyBeanTheme.surface.withValues(alpha: .62),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: HeyBeanTheme.border),
      ),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 260),
        child: content,
      ),
    );
  }
}

List<HermesTask> _replaceTask(List<HermesTask> tasks, HermesTask replacement) =>
    tasks
        .map((task) => task.id == replacement.id ? replacement : task)
        .toList(growable: false);

List<HermesTask> _removeTask(List<HermesTask> tasks, int taskId) =>
    tasks.where((task) => task.id != taskId).toList(growable: false);

List<HermesTask> _mergeTaskLists(
  List<HermesTask> primary,
  List<HermesTask> secondary,
) {
  final byId = <int, HermesTask>{};
  for (final task in [...secondary, ...primary]) {
    byId[task.id] = task;
  }
  return byId.values.toList(growable: false);
}

List<HermesNote> _sortedNotes(List<HermesNote> notes) {
  final sorted = [...notes];
  sorted.sort((a, b) {
    if (a.isPinned != b.isPinned) return a.isPinned ? -1 : 1;
    final aTime = DateTime.tryParse(a.updatedAt ?? '') ?? DateTime(1970);
    final bTime = DateTime.tryParse(b.updatedAt ?? '') ?? DateTime(1970);
    return bTime.compareTo(aTime);
  });
  return sorted;
}

List<HermesNoteFolder> _sortedNoteFolders(List<HermesNoteFolder> folders) {
  final sorted = [...folders];
  sorted.sort((a, b) {
    final order = (a.sortOrder ?? 0).compareTo(b.sortOrder ?? 0);
    if (order != 0) return order;
    final name = a.name.toLowerCase().compareTo(b.name.toLowerCase());
    if (name != 0) return name;
    return a.id.compareTo(b.id);
  });
  return sorted;
}

List<HermesNote> _upsertNote(List<HermesNote> notes, HermesNote note) {
  final next = [...notes];
  final index = next.indexWhere((item) => item.id == note.id);
  if (index == -1) {
    next.add(note);
  } else {
    next[index] = note;
  }
  return _sortedNotes(next);
}

List<HermesMemoryItem> _sortedMemoryItems(List<HermesMemoryItem> items) {
  final sorted = [...items];
  sorted.sort((a, b) {
    final aImportance = a.importance ?? 0;
    final bImportance = b.importance ?? 0;
    if (aImportance != bImportance) return bImportance.compareTo(aImportance);
    final aTime = DateTime.tryParse(a.updatedAt ?? '') ?? DateTime(1970);
    final bTime = DateTime.tryParse(b.updatedAt ?? '') ?? DateTime(1970);
    return bTime.compareTo(aTime);
  });
  return sorted;
}

List<HermesMemoryItem> _upsertMemoryItem(
  List<HermesMemoryItem> items,
  HermesMemoryItem item,
) {
  final next = [...items];
  final index = next.indexWhere((candidate) => candidate.id == item.id);
  if (index == -1) {
    next.add(item);
  } else {
    next[index] = item;
  }
  return _sortedMemoryItems(next);
}

String _memoryTypeLabel(String type) {
  switch (type) {
    case 'preference':
      return 'Preference';
    case 'instruction':
      return 'Instruction';
    case 'project':
      return 'Project';
    case 'decision':
      return 'Decision';
    case 'routine':
      return 'Routine';
    case 'identity':
      return 'Identity';
    case 'summary':
      return 'Summary';
    default:
      return 'Fact';
  }
}

bool _taskIsSubtask(HermesTask task) => task.parentTaskId != null;

List<HermesTask> _subtasksFor(HermesTask task, List<HermesTask> tasks) {
  final subtasks = tasks
      .where(
        (candidate) =>
            candidate.parentTaskId == task.id && !_taskIsCompleted(candidate),
      )
      .toList();
  subtasks.sort(_compareTasksByCompletionAndDueDate);
  return subtasks;
}

List<HermesTask> _visibleSortedTasks(List<HermesTask> tasks) {
  final today = _dateOnly(DateTime.now());
  final visible = tasks
      .where((task) => _taskVisibleOnOrAfter(task, today))
      .toList();
  visible.sort(_compareTasksByCompletionAndDueDate);
  return visible;
}

List<HermesTask> _tasksForTodayAgenda(List<HermesTask> tasks, DateTime day) {
  final today = _dateOnly(day);
  final visible = tasks.where((task) {
    final dueAt = _parseTaskDueDate(task);
    if (dueAt == null) return false;
    final dueDay = _dateOnly(dueAt);
    if (_taskIsCompleted(task)) return _sameCalendarDay(dueDay, today);
    return _taskIsOverdue(task) || _sameCalendarDay(dueDay, today);
  }).toList();
  visible.sort(_compareTasksByCompletionAndDueDate);
  return visible;
}

List<HermesTask> _tasksForMonthAgenda(List<HermesTask> tasks, DateTime month) {
  final visibleMonth = DateTime(month.year, month.month);
  final visible = tasks.where((task) {
    if (_taskIsCompleted(task)) return false;
    final dueAt = _parseTaskDueDate(task);
    if (dueAt == null) return false;
    final dueDay = _dateOnly(dueAt);
    final dueMonth = DateTime(dueDay.year, dueDay.month);
    return _taskIsOverdue(task) || dueMonth == visibleMonth;
  }).toList();
  visible.sort(_compareTasksByCompletionAndDueDate);
  return visible;
}

int? _taskDaysAway(HermesTask task) {
  final dueAt = _parseTaskDueDate(task);
  if (dueAt == null) return null;
  final today = _dateOnly(DateTime.now());
  final dueDay = _dateOnly(dueAt);
  return dueDay.difference(today).inDays;
}

int? _reminderDaysAway(HermesReminder reminder) {
  final dueAt = _parseReminderDueDate(reminder);
  if (dueAt == null) return null;
  final today = _dateOnly(DateTime.now());
  final dueDay = _dateOnly(dueAt);
  return dueDay.difference(today).inDays;
}

int _compareTasksByCompletionAndDueDate(HermesTask a, HermesTask b) {
  final completedCompare = _taskIsCompleted(a) == _taskIsCompleted(b)
      ? 0
      : (_taskIsCompleted(a) ? 1 : -1);
  if (completedCompare != 0) return completedCompare;
  final overdueCompare = _taskIsOverdue(a) == _taskIsOverdue(b)
      ? 0
      : (_taskIsOverdue(a) ? -1 : 1);
  if (overdueCompare != 0) return overdueCompare;
  final aDue = _parseTaskDueDate(a);
  final bDue = _parseTaskDueDate(b);
  if (aDue != null && bDue != null) {
    final dueCompare = aDue.compareTo(bDue);
    if (dueCompare != 0) return dueCompare;
  } else if (aDue != null) {
    return -1;
  } else if (bDue != null) {
    return 1;
  }
  return a.id.compareTo(b.id);
}

int _compareRemindersByCompletionAndDueDate(
  HermesReminder a,
  HermesReminder b,
) {
  final completedCompare = _reminderIsCompleted(a) == _reminderIsCompleted(b)
      ? 0
      : (_reminderIsCompleted(a) ? 1 : -1);
  if (completedCompare != 0) return completedCompare;
  final overdueCompare = _reminderIsOverdue(a) == _reminderIsOverdue(b)
      ? 0
      : (_reminderIsOverdue(a) ? -1 : 1);
  if (overdueCompare != 0) return overdueCompare;
  final aDue = _parseReminderDueDate(a);
  final bDue = _parseReminderDueDate(b);
  if (aDue != null && bDue != null) {
    final dueCompare = aDue.compareTo(bDue);
    if (dueCompare != 0) return dueCompare;
  } else if (aDue != null) {
    return -1;
  } else if (bDue != null) {
    return 1;
  }
  return a.id.compareTo(b.id);
}

List<HermesTask> _criticalTasksForToday(List<HermesTask> tasks) {
  final today = _dateOnly(DateTime.now());
  final visible = tasks.where((task) {
    if (!task.isCritical || _taskIsCompleted(task) || _taskIsSubtask(task)) {
      return false;
    }
    final dueAt = _parseTaskDueDate(task);
    return dueAt != null && !_dateOnly(dueAt).isAfter(today);
  }).toList();
  visible.sort(_compareTasksByCompletionAndDueDate);
  return visible;
}

List<HermesCalendarEvent> _criticalEventsForToday(
  List<HermesCalendarEvent> events,
) {
  final today = _dateOnly(DateTime.now());
  final visible = events
      .where((event) => event.isCritical && _eventFallsOnDay(event, today))
      .toList();
  visible.sort((a, b) {
    final aStart = _parseCalendarEventDateTime(a.startsAt);
    final bStart = _parseCalendarEventDateTime(b.startsAt);
    if (aStart != null && bStart != null) return aStart.compareTo(bStart);
    if (aStart != null) return -1;
    if (bStart != null) return 1;
    return a.id.compareTo(b.id);
  });
  return visible;
}

List<HermesReminder> _criticalRemindersForToday(
  List<HermesReminder> reminders,
) {
  final today = _dateOnly(DateTime.now());
  final visible = reminders.where((reminder) {
    if (!reminder.isCritical || _reminderIsCompleted(reminder)) {
      return false;
    }
    final dueAt = _parseReminderDueDate(reminder);
    return dueAt != null && !_dateOnly(dueAt).isAfter(today);
  }).toList();
  visible.sort((a, b) {
    final aDue = _parseReminderDueDate(a);
    final bDue = _parseReminderDueDate(b);
    if (aDue != null && bDue != null) return aDue.compareTo(bDue);
    if (aDue != null) return -1;
    if (bDue != null) return 1;
    return a.id.compareTo(b.id);
  });
  return visible;
}

bool _taskVisibleOnOrAfter(HermesTask task, DateTime today) {
  if (_taskIsRecurring(task)) return true;
  if (_taskIsOverdue(task)) return true;
  final dueAt = _parseTaskDueDate(task);
  return dueAt == null || !_dateOnly(dueAt).isBefore(today);
}

bool _taskIsCritical(HermesTask task) =>
    task.isCritical || _taskIsOverdue(task);

bool _reminderIsCritical(HermesReminder reminder) =>
    reminder.isCritical || _reminderIsOverdue(reminder);

bool _taskIsOverdue(HermesTask task) {
  if (_taskIsCompleted(task)) return false;
  final dueAt = _parseTaskDueDate(task);
  return dueAt != null && dueAt.isBefore(DateTime.now());
}

bool _reminderIsOverdue(HermesReminder reminder) {
  if (_reminderIsCompleted(reminder)) return false;
  final dueAt = _parseReminderDueDate(reminder);
  return dueAt != null && dueAt.isBefore(DateTime.now());
}

bool _taskIsCompleted(HermesTask task) {
  final status = (task.status ?? 'open').toLowerCase().replaceAll('_', '-');
  return status == 'completed' || status == 'complete' || status == 'done';
}

bool _reminderIsCompleted(HermesReminder reminder) {
  final status = (reminder.status ?? 'pending').toLowerCase().replaceAll(
    '_',
    '-',
  );
  return status == 'completed' || status == 'complete' || status == 'done';
}

String _taskSubtitle(HermesTask task) {
  final dueLabel = (task.dueAt != null && task.dueAt!.trim().isNotEmpty)
      ? _formatCalendarEventDateTime(task.dueAt)
      : '';
  final parts = <String>[
    if (_taskIsCompleted(task)) 'Completed',
    if ((task.category ?? '').trim().isNotEmpty) task.category!.trim(),
    if (_taskIsOverdue(task)) 'overdue',
    if (dueLabel.isNotEmpty) 'Due $dueLabel',
    if (_taskIsRecurring(task)) _recurrenceSummaryFromMetadata(task.metadata),
  ];
  return parts.join(' · ');
}

String _reminderSubtitle(HermesReminder reminder) {
  final parts = <String>[
    _reminderIsCompleted(reminder) ? 'Completed' : 'Pending',
    if ((reminder.category ?? '').trim().isNotEmpty) reminder.category!.trim(),
    if (_reminderIsOverdue(reminder)) 'overdue',
    if (reminder.dueAt != null && reminder.dueAt!.trim().isNotEmpty)
      _formatCalendarEventDateTime(reminder.dueAt)
    else
      'No time set',
    if (reminder.calendarEventId != null) 'Linked event',
    if ((reminder.metadata?['recurrence']?.toString() ?? '').isNotEmpty &&
        reminder.metadata?['recurrence'] != 'none')
      _recurrenceSummaryFromMetadata(reminder.metadata),
  ];
  return parts.join(' · ');
}

DateTime? _parseReminderDueDate(HermesReminder reminder) {
  final value = reminder.dueAt;
  if (value == null || value.trim().isEmpty) return null;
  return DateTime.tryParse(value)?.toLocal();
}

String _recurrenceSummaryFromMetadata(Map<String, Object?>? metadata) {
  final recurrence = (metadata?['recurrence']?.toString() ?? 'none')
      .trim()
      .toLowerCase();
  if (recurrence.isEmpty || recurrence == 'none') return '';
  if (recurrence == 'interval') {
    final interval = _recurrenceIntervalFromMetadata(metadata);
    if (interval == null || interval <= 0) return 'Custom interval';
    final unit =
        metadata?['unit']?.toString() ??
        metadata?['interval_unit']?.toString() ??
        metadata?['intervalUnit']?.toString() ??
        'days';
    return 'Every $interval ${_intervalUnitLabel(unit, interval)}';
  }
  return switch (recurrence) {
    'daily' => 'Daily',
    'weekly' => 'Weekly',
    'monthly' => 'Monthly',
    'yearly' => 'Yearly',
    'specific_days' => 'Specific days',
    _ => recurrence,
  };
}

int? _recurrenceIntervalFromMetadata(Map<String, Object?>? metadata) {
  final value = metadata?['interval'];
  if (value is int) return value;
  if (value is num) return value.round();
  return int.tryParse(value?.toString() ?? '');
}

String _intervalUnitLabel(String unit, int interval) {
  final normalized = switch (unit.trim().toLowerCase()) {
    'day' || 'days' => 'day',
    'week' || 'weeks' => 'week',
    'month' || 'months' => 'month',
    'year' || 'years' => 'year',
    final value when value.endsWith('s') && value.length > 1 => value.substring(
      0,
      value.length - 1,
    ),
    final value when value.isNotEmpty => value,
    _ => 'day',
  };
  return interval == 1 ? normalized : '${normalized}s';
}

Color _safeCategoryColor(String? value) {
  final color = value?.trim() ?? '';
  if (!RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(color)) {
    return _colorFromHex(_themeCategoryColorHex());
  }
  return Color(int.parse('FF${color.substring(1)}', radix: 16));
}

bool _eventIsAllDay(HermesCalendarEvent event) {
  final metadata = event.metadata;
  final marker = metadata?['all_day'] ?? metadata?['allDay'];
  final markerText = marker?.toString().toLowerCase();
  if (marker == true || markerText == 'true' || markerText == '1') return true;
  final source = metadata?['source']?.toString() ?? '';
  if (source != 'google_calendar') return false;
  final start = _parseCalendarEventDateTime(event.startsAt);
  final end = _parseCalendarEventDateTime(event.endsAt, event.startsAt);
  if (start == null || end == null) return false;
  final startsAtMidnight =
      start.hour == 0 && start.minute == 0 && start.second == 0;
  final endsAtMidnight = end.hour == 0 && end.minute == 0 && end.second == 0;
  return startsAtMidnight &&
      endsAtMidnight &&
      !end.isBefore(start.add(const Duration(days: 1)));
}

bool _eventRendersAboveTimeline(HermesCalendarEvent event) =>
    _eventIsAllDay(event) || _eventIsTimedMultiDay(event);

bool _eventIsTimedMultiDay(HermesCalendarEvent event) =>
    !_eventIsAllDay(event) && _eventSpansMultipleDays(event);

bool _eventSpansMultipleDays(HermesCalendarEvent event) {
  final start = _parseCalendarEventDateTime(event.startsAt);
  final end = _parseCalendarEventDateTime(event.endsAt, event.startsAt);
  if (start == null || end == null || !end.isAfter(start)) return false;
  return !_sameCalendarDay(start, end);
}

DateTime? _multiDayEventStartDay(HermesCalendarEvent event) {
  final start = _parseCalendarEventDateTime(event.startsAt);
  return start == null ? null : _dateOnly(start);
}

DateTime? _multiDayEventEndDay(HermesCalendarEvent event) {
  final start = _parseCalendarEventDateTime(event.startsAt);
  final end = _parseCalendarEventDateTime(event.endsAt, event.startsAt);
  if (start == null || end == null || !end.isAfter(start)) return null;
  return _dateOnly(end);
}

String _eventTimeRangeShort(HermesCalendarEvent event) {
  String shortTime(String? value) {
    final parsed = _parseCalendarEventDateTime(value, event.startsAt);
    if (parsed == null) return '';
    return _naturalTimeLabel(parsed);
  }

  final start = shortTime(event.startsAt);
  final end = shortTime(event.endsAt);
  if (start.isEmpty) return '';
  return end.isEmpty ? start : '$start – $end';
}

String _multiDayEventLabelForDay(HermesCalendarEvent event, DateTime day) {
  final start = _parseCalendarEventDateTime(event.startsAt);
  final end = _parseCalendarEventDateTime(event.endsAt, event.startsAt);
  if (start != null && _sameCalendarDay(start, day)) {
    return '${_naturalTimeLabel(start)} ${event.title}';
  }
  if (end != null && _sameCalendarDay(end, day)) {
    return '${event.title} ${_naturalTimeLabel(end)}';
  }
  return event.title;
}

String? _taskReminderInputToWireValue(String? value) {
  final trimmed = value?.trim() ?? '';
  if (trimmed.isEmpty) return null;
  final parsed = _parseCalendarEventDateTime(trimmed);
  return parsed == null ? trimmed : _dateTimeToWireIsoString(parsed);
}

bool _taskIsRecurring(HermesTask task) {
  final metadata = task.metadata;
  if (metadata == null) return false;
  final recurrence =
      metadata['recurrence'] ?? metadata['recurring'] ?? metadata['rrule'];
  final recurrenceValue = recurrence?.toString().trim().toLowerCase();
  return recurrence != null &&
      recurrence != false &&
      recurrenceValue != null &&
      recurrenceValue.isNotEmpty &&
      recurrenceValue != 'none';
}

DateTime? _parseTaskDueDate(HermesTask task) {
  final dueAt = task.dueAt;
  if (dueAt == null || dueAt.isEmpty) return null;
  return DateTime.tryParse(dueAt)?.toLocal();
}

List<_CommandCenterAgendaItem> _commandCenterAgendaItems({
  required List<HermesTask> tasks,
  required List<HermesReminder> reminders,
  required List<HermesCalendarEvent> calendar,
}) {
  final now = DateTime.now();
  final today = _dateOnly(now);
  final endOfToday = today.add(const Duration(days: 1));
  final items = <_CommandCenterAgendaItem>[];

  for (final event in calendar) {
    if (!_eventFallsOnDay(event, today)) continue;
    final start = _parseCalendarEventDateTime(event.startsAt);
    final end =
        _parseCalendarEventDateTime(event.endsAt, event.startsAt) ?? start;
    if (start == null) continue;
    if (!_eventIsAllDay(event) && end != null && end.isBefore(now)) continue;
    final allDay = _eventIsAllDay(event);
    final displayTime = allDay
        ? today
        : start.isBefore(now) && end != null && end.isAfter(now)
        ? now
        : start;
    items.add(
      _CommandCenterAgendaItem(
        key: 'event-${event.id}',
        kind: _CommandCenterAgendaKind.event,
        title: event.title,
        time: displayTime,
        timeLabel: allDay ? 'All day' : _eventTimeRangeShort(event),
        subtitle: (event.location ?? '').trim(),
      ),
    );
  }

  for (final task in tasks) {
    if (_taskIsCompleted(task) || _taskIsSubtask(task)) continue;
    final due = _parseTaskDueDate(task);
    if (due == null || !_sameCalendarDay(due, today)) continue;
    final dateOnly = _wireValueLooksDateOnly(task.dueAt);
    if (!dateOnly && due.isBefore(now)) continue;
    items.add(
      _CommandCenterAgendaItem(
        key: 'task-${task.id}',
        kind: _CommandCenterAgendaKind.task,
        title: task.title,
        time: dateOnly ? endOfToday.subtract(const Duration(minutes: 1)) : due,
        timeLabel: dateOnly ? 'Today' : _naturalTimeLabel(due),
        subtitle: (task.category ?? '').trim(),
      ),
    );
  }

  for (final reminder in reminders) {
    if (_reminderIsCompleted(reminder)) continue;
    final due = _parseReminderDueDate(reminder);
    if (due == null || !_sameCalendarDay(due, today)) continue;
    final dateOnly = _wireValueLooksDateOnly(reminder.dueAt);
    if (!dateOnly && due.isBefore(now)) continue;
    items.add(
      _CommandCenterAgendaItem(
        key: 'reminder-${reminder.id}',
        kind: _CommandCenterAgendaKind.reminder,
        title: reminder.title,
        time: dateOnly ? endOfToday.subtract(const Duration(minutes: 1)) : due,
        timeLabel: dateOnly ? 'Today' : _naturalTimeLabel(due),
        subtitle: (reminder.category ?? '').trim(),
      ),
    );
  }

  items.sort((a, b) {
    final timeCompare = a.time.compareTo(b.time);
    if (timeCompare != 0) return timeCompare;
    final kindCompare = a.kind.index.compareTo(b.kind.index);
    if (kindCompare != 0) return kindCompare;
    return a.title.toLowerCase().compareTo(b.title.toLowerCase());
  });
  return items;
}

bool _wireValueLooksDateOnly(String? value) =>
    value != null && RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(value.trim());

class _DeleteAccountConfirmationDialog extends StatefulWidget {
  const _DeleteAccountConfirmationDialog();

  @override
  State<_DeleteAccountConfirmationDialog> createState() =>
      _DeleteAccountConfirmationDialogState();
}

class _DeleteAccountConfirmationDialogState
    extends State<_DeleteAccountConfirmationDialog> {
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _submit() {
    Navigator.of(context).pop(_controller.text.trim() == 'DELETE');
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Delete account permanently?'),
    content: SingleChildScrollView(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'This permanently deletes your HeyBean account, assistant history, tasks, reminders, calendar events, and account data. Export anything you need before continuing.',
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('delete-account-confirmation-field'),
            controller: _controller,
            textInputAction: TextInputAction.done,
            onSubmitted: (_) => _submit(),
            decoration: const InputDecoration(
              labelText: 'Type DELETE to confirm',
            ),
          ),
        ],
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(false),
        child: const Text('Cancel'),
      ),
      FilledButton(
        key: const Key('delete-account-confirmation-submit'),
        style: _destructiveFilledButtonStyle(),
        onPressed: _submit,
        child: const Text('Delete account'),
      ),
    ],
  );
}

class _AccountCard extends StatelessWidget {
  const _AccountCard({
    required this.user,
    required this.onEmailChanged,
    required this.onDeleteAccount,
    required this.onSignOut,
    required this.launchExternalUrl,
    this.showLegalLinks = true,
    this.beforeAccountActions,
  });

  final HermesUser user;
  final Future<void> Function(String email) onEmailChanged;
  final Future<void> Function() onDeleteAccount;
  final Future<void> Function() onSignOut;
  final ExternalUrlLauncher launchExternalUrl;
  final bool showLegalLinks;
  final Widget? beforeAccountActions;

  Future<void> _editEmail(BuildContext context) async {
    final nextEmail = await _showEmailEditor(context, initialEmail: user.email);
    if (nextEmail == null || nextEmail.trim() == user.email) return;
    await onEmailChanged(nextEmail);
  }

  Future<bool> _confirmDeleteAccount(BuildContext context) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => const _DeleteAccountConfirmationDialog(),
    );
    return confirmed == true;
  }

  Future<void> _requestDeleteAccount(BuildContext context) async {
    if (await _confirmDeleteAccount(context)) {
      await onDeleteAccount();
    }
  }

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.settings_rounded,
          title: 'Profile',
          subtitle: 'Account and app settings',
          infoKey: Key('profile-info'),
          infoTitle: 'Profile and account',
          infoBullets: [
            'Edit your email here if your sign-in address changes.',
            'Privacy Policy, Terms of Use, and Support links open the hosted HeyBean pages.',
            'Delete account permanently removes your HeyBean account and data after confirmation.',
          ],
        ),
        const SizedBox(height: 10),
        _CompactItemTile(
          icon: Icons.email_outlined,
          title: 'Email',
          subtitle: user.email,
          trailing: TextButton(
            key: const Key('settings-edit-email-action'),
            onPressed: () => _editEmail(context),
            child: const Text('Edit'),
          ),
        ),
        const SizedBox(height: 10),
        if (beforeAccountActions != null) ...[
          beforeAccountActions!,
          const SizedBox(height: 10),
        ],
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            OutlinedButton.icon(
              key: const Key('sign-out-action'),
              onPressed: onSignOut,
              icon: const Icon(Icons.logout_rounded),
              label: const Text('Sign out'),
            ),
            FilledButton.icon(
              key: const Key('delete-account-action'),
              style: _destructiveFilledButtonStyle(),
              onPressed: () => _requestDeleteAccount(context),
              icon: const Icon(Icons.delete_outline_rounded),
              label: const Text('Delete account'),
            ),
          ],
        ),
        if (showLegalLinks) ...[
          const SizedBox(height: 10),
          _SettingsLegalLinksRow(launchExternalUrl: launchExternalUrl),
        ],
      ],
    ),
  );
}

class _SettingsLegalLinksRow extends StatelessWidget {
  const _SettingsLegalLinksRow({required this.launchExternalUrl});

  final ExternalUrlLauncher launchExternalUrl;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final style = theme.textTheme.bodyMedium?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      fontWeight: FontWeight.w400,
    );
    final buttonStyle = TextButton.styleFrom(
      foregroundColor: theme.colorScheme.onSurfaceVariant,
      minimumSize: Size.zero,
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
      textStyle: style,
    );

    return Align(
      alignment: Alignment.center,
      child: Wrap(
        alignment: WrapAlignment.center,
        spacing: 12,
        runSpacing: 6,
        children: [
          TextButton(
            key: const Key('settings-privacy-policy-link'),
            style: buttonStyle,
            onPressed: () => launchExternalUrl(_privacyPolicyUrl),
            child: const Text('Privacy Policy'),
          ),
          TextButton(
            key: const Key('settings-terms-of-service-link'),
            style: buttonStyle,
            onPressed: () => launchExternalUrl(_termsOfServiceUrl),
            child: const Text('Terms of Use'),
          ),
          TextButton(
            key: const Key('settings-support-link'),
            style: buttonStyle,
            onPressed: () => launchExternalUrl(_supportUrl),
            child: const Text('Support'),
          ),
        ],
      ),
    );
  }
}

Future<String?> _showEmailEditor(
  BuildContext context, {
  required String initialEmail,
}) => showDialog<String>(
  context: context,
  builder: (context) => _EmailEditorDialog(initialEmail: initialEmail),
);

class _EmailEditorDialog extends StatefulWidget {
  const _EmailEditorDialog({required this.initialEmail});

  final String initialEmail;

  @override
  State<_EmailEditorDialog> createState() => _EmailEditorDialogState();
}

class _EmailEditorDialogState extends State<_EmailEditorDialog> {
  late final TextEditingController _controller;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: widget.initialEmail);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Update email'),
    content: TextField(
      key: const Key('settings-email-editor-field'),
      controller: _controller,
      keyboardType: TextInputType.emailAddress,
      autofocus: true,
      decoration: const InputDecoration(labelText: 'Email address'),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(),
        child: const Text('Cancel'),
      ),
      FilledButton(
        key: const Key('settings-email-editor-save'),
        onPressed: () => Navigator.of(context).pop(_controller.text.trim()),
        child: const Text('Save'),
      ),
    ],
  );
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({
    required this.icon,
    required this.title,
    required this.subtitle,
    this.infoKey,
    this.infoTitle,
    this.infoBullets = const [],
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final Key? infoKey;
  final String? infoTitle;
  final List<String> infoBullets;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Icon(icon, color: HeyBeanTheme.accentStrong),
      const SizedBox(width: 10),
      Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title,
              style: Theme.of(
                context,
              ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800),
            ),
            if (subtitle.isNotEmpty)
              Text(
                subtitle,
                style: Theme.of(
                  context,
                ).textTheme.bodySmall?.copyWith(color: HeyBeanTheme.muted),
              ),
          ],
        ),
      ),
      if (infoTitle != null && infoBullets.isNotEmpty) ...[
        const SizedBox(width: 8),
        _InfoIconButton(key: infoKey, title: infoTitle!, bullets: infoBullets),
      ],
    ],
  );
}

class _FormEditorHeader extends StatelessWidget {
  const _FormEditorHeader({
    required this.icon,
    required this.title,
    required this.subtitle,
    this.titleKey,
    this.trailing,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final Key? titleKey;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.only(bottom: 14),
    decoration: const BoxDecoration(
      border: Border(bottom: BorderSide(color: Color(0x1A1C314E))),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 38,
          height: 38,
          decoration: BoxDecoration(
            color: HeyBeanTheme.accent.withValues(alpha: .10),
            borderRadius: BorderRadius.circular(14),
            border: Border.all(
              color: HeyBeanTheme.accent.withValues(alpha: .16),
            ),
          ),
          child: Icon(icon, color: HeyBeanTheme.accentStrong, size: 21),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                key: titleKey,
                style: Theme.of(context).textTheme.titleLarge?.copyWith(
                  color: HeyBeanTheme.text,
                  fontWeight: FontWeight.w900,
                  height: 1.1,
                ),
              ),
              if (subtitle.isNotEmpty) ...[
                const SizedBox(height: 4),
                Text(
                  subtitle,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: HeyBeanTheme.muted,
                    height: 1.35,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ],
          ),
        ),
        if (trailing != null) ...[const SizedBox(width: 12), trailing!],
      ],
    ),
  );
}

class _MobileFormSection extends StatelessWidget {
  const _MobileFormSection({
    super.key,
    required this.title,
    required this.children,
    this.subtitle,
    this.icon,
    this.iconWidget,
    this.infoKey,
    this.infoTitle,
    this.infoBullets = const [],
    this.primary = false,
  });

  final String title;
  final String? subtitle;
  final IconData? icon;
  final Widget? iconWidget;
  final Key? infoKey;
  final String? infoTitle;
  final List<String> infoBullets;
  final List<Widget> children;
  final bool primary;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: primary
          ? HeyBeanTheme.accent.withValues(alpha: .06)
          : HeyBeanTheme.surface2.withValues(alpha: .66),
      borderRadius: BorderRadius.circular(16),
      border: Border.all(
        color: primary
            ? HeyBeanTheme.accent.withValues(alpha: .18)
            : const Color(0x1A1C314E),
      ),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (iconWidget != null || icon != null) ...[
              iconWidget ??
                  Icon(icon, size: 18, color: HeyBeanTheme.accentStrong),
              const SizedBox(width: 8),
            ],
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: const TextStyle(
                      color: HeyBeanTheme.text,
                      fontSize: 13,
                      height: 1.2,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  if (subtitle != null && subtitle!.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      subtitle!,
                      style: const TextStyle(
                        color: HeyBeanTheme.muted,
                        fontSize: 12,
                        height: 1.35,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            if (infoTitle != null && infoBullets.isNotEmpty) ...[
              const SizedBox(width: 8),
              _InfoIconButton(
                key: infoKey,
                title: infoTitle!,
                bullets: infoBullets,
              ),
            ],
          ],
        ),
        if (children.isNotEmpty) ...[
          const SizedBox(height: 12),
          for (var index = 0; index < children.length; index++) ...[
            if (index > 0) const SizedBox(height: 12),
            children[index],
          ],
        ],
      ],
    ),
  );
}

class _MobileFormSwitch extends StatelessWidget {
  const _MobileFormSwitch({
    required this.value,
    required this.onChanged,
    required this.title,
    required this.subtitle,
    this.icon,
    this.widgetKey,
  });

  final bool value;
  final ValueChanged<bool>? onChanged;
  final String title;
  final String subtitle;
  final IconData? icon;
  final Key? widgetKey;

  @override
  Widget build(BuildContext context) => Container(
    key: widgetKey,
    decoration: BoxDecoration(
      color: Colors.white.withValues(alpha: .72),
      borderRadius: BorderRadius.circular(14),
      border: Border.all(color: const Color(0x1A1C314E)),
    ),
    child: SwitchListTile(
      value: value,
      onChanged: onChanged,
      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      dense: true,
      title: Text(
        title,
        style: const TextStyle(
          color: HeyBeanTheme.text,
          fontSize: 13,
          fontWeight: FontWeight.w900,
        ),
      ),
      subtitle: Text(
        subtitle,
        style: const TextStyle(
          color: HeyBeanTheme.muted,
          fontSize: 12,
          height: 1.35,
          fontWeight: FontWeight.w600,
        ),
      ),
      secondary: icon == null
          ? null
          : Icon(icon, color: HeyBeanTheme.accentStrong),
    ),
  );
}

class _InfoIconButton extends StatelessWidget {
  const _InfoIconButton({
    super.key,
    required this.title,
    required this.bullets,
  });

  final String title;
  final List<String> bullets;

  @override
  Widget build(BuildContext context) => IconButton(
    tooltip: 'More info about $title',
    icon: Icon(
      Icons.info_outline_rounded,
      semanticLabel: 'More info',
      size: 20,
      color: HeyBeanTheme.accentStrong,
    ),
    onPressed: () => _showInfoSheet(context, title: title, bullets: bullets),
  );
}

Future<void> _showInfoSheet(
  BuildContext context, {
  required String title,
  required List<String> bullets,
}) => showModalBottomSheet<void>(
  context: context,
  isScrollControlled: true,
  backgroundColor: Colors.transparent,
  builder: (context) => SafeArea(
    top: false,
    child: Container(
      key: Key(
        'info-sheet-${title.toLowerCase().replaceAll(RegExp(r'[^a-z0-9]+'), '-').replaceAll(RegExp(r'^-+|-+$'), '')}',
      ),
      margin: const EdgeInsets.all(12),
      padding: const EdgeInsets.fromLTRB(18, 12, 18, 18),
      decoration: BoxDecoration(
        color: HeyBeanTheme.surface,
        borderRadius: BorderRadius.circular(28),
        border: Border.all(color: HeyBeanTheme.border),
        boxShadow: const [
          BoxShadow(
            color: Color(0x26000000),
            blurRadius: 30,
            offset: Offset(0, 16),
          ),
        ],
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Center(
              child: Container(
                width: 44,
                height: 5,
                decoration: BoxDecoration(
                  color: HeyBeanTheme.border,
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Text(
              title,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                color: HeyBeanTheme.text,
                fontWeight: FontWeight.w900,
              ),
            ),
            const SizedBox(height: 12),
            for (final bullet in bullets)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.only(top: 5),
                      child: Icon(
                        Icons.circle,
                        size: 6,
                        color: HeyBeanTheme.accentStrong,
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        bullet,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: HeyBeanTheme.text,
                          height: 1.35,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            const SizedBox(height: 4),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: () => Navigator.of(context).pop(),
                child: const Text('Got it'),
              ),
            ),
          ],
        ),
      ),
    ),
  ),
);

class _ShellCard extends StatelessWidget {
  const _ShellCard({required this.child, this.glow = false});

  final Widget child;
  final bool glow;

  @override
  Widget build(BuildContext context) => Container(
    decoration: BoxDecoration(
      color: HeyBeanTheme.surface.withValues(alpha: .96),
      borderRadius: BorderRadius.circular(18),
      border: Border.all(color: HeyBeanTheme.border),
      boxShadow: [
        BoxShadow(
          color: glow
              ? HeyBeanTheme.accent.withValues(alpha: .12)
              : const Color(0xFF0F172A).withValues(alpha: .07),
          blurRadius: glow ? 30 : 22,
          offset: Offset(0, glow ? 14 : 10),
        ),
      ],
    ),
    padding: const EdgeInsets.all(18),
    child: child,
  );
}

class _MiniSurface extends StatelessWidget {
  const _MiniSurface({
    required this.label,
    required this.value,
    required this.icon,
  });

  final String label;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Container(
    width: 190,
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: HeyBeanTheme.surface2,
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: HeyBeanTheme.border),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, color: HeyBeanTheme.accentStrong),
        const SizedBox(height: 8),
        Text(label, style: const TextStyle(fontWeight: FontWeight.w800)),
        Text(
          value,
          maxLines: 3,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(color: HeyBeanTheme.muted),
        ),
      ],
    ),
  );
}

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
              const Icon(
                Icons.notifications_active_rounded,
                color: Colors.white,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
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
                      style: const TextStyle(
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
                icon: const Icon(Icons.close_rounded, color: Colors.white),
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
                child: const Text('Dismiss'),
              ),
              const Spacer(),
              FilledButton.icon(
                key: const Key('due-reminder-complete'),
                onPressed: onComplete,
                style: FilledButton.styleFrom(
                  backgroundColor: Colors.white,
                  foregroundColor: HeyBeanTheme.accent,
                ),
                icon: const Icon(Icons.check_rounded),
                label: const Text('Mark complete'),
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
    title: const Text('Report an issue'),
    content: Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text('Tell us what happened so we can fix it quickly.'),
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
            style: const TextStyle(
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
        child: const Text('Cancel'),
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
            : const Icon(Icons.send_rounded),
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
    title: const Text('Thank you for helping improve HeyBean!'),
    content: const Text(
      "We've received your feedback and will fix any issues ASAP!",
      textAlign: TextAlign.center,
    ),
    actionsAlignment: MainAxisAlignment.center,
    actions: [
      FilledButton(
        key: const Key('beta-feedback-thanks-done'),
        onPressed: () => Navigator.of(context).pop(),
        child: const Text('Done'),
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
          style: const TextStyle(
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
              child: const Text('Skip'),
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
                const Flexible(
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

class _SignedInBottomDock extends StatelessWidget {
  const _SignedInBottomDock({
    required this.showComposer,
    required this.composer,
    required this.menu,
  });

  final bool showComposer;
  final Widget composer;
  final Widget menu;

  @override
  Widget build(BuildContext context) {
    if (!showComposer) return menu;
    final bottomInset = MediaQuery.paddingOf(context).bottom;
    final dockBottomPadding = bottomInset > 0 ? bottomInset + 2 : 6.0;
    final menuHeight = 78.0 + dockBottomPadding;

    return SizedBox(
      key: const Key('signed-in-bottom-dock'),
      height:
          _beanChatComposerReservedHeight +
          menuHeight -
          _beanBottomMenuSurfaceInset,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Positioned(left: 0, right: 0, bottom: 0, child: menu),
          Positioned(
            key: const Key('bean-chat-composer-dock'),
            left: 0,
            right: 0,
            bottom: menuHeight - _beanBottomMenuSurfaceInset,
            child: Align(
              alignment: Alignment.bottomCenter,
              child: ConstrainedBox(
                constraints: const BoxConstraints(
                  minHeight: _beanChatComposerReservedHeight,
                  maxHeight: _beanChatComposerMaxHeight,
                ),
                child: composer,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _HeyBeanBottomMenu extends StatelessWidget {
  const _HeyBeanBottomMenu({
    required this.selected,
    required this.onSelected,
    required this.beanListening,
    required this.beanWorkItems,
    required this.beanWorkStatus,
    required this.beanWorkActive,
    this.statusLift = 0,
    required this.onMorePressed,
    required this.onBeanLongPressStart,
    required this.onBeanLongPressEnd,
  });

  final _HomeDestination selected;
  final ValueChanged<_HomeDestination> onSelected;
  final bool beanListening;
  final List<_BeanWorkItem> beanWorkItems;
  final String beanWorkStatus;
  final bool beanWorkActive;
  final double statusLift;
  final VoidCallback onMorePressed;
  final VoidCallback onBeanLongPressStart;
  final VoidCallback onBeanLongPressEnd;

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.paddingOf(context).bottom;
    final dockBottomPadding = bottomInset > 0 ? bottomInset + 2 : 6.0;

    return SizedBox(
      key: const Key('heybean-bottom-menu'),
      height: 78 + dockBottomPadding,
      child: Stack(
        alignment: Alignment.topCenter,
        clipBehavior: Clip.none,
        children: [
          Positioned.fill(
            top: _beanBottomMenuSurfaceInset,
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: HeyBeanTheme.surface.withValues(alpha: .94),
                border: const Border(
                  top: BorderSide(color: HeyBeanTheme.border),
                ),
                boxShadow: const [
                  BoxShadow(
                    color: Color(0x1A020617),
                    blurRadius: 18,
                    offset: Offset(0, -4),
                  ),
                ],
              ),
              child: Padding(
                padding: EdgeInsets.fromLTRB(10, 7, 10, dockBottomPadding),
                child: Row(
                  children: [
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-tasks'),
                        icon: Icons.task_alt_rounded,
                        label: 'Tasks',
                        selected: selected == _HomeDestination.tasks,
                        onPressed: () => onSelected(_HomeDestination.tasks),
                      ),
                    ),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-reminders'),
                        icon: Icons.notifications_active_rounded,
                        label: 'Reminders',
                        selected: selected == _HomeDestination.reminders,
                        onPressed: () => onSelected(_HomeDestination.reminders),
                      ),
                    ),
                    const SizedBox(width: 96),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-notes'),
                        iconWidget: _BeanNotesIcon(
                          size: 24,
                          color: selected == _HomeDestination.notes
                              ? HeyBeanTheme.accentStrong
                              : HeyBeanTheme.muted,
                        ),
                        label: 'Notes',
                        selected: selected == _HomeDestination.notes,
                        onPressed: () => onSelected(_HomeDestination.notes),
                      ),
                    ),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-more'),
                        icon: Icons.more_horiz_rounded,
                        label: 'More',
                        selected:
                            selected == _HomeDestination.settings ||
                            selected == _HomeDestination.memory,
                        onPressed: onMorePressed,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Positioned(
            top: 9,
            child: _BeanFab(
              selected: selected == _HomeDestination.bean,
              listening: beanListening,
              onPressed: () => onSelected(_HomeDestination.bean),
              onLongPressStart: onBeanLongPressStart,
              onLongPressEnd: onBeanLongPressEnd,
            ),
          ),
          Positioned(
            bottom: 86 + dockBottomPadding + statusLift,
            child: AnimatedSwitcher(
              duration: const Duration(milliseconds: 180),
              switchInCurve: Curves.easeOut,
              switchOutCurve: Curves.easeOut,
              transitionBuilder: (child, animation) => FadeTransition(
                opacity: animation,
                child: SlideTransition(
                  position: Tween<Offset>(
                    begin: const Offset(0, .08),
                    end: Offset.zero,
                  ).animate(animation),
                  child: child,
                ),
              ),
              child: beanWorkActive
                  ? _BeanWorkStatusTag(
                      key: const Key('bean-work-status-tag'),
                      status: beanWorkStatus,
                      items: beanWorkItems,
                    )
                  : const SizedBox(
                      key: Key('bean-work-status-tag-empty'),
                      width: 1,
                      height: 1,
                    ),
            ),
          ),
        ],
      ),
    );
  }
}

class _BeanWorkStatusTag extends StatelessWidget {
  const _BeanWorkStatusTag({
    super.key,
    required this.status,
    required this.items,
  });

  final String status;
  final List<_BeanWorkItem> items;

  @override
  Widget build(BuildContext context) {
    final title = _compactBeanStatusLabel(status);
    final displayItems = items.take(6).toList();
    final completed = displayItems.where((item) => item.done).length;

    return ConstrainedBox(
      constraints: BoxConstraints(
        maxWidth: math.min(MediaQuery.sizeOf(context).width - 32, 330.0),
      ),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: HeyBeanTheme.surface.withValues(alpha: .96),
          border: Border.all(color: HeyBeanTheme.accent.withValues(alpha: .26)),
          borderRadius: BorderRadius.circular(16),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: .14),
              blurRadius: 26,
              offset: const Offset(0, 12),
            ),
          ],
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    width: 8,
                    height: 8,
                    decoration: BoxDecoration(
                      color: HeyBeanTheme.accentStrong,
                      borderRadius: BorderRadius.circular(999),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Flexible(
                    child: Text(
                      title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: HeyBeanTheme.accentStrong,
                        fontSize: 12,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ),
                  if (displayItems.isNotEmpty) ...[
                    const SizedBox(width: 8),
                    Text(
                      '$completed/${displayItems.length}',
                      style: const TextStyle(
                        color: HeyBeanTheme.muted,
                        fontSize: 10,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ],
                ],
              ),
              if (displayItems.isNotEmpty) const SizedBox(height: 7),
              for (final item in displayItems)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(
                        item.done
                            ? Icons.check_box_rounded
                            : Icons.check_box_outline_blank_rounded,
                        size: 17,
                        color: item.done
                            ? HeyBeanTheme.accentStrong
                            : HeyBeanTheme.muted,
                      ),
                      const SizedBox(width: 7),
                      Flexible(
                        child: Text(
                          item.label,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: item.done
                                ? HeyBeanTheme.muted
                                : HeyBeanTheme.text,
                            fontSize: 12,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
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
}

class _BeanResponsePreviewTag extends StatefulWidget {
  const _BeanResponsePreviewTag({
    super.key,
    required this.text,
    this.onHoldStart,
    this.onHoldEnd,
    this.onDismissed,
  });

  final String text;
  final VoidCallback? onHoldStart;
  final VoidCallback? onHoldEnd;
  final VoidCallback? onDismissed;

  @override
  State<_BeanResponsePreviewTag> createState() =>
      _BeanResponsePreviewTagState();
}

class _BeanResponsePreviewTagState extends State<_BeanResponsePreviewTag> {
  static const double _dismissDistance = 48;
  Offset _dragOffset = Offset.zero;
  bool _dismissed = false;

  void _holdStart() => widget.onHoldStart?.call();

  void _holdEnd() {
    if (!_dismissed) widget.onHoldEnd?.call();
  }

  void _dismiss() {
    if (_dismissed) return;
    _dismissed = true;
    widget.onDismissed?.call();
  }

  bool _shouldDismiss(DragEndDetails details) {
    final velocity = details.velocity.pixelsPerSecond;
    return _dragOffset.dx.abs() >= _dismissDistance ||
        _dragOffset.dy >= _dismissDistance ||
        velocity.dx.abs() >= 450 ||
        velocity.dy >= 450;
  }

  @override
  Widget build(BuildContext context) => Listener(
    onPointerDown: (_) => _holdStart(),
    onPointerUp: (_) => _holdEnd(),
    onPointerCancel: (_) => _holdEnd(),
    child: GestureDetector(
      behavior: HitTestBehavior.opaque,
      onPanStart: (_) => _holdStart(),
      onPanUpdate: (details) {
        setState(() {
          _dragOffset += details.delta;
          if (_dragOffset.dy < 0) {
            _dragOffset = Offset(_dragOffset.dx, 0);
          }
        });
      },
      onPanEnd: (details) {
        if (_shouldDismiss(details)) {
          _dismiss();
          return;
        }
        setState(() => _dragOffset = Offset.zero);
        _holdEnd();
      },
      onPanCancel: () {
        setState(() => _dragOffset = Offset.zero);
        _holdEnd();
      },
      child: AnimatedSlide(
        duration: const Duration(milliseconds: 140),
        curve: Curves.easeOut,
        offset: Offset(_dragOffset.dx / 240, _dragOffset.dy / 160),
        child: AnimatedOpacity(
          duration: const Duration(milliseconds: 140),
          opacity: (1 - (_dragOffset.distance / 160)).clamp(.45, 1.0),
          child: ConstrainedBox(
            constraints: BoxConstraints(
              maxWidth: math.min(MediaQuery.sizeOf(context).width - 32, 360.0),
            ),
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: HeyBeanTheme.surface.withValues(alpha: .97),
                border: Border.all(
                  color: HeyBeanTheme.accent.withValues(alpha: .34),
                ),
                borderRadius: BorderRadius.circular(18),
                boxShadow: [
                  BoxShadow(
                    color: HeyBeanTheme.accent.withValues(alpha: .22),
                    blurRadius: 30,
                    offset: const Offset(0, 12),
                  ),
                  BoxShadow(
                    color: Colors.black.withValues(alpha: .10),
                    blurRadius: 18,
                    offset: const Offset(0, 8),
                  ),
                ],
              ),
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 10,
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: 8,
                      height: 8,
                      margin: const EdgeInsets.only(top: 5),
                      decoration: BoxDecoration(
                        color: HeyBeanTheme.accentStrong,
                        borderRadius: BorderRadius.circular(999),
                        boxShadow: [
                          BoxShadow(
                            color: HeyBeanTheme.accent.withValues(alpha: .45),
                            blurRadius: 12,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 9),
                    Flexible(
                      child: Text(
                        widget.text,
                        maxLines: 3,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: HeyBeanTheme.text,
                          fontSize: 12,
                          fontWeight: FontWeight.w800,
                          height: 1.25,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    ),
  );
}

class _MenuIconButton extends StatelessWidget {
  const _MenuIconButton({
    super.key,
    this.icon,
    this.iconWidget,
    required this.label,
    required this.onPressed,
    this.selected = false,
  });

  final IconData? icon;
  final Widget? iconWidget;
  final String label;
  final VoidCallback onPressed;
  final bool selected;

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.transparent,
    child: InkWell(
      borderRadius: BorderRadius.circular(999),
      onTap: onPressed,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 1),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          mainAxisSize: MainAxisSize.min,
          children: [
            iconWidget ??
                Icon(
                  icon,
                  color: selected
                      ? HeyBeanTheme.accentStrong
                      : HeyBeanTheme.muted,
                  size: 24,
                ),
            const SizedBox(height: 3),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: selected
                    ? HeyBeanTheme.accentStrong
                    : HeyBeanTheme.muted,
                fontSize: 10,
                fontWeight: selected ? FontWeight.w800 : FontWeight.w600,
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _BeanFab extends StatefulWidget {
  const _BeanFab({
    required this.selected,
    required this.listening,
    required this.onPressed,
    required this.onLongPressStart,
    required this.onLongPressEnd,
  });

  final bool selected;
  final bool listening;
  final VoidCallback onPressed;
  final VoidCallback onLongPressStart;
  final VoidCallback onLongPressEnd;

  @override
  State<_BeanFab> createState() => _BeanFabState();
}

class _BeanFabState extends State<_BeanFab>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulseController;
  bool _pressRecording = false;

  @override
  void initState() {
    super.initState();
    _pulseController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 950),
    );
    _syncPulseAnimation();
  }

  @override
  void didUpdateWidget(covariant _BeanFab oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.listening != widget.listening) {
      _syncPulseAnimation();
    }
  }

  void _syncPulseAnimation() {
    if (widget.listening) {
      _pulseController.repeat(reverse: true);
    } else {
      _pulseController.stop();
      _pulseController.value = 0;
    }
  }

  @override
  void dispose() {
    _pulseController.dispose();
    super.dispose();
  }

  void _beginPressRecording() {
    if (_pressRecording) return;
    _pressRecording = true;
    widget.onLongPressStart();
  }

  void _endPressRecording() {
    if (!_pressRecording) return;
    _pressRecording = false;
    widget.onLongPressEnd();
  }

  @override
  Widget build(BuildContext context) {
    final activeColor = HeyBeanTheme.accentStrong;
    return GestureDetector(
      key: const Key('nav-bean'),
      onTap: widget.onPressed,
      onLongPressStart: (_) => _beginPressRecording(),
      onLongPressEnd: (_) => _endPressRecording(),
      onLongPressCancel: _endPressRecording,
      child: SizedBox(
        width: 98,
        height: 98,
        child: Stack(
          alignment: Alignment.center,
          clipBehavior: Clip.none,
          children: [
            if (widget.listening)
              AnimatedBuilder(
                key: const Key('heybean-recording-pulse'),
                animation: _pulseController,
                builder: (context, child) {
                  final pulse = Curves.easeInOut.transform(
                    _pulseController.value,
                  );
                  return Container(
                    width: 82 + (pulse * 18),
                    height: 82 + (pulse * 18),
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: activeColor.withValues(alpha: .06 + (pulse * .06)),
                      boxShadow: [
                        BoxShadow(
                          color: activeColor.withValues(
                            alpha: .12 + (pulse * .08),
                          ),
                          blurRadius: 24 + (pulse * 18),
                          spreadRadius: 5 + (pulse * 8),
                        ),
                      ],
                    ),
                  );
                },
              ),
            Material(
              color: Colors.transparent,
              child: Ink(
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 180),
                  key: const Key('heybean-center-bean-button'),
                  width: 64,
                  height: 64,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: HeyBeanTheme.surface,
                    border: Border.all(
                      color: widget.listening || widget.selected
                          ? activeColor
                          : const Color(0xFFE2E8F0),
                      width: widget.listening ? 4 : 2.5,
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: widget.listening
                            ? activeColor.withValues(alpha: .18)
                            : Colors.black.withValues(alpha: .14),
                        blurRadius: widget.listening ? 32 : 22,
                        spreadRadius: widget.listening ? 3 : 0,
                        offset: const Offset(0, 10),
                      ),
                    ],
                  ),
                  child: Center(
                    child: Image.asset(
                      'assets/images/bean/bean-logo.png',
                      key: const Key('heybean-center-bean-logo'),
                      width: 38,
                      height: 38,
                      fit: BoxFit.contain,
                      semanticLabel: 'Bean chat',
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
