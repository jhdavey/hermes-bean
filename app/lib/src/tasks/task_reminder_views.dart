part of '../../main.dart';

class _QuietFilterOption {
  const _QuietFilterOption({
    required this.key,
    required this.value,
    required this.label,
  });

  final Key key;
  final String value;
  final String label;
}

class _QuietFilterBar extends StatelessWidget {
  const _QuietFilterBar({
    required this.options,
    required this.selected,
    required this.onSelected,
  });

  final List<_QuietFilterOption> options;
  final String selected;
  final ValueChanged<String> onSelected;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(3),
    decoration: BoxDecoration(
      color: _quietMutedSurfaceColor(alpha: .46),
      borderRadius: BorderRadius.circular(12),
      border: Border.all(color: _quietBorderColor(alpha: .38)),
    ),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (final option in options)
          Flexible(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 1),
              child: _QuietFilterButton(
                key: option.key,
                label: option.label,
                selected: option.value == selected,
                onPressed: () => onSelected(option.value),
              ),
            ),
          ),
      ],
    ),
  );
}

class _QuietFilterButton extends StatelessWidget {
  const _QuietFilterButton({
    super.key,
    required this.label,
    required this.selected,
    required this.onPressed,
  });

  final String label;
  final bool selected;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => TextButton(
    onPressed: onPressed,
    style: TextButton.styleFrom(
      minimumSize: const Size(0, 34),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
      foregroundColor: selected ? HeyBeanTheme.text : HeyBeanTheme.muted,
      backgroundColor: selected ? _quietSurfaceColor() : Colors.transparent,
      textStyle: TextStyle(
        fontSize: 12,
        fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
      ),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(9)),
    ),
    child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis),
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
        _QuietFilterBar(
          selected: _showAll
              ? 'all'
              : _showCompleted
              ? 'done'
              : 'active',
          options: const [
            _QuietFilterOption(
              key: Key('task-filter-open'),
              value: 'active',
              label: 'Active',
            ),
            _QuietFilterOption(
              key: Key('task-filter-done'),
              value: 'done',
              label: 'Done',
            ),
            _QuietFilterOption(
              key: Key('task-filter-all'),
              value: 'all',
              label: 'All tasks',
            ),
          ],
          onSelected: (value) => setState(() {
            _showCompleted = value == 'done';
            _showAll = value == 'all';
          }),
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
      initialTime: _formatCalendarDateTimeInput(task?.dueAt),
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
                        style: TextStyle(
                          color: HeyBeanTheme.text,
                          fontSize: 13,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(
                      countLabel,
                      style: TextStyle(
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
                decoration: BoxDecoration(
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
                    style: TextStyle(
                      color: HeyBeanTheme.text,
                      fontSize: 13,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ),
                Text(
                  countLabel,
                  style: TextStyle(
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
        _QuietFilterBar(
          selected: _showAll
              ? 'all'
              : _showCompleted
              ? 'completed'
              : 'scheduled',
          options: const [
            _QuietFilterOption(
              key: Key('reminder-filter-scheduled'),
              value: 'scheduled',
              label: 'Scheduled',
            ),
            _QuietFilterOption(
              key: Key('reminder-filter-completed'),
              value: 'completed',
              label: 'Completed',
            ),
            _QuietFilterOption(
              key: Key('reminder-filter-all'),
              value: 'all',
              label: 'All reminders',
            ),
          ],
          onSelected: (value) => setState(() {
            _showCompleted = value == 'completed';
            _showAll = value == 'all';
          }),
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
                : 'No scheduled reminders',
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
      initialTime: _formatCalendarDateTimeInput(reminder?.dueAt),
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
          : (_reminderIsCompleted(reminder)
                ? 'Mark scheduled'
                : 'Mark complete'),
      workspaces: widget.workspaces,
      activeWorkspaceId: widget.activeWorkspaceId,
      showPrimaryWorkspaceSelector: reminder == null,
      initialPrimaryWorkspaceId: reminder == null
          ? _workspaceValueForId(widget.workspaces, widget.activeWorkspaceId)
          : null,
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
                  ? 'scheduled'
                  : 'completed')
            : (reminder?.status ?? 'scheduled');
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
              ? 'scheduled'
              : 'completed')
        : (reminder?.status ?? 'scheduled');
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
