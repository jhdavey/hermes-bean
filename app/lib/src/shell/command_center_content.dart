part of '../../main.dart';

String? _settingsErrorForDisplay(String? error) {
  if (error == null) return null;
  final normalized = error.trim().toLowerCase();
  if (normalized.startsWith('bean is paused') ||
      normalized.startsWith('bean hit a snag') ||
      normalized.startsWith('bean could not') ||
      normalized.startsWith('bean lost')) {
    return null;
  }
  return error;
}

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
    required this.memoryItems,
    required this.memorySummaries,
    required this.memoryHistory,
    required this.eventCategories,
    required this.googleCalendarStatus,
    required this.outlookCalendarStatus,
    required this.events,
    required this.messages,
    required this.busy,
    required this.dashboardDataLoading,
    required this.chatRunState,
    required this.chatInputController,
    required this.chatInputFocusNode,
    required this.onChatMessageCopied,
    required this.onChatMessageEdited,
    required this.beanChatCollapsed,
    required this.onBeanChatCollapsedChanged,
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
    required this.onSelectDestination,
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
    required this.onMemoryRefresh,
    required this.onMemoryCreated,
    required this.onMemoryUpdated,
    required this.onMemoryDeleted,
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
    required this.onEditAgentOnboarding,
    required this.onWorkspacesChanged,
    this.error,
  });

  final HermesApiClient apiClient;
  final HermesUser user;
  final List<HermesTask> tasks;
  final List<HermesTask> pastTasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendar;
  final List<HermesNoteFolder> noteFolders;
  final List<HermesNote> notes;
  final int? noteToOpenId;
  final List<HermesMemoryItem> memoryItems;
  final List<HermesMemorySummary> memorySummaries;
  final List<HermesRequestHistoryItem> memoryHistory;
  final List<HermesEventCategory> eventCategories;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final GoogleCalendarSyncStatus? outlookCalendarStatus;
  final List<HermesActivityEvent> events;
  final List<HermesMessage> messages;
  final bool busy;
  final bool dashboardDataLoading;
  final String chatRunState;
  final TextEditingController chatInputController;
  final FocusNode chatInputFocusNode;
  final Future<void> Function(HermesMessage message) onChatMessageCopied;
  final ValueChanged<HermesMessage> onChatMessageEdited;
  final bool beanChatCollapsed;
  final ValueChanged<bool> onBeanChatCollapsedChanged;
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
  final ValueChanged<_HomeDestination> onSelectDestination;
  final Map<_OnboardingTourTarget, GlobalKey> onboardingTourTargetKeys;
  final bool allowNotesPreview;
  final Future<void> Function(HermesTask task) onTaskCompleted;
  final Set<int> pendingTaskIds;
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
  final Future<void> Function({
    required String title,
    required String startsAt,
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
    HermesCalendarEvent event, {
    required String title,
    required String startsAt,
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
    HermesCalendarEvent event, {
    List<Object> deleteFromWorkspaceIds,
  })
  onCalendarEventDeleted;
  final Future<HermesNoteFolder> Function(String name) onNoteFolderCreated;
  final Future<void> Function(HermesNoteFolder folder) onNoteFolderDeleted;
  final Future<HermesNote> Function(
    HermesNote? note, {
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
  final Future<void> Function(HermesNote note) onNoteDeleted;
  final Future<void> Function() onMemoryRefresh;
  final Future<HermesMemoryItem> Function({
    required String content,
    String type,
    String? title,
  })
  onMemoryCreated;
  final Future<HermesMemoryItem> Function(
    HermesMemoryItem item, {
    required String content,
    required String type,
    String? title,
  })
  onMemoryUpdated;
  final Future<void> Function(HermesMemoryItem item) onMemoryDeleted;
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
  final Future<void> Function() onDeleteAccount;
  final Future<void> Function() onSignOut;
  final Future<void> Function(String email) onAccountEmailChanged;
  final Future<void> Function(HermesNotificationPreferences preferences)
  onNotificationPreferencesChanged;
  final Future<void> Function(String themeKey) onThemeChanged;
  final Future<void> Function(String themeModeKey) onThemeModeChanged;
  final Future<void> Function(String label) onCommandCenterLabelChanged;
  final Future<void> Function(String preferredMapApp) onPreferredMapAppChanged;
  final ExternalUrlLauncher launchExternalUrl;
  final StripePaymentHandler stripePaymentHandler;
  final Future<void> Function() onBillingChanged;
  final VoidCallback onEditAgentOnboarding;
  final Future<void> Function() onWorkspacesChanged;
  final String? error;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final activeTasks = _visibleSortedTasks(tasks);
        final calendarTasks = showCalendarMonth
            ? _tasksForMonthAgenda(tasks, selectedCalendarDay)
            : _tasksForTodayAgenda(tasks, DateTime.now());
        final beanPanel = _HeroChatCard(
          messages: messages,
          busy: busy,
          runState: chatRunState,
          inputController: chatInputController,
          inputFocusNode: chatInputFocusNode,
          onMessageCopied: onChatMessageCopied,
          onMessageEdited: onChatMessageEdited,
        );
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
          _HomeDestination.bean => _CommandCenterHome(
            tasks: tasks,
            reminders: reminders,
            calendar: calendar,
            loading: dashboardDataLoading,
            chat: beanPanel,
            agendaPanelKey:
                onboardingTourTargetKeys[_OnboardingTourTarget
                    .commandCenterAgenda],
            chatPanelKey:
                onboardingTourTargetKeys[_OnboardingTourTarget
                    .commandCenterChat],
            chatCollapsed: beanChatCollapsed,
            onChatCollapsedChanged: onBeanChatCollapsedChanged,
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
                    key:
                        onboardingTourTargetKeys[_OnboardingTourTarget
                            .notesView],
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
                    message:
                        'Notes are available on this plan after upgrading.',
                    launchExternalUrl: launchExternalUrl,
                  ),
          _HomeDestination.memory => _MemoryView(
            items: memoryItems,
            summaries: memorySummaries,
            history: memoryHistory,
            onRefresh: onMemoryRefresh,
            onCreated: onMemoryCreated,
            onUpdated: onMemoryUpdated,
            onDeleted: onMemoryDeleted,
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
            onEditAgentOnboarding: onEditAgentOnboarding,
            onWorkspacesChanged: onWorkspacesChanged,
            error: _settingsErrorForDisplay(error),
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
            ? _InlinePlanLimitError(
                message: error!,
                onDismissed: onErrorDismissed,
              )
            : null;
        final panelChildren = <Widget>[
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
          if (selectedDestination == _HomeDestination.bean ||
              selectedDestination == _HomeDestination.notes)
            Expanded(child: selectedPanel)
          else
            selectedPanel,
        ];
        final panelNeedsFullHeight =
            selectedDestination == _HomeDestination.bean ||
            selectedDestination == _HomeDestination.notes;
        final selectedPanelWithStatus =
            panelChildren.length == 1 &&
                panelChildren.single == selectedPanel &&
                !panelNeedsFullHeight
            ? selectedPanel
            : Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: panelNeedsFullHeight
                    ? MainAxisSize.max
                    : MainAxisSize.min,
                children: panelChildren,
              );
        final right = Column(
          children: [
            _AccountCard(
              onDeleteAccount: onDeleteAccount,
              onSignOut: onSignOut,
              launchExternalUrl: launchExternalUrl,
            ),
            const SizedBox(height: 16),
            _ProgressCard(
              user: user,
              error: error,
              taskCount: activeTasks.length,
            ),
            const SizedBox(height: 16),
            _ActivityCard(events: events),
            const SizedBox(height: 16),
            _ShellCard(
              child: _CalendarAgenda(
                calendar: calendar,
                eventCategories: eventCategories,
                googleCalendarStatus: googleCalendarStatus,
                outlookCalendarStatus: outlookCalendarStatus,
                workspaces: user.workspaces,
                activeWorkspaceId: user.activeWorkspace?.id,
                onEventTap: onCalendarEventEdited,
                onEventCategorySaved: onEventCategorySaved,
                onEventCategoryDeleted: onEventCategoryDeleted,
              ),
            ),
          ],
        );
        if (constraints.maxWidth < 900 ||
            selectedDestination != _HomeDestination.bean) {
          return selectedPanelWithStatus;
        }
        // The Bean chat tab owns the full screen; activity/approvals live inside
        // its top menu and bottom approval dock instead of side dashboard cards.
        if (selectedDestination == _HomeDestination.bean) {
          return selectedPanelWithStatus;
        }
        return Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(flex: 7, child: beanPanel),
            const SizedBox(width: 16),
            Expanded(flex: 5, child: right),
          ],
        );
      },
    );
  }
}
