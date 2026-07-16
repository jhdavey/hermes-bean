part of '../../main.dart';

class _CommandCenterContent extends StatelessWidget {
  const _CommandCenterContent({
    required this.apiClient,
    required this.user,
    required this.tasks,
    required this.pastTasks,
    required this.reminders,
    required this.calendar,
    required this.noteFolders,
    required this.notes,
    required this.noteToOpenId,
    required this.eventCategories,
    required this.googleCalendarStatus,
    required this.outlookCalendarStatus,
    required this.dashboardDataLoading,
    required this.selectedDestination,
    required this.selectedCalendarDay,
    required this.showCalendarMonth,
    required this.calendarStartHour,
    required this.calendarEndHour,
    required this.onCalendarDaySelected,
    required this.onCalendarMonthSelected,
    required this.calendarMinimumDay,
    required this.onCalendarHistoryLimitReached,
    required this.onErrorDismissed,
    required this.onBackToCalendarDay,
    required this.onCalendarStartHourChanged,
    required this.onCalendarEndHourChanged,
    required this.onboardingTourTargetKeys,
    required this.allowNotesPreview,
    required this.onTaskCompleted,
    required this.pendingTaskIds,
    required this.onTaskSaved,
    required this.onTaskDeleted,
    required this.onReminderSaved,
    required this.onReminderCompleted,
    required this.onReminderDeleted,
    required this.onCalendarEventCreated,
    required this.onCalendarEventEdited,
    required this.onCalendarEventDeleted,
    required this.onNoteFolderCreated,
    required this.onNoteFolderDeleted,
    required this.onNoteSaved,
    required this.onNoteDeleted,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
    required this.onDeleteAccount,
    required this.onSignOut,
    required this.onAccountEmailChanged,
    required this.onNotificationPreferencesChanged,
    required this.onThemeChanged,
    required this.onThemeModeChanged,
    required this.onCommandCenterLabelChanged,
    required this.onPreferredMapAppChanged,
    required this.launchExternalUrl,
    required this.stripePaymentHandler,
    required this.onBillingChanged,
    required this.onWorkspacesChanged,
    this.error,
  });

  final BeanApiClient apiClient;
  final BeanUser user;
  final List<BeanTask> tasks;
  final List<BeanTask> pastTasks;
  final List<BeanReminder> reminders;
  final List<BeanCalendarEvent> calendar;
  final List<BeanNoteFolder> noteFolders;
  final List<BeanNote> notes;
  final int? noteToOpenId;
  final List<BeanEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final bool dashboardDataLoading;
  final _HomeDestination selectedDestination;
  final DateTime selectedCalendarDay;
  final bool showCalendarMonth;
  final int calendarStartHour;
  final int calendarEndHour;
  final ValueChanged<DateTime> onCalendarDaySelected;
  final ValueChanged<DateTime> onCalendarMonthSelected;
  final DateTime? calendarMinimumDay;
  final VoidCallback onCalendarHistoryLimitReached;
  final VoidCallback onErrorDismissed;
  final VoidCallback onBackToCalendarDay;
  final ValueChanged<int> onCalendarStartHourChanged;
  final ValueChanged<int> onCalendarEndHourChanged;
  final Map<_OnboardingTourTarget, GlobalKey> onboardingTourTargetKeys;
  final bool allowNotesPreview;
  final Future<void> Function(BeanTask task) onTaskCompleted;
  final Set<int> pendingTaskIds;
  final Future<void> Function(
    BeanTask? task, {
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
    BeanTask task, {
    List<Object> deleteFromWorkspaceIds,
  })
  onTaskDeleted;
  final Future<void> Function(
    BeanReminder? reminder, {
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
  final Future<void> Function(BeanReminder reminder) onReminderCompleted;
  final Future<void> Function(
    BeanReminder reminder, {
    List<Object> deleteFromWorkspaceIds,
  })
  onReminderDeleted;
  final Future<void> Function({
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
  })
  onCalendarEventCreated;
  final Future<void> Function(
    BeanCalendarEvent event, {
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
  })
  onCalendarEventEdited;
  final Future<void> Function(
    BeanCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onCalendarEventDeleted;
  final Future<BeanNoteFolder> Function(String name) onNoteFolderCreated;
  final Future<void> Function(BeanNoteFolder folder) onNoteFolderDeleted;
  final Future<BeanNote> Function(
    BeanNote? note, {
    required String title,
    required String bodyHtml,
    required String plainText,
    int? folderId,
    bool clearFolder,
    bool? isPinned,
    Map<String, Object?>? metadata,
    List<Object>? syncToWorkspaceIds,
  })
  onNoteSaved;
  final Future<void> Function(BeanNote note) onNoteDeleted;
  final Future<BeanEventCategory> Function({
    BeanEventCategory? category,
    required String name,
    required String color,
  })
  onEventCategorySaved;
  final Future<void> Function(
    BeanEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onEventCategoryDeleted;
  final Future<void> Function() onDeleteAccount;
  final Future<void> Function() onSignOut;
  final Future<void> Function(String email) onAccountEmailChanged;
  final Future<void> Function(BeanNotificationPreferences preferences)
  onNotificationPreferencesChanged;
  final Future<void> Function(String themeKey) onThemeChanged;
  final Future<void> Function(String themeModeKey) onThemeModeChanged;
  final Future<void> Function(String label) onCommandCenterLabelChanged;
  final Future<void> Function(String preferredMapApp) onPreferredMapAppChanged;
  final ExternalUrlLauncher launchExternalUrl;
  final StripePaymentHandler stripePaymentHandler;
  final Future<void> Function() onBillingChanged;
  final Future<void> Function() onWorkspacesChanged;
  final String? error;

  @override
  Widget build(BuildContext context) {
    final calendarTasks = showCalendarMonth
        ? _tasksForMonthAgenda(tasks, selectedCalendarDay)
        : _tasksForTodayAgenda(tasks, DateTime.now());
    final noteLimit = user.planLimits.noteLimit;
    final notesEnabled =
        allowNotesPreview ||
        user.isAdmin ||
        user.planLimits.notesEnabled ||
        noteLimit == null ||
        noteLimit > 0;
    final selectedPanel = switch (selectedDestination) {
      _HomeDestination.today => _TodayHomeView(
        user: user,
        tasks: calendarTasks,
        calendar: calendar,
        loading: dashboardDataLoading,
        eventCategories: eventCategories,
        googleCalendarStatus: googleCalendarStatus,
        outlookCalendarStatus: outlookCalendarStatus,
        selectedDay: selectedCalendarDay,
        showMonth: showCalendarMonth,
        startHour: calendarStartHour,
        endHour: calendarEndHour,
        calendarMinimumDay: calendarMinimumDay,
        onCalendarHistoryLimitReached: onCalendarHistoryLimitReached,
        onDateSelected: onCalendarDaySelected,
        onMonthSelected: onCalendarMonthSelected,
        onBackToDay: onBackToCalendarDay,
        onTaskCompleted: onTaskCompleted,
        onTaskSaved: onTaskSaved,
        onTaskDeleted: onTaskDeleted,
        onCalendarEventCreated: onCalendarEventCreated,
        onCalendarEventEdited: onCalendarEventEdited,
        onCalendarEventDeleted: onCalendarEventDeleted,
        onEventCategorySaved: onEventCategorySaved,
        onEventCategoryDeleted: onEventCategoryDeleted,
      ),
      _HomeDestination.tasks => KeyedSubtree(
        key: onboardingTourTargetKeys[_OnboardingTourTarget.tasksView],
        child: _TaskListCard(
          tasks: tasks,
          pastTasks: pastTasks,
          loading: dashboardDataLoading,
          eventCategories: eventCategories,
          pendingTaskIds: pendingTaskIds,
          onTaskCompleted: onTaskCompleted,
          onTaskSaved: onTaskSaved,
          onTaskDeleted: onTaskDeleted,
          onEventCategorySaved: onEventCategorySaved,
          workspaces: user.workspaces,
          activeWorkspaceId: user.activeWorkspace?.id,
        ),
      ),
      _HomeDestination.commandCenter => _CommandCenterHome(
        tasks: tasks,
        reminders: reminders,
        calendar: calendar,
        loading: dashboardDataLoading,
        agendaPanelKey:
            onboardingTourTargetKeys[_OnboardingTourTarget.commandCenterAgenda],
        eventCategories: eventCategories,
        googleCalendarStatus: googleCalendarStatus,
        outlookCalendarStatus: outlookCalendarStatus,
        workspaces: user.workspaces,
        activeWorkspaceId: user.activeWorkspace?.id,
        onTaskSaved: onTaskSaved,
        onTaskDeleted: onTaskDeleted,
        onReminderSaved: onReminderSaved,
        onReminderDeleted: onReminderDeleted,
        onCalendarEventEdited: onCalendarEventEdited,
        onCalendarEventDeleted: onCalendarEventDeleted,
        onEventCategorySaved: onEventCategorySaved,
        onEventCategoryDeleted: onEventCategoryDeleted,
      ),
      _HomeDestination.reminders => KeyedSubtree(
        key: onboardingTourTargetKeys[_OnboardingTourTarget.remindersView],
        child: _ReminderListCard(
          reminders: reminders,
          loading: dashboardDataLoading,
          eventCategories: eventCategories,
          onReminderSaved: onReminderSaved,
          onReminderCompleted: onReminderCompleted,
          onReminderDeleted: onReminderDeleted,
          onEventCategorySaved: onEventCategorySaved,
          workspaces: user.workspaces,
          activeWorkspaceId: user.activeWorkspace?.id,
        ),
      ),
      _HomeDestination.notes =>
        notesEnabled
            ? KeyedSubtree(
                key: onboardingTourTargetKeys[_OnboardingTourTarget.notesView],
                child: _NotesView(
                  folders: noteFolders,
                  notes: notes,
                  workspaces: user.workspaces,
                  activeWorkspaceId: user.activeWorkspace?.id,
                  openNoteId: noteToOpenId,
                  onFolderCreated: onNoteFolderCreated,
                  onFolderDeleted: onNoteFolderDeleted,
                  onNoteSaved: onNoteSaved,
                  onNoteDeleted: onNoteDeleted,
                ),
              )
            : _PlanLimitErrorBanner(
                message: 'Notes are available on this plan after upgrading.',
                launchExternalUrl: launchExternalUrl,
              ),
      _HomeDestination.settings => _SettingsView(
        apiClient: apiClient,
        launchExternalUrl: launchExternalUrl,
        stripePaymentHandler: stripePaymentHandler,
        user: user,
        onBillingChanged: onBillingChanged,
        googleCalendarStatus: googleCalendarStatus,
        calendarStartHour: calendarStartHour,
        calendarEndHour: calendarEndHour,
        onCalendarStartHourChanged: onCalendarStartHourChanged,
        onCalendarEndHourChanged: onCalendarEndHourChanged,
        onDeleteAccount: onDeleteAccount,
        onSignOut: onSignOut,
        onAccountEmailChanged: onAccountEmailChanged,
        onNotificationPreferencesChanged: onNotificationPreferencesChanged,
        onThemeChanged: onThemeChanged,
        onThemeModeChanged: onThemeModeChanged,
        onCommandCenterLabelChanged: onCommandCenterLabelChanged,
        onPreferredMapAppChanged: onPreferredMapAppChanged,
        onWorkspacesChanged: onWorkspacesChanged,
        error: error,
        onErrorDismissed: onErrorDismissed,
      ),
    };
    final limitBanner = _isPlanLimitMessage(error)
        ? _PlanLimitErrorBanner(
            message: error,
            launchExternalUrl: launchExternalUrl,
            onDismissed: onErrorDismissed,
          )
        : null;
    final inlineError = error != null && !_isPlanLimitMessage(error)
        ? _InlinePlanLimitError(message: error!, onDismissed: onErrorDismissed)
        : null;
    final fullHeight =
        selectedDestination == _HomeDestination.commandCenter ||
        selectedDestination == _HomeDestination.notes;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: fullHeight ? MainAxisSize.max : MainAxisSize.min,
      children: [
        if (limitBanner != null &&
            selectedDestination != _HomeDestination.settings) ...[
          limitBanner,
          const SizedBox(height: 12),
        ],
        if (inlineError != null &&
            selectedDestination != _HomeDestination.settings) ...[
          inlineError,
          const SizedBox(height: 12),
        ],
        if (fullHeight) Expanded(child: selectedPanel) else selectedPanel,
      ],
    );
  }
}
