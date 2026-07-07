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

  final HermesApiClient apiClient;
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
  GoogleCalendarSyncStatus? _outlookCalendarStatus;
  List<HermesApproval> _approvals = const [];
  List<HermesActivityEvent> _events = const [];
  final List<HermesMessage> _messages = const [
    HermesMessage(id: 0, role: 'assistant', content: 'Bean is waking up…'),
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
  int? _activeAssistantRunId;
  int? _activeBeanWorkMessageId;
  List<_BeanWorkItem> _beanWorkItems = const [];
  int _beanWorkEventFloorId = 0;
  bool _beanWorkFinalized = false;
  bool _beanWorkAcceptsOrphanPlanEvents = false;
  final Set<int> _beanWorkAppliedEventIds = <int>{};
  final Set<int> _beanDashboardRefreshEventIds = <int>{};
  final Set<String> _beanWorkStagedCompletionIds = <String>{};
  final Set<Timer> _beanWorkStageTimers = <Timer>{};
  final Map<Timer, Completer<void>> _beanClientRetryTimers =
      <Timer, Completer<void>>{};
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
  bool _onboardingTourPendingPlanSelection = false;
  bool _onboardingTourFinishWithImport = false;
  final Map<_OnboardingTourTarget, GlobalKey> _onboardingTourTargetKeys = {
    for (final target in _OnboardingTourTarget.values) target: GlobalKey(),
  };
  final TextEditingController _chatInputController = TextEditingController();
  final FocusNode _chatInputFocusNode = FocusNode();
  bool _beanChatCollapsed = false;
  int? _editingChatMessageId;
  int _localMessageSequence = -1;
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
    _cancelBeanResponsePreviewTimer();
    _dismissedBeanResponsePreviewKey = null;
    _beanResponsePreviewHeld = false;
    _loadingStatusText = null;
    _dashboardDataLoading = false;
    _onboardingTourVisible = false;
    _onboardingTourStep = 0;
    _onboardingTourPendingPlanSelection = false;
    _onboardingTourFinishWithImport = false;
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

  List<HermesCalendarEvent> _calendarEventsForDashboardState({
    required List<HermesCalendarEvent> listed,
    required List<HermesCalendarEvent> summary,
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
            _BeanWorkItem(
              id: id,
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
    _beanWorkFinalized = true;
    _scheduleBeanWorkStatusClearIfDone();
  }

  void _scheduleBeanWorkStatusClearIfDone() {
    if (_beanWorkItems.isEmpty || _beanWorkItems.any((item) => !item.done)) {
      return;
    }
    if (_activeAssistantRunId != null) {
      return;
    }
    _scheduleBeanWorkStatusClear(
      _selectedDestination == _HomeDestination.bean
          ? const Duration(seconds: 4)
          : const Duration(seconds: 5),
    );
  }

  void _scheduleBeanWorkStatusClear([
    Duration delay = const Duration(milliseconds: 1900),
  ]) {
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
      if (_busy || _activeAssistantRunId != null) {
        _scheduleBeanWorkStatusClear(delay);
        return;
      }
      setState(() {
        _beanWorkStatusHoldUntil = null;
        _beanWorkStatusMinUntil = null;
        _beanWorkItems = const [];
        _beanWorkFinalized = false;
        _beanWorkAcceptsOrphanPlanEvents = false;
        _activeAssistantRunId = null;
        _activeBeanWorkMessageId = null;
        _beanWorkStagedCompletionIds.clear();
      });
      _cancelBeanWorkStageTimers();
    });
  }

  void _cancelBeanWorkStatusClear() {
    _beanWorkStatusClearTimer?.cancel();
    _beanWorkStatusClearTimer = null;
    _beanWorkStatusHoldUntil = null;
  }

  void _prepareBeanWorkForFreshRequest() {
    _beanWorkStatusClearTimer?.cancel();
    _beanWorkStatusClearTimer = null;
    _beanWorkStatusHoldUntil = null;
    _beanWorkStatusMinUntil = null;
    _beanWorkItems = const [];
    _beanWorkEventFloorId = _events.fold<int>(
      0,
      (maxId, event) => math.max(maxId, event.id),
    );
    _beanWorkAppliedEventIds.clear();
    _beanDashboardRefreshEventIds.clear();
    _beanWorkFinalized = false;
    _beanWorkAcceptsOrphanPlanEvents = false;
    _activeBeanWorkMessageId = null;
    _beanWorkStagedCompletionIds.clear();
    _cancelBeanWorkStageTimers();
  }

  void _cancelBeanWorkStageTimers() {
    for (final timer in _beanWorkStageTimers) {
      timer.cancel();
    }
    _beanWorkStageTimers.clear();
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
    final target = _beanWorkTargetForLabel(label);
    final subjectKey = _beanWorkSubjectKeyForLabel(label);
    final preciseIndex = _beanWorkItems.indexWhere((item) {
      return _beanWorkItemCanResolvePlaceholder(item, target, subjectKey);
    });
    if (preciseIndex >= 0) return preciseIndex;
    if (target.isEmpty) return -1;
    final targetOnlyMatches = <int>[];
    for (var index = 0; index < _beanWorkItems.length; index++) {
      final item = _beanWorkItems[index];
      if (!item.id.startsWith('request-') ||
          item.resolvedByEvent ||
          item.done) {
        continue;
      }
      final placeholderTarget = _beanWorkTargetForLabel(item.label);
      if (placeholderTarget == target) targetOnlyMatches.add(index);
    }
    return targetOnlyMatches.length == 1 ? targetOnlyMatches.first : -1;
  }

  bool _beanWorkHasPendingPlaceholderForLabel(String label) {
    final target = _beanWorkTargetForLabel(label);
    final subjectKey = _beanWorkSubjectKeyForLabel(label);
    return _beanWorkItems.any(
      (item) => _beanWorkItemCanResolvePlaceholder(item, target, subjectKey),
    );
  }

  bool _beanWorkItemCanResolvePlaceholder(
    _BeanWorkItem item,
    String target,
    String subjectKey,
  ) {
    if (!item.id.startsWith('request-') || item.resolvedByEvent || item.done) {
      return false;
    }
    final placeholderTarget = _beanWorkTargetForLabel(item.label);
    final placeholderSubjectKey = _beanWorkSubjectKeyForLabel(item.label);
    if (target.isNotEmpty &&
        placeholderTarget.isNotEmpty &&
        target != placeholderTarget) {
      return false;
    }
    if (subjectKey.isNotEmpty && placeholderSubjectKey.isNotEmpty) {
      return subjectKey == placeholderSubjectKey ||
          subjectKey.contains(placeholderSubjectKey) ||
          placeholderSubjectKey.contains(subjectKey);
    }
    if (subjectKey.isEmpty && placeholderSubjectKey.isNotEmpty) return false;
    return target.isEmpty ||
        placeholderTarget.isEmpty ||
        target == placeholderTarget;
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

  String _beanWorkTargetForLabel(String label) {
    final category = _beanWorkCategoryForLabel(label);
    final separator = category.indexOf(':');
    if (separator < 0 || separator == category.length - 1) return '';
    return category.substring(separator + 1);
  }

  String _beanWorkSubjectKeyForLabel(String label) {
    final separator = label.indexOf(':');
    if (separator < 0 || separator == label.length - 1) return '';
    var subject = label.substring(separator + 1).toLowerCase();
    subject = subject
        .replaceAll(RegExp(r'\([^)]*\)'), ' ')
        .replaceAll(
          RegExp(
            r'\b(calendar|event|events|task|tasks|reminder|reminders|note|notes|create|creating|created|update|updating|updated|delete|deleting|deleted|save|saving|saved)\b',
          ),
          ' ',
        )
        .replaceAll(RegExp(r'[^a-z0-9]+'), ' ')
        .replaceAll(RegExp(r'\s+'), ' ')
        .trim();
    if (subject == 'groceries' || subject == 'grocery store') {
      subject = 'grocery shopping';
    }
    if (subject == 'cooking dinner' || subject == 'make dinner') {
      subject = 'cook dinner';
    }
    return subject;
  }

  bool _isGenericBeanWorkLabel(String label) => RegExp(
    r'^(finish|finished|background work|finish background work|bean started working|read request|working on request|work on request)$',
    caseSensitive: false,
  ).hasMatch(label.trim());

  void _beginBeanWorkEventContext({bool freshRequest = false}) {
    if (freshRequest) {
      _prepareBeanWorkForFreshRequest();
    }
    _beanWorkAcceptsOrphanPlanEvents = true;
  }

  void _seedBeanWorkItemsForRequest(String content) {
    final labels = _beanInitialWorkLabelsForRequest(content);
    _beanWorkItems = [
      for (var index = 0; index < labels.length && index < 6; index++)
        _BeanWorkItem(
          id: 'request-$index',
          label: labels[index],
          status: 'running',
        ),
    ];
  }

  List<String> _beanInitialWorkLabelsForRequest(String content) {
    final labels = _beanWorkLabelsForRequest(content);
    if (labels.isNotEmpty) return labels;
    final backgroundLabel = _beanBackgroundWorkLabelForRequest(content);
    return backgroundLabel == null ? const [] : [backgroundLabel];
  }

  bool _beanRequestIsCapabilityQuestion(String content) =>
      _beanCommandIsCapabilityQuestion(_normalizedBeanCommand(content));

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

  bool _beanRequestShouldUseQueuedRuntime(String content) {
    final command = _normalizedBeanCommand(content);
    if (command.isEmpty || _beanCommandIsCapabilityQuestion(command)) {
      return false;
    }
    return _beanCommandRequiresBackgroundWork(command) ||
        _beanCommandNeedsAgentWork(command);
  }

  bool _beanCommandRequiresBackgroundWork(String command) => RegExp(
    r'\b(weather|forecast|traffic|news|headline|flight|hotel|price|prices|stock|market|sports|score|calendar|agenda|schedule|event|meeting|appointment|task|tasks|todo|to do|reminder|note|notes|approval|workspace|plan|organize|prioritize|available|availability)\b',
  ).hasMatch(command);

  bool _beanCommandNeedsAgentWork(String command) => RegExp(
    r'\b(add|create|make|set|delete|remove|update|change|move|reschedule|complete|mark|save|remember|forget|schedule|book|reserve|find|check|look up)\b',
  ).hasMatch(command);

  String? _beanAcknowledgementForRequest(String content) {
    final command = _normalizedBeanCommand(content);
    if (command.isEmpty || _beanCommandIsCapabilityQuestion(command)) {
      return null;
    }
    final multiStep =
        command.split(RegExp(r'\b(?:and then|then|also|and)\b|[,;]')).length >
            1 ||
        RegExp(r'\b(and then|also|as well)\b').hasMatch(command);
    final action =
        RegExp(r'\b(delete|remove|cancel|forget)\b').hasMatch(command)
        ? 'delete'
        : RegExp(
            r'\b(update|change|move|reschedule|complete|mark)\b',
          ).hasMatch(command)
        ? 'update'
        : RegExp(
            r'\b(add|create|make|set|schedule|book|reserve|save|remember)\b',
          ).hasMatch(command)
        ? 'create'
        : '';
    if (RegExp(r'\b(weather|forecast)\b').hasMatch(command)) {
      return 'Let me check the forecast.';
    }
    if (RegExp(r'\b(traffic|drive|commute)\b').hasMatch(command)) {
      return 'Let me check the route.';
    }
    if (RegExp(r'\b(news|headline|headlines)\b').hasMatch(command)) {
      return 'Let me look for the latest.';
    }
    if (RegExp(
      r'\b(flight|flights|airfare|airfares|ticket|tickets|hotel|hotels|rental car|rentals|reservation|reservations|booking|bookings|available|availability|cheapest|price|prices)\b',
    ).hasMatch(command)) {
      return 'Let me check availability.';
    }
    if (RegExp(r'\b(stock|stocks|market|markets)\b').hasMatch(command)) {
      return 'Let me check the market.';
    }
    if (RegExp(r'\b(sports|score|scores|game|games)\b').hasMatch(command)) {
      return 'Let me check the score.';
    }
    if (RegExp(r'\b(plan|organize|prioritize)\b').hasMatch(command)) {
      return multiStep ? 'Let me map that out.' : 'I’ll think that through.';
    }
    if (RegExp(
          r'\b(calendar|calendars|agenda|schedule|schedules|event|events|meeting|meetings|appointment|appointments)\b',
        ).hasMatch(command) &&
        !RegExp(
          r'\b(reminder|reminders|remind|task|tasks|todo|to do|note|notes|folder|folders|memory|remember|forget)\b',
        ).hasMatch(command)) {
      if (action == 'delete') return 'I’ll take that off your calendar.';
      if (action == 'update') return 'I’ll adjust that on your calendar.';
      if (action == 'create') {
        return multiStep
            ? 'I’ll handle the calendar pieces.'
            : 'I’ll put that on your calendar.';
      }
      return 'Let me check your calendar.';
    }
    if (RegExp(r'\b(reminder|reminders|remind)\b').hasMatch(command)) {
      if (action == 'delete') return 'I’ll remove that reminder.';
      if (action == 'update') return 'I’ll update that reminder.';
      if (action == 'create') return 'I’ll set that reminder.';
      return 'Let me check your reminders.';
    }
    if (RegExp(r'\b(task|tasks|todo|to do)\b').hasMatch(command)) {
      if (action == 'delete') return 'I’ll remove that task.';
      if (action == 'update') return 'I’ll update that task.';
      if (action == 'create') return 'I’ll add that to your tasks.';
      return 'Let me check your tasks.';
    }
    if (RegExp(r'\b(note|notes|folder|folders)\b').hasMatch(command)) {
      if (action == 'delete') return 'I’ll remove that note.';
      if (action == 'update') return 'I’ll update that note.';
      if (action == 'create') return 'I’ll create that note.';
      return 'Let me check your notes.';
    }
    if (RegExp(r'\b(memory|remember|forget)\b').hasMatch(command)) {
      if (action == 'delete') return 'I’ll forget that.';
      if (action == 'create') return 'I’ll remember that.';
      return 'Let me check what I have saved.';
    }
    if (RegExp(r'\b(look up|check|find)\b').hasMatch(command)) {
      return 'Let me check on that.';
    }
    if (multiStep) return 'I’ll handle those one at a time.';
    return 'I’ll take care of that.';
  }

  String? _beanBackgroundWorkLabelForRequest(String content) {
    final command = _normalizedBeanCommand(content);
    if (command.isEmpty || _beanCommandIsCapabilityQuestion(command)) {
      return null;
    }
    final subject = _beanBackgroundWorkSubject(command);
    String withSubject(String base) =>
        subject == null ? base : '$base: $subject';
    if (RegExp(r'\b(weather|forecast)\b').hasMatch(command)) {
      return withSubject('Checking weather');
    }
    if (RegExp(r'\b(traffic|drive|commute)\b').hasMatch(command)) {
      return withSubject('Checking traffic');
    }
    if (RegExp(r'\b(news|headline|headlines)\b').hasMatch(command)) {
      return withSubject('Checking news');
    }
    if (RegExp(
      r'\b(flight|flights|airfare|airfares|ticket|tickets|hotel|hotels|rental car|rentals|reservation|reservations|booking|bookings|available|availability|cheapest|price|prices)\b',
    ).hasMatch(command)) {
      return withSubject('Checking travel');
    }
    if (RegExp(r'\b(stock|stocks|market|markets)\b').hasMatch(command)) {
      return withSubject('Checking markets');
    }
    if (RegExp(r'\b(sports|score|scores|game|games)\b').hasMatch(command)) {
      return withSubject('Checking scores');
    }
    if (RegExp(
      r'\b(calendar|calendars|agenda|schedule|schedules|event|events|meeting|meetings|appointment|appointments)\b',
    ).hasMatch(command)) {
      return withSubject('Checking calendar');
    }
    if (RegExp(r'\b(task|tasks|todo|to do)\b').hasMatch(command)) {
      return withSubject('Checking tasks');
    }
    if (RegExp(r'\b(reminder|reminders)\b').hasMatch(command)) {
      return withSubject('Checking reminders');
    }
    if (RegExp(r'\b(note|notes)\b').hasMatch(command)) {
      return withSubject('Checking notes');
    }
    if (RegExp(r'\b(approval|approvals)\b').hasMatch(command)) {
      return withSubject('Checking approvals');
    }
    if (RegExp(r'\b(workspace|workspaces)\b').hasMatch(command)) {
      return withSubject('Checking workspace');
    }
    if (RegExp(r'\b(plan|organize|prioritize)\b').hasMatch(command)) {
      return withSubject('Planning request');
    }
    if (_beanCommandRequiresBackgroundWork(command) ||
        _beanCommandNeedsAgentWork(command)) {
      return withSubject('Checking request');
    }
    return null;
  }

  String? _beanBackgroundWorkSubject(String command) {
    var text = command
        .replaceAll(
          RegExp(
            r"\b(can you|could you|would you|please|tell me|show me|give me|get me|find me|check|look up|pull up|what is|what's|whats|what are|what's on|whats on|how is|how's|hows|do i have|anything on|any updates on)\b",
          ),
          ' ',
        )
        .replaceAll(
          RegExp(
            r'\b(the|my|a|an|latest|current|currently|right now|now|today|tonight)\b',
          ),
          ' ',
        )
        .replaceAll(
          RegExp(
            r'\b(weather|forecast|traffic|news|headlines?|stocks?|markets?|sports|scores?|flights?|airfares?|tickets?|hotels?|rental cars?|rentals?|reservations?|bookings?|calendar|calendars|agenda|schedule|schedules|events?|meetings?|appointments?|tasks?|todo|to do|reminders?|approvals?|workspaces?)\b',
          ),
          ' ',
        )
        .replaceAll(RegExp(r'\b(for|about|in|on|at|near|nearby)\b'), ' ')
        .replaceAll(RegExp(r'\s+'), ' ')
        .trim();
    if (text.isEmpty ||
        text.length < 3 ||
        RegExp(
          r'^(me|it|that|this|there|anything|something)$',
        ).hasMatch(text)) {
      return null;
    }
    if (text.length > 42) text = '${text.substring(0, 42).trim()}...';
    return text[0].toUpperCase() + text.substring(1);
  }

  List<String> _beanWorkLabelsForRequest(String content) {
    final command = _normalizedBeanCommand(content);
    if (command.isEmpty || _beanCommandIsCapabilityQuestion(command)) {
      return const [];
    }
    final inheritedTarget = _beanWorkTargetForClause(command);
    final labels = command
        .split(RegExp(r'\b(?:and then|then|also|and)\b|[,;]'))
        .map((clause) => _beanWorkLabelForClause(clause, inheritedTarget))
        .whereType<String>()
        .where((label) => !_isGenericBeanWorkLabel(label))
        .toSet()
        .toList();
    if (labels.isNotEmpty) return labels.take(6).toList();
    final fallback = _beanWorkLabelForClause(command, inheritedTarget);
    return fallback == null || _isGenericBeanWorkLabel(fallback)
        ? const []
        : [fallback];
  }

  String _beanWorkTargetForClause(String clause) {
    if (RegExp(r'\b(reminder|reminders|remind)\b').hasMatch(clause)) {
      return 'reminder';
    }
    if (RegExp(r'\b(task|tasks|todo|to do)\b').hasMatch(clause)) {
      return 'task';
    }
    if (RegExp(r'\b(note|notes|folder|folders)\b').hasMatch(clause)) {
      return 'note';
    }
    if (RegExp(r'\b(memory|remember|forget)\b').hasMatch(clause)) {
      return 'memory';
    }
    if (RegExp(
      r'\b(calendar|event|events|meeting|appointment|schedule)\b',
    ).hasMatch(clause)) {
      return 'event';
    }
    return '';
  }

  String? _beanWorkLabelForClause(String rawClause, String inheritedTarget) {
    final clause = rawClause.trim();
    if (clause.isEmpty) return null;
    final action = RegExp(r'\b(delete|remove|cancel|forget)\b').hasMatch(clause)
        ? 'Delete'
        : RegExp(
            r'\b(update|change|move|reschedule|complete|mark)\b',
          ).hasMatch(clause)
        ? 'Update'
        : RegExp(
            r'\b(add|create|make|set|schedule|book|reserve|save|remember)\b',
          ).hasMatch(clause)
        ? 'Create'
        : '';
    if (action.isEmpty) return null;
    final target = _beanWorkTargetForClause(clause).isNotEmpty
        ? _beanWorkTargetForClause(clause)
        : inheritedTarget;
    if (target.isEmpty) return null;
    final targetLabel = switch (target) {
      'event' => 'calendar event',
      'memory' => 'knowledge',
      _ => target,
    };
    final subject = _beanWorkSubjectForClause(clause, target);
    return subject == null
        ? '$action $targetLabel'
        : '$action $targetLabel: $subject';
  }

  String? _beanWorkSubjectForClause(String clause, String target) {
    var subject = clause
        .replaceAll(
          RegExp(
            r'\b(can you|could you|would you|please|also|then|and|to my|on my|my|the|a|an)\b',
          ),
          ' ',
        )
        .replaceAll(
          RegExp(
            r'\b(add|create|make|set|schedule|book|reserve|save|remember|delete|remove|cancel|forget|update|change|move|reschedule|complete|mark)\b',
          ),
          ' ',
        )
        .replaceAll(
          RegExp(
            r'\b(calendar|event|events|meeting|appointment|schedule|reminder|reminders|remind|task|tasks|todo|to do|note|notes|folder|folders|memory|knowledge)\b',
          ),
          ' ',
        )
        .replaceAll(
          RegExp(
            r'\b(for|about|called|named|titled|with title|with the title)\b',
          ),
          ' ',
        )
        .replaceAll(
          RegExp(
            r'\b(today|tonight|tomorrow|next week|next month|at|from|until|by|before|after)\b.*$',
          ),
          ' ',
        )
        .replaceAll(RegExp(r'[^a-z0-9]+'), ' ')
        .replaceAll(RegExp(r'\s+'), ' ')
        .trim();
    if (subject.isEmpty || subject.length < 3) return null;
    if (subject.length > 48) subject = '${subject.substring(0, 48).trim()}...';
    return subject[0].toUpperCase() + subject.substring(1);
  }

  void _applyBeanWorkEvents(List<HermesActivityEvent> events) {
    if (_beanWorkFinalized) return;
    if (!_hasActiveBeanWorkContext) return;
    final ordered = [...events]
      ..sort((left, right) => left.id.compareTo(right.id));
    final appliedEvents = <HermesActivityEvent>[];
    for (final event in ordered) {
      if (event.id <= _beanWorkEventFloorId ||
          _beanWorkAppliedEventIds.contains(event.id)) {
        continue;
      }
      final item = _beanWorkItemFromEvent(event);
      if (item == null) continue;
      if (!_beanWorkEventBelongsToActiveRequest(event, item)) continue;
      _beanWorkAppliedEventIds.add(event.id);
      _beanWorkEventFloorId = math.max(_beanWorkEventFloorId, event.id);
      appliedEvents.add(event);
      final shouldStageCompletion =
          item.done &&
          !_beanWorkItems.any((existing) => existing.id == item.id) &&
          !_beanWorkHasPendingPlaceholderForLabel(item.label);
      if (shouldStageCompletion) {
        _upsertBeanWorkItem(
          item.id,
          item.label,
          status: 'running',
          resolvedByEvent: true,
        );
        _stageBeanWorkCompletion(item);
        continue;
      }
      _upsertBeanWorkItem(
        item.id,
        item.label,
        status: item.status,
        resolvedByEvent: true,
      );
    }
    _finalizeBeanWorkFromCompletedMutationEvents(appliedEvents);
  }

  void _finalizeBeanWorkFromCompletedMutationEvents(
    List<HermesActivityEvent> events,
  ) {
    if (_beanWorkFinalized || events.isEmpty) return;
    if (_beanWorkItems.isEmpty || _beanWorkItems.any((item) => !item.done)) {
      return;
    }
    if (!events.any(_beanActivityEventMutatesDashboard)) return;
    _beanWorkFinalized = true;
    _chatRunState = _activeAssistantRunId == null ? 'Updated' : 'Finalizing...';
    _scheduleBeanWorkStatusClearIfDone();
  }

  bool get _hasActiveBeanWorkContext =>
      _activeBeanWorkMessageId != null ||
      _activeAssistantRunId != null ||
      _beanWorkItems.isNotEmpty;

  void _stageBeanWorkCompletion(_BeanWorkItem item) {
    if (_beanWorkStagedCompletionIds.contains(item.id)) return;
    _beanWorkStagedCompletionIds.add(item.id);
    late final Timer timer;
    timer = Timer(const Duration(milliseconds: 850), () {
      _beanWorkStageTimers.remove(timer);
      if (!mounted || _beanWorkFinalized) return;
      setState(() {
        _upsertBeanWorkItem(
          item.id,
          item.label,
          status: item.status,
          resolvedByEvent: true,
        );
        _beanWorkStagedCompletionIds.remove(item.id);
      });
    });
    _beanWorkStageTimers.add(timer);
  }

  bool _beanWorkEventBelongsToActiveRequest(
    HermesActivityEvent event,
    _BeanWorkItem item,
  ) {
    final activeMessageId = _activeBeanWorkMessageId;
    if (activeMessageId == null) return true;

    final payload = event.payload;
    final eventMessageId =
        _intFromPayload(payload, 'user_message_id') ??
        _intFromPayload(payload, 'message_id') ??
        _intFromPayload(payload, 'request_message_id');
    if (eventMessageId != null) return eventMessageId == activeMessageId;

    final type = event.eventType;
    if (type == 'runtime.run_queued' ||
        type == 'runtime.run_started' ||
        type == 'runtime.run_completed' ||
        type == 'runtime.run_stale_failed' ||
        type == 'runtime.run_failed') {
      return true;
    }

    if (!type.startsWith('assistant.')) return true;

    if (type == 'assistant.work_item.planned' &&
        _beanWorkAcceptsOrphanPlanEvents) {
      return true;
    }

    if (_beanWorkAcceptsOrphanPlanEvents &&
        _beanActivityEventMutatesDashboard(event)) {
      return true;
    }

    return _beanWorkItems.any((existing) => existing.id == item.id) ||
        _beanWorkHasPendingPlaceholderForLabel(item.label);
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
    final workItemId = _stringFromPayload(payload, 'work_item_id');
    final workLabel = _stringFromPayload(payload, 'work_label');
    if (type == 'assistant.work_item.planned') {
      final label =
          _stringFromPayload(payload, 'label') ??
          workLabel ??
          _stringFromPayload(payload, 'title');
      if (workItemId == null || label == null || label.trim().isEmpty) {
        return null;
      }

      return _BeanWorkItem(
        id: workItemId,
        label: label,
        status: 'running',
        resolvedByEvent: true,
      );
    }
    if (type.contains('.duplicate_skipped') && workItemId == null) return null;
    final label = _beanWorkEventLabel(type, payload);
    if (label == null) return null;
    return _BeanWorkItem(
      id: workItemId ?? 'event-${event.id}',
      label: workLabel ?? label,
      status: _beanWorkEventStatus(status),
      resolvedByEvent: workItemId != null,
    );
  }

  String? _stringFromPayload(Map<String, Object?> payload, String key) {
    final value = payload[key];
    if (value == null) return null;
    final string = value.toString().trim();
    return string.isEmpty ? null : string;
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
    _cancelBeanWorkStageTimers();
    _cancelBeanClientRetryTimers();
    _beanWorkStatusClearTimer?.cancel();
    _beanResponsePreviewTimer?.cancel();
    _reminderDueTimer?.cancel();
    _stopDashboardChangePolling();
    _chatInputController.dispose();
    _chatInputFocusNode.dispose();
    unawaited(_pushNotifications.dispose());
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
      _busy ||
      _activeAssistantRunId != null ||
      _beanWorkItems.isNotEmpty ||
      _beanWorkStatusHolding;

  bool get _beanStopAvailable =>
      _busy ||
      _activeAssistantRunId != null ||
      _beanVisibleWorkItems.any((item) => !item.done) ||
      RegExp(
        r'\b(working|thinking|responding|queued|running|finalizing)\b',
        caseSensitive: false,
      ).hasMatch(_chatRunState);

  String get _beanStatusTagLabel {
    final items = _beanVisibleWorkItems;
    if (items.isNotEmpty && items.every((item) => item.done)) return 'Done';
    if (items.any((item) => !item.done)) return 'Working...';
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
    HermesUser? knownUser,
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
    Object? refreshError;

    Future<T> recover<T>(Future<T> future, T fallback) async {
      try {
        return await future;
      } catch (error) {
        refreshError ??= error;
        return fallback;
      }
    }

    final emptySummary = HermesTodaySummary(
      tasks: const [],
      reminders: const [],
      calendarEvents: const [],
      activityEvents: const [],
      approvals: const [],
      blockers: const [],
    );

    try {
      final summaryFuture = recover<HermesTodaySummary>(
        widget.apiClient.todaySummary(),
        emptySummary,
      );
      final user = knownUser ?? await widget.apiClient.me();
      if (!_isCurrentAuthGeneration(authGeneration)) return;
      _applyUserTheme(user);
      if (!deferSignupPaywall && _userNeedsSignupPaywall(user)) {
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
          _googleCalendarStatus = null;
          _outlookCalendarStatus = null;
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
      final workspaceId = _workspaceIdForUser(user);
      final cachedSnapshot = workspaceId == null
          ? null
          : _workspaceSnapshots[workspaceId] ??
                await _loadPersistedDashboardSnapshot(user);
      if (!_isCurrentAuthGeneration(authGeneration)) return;
      setState(() {
        _user = user;
        _session = null;
        if (cachedSnapshot == null) {
          _clearDashboardData();
        } else {
          _restoreDashboardSnapshot(cachedSnapshot);
        }
        _phase = _AuthPhase.signedIn;
        _loadingStatusText = null;
        _dashboardDataLoading = true;
      });

      final sessionDetailsFuture = recover<HermesSessionDetails?>(
        _loadDailySessionForUser(user, source: 'bootstrap'),
        null,
      );
      final googleCalendarStatusFuture = recover<GoogleCalendarSyncStatus>(
        _syncGoogleCalendarIfConnected(
          fallback:
              _googleCalendarStatus ??
              const GoogleCalendarSyncStatus(
                connected: false,
                status: 'not_connected',
              ),
          syncConnected: false,
        ),
        _googleCalendarStatus ??
            const GoogleCalendarSyncStatus(
              connected: false,
              status: 'not_connected',
            ),
      );
      final outlookCalendarStatusFuture = recover<GoogleCalendarSyncStatus>(
        _outlookCalendarStatusOrFallback(
          fallback:
              _outlookCalendarStatus ??
              const GoogleCalendarSyncStatus(
                connected: false,
                status: 'not_connected',
              ),
        ),
        _outlookCalendarStatus ??
            const GoogleCalendarSyncStatus(
              connected: false,
              status: 'not_connected',
            ),
      );
      unawaited(
        _applyCalendarStatusesWhenReady(
          authGeneration: authGeneration,
          googleCalendarStatusFuture: googleCalendarStatusFuture,
          outlookCalendarStatusFuture: outlookCalendarStatusFuture,
        ),
      );
      final primaryResultsFuture = Future.wait<Object>([
        summaryFuture,
        recover<List<HermesTask>>(
          widget.apiClient.listTasks(),
          const <HermesTask>[],
        ),
        recover<List<HermesReminder>>(
          widget.apiClient.listReminders(),
          const <HermesReminder>[],
        ),
        recover<List<HermesCalendarEvent>>(
          widget.apiClient.listCalendarEvents(skipExternalSync: true),
          const <HermesCalendarEvent>[],
        ),
        recover<List<HermesEventCategory>>(
          widget.apiClient.listEventCategories(),
          const <HermesEventCategory>[],
        ),
      ]);
      final primaryResults = await primaryResultsFuture;
      final sessionDetails = await sessionDetailsFuture;
      final session = sessionDetails?.session;
      final summary = primaryResults[0] as HermesTodaySummary;
      final listedTasks = _tasksWithPendingWrites(
        primaryResults[1] as List<HermesTask>,
      );
      final summaryTasks = _tasksWithPendingWrites(summary.tasks);
      final listedReminders = _remindersWithPendingWrites(
        primaryResults[2] as List<HermesReminder>,
      );
      final summaryReminders = _remindersWithPendingWrites(summary.reminders);
      final listedCalendarEvents = _calendarEventsForDashboardState(
        listed: primaryResults[3] as List<HermesCalendarEvent>,
        summary: summary.calendarEvents,
      );
      if (!_isCurrentAuthGeneration(authGeneration)) return;
      _applyUserTheme(user);
      setState(() {
        _user = user;
        _session = session;
        _replaceMessagesFromSession(sessionDetails, user: user);
        _tasks = listedTasks.isEmpty ? summaryTasks : listedTasks;
        _eventCategories = primaryResults[4] as List<HermesEventCategory>;
        _reminders = listedReminders.isEmpty
            ? summaryReminders
            : listedReminders;
        _calendar = listedCalendarEvents;
        _approvals = summary.approvals;
        _events = summary.activityEvents;
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
      unawaited(
        _loadSecondarySignedInData(
          authGeneration: authGeneration,
          sessionId: session?.id,
        ),
      );
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
            ? 'Bean is checking your saved sign-in. Your Remember me token is still saved, and Bean will reconnect when the connection is back.'
            : 'Bean can’t reach your account right now. Please sign in again and Bean will get right back to work.';
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
        _outlookCalendarStatus = null;
        _approvals = const [];
        _events = const [];
        _phase = _AuthPhase.signedOut;
        _loadingStatusText = null;
        _dashboardDataLoading = false;
      });
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
    if (!_isCurrentAuthGeneration(authGeneration) ||
        _phase != _AuthPhase.signedIn) {
      return;
    }
    setState(() {
      _googleCalendarStatus = statuses[0];
      _outlookCalendarStatus = statuses[1];
    });
    _cacheCurrentDashboardSnapshot();
  }

  Future<void> _loadSecondarySignedInData({
    required int authGeneration,
    int? sessionId,
  }) async {
    final dataVersion = _dashboardDataVersion;
    try {
      final notesEnabled = _notesEnabled;
      final results = await Future.wait<Object>([
        notesEnabled
            ? widget.apiClient.listNoteFolders().catchError(
                (_) => const <HermesNoteFolder>[],
              )
            : Future<Object>.value(const <HermesNoteFolder>[]),
        notesEnabled
            ? widget.apiClient.listNotes().catchError(
                (_) => const <HermesNote>[],
              )
            : Future<Object>.value(const <HermesNote>[]),
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
        sessionId == null
            ? Future<Object>.value(const <HermesActivityEvent>[])
            : widget.apiClient
                  .pollActivityEvents(sessionId)
                  .catchError((_) => const <HermesActivityEvent>[]),
      ]);
      if (!mounted ||
          _phase != _AuthPhase.signedIn ||
          authGeneration != _authGeneration ||
          dataVersion != _dashboardDataVersion) {
        return;
      }
      setState(() {
        _noteFolders = _sortedNoteFolders(results[0] as List<HermesNoteFolder>);
        _notes = _sortedNotes(results[1] as List<HermesNote>);
        _memoryItems = _sortedMemoryItems(results[2] as List<HermesMemoryItem>);
        _memorySummaries = results[3] as List<HermesMemorySummary>;
        _memoryHistory = results[4] as List<HermesRequestHistoryItem>;
        _pastTasks = _tasksWithPendingWrites(results[5] as List<HermesTask>);
        final events = results[6] as List<HermesActivityEvent>;
        if (events.isNotEmpty) _events = events;
      });
      _cacheCurrentDashboardSnapshot();
    } catch (_) {
      // Secondary panels can retry on navigation or the next dashboard refresh.
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

  Future<HermesAuthResult> _registerFromGuidedOnboarding(
    String name,
    String email,
    String password,
    String themeModeKey,
  ) async {
    final auth = await widget.apiClient.register(
      name: name,
      email: email,
      password: password,
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
      _forceAgentOnboarding = false;
      _editingAgentPreferences = false;
    });
    return HermesAuthResult(token: auth.token, user: user);
  }

  Future<HermesUser> _saveGuidedOnboardingPreferences({
    required String agentPersonality,
    required String onboardingContext,
    String? homeCity,
  }) async {
    await _completeAgentOnboarding(
      agentPersonality: agentPersonality,
      onboardingPriorities: const ['Planning', 'Reminders', 'Focus'],
      onboardingContext: onboardingContext,
      homeCity: homeCity,
    );
    final user = _user;
    if (user == null) {
      throw StateError('Guided onboarding preferences saved before account.');
    }
    return user;
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

  Future<void> _completeAgentOnboarding({
    required String agentPersonality,
    required List<String> onboardingPriorities,
    String? onboardingContext,
    String? name,
    String? homeCity,
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
        homeCity: homeCity,
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
    return false;
  }

  bool _userNeedsBeanIntroduction(HermesUser user) {
    return false;
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
    _showOnboardingTour();
  }

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
      unawaited(
        _loadSecondarySignedInData(
          authGeneration: _authGeneration,
          sessionId: _session?.id,
        ),
      );
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
      if (step.destination == _HomeDestination.bean) {
        _beanChatCollapsed = false;
      }
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
          _selectedDestination = _HomeDestination.bean;
          _beanChatCollapsed = false;
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

  Future<HermesSessionDetails?> _loadDailySessionForUser(
    HermesUser user, {
    String source = 'flutter',
  }) async {
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

    final session = await widget.apiClient.startSession(
      title: 'Today with Bean',
      runtimeMode: 'chat',
      workspaceId: user.activeWorkspace?.numericId,
      metadata: _flutterChatMetadata(additional: {'reason': source}),
    );
    return HermesSessionDetails(session: session);
  }

  void _replaceMessagesFromSession(
    HermesSessionDetails? details, {
    HermesUser? user,
    bool preserveVisibleMessages = false,
  }) {
    final replacementMessages = <HermesMessage>[];
    if (details != null && details.messages.isNotEmpty) {
      replacementMessages.addAll(
        details.messages
            .map(_displayableChatMessage)
            .whereType<HermesMessage>(),
      );
    }
    if (replacementMessages.isEmpty) {
      replacementMessages.add(
        HermesMessage(
          id: 0,
          role: 'assistant',
          content: _personalizedBeanIntroMessage(user ?? _user),
        ),
      );
    }
    final hasLocalPendingMessages = _messages.any(
      (message) => message.id < 0 || message.metadata['local_ack'] == true,
    );
    if (preserveVisibleMessages &&
        !hasLocalPendingMessages &&
        _messages.length > replacementMessages.length) {
      return;
    }
    _messages.clear();
    _messages.addAll(replacementMessages);
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
      _clearPlanLimitError();
      if (destination == _HomeDestination.today) {
        _selectedCalendarDay = _dateOnly(DateTime.now());
        _showCalendarMonth = false;
      }
      if (destination == _HomeDestination.bean && _needsBeanIntroduction) {
        _ensureBeanIntroductionPrompt();
      }
    });
    if (destination != _HomeDestination.bean &&
        _beanWorkItems.isNotEmpty &&
        _beanWorkItems.every((item) => item.done)) {
      _scheduleBeanWorkStatusClear(const Duration(seconds: 5));
    }
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

  void _replaceChatMessage(int localMessageId, HermesMessage message) {
    final index = _messages.indexWhere(
      (candidate) => candidate.id == localMessageId,
    );
    if (index == -1) return;
    final displayMessage = _displayableChatMessage(message);
    if (displayMessage == null) {
      _messages.removeAt(index);
      return;
    }
    _messages[index] = displayMessage;
  }

  void _beginEditingChatMessage(HermesMessage message) {
    if (_busy || message.role != 'user') return;
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
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('Copied'),
        behavior: SnackBarBehavior.floating,
        margin: EdgeInsets.fromLTRB(16, 0, 16, 16),
        duration: Duration(milliseconds: 1),
      ),
    );
  }

  Future<void> _stopAgent() async {
    final session = _session;
    if (!_beanStopAvailable) return;
    final activeRunId = _activeAssistantRunId;
    _chatRunToken++;
    if (activeRunId != null) {
      unawaited(() async {
        try {
          await widget.apiClient.cancelAssistantRun(activeRunId);
        } catch (_) {
          // The run may have finished before the cancel request arrives.
        }
      }());
    }
    if (mounted) {
      setState(() {
        _busy = false;
        _activeAssistantRunId = null;
        _editingChatMessageId = null;
        _chatRunState = 'Stopped';
        _beanWorkItems = const [];
        _beanWorkAcceptsOrphanPlanEvents = false;
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

  Future<void> _sendChatInputDraft() async {
    final text = _chatInputController.text.trim();
    if (text.isEmpty || _busy) return;
    final editingMessageId = _editingChatMessageId;
    _chatInputController.clear();
    await _sendChat(text, editingMessageId: editingMessageId);
  }

  Future<String?> _sendChat(String content, {int? editingMessageId}) async {
    final trimmed = content.trim();
    var session = _session;
    if (trimmed.isEmpty || session == null) return null;
    final runToken = ++_chatRunToken;
    final capabilityQuestion = _beanRequestIsCapabilityQuestion(trimmed);
    final needsQueuedRuntime = _beanRequestShouldUseQueuedRuntime(trimmed);
    final localAcknowledgement = needsQueuedRuntime
        ? _beanAcknowledgementForRequest(trimmed)
        : null;
    final localUserMessageId = _nextLocalMessageId();
    final localAckMessageId = localAcknowledgement == null
        ? null
        : _nextLocalMessageId();
    final editingServerMessageId =
        editingMessageId != null && editingMessageId > 0
        ? editingMessageId
        : null;
    var chatPhase = 'preparing message';
    String? clientRequestId;
    Map<String, Object?>? messageMetadataForRecovery;
    String? surfacedAssistantText;
    _cancelBeanClientRetryTimers();
    setState(() {
      _busy = true;
      _editingChatMessageId = null;
      _chatRunState = capabilityQuestion || !needsQueuedRuntime
          ? 'Thinking…'
          : 'Bean is working…';
      if (capabilityQuestion) {
        _prepareBeanWorkForFreshRequest();
      } else {
        _beginBeanWorkEventContext(freshRequest: true);
        if (needsQueuedRuntime) _seedBeanWorkItemsForRequest(trimmed);
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
      if (localAcknowledgement != null && localAckMessageId != null) {
        _messages.add(
          HermesMessage(
            id: localAckMessageId,
            role: 'assistant',
            content: localAcknowledgement,
            metadata: {'local_ack': true},
          ),
        );
      }
    });
    try {
      session = _session ?? session;
      final needsBeanIntroduction = _needsBeanIntroduction;
      final useDirectConversationReply =
          !needsBeanIntroduction &&
          (capabilityQuestion ||
              _shouldUseDirectConversationReply(trimmed) ||
              !needsQueuedRuntime);
      chatPhase = needsBeanIntroduction
          ? 'sending Bean introduction message'
          : editingServerMessageId != null
          ? 'branching Bean chat message'
          : useDirectConversationReply
          ? 'sending Bean conversation reply'
          : 'queueing Bean chat message';
      clientRequestId =
          'flutter-chat-${DateTime.now().microsecondsSinceEpoch}-$localUserMessageId';
      final messageMetadata = _flutterChatMetadata(
        additional: {
          'source': useDirectConversationReply
              ? 'flutter_direct_chat'
              : 'flutter',
          'client_request_id': clientRequestId,
          if (editingServerMessageId != null)
            'edited_message_id': editingServerMessageId,
        },
      );
      messageMetadataForRecovery = messageMetadata;
      late final HermesMessageResult result;
      if (needsBeanIntroduction) {
        result = await _sendBeanIntroductionMessage(session.id, trimmed);
      } else if (editingServerMessageId != null) {
        try {
          result = await widget.apiClient.branchMessage(
            sessionId: session.id,
            messageId: editingServerMessageId,
            content: trimmed,
            metadata: messageMetadata,
          );
        } catch (error) {
          if (!_shouldRetryQueuedBeanRequest(error)) rethrow;
          chatPhase = 'queueing edited Bean message after transient failure';
          result = await _queueBeanMessageWithRetry(
            sessionId: session.id,
            content: trimmed,
            metadata: messageMetadata,
          );
        }
      } else if (useDirectConversationReply) {
        try {
          result = await widget.apiClient.sendMessage(
            sessionId: session.id,
            content: trimmed,
            metadata: messageMetadata,
          );
        } catch (error) {
          if (!_shouldRetryQueuedBeanRequest(error)) rethrow;
          chatPhase =
              'queueing Bean conversation reply after transient failure';
          result = await _queueBeanMessageWithRetry(
            sessionId: session.id,
            content: trimmed,
            metadata: messageMetadata,
          );
        }
      } else {
        result = await _queueBeanMessageWithRetry(
          sessionId: session.id,
          content: trimmed,
          metadata: messageMetadata,
        );
      }
      if (!mounted || runToken != _chatRunToken) return null;
      if (result.status == 'queued') {
        setState(() {
          if (result.userMessage != null) {
            _replaceChatMessage(localUserMessageId, result.userMessage!);
            _activeBeanWorkMessageId = result.userMessage!.id;
          }
          _session = result.session;
          _chatRunState = 'working...';
          _events = _mergeEvents(result.events, _events);
          _applyBeanWorkEvents(result.events);
          _applyBeanDashboardMutationEvents(result.events);
        });
        final run = result.run;
        if (run != null) {
          setState(() {
            _activeAssistantRunId = run.id;
            _beginBeanWorkEventContext();
          });
          unawaited(_pollQueuedRun(run.id, runToken));
        } else {
          unawaited(
            _retryQueuedBeanRequestUntilAccepted(
              runToken: runToken,
              sessionId: session.id,
              content: trimmed,
              metadata: messageMetadata,
              localUserMessageId: localUserMessageId,
            ),
          );
        }
        return null;
      }

      if (!needsBeanIntroduction && result.assistantMessage != null) {
        final suppressedAssistantMessage = _assistantMessageShouldStayOutOfChat(
          result.assistantMessage!,
        );
        setState(() {
          if (result.userMessage != null) {
            _replaceChatMessage(localUserMessageId, result.userMessage!);
            _activeBeanWorkMessageId = result.userMessage!.id;
          }
          _session = result.session;
          _events = _mergeEvents(result.events, _events);
          _applyBeanWorkEvents(result.events);
          _applyBeanDashboardMutationEvents(result.events);
          if (result.status == 'cancelled') {
            _chatRunState = 'Stopped';
          } else if (suppressedAssistantMessage) {
            _chatRunState = 'Working in background';
            _beginBeanWorkEventContext();
          } else {
            final displayMessage = _displayableChatMessage(
              result.assistantMessage!,
            );
            if (displayMessage != null &&
                !_messages.any(
                  (candidate) => candidate.id == displayMessage.id,
                )) {
              _messages.add(displayMessage);
              surfacedAssistantText = displayMessage.content;
            }
            _chatRunState = result.status == 'blocked' ? 'Blocked' : 'Updated';
            _completeActiveBeanWorkItems();
          }
          final assistantContent = result.assistantMessage!.content;
          _error = _isPlanLimitMessage(assistantContent)
              ? assistantContent
              : null;
        });
        _refreshDashboardAfterBeanMutationEvents(result.events);
        if (!result.events.any(_beanActivityEventMutatesDashboard)) {
          unawaited(_refreshSignedInViews(showLoading: false));
        }
        return surfacedAssistantText;
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
          .listCalendarEvents(skipExternalSync: true)
          .catchError((_) => _calendar);
      final refreshedTasks = await widget.apiClient.listTasks().catchError(
        (_) => refreshedSummary.tasks,
      );
      if (!mounted || runToken != _chatRunToken) return null;
      final completedBeanIntroduction =
          needsBeanIntroduction && !_userNeedsBeanIntroduction(refreshedUser);
      final suppressedAssistantMessage =
          result.assistantMessage != null &&
          _assistantMessageShouldStayOutOfChat(result.assistantMessage!);
      setState(() {
        if (result.userMessage != null) {
          _replaceChatMessage(localUserMessageId, result.userMessage!);
          _activeBeanWorkMessageId = result.userMessage!.id;
        }
        _user = refreshedUser;
        _session = result.session;
        _error = null;
        if (result.status == 'cancelled') {
          _chatRunState = 'Stopped';
        } else if (result.assistantMessage != null) {
          final displayMessage = _displayableChatMessage(
            result.assistantMessage!,
          );
          if (displayMessage != null) {
            _messages.add(displayMessage);
            surfacedAssistantText = displayMessage.content;
          } else {
            _chatRunState = 'Working in background';
            _beginBeanWorkEventContext();
          }
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
          const fallbackAssistantText =
              'Done — I’m refreshing the latest app details now.';
          _messages.add(
            HermesMessage(
              id: _messages.length + 1,
              role: 'assistant',
              content: fallbackAssistantText,
            ),
          );
          surfacedAssistantText = fallbackAssistantText;
        }
        _chatRunState = suppressedAssistantMessage
            ? 'Working in background'
            : switch (result.status) {
                'blocked' => 'Blocked',
                'cancelled' => 'Stopped',
                _ => 'Updated',
              };
        _tasks = _tasksWithPendingWrites(refreshedTasks);
        _reminders = _remindersWithPendingWrites(refreshedSummary.reminders);
        _calendar = _calendarEventsForDashboardState(
          listed: refreshedCalendar,
          summary: refreshedSummary.calendarEvents,
        );
        _approvals = refreshedSummary.approvals;
        _events = _mergeEvents(result.events, refreshedEvents);
        _applyBeanWorkEvents(_events);
        _applyBeanDashboardMutationEvents(result.events);
        if (suppressedAssistantMessage) {
          _beginBeanWorkEventContext();
        } else {
          _completeActiveBeanWorkItems();
        }
      });
      if (suppressedAssistantMessage) {
        unawaited(
          _retryQueuedBeanRequestUntilAccepted(
            runToken: runToken,
            sessionId: session.id,
            content: trimmed,
            metadata: messageMetadata,
            localUserMessageId: localUserMessageId,
          ),
        );
      }
      if (completedBeanIntroduction) {
        unawaited(_startOnboardingTourAfterBeanIntroduction());
      }
    } catch (error, stackTrace) {
      final recovered = await _recoverChatFailureFromServer(
        runToken: runToken,
        session: session,
        clientRequestId: clientRequestId,
        metadata:
            messageMetadataForRecovery ??
            _flutterChatMetadata(
              additional: {
                if (clientRequestId != null)
                  'client_request_id': clientRequestId,
                if (editingServerMessageId != null)
                  'edited_message_id': editingServerMessageId,
              },
            ),
        localUserMessageId: localUserMessageId,
        originalContent: trimmed,
      );
      if (recovered) {
        return null;
      }
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
      if (!mounted || runToken != _chatRunToken) return null;
      final friendlyFailureMessage = beanFriendlyChatFailureMessage(error);
      setState(() {
        _chatRunState = 'Checking…';
        _beanWorkItems = const [];
        _beanWorkAcceptsOrphanPlanEvents = false;
        _messages.add(
          HermesMessage(
            id: _messages.length + 1,
            role: 'assistant',
            content: friendlyFailureMessage,
          ),
        );
        _error = null;
      });
      surfacedAssistantText = friendlyFailureMessage;
    } finally {
      if (mounted && runToken == _chatRunToken) setState(() => _busy = false);
    }
    final assistantText = beanSafeAssistantDisplayContent(
      surfacedAssistantText ?? '',
    ).trim();
    return assistantText.isEmpty ? null : assistantText;
  }

  Future<bool> _recoverChatFailureFromServer({
    required int runToken,
    required HermesSession? session,
    required String? clientRequestId,
    required Map<String, Object?> metadata,
    required int localUserMessageId,
    required String originalContent,
  }) async {
    final activeSession = session ?? _session;
    final requestId = clientRequestId?.trim() ?? '';
    if (activeSession == null || requestId.isEmpty) {
      return false;
    }

    try {
      final result = await widget.apiClient.lookupQueuedMessage(
        sessionId: activeSession.id,
        clientRequestId: requestId,
      );
      if (!mounted || runToken != _chatRunToken) return true;
      if (_queuedLookupResultShouldKeepRetrying(result)) {
        setState(() {
          if (result.userMessage != null) {
            _replaceChatMessage(localUserMessageId, result.userMessage!);
            _activeBeanWorkMessageId = result.userMessage!.id;
          }
          _session = result.session;
          _chatRunState = 'Working in background';
          _beginBeanWorkEventContext();
          _error = null;
        });
        unawaited(
          _retryQueuedBeanRequestUntilAccepted(
            runToken: runToken,
            sessionId: activeSession.id,
            content: originalContent,
            metadata: metadata,
            localUserMessageId: localUserMessageId,
          ),
        );
        return true;
      }
      _applyRecoveredChatResult(
        result,
        runToken: runToken,
        localUserMessageId: localUserMessageId,
        originalContent: originalContent,
      );
      return true;
    } catch (_) {
      // Fall through to session refresh. Some failures happen after the server
      // saved messages but before the run lookup is available.
    }

    try {
      final details = await widget.apiClient.resumeSessionDetails(
        activeSession.id,
      );
      if (!mounted || runToken != _chatRunToken) return true;
      final messages = details.messages;
      final userIndex = messages.indexWhere(
        (message) =>
            message.role == 'user' &&
            message.metadata['client_request_id'] == requestId,
      );
      if (userIndex == -1) {
        return false;
      }
      final hasAssistantAfter = _hasDisplayableAssistantAfter(
        messages,
        userIndex,
      );
      setState(() {
        _session = details.session;
        _replaceMessagesFromSession(
          details,
          user: _user,
          preserveVisibleMessages: true,
        );
        _activeBeanWorkMessageId = messages[userIndex].id;
        _chatRunState = hasAssistantAfter ? 'Updated' : 'Working in background';
        if (hasAssistantAfter) {
          _activeAssistantRunId = null;
          _completeActiveBeanWorkItems();
        } else {
          _beginBeanWorkEventContext();
        }
        _error = null;
      });
      if (hasAssistantAfter) {
        unawaited(_refreshSignedInViews(showLoading: false));
      }
      return true;
    } catch (_) {
      return false;
    }
  }

  void _applyRecoveredChatResult(
    HermesMessageResult result, {
    required int runToken,
    required int localUserMessageId,
    required String originalContent,
  }) {
    if (!mounted || runToken != _chatRunToken) return;
    if (result.status == 'queued') {
      setState(() {
        if (result.userMessage != null) {
          _replaceChatMessage(localUserMessageId, result.userMessage!);
          _activeBeanWorkMessageId = result.userMessage!.id;
        }
        _session = result.session;
        _chatRunState = 'working...';
        _events = _mergeEvents(result.events, _events);
        _applyBeanWorkEvents(result.events);
        _applyBeanDashboardMutationEvents(result.events);
        _beginBeanWorkEventContext();
        final run = result.run;
        if (run != null) {
          _activeAssistantRunId = run.id;
        }
        _error = null;
      });
      final run = result.run;
      if (run != null) {
        unawaited(_pollQueuedRun(run.id, runToken));
      }
      return;
    }

    setState(() {
      final suppressedAssistantMessage =
          result.assistantMessage != null &&
          _assistantMessageShouldStayOutOfChat(result.assistantMessage!);
      if (result.userMessage != null) {
        _replaceChatMessage(localUserMessageId, result.userMessage!);
        _activeBeanWorkMessageId = result.userMessage!.id;
      }
      _session = result.session;
      _events = _mergeEvents(result.events, _events);
      _applyBeanWorkEvents(result.events);
      _applyBeanDashboardMutationEvents(result.events);
      if (result.assistantMessage != null &&
          !_messages.any(
            (candidate) => candidate.id == result.assistantMessage!.id,
          )) {
        final displayMessage = _displayableChatMessage(
          result.assistantMessage!,
        );
        if (displayMessage != null) {
          _messages.add(displayMessage);
        } else {
          _chatRunState = 'Working in background';
          _beginBeanWorkEventContext();
        }
      }
      _chatRunState = suppressedAssistantMessage
          ? 'Working in background'
          : switch (result.status) {
              'blocked' => 'Blocked',
              'cancelled' => 'Stopped',
              _ => 'Updated',
            };
      if (result.status == 'completed' && !suppressedAssistantMessage) {
        _completeActiveBeanWorkItems();
      } else if (suppressedAssistantMessage) {
        _beginBeanWorkEventContext();
      }
      _activeAssistantRunId = null;
      _error = null;
    });
    unawaited(_refreshSignedInViews(showLoading: false));
  }

  Future<HermesMessageResult> _queueBeanMessageWithRetry({
    required int sessionId,
    required String content,
    required Map<String, Object?> metadata,
  }) async {
    Object? lastError;
    final clientRequestId = metadata['client_request_id'] is String
        ? (metadata['client_request_id']! as String).trim()
        : '';
    for (var attempt = 0; attempt < 3; attempt++) {
      try {
        return await widget.apiClient.queueMessage(
          sessionId: sessionId,
          content: content,
          metadata: metadata,
        );
      } catch (error) {
        lastError = error;
        if (!_shouldRetryQueuedBeanRequest(error) || attempt >= 2) {
          break;
        }
        await Future<void>.delayed(
          Duration(milliseconds: 300 + (attempt * 450)),
        );
      }
    }

    if (clientRequestId.isNotEmpty && lastError != null) {
      for (var attempt = 0; attempt < 10; attempt++) {
        try {
          final recovered = await widget.apiClient.lookupQueuedMessage(
            sessionId: sessionId,
            clientRequestId: clientRequestId,
          );
          if (!_queuedLookupResultShouldKeepRetrying(recovered)) {
            return recovered;
          }
        } catch (lookupError) {
          if (!_shouldRetryQueuedBeanRequest(lookupError) || attempt >= 9) {
            break;
          }
        }
        await Future<void>.delayed(
          Duration(milliseconds: 350 + (attempt * 250)),
        );
      }
    }

    final fallbackSession = _session;
    if (fallbackSession != null &&
        fallbackSession.id == sessionId &&
        lastError != null &&
        _shouldRetryQueuedBeanRequest(lastError)) {
      return HermesMessageResult(
        status: 'queued',
        session: fallbackSession,
        events: const [],
      );
    }

    throw lastError ?? StateError('Bean queue request failed.');
  }

  Future<void> _retryQueuedBeanRequestUntilAccepted({
    required int runToken,
    required int sessionId,
    required String content,
    required Map<String, Object?> metadata,
    required int localUserMessageId,
  }) async {
    final clientRequestId = metadata['client_request_id'] is String
        ? (metadata['client_request_id']! as String).trim()
        : '';
    if (clientRequestId.isEmpty) return;

    for (var attempt = 0; attempt < 18; attempt++) {
      await _sleepWithBeanClientRetryTimer(
        Duration(milliseconds: attempt < 4 ? 900 : 1800),
      );
      if (!mounted || runToken != _chatRunToken) return;

      try {
        final recovered = await widget.apiClient.lookupQueuedMessage(
          sessionId: sessionId,
          clientRequestId: clientRequestId,
        );
        if (!mounted || runToken != _chatRunToken) return;
        if (!_queuedLookupResultShouldKeepRetrying(recovered)) {
          _applyRecoveredChatResult(
            recovered,
            runToken: runToken,
            localUserMessageId: localUserMessageId,
            originalContent: content,
          );
          return;
        }
      } catch (_) {
        // If lookup has not seen the request yet, retry the original
        // idempotent queue call below.
      }

      try {
        final queued = await widget.apiClient.queueMessage(
          sessionId: sessionId,
          content: content,
          metadata: metadata,
        );
        if (!mounted || runToken != _chatRunToken) return;
        _applyRecoveredChatResult(
          queued,
          runToken: runToken,
          localUserMessageId: localUserMessageId,
          originalContent: content,
        );
        return;
      } catch (_) {
        if (!mounted || runToken != _chatRunToken) return;
        setState(() {
          _chatRunState = 'Working in background';
          _beginBeanWorkEventContext();
          _error = null;
        });
      }
    }
  }

  bool _queuedLookupResultShouldKeepRetrying(HermesMessageResult result) {
    if (result.run == null &&
        result.status == 'queued' &&
        result.assistantMessage == null) {
      return true;
    }

    final assistantMessage = result.assistantMessage;
    return result.run == null &&
        assistantMessage != null &&
        beanAssistantMessageShouldStayOutOfChat(assistantMessage);
  }

  Future<void> _sleepWithBeanClientRetryTimer(Duration duration) {
    final completer = Completer<void>();
    late final Timer timer;
    timer = Timer(duration, () {
      _beanClientRetryTimers.remove(timer);
      if (!completer.isCompleted) completer.complete();
    });
    _beanClientRetryTimers[timer] = completer;
    return completer.future;
  }

  void _cancelBeanClientRetryTimers() {
    final pending = Map<Timer, Completer<void>>.from(_beanClientRetryTimers);
    _beanClientRetryTimers.clear();
    for (final entry in pending.entries) {
      final timer = entry.key;
      final completer = entry.value;
      timer.cancel();
      if (!completer.isCompleted) completer.complete();
    }
  }

  bool _shouldRetryQueuedBeanRequest(Object error) {
    return beanShouldRecoverQueuedRequest(error);
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

  Future<void> _pollQueuedRun(int runId, int runToken) async {
    var pollErrors = 0;
    for (var attempt = 0; attempt < 90; attempt++) {
      await Future<void>.delayed(
        attempt == 0
            ? const Duration(milliseconds: 150)
            : const Duration(milliseconds: 250),
      );
      if (!mounted || runToken != _chatRunToken) return;
      try {
        final run = await widget.apiClient.getAssistantRun(runId);
        pollErrors = 0;
        if (!mounted || runToken != _chatRunToken) return;
        final runTerminal =
            run.status == 'completed' ||
            run.status == 'failed' ||
            run.status == 'cancelled';
        if (run.userMessageId != null) {
          setState(() {
            _activeBeanWorkMessageId = run.userMessageId;
          });
        }
        final sessionId = _session?.id;
        if (sessionId != null) {
          final events = await widget.apiClient
              .pollActivityEvents(
                sessionId,
                after: _latestActivityEventId(),
                waitSeconds: runTerminal ? 0 : 1,
              )
              .catchError((_) => const <HermesActivityEvent>[]);
          if (!mounted || runToken != _chatRunToken) return;
          if (events.isNotEmpty) {
            setState(() {
              _events = _mergeEvents(events, _events);
              _applyBeanWorkEvents(events);
              _applyBeanDashboardMutationEvents(events);
            });
            _refreshDashboardAfterBeanMutationEvents(events);
          }
        }
        if (runTerminal) {
          if (run.status == 'completed' && run.assistantMessage == null) {
            if (await _recoverQueuedRunFromSession(
              runId: runId,
              runToken: runToken,
            )) {
              return;
            }
            if (attempt < 89) {
              continue;
            }
          }
          final finalEvents = sessionId == null
              ? const <HermesActivityEvent>[]
              : await widget.apiClient
                    .pollActivityEvents(
                      sessionId,
                      after: _latestActivityEventId(),
                      waitSeconds: 1,
                    )
                    .catchError((_) => const <HermesActivityEvent>[]);
          if (!mounted || runToken != _chatRunToken) return;
          setState(() {
            if (_activeAssistantRunId == runId) _activeAssistantRunId = null;
            if (finalEvents.isNotEmpty) {
              _events = _mergeEvents(finalEvents, _events);
              _applyBeanWorkEvents(finalEvents);
              _applyBeanDashboardMutationEvents(finalEvents);
            }
            _chatRunState = switch (run.status) {
              'completed' => 'Updated',
              'cancelled' => 'Stopped',
              _ => 'Checking…',
            };
            _completeActiveBeanWorkItems(switch (run.status) {
              'completed' => 'completed',
              'cancelled' => 'cancelled',
              _ => 'failed',
            });
            final message = run.assistantMessage;
            if (message != null &&
                !_messages.any((candidate) => candidate.id == message.id)) {
              final displayMessage = _displayableChatMessage(message);
              if (displayMessage != null) {
                _messages.add(displayMessage);
              }
            } else if (run.status == 'failed') {
              _chatRunState = 'Ready';
            }
          });
          _refreshDashboardAfterBeanMutationEvents(finalEvents);
          if (!finalEvents.any(_beanActivityEventMutatesDashboard)) {
            unawaited(_refreshSignedInViews(showLoading: false));
          }
          return;
        }
        if (attempt > 0 &&
            attempt % 12 == 0 &&
            await _recoverQueuedRunFromSession(
              runId: runId,
              runToken: runToken,
            )) {
          return;
        }
      } catch (_) {
        pollErrors++;
        if (pollErrors >= 2 &&
            await _recoverQueuedRunFromSession(
              runId: runId,
              runToken: runToken,
            )) {
          return;
        }
      }
    }
    if (await _recoverQueuedRunFromSession(runId: runId, runToken: runToken)) {
      return;
    }
    if (!mounted || runToken != _chatRunToken) return;
    setState(() {
      _busy = false;
      _chatRunState = 'Working in background';
    });
  }

  Future<bool> _recoverQueuedRunFromSession({
    required int runId,
    required int runToken,
  }) async {
    final session = _session;
    final activeMessageId = _activeBeanWorkMessageId;
    if (session == null || activeMessageId == null) return false;

    try {
      final details = await widget.apiClient.resumeSessionDetails(session.id);
      if (!mounted || runToken != _chatRunToken) return true;

      final messages = details.messages;
      final userIndex = messages.indexWhere(
        (message) => message.id == activeMessageId && message.role == 'user',
      );
      if (userIndex == -1) return false;

      final hasAssistantAfter = _hasDisplayableAssistantAfter(
        messages,
        userIndex,
      );
      if (!hasAssistantAfter) return false;
      setState(() {
        if (_activeAssistantRunId == runId) _activeAssistantRunId = null;
        _session = details.session;
        _replaceMessagesFromSession(
          details,
          user: _user,
          preserveVisibleMessages: true,
        );
        _chatRunState = 'Updated';
        _busy = false;
        _error = null;
        _completeActiveBeanWorkItems();
      });

      return true;
    } catch (_) {
      return false;
    }
  }

  void _refreshDashboardAfterBeanMutationEvents(
    List<HermesActivityEvent> events,
  ) {
    final freshEvents = _freshBeanDashboardMutationEvents(events);
    if (freshEvents.isEmpty) return;
    unawaited(_refreshDashboardResourcesAfterBeanMutations(freshEvents));
  }

  List<HermesActivityEvent> _freshBeanDashboardMutationEvents(
    List<HermesActivityEvent> events,
  ) {
    final fresh = <HermesActivityEvent>[];
    for (final event in events) {
      if (_beanDashboardRefreshEventIds.contains(event.id)) continue;
      if (!_beanActivityEventMutatesDashboard(event)) continue;
      _beanDashboardRefreshEventIds.add(event.id);
      fresh.add(event);
    }
    return fresh;
  }

  Future<void> _refreshDashboardResourcesAfterBeanMutations(
    List<HermesActivityEvent> events,
  ) async {
    final targets = _beanMutationTargets(events);
    if (targets.isEmpty) return;
    final tasksFuture = targets.contains('tasks')
        ? widget.apiClient.listTasks().catchError((_) => _tasks)
        : Future<List<HermesTask>?>.value();
    final pastTasksFuture = targets.contains('tasks')
        ? widget.apiClient.listPastTasks().catchError((_) => _pastTasks)
        : Future<List<HermesTask>?>.value();
    final remindersFuture = targets.contains('reminders')
        ? widget.apiClient.listReminders().catchError((_) => _reminders)
        : Future<List<HermesReminder>?>.value();
    final calendarFuture = targets.contains('calendar')
        ? widget.apiClient
              .listCalendarEvents(skipExternalSync: true)
              .catchError((_) => _calendar)
        : Future<List<HermesCalendarEvent>?>.value();
    final noteFoldersFuture = targets.contains('noteFolders')
        ? widget.apiClient.listNoteFolders().catchError((_) => _noteFolders)
        : Future<List<HermesNoteFolder>?>.value();
    final notesFuture =
        targets.contains('notes') || targets.contains('noteFolders')
        ? widget.apiClient.listNotes().catchError((_) => _notes)
        : Future<List<HermesNote>?>.value();
    final memoryItemsFuture = targets.contains('memory')
        ? widget.apiClient.listMemoryItems().catchError((_) => _memoryItems)
        : Future<List<HermesMemoryItem>?>.value();
    final memorySummariesFuture = targets.contains('memory')
        ? widget.apiClient.listMemorySummaries().catchError(
            (_) => _memorySummaries,
          )
        : Future<List<HermesMemorySummary>?>.value();
    final memoryHistoryFuture = targets.contains('memory')
        ? widget.apiClient.listRequestHistory().catchError(
            (_) => _memoryHistory,
          )
        : Future<List<HermesRequestHistoryItem>?>.value();
    final summaryFuture = targets.contains('summary')
        ? widget.apiClient.todaySummary().catchError(
            (_) => HermesTodaySummary(
              tasks: _tasks,
              reminders: _reminders,
              calendarEvents: _calendar,
              activityEvents: _events,
              approvals: _approvals,
              blockers: const [],
            ),
          )
        : Future<HermesTodaySummary?>.value();

    final results = await Future.wait<Object?>([
      tasksFuture,
      pastTasksFuture,
      remindersFuture,
      calendarFuture,
      noteFoldersFuture,
      notesFuture,
      memoryItemsFuture,
      memorySummariesFuture,
      memoryHistoryFuture,
      summaryFuture,
    ]);
    if (!mounted || _phase != _AuthPhase.signedIn) return;
    setState(() {
      final tasks = results[0] as List<HermesTask>?;
      final pastTasks = results[1] as List<HermesTask>?;
      final reminders = results[2] as List<HermesReminder>?;
      final calendar = results[3] as List<HermesCalendarEvent>?;
      final noteFolders = results[4] as List<HermesNoteFolder>?;
      final notes = results[5] as List<HermesNote>?;
      final memoryItems = results[6] as List<HermesMemoryItem>?;
      final memorySummaries = results[7] as List<HermesMemorySummary>?;
      final memoryHistory = results[8] as List<HermesRequestHistoryItem>?;
      final summary = results[9] as HermesTodaySummary?;

      if (tasks != null) {
        _tasks = _dashboardListForMutationRefresh(
          refreshed: _tasksWithPendingWrites(tasks),
          current: _tasks,
          showLoading: false,
          deletedIds: _activePendingTaskDeleteIds(),
          idFor: (task) => task.id,
        );
      }
      if (pastTasks != null) {
        _pastTasks = _tasksWithPendingWrites(pastTasks);
      }
      if (reminders != null) {
        _reminders = _dashboardListForMutationRefresh(
          refreshed: _remindersWithPendingWrites(reminders),
          current: _reminders,
          showLoading: false,
          deletedIds: _activePendingReminderDeleteIds(),
          idFor: (reminder) => reminder.id,
        );
      }
      if (calendar != null) {
        _calendar = _dashboardListForMutationRefresh(
          refreshed: _calendarEventsForDashboardState(
            listed: calendar,
            summary: _calendar,
          ),
          current: _calendar,
          showLoading: false,
          deletedIds: _activePendingCalendarEventDeleteIds(),
          idFor: (event) => event.id,
        );
      }
      if (noteFolders != null) _noteFolders = _sortedNoteFolders(noteFolders);
      if (notes != null) _notes = _sortedNotes(notes);
      if (memoryItems != null) _memoryItems = _sortedMemoryItems(memoryItems);
      if (memorySummaries != null) _memorySummaries = memorySummaries;
      if (memoryHistory != null) _memoryHistory = memoryHistory;
      if (summary != null) {
        _approvals = summary.approvals;
        _events = _mergeEvents(summary.activityEvents, _events);
      }
      _markDashboardDataMutated();
      _cacheCurrentDashboardSnapshot();
    });
  }

  Set<String> _beanMutationTargets(List<HermesActivityEvent> events) {
    final targets = <String>{};
    for (final event in events) {
      final type = event.eventType;
      if (type.contains('.task.')) targets.add('tasks');
      if (type.contains('.reminder.')) targets.add('reminders');
      if (type.contains('.calendar_event.')) targets.add('calendar');
      if (type.contains('.note_folder.')) targets.add('noteFolders');
      if (type.contains('.note.')) targets.add('notes');
      if (type.contains('.memory.')) targets.add('memory');
      if (type.contains('.approval.') || type.contains('.blocker.')) {
        targets.add('summary');
      }
    }
    return targets;
  }

  void _applyBeanDashboardMutationEvents(List<HermesActivityEvent> events) {
    if (events.isEmpty) return;
    var mutated = false;

    for (final event in events) {
      if (!_beanActivityEventMutatesDashboard(event)) continue;
      final mutationVersion = ++_dashboardDataVersion;
      final type = event.eventType;
      final payload = event.payload;

      if (type == 'assistant.task.deleted') {
        final id = _intFromPayload(payload, 'task_id');
        if (id == null) continue;
        _rememberPendingTaskDelete(id, mutationVersion);
        _tasks = _tasks.where((task) => task.id != id).toList();
        _pastTasks = _pastTasks.where((task) => task.id != id).toList();
        mutated = true;
        continue;
      }

      if (type == 'assistant.reminder.deleted') {
        final id = _intFromPayload(payload, 'reminder_id');
        if (id == null) continue;
        _rememberPendingReminderDelete(id, mutationVersion);
        _reminders = _reminders.where((reminder) => reminder.id != id).toList();
        mutated = true;
        continue;
      }

      if (type == 'assistant.calendar_event.deleted') {
        final id = _intFromPayload(payload, 'calendar_event_id');
        if (id == null) continue;
        _rememberPendingCalendarEventDelete(id, mutationVersion);
        _calendar = _calendar.where((event) => event.id != id).toList();
        mutated = true;
        continue;
      }

      if (type == 'assistant.note.deleted') {
        final id = _intFromPayload(payload, 'note_id');
        if (id == null) continue;
        _notes = _notes.where((note) => note.id != id).toList();
        mutated = true;
        continue;
      }

      if (type == 'assistant.note_folder.deleted') {
        final id = _intFromPayload(payload, 'note_folder_id');
        if (id == null) continue;
        _noteFolders = _noteFolders.where((folder) => folder.id != id).toList();
        mutated = true;
      }
    }

    if (mutated) {
      _markDashboardDataMutated();
    }
  }

  int? _intFromPayload(Map<String, Object?> payload, String key) {
    final value = payload[key];
    if (value is int) return value;
    if (value is num) return value.toInt();
    if (value is String) return int.tryParse(value);
    return null;
  }

  bool _beanActivityEventMutatesDashboard(HermesActivityEvent event) {
    final type = event.eventType;
    if (!type.startsWith('assistant.')) return false;
    if (!_beanWorkStatusDone(_beanWorkEventStatus(event.status ?? ''))) {
      return false;
    }
    return RegExp(
      r'\.(?:task|reminder|calendar_event|note|note_folder|memory|approval|blocker)\.(?:created|updated|deleted)$',
    ).hasMatch(type);
  }

  HermesMessage? _displayableChatMessage(HermesMessage message) {
    if (_assistantMessageShouldStayOutOfChat(message)) {
      return null;
    }

    final content = _naturalLanguageContent(message.content);
    final safeContent = content == null
        ? null
        : beanSafeAssistantDisplayContent(content);
    return HermesMessage(
      id: message.id,
      role: message.role,
      content:
          safeContent ??
          (message.metadata['runtime'] == 'tools' ? 'Done.' : null),
      metadata: message.metadata,
    );
  }

  bool _hasDisplayableAssistantAfter(
    List<HermesMessage> messages,
    int userIndex,
  ) {
    return messages
        .skip(userIndex + 1)
        .where((message) => message.role == 'assistant')
        .map(_displayableChatMessage)
        .whereType<HermesMessage>()
        .any((message) => (message.content ?? '').trim().isNotEmpty);
  }

  bool _assistantMessageShouldStayOutOfChat(HermesMessage message) {
    return beanAssistantMessageShouldStayOutOfChat(message);
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
        if (decoded.containsKey('role') || decoded.containsKey('content')) {
          return null;
        }
      }
    } catch (_) {
      // Plain-text assistant messages are already displayable.
    }
    final cleaned = _removeLeadingJsonLines(trimmed);
    if (cleaned != null) return cleaned;
    return content;
  }

  String? _removeLeadingJsonLines(String content) {
    final lines = content.split('\n');
    var firstNaturalLine = 0;
    while (firstNaturalLine < lines.length) {
      final line = lines[firstNaturalLine].trim();
      if (line.isEmpty) {
        firstNaturalLine++;
        continue;
      }
      if (!_looksLikeStandaloneJsonObject(line)) break;
      firstNaturalLine++;
    }
    if (firstNaturalLine == 0 || firstNaturalLine >= lines.length) {
      return null;
    }
    final cleaned = lines.skip(firstNaturalLine).join('\n').trim();
    return cleaned.isEmpty ? null : cleaned;
  }

  bool _looksLikeStandaloneJsonObject(String value) {
    if (!value.startsWith('{') || !value.endsWith('}')) return false;
    try {
      return jsonDecode(value) is Map<String, Object?>;
    } catch (_) {
      return false;
    }
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

  int _latestActivityEventId() {
    var latest = 0;
    for (final event in _events) {
      if (event.id > latest) latest = event.id;
    }
    return latest;
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

  int? _workspaceIdForUser(HermesUser user) =>
      user.activeWorkspace?.numericId ?? user.defaultWorkspaceId;

  String? _dashboardSnapshotPreferenceKeyForUser(
    HermesUser user, {
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
    required HermesUser user,
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
    HermesUser user,
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
    _memoryItems = snapshot.memoryItems;
    _memorySummaries = snapshot.memorySummaries;
    _memoryHistory = snapshot.memoryHistory;
    _eventCategories = snapshot.eventCategories;
    _approvals = snapshot.approvals;
    _events = snapshot.events;
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
    _memoryItems = const [];
    _memorySummaries = const [];
    _memoryHistory = const [];
    _eventCategories = const [];
    _googleCalendarStatus = null;
    _outlookCalendarStatus = null;
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
        waitSeconds: _busy || _activeAssistantRunId != null ? 1 : 0,
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
    final session = _session;
    if (_phase != _AuthPhase.signedIn) return;
    final authGeneration = _authGeneration;
    final refreshGeneration = ++_dashboardRefreshGeneration;
    final dataVersion = _dashboardDataVersion;
    final user = _user;
    if (showLoading) {
      setState(() {
        _dashboardDataLoading = true;
      });
    }
    try {
      final sessionDetailsFuture =
          session != null && _activeAssistantRunId != null
          ? widget.apiClient
                .resumeSessionDetails(session.id)
                .then<HermesSessionDetails?>((details) => details)
                .catchError((_) => null)
          : session == null && user != null
          ? _loadDailySessionForUser(
              user,
              source: 'refresh',
            ).catchError((_) => null)
          : Future<HermesSessionDetails?>.value(null);
      final googleCalendarStatus = await _syncGoogleCalendarIfConnected(
        syncConnected: false,
      );
      final outlookCalendarStatus = await _outlookCalendarStatusOrFallback(
        fallback: _outlookCalendarStatus,
      );
      final results = await Future.wait<Object>([
        widget.apiClient.todaySummary(),
        widget.apiClient.listTasks().catchError((_) => const <HermesTask>[]),
        widget.apiClient.listReminders().catchError(
          (_) => const <HermesReminder>[],
        ),
        widget.apiClient
            .listCalendarEvents(skipExternalSync: true)
            .catchError((_) => const <HermesCalendarEvent>[]),
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
      final sessionDetails = await sessionDetailsFuture;
      final summary = results[0] as HermesTodaySummary;
      final listedTasks = _tasksWithPendingWrites(
        results[1] as List<HermesTask>,
      );
      final summaryTasks = _tasksWithPendingWrites(summary.tasks);
      final listedReminders = _remindersWithPendingWrites(
        results[2] as List<HermesReminder>,
      );
      final summaryReminders = _remindersWithPendingWrites(summary.reminders);
      final listedCalendarEvents = _calendarEventsForDashboardState(
        listed: results[3] as List<HermesCalendarEvent>,
        summary: summary.calendarEvents,
      );
      final refreshedTasks = listedTasks.isEmpty ? summaryTasks : listedTasks;
      final refreshedReminders = listedReminders.isEmpty
          ? summaryReminders
          : listedReminders;
      final refreshedActivityEvents = results[11] as List<HermesActivityEvent>;
      if (!mounted ||
          _phase != _AuthPhase.signedIn ||
          authGeneration != _authGeneration ||
          refreshGeneration != _dashboardRefreshGeneration ||
          dataVersion != _dashboardDataVersion) {
        return;
      }
      setState(() {
        if (sessionDetails != null) {
          _session = sessionDetails.session;
          _replaceMessagesFromSession(
            sessionDetails,
            user: user,
            preserveVisibleMessages: true,
          );
        }
        _tasks = _dashboardListForMutationRefresh(
          refreshed: refreshedTasks,
          current: _tasks,
          showLoading: showLoading,
          deletedIds: _activePendingTaskDeleteIds(),
          idFor: (task) => task.id,
        );
        _noteFolders = _sortedNoteFolders(results[4] as List<HermesNoteFolder>);
        _notes = _sortedNotes(results[5] as List<HermesNote>);
        _memoryItems = _sortedMemoryItems(results[6] as List<HermesMemoryItem>);
        _memorySummaries = results[7] as List<HermesMemorySummary>;
        _memoryHistory = results[8] as List<HermesRequestHistoryItem>;
        _pastTasks = _tasksWithPendingWrites(results[9] as List<HermesTask>);
        _eventCategories = results[10] as List<HermesEventCategory>;
        _googleCalendarStatus = googleCalendarStatus;
        _outlookCalendarStatus = outlookCalendarStatus;
        _reminders = _dashboardListForMutationRefresh(
          refreshed: refreshedReminders,
          current: _reminders,
          showLoading: showLoading,
          deletedIds: _activePendingReminderDeleteIds(),
          idFor: (reminder) => reminder.id,
        );
        _calendar = _dashboardListForMutationRefresh(
          refreshed: listedCalendarEvents,
          current: _calendar,
          showLoading: showLoading,
          deletedIds: _activePendingCalendarEventDeleteIds(),
          idFor: (event) => event.id,
        );
        _approvals = summary.approvals;
        _events = refreshedActivityEvents;
        if (_busy ||
            _activeAssistantRunId != null ||
            _beanWorkItems.isNotEmpty) {
          _applyBeanWorkEvents(refreshedActivityEvents);
        }
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
        _dashboardDataLoading = false;
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
    setState(() {
      _dashboardDataLoading = true;
    });
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
      final outlookCalendarStatus = await _outlookCalendarStatusOrFallback(
        fallback: _outlookCalendarStatus,
      );
      final results = await Future.wait<Object>([
        widget.apiClient.todaySummary(),
        widget.apiClient.listTasks().catchError((_) => const <HermesTask>[]),
        widget.apiClient.listReminders().catchError(
          (_) => const <HermesReminder>[],
        ),
        widget.apiClient
            .listCalendarEvents(skipExternalSync: !syncConnectedCalendar)
            .catchError((_) => const <HermesCalendarEvent>[]),
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
      final listedCalendarEvents = _calendarEventsForDashboardState(
        listed: results[3] as List<HermesCalendarEvent>,
        summary: summary.calendarEvents,
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
        _outlookCalendarStatus = outlookCalendarStatus;
        _reminders = listedReminders.isEmpty
            ? summaryReminders
            : listedReminders;
        _calendar = listedCalendarEvents;
        _approvals = summary.approvals;
        _events = results[11] as List<HermesActivityEvent>;
        _error = null;
        _dashboardDataLoading = false;
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
    HermesReminder? reminder, {
    required String title,
    required String remindAt,
    String status = 'pending',
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
    HermesReminder reminder, {
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
    if (!_notesEnabled) {
      throw HermesApiException(
        402,
        'Notes are available on this plan after upgrading.',
      );
    }
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
    final normalizedColor = _isHexColor(color)
        ? color!.trim().toUpperCase()
        : _themeCategoryColorHex();
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
    final normalizedColor = _isHexColor(color)
        ? color!.trim().toUpperCase()
        : _themeCategoryColorHex();
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
      outlookCalendarStatus: _outlookCalendarStatus,
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
    final showBeanIntroSpotlight = _showBeanIntroSpotlight;
    final beanResponsePreview = _beanCollapsedResponsePreview;
    final beanWorkStripActive =
        _beanStatusTagVisible && _beanVisibleWorkItems.isNotEmpty;
    final beanDockStatusLift = _selectedDestination == _HomeDestination.bean
        ? _beanChatComposerReservedHeight +
              _beanWorkDockStripHeight(
                _beanVisibleWorkItems,
                beanWorkStripActive,
              )
        : 0.0;
    _syncBeanResponsePreviewTimer(beanResponsePreview);
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
                      showComposer:
                          _selectedDestination == _HomeDestination.bean,
                      beanWorkItems: _beanVisibleWorkItems,
                      beanWorkStatus: _beanStatusTagLabel,
                      beanWorkActive: beanWorkStripActive,
                      composer: _DockedBeanChatComposer(
                        controller: _chatInputController,
                        focusNode: _chatInputFocusNode,
                        busy: _beanStopAvailable,
                        attachedToWorkStrip: beanWorkStripActive,
                        onSend: () => unawaited(_sendChatInputDraft()),
                        onStop: _stopAgent,
                      ),
                      menu: _HeyBeanBottomMenu(
                        selected: _selectedDestination,
                        beanWorking: _beanStopAvailable,
                        onSelected: _selectDestination,
                        onMorePressed: _openMoreMenu,
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
                        ? beanDockStatusLift
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
            const CircularProgressIndicator(),
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
        onSavePreferences: _saveGuidedOnboardingPreferences,
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
    outlookCalendarStatus: _outlookCalendarStatus,
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
    onErrorDismissed: _dismissError,
    onBackToCalendarDay: _returnToCalendarDay,
    onCalendarStartHourChanged: _setCalendarStartHour,
    onCalendarEndHourChanged: _setCalendarEndHour,
    onSelectDestination: _selectDestination,
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
    onThemeModeChanged: _updateThemeMode,
    onCommandCenterLabelChanged: _updateCommandCenterLabel,
    onPreferredMapAppChanged: _updatePreferredMapApp,
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
