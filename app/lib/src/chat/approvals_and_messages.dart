part of '../../main.dart';

typedef _ApprovalAction = Future<void> Function(HermesApproval approval);
typedef _ApprovalChangeAction =
    Future<void> Function(HermesApproval approval, String revisedRequest);

class _ApprovalRequestSheet extends StatefulWidget {
  const _ApprovalRequestSheet({
    required this.approval,
    required this.onApprove,
    required this.onAlwaysApprove,
    required this.onDeny,
    required this.onChange,
  });

  final HermesApproval approval;
  final _ApprovalAction onApprove;
  final _ApprovalAction onAlwaysApprove;
  final _ApprovalAction onDeny;
  final _ApprovalChangeAction onChange;

  @override
  State<_ApprovalRequestSheet> createState() => _ApprovalRequestSheetState();
}

class _ApprovalRequestSheetState extends State<_ApprovalRequestSheet> {
  final TextEditingController _changeController = TextEditingController();
  bool _changing = false;
  bool _busy = false;

  @override
  void dispose() {
    _changeController.dispose();
    super.dispose();
  }

  Future<void> _run(Future<void> Function() action) async {
    setState(() => _busy = true);
    try {
      await action();
      if (mounted) Navigator.of(context).pop();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.viewInsetsOf(context).bottom;
    final approval = widget.approval;
    final actionDescription = _approvalActionDescription(approval);

    return Padding(
      padding: EdgeInsets.only(bottom: bottomInset),
      child: Container(
        key: const Key('global-approval-bottom-sheet'),
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
        decoration: BoxDecoration(
          color: HeyBeanTheme.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
          boxShadow: [
            BoxShadow(
              color: Color(0x26000000),
              blurRadius: 30,
              offset: Offset(0, -12),
            ),
          ],
        ),
        child: SafeArea(
          top: false,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 42,
                  height: 4,
                  decoration: BoxDecoration(
                    color: HeyBeanTheme.border,
                    borderRadius: BorderRadius.circular(999),
                  ),
                ),
              ),
              const SizedBox(height: 18),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 42,
                    height: 42,
                    decoration: BoxDecoration(
                      color: HeyBeanTheme.warning.withValues(alpha: .14),
                      borderRadius: BorderRadius.circular(16),
                    ),
                    child: Icon(
                      Icons.verified_user_rounded,
                      color: HeyBeanTheme.warning,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'I need approval',
                          style: Theme.of(context).textTheme.titleLarge
                              ?.copyWith(fontWeight: FontWeight.w900),
                        ),
                        const SizedBox(height: 3),
                        Text(
                          "Approve or deny Bean's next action",
                          style: TextStyle(
                            color: HeyBeanTheme.muted,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: const Color(0xFFFFFBEB),
                  borderRadius: BorderRadius.circular(18),
                  border: Border.all(
                    color: HeyBeanTheme.warning.withValues(alpha: .28),
                  ),
                ),
                child: Text(
                  actionDescription,
                  key: const Key('approval-action-description'),
                  style: TextStyle(
                    height: 1.35,
                    fontWeight: FontWeight.w700,
                    color: HeyBeanTheme.text,
                  ),
                ),
              ),
              const SizedBox(height: 14),
              if (_changing) ...[
                TextField(
                  key: const Key('approval-change-input'),
                  controller: _changeController,
                  minLines: 2,
                  maxLines: 4,
                  enabled: !_busy,
                  autofocus: true,
                  decoration: _longFormInputDecoration(
                    labelText: 'Change Bean’s instruction',
                    hintText: 'Tell Bean what to do instead…',
                  ),
                ),
                const SizedBox(height: 10),
              ],
              Wrap(
                spacing: 8,
                runSpacing: 8,
                alignment: WrapAlignment.end,
                children: [
                  TextButton(
                    key: const Key('approval-deny-action'),
                    onPressed: _busy
                        ? null
                        : () => _run(() => widget.onDeny(approval)),
                    child: Text('Deny'),
                  ),
                  OutlinedButton(
                    key: const Key('approval-change-action'),
                    onPressed: _busy
                        ? null
                        : () {
                            if (!_changing) {
                              setState(() => _changing = true);
                              return;
                            }
                            final revised = _changeController.text.trim();
                            if (revised.isEmpty) return;
                            _run(() => widget.onChange(approval, revised));
                          },
                    child: Text(_changing ? 'Send change' : 'Change'),
                  ),
                  OutlinedButton(
                    key: const Key('approval-always-approve-action'),
                    onPressed: _busy
                        ? null
                        : () => _run(() => widget.onAlwaysApprove(approval)),
                    child: Text('Always approve'),
                  ),
                  FilledButton(
                    key: const Key('approval-approve-action'),
                    onPressed: _busy
                        ? null
                        : () => _run(() => widget.onApprove(approval)),
                    child: _busy
                        ? const SizedBox.square(
                            dimension: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Text('Approve'),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

String _approvalActionDescription(HermesApproval approval) {
  final action = approval.payload['action'];
  final actionMap = action is Map
      ? action.map((key, value) => MapEntry(key.toString(), value))
      : const <String, Object?>{};
  final type = (actionMap['type'] ?? approval.title).toString();
  final risk = (actionMap['risk'] ?? 'unknown').toString().toLowerCase();
  final description = approval.description?.trim();
  if (description != null && description.isNotEmpty) {
    return '$description This action is marked $risk risk.';
  }

  return 'Bean wants to ${type.replaceAll('.', ' ')}. This action is marked $risk risk.';
}

String _compactBeanStatusLabel(String value) {
  final raw = value.trim();
  if (raw.isEmpty) return 'Ready';
  final lower = raw.toLowerCase();
  if (lower.contains("unknown parameter: 'response.modalities'") ||
      lower.contains('unknown parameter: response.modalities')) {
    return 'Bean chat issue';
  }
  return switch (lower) {
    'failed' => 'Failed',
    'blocked' => 'Blocked',
    'stopped' => 'Stopped',
    'updated' => 'Updated',
    _ => raw.length > 44 ? '${raw.substring(0, 41)}...' : raw,
  };
}

// ignore: unused_element
class _QuickPromptRail extends StatelessWidget {
  const _QuickPromptRail({required this.onPrompt});

  final Future<void> Function(String content) onPrompt;

  @override
  Widget build(BuildContext context) {
    const prompts = <({IconData icon, String label, String prompt, Key key})>[
      (
        icon: Icons.today_rounded,
        label: 'Plan today',
        prompt: 'Help me plan today',
        key: Key('quick-plan-today'),
      ),
      (
        icon: Icons.task_alt_rounded,
        label: 'Add task',
        prompt: 'Add a task',
        key: Key('quick-add-task'),
      ),
      (
        icon: Icons.notifications_active_rounded,
        label: 'Set reminder',
        prompt: 'Set a reminder',
        key: Key('quick-set-reminder'),
      ),
      (
        icon: Icons.calendar_month_rounded,
        label: 'Schedule event',
        prompt: 'Schedule an event',
        key: Key('quick-schedule-event'),
      ),
    ];

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final prompt in prompts)
          ActionChip(
            key: prompt.key,
            avatar: Icon(
              prompt.icon,
              size: 16,
              color: HeyBeanTheme.accentStrong,
            ),
            label: Text(prompt.label),
            onPressed: () => onPrompt(prompt.prompt),
            backgroundColor: HeyBeanTheme.accent.withValues(alpha: .08),
            side: BorderSide(color: HeyBeanTheme.border),
            labelStyle: TextStyle(
              color: HeyBeanTheme.text,
              fontWeight: FontWeight.w700,
            ),
          ),
      ],
    );
  }
}

enum _SentMessageAction { copy, edit }

class _MessageBubble extends StatelessWidget {
  const _MessageBubble({
    required this.sender,
    required this.message,
    this.alignRight = false,
    this.onCopy,
    this.onEdit,
  });

  final String sender;
  final String message;
  final bool alignRight;
  final VoidCallback? onCopy;
  final VoidCallback? onEdit;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) {
      final hasActions = onCopy != null || onEdit != null;
      final availableWidth = constraints.hasBoundedWidth
          ? constraints.maxWidth
          : MediaQuery.sizeOf(context).width;
      final bubbleWidth = math.min(math.max(availableWidth, 0.0), 560.0);
      final bubble = SizedBox(
        width: bubbleWidth,
        child: Container(
          key: alignRight ? const Key('user-message-bubble') : null,
          padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 8),
          decoration: BoxDecoration(
            color: alignRight
                ? HeyBeanTheme.accent.withValues(alpha: .08)
                : _quietMutedSurfaceColor(alpha: .54),
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: _quietBorderColor(alpha: .38)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      sender,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: HeyBeanTheme.muted,
                        fontSize: 13,
                        height: 1.12,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                  if (hasActions)
                    Builder(
                      builder: (buttonContext) => IconButton(
                        key: const Key('sent-message-actions-trigger'),
                        tooltip: 'Message actions',
                        visualDensity: VisualDensity.compact,
                        padding: EdgeInsets.zero,
                        constraints: const BoxConstraints.tightFor(
                          width: 40,
                          height: 32,
                        ),
                        icon: Icon(
                          Icons.more_horiz_rounded,
                          size: 18,
                          color: HeyBeanTheme.muted,
                        ),
                        onPressed: () async {
                          ScaffoldMessenger.of(context).hideCurrentSnackBar();
                          final overlay =
                              Overlay.of(
                                    buttonContext,
                                  ).context.findRenderObject()
                                  as RenderBox;
                          final button =
                              buttonContext.findRenderObject() as RenderBox;
                          final topLeft = button.localToGlobal(
                            Offset.zero,
                            ancestor: overlay,
                          );
                          final bottomRight = button.localToGlobal(
                            button.size.bottomRight(Offset.zero),
                            ancestor: overlay,
                          );
                          final action = await showMenu<_SentMessageAction>(
                            context: buttonContext,
                            position: RelativeRect.fromRect(
                              Rect.fromPoints(topLeft, bottomRight),
                              Offset.zero & overlay.size,
                            ),
                            items: [
                              if (onCopy != null)
                                const PopupMenuItem<_SentMessageAction>(
                                  key: Key('chat-copy-sent-message-action'),
                                  value: _SentMessageAction.copy,
                                  child: ListTile(
                                    dense: true,
                                    leading: Icon(Icons.copy_rounded),
                                    title: Text('Copy'),
                                  ),
                                ),
                              if (onEdit != null)
                                const PopupMenuItem<_SentMessageAction>(
                                  key: Key('chat-edit-sent-message-action'),
                                  value: _SentMessageAction.edit,
                                  child: ListTile(
                                    dense: true,
                                    leading: Icon(Icons.edit_rounded),
                                    title: Text('Edit'),
                                  ),
                                ),
                            ],
                          );
                          switch (action) {
                            case _SentMessageAction.copy:
                              onCopy?.call();
                            case _SentMessageAction.edit:
                              onEdit?.call();
                            case null:
                              break;
                          }
                        },
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 2),
              Text(
                message,
                style: TextStyle(
                  color: HeyBeanTheme.text,
                  fontSize: 15,
                  height: 1.28,
                ),
              ),
            ],
          ),
        ),
      );

      return Align(
        alignment: alignRight ? Alignment.centerRight : Alignment.centerLeft,
        child: bubble,
      );
    },
  );
}

// ignore: unused_element
class _ApprovalCard extends StatelessWidget {
  const _ApprovalCard({required this.approvals});

  final List<HermesApproval> approvals;

  @override
  Widget build(BuildContext context) {
    final hasApprovals = approvals.isNotEmpty;

    return _ShellCard(
      glow: hasApprovals,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionTitle(
            icon: Icons.verified_user_rounded,
            title: hasApprovals ? 'Pending approvals' : 'Approval queue clear',
            subtitle: hasApprovals
                ? 'Hermes will wait before risky/destructive actions'
                : 'Low-risk internal actions can run automatically',
          ),
          const SizedBox(height: 14),
          if (hasApprovals)
            for (final approval in approvals.take(3)) ...[
              _ApprovalListTile(approval: approval),
              if (approval != approvals.take(3).last)
                const SizedBox(height: 10),
            ]
          else
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: HeyBeanTheme.surface2,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: HeyBeanTheme.border),
              ),
              child: Text(
                'Hermes Bean asks first for mail, payments, destructive edits, deployments, and other risky requests.',
              ),
            ),
        ],
      ),
    );
  }
}

class _ApprovalListTile extends StatelessWidget {
  const _ApprovalListTile({required this.approval});

  final HermesApproval approval;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: HeyBeanTheme.surface2,
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: HeyBeanTheme.border),
    ),
    child: Row(
      children: [
        Icon(Icons.shield_rounded, color: HeyBeanTheme.warning),
        const SizedBox(width: 12),
        Expanded(
          child: Text(
            approval.title,
            style: TextStyle(fontWeight: FontWeight.w700),
          ),
        ),
        Text(
          approval.status ?? 'pending',
          style: Theme.of(context).textTheme.labelMedium?.copyWith(
            color: HeyBeanTheme.muted,
            fontWeight: FontWeight.w700,
          ),
        ),
      ],
    ),
  );
}

// ignore: unused_element
class _TabSurface extends StatelessWidget {
  const _TabSurface({
    required this.tasks,
    required this.reminders,
    required this.calendar,
    required this.events,
  });

  final List<HermesTask> tasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendar;
  final List<HermesActivityEvent> events;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: DefaultTabController(
      length: 5,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          TabBar(
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            labelColor: HeyBeanTheme.accentStrong,
            unselectedLabelColor: HeyBeanTheme.muted,
            indicatorColor: HeyBeanTheme.accent,
            tabs: const [
              Tab(text: 'Today'),
              Tab(text: 'Tasks'),
              Tab(text: 'Reminders'),
              Tab(text: 'Calendar'),
              Tab(text: 'Activity'),
            ],
          ),
          const SizedBox(height: 16),
          Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [
              _MiniSurface(
                label: 'Today',
                value: '${tasks.length} tasks · ${calendar.length} events',
                icon: Icons.today_rounded,
              ),
              _MiniSurface(
                label: 'Tasks',
                value: tasks.isEmpty
                    ? 'No open tasks'
                    : tasks.map((t) => t.title).join(', '),
                icon: Icons.task_alt_rounded,
              ),
              _MiniSurface(
                label: 'Reminders',
                value: reminders.isEmpty
                    ? 'No reminders'
                    : reminders.map((r) => r.title).join(', '),
                icon: Icons.notifications_active_rounded,
              ),
              _MiniSurface(
                label: 'Calendar',
                value: calendar.isEmpty
                    ? 'Open calendar'
                    : calendar.map((e) => e.title).join(', '),
                icon: Icons.calendar_month_rounded,
              ),
              _MiniSurface(
                label: 'Activity',
                value: '${events.length} agent events',
                icon: Icons.auto_awesome_rounded,
              ),
            ],
          ),
        ],
      ),
    ),
  );
}

class _ProgressCard extends StatelessWidget {
  const _ProgressCard({
    required this.user,
    required this.taskCount,
    this.error,
  });

  final HermesUser user;
  final int taskCount;
  final String? error;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.trending_up_rounded,
          title: 'Agent progress',
          subtitle: 'Live run status',
        ),
        const SizedBox(height: 12),
        Text(
          'Welcome, ${user.name}',
          style: Theme.of(
            context,
          ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800),
        ),
        Text('$taskCount live tasks loaded'),
        if (error != null) _InlinePlanLimitError(message: error!),
      ],
    ),
  );
}

class _PlanLimitErrorBanner extends StatelessWidget {
  const _PlanLimitErrorBanner({
    required this.message,
    required this.launchExternalUrl,
    this.onDismissed,
  });

  final String? message;
  final ExternalUrlLauncher launchExternalUrl;
  final VoidCallback? onDismissed;

  @override
  Widget build(BuildContext context) {
    final text = message;
    if (!_isPlanLimitMessage(text)) return const SizedBox.shrink();

    return Container(
      key: const Key('plan-limit-error-banner'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: HeyBeanTheme.accent.withValues(alpha: .10),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: HeyBeanTheme.accent.withValues(alpha: .24)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                  color: HeyBeanTheme.accent.withValues(alpha: .14),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(
                  Icons.workspace_premium_rounded,
                  color: HeyBeanTheme.accentStrong,
                  size: 20,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Upgrade to keep going',
                      style: TextStyle(
                        color: HeyBeanTheme.text,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      text!,
                      style: TextStyle(
                        color: HeyBeanTheme.muted,
                        fontWeight: FontWeight.w700,
                        height: 1.35,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: FilledButton.icon(
                  key: const Key('plan-limit-upgrade-action'),
                  onPressed: () => launchExternalUrl(_pricingUrl),
                  icon: Icon(Icons.arrow_upward_rounded),
                  label: Text('Upgrade plan'),
                ),
              ),
              if (onDismissed != null) ...[
                const SizedBox(width: 8),
                IconButton(
                  key: const Key('plan-limit-error-dismiss-action'),
                  tooltip: 'Dismiss',
                  onPressed: onDismissed,
                  icon: Icon(
                    Icons.close_rounded,
                    color: HeyBeanTheme.muted,
                    size: 20,
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

class _InlinePlanLimitError extends StatefulWidget {
  const _InlinePlanLimitError({
    super.key,
    required this.message,
    this.launchExternalUrl = _defaultLaunchExternalUrl,
    this.onDismissed,
  });

  final String message;
  final ExternalUrlLauncher launchExternalUrl;
  final VoidCallback? onDismissed;

  @override
  State<_InlinePlanLimitError> createState() => _InlinePlanLimitErrorState();
}

class _InlinePlanLimitErrorState extends State<_InlinePlanLimitError> {
  bool _dismissed = false;

  @override
  void didUpdateWidget(covariant _InlinePlanLimitError oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.message != widget.message) {
      _dismissed = false;
    }
  }

  void _dismiss() {
    widget.onDismissed?.call();
    if (widget.onDismissed == null) {
      setState(() => _dismissed = true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_dismissed) return const SizedBox.shrink();
    if (!_isPlanLimitMessage(widget.message)) {
      return Text(widget.message, style: TextStyle(color: Colors.redAccent));
    }

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: HeyBeanTheme.accent.withValues(alpha: .10),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: HeyBeanTheme.accent.withValues(alpha: .24)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Upgrade to keep going',
                  style: TextStyle(
                    color: HeyBeanTheme.text,
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ),
              IconButton(
                key: const Key('inline-plan-limit-dismiss-action'),
                tooltip: 'Dismiss',
                onPressed: _dismiss,
                icon: Icon(
                  Icons.close_rounded,
                  color: HeyBeanTheme.muted,
                  size: 20,
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            widget.message,
            style: TextStyle(
              color: HeyBeanTheme.muted,
              fontWeight: FontWeight.w700,
              height: 1.35,
            ),
          ),
          const SizedBox(height: 10),
          Align(
            alignment: Alignment.centerLeft,
            child: FilledButton.icon(
              key: const Key('inline-plan-limit-upgrade-action'),
              onPressed: () => widget.launchExternalUrl(_pricingUrl),
              icon: Icon(Icons.arrow_upward_rounded),
              label: Text('Upgrade plan'),
            ),
          ),
        ],
      ),
    );
  }
}

class _SuccessNotice extends StatelessWidget {
  const _SuccessNotice({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: HeyBeanTheme.accent.withValues(alpha: .10),
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: HeyBeanTheme.accent.withValues(alpha: .22)),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(
          Icons.check_circle_rounded,
          color: HeyBeanTheme.accentStrong,
          size: 20,
        ),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            message,
            style: TextStyle(
              color: HeyBeanTheme.text,
              fontWeight: FontWeight.w800,
              height: 1.3,
            ),
          ),
        ),
      ],
    ),
  );
}

class _ActivityCard extends StatelessWidget {
  const _ActivityCard({required this.events});

  final List<HermesActivityEvent> events;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.history_rounded,
          title: 'Activity feed',
          subtitle: 'Grounded API events',
        ),
        const SizedBox(height: 12),
        for (final event in events)
          ListTile(
            dense: true,
            leading: Icon(Icons.bolt_rounded),
            title: Text(event.eventType),
          ),
      ],
    ),
  );
}
