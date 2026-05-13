import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'hermes_api_client.dart';

void main() {
  runApp(HermesBeanApp());
}

abstract class AuthTokenStore {
  Future<String?> loadToken();
  Future<void> saveToken(String token);
  Future<void> clearToken();
}

class SharedPreferencesAuthTokenStore implements AuthTokenStore {
  const SharedPreferencesAuthTokenStore();

  static const String _tokenKey = 'auth_token';

  @override
  Future<String?> loadToken() async {
    final preferences = await SharedPreferences.getInstance();
    return preferences.getString(_tokenKey);
  }

  @override
  Future<void> saveToken(String token) async {
    final preferences = await SharedPreferences.getInstance();
    await preferences.setString(_tokenKey, token);
  }

  @override
  Future<void> clearToken() async {
    final preferences = await SharedPreferences.getInstance();
    await preferences.remove(_tokenKey);
  }
}

class HermesBeanApp extends StatelessWidget {
  HermesBeanApp({
    super.key,
    HermesApiClient? apiClient,
    AuthTokenStore? tokenStore,
  }) : apiClient = apiClient ?? HermesApiClient(),
       tokenStore = tokenStore ?? const SharedPreferencesAuthTokenStore();

  final HermesApiClient apiClient;
  final AuthTokenStore tokenStore;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Hermes Bean',
      debugShowCheckedModeBanner: false,
      theme: HeyBeanTheme.lightTheme,
      home: CommandCenterShell(apiClient: apiClient, tokenStore: tokenStore),
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

enum _AuthPhase { loading, signedOut, signedIn }

enum _HomeDestination { today, tasks, bean, reminders, settings }

class CommandCenterShell extends StatefulWidget {
  const CommandCenterShell({
    super.key,
    required this.apiClient,
    required this.tokenStore,
  });

  final HermesApiClient apiClient;
  final AuthTokenStore tokenStore;

  @override
  State<CommandCenterShell> createState() => _CommandCenterShellState();
}

class _CommandCenterShellState extends State<CommandCenterShell> {
  _AuthPhase _phase = _AuthPhase.loading;
  HermesUser? _user;
  HermesSession? _session;
  List<HermesTask> _tasks = const [];
  List<HermesTask> _pastTasks = const [];
  List<HermesReminder> _reminders = const [];
  List<HermesCalendarEvent> _calendar = const [];
  List<HermesEventCategory> _eventCategories = const [];
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
  bool _busy = false;
  String _chatRunState = 'Ready';
  _HomeDestination _selectedDestination = _HomeDestination.today;
  bool _showCalendarMonth = false;
  DateTime _selectedCalendarDay = _dateOnly(DateTime.now());
  int _calendarStartHour = _defaultCalendarStartHour;
  int _calendarEndHour = _defaultCalendarEndHour;

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  Future<void> _bootstrap() async {
    widget.apiClient.bearerToken ??= await widget.tokenStore.loadToken();
    if (widget.apiClient.bearerToken == null) {
      setState(() => _phase = _AuthPhase.signedOut);
      return;
    }
    await _loadSignedIn();
  }

  Future<void> _loadSignedIn({HermesUser? knownUser}) async {
    setState(() {
      _phase = _AuthPhase.loading;
      _error = null;
    });
    try {
      final user = knownUser ?? await widget.apiClient.me();
      final session = await widget.apiClient.startSession(
        title: 'Today',
        metadata: {'source': 'flutter'},
      );
      final results = await Future.wait<Object>([
        widget.apiClient.todaySummary(),
        widget.apiClient.listPastTasks().catchError(
          (_) => const <HermesTask>[],
        ),
        widget.apiClient.listEventCategories().catchError(
          (_) => const <HermesEventCategory>[],
        ),
        widget.apiClient.pollActivityEvents(session.id),
      ]);
      final summary = results[0] as HermesTodaySummary;
      if (!mounted) return;
      setState(() {
        _user = user;
        _session = session;
        _tasks = summary.tasks;
        _pastTasks = results[1] as List<HermesTask>;
        _eventCategories = results[2] as List<HermesEventCategory>;
        _reminders = summary.reminders;
        _calendar = summary.calendarEvents;
        _approvals = summary.approvals;
        _events = results[3] as List<HermesActivityEvent>;
        _phase = _AuthPhase.signedIn;
      });
    } catch (error) {
      if (!mounted) return;
      await widget.tokenStore.clearToken();
      widget.apiClient.bearerToken = null;
      setState(() {
        _error =
            'Session expired or the API could not be reached. Please sign in again.';
        _user = null;
        _session = null;
        _tasks = const [];
        _pastTasks = const [];
        _reminders = const [];
        _calendar = const [];
        _eventCategories = const [];
        _approvals = const [];
        _events = const [];
        _phase = _AuthPhase.signedOut;
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
        await widget.tokenStore.saveToken(auth.token);
      } else {
        await widget.tokenStore.clearToken();
      }
      await _loadSignedIn(knownUser: auth.user);
    } catch (error) {
      setState(() => _error = 'Sign in failed: $error');
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
      await _loadSignedIn(knownUser: auth.user);
    } catch (error) {
      setState(() => _error = 'Registration failed: $error');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _sendChat(String content) async {
    final trimmed = content.trim();
    final session = _session;
    if (trimmed.isEmpty || session == null) return;
    setState(() {
      _busy = true;
      _chatRunState = 'Hermes is working…';
      _messages.add(
        HermesMessage(id: _messages.length + 1, role: 'user', content: trimmed),
      );
    });
    try {
      final result = await widget.apiClient.sendMessage(
        sessionId: session.id,
        content: trimmed,
        metadata: {'source': 'flutter'},
      );
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
      if (!mounted) return;
      setState(() {
        _session = result.session;
        if (result.assistantMessage != null) {
          _messages.add(_displayableAssistantMessage(result.assistantMessage!));
        } else if (result.status == 'blocked' && result.blocker != null) {
          final reason = _readBlockerReason(result.blocker);
          _messages.add(
            HermesMessage(
              id: _messages.length + 1,
              role: 'assistant',
              content: reason == null || reason.isEmpty
                  ? 'Hermes is blocked and needs attention before it can continue.'
                  : 'Hermes is blocked: $reason',
            ),
          );
        } else if (result.assistantMessage == null) {
          _messages.add(
            HermesMessage(
              id: _messages.length + 1,
              role: 'assistant',
              content:
                  'Hermes finished, but did not return a response. Please clarify what you want me to do next and I will continue.',
            ),
          );
        }
        _chatRunState = result.status == 'blocked' ? 'Blocked' : 'Updated';
        _tasks = refreshedSummary.tasks;
        _reminders = refreshedSummary.reminders;
        _calendar = refreshedSummary.calendarEvents;
        _approvals = refreshedSummary.approvals;
        _events = _mergeEvents(result.events, refreshedEvents);
      });
    } catch (error) {
      setState(() {
        _chatRunState = 'Failed';
        _messages.add(
          HermesMessage(
            id: _messages.length + 1,
            role: 'assistant',
            content: _chatFailureMessage(error),
          ),
        );
        _error = 'Send failed: $error';
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  HermesMessage _displayableAssistantMessage(HermesMessage message) {
    return HermesMessage(
      id: message.id,
      role: message.role,
      content: _naturalLanguageContent(message.content),
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
      if (value is String && value.trim().isNotEmpty) return value.trim();
    }
    final context = blocker['context'];
    if (context is Map<String, Object?>) {
      final detail =
          context['message'] ?? context['error'] ?? context['failure_type'];
      if (detail is String && detail.trim().isNotEmpty) return detail.trim();
    }
    return null;
  }

  String _chatFailureMessage(Object error) {
    final reason = _extractFailureReason(error);
    return reason == null || reason.isEmpty
        ? 'I could not complete that request because the Hermes API could not be reached. Please try again, or clarify any missing details so I can continue.'
        : 'I could not complete that request because $reason Please try again, or clarify any missing details so I can continue.';
  }

  String? _extractFailureReason(Object error) {
    if (error is HermesApiException) {
      try {
        final decoded = jsonDecode(error.body);
        if (decoded is Map<String, Object?>) {
          final message =
              decoded['message'] ?? decoded['error'] ?? decoded['reason'];
          if (message is String && message.trim().isNotEmpty) {
            return _sentenceFragment(message);
          }
        }
      } catch (_) {
        // Fall through to the exception status below.
      }
      return 'the API returned HTTP ${error.statusCode}.';
    }
    return null;
  }

  String _sentenceFragment(String message) {
    final trimmed = message.trim();
    if (trimmed.isEmpty) return trimmed;
    return trimmed.endsWith('.') ||
            trimmed.endsWith('!') ||
            trimmed.endsWith('?')
        ? trimmed
        : '$trimmed.';
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

  void _openCalendarMonth() {
    setState(() {
      _selectedDestination = _HomeDestination.today;
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

  void _setCalendarStartHour(int hour) {
    setState(() {
      _calendarStartHour = hour.clamp(0, 22);
      if (_calendarEndHour <= _calendarStartHour) {
        _calendarEndHour = (_calendarStartHour + 1).clamp(1, 23);
      }
    });
  }

  void _setCalendarEndHour(int hour) {
    setState(() {
      _calendarEndHour = hour.clamp(_calendarStartHour + 1, 23);
    });
  }

  Future<void> _refreshSignedInViews() async {
    final session = _session;
    if (_phase != _AuthPhase.signedIn || session == null) return;
    try {
      final results = await Future.wait<Object>([
        widget.apiClient.todaySummary(),
        widget.apiClient.listPastTasks().catchError(
          (_) => const <HermesTask>[],
        ),
        widget.apiClient.listEventCategories().catchError(
          (_) => const <HermesEventCategory>[],
        ),
        widget.apiClient.pollActivityEvents(session.id),
      ]);
      final summary = results[0] as HermesTodaySummary;
      if (!mounted) return;
      setState(() {
        _tasks = summary.tasks;
        _pastTasks = results[1] as List<HermesTask>;
        _eventCategories = results[2] as List<HermesEventCategory>;
        _reminders = summary.reminders;
        _calendar = summary.calendarEvents;
        _approvals = summary.approvals;
        _events = results[3] as List<HermesActivityEvent>;
        _error = null;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() => _error = 'Refresh failed: $error');
    }
  }

  Future<void> _toggleTaskCompletion(HermesTask task) async {
    final wasCompleted = _taskIsCompleted(task);
    final previousTasks = _tasks;
    final previousPastTasks = _pastTasks;
    final optimisticTask = wasCompleted
        ? task.copyWith(status: 'open', clearCompletedAt: true)
        : task.copyWith(
            status: 'completed',
            completedAt: DateTime.now().toIso8601String(),
          );
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
      setState(() {
        if (_tasks.any((candidate) => candidate.id == updatedTask.id)) {
          _tasks = _replaceTask(_tasks, updatedTask);
        }
        if (_pastTasks.any((candidate) => candidate.id == updatedTask.id)) {
          _pastTasks = _replaceTask(_pastTasks, updatedTask);
        }
      });
      await _refreshSignedInViews();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _tasks = previousTasks;
        _pastTasks = previousPastTasks;
        _error = wasCompleted
            ? 'Could not reopen task: $error'
            : 'Could not complete task: $error';
      });
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
    setState(() {
      final exists = _eventCategories.any((item) => item.id == saved.id);
      _eventCategories = exists
          ? _eventCategories
                .map((item) => item.id == saved.id ? saved : item)
                .toList()
          : [..._eventCategories, saved];
    });
    return saved;
  }

  Future<void> _deleteEventCategory(HermesEventCategory category) async {
    await widget.apiClient.deleteEventCategory(category.id);
    if (!mounted) return;
    setState(() {
      _eventCategories = _eventCategories
          .where((item) => item.id != category.id)
          .toList();
      _calendar = _calendar
          .map(
            (event) => event.category == category.name
                ? event.copyWith(category: null)
                : event,
          )
          .toList();
    });
  }

  Future<void> _editCalendarEvent(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? category,
    String? color,
    String? recurrence,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
  }) async {
    final previousCalendar = _calendar;
    final optimisticEvent = event.copyWith(
      title: title,
      startsAt: startsAt,
      endsAt: endsAt,
      category: category,
      color: color,
      recurrence: recurrence,
    );
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
        startsAt: startsAt,
        endsAt: endsAt,
        category: category,
        color: color,
        recurrence: recurrence,
      );
      if (reminderMinutesBefore != null && reminderMinutesBefore > 0) {
        final start = _parseCalendarEventDateTime(startsAt);
        if (start != null) {
          await widget.apiClient.createEventReminder(
            calendarEventId: event.id,
            title: 'Reminder: $title',
            remindAt: start
                .subtract(Duration(minutes: reminderMinutesBefore))
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
      setState(() {
        _calendar = _calendar
            .map(
              (candidate) =>
                  candidate.id == event.id ? updatedEvent : candidate,
            )
            .toList();
      });
      await _refreshSignedInViews();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _calendar = previousCalendar;
        _error = 'Could not update event: $error';
      });
    }
  }

  Future<void> _deleteAccount() async {
    setState(() => _busy = true);
    try {
      await widget.apiClient.deleteAccount();
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _phase = _AuthPhase.signedOut;
          _user = null;
        });
        await widget.tokenStore.clearToken();
      }
    }
  }

  @override
  Widget build(BuildContext context) {
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
                titleSpacing: 20,
                title: _phase == _AuthPhase.signedIn
                    ? _CalendarHeaderButton(
                        key: const Key('calendar-month-chevron'),
                        label: _monthName(_selectedCalendarDay.month),
                        icon: Icons.chevron_left_rounded,
                        onTap: _openCalendarMonth,
                      )
                    : null,
                actions: [
                  if (_phase == _AuthPhase.signedIn) ...[
                    _CalendarHeaderButton(
                      key: const Key('calendar-today-button'),
                      label: 'Today',
                      icon: Icons.today_rounded,
                      iconSize: 16,
                      horizontalPadding: 10,
                      verticalPadding: 7,
                      labelStyle: const TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w800,
                      ),
                      onTap: _returnToToday,
                    ),
                    const SizedBox(width: 8),
                    _CriticalTaskBadge(
                      count:
                          _visibleSortedTasks(_tasks).length +
                          _reminders.length,
                    ),
                  ],
                  const SizedBox(width: 16),
                ],
              ),
              body: SafeArea(child: _body()),
              bottomNavigationBar: _phase == _AuthPhase.signedIn
                  ? _HeyBeanBottomMenu(
                      selected: _selectedDestination,
                      onSelected: (destination) =>
                          setState(() => _selectedDestination = destination),
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
      return const Center(child: CircularProgressIndicator());
    }
    if (_phase == _AuthPhase.signedOut) {
      return _SignedOutScreen(
        onLogin: _login,
        onRegister: _register,
        busy: _busy,
        error: _error,
      );
    }
    return RefreshIndicator(
      key: const Key('signed-in-refresh-indicator'),
      onRefresh: _refreshSignedInViews,
      child: SingleChildScrollView(
        key: const Key('signed-in-refresh-scroll'),
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 112),
        child: _CommandCenterContent(
          user: _user!,
          tasks: _tasks,
          pastTasks: _pastTasks,
          reminders: _reminders,
          calendar: _calendar,
          eventCategories: _eventCategories,
          approvals: _approvals,
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
          onBackToCalendarDay: _returnToCalendarDay,
          onCalendarStartHourChanged: _setCalendarStartHour,
          onCalendarEndHourChanged: _setCalendarEndHour,
          onSelectDestination: (destination) =>
              setState(() => _selectedDestination = destination),
          onSend: _sendChat,
          onTaskCompleted: _toggleTaskCompletion,
          onCalendarEventEdited: _editCalendarEvent,
          onEventCategorySaved: _saveEventCategory,
          onEventCategoryDeleted: _deleteEventCategory,
          onDeleteAccount: _deleteAccount,
        ),
      ),
    );
  }
}

class _SignedOutScreen extends StatefulWidget {
  const _SignedOutScreen({
    required this.onLogin,
    required this.onRegister,
    required this.busy,
    this.error,
  });

  final Future<void> Function(
    String email,
    String password, {
    required bool rememberMe,
  })
  onLogin;
  final Future<void> Function(String name, String email, String password)
  onRegister;
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
  void dispose() {
    _name.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _showForgotLoginDialog() async {
    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Forgot login?'),
        content: const Text(
          'Password reset is not wired yet. For local testing, create a new test account from this screen with any email and a 12+ character password.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('OK'),
          ),
        ],
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
    final title = _registerMode
        ? 'Create your Hermes Bean account'
        : 'Sign in to Hermes Bean';
    final subtitle = _registerMode
        ? 'Use any test email and a 12+ character password'
        : 'Live API-backed personal assistant';

    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 440),
        child: _ShellCard(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _SectionTitle(
                icon: _registerMode
                    ? Icons.person_add_alt_1_rounded
                    : Icons.lock_rounded,
                title: title,
                subtitle: subtitle,
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
                  helperText: _registerMode ? 'Minimum 12 characters' : null,
                ),
              ),
              if (!_registerMode) ...[
                const SizedBox(height: 8),
                CheckboxListTile(
                  key: const Key('remember-me-checkbox'),
                  value: _rememberMe,
                  onChanged: widget.busy
                      ? null
                      : (value) => setState(() => _rememberMe = value ?? false),
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
                      ? (_registerMode ? 'Creating account…' : 'Signing in…')
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
                      _registerMode ? 'show-login-mode' : 'show-register-mode',
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
                    onPressed: widget.busy ? null : _showForgotLoginDialog,
                    child: const Text('Forgot login?'),
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

class _CommandCenterContent extends StatelessWidget {
  const _CommandCenterContent({
    required this.user,
    required this.tasks,
    required this.pastTasks,
    required this.reminders,
    required this.calendar,
    required this.eventCategories,
    required this.approvals,
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
    required this.onBackToCalendarDay,
    required this.onCalendarStartHourChanged,
    required this.onCalendarEndHourChanged,
    required this.onSelectDestination,
    required this.onSend,
    required this.onTaskCompleted,
    required this.onCalendarEventEdited,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
    required this.onDeleteAccount,
    this.error,
  });

  final HermesUser user;
  final List<HermesTask> tasks;
  final List<HermesTask> pastTasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendar;
  final List<HermesEventCategory> eventCategories;
  final List<HermesApproval> approvals;
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
  final VoidCallback onBackToCalendarDay;
  final ValueChanged<int> onCalendarStartHourChanged;
  final ValueChanged<int> onCalendarEndHourChanged;
  final ValueChanged<_HomeDestination> onSelectDestination;
  final Future<void> Function(String content) onSend;
  final Future<void> Function(HermesTask task) onTaskCompleted;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? category,
    String? color,
    String? recurrence,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
  })
  onCalendarEventEdited;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(HermesEventCategory category)
  onEventCategoryDeleted;
  final Future<void> Function() onDeleteAccount;
  final String? error;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final pendingApprovals = approvals
            .where((approval) => (approval.status ?? 'pending') == 'pending')
            .toList();
        final activeTasks = _visibleSortedTasks(tasks);
        final beanPanel = Column(
          children: [
            if (pendingApprovals.isNotEmpty) ...[
              _ApprovalBanner(approval: pendingApprovals.first),
              const SizedBox(height: 16),
            ],
            _HeroChatCard(
              messages: messages,
              busy: busy,
              runState: chatRunState,
              onSend: onSend,
            ),
            const SizedBox(height: 16),
            _ApprovalCard(approvals: pendingApprovals),
            const SizedBox(height: 16),
            _ProgressCard(
              user: user,
              error: error,
              taskCount: activeTasks.length,
            ),
            const SizedBox(height: 16),
            _TabSurface(
              tasks: activeTasks,
              reminders: reminders,
              calendar: calendar,
              events: events,
            ),
            const SizedBox(height: 16),
            _ActivityCard(events: events),
            const SizedBox(height: 16),
            _AccountCard(user: user, onDeleteAccount: onDeleteAccount),
          ],
        );
        final selectedPanel = switch (selectedDestination) {
          _HomeDestination.today => _TodayHomeView(
            user: user,
            tasks: activeTasks,
            reminders: reminders,
            calendar: calendar,
            eventCategories: eventCategories,
            approvals: pendingApprovals,
            selectedDay: selectedCalendarDay,
            showMonth: showCalendarMonth,
            startHour: calendarStartHour,
            endHour: calendarEndHour,
            onDateSelected: onCalendarDaySelected,
            onBackToDay: onBackToCalendarDay,
            onTaskCompleted: onTaskCompleted,
            onCalendarEventEdited: onCalendarEventEdited,
            onEventCategorySaved: onEventCategorySaved,
            onEventCategoryDeleted: onEventCategoryDeleted,
          ),
          _HomeDestination.tasks => _TaskListCard(
            tasks: activeTasks,
            onTaskCompleted: onTaskCompleted,
          ),
          _HomeDestination.bean => beanPanel,
          _HomeDestination.reminders => _ReminderListCard(reminders: reminders),
          _HomeDestination.settings => _SettingsView(
            user: user,
            approvals: pendingApprovals,
            pastTasks: pastTasks,
            onTaskCompleted: onTaskCompleted,
            calendarStartHour: calendarStartHour,
            calendarEndHour: calendarEndHour,
            onCalendarStartHourChanged: onCalendarStartHourChanged,
            onCalendarEndHourChanged: onCalendarEndHourChanged,
            onDeleteAccount: onDeleteAccount,
          ),
        };
        final right = Column(
          children: [
            _AccountCard(user: user, onDeleteAccount: onDeleteAccount),
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
    required this.onSend,
  });

  final List<HermesMessage> messages;
  final bool busy;
  final String runState;
  final Future<void> Function(String content) onSend;

  @override
  State<_HeroChatCard> createState() => _HeroChatCardState();
}

class _HeroChatCardState extends State<_HeroChatCard> {
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => _ShellCard(
    key: const Key('chat-view'),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.chat_bubble_rounded,
          title: 'Bean',
          subtitle: 'Chat-first command center for your household',
        ),
        const SizedBox(height: 10),
        _ChatRunStatePill(label: widget.runState),
        const SizedBox(height: 14),
        _QuickPromptRail(onPrompt: widget.onSend),
        const SizedBox(height: 18),
        for (final message in widget.messages) ...[
          _MessageBubble(
            sender: message.role == 'user' ? 'You' : 'Hermes',
            message: message.content ?? '',
            alignRight: message.role == 'user',
          ),
          const SizedBox(height: 10),
        ],
        Container(
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(
            color: HeyBeanTheme.surface2,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: HeyBeanTheme.border),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: TextField(
                  key: const Key('chat-input'),
                  controller: _controller,
                  minLines: 1,
                  maxLines: 4,
                  textInputAction: TextInputAction.send,
                  onSubmitted: widget.busy
                      ? null
                      : (text) {
                          _controller.clear();
                          widget.onSend(text);
                        },
                  decoration: const InputDecoration(
                    hintText:
                        'Ask Bean to create tasks, reminders, or calendar events...',
                    border: InputBorder.none,
                    enabledBorder: InputBorder.none,
                    focusedBorder: InputBorder.none,
                    filled: false,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              FilledButton(
                key: const Key('primary-chat-action'),
                onPressed: widget.busy
                    ? null
                    : () {
                        final text = _controller.text;
                        _controller.clear();
                        widget.onSend(text);
                      },
                child: const Icon(Icons.arrow_upward_rounded, size: 18),
              ),
            ],
          ),
        ),
      ],
    ),
  );
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
  });

  final String sender;
  final String message;
  final bool alignRight;

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
          Text(
            sender,
            style: const TextStyle(
              color: HeyBeanTheme.accentStrong,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 4),
          Text(message),
        ],
      ),
    ),
  );
}

class _ApprovalBanner extends StatelessWidget {
  const _ApprovalBanner({required this.approval});

  final HermesApproval approval;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(16),
    decoration: BoxDecoration(
      color: const Color(0xFFFFFBEB),
      borderRadius: BorderRadius.circular(22),
      border: Border.all(color: const Color(0xFFF59E0B).withValues(alpha: .42)),
      boxShadow: [
        BoxShadow(
          color: const Color(0xFFF59E0B).withValues(alpha: .12),
          blurRadius: 22,
          offset: const Offset(0, 12),
        ),
      ],
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            color: HeyBeanTheme.warning,
            borderRadius: BorderRadius.circular(16),
          ),
          child: const Icon(Icons.priority_high_rounded, color: Colors.white),
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Approval needed',
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: HeyBeanTheme.text,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                approval.title,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: HeyBeanTheme.muted,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(width: 12),
        FilledButton(
          key: const Key('review-approval-action'),
          onPressed: () => showDialog<void>(
            context: context,
            builder: (context) => AlertDialog(
              title: const Text('Pending approval'),
              content: Text(approval.title),
              actions: [
                TextButton(
                  onPressed: () => Navigator.of(context).pop(),
                  child: const Text('OK'),
                ),
              ],
            ),
          ),
          child: const Text('Review'),
        ),
      ],
    ),
  );
}

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
    required this.reminders,
    required this.calendar,
    required this.eventCategories,
    required this.approvals,
    required this.selectedDay,
    required this.showMonth,
    required this.startHour,
    required this.endHour,
    required this.onDateSelected,
    required this.onBackToDay,
    required this.onTaskCompleted,
    required this.onCalendarEventEdited,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final HermesUser user;
  final List<HermesTask> tasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendar;
  final List<HermesEventCategory> eventCategories;
  final List<HermesApproval> approvals;
  final DateTime selectedDay;
  final bool showMonth;
  final int startHour;
  final int endHour;
  final ValueChanged<DateTime> onDateSelected;
  final VoidCallback onBackToDay;
  final Future<void> Function(HermesTask task) onTaskCompleted;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? category,
    String? color,
    String? recurrence,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
  })
  onCalendarEventEdited;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(HermesEventCategory category)
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) => Column(
    key: const Key('today-view'),
    children: [
      if (approvals.isNotEmpty) ...[
        _ApprovalBanner(approval: approvals.first),
        const SizedBox(height: 16),
      ],
      _ShellCard(
        key: const Key('calendar-view'),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (showMonth) ...[
              _MonthCalendarHeader(
                month: selectedDay,
                onBackToDay: onBackToDay,
              ),
              const SizedBox(height: 16),
              _MonthGrid(
                calendar: calendar,
                selectedDay: selectedDay,
                onDateSelected: onDateSelected,
              ),
              const SizedBox(height: 16),
              _CalendarMonthTaskList(
                tasks: tasks,
                reminders: reminders,
                calendar: calendar,
                onTaskCompleted: onTaskCompleted,
              ),
            ] else ...[
              _AppleStyleTodayTimeline(
                calendar: calendar,
                eventCategories: eventCategories,
                selectedDay: selectedDay,
                startHour: startHour,
                endHour: endHour,
                onDayChanged: onDateSelected,
                onEventTap: onCalendarEventEdited,
                onEventCategorySaved: onEventCategorySaved,
                onEventCategoryDeleted: onEventCategoryDeleted,
              ),
            ],
          ],
        ),
      ),
      const SizedBox(height: 16),
      _ShellCard(
        key: const Key('today-task-list'),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _SectionTitle(
              icon: Icons.task_alt_rounded,
              title: 'Tasks for today',
              subtitle: '${tasks.length} tasks · ${reminders.length} reminders',
            ),
            const SizedBox(height: 12),
            if (tasks.isEmpty && reminders.isEmpty)
              const _EmptySurface(label: 'Nothing scheduled for today')
            else ...[
              for (final task in tasks)
                _TaskItemTile(
                  task: task,
                  subtitle: _statusLabel(task.status),
                  onCompleted: onTaskCompleted,
                ),
              for (final reminder in reminders)
                _CompactItemTile(
                  icon: Icons.notifications_active_outlined,
                  title: reminder.title,
                  subtitle: reminder.dueAt ?? 'Reminder',
                ),
            ],
          ],
        ),
      ),
    ],
  );
}

class _CriticalTaskBadge extends StatelessWidget {
  const _CriticalTaskBadge({required this.count});

  final int count;

  @override
  Widget build(BuildContext context) => Tooltip(
    message: 'Critical tasks',
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

const _defaultCalendarStartHour = 9;
const _defaultCalendarEndHour = 22;
const _calendarHourHeight = 42.0;
const _calendarTimeColumnWidth = 48.0;

class _AppleStyleTodayTimeline extends StatefulWidget {
  const _AppleStyleTodayTimeline({
    required this.calendar,
    required this.eventCategories,
    required this.selectedDay,
    required this.startHour,
    required this.endHour,
    required this.onDayChanged,
    required this.onEventTap,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final List<HermesCalendarEvent> calendar;
  final List<HermesEventCategory> eventCategories;
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
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
  })
  onEventTap;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(HermesEventCategory category)
  onEventCategoryDeleted;

  @override
  State<_AppleStyleTodayTimeline> createState() =>
      _AppleStyleTodayTimelineState();
}

class _AppleStyleTodayTimelineState extends State<_AppleStyleTodayTimeline> {
  static const int _initialDayPage = 10000;
  static const int _daysPerTimelinePage = 2;

  late final PageController _dayPageController;
  late DateTime _pageAnchorDay;
  int _visibleDayPage = _initialDayPage;
  double _headerHorizontalDrag = 0;

  @override
  void initState() {
    super.initState();
    _pageAnchorDay = _dateOnly(widget.selectedDay);
    _dayPageController = PageController(initialPage: _initialDayPage);
  }

  @override
  void didUpdateWidget(covariant _AppleStyleTodayTimeline oldWidget) {
    super.didUpdateWidget(oldWidget);
    final selectedDay = _dateOnly(widget.selectedDay);
    final visiblePage = _dayPageController.hasClients
        ? _dayPageController.page?.round() ?? _initialDayPage
        : _initialDayPage;
    final visibleDay = _dateForPage(visiblePage);

    if (!_sameCalendarDay(selectedDay, visibleDay)) {
      _pageAnchorDay = selectedDay;
      _visibleDayPage = _initialDayPage;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted || !_dayPageController.hasClients) return;
        _dayPageController.jumpToPage(_initialDayPage);
      });
    }
  }

  @override
  void dispose() {
    _dayPageController.dispose();
    super.dispose();
  }

  DateTime _dateForPage(int page) => _pageAnchorDay.add(
    Duration(days: (page - _initialDayPage) * _daysPerTimelinePage),
  );

  void _handleHeaderWeekDragStart(DragStartDetails details) {
    _headerHorizontalDrag = 0;
  }

  void _handleHeaderWeekDragUpdate(DragUpdateDetails details) {
    _headerHorizontalDrag += details.primaryDelta ?? 0;
  }

  void _handleHeaderWeekScroll(DragEndDetails details) {
    final velocity = details.primaryVelocity ?? 0;
    final distance = _headerHorizontalDrag;
    if (velocity.abs() < 80 && distance.abs() < 48) return;
    final direction = velocity.abs() >= 80 ? velocity : distance;
    if (!_dayPageController.hasClients) return;
    final currentPage = _dayPageController.page?.round() ?? _initialDayPage;
    final visibleDay = _dateForPage(currentPage);
    widget.onDayChanged(visibleDay.add(Duration(days: direction < 0 ? 7 : -7)));
  }

  void _handlePageChanged(int page) {
    setState(() => _visibleDayPage = page);
    final nextSelectedDay = _dateForPage(page);
    if (!_sameCalendarDay(nextSelectedDay, widget.selectedDay)) {
      widget.onDayChanged(nextSelectedDay);
    }
  }

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    final today = _dateOnly(now);
    final selectedDay = _dateOnly(widget.selectedDay);
    final weekStartDay = selectedDay.subtract(
      Duration(days: selectedDay.weekday - DateTime.monday),
    );
    final visibleHours = _calendarVisibleHours(
      widget.startHour,
      widget.endHour,
    );
    final timelineHeight = 49 + (visibleHours.length * _calendarHourHeight);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _WeekDateHeader(
          today: today,
          weekStartDay: weekStartDay,
          selectedDay: selectedDay,
          onDateSelected: widget.onDayChanged,
          onHorizontalDayScrollStart: _handleHeaderWeekDragStart,
          onHorizontalDayScrollUpdate: _handleHeaderWeekDragUpdate,
          onHorizontalDayScrollEnd: _handleHeaderWeekScroll,
        ),
        const SizedBox(height: 10),
        Container(
          key: const Key('apple-style-day-timeline'),
          decoration: const BoxDecoration(
            border: Border(top: BorderSide(color: HeyBeanTheme.border)),
          ),
          height: timelineHeight,
          child: Row(
            children: [
              _FixedTimelineHoursColumn(visibleHours: visibleHours),
              Expanded(
                child: PageView.builder(
                  key: const PageStorageKey<String>(
                    'apple-style-day-page-view',
                  ),
                  controller: _dayPageController,
                  physics: const BouncingScrollPhysics(
                    parent: PageScrollPhysics(),
                  ),
                  allowImplicitScrolling: true,
                  onPageChanged: _handlePageChanged,
                  itemBuilder: (context, page) => _TwoDayTimelinePage(
                    key: ValueKey('two-day-timeline-page-$page'),
                    calendar: widget.calendar,
                    eventCategories: widget.eventCategories,
                    selectedDay: _dateForPage(page),
                    today: today,
                    now: now,
                    startHour: widget.startHour,
                    endHour: widget.endHour,
                    visibleHours: visibleHours,
                    isActivePage: page == _visibleDayPage,
                    onEventTap: widget.onEventTap,
                    onEventCategorySaved: widget.onEventCategorySaved,
                    onEventCategoryDeleted: widget.onEventCategoryDeleted,
                  ),
                ),
              ),
            ],
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
    required this.selectedDay,
    required this.today,
    required this.now,
    required this.startHour,
    required this.endHour,
    required this.visibleHours,
    required this.isActivePage,
    required this.onEventTap,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final List<HermesCalendarEvent> calendar;
  final List<HermesEventCategory> eventCategories;
  final DateTime selectedDay;
  final DateTime today;
  final DateTime now;
  final int startHour;
  final int endHour;
  final List<int> visibleHours;
  final bool isActivePage;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? category,
    String? color,
    String? recurrence,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
  })
  onEventTap;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(HermesEventCategory category)
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) {
    final selectedNextDay = selectedDay.add(const Duration(days: 1));
    final firstVisibleHour = visibleHours.first;
    final markerOffset =
        48 +
        ((now.hour + (now.minute / 60)) - firstVisibleHour).clamp(
              0.0,
              visibleHours.length.toDouble(),
            ) *
            _calendarHourHeight;
    final showCurrentTimeMarker =
        _sameCalendarDay(selectedDay, today) ||
        _sameCalendarDay(selectedNextDay, today);

    return Column(
      children: [
        Row(
          children: [
            Expanded(
              child: _DayColumnHeading(
                key: isActivePage
                    ? const Key('day-column-heading-selected')
                    : ValueKey(
                        'day-column-heading-selected-${selectedDay.toIso8601String()}',
                      ),
                date: selectedDay,
                isToday: _sameCalendarDay(selectedDay, today),
              ),
            ),
            Expanded(
              child: _DayColumnHeading(
                key: isActivePage
                    ? const Key('day-column-heading-next')
                    : ValueKey(
                        'day-column-heading-next-${selectedNextDay.toIso8601String()}',
                      ),
                date: selectedNextDay,
                isToday: _sameCalendarDay(selectedNextDay, today),
              ),
            ),
          ],
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
                if (showCurrentTimeMarker)
                  Positioned(
                    key: const Key('calendar-current-time-marker'),
                    top: markerOffset - 48,
                    left: 0,
                    right: 0,
                    child: Row(
                      children: [
                        Expanded(
                          child: Container(
                            height: 2,
                            color: HeyBeanTheme.accent,
                          ),
                        ),
                      ],
                    ),
                  ),
                for (final event in calendar) ...[
                  if (_eventFallsOnDay(event, selectedDay) &&
                      _eventFallsWithinHours(event, startHour, endHour))
                    _TimelineEventBlock(
                      event: event,
                      startHour: startHour,
                      endHour: endHour,
                      columnIndex: 0,
                      timelineWidth: constraints.maxWidth,
                      eventCategories: eventCategories,
                      onTap: onEventTap,
                      onEventCategorySaved: onEventCategorySaved,
                      onEventCategoryDeleted: onEventCategoryDeleted,
                    ),
                  if (_eventFallsOnDay(event, selectedNextDay) &&
                      _eventFallsWithinHours(event, startHour, endHour))
                    _TimelineEventBlock(
                      event: event,
                      startHour: startHour,
                      endHour: endHour,
                      columnIndex: 1,
                      timelineWidth: constraints.maxWidth,
                      eventCategories: eventCategories,
                      onTap: onEventTap,
                      onEventCategorySaved: onEventCategorySaved,
                      onEventCategoryDeleted: onEventCategoryDeleted,
                    ),
                ],
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
    this.iconSize = 20,
    this.horizontalPadding = 12,
    this.verticalPadding = 8,
    this.labelStyle = const TextStyle(fontWeight: FontWeight.w800),
  });

  final String label;
  final IconData icon;
  final VoidCallback onTap;
  final double iconSize;
  final double horizontalPadding;
  final double verticalPadding;
  final TextStyle labelStyle;

  @override
  Widget build(BuildContext context) => InkWell(
    borderRadius: BorderRadius.circular(22),
    onTap: onTap,
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
          Icon(icon, size: iconSize),
          const SizedBox(width: 4),
          Text(label, style: labelStyle),
        ],
      ),
    ),
  );
}

class _WeekDateHeader extends StatelessWidget {
  const _WeekDateHeader({
    required this.today,
    required this.weekStartDay,
    required this.selectedDay,
    required this.onDateSelected,
    required this.onHorizontalDayScrollStart,
    required this.onHorizontalDayScrollUpdate,
    required this.onHorizontalDayScrollEnd,
  });

  final DateTime today;
  final DateTime weekStartDay;
  final DateTime selectedDay;
  final ValueChanged<DateTime> onDateSelected;
  final GestureDragStartCallback onHorizontalDayScrollStart;
  final GestureDragUpdateCallback onHorizontalDayScrollUpdate;
  final GestureDragEndCallback onHorizontalDayScrollEnd;

  @override
  Widget build(BuildContext context) {
    var neutralPillIndex = 0;
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onHorizontalDragStart: onHorizontalDayScrollStart,
      onHorizontalDragUpdate: onHorizontalDayScrollUpdate,
      onHorizontalDragEnd: onHorizontalDayScrollEnd,
      child: Row(
        key: const Key('apple-style-week-date-header'),
        children: [
          const SizedBox(width: _calendarTimeColumnWidth),
          for (var index = 0; index < 7; index++)
            Expanded(
              child: Builder(
                builder: (context) {
                  final date = weekStartDay.add(Duration(days: index));
                  final isSelected = _sameCalendarDay(date, selectedDay);
                  final isNextVisible = _sameCalendarDay(
                    date,
                    selectedDay.add(const Duration(days: 1)),
                  );
                  final pillKey = isSelected
                      ? 'week-date-pill-selected'
                      : isNextVisible
                      ? 'week-date-pill-next-visible'
                      : 'week-date-pill-neutral-${neutralPillIndex++}';

                  return _WeekDateHeaderCell(
                    key: Key('week-date-cell-$index'),
                    date: date,
                    today: today,
                    selectedDay: selectedDay,
                    isNextVisibleDay: isNextVisible,
                    pillKey: Key(pillKey),
                    onTap: () => onDateSelected(date),
                  );
                },
              ),
            ),
        ],
      ),
    );
  }
}

class _WeekDateHeaderCell extends StatelessWidget {
  const _WeekDateHeaderCell({
    super.key,
    required this.date,
    required this.today,
    required this.selectedDay,
    required this.isNextVisibleDay,
    required this.pillKey,
    required this.onTap,
  });

  final DateTime date;
  final DateTime today;
  final DateTime selectedDay;
  final bool isNextVisibleDay;
  final Key pillKey;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isSelected = _sameCalendarDay(date, selectedDay);
    final isToday = _sameCalendarDay(date, today);
    final backgroundColor = isSelected
        ? HeyBeanTheme.accent
        : isNextVisibleDay
        ? const Color(0xFFE5E7EB)
        : null;
    final textColor = isSelected ? Colors.white : HeyBeanTheme.text;
    final borderRadius = isSelected && isNextVisibleDay
        ? BorderRadius.circular(18)
        : isSelected
        ? const BorderRadius.horizontal(left: Radius.circular(18))
        : isNextVisibleDay
        ? const BorderRadius.horizontal(right: Radius.circular(18))
        : BorderRadius.circular(18);

    return InkWell(
      borderRadius: BorderRadius.circular(20),
      onTap: onTap,
      child: Column(
        children: [
          Text(
            _weekdayLetter(date.weekday),
            style: const TextStyle(
              color: HeyBeanTheme.muted,
              fontSize: 12,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 5),
          Container(
            key: pillKey,
            width: double.infinity,
            height: 32,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: backgroundColor,
              borderRadius: borderRadius,
              border: Border.all(
                color: isToday && !isSelected
                    ? HeyBeanTheme.accentStrong
                    : backgroundColor == null
                    ? Colors.transparent
                    : HeyBeanTheme.border,
              ),
            ),
            child: Text(
              '${date.day}',
              style: TextStyle(
                color: textColor,
                fontSize: 16,
                fontWeight: FontWeight.w900,
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
    height: 48,
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
        fontWeight: FontWeight.w900,
      ),
    ),
  );
}

class _FixedTimelineHoursColumn extends StatelessWidget {
  const _FixedTimelineHoursColumn({required this.visibleHours});

  final List<int> visibleHours;

  @override
  Widget build(BuildContext context) => SizedBox(
    key: const Key('calendar-fixed-hours-column'),
    width: _calendarTimeColumnWidth,
    child: Column(
      children: [
        const SizedBox(height: 48),
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

class _TimelineEventBlock extends StatelessWidget {
  const _TimelineEventBlock({
    required this.event,
    required this.startHour,
    required this.endHour,
    required this.columnIndex,
    required this.timelineWidth,
    required this.eventCategories,
    required this.onTap,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final HermesCalendarEvent event;
  final int startHour;
  final int endHour;
  final int columnIndex;
  final double timelineWidth;
  final List<HermesEventCategory> eventCategories;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? category,
    String? color,
    String? recurrence,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
  })
  onTap;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(HermesEventCategory category)
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) {
    final parsed = _parseCalendarEventDateTime(event.startsAt);
    if (parsed == null) return const SizedBox.shrink();
    final visibleHours = _calendarVisibleHours(startHour, endHour);
    final hourPosition =
        ((parsed.hour + (parsed.minute / 60)) - visibleHours.first).clamp(
          0.0,
          visibleHours.length.toDouble(),
        ) *
        _calendarHourHeight;
    final dayColumnWidth = timelineWidth / 2;
    final left = (dayColumnWidth * columnIndex) + 8;
    final width = (dayColumnWidth - 16).clamp(0.0, double.infinity);
    return Positioned(
      top: hourPosition + 2,
      left: left,
      width: width,
      child: InkWell(
        key: Key(_calendarEventBlockKey(event)),
        borderRadius: BorderRadius.circular(10),
        onTap: () => _showCalendarEventDetails(
          context,
          event,
          eventCategories: eventCategories,
          onSave: onTap,
          onEventCategorySaved: onEventCategorySaved,
          onEventCategoryDeleted: onEventCategoryDeleted,
        ),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
          decoration: BoxDecoration(
            color: _calendarEventColor(event).withValues(alpha: .14),
            borderRadius: BorderRadius.circular(10),
            border: Border.all(
              color: _calendarEventColor(event).withValues(alpha: .35),
            ),
          ),
          child: Text(
            event.title,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: _calendarEventColor(event),
              fontWeight: FontWeight.w800,
              fontSize: 12,
            ),
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
  required Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? category,
    String? color,
    String? recurrence,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
  })
  onSave,
  required Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved,
  required Future<void> Function(HermesEventCategory category)
  onEventCategoryDeleted,
}) async {
  final result = await Navigator.of(context).push<Map<String, Object?>>(
    MaterialPageRoute(
      builder: (_) => _CalendarEventDetailPage(
        event: event,
        eventCategories: eventCategories,
        onEventCategorySaved: onEventCategorySaved,
        onEventCategoryDeleted: onEventCategoryDeleted,
      ),
    ),
  );

  if (result != null) {
    await onSave(
      event,
      title: result['title'] as String,
      startsAt: result['startsAt'] as String,
      endsAt: result['endsAt'] as String?,
      category: result['category'] as String?,
      color: result['color'] as String?,
      recurrence: result['recurrence'] as String?,
      reminderMinutesBefore: result['reminderMinutesBefore'] as int?,
      reminderRecurrence: result['reminderRecurrence'] as String?,
      reminderSpecificDays: (result['reminderSpecificDays'] as List?)
          ?.whereType<String>()
          .toList(),
      reminderInterval: result['reminderInterval'] as int?,
      reminderIntervalUnit: result['reminderIntervalUnit'] as String?,
    );
  }
}

class _CalendarEventDetailPage extends StatefulWidget {
  const _CalendarEventDetailPage({
    required this.event,
    required this.eventCategories,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final HermesCalendarEvent event;
  final List<HermesEventCategory> eventCategories;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(HermesEventCategory category)
  onEventCategoryDeleted;

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
  late final TextEditingController _reminderInterval;
  late String _color;
  late String _recurrence;
  late List<HermesEventCategory> _categories;
  String _reminderRecurrence = 'none';
  String _reminderIntervalUnit = 'days';
  final Set<String> _reminderSpecificDays = <String>{};
  bool _savingCategory = false;

  static const _colors = <({String value, String label})>[
    (value: '#34C759', label: 'Green'),
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
    _title = TextEditingController(text: event.title);
    _startsAt = TextEditingController(
      text: _formatCalendarEventDateTime(event.startsAt),
    );
    _endsAt = TextEditingController(
      text: _formatCalendarEventDateTime(event.endsAt),
    );
    _category = TextEditingController(text: event.category ?? 'Personal');
    _reminder = TextEditingController();
    _reminderInterval = TextEditingController(text: '1');
    _categories = [...widget.eventCategories];
    _color = _colors.any((color) => color.value == event.color)
        ? event.color!
        : '#34C759';
    _recurrence =
        _recurrences.any((recurrence) => recurrence.value == event.recurrence)
        ? event.recurrence!
        : 'none';
  }

  @override
  void dispose() {
    _title.dispose();
    _startsAt.dispose();
    _endsAt.dispose();
    _category.dispose();
    _reminder.dispose();
    _reminderInterval.dispose();
    super.dispose();
  }

  void _save() {
    final startsAt = _calendarEventInputToWireValue(
      _startsAt.text,
      originalValue: widget.event.startsAt,
    );
    final endsAt = _calendarEventInputToWireValue(
      _endsAt.text,
      originalValue: widget.event.endsAt,
      allowEmpty: true,
    );

    Navigator.of(context).pop(<String, Object?>{
      'title': _title.text.trim().isEmpty
          ? widget.event.title
          : _title.text.trim(),
      'startsAt': startsAt,
      'endsAt': endsAt,
      'category': _category.text.trim().isEmpty ? null : _category.text.trim(),
      'color': _color,
      'recurrence': _recurrence,
      'reminderMinutesBefore': int.tryParse(_reminder.text.trim()),
      'reminderRecurrence': _reminderRecurrence,
      'reminderSpecificDays': _reminderSpecificDays.toList()..sort(),
      'reminderInterval': int.tryParse(_reminderInterval.text.trim()),
      'reminderIntervalUnit': _reminderIntervalUnit,
    });
  }

  Future<void> _saveCategory({HermesEventCategory? category}) async {
    final name = _category.text.trim();
    if (name.isEmpty) return;
    setState(() => _savingCategory = true);
    try {
      final saved = await widget.onEventCategorySaved(
        category: category,
        name: name,
        color: _color,
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
    } finally {
      if (mounted) setState(() => _savingCategory = false);
    }
  }

  Future<void> _deleteCategory(HermesEventCategory category) async {
    setState(() => _savingCategory = true);
    try {
      await widget.onEventCategoryDeleted(category);
      if (!mounted) return;
      setState(() {
        _categories = _categories
            .where((item) => item.id != category.id)
            .toList();
        if (_category.text.trim() == category.name) _category.clear();
      });
    } finally {
      if (mounted) setState(() => _savingCategory = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final eventColor = _calendarEventColor(
      HermesCalendarEvent(
        id: widget.event.id,
        title: widget.event.title,
        startsAt: widget.event.startsAt,
        endsAt: widget.event.endsAt,
        category: widget.event.category,
        color: _color,
        recurrence: widget.event.recurrence,
      ),
    );

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
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Event settings',
                            style: Theme.of(context).textTheme.headlineSmall
                                ?.copyWith(
                                  fontWeight: FontWeight.w900,
                                  color: HeyBeanTheme.text,
                                  letterSpacing: -.4,
                                ),
                          ),
                          Text(
                            'Schedule, category, recurrence, and reminders',
                            style: Theme.of(context).textTheme.bodySmall
                                ?.copyWith(color: HeyBeanTheme.muted),
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
                      _EventDetailHero(
                        title: widget.event.title,
                        startsAt: widget.event.startsAt,
                        endsAt: widget.event.endsAt,
                        color: eventColor,
                      ),
                      const SizedBox(height: 14),
                      _ShellCard(
                        glow: true,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const _SectionTitle(
                              icon: Icons.edit_calendar_rounded,
                              title: 'Details',
                              subtitle:
                                  'Keep this event readable for you and the agent.',
                            ),
                            const SizedBox(height: 18),
                            TextField(
                              key: const Key('event-title-field'),
                              controller: _title,
                              textInputAction: TextInputAction.next,
                              decoration: const InputDecoration(
                                labelText: 'Title',
                                prefixIcon: Icon(Icons.title_rounded),
                              ),
                            ),
                            const SizedBox(height: 12),
                            TextField(
                              key: const Key('event-category-field'),
                              controller: _category,
                              textInputAction: TextInputAction.next,
                              decoration: const InputDecoration(
                                labelText: 'Category',
                                prefixIcon: Icon(Icons.sell_outlined),
                              ),
                            ),
                            const SizedBox(height: 8),
                            Wrap(
                              key: const Key('event-category-manager'),
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                for (final category in _categories)
                                  InputChip(
                                    label: Text(category.name),
                                    selected:
                                        _category.text.trim() == category.name,
                                    avatar: CircleAvatar(
                                      radius: 6,
                                      backgroundColor: _colorFromHex(
                                        category.color,
                                      ),
                                    ),
                                    onPressed: () => setState(() {
                                      _category.text = category.name;
                                      _color = category.color;
                                    }),
                                    onDeleted: _savingCategory
                                        ? null
                                        : () => _deleteCategory(category),
                                  ),
                                ActionChip(
                                  key: const Key('event-category-save-action'),
                                  avatar: const Icon(
                                    Icons.save_outlined,
                                    size: 18,
                                  ),
                                  label: Text(
                                    _savingCategory
                                        ? 'Saving...'
                                        : 'Save category color',
                                  ),
                                  onPressed: _savingCategory
                                      ? null
                                      : () {
                                          final matches = _categories.where(
                                            (item) =>
                                                item.name ==
                                                _category.text.trim(),
                                          );
                                          _saveCategory(
                                            category: matches.isEmpty
                                                ? null
                                                : matches.first,
                                          );
                                        },
                                ),
                              ],
                            ),
                            const SizedBox(height: 12),
                            Column(
                              key: const Key('event-color-field'),
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                _EventFieldLabel(
                                  icon: Icons.palette_outlined,
                                  label: 'Color',
                                ),
                                const SizedBox(height: 8),
                                Wrap(
                                  spacing: 8,
                                  runSpacing: 8,
                                  children: [
                                    for (final color in _colors)
                                      ChoiceChip(
                                        label: Text(color.label),
                                        selected: _color == color.value,
                                        avatar: CircleAvatar(
                                          radius: 6,
                                          backgroundColor: Color(
                                            int.parse(
                                              'FF${color.value.substring(1)}',
                                              radix: 16,
                                            ),
                                          ),
                                        ),
                                        onSelected: (_) => setState(() {
                                          _color = color.value;
                                        }),
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          ],
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
                            ),
                            const SizedBox(height: 18),
                            TextField(
                              key: const Key('event-start-field'),
                              controller: _startsAt,
                              textInputAction: TextInputAction.next,
                              decoration: const InputDecoration(
                                labelText: 'Start time',
                                prefixIcon: Icon(Icons.play_arrow_rounded),
                              ),
                            ),
                            const SizedBox(height: 12),
                            TextField(
                              key: const Key('event-end-field'),
                              controller: _endsAt,
                              textInputAction: TextInputAction.next,
                              decoration: const InputDecoration(
                                labelText: 'End time',
                                prefixIcon: Icon(Icons.stop_rounded),
                              ),
                            ),
                            const SizedBox(height: 12),
                            Column(
                              key: const Key('event-recurrence-field'),
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                _EventFieldLabel(
                                  icon: Icons.repeat_rounded,
                                  label: 'Recurrence',
                                ),
                                const SizedBox(height: 8),
                                Wrap(
                                  spacing: 8,
                                  runSpacing: 8,
                                  children: [
                                    for (final recurrence in _recurrences)
                                      ChoiceChip(
                                        label: Text(recurrence.label),
                                        selected:
                                            _recurrence == recurrence.value,
                                        onSelected: (_) => setState(() {
                                          _recurrence = recurrence.value;
                                        }),
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
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

class _EventDetailHero extends StatelessWidget {
  const _EventDetailHero({
    required this.title,
    required this.startsAt,
    required this.endsAt,
    required this.color,
  });

  final String title;
  final String? startsAt;
  final String? endsAt;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(18),
    decoration: BoxDecoration(
      borderRadius: BorderRadius.circular(24),
      gradient: LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: [color.withValues(alpha: .20), HeyBeanTheme.surface],
      ),
      border: Border.all(color: color.withValues(alpha: .28)),
      boxShadow: const [
        BoxShadow(
          color: Color(0x14000000),
          blurRadius: 30,
          offset: Offset(0, 18),
        ),
      ],
    ),
    child: Row(
      children: [
        Container(
          width: 56,
          height: 56,
          decoration: BoxDecoration(
            color: color,
            borderRadius: BorderRadius.circular(18),
            boxShadow: [
              BoxShadow(
                color: color.withValues(alpha: .28),
                blurRadius: 18,
                offset: const Offset(0, 10),
              ),
            ],
          ),
          child: const Icon(Icons.event_rounded, color: Colors.white),
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleLarge?.copyWith(
                  fontWeight: FontWeight.w900,
                  color: HeyBeanTheme.text,
                  letterSpacing: -.3,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                _eventDateRangeLabel(startsAt: startsAt, endsAt: endsAt),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: HeyBeanTheme.muted,
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

Color _colorFromHex(String value) {
  if (!value.startsWith('#') || value.length != 7) {
    return HeyBeanTheme.accentStrong;
  }
  return Color(int.parse('FF${value.substring(1)}', radix: 16));
}

Color _calendarEventColor(HermesCalendarEvent event) {
  final value = event.color;
  if (value == null) return HeyBeanTheme.accentStrong;
  return _colorFromHex(value);
}

String _eventSubtitle(HermesCalendarEvent event) {
  final parts = <String>[
    if (event.startsAt != null) _formatCalendarEventDateTime(event.startsAt),
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

List<int> _calendarVisibleHours(int startHour, int endHour) {
  final start = startHour.clamp(0, 22);
  final end = endHour.clamp(start + 1, 23);
  return [for (var hour = start; hour <= end; hour++) hour];
}

bool _eventFallsWithinHours(
  HermesCalendarEvent event,
  int startHour,
  int endHour,
) {
  final parsed = _parseCalendarEventDateTime(event.startsAt);
  if (parsed == null) return false;
  return parsed.hour >= startHour && parsed.hour <= endHour;
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

String _weekdayLetter(int weekday) =>
    const ['M', 'T', 'W', 'T', 'F', 'S', 'S'][weekday - 1];

String _shortWeekdayName(int weekday) =>
    const ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][weekday - 1];

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

String _formatCalendarEventDateTime(String? value) {
  if (value == null || value.trim().isEmpty) return '';
  final parsed = _parseCalendarEventDateTime(value);
  if (parsed == null) return value.trim();
  final local = parsed.toLocal();
  var hour = local.hour % 12;
  if (hour == 0) hour = 12;
  final minute = local.minute.toString().padLeft(2, '0');
  final meridiem = local.hour >= 12 ? 'PM' : 'AM';
  return '${_shortWeekdayName(local.weekday)}, ${_shortMonthName(local.month)} '
      '${local.day} · $hour:$minute $meridiem';
}

String _eventDateRangeLabel({String? startsAt, String? endsAt}) {
  final parts = <String>[
    if (startsAt != null && startsAt.trim().isNotEmpty)
      _formatCalendarEventDateTime(startsAt),
    if (endsAt != null && endsAt.trim().isNotEmpty)
      _formatCalendarEventDateTime(endsAt),
  ].where((part) => part.isNotEmpty).toList();
  return parts.isEmpty ? 'Unscheduled' : parts.join(' → ');
}

String? _calendarEventInputToWireValue(
  String value, {
  required String? originalValue,
  bool allowEmpty = false,
}) {
  final trimmed = value.trim();
  if (trimmed.isEmpty) return allowEmpty ? null : trimmed;

  final originalDisplay = _formatCalendarEventDateTime(originalValue);
  if (originalValue != null && trimmed == originalDisplay) {
    return originalValue;
  }

  final parsed = _parseCalendarEventDateTime(trimmed, originalValue);
  return parsed?.toIso8601String() ?? trimmed;
}

DateTime? _parseCalendarEventDateTime(String? value, [String? referenceValue]) {
  if (value == null || value.trim().isEmpty) return null;
  final parsed = DateTime.tryParse(value);
  if (parsed != null) return parsed;

  final trimmed = value.trim();
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
  final now = DateTime.now();
  return DateTime(now.year, now.month, now.day, hour, minute);
}

bool _sameCalendarDay(DateTime a, DateTime b) =>
    a.year == b.year && a.month == b.month && a.day == b.day;

DateTime _dateOnly(DateTime date) => DateTime(date.year, date.month, date.day);

bool _eventFallsOnDay(HermesCalendarEvent event, DateTime day) {
  final parsed = _parseCalendarEventDateTime(event.startsAt);
  if (parsed == null) return false;
  return _sameCalendarDay(parsed, day);
}

class _CalendarMonthTaskList extends StatelessWidget {
  const _CalendarMonthTaskList({
    required this.tasks,
    required this.reminders,
    required this.calendar,
    required this.onTaskCompleted,
  });

  final List<HermesTask> tasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendar;
  final Future<void> Function(HermesTask task) onTaskCompleted;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(
        'Rest of month',
        style: Theme.of(
          context,
        ).textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 8),
      if (tasks.isEmpty && reminders.isEmpty && calendar.isEmpty)
        const _EmptySurface(label: 'No tasks for the rest of the month')
      else ...[
        for (final task in tasks)
          _TaskItemTile(
            task: task,
            subtitle: 'Today · ${_statusLabel(task.status)}',
            onCompleted: onTaskCompleted,
          ),
        for (final reminder in reminders)
          _CompactItemTile(
            icon: Icons.notifications_active_outlined,
            title: reminder.title,
            subtitle: reminder.dueAt ?? 'Today',
          ),
        for (final event in calendar)
          _CompactItemTile(
            icon: Icons.event_available_rounded,
            title: event.title,
            subtitle: event.startsAt ?? 'This month',
          ),
      ],
    ],
  );
}

class _MonthCalendarHeader extends StatelessWidget {
  const _MonthCalendarHeader({required this.month, required this.onBackToDay});

  final DateTime month;
  final VoidCallback onBackToDay;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      InkWell(
        key: const Key('calendar-day-chevron'),
        borderRadius: BorderRadius.circular(22),
        onTap: onBackToDay,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
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
              const Icon(Icons.chevron_right_rounded, size: 20),
              const SizedBox(width: 4),
              Text(
                _monthName(month.month),
                style: const TextStyle(fontWeight: FontWeight.w800),
              ),
            ],
          ),
        ),
      ),
      const Spacer(),
    ],
  );
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
    for (final event in calendar) {
      final parsed = _parseCalendarEventDateTime(event.startsAt);
      if (parsed != null &&
          parsed.month == visibleMonth.month &&
          parsed.year == visibleMonth.year) {
        eventDays.add(parsed.day);
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
    this.onEventTap,
    this.onEventCategorySaved,
    this.onEventCategoryDeleted,
  });

  final List<HermesCalendarEvent> calendar;
  final List<HermesEventCategory> eventCategories;
  final Future<void> Function(
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
    String? endsAt,
    String? category,
    String? color,
    String? recurrence,
    int? reminderMinutesBefore,
    String? reminderRecurrence,
    List<String>? reminderSpecificDays,
    int? reminderInterval,
    String? reminderIntervalUnit,
  })?
  onEventTap;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })?
  onEventCategorySaved;
  final Future<void> Function(HermesEventCategory category)?
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
                    onSave: onEventTap!,
                    onEventCategorySaved: onEventCategorySaved!,
                    onEventCategoryDeleted: onEventCategoryDeleted!,
                  ),
          ),
    ],
  );
}

class _TaskListCard extends StatelessWidget {
  const _TaskListCard({required this.tasks, required this.onTaskCompleted});

  final List<HermesTask> tasks;
  final Future<void> Function(HermesTask task) onTaskCompleted;

  @override
  Widget build(BuildContext context) => _ShellCard(
    key: const Key('tasks-view'),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.task_alt_rounded,
          title: 'Task list',
          subtitle: 'Only open assistant tasks in this app',
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: const [
            ChoiceChip(label: Text('Open'), selected: true),
            ChoiceChip(label: Text('Done'), selected: false),
          ],
        ),
        const SizedBox(height: 12),
        if (tasks.isEmpty)
          const _EmptySurface(label: 'No open tasks')
        else
          for (final task in tasks)
            _TaskItemTile(
              task: task,
              subtitle: _statusLabel(task.status),
              onCompleted: onTaskCompleted,
            ),
      ],
    ),
  );
}

class _ReminderListCard extends StatelessWidget {
  const _ReminderListCard({required this.reminders});

  final List<HermesReminder> reminders;

  @override
  Widget build(BuildContext context) => _ShellCard(
    key: const Key('reminders-view'),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.notifications_active_rounded,
          title: 'Reminders',
          subtitle: 'Upcoming reminders from Bean',
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: const [
            ChoiceChip(label: Text('Pending'), selected: true),
            ChoiceChip(label: Text('Completed'), selected: false),
          ],
        ),
        const SizedBox(height: 12),
        if (reminders.isEmpty)
          const _EmptySurface(label: 'No reminders')
        else
          for (final reminder in reminders)
            _CompactItemTile(
              icon: Icons.notifications_none_rounded,
              title: reminder.title,
              subtitle: reminder.dueAt ?? 'No time set',
            ),
      ],
    ),
  );
}

class _SettingsView extends StatelessWidget {
  const _SettingsView({
    required this.user,
    required this.approvals,
    required this.pastTasks,
    required this.onTaskCompleted,
    required this.calendarStartHour,
    required this.calendarEndHour,
    required this.onCalendarStartHourChanged,
    required this.onCalendarEndHourChanged,
    required this.onDeleteAccount,
  });

  final HermesUser user;
  final List<HermesApproval> approvals;
  final List<HermesTask> pastTasks;
  final Future<void> Function(HermesTask task) onTaskCompleted;
  final int calendarStartHour;
  final int calendarEndHour;
  final ValueChanged<int> onCalendarStartHourChanged;
  final ValueChanged<int> onCalendarEndHourChanged;
  final Future<void> Function() onDeleteAccount;

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
            ),
            const SizedBox(height: 12),
            _CompactItemTile(
              icon: Icons.person_outline_rounded,
              title: user.name,
              subtitle: user.email,
            ),
            const _CompactItemTile(
              icon: Icons.tune_rounded,
              title: 'Bean preferences',
              subtitle:
                  'Chat guidance and assistant behavior are managed by Hermes Bean',
            ),
            _CalendarPreferencesCard(
              startHour: calendarStartHour,
              endHour: calendarEndHour,
              onStartHourChanged: onCalendarStartHourChanged,
              onEndHourChanged: onCalendarEndHourChanged,
            ),
            _PastTasksSettingsCard(
              tasks: pastTasks,
              onTaskCompleted: onTaskCompleted,
            ),
            _CompactItemTile(
              icon: Icons.verified_user_outlined,
              title: 'Approval rules',
              subtitle: approvals.isEmpty
                  ? 'No pending approvals'
                  : '${approvals.length} pending approval needs review',
            ),
          ],
        ),
      ),
      const SizedBox(height: 16),
      _AccountCard(user: user, onDeleteAccount: onDeleteAccount),
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

class _PastTasksSettingsCard extends StatelessWidget {
  const _PastTasksSettingsCard({
    required this.tasks,
    required this.onTaskCompleted,
  });

  final List<HermesTask> tasks;
  final Future<void> Function(HermesTask task) onTaskCompleted;

  @override
  Widget build(BuildContext context) => Container(
    key: const Key('past-tasks-settings'),
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
        const Row(
          children: [
            Icon(
              Icons.history_toggle_off_rounded,
              color: HeyBeanTheme.accentStrong,
            ),
            SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Past tasks',
                    style: TextStyle(fontWeight: FontWeight.w800),
                  ),
                  Text(
                    'Completed tasks that dropped off active lists stay here for 10 days.',
                    style: TextStyle(color: HeyBeanTheme.muted),
                  ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (tasks.isEmpty)
          const _EmptySurface(label: 'No past completed tasks')
        else
          for (final task in tasks)
            _TaskItemTile(
              task: task,
              subtitle: 'Completed · Permanently deletes after 10 days',
              onCompleted: onTaskCompleted,
            ),
      ],
    ),
  );
}

class _TaskItemTile extends StatelessWidget {
  const _TaskItemTile({
    required this.task,
    required this.subtitle,
    required this.onCompleted,
  });

  final HermesTask task;
  final String subtitle;
  final Future<void> Function(HermesTask task) onCompleted;

  @override
  Widget build(BuildContext context) {
    final completed = _taskIsCompleted(task);
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
      decoration: BoxDecoration(
        color: completed ? HeyBeanTheme.surface : HeyBeanTheme.surface2,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: HeyBeanTheme.border),
      ),
      child: CheckboxListTile(
        key: Key('task-complete-checkbox-${task.id}'),
        value: completed,
        onChanged: (_) => onCompleted(task),
        controlAffinity: ListTileControlAffinity.leading,
        contentPadding: const EdgeInsets.symmetric(horizontal: 6),
        activeColor: HeyBeanTheme.accentStrong,
        title: Text(
          task.title,
          style: TextStyle(
            fontWeight: FontWeight.w800,
            decoration: completed ? TextDecoration.lineThrough : null,
            color: completed ? HeyBeanTheme.muted : HeyBeanTheme.text,
          ),
        ),
        subtitle: Text(
          subtitle,
          style: const TextStyle(color: HeyBeanTheme.muted),
        ),
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
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback? onTap;

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

List<HermesTask> _visibleSortedTasks(List<HermesTask> tasks) {
  final today = _dateOnly(DateTime.now());
  final visible = tasks
      .where((task) => _taskVisibleOnOrAfter(task, today))
      .toList();
  visible.sort((a, b) {
    final completedCompare = _taskIsCompleted(a) == _taskIsCompleted(b)
        ? 0
        : (_taskIsCompleted(a) ? 1 : -1);
    if (completedCompare != 0) return completedCompare;
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
  });
  return visible;
}

bool _taskVisibleOnOrAfter(HermesTask task, DateTime today) {
  if (_taskIsRecurring(task)) return true;
  final dueAt = _parseTaskDueDate(task);
  return dueAt == null || !_dateOnly(dueAt).isBefore(today);
}

bool _taskIsCompleted(HermesTask task) {
  final status = (task.status ?? 'open').toLowerCase().replaceAll('_', '-');
  return status == 'completed' || status == 'complete' || status == 'done';
}

bool _taskIsRecurring(HermesTask task) {
  final metadata = task.metadata;
  if (metadata == null) return false;
  final recurrence =
      metadata['recurrence'] ?? metadata['recurring'] ?? metadata['rrule'];
  return recurrence != null &&
      recurrence != false &&
      recurrence.toString().isNotEmpty;
}

DateTime? _parseTaskDueDate(HermesTask task) {
  final dueAt = task.dueAt;
  if (dueAt == null || dueAt.isEmpty) return null;
  return DateTime.tryParse(dueAt)?.toLocal();
}

String _statusLabel(String? status) {
  final normalized = (status ?? 'open').replaceAll('_', ' ');
  if (normalized.isEmpty) return 'Open';
  return '${normalized[0].toUpperCase()}${normalized.substring(1)}';
}

class _AccountCard extends StatelessWidget {
  const _AccountCard({required this.user, required this.onDeleteAccount});

  final HermesUser user;
  final Future<void> Function() onDeleteAccount;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.settings_rounded,
          title: 'Profile',
          subtitle: 'Account and app settings',
        ),
        const SizedBox(height: 10),
        Text(user.email),
        const SizedBox(height: 10),
        OutlinedButton.icon(
          key: const Key('delete-account-action'),
          onPressed: onDeleteAccount,
          icon: const Icon(Icons.delete_outline_rounded),
          label: const Text('Delete account'),
        ),
      ],
    ),
  );
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({
    required this.icon,
    required this.title,
    required this.subtitle,
  });

  final IconData icon;
  final String title;
  final String subtitle;

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
            Text(
              subtitle,
              style: Theme.of(
                context,
              ).textTheme.bodySmall?.copyWith(color: HeyBeanTheme.muted),
            ),
          ],
        ),
      ),
    ],
  );
}

class _ShellCard extends StatelessWidget {
  const _ShellCard({super.key, required this.child, this.glow = false});

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

class _HeyBeanBottomMenu extends StatelessWidget {
  const _HeyBeanBottomMenu({required this.selected, required this.onSelected});

  final _HomeDestination selected;
  final ValueChanged<_HomeDestination> onSelected;

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
                    const SizedBox(width: 84),
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
            top: 15,
            child: _BeanFab(
              selected: selected == _HomeDestination.bean,
              onPressed: () => onSelected(_HomeDestination.bean),
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

class _BeanFab extends StatelessWidget {
  const _BeanFab({required this.selected, required this.onPressed});

  final bool selected;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.transparent,
    child: InkWell(
      key: const Key('nav-bean'),
      customBorder: const CircleBorder(),
      onTap: onPressed,
      child: Container(
        key: const Key('heybean-center-bean-button'),
        width: 64,
        height: 64,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: const LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [Color(0xFF22C55E), Color(0xFF16A34A), Color(0xFF15803D)],
          ),
          border: Border.all(
            color: selected ? Colors.white : const Color(0xFFE2E8F0),
            width: 4,
          ),
          boxShadow: const [
            BoxShadow(
              color: Color(0x3D16A34A),
              blurRadius: 24,
              offset: Offset(0, 10),
            ),
          ],
        ),
        child: const Icon(Icons.eco_rounded, color: Colors.white, size: 30),
      ),
    ),
  );
}
