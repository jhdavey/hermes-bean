part of '../../main.dart';

class CommandCenterShell extends StatefulWidget {
  const CommandCenterShell({
    super.key,
    required this.apiClient,
    required this.tokenStore,
    required this.launchExternalUrl,
    required this.updateAppIconBadge,
    required this.stripePaymentHandler,
    required this.onThemeChanged,
    required this.onThemeModeChanged,
  });

  final BeanApiClient apiClient;
  final AuthTokenStore tokenStore;
  final ExternalUrlLauncher launchExternalUrl;
  final AppIconBadgeUpdater updateAppIconBadge;
  final StripePaymentHandler stripePaymentHandler;
  final ValueChanged<String> onThemeChanged;
  final ValueChanged<String> onThemeModeChanged;

  @override
  State<CommandCenterShell> createState() => _CommandCenterShellState();
}

class _CommandCenterShellState extends State<CommandCenterShell>
    with WidgetsBindingObserver {
  static const _localPendingNoteMetadataKey = '_local_pending_save';
  _AuthPhase _phase = _AuthPhase.loading;
  BeanUser? _user;
  List<BeanTask> _tasks = const [];
  List<BeanTask> _pastTasks = const [];
  List<BeanReminder> _reminders = const [];
  List<BeanCalendarEvent> _calendar = const [];
  List<BeanNoteFolder> _noteFolders = const [];
  List<BeanNote> _notes = const [];
  int? _noteToOpenId;
  List<BeanEventCategory> _eventCategories = const [];
  GoogleCalendarSyncStatus? _googleCalendarStatus;
  GoogleCalendarSyncStatus? _outlookCalendarStatus;
  String? _error;
  String? _authNotice;
  String? _loadingStatusText;
  String? _checkoutBusyPlan;
  String? _checkoutError;
  bool _busy = false;
  bool _dashboardDataLoading = false;
  _HomeDestination _selectedDestination = _HomeDestination.commandCenter;
  bool _showCalendarMonth = false;
  DateTime _selectedCalendarDay = _dateOnly(DateTime.now());
  int _calendarStartHour = _defaultCalendarStartHour;
  int _calendarEndHour = _defaultCalendarEndHour;
  final Set<int> _pendingTaskIds = <int>{};
  bool _onboardingTourVisible = false;
  int _onboardingTourStep = 0;
  bool _onboardingTourPendingPlanSelection = false;
  bool _onboardingTourFinishWithImport = false;
  final Map<_OnboardingTourTarget, GlobalKey> _onboardingTourTargetKeys = {
    for (final target in _OnboardingTourTarget.values) target: GlobalKey(),
  };
  final Set<int> _dismissedReminderBannerIds = <int>{};
  final Set<int> _notifiedReminderIds = <int>{};
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
  final Map<int, _PendingNoteSave> _pendingNoteSaves = {};
  final Map<int, Timer> _pendingNoteSaveTimers = {};
  final Set<int> _noteSavesInFlight = <int>{};
  int _noteSaveVersion = 0;
  bool _beanAssistantOpen = false;
  bool _beanAssistantSending = false;
  bool _beanDockPushToTalkHeld = false;
  final GlobalKey<_BeanAssistantPanelState> _beanAssistantPanelKey =
      GlobalKey<_BeanAssistantPanelState>();
  int? _beanAssistantSessionId;
  String? _beanAssistantError;
  List<BeanAssistantMessage> _beanAssistantMessages = const [];
  List<BeanAssistantConfirmation> _beanAssistantConfirmations = const [];

  void _applyUserTheme(BeanUser? user) {
    widget.onThemeChanged(user?.theme ?? 'green');
    widget.onThemeModeChanged(user?.themeMode ?? 'auto');
  }

  bool get _notesEnabled {
    final user = _user;
    final noteLimit = user?.planLimits.noteLimit;
    return user != null &&
        (_onboardingTourVisible ||
            user.isAdmin ||
            user.planLimits.notesEnabled ||
            noteLimit == null ||
            noteLimit > 0);
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
    _tasks = const [];
    _pastTasks = const [];
    _reminders = const [];
    _calendar = const [];
    _noteFolders = const [];
    _notes = const [];
    _eventCategories = const [];
    _googleCalendarStatus = null;
    _pendingTaskIds.clear();
    _pendingTaskWrites.clear();
    _pendingReminderWrites.clear();
    _pendingCalendarEventWrites.clear();
    _latestTaskWriteVersions.clear();
    _latestReminderWriteVersions.clear();
    _latestCalendarEventWriteVersions.clear();
    for (final timer in _pendingNoteSaveTimers.values) {
      timer.cancel();
    }
    _pendingNoteSaveTimers.clear();
    _pendingNoteSaves.clear();
    _noteSavesInFlight.clear();
    _dismissedReminderBannerIds.clear();
    _notifiedReminderIds.clear();
    _loadingStatusText = null;
    _dashboardDataLoading = false;
    _onboardingTourVisible = false;
    _onboardingTourStep = 0;
    _onboardingTourPendingPlanSelection = false;
    _onboardingTourFinishWithImport = false;
    _beanAssistantOpen = false;
    _beanAssistantSending = false;
    _beanDockPushToTalkHeld = false;
    _beanAssistantSessionId = null;
    _beanAssistantError = null;
    _beanAssistantMessages = const [];
    _beanAssistantConfirmations = const [];
  }

  void _rememberPendingTaskWrite(BeanTask task, int mutationVersion) {
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

  List<BeanTask> _tasksWithPendingWrites(List<BeanTask> tasks) {
    if (_pendingTaskWrites.isEmpty) return tasks;

    final now = DateTime.now();
    final activeWorkspaceId = _activeWorkspaceId();
    final merged = List<BeanTask>.from(tasks);

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

  bool _taskMatchesPendingWrite(BeanTask refreshed, BeanTask pending) =>
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
    BeanTask optimisticTask,
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
    BeanReminder reminder,
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

  List<BeanReminder> _remindersWithPendingWrites(List<BeanReminder> reminders) {
    if (_pendingReminderWrites.isEmpty) return reminders;

    final now = DateTime.now();
    final activeWorkspaceId = _activeWorkspaceId();
    final merged = List<BeanReminder>.from(reminders);

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
    BeanReminder refreshed,
    BeanReminder pending,
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
    BeanReminder optimisticReminder,
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
    BeanCalendarEvent event,
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

  List<BeanCalendarEvent> _calendarEventsWithPendingWrites(
    List<BeanCalendarEvent> events,
  ) {
    if (_pendingCalendarEventWrites.isEmpty) return events;

    final now = DateTime.now();
    final activeWorkspaceId = _activeWorkspaceId();
    final merged = List<BeanCalendarEvent>.from(events);

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

  List<BeanCalendarEvent> _calendarEventsForDashboardState({
    required List<BeanCalendarEvent> listed,
    required List<BeanCalendarEvent> summary,
  }) {
    final listedEvents = _normalizeCalendarEventsForDisplay(
      _calendarEventsWithPendingWrites(listed),
    );
    if (listedEvents.isNotEmpty) return listedEvents;
    return _normalizeCalendarEventsForDisplay(
      _calendarEventsWithPendingWrites(summary),
    );
  }

  List<T> _dashboardListForRefresh<T>(
    List<T> refreshed,
    List<T> current, {
    required bool showLoading,
  }) {
    if (!showLoading && refreshed.isEmpty && current.isNotEmpty) {
      return current;
    }
    return refreshed;
  }

  List<T> _dashboardListForMutationRefresh<T>({
    required List<T> refreshed,
    required List<T> current,
    required bool showLoading,
    required Set<int> deletedIds,
    required int Function(T item) idFor,
  }) {
    if (showLoading || deletedIds.isEmpty) {
      return _dashboardListForRefresh(
        refreshed,
        current,
        showLoading: showLoading,
      );
    }

    final merged = <int, T>{};
    for (final item in refreshed) {
      final id = idFor(item);
      if (!deletedIds.contains(id)) merged[id] = item;
    }
    for (final item in current) {
      final id = idFor(item);
      if (!deletedIds.contains(id)) merged.putIfAbsent(id, () => item);
    }
    return merged.values.toList(growable: false);
  }

  Set<int> _activePendingTaskDeleteIds() => _activePendingDeleteIds(
    _pendingTaskWrites.map((key, value) => MapEntry(key, value)),
  );

  Set<int> _activePendingReminderDeleteIds() => _activePendingDeleteIds(
    _pendingReminderWrites.map((key, value) => MapEntry(key, value)),
  );

  Set<int> _activePendingCalendarEventDeleteIds() => _activePendingDeleteIds(
    _pendingCalendarEventWrites.map((key, value) => MapEntry(key, value)),
  );

  Set<int> _activePendingDeleteIds(Map<int, dynamic> pendingWrites) {
    final now = DateTime.now();
    final activeWorkspaceId = _activeWorkspaceId();
    final deletedIds = <int>{};
    for (final entry in pendingWrites.entries) {
      final pending = entry.value;
      final deleted = pending.deleted == true;
      final expiresAt = pending.expiresAt;
      final workspaceId = pending.workspaceId;
      if (!deleted ||
          expiresAt is! DateTime ||
          !expiresAt.isAfter(now) ||
          (workspaceId != null &&
              activeWorkspaceId != null &&
              workspaceId != activeWorkspaceId)) {
        continue;
      }
      deletedIds.add(entry.key);
    }
    return deletedIds;
  }

  bool _deleteSelectionAffectsVisibleWorkspace(
    int? itemWorkspaceId,
    List<Object> deleteFromWorkspaceIds,
  ) {
    if (deleteFromWorkspaceIds.isEmpty) return true;
    final visibleWorkspaceId = itemWorkspaceId ?? _activeWorkspaceId();
    if (visibleWorkspaceId == null) return true;
    final selectedWorkspaceIds = deleteFromWorkspaceIds
        .map((id) => id.toString())
        .toSet();
    return selectedWorkspaceIds.contains(visibleWorkspaceId.toString());
  }

  bool _calendarEventMatchesPendingWrite(
    BeanCalendarEvent refreshed,
    BeanCalendarEvent pending,
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
    BeanCalendarEvent optimisticEvent,
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
    final leftDate = _parseCalendarEventDateTime(left);
    final rightDate = _parseCalendarEventDateTime(right);
    if (leftDate == null || rightDate == null) return left == right;
    return leftDate.isAtSameMomentAs(rightDate);
  }

  int _nextLocalResourceId() => _localResourceSequence--;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
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
    for (final timer in _pendingNoteSaveTimers.values) {
      timer.cancel();
    }
    _flushPendingNoteSaves();
    _stopDashboardChangePolling();
    unawaited(_pushNotifications.dispose());
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _scheduleAppIconBadgeSync(_criticalItemCountForToday());
      unawaited(_pollDashboardChanges());
    } else if (state == AppLifecycleState.inactive ||
        state == AppLifecycleState.paused ||
        state == AppLifecycleState.detached) {
      _flushPendingNoteSaves();
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

  BeanReminder? _dueReminderBanner() {
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

  DateTime? _parseReminderDueAt(BeanReminder reminder) {
    final value = reminder.dueAt;
    if (value == null || value.trim().isEmpty) return null;
    return DateTime.tryParse(value)?.toLocal();
  }

  bool _isReminderCompleted(BeanReminder reminder) {
    return reminder.status == 'completed';
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
      error is BeanApiException &&
      (error.statusCode == 401 || error.statusCode == 403);

  bool _userNeedsSignupPaywall(BeanUser user) {
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

  Future<GoogleCalendarSyncStatus> _outlookCalendarStatusOrFallback({
    GoogleCalendarSyncStatus? fallback,
  }) async {
    try {
      return await widget.apiClient.outlookCalendarStatus();
    } catch (_) {
      return fallback ??
          _outlookCalendarStatus ??
          const GoogleCalendarSyncStatus(
            connected: false,
            status: 'not_connected',
          );
    }
  }

  Future<void> _loadSignedIn({
    BeanUser? knownUser,
    bool launchedFromRememberedToken = false,
    String? loadingStatusText,
    bool deferSignupPaywall = false,
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

    try {
      final user = knownUser ?? await widget.apiClient.me();
      if (!_isCurrentAuthGeneration(authGeneration)) return;
      _applyUserTheme(user);
      if (!deferSignupPaywall && _userNeedsSignupPaywall(user)) {
        setState(() {
          _clearDashboardData();
          _user = user;
          _phase = _AuthPhase.planSelection;
          _loadingStatusText = null;
          _dashboardDataLoading = false;
          _checkoutError = null;
        });
        return;
      }

      final workspaceId = _workspaceIdForUser(user);
      final cachedSnapshot = workspaceId == null
          ? null
          : _workspaceSnapshots[workspaceId] ??
                await _loadPersistedDashboardSnapshot(user);
      if (!_isCurrentAuthGeneration(authGeneration)) return;
      setState(() {
        _user = user;
        if (cachedSnapshot == null) {
          _clearDashboardData();
        } else {
          _restoreDashboardSnapshot(cachedSnapshot);
        }
        _phase = _AuthPhase.signedIn;
        _loadingStatusText = null;
        _dashboardDataLoading = true;
      });

      final googleStatusFuture = _syncGoogleCalendarIfConnected(
        fallback: _googleCalendarStatus,
        syncConnected: false,
      );
      final outlookStatusFuture = _outlookCalendarStatusOrFallback(
        fallback: _outlookCalendarStatus,
      );
      unawaited(
        _applyCalendarStatusesWhenReady(
          authGeneration: authGeneration,
          googleCalendarStatusFuture: googleStatusFuture,
          outlookCalendarStatusFuture: outlookStatusFuture,
        ),
      );

      try {
        final results = await Future.wait<Object>([
          widget.apiClient.listTasks(),
          widget.apiClient.listReminders(),
          widget.apiClient.listCalendarEvents(skipExternalSync: true),
          widget.apiClient.listEventCategories(),
        ]);
        if (!_isCurrentAuthGeneration(authGeneration)) return;
        setState(() {
          _tasks = _tasksWithPendingWrites(results[0] as List<BeanTask>);
          _reminders = _remindersWithPendingWrites(
            results[1] as List<BeanReminder>,
          );
          _calendar = _calendarEventsForDashboardState(
            listed: results[2] as List<BeanCalendarEvent>,
            summary: const [],
          );
          _eventCategories = results[3] as List<BeanEventCategory>;
          _dashboardDataLoading = false;
          _error = null;
        });
      } catch (error, stackTrace) {
        debugPrint(
          'HeyBean dashboard bootstrap failed: ${error.runtimeType}: $error\n$stackTrace',
        );
        if (!_isCurrentAuthGeneration(authGeneration)) return;
        setState(() {
          _dashboardDataLoading = false;
          _error = beanFriendlyErrorMessage(
            error,
            action: 'load your latest dashboard data',
          );
        });
      }
      _syncReminderNotifications();
      unawaited(_pushNotifications.registerForUser(widget.apiClient));
      _cacheCurrentDashboardSnapshot();
      _startDashboardChangePolling(resetCursor: true);
      unawaited(_loadSecondarySignedInData(authGeneration: authGeneration));
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
        _clearSignedInState();
        _error = invalidToken
            ? 'Session expired or the saved sign-in is no longer valid. Please sign in again.'
            : launchedFromRememberedToken
            ? 'Your saved sign-in could not be checked. Try again when your connection is restored.'
            : beanFriendlyErrorMessage(error, action: 'load your account');
        _phase = _AuthPhase.signedOut;
      });
    }
  }

  Future<void> _loadSecondarySignedInData({required int authGeneration}) async {
    final dataVersion = _dashboardDataVersion;
    try {
      final notesEnabled = _notesEnabled;
      final results = await Future.wait<Object>([
        notesEnabled
            ? widget.apiClient.listNoteFolders()
            : Future<Object>.value(const <BeanNoteFolder>[]),
        notesEnabled
            ? widget.apiClient.listNotes()
            : Future<Object>.value(const <BeanNote>[]),
        widget.apiClient.listPastTasks(),
      ]);
      if (!mounted ||
          _phase != _AuthPhase.signedIn ||
          authGeneration != _authGeneration ||
          dataVersion != _dashboardDataVersion) {
        return;
      }
      setState(() {
        _noteFolders = _sortedNoteFolders(results[0] as List<BeanNoteFolder>);
        _notes = _notesWithLocalDrafts(results[1] as List<BeanNote>);
        _pastTasks = _tasksWithPendingWrites(results[2] as List<BeanTask>);
      });
      _cacheCurrentDashboardSnapshot();
      _resumeLocalNoteDrafts();
    } catch (_) {
      // Secondary panels retry on the next refresh.
    }
  }

  Future<void> _applyCalendarStatusesWhenReady({
    required int authGeneration,
    required Future<GoogleCalendarSyncStatus> googleCalendarStatusFuture,
    required Future<GoogleCalendarSyncStatus> outlookCalendarStatusFuture,
  }) async {
    final statuses = await Future.wait<GoogleCalendarSyncStatus>([
      googleCalendarStatusFuture,
      outlookCalendarStatusFuture,
    ]);
    if (!_isCurrentAuthGeneration(authGeneration)) {
      return;
    }
    setState(() {
      _googleCalendarStatus = statuses[0];
      _outlookCalendarStatus = statuses[1];
    });
    _cacheCurrentDashboardSnapshot();
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

  Future<void> _startTrialCheckoutForInterval(
    String plan,
    String billingInterval,
  ) async {
    if (_checkoutBusyPlan != null) return;
    final shouldLaunchTourAfterCheckout = _phase == _AuthPhase.guidedOnboarding;
    setState(() {
      _checkoutBusyPlan = plan;
      _checkoutError = null;
    });
    try {
      final setup = await widget.apiClient.createMobileSubscriptionSetup(
        plan: plan,
        billingInterval: billingInterval,
      );
      await widget.stripePaymentHandler.preparePaymentSheet(
        setup,
        user: _user!,
        primaryButtonLabel: 'Start ${_subscriptionPlanLabel(plan)} trial',
      );
      await widget.stripePaymentHandler.presentPaymentSheet();
      await widget.apiClient.confirmMobileSubscription(
        plan: plan,
        billingInterval: billingInterval,
        setupIntentId: setup.setupIntentId,
      );
      if (!mounted) return;
      setState(() {
        _checkoutBusyPlan = null;
        _checkoutError = null;
      });
      await _loadSignedIn(loadingStatusText: 'Preparing your dashboard...');
      if (shouldLaunchTourAfterCheckout &&
          mounted &&
          _phase == _AuthPhase.signedIn) {
        _showOnboardingTour(finishWithImport: true);
      }
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

  Future<void> _redeemCouponCodeForSignup(String code) async {
    if (_checkoutBusyPlan != null) return;
    final shouldLaunchTourAfterCoupon = _phase == _AuthPhase.guidedOnboarding;
    setState(() {
      _checkoutBusyPlan = 'coupon';
      _checkoutError = null;
    });
    try {
      await widget.apiClient.redeemCouponCode(code: code);
      if (!mounted) return;
      setState(() {
        _checkoutBusyPlan = null;
        _checkoutError = null;
      });
      await _loadSignedIn(loadingStatusText: 'Applying your coupon...');
      if (shouldLaunchTourAfterCoupon &&
          mounted &&
          _phase == _AuthPhase.signedIn) {
        _showOnboardingTour(finishWithImport: true);
      }
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _checkoutBusyPlan = null;
        _checkoutError = beanFriendlyErrorMessage(
          error,
          action: 'apply your coupon code',
        );
      });
      rethrow;
    }
  }

  Future<void> _continueAfterCheckout() async {
    setState(() => _checkoutError = null);
    await _loadSignedIn(loadingStatusText: 'Refreshing your subscription...');
  }

  Future<void> _requestPasswordReset(String email) async {
    await widget.apiClient.requestPasswordReset(email: email);
  }

  void _startGuidedOnboarding() {
    setState(() {
      _phase = _AuthPhase.guidedOnboarding;
      _error = null;
      _authNotice = null;
      _checkoutError = null;
      _checkoutBusyPlan = null;
    });
  }

  Future<BeanAuthResult> _registerFromGuidedOnboarding(
    String name,
    String email,
    String password,
    String themeModeKey,
  ) async {
    final timezone = await _deviceIanaTimezone();
    final auth = await widget.apiClient.register(
      name: name,
      email: email,
      password: password,
      clientTimezone: timezone,
    );
    await widget.tokenStore.saveRememberMe(true);
    await widget.tokenStore.saveToken(auth.token);
    if (!mounted) return auth;
    final normalizedThemeModeKey = heyBeanThemeModeForKey(themeModeKey).key;
    var user = auth.user;
    if (user.themeMode != normalizedThemeModeKey) {
      user = await widget.apiClient.updateMe(themeMode: normalizedThemeModeKey);
    }
    _applyUserTheme(user);
    setState(() {
      _user = user;
      _authNotice = null;
      _error = null;
      _checkoutError = null;
      _dashboardDataLoading = false;
    });
    return BeanAuthResult(token: auth.token, user: user);
  }

  Future<void> _launchGuidedLiveTour() async {
    final user = _user;
    if (user == null) return;
    final pendingPlanSelection = _userNeedsSignupPaywall(user);
    await _loadSignedIn(
      knownUser: user,
      loadingStatusText: 'Preparing your dashboard...',
      deferSignupPaywall: pendingPlanSelection,
    );
    if (!mounted || _phase != _AuthPhase.signedIn) return;
    _showOnboardingTour(pendingPlanSelection: pendingPlanSelection);
  }

  String _onboardingTourSeenPreferenceKey(BeanUser user) =>
      '$_onboardingTourSeenPreferencePrefix.${user.id}';

  void _showOnboardingTour({
    bool pendingPlanSelection = false,
    bool finishWithImport = false,
  }) {
    _activateOnboardingTourStep(
      0,
      pendingPlanSelection: pendingPlanSelection,
      finishWithImport: finishWithImport,
    );
    if (_phase == _AuthPhase.signedIn) {
      unawaited(_loadSecondarySignedInData(authGeneration: _authGeneration));
    }
  }

  void _activateOnboardingTourStep(
    int index, {
    bool? pendingPlanSelection,
    bool? finishWithImport,
  }) {
    final boundedIndex = index.clamp(0, _appOnboardingTourSteps.length - 1);
    final step = _appOnboardingTourSteps[boundedIndex];
    setState(() {
      _onboardingTourVisible = true;
      _onboardingTourStep = boundedIndex;
      if (pendingPlanSelection != null) {
        _onboardingTourPendingPlanSelection = pendingPlanSelection;
      }
      if (finishWithImport != null) {
        _onboardingTourFinishWithImport = finishWithImport;
      }
      _selectedDestination = step.destination;
      _clearPlanLimitError();
      if (step.destination == _HomeDestination.today) {
        _selectedCalendarDay = _dateOnly(DateTime.now());
        _showCalendarMonth = false;
      }
    });
  }

  void _advanceOnboardingTour() {
    final nextIndex = _onboardingTourStep + 1;
    if (nextIndex >= _appOnboardingTourSteps.length) {
      unawaited(_markOnboardingTourSeenAndClose());
      return;
    }
    _activateOnboardingTourStep(nextIndex);
  }

  void _dismissOnboardingTour() {
    unawaited(_markOnboardingTourSeenAndClose());
  }

  Future<void> _markOnboardingTourSeenAndClose() async {
    final user = _user;
    final showPlanSelection = _onboardingTourPendingPlanSelection;
    final showImport = _onboardingTourFinishWithImport && !showPlanSelection;
    if (mounted) {
      setState(() {
        _onboardingTourVisible = false;
        _onboardingTourStep = 0;
        _onboardingTourPendingPlanSelection = false;
        _onboardingTourFinishWithImport = false;
        if (showPlanSelection) {
          _phase = _AuthPhase.planSelection;
          _selectedDestination = _HomeDestination.commandCenter;
        }
      });
    }
    if (user != null) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setBool(_onboardingTourSeenPreferenceKey(user), true);
    }
    if (showImport && mounted) {
      await _openExternalCalendarImportSheet();
    }
  }

  Future<void> _openExternalCalendarImportSheet() async {
    final user = _user;
    if (user == null) return;
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: SingleChildScrollView(
          padding: EdgeInsets.fromLTRB(
            20,
            0,
            20,
            MediaQuery.viewInsetsOf(context).bottom + 24,
          ),
          child: _ShellCard(
            child: _ExternalCalendarImportCard(
              apiClient: widget.apiClient,
              user: user,
              title: 'Last step: import your calendar',
              compact: true,
              onImported: () async {
                await _refreshSignedInViews();
              },
              onSkipped: () => Navigator.of(context).maybePop(),
            ),
          ),
        ),
      ),
    );
  }

  void _selectDestination(_HomeDestination destination) {
    setState(() {
      _selectedDestination = destination;
      _clearPlanLimitError();
      if (destination == _HomeDestination.today) {
        _selectedCalendarDay = _dateOnly(DateTime.now());
        _showCalendarMonth = false;
      }
    });
  }

  void _openBeanAssistant() {
    if (_phase != _AuthPhase.signedIn) return;
    setState(() {
      _beanAssistantOpen = !_beanAssistantOpen;
      _beanAssistantError = null;
    });
  }

  void _startBeanAssistantPushToTalkFromDock() {
    if (_phase != _AuthPhase.signedIn) return;
    _beanDockPushToTalkHeld = true;
    setState(() {
      _beanAssistantOpen = true;
      _beanAssistantError = null;
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || !_beanDockPushToTalkHeld || !_beanAssistantOpen) return;
      unawaited(
        _beanAssistantPanelKey.currentState?._startElevenLabsPushToTalk(),
      );
    });
  }

  void _stopBeanAssistantPushToTalkFromDock({bool cancelled = false}) {
    _beanDockPushToTalkHeld = false;
    unawaited(
      _beanAssistantPanelKey.currentState?._stopElevenLabsPushToTalk(
        cancelled: cancelled,
      ),
    );
  }

  Future<void> _sendBeanAssistantMessage(
    String content, {
    String? source,
  }) async {
    if (_beanAssistantSending || content.trim().isEmpty) return;
    setState(() {
      _beanAssistantSending = true;
      _beanAssistantError = null;
      _beanAssistantMessages = [
        ..._beanAssistantMessages,
        BeanAssistantMessage(role: 'user', content: content.trim()),
      ];
    });
    try {
      final turn = await widget.apiClient.sendBeanMessage(
        content: content.trim(),
        sessionId: _beanAssistantSessionId,
        workspaceId: _activeWorkspaceId(),
        clientTimezone: _user?.timezone,
        source: source,
      );
      if (!mounted) return;
      setState(() {
        _beanAssistantSessionId = turn.session.id;
        _beanAssistantMessages = turn.messages;
        _beanAssistantConfirmations = turn.confirmations;
        _beanAssistantSending = false;
      });
      unawaited(_refreshSignedInViews(showLoading: false));
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _beanAssistantSending = false;
        _beanAssistantError = beanFriendlyErrorMessage(
          error,
          action: 'message Bean',
        );
      });
    }
  }

  Future<BeanRealtimeSession> _createElevenLabsBeanSession() =>
      widget.apiClient.createBeanRealtimeSession(
        sessionId: _beanAssistantSessionId,
        workspaceId: _activeWorkspaceId(),
        clientTimezone: _user?.timezone,
      );

  void _applyElevenLabsBeanSession(BeanRealtimeSession realtime) {
    final sessionId = realtime.beanSessionId;
    if (sessionId == null || sessionId <= 0) return;
    setState(() => _beanAssistantSessionId = sessionId);
  }

  Future<void> _recordBeanVoiceEvent(
    String eventType, {
    String? label,
    Map<String, Object?>? payload,
  }) async {
    try {
      await widget.apiClient.recordBeanVoiceEvent(
        eventType: eventType,
        sessionId: _beanAssistantSessionId,
        mode: 'mobile_elevenlabs',
        source: 'flutter_elevenlabs_agent',
        label: label,
        payload: payload,
      );
    } catch (_) {
      // Voice telemetry should never make the assistant UI unusable.
    }
  }

  Future<String> _askBeanFromElevenLabsAgent(
    Map<String, dynamic> parameters,
  ) async {
    final content =
        [parameters['message'], parameters['content'], parameters['request']]
            .map((value) => value?.toString().trim() ?? '')
            .firstWhere((value) => value.isNotEmpty, orElse: () => '');
    if (content.isEmpty) return 'I did not receive a Bean request.';

    if (mounted) {
      setState(() {
        _beanAssistantSending = true;
        _beanAssistantError = null;
        _beanAssistantMessages = [
          ..._beanAssistantMessages,
          BeanAssistantMessage(role: 'user', content: content),
        ];
      });
    }

    await _recordBeanVoiceEvent(
      'bean_request_sent',
      label: content,
      payload: {'transport': 'elevenlabs_agent', 'tool': 'askBean'},
    );

    try {
      final turn = await widget.apiClient.sendBeanMessage(
        content: content,
        sessionId: _beanAssistantSessionId,
        workspaceId: _activeWorkspaceId(),
        clientTimezone: _user?.timezone,
        source: 'elevenlabs_agent',
      );
      final answer = turn.messages.reversed
          .where((message) => message.role == 'assistant')
          .map((message) => message.content.trim())
          .firstWhere((message) => message.isNotEmpty, orElse: () => '');
      if (mounted) {
        setState(() {
          _beanAssistantSessionId = turn.session.id;
          _beanAssistantMessages = turn.messages;
          _beanAssistantConfirmations = turn.confirmations;
          _beanAssistantSending = false;
        });
        unawaited(_refreshSignedInViews(showLoading: false));
      }
      await _recordBeanVoiceEvent(
        'bean_response_received',
        label: answer,
        payload: {
          'transport': 'elevenlabs_agent',
          'tool': 'askBean',
          'run_id': turn.run.id,
          'failed': turn.run.status == 'failed',
        },
      );
      return answer.isNotEmpty ? answer : 'Bean finished that.';
    } catch (error) {
      if (mounted) {
        setState(() {
          _beanAssistantSending = false;
          _beanAssistantError = beanFriendlyErrorMessage(
            error,
            action: 'message Bean',
          );
        });
      }
      await _recordBeanVoiceEvent(
        'voice_request_error',
        label: content,
        payload: {
          'transport': 'elevenlabs_agent',
          'tool': 'askBean',
          'error': error.toString(),
        },
      );
      return 'I hit a problem checking Bean. Please try that again.';
    }
  }

  Future<void> _approveBeanConfirmation(
    BeanAssistantConfirmation confirmation,
  ) async {
    if (_beanAssistantSending) return;
    setState(() {
      _beanAssistantSending = true;
      _beanAssistantError = null;
    });
    try {
      final turn = await widget.apiClient.approveBeanConfirmation(
        confirmation.id,
      );
      if (!mounted) return;
      setState(() {
        _beanAssistantSessionId = turn.session.id;
        _beanAssistantMessages = turn.messages;
        _beanAssistantConfirmations = turn.confirmations;
        _beanAssistantSending = false;
      });
      unawaited(_refreshSignedInViews(showLoading: false));
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _beanAssistantSending = false;
        _beanAssistantError = beanFriendlyErrorMessage(
          error,
          action: 'approve that Bean action',
        );
      });
    }
  }

  void _returnToToday() {
    setState(() {
      _selectedDestination = _HomeDestination.today;
      _selectedCalendarDay = _dateOnly(DateTime.now());
      _showCalendarMonth = false;
      _clearPlanLimitError();
    });
  }

  void _openCurrentCalendarMonth() {
    setState(() {
      _selectedDestination = _HomeDestination.today;
      _selectedCalendarDay = _dateOnly(DateTime.now());
      _showCalendarMonth = true;
      _clearPlanLimitError();
    });
  }

  void _returnToCalendarDay() {
    setState(() {
      _showCalendarMonth = false;
      _clearPlanLimitError();
    });
  }

  void _selectCalendarDay(DateTime date) {
    final allowedDate = _allowedCalendarDate(date);
    final blocked = !_sameCalendarDay(_dateOnly(date), allowedDate);
    setState(() {
      _selectedCalendarDay = allowedDate;
      _showCalendarMonth = false;
      if (blocked) {
        _error = _calendarHistoryLimitMessage();
      } else {
        _clearPlanLimitError();
      }
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
      if (blocked) {
        _error = _calendarHistoryLimitMessage();
      } else {
        _clearPlanLimitError();
      }
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

  void _clearPlanLimitError() {
    if (_isPlanLimitMessage(_error)) {
      _error = null;
    }
  }

  void _dismissError() {
    setState(() => _error = null);
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

  int? _workspaceIdForUser(BeanUser user) =>
      user.activeWorkspace?.numericId ?? user.defaultWorkspaceId;

  String? _dashboardSnapshotPreferenceKeyForUser(
    BeanUser user, {
    int? workspaceId,
  }) {
    final id = workspaceId ?? _workspaceIdForUser(user);
    if (id == null || id <= 0) return null;
    return '$_dashboardSnapshotPreferencePrefix.${user.id}.$id';
  }

  void _cacheCurrentDashboardSnapshot({int? workspaceId}) {
    final id = workspaceId ?? _activeWorkspaceId();
    if (id == null || id <= 0) return;
    final snapshot = _DashboardSnapshot(
      tasks: List<BeanTask>.unmodifiable(_tasks),
      pastTasks: List<BeanTask>.unmodifiable(_pastTasks),
      reminders: List<BeanReminder>.unmodifiable(_reminders),
      calendar: List<BeanCalendarEvent>.unmodifiable(_calendar),
      noteFolders: List<BeanNoteFolder>.unmodifiable(_noteFolders),
      notes: List<BeanNote>.unmodifiable(_notes),
      eventCategories: List<BeanEventCategory>.unmodifiable(_eventCategories),
      googleCalendarStatus: _googleCalendarStatus,
      outlookCalendarStatus: _outlookCalendarStatus,
    );
    _workspaceSnapshots[id] = snapshot;
    final user = _user;
    if (user != null) {
      unawaited(
        _persistDashboardSnapshot(snapshot, user: user, workspaceId: id),
      );
    }
  }

  Future<void> _persistDashboardSnapshot(
    _DashboardSnapshot snapshot, {
    required BeanUser user,
    required int workspaceId,
  }) async {
    final key = _dashboardSnapshotPreferenceKeyForUser(
      user,
      workspaceId: workspaceId,
    );
    if (key == null) return;
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(
        key,
        jsonEncode(_dashboardSnapshotCacheJson(snapshot)),
      );
    } catch (_) {
      // Launch cache is opportunistic; the network refresh remains authoritative.
    }
  }

  Future<_DashboardSnapshot?> _loadPersistedDashboardSnapshot(
    BeanUser user,
  ) async {
    final key = _dashboardSnapshotPreferenceKeyForUser(user);
    if (key == null) return null;
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(key);
      if (raw == null || raw.isEmpty) return null;
      final decoded = jsonDecode(raw);
      if (decoded is! Map) return null;
      return _dashboardSnapshotFromCache(Map<String, Object?>.from(decoded));
    } catch (_) {
      return null;
    }
  }

  void _restoreDashboardSnapshot(_DashboardSnapshot snapshot) {
    _tasks = snapshot.tasks;
    _pastTasks = snapshot.pastTasks;
    _reminders = snapshot.reminders;
    _calendar = snapshot.calendar;
    _noteFolders = snapshot.noteFolders;
    _notes = snapshot.notes;
    _eventCategories = snapshot.eventCategories;
    _googleCalendarStatus = snapshot.googleCalendarStatus;
    _outlookCalendarStatus = snapshot.outlookCalendarStatus;
  }

  void _clearDashboardData() {
    _tasks = const [];
    _pastTasks = const [];
    _reminders = const [];
    _calendar = const [];
    _noteFolders = const [];
    _notes = const [];
    _eventCategories = const [];
    _googleCalendarStatus = null;
    _outlookCalendarStatus = null;
  }

  BeanUser _userWithActiveWorkspace(BeanUser user, BeanWorkspace workspace) {
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
        waitSeconds: 0,
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
        await _refreshSignedInViews(showLoading: false);
      }
    } catch (_) {
      // Live refresh is opportunistic; manual pull-to-refresh still works.
    } finally {
      _dashboardChangePollInFlight = false;
    }
  }

  Future<void> _refreshSignedInViews({bool showLoading = true}) async {
    if (_phase != _AuthPhase.signedIn) return;
    final authGeneration = _authGeneration;
    final refreshGeneration = ++_dashboardRefreshGeneration;
    final dataVersion = _dashboardDataVersion;
    if (showLoading) setState(() => _dashboardDataLoading = true);
    try {
      final results = await Future.wait<Object>([
        widget.apiClient.listTasks(),
        widget.apiClient.listReminders(),
        widget.apiClient.listCalendarEvents(skipExternalSync: true),
        _notesEnabled
            ? widget.apiClient.listNoteFolders()
            : Future<Object>.value(const <BeanNoteFolder>[]),
        _notesEnabled
            ? widget.apiClient.listNotes()
            : Future<Object>.value(const <BeanNote>[]),
        widget.apiClient.listPastTasks(),
        widget.apiClient.listEventCategories(),
        _syncGoogleCalendarIfConnected(syncConnected: false),
        _outlookCalendarStatusOrFallback(fallback: _outlookCalendarStatus),
      ]);
      if (!mounted ||
          _phase != _AuthPhase.signedIn ||
          authGeneration != _authGeneration ||
          refreshGeneration != _dashboardRefreshGeneration ||
          dataVersion != _dashboardDataVersion) {
        return;
      }
      final tasks = _tasksWithPendingWrites(results[0] as List<BeanTask>);
      final reminders = _remindersWithPendingWrites(
        results[1] as List<BeanReminder>,
      );
      final calendar = _calendarEventsForDashboardState(
        listed: results[2] as List<BeanCalendarEvent>,
        summary: const [],
      );
      setState(() {
        _tasks = _dashboardListForMutationRefresh(
          refreshed: tasks,
          current: _tasks,
          showLoading: showLoading,
          deletedIds: _activePendingTaskDeleteIds(),
          idFor: (task) => task.id,
        );
        _reminders = _dashboardListForMutationRefresh(
          refreshed: reminders,
          current: _reminders,
          showLoading: showLoading,
          deletedIds: _activePendingReminderDeleteIds(),
          idFor: (reminder) => reminder.id,
        );
        _calendar = _dashboardListForMutationRefresh(
          refreshed: calendar,
          current: _calendar,
          showLoading: showLoading,
          deletedIds: _activePendingCalendarEventDeleteIds(),
          idFor: (event) => event.id,
        );
        _noteFolders = _sortedNoteFolders(results[3] as List<BeanNoteFolder>);
        _notes = _notesWithLocalDrafts(results[4] as List<BeanNote>);
        _pastTasks = _tasksWithPendingWrites(results[5] as List<BeanTask>);
        _eventCategories = results[6] as List<BeanEventCategory>;
        _googleCalendarStatus = results[7] as GoogleCalendarSyncStatus;
        _outlookCalendarStatus = results[8] as GoogleCalendarSyncStatus;
        _dashboardDataLoading = false;
        _error = null;
      });
      _syncReminderNotifications();
      _cacheCurrentDashboardSnapshot();
      _resumeLocalNoteDrafts();
    } catch (_) {
      if (!mounted ||
          authGeneration != _authGeneration ||
          refreshGeneration != _dashboardRefreshGeneration) {
        return;
      }
      setState(() => _dashboardDataLoading = false);
    }
  }

  Future<void> _refreshWorkspaceDataFromServer({
    bool syncConnectedCalendar = false,
    String errorAction = 'refresh your latest data',
  }) async {
    if (_phase != _AuthPhase.signedIn) return;
    final generation = ++_workspaceRefreshGeneration;
    try {
      final user = await widget.apiClient.me();
      if (!mounted || generation != _workspaceRefreshGeneration) return;
      _applyUserTheme(user);
      setState(() => _user = user);
      if (syncConnectedCalendar) {
        await _syncGoogleCalendarIfConnected(syncConnected: true);
      }
      await _refreshSignedInViews();
    } catch (error) {
      if (!mounted || generation != _workspaceRefreshGeneration) return;
      setState(
        () => _error = beanFriendlyErrorMessage(error, action: errorAction),
      );
    }
  }

  Future<void> _toggleTaskCompletion(BeanTask task) async {
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
    BeanTask task, {
    required bool wasCompleted,
    required BeanTask optimisticTask,
    required List<BeanTask> previousTasks,
    required List<BeanTask> previousPastTasks,
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
      unawaited(_refreshSignedInViews(showLoading: false));
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
    BeanTask? task, {
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
  }) {
    final normalizedDueAt = _taskReminderInputToWireValue(dueAt);
    final normalizedColor = _isHexColor(color)
        ? color!.trim().toUpperCase()
        : _themeCategoryColorHex();
    final metadata = <String, Object?>{
      ...?task?.metadata,
      ...?recurrenceMetadata,
      if (parentTaskId != null || task?.parentTaskId != null)
        'parent_task_id': parentTaskId ?? task!.parentTaskId,
    };
    final previousTasks = _tasks;
    final previousPastTasks = _pastTasks;
    final optimisticTask = task == null
        ? BeanTask(
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
            status: task.status,
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
    BeanTask? task, {
    required String title,
    required String? normalizedDueAt,
    required String? notes,
    required String? category,
    required String? normalizedColor,
    required bool? isCritical,
    required Map<String, Object?> metadata,
    required int? workspaceId,
    required List<Object> syncToWorkspaceIds,
    required BeanTask optimisticTask,
    required List<BeanTask> previousTasks,
    required List<BeanTask> previousPastTasks,
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
              status: task.status,
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
      unawaited(_refreshSignedInViews(showLoading: false));
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
          status: 'scheduled',
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
        );
      },
    );
  }

  Future<void> _deleteTask(
    BeanTask task, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    final previousTasks = _tasks;
    final previousPastTasks = _pastTasks;
    _markDashboardDataMutated();
    final mutationVersion = _dashboardDataVersion;
    final affectsVisibleCopy = _deleteSelectionAffectsVisibleWorkspace(
      task.workspaceId,
      deleteFromWorkspaceIds,
    );
    if (affectsVisibleCopy) {
      _rememberPendingTaskDelete(task.id, mutationVersion);
    }
    setState(() {
      if (affectsVisibleCopy) {
        _tasks = _removeTask(_tasks, task.id);
        _pastTasks = _removeTask(_pastTasks, task.id);
      }
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
    BeanTask task, {
    required List<Object> deleteFromWorkspaceIds,
    required List<BeanTask> previousTasks,
    required List<BeanTask> previousPastTasks,
    required int mutationVersion,
  }) async {
    try {
      await widget.apiClient.deleteTask(
        task.id,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
      );
      if (!_canApplyBackgroundSave(mutationVersion)) return;
      _cacheCurrentDashboardSnapshot();
      unawaited(_refreshSignedInViews(showLoading: false));
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
    BeanReminder? reminder, {
    required String title,
    required String remindAt,
    String status = 'scheduled',
    String? category,
    String? color,
    int? workspaceId,
    Map<String, Object?>? recurrenceMetadata,
    List<Object> syncToWorkspaceIds = const [],
  }) {
    final normalizedRemindAt = _taskReminderInputToWireValue(remindAt);
    if (normalizedRemindAt == null) {
      if (mounted) setState(() => _error = 'Reminder time is required.');
      return Future<void>.value();
    }
    final normalizedColor = _isHexColor(color)
        ? color!.trim().toUpperCase()
        : _themeCategoryColorHex();
    final metadata = <String, Object?>{
      ...?reminder?.metadata,
      ...?recurrenceMetadata,
    };
    final previousReminders = _reminders;
    final optimisticReminder = reminder == null
        ? BeanReminder(
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
    BeanReminder? reminder, {
    required String title,
    required String normalizedRemindAt,
    required String status,
    required String? category,
    required String? normalizedColor,
    required Map<String, Object?> metadata,
    required int? workspaceId,
    required List<Object> syncToWorkspaceIds,
    required BeanReminder optimisticReminder,
    required List<BeanReminder> previousReminders,
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
      unawaited(_refreshSignedInViews(showLoading: false));
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

  Future<void> _toggleReminderCompletion(BeanReminder reminder) async {
    final previousReminders = _reminders;
    final completed = _reminderIsCompleted(reminder);
    final updatedStatus = completed ? 'scheduled' : 'completed';
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
    BeanReminder reminder, {
    required String updatedStatus,
    required bool completed,
    required BeanReminder optimisticReminder,
    required List<BeanReminder> previousReminders,
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
      unawaited(_refreshSignedInViews(showLoading: false));
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
    BeanReminder reminder, {
    List<Object> deleteFromWorkspaceIds = const [],
  }) async {
    final previousReminders = _reminders;
    _markDashboardDataMutated();
    final mutationVersion = _dashboardDataVersion;
    final affectsVisibleCopy = _deleteSelectionAffectsVisibleWorkspace(
      reminder.workspaceId,
      deleteFromWorkspaceIds,
    );
    if (affectsVisibleCopy) {
      _rememberPendingReminderDelete(reminder.id, mutationVersion);
    }
    setState(() {
      if (affectsVisibleCopy) {
        _reminders = _reminders
            .where((item) => item.id != reminder.id)
            .toList();
      }
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
    BeanReminder reminder, {
    required List<Object> deleteFromWorkspaceIds,
    required List<BeanReminder> previousReminders,
    required int mutationVersion,
  }) async {
    try {
      await widget.apiClient.deleteReminder(
        reminder.id,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
      );
      if (!_canApplyBackgroundSave(mutationVersion)) return;
      _cacheCurrentDashboardSnapshot();
      unawaited(_refreshSignedInViews(showLoading: false));
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

  Future<BeanNoteFolder> _createNoteFolder(String name) async {
    final folder = await widget.apiClient.createNoteFolder(name: name);
    if (!mounted) return folder;
    setState(() => _noteFolders = _upsertNoteFolder(_noteFolders, folder));
    _cacheCurrentDashboardSnapshot();
    return folder;
  }

  Future<void> _deleteNoteFolder(BeanNoteFolder folder) async {
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

  Future<BeanNote> _saveNote(
    BeanNote? note, {
    required String title,
    required String bodyHtml,
    required String plainText,
    int? folderId,
    bool clearFolder = false,
    bool? isPinned,
    Map<String, Object?>? metadata,
    List<Object>? syncToWorkspaceIds,
  }) async {
    if (!_notesEnabled) {
      throw BeanApiException(
        402,
        'Notes are available on this plan after upgrading.',
      );
    }
    if (note == null) {
      final saved = await widget.apiClient.createNote(
        title: title,
        bodyHtml: bodyHtml,
        plainText: plainText,
        bodyMarkdown: plainText,
        folderId: folderId,
        isPinned: isPinned ?? false,
        metadata: metadata,
        syncToWorkspaceIds: syncToWorkspaceIds ?? const [],
      );
      if (mounted) {
        setState(() => _notes = _upsertNote(_notes, saved));
        _cacheCurrentDashboardSnapshot();
      }
      return saved;
    }

    final serverMetadata = _noteServerMetadata(metadata ?? note.metadata);
    final optimistic = note.copyWith(
      title: title,
      bodyHtml: bodyHtml,
      plainText: plainText,
      folderId: folderId,
      clearFolder: clearFolder,
      isPinned: isPinned,
      updatedAt: DateTime.now().toUtc().toIso8601String(),
      metadata: {...serverMetadata, _localPendingNoteMetadataKey: true},
      linkedWorkspaceIds: syncToWorkspaceIds == null
          ? note.linkedWorkspaceIds
          : syncToWorkspaceIds
                .map((id) => int.tryParse(id.toString()))
                .whereType<int>()
                .toList(growable: false),
    );
    final version = ++_noteSaveVersion;
    final pending = _PendingNoteSave(
      note: optimistic,
      version: version,
      title: title,
      bodyHtml: bodyHtml,
      plainText: plainText,
      folderId: folderId,
      clearFolder: clearFolder,
      isPinned: isPinned,
      metadata: serverMetadata,
      syncToWorkspaceIds: syncToWorkspaceIds,
    );
    _pendingNoteSaves[note.id] = pending;
    if (mounted) {
      setState(() => _notes = _upsertNote(_notes, optimistic));
      _cacheCurrentDashboardSnapshot();
    }
    _schedulePendingNoteSave(note.id);
    return optimistic;
  }

  Map<String, Object?> _noteServerMetadata(Map<String, Object?> metadata) {
    final serverMetadata = Map<String, Object?>.from(metadata);
    serverMetadata.remove(_localPendingNoteMetadataKey);
    return serverMetadata;
  }

  void _schedulePendingNoteSave(
    int noteId, {
    Duration delay = const Duration(milliseconds: 240),
  }) {
    _pendingNoteSaveTimers.remove(noteId)?.cancel();
    _pendingNoteSaveTimers[noteId] = Timer(
      delay,
      () => unawaited(_drainPendingNoteSave(noteId)),
    );
  }

  void _flushPendingNoteSaves() {
    final noteIds = _pendingNoteSaves.keys.toList(growable: false);
    for (final noteId in noteIds) {
      _pendingNoteSaveTimers.remove(noteId)?.cancel();
      unawaited(_drainPendingNoteSave(noteId));
    }
  }

  Future<void> _drainPendingNoteSave(int noteId) async {
    _pendingNoteSaveTimers.remove(noteId)?.cancel();
    if (_noteSavesInFlight.contains(noteId)) return;
    final pending = _pendingNoteSaves.remove(noteId);
    if (pending == null) return;
    _noteSavesInFlight.add(noteId);
    try {
      final saved = await widget.apiClient.updateNote(
        noteId,
        title: pending.title,
        bodyHtml: pending.bodyHtml,
        plainText: pending.plainText,
        bodyMarkdown: pending.plainText,
        folderId: pending.folderId,
        clearFolder: pending.clearFolder,
        isPinned: pending.isPinned,
        metadata: pending.metadata,
        syncToWorkspaceIds: pending.syncToWorkspaceIds,
      );
      final newerSave = _pendingNoteSaves[noteId];
      if (mounted &&
          (newerSave == null || newerSave.version <= pending.version)) {
        setState(() => _notes = _upsertNote(_notes, saved));
        _cacheCurrentDashboardSnapshot();
      }
    } catch (_) {
      final newerSave = _pendingNoteSaves[noteId];
      if (newerSave == null || newerSave.version < pending.version) {
        _pendingNoteSaves[noteId] = pending;
      }
      if (mounted) {
        _cacheCurrentDashboardSnapshot();
        _schedulePendingNoteSave(noteId, delay: const Duration(seconds: 3));
      }
    } finally {
      _noteSavesInFlight.remove(noteId);
      if (_pendingNoteSaves.containsKey(noteId) &&
          !_pendingNoteSaveTimers.containsKey(noteId)) {
        _schedulePendingNoteSave(noteId);
      }
    }
  }

  List<BeanNote> _notesWithLocalDrafts(List<BeanNote> serverNotes) {
    final merged = <int, BeanNote>{
      for (final note in serverNotes) note.id: note,
    };
    for (final note in _notes) {
      if (note.metadata[_localPendingNoteMetadataKey] == true) {
        merged[note.id] = note;
      }
    }
    return _sortedNotes(merged.values.toList(growable: false));
  }

  void _resumeLocalNoteDrafts() {
    for (final note in _notes.where(
      (note) => note.metadata[_localPendingNoteMetadataKey] == true,
    )) {
      if (_pendingNoteSaves.containsKey(note.id) ||
          _noteSavesInFlight.contains(note.id)) {
        continue;
      }
      unawaited(
        _saveNote(
          note,
          title: note.title,
          bodyHtml: note.bodyHtml ?? '',
          plainText: note.plainText ?? '',
          folderId: note.folderId,
          clearFolder: note.folderId == null,
          isPinned: note.isPinned,
          metadata: _noteServerMetadata(note.metadata),
          syncToWorkspaceIds: note.linkedWorkspaceIds,
        ),
      );
    }
  }

  Future<void> _createNoteFromTopMenu() async {
    if (!_notesEnabled) {
      if (mounted) {
        setState(() => _selectedDestination = _HomeDestination.notes);
      }
      return;
    }
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

  Future<void> _deleteNote(BeanNote note) async {
    await widget.apiClient.deleteNote(note.id);
    if (!mounted) return;
    setState(
      () => _notes = _notes.where((item) => item.id != note.id).toList(),
    );
  }

  Future<BeanEventCategory> _saveEventCategory({
    BeanEventCategory? category,
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
    BeanEventCategory category, {
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
    required bool allDay,
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
    final wireStartsAt = allDay
        ? startsAt
        : _calendarEventWireValueToUtcIso(startsAt) ?? startsAt;
    final wireEndsAt = allDay
        ? endsAt
        : _calendarEventWireValueToUtcIso(endsAt);
    final normalizedColor = _isHexColor(color)
        ? color!.trim().toUpperCase()
        : _themeCategoryColorHex();
    final requestMetadata = metadata == null
        ? null
        : <String, Object?>{...metadata};
    requestMetadata?.remove('all_day');
    final optimisticMetadata = <String, Object?>{
      ...?requestMetadata,
      'all_day': allDay,
    };
    final previousCalendar = _calendar;
    final optimisticEvent = BeanCalendarEvent(
      id: _nextLocalResourceId(),
      title: title,
      startsAt: wireStartsAt,
      endsAt: wireEndsAt,
      notes: notes,
      location: location,
      status: status ?? 'scheduled',
      category: category,
      color: normalizedColor,
      recurrence: recurrence,
      metadata: optimisticMetadata,
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
        allDay: allDay,
        wireStartsAt: wireStartsAt,
        wireEndsAt: wireEndsAt,
        notes: notes,
        location: location,
        status: status,
        category: category,
        normalizedColor: normalizedColor,
        recurrence: recurrence,
        metadata: requestMetadata,
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
    required bool allDay,
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
    required BeanCalendarEvent optimisticEvent,
    required List<BeanCalendarEvent> previousCalendar,
    required int mutationVersion,
  }) async {
    try {
      final createdEvent = await widget.apiClient.createCalendarEvent(
        title: title,
        startsAt: wireStartsAt,
        allDay: allDay,
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
      unawaited(_refreshSignedInViews(showLoading: false));
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
    if (normalizedRecurrence == 'interval') {
      final interval = eventMetadata?['interval'];
      if (interval is int && interval > 0) {
        metadata['interval'] = interval;
      }
      final unit = eventMetadata?['unit'];
      if (unit is String && unit.trim().isNotEmpty) {
        metadata['unit'] = unit;
      }
    }
    return metadata;
  }

  Future<void> _editCalendarEvent(
    BeanCalendarEvent event, {
    required String title,
    required String startsAt,
    required bool allDay,
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
    final wireStartsAt = allDay
        ? startsAt
        : _calendarEventWireValueToUtcIso(startsAt) ?? startsAt;
    final wireEndsAt = allDay
        ? endsAt
        : _calendarEventWireValueToUtcIso(endsAt);
    final normalizedColor = _isHexColor(color)
        ? color!.trim().toUpperCase()
        : _themeCategoryColorHex();
    final requestMetadata = metadata == null
        ? null
        : <String, Object?>{...metadata};
    requestMetadata?.remove('all_day');
    final optimisticMetadata = <String, Object?>{
      ...?requestMetadata,
      'all_day': allDay,
    };
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
      metadata: optimisticMetadata,
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
        allDay: allDay,
        wireStartsAt: wireStartsAt,
        wireEndsAt: wireEndsAt,
        notes: notes,
        location: location,
        status: status,
        category: category,
        normalizedColor: normalizedColor,
        recurrence: recurrence,
        metadata: requestMetadata,
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
    BeanCalendarEvent event, {
    required String title,
    required bool allDay,
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
    required BeanCalendarEvent optimisticEvent,
    required List<BeanCalendarEvent> previousCalendar,
    required int mutationVersion,
  }) async {
    try {
      final updatedEvent = await widget.apiClient.updateCalendarEvent(
        event.id,
        title: title,
        startsAt: wireStartsAt,
        allDay: allDay,
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
      unawaited(_refreshSignedInViews(showLoading: false));
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
    BeanCalendarEvent event, {
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
    final affectsVisibleCopy = _deleteSelectionAffectsVisibleWorkspace(
      event.workspaceId,
      deleteFromWorkspaceIds,
    );
    _markDashboardDataMutated();
    final mutationVersion = _dashboardDataVersion;
    if (!isRecurringOccurrenceDelete && affectsVisibleCopy) {
      _rememberPendingCalendarEventDelete(event.id, mutationVersion);
    }
    setState(() {
      if (isRecurringOccurrenceDelete && affectsVisibleCopy) {
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
      } else if (affectsVisibleCopy) {
        _calendar = _calendar
            .where((candidate) => candidate.id != event.id)
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
    BeanCalendarEvent event, {
    required List<Object> deleteFromWorkspaceIds,
    required String? recurringDeleteMode,
    required String? recurringOccurrenceDate,
    required List<BeanCalendarEvent> previousCalendar,
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
      _cacheCurrentDashboardSnapshot();
      unawaited(_refreshSignedInViews(showLoading: false));
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

  Future<void> _exportAccountData() async {
    try {
      final exported = await widget.apiClient.exportAccount();
      final encoded = const JsonEncoder.withIndent('  ').convert(exported);
      await Clipboard.setData(ClipboardData(text: encoded));
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Account export copied to clipboard.')),
      );
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = beanFriendlyErrorMessage(error, action: 'export your account');
      });
    }
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
    final draft = BeanCalendarEvent(
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
      outlookCalendarStatus: _outlookCalendarStatus,
      workspaces: _user?.workspaces ?? const [],
      activeWorkspaceId: _user?.activeWorkspace?.id,
      onSave:
          (
            _, {
            required String title,
            required String startsAt,
            required bool allDay,
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
            allDay: allDay,
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
    BeanNotificationPreferences preferences,
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

  Future<void> _updateThemeMode(String themeModeKey) async {
    if (_busy) return;
    final normalizedThemeModeKey = heyBeanThemeModeForKey(themeModeKey).key;
    final previousUser = _user;
    setState(() {
      _busy = true;
      _error = null;
      if (previousUser != null) {
        _user = previousUser.copyWith(themeMode: normalizedThemeModeKey);
      }
    });
    _applyUserTheme(_user);
    try {
      final updatedUser = await widget.apiClient.updateMe(
        themeMode: normalizedThemeModeKey,
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
        _error = beanFriendlyErrorMessage(
          error,
          action: 'update your theme mode',
        );
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

  Future<void> _updatePreferredMapApp(String preferredMapApp) async {
    if (_busy) return;
    final normalized = preferredMapApp == 'apple' ? 'apple' : 'google';
    final previousUser = _user;
    setState(() {
      _busy = true;
      _error = null;
      if (previousUser != null) {
        _user = previousUser.copyWith(preferredMapApp: normalized);
        _HeyBeanRuntimeServices.preferredMapApp = normalized;
      }
    });
    try {
      final updatedUser = await widget.apiClient.updateMe(
        preferredMapApp: normalized,
      );
      if (!mounted) return;
      _applyUserTheme(updatedUser);
      _HeyBeanRuntimeServices.preferredMapApp = updatedUser.preferredMapApp;
      setState(() {
        _user = updatedUser;
        _busy = false;
        _error = null;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _user = previousUser;
        if (previousUser != null) {
          _HeyBeanRuntimeServices.preferredMapApp =
              previousUser.preferredMapApp;
        }
        _busy = false;
        _error = beanFriendlyErrorMessage(
          error,
          action: 'update your map preference',
        );
      });
    }
  }

  Future<void> _updateTimezone(String timezone) async {
    if (_busy) return;
    final normalized = timezone.trim();
    if (normalized.isEmpty) return;
    final previousUser = _user;
    setState(() {
      _busy = true;
      _error = null;
      if (previousUser != null) {
        _user = previousUser.copyWith(timezone: normalized);
      }
    });
    try {
      final updatedUser = await widget.apiClient.updateMe(timezone: normalized);
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
          action: 'update your timezone',
        );
      });
      rethrow;
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
        _applyUserTheme(null);
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

  String _workspaceDisplayName(BeanWorkspace workspace) =>
      workspace.isPersonal ? 'Personal' : workspace.name;

  Future<void> _switchWorkspaceFromTopBar(BeanWorkspace workspace) async {
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
      _dashboardDataLoading = true;
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
    final workspaces = user?.workspaces ?? const <BeanWorkspace>[];
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
      Padding(
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
            style: TextStyle(fontWeight: FontWeight.w800),
          ),
          onTap: _busy || workspace.numericId == null
              ? null
              : () {
                  Navigator.pop(context);
                  unawaited(_switchWorkspaceFromTopBar(workspace));
                },
        ),
      Padding(
        padding: EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        child: Divider(height: 1),
      ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final criticalItemCount = _criticalItemCountForToday();
    _scheduleAppIconBadgeSync(criticalItemCount);
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: HeyBeanTheme.isDark
          ? HeyBeanTheme.darkSystemOverlayStyle
          : HeyBeanTheme.lightSystemOverlayStyle,
      child: Container(
        key: const Key('heybean-background-gradient'),
        decoration: BoxDecoration(color: HeyBeanTheme.bg0),
        child: Stack(
          children: [
            const Positioned.fill(
              key: Key('green-glow-left'),
              child: SizedBox.shrink(),
            ),
            Scaffold(
              appBar: AppBar(
                titleSpacing: 12,
                title: _phase == _AuthPhase.signedIn
                    ? KeyedSubtree(
                        key:
                            _onboardingTourTargetKeys[_OnboardingTourTarget
                                .calendarControls],
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            _CalendarHeaderButton(
                              key: const Key('calendar-today-button'),
                              label: _calendarHeaderDayLabel(DateTime.now()),
                              icon: null,
                              horizontalPadding: 10,
                              verticalPadding: 7,
                              labelStyle: TextStyle(
                                fontSize: 13,
                                fontWeight: FontWeight.normal,
                              ),
                              onTap: _returnToToday,
                            ),
                            const SizedBox(width: 8),
                            Flexible(
                              child: _CalendarHeaderButton(
                                key: const Key('calendar-month-chevron'),
                                label: _calendarHeaderMonthLabel(
                                  DateTime.now(),
                                ),
                                icon: Icons.calendar_month_rounded,
                                horizontalPadding: 10,
                                verticalPadding: 7,
                                labelStyle: TextStyle(
                                  fontSize: 13,
                                  fontWeight: FontWeight.normal,
                                ),
                                onTap: _openCurrentCalendarMonth,
                              ),
                            ),
                          ],
                        ),
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
                    KeyedSubtree(
                      key:
                          _onboardingTourTargetKeys[_OnboardingTourTarget
                              .createMenu],
                      child: _CreateItemMenu(
                        onCreateEvent: _showNewCalendarEventEditor,
                        onCreateTask: _showNewTaskEditor,
                        onCreateReminder: _showNewReminderEditor,
                        onCreateNote: _createNoteFromTopMenu,
                      ),
                    ),
                  ],
                  const SizedBox(width: 16),
                ],
              ),
              body: SafeArea(child: _bodyWithBetaBanner()),
              bottomNavigationBar: _phase == _AuthPhase.signedIn
                  ? _SignedInBottomDock(
                      menu: _HeyBeanBottomMenu(
                        selected: _selectedDestination,
                        beanOpen: _beanAssistantOpen,
                        beanSending: _beanAssistantSending,
                        beanHasError: _beanAssistantError != null,
                        onSelected: _selectDestination,
                        onBeanPressed: _openBeanAssistant,
                        onBeanPushToTalkStart:
                            _startBeanAssistantPushToTalkFromDock,
                        onBeanPushToTalkEnd:
                            _stopBeanAssistantPushToTalkFromDock,
                        onMorePressed: _openMoreMenu,
                      ),
                    )
                  : null,
            ),
            if (_beanAssistantOpen)
              _BeanAssistantPanel(
                key: _beanAssistantPanelKey,
                messages: _beanAssistantMessages,
                confirmations: _beanAssistantConfirmations,
                sending: _beanAssistantSending,
                error: _beanAssistantError,
                onSend: _sendBeanAssistantMessage,
                onConfirm: _approveBeanConfirmation,
                onCreateElevenLabsSession: _createElevenLabsBeanSession,
                onElevenLabsSessionReady: _applyElevenLabsBeanSession,
                onAskBeanFromElevenLabsAgent: _askBeanFromElevenLabsAgent,
                onVoiceEvent: _recordBeanVoiceEvent,
                userId: _user?.id,
                workspaceId: _activeWorkspaceId(),
                clientTimezone: _user?.timezone,
              ),
            if (_onboardingTourVisible)
              _OnboardingTourOverlay(
                stepIndex: _onboardingTourStep,
                step: _appOnboardingTourSteps[_onboardingTourStep],
                targetKeys: _onboardingTourTargetKeys,
                primaryLabel:
                    _onboardingTourStep >= _appOnboardingTourSteps.length - 1
                    ? (_onboardingTourFinishWithImport
                          ? 'Import calendar'
                          : _onboardingTourPendingPlanSelection
                          ? 'Plan setup'
                          : 'Finish')
                    : 'Next',
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
            const CircularProgressIndicator(
              key: Key('signed-in-loading-indicator'),
            ),
            if (_loadingStatusText != null) ...[
              const SizedBox(height: 12),
              Text(
                _loadingStatusText!,
                key: const Key('full-screen-loading-message'),
                style: TextStyle(
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
        onStartSignup: _startGuidedOnboarding,
        onForgotPassword: _requestPasswordReset,
        tokenStore: widget.tokenStore,
        launchExternalUrl: widget.launchExternalUrl,
        busy: _busy,
        error: _error,
        notice: _authNotice,
      );
    }
    if (_phase == _AuthPhase.guidedOnboarding) {
      return _GuidedBeanOnboardingScreen(
        apiClient: widget.apiClient,
        stripePaymentHandler: widget.stripePaymentHandler,
        busyPlan: _checkoutBusyPlan,
        checkoutError: _checkoutError,
        onCreateAccount: _registerFromGuidedOnboarding,
        onLaunchLiveTour: _launchGuidedLiveTour,
        onSelectPlan: _startTrialCheckoutForInterval,
        onRedeemCoupon: _redeemCouponCodeForSignup,
        onContactEnterprise: () {
          widget.launchExternalUrl(_enterpriseContactUrl);
        },
        onPreviewThemeMode: widget.onThemeModeChanged,
        onBackToLogin: () {
          _applyUserTheme(null);
          setState(() {
            _phase = _AuthPhase.signedOut;
            _error = null;
            _checkoutError = null;
          });
        },
      );
    }
    if (_phase == _AuthPhase.planSelection) {
      return _SignupPaywallScreen(
        user: _user!,
        busyPlan: _checkoutBusyPlan,
        error: _checkoutError,
        onSelectPlan: _startTrialCheckoutForInterval,
        onRedeemCoupon: _redeemCouponCodeForSignup,
        onContactEnterprise: () {
          widget.launchExternalUrl(_enterpriseContactUrl);
        },
        onContinue: _continueAfterCheckout,
        onSignOut: _logout,
      );
    }
    final user = _user!;
    _HeyBeanRuntimeServices.apiClient = widget.apiClient;
    _HeyBeanRuntimeServices.launchExternalUrl = widget.launchExternalUrl;
    _HeyBeanRuntimeServices.preferredMapApp = user.preferredMapApp;
    final dueReminder = _dueReminderBanner();
    final signedInContent = _signedInContent(user);
    final commandCenterSelected =
        _selectedDestination == _HomeDestination.commandCenter;
    final usesFullHeightSurface =
        commandCenterSelected || _selectedDestination == _HomeDestination.notes;
    final signedInSurface = usesFullHeightSurface
        ? Padding(
            padding: EdgeInsets.fromLTRB(
              20,
              8,
              20,
              commandCenterSelected ? 8 : 12,
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

  Widget _signedInContent(BeanUser user) => _CommandCenterContent(
    apiClient: widget.apiClient,
    user: user,
    tasks: _tasks,
    pastTasks: _pastTasks,
    reminders: _reminders,
    calendar: _calendar,
    noteFolders: _noteFolders,
    notes: _notes,
    noteToOpenId: _noteToOpenId,
    eventCategories: _eventCategories,
    googleCalendarStatus: _googleCalendarStatus,
    outlookCalendarStatus: _outlookCalendarStatus,
    dashboardDataLoading: _dashboardDataLoading,
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
    onErrorDismissed: _dismissError,
    onBackToCalendarDay: _returnToCalendarDay,
    onCalendarStartHourChanged: _setCalendarStartHour,
    onCalendarEndHourChanged: _setCalendarEndHour,
    onboardingTourTargetKeys: _onboardingTourTargetKeys,
    allowNotesPreview: _onboardingTourVisible,
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
    onEventCategorySaved: _saveEventCategory,
    onEventCategoryDeleted: _deleteEventCategory,
    onDeleteAccount: _deleteAccount,
    onSignOut: _logout,
    onExportAccount: _exportAccountData,
    onAccountEmailChanged: _updateAccountEmail,
    onNotificationPreferencesChanged: _updateNotificationPreferences,
    onThemeChanged: _updateTheme,
    onThemeModeChanged: _updateThemeMode,
    onCommandCenterLabelChanged: _updateCommandCenterLabel,
    onPreferredMapAppChanged: _updatePreferredMapApp,
    onTimezoneChanged: _updateTimezone,
    eventCategoriesForSettings: _eventCategories,
    onSettingsEventCategorySaved: _saveEventCategory,
    onSettingsEventCategoryDeleted: _deleteEventCategory,
    launchExternalUrl: widget.launchExternalUrl,
    stripePaymentHandler: widget.stripePaymentHandler,
    onBillingChanged: () =>
        _loadSignedIn(loadingStatusText: 'Refreshing your subscription...'),
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
                  leading: Icon(Icons.settings_rounded),
                  title: Text('Settings'),
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
