part of '../../main.dart';

class _CommandCenterHome extends StatefulWidget {
  const _CommandCenterHome({
    required this.tasks,
    required this.reminders,
    required this.calendar,
    required this.loading,
    required this.chat,
    this.agendaPanelKey,
    this.chatPanelKey,
    required this.chatCollapsed,
    required this.onChatCollapsedChanged,
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

  final List<HermesTask> tasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendar;
  final bool loading;
  final Widget chat;
  final Key? agendaPanelKey;
  final Key? chatPanelKey;
  final bool chatCollapsed;
  final ValueChanged<bool> onChatCollapsedChanged;
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
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
  })
  onReminderSaved;
  final Future<void> Function(
    HermesReminder reminder, {
    List<Object> deleteFromWorkspaceIds,
  })
  onReminderDeleted;
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
    final glanceDays = _commandCenterGlanceDays(widget.calendar);

    return LayoutBuilder(
      key: const Key('command-center-home'),
      builder: (context, constraints) {
        final glanceHeight = constraints.maxHeight < 520 ? 116.0 : 138.0;
        final maxChatHeight = math.max(
          0.0,
          constraints.maxHeight - 150.0 - glanceHeight,
        );
        final minChatHeight = math.min(72.0, maxChatHeight);
        final fallbackChatHeight = math.min(
          math.max(128.0, constraints.maxHeight * .30),
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
              child: KeyedSubtree(
                key: widget.agendaPanelKey,
                child: _CommandCenterAgendaList(
                  items: items,
                  loading: widget.loading,
                  onItemTap: _openAgendaItem,
                ),
              ),
            ),
            SizedBox(
              height: glanceHeight,
              child: _CommandCenterGlanceList(
                days: glanceDays,
                loading: widget.loading,
                onEventTap: _openCalendarEvent,
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
                child: widget.chatPanelKey == null
                    ? widget.chat
                    : KeyedSubtree(
                        key: widget.chatPanelKey,
                        child: widget.chat,
                      ),
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

  Future<void> _openCalendarEvent(HermesCalendarEvent event) async {
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

  Future<void> _showTaskEditor(HermesTask task) async {
    var savedInsideEditor = false;
    final result = await _showTitleTimeEditor(
      context,
      title: 'Edit task',
      titleLabel: 'Task title',
      timeLabel: 'Due date',
      initialTitle: task.title,
      initialTime: _formatCalendarEventDateTime(task.dueAt),
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

  Future<void> _showReminderEditor(HermesReminder reminder) async {
    var savedInsideEditor = false;
    final result = await _showTitleTimeEditor(
      context,
      title: 'Edit reminder',
      titleLabel: 'Reminder title',
      timeLabel: 'Remind me at',
      initialTitle: reminder.title,
      initialTime: _formatCalendarEventDateTime(reminder.dueAt),
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
          ? 'Mark pending'
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
            ? (_reminderIsCompleted(reminder) ? 'pending' : 'completed')
            : (reminder.status ?? 'pending');
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
        ? (_reminderIsCompleted(reminder) ? 'pending' : 'completed')
        : (reminder.status ?? 'pending');
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

class _CommandCenterGlanceList extends StatelessWidget {
  const _CommandCenterGlanceList({
    required this.days,
    required this.loading,
    required this.onEventTap,
  });

  final List<_CommandCenterGlanceDay> days;
  final bool loading;
  final ValueChanged<HermesCalendarEvent> onEventTap;

  @override
  Widget build(BuildContext context) => Stack(
    key: const Key('command-center-glance-list'),
    children: [
      ListView.separated(
        padding: const EdgeInsets.fromLTRB(0, 4, 0, 6),
        itemCount: days.length,
        separatorBuilder: (context, index) => const SizedBox(height: 4),
        itemBuilder: (context, index) => _CommandCenterGlanceDayTile(
          day: days[index],
          onEventTap: onEventTap,
        ),
      ),
      if (loading)
        Positioned(
          key: const Key('command-center-glance-refreshing'),
          top: 6,
          right: 4,
          child: _InlineLoadingBadge(label: 'Updating'),
        ),
    ],
  );
}

class _CommandCenterGlanceDayTile extends StatelessWidget {
  const _CommandCenterGlanceDayTile({
    required this.day,
    required this.onEventTap,
  });

  final _CommandCenterGlanceDay day;
  final ValueChanged<HermesCalendarEvent> onEventTap;

  @override
  Widget build(BuildContext context) => Container(
    key: Key('command-center-glance-day-${_calendarDateKey(day.date)}'),
    padding: const EdgeInsets.fromLTRB(7, 7, 7, 6),
    decoration: BoxDecoration(
      border: Border(bottom: BorderSide(color: _quietBorderColor(alpha: .46))),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          _commandCenterGlanceDayLabel(day.date),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            color: HeyBeanTheme.text,
            fontSize: 12,
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 5),
        if (day.events.isEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 2),
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
              for (final event in day.events)
                Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: _CommandCenterGlanceEventPill(
                    event: event,
                    onTap: () => onEventTap(event),
                  ),
                ),
            ],
          ),
      ],
    ),
  );
}

class _CommandCenterGlanceEventPill extends StatelessWidget {
  const _CommandCenterGlanceEventPill({
    required this.event,
    required this.onTap,
  });

  final HermesCalendarEvent event;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final color = _calendarEventColor(event);
    final hasLocation = (event.location ?? '').trim().isNotEmpty;
    final hasNotes = _eventHasNotes(event);
    return Material(
      key: Key('command-center-glance-event-${event.id}'),
      color: color.withValues(alpha: HeyBeanTheme.isDark ? .18 : .11),
      borderRadius: BorderRadius.circular(8),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(8),
        child: Container(
          constraints: const BoxConstraints(minHeight: 32),
          padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 5),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(8),
            border: Border.all(color: color.withValues(alpha: .30)),
          ),
          child: Row(
            children: [
              SizedBox(
                width: 60,
                child: Text(
                  _commandCenterGlanceEventTime(event),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: HeyBeanTheme.muted,
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              const SizedBox(width: 5),
              Expanded(
                child: Text(
                  event.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: HeyBeanTheme.text,
                    fontSize: 12,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ),
              if (hasLocation) ...[
                const SizedBox(width: 5),
                Icon(
                  Icons.location_on_outlined,
                  size: 13,
                  color: HeyBeanTheme.muted,
                ),
              ],
              if (hasNotes) ...[
                const SizedBox(width: 5),
                Icon(Icons.notes_rounded, size: 13, color: HeyBeanTheme.muted),
              ],
            ],
          ),
        ),
      ),
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
              color: _quietBorderColor(alpha: .52),
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
                color: HeyBeanTheme.muted,
              ),
            ),
          ),
        ],
      ),
    ),
  );
}

class _CommandCenterAgendaList extends StatelessWidget {
  const _CommandCenterAgendaList({
    required this.items,
    required this.loading,
    required this.onItemTap,
  });

  final List<_CommandCenterAgendaItem> items;
  final bool loading;
  final ValueChanged<_CommandCenterAgendaItem> onItemTap;

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
        decoration: _quietSurfaceDecoration(
          radius: 18,
          color: _quietSurfaceColor(alpha: .62),
          borderAlpha: .32,
        ),
        child: Text(
          'Nothing else scheduled for today.',
          style: TextStyle(
            color: HeyBeanTheme.muted,
            fontWeight: FontWeight.w600,
          ),
        ),
      );
    }

    return Stack(
      key: const Key('command-center-agenda-stack'),
      children: [
        ListView.builder(
          key: const Key('command-center-agenda-list'),
          padding: EdgeInsets.zero,
          itemCount: items.length,
          itemBuilder: (context, index) =>
              _CommandCenterAgendaRow(item: items[index], onTap: onItemTap),
        ),
        if (loading)
          Positioned(
            key: const Key('command-center-agenda-refreshing'),
            top: 4,
            right: 4,
            child: _InlineLoadingBadge(label: 'Updating'),
          ),
      ],
    );
  }
}

class _CommandCenterAgendaRow extends StatelessWidget {
  const _CommandCenterAgendaRow({required this.item, required this.onTap});

  final _CommandCenterAgendaItem item;
  final ValueChanged<_CommandCenterAgendaItem> onTap;

  @override
  Widget build(BuildContext context) {
    final color = switch (item.kind) {
      _CommandCenterAgendaKind.event => HeyBeanTheme.accentStrong,
      _CommandCenterAgendaKind.task => HeyBeanTheme.warning,
      _CommandCenterAgendaKind.reminder => const Color(0xFF3B82F6),
    };
    final kindLabel = switch (item.kind) {
      _CommandCenterAgendaKind.event => 'Event',
      _CommandCenterAgendaKind.task => 'Task',
      _CommandCenterAgendaKind.reminder => 'Reminder',
    };
    final hasEventNotes = item.event != null && _eventHasNotes(item.event!);
    const timeWidth = 50.0;

    return Material(
      color: Colors.transparent,
      key: Key('command-center-agenda-${item.key}'),
      child: InkWell(
        onTap: () => onTap(item),
        child: Container(
          height: 42,
          padding: const EdgeInsets.symmetric(horizontal: 6),
          decoration: BoxDecoration(
            border: Border(
              bottom: BorderSide(color: _quietBorderColor(alpha: .30)),
            ),
          ),
          child: Row(
            children: [
              SizedBox(
                key: Key('command-center-agenda-time-${item.key}'),
                width: timeWidth,
                child: _CommandCenterAgendaTimeLabel(
                  label: item.timeLabel,
                  allowStackedRange:
                      item.kind == _CommandCenterAgendaKind.event,
                ),
              ),
              const SizedBox(width: 5),
              Container(
                key: Key('command-center-agenda-dot-${item.key}'),
                width: 7,
                height: 7,
                decoration: BoxDecoration(
                  color: color.withValues(alpha: .82),
                  shape: BoxShape.circle,
                ),
              ),
              const SizedBox(width: 6),
              Expanded(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: HeyBeanTheme.text,
                        fontSize: 14,
                        fontWeight: FontWeight.w600,
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
        ? label.split(RegExp(r'\s+[–-]\s+'))
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
  final HermesCalendarEvent? event;
  final HermesTask? task;
  final HermesReminder? reminder;
}

class _CommandCenterGlanceDay {
  const _CommandCenterGlanceDay({required this.date, required this.events});

  final DateTime date;
  final List<HermesCalendarEvent> events;
}
