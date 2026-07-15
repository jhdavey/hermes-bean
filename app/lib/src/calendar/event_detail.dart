part of '../../main.dart';

typedef _CalendarEventSaveCallback =
    Future<void> Function(
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
    });

Future<void> _showCalendarEventDetails(
  BuildContext context,
  HermesCalendarEvent event, {
  required List<HermesEventCategory> eventCategories,
  GoogleCalendarSyncStatus? googleCalendarStatus,
  GoogleCalendarSyncStatus? outlookCalendarStatus,
  String? occurrenceDate,
  required _CalendarEventSaveCallback onSave,
  required Future<HermesEventCategory> Function({
    HermesEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved,
  required Future<void> Function(
    HermesEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted,
  Future<void> Function(HermesCalendarEvent event, bool isCritical)?
  onCriticalChanged,
  Future<void> Function(
    HermesCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })?
  onDelete,
  List<HermesWorkspace> workspaces = const [],
  String? activeWorkspaceId,
}) async {
  final result = await Navigator.of(context).push<Map<String, Object?>>(
    MaterialPageRoute(
      builder: (_) => _CalendarEventDetailPage(
        event: event,
        occurrenceDate: occurrenceDate,
        eventCategories: eventCategories,
        googleCalendarStatus: googleCalendarStatus,
        outlookCalendarStatus: outlookCalendarStatus,
        workspaces: workspaces,
        activeWorkspaceId: activeWorkspaceId,
        onSave: onSave,
        onEventCategorySaved: onEventCategorySaved,
        onEventCategoryDeleted: onEventCategoryDeleted,
        onCriticalChanged: onCriticalChanged,
        onDelete: onDelete,
      ),
    ),
  );

  if (result != null && result['action'] == 'delete') {
    final recurringDeleteMode = result['recurringDeleteMode'] as String?;
    final recurringOccurrenceDate =
        result['recurringOccurrenceDate'] as String?;
    final deleteEvent =
        recurringDeleteMode == null && recurringOccurrenceDate == null
        ? event
        : event.copyWith(
            metadata: {
              ...?event.metadata,
              if (recurringDeleteMode != null)
                '_delete_recurring_mode': recurringDeleteMode,
              if (recurringOccurrenceDate != null)
                '_delete_occurrence_date': recurringOccurrenceDate,
            },
          );
    await onDelete?.call(
      deleteEvent,
      deleteFromWorkspaceIds:
          (result['deleteFromWorkspaceIds'] as List?)
              ?.whereType<Object>()
              .toList() ??
          const [],
    );
    return;
  }

  return;
}

class _CalendarEventDetailPage extends StatefulWidget {
  const _CalendarEventDetailPage({
    required this.event,
    this.occurrenceDate,
    required this.eventCategories,
    this.googleCalendarStatus,
    this.outlookCalendarStatus,
    this.workspaces = const [],
    this.activeWorkspaceId,
    required this.onSave,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
    this.onCriticalChanged,
    this.onDelete,
  });

  final HermesCalendarEvent event;
  final String? occurrenceDate;
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
  final _CalendarEventSaveCallback onSave;
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
  final Future<void> Function(HermesCalendarEvent event, bool isCritical)?
  onCriticalChanged;
  final Future<void> Function(
    HermesCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })?
  onDelete;

  @override
  State<_CalendarEventDetailPage> createState() =>
      _CalendarEventDetailPageState();
}

class _CalendarEventDetailPageState extends State<_CalendarEventDetailPage> {
  late final TextEditingController _title;
  late final TextEditingController _startsAt;
  late final TextEditingController _endsAt;
  late final TextEditingController _notes;
  late final TextEditingController _location;
  late final TextEditingController _category;
  late final TextEditingController _eventInterval;
  late String _color;
  late String _recurrence;
  late String _status;
  late List<HermesEventCategory> _categories;
  String _eventIntervalUnit = 'days';
  Object? _primaryWorkspaceId;
  final Set<Object> _syncWorkspaceIds = <Object>{};
  String? _validationError;
  late bool _isCritical;
  late bool _allDay;
  bool _createEventReminder = false;
  int _reminderMinutesBefore = 15;
  final Set<String> _eventSpecificDays = <String>{};
  bool _saving = false;
  bool _savingCategory = false;
  bool _showCategoryManager = false;
  Timer? _placeSearchDebounce;
  List<HermesPlaceSuggestion> _placeSuggestions = const [];
  HermesPlaceDetails? _selectedPlace;
  String? _placeLookupError;
  String? _placeSessionToken;
  bool _loadingPlaceSuggestions = false;
  bool _loadingPlaceDetails = false;

  static const _colors = <({String value, String label})>[
    (value: _beanGreenCategoryColor, label: 'Green'),
    (value: '#007AFF', label: 'Blue'),
    (value: '#FF9500', label: 'Orange'),
    (value: '#AF52DE', label: 'Purple'),
    (value: '#FF3B30', label: 'Red'),
  ];

  static const _recurrences = <({String value, String label})>[
    (value: 'none', label: 'None'),
    (value: 'daily', label: 'Daily'),
    (value: 'weekly', label: 'Weekly'),
    (value: 'monthly', label: 'Monthly'),
    (value: 'yearly', label: 'Yearly'),
    (value: 'specific_days', label: 'Specific days'),
    (value: 'interval', label: 'Every X'),
  ];

  static const _statuses = <({String value, String label})>[
    (value: 'scheduled', label: 'Scheduled'),
    (value: 'cancelled', label: 'Cancelled'),
  ];

  static const _reminderMinuteOptions = <({int value, String label})>[
    (value: 0, label: 'At start time'),
    (value: 5, label: '5 minutes before'),
    (value: 10, label: '10 minutes before'),
    (value: 15, label: '15 minutes before'),
    (value: 30, label: '30 minutes before'),
    (value: 60, label: '1 hour before'),
    (value: 120, label: '2 hours before'),
    (value: 1440, label: '1 day before'),
  ];

  static const _weekdays = <({String value, String label})>[
    (value: 'mon', label: 'Mon'),
    (value: 'tue', label: 'Tue'),
    (value: 'wed', label: 'Wed'),
    (value: 'thu', label: 'Thu'),
    (value: 'fri', label: 'Fri'),
    (value: 'sat', label: 'Sat'),
    (value: 'sun', label: 'Sun'),
  ];

  static const _intervalUnits = <({String value, String label})>[
    (value: 'days', label: 'days'),
    (value: 'weeks', label: 'weeks'),
    (value: 'months', label: 'months'),
    (value: 'years', label: 'years'),
  ];

  @override
  void initState() {
    super.initState();
    final event = widget.event;
    final eventMetadata = event.metadata ?? const <String, Object?>{};
    final initialPrimaryWorkspaceId =
        event.workspaceId ?? _workspaceValueToInt(widget.activeWorkspaceId);
    _primaryWorkspaceId =
        initialPrimaryWorkspaceId ??
        _workspaceValueForId(widget.workspaces, widget.activeWorkspaceId);
    _allDay = _eventIsAllDay(event);
    _title = TextEditingController(text: event.title);
    _startsAt = TextEditingController(
      text: _allDay
          ? _formatCalendarAllDayEventDate(event.startsAt)
          : _formatCalendarDateTimeInput(event.startsAt),
    );
    _endsAt = TextEditingController(
      text: _allDay
          ? _formatCalendarAllDayEventDate(event.endsAt ?? event.startsAt)
          : _formatCalendarDateTimeInput(event.endsAt),
    );
    _notes = TextEditingController(text: event.notes ?? '');
    _location = TextEditingController(text: event.location ?? '');
    _selectedPlace = _placeDetailsFromEvent(event);
    _location.addListener(_handleLocationTextChanged);
    _placeSessionToken = DateTime.now().microsecondsSinceEpoch.toString();
    _categories = [...widget.eventCategories];
    _category = TextEditingController(text: event.category ?? '');
    _eventInterval = TextEditingController(
      text: eventMetadata['interval']?.toString() ?? '1',
    );
    _syncWorkspaceIds.addAll(
      _initialSyncWorkspaceIds(
        linkedWorkspaceIds: event.linkedWorkspaceIds,
        workspaceId: event.workspaceId,
        activeWorkspaceId: widget.activeWorkspaceId,
      ),
    );
    _isCritical = event.isCritical;
    final matchingCategoryColor = _categories
        .where(
          (category) =>
              category.name.toLowerCase() ==
              (event.category ?? '').trim().toLowerCase(),
        )
        .map((category) => category.color)
        .firstOrNull;
    final initialColor = (event.category ?? '').trim().isEmpty
        ? event.color ?? _themeCategoryColorHex()
        : matchingCategoryColor ?? event.color;
    _color = _isHexColor(initialColor)
        ? initialColor!.toUpperCase()
        : _themeCategoryColorHex();
    _recurrence =
        _recurrences.any((recurrence) => recurrence.value == event.recurrence)
        ? event.recurrence!
        : 'none';
    _status = _statuses.any((status) => status.value == event.status)
        ? event.status
        : 'scheduled';
    _eventSpecificDays.addAll(
      ((eventMetadata['days'] as List?) ?? const <Object?>[])
          .whereType<String>(),
    );
    _eventIntervalUnit =
        _intervalUnits.any((unit) => unit.value == eventMetadata['unit'])
        ? eventMetadata['unit'] as String
        : 'days';
  }

  @override
  void dispose() {
    _placeSearchDebounce?.cancel();
    _location.removeListener(_handleLocationTextChanged);
    _title.dispose();
    _startsAt.dispose();
    _endsAt.dispose();
    _notes.dispose();
    _location.dispose();
    _category.dispose();
    _eventInterval.dispose();
    super.dispose();
  }

  HermesApiClient? get _placesApiClient => _HeyBeanRuntimeServices.apiClient;

  void _handleLocationTextChanged() {
    final query = _location.text.trim();
    final selectedAddress = _selectedPlace?.displayAddress ?? '';
    if (_selectedPlace != null &&
        selectedAddress.isNotEmpty &&
        query != selectedAddress) {
      setState(() => _selectedPlace = null);
    }

    _placeSearchDebounce?.cancel();
    if (query.length < 3 || _placesApiClient == null) {
      if (_placeSuggestions.isNotEmpty ||
          _loadingPlaceSuggestions ||
          _placeLookupError != null) {
        setState(() {
          _placeSuggestions = const [];
          _loadingPlaceSuggestions = false;
          _placeLookupError = null;
        });
      }
      return;
    }

    _placeSearchDebounce = Timer(const Duration(milliseconds: 350), () {
      unawaited(_loadPlaceSuggestions(query));
    });
  }

  Future<void> _loadPlaceSuggestions(String query) async {
    final apiClient = _placesApiClient;
    if (apiClient == null) return;
    setState(() {
      _loadingPlaceSuggestions = true;
      _placeLookupError = null;
    });
    try {
      final suggestions = await apiClient.autocompletePlaces(
        input: query,
        sessionToken: _placeSessionToken,
      );
      if (!mounted || _location.text.trim() != query) return;
      setState(() {
        _placeSuggestions = suggestions;
        _loadingPlaceSuggestions = false;
      });
    } on HermesApiException catch (error) {
      if (!mounted || _location.text.trim() != query) return;
      setState(() {
        _placeSuggestions = const [];
        _loadingPlaceSuggestions = false;
        _placeLookupError = switch (error.statusCode) {
          401 => 'Sign in again to search locations.',
          404 => 'Location search is not available on this API server yet.',
          _ => 'Could not load location suggestions (${error.statusCode}).',
        };
      });
    } catch (_) {
      if (!mounted || _location.text.trim() != query) return;
      setState(() {
        _placeSuggestions = const [];
        _loadingPlaceSuggestions = false;
        _placeLookupError = 'Could not load location suggestions.';
      });
    }
  }

  Future<void> _selectPlaceSuggestion(HermesPlaceSuggestion suggestion) async {
    final apiClient = _placesApiClient;
    if (apiClient == null) return;
    FocusManager.instance.primaryFocus?.unfocus();
    setState(() {
      _loadingPlaceDetails = true;
      _placeLookupError = null;
      _placeSuggestions = const [];
    });
    try {
      final details = await apiClient.placeDetails(
        placeId: suggestion.placeId,
        sessionToken: _placeSessionToken,
      );
      if (!mounted) return;
      final selectedText = details.displayAddress.isNotEmpty
          ? details.displayAddress
          : suggestion.fullText ?? suggestion.primaryText;
      setState(() {
        _selectedPlace = HermesPlaceDetails(
          placeId: details.placeId,
          name: details.name,
          formattedAddress: selectedText,
          latitude: details.latitude,
          longitude: details.longitude,
          googleMapsUri: details.googleMapsUri,
        );
        _location.text = selectedText;
        _loadingPlaceDetails = false;
        _placeSessionToken = DateTime.now().microsecondsSinceEpoch.toString();
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loadingPlaceDetails = false;
        _placeLookupError = 'Could not load that location.';
      });
    }
  }

  Future<void> _openEventDirections() async {
    final address = _location.text.trim();
    if (address.isEmpty) return;
    final uri = _eventDirectionsUri(
      address: address,
      preferredMapApp: _HeyBeanRuntimeServices.preferredMapApp,
      googlePlaceId: _selectedPlace?.placeId,
    );
    await _HeyBeanRuntimeServices.launchExternalUrl(uri);
  }

  Map<String, Object?> _placeMetadataForSave() {
    final selectedPlace = _selectedPlace;
    final locationText = _location.text.trim();
    if (selectedPlace == null ||
        locationText.isEmpty ||
        selectedPlace.displayAddress != locationText) {
      return const {
        'place_id': null,
        'place_formatted_address': null,
        'place_lat': null,
        'place_lng': null,
        'google_maps_uri': null,
      };
    }

    return {
      'place_id': selectedPlace.placeId,
      'place_formatted_address': selectedPlace.formattedAddress,
      'place_lat': selectedPlace.latitude,
      'place_lng': selectedPlace.longitude,
      'google_maps_uri': selectedPlace.googleMapsUri,
    };
  }

  Future<void> _save() async {
    if (_saving) return;
    late final String startsAt;
    String? endsAt;
    DateTime? parsedStart;
    DateTime? parsedEnd;

    if (_allDay) {
      final originalWasAllDay = _eventIsAllDay(widget.event);
      final originalStartsAt = widget.event.startsAt;
      final originalEndsAt = widget.event.endsAt;
      final startIsUnchanged =
          originalWasAllDay &&
          originalStartsAt != null &&
          _startsAt.text.trim() ==
              _formatCalendarAllDayEventDate(originalStartsAt);
      final endIsUnchanged =
          originalWasAllDay &&
          originalEndsAt != null &&
          _endsAt.text.trim() == _formatCalendarAllDayEventDate(originalEndsAt);
      final startDate = _calendarEventDateInputToDate(
        _startsAt.text,
        originalValue: originalStartsAt,
      );
      final endDate = _calendarEventDateInputToDate(
        _endsAt.text,
        originalValue: originalEndsAt,
      );
      if (startDate == null || endDate == null) {
        setState(() => _validationError = 'Choose valid start and end dates.');
        return;
      }
      final inputStart = DateTime(
        startDate.year,
        startDate.month,
        startDate.day,
      );
      final inputEnd = DateTime(endDate.year, endDate.month, endDate.day);
      startsAt = startIsUnchanged
          ? originalStartsAt
          : inputStart.toUtc().toIso8601String();
      endsAt = endIsUnchanged
          ? originalEndsAt
          : inputEnd.toUtc().toIso8601String();
      parsedStart = _parseCalendarEventDateTime(startsAt);
      parsedEnd = _parseCalendarEventDateTime(endsAt);
      if (parsedStart == null ||
          parsedEnd == null ||
          !parsedEnd.isAfter(parsedStart)) {
        setState(
          () => _validationError = 'Ends before must be after the start date.',
        );
        return;
      }
    } else {
      final wireStartsAt = _calendarEventInputToWireValue(
        _startsAt.text,
        originalValue: widget.event.startsAt,
      );
      final wireEndsAt = _calendarEventInputToWireValue(
        _endsAt.text,
        originalValue: widget.event.endsAt,
        allowEmpty: true,
      );
      parsedStart = _parseCalendarEventDateTime(wireStartsAt);
      parsedEnd = _parseCalendarEventDateTime(wireEndsAt);
      if (wireStartsAt == null ||
          wireStartsAt.trim().isEmpty ||
          parsedStart == null) {
        setState(() => _validationError = 'Choose a valid start time.');
        return;
      }
      if (wireEndsAt != null && parsedEnd == null) {
        setState(
          () => _validationError = 'Enter a valid end time or leave it blank.',
        );
        return;
      }
      if (parsedEnd != null &&
          (parsedEnd.isBefore(parsedStart) ||
              parsedEnd.isAtSameMomentAs(parsedStart))) {
        setState(
          () => _validationError = 'End time must be after the start time.',
        );
        return;
      }
      startsAt = wireStartsAt;
      endsAt = wireEndsAt;
    }

    final eventInterval = int.tryParse(_eventInterval.text.trim()) ?? 1;
    final syncToWorkspaceIds = _syncWorkspaceIds.toList();
    Object? primaryWorkspaceId = _primaryWorkspaceId;
    if (widget.event.id == 0 && widget.workspaces.isNotEmpty) {
      if (primaryWorkspaceId == null && syncToWorkspaceIds.isNotEmpty) {
        primaryWorkspaceId = syncToWorkspaceIds.removeAt(0);
      }
      if (primaryWorkspaceId == null) {
        setState(() => _validationError = 'Choose at least one workspace.');
        return;
      }
      syncToWorkspaceIds.removeWhere(
        (workspaceId) => _workspaceValuesMatch(workspaceId, primaryWorkspaceId),
      );
    }
    final eventMetadata = <String, Object?>{...?widget.event.metadata};
    eventMetadata
      ..remove('recurrence')
      ..remove('days')
      ..remove('interval')
      ..remove('unit');
    if (_recurrence == 'specific_days') {
      eventMetadata['days'] = _eventSpecificDays.toList()..sort();
    }
    if (_recurrence == 'interval') {
      eventMetadata['interval'] = eventInterval;
      eventMetadata['unit'] = _eventIntervalUnit;
    }
    eventMetadata.addAll(_placeMetadataForSave());
    eventMetadata.remove('all_day');

    setState(() {
      _saving = true;
      _validationError = null;
    });
    try {
      await widget.onSave(
        widget.event,
        title: _title.text.trim().isEmpty
            ? widget.event.title
            : _title.text.trim(),
        startsAt: startsAt,
        allDay: _allDay,
        endsAt: endsAt,
        notes: _notes.text.trim().isEmpty ? null : _notes.text.trim(),
        location: _location.text.trim().isEmpty ? null : _location.text.trim(),
        status: _status,
        category: _category.text.trim().isEmpty ? null : _category.text.trim(),
        color: _color,
        recurrence: _recurrence,
        metadata: eventMetadata,
        isCritical: _isCritical,
        reminderMinutesBefore: _createEventReminder
            ? _reminderMinutesBefore
            : null,
        reminderRecurrence: null,
        reminderSpecificDays: const [],
        reminderInterval: null,
        reminderIntervalUnit: null,
        workspaceId: _workspaceValueToInt(primaryWorkspaceId),
        syncToWorkspaceIds: syncToWorkspaceIds,
      );
      if (!mounted) return;
      Navigator.of(context).pop(<String, Object?>{'action': 'saved'});
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _validationError = beanFriendlyErrorMessage(
          error,
          action: 'save that event',
        );
      });
    }
  }

  Future<void> _saveCategoryValues({
    HermesEventCategory? category,
    required String name,
    required String color,
  }) async {
    final trimmedName = name.trim();
    if (trimmedName.isEmpty) return;
    setState(() {
      _savingCategory = true;
      _validationError = null;
    });
    try {
      final saved = await widget.onEventCategorySaved(
        category: category,
        name: trimmedName,
        color: color,
      );
      if (!mounted) return;
      setState(() {
        final exists = _categories.any((item) => item.id == saved.id);
        _categories = exists
            ? _categories
                  .map((item) => item.id == saved.id ? saved : item)
                  .toList()
            : [..._categories, saved];
        _category.text = saved.name;
        _color = saved.color;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _validationError = 'Could not save category. Try a different name.';
      });
    } finally {
      if (mounted) setState(() => _savingCategory = false);
    }
  }

  Future<void> _toggleCritical() async {
    final nextValue = !_isCritical;
    setState(() {
      _isCritical = nextValue;
      _validationError = null;
    });

    final onCriticalChanged = widget.onCriticalChanged;
    if (onCriticalChanged == null) return;

    try {
      await onCriticalChanged(widget.event, nextValue);
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _isCritical = !nextValue;
        _validationError = 'Could not update critical status. Try again.';
      });
    }
  }

  Future<void> _openCategoryCreationModal() async {
    final result = await showDialog<Map<String, String>>(
      context: context,
      builder: (context) =>
          _EventCategoryCreateDialog(initialColor: _color, colors: _colors),
    );
    if (result == null || !mounted) return;
    await _saveCategoryValues(
      name: result['name'] ?? '',
      color: result['color'] ?? _color,
    );
  }

  Future<void> _openCategoryEditModal(HermesEventCategory category) async {
    if (category.id < 0) return;
    final result = await showDialog<Map<String, String>>(
      context: context,
      builder: (context) => _EventCategoryCreateDialog(
        initialColor: category.color,
        initialName: category.name,
        editing: true,
        colors: _colors,
      ),
    );
    if (result == null || !mounted) return;
    await _saveCategoryValues(
      category: category,
      name: result['name'] ?? category.name,
      color: result['color'] ?? category.color,
    );
  }

  String _categoryKey(String name) => name.trim().replaceAll(' ', '-');

  Color _categoryColor(String value) =>
      Color(int.parse('FF${value.substring(1)}', radix: 16));

  List<HermesEventCategory> get _categoryChipValues {
    final byName = <String, HermesEventCategory>{};
    for (final category in _categories) {
      byName[category.name.toLowerCase()] = category;
    }
    final selectedName = _category.text.trim();
    if (selectedName.isNotEmpty &&
        !byName.containsKey(selectedName.toLowerCase())) {
      byName[selectedName.toLowerCase()] = HermesEventCategory(
        id: -1,
        name: selectedName,
        color: _color,
      );
    }
    final values = byName.values.toList()
      ..sort((a, b) => a.name.toLowerCase().compareTo(b.name.toLowerCase()));
    return values;
  }

  void _selectCategory(HermesEventCategory category) {
    setState(() {
      _category.text = category.name;
      _color = category.color;
    });
  }

  void _setEndOneHourAfterStart() {
    if (_allDay) return;
    final wireStart = _calendarEventInputToWireValue(
      _startsAt.text,
      originalValue: widget.event.startsAt,
    );
    final start = _parseCalendarEventDateTime(wireStart);
    if (start == null) return;
    _endsAt.text = _formatCalendarDateTimeInput(
      start.add(const Duration(hours: 1)).toIso8601String(),
    );
    _validationError = null;
  }

  Future<void> _deleteCategoryValues(HermesEventCategory category) async {
    if (category.id < 0) {
      setState(() {
        if (_category.text.trim() == category.name) {
          _category.clear();
          _color = _themeCategoryColorHex();
        }
      });
      return;
    }
    final deleteFromWorkspaceIds = await _confirmWorkspaceDeleteSelection(
      context,
      itemTitle: category.name,
      itemType: 'category',
      workspaces: widget.workspaces,
      activeWorkspaceId: widget.activeWorkspaceId,
      workspaceId: category.workspaceId,
      linkedWorkspaceIds: category.linkedWorkspaceIds,
    );
    if (deleteFromWorkspaceIds == null || !mounted) return;
    setState(() => _savingCategory = true);
    try {
      await widget.onEventCategoryDeleted(
        category,
        deleteFromWorkspaceIds: deleteFromWorkspaceIds,
      );
      if (!mounted) return;
      setState(() {
        _categories = _categories
            .where((item) => item.id != category.id)
            .toList();
        if (_category.text.trim() == category.name) {
          _category.clear();
          _color = _themeCategoryColorHex();
        }
      });
    } finally {
      if (mounted) setState(() => _savingCategory = false);
    }
  }

  Future<Map<String, Object?>?> _confirmCalendarEventDelete() async {
    final linkedIds = <int>{
      if (widget.event.workspaceId != null) widget.event.workspaceId!,
      ...widget.event.linkedWorkspaceIds,
    };
    if (linkedIds.isEmpty && widget.activeWorkspaceId != null) {
      final activeId = int.tryParse(widget.activeWorkspaceId!);
      if (activeId != null) linkedIds.add(activeId);
    }

    final workspaceById = {
      for (final workspace in widget.workspaces)
        if (workspace.numericId != null) workspace.numericId!: workspace,
    };
    final deleteChoices =
        linkedIds
            .map(
              (id) =>
                  workspaceById[id] ??
                  HermesWorkspace(
                    id: id.toString(),
                    name: id == widget.event.workspaceId
                        ? 'Current workspace'
                        : 'Workspace $id',
                  ),
            )
            .toList()
          ..sort((a, b) {
            if (a.numericId == widget.event.workspaceId) return -1;
            if (b.numericId == widget.event.workspaceId) return 1;
            return a.name.toLowerCase().compareTo(b.name.toLowerCase());
          });

    final isRecurring = _eventIsRecurring(widget.event);
    final occurrenceDate = widget.occurrenceDate;
    if (isRecurring && occurrenceDate != null) {
      var recurringMode = 'single';
      final selectedIds = deleteChoices
          .map((workspace) => workspace.numericId ?? workspace.id)
          .toSet();
      return showDialog<Map<String, Object?>>(
        context: context,
        builder: (context) => StatefulBuilder(
          builder: (context, setDialogState) {
            final canDelete = selectedIds.isNotEmpty || deleteChoices.isEmpty;

            return AlertDialog(
              title: Text('Delete recurring event'),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    ListTile(
                      key: const Key('event-delete-recurring-single'),
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(
                        recurringMode == 'single'
                            ? Icons.radio_button_checked_rounded
                            : Icons.radio_button_unchecked_rounded,
                        color: recurringMode == 'single'
                            ? HeyBeanTheme.accentStrong
                            : HeyBeanTheme.muted,
                      ),
                      onTap: () =>
                          setDialogState(() => recurringMode = 'single'),
                      title: Text('This event only'),
                      subtitle: Text('The series resumes after this date.'),
                    ),
                    ListTile(
                      key: const Key('event-delete-recurring-future'),
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(
                        recurringMode == 'future'
                            ? Icons.radio_button_checked_rounded
                            : Icons.radio_button_unchecked_rounded,
                        color: recurringMode == 'future'
                            ? HeyBeanTheme.accentStrong
                            : HeyBeanTheme.muted,
                      ),
                      onTap: () =>
                          setDialogState(() => recurringMode = 'future'),
                      title: Text('This and future events'),
                      subtitle: Text('Earlier events stay on the calendar.'),
                    ),
                    ListTile(
                      key: const Key('event-delete-recurring-all'),
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(
                        recurringMode == 'all'
                            ? Icons.radio_button_checked_rounded
                            : Icons.radio_button_unchecked_rounded,
                        color: recurringMode == 'all'
                            ? HeyBeanTheme.accentStrong
                            : HeyBeanTheme.muted,
                      ),
                      onTap: () => setDialogState(() => recurringMode = 'all'),
                      title: Text('Entire series'),
                      subtitle: Text('Remove every occurrence.'),
                    ),
                    if (deleteChoices.length > 1) ...[
                      Divider(height: 20),
                      for (final workspace in deleteChoices)
                        CheckboxListTile(
                          key: Key('event-delete-workspace-${workspace.id}'),
                          contentPadding: EdgeInsets.zero,
                          value: selectedIds.contains(
                            workspace.numericId ?? workspace.id,
                          ),
                          onChanged: (value) => setDialogState(() {
                            final id = workspace.numericId ?? workspace.id;
                            if (value ?? false) {
                              selectedIds.add(id);
                            } else {
                              selectedIds.remove(id);
                            }
                          }),
                          title: Text(
                            workspace.isPersonal ? 'Personal' : workspace.name,
                          ),
                          subtitle:
                              workspace.numericId == widget.event.workspaceId
                              ? Text('Current copy')
                              : null,
                        ),
                    ],
                  ],
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.of(context).pop(),
                  child: Text('Cancel'),
                ),
                FilledButton(
                  key: const Key('event-delete-recurring-action'),
                  style: FilledButton.styleFrom(
                    backgroundColor: HeyBeanTheme.destructive,
                    foregroundColor: Colors.white,
                  ),
                  onPressed: canDelete
                      ? () => Navigator.of(context).pop({
                          'deleteFromWorkspaceIds': selectedIds.toList(),
                          'recurringDeleteMode': recurringMode,
                          'recurringOccurrenceDate': occurrenceDate,
                        })
                      : null,
                  child: Text('Delete'),
                ),
              ],
            );
          },
        ),
      );
    }

    if (deleteChoices.length <= 1) {
      final confirmed = await _confirmDestructiveAction(
        context,
        title: 'Delete event?',
        message: 'This removes "${widget.event.title}" from your calendar.',
        confirmLabel: 'Delete event',
      );
      if (!confirmed) return null;
      return {
        'deleteFromWorkspaceIds': [
          if (deleteChoices.isNotEmpty)
            deleteChoices.first.numericId ?? deleteChoices.first.id,
        ],
      };
    }

    final selectedIds = deleteChoices
        .map((workspace) => workspace.numericId ?? workspace.id)
        .toSet();
    return showDialog<Map<String, Object?>>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) {
          final canDelete = selectedIds.isNotEmpty;

          return AlertDialog(
            title: Text('Delete event from'),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  '"${widget.event.title}" is linked across workspaces. Choose where to remove it.',
                ),
                const SizedBox(height: 10),
                for (final workspace in deleteChoices)
                  CheckboxListTile(
                    key: Key('event-delete-workspace-${workspace.id}'),
                    contentPadding: EdgeInsets.zero,
                    value: selectedIds.contains(
                      workspace.numericId ?? workspace.id,
                    ),
                    onChanged: (value) => setDialogState(() {
                      final id = workspace.numericId ?? workspace.id;
                      if (value ?? false) {
                        selectedIds.add(id);
                      } else {
                        selectedIds.remove(id);
                      }
                    }),
                    title: Text(
                      workspace.isPersonal ? 'Personal' : workspace.name,
                    ),
                    subtitle: workspace.numericId == widget.event.workspaceId
                        ? Text('Current copy')
                        : null,
                  ),
              ],
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.of(context).pop(),
                child: Text('Cancel'),
              ),
              FilledButton(
                key: const Key('event-delete-selected-workspaces-action'),
                style: FilledButton.styleFrom(
                  backgroundColor: HeyBeanTheme.destructive,
                  foregroundColor: Colors.white,
                ),
                onPressed: canDelete
                    ? () => Navigator.of(
                        context,
                      ).pop({'deleteFromWorkspaceIds': selectedIds.toList()})
                    : null,
                child: Text('Delete event'),
              ),
            ],
          );
        },
      ),
    );
  }

  Future<void> _showTimeDock(
    TextEditingController controller, {
    required String? originalValue,
    String? referenceValue,
    bool updateEndFromStart = false,
  }) async {
    if (_allDay) {
      await _showDateDock(
        controller,
        originalValue: originalValue,
        referenceValue: referenceValue,
      );
      return;
    }

    final selected = await _showStandardDateTimeDock(
      context,
      initialText: controller.text,
      originalValue: originalValue,
      referenceValue: referenceValue,
      keyPrefix: 'event',
    );
    if (selected != null && mounted) {
      setState(() {
        controller.text = _formatCalendarDateTimeInput(
          selected.toIso8601String(),
        );
        if (updateEndFromStart) _setEndOneHourAfterStart();
      });
    }
  }

  Future<void> _showDateDock(
    TextEditingController controller, {
    required String? originalValue,
    String? referenceValue,
  }) async {
    final selected = await _showStandardDateTimeDock(
      context,
      initialText: controller.text,
      originalValue: originalValue,
      referenceValue: referenceValue,
      keyPrefix: 'event-date',
      dateOnly: true,
    );
    if (selected != null && mounted) {
      setState(() {
        controller.text = _formatCalendarDateInput(selected);
      });
    }
  }

  void _setAllDay(bool value) {
    if (_allDay == value) return;
    setState(() {
      _allDay = value;
      _validationError = null;
      final start =
          _parseCalendarEventDateTime(_startsAt.text) ??
          _parseCalendarEventDateTime(widget.event.startsAt) ??
          DateTime.now();
      final end =
          _parseCalendarEventDateTime(_endsAt.text) ??
          _parseCalendarEventDateTime(widget.event.endsAt) ??
          start;

      if (value) {
        _startsAt.text = _formatCalendarDateInput(start);
        final startDay = _dateOnly(start);
        final endDay = _dateOnly(end);
        _endsAt.text = _formatCalendarDateInput(
          endDay.isAfter(startDay)
              ? endDay
              : startDay.add(const Duration(days: 1)),
        );
      } else {
        final startDate =
            _calendarEventDateInputToDate(_startsAt.text) ?? start;
        final endDate = _calendarEventDateInputToDate(_endsAt.text);
        _startsAt.text = _formatCalendarDateTimeInput(
          DateTime(
            startDate.year,
            startDate.month,
            startDate.day,
            start.hour == 0 ? 9 : start.hour,
            start.minute,
          ).toIso8601String(),
        );
        if (endDate == null) {
          _endsAt.clear();
        } else {
          _endsAt.text = _formatCalendarDateTimeInput(
            DateTime(
              endDate.year,
              endDate.month,
              endDate.day,
              start.hour == 0 ? 17 : start.add(const Duration(hours: 1)).hour,
              start.minute,
            ).toIso8601String(),
          );
        }
      }
    });
  }

  bool get _creatingEvent => widget.event.id == 0;

  List<HermesWorkspace> get _eventWorkspaceChoices {
    if (_creatingEvent) return widget.workspaces;
    return widget.workspaces
        .where((workspace) => workspace.id != widget.activeWorkspaceId)
        .toList();
  }

  bool _eventWorkspaceSelected(HermesWorkspace workspace) {
    final value = _workspaceValue(workspace);
    return _workspaceValuesMatch(value, _primaryWorkspaceId) ||
        _syncWorkspaceIds.any(
          (workspaceId) => _workspaceValuesMatch(workspaceId, value),
        );
  }

  void _setEventWorkspaceSelected(HermesWorkspace workspace, bool selected) {
    final value = _workspaceValue(workspace);
    setState(() {
      _validationError = null;
      if (!_creatingEvent) {
        if (selected) {
          _syncWorkspaceIds.add(value);
        } else {
          _syncWorkspaceIds.removeWhere(
            (workspaceId) => _workspaceValuesMatch(workspaceId, value),
          );
        }
        return;
      }

      if (selected) {
        if (_primaryWorkspaceId == null) {
          _primaryWorkspaceId = value;
        } else {
          _syncWorkspaceIds.add(value);
        }
        return;
      }

      if (_workspaceValuesMatch(value, _primaryWorkspaceId)) {
        _primaryWorkspaceId = null;
        if (_syncWorkspaceIds.isNotEmpty) {
          final replacement = _syncWorkspaceIds.first;
          _primaryWorkspaceId = replacement;
          _syncWorkspaceIds.removeWhere(
            (workspaceId) => _workspaceValuesMatch(workspaceId, replacement),
          );
        }
      } else {
        _syncWorkspaceIds.removeWhere(
          (workspaceId) => _workspaceValuesMatch(workspaceId, value),
        );
      }
    });
  }

  Widget _buildLocationEditor() {
    final apiClient = _placesApiClient;
    final selectedPlace = _selectedPlace;
    final showMap =
        selectedPlace?.latitude != null && selectedPlace?.longitude != null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const Key('event-location-field'),
          controller: _location,
          textInputAction: TextInputAction.next,
          decoration: InputDecoration(
            labelText: 'Location',
            prefixIcon: const Icon(Icons.place_rounded),
            suffixIcon: _loadingPlaceSuggestions || _loadingPlaceDetails
                ? const Padding(
                    padding: EdgeInsets.all(14),
                    child: SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  )
                : _location.text.trim().isEmpty
                ? null
                : IconButton(
                    tooltip: 'Clear location',
                    icon: const Icon(Icons.close_rounded),
                    onPressed: () {
                      setState(() {
                        _location.clear();
                        _selectedPlace = null;
                        _placeSuggestions = const [];
                        _placeLookupError = null;
                      });
                    },
                  ),
          ),
        ),
        if (_placeLookupError != null) ...[
          const SizedBox(height: 8),
          Text(
            _placeLookupError!,
            style: TextStyle(
              color: HeyBeanTheme.destructive,
              fontSize: 12,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
        if (_placeSuggestions.isNotEmpty) ...[
          const SizedBox(height: 8),
          Container(
            key: const Key('event-location-suggestions'),
            decoration: BoxDecoration(
              color: HeyBeanTheme.surface,
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: HeyBeanTheme.border),
            ),
            child: Column(
              children: [
                for (var index = 0; index < _placeSuggestions.length; index++)
                  _LocationSuggestionTile(
                    suggestion: _placeSuggestions[index],
                    showDivider: index != _placeSuggestions.length - 1,
                    onTap: () => unawaited(
                      _selectPlaceSuggestion(_placeSuggestions[index]),
                    ),
                  ),
              ],
            ),
          ),
        ],
        if (showMap && apiClient != null) ...[
          const SizedBox(height: 10),
          _EventLocationMapPreview(
            apiClient: apiClient,
            place: selectedPlace!,
            onDirections: _openEventDirections,
          ),
        ] else if (_location.text.trim().isNotEmpty) ...[
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const Key('event-directions-button'),
              onPressed: _openEventDirections,
              icon: const Icon(Icons.directions_rounded),
              label: Text(
                _HeyBeanRuntimeServices.preferredMapApp == 'apple'
                    ? 'Open in Apple Maps'
                    : 'Open in Google Maps',
              ),
            ),
          ),
        ],
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: const Key('calendar-event-detail-page'),
      body: Container(
        decoration: BoxDecoration(color: HeyBeanTheme.bg0),
        child: SafeArea(
          child: Column(
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 10),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    IconButton.outlined(
                      key: const Key('event-detail-back-action'),
                      onPressed: () => Navigator.of(context).pop(),
                      icon: Icon(Icons.arrow_back_rounded),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _FormEditorHeader(
                        icon: Icons.calendar_month_rounded,
                        title: 'Event Details',
                        titleKey: const Key('event-detail-header-title'),
                        subtitle: 'Schedule, details, and calendar sync',
                        trailing: IconButton.outlined(
                          key: const Key('event-detail-critical-toggle'),
                          tooltip: _isCritical
                              ? 'Remove critical star'
                              : 'Mark critical',
                          onPressed: _toggleCritical,
                          icon: Icon(
                            _isCritical
                                ? Icons.star_rounded
                                : Icons.star_border_rounded,
                            color: _isCritical
                                ? HeyBeanTheme.warning
                                : HeyBeanTheme.muted,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              Expanded(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 120),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      _MobileFormSection(
                        title: 'Schedule',
                        subtitle: 'Choose exact dates and times.',
                        icon: Icons.schedule_rounded,
                        infoKey: const Key('event-schedule-info'),
                        infoTitle: 'Event schedule',
                        infoBullets: const [
                          'Turn on All day for date-only events with no start or end time.',
                          'Tap a time field to use the date and time picker.',
                          'Use the picker so the app sends an exact date and time.',
                          'End time must be after the start time; leave it blank for a simple reminder-style event.',
                        ],
                        primary: true,
                        children: [
                          TextField(
                            key: const Key('event-title-field'),
                            controller: _title,
                            textInputAction: TextInputAction.next,
                            decoration: const InputDecoration(
                              labelText: 'Event title',
                              prefixIcon: Icon(Icons.title_rounded),
                            ),
                          ),
                          if (_validationError != null)
                            _InlinePlanLimitError(
                              key: const Key('event-validation-error'),
                              message: _validationError!,
                            ),
                          Padding(
                            padding: const EdgeInsets.symmetric(vertical: 2),
                            child: Row(
                              children: [
                                Expanded(
                                  child: GestureDetector(
                                    key: const Key('event-all-day-toggle'),
                                    behavior: HitTestBehavior.opaque,
                                    onTap: () => _setAllDay(!_allDay),
                                    child: Padding(
                                      padding: const EdgeInsets.symmetric(
                                        vertical: 10,
                                      ),
                                      child: Row(
                                        children: [
                                          Icon(
                                            Icons.calendar_today_rounded,
                                            size: 20,
                                            color: HeyBeanTheme.muted,
                                          ),
                                          const SizedBox(width: 10),
                                          Expanded(
                                            child: Text(
                                              'All day',
                                              style: TextStyle(
                                                color: HeyBeanTheme.text,
                                                fontSize: 13,
                                                fontWeight: FontWeight.w600,
                                              ),
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                  ),
                                ),
                                IconButton(
                                  tooltip: 'All day event info',
                                  visualDensity: VisualDensity.compact,
                                  constraints: const BoxConstraints.tightFor(
                                    width: 34,
                                    height: 34,
                                  ),
                                  icon: Icon(
                                    Icons.info_outline_rounded,
                                    semanticLabel: 'All day event info',
                                    size: 18,
                                    color: HeyBeanTheme.muted,
                                  ),
                                  onPressed: () => _showInfoSheet(
                                    context,
                                    title: 'All day events',
                                    bullets: const [
                                      'Use dates instead of specific start and end times.',
                                    ],
                                  ),
                                ),
                                Switch(value: _allDay, onChanged: _setAllDay),
                              ],
                            ),
                          ),
                          TextField(
                            key: const Key('event-start-field'),
                            controller: _startsAt,
                            readOnly: true,
                            onTap: () => _showTimeDock(
                              _startsAt,
                              originalValue: widget.event.startsAt,
                              updateEndFromStart: !_allDay,
                            ),
                            decoration: InputDecoration(
                              labelText: _allDay ? 'Date' : 'Start time',
                              prefixIcon: Icon(
                                _allDay
                                    ? Icons.event_rounded
                                    : Icons.play_arrow_rounded,
                              ),
                              suffixIcon: Icon(Icons.expand_less_rounded),
                            ),
                          ),
                          TextField(
                            key: const Key('event-end-field'),
                            controller: _endsAt,
                            readOnly: true,
                            onTap: () => _showTimeDock(
                              _endsAt,
                              originalValue: widget.event.endsAt,
                              referenceValue: _startsAt.text,
                            ),
                            decoration: InputDecoration(
                              labelText: _allDay ? 'Ends before' : 'End time',
                              prefixIcon: const Icon(Icons.stop_rounded),
                              suffixIcon: const Icon(Icons.expand_less_rounded),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 14),
                      _MobileFormSection(
                        title: 'Event details',
                        subtitle: 'Location, notes, and status',
                        iconWidget: _BeanNotesIcon(
                          size: 18,
                          color: HeyBeanTheme.muted,
                        ),
                        children: [
                          _buildLocationEditor(),
                          DropdownButtonFormField<String>(
                            key: const Key('event-status-field'),
                            initialValue: _status,
                            decoration: const InputDecoration(
                              labelText: 'Status',
                              prefixIcon: Icon(Icons.event_available_rounded),
                            ),
                            items: [
                              for (final status in _statuses)
                                DropdownMenuItem(
                                  value: status.value,
                                  child: Text(status.label),
                                ),
                            ],
                            onChanged: (value) {
                              if (value == null) return;
                              setState(() => _status = value);
                            },
                          ),
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                children: [
                                  Icon(
                                    Icons.notes_rounded,
                                    color: HeyBeanTheme.muted,
                                    size: 18,
                                  ),
                                  const SizedBox(width: 8),
                                  Text(
                                    'Notes',
                                    style: TextStyle(
                                      color: HeyBeanTheme.text,
                                      fontSize: 13,
                                      fontWeight: FontWeight.w600,
                                    ),
                                  ),
                                ],
                              ),
                              const SizedBox(height: 8),
                              TextField(
                                key: const Key('event-notes-field'),
                                controller: _notes,
                                minLines: 3,
                                maxLines: 6,
                                decoration: _longFormInputDecoration(
                                  hintText: 'Add event notes',
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                      const SizedBox(height: 14),
                      _MobileFormSection(
                        title: 'Organize',
                        subtitle: 'Category, color, and workspace',
                        icon: Icons.category_outlined,
                        children: [
                          Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Expanded(
                                child: KeyedSubtree(
                                  key: ValueKey(
                                    'event-category-dropdown-${_category.text.trim().toLowerCase()}',
                                  ),
                                  child: DropdownButtonFormField<String>(
                                    key: const Key('event-category-dropdown'),
                                    initialValue: _category.text.trim().isEmpty
                                        ? ''
                                        : _category.text.trim(),
                                    decoration: const InputDecoration(
                                      labelText: 'Category',
                                      prefixIcon: Icon(Icons.category_outlined),
                                    ),
                                    isExpanded: true,
                                    items: [
                                      const DropdownMenuItem<String>(
                                        key: Key('event-category-none'),
                                        value: '',
                                        child: Text('No category'),
                                      ),
                                      for (final category
                                          in _categoryChipValues)
                                        DropdownMenuItem<String>(
                                          key: Key(
                                            'event-category-option-${_categoryKey(category.name)}',
                                          ),
                                          value: category.name,
                                          child: Row(
                                            mainAxisSize: MainAxisSize.min,
                                            children: [
                                              CircleAvatar(
                                                radius: 6,
                                                backgroundColor: _categoryColor(
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
                                    onChanged: _savingCategory
                                        ? null
                                        : (value) {
                                            final nextValue = value ?? '';
                                            if (nextValue.isEmpty) {
                                              setState(() {
                                                _category.text = '';
                                                _color =
                                                    _themeCategoryColorHex();
                                              });
                                              return;
                                            }
                                            final selected = _categoryChipValues
                                                .where(
                                                  (category) =>
                                                      category.name ==
                                                      nextValue,
                                                )
                                                .firstOrNull;
                                            setState(() {
                                              _category.text = nextValue;
                                              _color =
                                                  selected?.color ?? _color;
                                            });
                                          },
                                  ),
                                ),
                              ),
                              const SizedBox(width: 10),
                              _CreateButton(
                                key: const Key('event-category-add-action'),
                                onPressed: _savingCategory
                                    ? null
                                    : _openCategoryCreationModal,
                                tooltip: 'Create category',
                              ),
                            ],
                          ),
                          Align(
                            alignment: Alignment.centerLeft,
                            child: TextButton.icon(
                              key: const Key('event-category-manager-toggle'),
                              onPressed: _savingCategory
                                  ? null
                                  : () => setState(
                                      () => _showCategoryManager =
                                          !_showCategoryManager,
                                    ),
                              icon: Icon(
                                _showCategoryManager
                                    ? Icons.expand_less_rounded
                                    : Icons.tune_rounded,
                              ),
                              label: Text(
                                _showCategoryManager
                                    ? 'Hide category manager'
                                    : 'Manage categories',
                              ),
                            ),
                          ),
                          if (_showCategoryManager)
                            Wrap(
                              key: const Key('event-category-manager'),
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                for (final category in _categoryChipValues)
                                  _EventCategoryChip(
                                    chipKey: Key(
                                      'event-category-chip-${_categoryKey(category.name)}',
                                    ),
                                    deleteKey: Key(
                                      'event-category-delete-${_categoryKey(category.name)}',
                                    ),
                                    editKey: Key(
                                      'event-category-edit-${_categoryKey(category.name)}',
                                    ),
                                    category: category,
                                    color: _categoryColor(category.color),
                                    selected:
                                        _category.text.trim() == category.name,
                                    saving: _savingCategory,
                                    onSelected: () => _selectCategory(category),
                                    onEdited: () =>
                                        _openCategoryEditModal(category),
                                    onDeleted: () =>
                                        _deleteCategoryValues(category),
                                  ),
                              ],
                            ),
                          if (_category.text.trim().isEmpty) ...[
                            const _EventFieldLabel(
                              icon: Icons.palette_outlined,
                              label: 'Color',
                            ),
                            Wrap(
                              key: const Key('event-no-category-colors'),
                              spacing: 10,
                              runSpacing: 10,
                              children: [
                                for (final color in _colors)
                                  _ColorSwatchButton(
                                    label: color.label,
                                    color: _colorFromHex(color.value),
                                    selected:
                                        _color.toUpperCase() ==
                                        color.value.toUpperCase(),
                                    onTap: () => setState(() {
                                      _color = color.value.toUpperCase();
                                    }),
                                  ),
                              ],
                            ),
                          ],
                        ],
                      ),
                      const SizedBox(height: 14),
                      _MobileFormSection(
                        key: const Key('event-recurrence-field'),
                        title: 'Repeat',
                        subtitle: 'Make this repeat when it should come back',
                        icon: Icons.repeat_rounded,
                        infoKey: const Key('event-recurrence-info'),
                        infoTitle: 'Event recurrence',
                        infoBullets: const [
                          'Choose None for a one-time event.',
                          'Specific days repeats on the weekdays you select.',
                          'Every X lets you build patterns like every 2 weeks or every 3 months.',
                        ],
                        children: [
                          const _EventFieldLabel(
                            icon: Icons.repeat_on_rounded,
                            label: 'Recurrence',
                          ),
                          Text(
                            'Repeat this event when needed.',
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
                              for (final recurrence in _recurrences)
                                ChoiceChip(
                                  label: Text(recurrence.label),
                                  selected: _recurrence == recurrence.value,
                                  onSelected: (_) => setState(() {
                                    _recurrence = recurrence.value;
                                  }),
                                ),
                            ],
                          ),
                          if (_recurrence == 'specific_days') ...[
                            const SizedBox(height: 10),
                            Wrap(
                              key: const Key('event-specific-days'),
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                for (final day in _weekdays)
                                  FilterChip(
                                    label: Text(day.label),
                                    selected: _eventSpecificDays.contains(
                                      day.value,
                                    ),
                                    onSelected: (selected) => setState(() {
                                      if (selected) {
                                        _eventSpecificDays.add(day.value);
                                      } else {
                                        _eventSpecificDays.remove(day.value);
                                      }
                                    }),
                                  ),
                              ],
                            ),
                          ],
                          if (_recurrence == 'interval') ...[
                            const SizedBox(height: 10),
                            Row(
                              key: const Key('event-interval-field'),
                              children: [
                                Expanded(
                                  child: TextField(
                                    controller: _eventInterval,
                                    keyboardType: TextInputType.number,
                                    decoration: const InputDecoration(
                                      labelText: 'Every',
                                      prefixIcon: Icon(Icons.numbers_rounded),
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 10),
                                DropdownButton<String>(
                                  value: _eventIntervalUnit,
                                  items: [
                                    for (final unit in _intervalUnits)
                                      DropdownMenuItem(
                                        value: unit.value,
                                        child: Text(unit.label),
                                      ),
                                  ],
                                  onChanged: (value) => setState(() {
                                    if (value != null) {
                                      _eventIntervalUnit = value;
                                    }
                                  }),
                                ),
                              ],
                            ),
                          ],
                        ],
                      ),
                      if (_eventWorkspaceChoices.isNotEmpty) ...[
                        const SizedBox(height: 14),
                        _MobileFormSection(
                          key: const Key('event-workspace-sync-field'),
                          title: 'Local Workspace Sync',
                          subtitle: _creatingEvent
                              ? 'Choose every workspace this event should be created in.'
                              : 'Copy this event only to selected HeyBean workspaces.',
                          icon: Icons.home_work_outlined,
                          infoKey: const Key('event-workspace-sync-info'),
                          infoTitle: 'Local Workspace Sync',
                          infoBullets: const [
                            'Use this when a Personal event should also appear in a household workspace.',
                            'Sync creates a copy for the selected workspace; future edits remain controlled by Bean.',
                            'Leave everything unchecked to keep the event only in the current workspace.',
                          ],
                          children: [
                            Wrap(
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                for (final workspace in _eventWorkspaceChoices)
                                  FilterChip(
                                    key: Key(
                                      'event-sync-workspace-${workspace.id}',
                                    ),
                                    label: Text(
                                      _workspaceValuesMatch(
                                            _workspaceValue(workspace),
                                            _workspaceValueForId(
                                              widget.workspaces,
                                              widget.activeWorkspaceId,
                                            ),
                                          )
                                          ? '${workspace.isPersonal ? 'Personal' : workspace.name} (current)'
                                          : workspace.isPersonal
                                          ? 'Personal'
                                          : workspace.name,
                                    ),
                                    selected: _eventWorkspaceSelected(
                                      workspace,
                                    ),
                                    onSelected: (selected) =>
                                        _setEventWorkspaceSelected(
                                          workspace,
                                          selected,
                                        ),
                                  ),
                              ],
                            ),
                          ],
                        ),
                      ],
                      const SizedBox(height: 14),
                      _MobileFormSection(
                        title: 'Create reminder',
                        subtitle: _recurrence == 'none'
                            ? 'Optionally remind me before this event.'
                            : 'Optionally remind me before every event in this series.',
                        icon: Icons.notifications_active_outlined,
                        infoKey: const Key('event-reminder-info'),
                        infoTitle: 'Event reminders',
                        infoBullets: const [
                          'Minutes before controls when Bean reminds you before the event starts.',
                          'For repeating events, the reminder follows the event repeat pattern.',
                          'Leave Create reminder off if you do not need a reminder for this event.',
                        ],
                        children: [
                          _MobileFormSwitch(
                            widgetKey: const Key(
                              'event-create-reminder-toggle',
                            ),
                            value: _createEventReminder,
                            onChanged: (value) =>
                                setState(() => _createEventReminder = value),
                            icon: Icons.alarm_rounded,
                            title: 'Create reminder',
                            subtitle: _recurrence == 'none'
                                ? 'Add a reminder before this event starts.'
                                : 'Add a reminder before each occurrence.',
                          ),
                          if (_createEventReminder)
                            DropdownButtonFormField<int>(
                              key: const Key('event-reminder-minutes-field'),
                              initialValue: _reminderMinutesBefore,
                              decoration: const InputDecoration(
                                labelText: 'Remind me',
                                prefixIcon: Icon(Icons.alarm_rounded),
                              ),
                              items: [
                                for (final option in _reminderMinuteOptions)
                                  DropdownMenuItem<int>(
                                    value: option.value,
                                    child: Text(option.label),
                                  ),
                              ],
                              onChanged: (value) {
                                if (value == null) return;
                                setState(() => _reminderMinutesBefore = value);
                              },
                            ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
      bottomNavigationBar: SafeArea(
        top: false,
        child: Container(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 16),
          decoration: BoxDecoration(
            color: HeyBeanTheme.isDark
                ? HeyBeanTheme.surface.withValues(alpha: .97)
                : HeyBeanTheme.surface.withValues(alpha: .97),
            border: Border(
              top: BorderSide(color: _quietBorderColor(alpha: .40)),
            ),
          ),
          child: Row(
            children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: _saving ? null : () => Navigator.of(context).pop(),
                  child: Text('Cancel'),
                ),
              ),
              if (widget.onDelete != null) ...[
                const SizedBox(width: 12),
                IconButton.filled(
                  key: const Key('event-delete-action'),
                  tooltip: 'Delete event',
                  style: _destructiveIconButtonStyle(),
                  onPressed: _saving
                      ? null
                      : () async {
                          final deleteOptions =
                              await _confirmCalendarEventDelete();
                          if (!context.mounted || deleteOptions == null) {
                            return;
                          }
                          Navigator.of(context).pop({
                            'action': 'delete',
                            'deleteFromWorkspaceIds':
                                deleteOptions['deleteFromWorkspaceIds'],
                            'recurringDeleteMode':
                                deleteOptions['recurringDeleteMode'],
                            'recurringOccurrenceDate':
                                deleteOptions['recurringOccurrenceDate'],
                          });
                        },
                  icon: Icon(Icons.delete_outline_rounded),
                ),
              ],
              const SizedBox(width: 12),
              Expanded(
                child: FilledButton.icon(
                  key: const Key('event-save-action'),
                  onPressed: _saving ? null : _save,
                  icon: _saving
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : Icon(Icons.check_rounded),
                  label: Text(_saving ? 'Saving...' : 'Save event'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _LocationSuggestionTile extends StatelessWidget {
  const _LocationSuggestionTile({
    required this.suggestion,
    required this.showDivider,
    required this.onTap,
  });

  final HermesPlaceSuggestion suggestion;
  final bool showDivider;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Column(
    children: [
      ListTile(
        dense: true,
        leading: Icon(Icons.place_outlined, color: HeyBeanTheme.muted),
        title: Text(
          suggestion.primaryText,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(fontWeight: FontWeight.w600),
        ),
        subtitle:
            suggestion.secondaryText == null ||
                suggestion.secondaryText!.trim().isEmpty
            ? null
            : Text(
                suggestion.secondaryText!,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
        onTap: onTap,
      ),
      if (showDivider)
        Divider(height: 1, thickness: 1, color: HeyBeanTheme.border),
    ],
  );
}

class _EventLocationMapPreview extends StatelessWidget {
  const _EventLocationMapPreview({
    required this.apiClient,
    required this.place,
    required this.onDirections,
  });

  final HermesApiClient apiClient;
  final HermesPlaceDetails place;
  final VoidCallback onDirections;

  @override
  Widget build(BuildContext context) {
    final lat = place.latitude;
    final lng = place.longitude;
    if (lat == null || lng == null) return const SizedBox.shrink();
    final mapTheme = Theme.of(context).brightness == Brightness.dark
        ? 'dark'
        : 'light';
    final mapUri = apiClient.resolveApiUri(
      '/places/static-map?lat=${Uri.encodeComponent(lat.toString())}&lng=${Uri.encodeComponent(lng.toString())}&theme=$mapTheme',
    );
    return Container(
      key: const Key('event-location-map-preview'),
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: HeyBeanTheme.surface,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: HeyBeanTheme.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          AspectRatio(
            aspectRatio: 2.45,
            child: Image.network(
              mapUri.toString(),
              headers: apiClient.authenticatedHeaders,
              fit: BoxFit.cover,
              errorBuilder: (context, error, stackTrace) =>
                  const _MapPreviewFallback(),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    place.displayAddress,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: HeyBeanTheme.text,
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                FilledButton.icon(
                  key: const Key('event-directions-button'),
                  onPressed: onDirections,
                  icon: const Icon(Icons.directions_rounded, size: 18),
                  label: Text(
                    _HeyBeanRuntimeServices.preferredMapApp == 'apple'
                        ? 'Apple Maps'
                        : 'Google Maps',
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _MapPreviewFallback extends StatelessWidget {
  const _MapPreviewFallback();

  @override
  Widget build(BuildContext context) => DecoratedBox(
    decoration: BoxDecoration(color: HeyBeanTheme.surface2),
    child: Center(
      child: Icon(Icons.location_pin, color: HeyBeanTheme.muted, size: 34),
    ),
  );
}

class _EventCategoryChip extends StatelessWidget {
  const _EventCategoryChip({
    required this.chipKey,
    required this.deleteKey,
    required this.editKey,
    required this.category,
    required this.color,
    required this.selected,
    required this.saving,
    required this.onSelected,
    required this.onEdited,
    required this.onDeleted,
  });

  final Key chipKey;
  final Key deleteKey;
  final Key editKey;
  final HermesEventCategory category;
  final Color color;
  final bool selected;
  final bool saving;
  final VoidCallback onSelected;
  final VoidCallback onEdited;
  final VoidCallback onDeleted;

  @override
  Widget build(BuildContext context) {
    final backgroundColor = selected
        ? HeyBeanTheme.accent.withValues(alpha: HeyBeanTheme.isDark ? .12 : .08)
        : HeyBeanTheme.surface;
    final borderColor = selected
        ? HeyBeanTheme.accentStrong.withValues(alpha: .46)
        : _quietBorderColor(alpha: .38);
    final textColor = selected ? HeyBeanTheme.text : HeyBeanTheme.text;

    return Material(
      color: backgroundColor,
      shape: StadiumBorder(
        side: BorderSide(color: borderColor, width: selected ? 1.2 : 1),
      ),
      child: Padding(
        padding: const EdgeInsets.only(left: 4, right: 4, top: 4, bottom: 4),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            InkWell(
              key: chipKey,
              borderRadius: BorderRadius.circular(999),
              onTap: saving ? null : onSelected,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    CircleAvatar(radius: 6, backgroundColor: color),
                    const SizedBox(width: 7),
                    Text(
                      category.name,
                      style: TextStyle(
                        color: textColor,
                        fontWeight: selected
                            ? FontWeight.w700
                            : FontWeight.w500,
                      ),
                    ),
                    if (selected) ...[
                      const SizedBox(width: 5),
                      Icon(
                        Icons.check_circle_rounded,
                        size: 15,
                        color: HeyBeanTheme.muted,
                      ),
                    ],
                  ],
                ),
              ),
            ),
            if (category.id >= 0)
              IconButton(
                key: editKey,
                visualDensity: VisualDensity.compact,
                padding: EdgeInsets.zero,
                constraints: const BoxConstraints.tightFor(
                  width: 28,
                  height: 28,
                ),
                tooltip: 'Edit ${category.name}',
                onPressed: saving ? null : onEdited,
                icon: Icon(
                  Icons.edit_rounded,
                  size: 16,
                  color: HeyBeanTheme.muted,
                ),
              ),
            IconButton(
              key: deleteKey,
              visualDensity: VisualDensity.compact,
              padding: EdgeInsets.zero,
              constraints: const BoxConstraints.tightFor(width: 28, height: 28),
              tooltip: 'Delete ${category.name}',
              onPressed: saving ? null : onDeleted,
              icon: Icon(
                Icons.close_rounded,
                size: 18,
                color: saving ? HeyBeanTheme.muted : HeyBeanTheme.destructive,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ColorSwatchButton extends StatelessWidget {
  const _ColorSwatchButton({
    required this.label,
    required this.color,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final Color color;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
    button: true,
    selected: selected,
    label: '$label color',
    child: Material(
      color: Colors.transparent,
      shape: const CircleBorder(),
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: Container(
          width: 36,
          height: 36,
          padding: const EdgeInsets.all(4),
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            border: Border.all(
              color: selected
                  ? HeyBeanTheme.text.withValues(alpha: .62)
                  : _quietBorderColor(alpha: .38),
              width: selected ? 1.4 : 1,
            ),
          ),
          child: DecoratedBox(
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
            child: selected
                ? Icon(Icons.check_rounded, color: Colors.white, size: 16)
                : null,
          ),
        ),
      ),
    ),
  );
}

class _EventCategoryCreateDialog extends StatefulWidget {
  const _EventCategoryCreateDialog({
    required this.initialColor,
    required this.colors,
    this.initialName,
    this.editing = false,
  });

  final String initialColor;
  final String? initialName;
  final bool editing;
  final List<({String value, String label})> colors;

  @override
  State<_EventCategoryCreateDialog> createState() =>
      _EventCategoryCreateDialogState();
}

class _EventCategoryCreateDialogState
    extends State<_EventCategoryCreateDialog> {
  late final TextEditingController _nameController;
  late String _selectedColor;
  late double _hue;
  String? _validationError;

  @override
  void initState() {
    super.initState();
    _nameController = TextEditingController(text: widget.initialName ?? '');
    _selectedColor = widget.initialColor.toUpperCase();
    final hsv = HSVColor.fromColor(_colorFromHex(_selectedColor));
    _hue = hsv.hue;
  }

  @override
  void dispose() {
    _nameController.dispose();
    super.dispose();
  }

  void _selectColor(String color) {
    final normalized = color.toUpperCase();
    final hsv = HSVColor.fromColor(_colorFromHex(normalized));
    setState(() {
      _selectedColor = normalized;
      _hue = hsv.hue;
    });
  }

  void _setHue(double hue) {
    setState(() {
      _hue = hue.clamp(0, 360);
      _selectedColor = _colorHexFromHue(_hue);
    });
  }

  void _submit() {
    final name = _nameController.text.trim();
    if (name.isEmpty) {
      setState(() => _validationError = 'Enter a category name.');
      return;
    }
    Navigator.of(context).pop({'name': name, 'color': _selectedColor});
  }

  @override
  Widget build(BuildContext context) {
    final previewColor = _colorFromHex(_selectedColor);
    return AlertDialog(
      key: const Key('event-category-create-modal'),
      title: Text(widget.editing ? 'Edit category' : 'New category'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            TextField(
              key: const Key('event-category-modal-name-field'),
              controller: _nameController,
              autofocus: true,
              textInputAction: TextInputAction.done,
              onSubmitted: (_) => _submit(),
              decoration: InputDecoration(
                labelText: 'Category name',
                prefixIcon: Icon(Icons.sell_outlined),
                errorText: _validationError,
              ),
            ),
            const SizedBox(height: 14),
            _EventFieldLabel(icon: Icons.palette_outlined, label: 'Color'),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final color in widget.colors)
                  ChoiceChip(
                    label: Text(color.label),
                    selected: _selectedColor == color.value.toUpperCase(),
                    avatar: CircleAvatar(
                      radius: 6,
                      backgroundColor: _colorFromHex(color.value),
                    ),
                    onSelected: (_) => _selectColor(color.value),
                  ),
              ],
            ),
            const SizedBox(height: 16),
            Container(
              key: const Key('event-category-custom-color-preview'),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: previewColor.withValues(alpha: .14),
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: previewColor, width: 1.4),
              ),
              child: Row(
                children: [
                  CircleAvatar(radius: 14, backgroundColor: previewColor),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      _selectedColor,
                      style: TextStyle(
                        color: HeyBeanTheme.text,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 12),
            _RainbowColorSlider(
              value: _hue.clamp(0, 360),
              selectedColor: previewColor,
              onChanged: _setHue,
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text('Cancel'),
        ),
        if (widget.editing)
          FilledButton(
            key: const Key('event-category-modal-save-action'),
            onPressed: _submit,
            child: Text('Save'),
          )
        else
          _CreateButton(
            key: const Key('event-category-modal-save-action'),
            tooltip: 'Create category',
            onPressed: _submit,
          ),
      ],
    );
  }
}

class _RainbowColorSlider extends StatelessWidget {
  const _RainbowColorSlider({
    required this.value,
    required this.selectedColor,
    required this.onChanged,
  });

  final double value;
  final Color selectedColor;
  final ValueChanged<double> onChanged;

  static const double _width = 280;
  static const double _height = 48;
  static const double _horizontalInset = 12;
  static const double _thumbSize = 28;

  static const _gradientColors = <Color>[
    Color(0xFFFF2D2D),
    Color(0xFFFF9500),
    Color(0xFFFFFF00),
    Color(0xFF00E436),
    Color(0xFF00D5FF),
    Color(0xFF1F5BFF),
    Color(0xFF9B00FF),
    Color(0xFFFF00CC),
    Color(0xFFFF2D2D),
  ];

  @override
  Widget build(BuildContext context) {
    final trackWidth = _width - _horizontalInset * 2;
    final left = _horizontalInset + trackWidth * (value.clamp(0, 360) / 360);
    return SizedBox(
      width: _width,
      height: _height,
      child: Stack(
        alignment: Alignment.center,
        children: [
          Positioned.fill(
            left: _horizontalInset,
            right: _horizontalInset,
            child: Center(
              child: Container(
                key: const Key('event-category-color-slider-gradient'),
                height: 8,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(999),
                  gradient: const LinearGradient(colors: _gradientColors),
                ),
              ),
            ),
          ),
          Positioned(
            left: (left - _thumbSize / 2).clamp(0.0, _width - _thumbSize),
            top: 10,
            child: Container(
              key: const Key('event-category-color-slider-thumb'),
              width: _thumbSize,
              height: _thumbSize,
              decoration: BoxDecoration(
                color: selectedColor,
                shape: BoxShape.circle,
                border: Border.all(color: Colors.white, width: 2),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: .12),
                    blurRadius: 8,
                    offset: const Offset(0, 2),
                  ),
                ],
              ),
            ),
          ),
          SliderTheme(
            data: SliderTheme.of(context).copyWith(
              trackHeight: 10,
              activeTrackColor: Colors.transparent,
              inactiveTrackColor: Colors.transparent,
              thumbColor: Colors.transparent,
              overlayColor: selectedColor.withValues(alpha: .10),
              thumbShape: const RoundSliderThumbShape(enabledThumbRadius: 14),
              overlayShape: const RoundSliderOverlayShape(overlayRadius: 20),
            ),
            child: Slider(
              key: const Key('event-category-color-slider'),
              min: 0,
              max: 360,
              divisions: 360,
              value: value.clamp(0, 360),
              onChanged: onChanged,
            ),
          ),
        ],
      ),
    );
  }
}

class _EventFieldLabel extends StatelessWidget {
  const _EventFieldLabel({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Icon(icon, size: 17, color: HeyBeanTheme.muted),
      const SizedBox(width: 8),
      Text(
        label,
        style: Theme.of(context).textTheme.labelLarge?.copyWith(
          color: HeyBeanTheme.text,
          fontWeight: FontWeight.w600,
        ),
      ),
    ],
  );
}

bool _isHexColor(String? value) =>
    value != null && RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(value);

Color _colorFromHex(String value) {
  if (!_isHexColor(value)) {
    return HeyBeanTheme.accentStrong;
  }
  return Color(int.parse('FF${value.substring(1)}', radix: 16));
}

String _colorHexFromHue(double hue) =>
    _hexFromColor(HSVColor.fromAHSV(1, hue.clamp(0, 360), .85, .95).toColor());

String _hexFromColor(Color color) {
  final red = (color.r * 255).round().clamp(0, 255);
  final green = (color.g * 255).round().clamp(0, 255);
  final blue = (color.b * 255).round().clamp(0, 255);
  return '#${red.toRadixString(16).padLeft(2, '0')}'
          '${green.toRadixString(16).padLeft(2, '0')}'
          '${blue.toRadixString(16).padLeft(2, '0')}'
      .toUpperCase();
}

Color _calendarEventColor(HermesCalendarEvent event) {
  final value = event.color;
  if (value == null) return _colorFromHex(_beanGreenCategoryColor);
  return _colorFromHex(value);
}

bool _eventHasNotes(HermesCalendarEvent event) =>
    (event.notes ?? '').trim().isNotEmpty;

HermesPlaceDetails? _placeDetailsFromEvent(HermesCalendarEvent event) {
  final metadata = event.metadata;
  if (metadata == null) return null;
  final placeId =
      _metadataString(metadata, 'place_id') ??
      _metadataString(metadata, 'placeId');
  final lat =
      _metadataDouble(metadata, 'place_lat') ??
      _metadataDouble(metadata, 'placeLat') ??
      _metadataDouble(metadata, 'latitude');
  final lng =
      _metadataDouble(metadata, 'place_lng') ??
      _metadataDouble(metadata, 'placeLng') ??
      _metadataDouble(metadata, 'longitude');
  final address =
      _metadataString(metadata, 'place_formatted_address') ??
      _metadataString(metadata, 'placeFormattedAddress') ??
      event.location;
  if ((placeId == null || placeId.isEmpty) && lat == null && lng == null) {
    return null;
  }
  return HermesPlaceDetails(
    placeId: placeId ?? '',
    formattedAddress: address,
    latitude: lat,
    longitude: lng,
    googleMapsUri:
        _metadataString(metadata, 'google_maps_uri') ??
        _metadataString(metadata, 'googleMapsUri'),
  );
}

String? _metadataString(Map<String, Object?> metadata, String key) {
  final value = metadata[key];
  if (value == null) return null;
  final text = value.toString().trim();
  return text.isEmpty ? null : text;
}

double? _metadataDouble(Map<String, Object?> metadata, String key) {
  final value = metadata[key];
  if (value == null) return null;
  if (value is num) return value.toDouble();
  return double.tryParse(value.toString());
}

Uri _eventDirectionsUri({
  required String address,
  required String preferredMapApp,
  String? googlePlaceId,
}) {
  final normalizedAddress = address.trim();
  if (preferredMapApp == 'apple') {
    return Uri.https('maps.apple.com', '/', {'q': normalizedAddress});
  }
  final query = <String, String>{'api': '1', 'query': normalizedAddress};
  if (googlePlaceId != null && googlePlaceId.trim().isNotEmpty) {
    query['query_place_id'] = googlePlaceId.trim();
  }
  return Uri.https('www.google.com', '/maps/search/', query);
}

String _eventSubtitle(HermesCalendarEvent event) {
  final parts = <String>[
    if (event.startsAt != null || event.endsAt != null)
      _eventDateRangeLabel(startsAt: event.startsAt, endsAt: event.endsAt),
    if (event.category != null && event.category!.isNotEmpty) event.category!,
    if (event.recurrence != null && event.recurrence != 'none')
      event.recurrence!,
  ];
  return parts.isEmpty ? 'Unscheduled' : parts.join(' · ');
}

String _calendarEventBlockKey(HermesCalendarEvent event) {
  final slug = event.title
      .toLowerCase()
      .replaceAll(RegExp(r'[^a-z0-9]+'), '-')
      .replaceAll(RegExp(r'^-+|-+$'), '');
  return 'calendar-event-block-${slug.isEmpty ? event.id : slug}';
}

String _calendarEventBlockKeyForDay(HermesCalendarEvent event, DateTime day) {
  final base = _calendarEventBlockKey(event);
  if (!_eventIsRecurring(event) &&
      !_calendarEventIsGeneratedOccurrence(event)) {
    return base;
  }
  return '$base-${day.year}-${day.month}-${day.day}';
}

List<int> _calendarVisibleHours(int startHour, int endHour) {
  final start = startHour.clamp(0, 22);
  final end = endHour.clamp(start + 1, 23);
  return [for (var hour = start; hour <= end; hour++) hour];
}

List<int> _calendarVisibleHoursForEvents(
  List<HermesCalendarEvent> events,
  DateTime selectedDay,
  int startHour,
  int endHour,
) {
  var start = startHour.clamp(0, 22);
  var end = endHour.clamp(start + 1, 23);
  final days = [
    _dateOnly(selectedDay),
    _dateOnly(selectedDay.add(const Duration(days: 1))),
  ];

  for (final event in events) {
    if (_eventRendersAboveTimeline(event)) continue;
    for (final day in days) {
      final segment = _eventVisibleSegment(event, day, 0, 23);
      if (segment == null) continue;
      final startDecimal = _decimalHoursFromDayStart(segment.start, day);
      final endDecimal = _decimalHoursFromDayStart(segment.end, day);
      final segmentStartHour = startDecimal.floor().clamp(0, 22);
      if (segmentStartHour < start) start = segmentStartHour;
      final segmentEndHour = endDecimal.ceil().clamp(start + 1, 24) - 1;
      final boundedEndHour = segmentEndHour.clamp(start + 1, 23);
      if (boundedEndHour > end) end = boundedEndHour;
    }
  }

  return _calendarVisibleHours(start, end);
}

class _TimelineEventLayout {
  const _TimelineEventLayout({
    required this.event,
    required this.laneIndex,
    required this.laneCount,
  });

  final HermesCalendarEvent event;
  final int laneIndex;
  final int laneCount;
}
