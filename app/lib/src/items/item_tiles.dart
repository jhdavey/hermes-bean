part of '../../main.dart';

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
        ? _quietSurfaceColor(alpha: .42)
        : categoryColor.withValues(alpha: .14);
    final borderColor = _quietBorderColor(alpha: completed ? .28 : .38);
    return Container(
      key: Key('task-row-surface-${task.id}'),
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
      decoration: BoxDecoration(
        color: surfaceColor,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: borderColor),
      ),
      child: Stack(
        children: [
          if (!completed)
            Positioned(
              left: 0,
              top: 12,
              bottom: 12,
              child: Container(
                width: 3,
                decoration: BoxDecoration(
                  color: categoryColor.withValues(alpha: .72),
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
            ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  widget.pending
                      ? Padding(
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
                                        color: HeyBeanTheme.muted,
                                        size: 18,
                                      ),
                                    ),
                                  ),
                              ],
                            ),
                            if (widget.subtitle.isNotEmpty) ...[
                              const SizedBox(height: 3),
                              Text(
                                widget.subtitle,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(
                                  color: HeyBeanTheme.muted,
                                  fontSize: 12,
                                  fontWeight: FontWeight.w500,
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
                      icon: Icon(
                        Icons.edit_outlined,
                        color: HeyBeanTheme.muted,
                        size: 20,
                      ),
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
                            color: _quietMutedSurfaceColor(alpha: .42),
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(
                              color: _quietBorderColor(alpha: .32),
                            ),
                          ),
                          child: Text(
                            task.notes!.trim(),
                            style: TextStyle(fontSize: 13, height: 1.35),
                          ),
                        ),
                        const SizedBox(height: 8),
                      ],
                      Row(
                        children: [
                          Expanded(
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
                            _CreateButton(
                              key: Key('task-add-subtask-${task.id}'),
                              tooltip: 'Add subtask',
                              onPressed: widget.onAddSubtask,
                            ),
                        ],
                      ),
                      if (widget.subtasks.isEmpty)
                        Text(
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
              child: Icon(
                Icons.star_rounded,
                color: HeyBeanTheme.warning.withValues(alpha: .88),
                size: 14,
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
              Icon(Icons.star_rounded, size: 14, color: HeyBeanTheme.warning),
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
                _compactDueTimeLabel(task.dueAt),
                style: TextStyle(color: HeyBeanTheme.muted, fontSize: 11),
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
        ? _quietSurfaceColor(alpha: .42)
        : categoryColor.withValues(alpha: .14);
    final borderColor = _quietBorderColor(alpha: completed ? .28 : .38);
    return Container(
      key: Key('reminder-row-surface-${reminder.id}'),
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
      decoration: BoxDecoration(
        color: surfaceColor,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: borderColor),
      ),
      child: Stack(
        children: [
          if (!completed)
            Positioned(
              left: 0,
              top: 12,
              bottom: 12,
              child: Container(
                width: 3,
                decoration: BoxDecoration(
                  color: categoryColor.withValues(alpha: .72),
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
            ),
          Row(
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
                              Icon(
                                Icons.star_rounded,
                                size: 14,
                                color: HeyBeanTheme.warning.withValues(
                                  alpha: .88,
                                ),
                              ),
                              const SizedBox(width: 4),
                            ],
                            Expanded(
                              child: Text(
                                reminder.title,
                                style: TextStyle(
                                  fontWeight: FontWeight.w600,
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
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: HeyBeanTheme.muted,
                            fontSize: 12,
                            fontWeight: FontWeight.w500,
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
                icon: Icon(
                  Icons.edit_outlined,
                  color: HeyBeanTheme.muted,
                  size: 20,
                ),
              ),
            ],
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
    onTap: onTap,
    child: Container(
      padding: const EdgeInsets.symmetric(vertical: 13),
      decoration: _sectionDividerDecoration(alpha: .22),
      child: Row(
        children: [
          Icon(icon, color: HeyBeanTheme.muted, size: 20),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: TextStyle(fontWeight: FontWeight.w600)),
                Text(
                  subtitle,
                  style: TextStyle(color: HeyBeanTheme.muted, fontSize: 12),
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
    decoration: _quietSurfaceDecoration(
      color: _quietMutedSurfaceColor(alpha: .52),
      borderAlpha: .42,
    ),
    child: Text(
      label,
      style: TextStyle(color: HeyBeanTheme.muted, fontWeight: FontWeight.w500),
    ),
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
      decoration: _quietSurfaceDecoration(
        color: _quietMutedSurfaceColor(alpha: .52),
        borderAlpha: .42,
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
            style: TextStyle(
              color: HeyBeanTheme.muted,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
    if (!fillHeight) return content;
    return Container(
      alignment: Alignment.center,
      decoration: _quietSurfaceDecoration(
        radius: 18,
        color: _quietSurfaceColor(alpha: .62),
        borderAlpha: .36,
      ),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 260),
        child: content,
      ),
    );
  }
}

class _InlineLoadingBadge extends StatelessWidget {
  const _InlineLoadingBadge({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: _quietSurfaceColor(alpha: .94),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: _quietBorderColor(alpha: .42)),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox.square(
              dimension: 13,
              child: CircularProgressIndicator(
                strokeWidth: 2,
                color: HeyBeanTheme.accentStrong,
                backgroundColor: HeyBeanTheme.accent.withValues(alpha: .14),
              ),
            ),
            const SizedBox(width: 7),
            Text(
              label,
              style: TextStyle(
                color: HeyBeanTheme.muted,
                fontSize: 11,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
