part of '../../main.dart';

Future<DateTime?> _showStandardDateTimeDock(
  BuildContext context, {
  required String initialText,
  String? originalValue,
  String? referenceValue,
  String keyPrefix = 'standard',
  bool dateOnly = false,
}) async {
  final parsed =
      _parseCalendarEventDateTime(initialText, referenceValue) ??
      _parseCalendarEventDateTime(originalValue, referenceValue) ??
      _parseCalendarEventDateTime(referenceValue) ??
      DateTime.now();
  final initialYear = parsed.year;
  final yearStart = initialYear - 1;
  final initialMonthIndex = parsed.month - 1;
  final initialDayIndex = parsed.day - 1;
  final initialYearIndex = initialYear - yearStart;
  final initialHourIndex = (parsed.hour % 12 == 0 ? 12 : parsed.hour % 12) - 1;
  final initialMinuteIndex = (parsed.minute / 5).round().clamp(0, 11);
  final initialMeridiemIndex = parsed.hour >= 12 ? 1 : 0;
  var selectedMonthIndex = initialMonthIndex;
  var selectedDayIndex = initialDayIndex;
  var selectedYearIndex = initialYearIndex;
  var selectedHourIndex = initialHourIndex;
  var selectedMinuteIndex = initialMinuteIndex;
  var selectedMeridiemIndex = initialMeridiemIndex;
  final monthController = FixedExtentScrollController(
    initialItem: initialMonthIndex,
  );
  final dayController = FixedExtentScrollController(
    initialItem: initialDayIndex,
  );
  final yearController = FixedExtentScrollController(
    initialItem: initialYearIndex,
  );
  final hourController = FixedExtentScrollController(
    initialItem: initialHourIndex,
  );
  final minuteController = FixedExtentScrollController(
    initialItem: initialMinuteIndex,
  );
  final meridiemController = FixedExtentScrollController(
    initialItem: initialMeridiemIndex,
  );

  try {
    return await showModalBottomSheet<DateTime>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (context) => SafeArea(
        top: false,
        child: Container(
          key: Key('$keyPrefix-time-dock'),
          margin: const EdgeInsets.all(12),
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
          decoration: BoxDecoration(
            color: HeyBeanTheme.surface,
            borderRadius: BorderRadius.circular(28),
            border: Border.all(color: HeyBeanTheme.border),
            boxShadow: const [
              BoxShadow(
                color: Color(0x26000000),
                blurRadius: 30,
                offset: Offset(0, 16),
              ),
            ],
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 44,
                height: 5,
                decoration: BoxDecoration(
                  color: HeyBeanTheme.border,
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
              const SizedBox(height: 14),
              Text(
                dateOnly ? 'Choose date' : 'Choose date and time',
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  color: HeyBeanTheme.text,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 12),
              SizedBox(
                height: 128,
                child: Row(
                  children: [
                    Expanded(
                      child: CupertinoPicker(
                        key: Key('$keyPrefix-date-month-dial'),
                        scrollController: monthController,
                        itemExtent: 36,
                        magnification: 1.05,
                        useMagnifier: true,
                        onSelectedItemChanged: (index) =>
                            selectedMonthIndex = index,
                        children: [
                          for (var month = 1; month <= 12; month++)
                            Center(child: Text(_monthName(month))),
                        ],
                      ),
                    ),
                    Expanded(
                      child: CupertinoPicker(
                        key: Key('$keyPrefix-date-day-dial'),
                        scrollController: dayController,
                        itemExtent: 36,
                        magnification: 1.05,
                        useMagnifier: true,
                        onSelectedItemChanged: (index) =>
                            selectedDayIndex = index,
                        children: [
                          for (var day = 1; day <= 31; day++)
                            Center(child: Text(day.toString())),
                        ],
                      ),
                    ),
                    Expanded(
                      child: CupertinoPicker(
                        key: Key('$keyPrefix-date-year-dial'),
                        scrollController: yearController,
                        itemExtent: 36,
                        magnification: 1.05,
                        useMagnifier: true,
                        onSelectedItemChanged: (index) =>
                            selectedYearIndex = index,
                        children: [
                          for (
                            var year = yearStart;
                            year <= yearStart + 4;
                            year++
                          )
                            Center(child: Text(year.toString())),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              if (!dateOnly) ...[
                const SizedBox(height: 12),
                SizedBox(
                  height: 190,
                  child: Row(
                    children: [
                      Expanded(
                        child: CupertinoPicker(
                          key: Key('$keyPrefix-time-hour-dial'),
                          scrollController: hourController,
                          itemExtent: 42,
                          magnification: 1.08,
                          useMagnifier: true,
                          looping: true,
                          onSelectedItemChanged: (index) =>
                              selectedHourIndex = index % 12,
                          children: [
                            for (var hour = 1; hour <= 12; hour++)
                              Center(child: Text(hour.toString())),
                          ],
                        ),
                      ),
                      Text(
                        ':',
                        style: Theme.of(context).textTheme.headlineSmall
                            ?.copyWith(fontWeight: FontWeight.w900),
                      ),
                      Expanded(
                        child: CupertinoPicker(
                          key: Key('$keyPrefix-time-minute-dial'),
                          scrollController: minuteController,
                          itemExtent: 42,
                          magnification: 1.08,
                          useMagnifier: true,
                          looping: true,
                          onSelectedItemChanged: (index) =>
                              selectedMinuteIndex = index % 12,
                          children: [
                            for (var minute = 0; minute < 60; minute += 5)
                              Center(
                                child: Text(minute.toString().padLeft(2, '0')),
                              ),
                          ],
                        ),
                      ),
                      Expanded(
                        child: CupertinoPicker(
                          key: Key('$keyPrefix-time-meridiem-dial'),
                          scrollController: meridiemController,
                          itemExtent: 42,
                          magnification: 1.08,
                          useMagnifier: true,
                          onSelectedItemChanged: (index) =>
                              selectedMeridiemIndex = index,
                          children: const [
                            Center(child: Text('AM')),
                            Center(child: Text('PM')),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ],
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () => Navigator.of(context).pop(),
                      child: Text('Cancel'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: FilledButton(
                      key: Key('$keyPrefix-time-dock-done'),
                      onPressed: () {
                        final year = yearStart + selectedYearIndex;
                        final month = selectedMonthIndex + 1;
                        final maxDay = DateTime(year, month + 1, 0).day;
                        final day = (selectedDayIndex + 1).clamp(1, maxDay);
                        if (dateOnly) {
                          Navigator.of(context).pop(DateTime(year, month, day));
                          return;
                        }
                        final hour12 = selectedHourIndex + 1;
                        final minute = selectedMinuteIndex * 5;
                        var hour24 = hour12 % 12;
                        if (selectedMeridiemIndex == 1) hour24 += 12;
                        Navigator.of(
                          context,
                        ).pop(DateTime(year, month, day, hour24, minute));
                      },
                      child: Text('Done'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  } finally {
    monthController.dispose();
    dayController.dispose();
    yearController.dispose();
    hourController.dispose();
    minuteController.dispose();
    meridiemController.dispose();
  }
}

const _titleTimeEditorRecurrences = <({String value, String label})>[
  (value: 'none', label: 'None'),
  (value: 'daily', label: 'Daily'),
  (value: 'weekly', label: 'Weekly'),
  (value: 'monthly', label: 'Monthly'),
  (value: 'yearly', label: 'Yearly'),
  (value: 'specific_days', label: 'Specific days'),
  (value: 'interval', label: 'Every X'),
];

const _titleTimeEditorWeekdays = <({String value, String label})>[
  (value: 'mon', label: 'Mon'),
  (value: 'tue', label: 'Tue'),
  (value: 'wed', label: 'Wed'),
  (value: 'thu', label: 'Thu'),
  (value: 'fri', label: 'Fri'),
  (value: 'sat', label: 'Sat'),
  (value: 'sun', label: 'Sun'),
];

const _titleTimeEditorIntervalUnits = <({String value, String label})>[
  (value: 'days', label: 'days'),
  (value: 'weeks', label: 'weeks'),
  (value: 'months', label: 'months'),
];

const _titleTimeEditorCategoryColors = <({String value, String label})>[
  (value: _beanGreenCategoryColor, label: 'Green'),
  (value: '#007AFF', label: 'Blue'),
  (value: '#FF9500', label: 'Orange'),
  (value: '#AF52DE', label: 'Purple'),
  (value: '#FF3B30', label: 'Red'),
];

String _recurrenceFromMetadata(Map<String, Object?>? metadata) {
  final value = metadata?['recurrence']?.toString() ?? 'none';
  return _titleTimeEditorRecurrences.any(
        (recurrence) => recurrence.value == value,
      )
      ? value
      : 'none';
}

Set<String> _recurrenceDaysFromMetadata(Map<String, Object?>? metadata) =>
    ((metadata?['days'] ??
                    metadata?['specific_days'] ??
                    metadata?['specificDays'])
                as List? ??
            const <Object?>[])
        .map((value) => value.toString())
        .where(
          (value) => _titleTimeEditorWeekdays.any((day) => day.value == value),
        )
        .toSet();

String _recurrenceIntervalUnitFromMetadata(Map<String, Object?>? metadata) {
  final value =
      metadata?['unit']?.toString() ??
      metadata?['interval_unit']?.toString() ??
      metadata?['intervalUnit']?.toString() ??
      'days';
  return _titleTimeEditorIntervalUnits.any((unit) => unit.value == value)
      ? value
      : 'days';
}

Map<String, Object?> _metadataWithRecurrence(
  Map<String, Object?>? existing, {
  required String recurrence,
  required Iterable<String> days,
  required int interval,
  required String unit,
}) {
  final metadata = <String, Object?>{...?existing};
  metadata
    ..remove('days')
    ..remove('specific_days')
    ..remove('specificDays')
    ..remove('interval')
    ..remove('unit')
    ..remove('interval_unit')
    ..remove('intervalUnit')
    ..['recurrence'] = recurrence;
  if (recurrence == 'specific_days') {
    metadata['days'] = days.toList()..sort();
  }
  if (recurrence == 'specific_days' || recurrence == 'interval') {
    metadata['interval'] = interval;
    metadata['unit'] = unit;
  }
  return metadata;
}

Future<Map<String, Object?>?> _showTitleTimeEditor(
  BuildContext context, {
  required String title,
  required String titleLabel,
  required String timeLabel,
  required String initialTitle,
  required String initialTime,
  IconData? editorIcon,
  String? editorSubtitle,
  String? primarySectionTitle,
  String? primarySectionSubtitle,
  String initialNotes = '',
  required bool allowEmptyTime,
  List<HermesEventCategory> categories = const [],
  String? initialCategory,
  String? initialColor,
  String? deleteLabel,
  String? completeLabel,
  bool initialCritical = false,
  bool showCritical = true,
  bool showNotes = false,
  bool showTimeTextField = true,
  bool showRecurrence = false,
  String recurrenceTitle = 'Recurrence',
  String recurrenceSubtitle = 'Repeat this item when needed.',
  String recurrenceInfoTitle = 'Recurrence',
  Map<String, Object?>? initialMetadata,
  Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })?
  onEventCategorySaved,
  List<HermesWorkspace> workspaces = const [],
  String? activeWorkspaceId,
  bool showPrimaryWorkspaceSelector = false,
  Object? initialPrimaryWorkspaceId,
  GoogleCalendarSyncStatus? googleCalendarStatus,
  List<String> initialGoogleCalendarIds = const [],
  List<Object> initialSyncWorkspaceIds = const [],
  Future<void> Function(Map<String, Object?> result)? onSave,
}) async {
  final titleController = TextEditingController(text: initialTitle);
  final timeController = TextEditingController(text: initialTime);
  final notesController = TextEditingController(text: initialNotes);
  var selectedCategory = initialCategory?.trim() ?? '';
  var selectedColor = selectedCategory.isEmpty
      ? _themeCategoryColorHex()
      : initialColor?.trim() ?? _themeCategoryColorHex();
  var modalCategories = [...categories];
  var savingCategory = false;
  var saving = false;
  var isCritical = initialCritical;
  var recurrence = _recurrenceFromMetadata(initialMetadata);
  final recurrenceSpecificDays = _recurrenceDaysFromMetadata(initialMetadata);
  final recurrenceIntervalController = TextEditingController(
    text: initialMetadata?['interval']?.toString() ?? '1',
  );
  var recurrenceIntervalUnit = _recurrenceIntervalUnitFromMetadata(
    initialMetadata,
  );
  final syncWorkspaceIds = <Object>{...initialSyncWorkspaceIds};
  Object? selectedPrimaryWorkspaceId = initialPrimaryWorkspaceId;
  final googleCalendarIds = <String>{...initialGoogleCalendarIds};
  final writableGoogleCalendars =
      googleCalendarStatus?.writableCalendars ?? const <GoogleCalendarInfo>[];
  String? validationError;
  final isReminderEditor = titleLabel.toLowerCase().contains('reminder');
  final resolvedEditorIcon =
      editorIcon ??
      (isReminderEditor
          ? Icons.notifications_active_outlined
          : Icons.task_alt_rounded);
  final resolvedEditorSubtitle = (editorSubtitle?.trim().isNotEmpty ?? false)
      ? editorSubtitle!.trim()
      : isReminderEditor
      ? 'Time-sensitive nudge with optional repeat'
      : title.toLowerCase().contains('sub-task')
      ? 'Assigned to its parent task'
      : 'Keep the task lightweight, dated, and organized';
  final resolvedPrimarySectionTitle =
      primarySectionTitle ??
      (isReminderEditor ? 'Reminder basics' : 'Task basics');
  final resolvedPrimarySectionSubtitle =
      primarySectionSubtitle ??
      (isReminderEditor
          ? 'Title and required reminder time'
          : 'Title and optional due date');
  final actionLabel = deleteLabel == null ? 'Create' : 'Save';

  Map<String, Object?>? buildPayload(
    StateSetter setModalState, {
    bool complete = false,
  }) {
    final title = titleController.text.trim();
    final time = timeController.text.trim();
    if (title.isEmpty) {
      setModalState(() => validationError = 'A title is required.');
      return null;
    }
    if (!allowEmptyTime && time.isEmpty) {
      setModalState(() => validationError = 'A time is required.');
      return null;
    }
    if (time.isNotEmpty && _taskReminderInputToWireValue(time) == null) {
      setModalState(
        () => validationError =
            'Use a recognizable date/time, like Today 5:00 PM.',
      );
      return null;
    }
    Object? payloadPrimaryWorkspaceId = selectedPrimaryWorkspaceId;
    final payloadSyncWorkspaceIds = syncWorkspaceIds.toList();
    if (showPrimaryWorkspaceSelector && workspaces.isNotEmpty) {
      if (payloadPrimaryWorkspaceId == null &&
          payloadSyncWorkspaceIds.isNotEmpty) {
        payloadPrimaryWorkspaceId = payloadSyncWorkspaceIds.removeAt(0);
      }
      if (payloadPrimaryWorkspaceId == null) {
        setModalState(() => validationError = 'Choose at least one workspace.');
        return null;
      }
      payloadSyncWorkspaceIds.removeWhere(
        (workspaceId) =>
            _workspaceValuesMatch(workspaceId, payloadPrimaryWorkspaceId),
      );
    }

    return {
      'title': title,
      'time': time.isEmpty ? null : time,
      'notes': notesController.text.trim().isEmpty
          ? null
          : notesController.text.trim(),
      if (complete) 'complete': true,
      'category': selectedCategory.isEmpty ? null : selectedCategory,
      'color': selectedCategory.isEmpty
          ? _themeCategoryColorHex()
          : (selectedColor.isEmpty ? _themeCategoryColorHex() : selectedColor),
      'isCritical': isCritical,
      if (showRecurrence)
        'recurrenceMetadata': _metadataWithRecurrence(
          initialMetadata,
          recurrence: recurrence,
          days: recurrenceSpecificDays,
          interval: int.tryParse(recurrenceIntervalController.text.trim()) ?? 1,
          unit: recurrenceIntervalUnit,
        ),
      'syncToWorkspaceIds': payloadSyncWorkspaceIds,
      if (showPrimaryWorkspaceSelector)
        'workspaceId': _workspaceValueToInt(payloadPrimaryWorkspaceId),
      'googleCalendarIds': googleCalendarIds.toList()..sort(),
    };
  }

  Future<void> submitPayload(
    BuildContext context,
    StateSetter setModalState, {
    bool complete = false,
  }) async {
    if (saving) return;
    final payload = buildPayload(setModalState, complete: complete);
    if (payload == null) return;
    if (onSave == null) {
      Navigator.of(context).pop(payload);
      return;
    }

    setModalState(() {
      saving = true;
      validationError = null;
    });
    try {
      await onSave(payload);
      if (context.mounted) {
        Navigator.of(context).pop(payload);
      }
    } catch (error) {
      if (!context.mounted) return;
      setModalState(() {
        saving = false;
        validationError = beanFriendlyErrorMessage(
          error,
          action: 'save that change',
        );
      });
    }
  }

  Future<void> chooseDateTime(
    BuildContext pickerContext,
    StateSetter setModalState,
  ) async {
    final selected = await _showStandardDateTimeDock(
      pickerContext,
      initialText: timeController.text,
      originalValue: initialTime,
      keyPrefix: 'title-time',
    );
    if (selected == null) return;
    setModalState(() {
      timeController.text = _formatCalendarEventDateTime(
        selected.toIso8601String(),
      );
      validationError = null;
    });
  }

  return showModalBottomSheet<Map<String, Object?>>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    backgroundColor: Colors.transparent,
    builder: (context) => StatefulBuilder(
      builder: (context, setModalState) {
        final syncPrimaryWorkspaceId = showPrimaryWorkspaceSelector
            ? selectedPrimaryWorkspaceId
            : _workspaceValueForId(workspaces, activeWorkspaceId);
        final syncTargets = workspaces
            .where(
              (workspace) => !_workspaceValuesMatch(
                _workspaceValue(workspace),
                syncPrimaryWorkspaceId,
              ),
            )
            .toList();
        final workspaceChoices = showPrimaryWorkspaceSelector
            ? workspaces
            : syncTargets;
        final categoryDropdownValues = <HermesEventCategory>[
          ...modalCategories,
        ];
        if (selectedCategory.isNotEmpty &&
            !categoryDropdownValues.any(
              (category) =>
                  category.name.toLowerCase() == selectedCategory.toLowerCase(),
            )) {
          categoryDropdownValues.add(
            HermesEventCategory(
              id: -1,
              name: selectedCategory,
              color: selectedColor,
            ),
          );
        }
        categoryDropdownValues.sort(
          (a, b) => a.name.toLowerCase().compareTo(b.name.toLowerCase()),
        );
        final mediaQuery = MediaQuery.of(context);
        final topInset = mediaQuery.padding.top;
        return Padding(
          padding: EdgeInsets.only(bottom: mediaQuery.viewInsets.bottom),
          child: Container(
            height: mediaQuery.size.height - topInset,
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 10),
            decoration: BoxDecoration(
              color: HeyBeanTheme.surface,
              borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
              border: Border(
                top: BorderSide(color: _quietBorderColor(alpha: .42)),
              ),
            ),
            child: SafeArea(
              top: false,
              child: Stack(
                children: [
                  Positioned.fill(
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.only(bottom: 120),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          _FormEditorHeader(
                            icon: resolvedEditorIcon,
                            title: title,
                            subtitle: resolvedEditorSubtitle,
                          ),
                          const SizedBox(height: 14),
                          _MobileFormSection(
                            title: resolvedPrimarySectionTitle,
                            subtitle: resolvedPrimarySectionSubtitle,
                            icon: resolvedEditorIcon,
                            primary: true,
                            children: [
                              TextFormField(
                                key: const Key('title-time-editor-title'),
                                controller: titleController,
                                textInputAction: TextInputAction.next,
                                decoration: InputDecoration(
                                  labelText: titleLabel,
                                ),
                              ),
                              if (showTimeTextField)
                                TextFormField(
                                  key: const Key('title-time-editor-time'),
                                  controller: timeController,
                                  readOnly: true,
                                  onTap: () =>
                                      chooseDateTime(context, setModalState),
                                  decoration: InputDecoration(
                                    labelText: timeLabel,
                                    helperText: allowEmptyTime
                                        ? 'Optional · tap to choose date and time'
                                        : 'Required · tap to choose date and time',
                                    suffixIcon: IconButton(
                                      key: const Key(
                                        'title-time-editor-open-picker',
                                      ),
                                      tooltip: 'Choose date and time',
                                      onPressed: () => chooseDateTime(
                                        context,
                                        setModalState,
                                      ),
                                      icon: Icon(Icons.calendar_month_rounded),
                                    ),
                                  ),
                                )
                              else
                                Material(
                                  key: const Key(
                                    'title-time-editor-selected-time-label',
                                  ),
                                  borderRadius: BorderRadius.circular(999),
                                  color: Colors.transparent,
                                  child: InkWell(
                                    borderRadius: BorderRadius.circular(999),
                                    onTap: () =>
                                        chooseDateTime(context, setModalState),
                                    child: Container(
                                      padding: const EdgeInsets.symmetric(
                                        horizontal: 12,
                                        vertical: 10,
                                      ),
                                      decoration: BoxDecoration(
                                        color: Colors.white.withValues(
                                          alpha: HeyBeanTheme.isDark
                                              ? .04
                                              : .58,
                                        ),
                                        borderRadius: BorderRadius.circular(
                                          999,
                                        ),
                                        border: Border.all(
                                          color: const Color(0x1A1C314E),
                                        ),
                                      ),
                                      child: Row(
                                        children: [
                                          Icon(
                                            Icons.schedule_rounded,
                                            size: 18,
                                            color: HeyBeanTheme.muted,
                                          ),
                                          const SizedBox(width: 8),
                                          Expanded(
                                            child: Text(
                                              timeController.text.trim().isEmpty
                                                  ? 'No date and time selected'
                                                  : timeController.text.trim(),
                                              style: TextStyle(
                                                color:
                                                    timeController.text
                                                        .trim()
                                                        .isEmpty
                                                    ? HeyBeanTheme.muted
                                                    : HeyBeanTheme.text,
                                                fontWeight: FontWeight.w700,
                                              ),
                                            ),
                                          ),
                                          Icon(
                                            Icons.calendar_month_rounded,
                                            size: 18,
                                            color: HeyBeanTheme.muted,
                                          ),
                                        ],
                                      ),
                                    ),
                                  ),
                                ),
                              if (showCritical)
                                _MobileFormSwitch(
                                  widgetKey: const Key(
                                    'title-time-editor-critical-toggle',
                                  ),
                                  value: isCritical,
                                  onChanged: (selected) => setModalState(
                                    () => isCritical = selected,
                                  ),
                                  icon: isCritical
                                      ? Icons.star_rounded
                                      : Icons.star_border_rounded,
                                  title: 'Critical',
                                  subtitle:
                                      'Keep this visible in today’s priority view.',
                                ),
                            ],
                          ),
                          if (showNotes) ...[
                            const SizedBox(height: 12),
                            _MobileFormSection(
                              title: 'Details',
                              subtitle: 'Notes and importance',
                              iconWidget: _BeanNotesIcon(
                                size: 18,
                                color: HeyBeanTheme.muted,
                              ),
                              children: [
                                TextFormField(
                                  key: const Key('title-time-editor-notes'),
                                  controller: notesController,
                                  minLines: 3,
                                  maxLines: 6,
                                  decoration: _longFormInputDecoration(
                                    labelText: 'Notes',
                                    hintText: 'Add task details',
                                    prefixIcon: _BeanNotesIcon(),
                                  ),
                                ),
                              ],
                            ),
                          ],
                          if (modalCategories.isNotEmpty ||
                              onEventCategorySaved != null) ...[
                            const SizedBox(height: 12),
                            _MobileFormSection(
                              title: 'Organize',
                              subtitle: 'Category, color, and workspace',
                              icon: Icons.category_outlined,
                              children: [
                                Row(
                                  children: [
                                    Expanded(
                                      child: Text(
                                        'Category',
                                        style: TextStyle(
                                          color: HeyBeanTheme.text,
                                          fontWeight: FontWeight.w600,
                                        ),
                                      ),
                                    ),
                                    if (onEventCategorySaved != null)
                                      IconButton.outlined(
                                        key: const Key(
                                          'title-time-editor-category-add-action',
                                        ),
                                        tooltip: 'Create category',
                                        onPressed: savingCategory
                                            ? null
                                            : () async {
                                                final categoryValues =
                                                    await showDialog<
                                                      Map<String, String>
                                                    >(
                                                      context: context,
                                                      builder: (context) =>
                                                          const _EventCategoryCreateDialog(
                                                            initialColor:
                                                                _beanGreenCategoryColor,
                                                            colors:
                                                                _titleTimeEditorCategoryColors,
                                                          ),
                                                    );
                                                if (categoryValues == null) {
                                                  return;
                                                }
                                                final name =
                                                    categoryValues['name']
                                                        ?.trim() ??
                                                    '';
                                                final color =
                                                    categoryValues['color']
                                                        ?.trim() ??
                                                    _beanGreenCategoryColor;
                                                if (name.isEmpty) return;
                                                setModalState(
                                                  () => savingCategory = true,
                                                );
                                                try {
                                                  final saved =
                                                      await onEventCategorySaved(
                                                        name: name,
                                                        color: color,
                                                      );
                                                  setModalState(() {
                                                    modalCategories = [
                                                      ...modalCategories.where(
                                                        (item) =>
                                                            item.id != saved.id,
                                                      ),
                                                      saved,
                                                    ];
                                                    selectedCategory =
                                                        saved.name;
                                                    selectedColor = saved.color;
                                                    savingCategory = false;
                                                  });
                                                } catch (_) {
                                                  setModalState(() {
                                                    savingCategory = false;
                                                    validationError =
                                                        'Could not create category.';
                                                  });
                                                }
                                              },
                                        icon: Icon(Icons.add_rounded),
                                      ),
                                  ],
                                ),
                                KeyedSubtree(
                                  key: ValueKey(
                                    'title-time-editor-category-${selectedCategory.toLowerCase()}',
                                  ),
                                  child: DropdownButtonFormField<String>(
                                    key: const Key(
                                      'title-time-editor-category-select',
                                    ),
                                    initialValue: selectedCategory.isEmpty
                                        ? ''
                                        : selectedCategory,
                                    decoration: const InputDecoration(
                                      labelText: 'Category',
                                      prefixIcon: Icon(Icons.category_outlined),
                                    ),
                                    isExpanded: true,
                                    items: [
                                      const DropdownMenuItem<String>(
                                        key: Key(
                                          'title-time-editor-category-none',
                                        ),
                                        value: '',
                                        child: Text('No category'),
                                      ),
                                      for (final category
                                          in categoryDropdownValues)
                                        DropdownMenuItem<String>(
                                          key: Key(
                                            'title-time-editor-category-${category.name.toLowerCase().replaceAll(' ', '-')}',
                                          ),
                                          value: category.name,
                                          child: Row(
                                            mainAxisSize: MainAxisSize.min,
                                            children: [
                                              CircleAvatar(
                                                key: Key(
                                                  'title-time-editor-category-dot-${category.name.toLowerCase().replaceAll(' ', '-')}',
                                                ),
                                                radius: 6,
                                                backgroundColor:
                                                    _safeCategoryColor(
                                                      category.color,
                                                    ),
                                              ),
                                              const SizedBox(width: 8),
                                              Text(
                                                category.name,
                                                overflow: TextOverflow.ellipsis,
                                              ),
                                            ],
                                          ),
                                        ),
                                    ],
                                    onChanged: saving
                                        ? null
                                        : (value) => setModalState(() {
                                            final nextValue = value ?? '';
                                            if (nextValue.isEmpty) {
                                              selectedCategory = '';
                                              selectedColor =
                                                  _themeCategoryColorHex();
                                              return;
                                            }
                                            final category =
                                                categoryDropdownValues
                                                    .where(
                                                      (item) =>
                                                          item.name ==
                                                          nextValue,
                                                    )
                                                    .firstOrNull;
                                            selectedCategory = nextValue;
                                            selectedColor =
                                                category?.color ??
                                                selectedColor;
                                          }),
                                  ),
                                ),
                                _EventFieldLabel(
                                  icon: Icons.palette_outlined,
                                  label: 'Color',
                                ),
                                Wrap(
                                  spacing: 10,
                                  runSpacing: 10,
                                  children: [
                                    for (final color
                                        in _titleTimeEditorCategoryColors)
                                      _ColorSwatchButton(
                                        label: color.label,
                                        color: _colorFromHex(color.value),
                                        selected:
                                            selectedColor.toUpperCase() ==
                                            color.value.toUpperCase(),
                                        onTap: () => setModalState(() {
                                          selectedColor = color.value;
                                        }),
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          ],
                          if (showRecurrence) ...[
                            const SizedBox(height: 12),
                            _MobileFormSection(
                              key: const Key(
                                'title-time-editor-recurrence-field',
                              ),
                              title: 'Repeat',
                              subtitle:
                                  'Make this repeat when it should come back',
                              icon: Icons.repeat_rounded,
                              infoKey: const Key(
                                'title-time-editor-recurrence-info',
                              ),
                              infoTitle: recurrenceInfoTitle,
                              infoBullets: const [
                                'Choose None for a one-time item.',
                                'Specific days repeats on the weekdays you select.',
                                'Every X lets you build patterns like every 2 weeks or every 3 months.',
                              ],
                              children: [
                                _EventFieldLabel(
                                  icon: Icons.repeat_on_rounded,
                                  label: recurrenceTitle,
                                ),
                                Text(
                                  recurrenceSubtitle,
                                  style: TextStyle(
                                    color: HeyBeanTheme.muted,
                                    fontSize: 12,
                                    height: 1.35,
                                    fontWeight: FontWeight.w700,
                                  ),
                                ),
                                Wrap(
                                  spacing: 8,
                                  runSpacing: 8,
                                  children: [
                                    for (final option
                                        in _titleTimeEditorRecurrences)
                                      ChoiceChip(
                                        label: Text(option.label),
                                        selected: recurrence == option.value,
                                        onSelected: (_) => setModalState(() {
                                          recurrence = option.value;
                                        }),
                                      ),
                                  ],
                                ),
                                if (recurrence == 'specific_days')
                                  Wrap(
                                    key: const Key(
                                      'title-time-editor-specific-days',
                                    ),
                                    spacing: 8,
                                    runSpacing: 8,
                                    children: [
                                      for (final day
                                          in _titleTimeEditorWeekdays)
                                        FilterChip(
                                          label: Text(day.label),
                                          selected: recurrenceSpecificDays
                                              .contains(day.value),
                                          onSelected: (selected) =>
                                              setModalState(() {
                                                if (selected) {
                                                  recurrenceSpecificDays.add(
                                                    day.value,
                                                  );
                                                } else {
                                                  recurrenceSpecificDays.remove(
                                                    day.value,
                                                  );
                                                }
                                              }),
                                        ),
                                    ],
                                  ),
                                if (recurrence == 'interval')
                                  Row(
                                    key: const Key(
                                      'title-time-editor-interval-field',
                                    ),
                                    children: [
                                      Expanded(
                                        child: TextField(
                                          key: const Key(
                                            'title-time-editor-interval-count',
                                          ),
                                          controller:
                                              recurrenceIntervalController,
                                          keyboardType: TextInputType.number,
                                          decoration: const InputDecoration(
                                            labelText: 'Every',
                                            prefixIcon: Icon(
                                              Icons.numbers_rounded,
                                            ),
                                          ),
                                        ),
                                      ),
                                      const SizedBox(width: 10),
                                      DropdownButton<String>(
                                        key: const Key(
                                          'title-time-editor-interval-unit',
                                        ),
                                        value: recurrenceIntervalUnit,
                                        items: [
                                          for (final unit
                                              in _titleTimeEditorIntervalUnits)
                                            DropdownMenuItem(
                                              value: unit.value,
                                              child: Text(unit.label),
                                            ),
                                        ],
                                        onChanged: (value) => setModalState(() {
                                          if (value != null) {
                                            recurrenceIntervalUnit = value;
                                          }
                                        }),
                                      ),
                                    ],
                                  ),
                              ],
                            ),
                          ],
                          Align(
                            alignment: Alignment.centerLeft,
                            child: TextButton.icon(
                              key: const Key('title-time-editor-picker-button'),
                              onPressed: () =>
                                  chooseDateTime(context, setModalState),
                              icon: Icon(Icons.schedule_rounded),
                              label: Text('Choose date and time'),
                            ),
                          ),
                          if (writableGoogleCalendars.isNotEmpty) ...[
                            const SizedBox(height: 8),
                            _MobileFormSection(
                              key: const Key(
                                'title-time-editor-google-calendar-sync',
                              ),
                              title: 'Connected calendars',
                              subtitle:
                                  'Create or update this item on selected writable connected calendars.',
                              icon: Icons.calendar_month_rounded,
                              children: [
                                Wrap(
                                  spacing: 8,
                                  runSpacing: 8,
                                  children: [
                                    for (final calendar
                                        in writableGoogleCalendars)
                                      FilterChip(
                                        key: Key(
                                          'title-time-editor-google-calendar-${calendar.id}',
                                        ),
                                        label: Text(calendar.summary),
                                        selected: googleCalendarIds.contains(
                                          calendar.id,
                                        ),
                                        onSelected: (selected) =>
                                            setModalState(() {
                                              if (selected) {
                                                googleCalendarIds.add(
                                                  calendar.id,
                                                );
                                              } else {
                                                googleCalendarIds.remove(
                                                  calendar.id,
                                                );
                                              }
                                            }),
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          ],
                          if (showPrimaryWorkspaceSelector &&
                              workspaceChoices.isNotEmpty) ...[
                            const SizedBox(height: 8),
                            _MobileFormSection(
                              key: const Key(
                                'title-time-editor-primary-workspace',
                              ),
                              title: 'Workspaces',
                              subtitle:
                                  'Choose every workspace this item should be created in.',
                              icon: Icons.home_work_outlined,
                              children: [
                                Wrap(
                                  spacing: 8,
                                  runSpacing: 8,
                                  children: [
                                    for (final workspace in workspaceChoices)
                                      Builder(
                                        builder: (context) {
                                          final value = _workspaceValue(
                                            workspace,
                                          );
                                          final selected =
                                              _workspaceValuesMatch(
                                                value,
                                                selectedPrimaryWorkspaceId,
                                              ) ||
                                              syncWorkspaceIds.any(
                                                (workspaceId) =>
                                                    _workspaceValuesMatch(
                                                      workspaceId,
                                                      value,
                                                    ),
                                              );
                                          final isCurrent =
                                              _workspaceValuesMatch(
                                                value,
                                                _workspaceValueForId(
                                                  workspaces,
                                                  activeWorkspaceId,
                                                ),
                                              );
                                          final label = workspace.isPersonal
                                              ? 'Personal'
                                              : workspace.name;
                                          return FilterChip(
                                            key: Key(
                                              'title-time-editor-primary-workspace-${workspace.id}',
                                            ),
                                            label: Text(
                                              isCurrent
                                                  ? '$label (current)'
                                                  : label,
                                            ),
                                            selected: selected,
                                            onSelected: saving
                                                ? null
                                                : (
                                                    nextSelected,
                                                  ) => setModalState(() {
                                                    validationError = null;
                                                    if (nextSelected) {
                                                      if (selectedPrimaryWorkspaceId ==
                                                          null) {
                                                        selectedPrimaryWorkspaceId =
                                                            value;
                                                      } else {
                                                        syncWorkspaceIds.add(
                                                          value,
                                                        );
                                                      }
                                                      return;
                                                    }

                                                    if (_workspaceValuesMatch(
                                                      value,
                                                      selectedPrimaryWorkspaceId,
                                                    )) {
                                                      selectedPrimaryWorkspaceId =
                                                          null;
                                                      if (syncWorkspaceIds
                                                          .isNotEmpty) {
                                                        final replacement =
                                                            syncWorkspaceIds
                                                                .first;
                                                        selectedPrimaryWorkspaceId =
                                                            replacement;
                                                        syncWorkspaceIds
                                                            .removeWhere(
                                                              (workspaceId) =>
                                                                  _workspaceValuesMatch(
                                                                    workspaceId,
                                                                    replacement,
                                                                  ),
                                                            );
                                                      }
                                                    } else {
                                                      syncWorkspaceIds.removeWhere(
                                                        (workspaceId) =>
                                                            _workspaceValuesMatch(
                                                              workspaceId,
                                                              value,
                                                            ),
                                                      );
                                                    }
                                                  }),
                                          );
                                        },
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          ],
                          if (!showPrimaryWorkspaceSelector &&
                              syncTargets.isNotEmpty) ...[
                            const SizedBox(height: 8),
                            _MobileFormSection(
                              key: const Key(
                                'title-time-editor-workspace-sync',
                              ),
                              title: 'Also assign to',
                              subtitle:
                                  'Copy this item only to selected workspaces.',
                              icon: Icons.account_tree_outlined,
                              children: [
                                Wrap(
                                  spacing: 8,
                                  runSpacing: 8,
                                  children: [
                                    for (final workspace in syncTargets)
                                      FilterChip(
                                        key: Key(
                                          'title-time-editor-sync-workspace-${workspace.id}',
                                        ),
                                        label: Text(workspace.name),
                                        selected: syncWorkspaceIds.any(
                                          (workspaceId) =>
                                              _workspaceValuesMatch(
                                                workspaceId,
                                                _workspaceValue(workspace),
                                              ),
                                        ),
                                        onSelected: (selected) =>
                                            setModalState(() {
                                              final value = _workspaceValue(
                                                workspace,
                                              );
                                              if (selected) {
                                                syncWorkspaceIds.add(value);
                                              } else {
                                                syncWorkspaceIds.removeWhere(
                                                  (workspaceId) =>
                                                      _workspaceValuesMatch(
                                                        workspaceId,
                                                        value,
                                                      ),
                                                );
                                              }
                                            }),
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          ],
                          if (validationError != null) ...[
                            const SizedBox(height: 8),
                            _InlinePlanLimitError(message: validationError!),
                          ],
                          const SizedBox(height: 12),
                        ],
                      ),
                    ),
                  ),
                  Positioned(
                    left: 0,
                    right: 0,
                    bottom: 0,
                    child: Container(
                      padding: const EdgeInsets.fromLTRB(0, 10, 0, 0),
                      decoration: BoxDecoration(
                        color: HeyBeanTheme.surface.withValues(alpha: .97),
                        border: Border(
                          top: BorderSide(color: _quietBorderColor(alpha: .40)),
                        ),
                      ),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: OutlinedButton(
                                  onPressed: saving
                                      ? null
                                      : () => Navigator.of(context).pop(),
                                  child: Text('Cancel'),
                                ),
                              ),
                              if (deleteLabel != null) ...[
                                const SizedBox(width: 10),
                                IconButton.filled(
                                  key: const Key('title-time-editor-delete'),
                                  tooltip: deleteLabel,
                                  style: _destructiveIconButtonStyle(),
                                  onPressed: saving
                                      ? null
                                      : () => Navigator.of(
                                          context,
                                        ).pop({'delete': true}),
                                  icon: Icon(Icons.delete_outline_rounded),
                                ),
                              ],
                              const SizedBox(width: 10),
                              Expanded(
                                child: FilledButton.icon(
                                  key: const Key(
                                    'title-time-editor-save-bottom',
                                  ),
                                  onPressed: saving
                                      ? null
                                      : () => submitPayload(
                                          context,
                                          setModalState,
                                        ),
                                  icon: saving
                                      ? const SizedBox(
                                          width: 18,
                                          height: 18,
                                          child: CircularProgressIndicator(
                                            strokeWidth: 2,
                                          ),
                                        )
                                      : Icon(Icons.check_rounded),
                                  label: Text(
                                    saving ? 'Saving...' : actionLabel,
                                  ),
                                ),
                              ),
                            ],
                          ),
                          if (completeLabel != null) ...[
                            const SizedBox(height: 8),
                            Align(
                              alignment: Alignment.centerRight,
                              child: TextButton.icon(
                                key: const Key('title-time-editor-complete'),
                                onPressed: saving
                                    ? null
                                    : () => submitPayload(
                                        context,
                                        setModalState,
                                        complete: true,
                                      ),
                                icon: Icon(Icons.done_all_rounded),
                                label: Text(completeLabel),
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    ),
  );
}
