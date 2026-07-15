part of '../../main.dart';

class _MonthScroller extends StatefulWidget {
  const _MonthScroller({
    required this.selectedMonth,
    required this.onMonthSelected,
  });

  final DateTime selectedMonth;
  final ValueChanged<DateTime> onMonthSelected;

  @override
  State<_MonthScroller> createState() => _MonthScrollerState();
}

class _MonthScrollerState extends State<_MonthScroller> {
  ScrollController? _scrollController;

  @override
  void dispose() {
    _scrollController?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final selected = DateTime(
      widget.selectedMonth.year,
      widget.selectedMonth.month,
    );
    final currentMonth = DateTime(DateTime.now().year, DateTime.now().month);
    final months = List<DateTime>.generate(
      37,
      (index) => DateTime(currentMonth.year, currentMonth.month - 12 + index),
    );
    return LayoutBuilder(
      builder: (context, constraints) {
        final pillWidth = constraints.maxWidth / 6;
        _scrollController ??= ScrollController(
          initialScrollOffset: pillWidth * 12,
        );
        return SizedBox(
          key: const Key('calendar-month-scroller'),
          height: 48,
          child: SingleChildScrollView(
            controller: _scrollController,
            scrollDirection: Axis.horizontal,
            physics: const BouncingScrollPhysics(),
            child: Row(
              children: [
                for (var index = 0; index < months.length; index++)
                  Builder(
                    builder: (context) {
                      final month = months[index];
                      final isSelected =
                          month.year == selected.year &&
                          month.month == selected.month;
                      return SizedBox(
                        key: isSelected
                            ? const Key('calendar-month-pill-selected')
                            : Key('calendar-month-pill-$index'),
                        width: pillWidth,
                        child: Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 3),
                          child: InkWell(
                            borderRadius: BorderRadius.circular(18),
                            onTap: () => widget.onMonthSelected(month),
                            child: Container(
                              alignment: Alignment.center,
                              decoration: BoxDecoration(
                                color: isSelected
                                    ? HeyBeanTheme.accent
                                    : HeyBeanTheme.surface2.withValues(
                                        alpha: HeyBeanTheme.isDark ? .94 : 1,
                                      ),
                                borderRadius: BorderRadius.circular(18),
                                border: Border.all(
                                  color: isSelected
                                      ? HeyBeanTheme.accentStrong
                                      : HeyBeanTheme.isDark
                                      ? HeyBeanTheme.borderStrong
                                      : HeyBeanTheme.border,
                                ),
                              ),
                              child: Column(
                                mainAxisAlignment: MainAxisAlignment.center,
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Text(
                                    _shortMonthName(month.month),
                                    style: TextStyle(
                                      color: isSelected
                                          ? Colors.white
                                          : HeyBeanTheme.text,
                                      fontSize: 12,
                                      fontWeight: FontWeight.w900,
                                      height: 1,
                                    ),
                                  ),
                                  const SizedBox(height: 2),
                                  Text(
                                    '${month.year}',
                                    style: TextStyle(
                                      color: isSelected
                                          ? Colors.white.withValues(alpha: .88)
                                          : HeyBeanTheme.muted,
                                      fontSize: 10,
                                      fontWeight: FontWeight.w800,
                                      height: 1,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                      );
                    },
                  ),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _MonthGrid extends StatelessWidget {
  const _MonthGrid({
    required this.calendar,
    required this.selectedDay,
    required this.onDateSelected,
  });

  final List<HermesCalendarEvent> calendar;
  final DateTime selectedDay;
  final ValueChanged<DateTime> onDateSelected;

  @override
  Widget build(BuildContext context) {
    final today = _dateOnly(DateTime.now());
    final visibleMonth = _dateOnly(selectedDay);
    final first = DateTime(visibleMonth.year, visibleMonth.month);
    final daysInMonth = DateTime(
      visibleMonth.year,
      visibleMonth.month + 1,
      0,
    ).day;
    final leadingBlanks = first.weekday % 7;
    final totalCells = leadingBlanks + daysInMonth;
    final rowCount = (totalCells / 7).ceil();
    final eventDays = <int>{};
    final eventNoteDays = <int>{};
    for (var day = 1; day <= daysInMonth; day++) {
      final date = DateTime(visibleMonth.year, visibleMonth.month, day);
      for (final event in calendar) {
        if (_eventFallsOnDay(event, date)) {
          eventDays.add(day);
          if (_eventHasNotes(event)) eventNoteDays.add(day);
        }
      }
    }

    const weekdays = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
    return Column(
      key: const Key('apple-style-month-grid'),
      children: [
        Row(
          children: [
            for (final weekday in weekdays)
              Expanded(
                child: Center(
                  child: Text(
                    weekday,
                    style: TextStyle(
                      color: HeyBeanTheme.muted,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
              ),
          ],
        ),
        const SizedBox(height: 8),
        for (var row = 0; row < rowCount; row++) ...[
          Row(
            children: [
              for (var column = 0; column < 7; column++)
                Expanded(
                  child: Builder(
                    builder: (context) {
                      final day = _dayForCell(
                        row * 7 + column,
                        leadingBlanks,
                        daysInMonth,
                      );
                      final date = day == null
                          ? null
                          : DateTime(
                              visibleMonth.year,
                              visibleMonth.month,
                              day,
                            );
                      return _MonthDayCell(
                        day: day,
                        isToday: date != null && _sameCalendarDay(date, today),
                        isSelected:
                            date != null && _sameCalendarDay(date, selectedDay),
                        hasEvent: day != null && eventDays.contains(day),
                        hasNotes: day != null && eventNoteDays.contains(day),
                        onTap: date == null ? null : () => onDateSelected(date),
                      );
                    },
                  ),
                ),
            ],
          ),
          const SizedBox(height: 6),
        ],
      ],
    );
  }

  int? _dayForCell(int cell, int leadingBlanks, int daysInMonth) {
    final day = cell - leadingBlanks + 1;
    if (day < 1 || day > daysInMonth) return null;
    return day;
  }
}

class _MonthDayCell extends StatelessWidget {
  const _MonthDayCell({
    required this.day,
    required this.isToday,
    required this.isSelected,
    required this.hasEvent,
    required this.hasNotes,
    required this.onTap,
  });

  final int? day;
  final bool isToday;
  final bool isSelected;
  final bool hasEvent;
  final bool hasNotes;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final backgroundColor = isToday
        ? HeyBeanTheme.accent
        : isSelected
        ? HeyBeanTheme.accent.withValues(alpha: .10)
        : HeyBeanTheme.surface2;
    final borderColor = isToday || isSelected
        ? HeyBeanTheme.accentStrong
        : HeyBeanTheme.border;

    return InkWell(
      borderRadius: BorderRadius.circular(999),
      onTap: onTap,
      child: Container(
        height: 42,
        margin: const EdgeInsets.symmetric(horizontal: 2),
        decoration: BoxDecoration(
          color: backgroundColor,
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: borderColor),
        ),
        child: day == null
            ? const SizedBox.shrink()
            : Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    '$day',
                    style: TextStyle(
                      color: isToday ? Colors.white : HeyBeanTheme.text,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  if (hasEvent)
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Container(
                          width: 5,
                          height: 5,
                          decoration: BoxDecoration(
                            color: isToday ? Colors.white : HeyBeanTheme.accent,
                            shape: BoxShape.circle,
                          ),
                        ),
                        if (hasNotes) ...[
                          const SizedBox(width: 3),
                          Icon(
                            Icons.notes_rounded,
                            key: Key('month-day-notes-icon-$day'),
                            size: 8,
                            color: isToday
                                ? Colors.white
                                : HeyBeanTheme.accentStrong,
                          ),
                        ],
                      ],
                    ),
                ],
              ),
      ),
    );
  }
}

class _CalendarAgenda extends StatelessWidget {
  const _CalendarAgenda({
    required this.calendar,
    required this.eventCategories,
    this.googleCalendarStatus,
    this.outlookCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
    this.onEventTap,
    this.onEventCategorySaved,
    this.onEventCategoryDeleted,
  });

  final List<HermesCalendarEvent> calendar;
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
  final Future<void> Function(
    HermesCalendarEvent event, {
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
  })?
  onEventTap;
  final Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })?
  onEventCategorySaved;
  final Future<void> Function(
    HermesEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })?
  onEventCategoryDeleted;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(
        'Today / upcoming',
        style: Theme.of(
          context,
        ).textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 8),
      if (calendar.isEmpty)
        const _EmptySurface(label: 'No calendar events')
      else
        for (final event in calendar)
          _CompactItemTile(
            icon: Icons.event_available_rounded,
            title: event.title,
            subtitle: _eventSubtitle(event),
            onTap:
                onEventTap == null ||
                    onEventCategorySaved == null ||
                    onEventCategoryDeleted == null
                ? null
                : () => _showCalendarEventDetails(
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
                        }) => onEventTap!(
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
                    onCriticalChanged: (savedEvent, isCritical) => onEventTap!(
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
                    onEventCategorySaved: onEventCategorySaved!,
                    onEventCategoryDeleted: onEventCategoryDeleted!,
                  ),
          ),
    ],
  );
}
