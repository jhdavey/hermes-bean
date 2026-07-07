part of '../../main.dart';

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
  final overdue = _taskIsOverdue(task);
  final dueLabel = _compactDueTimeLabel(
    task.dueAt,
    showDateForOverdue: overdue,
  );
  final parts = <String>[
    if (_taskIsCompleted(task)) 'Completed',
    if ((task.category ?? '').trim().isNotEmpty) task.category!.trim(),
    if (overdue) 'overdue',
    if (dueLabel.isNotEmpty) 'Due $dueLabel',
    if (_taskIsRecurring(task)) _recurrenceSummaryFromMetadata(task.metadata),
  ];
  return parts.join(' · ');
}

String _reminderSubtitle(HermesReminder reminder) {
  final overdue = _reminderIsOverdue(reminder);
  final dueLabel = _compactDueTimeLabel(
    reminder.dueAt,
    showDateForOverdue: overdue,
  );
  final parts = <String>[
    _reminderIsCompleted(reminder) ? 'Completed' : 'Pending',
    if ((reminder.category ?? '').trim().isNotEmpty) reminder.category!.trim(),
    if (overdue) 'overdue',
    if (dueLabel.isNotEmpty) dueLabel else 'No time set',
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
  final start = _parseCalendarEventDateTime(event.startsAt);
  final end = _parseCalendarEventDateTime(event.endsAt, event.startsAt);
  if (start != null && end != null && end.isAfter(start)) {
    return _compactTimeRangeLabel(start, end);
  }
  final startLabel = start == null ? '' : _naturalTimeLabel(start);
  if (startLabel.isEmpty) return '';
  return startLabel;
}

String _compactDueTimeLabel(String? value, {bool showDateForOverdue = false}) {
  final trimmed = value?.trim() ?? '';
  if (trimmed.isEmpty) return '';
  final today = _dateOnly(DateTime.now());
  if (_wireValueLooksDateOnly(trimmed)) {
    final date = _calendarEventStoredDate(trimmed);
    if (date == null) return trimmed;
    return _compactDueDateLabel(
      date,
      today: today,
      useRelativeTodayAndTomorrow: !showDateForOverdue,
    );
  }
  final parsed = _parseCalendarEventDateTime(trimmed);
  if (parsed == null) return trimmed;
  if (showDateForOverdue && parsed.isBefore(DateTime.now())) {
    return _compactDueDateLabel(
      parsed,
      today: today,
      useRelativeTodayAndTomorrow: false,
    );
  }
  return _naturalTimeLabel(parsed);
}

String _compactDueDateLabel(
  DateTime date, {
  required DateTime today,
  bool useRelativeTodayAndTomorrow = true,
}) {
  if (useRelativeTodayAndTomorrow) {
    if (_sameCalendarDay(date, today)) return 'Today';
    if (_sameCalendarDay(date, today.add(const Duration(days: 1)))) {
      return 'Tomorrow';
    }
  }
  return date.year == today.year
      ? '${_shortMonthName(date.month)} ${date.day}'
      : '${_shortMonthName(date.month)} ${date.day}, ${date.year}';
}

String _compactTimeRangeLabel(DateTime start, DateTime end) {
  final startLabel = _naturalTimeLabel(start);
  final endLabel = _naturalTimeLabel(end);
  final startMeridiem = start.hour >= 12 ? 'pm' : 'am';
  final endMeridiem = end.hour >= 12 ? 'pm' : 'am';
  if (startMeridiem != endMeridiem) return '$startLabel-$endLabel';
  return '${startLabel.replaceFirst(RegExp('$startMeridiem\$'), '')}-$endLabel';
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
        event: event,
      ),
    );
  }

  for (final task in tasks) {
    if (_taskIsCompleted(task) || _taskIsSubtask(task)) continue;
    final due = _parseTaskDueDate(task);
    final dueDay = due == null ? null : _dateOnly(due);
    if (due == null || dueDay == null || dueDay.isAfter(today)) continue;
    final dateOnly = _wireValueLooksDateOnly(task.dueAt);
    final overdue = dueDay.isBefore(today) || (!dateOnly && due.isBefore(now));
    items.add(
      _CommandCenterAgendaItem(
        key: 'task-${task.id}',
        kind: _CommandCenterAgendaKind.task,
        title: task.title,
        time: overdue
            ? due
            : dateOnly
            ? endOfToday.subtract(const Duration(minutes: 1))
            : due,
        timeLabel: overdue
            ? _compactDueTimeLabel(task.dueAt, showDateForOverdue: true)
            : dateOnly
            ? 'Today'
            : _naturalTimeLabel(due),
        subtitle: [
          if (overdue) 'overdue',
          if ((task.category ?? '').trim().isNotEmpty) task.category!.trim(),
        ].join(' · '),
        task: task,
      ),
    );
  }

  for (final reminder in reminders) {
    if (_reminderIsCompleted(reminder)) continue;
    final due = _parseReminderDueDate(reminder);
    final dueDay = due == null ? null : _dateOnly(due);
    if (due == null || dueDay == null || dueDay.isAfter(today)) continue;
    final dateOnly = _wireValueLooksDateOnly(reminder.dueAt);
    final overdue = dueDay.isBefore(today) || (!dateOnly && due.isBefore(now));
    items.add(
      _CommandCenterAgendaItem(
        key: 'reminder-${reminder.id}',
        kind: _CommandCenterAgendaKind.reminder,
        title: reminder.title,
        time: overdue
            ? due
            : dateOnly
            ? endOfToday.subtract(const Duration(minutes: 1))
            : due,
        timeLabel: overdue
            ? _compactDueTimeLabel(reminder.dueAt, showDateForOverdue: true)
            : dateOnly
            ? 'Today'
            : _naturalTimeLabel(due),
        subtitle: [
          if (overdue) 'overdue',
          if ((reminder.category ?? '').trim().isNotEmpty)
            reminder.category!.trim(),
        ].join(' · '),
        reminder: reminder,
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

List<_CommandCenterGlanceDay> _commandCenterGlanceDays(
  List<HermesCalendarEvent> calendar,
) {
  final today = _dateOnly(DateTime.now());
  final days = <DateTime>[
    today.add(const Duration(days: 1)),
    today.add(const Duration(days: 2)),
  ];
  return days
      .map(
        (day) => _CommandCenterGlanceDay(
          date: day,
          events: _commandCenterEventsForGlanceDay(calendar, day),
        ),
      )
      .toList(growable: false);
}

List<HermesCalendarEvent> _commandCenterEventsForGlanceDay(
  List<HermesCalendarEvent> calendar,
  DateTime day,
) {
  final events = calendar
      .where((event) => _eventFallsOnDay(event, day))
      .toList(growable: false);
  events.sort((a, b) {
    final aAllDay = _eventIsAllDay(a);
    final bAllDay = _eventIsAllDay(b);
    if (aAllDay != bAllDay) return aAllDay ? -1 : 1;
    final aStart = _commandCenterGlanceSortTime(a, day);
    final bStart = _commandCenterGlanceSortTime(b, day);
    final timeCompare = aStart.compareTo(bStart);
    if (timeCompare != 0) return timeCompare;
    return a.title.toLowerCase().compareTo(b.title.toLowerCase());
  });
  return events;
}

DateTime _commandCenterGlanceSortTime(HermesCalendarEvent event, DateTime day) {
  final start = _parseCalendarEventDateTime(event.startsAt);
  if (start == null || start.isBefore(day)) return day;
  return start;
}

String _commandCenterGlanceDayLabel(DateTime day) {
  final today = _dateOnly(DateTime.now());
  if (_sameCalendarDay(day, today.add(const Duration(days: 1)))) {
    return 'Tomorrow ${_shortMonthName(day.month)} ${day.day}';
  }
  return '${_shortWeekdayName(day.weekday)} ${_shortMonthName(day.month)} ${day.day}';
}

String _commandCenterGlanceEventTime(HermesCalendarEvent event) {
  if (_eventIsAllDay(event)) return 'All day';
  final time = _eventTimeRangeShort(event);
  return time.isEmpty ? 'Timed' : time;
}

bool _wireValueLooksDateOnly(String? value) =>
    value != null && RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(value.trim());
