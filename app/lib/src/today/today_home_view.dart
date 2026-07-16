part of '../../main.dart';

class _TodayHomeView extends StatelessWidget {
  const _TodayHomeView({
    required this.user,
    required this.tasks,
    required this.calendar,
    required this.loading,
    required this.eventCategories,
    required this.googleCalendarStatus,
    required this.outlookCalendarStatus,
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

  final BeanUser user;
  final List<BeanTask> tasks;
  final List<BeanCalendarEvent> calendar;
  final bool loading;
  final List<BeanEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final DateTime selectedDay;
  final bool showMonth;
  final int startHour;
  final int endHour;
  final DateTime? calendarMinimumDay;
  final VoidCallback onCalendarHistoryLimitReached;
  final ValueChanged<DateTime> onDateSelected;
  final ValueChanged<DateTime> onMonthSelected;
  final VoidCallback onBackToDay;
  final Future<void> Function(BeanTask task) onTaskCompleted;
  final Future<void> Function(
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
    List<Object> syncToWorkspaceIds,
  })
  onTaskSaved;
  final Future<void> Function(
    BeanTask task, {
    List<Object> deleteFromWorkspaceIds,
  })
  onTaskDeleted;
  final Future<void> Function({
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
    List<Object> syncToWorkspaceIds,
  })
  onCalendarEventCreated;
  final Future<void> Function(
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
    List<Object> syncToWorkspaceIds,
  })
  onCalendarEventEdited;
  final Future<void> Function(
    BeanCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onCalendarEventDeleted;
  final Future<BeanEventCategory> Function({
    BeanEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    BeanEventCategory category, {
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
                outlookCalendarStatus: outlookCalendarStatus,
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
    BeanTask? task,
    BeanTask? parentTask,
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
      initialTime: _formatCalendarDateTimeInput(task?.dueAt),
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
    );
  }
}

class _CriticalTaskBadge extends StatelessWidget {
  const _CriticalTaskBadge({
    required this.tasks,
    required this.reminders,
    required this.events,
  });

  final List<BeanTask> tasks;
  final List<BeanReminder> reminders;
  final List<BeanCalendarEvent> events;

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
          fontSize: 21,
          fontWeight: FontWeight.w600,
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
                style: TextStyle(
                  color: HeyBeanTheme.text,
                  fontWeight: FontWeight.w800,
                ),
              ),
              if (subtitle.isNotEmpty)
                Text(
                  subtitle,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(color: HeyBeanTheme.muted, fontSize: 12),
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
const _calendarEventBlockFillAlpha = .14;
const _calendarEventBlockBorderAlpha = .35;

class _AppleStyleTodayTimeline extends StatefulWidget {
  const _AppleStyleTodayTimeline({
    required this.calendar,
    required this.eventCategories,
    required this.googleCalendarStatus,
    required this.outlookCalendarStatus,
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

  final List<BeanCalendarEvent> calendar;
  final List<BeanEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final List<BeanWorkspace> workspaces;
  final String? activeWorkspaceId;
  final DateTime selectedDay;
  final int startHour;
  final int endHour;
  final DateTime? minimumDay;
  final VoidCallback? onHistoryLimitReached;
  final ValueChanged<DateTime> onDayChanged;
  final Future<void> Function(
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
    List<Object> syncToWorkspaceIds,
  })
  onEventTap;
  final Future<void> Function(
    BeanCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventDeleted;
  final Future<BeanEventCategory> Function({
    BeanEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    BeanEventCategory category, {
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
    final pinnedRowsHeight =
        (showMultiDayRow ? _calendarMultiDayRowHeight : 0) +
        _calendarAllDayRowHeight;
    final timelineContentHeight = visibleHours.length * _calendarHourHeight;
    final timelineHeight = 1 + timelineContentHeight;
    final markerOffset =
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
          height: pinnedRowsHeight,
          child: _PinnedTimelineRows(
            pageController: _dayPageController,
            initialDayPage: _initialDayPage,
            pageAnchorDay: _pageAnchorDay,
            calendar: widget.calendar,
            eventCategories: widget.eventCategories,
            googleCalendarStatus: widget.googleCalendarStatus,
            outlookCalendarStatus: widget.outlookCalendarStatus,
            workspaces: widget.workspaces,
            activeWorkspaceId: widget.activeWorkspaceId,
            visibleStartDay: visibleStartDay,
            showMultiDayRow: showMultiDayRow,
            onEventTap: widget.onEventTap,
            onEventDeleted: widget.onEventDeleted,
            onEventCategorySaved: widget.onEventCategorySaved,
            onEventCategoryDeleted: widget.onEventCategoryDeleted,
          ),
        ),
        SizedBox(
          height: timelineViewportHeight,
          child: SingleChildScrollView(
            key: const Key('apple-style-day-timeline-scroll'),
            controller: _timelineScrollController,
            child: Container(
              key: const Key('apple-style-day-timeline'),
              decoration: BoxDecoration(
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
                        _FixedTimelineHoursColumn(visibleHours: visibleHours),
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
                            itemBuilder: (context, page) => _TwoDayTimelinePage(
                              key: ValueKey('two-day-timeline-page-$page'),
                              calendar: widget.calendar,
                              eventCategories: widget.eventCategories,
                              googleCalendarStatus: widget.googleCalendarStatus,
                              outlookCalendarStatus:
                                  widget.outlookCalendarStatus,
                              workspaces: widget.workspaces,
                              activeWorkspaceId: widget.activeWorkspaceId,
                              selectedDay: _dateForPage(page),
                              startHour: visibleHours.first,
                              endHour: visibleHours.last,
                              visibleHours: visibleHours,
                              onEventTap: widget.onEventTap,
                              onEventDeleted: widget.onEventDeleted,
                              onEventCategorySaved: widget.onEventCategorySaved,
                              onEventCategoryDeleted:
                                  widget.onEventCategoryDeleted,
                            ),
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
                              style: TextStyle(
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
    required this.outlookCalendarStatus,
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

  final List<BeanCalendarEvent> calendar;
  final List<BeanEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final List<BeanWorkspace> workspaces;
  final String? activeWorkspaceId;
  final DateTime selectedDay;
  final int startHour;
  final int endHour;
  final List<int> visibleHours;
  final Future<void> Function(
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
    List<Object> syncToWorkspaceIds,
  })
  onEventTap;
  final Future<void> Function(
    BeanCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventDeleted;
  final Future<BeanEventCategory> Function({
    BeanEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    BeanEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) {
    final selectedNextDay = selectedDay.add(const Duration(days: 1));
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

    return LayoutBuilder(
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
              outlookCalendarStatus: outlookCalendarStatus,
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
              outlookCalendarStatus: outlookCalendarStatus,
              workspaces: workspaces,
              activeWorkspaceId: activeWorkspaceId,
              onTap: onEventTap,
              onDelete: onEventDeleted,
              onEventCategorySaved: onEventCategorySaved,
              onEventCategoryDeleted: onEventCategoryDeleted,
            ),
        ],
      ),
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
  Widget build(BuildContext context) {
    final background = HeyBeanTheme.isDark
        ? HeyBeanTheme.surface2.withValues(alpha: .94)
        : HeyBeanTheme.surface;
    final shadowColor = HeyBeanTheme.isDark
        ? Colors.black.withValues(alpha: .20)
        : Colors.black.withValues(alpha: .07);
    return InkWell(
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
            color: background,
            borderRadius: BorderRadius.circular(22),
            border: Border.all(
              color: HeyBeanTheme.isDark
                  ? HeyBeanTheme.borderStrong
                  : HeyBeanTheme.border,
            ),
            boxShadow: [
              BoxShadow(
                color: shadowColor,
                blurRadius: HeyBeanTheme.isDark ? 16 : 14,
                offset: const Offset(0, 6),
              ),
            ],
          ),
          child: IconTheme(
            data: IconThemeData(color: HeyBeanTheme.muted, size: 16),
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
                    style: labelStyle.copyWith(color: HeyBeanTheme.text),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
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
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: HeyBeanTheme.border)),
      ),
      child: Row(
        children: [
          Container(
            width: _calendarTimeColumnWidth,
            height: _calendarDayHeaderHeight,
            decoration: BoxDecoration(
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
    decoration: BoxDecoration(
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

class _PinnedTimelineRows extends StatelessWidget {
  const _PinnedTimelineRows({
    required this.pageController,
    required this.initialDayPage,
    required this.pageAnchorDay,
    required this.calendar,
    required this.eventCategories,
    required this.googleCalendarStatus,
    required this.outlookCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
    required this.visibleStartDay,
    required this.showMultiDayRow,
    required this.onEventTap,
    required this.onEventDeleted,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final PageController pageController;
  final int initialDayPage;
  final DateTime pageAnchorDay;
  final List<BeanCalendarEvent> calendar;
  final List<BeanEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final List<BeanWorkspace> workspaces;
  final String? activeWorkspaceId;
  final DateTime visibleStartDay;
  final bool showMultiDayRow;
  final Future<void> Function(
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
    List<Object> syncToWorkspaceIds,
  })
  onEventTap;
  final Future<void> Function(
    BeanCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventDeleted;
  final Future<BeanEventCategory> Function({
    BeanEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    BeanEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      SizedBox(
        width: _calendarTimeColumnWidth,
        child: Column(
          children: [
            if (showMultiDayRow)
              const _PinnedTimelineLabel(
                key: Key('calendar-multi-day-label'),
                height: _calendarMultiDayRowHeight,
                label: 'Multi-Day',
                accent: true,
              ),
            const _PinnedTimelineLabel(
              key: Key('calendar-all-day-label'),
              height: _calendarAllDayRowHeight,
              label: 'All Day',
            ),
          ],
        ),
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
                  pageController: pageController,
                  initialDayPage: initialDayPage,
                  pageAnchorDay: pageAnchorDay,
                  events: calendar
                      .where((event) => _eventIsTimedMultiDay(event))
                      .toList(),
                  eventCategories: eventCategories,
                  googleCalendarStatus: googleCalendarStatus,
                  outlookCalendarStatus: outlookCalendarStatus,
                  workspaces: workspaces,
                  activeWorkspaceId: activeWorkspaceId,
                  onEventTap: onEventTap,
                  onEventDeleted: onEventDeleted,
                  onEventCategorySaved: onEventCategorySaved,
                  onEventCategoryDeleted: onEventCategoryDeleted,
                ),
              ),
            SizedBox(
              height: _calendarAllDayRowHeight,
              child: _ScrollableAllDayEventRows(
                pageController: pageController,
                initialDayPage: initialDayPage,
                pageAnchorDay: pageAnchorDay,
                calendar: calendar,
                eventCategories: eventCategories,
                googleCalendarStatus: googleCalendarStatus,
                outlookCalendarStatus: outlookCalendarStatus,
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
    ],
  );
}

class _PinnedTimelineLabel extends StatelessWidget {
  const _PinnedTimelineLabel({
    super.key,
    required this.height,
    required this.label,
    this.accent = false,
  });

  final double height;
  final String label;
  final bool accent;

  @override
  Widget build(BuildContext context) => Container(
    height: height,
    decoration: BoxDecoration(
      color: accent
          ? HeyBeanTheme.accent.withValues(alpha: .06)
          : Colors.transparent,
      border: Border(bottom: BorderSide(color: HeyBeanTheme.border)),
    ),
    child: Padding(
      padding: const EdgeInsets.only(top: 10, right: 6),
      child: Align(
        alignment: Alignment.topRight,
        child: Text(
          label,
          textAlign: TextAlign.right,
          style: TextStyle(
            color: HeyBeanTheme.muted,
            fontSize: 11,
            fontWeight: FontWeight.w800,
          ),
        ),
      ),
    ),
  );
}

class _ScrollableAllDayEventRows extends StatelessWidget {
  const _ScrollableAllDayEventRows({
    required this.pageController,
    required this.initialDayPage,
    required this.pageAnchorDay,
    required this.calendar,
    required this.eventCategories,
    required this.googleCalendarStatus,
    required this.outlookCalendarStatus,
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
  final List<BeanCalendarEvent> calendar;
  final List<BeanEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final List<BeanWorkspace> workspaces;
  final String? activeWorkspaceId;
  final Future<void> Function(
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
    List<Object> syncToWorkspaceIds,
  })
  onEventTap;
  final Future<void> Function(
    BeanCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventDeleted;
  final Future<BeanEventCategory> Function({
    BeanEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    BeanEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) => Container(
    decoration: BoxDecoration(
      border: Border(bottom: BorderSide(color: HeyBeanTheme.border)),
    ),
    child: ClipRect(
      child: LayoutBuilder(
        builder: (context, constraints) => AnimatedBuilder(
          animation: pageController,
          builder: (context, _) {
            final dayOffset = _timelineDayOffset(
              pageController,
              initialDayPage,
            );
            final columnWidth = constraints.maxWidth / 2;
            final firstRenderedDayOffset = dayOffset.floor() - 1;
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
                    child: Builder(
                      builder: (context) {
                        final day = pageAnchorDay.add(
                          Duration(days: dayOffsetIndex),
                        );
                        final events = calendar
                            .where(
                              (event) =>
                                  _eventIsAllDay(event) &&
                                  _eventFallsOnDay(event, day),
                            )
                            .toList();
                        return _AllDayEventRow(
                          key: Key(
                            'calendar-all-day-row-${day.toIso8601String()}',
                          ),
                          day: day,
                          events: events,
                          eventCategories: eventCategories,
                          googleCalendarStatus: googleCalendarStatus,
                          outlookCalendarStatus: outlookCalendarStatus,
                          workspaces: workspaces,
                          activeWorkspaceId: activeWorkspaceId,
                          onEventTap: onEventTap,
                          onEventDeleted: onEventDeleted,
                          onEventCategorySaved: onEventCategorySaved,
                          onEventCategoryDeleted: onEventCategoryDeleted,
                        );
                      },
                    ),
                  ),
              ],
            );
          },
        ),
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
  const _FixedTimelineHoursColumn({required this.visibleHours});

  final List<int> visibleHours;

  @override
  Widget build(BuildContext context) => SizedBox(
    key: const Key('calendar-fixed-hours-column'),
    width: _calendarTimeColumnWidth,
    child: Column(
      children: [
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
                  style: TextStyle(
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
              decoration: BoxDecoration(
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
    required this.outlookCalendarStatus,
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
  final List<BeanCalendarEvent> events;
  final List<BeanEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final List<BeanWorkspace> workspaces;
  final String? activeWorkspaceId;
  final Future<void> Function(
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
    List<Object> syncToWorkspaceIds,
  })
  onEventTap;
  final Future<void> Function(
    BeanCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventDeleted;
  final Future<BeanEventCategory> Function({
    BeanEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    BeanEventCategory category, {
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
                      child: VerticalDivider(
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
                            outlookCalendarStatus: outlookCalendarStatus,
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
    required this.outlookCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
    required this.onEventTap,
    required this.onEventDeleted,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final BeanCalendarEvent event;
  final DateTime startDay;
  final int daySpan;
  final double columnWidth;
  final List<BeanEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final List<BeanWorkspace> workspaces;
  final String? activeWorkspaceId;
  final Future<void> Function(
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
    List<Object> syncToWorkspaceIds,
  })
  onEventTap;
  final Future<void> Function(
    BeanCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventDeleted;
  final Future<BeanEventCategory> Function({
    BeanEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    BeanEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) {
    final color = _calendarEventColor(event);
    final hasNotes = _eventHasNotes(event);
    final foregroundColor = HeyBeanTheme.text;
    return InkWell(
      key: Key('calendar-multi-day-event-${event.id}'),
      borderRadius: BorderRadius.circular(12),
      onTap: () => _showCalendarEventDetails(
        context,
        event,
        eventCategories: eventCategories,
        googleCalendarStatus: googleCalendarStatus,
        outlookCalendarStatus: outlookCalendarStatus,
        workspaces: workspaces,
        activeWorkspaceId: activeWorkspaceId,
        onSave:
            (
              savedEvent, {
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
            }) => onEventTap(
              savedEvent,
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
              syncToWorkspaceIds: syncToWorkspaceIds,
            ),
        onCriticalChanged: (savedEvent, isCritical) => onEventTap(
          savedEvent,
          title: savedEvent.title,
          startsAt:
              savedEvent.startsAt ?? DateTime.now().toUtc().toIso8601String(),
          allDay: _eventIsAllDay(savedEvent),
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
          color: color.withValues(alpha: _calendarEventBlockFillAlpha),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: color.withValues(alpha: _calendarEventBlockBorderAlpha),
          ),
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
            if (hasNotes)
              Positioned(
                left: event.isCritical ? 26 : 8,
                top: 8,
                child: Icon(
                  Icons.notes_rounded,
                  key: Key('event-notes-icon-${event.id}'),
                  color: foregroundColor.withValues(alpha: .82),
                  size: 13,
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
                    left: dayIndex == 0
                        ? 10 + (event.isCritical ? 18 : 0) + (hasNotes ? 18 : 0)
                        : 10,
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
                      style: TextStyle(
                        color: foregroundColor,
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
    required this.outlookCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
    required this.onEventTap,
    required this.onEventDeleted,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final DateTime day;
  final List<BeanCalendarEvent> events;
  final List<BeanEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final List<BeanWorkspace> workspaces;
  final String? activeWorkspaceId;
  final Future<void> Function(
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
    List<Object> syncToWorkspaceIds,
  })
  onEventTap;
  final Future<void> Function(
    BeanCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventDeleted;
  final Future<BeanEventCategory> Function({
    BeanEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    BeanEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) => Container(
    decoration: BoxDecoration(
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
        final foregroundColor = HeyBeanTheme.text;
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
            outlookCalendarStatus: outlookCalendarStatus,
            workspaces: workspaces,
            activeWorkspaceId: activeWorkspaceId,
            onSave:
                (
                  savedEvent, {
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
                }) => onEventTap(
                  savedEvent,
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
                  syncToWorkspaceIds: syncToWorkspaceIds,
                ),
            onCriticalChanged: (savedEvent, isCritical) => onEventTap(
              savedEvent,
              title: savedEvent.title,
              startsAt:
                  savedEvent.startsAt ??
                  DateTime.now().toUtc().toIso8601String(),
              allDay: _eventIsAllDay(savedEvent),
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
              color: color.withValues(alpha: _calendarEventBlockFillAlpha),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(
                color: color.withValues(alpha: _calendarEventBlockBorderAlpha),
              ),
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
                if (_eventHasNotes(event)) ...[
                  Icon(
                    Icons.notes_rounded,
                    key: Key('event-notes-icon-${event.id}'),
                    color: foregroundColor.withValues(alpha: .82),
                    size: 13,
                  ),
                  const SizedBox(width: 4),
                ],
                Flexible(
                  child: Text(
                    event.title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: foregroundColor,
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
    required this.outlookCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
    required this.onTap,
    required this.onDelete,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final BeanCalendarEvent event;
  final DateTime day;
  final int startHour;
  final int endHour;
  final int columnIndex;
  final int laneIndex;
  final int laneCount;
  final double timelineWidth;
  final List<BeanEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final List<BeanWorkspace> workspaces;
  final String? activeWorkspaceId;
  final Future<void> Function(
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
    List<Object> syncToWorkspaceIds,
  })
  onTap;
  final Future<void> Function(
    BeanCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onDelete;
  final Future<BeanEventCategory> Function({
    BeanEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    BeanEventCategory category, {
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
    final eventColor = _calendarEventColor(event);
    final foregroundColor = HeyBeanTheme.text;
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
          outlookCalendarStatus: outlookCalendarStatus,
          workspaces: workspaces,
          activeWorkspaceId: activeWorkspaceId,
          onSave:
              (
                savedEvent, {
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
              }) => onTap(
                savedEvent,
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
                syncToWorkspaceIds: syncToWorkspaceIds,
              ),
          onCriticalChanged: (savedEvent, isCritical) => onTap(
            savedEvent,
            title: savedEvent.title,
            startsAt:
                savedEvent.startsAt ?? DateTime.now().toUtc().toIso8601String(),
            allDay: _eventIsAllDay(savedEvent),
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
            color: eventColor.withValues(alpha: _calendarEventBlockFillAlpha),
            borderRadius: BorderRadius.circular(6),
            border: Border.all(
              color: eventColor.withValues(
                alpha: _calendarEventBlockBorderAlpha,
              ),
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
              if (_eventHasNotes(event)) ...[
                Icon(
                  Icons.notes_rounded,
                  key: Key('event-notes-icon-${event.id}'),
                  color: foregroundColor.withValues(alpha: .82),
                  size: 13,
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
                        color: foregroundColor,
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
                          color: foregroundColor.withValues(alpha: .82),
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
