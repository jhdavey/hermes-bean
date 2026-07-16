part of '../../main.dart';

class _TimelineEventLayoutCandidate {
  const _TimelineEventLayoutCandidate({
    required this.event,
    required this.start,
    required this.end,
  });

  final BeanCalendarEvent event;
  final DateTime start;
  final DateTime end;
}

List<_TimelineEventLayout> _timelineEventLayoutsForDay(
  List<BeanCalendarEvent> events,
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
    final assigned = <({BeanCalendarEvent event, int laneIndex})>[];
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
  BeanCalendarEvent event,
  DateTime day,
  int startHour,
  int endHour,
) {
  var start = _parseCalendarEventDateTime(event.startsAt);
  if (start == null) return null;
  var end =
      _parseCalendarEventDateTime(event.endsAt) ??
      start.add(const Duration(minutes: 30));
  if (!end.isAfter(start)) {
    end = start.add(const Duration(minutes: 30));
  }

  final dayStart = DateTime(day.year, day.month, day.day);
  if (_eventIsRecurring(event)) {
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

String _formatCalendarEventDateTime(String? value) =>
    _formatNaturalDateTime(value);

String _formatCalendarDateTimeInput(String? value) {
  final parsed = _parseCalendarEventDateTime(value);
  if (parsed == null) return '';
  final local = parsed.toLocal();
  return '${local.year.toString().padLeft(4, '0')}-'
      '${local.month.toString().padLeft(2, '0')}-'
      '${local.day.toString().padLeft(2, '0')} '
      '${local.hour.toString().padLeft(2, '0')}:'
      '${local.minute.toString().padLeft(2, '0')}';
}

String _formatCalendarDateInput(DateTime value) =>
    '${value.year.toString().padLeft(4, '0')}-'
    '${value.month.toString().padLeft(2, '0')}-'
    '${value.day.toString().padLeft(2, '0')}';

String _formatCalendarAllDayEventDate(String? value) {
  final parsed = _parseCalendarEventDateTime(value);
  return parsed == null ? '' : _formatCalendarDateInput(parsed);
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
  final end = _parseCalendarEventDateTime(endsAt);
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
  bool allowEmpty = false,
}) {
  final trimmed = value.trim();
  if (trimmed.isEmpty) return allowEmpty ? null : trimmed;

  final originalDisplay = _formatCalendarDateTimeInput(originalValue);
  if (originalValue != null && trimmed == originalDisplay) {
    return _calendarEventWireValueToUtcIso(originalValue) ?? originalValue;
  }

  final parsed = _parseCalendarEventDateTime(trimmed);
  return parsed?.toUtc().toIso8601String() ?? trimmed;
}

DateTime? _calendarEventDateInputToDate(
  String? value, {
  String? originalValue,
}) {
  final trimmed = value?.trim() ?? '';
  if (trimmed.isEmpty) return null;

  final parsed = _parseCalendarDateOnly(trimmed);
  if (parsed != null) return parsed;

  final original = _parseCalendarEventDateTime(originalValue);
  final originalDisplay = original == null
      ? ''
      : _formatCalendarDateInput(original);
  if (originalValue != null && trimmed == originalDisplay) {
    return original == null ? null : _dateOnly(original);
  }

  return null;
}

DateTime? _parseCalendarDateOnly(String value) {
  final trimmed = value.trim();
  if (trimmed.isEmpty) return null;

  final isoMatch = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(trimmed);
  if (isoMatch != null) {
    final year = int.tryParse(isoMatch.group(1)!);
    final month = int.tryParse(isoMatch.group(2)!);
    final day = int.tryParse(isoMatch.group(3)!);
    if (year != null && month != null && day != null) {
      return _validatedCalendarDate(year, month, day);
    }
  }

  return null;
}

DateTime? _validatedCalendarDate(int year, int month, int day) {
  final value = DateTime(year, month, day);
  return value.year == year && value.month == month && value.day == day
      ? value
      : null;
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
  final parsed = DateTime(
    year,
    month,
    day,
    hour,
    minute,
    second,
    microsecond ~/ 1000,
    microsecond % 1000,
  );
  if (parsed.year != year ||
      parsed.month != month ||
      parsed.day != day ||
      parsed.hour != hour ||
      parsed.minute != minute ||
      parsed.second != second) {
    return null;
  }
  return parsed;
}

DateTime? _parseCalendarEventDateTime(String? value) {
  if (value == null || value.trim().isEmpty) return null;
  final trimmed = value.trim();
  final absoluteIso = RegExp(
    r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$',
  );
  if (absoluteIso.hasMatch(trimmed)) {
    final parsed = DateTime.tryParse(trimmed);
    return parsed?.toLocal();
  }
  final isoWallClock = _parseIsoDeviceLocalDateTime(trimmed);
  return isoWallClock;
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

bool _eventIsRecurring(BeanCalendarEvent event) {
  if (_calendarEventIsGeneratedOccurrence(event) ||
      _calendarEventRecurrenceExpansionDisabled(event)) {
    return false;
  }
  return const {
    'daily',
    'weekly',
    'monthly',
    'yearly',
    'specific_days',
    'interval',
  }.contains(event.recurrence);
}

bool _metadataTruthy(Object? value) => value == true;

bool _calendarEventIsGeneratedOccurrence(BeanCalendarEvent event) {
  final metadata = event.metadata;
  return _metadataTruthy(metadata?['recurrence_generated']) ||
      metadata?['recurrence_parent_event_id'] != null;
}

bool _calendarEventSourceHidden(BeanCalendarEvent event) =>
    _metadataTruthy(event.metadata?['recurrence_source_hidden']);

bool _calendarEventRecurrenceExpansionDisabled(BeanCalendarEvent event) =>
    _metadataTruthy(event.metadata?['_display_disable_recurrence_expansion']);

String? _calendarGeneratedOccurrenceKey(BeanCalendarEvent event) {
  final metadata = event.metadata;
  final parentId = metadata?['recurrence_parent_event_id'];
  if (parentId == null) return null;
  final occurrenceDate = metadata?['recurrence_occurrence_date'];
  final parsedStart = _parseCalendarEventDateTime(event.startsAt);
  final occurrenceKey =
      occurrenceDate?.toString() ??
      (parsedStart == null
          ? event.id.toString()
          : _calendarDateKey(parsedStart));
  return 'generated:$parentId:$occurrenceKey';
}

String _calendarDisplayDedupKey(BeanCalendarEvent event) {
  final generatedKey = _calendarGeneratedOccurrenceKey(event);
  if (generatedKey != null) return generatedKey;
  final googleIds = event.googleCalendarIds;
  if (googleIds.isNotEmpty) {
    final sortedGoogleIds = [...googleIds]..sort();
    return 'google:${sortedGoogleIds.join(',')}:${event.startsAt}:${event.endsAt}';
  }
  return 'event:${event.id}';
}

List<BeanCalendarEvent> _normalizeCalendarEventsForDisplay(
  List<BeanCalendarEvent> events,
) {
  final normalized = <BeanCalendarEvent>[];
  final seen = <String>{};
  final materializedSeriesParentIds = events
      .map((event) => event.metadata?['recurrence_parent_event_id'])
      .whereType<Object>()
      .map((value) => value.toString())
      .toSet();
  for (final event in events) {
    if (_calendarEventSourceHidden(event)) continue;
    final displayEvent =
        _eventIsRecurring(event) &&
            materializedSeriesParentIds.contains(event.id.toString())
        ? event.copyWith(
            metadata: {
              ...?event.metadata,
              '_display_disable_recurrence_expansion': true,
            },
          )
        : event;
    final key = _calendarDisplayDedupKey(event);
    if (seen.add(key)) normalized.add(displayEvent);
  }
  return normalized;
}

Set<String> _recurringExceptionDates(BeanCalendarEvent event) {
  final raw = event.metadata?['recurring_exception_dates'];
  if (raw is! List) return const <String>{};
  return raw
      .map((value) => value.toString().trim())
      .where((value) => value.isNotEmpty)
      .toSet();
}

Map<String, Object?> _metadataAfterRecurringDelete(
  BeanCalendarEvent event,
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

bool _eventFallsOnDay(BeanCalendarEvent event, DateTime day) {
  final dayStart = DateTime(day.year, day.month, day.day);
  final start = _parseCalendarEventDateTime(event.startsAt);
  if (start == null) return false;
  final parsedEnd = _parseCalendarEventDateTime(event.endsAt);
  if (parsedEnd != null && !parsedEnd.isAfter(start)) return false;
  final end =
      parsedEnd ??
      start.add(
        _eventIsAllDay(event)
            ? const Duration(days: 1)
            : const Duration(minutes: 30),
      );
  final dayEnd = dayStart.add(const Duration(days: 1));
  if (_eventIsRecurring(event)) {
    return _recurringEventFallsOnDay(event, start, end, dayStart, dayEnd);
  }
  return end.isAfter(dayStart) && start.isBefore(dayEnd);
}

bool _recurringEventFallsOnDay(
  BeanCalendarEvent event,
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
    event.metadata?['recurrence_until']?.toString(),
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

  final recurrence = event.recurrence ?? 'none';
  final daysSinceStart = dayStart.difference(_dateOnly(start)).inDays;

  switch (recurrence) {
    case 'daily':
      return daysSinceStart >= 0;
    case 'weekly':
      return daysSinceStart >= 0 && daysSinceStart % 7 == 0;
    case 'monthly':
      final months =
          (dayStart.year - start.year) * 12 + (dayStart.month - start.month);
      return months >= 0 && dayStart.day == start.day;
    case 'yearly':
      final years = dayStart.year - start.year;
      return years >= 0 &&
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
      final interval = event.metadata?['interval'];
      final unit = event.metadata?['unit'];
      if (interval is! int ||
          interval < 1 ||
          interval > 365 ||
          unit is! String) {
        return false;
      }
      switch (unit) {
        case 'days':
          return daysSinceStart >= 0 && daysSinceStart % interval == 0;
        case 'weeks':
          return daysSinceStart >= 0 && daysSinceStart % (7 * interval) == 0;
        case 'months':
          final months =
              (dayStart.year - start.year) * 12 +
              (dayStart.month - start.month);
          return months >= 0 &&
              months % interval == 0 &&
              dayStart.day == start.day;
        case 'years':
          final years = dayStart.year - start.year;
          return years >= 0 &&
              years % interval == 0 &&
              dayStart.month == start.month &&
              dayStart.day == start.day;
        default:
          return false;
      }
    default:
      return end.isAfter(dayStart) && start.isBefore(dayEnd);
  }
}
