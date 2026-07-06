part of '../../main.dart';

class _TimelineEventLayoutCandidate {
  const _TimelineEventLayoutCandidate({
    required this.event,
    required this.start,
    required this.end,
  });

  final HermesCalendarEvent event;
  final DateTime start;
  final DateTime end;
}

List<_TimelineEventLayout> _timelineEventLayoutsForDay(
  List<HermesCalendarEvent> events,
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
    final assigned = <({HermesCalendarEvent event, int laneIndex})>[];
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
  HermesCalendarEvent event,
  DateTime day,
  int startHour,
  int endHour,
) {
  var start = _parseCalendarEventDateTime(event.startsAt);
  if (start == null) return null;
  var end =
      _parseCalendarEventDateTime(event.endsAt, event.startsAt) ??
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

int? _weekdayNumber(String name) {
  final normalized = name.toLowerCase().replaceAll('.', '');
  const aliases = <String, int>{
    'mon': DateTime.monday,
    'monday': DateTime.monday,
    'tue': DateTime.tuesday,
    'tues': DateTime.tuesday,
    'tuesday': DateTime.tuesday,
    'wed': DateTime.wednesday,
    'weds': DateTime.wednesday,
    'wednesday': DateTime.wednesday,
    'thu': DateTime.thursday,
    'thur': DateTime.thursday,
    'thurs': DateTime.thursday,
    'thursday': DateTime.thursday,
    'fri': DateTime.friday,
    'friday': DateTime.friday,
    'sat': DateTime.saturday,
    'saturday': DateTime.saturday,
    'sun': DateTime.sunday,
    'sunday': DateTime.sunday,
  };
  return aliases[normalized];
}

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

String _formatCalendarEventDateTime(String? value) =>
    _formatNaturalDateTime(value);

String _formatCalendarEventDate(String? value) {
  final parsed = _parseCalendarEventDateTime(value);
  return parsed == null ? '' : _formatCalendarDateLabel(parsed);
}

String _formatCalendarAllDayEventDate(String? value) {
  final parsed = _calendarEventStoredDate(value);
  if (parsed != null) return _formatCalendarDateLabel(parsed);
  return _formatCalendarEventDate(value);
}

String _formatCalendarEventEndDate(String? startsAt, String? endsAt) {
  final start = _parseCalendarEventDateTime(startsAt);
  final end = _parseCalendarEventDateTime(endsAt, startsAt);
  if (end == null) return start == null ? '' : _formatCalendarDateLabel(start);
  return _formatCalendarDateLabel(_displayEndDateForAllDay(start, end));
}

String _formatCalendarAllDayEventEndDate(String? startsAt, String? endsAt) {
  final start = _calendarEventStoredDate(startsAt);
  final end = _calendarEventStoredDate(endsAt);
  if (end == null) {
    return start == null
        ? _formatCalendarEventEndDate(startsAt, endsAt)
        : _formatCalendarDateLabel(start);
  }
  if (start != null && end.isAfter(start)) {
    return _formatCalendarDateLabel(end.subtract(const Duration(days: 1)));
  }
  return _formatCalendarDateLabel(end);
}

DateTime _displayEndDateForAllDay(DateTime? start, DateTime? end) {
  if (end == null) return _dateOnly(start ?? DateTime.now());
  final normalizedEnd = _dateOnly(end);
  final isExclusiveMidnight =
      end.hour == 0 &&
      end.minute == 0 &&
      end.second == 0 &&
      end.millisecond == 0 &&
      start != null &&
      end.isAfter(_dateOnly(start));
  return isExclusiveMidnight
      ? normalizedEnd.subtract(const Duration(days: 1))
      : normalizedEnd;
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
  final end = _parseCalendarEventDateTime(endsAt, startsAt);
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
  String? referenceValue,
  bool allowEmpty = false,
}) {
  final trimmed = value.trim();
  if (trimmed.isEmpty) return allowEmpty ? null : trimmed;

  final originalDisplay = _formatCalendarEventDateTime(originalValue);
  if (originalValue != null && trimmed == originalDisplay) {
    return _calendarEventWireValueToUtcIso(originalValue) ?? originalValue;
  }

  final parsed = _parseCalendarEventDateTime(
    trimmed,
    referenceValue ?? originalValue,
  );
  return parsed?.toUtc().toIso8601String() ?? trimmed;
}

DateTime? _calendarEventDateInputToDate(
  String? value, {
  String? originalValue,
  DateTime? referenceValue,
}) {
  final trimmed = value?.trim() ?? '';
  if (trimmed.isEmpty) {
    final original = _parseCalendarEventDateTime(originalValue);
    return original == null ? null : _dateOnly(original);
  }

  final parsed = _parseCalendarDateOnly(
    trimmed,
    referenceValue: referenceValue,
  );
  if (parsed != null) return parsed;

  final originalDisplay = _formatCalendarEventDate(originalValue);
  if (originalValue != null && trimmed == originalDisplay) {
    final original = _parseCalendarEventDateTime(originalValue);
    return original == null ? null : _dateOnly(original);
  }

  final parsedDateTime = _parseCalendarEventDateTime(trimmed);
  return parsedDateTime == null ? null : _dateOnly(parsedDateTime);
}

DateTime? _parseCalendarDateOnly(String value, {DateTime? referenceValue}) {
  final trimmed = value.trim();
  if (trimmed.isEmpty) return null;

  final isoMatch = RegExp(r'^(\d{4})-(\d{1,2})-(\d{1,2})$').firstMatch(trimmed);
  if (isoMatch != null) {
    final year = int.tryParse(isoMatch.group(1)!);
    final month = int.tryParse(isoMatch.group(2)!);
    final day = int.tryParse(isoMatch.group(3)!);
    if (year != null && month != null && day != null) {
      return DateTime(year, month, day);
    }
  }

  final relative = trimmed.toLowerCase();
  if (relative == 'today' || relative == 'tomorrow') {
    return _dateOnly(
      DateTime.now().add(
        relative == 'tomorrow' ? const Duration(days: 1) : Duration.zero,
      ),
    );
  }

  final friendlyMatch = RegExp(
    r'^(?:[A-Za-z]{3,9},?\s+)?([A-Za-z]{3,9})\s+(\d{1,2})(?:,?\s+(\d{4}))?$',
    caseSensitive: false,
  ).firstMatch(trimmed);
  if (friendlyMatch != null) {
    final month = _monthNumber(friendlyMatch.group(1)!);
    final day = int.tryParse(friendlyMatch.group(2)!);
    final year =
        int.tryParse(friendlyMatch.group(3) ?? '') ??
        referenceValue?.year ??
        DateTime.now().year;
    if (month != null && day != null) {
      return DateTime(year, month, day);
    }
  }

  return null;
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
  return DateTime(
    year,
    month,
    day,
    hour,
    minute,
    second,
    microsecond ~/ 1000,
    microsecond % 1000,
  );
}

DateTime? _parseCalendarEventDateTime(String? value, [String? referenceValue]) {
  if (value == null || value.trim().isEmpty) return null;
  final trimmed = value.trim();
  final parsed = DateTime.tryParse(trimmed);
  if (parsed != null) return parsed.isUtc ? parsed.toLocal() : parsed;
  final isoWallClock = _parseIsoDeviceLocalDateTime(trimmed);
  if (isoWallClock != null) return isoWallClock;

  final relativeMatch = RegExp(
    r'^(today|tomorrow)\s*(?:@|·|at)?\s*(\d{1,2})(?::(\d{2}))?\s*([AP]M)$',
    caseSensitive: false,
  ).firstMatch(trimmed);
  if (relativeMatch != null) {
    final base = DateTime.now().add(
      relativeMatch.group(1)!.toLowerCase() == 'tomorrow'
          ? const Duration(days: 1)
          : Duration.zero,
    );
    var hour = int.tryParse(relativeMatch.group(2)!);
    final minute = int.tryParse(relativeMatch.group(3) ?? '0') ?? 0;
    final meridiem = relativeMatch.group(4)!.toUpperCase();
    if (hour != null) {
      if (meridiem == 'PM' && hour != 12) hour += 12;
      if (meridiem == 'AM' && hour == 12) hour = 0;
      return DateTime(base.year, base.month, base.day, hour, minute);
    }
  }

  final weekdayMatch = RegExp(
    r'^(mon(?:day)?|tue(?:s|sday)?|wed(?:s|nesday)?|thu(?:r|rs|rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)\.?\s*(?:@|·|at)?\s*'
    r'(\d{1,2})(?::(\d{2}))?\s*([AP]M)$',
    caseSensitive: false,
  ).firstMatch(trimmed);
  if (weekdayMatch != null) {
    final weekday = _weekdayNumber(weekdayMatch.group(1)!);
    var hour = int.tryParse(weekdayMatch.group(2)!);
    final minute = int.tryParse(weekdayMatch.group(3) ?? '0') ?? 0;
    final meridiem = weekdayMatch.group(4)!.toUpperCase();
    if (weekday != null && hour != null) {
      if (meridiem == 'PM' && hour != 12) hour += 12;
      if (meridiem == 'AM' && hour == 12) hour = 0;
      final today = _dateOnly(DateTime.now());
      final daysUntil = (weekday - today.weekday) % DateTime.daysPerWeek;
      final base = today.add(Duration(days: daysUntil));
      return DateTime(base.year, base.month, base.day, hour, minute);
    }
  }

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
  final reference =
      _parseCalendarEventDateTime(referenceValue) ?? DateTime.now();
  return DateTime(reference.year, reference.month, reference.day, hour, minute);
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

bool _eventIsRecurring(HermesCalendarEvent event) {
  if (_calendarEventIsGeneratedOccurrence(event) ||
      _calendarEventRecurrenceExpansionDisabled(event)) {
    return false;
  }
  final recurrence = (event.recurrence ?? 'none').toLowerCase();
  return recurrence.isNotEmpty && recurrence != 'none';
}

bool _metadataTruthy(Object? value) {
  if (value == true) return true;
  if (value is num) return value != 0;
  final normalized = value?.toString().trim().toLowerCase();
  return normalized == 'true' || normalized == '1' || normalized == 'yes';
}

bool _calendarEventIsGeneratedOccurrence(HermesCalendarEvent event) {
  final metadata = event.metadata;
  return _metadataTruthy(
        metadata?['recurrence_generated'] ?? metadata?['recurrenceGenerated'],
      ) ||
      metadata?['recurrence_parent_event_id'] != null ||
      metadata?['recurrenceParentEventId'] != null;
}

bool _calendarEventSourceHidden(HermesCalendarEvent event) => _metadataTruthy(
  event.metadata?['recurrence_source_hidden'] ??
      event.metadata?['recurrenceSourceHidden'],
);

bool _calendarEventRecurrenceExpansionDisabled(HermesCalendarEvent event) =>
    _metadataTruthy(event.metadata?['_display_disable_recurrence_expansion']);

String? _calendarGeneratedOccurrenceKey(HermesCalendarEvent event) {
  final metadata = event.metadata;
  final parentId =
      metadata?['recurrence_parent_event_id'] ??
      metadata?['recurrenceParentEventId'];
  if (parentId == null) return null;
  final occurrenceDate =
      metadata?['recurrence_occurrence_date'] ??
      metadata?['recurrenceOccurrenceDate'];
  final parsedStart = _parseCalendarEventDateTime(event.startsAt);
  final occurrenceKey =
      occurrenceDate?.toString() ??
      (parsedStart == null
          ? event.id.toString()
          : _calendarDateKey(parsedStart));
  return 'generated:$parentId:$occurrenceKey';
}

String _calendarDisplayDedupKey(HermesCalendarEvent event) {
  final generatedKey = _calendarGeneratedOccurrenceKey(event);
  if (generatedKey != null) return generatedKey;
  final googleIds = event.googleCalendarIds;
  if (googleIds.isNotEmpty) {
    final sortedGoogleIds = [...googleIds]..sort();
    return 'google:${sortedGoogleIds.join(',')}:${event.startsAt}:${event.endsAt}';
  }
  return 'event:${event.id}';
}

List<HermesCalendarEvent> _normalizeCalendarEventsForDisplay(
  List<HermesCalendarEvent> events,
) {
  final normalized = <HermesCalendarEvent>[];
  final seen = <String>{};
  final materializedSeriesParentIds = events
      .map(
        (event) =>
            event.metadata?['recurrence_parent_event_id'] ??
            event.metadata?['recurrenceParentEventId'],
      )
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

DateTime? _calendarEventStoredDate(String? value) {
  final trimmed = value?.trim() ?? '';
  if (trimmed.isEmpty) return null;
  final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})').firstMatch(trimmed);
  if (match == null) return null;
  final year = int.tryParse(match.group(1)!);
  final month = int.tryParse(match.group(2)!);
  final day = int.tryParse(match.group(3)!);
  if (year == null || month == null || day == null) return null;
  return DateTime(year, month, day);
}

DateTime? _calendarEventDisplayStartDay(HermesCalendarEvent event) {
  if (_eventIsAllDay(event)) {
    final parsed = _parseCalendarEventDateTime(event.startsAt);
    return _calendarEventStoredDate(event.startsAt) ??
        (parsed == null ? null : _dateOnly(parsed));
  }
  final start = _parseCalendarEventDateTime(event.startsAt);
  return start == null ? null : _dateOnly(start);
}

DateTime? _calendarEventDisplayEndExclusiveDay(HermesCalendarEvent event) {
  if (_eventIsAllDay(event)) {
    final start = _calendarEventDisplayStartDay(event);
    final end = _calendarEventStoredDate(event.endsAt);
    if (end == null) return start?.add(const Duration(days: 1));
    return end.isAfter(start ?? end) ? end : end.add(const Duration(days: 1));
  }
  final end = _parseCalendarEventDateTime(event.endsAt, event.startsAt);
  return end == null ? null : _dateOnly(end);
}

Set<String> _recurringExceptionDates(HermesCalendarEvent event) {
  final raw =
      event.metadata?['recurring_exception_dates'] ??
      event.metadata?['recurringExceptionDates'] ??
      event.metadata?['recurrence_exceptions'];
  if (raw is! List) return const <String>{};
  return raw
      .map((value) => value.toString().trim())
      .where((value) => value.isNotEmpty)
      .toSet();
}

Map<String, Object?> _metadataAfterRecurringDelete(
  HermesCalendarEvent event,
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

bool _eventFallsOnDay(HermesCalendarEvent event, DateTime day) {
  final dayStart = DateTime(day.year, day.month, day.day);
  if (_eventIsAllDay(event)) {
    final startDay = _calendarEventDisplayStartDay(event);
    if (startDay == null) return false;
    final endExclusive =
        _calendarEventDisplayEndExclusiveDay(event) ??
        startDay.add(const Duration(days: 1));
    return !dayStart.isBefore(startDay) && dayStart.isBefore(endExclusive);
  }
  final start = _parseCalendarEventDateTime(event.startsAt);
  if (start == null) return false;
  var end =
      _parseCalendarEventDateTime(event.endsAt, event.startsAt) ??
      start.add(const Duration(minutes: 30));
  if (!end.isAfter(start)) {
    end = start.add(const Duration(minutes: 30));
  }
  final dayEnd = dayStart.add(const Duration(days: 1));
  if (_eventIsRecurring(event)) {
    return _recurringEventFallsOnDay(event, start, end, dayStart, dayEnd);
  }
  return end.isAfter(dayStart) && start.isBefore(dayEnd);
}

bool _recurringEventFallsOnDay(
  HermesCalendarEvent event,
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
    event.metadata?['recurrence_until']?.toString() ??
        event.metadata?['recurrenceUntil']?.toString(),
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

  final recurrence = (event.recurrence ?? 'none').toLowerCase();
  final interval = (event.metadata?['interval'] is num)
      ? ((event.metadata!['interval'] as num).toInt()).clamp(1, 365)
      : int.tryParse(event.metadata?['interval']?.toString() ?? '') ?? 1;
  final daysSinceStart = dayStart.difference(_dateOnly(start)).inDays;

  switch (recurrence) {
    case 'daily':
      return daysSinceStart % interval == 0;
    case 'weekly':
      return daysSinceStart >= 0 && daysSinceStart % (7 * interval) == 0;
    case 'monthly':
      final months =
          (dayStart.year - start.year) * 12 + (dayStart.month - start.month);
      return months >= 0 && months % interval == 0 && dayStart.day == start.day;
    case 'yearly':
      final years = dayStart.year - start.year;
      return years >= 0 &&
          years % interval == 0 &&
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
      final unit = (event.metadata?['unit'] ?? 'days').toString();
      if (unit == 'weeks') {
        return daysSinceStart >= 0 && daysSinceStart % (7 * interval) == 0;
      }
      if (unit == 'months') {
        final months =
            (dayStart.year - start.year) * 12 + (dayStart.month - start.month);
        return months >= 0 &&
            months % interval == 0 &&
            dayStart.day == start.day;
      }
      return daysSinceStart >= 0 && daysSinceStart % interval == 0;
    default:
      return end.isAfter(dayStart) && start.isBefore(dayEnd);
  }
}
