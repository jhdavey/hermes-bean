part of '../../main.dart';

enum _AuthPhase {
  loading,
  signedOut,
  guidedOnboarding,
  planSelection,
  signedIn,
}

enum _HomeDestination {
  today,
  tasks,
  commandCenter,
  reminders,
  notes,
  settings,
}

const _dashboardChangePollInterval = Duration(seconds: 3);
const _pendingCalendarEventWriteTtl = Duration(minutes: 2);
const _pendingDashboardWriteTtl = Duration(minutes: 2);
const _onboardingTourSeenPreferencePrefix = 'heybean.onboarding_tour_seen';
const _dashboardSnapshotPreferencePrefix = 'heybean.dashboard_snapshot.v2';

class _DashboardSnapshot {
  const _DashboardSnapshot({
    required this.tasks,
    required this.pastTasks,
    required this.reminders,
    required this.calendar,
    required this.noteFolders,
    required this.notes,
    required this.eventCategories,
    this.googleCalendarStatus,
    this.outlookCalendarStatus,
  });

  final List<BeanTask> tasks;
  final List<BeanTask> pastTasks;
  final List<BeanReminder> reminders;
  final List<BeanCalendarEvent> calendar;
  final List<BeanNoteFolder> noteFolders;
  final List<BeanNote> notes;
  final List<BeanEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
}

List<Map<String, Object?>> _snapshotObjectList(Object? raw) {
  if (raw is! List) return const [];
  return raw
      .whereType<Map>()
      .map((item) => Map<String, Object?>.from(item))
      .toList(growable: false);
}

Map<String, Object?> _taskCacheJson(BeanTask task) => {
  'id': task.id,
  'title': task.title,
  'status': task.status,
  'due_at': task.dueAt,
  'notes': task.notes,
  'category': task.category,
  'color': task.color,
  'is_critical': task.isCritical,
  'completed_at': task.completedAt,
  'metadata': task.metadata,
  'workspace_id': task.workspaceId,
  'linked_workspace_ids': task.linkedWorkspaceIds,
};

Map<String, Object?> _reminderCacheJson(BeanReminder reminder) => {
  'id': reminder.id,
  'title': reminder.title,
  'due_at': reminder.dueAt,
  'category': reminder.category,
  'color': reminder.color,
  'is_critical': reminder.isCritical,
  'status': reminder.status,
  'completed_at': reminder.completedAt,
  'calendar_event_id': reminder.calendarEventId,
  'metadata': reminder.metadata,
  'workspace_id': reminder.workspaceId,
  'linked_workspace_ids': reminder.linkedWorkspaceIds,
};

Map<String, Object?> _calendarEventCacheJson(BeanCalendarEvent event) => {
  'id': event.id,
  'title': event.title,
  'workspace_id': event.workspaceId,
  'linked_workspace_ids': event.linkedWorkspaceIds,
  'starts_at': event.startsAt,
  'ends_at': event.endsAt,
  'description': event.notes,
  'location': event.location,
  'status': event.status,
  'category': event.category,
  'color': event.color,
  'is_critical': event.isCritical,
  'recurrence': event.recurrence,
  'metadata': event.metadata,
};

Map<String, Object?> _eventCategoryCacheJson(BeanEventCategory category) => {
  'id': category.id,
  'name': category.name,
  'color': category.color,
  'workspace_id': category.workspaceId,
  'linked_workspace_ids': category.linkedWorkspaceIds,
};

Map<String, Object?> _dashboardSnapshotCacheJson(_DashboardSnapshot snapshot) =>
    {
      'cached_at': DateTime.now().toIso8601String(),
      'tasks': snapshot.tasks.map(_taskCacheJson).toList(),
      'past_tasks': snapshot.pastTasks.map(_taskCacheJson).toList(),
      'reminders': snapshot.reminders.map(_reminderCacheJson).toList(),
      'calendar': snapshot.calendar.map(_calendarEventCacheJson).toList(),
      'event_categories': snapshot.eventCategories
          .map(_eventCategoryCacheJson)
          .toList(),
    };

_DashboardSnapshot _dashboardSnapshotFromCache(Map<String, Object?> json) =>
    _DashboardSnapshot(
      tasks: _snapshotObjectList(
        json['tasks'],
      ).map(BeanTask.fromJson).toList(growable: false),
      pastTasks: _snapshotObjectList(
        json['past_tasks'],
      ).map(BeanTask.fromJson).toList(growable: false),
      reminders: _snapshotObjectList(
        json['reminders'],
      ).map(BeanReminder.fromJson).toList(growable: false),
      calendar: _snapshotObjectList(
        json['calendar'],
      ).map(BeanCalendarEvent.fromJson).toList(growable: false),
      noteFolders: const [],
      notes: const [],
      eventCategories: _snapshotObjectList(
        json['event_categories'],
      ).map(BeanEventCategory.fromJson).toList(growable: false),
    );

class _PendingCalendarEventWrite {
  const _PendingCalendarEventWrite({
    this.event,
    required this.expiresAt,
    required this.workspaceId,
    required this.mutationVersion,
    this.deleted = false,
  });

  final BeanCalendarEvent? event;
  final DateTime expiresAt;
  final int? workspaceId;
  final int mutationVersion;
  final bool deleted;
}

class _PendingTaskWrite {
  const _PendingTaskWrite({
    this.task,
    required this.expiresAt,
    required this.workspaceId,
    required this.mutationVersion,
    this.deleted = false,
  });

  final BeanTask? task;
  final DateTime expiresAt;
  final int? workspaceId;
  final int mutationVersion;
  final bool deleted;
}

class _PendingReminderWrite {
  const _PendingReminderWrite({
    this.reminder,
    required this.expiresAt,
    required this.workspaceId,
    required this.mutationVersion,
    this.deleted = false,
  });

  final BeanReminder? reminder;
  final DateTime expiresAt;
  final int? workspaceId;
  final int mutationVersion;
  final bool deleted;
}

class _PendingNoteSave {
  const _PendingNoteSave({
    required this.note,
    required this.version,
    required this.title,
    required this.bodyHtml,
    required this.plainText,
    required this.folderId,
    required this.clearFolder,
    required this.isPinned,
    required this.metadata,
    required this.syncToWorkspaceIds,
  });

  final BeanNote note;
  final int version;
  final String title;
  final String bodyHtml;
  final String plainText;
  final int? folderId;
  final bool clearFolder;
  final bool? isPinned;
  final Map<String, Object?>? metadata;
  final List<Object>? syncToWorkspaceIds;
}
