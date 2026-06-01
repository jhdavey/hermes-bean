import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math' as math;

import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import 'bean_realtime_conversation.dart';
import 'hermes_api_client.dart';

typedef ExternalUrlLauncher = Future<bool> Function(Uri url);
typedef AppIconBadgeUpdater = Future<void> Function(int count);

const MethodChannel _heyBeanPlatformChannel = MethodChannel('heybean/platform');
final Uri _privacyPolicyUrl = Uri.parse('https://heybean.org/privacy');
final Uri _termsOfServiceUrl = Uri.parse('https://heybean.org/terms');
final Uri _supportUrl = Uri.parse('https://heybean.org/support');
const String _beanGreenCategoryColor = '#34C759';

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

bool _isAllowedExternalUrl(Uri url) {
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

Map<String, Object?> _flutterChatMetadata({
  Map<String, Object?> additional = const {},
}) => {
  'source': 'flutter',
  'client_context': _clientTemporalContext(),
  ...additional,
};

String beanFriendlyErrorMessage(Object error, {String? action}) {
  final prefix = action == null || action.trim().isEmpty
      ? 'Bean hit a little snag.'
      : 'Bean could not ${action.trim()}.';
  final guidance = _beanErrorGuidance(error);
  return '$prefix $guidance Don’t worry — your data is safe, and if this keeps happening we’ll fix it as soon as possible.';
}

String beanFriendlyChatFailureMessage(Object error) {
  final guidance = _beanErrorGuidance(error);
  return 'Bean could not finish that request. $guidance Please try again, or tell Bean any missing details and I’ll pick it back up. Don’t worry — if this keeps happening we’ll fix it as soon as possible.';
}

String _beanErrorGuidance(Object error) {
  if (error is HermesApiException) {
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

class HermesBeanApp extends StatelessWidget {
  HermesBeanApp({
    super.key,
    HermesApiClient? apiClient,
    AuthTokenStore? tokenStore,
    ExternalUrlLauncher? launchExternalUrl,
    AppIconBadgeUpdater? updateAppIconBadge,
    this.realtimeConversation,
  }) : apiClient = apiClient ?? HermesApiClient(),
       tokenStore = tokenStore ?? const SharedPreferencesAuthTokenStore(),
       launchExternalUrl = launchExternalUrl ?? _defaultLaunchExternalUrl,
       updateAppIconBadge = updateAppIconBadge ?? _defaultUpdateAppIconBadge;

  final HermesApiClient apiClient;
  final AuthTokenStore tokenStore;
  final ExternalUrlLauncher launchExternalUrl;
  final AppIconBadgeUpdater updateAppIconBadge;
  final BeanRealtimeConversation? realtimeConversation;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Hermes Bean',
      debugShowCheckedModeBanner: false,
      theme: HeyBeanTheme.lightTheme,
      home: CommandCenterShell(
        apiClient: apiClient,
        tokenStore: tokenStore,
        launchExternalUrl: launchExternalUrl,
        updateAppIconBadge: updateAppIconBadge,
        realtimeConversation: realtimeConversation,
      ),
    );
  }
}

class HeyBeanTheme {
  const HeyBeanTheme._();

  static const Color bg0 = Color(0xFFF8FBF6);
  static const Color bg1 = Color(0xFFF1F7EE);
  static const Color bg2 = Color(0xFFEAF2E6);
  static const Color surface = Color(0xFFFFFFFF);
  static const Color surface2 = Color(0xFFF6FAF4);
  static const Color text = Color(0xFF1F2937);
  static const Color muted = Color(0xFF64748B);
  static const Color border = Color(0x2694A3B8);
  static const Color borderStrong = Color(0x4D94A3B8);
  static const Color accent = Color(0xFF16A34A);
  static const Color accentStrong = Color(0xFF15803D);
  static const Color success = Color(0xFF22C55E);
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

  static ThemeData get lightTheme {
    final colorScheme =
        ColorScheme.fromSeed(
          brightness: Brightness.light,
          seedColor: accent,
        ).copyWith(
          primary: accent,
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
          borderRadius: BorderRadius.circular(18),
          side: const BorderSide(color: border),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface2,
        hintStyle: const TextStyle(color: muted),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: accent,
          foregroundColor: Colors.white,
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: text,
          side: const BorderSide(color: borderStrong),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(foregroundColor: accentStrong),
      ),
    );
  }
}

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

enum _AuthPhase { loading, signedOut, signedIn }

enum _HomeDestination { today, tasks, bean, reminders, settings }

const _dashboardChangePollInterval = Duration(seconds: 15);
const _pendingCalendarEventWriteTtl = Duration(minutes: 2);

class _DashboardSnapshot {
  const _DashboardSnapshot({
    required this.tasks,
    required this.pastTasks,
    required this.reminders,
    required this.calendar,
    required this.eventCategories,
    required this.approvals,
    required this.events,
    this.googleCalendarStatus,
  });

  final List<HermesTask> tasks;
  final List<HermesTask> pastTasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendar;
  final List<HermesEventCategory> eventCategories;
  final List<HermesApproval> approvals;
  final List<HermesActivityEvent> events;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
}

class _PendingCalendarEventWrite {
  const _PendingCalendarEventWrite({
    required this.event,
    required this.expiresAt,
    required this.workspaceId,
  });

  final HermesCalendarEvent event;
  final DateTime expiresAt;
  final int? workspaceId;
}

class CommandCenterShell extends StatefulWidget {
  const CommandCenterShell({
    super.key,
    required this.apiClient,
    required this.tokenStore,
    required this.launchExternalUrl,
    required this.updateAppIconBadge,
    this.realtimeConversation,
  });

  final HermesApiClient apiClient;
  final AuthTokenStore tokenStore;
  final ExternalUrlLauncher launchExternalUrl;
  final AppIconBadgeUpdater updateAppIconBadge;
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
  String? _loadingStatusText;
  bool _busy = false;
  String _chatRunState = 'Ready';
  int _chatRunToken = 0;
  _HomeDestination _selectedDestination = _HomeDestination.today;
  bool _showCalendarMonth = false;
  DateTime _selectedCalendarDay = _dateOnly(DateTime.now());
  int _calendarStartHour = _defaultCalendarStartHour;
  int _calendarEndHour = _defaultCalendarEndHour;
  final Set<int> _pendingTaskIds = <int>{};
  bool _forceAgentOnboarding = false;
  bool _editingAgentPreferences = false;
  bool _beanVoiceListening = false;
  String? _beanVoiceDraft;
  late final BeanRealtimeConversation _realtimeConversation;
  final Set<int> _dismissedReminderBannerIds = <int>{};
  final Set<int> _notifiedReminderIds = <int>{};
  int? _shownApprovalSheetId;
  bool _approvalSheetOpen = false;
  final _ReminderNotificationService _reminderNotifications =
      _ReminderNotificationService();
  Timer? _reminderDueTimer;
  Timer? _dashboardChangeTimer;
  bool _dashboardChangePollInFlight = false;
  int _dashboardChangePollGeneration = 0;
  int _dashboardChangeLastId = 0;
  int _dashboardRefreshGeneration = 0;
  int _dashboardDataVersion = 0;
  int _workspaceRefreshGeneration = 0;
  int? _lastScheduledAppIconBadgeCount;
  final Map<int, _DashboardSnapshot> _workspaceSnapshots = {};
  final Map<int, _PendingCalendarEventWrite> _pendingCalendarEventWrites = {};

  void _markDashboardDataMutated() {
    _dashboardDataVersion++;
    _dashboardRefreshGeneration++;
  }

  void _rememberPendingCalendarEventWrite(HermesCalendarEvent event) {
    _pendingCalendarEventWrites[event.id] = _PendingCalendarEventWrite(
      event: event,
      expiresAt: DateTime.now().add(_pendingCalendarEventWriteTtl),
      workspaceId: _activeWorkspaceId(),
    );
  }

  void _forgetPendingCalendarEventWrite(int eventId) {
    _pendingCalendarEventWrites.remove(eventId);
  }

  List<HermesCalendarEvent> _calendarEventsWithPendingWrites(
    List<HermesCalendarEvent> events,
  ) {
    if (_pendingCalendarEventWrites.isEmpty) return events;

    final now = DateTime.now();
    final activeWorkspaceId = _activeWorkspaceId();
    final merged = List<HermesCalendarEvent>.from(events);
    final indexById = <int, int>{
      for (var index = 0; index < merged.length; index++)
        merged[index].id: index,
    };

    for (final entry in _pendingCalendarEventWrites.entries.toList()) {
      final pending = entry.value;
      if (!pending.expiresAt.isAfter(now)) {
        _pendingCalendarEventWrites.remove(entry.key);
        continue;
      }
      if (pending.workspaceId != null &&
          activeWorkspaceId != null &&
          pending.workspaceId != activeWorkspaceId) {
        continue;
      }

      final index = indexById[entry.key];
      if (index == null) {
        merged.add(pending.event);
        continue;
      }

      if (_calendarEventMatchesPendingWrite(merged[index], pending.event)) {
        _pendingCalendarEventWrites.remove(entry.key);
      } else {
        merged[index] = pending.event;
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
      refreshed.color == pending.color &&
      refreshed.recurrence == pending.recurrence &&
      refreshed.isCritical == pending.isCritical;

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
              _beanVoiceDraft = role == 'user' ? text.trim() : _beanVoiceDraft;
            });
          },
          onRunQueued: (runId) {
            if (!mounted) return;
            setState(
              () => _chatRunState = 'Bean is working in the background...',
            );
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
    _reminderDueTimer?.cancel();
    _stopDashboardChangePolling();
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
      setState(() => _phase = _AuthPhase.signedOut);
      return;
    }
    await _loadSignedIn(launchedFromRememberedToken: rememberedToken != null);
  }

  bool _isInvalidTokenError(Object error) =>
      error is HermesApiException &&
      (error.statusCode == 401 || error.statusCode == 403);

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
    _workspaceRefreshGeneration++;
    setState(() {
      _phase = _AuthPhase.loading;
      _loadingStatusText = loadingStatusText;
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
      if (!mounted) return;
      setState(() {
        _user = user;
        _session = null;
        _tasks = const [];
        _pastTasks = const [];
        _reminders = const [];
        _calendar = const [];
        _eventCategories = const [];
        _approvals = const [];
        _events = const [];
        _phase = _AuthPhase.signedIn;
        _loadingStatusText = null;
      });

      final session = await recover<HermesSession?>(
        widget.apiClient.startSession(
          title: _userNeedsBeanIntroduction(user) ? 'Welcome to Bean' : 'Today',
          runtimeMode: _userNeedsBeanIntroduction(user) ? 'onboarding' : 'chat',
          workspaceId: user.activeWorkspace?.numericId,
          metadata: _flutterChatMetadata(),
        ),
        null,
      );
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
      final listedTasks = results[1] as List<HermesTask>;
      final listedReminders = results[2] as List<HermesReminder>;
      final listedCalendarEvents = _calendarEventsWithPendingWrites(
        results[3] as List<HermesCalendarEvent>,
      );
      if (!mounted) return;
      setState(() {
        _user = user;
        _session = session;
        _tasks = listedTasks.isEmpty ? summary.tasks : listedTasks;
        _pastTasks = results[4] as List<HermesTask>;
        _eventCategories = results[5] as List<HermesEventCategory>;
        _googleCalendarStatus = googleCalendarStatus;
        _reminders = listedReminders.isEmpty
            ? summary.reminders
            : listedReminders;
        _calendar = listedCalendarEvents;
        _approvals = summary.approvals;
        _events = results[6] as List<HermesActivityEvent>;
        _phase = _AuthPhase.signedIn;
        _loadingStatusText = null;
        _error = refreshError == null
            ? null
            : 'You are signed in. ${beanFriendlyErrorMessage(refreshError!, action: 'refresh your latest data')}';
      });
      _syncReminderNotifications();
      _cacheCurrentDashboardSnapshot();
      _startDashboardChangePolling(resetCursor: true);
    } catch (error) {
      _stopDashboardChangePolling();
      if (!mounted) return;
      final invalidToken = _isInvalidTokenError(error);
      if (invalidToken) {
        await widget.tokenStore.clearToken();
        widget.apiClient.bearerToken = null;
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
        _eventCategories = const [];
        _googleCalendarStatus = null;
        _approvals = const [];
        _events = const [];
        _phase = _AuthPhase.signedOut;
        _loadingStatusText = null;
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
    });
    try {
      final auth = await widget.apiClient.register(
        name: name,
        email: email,
        password: password,
      );
      await widget.tokenStore.saveRememberMe(true);
      await widget.tokenStore.saveToken(auth.token);
      await _loadSignedIn(knownUser: auth.user);
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

  Future<void> _requestPasswordReset(String email) async {
    await widget.apiClient.requestPasswordReset(email: email);
  }

  Future<void> _completeAgentOnboarding({
    required String agentPersonality,
    required List<String> onboardingPriorities,
    String? onboardingContext,
  }) async {
    final wasEditingAgentPreferences = _editingAgentPreferences;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final updatedUser = await widget.apiClient.updateMe(
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
    const prompt =
        'Hi, I’m Bean. Start by introducing yourself — tell me what you want help with, what matters most day to day, and how you’d like me to work with you.';
    final alreadyPrompted = _messages.any(
      (message) => message.role == 'assistant' && message.content == prompt,
    );
    if (!alreadyPrompted) {
      _messages.add(
        HermesMessage(
          id: _messages.length + 1,
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

  Future<void> _startNewChatSession() async {
    setState(() {
      _busy = true;
      _chatRunState = 'Starting new session…';
      _error = null;
    });
    try {
      final session = await widget.apiClient.startSession(
        title: _needsBeanIntroduction ? 'Welcome to Bean' : 'New chat',
        runtimeMode: _needsBeanIntroduction ? 'onboarding' : 'chat',
        workspaceId: _user?.activeWorkspace?.numericId,
        metadata: _flutterChatMetadata(),
      );
      final events = await widget.apiClient
          .pollActivityEvents(session.id)
          .catchError((_) => const <HermesActivityEvent>[]);
      if (!mounted) return;
      setState(() {
        _session = session;
        _messages
          ..clear()
          ..add(
            const HermesMessage(
              id: 0,
              role: 'assistant',
              content: 'New chat started. What should Bean handle next?',
            ),
          );
        _events = events;
        _chatRunState = 'Ready';
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _chatRunState = 'Failed';
        _error = beanFriendlyErrorMessage(error, action: 'start a new chat');
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _startBeanVoiceDraft() async {
    if (_busy || _beanVoiceListening) return;
    setState(() {
      _selectedDestination = _HomeDestination.bean;
      _beanVoiceListening = true;
      _beanVoiceDraft = '';
      _error = null;
      _chatRunState = 'Starting realtime voice...';
    });

    try {
      final realtimeSession = await _realtimeConversation.start(
        workspaceId: _user?.activeWorkspace?.numericId,
        metadata: _flutterChatMetadata(),
      );
      if (!mounted || !_beanVoiceListening) return;
      _realtimeConversation.setMicrophoneEnabled(true);
      setState(() {
        _session = realtimeSession;
        _chatRunState = 'Listening...';
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _beanVoiceListening = false;
        _beanVoiceDraft = null;
        _chatRunState = 'Ready';
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

  Future<void> _stopAgent() async {
    final session = _session;
    if (_beanVoiceListening) {
      await _realtimeConversation.stop();
      if (!mounted) return;
      setState(() {
        _beanVoiceListening = false;
        _beanVoiceDraft = null;
        _chatRunState = 'Ready';
      });
      return;
    }

    if (!_busy) return;
    _chatRunToken++;
    if (mounted) {
      setState(() {
        _busy = false;
        _chatRunState = 'Stopped';
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
    final typedDraft = (_beanVoiceDraft ?? '').trim();
    _realtimeConversation.setMicrophoneEnabled(false);
    setState(() {
      _chatRunState = typedDraft.isEmpty ? 'Listening' : 'Heard: $typedDraft';
    });
  }

  Future<void> _sendChat(String content) async {
    final trimmed = content.trim();
    final session = _session;
    if (trimmed.isEmpty || session == null) return;
    final runToken = ++_chatRunToken;
    setState(() {
      _busy = true;
      _chatRunState = 'Bean is working…';
      _messages.add(
        HermesMessage(id: _messages.length + 1, role: 'user', content: trimmed),
      );
    });
    try {
      final result = await widget.apiClient.queueMessage(
        sessionId: session.id,
        content: trimmed,
        metadata: _flutterChatMetadata(),
      );
      if (!mounted || runToken != _chatRunToken) return;
      if (result.status == 'queued') {
        setState(() {
          _session = result.session;
          _chatRunState = 'Bean is working in the background...';
          _events = _mergeEvents(result.events, _events);
          _messages.add(
            HermesMessage(
              id: _messages.length + 1,
              role: 'assistant',
              content: 'I’m working on that in the background.',
            ),
          );
        });
        final run = result.run;
        if (run != null) unawaited(_pollQueuedRun(run.id, runToken));
        return;
      }

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
      setState(() {
        _user = refreshedUser;
        _session = result.session;
        if (result.status == 'cancelled') {
          _chatRunState = 'Stopped';
        } else if (result.assistantMessage != null) {
          _messages.add(_displayableAssistantMessage(result.assistantMessage!));
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
        _tasks = refreshedTasks;
        _reminders = refreshedSummary.reminders;
        _calendar = refreshedCalendar;
        _approvals = refreshedSummary.approvals;
        _events = _mergeEvents(result.events, refreshedEvents);
      });
    } catch (error) {
      if (!mounted || runToken != _chatRunToken) return;
      setState(() {
        _chatRunState = 'Failed';
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

  Future<void> _pollQueuedRun(int runId, int runToken) async {
    for (var attempt = 0; attempt < 30; attempt++) {
      await Future<void>.delayed(const Duration(seconds: 2));
      if (!mounted || runToken != _chatRunToken) return;
      try {
        final run = await widget.apiClient.getAssistantRun(runId);
        if (!mounted || runToken != _chatRunToken) return;
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
    setState(() {
      _selectedCalendarDay = _dateOnly(date);
      _showCalendarMonth = false;
    });
  }

  void _selectCalendarMonth(DateTime month) {
    final selected = _dateOnly(_selectedCalendarDay);
    final daysInTargetMonth = DateTime(month.year, month.month + 1, 0).day;
    setState(() {
      _selectedCalendarDay = DateTime(
        month.year,
        month.month,
        selected.day.clamp(1, daysInTargetMonth),
      );
      _showCalendarMonth = true;
    });
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
    final previousLatestId = _dashboardChangeLastId;
    try {
      final feed = await widget.apiClient.dashboardChanges(
        after: previousLatestId,
      );
      if (!mounted ||
          _phase != _AuthPhase.signedIn ||
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
        widget.apiClient.listPastTasks().catchError(
          (_) => const <HermesTask>[],
        ),
        widget.apiClient.listEventCategories().catchError(
          (_) => const <HermesEventCategory>[],
        ),
        widget.apiClient.pollActivityEvents(session.id),
      ]);
      final summary = results[0] as HermesTodaySummary;
      final listedTasks = results[1] as List<HermesTask>;
      final listedReminders = results[2] as List<HermesReminder>;
      final listedCalendarEvents = _calendarEventsWithPendingWrites(
        results[3] as List<HermesCalendarEvent>,
      );
      if (!mounted ||
          refreshGeneration != _dashboardRefreshGeneration ||
          dataVersion != _dashboardDataVersion) {
        return;
      }
      setState(() {
        _tasks = listedTasks.isEmpty ? summary.tasks : listedTasks;
        _pastTasks = results[4] as List<HermesTask>;
        _eventCategories = results[5] as List<HermesEventCategory>;
        _googleCalendarStatus = googleCalendarStatus;
        _reminders = listedReminders.isEmpty
            ? summary.reminders
            : listedReminders;
        _calendar = listedCalendarEvents;
        _approvals = summary.approvals;
        _events = results[6] as List<HermesActivityEvent>;
        _error = null;
      });
      _syncReminderNotifications();
      _cacheCurrentDashboardSnapshot();
    } catch (error) {
      if (!mounted) return;
      setState(
        () => _error = beanFriendlyErrorMessage(
          error,
          action: 'refresh your latest data',
        ),
      );
    }
  }

  Future<void> _refreshWorkspaceDataFromServer({
    bool syncConnectedCalendar = false,
    String errorAction = 'refresh your latest data',
  }) async {
    if (_phase != _AuthPhase.signedIn) return;
    final generation = ++_workspaceRefreshGeneration;
    final refreshGeneration = ++_dashboardRefreshGeneration;
    final dataVersion = _dashboardDataVersion;
    try {
      final user = await widget.apiClient.me();
      final session = await widget.apiClient.startSession(
        title: _userNeedsBeanIntroduction(user)
            ? 'Welcome to Bean'
            : 'Workspace chat',
        runtimeMode: _userNeedsBeanIntroduction(user) ? 'onboarding' : 'chat',
        workspaceId: user.activeWorkspace?.numericId,
        metadata: {'source': 'flutter', 'reason': 'workspace_refresh'},
      );
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
        widget.apiClient.listPastTasks().catchError(
          (_) => const <HermesTask>[],
        ),
        widget.apiClient.listEventCategories().catchError(
          (_) => const <HermesEventCategory>[],
        ),
        widget.apiClient
            .pollActivityEvents(session.id)
            .catchError((_) => const <HermesActivityEvent>[]),
      ]);
      final summary = results[0] as HermesTodaySummary;
      final listedTasks = results[1] as List<HermesTask>;
      final listedReminders = results[2] as List<HermesReminder>;
      final listedCalendarEvents = _calendarEventsWithPendingWrites(
        results[3] as List<HermesCalendarEvent>,
      );
      if (!mounted ||
          _phase != _AuthPhase.signedIn ||
          generation != _workspaceRefreshGeneration ||
          refreshGeneration != _dashboardRefreshGeneration ||
          dataVersion != _dashboardDataVersion) {
        return;
      }
      setState(() {
        _user = user;
        _session = session;
        _tasks = listedTasks.isEmpty ? summary.tasks : listedTasks;
        _pastTasks = results[4] as List<HermesTask>;
        _eventCategories = results[5] as List<HermesEventCategory>;
        _googleCalendarStatus = googleCalendarStatus;
        _reminders = listedReminders.isEmpty
            ? summary.reminders
            : listedReminders;
        _calendar = listedCalendarEvents;
        _approvals = summary.approvals;
        _events = results[6] as List<HermesActivityEvent>;
        _error = null;
      });
      _syncReminderNotifications();
      _cacheCurrentDashboardSnapshot();
    } catch (error) {
      if (!mounted ||
          _phase != _AuthPhase.signedIn ||
          generation != _workspaceRefreshGeneration) {
        return;
      }
      setState(
        () => _error = beanFriendlyErrorMessage(error, action: errorAction),
      );
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

    try {
      final updatedTask = wasCompleted
          ? await widget.apiClient.reopenTask(task.id)
          : await widget.apiClient.completeTask(task.id);
      if (!mounted) return;
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
      await _refreshSignedInViews();
    } catch (error) {
      if (!mounted) return;
      _markDashboardDataMutated();
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
    Map<String, Object?>? recurrenceMetadata,
    List<Object> syncToWorkspaceIds = const [],
    List<String> googleCalendarIds = const [],
  }) async {
    final normalizedDueAt = _taskReminderInputToWireValue(dueAt);
    final normalizedColor = category == null ? _beanGreenCategoryColor : color;
    final metadata = <String, Object?>{
      ...?task?.metadata,
      ...?recurrenceMetadata,
      if (googleCalendarIds.isNotEmpty || task != null)
        'google_calendar_ids': googleCalendarIds,
      if (parentTaskId != null || task?.parentTaskId != null)
        'parent_task_id': parentTaskId ?? task!.parentTaskId,
    };
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
              workspaceId: _user?.activeWorkspace?.numericId,
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
      if (!mounted) return;
      _markDashboardDataMutated();
      setState(() {
        final exists = _tasks.any((item) => item.id == saved.id);
        _tasks = exists
            ? _tasks.map((item) => item.id == saved.id ? saved : item).toList()
            : [..._tasks, saved];
        _error = null;
      });
      _cacheCurrentDashboardSnapshot();
      await _refreshSignedInViews();
    } catch (error) {
      if (mounted) {
        setState(
          () => _error = beanFriendlyErrorMessage(
            error,
            action: 'save that task',
          ),
        );
      }
    }
  }

  Future<void> _showNewTaskEditor() async {
    final result = await _showTitleTimeEditor(
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
      googleCalendarStatus: _googleCalendarStatus,
      showRecurrence: true,
      recurrenceTitle: 'Task recurrence',
      recurrenceSubtitle: 'Repeat this task when needed.',
      recurrenceInfoTitle: 'Task recurrence',
    );
    if (result == null) return;
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

  Future<void> _showNewReminderEditor() async {
    final result = await _showTitleTimeEditor(
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
      googleCalendarStatus: _googleCalendarStatus,
      showRecurrence: true,
      recurrenceTitle: 'Reminder repeats',
      recurrenceSubtitle: 'Repeat this reminder when needed.',
      recurrenceInfoTitle: 'Reminder recurrence',
    );
    if (result == null) return;
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

  Future<void> _deleteTask(
    HermesTask task, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    final previousTasks = _tasks;
    _markDashboardDataMutated();
    setState(() => _tasks = _removeTask(_tasks, task.id));
    try {
      await widget.apiClient.deleteTask(
        task.id,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
      );
      _cacheCurrentDashboardSnapshot();
      await _refreshSignedInViews();
    } catch (error) {
      if (mounted) {
        _markDashboardDataMutated();
        setState(() {
          _tasks = previousTasks;
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
    Map<String, Object?>? recurrenceMetadata,
    List<Object> syncToWorkspaceIds = const [],
    List<String> googleCalendarIds = const [],
  }) async {
    final normalizedRemindAt = _taskReminderInputToWireValue(remindAt);
    if (normalizedRemindAt == null) {
      if (mounted) setState(() => _error = 'Reminder time is required.');
      return;
    }
    final normalizedColor = category == null ? _beanGreenCategoryColor : color;
    final metadata = <String, Object?>{
      ...?reminder?.metadata,
      ...?recurrenceMetadata,
      if (googleCalendarIds.isNotEmpty || reminder != null)
        'google_calendar_ids': googleCalendarIds,
    };
    try {
      final saved = reminder == null
          ? await widget.apiClient.createReminder(
              title: title,
              remindAt: normalizedRemindAt,
              status: status,
              category: category,
              color: normalizedColor,
              metadata: metadata.isEmpty ? null : metadata,
              workspaceId: _user?.activeWorkspace?.numericId,
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
      if (!mounted) return;
      _markDashboardDataMutated();
      setState(() {
        final exists = _reminders.any((item) => item.id == saved.id);
        _reminders = exists
            ? _reminders
                  .map((item) => item.id == saved.id ? saved : item)
                  .toList()
            : [..._reminders, saved];
        _error = null;
      });
      _cacheCurrentDashboardSnapshot();
      await _refreshSignedInViews();
    } catch (error) {
      if (mounted) {
        setState(
          () => _error = beanFriendlyErrorMessage(
            error,
            action: 'save that reminder',
          ),
        );
      }
    }
  }

  Future<void> _toggleReminderCompletion(HermesReminder reminder) async {
    final previousReminders = _reminders;
    final completed = _reminderIsCompleted(reminder);
    final updatedStatus = completed ? 'pending' : 'completed';
    final optimisticReminder = reminder.copyWith(status: updatedStatus);
    _markDashboardDataMutated();
    setState(() {
      _reminders = _reminders
          .map((item) => item.id == reminder.id ? optimisticReminder : item)
          .toList();
      _error = null;
    });
    try {
      final saved = await widget.apiClient.updateReminder(
        reminder.id,
        status: updatedStatus,
      );
      if (!mounted) return;
      _markDashboardDataMutated();
      setState(() {
        _reminders = _reminders
            .map((item) => item.id == saved.id ? saved : item)
            .toList();
      });
      _cacheCurrentDashboardSnapshot();
      await _refreshSignedInViews();
    } catch (error) {
      if (!mounted) return;
      _markDashboardDataMutated();
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
    setState(
      () => _reminders = _reminders
          .where((item) => item.id != reminder.id)
          .toList(),
    );
    try {
      await widget.apiClient.deleteReminder(
        reminder.id,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
      );
      _cacheCurrentDashboardSnapshot();
      await _refreshSignedInViews();
    } catch (error) {
      if (mounted) {
        _markDashboardDataMutated();
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
                    color: _beanGreenCategoryColor,
                  )
                : event,
          )
          .toList();
      _tasks = _tasks
          .map(
            (task) => task.category == category.name
                ? task.copyWith(
                    clearCategory: true,
                    color: _beanGreenCategoryColor,
                  )
                : task,
          )
          .toList();
      _reminders = _reminders
          .map(
            (reminder) => reminder.category == category.name
                ? reminder.copyWith(
                    clearCategory: true,
                    color: _beanGreenCategoryColor,
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
    List<Object> syncToWorkspaceIds = const [],
  }) async {
    final wireStartsAt = _calendarEventWireValueToUtcIso(startsAt) ?? startsAt;
    final wireEndsAt = _calendarEventWireValueToUtcIso(endsAt);
    final normalizedColor = category == null ? _beanGreenCategoryColor : color;
    try {
      final createdEvent = await widget.apiClient.createCalendarEvent(
        title: title,
        startsAt: wireStartsAt,
        endsAt: wireEndsAt,
        category: category,
        color: normalizedColor,
        recurrence: recurrence,
        metadata: metadata,
        isCritical: isCritical ?? false,
        workspaceId: _user?.activeWorkspace?.numericId,
        syncToWorkspaceIds: syncToWorkspaceIds,
      );
      if (reminderMinutesBefore != null && reminderMinutesBefore > 0) {
        final start = _parseCalendarEventDateTime(wireStartsAt);
        if (start != null) {
          await widget.apiClient.createEventReminder(
            calendarEventId: createdEvent.id,
            title: 'Reminder: $title',
            remindAt: start
                .subtract(Duration(minutes: reminderMinutesBefore))
                .toUtc()
                .toIso8601String(),
            metadata: {
              'minutes_before': reminderMinutesBefore,
              'recurrence': reminderRecurrence ?? 'none',
              if ((reminderSpecificDays ?? const <String>[]).isNotEmpty)
                'days': reminderSpecificDays,
              if (reminderInterval != null && reminderInterval > 0)
                'interval': reminderInterval,
              if (reminderIntervalUnit != null) 'unit': reminderIntervalUnit,
            },
          );
        }
      }
      if (!mounted) return;
      _markDashboardDataMutated();
      _rememberPendingCalendarEventWrite(createdEvent);
      setState(() {
        _calendar = [..._calendar, createdEvent];
        _error = null;
      });
      _cacheCurrentDashboardSnapshot();
      await _refreshSignedInViews();
    } catch (error) {
      if (mounted) {
        setState(
          () => _error = beanFriendlyErrorMessage(
            error,
            action: 'create that calendar event',
          ),
        );
      }
    }
  }

  Future<void> _editCalendarEvent(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
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
    List<Object> syncToWorkspaceIds = const [],
  }) async {
    final wireStartsAt = _calendarEventWireValueToUtcIso(startsAt) ?? startsAt;
    final wireEndsAt = _calendarEventWireValueToUtcIso(endsAt);
    final normalizedColor = category == null ? _beanGreenCategoryColor : color;
    final previousCalendar = _calendar;
    final optimisticEvent = event.copyWith(
      title: title,
      startsAt: wireStartsAt,
      endsAt: wireEndsAt,
      category: category,
      color: normalizedColor,
      recurrence: recurrence,
      metadata: metadata,
      isCritical: isCritical ?? event.isCritical,
      clearEndsAt: wireEndsAt == null,
      clearCategory: category == null,
      clearColor: false,
      clearRecurrence: recurrence == null,
    );
    _markDashboardDataMutated();
    setState(() {
      _calendar = _calendar
          .map(
            (candidate) =>
                candidate.id == event.id ? optimisticEvent : candidate,
          )
          .toList();
      _error = null;
    });

    try {
      final updatedEvent = await widget.apiClient.updateCalendarEvent(
        event.id,
        title: title,
        startsAt: wireStartsAt,
        endsAt: wireEndsAt,
        category: category,
        color: normalizedColor,
        recurrence: recurrence,
        metadata: metadata,
        isCritical: isCritical,
        syncToWorkspaceIds: syncToWorkspaceIds,
      );
      if (reminderMinutesBefore != null && reminderMinutesBefore > 0) {
        final start = _parseCalendarEventDateTime(wireStartsAt);
        if (start != null) {
          await widget.apiClient.createEventReminder(
            calendarEventId: event.id,
            title: 'Reminder: $title',
            remindAt: start
                .subtract(Duration(minutes: reminderMinutesBefore))
                .toUtc()
                .toIso8601String(),
            metadata: {
              'minutes_before': reminderMinutesBefore,
              'recurrence': reminderRecurrence ?? 'none',
              if ((reminderSpecificDays ?? const <String>[]).isNotEmpty)
                'days': reminderSpecificDays,
              if (reminderInterval != null && reminderInterval > 0)
                'interval': reminderInterval,
              if (reminderIntervalUnit != null) 'unit': reminderIntervalUnit,
            },
          );
        }
      }
      if (!mounted) return;
      _markDashboardDataMutated();
      _rememberPendingCalendarEventWrite(updatedEvent);
      setState(() {
        _calendar = _calendar
            .map(
              (candidate) =>
                  candidate.id == event.id ? updatedEvent : candidate,
            )
            .toList();
      });
      _cacheCurrentDashboardSnapshot();
      await _refreshSignedInViews();
    } catch (error) {
      if (!mounted) return;
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
    try {
      await widget.apiClient.deleteCalendarEvent(
        event.id,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
        recurringDeleteMode: recurringDeleteMode,
        recurringOccurrenceDate: recurringOccurrenceDate,
      );
      _forgetPendingCalendarEventWrite(event.id);
      _cacheCurrentDashboardSnapshot();
      await _refreshSignedInViews();
    } catch (error) {
      if (!mounted) return;
      _markDashboardDataMutated();
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
            List<Object> syncToWorkspaceIds = const [],
          }) => _createCalendarEvent(
            title: title,
            startsAt: startsAt,
            endsAt: endsAt,
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

  Future<void> _logout() async {
    if (_busy) return;
    _stopDashboardChangePolling();
    setState(() => _busy = true);
    try {
      await widget.apiClient.logout();
    } catch (_) {
      widget.apiClient.bearerToken = null;
    } finally {
      await widget.tokenStore.clearToken();
      if (mounted) {
        setState(() {
          _busy = false;
          _phase = _AuthPhase.signedOut;
          _loadingStatusText = null;
          _user = null;
          _session = null;
          _messages.clear();
          _events = const [];
          _error = null;
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
          _user = null;
          _session = null;
          _messages.clear();
          _events = const [];
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
        _error = beanFriendlyErrorMessage(error, action: 'switch workspaces');
      });
    }
  }

  Widget _topWorkspaceSwitcher() {
    final user = _user;
    final workspaces = user?.workspaces ?? const <HermesWorkspace>[];
    if (_phase != _AuthPhase.signedIn || workspaces.isEmpty) {
      return const SizedBox.shrink();
    }
    final activeWorkspace =
        user?.activeWorkspace ??
        workspaces.firstWhere(
          (workspace) => workspace.active || workspace.isDefault,
          orElse: () => workspaces.first,
        );
    final activeLabel = _workspaceDisplayName(activeWorkspace);

    return PopupMenuButton<int>(
      key: const Key('top-workspace-switcher'),
      tooltip: 'Switch workspace',
      enabled: !_busy && workspaces.length > 1,
      onSelected: (workspaceId) {
        final workspace = workspaces.firstWhere(
          (candidate) => candidate.numericId == workspaceId,
          orElse: () => activeWorkspace,
        );
        unawaited(_switchWorkspaceFromTopBar(workspace));
      },
      itemBuilder: (context) => [
        for (final workspace in workspaces)
          PopupMenuItem<int>(
            key: Key('top-workspace-option-${workspace.id}'),
            value: workspace.numericId,
            enabled: workspace.numericId != null,
            child: Row(
              children: [
                Icon(
                  workspace.id == activeWorkspace.id
                      ? Icons.check_circle_rounded
                      : Icons.grid_view_rounded,
                  size: 18,
                  color: workspace.id == activeWorkspace.id
                      ? HeyBeanTheme.accentStrong
                      : HeyBeanTheme.muted,
                ),
                const SizedBox(width: 8),
                Flexible(
                  child: Text(
                    _workspaceDisplayName(workspace),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
          ),
      ],
      child: Container(
        constraints: const BoxConstraints(maxWidth: 150),
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
        decoration: BoxDecoration(
          color: HeyBeanTheme.surface.withValues(alpha: .76),
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: HeyBeanTheme.border),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(
              Icons.grid_view_rounded,
              size: 16,
              color: HeyBeanTheme.accentStrong,
            ),
            const SizedBox(width: 6),
            Flexible(
              child: Text(
                activeLabel,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
            const SizedBox(width: 2),
            const Icon(Icons.keyboard_arrow_down_rounded, size: 16),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final criticalItemCount = _criticalItemCountForToday();
    _scheduleAppIconBadgeSync(criticalItemCount);
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: HeyBeanTheme.lightSystemOverlayStyle,
      child: Container(
        key: const Key('heybean-background-gradient'),
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            stops: [0, .5, 1],
            colors: [HeyBeanTheme.bg0, HeyBeanTheme.bg1, HeyBeanTheme.bg2],
          ),
        ),
        child: Stack(
          children: [
            const Positioned.fill(
              key: Key('green-glow-left'),
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: RadialGradient(
                    center: Alignment(-1.12, -1.2),
                    radius: 1.1,
                    colors: [Color(0x1916A34A), Colors.transparent],
                  ),
                ),
              ),
            ),
            Scaffold(
              appBar: AppBar(
                titleSpacing: 12,
                title: null,
                actions: [
                  if (_phase == _AuthPhase.signedIn) ...[
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
                    _CalendarHeaderButton(
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
                    const SizedBox(width: 8),
                    Flexible(child: _topWorkspaceSwitcher()),
                    const SizedBox(width: 8),
                    _CriticalTaskBadge(
                      tasks: _criticalTasksForToday(_tasks),
                      reminders: _criticalRemindersForToday(_reminders),
                      events: _criticalEventsForToday(_calendar),
                    ),
                    if (_selectedDestination == _HomeDestination.today) ...[
                      const SizedBox(width: 8),
                      IconButton.filledTonal(
                        key: const Key('calendar-add-event-action'),
                        tooltip: 'Create event',
                        onPressed: _showNewCalendarEventEditor,
                        icon: const Icon(Icons.add_rounded),
                      ),
                    ],
                    if (_selectedDestination == _HomeDestination.tasks) ...[
                      const SizedBox(width: 8),
                      IconButton.filledTonal(
                        key: const Key('task-add-action'),
                        tooltip: 'Add task',
                        onPressed: _showNewTaskEditor,
                        icon: const Icon(Icons.add_rounded),
                      ),
                    ],
                    if (_selectedDestination == _HomeDestination.reminders) ...[
                      const SizedBox(width: 8),
                      IconButton.filledTonal(
                        key: const Key('reminder-add-action'),
                        tooltip: 'Add reminder',
                        onPressed: _showNewReminderEditor,
                        icon: const Icon(Icons.add_rounded),
                      ),
                    ],
                  ],
                  const SizedBox(width: 16),
                ],
              ),
              body: SafeArea(child: _body()),
              bottomNavigationBar: _phase == _AuthPhase.signedIn
                  ? _HeyBeanBottomMenu(
                      selected: _selectedDestination,
                      beanListening: _beanVoiceListening,
                      onSelected: _selectDestination,
                      onBeanLongPressStart: () =>
                          unawaited(_startBeanVoiceDraft()),
                      onBeanLongPressEnd: () =>
                          unawaited(_finishBeanVoiceDraft()),
                    )
                  : null,
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
      );
    }
    final user = _user!;
    _scheduleApprovalSheet();
    final showAgentOnboarding =
        _forceAgentOnboarding || _editingAgentPreferences;
    final showBeanIntroBubble =
        _needsBeanIntroduction &&
        _selectedDestination != _HomeDestination.bean &&
        !_editingAgentPreferences &&
        !_forceAgentOnboarding;
    final editingAgentPreferences = _editingAgentPreferences;
    final dueReminder = _dueReminderBanner();
    final signedInContent = _signedInContent(user);
    final signedInSurface = _selectedDestination == _HomeDestination.bean
        ? Padding(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
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
        if (showBeanIntroBubble)
          Positioned(
            left: 24,
            right: 24,
            bottom: 104 + MediaQuery.paddingOf(context).bottom,
            child: _BeanIntroCallout(
              onTap: () => _selectDestination(_HomeDestination.bean),
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

  Widget _signedInContent(HermesUser user) => _CommandCenterContent(
    apiClient: widget.apiClient,
    user: user,
    tasks: _tasks,
    pastTasks: _pastTasks,
    reminders: _reminders,
    calendar: _calendar,
    eventCategories: _eventCategories,
    googleCalendarStatus: _googleCalendarStatus,
    events: _events,
    messages: _messages,
    busy: _busy,
    chatRunState: _chatRunState,
    error: _error,
    selectedDestination: _selectedDestination,
    selectedCalendarDay: _selectedCalendarDay,
    showCalendarMonth: _showCalendarMonth,
    calendarStartHour: _calendarStartHour,
    calendarEndHour: _calendarEndHour,
    onCalendarDaySelected: _selectCalendarDay,
    onCalendarMonthSelected: _selectCalendarMonth,
    onBackToCalendarDay: _returnToCalendarDay,
    onCalendarStartHourChanged: _setCalendarStartHour,
    onCalendarEndHourChanged: _setCalendarEndHour,
    onSelectDestination: _selectDestination,
    onSend: _sendChat,
    onStop: _stopAgent,
    onNewChatSession: _startNewChatSession,
    beanVoiceListening: _beanVoiceListening,
    beanVoiceDraft: _beanVoiceDraft,
    onBeanVoiceDraftChanged: _updateBeanVoiceDraft,
    onTaskCompleted: _toggleTaskCompletion,
    pendingTaskIds: _pendingTaskIds,
    onTaskSaved: _createOrUpdateTask,
    onTaskDeleted: _deleteTask,
    onReminderSaved: _createOrUpdateReminder,
    onReminderCompleted: _toggleReminderCompletion,
    onReminderDeleted: _deleteReminder,
    onCalendarEventCreated: _createCalendarEvent,
    onCalendarEventEdited: _editCalendarEvent,
    onCalendarEventDeleted: _deleteCalendarEvent,
    onEventCategorySaved: _saveEventCategory,
    onEventCategoryDeleted: _deleteEventCategory,
    onDeleteAccount: _deleteAccount,
    onSignOut: _logout,
    onAccountEmailChanged: _updateAccountEmail,
    onNotificationPreferencesChanged: _updateNotificationPreferences,
    launchExternalUrl: widget.launchExternalUrl,
    onEditAgentOnboarding: () {
      setState(() {
        _editingAgentPreferences = true;
        _forceAgentOnboarding = false;
      });
    },
    onWorkspacesChanged: _reloadSignedInViewsFromSettings,
  );
}

typedef _RegisterHandler =
    Future<void> Function(String name, String email, String password);
typedef _ForgotPasswordHandler = Future<void> Function(String email);

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
                      child: const Icon(
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
    decoration: const InputDecoration(
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
    final title = _registerMode ? 'Create your Hermes Bean account' : 'Login';
    final subtitle = _registerMode
        ? 'Create your account with your email and a secure 12+ character password'
        : '';

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
                                const Icon(
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
    required this.eventCategories,
    required this.googleCalendarStatus,
    required this.events,
    required this.messages,
    required this.busy,
    required this.chatRunState,
    required this.selectedDestination,
    required this.selectedCalendarDay,
    required this.showCalendarMonth,
    required this.calendarStartHour,
    required this.calendarEndHour,
    required this.onCalendarDaySelected,
    required this.onCalendarMonthSelected,
    required this.onBackToCalendarDay,
    required this.onCalendarStartHourChanged,
    required this.onCalendarEndHourChanged,
    required this.onSelectDestination,
    required this.onSend,
    required this.onStop,
    required this.onNewChatSession,
    required this.beanVoiceListening,
    required this.beanVoiceDraft,
    required this.onBeanVoiceDraftChanged,
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
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
    required this.onDeleteAccount,
    required this.onSignOut,
    required this.onAccountEmailChanged,
    required this.onNotificationPreferencesChanged,
    required this.launchExternalUrl,
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
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final List<HermesActivityEvent> events;
  final List<HermesMessage> messages;
  final bool busy;
  final String chatRunState;
  final _HomeDestination selectedDestination;
  final DateTime selectedCalendarDay;
  final bool showCalendarMonth;
  final int calendarStartHour;
  final int calendarEndHour;
  final ValueChanged<DateTime> onCalendarDaySelected;
  final ValueChanged<DateTime> onCalendarMonthSelected;
  final VoidCallback onBackToCalendarDay;
  final ValueChanged<int> onCalendarStartHourChanged;
  final ValueChanged<int> onCalendarEndHourChanged;
  final ValueChanged<_HomeDestination> onSelectDestination;
  final Future<void> Function(String content) onSend;
  final Future<void> Function() onStop;
  final Future<void> Function() onNewChatSession;
  final bool beanVoiceListening;
  final String? beanVoiceDraft;
  final ValueChanged<String> onBeanVoiceDraftChanged;
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
    List<Object> syncToWorkspaceIds,
  })
  onCalendarEventCreated;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
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
  final Future<void> Function() onDeleteAccount;
  final Future<void> Function() onSignOut;
  final Future<void> Function(String email) onAccountEmailChanged;
  final Future<void> Function(HermesNotificationPreferences preferences)
  onNotificationPreferencesChanged;
  final ExternalUrlLauncher launchExternalUrl;
  final VoidCallback onEditAgentOnboarding;
  final Future<void> Function() onWorkspacesChanged;
  final String? error;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final activeTasks = _visibleSortedTasks(tasks);
        final selectedDayTasks = _tasksForTodayAgenda(tasks, DateTime.now());
        final beanPanel = _HeroChatCard(
          messages: messages,
          busy: busy,
          runState: chatRunState,
          events: events,
          voiceListening: beanVoiceListening,
          voiceDraft: beanVoiceDraft,
          onVoiceDraftChanged: onBeanVoiceDraftChanged,
          onNewSession: onNewChatSession,
          onSend: onSend,
          onStop: onStop,
        );
        final selectedPanel = switch (selectedDestination) {
          _HomeDestination.today => _TodayHomeView(
            user: user,
            tasks: selectedDayTasks,
            calendar: calendar,
            eventCategories: eventCategories,
            googleCalendarStatus: googleCalendarStatus,
            selectedDay: selectedCalendarDay,
            showMonth: showCalendarMonth,
            startHour: calendarStartHour,
            endHour: calendarEndHour,
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
            eventCategories: eventCategories,
            pendingTaskIds: pendingTaskIds,
            onTaskCompleted: onTaskCompleted,
            onTaskSaved: onTaskSaved,
            onTaskDeleted: onTaskDeleted,
            onEventCategorySaved: onEventCategorySaved,
            workspaces: user.workspaces,
            activeWorkspaceId: user.activeWorkspace?.id,
          ),
          _HomeDestination.bean => beanPanel,
          _HomeDestination.reminders => _ReminderListCard(
            reminders: reminders,
            eventCategories: eventCategories,
            onReminderSaved: onReminderSaved,
            onReminderCompleted: onReminderCompleted,
            onReminderDeleted: onReminderDeleted,
            onEventCategorySaved: onEventCategorySaved,
            workspaces: user.workspaces,
            activeWorkspaceId: user.activeWorkspace?.id,
          ),
          _HomeDestination.settings => _SettingsView(
            apiClient: apiClient,
            launchExternalUrl: launchExternalUrl,
            user: user,
            googleCalendarStatus: googleCalendarStatus,
            calendarStartHour: calendarStartHour,
            calendarEndHour: calendarEndHour,
            onCalendarStartHourChanged: onCalendarStartHourChanged,
            onCalendarEndHourChanged: onCalendarEndHourChanged,
            onDeleteAccount: onDeleteAccount,
            onSignOut: onSignOut,
            onAccountEmailChanged: onAccountEmailChanged,
            onNotificationPreferencesChanged: onNotificationPreferencesChanged,
            onEditAgentOnboarding: onEditAgentOnboarding,
            onWorkspacesChanged: onWorkspacesChanged,
            error: error,
          ),
        };
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
          return selectedPanel;
        }
        // The Bean chat tab owns the full screen; activity/approvals live inside
        // its top menu and bottom approval dock instead of side dashboard cards.
        if (selectedDestination == _HomeDestination.bean) {
          return selectedPanel;
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

class _HeroChatCard extends StatefulWidget {
  const _HeroChatCard({
    required this.messages,
    required this.busy,
    required this.runState,
    required this.events,
    required this.voiceListening,
    required this.voiceDraft,
    required this.onVoiceDraftChanged,
    required this.onNewSession,
    required this.onSend,
    required this.onStop,
  });

  final List<HermesMessage> messages;
  final bool busy;
  final String runState;
  final List<HermesActivityEvent> events;
  final bool voiceListening;
  final String? voiceDraft;
  final ValueChanged<String> onVoiceDraftChanged;
  final Future<void> Function() onNewSession;
  final Future<void> Function(String content) onSend;
  final Future<void> Function() onStop;

  @override
  State<_HeroChatCard> createState() => _HeroChatCardState();
}

class _HeroChatCardState extends State<_HeroChatCard> {
  final _controller = TextEditingController();
  final _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    _syncVoiceDraftToInput();
  }

  void _syncVoiceDraftToInput() {
    if (widget.voiceListening &&
        widget.voiceDraft != null &&
        widget.voiceDraft != _controller.text) {
      _controller.text = widget.voiceDraft!;
      _controller.selection = TextSelection.collapsed(
        offset: _controller.text.length,
      );
    }
  }

  @override
  void didUpdateWidget(covariant _HeroChatCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    _syncVoiceDraftToInput();
    WidgetsBinding.instance.addPostFrameCallback((_) => _scrollToBottom());
  }

  @override
  void dispose() {
    _controller.dispose();
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

  Future<void> _sendCurrentDraft() async {
    final text = _controller.text.trim();
    if (text.isEmpty || widget.busy) return;
    _controller.clear();
    await widget.onSend(text);
  }

  void _editSentMessage(HermesMessage message) {
    _controller.text = message.content ?? '';
    _controller.selection = TextSelection.collapsed(
      offset: _controller.text.length,
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
              Row(
                key: const Key('chat-top-bar'),
                children: [
                  _ChatRunStatePill(label: widget.runState),
                  const Spacer(),
                  _ChatActivityMenu(events: widget.events),
                  const SizedBox(width: 8),
                  TextButton.icon(
                    key: const Key('chat-new-session-action'),
                    onPressed: widget.busy ? null : widget.onNewSession,
                    icon: const Icon(Icons.add_comment_rounded, size: 18),
                    label: const Text('/new'),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Expanded(
                child: Builder(
                  builder: (context) {
                    final latestAssistantModelIndex = widget.messages
                        .lastIndexWhere(
                          (message) =>
                              message.role != 'user' &&
                              message.modelName != null,
                        );
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
                            modelName: index == latestAssistantModelIndex
                                ? message.modelName
                                : null,
                            onEdit: isUser
                                ? () => _editSentMessage(message)
                                : null,
                          ),
                        );
                      },
                    );
                  },
                ),
              ),
              _ChatInputDock(
                controller: _controller,
                busy: widget.busy,
                listening: widget.voiceListening,
                onChanged: widget.voiceListening
                    ? widget.onVoiceDraftChanged
                    : null,
                onSend: _sendCurrentDraft,
                onStop: widget.onStop,
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ChatInputDock extends StatelessWidget {
  const _ChatInputDock({
    required this.controller,
    required this.busy,
    required this.listening,
    required this.onSend,
    required this.onStop,
    this.onChanged,
  });

  final TextEditingController controller;
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
      borderRadius: BorderRadius.circular(22),
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
            minLines: 1,
            maxLines: 3,
            onChanged: onChanged,
            textInputAction: TextInputAction.send,
            onSubmitted: busy ? null : (_) => onSend(),
            decoration: InputDecoration(
              hintText: listening
                  ? 'Listening… speak now or type to correct the transcript'
                  : 'Message Bean…',
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

class _ChatActivityMenu extends StatelessWidget {
  const _ChatActivityMenu({required this.events});

  final List<HermesActivityEvent> events;

  @override
  Widget build(BuildContext context) => PopupMenuButton<void>(
    key: const Key('chat-activity-menu'),
    tooltip: 'Activity feed',
    icon: const Icon(Icons.menu_rounded),
    itemBuilder: (context) => [
      PopupMenuItem<void>(
        enabled: false,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 320, minWidth: 240),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text(
                'Activity feed',
                style: TextStyle(fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 8),
              if (events.isEmpty)
                const Text(
                  'No recent activity.',
                  style: TextStyle(color: HeyBeanTheme.muted),
                )
              else
                for (final event in events.take(6))
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Text(
                      '${event.eventType} · ${event.status ?? 'updated'}',
                    ),
                  ),
            ],
          ),
        ),
      ),
    ],
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
                  decoration: const InputDecoration(
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

class _ChatRunStatePill extends StatelessWidget {
  const _ChatRunStatePill({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    final normalized = label.toLowerCase();
    final color =
        normalized.contains('blocked') || normalized.contains('failed')
        ? HeyBeanTheme.warning
        : normalized.contains('working')
        ? HeyBeanTheme.accentStrong
        : HeyBeanTheme.muted;

    return Container(
      key: const Key('chat-run-state'),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(
        color: color.withValues(alpha: .10),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withValues(alpha: .24)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 8,
            height: 8,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
          ),
          const SizedBox(width: 8),
          Text(
            label,
            style: TextStyle(
              color: color,
              fontSize: 12,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
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
            backgroundColor: const Color(0x1416A34A),
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
    this.modelName,
    this.onEdit,
  });

  final String sender;
  final String message;
  final bool alignRight;
  final bool progress;
  final String? modelName;
  final VoidCallback? onEdit;

  @override
  Widget build(BuildContext context) => Align(
    alignment: alignRight ? Alignment.centerRight : Alignment.centerLeft,
    child: Container(
      constraints: const BoxConstraints(maxWidth: 560),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: alignRight ? const Color(0x1F16A34A) : HeyBeanTheme.surface2,
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
              if (modelName == null)
                Text(
                  sender,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: HeyBeanTheme.accentStrong,
                    fontWeight: FontWeight.w800,
                  ),
                )
              else
                Expanded(
                  child: Text(
                    sender,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: HeyBeanTheme.accentStrong,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
              if (modelName != null) ...[
                const SizedBox(width: 10),
                ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 160),
                  child: Text(
                    modelName!,
                    key: const Key('assistant-message-model-label'),
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.right,
                    style: const TextStyle(
                      color: HeyBeanTheme.muted,
                      fontSize: 10,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              ],
              if (onEdit != null) ...[
                const SizedBox(width: 8),
                InkWell(
                  key: const Key('chat-edit-sent-message-action'),
                  onTap: onEdit,
                  borderRadius: BorderRadius.circular(999),
                  child: const Padding(
                    padding: EdgeInsets.all(3),
                    child: Icon(Icons.edit_outlined, size: 14),
                  ),
                ),
              ],
            ],
          ),
          const SizedBox(height: 4),
          Text(message),
        ],
      ),
    ),
  );
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
          const TabBar(
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            labelColor: HeyBeanTheme.accentStrong,
            unselectedLabelColor: HeyBeanTheme.muted,
            indicatorColor: HeyBeanTheme.accent,
            tabs: [
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
        if (error != null)
          Text(error!, style: const TextStyle(color: HeyBeanTheme.warning)),
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
    required this.eventCategories,
    required this.googleCalendarStatus,
    required this.selectedDay,
    required this.showMonth,
    required this.startHour,
    required this.endHour,
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
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final DateTime selectedDay;
  final bool showMonth;
  final int startHour;
  final int endHour;
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
    List<Object> syncToWorkspaceIds,
  })
  onCalendarEventCreated;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
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
    const dayLabel = 'Today';
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
              _AppleStyleTodayTimeline(
                calendar: calendar,
                eventCategories: eventCategories,
                googleCalendarStatus: googleCalendarStatus,
                workspaces: user.workspaces,
                activeWorkspaceId: user.activeWorkspace?.id,
                selectedDay: selectedDay,
                startHour: startHour,
                endHour: endHour,
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
              title: 'Tasks for $dayLabel',
              subtitle: '${tasks.length} tasks',
              infoKey: const Key('today-tasks-info'),
              infoTitle: 'Tasks for today',
              infoBullets: const [
                'Use this list for the tasks Bean thinks belong on your current day.',
                'Tap the circle to complete or reopen a task. Tap the row to edit details.',
                'Star important tasks as Critical so they appear in the top count.',
              ],
            ),
            const SizedBox(height: 12),
            if (tasks.isEmpty)
              _EmptySurface(label: 'No tasks scheduled for $dayLabel')
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
      googleCalendarStatus: googleCalendarStatus,
      initialGoogleCalendarIds: task?.googleCalendarIds ?? const [],
      initialSyncWorkspaceIds: task == null
          ? const []
          : _initialSyncWorkspaceIds(
              linkedWorkspaceIds: task.linkedWorkspaceIds,
              workspaceId: task.workspaceId,
              activeWorkspaceId: user.activeWorkspace?.id,
            ),
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
      width: 36,
      height: 36,
      alignment: Alignment.center,
      decoration: const BoxDecoration(
        color: HeyBeanTheme.accent,
        shape: BoxShape.circle,
      ),
      child: Text(
        '$count',
        style: const TextStyle(
          color: Colors.white,
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
  final ValueChanged<DateTime> onDayChanged;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
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
    if (nextOffset != _visibleDayOffset) {
      setState(() => _visibleDayOffset = nextOffset);
    }
    final nextSelectedDay = _dateForPage(page);
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
      color: Color(0x0F16A34A),
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
              List<Object> syncToWorkspaceIds = const [],
            }) => onEventTap(
              savedEvent,
              title: title,
              startsAt: startsAt,
              endsAt: endsAt,
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
                  List<Object> syncToWorkspaceIds = const [],
                }) => onEventTap(
                  savedEvent,
                  title: title,
                  startsAt: startsAt,
                  endsAt: endsAt,
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
                List<Object> syncToWorkspaceIds = const [],
              }) => onTap(
                savedEvent,
                title: title,
                startsAt: startsAt,
                endsAt: endsAt,
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

Future<void> _showCalendarEventDetails(
  BuildContext context,
  HermesCalendarEvent event, {
  required List<HermesEventCategory> eventCategories,
  GoogleCalendarSyncStatus? googleCalendarStatus,
  String? occurrenceDate,
  required Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
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
    List<Object> syncToWorkspaceIds,
  })
  onSave,
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

  if (result != null) {
    await onSave(
      event,
      title: result['title'] as String,
      startsAt: result['startsAt'] as String,
      endsAt: result['endsAt'] as String?,
      category: result['category'] as String?,
      color: result['color'] as String?,
      recurrence: result['recurrence'] as String?,
      metadata: result['metadata'] as Map<String, Object?>?,
      isCritical: result['isCritical'] as bool?,
      reminderMinutesBefore: result['reminderMinutesBefore'] as int?,
      reminderRecurrence: result['reminderRecurrence'] as String?,
      reminderSpecificDays: (result['reminderSpecificDays'] as List?)
          ?.whereType<String>()
          .toList(),
      reminderInterval: result['reminderInterval'] as int?,
      reminderIntervalUnit: result['reminderIntervalUnit'] as String?,
      syncToWorkspaceIds:
          (result['syncToWorkspaceIds'] as List?)
              ?.whereType<Object>()
              .toList() ??
          const [],
    );
  }
}

class _CalendarEventDetailPage extends StatefulWidget {
  const _CalendarEventDetailPage({
    required this.event,
    this.occurrenceDate,
    required this.eventCategories,
    this.googleCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
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
  late final TextEditingController _category;
  late final TextEditingController _reminder;
  late final TextEditingController _eventInterval;
  late final TextEditingController _reminderInterval;
  late String _color;
  late String _recurrence;
  late List<HermesEventCategory> _categories;
  String _eventIntervalUnit = 'days';
  String _reminderRecurrence = 'none';
  String _reminderIntervalUnit = 'days';
  final Set<String> _googleCalendarIds = <String>{};
  final Set<Object> _syncWorkspaceIds = <Object>{};
  String? _validationError;
  late bool _isCritical;
  late bool _allDay;
  final Set<String> _eventSpecificDays = <String>{};
  final Set<String> _reminderSpecificDays = <String>{};
  bool _savingCategory = false;

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

  static const _reminderRecurrences = <({String value, String label})>[
    (value: 'none', label: 'Once'),
    (value: 'daily', label: 'Daily'),
    (value: 'weekly', label: 'Weekly'),
    (value: 'monthly', label: 'Monthly'),
    (value: 'specific_days', label: 'Specific days'),
    (value: 'interval', label: 'Every X'),
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
    _categories = [...widget.eventCategories];
    _category = TextEditingController(text: event.category ?? '');
    _reminder = TextEditingController();
    _eventInterval = TextEditingController(
      text: eventMetadata['interval']?.toString() ?? '1',
    );
    _reminderInterval = TextEditingController(text: '1');
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
    final initialColor = event.color ?? matchingCategoryColor;
    _color = _isHexColor(initialColor)
        ? initialColor!.toUpperCase()
        : _beanGreenCategoryColor;
    _recurrence =
        _recurrences.any((recurrence) => recurrence.value == event.recurrence)
        ? event.recurrence!
        : 'none';
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
    _category.dispose();
    _reminder.dispose();
    _eventInterval.dispose();
    _reminderInterval.dispose();
    super.dispose();
  }

  void _save() {
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

    Navigator.of(context).pop(<String, Object?>{
      'title': _title.text.trim().isEmpty
          ? widget.event.title
          : _title.text.trim(),
      'startsAt': startsAt,
      'endsAt': endsAt,
      'category': _category.text.trim().isEmpty ? null : _category.text.trim(),
      'color': _color,
      'recurrence': _recurrence,
      'metadata': eventMetadata,
      'isCritical': _isCritical,
      'reminderMinutesBefore': int.tryParse(_reminder.text.trim()),
      'reminderRecurrence': _reminderRecurrence,
      'reminderSpecificDays': _reminderSpecificDays.toList()..sort(),
      'reminderInterval': int.tryParse(_reminderInterval.text.trim()),
      'reminderIntervalUnit': _reminderIntervalUnit,
      'syncToWorkspaceIds': _syncWorkspaceIds.toList(),
    });
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
          _color = _beanGreenCategoryColor;
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
          _color = _beanGreenCategoryColor;
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
    final initial =
        _calendarEventDateInputToDate(
          controller.text,
          originalValue: originalValue,
          referenceValue: _calendarEventDateInputToDate(referenceValue ?? ''),
        ) ??
        _dateOnly(DateTime.now());
    final selected = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(initial.year - 2),
      lastDate: DateTime(initial.year + 5),
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: const Key('calendar-event-detail-page'),
      body: Container(
        decoration: const BoxDecoration(
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
                  children: [
                    IconButton.filledTonal(
                      key: const Key('event-detail-back-action'),
                      onPressed: () => Navigator.of(context).pop(),
                      icon: const Icon(Icons.arrow_back_rounded),
                    ),
                    const SizedBox(width: 8),
                    IconButton.filledTonal(
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
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Event Details',
                            key: const Key('event-detail-header-title'),
                            style: Theme.of(context).textTheme.headlineSmall
                                ?.copyWith(
                                  fontWeight: FontWeight.w900,
                                  color: HeyBeanTheme.text,
                                  letterSpacing: -.4,
                                ),
                          ),
                        ],
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
                      _ShellCard(
                        glow: true,
                        child: TextField(
                          key: const Key('event-title-field'),
                          controller: _title,
                          textInputAction: TextInputAction.next,
                          decoration: const InputDecoration(
                            labelText: 'Title',
                            prefixIcon: Icon(Icons.title_rounded),
                          ),
                        ),
                      ),
                      const SizedBox(height: 14),
                      _ShellCard(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const _SectionTitle(
                              icon: Icons.schedule_rounded,
                              title: 'Schedule',
                              subtitle:
                                  'Use exact times or natural entries from the agent.',
                              infoKey: Key('event-schedule-info'),
                              infoTitle: 'Event schedule',
                              infoBullets: [
                                'Turn on All day for date-only events with no start or end time.',
                                'Tap a time field to use the date and time picker.',
                                'You can also type natural entries like today at 2pm or May 18 at 9am.',
                                'End time must be after the start time; leave it blank for a simple reminder-style event.',
                              ],
                            ),
                            const SizedBox(height: 18),
                            if (_validationError != null) ...[
                              Text(
                                _validationError!,
                                key: const Key('event-validation-error'),
                                style: const TextStyle(color: Colors.redAccent),
                              ),
                              const SizedBox(height: 8),
                            ],
                            SwitchListTile(
                              key: const Key('event-all-day-toggle'),
                              contentPadding: EdgeInsets.zero,
                              value: _allDay,
                              onChanged: _setAllDay,
                              title: const Text('All day'),
                              subtitle: const Text(
                                'Use dates only instead of start and end times.',
                              ),
                              secondary: const Icon(
                                Icons.calendar_today_rounded,
                              ),
                            ),
                            const SizedBox(height: 12),
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
                                labelText: _allDay
                                    ? 'Start date'
                                    : 'Start time',
                                prefixIcon: const Icon(
                                  Icons.play_arrow_rounded,
                                ),
                                suffixIcon: const Icon(
                                  Icons.expand_less_rounded,
                                ),
                              ),
                            ),
                            const SizedBox(height: 12),
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
                                suffixIcon: const Icon(
                                  Icons.expand_less_rounded,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 14),
                      _ShellCard(
                        child: Column(
                          key: const Key('event-recurrence-field'),
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const _SectionTitle(
                              icon: Icons.repeat_rounded,
                              title: 'Recurrence',
                              subtitle: 'Repeat this event when needed.',
                              infoKey: Key('event-recurrence-info'),
                              infoTitle: 'Event recurrence',
                              infoBullets: [
                                'Choose None for a one-time event.',
                                'Specific days repeats on the weekdays you select.',
                                'Every X lets you build patterns like every 2 weeks or every 3 months.',
                              ],
                            ),
                            const SizedBox(height: 18),
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
                      ),
                      if ((widget
                              .googleCalendarStatus
                              ?.writableCalendars
                              .isNotEmpty ??
                          false)) ...[
                        const SizedBox(height: 14),
                        _ShellCard(
                          child: Column(
                            key: const Key('event-google-calendar-field'),
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const _SectionTitle(
                                icon: Icons.calendar_month_rounded,
                                title: 'External Calendar Sync',
                                subtitle:
                                    'Add or update this event on selected writable external calendars.',
                                infoKey: Key('event-google-calendars-info'),
                                infoTitle: 'External Calendar Sync',
                                infoBullets: [
                                  'Checked external calendars receive a copy of this local Bean event.',
                                  'Only writable connected external calendars are shown here.',
                                  'Changing this list affects this event, not your whole account.',
                                ],
                              ),
                              const SizedBox(height: 12),
                              for (final calendar
                                  in widget
                                      .googleCalendarStatus!
                                      .writableCalendars)
                                CheckboxListTile(
                                  key: Key(
                                    'event-google-calendar-${calendar.id}',
                                  ),
                                  contentPadding: EdgeInsets.zero,
                                  value: _googleCalendarIds.contains(
                                    calendar.id,
                                  ),
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
                                      ? const Text(
                                          'Default for new local events',
                                        )
                                      : null,
                                ),
                            ],
                          ),
                        ),
                      ],
                      if (widget.workspaces
                          .where(
                            (workspace) =>
                                workspace.id != widget.activeWorkspaceId,
                          )
                          .isNotEmpty) ...[
                        const SizedBox(height: 14),
                        _ShellCard(
                          child: Column(
                            key: const Key('event-workspace-sync-field'),
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const _SectionTitle(
                                icon: Icons.home_work_outlined,
                                title: 'Local Workspace Sync',
                                subtitle:
                                    'Copy this event only to selected HeyBean workspaces.',
                                infoKey: Key('event-workspace-sync-info'),
                                infoTitle: 'Local Workspace Sync',
                                infoBullets: [
                                  'Use this when a Personal event should also appear in a household workspace.',
                                  'Sync creates a copy for the selected workspace; future edits remain controlled by Bean.',
                                  'Leave everything unchecked to keep the event only in the current workspace.',
                                ],
                              ),
                              const SizedBox(height: 10),
                              Wrap(
                                spacing: 8,
                                runSpacing: 8,
                                children: [
                                  for (final workspace
                                      in widget.workspaces.where(
                                        (workspace) =>
                                            workspace.id !=
                                            widget.activeWorkspaceId,
                                      ))
                                    FilterChip(
                                      key: Key(
                                        'event-sync-workspace-${workspace.id}',
                                      ),
                                      label: Text(workspace.name),
                                      selected: _syncWorkspaceIds.contains(
                                        workspace.numericId ?? workspace.id,
                                      ),
                                      onSelected: (selected) => setState(() {
                                        final value =
                                            workspace.numericId ?? workspace.id;
                                        if (selected) {
                                          _syncWorkspaceIds.add(value);
                                        } else {
                                          _syncWorkspaceIds.remove(value);
                                        }
                                      }),
                                    ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      ],
                      const SizedBox(height: 14),
                      _ShellCard(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const _SectionTitle(
                              icon: Icons.notifications_active_outlined,
                              title: 'Reminder',
                              subtitle:
                                  'Add a reminder relative to this event.',
                              infoKey: Key('event-reminder-info'),
                              infoTitle: 'Event reminders',
                              infoBullets: [
                                'Minutes before controls when Bean reminds you before the event starts.',
                                'Reminder repeats are separate from event recurrence, so reminders can follow their own pattern.',
                                'Leave minutes blank if you do not need a reminder for this event.',
                              ],
                            ),
                            const SizedBox(height: 18),
                            TextField(
                              key: const Key('event-reminder-minutes-field'),
                              controller: _reminder,
                              keyboardType: TextInputType.number,
                              decoration: const InputDecoration(
                                labelText: 'Minutes before',
                                hintText: '15',
                                prefixIcon: Icon(Icons.alarm_rounded),
                              ),
                            ),
                            const SizedBox(height: 12),
                            _EventFieldLabel(
                              icon: Icons.repeat_on_rounded,
                              label: 'Reminder repeats',
                            ),
                            const SizedBox(height: 8),
                            Wrap(
                              key: const Key('event-reminder-recurrence-field'),
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                for (final recurrence in _reminderRecurrences)
                                  ChoiceChip(
                                    label: Text(recurrence.label),
                                    selected:
                                        _reminderRecurrence == recurrence.value,
                                    onSelected: (_) => setState(() {
                                      _reminderRecurrence = recurrence.value;
                                    }),
                                  ),
                              ],
                            ),
                            if (_reminderRecurrence == 'specific_days') ...[
                              const SizedBox(height: 10),
                              Wrap(
                                key: const Key('event-reminder-specific-days'),
                                spacing: 8,
                                runSpacing: 8,
                                children: [
                                  for (final day in _weekdays)
                                    FilterChip(
                                      label: Text(day.label),
                                      selected: _reminderSpecificDays.contains(
                                        day.value,
                                      ),
                                      onSelected: (selected) => setState(() {
                                        if (selected) {
                                          _reminderSpecificDays.add(day.value);
                                        } else {
                                          _reminderSpecificDays.remove(
                                            day.value,
                                          );
                                        }
                                      }),
                                    ),
                                ],
                              ),
                            ],
                            if (_reminderRecurrence == 'interval') ...[
                              const SizedBox(height: 10),
                              Row(
                                key: const Key('event-reminder-interval-field'),
                                children: [
                                  Expanded(
                                    child: TextField(
                                      controller: _reminderInterval,
                                      keyboardType: TextInputType.number,
                                      decoration: const InputDecoration(
                                        labelText: 'Every',
                                        prefixIcon: Icon(Icons.numbers_rounded),
                                      ),
                                    ),
                                  ),
                                  const SizedBox(width: 10),
                                  DropdownButton<String>(
                                    value: _reminderIntervalUnit,
                                    items: [
                                      for (final unit in _intervalUnits)
                                        DropdownMenuItem(
                                          value: unit.value,
                                          child: Text(unit.label),
                                        ),
                                    ],
                                    onChanged: (value) => setState(() {
                                      if (value != null) {
                                        _reminderIntervalUnit = value;
                                      }
                                    }),
                                  ),
                                ],
                              ),
                            ],
                          ],
                        ),
                      ),
                      const SizedBox(height: 14),
                      Column(
                        key: const Key('event-category-chip-list'),
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  'Category',
                                  style: Theme.of(context).textTheme.labelLarge
                                      ?.copyWith(
                                        color: HeyBeanTheme.text,
                                        fontWeight: FontWeight.w800,
                                      ),
                                ),
                              ),
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
                          const SizedBox(height: 8),
                          Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              _CategoryOptionPill(
                                key: const Key('event-category-none'),
                                label: 'No category',
                                selected: _category.text.trim().isEmpty,
                                onTap: () {
                                  setState(() {
                                    _category.text = '';
                                    _color = _beanGreenCategoryColor;
                                  });
                                },
                              ),
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
                  onPressed: () => Navigator.of(context).pop(),
                  child: const Text('Cancel'),
                ),
              ),
              if (widget.onDelete != null) ...[
                const SizedBox(width: 12),
                IconButton.filled(
                  key: const Key('event-delete-action'),
                  tooltip: 'Delete event',
                  style: _destructiveIconButtonStyle(),
                  onPressed: () async {
                    final deleteOptions = await _confirmCalendarEventDelete();
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
                  onPressed: _save,
                  icon: const Icon(Icons.check_rounded),
                  label: const Text('Save event'),
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
                    const Icon(
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

class _CategoryOptionPill extends StatelessWidget {
  const _CategoryOptionPill({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
    this.color,
    this.dotKey,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;
  final Color? color;
  final Key? dotKey;

  @override
  Widget build(BuildContext context) {
    final dotColor = color ?? HeyBeanTheme.muted;
    return Material(
      color: Colors.white,
      shape: StadiumBorder(
        side: BorderSide(
          color: selected ? HeyBeanTheme.accent : HeyBeanTheme.border,
          width: selected ? 1.8 : 1.2,
        ),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(999),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (color != null) ...[
                CircleAvatar(key: dotKey, radius: 6, backgroundColor: dotColor),
                const SizedBox(width: 7),
              ],
              Text(
                label,
                style: TextStyle(
                  color: HeyBeanTheme.text,
                  fontWeight: selected ? FontWeight.w900 : FontWeight.w700,
                ),
              ),
              if (selected) ...[
                const SizedBox(width: 6),
                const Icon(
                  Icons.check_circle_rounded,
                  size: 15,
                  color: HeyBeanTheme.accent,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
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
        FilledButton(
          key: const Key('event-category-modal-save-action'),
          onPressed: _submit,
          child: Text(widget.editing ? 'Save' : 'Create'),
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
        ? const Color(0xFFE8F5E9)
        : HeyBeanTheme.surface2;
    final borderColor = isToday || isSelected
        ? HeyBeanTheme.accentStrong
        : HeyBeanTheme.border;

    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: onTap,
      child: Container(
        height: 42,
        margin: const EdgeInsets.symmetric(horizontal: 2),
        decoration: BoxDecoration(
          color: backgroundColor,
          borderRadius: BorderRadius.circular(14),
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
                          List<Object> syncToWorkspaceIds = const [],
                        }) => onEventTap!(
                          savedEvent,
                          title: title,
                          startsAt: startsAt,
                          endsAt: endsAt,
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
                'Choose date and time',
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
                        final hour12 = selectedHourIndex + 1;
                        final minute = selectedMinuteIndex * 5;
                        var hour24 = hour12 % 12;
                        if (selectedMeridiemIndex == 1) hour24 += 12;
                        final year = yearStart + selectedYearIndex;
                        final month = selectedMonthIndex + 1;
                        final maxDay = DateTime(year, month + 1, 0).day;
                        final day = (selectedDayIndex + 1).clamp(1, maxDay);
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
  GoogleCalendarSyncStatus? googleCalendarStatus,
  List<String> initialGoogleCalendarIds = const [],
  List<Object> initialSyncWorkspaceIds = const [],
}) async {
  final titleController = TextEditingController(text: initialTitle);
  final timeController = TextEditingController(text: initialTime);
  final notesController = TextEditingController(text: initialNotes);
  var selectedCategory = initialCategory?.trim() ?? '';
  var selectedColor = selectedCategory.isEmpty
      ? _beanGreenCategoryColor
      : initialColor?.trim() ?? _beanGreenCategoryColor;
  var modalCategories = [...categories];
  var savingCategory = false;
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
  final googleCalendarIds = <String>{...initialGoogleCalendarIds};
  final writableGoogleCalendars =
      googleCalendarStatus?.writableCalendars ?? const <GoogleCalendarInfo>[];
  final syncTargets = workspaces
      .where((workspace) => workspace.id != activeWorkspaceId)
      .toList();
  String? validationError;

  return showModalBottomSheet<Map<String, Object?>>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (context) => StatefulBuilder(
      builder: (context, setModalState) => Padding(
        padding: EdgeInsets.only(
          bottom: MediaQuery.of(context).viewInsets.bottom,
        ),
        child: Container(
          height: MediaQuery.of(context).size.height * 0.92,
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
          decoration: const BoxDecoration(
            color: HeyBeanTheme.surface,
            borderRadius: BorderRadius.vertical(top: Radius.circular(26)),
            border: Border(top: BorderSide(color: HeyBeanTheme.border)),
          ),
          child: SafeArea(
            top: false,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _SectionTitle(
                    icon: Icons.edit_note_rounded,
                    title: title,
                    subtitle: '',
                  ),
                  Align(
                    alignment: Alignment.centerRight,
                    child: FilledButton.icon(
                      key: const Key('title-time-editor-save'),
                      onPressed: () async {
                        final title = titleController.text.trim();
                        final time = timeController.text.trim();
                        if (title.isEmpty ||
                            (!allowEmptyTime && time.isEmpty)) {
                          return;
                        }
                        if (time.isNotEmpty &&
                            _taskReminderInputToWireValue(time) == null) {
                          setModalState(
                            () => validationError =
                                'Use a recognizable date/time, like Today 5:00 PM.',
                          );
                          return;
                        }
                        Navigator.of(context).pop({
                          'title': title,
                          'time': time.isEmpty ? null : time,
                          'notes': notesController.text.trim().isEmpty
                              ? null
                              : notesController.text.trim(),
                          'category': selectedCategory.isEmpty
                              ? null
                              : selectedCategory,
                          'color': selectedCategory.isEmpty
                              ? _beanGreenCategoryColor
                              : (selectedColor.isEmpty
                                    ? _beanGreenCategoryColor
                                    : selectedColor),
                          'isCritical': isCritical,
                          if (showRecurrence)
                            'recurrenceMetadata': _metadataWithRecurrence(
                              initialMetadata,
                              recurrence: recurrence,
                              days: recurrenceSpecificDays,
                              interval:
                                  int.tryParse(
                                    recurrenceIntervalController.text.trim(),
                                  ) ??
                                  1,
                              unit: recurrenceIntervalUnit,
                            ),
                          'syncToWorkspaceIds': syncWorkspaceIds.toList(),
                          'googleCalendarIds': googleCalendarIds.toList()
                            ..sort(),
                        });
                      },
                      icon: const Icon(Icons.check_rounded),
                      label: const Text('Save'),
                    ),
                  ),
                  const SizedBox(height: 14),
                  if (showCritical) ...[
                    Align(
                      alignment: Alignment.centerLeft,
                      child: FilterChip(
                        key: const Key('title-time-editor-critical-toggle'),
                        avatar: Icon(
                          isCritical
                              ? Icons.star_rounded
                              : Icons.star_border_rounded,
                          color: isCritical
                              ? HeyBeanTheme.warning
                              : HeyBeanTheme.muted,
                          size: 18,
                        ),
                        label: const Text('Critical'),
                        selected: isCritical,
                        onSelected: (selected) =>
                            setModalState(() => isCritical = selected),
                      ),
                    ),
                    const SizedBox(height: 12),
                  ],
                  TextFormField(
                    key: const Key('title-time-editor-title'),
                    controller: titleController,
                    textInputAction: TextInputAction.next,
                    decoration: InputDecoration(labelText: titleLabel),
                  ),
                  const SizedBox(height: 12),
                  if (modalCategories.isNotEmpty ||
                      onEventCategorySaved != null) ...[
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            'Category',
                            style: Theme.of(context).textTheme.labelLarge
                                ?.copyWith(
                                  color: HeyBeanTheme.text,
                                  fontWeight: FontWeight.w800,
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
                                        await showDialog<Map<String, String>>(
                                          context: context,
                                          builder: (context) =>
                                              const _EventCategoryCreateDialog(
                                                initialColor:
                                                    _beanGreenCategoryColor,
                                                colors: [
                                                  (
                                                    value:
                                                        _beanGreenCategoryColor,
                                                    label: 'Green',
                                                  ),
                                                  (
                                                    value: '#007AFF',
                                                    label: 'Blue',
                                                  ),
                                                  (
                                                    value: '#FF9500',
                                                    label: 'Orange',
                                                  ),
                                                  (
                                                    value: '#AF52DE',
                                                    label: 'Purple',
                                                  ),
                                                  (
                                                    value: '#FF3B30',
                                                    label: 'Red',
                                                  ),
                                                ],
                                              ),
                                        );
                                    if (categoryValues == null) return;
                                    final name =
                                        categoryValues['name']?.trim() ?? '';
                                    final color =
                                        categoryValues['color']?.trim() ??
                                        _beanGreenCategoryColor;
                                    if (name.isEmpty) return;
                                    setModalState(() => savingCategory = true);
                                    try {
                                      final saved = await onEventCategorySaved(
                                        name: name,
                                        color: color,
                                      );
                                      setModalState(() {
                                        modalCategories = [
                                          ...modalCategories.where(
                                            (item) => item.id != saved.id,
                                          ),
                                          saved,
                                        ];
                                        selectedCategory = saved.name;
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
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        _CategoryOptionPill(
                          key: const Key('title-time-editor-category-none'),
                          label: 'No category',
                          selected: selectedCategory.isEmpty,
                          onTap: () => setModalState(() {
                            selectedCategory = '';
                            selectedColor = _beanGreenCategoryColor;
                          }),
                        ),
                        for (final category in modalCategories)
                          _CategoryOptionPill(
                            key: Key(
                              'title-time-editor-category-${category.name.toLowerCase().replaceAll(' ', '-')}',
                            ),
                            dotKey: Key(
                              'title-time-editor-category-dot-${category.name.toLowerCase().replaceAll(' ', '-')}',
                            ),
                            label: category.name,
                            color: _safeCategoryColor(category.color),
                            selected: selectedCategory == category.name,
                            onTap: () => setModalState(() {
                              selectedCategory = category.name;
                              selectedColor = category.color;
                            }),
                          ),
                      ],
                    ),
                    const SizedBox(height: 12),
                  ],
                  if (showTimeTextField)
                    TextFormField(
                      key: const Key('title-time-editor-time'),
                      controller: timeController,
                      textInputAction: TextInputAction.done,
                      decoration: InputDecoration(
                        labelText: timeLabel,
                        helperText: allowEmptyTime
                            ? 'Optional · examples: Today 5:00 PM, 5:00 PM, May 18 9 AM'
                            : 'Required · examples: Today 5:00 PM, May 18 9 AM',
                        suffixIcon: IconButton(
                          key: const Key('title-time-editor-open-picker'),
                          tooltip: 'Choose date and time',
                          onPressed: () async {
                            final selected = await _showStandardDateTimeDock(
                              context,
                              initialText: timeController.text,
                              originalValue: initialTime,
                              keyPrefix: 'title-time',
                            );
                            if (selected == null) return;
                            setModalState(() {
                              timeController.text =
                                  _formatCalendarEventDateTime(
                                    selected.toIso8601String(),
                                  );
                              validationError = null;
                            });
                          },
                          icon: const Icon(Icons.calendar_month_rounded),
                        ),
                      ),
                    )
                  else
                    Container(
                      key: const Key('title-time-editor-selected-time-label'),
                      padding: const EdgeInsets.symmetric(
                        horizontal: 12,
                        vertical: 10,
                      ),
                      decoration: BoxDecoration(
                        color: HeyBeanTheme.surface2,
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(color: HeyBeanTheme.border),
                      ),
                      child: Text(
                        timeController.text.trim().isEmpty
                            ? 'No date and time selected'
                            : timeController.text.trim(),
                        style: TextStyle(
                          color: timeController.text.trim().isEmpty
                              ? HeyBeanTheme.muted
                              : HeyBeanTheme.text,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  if (showNotes) ...[
                    const SizedBox(height: 12),
                    TextFormField(
                      key: const Key('title-time-editor-notes'),
                      controller: notesController,
                      minLines: 2,
                      maxLines: 5,
                      decoration: const InputDecoration(
                        labelText: 'Notes',
                        hintText: 'Add task details',
                      ),
                    ),
                  ],
                  if (showRecurrence) ...[
                    const SizedBox(height: 12),
                    Container(
                      key: const Key('title-time-editor-recurrence-field'),
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: HeyBeanTheme.surface2,
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(color: HeyBeanTheme.border),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          _SectionTitle(
                            icon: Icons.repeat_rounded,
                            title: recurrenceTitle,
                            subtitle: recurrenceSubtitle,
                            infoKey: const Key(
                              'title-time-editor-recurrence-info',
                            ),
                            infoTitle: recurrenceInfoTitle,
                            infoBullets: const [
                              'Choose None for a one-time item.',
                              'Specific days repeats on the weekdays you select.',
                              'Every X lets you build patterns like every 2 weeks or every 3 months.',
                            ],
                          ),
                          const SizedBox(height: 12),
                          Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              for (final option in _titleTimeEditorRecurrences)
                                ChoiceChip(
                                  label: Text(option.label),
                                  selected: recurrence == option.value,
                                  onSelected: (_) => setModalState(() {
                                    recurrence = option.value;
                                  }),
                                ),
                            ],
                          ),
                          if (recurrence == 'specific_days') ...[
                            const SizedBox(height: 10),
                            Wrap(
                              key: const Key('title-time-editor-specific-days'),
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                for (final day in _titleTimeEditorWeekdays)
                                  FilterChip(
                                    label: Text(day.label),
                                    selected: recurrenceSpecificDays.contains(
                                      day.value,
                                    ),
                                    onSelected: (selected) => setModalState(() {
                                      if (selected) {
                                        recurrenceSpecificDays.add(day.value);
                                      } else {
                                        recurrenceSpecificDays.remove(
                                          day.value,
                                        );
                                      }
                                    }),
                                  ),
                              ],
                            ),
                          ],
                          if (recurrence == 'interval') ...[
                            const SizedBox(height: 10),
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
                                    controller: recurrenceIntervalController,
                                    keyboardType: TextInputType.number,
                                    decoration: const InputDecoration(
                                      labelText: 'Every',
                                      prefixIcon: Icon(Icons.numbers_rounded),
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
                        ],
                      ),
                    ),
                  ],
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton.icon(
                      key: const Key('title-time-editor-picker-button'),
                      onPressed: () async {
                        final selected = await _showStandardDateTimeDock(
                          context,
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
                      },
                      icon: const Icon(Icons.schedule_rounded),
                      label: const Text('Choose date and time'),
                    ),
                  ),
                  if (writableGoogleCalendars.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Container(
                      key: const Key('title-time-editor-google-calendar-sync'),
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: HeyBeanTheme.surface2,
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(color: HeyBeanTheme.border),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Add to connected calendars',
                            style: TextStyle(fontWeight: FontWeight.w800),
                          ),
                          const SizedBox(height: 4),
                          const Text(
                            'Create or update this item on selected writable connected calendars.',
                            style: TextStyle(color: HeyBeanTheme.muted),
                          ),
                          const SizedBox(height: 8),
                          Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              for (final calendar in writableGoogleCalendars)
                                FilterChip(
                                  key: Key(
                                    'title-time-editor-google-calendar-${calendar.id}',
                                  ),
                                  label: Text(calendar.summary),
                                  selected: googleCalendarIds.contains(
                                    calendar.id,
                                  ),
                                  onSelected: (selected) => setModalState(() {
                                    if (selected) {
                                      googleCalendarIds.add(calendar.id);
                                    } else {
                                      googleCalendarIds.remove(calendar.id);
                                    }
                                  }),
                                ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ],
                  if (syncTargets.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Container(
                      key: const Key('title-time-editor-workspace-sync'),
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: HeyBeanTheme.surface2,
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(color: HeyBeanTheme.border),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Sync to workspaces',
                            style: TextStyle(fontWeight: FontWeight.w800),
                          ),
                          const SizedBox(height: 4),
                          const Text(
                            'Copy this item only to selected workspaces.',
                            style: TextStyle(color: HeyBeanTheme.muted),
                          ),
                          const SizedBox(height: 8),
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
                                  selected: syncWorkspaceIds.contains(
                                    workspace.numericId ?? workspace.id,
                                  ),
                                  onSelected: (selected) => setModalState(() {
                                    final value =
                                        workspace.numericId ?? workspace.id;
                                    if (selected) {
                                      syncWorkspaceIds.add(value);
                                    } else {
                                      syncWorkspaceIds.remove(value);
                                    }
                                  }),
                                ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ],
                  if (validationError != null) ...[
                    const SizedBox(height: 8),
                    Text(
                      validationError!,
                      style: const TextStyle(color: Colors.redAccent),
                    ),
                  ],
                  const SizedBox(height: 14),
                  Row(
                    children: [
                      if (deleteLabel != null)
                        Expanded(
                          child: FilledButton.icon(
                            key: const Key('title-time-editor-delete'),
                            style: _destructiveFilledButtonStyle(),
                            onPressed: () =>
                                Navigator.of(context).pop({'delete': true}),
                            icon: const Icon(Icons.delete_outline_rounded),
                            label: Text(deleteLabel),
                          ),
                        ),
                      if (deleteLabel != null) const SizedBox(width: 10),
                      Expanded(
                        child: FilledButton.icon(
                          key: const Key('title-time-editor-save-bottom'),
                          onPressed: () {
                            final title = titleController.text.trim();
                            final time = timeController.text.trim();
                            if (title.isEmpty) {
                              setModalState(
                                () => validationError = 'A title is required.',
                              );
                              return;
                            }
                            if (!allowEmptyTime && time.isEmpty) {
                              setModalState(
                                () => validationError = 'A time is required.',
                              );
                              return;
                            }
                            if (time.isNotEmpty &&
                                _taskReminderInputToWireValue(time) == null) {
                              setModalState(
                                () => validationError =
                                    'Use a recognizable date/time, like Today 5:00 PM.',
                              );
                              return;
                            }
                            Navigator.of(context).pop({
                              'title': title,
                              'time': time.isEmpty ? null : time,
                              'notes': notesController.text.trim().isEmpty
                                  ? null
                                  : notesController.text.trim(),
                              'category': selectedCategory.isEmpty
                                  ? null
                                  : selectedCategory,
                              'color': selectedCategory.isEmpty
                                  ? _beanGreenCategoryColor
                                  : (selectedColor.isEmpty
                                        ? _beanGreenCategoryColor
                                        : selectedColor),
                              'isCritical': isCritical,
                              if (showRecurrence)
                                'recurrenceMetadata': _metadataWithRecurrence(
                                  initialMetadata,
                                  recurrence: recurrence,
                                  days: recurrenceSpecificDays,
                                  interval:
                                      int.tryParse(
                                        recurrenceIntervalController.text
                                            .trim(),
                                      ) ??
                                      1,
                                  unit: recurrenceIntervalUnit,
                                ),
                              'syncToWorkspaceIds': syncWorkspaceIds.toList(),
                              'googleCalendarIds': googleCalendarIds.toList()
                                ..sort(),
                            });
                          },
                          icon: const Icon(Icons.check_rounded),
                          label: const Text('Save'),
                        ),
                      ),
                    ],
                  ),
                  if (completeLabel != null) ...[
                    const SizedBox(height: 8),
                    TextButton.icon(
                      key: const Key('title-time-editor-complete'),
                      onPressed: () {
                        final title = titleController.text.trim();
                        final time = timeController.text.trim();
                        if (title.isEmpty || time.isEmpty) return;
                        Navigator.of(context).pop({
                          'title': title,
                          'time': time,
                          'notes': notesController.text.trim().isEmpty
                              ? null
                              : notesController.text.trim(),
                          'complete': true,
                          'category': selectedCategory.isEmpty
                              ? null
                              : selectedCategory,
                          'color': selectedCategory.isEmpty
                              ? _beanGreenCategoryColor
                              : (selectedColor.isEmpty
                                    ? _beanGreenCategoryColor
                                    : selectedColor),
                          'isCritical': isCritical,
                          if (showRecurrence)
                            'recurrenceMetadata': _metadataWithRecurrence(
                              initialMetadata,
                              recurrence: recurrence,
                              days: recurrenceSpecificDays,
                              interval:
                                  int.tryParse(
                                    recurrenceIntervalController.text.trim(),
                                  ) ??
                                  1,
                              unit: recurrenceIntervalUnit,
                            ),
                          'syncToWorkspaceIds': syncWorkspaceIds.toList(),
                          'googleCalendarIds': googleCalendarIds.toList()
                            ..sort(),
                        });
                      },
                      icon: const Icon(Icons.done_all_rounded),
                      label: Text(completeLabel),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    ),
  );
}

class _TaskListCard extends StatefulWidget {
  const _TaskListCard({
    required this.tasks,
    required this.pastTasks,
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
    final activeSubtasks = widget.tasks
        .where((task) => !_taskIsCompleted(task) && _taskIsSubtask(task))
        .toList();
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
        if (visibleTasks.isEmpty)
          _EmptySurface(
            label: _showAll
                ? 'No tasks yet'
                : _showCompleted
                ? 'No completed tasks'
                : 'No active tasks',
          )
        else
          for (final task in visibleTasks)
            _TaskItemTile(
              task: task,
              subtitle: _taskSubtitle(task),
              subtasks: _subtasksFor(task, activeSubtasks),
              pending: widget.pendingTaskIds.contains(task.id),
              onTap: () => _showTaskEditor(context, task: task),
              onCompleted: widget.onTaskCompleted,
              onSubtaskCompleted: widget.onTaskCompleted,
              onSubtaskTap: (subtask) =>
                  _showTaskEditor(context, task: subtask),
              pendingTaskIds: widget.pendingTaskIds,
              onAddSubtask:
                  !_showCompleted && !_showAll && !_taskIsSubtask(task)
                  ? () => _showTaskEditor(context, parentTask: task)
                  : null,
            ),
      ],
    );
  }

  Future<void> _showTaskEditor(
    BuildContext context, {
    HermesTask? task,
    HermesTask? parentTask,
  }) async {
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
      initialGoogleCalendarIds: task?.googleCalendarIds ?? const [],
      initialSyncWorkspaceIds: task == null
          ? const []
          : _initialSyncWorkspaceIds(
              linkedWorkspaceIds: task.linkedWorkspaceIds,
              workspaceId: task.workspaceId,
              activeWorkspaceId: widget.activeWorkspaceId,
            ),
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

class _ReminderListCard extends StatefulWidget {
  const _ReminderListCard({
    required this.reminders,
    required this.eventCategories,
    required this.onReminderSaved,
    required this.onReminderCompleted,
    required this.onReminderDeleted,
    required this.onEventCategorySaved,
    this.workspaces = const [],
    this.activeWorkspaceId,
  });

  final List<HermesReminder> reminders;
  final List<HermesEventCategory> eventCategories;
  final Future<void> Function(
    HermesReminder? reminder, {
    required String title,
    required String remindAt,
    String status,
    String? category,
    String? color,
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

  @override
  Widget build(BuildContext context) {
    final visibleReminders = widget.reminders
        .where(
          (reminder) =>
              _showAll || _reminderIsCompleted(reminder) == _showCompleted,
        )
        .toList();
    visibleReminders.sort(_compareRemindersByCompletionAndDueDate);
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
        if (visibleReminders.isEmpty)
          _EmptySurface(
            label: _showAll
                ? 'No reminders yet'
                : _showCompleted
                ? 'No completed reminders'
                : 'No pending reminders',
          )
        else
          for (final reminder in visibleReminders)
            _ReminderItemTile(
              reminder: reminder,
              subtitle: _reminderSubtitle(reminder),
              onTap: () => _showReminderEditor(context, reminder: reminder),
              onCompleted: widget.onReminderCompleted,
            ),
      ],
    );
  }

  Future<void> _showReminderEditor(
    BuildContext context, {
    HermesReminder? reminder,
  }) async {
    final result = await _showTitleTimeEditor(
      context,
      title: reminder == null ? 'New reminder' : 'Edit reminder',
      titleLabel: 'Reminder title',
      timeLabel: 'Remind me at',
      initialTitle: reminder?.title ?? '',
      initialTime: _formatCalendarEventDateTime(reminder?.dueAt),
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
      initialGoogleCalendarIds: reminder?.googleCalendarIds ?? const [],
      initialSyncWorkspaceIds: reminder == null
          ? const []
          : _initialSyncWorkspaceIds(
              linkedWorkspaceIds: reminder.linkedWorkspaceIds,
              workspaceId: reminder.workspaceId,
              activeWorkspaceId: widget.activeWorkspaceId,
            ),
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

class _SettingsView extends StatelessWidget {
  const _SettingsView({
    required this.apiClient,
    required this.launchExternalUrl,
    required this.user,
    this.googleCalendarStatus,
    required this.calendarStartHour,
    required this.calendarEndHour,
    required this.onCalendarStartHourChanged,
    required this.onCalendarEndHourChanged,
    required this.onDeleteAccount,
    required this.onSignOut,
    required this.onAccountEmailChanged,
    required this.onNotificationPreferencesChanged,
    required this.onEditAgentOnboarding,
    required this.onWorkspacesChanged,
    this.error,
  });

  final HermesApiClient apiClient;
  final ExternalUrlLauncher launchExternalUrl;
  final HermesUser user;
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
              Text(error!, style: const TextStyle(color: HeyBeanTheme.warning)),
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
            _NotificationPreferencesCard(
              preferences: user.notificationPreferences,
              onChanged: onNotificationPreferencesChanged,
            ),
            const SizedBox(height: 8),
            _WorkspacesSettingsCard(
              apiClient: apiClient,
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
      ),
    ],
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
          child: const Row(
            children: [
              Icon(
                Icons.notifications_active_outlined,
                color: HeyBeanTheme.accentStrong,
              ),
              SizedBox(width: 12),
              Expanded(
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
    required this.user,
    required this.onChanged,
    this.googleCalendarStatus,
  });

  final HermesApiClient apiClient;
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
      return const Icon(
        Icons.check_circle_rounded,
        color: Color(0xFF16A34A),
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
                const Icon(
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
              Text(
                _message!,
                style: const TextStyle(color: HeyBeanTheme.muted),
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
                FilledButton.icon(
                  key: const Key('workspace-create-household-action'),
                  onPressed: _busy ? null : _createHousehold,
                  icon: const Icon(Icons.add_home_rounded),
                  label: const Text('Add household'),
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
              const Icon(
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
                const Icon(
                  Icons.sync_rounded,
                  color: HeyBeanTheme.accentStrong,
                ),
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
              SelectableText(
                _message!,
                style: const TextStyle(color: HeyBeanTheme.muted),
              ),
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

List<HermesTask> _tasksForDay(List<HermesTask> tasks, DateTime day) {
  final selectedDay = _dateOnly(day);
  final today = _dateOnly(DateTime.now());
  final visible = tasks.where((task) {
    if (_taskIsRecurring(task)) return true;
    if (_taskIsOverdue(task)) return _sameCalendarDay(selectedDay, today);
    final dueAt = _parseTaskDueDate(task);
    if (dueAt == null) return _sameCalendarDay(selectedDay, today);
    return _sameCalendarDay(_dateOnly(dueAt), selectedDay);
  }).toList();
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
  return _tasksForDay(tasks, DateTime.now())
      .where(
        (task) =>
            _taskIsCritical(task) &&
            !_taskIsCompleted(task) &&
            !_taskIsSubtask(task),
      )
      .toList();
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
    if (!_reminderIsCritical(reminder) || _reminderIsCompleted(reminder)) {
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
    return _colorFromHex(_beanGreenCategoryColor);
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
  });

  final HermesUser user;
  final Future<void> Function(String email) onEmailChanged;
  final Future<void> Function() onDeleteAccount;
  final Future<void> Function() onSignOut;
  final ExternalUrlLauncher launchExternalUrl;

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
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          runSpacing: 4,
          children: [
            TextButton(
              key: const Key('settings-privacy-policy-link'),
              onPressed: () => launchExternalUrl(_privacyPolicyUrl),
              child: const Text('Privacy Policy'),
            ),
            TextButton(
              key: const Key('settings-terms-of-service-link'),
              onPressed: () => launchExternalUrl(_termsOfServiceUrl),
              child: const Text('Terms of Use'),
            ),
            TextButton(
              key: const Key('settings-support-link'),
              onPressed: () => launchExternalUrl(_supportUrl),
              child: const Text('Support'),
            ),
          ],
        ),
      ],
    ),
  );
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
    icon: const Icon(
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
                    const Padding(
                      padding: EdgeInsets.only(top: 5),
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
  Widget build(BuildContext context) => Card(
    child: Container(
      decoration: glow
          ? BoxDecoration(
              borderRadius: BorderRadius.circular(18),
              boxShadow: const [
                BoxShadow(
                  color: Color(0x1716A34A),
                  blurRadius: 24,
                  offset: Offset(0, 12),
                ),
              ],
            )
          : null,
      padding: const EdgeInsets.all(18),
      child: child,
    ),
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

class _BeanIntroCallout extends StatelessWidget {
  const _BeanIntroCallout({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => GestureDetector(
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
            boxShadow: const [
              BoxShadow(
                color: Color(0x2416A34A),
                blurRadius: 24,
                offset: Offset(0, 10),
              ),
            ],
          ),
          child: const Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.eco_rounded, color: HeyBeanTheme.accentStrong),
              SizedBox(width: 10),
              Flexible(
                child: Text(
                  'Start by introducing yourself to Bean',
                  key: Key('bean-intro-callout-text'),
                  style: TextStyle(fontWeight: FontWeight.w800),
                ),
              ),
            ],
          ),
        ),
        CustomPaint(
          size: const Size(28, 22),
          painter: _BeanIntroArrowPainter(),
        ),
      ],
    ),
  );
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

class _HeyBeanBottomMenu extends StatelessWidget {
  const _HeyBeanBottomMenu({
    required this.selected,
    required this.onSelected,
    required this.beanListening,
    required this.onBeanLongPressStart,
    required this.onBeanLongPressEnd,
  });

  final _HomeDestination selected;
  final ValueChanged<_HomeDestination> onSelected;
  final bool beanListening;
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
            top: 22,
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
                        key: const Key('nav-today'),
                        icon: Icons.today_rounded,
                        label: 'Calendar',
                        selected: selected == _HomeDestination.today,
                        onPressed: () => onSelected(_HomeDestination.today),
                      ),
                    ),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-tasks'),
                        icon: Icons.task_alt_rounded,
                        label: 'Tasks',
                        selected: selected == _HomeDestination.tasks,
                        onPressed: () => onSelected(_HomeDestination.tasks),
                      ),
                    ),
                    const SizedBox(width: 96),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-reminders'),
                        icon: Icons.notifications_active_rounded,
                        label: 'Reminders',
                        selected: selected == _HomeDestination.reminders,
                        onPressed: () => onSelected(_HomeDestination.reminders),
                      ),
                    ),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-settings'),
                        icon: Icons.settings_rounded,
                        label: 'Settings',
                        selected: selected == _HomeDestination.settings,
                        onPressed: () => onSelected(_HomeDestination.settings),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Positioned(
            top: 7,
            child: _BeanFab(
              selected: selected == _HomeDestination.bean,
              listening: beanListening,
              onPressed: () => onSelected(_HomeDestination.bean),
              onLongPressStart: onBeanLongPressStart,
              onLongPressEnd: onBeanLongPressEnd,
            ),
          ),
        ],
      ),
    );
  }
}

class _MenuIconButton extends StatelessWidget {
  const _MenuIconButton({
    super.key,
    required this.icon,
    required this.label,
    required this.onPressed,
    this.selected = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback onPressed;
  final bool selected;

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.transparent,
    child: InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: onPressed,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 1),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              icon,
              color: selected ? HeyBeanTheme.accentStrong : HeyBeanTheme.muted,
              size: 20,
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

  @override
  Widget build(BuildContext context) => GestureDetector(
    key: const Key('nav-bean'),
    onLongPressStart: (_) => widget.onLongPressStart(),
    onLongPressEnd: (_) => widget.onLongPressEnd(),
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
                    color: const Color(
                      0xFF22C55E,
                    ).withValues(alpha: .14 + (pulse * .10)),
                    boxShadow: [
                      BoxShadow(
                        color: const Color(
                          0xFF22C55E,
                        ).withValues(alpha: .42 + (pulse * .24)),
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
            child: InkWell(
              customBorder: const CircleBorder(),
              onTap: widget.onPressed,
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 180),
                key: const Key('heybean-center-bean-button'),
                width: 72,
                height: 72,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: const LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [
                      Color(0xFF22C55E),
                      Color(0xFF16A34A),
                      Color(0xFF15803D),
                    ],
                  ),
                  border: Border.all(
                    color: widget.listening
                        ? const Color(0xFFBBF7D0)
                        : (widget.selected
                              ? Colors.white
                              : const Color(0xFFE2E8F0)),
                    width: widget.listening ? 7 : 4,
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: widget.listening
                          ? const Color(0x8F22C55E)
                          : const Color(0x3D16A34A),
                      blurRadius: widget.listening ? 36 : 24,
                      spreadRadius: widget.listening ? 5 : 0,
                      offset: const Offset(0, 10),
                    ),
                  ],
                ),
                child: Center(
                  child: Image.asset(
                    'assets/images/bean/bean-logo-white-overlay.png',
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
