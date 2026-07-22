part of '../../main.dart';

class _CommandCenterHome extends StatefulWidget {
  const _CommandCenterHome({
    required this.tasks,
    required this.reminders,
    required this.calendar,
    required this.loading,
    this.agendaPanelKey,
    required this.eventCategories,
    required this.googleCalendarStatus,
    required this.outlookCalendarStatus,
    required this.workspaces,
    required this.activeWorkspaceId,
    required this.onTaskSaved,
    required this.onTaskDeleted,
    required this.onReminderSaved,
    required this.onReminderDeleted,
    required this.onCalendarEventEdited,
    required this.onCalendarEventDeleted,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
  });

  final List<BeanTask> tasks;
  final List<BeanReminder> reminders;
  final List<BeanCalendarEvent> calendar;
  final bool loading;
  final Key? agendaPanelKey;
  final List<BeanEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final List<BeanWorkspace> workspaces;
  final String? activeWorkspaceId;
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
  final Future<void> Function(
    BeanReminder? reminder, {
    required String title,
    required String remindAt,
    String status,
    String? category,
    String? color,
    int? workspaceId,
    Map<String, Object?>? recurrenceMetadata,
    List<Object> syncToWorkspaceIds,
  })
  onReminderSaved;
  final Future<void> Function(
    BeanReminder reminder, {
    List<Object> deleteFromWorkspaceIds,
  })
  onReminderDeleted;
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
  State<_CommandCenterHome> createState() => _CommandCenterHomeState();
}

class _CommandCenterHomeState extends State<_CommandCenterHome> {
  @override
  Widget build(BuildContext context) {
    final items = _commandCenterAgendaItems(
      tasks: widget.tasks,
      reminders: widget.reminders,
      calendar: widget.calendar,
    );
    final glanceDays = _commandCenterGlanceDays(widget.calendar);

    return KeyedSubtree(
      key: const Key('command-center-home'),
      child: KeyedSubtree(
        key: widget.agendaPanelKey,
        child: _CommandCenterAgendaList(
          items: items,
          glanceDays: glanceDays,
          loading: widget.loading,
          onItemTap: _openAgendaItem,
          onEventTap: _openCalendarEvent,
        ),
      ),
    );
  }

  Future<void> _openAgendaItem(_CommandCenterAgendaItem item) async {
    switch (item.kind) {
      case _CommandCenterAgendaKind.event:
        final event = item.event;
        if (event == null) return;
        await _openCalendarEvent(event);
      case _CommandCenterAgendaKind.task:
        final task = item.task;
        if (task == null) return;
        await _showTaskEditor(task);
      case _CommandCenterAgendaKind.reminder:
        final reminder = item.reminder;
        if (reminder == null) return;
        await _showReminderEditor(reminder);
    }
  }

  Future<void> _openCalendarEvent(BeanCalendarEvent event) async {
    await _showCalendarEventDetails(
      context,
      event,
      eventCategories: widget.eventCategories,
      googleCalendarStatus: widget.googleCalendarStatus,
      outlookCalendarStatus: widget.outlookCalendarStatus,
      workspaces: widget.workspaces,
      activeWorkspaceId: widget.activeWorkspaceId,
      onSave: widget.onCalendarEventEdited,
      onDelete: widget.onCalendarEventDeleted,
      onEventCategorySaved: widget.onEventCategorySaved,
      onEventCategoryDeleted: widget.onEventCategoryDeleted,
    );
  }

  Future<void> _showTaskEditor(BeanTask task) async {
    var savedInsideEditor = false;
    final result = await _showTitleTimeEditor(
      context,
      title: 'Edit task',
      titleLabel: 'Task title',
      timeLabel: 'Due date',
      initialTitle: task.title,
      initialTime: _formatCalendarDateTimeInput(task.dueAt),
      initialNotes: task.notes ?? '',
      allowEmptyTime: true,
      showNotes: true,
      categories: widget.eventCategories,
      initialCategory: task.category,
      initialColor: task.color,
      initialCritical: task.isCritical,
      deleteLabel: 'Delete task',
      showRecurrence: true,
      recurrenceTitle: 'Task recurrence',
      recurrenceSubtitle: 'Repeat this task when needed.',
      recurrenceInfoTitle: 'Task recurrence',
      initialMetadata: task.metadata,
      onEventCategorySaved: widget.onEventCategorySaved,
      workspaces: widget.workspaces,
      activeWorkspaceId: widget.activeWorkspaceId,
      initialSyncWorkspaceIds: _initialSyncWorkspaceIds(
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
    if (result == null || !mounted) return;
    if (result['delete'] == true) {
      final deleteFromWorkspaceIds = await _confirmWorkspaceDeleteSelection(
        context,
        itemTitle: task.title,
        itemType: 'task',
        workspaces: widget.workspaces,
        activeWorkspaceId: widget.activeWorkspaceId,
        workspaceId: task.workspaceId,
        linkedWorkspaceIds: task.linkedWorkspaceIds,
      );
      if (!mounted || deleteFromWorkspaceIds == null) return;
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
      workspaceId: result['workspaceId'] as int?,
      recurrenceMetadata: result['recurrenceMetadata'] as Map<String, Object?>?,
      syncToWorkspaceIds:
          (result['syncToWorkspaceIds'] as List?)
              ?.whereType<Object>()
              .toList() ??
          const [],
    );
  }

  Future<void> _showReminderEditor(BeanReminder reminder) async {
    var savedInsideEditor = false;
    final result = await _showTitleTimeEditor(
      context,
      title: 'Edit reminder',
      titleLabel: 'Reminder title',
      timeLabel: 'Remind me at',
      initialTitle: reminder.title,
      initialTime: _formatCalendarDateTimeInput(reminder.dueAt),
      editorIcon: Icons.notifications_active_outlined,
      editorSubtitle: 'Time-sensitive nudge with optional repeat',
      primarySectionTitle: 'Reminder basics',
      primarySectionSubtitle: 'Title and required reminder time',
      allowEmptyTime: false,
      categories: widget.eventCategories,
      initialCategory: reminder.category,
      initialColor: reminder.color,
      showCritical: false,
      showTimeTextField: false,
      showRecurrence: true,
      recurrenceTitle: 'Reminder repeats',
      recurrenceSubtitle: 'Repeat this reminder when needed.',
      recurrenceInfoTitle: 'Reminder recurrence',
      initialMetadata: reminder.metadata,
      onEventCategorySaved: widget.onEventCategorySaved,
      deleteLabel: 'Delete reminder',
      completeLabel: _reminderIsCompleted(reminder)
          ? 'Mark scheduled'
          : 'Mark complete',
      workspaces: widget.workspaces,
      activeWorkspaceId: widget.activeWorkspaceId,
      initialSyncWorkspaceIds: _initialSyncWorkspaceIds(
        linkedWorkspaceIds: reminder.linkedWorkspaceIds,
        workspaceId: reminder.workspaceId,
        activeWorkspaceId: widget.activeWorkspaceId,
      ),
      onSave: (result) async {
        final title = (result['title'] as String).trim();
        final time = (result['time'] as String?)?.trim() ?? '';
        if (title.isEmpty || time.isEmpty) return;
        final status = result['complete'] == true
            ? (_reminderIsCompleted(reminder) ? 'scheduled' : 'completed')
            : reminder.status;
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
        );
        savedInsideEditor = true;
      },
    );
    if (result == null || !mounted) return;
    if (result['delete'] == true) {
      final deleteFromWorkspaceIds = await _confirmWorkspaceDeleteSelection(
        context,
        itemTitle: reminder.title,
        itemType: 'reminder',
        workspaces: widget.workspaces,
        activeWorkspaceId: widget.activeWorkspaceId,
        workspaceId: reminder.workspaceId,
        linkedWorkspaceIds: reminder.linkedWorkspaceIds,
      );
      if (!mounted || deleteFromWorkspaceIds == null) return;
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
        ? (_reminderIsCompleted(reminder) ? 'scheduled' : 'completed')
        : reminder.status;
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
    );
  }
}

class _CommandCenterGlanceSection extends StatelessWidget {
  const _CommandCenterGlanceSection({
    required this.days,
    required this.onEventTap,
  });

  final List<_CommandCenterGlanceDay> days;
  final ValueChanged<BeanCalendarEvent> onEventTap;

  @override
  Widget build(BuildContext context) => Column(
    key: const Key('command-center-glance-list'),
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      for (var index = 0; index < days.length; index++)
        _CommandCenterGlanceDayTile(
          day: days[index],
          showBottomDivider: index < days.length - 1,
          onEventTap: onEventTap,
        ),
    ],
  );
}

class _CommandCenterAgendaEmptyInline extends StatelessWidget {
  const _CommandCenterAgendaEmptyInline();

  @override
  Widget build(BuildContext context) => Container(
    key: const Key('command-center-agenda-empty'),
    constraints: const BoxConstraints(minHeight: 42),
    alignment: Alignment.centerLeft,
    padding: const EdgeInsets.symmetric(horizontal: 7),
    child: Text(
      'Nothing else scheduled for today.',
      style: TextStyle(
        color: HeyBeanTheme.muted,
        fontSize: 12,
        fontWeight: FontWeight.w600,
      ),
    ),
  );
}

class _CommandCenterDayHeader extends StatelessWidget {
  const _CommandCenterDayHeader({super.key, required this.label});

  final String label;

  @override
  Widget build(BuildContext context) => Text(
    label,
    maxLines: 1,
    overflow: TextOverflow.ellipsis,
    style: TextStyle(
      color: HeyBeanTheme.text,
      fontSize: 12,
      fontWeight: FontWeight.w700,
    ),
  );
}

class _CommandCenterRowTitle extends StatelessWidget {
  const _CommandCenterRowTitle({
    required this.title,
    required this.isCritical,
    required this.criticalStarKey,
  });

  final String title;
  final bool isCritical;
  final Key criticalStarKey;

  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      if (isCritical) ...[
        Icon(
          Icons.star_rounded,
          key: criticalStarKey,
          semanticLabel: 'Critical',
          size: 14,
          color: HeyBeanTheme.warning.withValues(alpha: .90),
        ),
        const SizedBox(width: 3),
      ],
      Flexible(
        child: Text(
          title,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            color: HeyBeanTheme.text,
            fontSize: 14,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
    ],
  );
}

class _CommandCenterGlanceDayTile extends StatelessWidget {
  const _CommandCenterGlanceDayTile({
    required this.day,
    required this.showBottomDivider,
    required this.onEventTap,
  });

  final _CommandCenterGlanceDay day;
  final bool showBottomDivider;
  final ValueChanged<BeanCalendarEvent> onEventTap;

  @override
  Widget build(BuildContext context) => Container(
    key: Key('command-center-glance-day-${_calendarDateKey(day.date)}'),
    padding: const EdgeInsets.only(top: 7, bottom: 6),
    decoration: BoxDecoration(
      border: showBottomDivider
          ? Border(bottom: BorderSide(color: _quietBorderColor(alpha: .46)))
          : null,
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 7),
          child: _CommandCenterDayHeader(
            label: _commandCenterGlanceDayLabel(day.date),
          ),
        ),
        const SizedBox(height: 5),
        if (day.events.isEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 2),
            child: Text(
              'No events',
              style: TextStyle(
                color: HeyBeanTheme.muted,
                fontSize: 11,
                fontWeight: FontWeight.w500,
              ),
            ),
          )
        else
          Column(
            children: [
              for (var index = 0; index < day.events.length; index++)
                _CommandCenterGlanceEventRow(
                  event: day.events[index],
                  showTopDivider: index > 0,
                  onTap: () => onEventTap(day.events[index]),
                ),
            ],
          ),
      ],
    ),
  );
}

class _CommandCenterGlanceEventRow extends StatelessWidget {
  const _CommandCenterGlanceEventRow({
    required this.event,
    required this.showTopDivider,
    required this.onTap,
  });

  final BeanCalendarEvent event;
  final bool showTopDivider;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final color = _calendarEventColor(event);
    final hasNotes = _eventHasNotes(event);
    final subtitle = (event.location ?? '').trim();
    const timeWidth = 50.0;

    return Material(
      key: Key('command-center-glance-event-${event.id}'),
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        child: Container(
          key: Key('command-center-glance-category-rail-${event.id}'),
          height: 42,
          padding: const EdgeInsets.only(left: 8, right: 6),
          decoration: BoxDecoration(
            border: Border(
              left: BorderSide(color: color.withValues(alpha: .72), width: 3),
              top: showTopDivider
                  ? BorderSide(color: _quietBorderColor(alpha: .30))
                  : BorderSide.none,
            ),
          ),
          child: Row(
            children: [
              const SizedBox(width: 8),
              SizedBox(
                width: timeWidth,
                child: _CommandCenterAgendaTimeLabel(
                  label: _commandCenterGlanceEventTime(event),
                  allowStackedRange: true,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _CommandCenterRowTitle(
                      title: event.title,
                      isCritical: event.isCritical,
                      criticalStarKey: Key(
                        'command-center-glance-critical-star-${event.id}',
                      ),
                    ),
                    const SizedBox(height: 1),
                    Text(
                      subtitle.isNotEmpty ? subtitle : 'Event',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: HeyBeanTheme.muted,
                        fontSize: 11,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ],
                ),
              ),
              if (hasNotes)
                Icon(
                  Icons.notes_rounded,
                  key: Key('command-center-glance-notes-${event.id}'),
                  size: 14,
                  color: HeyBeanTheme.muted,
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CommandCenterAgendaList extends StatelessWidget {
  const _CommandCenterAgendaList({
    required this.items,
    required this.glanceDays,
    required this.loading,
    required this.onItemTap,
    required this.onEventTap,
  });

  final List<_CommandCenterAgendaItem> items;
  final List<_CommandCenterGlanceDay> glanceDays;
  final bool loading;
  final ValueChanged<_CommandCenterAgendaItem> onItemTap;
  final ValueChanged<BeanCalendarEvent> onEventTap;

  @override
  Widget build(BuildContext context) {
    final overdueItems = items.where((item) => item.isOverdue).toList();
    final todayItems = items.where((item) => !item.isOverdue).toList();

    if (loading && items.isEmpty) {
      return const _InlineLoadingSurface(
        key: Key('command-center-agenda-loading'),
        label: 'Loading today',
        fillHeight: true,
      );
    }

    return Stack(
      key: const Key('command-center-agenda-stack'),
      children: [
        ListView(
          key: const Key('command-center-agenda-list'),
          padding: EdgeInsets.zero,
          children: [
            if (overdueItems.isNotEmpty) ...[
              Padding(
                padding: const EdgeInsets.fromLTRB(7, 7, 7, 5),
                child: _CommandCenterDayHeader(
                  key: const Key('command-center-overdue-header'),
                  label: 'Overdue',
                ),
              ),
              for (var index = 0; index < overdueItems.length; index++)
                _CommandCenterAgendaRow(
                  item: overdueItems[index],
                  showTopDivider: index > 0,
                  onTap: onItemTap,
                ),
            ],
            Padding(
              padding: const EdgeInsets.fromLTRB(7, 7, 7, 5),
              child: _CommandCenterDayHeader(
                key: const Key('command-center-today-header'),
                label:
                    'Today - ${_commandCenterGlanceDayLabel(DateTime.now())}',
              ),
            ),
            if (todayItems.isEmpty) const _CommandCenterAgendaEmptyInline(),
            for (var index = 0; index < todayItems.length; index++)
              _CommandCenterAgendaRow(
                item: todayItems[index],
                showTopDivider: index > 0,
                onTap: onItemTap,
              ),
            _CommandCenterGlanceSection(
              days: glanceDays,
              onEventTap: onEventTap,
            ),
          ],
        ),
      ],
    );
  }
}

class _CommandCenterAgendaRow extends StatelessWidget {
  const _CommandCenterAgendaRow({
    required this.item,
    required this.showTopDivider,
    required this.onTap,
  });

  final _CommandCenterAgendaItem item;
  final bool showTopDivider;
  final ValueChanged<_CommandCenterAgendaItem> onTap;

  @override
  Widget build(BuildContext context) {
    final categoryColor = _safeCategoryColor(switch (item.kind) {
      _CommandCenterAgendaKind.event => item.event?.color,
      _CommandCenterAgendaKind.task => item.task?.color,
      _CommandCenterAgendaKind.reminder => item.reminder?.color,
    });
    final kindLabel = switch (item.kind) {
      _CommandCenterAgendaKind.event => 'Event',
      _CommandCenterAgendaKind.task => 'Task',
      _CommandCenterAgendaKind.reminder => 'Reminder',
    };
    final hasEventNotes = item.event != null && _eventHasNotes(item.event!);
    final isCritical = switch (item.kind) {
      _CommandCenterAgendaKind.event => item.event?.isCritical ?? false,
      _CommandCenterAgendaKind.task => item.task?.isCritical ?? false,
      _CommandCenterAgendaKind.reminder => item.reminder?.isCritical ?? false,
    };
    const timeWidth = 50.0;

    return Material(
      color: Colors.transparent,
      key: Key('command-center-agenda-${item.key}'),
      child: InkWell(
        onTap: () => onTap(item),
        child: Container(
          height: 42,
          padding: const EdgeInsets.only(left: 8, right: 6),
          decoration: BoxDecoration(
            border: Border(
              left: BorderSide(
                color: categoryColor.withValues(alpha: .72),
                width: 3,
              ),
            ),
          ),
          child: Stack(
            children: [
              Positioned(
                key: Key('command-center-agenda-category-rail-${item.key}'),
                left: 0,
                top: 0,
                child: const SizedBox.shrink(),
              ),
              Row(
                children: [
                  const SizedBox(width: 8),
                  SizedBox(
                    key: Key('command-center-agenda-time-${item.key}'),
                    width: timeWidth,
                    child: _CommandCenterAgendaTimeLabel(
                      label: item.timeLabel,
                      allowStackedRange:
                          item.kind == _CommandCenterAgendaKind.event,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _CommandCenterRowTitle(
                          title: item.title,
                          isCritical: isCritical,
                          criticalStarKey: Key(
                            'command-center-agenda-critical-star-${item.key}',
                          ),
                        ),
                        const SizedBox(height: 1),
                        Text(
                          item.subtitle.isNotEmpty ? item.subtitle : kindLabel,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: HeyBeanTheme.muted,
                            fontSize: 11,
                            fontWeight: FontWeight.w500,
                          ),
                        ),
                      ],
                    ),
                  ),
                  if (hasEventNotes)
                    Icon(
                      Icons.notes_rounded,
                      key: Key('command-center-event-notes-${item.key}'),
                      size: 14,
                      color: HeyBeanTheme.muted,
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

class _CommandCenterAgendaTimeLabel extends StatelessWidget {
  const _CommandCenterAgendaTimeLabel({
    required this.label,
    required this.allowStackedRange,
  });

  final String label;
  final bool allowStackedRange;

  @override
  Widget build(BuildContext context) {
    final rangeParts = allowStackedRange
        ? label.split(RegExp(r'\s*[–-]\s*'))
        : const <String>[];
    final canStack =
        rangeParts.length == 2 &&
        rangeParts.every((part) => part.trim().isNotEmpty);
    final style = TextStyle(
      color: HeyBeanTheme.muted,
      fontSize: 12,
      fontWeight: FontWeight.w700,
      height: 1.05,
    );
    if (canStack) {
      return Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            rangeParts[0].trim(),
            maxLines: 1,
            overflow: TextOverflow.visible,
            softWrap: false,
            style: style,
          ),
          Text(
            rangeParts[1].trim(),
            maxLines: 1,
            overflow: TextOverflow.visible,
            softWrap: false,
            style: style.copyWith(
              color: HeyBeanTheme.muted.withValues(alpha: .82),
            ),
          ),
        ],
      );
    }

    return Text(
      label,
      maxLines: 1,
      overflow: TextOverflow.visible,
      softWrap: false,
      style: style,
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
    this.isOverdue = false,
    this.event,
    this.task,
    this.reminder,
  });

  final String key;
  final _CommandCenterAgendaKind kind;
  final String title;
  final DateTime time;
  final String timeLabel;
  final String subtitle;
  final bool isOverdue;
  final BeanCalendarEvent? event;
  final BeanTask? task;
  final BeanReminder? reminder;
}

class _CommandCenterGlanceDay {
  const _CommandCenterGlanceDay({required this.date, required this.events});

  final DateTime date;
  final List<BeanCalendarEvent> events;
}
