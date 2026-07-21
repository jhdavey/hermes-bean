part of '../../main.dart';

class _SettingsView extends StatelessWidget {
  const _SettingsView({
    required this.apiClient,
    required this.launchExternalUrl,
    required this.stripePaymentHandler,
    required this.user,
    required this.onBillingChanged,
    this.googleCalendarStatus,
    required this.eventCategories,
    required this.onEventCategorySaved,
    required this.onEventCategoryDeleted,
    required this.calendarStartHour,
    required this.calendarEndHour,
    required this.onCalendarStartHourChanged,
    required this.onCalendarEndHourChanged,
    required this.onDeleteAccount,
    required this.onSignOut,
    required this.onExportAccount,
    required this.onAccountEmailChanged,
    required this.onNotificationPreferencesChanged,
    required this.onThemeChanged,
    required this.onThemeModeChanged,
    required this.onCommandCenterLabelChanged,
    required this.onPreferredMapAppChanged,
    required this.onTimezoneChanged,
    required this.onWorkspacesChanged,
    this.error,
    this.onErrorDismissed,
  });

  final BeanApiClient apiClient;
  final ExternalUrlLauncher launchExternalUrl;
  final StripePaymentHandler stripePaymentHandler;
  final BeanUser user;
  final Future<void> Function() onBillingChanged;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final List<BeanEventCategory> eventCategories;
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
  final int calendarStartHour;
  final int calendarEndHour;
  final ValueChanged<int> onCalendarStartHourChanged;
  final ValueChanged<int> onCalendarEndHourChanged;
  final Future<void> Function() onDeleteAccount;
  final Future<void> Function() onSignOut;
  final Future<void> Function() onExportAccount;
  final Future<void> Function(String email) onAccountEmailChanged;
  final Future<void> Function(BeanNotificationPreferences preferences)
  onNotificationPreferencesChanged;
  final Future<void> Function(String themeKey) onThemeChanged;
  final Future<void> Function(String themeModeKey) onThemeModeChanged;
  final Future<void> Function(String label) onCommandCenterLabelChanged;
  final Future<void> Function(String preferredMapApp) onPreferredMapAppChanged;
  final Future<void> Function(String timezone) onTimezoneChanged;
  final Future<void> Function() onWorkspacesChanged;
  final String? error;
  final VoidCallback? onErrorDismissed;

  Future<void> _editEmail(BuildContext context) async {
    final nextEmail = await _showEmailEditor(context, initialEmail: user.email);
    if (nextEmail == null || nextEmail.trim() == user.email) return;
    await onAccountEmailChanged(nextEmail);
  }

  @override
  Widget build(BuildContext context) => Column(
    key: const Key('settings-view'),
    children: [
      _ShellCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const _SectionTitle(
              icon: Icons.settings_rounded,
              title: 'Settings',
              subtitle: '',
              infoKey: Key('settings-info'),
              infoTitle: 'Settings help',
              infoBullets: [
                'Choose the theme, notifications, and calendar layout that work best for you.',
                'Workspaces keep household calendars, tasks, and reminders separated from Personal.',
                'Account controls, legal links, and sign out live at the bottom of Settings.',
              ],
            ),
            const SizedBox(height: 10),
            _SettingsEmailRow(
              email: user.email,
              onEdit: () => _editEmail(context),
            ),
            const SizedBox(height: 12),
            if (error != null) ...[
              _InlinePlanLimitError(
                message: error!,
                launchExternalUrl: launchExternalUrl,
                onDismissed: onErrorDismissed,
              ),
              const SizedBox(height: 12),
            ],
            _ThemePreferencesCard(
              selectedThemeKey: user.theme,
              selectedThemeModeKey: user.themeMode,
              commandCenterLabel: user.commandCenterLabel,
              onChanged: onThemeChanged,
              onModeChanged: onThemeModeChanged,
              onCommandCenterLabelChanged: onCommandCenterLabelChanged,
            ),
            _NotificationPreferencesCard(
              preferences: user.notificationPreferences,
              onChanged: onNotificationPreferencesChanged,
            ),
            _MapPreferencesCard(
              preferredMapApp: user.preferredMapApp,
              onChanged: onPreferredMapAppChanged,
            ),
            _TimezonePreferencesCard(
              timezone: user.timezone,
              onChanged: onTimezoneChanged,
            ),
            _CategoriesSettingsCard(
              categories: eventCategories,
              onSaved: onEventCategorySaved,
              onDeleted: onEventCategoryDeleted,
            ),
            const SizedBox(height: 8),
            _WorkspacesSettingsCard(
              apiClient: apiClient,
              launchExternalUrl: launchExternalUrl,
              user: user,
              googleCalendarStatus: googleCalendarStatus,
              onChanged: onWorkspacesChanged,
            ),
            _GoogleCalendarSyncCard(
              apiClient: apiClient,
              launchExternalUrl: launchExternalUrl,
            ),
            _ExternalCalendarImportCard(
              apiClient: apiClient,
              user: user,
              onImported: onWorkspacesChanged,
            ),
            _CalendarPreferencesCard(
              startHour: calendarStartHour,
              endHour: calendarEndHour,
              onStartHourChanged: onCalendarStartHourChanged,
              onEndHourChanged: onCalendarEndHourChanged,
            ),
          ],
        ),
      ),
      const SizedBox(height: 16),
      _AccountCard(
        onDeleteAccount: onDeleteAccount,
        onSignOut: onSignOut,
        onExportAccount: onExportAccount,
        launchExternalUrl: launchExternalUrl,
        showLegalLinks: false,
        beforeAccountActions: _BillingSettingsCard(
          apiClient: apiClient,
          user: user,
          stripePaymentHandler: stripePaymentHandler,
          onBillingChanged: onBillingChanged,
        ),
      ),
      const SizedBox(height: 10),
      _SettingsLegalLinksRow(launchExternalUrl: launchExternalUrl),
    ],
  );
}

class _SettingsEmailRow extends StatelessWidget {
  const _SettingsEmailRow({required this.email, required this.onEdit});

  final String email;
  final VoidCallback onEdit;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.fromLTRB(0, 10, 0, 12),
    decoration: _sectionDividerDecoration(alpha: .22),
    child: Row(
      children: [
        Icon(Icons.email_outlined, color: HeyBeanTheme.muted, size: 18),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            email,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: HeyBeanTheme.muted,
              fontSize: 13,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
        const SizedBox(width: 8),
        TextButton(
          key: const Key('settings-edit-email-action'),
          onPressed: onEdit,
          child: Text('Edit'),
        ),
      ],
    ),
  );
}

class _BillingSettingsCard extends StatefulWidget {
  const _BillingSettingsCard({
    required this.apiClient,
    required this.user,
    required this.stripePaymentHandler,
    required this.onBillingChanged,
  });

  final BeanApiClient apiClient;
  final BeanUser user;
  final StripePaymentHandler stripePaymentHandler;
  final Future<void> Function() onBillingChanged;

  @override
  State<_BillingSettingsCard> createState() => _BillingSettingsCardState();
}

class _BillingSettingsCardState extends State<_BillingSettingsCard> {
  final _couponController = TextEditingController();
  BeanBillingPaymentMethod? _paymentMethod;
  BeanSubscriptionSummary? _subscription;
  bool _loadingPaymentMethod = true;
  bool _loadingSubscription = true;
  bool _busy = false;
  String? _error;
  String? _message;

  String get _planLabel => _subscriptionPlanLabel(
    _subscription?.tier ?? widget.user.subscriptionTier,
  );

  @override
  void dispose() {
    _couponController.dispose();
    super.dispose();
  }

  @override
  void initState() {
    super.initState();
    unawaited(_loadPaymentMethod());
    unawaited(_loadSubscriptionSummary());
  }

  @override
  void didUpdateWidget(covariant _BillingSettingsCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.user.subscriptionTier != widget.user.subscriptionTier ||
        oldWidget.user.subscriptionStatus != widget.user.subscriptionStatus) {
      unawaited(_loadPaymentMethod());
      unawaited(_loadSubscriptionSummary());
    }
  }

  BeanSubscriptionSummary get _fallbackSubscription => BeanSubscriptionSummary(
    tier: widget.user.subscriptionTier,
    status: widget.user.subscriptionStatus,
    trialEndsAt: widget.user.subscriptionTrialEndsAt,
  );

  BeanSubscriptionSummary get _currentSubscription =>
      _subscription ?? _fallbackSubscription;

  Future<void> _loadPaymentMethod() async {
    setState(() {
      _loadingPaymentMethod = true;
      _error = null;
    });
    try {
      final paymentMethod = await widget.apiClient.getBillingPaymentMethod();
      if (!mounted) return;
      setState(() => _paymentMethod = paymentMethod);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = beanFriendlyErrorMessage(
          error,
          action: 'load your payment method',
        );
      });
    } finally {
      if (mounted) setState(() => _loadingPaymentMethod = false);
    }
  }

  Future<void> _loadSubscriptionSummary() async {
    setState(() {
      _loadingSubscription = true;
      _error = null;
    });
    try {
      final summary = await widget.apiClient.getSubscriptionSummary();
      if (!mounted) return;
      setState(() => _subscription = summary);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = beanFriendlyErrorMessage(
          error,
          action: 'load your subscription',
        );
      });
    } finally {
      if (mounted) setState(() => _loadingSubscription = false);
    }
  }

  Future<void> _refreshBillingAfterChange({String? message}) async {
    final summary = await widget.apiClient.getSubscriptionSummary();
    if (!mounted) return;
    setState(() {
      _subscription = summary;
      _message = message;
    });
    await widget.onBillingChanged();
  }

  Future<void> _updatePaymentMethod() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
      _message = null;
    });
    try {
      final setup = await widget.apiClient.createPaymentMethodSetup();
      await widget.stripePaymentHandler.preparePaymentSheet(
        setup,
        user: widget.user,
        primaryButtonLabel: 'Save payment method',
      );
      await widget.stripePaymentHandler.presentPaymentSheet();
      final paymentMethod = await widget.apiClient.confirmPaymentMethodSetup(
        setupIntentId: setup.setupIntentId,
      );
      if (!mounted) return;
      setState(() => _paymentMethod = paymentMethod);
      await widget.onBillingChanged();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = _isStripePaymentCanceled(error)
            ? null
            : beanFriendlyErrorMessage(
                error,
                action: 'update your payment method',
              );
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _subscribeToPlan(String plan, String billingInterval) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
      _message = null;
    });
    try {
      final setup = await widget.apiClient.createMobileSubscriptionSetup(
        plan: plan,
        billingInterval: billingInterval,
      );
      await widget.stripePaymentHandler.preparePaymentSheet(
        setup,
        user: widget.user,
        primaryButtonLabel: 'Start ${_subscriptionPlanLabel(plan)} trial',
      );
      await widget.stripePaymentHandler.presentPaymentSheet();
      final result = await widget.apiClient.confirmMobileSubscription(
        plan: plan,
        billingInterval: billingInterval,
        setupIntentId: setup.setupIntentId,
      );
      if (!mounted) return;
      setState(() => _paymentMethod = result.paymentMethod ?? _paymentMethod);
      await _refreshBillingAfterChange(message: 'Subscription updated.');
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = _isStripePaymentCanceled(error)
            ? null
            : beanFriendlyErrorMessage(
                error,
                action: 'change your subscription',
              );
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _choosePlan() async {
    final choice = await showModalBottomSheet<_PlanBillingChoice>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) => _PlanManagementSheet(
        currentPlan: widget.user.subscriptionTier,
        currentBillingInterval: _currentSubscription.billingInterval,
      ),
    );
    if (choice != null) {
      await _subscribeToPlan(choice.plan, choice.billingInterval);
    }
  }

  Future<void> _cancelSubscription() async {
    if (_busy) return;
    final confirmed = await _confirmDestructiveAction(
      context,
      title: 'Cancel subscription?',
      message:
          'Your current access stays active until the end of the paid period or trial. Once the final active period has ended, your HeyBean data will be deleted and you will need to create a new account if you choose to keep using the app in the future.',
      confirmLabel: 'Cancel renewal',
    );
    if (!confirmed) return;
    setState(() {
      _busy = true;
      _error = null;
      _message = 'Canceling renewal...';
    });
    try {
      final summary = await widget.apiClient.cancelSubscription();
      if (!mounted) return;
      setState(() => _subscription = summary);
      await _refreshBillingAfterChange(
        message:
            'Subscription renewal canceled. Current access stays active through the end of this period.',
      );
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = beanFriendlyErrorMessage(
          error,
          action: 'cancel your subscription',
        );
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resumeSubscription() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
      _message = 'Restarting subscription...';
    });
    try {
      final summary = await widget.apiClient.resumeSubscription();
      if (!mounted) return;
      setState(() => _subscription = summary);
      await _refreshBillingAfterChange(
        message: 'Subscription restarted. Renewal is active again.',
      );
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = beanFriendlyErrorMessage(
          error,
          action: 'restart your subscription',
        );
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _redeemCouponCode() async {
    if (_busy) return;
    final code = _couponController.text.replaceAll(RegExp(r'\D'), '');
    if (code.length != 6) {
      setState(() {
        _error = 'Enter a 6-digit coupon code.';
        _message = null;
      });
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _message = 'Applying coupon...';
    });
    try {
      final result = await widget.apiClient.redeemCouponCode(code: code);
      if (!mounted) return;
      _couponController.clear();
      setState(() {
        _subscription = result.subscription;
        _message = _couponAppliedMessage(result.subscription);
      });
      await widget.onBillingChanged();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = beanFriendlyErrorMessage(
          error,
          action: 'apply your coupon code',
        );
        _message = null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final subscription = _currentSubscription;
    final status = subscription.status;
    final canceled = subscription.cancelAtPeriodEnd;
    final statusLine = status == null || status.isEmpty
        ? 'Current plan: $_planLabel • ${_billingIntervalLabel(subscription.billingInterval)}'
        : canceled
        ? 'Current plan: $_planLabel • renewal canceled'
        : 'Current plan: $_planLabel • ${_billingIntervalLabel(subscription.billingInterval)} • ${status.replaceAll('_', ' ')}';
    final paymentLine = _loadingPaymentMethod
        ? 'Loading payment method...'
        : _paymentMethod?.displayLine ?? 'No saved payment method yet';
    final accessEndLine = _subscriptionAccessEndLine(subscription);
    final renewalLine = _subscriptionRenewalLine(subscription);

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(vertical: 14),
      decoration: _sectionDividerDecoration(),
      child: Column(
        key: const Key('billing-settings-card'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: _quietMutedSurfaceColor(alpha: .42),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: _quietBorderColor(alpha: .32)),
                ),
                child: Icon(
                  Icons.credit_card_rounded,
                  color: HeyBeanTheme.muted,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Billing',
                      style: TextStyle(
                        color: HeyBeanTheme.text,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      _loadingSubscription
                          ? 'Loading subscription...'
                          : statusLine,
                      style: TextStyle(
                        color: HeyBeanTheme.muted,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      paymentLine,
                      key: const Key('settings-payment-method-summary'),
                      style: TextStyle(
                        color: HeyBeanTheme.muted,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                    if (accessEndLine != null) ...[
                      const SizedBox(height: 4),
                      Text(
                        accessEndLine,
                        key: const Key('settings-subscription-access-end'),
                        style: TextStyle(
                          color: HeyBeanTheme.destructive,
                          fontSize: 12,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Once the final active period has ended, your HeyBean data will be deleted and you will need to create a new account to keep using the app.',
                        style: TextStyle(
                          color: HeyBeanTheme.muted,
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          height: 1.25,
                        ),
                      ),
                    ] else if (renewalLine != null) ...[
                      const SizedBox(height: 4),
                      Text(
                        renewalLine,
                        key: const Key('settings-subscription-renewal-summary'),
                        style: TextStyle(
                          color: HeyBeanTheme.muted,
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ] else if (subscription.status == 'trialing') ...[
                      const SizedBox(height: 4),
                      Text(
                        'Trial renewal uses the saved Stripe payment method.',
                        style: TextStyle(
                          color: HeyBeanTheme.muted,
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
          if (_error != null) ...[
            const SizedBox(height: 10),
            _InlinePlanLimitError(message: _error!),
          ],
          if (_message != null) ...[
            const SizedBox(height: 10),
            _SuccessNotice(message: _message!),
          ],
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: _quietMutedSurfaceColor(alpha: .32),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: _quietBorderColor(alpha: .32)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  'Coupon code',
                  style: TextStyle(
                    color: HeyBeanTheme.text,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        key: const Key('settings-coupon-code-input'),
                        controller: _couponController,
                        enabled: !_busy,
                        keyboardType: TextInputType.number,
                        textInputAction: TextInputAction.done,
                        maxLength: 6,
                        inputFormatters: [
                          FilteringTextInputFormatter.digitsOnly,
                        ],
                        decoration: InputDecoration(
                          labelText: '6-digit code',
                          counterText: '',
                          border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(14),
                          ),
                        ),
                        onSubmitted: (_) => unawaited(_redeemCouponCode()),
                      ),
                    ),
                    const SizedBox(width: 8),
                    OutlinedButton.icon(
                      key: const Key('settings-apply-coupon-action'),
                      onPressed: _busy ? null : _redeemCouponCode,
                      icon: Icon(Icons.confirmation_number_rounded),
                      label: Text('Apply'),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              FilledButton.icon(
                key: const Key('settings-upgrade-plan-action'),
                onPressed: _busy ? null : _choosePlan,
                icon: _busy
                    ? const SizedBox.square(
                        dimension: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Icon(Icons.swap_vert_rounded),
                label: Text('Change plan'),
              ),
              OutlinedButton.icon(
                key: const Key('settings-update-payment-method-action'),
                onPressed: _busy ? null : _updatePaymentMethod,
                icon: Icon(Icons.credit_card_rounded),
                label: Text('Update payment'),
              ),
              OutlinedButton.icon(
                key: const Key('settings-cancel-subscription-action'),
                onPressed: _busy || !subscription.canCancel
                    ? null
                    : _cancelSubscription,
                icon: Icon(Icons.event_busy_rounded),
                label: Text(canceled ? 'Renewal canceled' : 'Cancel renewal'),
              ),
              if (subscription.canResume)
                FilledButton.icon(
                  key: const Key('settings-resume-subscription-action'),
                  onPressed: _busy ? null : _resumeSubscription,
                  icon: _busy
                      ? const SizedBox.square(
                          dimension: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : Icon(Icons.restart_alt_rounded),
                  label: Text('Restart subscription'),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

String? _subscriptionAccessEndLine(BeanSubscriptionSummary subscription) {
  if (!subscription.cancelAtPeriodEnd) return null;
  final accessEndsAt =
      subscription.accessEndsAt ?? subscription.currentPeriodEnd;
  final label = _formatBillingDate(accessEndsAt);
  return label == null
      ? 'Access ends at the end of this period'
      : 'Last day of access: $label';
}

String? _subscriptionRenewalLine(BeanSubscriptionSummary subscription) {
  if (subscription.cancelAtPeriodEnd) return null;
  if (subscription.trialEndsAt != null &&
      subscription.trialEndsAt!.isNotEmpty) {
    final label = _formatBillingDate(subscription.trialEndsAt);
    return label == null
        ? 'Trial renewal uses the saved Stripe payment method.'
        : 'Trial runs through $label.';
  }
  final currentPeriodEnd = subscription.currentPeriodEnd;
  if (currentPeriodEnd == null || currentPeriodEnd.isEmpty) return null;
  final label = _formatBillingDate(currentPeriodEnd);
  return label == null ? null : 'Renews around $label.';
}

String? _formatBillingDate(String? value) {
  final parsed = _parseCalendarEventDateTime(value);
  return parsed == null ? null : _formatCalendarDateLabel(parsed);
}

String _couponAppliedMessage(BeanSubscriptionSummary subscription) {
  final label = _formatBillingDate(subscription.baseCompExpiresAt);
  return label == null
      ? 'Coupon applied. Base access is active.'
      : 'Coupon applied. Free Base access runs through $label.';
}

class _PlanBillingChoice {
  const _PlanBillingChoice({required this.plan, required this.billingInterval});

  final String plan;
  final String billingInterval;
}

class _PlanManagementSheet extends StatefulWidget {
  const _PlanManagementSheet({
    required this.currentPlan,
    required this.currentBillingInterval,
  });

  final String currentPlan;
  final String currentBillingInterval;

  @override
  State<_PlanManagementSheet> createState() => _PlanManagementSheetState();
}

class _PlanManagementSheetState extends State<_PlanManagementSheet> {
  late String _billingInterval;

  @override
  void initState() {
    super.initState();
    _billingInterval = _normalizedBillingInterval(
      widget.currentBillingInterval,
    );
  }

  @override
  Widget build(BuildContext context) {
    final current = widget.currentPlan.trim().toLowerCase();
    final currentInterval = _normalizedBillingInterval(
      widget.currentBillingInterval,
    );
    final plans = _signupPlanOptions.where((plan) => plan.startsCheckout);
    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'Change plan',
              style: Theme.of(
                context,
              ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 6),
            Text(
              'Payment stays inside HeyBean with Stripe handling secure card entry and storage.',
              style: TextStyle(
                color: HeyBeanTheme.muted,
                fontWeight: FontWeight.w700,
                height: 1.35,
              ),
            ),
            const SizedBox(height: 14),
            _BillingIntervalToggle(
              selected: _billingInterval,
              onChanged: (value) => setState(
                () => _billingInterval = _normalizedBillingInterval(value),
              ),
            ),
            const SizedBox(height: 14),
            for (final plan in plans) ...[
              _PlanManagementTile(
                plan: plan,
                billingInterval: _billingInterval,
                selected:
                    plan.key == current && _billingInterval == currentInterval,
                onTap:
                    plan.key == current && _billingInterval == currentInterval
                    ? null
                    : () => Navigator.of(context).pop(
                        _PlanBillingChoice(
                          plan: plan.key,
                          billingInterval: _billingInterval,
                        ),
                      ),
              ),
              const SizedBox(height: 10),
            ],
          ],
        ),
      ),
    );
  }
}

class _PlanManagementTile extends StatelessWidget {
  const _PlanManagementTile({
    required this.plan,
    required this.billingInterval,
    required this.selected,
    required this.onTap,
  });

  final _SignupPlanOption plan;
  final String billingInterval;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => InkWell(
    key: Key('settings-plan-${plan.key}'),
    borderRadius: BorderRadius.circular(18),
    onTap: onTap,
    child: Ink(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: selected
            ? HeyBeanTheme.accent.withValues(alpha: .10)
            : HeyBeanTheme.surface,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(
          color: selected
              ? HeyBeanTheme.accent.withValues(alpha: .32)
              : HeyBeanTheme.border,
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            selected
                ? Icons.check_circle_rounded
                : Icons.radio_button_unchecked_rounded,
            color: selected ? HeyBeanTheme.accentStrong : HeyBeanTheme.muted,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  plan.label,
                  style: TextStyle(
                    color: HeyBeanTheme.text,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  '${_planDisplayPrice(plan, billingInterval)}${_planDisplayPriceSuffix(plan, billingInterval) ?? ''} • ${_planTrialText(billingInterval)}',
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
    ),
  );
}

class _ThemePreferencesCard extends StatefulWidget {
  const _ThemePreferencesCard({
    required this.selectedThemeKey,
    required this.selectedThemeModeKey,
    required this.commandCenterLabel,
    required this.onChanged,
    required this.onModeChanged,
    required this.onCommandCenterLabelChanged,
  });

  final String selectedThemeKey;
  final String selectedThemeModeKey;
  final String commandCenterLabel;
  final Future<void> Function(String themeKey) onChanged;
  final Future<void> Function(String themeModeKey) onModeChanged;
  final Future<void> Function(String label) onCommandCenterLabelChanged;

  @override
  State<_ThemePreferencesCard> createState() => _ThemePreferencesCardState();
}

class _ThemePreferencesCardState extends State<_ThemePreferencesCard> {
  late String _selectedThemeKey;
  late String _selectedThemeModeKey;
  late final TextEditingController _commandCenterLabelController;
  bool _saving = false;
  bool _savingMode = false;
  bool _expanded = false;
  bool _savingLabel = false;

  @override
  void initState() {
    super.initState();
    _selectedThemeKey = heyBeanColorThemeForKey(widget.selectedThemeKey).key;
    _selectedThemeModeKey = heyBeanThemeModeForKey(
      widget.selectedThemeModeKey,
    ).key;
    _commandCenterLabelController = TextEditingController(
      text: widget.commandCenterLabel,
    );
  }

  @override
  void didUpdateWidget(covariant _ThemePreferencesCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!_saving) {
      _selectedThemeKey = heyBeanColorThemeForKey(widget.selectedThemeKey).key;
    }
    if (!_savingMode) {
      _selectedThemeModeKey = heyBeanThemeModeForKey(
        widget.selectedThemeModeKey,
      ).key;
    }
    if (!_savingLabel &&
        oldWidget.commandCenterLabel != widget.commandCenterLabel &&
        _commandCenterLabelController.text != widget.commandCenterLabel) {
      _commandCenterLabelController.text = widget.commandCenterLabel;
    }
  }

  @override
  void dispose() {
    _commandCenterLabelController.dispose();
    super.dispose();
  }

  Future<void> _save(String themeKey) async {
    final normalizedThemeKey = heyBeanColorThemeForKey(themeKey).key;
    if (_saving || normalizedThemeKey == _selectedThemeKey) return;
    setState(() {
      _selectedThemeKey = normalizedThemeKey;
      _saving = true;
    });
    try {
      await widget.onChanged(normalizedThemeKey);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _saveMode(String themeModeKey) async {
    final normalizedThemeModeKey = heyBeanThemeModeForKey(themeModeKey).key;
    if (_savingMode || normalizedThemeModeKey == _selectedThemeModeKey) return;
    setState(() {
      _selectedThemeModeKey = normalizedThemeModeKey;
      _savingMode = true;
    });
    try {
      await widget.onModeChanged(normalizedThemeModeKey);
    } finally {
      if (mounted) setState(() => _savingMode = false);
    }
  }

  Future<void> _saveCommandCenterLabel() async {
    if (_savingLabel) return;
    final label = _commandCenterLabelController.text.trim().isEmpty
        ? 'Command Center'
        : _commandCenterLabelController.text.trim();
    if (label == widget.commandCenterLabel) return;
    setState(() {
      _savingLabel = true;
      _commandCenterLabelController.text = label;
    });
    try {
      await widget.onCommandCenterLabelChanged(label);
    } finally {
      if (mounted) setState(() => _savingLabel = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final selectedTheme = heyBeanColorThemeForKey(_selectedThemeKey);
    final selectedMode = heyBeanThemeModeForKey(_selectedThemeModeKey);
    return Container(
      key: const Key('theme-preferences-card'),
      margin: const EdgeInsets.only(top: 4),
      decoration: _sectionDividerDecoration(),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Material(
            color: Colors.transparent,
            child: InkWell(
              key: const Key('theme-preferences-toggle'),
              onTap: () => setState(() => _expanded = !_expanded),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 14),
                child: Row(
                  children: [
                    Icon(Icons.palette_outlined, color: HeyBeanTheme.muted),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Appearance',
                            style: TextStyle(fontWeight: FontWeight.w600),
                          ),
                          const SizedBox(height: 3),
                          Text(
                            '${selectedTheme.label} accent · ${selectedMode.label} · ${widget.commandCenterLabel}',
                            style: TextStyle(
                              color: HeyBeanTheme.muted,
                              fontSize: 12,
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                        ],
                      ),
                    ),
                    Container(
                      width: 22,
                      height: 22,
                      decoration: BoxDecoration(
                        color: selectedTheme.accent,
                        shape: BoxShape.circle,
                        border: Border.all(
                          color: _quietBorderColor(alpha: .34),
                          width: 1,
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    if (_saving)
                      SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: HeyBeanTheme.accent,
                        ),
                      )
                    else
                      Icon(
                        _expanded
                            ? Icons.keyboard_arrow_up_rounded
                            : Icons.keyboard_arrow_down_rounded,
                        color: HeyBeanTheme.muted,
                      ),
                  ],
                ),
              ),
            ),
          ),
          if (_expanded)
            Padding(
              key: const Key('theme-preferences-options'),
              padding: const EdgeInsets.only(bottom: 14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Divider(height: 1, color: HeyBeanTheme.border),
                  const SizedBox(height: 12),
                  Text(
                    'Choose the accent color used across HeyBean.',
                    style: TextStyle(color: HeyBeanTheme.muted, fontSize: 12),
                  ),
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final theme in heyBeanColorThemes)
                        _ThemeSwatchButton(
                          theme: theme,
                          selected: theme.key == _selectedThemeKey,
                          disabled: _saving,
                          onTap: () => _save(theme.key),
                        ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  Text(
                    'Choose when HeyBean uses dark mode.',
                    style: TextStyle(color: HeyBeanTheme.muted, fontSize: 12),
                  ),
                  const SizedBox(height: 10),
                  _ThemeModeSelector(
                    selectedThemeModeKey: _selectedThemeModeKey,
                    disabled: _savingMode,
                    onChanged: _saveMode,
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    key: const Key('command-center-label-field'),
                    controller: _commandCenterLabelController,
                    enabled: !_savingLabel,
                    textInputAction: TextInputAction.done,
                    onSubmitted: (_) => unawaited(_saveCommandCenterLabel()),
                    decoration: const InputDecoration(
                      labelText: 'Command Center name',
                      hintText: 'Command Center',
                    ),
                  ),
                  const SizedBox(height: 10),
                  Align(
                    alignment: Alignment.centerRight,
                    child: FilledButton(
                      key: const Key('command-center-label-save'),
                      onPressed: _savingLabel
                          ? null
                          : () => unawaited(_saveCommandCenterLabel()),
                      child: _savingLabel
                          ? const SizedBox.square(
                              dimension: 17,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : Text('Save'),
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

class _ThemeSwatchButton extends StatelessWidget {
  const _ThemeSwatchButton({
    required this.theme,
    required this.selected,
    required this.disabled,
    required this.onTap,
  });

  final HeyBeanColorTheme theme;
  final bool selected;
  final bool disabled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
    button: true,
    selected: selected,
    label: '${theme.label} theme',
    child: InkWell(
      borderRadius: BorderRadius.circular(999),
      onTap: disabled ? null : onTap,
      child: Container(
        width: 112,
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
        decoration: BoxDecoration(
          color: selected
              ? theme.accent.withValues(alpha: .08)
              : HeyBeanTheme.surface.withValues(
                  alpha: HeyBeanTheme.isDark ? .86 : .72,
                ),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: selected
                ? theme.accentStrong.withValues(alpha: .34)
                : _quietBorderColor(alpha: .38),
          ),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 22,
              height: 22,
              decoration: BoxDecoration(
                color: theme.accent,
                shape: BoxShape.circle,
                border: Border.all(color: HeyBeanTheme.surface, width: 2),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                theme.label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(fontWeight: FontWeight.w800),
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _ThemeModeSelector extends StatelessWidget {
  const _ThemeModeSelector({
    required this.selectedThemeModeKey,
    required this.disabled,
    required this.onChanged,
  });

  final String selectedThemeModeKey;
  final bool disabled;
  final Future<void> Function(String themeModeKey) onChanged;

  @override
  Widget build(BuildContext context) => SegmentedButton<String>(
    key: const Key('theme-mode-selector'),
    segments: [
      for (final mode in heyBeanThemeModes)
        ButtonSegment<String>(
          value: mode.key,
          icon: Icon(_themeModeIcon(mode.key), size: 18),
          label: Text(mode.label),
          tooltip: mode.subtitle,
        ),
    ],
    selected: {heyBeanThemeModeForKey(selectedThemeModeKey).key},
    showSelectedIcon: false,
    onSelectionChanged: disabled
        ? null
        : (selection) => unawaited(onChanged(selection.first)),
    style: ButtonStyle(
      visualDensity: VisualDensity.compact,
      foregroundColor: WidgetStateProperty.resolveWith(
        (states) => states.contains(WidgetState.selected)
            ? HeyBeanTheme.accentInk
            : HeyBeanTheme.text,
      ),
      backgroundColor: WidgetStateProperty.resolveWith(
        (states) => states.contains(WidgetState.selected)
            ? HeyBeanTheme.accent
            : HeyBeanTheme.surface,
      ),
      side: WidgetStateProperty.resolveWith(
        (states) => BorderSide(
          color: states.contains(WidgetState.selected)
              ? HeyBeanTheme.accentStrong.withValues(alpha: .42)
              : HeyBeanTheme.border,
        ),
      ),
    ),
  );
}

IconData _themeModeIcon(String key) => switch (key) {
  'light' => Icons.light_mode_rounded,
  'dark' => Icons.dark_mode_rounded,
  _ => Icons.brightness_auto_rounded,
};

class _NotificationPreferencesCard extends StatefulWidget {
  const _NotificationPreferencesCard({
    required this.preferences,
    required this.onChanged,
  });

  final BeanNotificationPreferences preferences;
  final Future<void> Function(BeanNotificationPreferences preferences)
  onChanged;

  @override
  State<_NotificationPreferencesCard> createState() =>
      _NotificationPreferencesCardState();
}

class _NotificationPreferencesCardState
    extends State<_NotificationPreferencesCard> {
  late BeanNotificationPreferences _preferences;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _preferences = widget.preferences;
  }

  @override
  void didUpdateWidget(covariant _NotificationPreferencesCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!_saving) _preferences = widget.preferences;
  }

  Future<void> _save(BeanNotificationPreferences preferences) async {
    setState(() {
      _preferences = preferences;
      _saving = true;
    });
    try {
      await widget.onChanged(preferences);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => Container(
    key: const Key('notification-preferences-card'),
    margin: const EdgeInsets.only(top: 4),
    padding: const EdgeInsets.only(top: 14, bottom: 6),
    decoration: _sectionDividerDecoration(),
    child: Column(
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: 4),
          child: Row(
            children: [
              Icon(
                Icons.notifications_active_outlined,
                color: HeyBeanTheme.muted,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  'Notification preferences',
                  style: TextStyle(fontWeight: FontWeight.w600),
                ),
              ),
            ],
          ),
        ),
        SwitchListTile.adaptive(
          key: const Key('reminder-push-preference'),
          value: _preferences.reminderPush,
          onChanged: _saving
              ? null
              : (value) => _save(_preferences.copyWith(reminderPush: value)),
          title: Text('Reminder push notifications'),
          secondary: Icon(
            Icons.phone_iphone_rounded,
            color: HeyBeanTheme.muted,
          ),
        ),
        SwitchListTile.adaptive(
          key: const Key('reminder-email-preference'),
          value: _preferences.reminderEmail,
          onChanged: _saving
              ? null
              : (value) => _save(_preferences.copyWith(reminderEmail: value)),
          title: Text('Reminder emails'),
          secondary: Icon(Icons.email_outlined, color: HeyBeanTheme.muted),
        ),
      ],
    ),
  );
}

class _MapPreferencesCard extends StatefulWidget {
  const _MapPreferencesCard({
    required this.preferredMapApp,
    required this.onChanged,
  });

  final String preferredMapApp;
  final Future<void> Function(String preferredMapApp) onChanged;

  @override
  State<_MapPreferencesCard> createState() => _MapPreferencesCardState();
}

class _MapPreferencesCardState extends State<_MapPreferencesCard> {
  late String _preferredMapApp;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _preferredMapApp = _normalizedMapApp(widget.preferredMapApp);
  }

  @override
  void didUpdateWidget(covariant _MapPreferencesCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!_saving) _preferredMapApp = _normalizedMapApp(widget.preferredMapApp);
  }

  Future<void> _save(String preferredMapApp) async {
    final normalized = _normalizedMapApp(preferredMapApp);
    setState(() {
      _preferredMapApp = normalized;
      _saving = true;
    });
    try {
      await widget.onChanged(normalized);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => Container(
    key: const Key('map-preferences-card'),
    margin: const EdgeInsets.only(top: 4),
    padding: const EdgeInsets.only(top: 14, bottom: 12),
    decoration: _sectionDividerDecoration(),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(Icons.near_me_outlined, color: HeyBeanTheme.muted),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Map preference',
                    style: TextStyle(fontWeight: FontWeight.w600),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    'Used when opening event directions.',
                    style: TextStyle(
                      color: HeyBeanTheme.muted,
                      fontSize: 12,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        SegmentedButton<String>(
          key: const Key('preferred-map-selector'),
          segments: const [
            ButtonSegment<String>(
              value: 'google',
              icon: Icon(Icons.map_rounded, size: 18),
              label: Text('Google Maps'),
            ),
            ButtonSegment<String>(
              value: 'apple',
              icon: Icon(Icons.near_me_rounded, size: 18),
              label: Text('Apple Maps'),
            ),
          ],
          selected: {_preferredMapApp},
          showSelectedIcon: false,
          onSelectionChanged: _saving
              ? null
              : (selection) => unawaited(_save(selection.first)),
          style: ButtonStyle(
            visualDensity: VisualDensity.compact,
            foregroundColor: WidgetStateProperty.resolveWith(
              (states) => states.contains(WidgetState.selected)
                  ? HeyBeanTheme.accentInk
                  : HeyBeanTheme.text,
            ),
            backgroundColor: WidgetStateProperty.resolveWith(
              (states) => states.contains(WidgetState.selected)
                  ? HeyBeanTheme.accent
                  : HeyBeanTheme.surface,
            ),
            side: WidgetStateProperty.resolveWith(
              (states) => BorderSide(
                color: states.contains(WidgetState.selected)
                    ? HeyBeanTheme.accentStrong.withValues(alpha: .42)
                    : HeyBeanTheme.border,
              ),
            ),
          ),
        ),
      ],
    ),
  );
}

String _normalizedMapApp(String value) => value == 'apple' ? 'apple' : 'google';

class _CategoriesSettingsCard extends StatefulWidget {
  const _CategoriesSettingsCard({
    required this.categories,
    required this.onSaved,
    required this.onDeleted,
  });

  final List<BeanEventCategory> categories;
  final Future<BeanEventCategory> Function({
    BeanEventCategory? category,
    required String name,
    required String color,
  })
  onSaved;
  final Future<void> Function(
    BeanEventCategory category, {
    List<Object> deleteFromWorkspaceIds,
  })
  onDeleted;

  @override
  State<_CategoriesSettingsCard> createState() =>
      _CategoriesSettingsCardState();
}

class _CategoriesSettingsCardState extends State<_CategoriesSettingsCard> {
  static const _colors = <({String value, String label})>[
    (value: _beanGreenCategoryColor, label: 'Green'),
    (value: '#007AFF', label: 'Blue'),
    (value: '#FF9500', label: 'Orange'),
    (value: '#AF52DE', label: 'Purple'),
    (value: '#FF3B30', label: 'Red'),
  ];

  bool _busy = false;
  String? _error;

  Future<void> _editCategory([BeanEventCategory? category]) async {
    final result = await showDialog<Map<String, String>>(
      context: context,
      builder: (context) => _EventCategoryCreateDialog(
        initialColor: category?.color ?? _themeCategoryColorHex(),
        initialName: category?.name,
        editing: category != null,
        colors: _colors,
      ),
    );
    if (result == null) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.onSaved(
        category: category,
        name: result['name'] ?? category?.name ?? '',
        color: result['color'] ?? category?.color ?? _themeCategoryColorHex(),
      );
    } catch (error) {
      if (mounted) {
        setState(
          () =>
              _error = beanFriendlyErrorMessage(error, action: 'save category'),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _deleteCategory(BeanEventCategory category) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Delete category?'),
        content: Text(
          'This removes "${category.name}" from existing events, tasks, and reminders.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.onDeleted(category);
    } catch (error) {
      if (mounted) {
        setState(
          () => _error = beanFriendlyErrorMessage(
            error,
            action: 'delete category',
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Container(
    key: const Key('categories-settings-card'),
    margin: const EdgeInsets.only(top: 4),
    padding: const EdgeInsets.only(top: 14, bottom: 12),
    decoration: _sectionDividerDecoration(),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(Icons.sell_outlined, color: HeyBeanTheme.muted),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Categories',
                    style: TextStyle(fontWeight: FontWeight.w600),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    'Manage the colors Bean uses for events, tasks, and reminders.',
                    style: TextStyle(
                      color: HeyBeanTheme.muted,
                      fontSize: 12,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ],
              ),
            ),
            FilledButton.icon(
              key: const Key('settings-add-category-action'),
              onPressed: _busy ? null : () => unawaited(_editCategory()),
              icon: const Icon(Icons.add_rounded, size: 18),
              label: const Text('Add'),
            ),
          ],
        ),
        if (_error != null) ...[
          const SizedBox(height: 8),
          Text(
            _error!,
            key: const Key('settings-category-error'),
            style: const TextStyle(
              color: Color(0xFFB42318),
              fontSize: 12,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
        const SizedBox(height: 10),
        if (widget.categories.isEmpty)
          Text(
            'No custom categories yet.',
            style: TextStyle(
              color: HeyBeanTheme.muted,
              fontWeight: FontWeight.w600,
            ),
          )
        else
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final category in widget.categories)
                InputChip(
                  key: Key('settings-category-${category.id}'),
                  avatar: CircleAvatar(
                    radius: 6,
                    backgroundColor: _safeCategoryColor(category.color),
                  ),
                  label: Text(category.name),
                  onPressed: _busy
                      ? null
                      : () => unawaited(_editCategory(category)),
                  onDeleted: _busy
                      ? null
                      : () => unawaited(_deleteCategory(category)),
                ),
            ],
          ),
      ],
    ),
  );
}

class _TimezonePreferencesCard extends StatefulWidget {
  const _TimezonePreferencesCard({
    required this.timezone,
    required this.onChanged,
  });

  final String? timezone;
  final Future<void> Function(String timezone) onChanged;

  @override
  State<_TimezonePreferencesCard> createState() =>
      _TimezonePreferencesCardState();
}

class _TimezonePreferencesCardState extends State<_TimezonePreferencesCard> {
  late final TextEditingController _controller;
  bool _saving = false;
  String? _message;
  String? _error;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: widget.timezone ?? '');
  }

  @override
  void didUpdateWidget(covariant _TimezonePreferencesCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!_saving && widget.timezone != oldWidget.timezone) {
      _controller.text = widget.timezone ?? '';
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _useDeviceTimezone() async {
    final timezone = await _deviceIanaTimezone();
    if (timezone == null || timezone.isEmpty) return;
    _controller.text = timezone;
    await _save();
  }

  Future<void> _save() async {
    final timezone = _controller.text.trim();
    if (timezone.isEmpty || _saving) return;
    setState(() {
      _saving = true;
      _error = null;
      _message = null;
    });
    try {
      await widget.onChanged(timezone);
      if (mounted) setState(() => _message = 'Timezone saved.');
    } catch (error) {
      if (mounted) {
        setState(
          () =>
              _error = beanFriendlyErrorMessage(error, action: 'save timezone'),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => Container(
    key: const Key('timezone-preferences-card'),
    margin: const EdgeInsets.only(top: 4),
    padding: const EdgeInsets.only(top: 14, bottom: 12),
    decoration: _sectionDividerDecoration(),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(Icons.schedule_rounded, color: HeyBeanTheme.muted),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Timezone',
                    style: TextStyle(fontWeight: FontWeight.w600),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    'Bean uses this for all due dates, reminders, events, and voice answers.',
                    style: TextStyle(
                      color: HeyBeanTheme.muted,
                      fontSize: 12,
                      fontWeight: FontWeight.w500,
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
              child: TextField(
                key: const Key('timezone-input'),
                controller: _controller,
                textInputAction: TextInputAction.done,
                onSubmitted: (_) => unawaited(_save()),
                decoration: const InputDecoration(
                  labelText: 'IANA timezone',
                  hintText: 'America/New_York',
                ),
              ),
            ),
            const SizedBox(width: 8),
            OutlinedButton(
              key: const Key('timezone-use-device-action'),
              onPressed: _saving ? null : () => unawaited(_useDeviceTimezone()),
              child: const Text('Device'),
            ),
            const SizedBox(width: 8),
            FilledButton(
              key: const Key('timezone-save-action'),
              onPressed: _saving ? null : () => unawaited(_save()),
              child: _saving
                  ? const SizedBox.square(
                      dimension: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Text('Save'),
            ),
          ],
        ),
        if (_message != null || _error != null) ...[
          const SizedBox(height: 8),
          Text(
            _error ?? _message!,
            key: const Key('timezone-save-status'),
            style: TextStyle(
              color: _error == null
                  ? HeyBeanTheme.muted
                  : const Color(0xFFB42318),
              fontSize: 12,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ],
    ),
  );
}

class _WorkspacesSettingsCard extends StatefulWidget {
  const _WorkspacesSettingsCard({
    required this.apiClient,
    required this.launchExternalUrl,
    required this.user,
    required this.onChanged,
    this.googleCalendarStatus,
  });

  final BeanApiClient apiClient;
  final ExternalUrlLauncher launchExternalUrl;
  final BeanUser user;
  final GoogleCalendarSyncStatus? googleCalendarStatus;
  final Future<void> Function() onChanged;

  @override
  State<_WorkspacesSettingsCard> createState() =>
      _WorkspacesSettingsCardState();
}

class _WorkspacesSettingsCardState extends State<_WorkspacesSettingsCard> {
  late Future<List<BeanWorkspace>> _workspacesFuture;
  String? _message;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _workspacesFuture = _loadWorkspaces();
  }

  Future<List<BeanWorkspace>> _loadWorkspaces() async {
    try {
      final workspaces = await widget.apiClient.listWorkspaces();
      if (workspaces.isNotEmpty) return workspaces;
    } catch (_) {}
    if (widget.user.workspaces.isNotEmpty) return widget.user.workspaces;
    final personal =
        widget.user.personalWorkspace ??
        BeanWorkspace(
          id: (widget.user.defaultWorkspaceId ?? 0).toString(),
          name: 'Personal',
          type: 'personal',
          role: 'owner',
          active: true,
          isDefault: true,
        );
    return [personal];
  }

  void _reload() {
    setState(() {
      _workspacesFuture = _loadWorkspaces();
    });
  }

  Future<T?> _run<T>(Future<T> Function() action, String success) async {
    setState(() {
      _busy = true;
      _message = null;
    });
    try {
      final result = await action();
      if (!mounted) return result;
      setState(() => _message = success);
      _reload();
      await widget.onChanged();
      return result;
    } catch (error) {
      if (mounted) {
        setState(
          () => _message = beanFriendlyErrorMessage(
            error,
            action: 'finish that workspace action',
          ),
        );
      }
      return null;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _createHousehold() async {
    final name = await showDialog<String>(
      context: context,
      builder: (context) => const _WorkspaceTextInputDialog(
        title: 'Create Workspace',
        labelText: 'Workspace name',
        fieldKey: Key('workspace-create-name-field'),
        submitKey: Key('workspace-create-save'),
        submitLabel: 'Save',
      ),
    );
    if (name == null || name.trim().isEmpty) return;
    await _run(
      () => widget.apiClient.createWorkspace(name: name.trim()),
      'Workspace created.',
    );
  }

  Future<void> _inviteMember(BeanWorkspace workspace) async {
    final workspaceId = workspace.numericId;
    if (workspaceId == null) return;
    final email = await showDialog<String>(
      context: context,
      builder: (context) => _WorkspaceTextInputDialog(
        title: 'Invite to ${workspace.name}',
        labelText: 'Email',
        fieldKey: Key('workspace-invite-email-${workspace.id}'),
        submitKey: Key('workspace-invite-save-${workspace.id}'),
        submitLabel: 'Invite',
        keyboardType: TextInputType.emailAddress,
      ),
    );
    if (email == null || email.trim().isEmpty) return;
    final membership = await _run(
      () => widget.apiClient.inviteWorkspaceMember(
        workspaceId,
        email: email.trim(),
      ),
      'Invitation sent.',
    );
    if (!mounted || membership == null) return;
    await _showInvitationLinkDialog(membership);
  }

  Future<void> _showInvitationLinkDialog(
    BeanWorkspaceMembership membership,
  ) async {
    final link = membership.invitationAcceptUrl;
    if (link == null || link.trim().isEmpty) return;
    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Invitation sent'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Share this invite link if the email does not arrive.'),
            const SizedBox(height: 12),
            SelectableText(
              link,
              key: const Key('workspace-invite-share-link'),
              style: TextStyle(fontSize: 13),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: Text('Done'),
          ),
          FilledButton.icon(
            key: const Key('workspace-invite-copy-link'),
            onPressed: () async {
              await Clipboard.setData(ClipboardData(text: link));
              if (context.mounted) Navigator.of(context).pop();
              if (mounted) {
                setState(() => _message = 'Invitation link copied.');
              }
            },
            icon: Icon(Icons.copy_rounded),
            label: Text('Copy link'),
          ),
        ],
      ),
    );
  }

  Future<void> _acceptInvitation() async {
    final input = await showDialog<String>(
      context: context,
      builder: (context) => const _WorkspaceTextInputDialog(
        title: 'Accept workspace invitation',
        labelText: 'Invitation token or link',
        fieldKey: Key('workspace-accept-invitation-token'),
        submitKey: Key('workspace-accept-invitation-save'),
        submitLabel: 'Accept',
        keyboardType: TextInputType.url,
      ),
    );
    final token = _workspaceInvitationTokenFromInput(input ?? '');
    if (token == null) return;
    await _run(
      () => widget.apiClient.acceptWorkspaceInvitation(token),
      'Invitation accepted.',
    );
  }

  String? _workspaceInvitationTokenFromInput(String input) {
    final trimmed = input.trim();
    if (trimmed.isEmpty) return null;
    final uri = Uri.tryParse(trimmed);
    final segments = uri?.pathSegments ?? const <String>[];
    final invitationIndex = segments.indexOf('workspace-invitations');
    if (invitationIndex >= 0 && invitationIndex + 1 < segments.length) {
      final token = segments[invitationIndex + 1].trim();
      if (token.isNotEmpty) return token;
    }
    return trimmed;
  }

  Future<void> _renameWorkspace(BeanWorkspace workspace) async {
    final workspaceId = workspace.numericId;
    if (workspaceId == null || workspace.isPersonal) return;
    final name = await showDialog<String>(
      context: context,
      builder: (context) => _WorkspaceTextInputDialog(
        title: 'Rename household',
        labelText: 'Household name',
        fieldKey: Key('workspace-rename-field-${workspace.id}'),
        submitKey: Key('workspace-rename-save-${workspace.id}'),
        submitLabel: 'Save',
        initialValue: workspace.name,
      ),
    );
    if (name == null || name.trim().isEmpty) return;
    await _run(
      () => widget.apiClient.updateWorkspace(workspaceId, name: name.trim()),
      'Workspace renamed.',
    );
  }

  Future<void> _syncAllFromPersonal(
    BeanWorkspace target,
    List<BeanWorkspace> workspaces,
  ) async {
    final source =
        widget.user.personalWorkspace ??
        workspaces.firstWhere(
          (workspace) => workspace.isPersonal,
          orElse: () => workspaces.first,
        );
    if (source.numericId == null || target.numericId == null) return;
    if (source.id == target.id) return;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Sync all from my personal workspace'),
        content: Text(
          'Copy all current Personal tasks, reminders, and events to ${target.name}. This is a one-time sync and will not automatically sync future items.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: Text('Cancel'),
          ),
          FilledButton(
            key: Key('workspace-sync-personal-run-${target.id}'),
            onPressed: () => Navigator.of(context).pop(true),
            child: Text('Sync'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;

    final result = await _run(
      () => widget.apiClient.syncWorkspaceAll(
        source.numericId!,
        targetWorkspaceId: target.numericId!,
        resourceTypes: const ['tasks', 'reminders', 'calendar_events'],
      ),
      'Personal workspace sync completed.',
    );
    if (result != null && mounted) {
      setState(() {
        _message =
            'Copied ${result.tasks} tasks, ${result.reminders} reminders, and ${result.calendarEvents} events from Personal to ${target.name}.';
      });
    }
  }

  Future<void> _toggleGoogleCalendar(
    BeanWorkspace workspace,
    String calendarId,
    bool selected,
  ) async {
    final workspaceId = workspace.numericId;
    if (workspaceId == null) return;
    final current = workspace.googleCalendarMappings
        .map((mapping) => mapping['google_calendar_id']?.toString())
        .whereType<String>()
        .toSet();
    if (selected) {
      current.add(calendarId);
    } else {
      current.remove(calendarId);
    }
    await _run(
      () => widget.apiClient.updateWorkspaceGoogleCalendars(
        workspaceId,
        googleCalendarIds: current.toList(),
        defaultExportCalendarId: current.isEmpty ? null : current.first,
      ),
      'Workspace calendar choices saved.',
    );
  }

  String _googleCalendarAccessLabel(
    GoogleCalendarInfo calendar,
    BeanWorkspace workspace,
  ) {
    final defaultForWorkspace = workspace.googleCalendarMappings.any(
      (mapping) =>
          mapping['google_calendar_id']?.toString() == calendar.id &&
          mapping['is_default_export'] == true,
    );
    final access = calendar.canWrite ? 'Can add local events' : 'Read only';
    return defaultForWorkspace
        ? '$access · Default for new local events'
        : access;
  }

  Iterable<BeanWorkspaceMembership> _visibleMemberships(
    BeanWorkspace workspace,
  ) => workspace.memberships.where(
    (membership) =>
        membership.status != 'removed' && membership.status != 'left',
  );

  String _membershipTitle(BeanWorkspaceMembership membership) {
    final name = membership.user?.name.trim();
    if (name != null && name.isNotEmpty) return name;
    final email = membership.invitedEmail?.trim();
    if (email != null && email.isNotEmpty) return email;
    return 'Invited member';
  }

  String _membershipSubtitle(BeanWorkspaceMembership membership) {
    final email = membership.user?.email.trim().isNotEmpty == true
        ? membership.user!.email.trim()
        : membership.invitedEmail?.trim();
    if (membership.status == 'invited' || membership.status == 'pending') {
      return email == null || email.isEmpty
          ? 'Invite pending'
          : 'Invite pending - $email';
    }
    return email == null || email.isEmpty ? membership.role : email;
  }

  Future<void> _copyInviteLinkForMembership(
    BeanWorkspace workspace,
    BeanWorkspaceMembership membership,
  ) async {
    final workspaceId = workspace.numericId;
    final email = membership.invitedEmail?.trim();
    if (workspaceId == null || email == null || email.isEmpty) return;

    final refreshedMembership = await _run(
      () => widget.apiClient.inviteWorkspaceMember(workspaceId, email: email),
      'Invite link ready.',
    );
    final link = refreshedMembership?.invitationAcceptUrl;
    if (link == null || link.trim().isEmpty) return;
    await Clipboard.setData(ClipboardData(text: link));
    if (mounted) setState(() => _message = 'Invitation link copied.');
  }

  Widget _membershipStatusIcon(BeanWorkspaceMembership membership) {
    if (membership.status == 'active') {
      return Icon(
        Icons.check_circle_rounded,
        color: HeyBeanTheme.accent,
        size: 18,
      );
    }
    return Icon(
      Icons.schedule_send_rounded,
      color: HeyBeanTheme.muted,
      size: 18,
    );
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<List<BeanWorkspace>>(
    future: _workspacesFuture,
    builder: (context, snapshot) {
      final workspaces = snapshot.data ?? widget.user.workspaces;
      final activeId =
          widget.user.activeWorkspace?.id ??
          widget.user.defaultWorkspaceId?.toString();
      final googleCalendars =
          widget.googleCalendarStatus?.calendars ??
          const <GoogleCalendarInfo>[];
      return Container(
        key: const Key('workspaces-settings'),
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.symmetric(vertical: 14),
        decoration: _sectionDividerDecoration(),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  Icons.home_work_outlined,
                  color: HeyBeanTheme.accentStrong,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Workspaces',
                        style: TextStyle(fontWeight: FontWeight.w800),
                      ),
                      Text(
                        'Personal and shared spaces with their own Bean, calendar, tasks, reminders, and settings.',
                        style: TextStyle(color: HeyBeanTheme.muted),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                const _InfoIconButton(
                  key: Key('workspaces-info'),
                  title: 'Workspaces',
                  bullets: [
                    'Personal is your private space. Household workspaces are shared spaces for family plans.',
                    "Switch workspaces to see that space's Bean, calendar, tasks, reminders, and settings.",
                    'Workspace calendar choices control which connected calendars appear in that workspace.',
                  ],
                ),
                _CreateButton(
                  key: const Key('workspace-create-household-action'),
                  tooltip: 'Create workspace',
                  onPressed: _busy ? null : _createHousehold,
                ),
              ],
            ),
            if (_message != null) ...[
              const SizedBox(height: 8),
              _InlinePlanLimitError(
                message: _message!,
                launchExternalUrl: widget.launchExternalUrl,
              ),
            ],
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final workspace in workspaces)
                  ChoiceChip(
                    key: Key('workspace-switch-${workspace.id}'),
                    label: Text(
                      workspace.isPersonal ? 'Personal' : workspace.name,
                    ),
                    selected: workspace.id == activeId || workspace.active,
                    onSelected: _busy || workspace.numericId == null
                        ? null
                        : (_) => _run(
                            () => widget.apiClient.setDefaultWorkspace(
                              workspace.numericId!,
                            ),
                            'Switched to ${workspace.name}.',
                          ),
                  ),
              ],
            ),
            const SizedBox(height: 10),
            for (final workspace in workspaces)
              Container(
                key: Key('workspace-row-${workspace.id}'),
                margin: EdgeInsets.only(
                  top: workspace.id == workspaces.first.id ? 0 : 10,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            workspace.isPersonal ? 'Personal' : workspace.name,
                            style: TextStyle(fontWeight: FontWeight.w800),
                          ),
                        ),
                        Text(
                          workspace.role,
                          key: Key('workspace-role-${workspace.id}'),
                          style: TextStyle(color: HeyBeanTheme.muted),
                        ),
                        if (!workspace.isPersonal &&
                            workspace.numericId != null) ...[
                          const SizedBox(width: 8),
                          TextButton(
                            key: Key('workspace-leave-${workspace.id}'),
                            onPressed: _busy
                                ? null
                                : () => _run(
                                    () => widget.apiClient.leaveWorkspace(
                                      workspace.numericId!,
                                    ),
                                    'Left ${workspace.name}.',
                                  ),
                            child: Text('Leave'),
                          ),
                        ],
                      ],
                    ),
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        if (!workspace.isPersonal && workspace.canManageMembers)
                          TextButton(
                            key: Key('workspace-rename-${workspace.id}'),
                            onPressed: _busy
                                ? null
                                : () => _renameWorkspace(workspace),
                            child: Text('Rename'),
                          ),
                        if (workspace.canManageMembers && !workspace.isPersonal)
                          TextButton(
                            key: Key('workspace-invite-${workspace.id}'),
                            onPressed: _busy
                                ? null
                                : () => _inviteMember(workspace),
                            child: Text('Invite'),
                          ),
                      ],
                    ),
                    for (final membership in _visibleMemberships(workspace))
                      ListTile(
                        key: Key(
                          'workspace-member-${workspace.id}-${membership.id}',
                        ),
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        leading: _membershipStatusIcon(membership),
                        title: Text(_membershipTitle(membership)),
                        subtitle: Text(_membershipSubtitle(membership)),
                        trailing:
                            workspace.canManageMembers &&
                                !workspace.isPersonal &&
                                workspace.numericId != null
                            ? PopupMenuButton<String>(
                                key: Key(
                                  'workspace-member-actions-${workspace.id}-${membership.id}',
                                ),
                                onSelected: (value) {
                                  if (value == 'owner') {
                                    _run(
                                      () => widget.apiClient
                                          .updateWorkspaceMember(
                                            workspace.numericId!,
                                            membership.id,
                                            role: 'owner',
                                          ),
                                      'Member is now an owner.',
                                    );
                                  } else if (value == 'copy_link') {
                                    _copyInviteLinkForMembership(
                                      workspace,
                                      membership,
                                    );
                                  } else if (value == 'remove') {
                                    _run(
                                      () => widget.apiClient
                                          .removeWorkspaceMember(
                                            workspace.numericId!,
                                            membership.id,
                                          ),
                                      'Member removed.',
                                    );
                                  }
                                },
                                itemBuilder: (context) => [
                                  if (membership.status == 'active')
                                    const PopupMenuItem(
                                      value: 'owner',
                                      child: Text('Make owner'),
                                    ),
                                  if (membership.status == 'invited' ||
                                      membership.status == 'pending')
                                    const PopupMenuItem(
                                      value: 'copy_link',
                                      child: Text('Copy invite link'),
                                    ),
                                  const PopupMenuItem(
                                    value: 'remove',
                                    child: Text('Remove'),
                                  ),
                                ],
                              )
                            : null,
                      ),
                    if (!workspace.isPersonal &&
                        workspace.numericId != null) ...[
                      const SizedBox(height: 6),
                      OutlinedButton.icon(
                        key: Key(
                          'workspace-sync-personal-action-${workspace.id}',
                        ),
                        onPressed: _busy
                            ? null
                            : () => _syncAllFromPersonal(workspace, workspaces),
                        icon: Icon(Icons.refresh_rounded),
                        label: Text('Sync all from personal'),
                      ),
                    ],
                    if (googleCalendars.isNotEmpty) ...[
                      const SizedBox(height: 6),
                      Text(
                        'Connected calendars for this workspace',
                        style: TextStyle(fontWeight: FontWeight.w700),
                      ),
                      for (final calendar in googleCalendars)
                        CheckboxListTile(
                          key: Key(
                            'workspace-google-calendar-${workspace.id}-${calendar.id}',
                          ),
                          dense: true,
                          contentPadding: EdgeInsets.zero,
                          value: workspace.googleCalendarMappings.any(
                            (mapping) =>
                                mapping['google_calendar_id']?.toString() ==
                                calendar.id,
                          ),
                          onChanged: _busy || workspace.numericId == null
                              ? null
                              : (value) => _toggleGoogleCalendar(
                                  workspace,
                                  calendar.id,
                                  value ?? false,
                                ),
                          title: Text(calendar.summary),
                          subtitle: Text(
                            _googleCalendarAccessLabel(calendar, workspace),
                            key: Key(
                              'workspace-google-calendar-access-${workspace.id}-${calendar.id}',
                            ),
                            style: TextStyle(
                              color: HeyBeanTheme.muted,
                              fontSize: 12,
                            ),
                          ),
                        ),
                    ],
                  ],
                ),
              ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                OutlinedButton.icon(
                  key: const Key('workspace-accept-invitation-action'),
                  onPressed: _busy ? null : _acceptInvitation,
                  icon: Icon(Icons.mark_email_read_rounded),
                  label: Text('Accept invitation'),
                ),
              ],
            ),
          ],
        ),
      );
    },
  );
}

class _WorkspaceTextInputDialog extends StatefulWidget {
  const _WorkspaceTextInputDialog({
    required this.title,
    required this.labelText,
    required this.fieldKey,
    required this.submitKey,
    required this.submitLabel,
    this.initialValue = '',
    this.keyboardType,
  });

  final String title;
  final String labelText;
  final Key fieldKey;
  final Key submitKey;
  final String submitLabel;
  final String initialValue;
  final TextInputType? keyboardType;

  @override
  State<_WorkspaceTextInputDialog> createState() =>
      _WorkspaceTextInputDialogState();
}

class _WorkspaceTextInputDialogState extends State<_WorkspaceTextInputDialog> {
  late final TextEditingController _controller;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: widget.initialValue);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(widget.title),
    content: TextField(
      key: widget.fieldKey,
      controller: _controller,
      autofocus: true,
      keyboardType: widget.keyboardType,
      decoration: InputDecoration(labelText: widget.labelText),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(),
        child: Text('Cancel'),
      ),
      FilledButton(
        key: widget.submitKey,
        onPressed: () => Navigator.of(context).pop(_controller.text.trim()),
        child: Text(widget.submitLabel),
      ),
    ],
  );
}

class _CalendarPreferencesCard extends StatelessWidget {
  const _CalendarPreferencesCard({
    required this.startHour,
    required this.endHour,
    required this.onStartHourChanged,
    required this.onEndHourChanged,
  });

  final int startHour;
  final int endHour;
  final ValueChanged<int> onStartHourChanged;
  final ValueChanged<int> onEndHourChanged;

  @override
  Widget build(BuildContext context) {
    final startOptions = [for (var hour = 0; hour <= 22; hour++) hour];
    final endOptions = [
      for (var hour = startHour + 1; hour <= 23; hour++) hour,
    ];

    return Container(
      key: const Key('calendar-preferences-settings'),
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(vertical: 14),
      decoration: _sectionDividerDecoration(),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                Icons.calendar_view_day_rounded,
                color: HeyBeanTheme.accentStrong,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Calendar preferences',
                      style: TextStyle(fontWeight: FontWeight.w800),
                    ),
                    Text(
                      'Day view visible hours: ${_hourLabel(startHour)} – ${_hourLabel(endHour)}',
                      style: TextStyle(color: HeyBeanTheme.muted),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              const _InfoIconButton(
                key: Key('calendar-preferences-info'),
                title: 'Calendar preferences',
                bullets: [
                  'Start and end hours only change the visible day timeline window.',
                  'Events outside this range are still saved and can show when you expand the day window.',
                  'Use this to keep the daily view focused on the hours you actually plan around.',
                ],
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: DropdownButtonFormField<int>(
                  key: const Key('calendar-start-hour-setting'),
                  initialValue: startHour,
                  decoration: const InputDecoration(
                    labelText: 'Start hour',
                    isDense: true,
                  ),
                  items: [
                    for (final hour in startOptions)
                      DropdownMenuItem(
                        value: hour,
                        child: Text(_hourLabel(hour)),
                      ),
                  ],
                  onChanged: (value) {
                    if (value != null) onStartHourChanged(value);
                  },
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: DropdownButtonFormField<int>(
                  key: const Key('calendar-end-hour-setting'),
                  initialValue: endHour,
                  decoration: const InputDecoration(
                    labelText: 'End hour',
                    isDense: true,
                  ),
                  items: [
                    for (final hour in endOptions)
                      DropdownMenuItem(
                        value: hour,
                        child: Text(_hourLabel(hour)),
                      ),
                  ],
                  onChanged: (value) {
                    if (value != null) onEndHourChanged(value);
                  },
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _GoogleCalendarSyncCard extends StatefulWidget {
  const _GoogleCalendarSyncCard({
    required this.apiClient,
    required this.launchExternalUrl,
  });

  final BeanApiClient apiClient;
  final ExternalUrlLauncher launchExternalUrl;

  @override
  State<_GoogleCalendarSyncCard> createState() =>
      _GoogleCalendarSyncCardState();
}

class _GoogleCalendarSyncCardState extends State<_GoogleCalendarSyncCard>
    with WidgetsBindingObserver {
  late Future<List<GoogleCalendarSyncStatus>> _statusFuture;
  String? _message;
  String? _googleAuthUrl;
  String? _outlookAuthUrl;
  bool _busy = false;
  String? _waitingForProviderReturn;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _statusFuture = _loadStatuses();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed &&
        _waitingForProviderReturn != null) {
      _syncAfterProviderReturn(_waitingForProviderReturn!);
    }
  }

  Future<List<GoogleCalendarSyncStatus>> _loadStatuses() async => Future.wait([
    widget.apiClient.googleCalendarStatus(),
    widget.apiClient.outlookCalendarStatus(),
  ]);

  void _reload() {
    setState(() {
      _statusFuture = _loadStatuses();
    });
  }

  Future<void> _showConnectOptions() async {
    final provider = await showModalBottomSheet<String>(
      context: context,
      backgroundColor: Colors.transparent,
      builder: (context) => Container(
        margin: const EdgeInsets.all(10),
        decoration: BoxDecoration(
          color: HeyBeanTheme.surface,
          borderRadius: BorderRadius.circular(24),
          border: Border.all(color: HeyBeanTheme.border),
        ),
        child: SafeArea(
          top: false,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              ListTile(
                key: const Key('external-calendar-connect-google'),
                leading: Icon(Icons.calendar_month_rounded),
                title: Text('Google Calendar'),
                subtitle: Text('Connect with Google OAuth'),
                onTap: () => Navigator.of(context).pop('google'),
              ),
              ListTile(
                key: const Key('external-calendar-connect-outlook'),
                leading: Icon(Icons.mail_outline_rounded),
                title: Text('Microsoft Outlook'),
                subtitle: Text('Connect with Microsoft sign-in'),
                onTap: () => Navigator.of(context).pop('outlook'),
              ),
            ],
          ),
        ),
      ),
    );
    if (provider != null) {
      await _connect(provider);
    }
  }

  Future<void> _connect(String provider) async {
    setState(() {
      _busy = true;
      _message = null;
    });
    try {
      final rawUrl = provider == 'outlook'
          ? await widget.apiClient.outlookCalendarAuthUrl()
          : await widget.apiClient.googleCalendarAuthUrl();
      final url = Uri.parse(rawUrl);
      if (provider == 'outlook') {
        _outlookAuthUrl = rawUrl;
      } else {
        _googleAuthUrl = rawUrl;
      }
      var launched = false;
      try {
        launched = await widget.launchExternalUrl(url);
      } catch (_) {
        launched = false;
      }
      if (!launched) {
        launched = await _launchExternalUrlWithNativeFallback(url);
      }
      if (!mounted) return;
      setState(() {
        _waitingForProviderReturn = launched ? provider : null;
        _message = launched
            ? 'Finish approving ${_providerLabel(provider)} access in the browser. If a QR prompt appears in the simulator, tap Copy auth link, finish it in your browser, then tap Check connection here.'
            : 'Could not open ${_providerLabel(provider)} authorization automatically. Tap Copy auth link, finish it in any browser, then tap Check connection here.';
      });
      _reload();
    } catch (error) {
      if (mounted) {
        setState(
          () => _message = beanFriendlyErrorMessage(
            error,
            action: 'start calendar connection',
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _syncAfterProviderReturn(String provider) async {
    setState(() {
      _busy = true;
      _message = 'Checking calendar connection…';
    });
    try {
      final status = provider == 'outlook'
          ? await widget.apiClient.outlookCalendarStatus()
          : await widget.apiClient.googleCalendarStatus();
      if (!mounted) return;
      if (!status.connected) {
        setState(() {
          _statusFuture = _loadStatuses();
          _message =
              'Calendar sync is not connected yet. Finish approval in the browser, then return to HeyBean.';
        });
        return;
      }
      final result = provider == 'outlook'
          ? await widget.apiClient.syncOutlookCalendar()
          : await widget.apiClient.syncGoogleCalendar();
      if (!mounted) return;
      setState(() {
        _waitingForProviderReturn = null;
        if (provider == 'outlook') {
          _outlookAuthUrl = null;
        } else {
          _googleAuthUrl = null;
        }
        _message =
            '${_providerLabel(provider)} connected and synced ${result.imported} event${result.imported == 1 ? '' : 's'}${result.deleted > 0 ? ', removed ${result.deleted}' : ''}.';
        _statusFuture = _loadStatuses();
      });
    } catch (error) {
      if (mounted) {
        setState(
          () => _message = beanFriendlyErrorMessage(
            error,
            action: 'sync calendar after connecting',
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _copyAuthLink(String provider) async {
    final rawUrl = provider == 'outlook' ? _outlookAuthUrl : _googleAuthUrl;
    if (rawUrl == null) return;
    await Clipboard.setData(ClipboardData(text: rawUrl));
    if (!mounted) return;
    setState(() {
      _message =
          'Copied ${_providerLabel(provider)} authorization link. Open it in your browser, approve calendar access, then tap Check connection here.';
    });
  }

  Future<void> _checkConnection(String provider) =>
      _syncAfterProviderReturn(provider);

  Future<void> _sync(String provider) async {
    setState(() {
      _busy = true;
      _message = null;
    });
    try {
      final result = provider == 'outlook'
          ? await widget.apiClient.syncOutlookCalendar()
          : await widget.apiClient.syncGoogleCalendar();
      if (!mounted) return;
      setState(() {
        _message =
            '${_providerLabel(provider)} sync pulled ${result.imported} external event${result.imported == 1 ? '' : 's'} into Bean${result.deleted > 0 ? ' and removed ${result.deleted}' : ''}. Local Bean events stay local.';
        if (provider == 'outlook') {
          _outlookAuthUrl = null;
        } else {
          _googleAuthUrl = null;
        }
        _statusFuture = _loadStatuses();
      });
    } catch (error) {
      if (mounted) {
        setState(
          () => _message = beanFriendlyErrorMessage(
            error,
            action: 'sync calendar',
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _disconnect(String provider) async {
    setState(() {
      _busy = true;
      _message = null;
    });
    try {
      if (provider == 'outlook') {
        await widget.apiClient.disconnectOutlookCalendar();
      } else {
        await widget.apiClient.disconnectGoogleCalendar();
      }
      if (!mounted) return;
      setState(() {
        _message = '${_providerLabel(provider)} sync disconnected.';
        _statusFuture = _loadStatuses();
      });
    } catch (error) {
      if (mounted) {
        setState(
          () => _message = beanFriendlyErrorMessage(
            error,
            action: 'disconnect calendar sync',
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _updateSelection(
    String provider,
    List<String> selectedCalendarIds,
  ) async {
    if (selectedCalendarIds.isEmpty) return;
    setState(() {
      _busy = true;
      _message = null;
    });
    try {
      if (provider == 'outlook') {
        await widget.apiClient.updateOutlookCalendarSelection(
          selectedCalendarIds: selectedCalendarIds,
          defaultCalendarId: selectedCalendarIds.first,
        );
      } else {
        await widget.apiClient.updateGoogleCalendarSelection(
          selectedCalendarIds: selectedCalendarIds,
          defaultCalendarId: selectedCalendarIds.first,
        );
      }
      if (!mounted) return;
      setState(() {
        _message = '${_providerLabel(provider)} calendar choices saved.';
        _statusFuture = _loadStatuses();
      });
    } catch (error) {
      if (mounted) {
        setState(
          () => _message = beanFriendlyErrorMessage(
            error,
            action: 'save calendar choices',
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _providerLabel(String provider) =>
      provider == 'outlook' ? 'Microsoft Outlook' : 'Google Calendar';

  @override
  Widget build(
    BuildContext context,
  ) => FutureBuilder<List<GoogleCalendarSyncStatus>>(
    future: _statusFuture,
    builder: (context, snapshot) {
      final googleStatus = snapshot.data?.isNotEmpty == true
          ? snapshot.data![0]
          : null;
      final outlookStatus = (snapshot.data?.length ?? 0) > 1
          ? snapshot.data![1]
          : null;
      final connected =
          (googleStatus?.connected ?? false) ||
          (outlookStatus?.connected ?? false);
      return Container(
        key: const Key('google-calendar-sync-settings'),
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.symmetric(vertical: 14),
        decoration: _sectionDividerDecoration(),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.sync_rounded, color: HeyBeanTheme.accentStrong),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'External Calendar Sync',
                        style: TextStyle(fontWeight: FontWeight.w800),
                      ),
                      Text(
                        connected
                            ? 'Connected calendars pull external events into Bean. Local Bean events stay local.'
                            : 'Connect Google Calendar or Microsoft Outlook.',
                        style: TextStyle(color: HeyBeanTheme.muted),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                const _InfoIconButton(
                  key: Key('google-calendar-sync-info'),
                  title: 'External Calendar Sync',
                  bullets: [
                    'Sync now pulls selected external calendar events into Bean.',
                    'Local Bean events are not pushed to connected calendars.',
                    'Disconnecting stops future sync. It does not delete your external account or calendar.',
                  ],
                ),
              ],
            ),
            if (googleStatus?.lastError != null &&
                googleStatus!.lastError!.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(
                googleStatus.lastError!,
                style: TextStyle(color: Colors.redAccent),
              ),
            ],
            if (outlookStatus?.lastError != null &&
                outlookStatus!.lastError!.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(
                outlookStatus.lastError!,
                style: TextStyle(color: Colors.redAccent),
              ),
            ],
            if (_message != null) ...[
              const SizedBox(height: 8),
              _InlinePlanLimitError(message: _message!),
            ],
            const SizedBox(height: 12),
            _ExternalCalendarProviderTile(
              provider: 'google',
              label: 'Google Calendar',
              status: googleStatus,
              busy: _busy,
              authUrl: _googleAuthUrl,
              onCopyLink: () => _copyAuthLink('google'),
              onCheckConnection: () => _checkConnection('google'),
              onSync: () => _sync('google'),
              onDisconnect: () => _disconnect('google'),
              onSelectionChanged: (ids) => _updateSelection('google', ids),
            ),
            const SizedBox(height: 10),
            _ExternalCalendarProviderTile(
              provider: 'outlook',
              label: 'Microsoft Outlook',
              status: outlookStatus,
              busy: _busy,
              authUrl: _outlookAuthUrl,
              onCopyLink: () => _copyAuthLink('outlook'),
              onCheckConnection: () => _checkConnection('outlook'),
              onSync: () => _sync('outlook'),
              onDisconnect: () => _disconnect('outlook'),
              onSelectionChanged: (ids) => _updateSelection('outlook', ids),
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                OutlinedButton.icon(
                  key: const Key('google-calendar-connect-action'),
                  onPressed: _busy ? null : _showConnectOptions,
                  icon: Icon(Icons.login_rounded),
                  label: Text(
                    connected ? 'Connect another calendar' : 'Connect Calendar',
                  ),
                ),
              ],
            ),
          ],
        ),
      );
    },
  );
}

class _ExternalCalendarImportCard extends StatefulWidget {
  const _ExternalCalendarImportCard({
    required this.apiClient,
    required this.user,
    required this.onImported,
    this.title = 'Import External Calendar',
    this.onSkipped,
    this.compact = false,
  });

  final BeanApiClient apiClient;
  final BeanUser user;
  final Future<void> Function() onImported;
  final String title;
  final VoidCallback? onSkipped;
  final bool compact;

  @override
  State<_ExternalCalendarImportCard> createState() =>
      _ExternalCalendarImportCardState();
}

class _ExternalCalendarImportCardState
    extends State<_ExternalCalendarImportCard> {
  final TextEditingController _urlController = TextEditingController();
  late Future<List<ExternalCalendarProviderPreset>> _providersFuture;
  ExternalCalendarProviderPreset? _selectedProvider;
  bool _busy = false;
  String? _error;
  String? _message;

  int? get _workspaceId =>
      widget.user.activeWorkspace?.numericId ?? widget.user.defaultWorkspaceId;

  String get _workspaceName =>
      widget.user.activeWorkspace?.name ??
      _workspaceNameForId(widget.user.workspaces, _workspaceId) ??
      'current workspace';

  @override
  void initState() {
    super.initState();
    _providersFuture = widget.apiClient.listExternalCalendarProviders();
  }

  @override
  void dispose() {
    _urlController.dispose();
    super.dispose();
  }

  Future<void> _pasteFromClipboard() async {
    final data = await Clipboard.getData(Clipboard.kTextPlain);
    final text = data?.text?.trim();
    if (text == null || text.isEmpty) return;
    setState(() {
      _urlController.text = text;
      _error = null;
    });
  }

  Future<void> _import() async {
    final url = _urlController.text.trim();
    if (url.isEmpty || _busy) {
      setState(() => _error = 'Paste a calendar link first.');
      return;
    }
    final provider = _selectedProvider;
    if (provider == null) {
      setState(() => _error = 'Choose a calendar provider first.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
      _message = null;
    });

    try {
      final result = await widget.apiClient.importExternalCalendar(
        providerKey: provider.key,
        url: url,
        workspaceId: _workspaceId,
      );
      if (!mounted) return;
      setState(() {
        _message = _externalImportMessage(result, provider);
        _urlController.clear();
      });
      await widget.onImported();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = beanFriendlyErrorMessage(
          error,
          action: 'import external calendar',
        );
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _externalImportMessage(
    ExternalCalendarImportResult result,
    ExternalCalendarProviderPreset provider,
  ) {
    final created = result.imported;
    final updated = result.updated;
    final deleted = result.deleted;
    final skipped = result.skipped;
    final parts = <String>[
      if (created > 0) '$created new',
      if (updated > 0) '$updated updated',
      if (deleted > 0) '$deleted removed',
      if (skipped > 0) '$skipped skipped',
    ];
    final summary = parts.isEmpty ? 'No changes' : parts.join(', ');
    return '$summary from ${result.providerLabel ?? provider.label} into $_workspaceName.';
  }

  String? _workspaceNameForId(List<BeanWorkspace> workspaces, int? id) {
    if (id == null) return null;
    for (final workspace in workspaces) {
      if (workspace.numericId == id) return workspace.name;
    }

    return null;
  }

  ExternalCalendarProviderPreset _defaultProvider(
    List<ExternalCalendarProviderPreset> providers,
  ) {
    final selected = _selectedProvider;
    if (selected != null && providers.any((item) => item.key == selected.key)) {
      return selected;
    }
    return providers.firstWhere(
      (provider) => provider.key == 'apple',
      orElse: () => providers.first,
    );
  }

  IconData _providerIcon(String key) => switch (key) {
    'apple' => Icons.phone_iphone_rounded,
    'google' => Icons.calendar_month_rounded,
    'outlook' => Icons.mail_outline_rounded,
    'proton' => Icons.lock_outline_rounded,
    'yahoo' => Icons.alternate_email_rounded,
    'fastmail' => Icons.mark_email_read_rounded,
    'nextcloud' => Icons.cloud_queue_rounded,
    _ => Icons.event_note_rounded,
  };

  @override
  Widget build(
    BuildContext context,
  ) => FutureBuilder<List<ExternalCalendarProviderPreset>>(
    future: _providersFuture,
    builder: (context, snapshot) {
      final providers = snapshot.data ?? const [];
      final loading = snapshot.connectionState != ConnectionState.done;
      final selected = providers.isEmpty ? null : _defaultProvider(providers);
      if (_selectedProvider == null && selected != null) {
        _selectedProvider = selected;
      }

      return Container(
        key: const Key('external-calendar-import-settings'),
        margin: EdgeInsets.only(bottom: widget.compact ? 0 : 10),
        padding: widget.compact
            ? const EdgeInsets.all(0)
            : const EdgeInsets.symmetric(vertical: 14),
        decoration: widget.compact ? null : _sectionDividerDecoration(),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: HeyBeanTheme.accent.withValues(alpha: .10),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(
                      color: HeyBeanTheme.accent.withValues(alpha: .24),
                    ),
                  ),
                  child: Icon(
                    Icons.calendar_month_rounded,
                    color: HeyBeanTheme.accentStrong,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        widget.title,
                        style: TextStyle(
                          color: HeyBeanTheme.text,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        selected == null
                            ? 'Choose a provider, paste a public calendar link, and import events into $_workspaceName.'
                            : '${selected.description} Imports go into $_workspaceName.',
                        style: TextStyle(
                          color: HeyBeanTheme.muted,
                          fontWeight: FontWeight.w500,
                          height: 1.25,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                _InfoIconButton(
                  key: const Key('external-calendar-import-info'),
                  title: widget.title,
                  bullets: selected?.instructions.isNotEmpty == true
                      ? selected!.instructions
                      : const [
                          'Choose the calendar app you use.',
                          'Paste a public iCal, ICS, or webcal link.',
                          'The link is used for import only. HeyBean does not get account access.',
                        ],
                ),
              ],
            ),
            const SizedBox(height: 12),
            if (loading)
              const LinearProgressIndicator(minHeight: 3)
            else if (providers.isEmpty)
              _InlinePlanLimitError(
                message:
                    'Calendar import options could not load. Try again in a moment.',
              )
            else ...[
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final provider in providers)
                    ChoiceChip(
                      key: Key('external-calendar-provider-${provider.key}'),
                      selected: selected?.key == provider.key,
                      avatar: Icon(_providerIcon(provider.key), size: 18),
                      label: Text(provider.label),
                      onSelected: _busy
                          ? null
                          : (_) => setState(() {
                              _selectedProvider = provider;
                              _error = null;
                            }),
                    ),
                ],
              ),
              const SizedBox(height: 12),
              TextField(
                key: const Key('external-calendar-url-field'),
                controller: _urlController,
                enabled: !_busy,
                keyboardType: TextInputType.url,
                textInputAction: TextInputAction.done,
                onSubmitted: (_) => _import(),
                decoration: InputDecoration(
                  labelText: selected?.linkLabel ?? 'Calendar link',
                  hintText:
                      selected?.linkHint ?? 'webcal://example.com/calendar.ics',
                  prefixIcon: Icon(Icons.link_rounded),
                  suffixIcon: IconButton(
                    key: const Key('external-calendar-paste-action'),
                    tooltip: 'Paste link',
                    onPressed: _busy ? null : _pasteFromClipboard,
                    icon: Icon(Icons.content_paste_rounded),
                  ),
                ),
              ),
            ],
            if (_error != null) ...[
              const SizedBox(height: 10),
              _InlinePlanLimitError(message: _error!),
            ],
            if (_message != null) ...[
              const SizedBox(height: 10),
              _SuccessNotice(message: _message!),
            ],
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                FilledButton.icon(
                  key: const Key('external-calendar-import-action'),
                  onPressed: _busy || providers.isEmpty ? null : _import,
                  icon: _busy
                      ? const SizedBox.square(
                          dimension: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : Icon(Icons.file_download_done_rounded),
                  label: Text(
                    _busy
                        ? 'Importing...'
                        : 'Import ${selected?.label ?? 'Calendar'}',
                  ),
                ),
                if (widget.onSkipped != null)
                  TextButton(
                    key: const Key('external-calendar-skip-action'),
                    onPressed: _busy ? null : widget.onSkipped,
                    child: Text('Skip for now'),
                  ),
              ],
            ),
          ],
        ),
      );
    },
  );
}

class _ExternalCalendarProviderTile extends StatelessWidget {
  const _ExternalCalendarProviderTile({
    required this.provider,
    required this.label,
    required this.status,
    required this.busy,
    required this.authUrl,
    required this.onCopyLink,
    required this.onCheckConnection,
    required this.onSync,
    required this.onDisconnect,
    required this.onSelectionChanged,
  });

  final String provider;
  final String label;
  final GoogleCalendarSyncStatus? status;
  final bool busy;
  final String? authUrl;
  final VoidCallback onCopyLink;
  final VoidCallback onCheckConnection;
  final VoidCallback onSync;
  final VoidCallback onDisconnect;
  final ValueChanged<List<String>> onSelectionChanged;

  @override
  Widget build(BuildContext context) {
    final connected = status?.connected ?? false;
    return DecoratedBox(
      decoration: BoxDecoration(
        border: Border.all(color: HeyBeanTheme.border),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  provider == 'outlook'
                      ? Icons.mail_outline_rounded
                      : Icons.calendar_month_rounded,
                  color: HeyBeanTheme.accentStrong,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    connected
                        ? '$label connected${status?.lastSyncedAt == null ? '' : ' · last sync ${_formatCalendarEventDateTime(status?.lastSyncedAt)}'}'
                        : '$label not connected',
                    style: TextStyle(fontWeight: FontWeight.w800),
                  ),
                ),
              ],
            ),
            if (authUrl != null) ...[
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  OutlinedButton.icon(
                    key: Key('$provider-calendar-copy-link-action'),
                    onPressed: busy ? null : onCopyLink,
                    icon: Icon(Icons.copy_rounded),
                    label: Text('Copy auth link'),
                  ),
                  OutlinedButton.icon(
                    key: Key('$provider-calendar-check-connection-action'),
                    onPressed: busy ? null : onCheckConnection,
                    icon: Icon(Icons.verified_rounded),
                    label: Text('Check connection'),
                  ),
                ],
              ),
            ],
            if (connected) ...[
              const SizedBox(height: 8),
              Text(
                'Sync now pulls selected external events into Bean. Local Bean events stay local.',
                style: TextStyle(color: HeyBeanTheme.muted),
              ),
              if ((status?.calendars ?? const <GoogleCalendarInfo>[])
                  .isNotEmpty) ...[
                const SizedBox(height: 6),
                for (final calendar in status!.calendars)
                  CheckboxListTile(
                    key: Key(
                      provider == 'google'
                          ? 'google-calendar-source-${calendar.id}'
                          : 'outlook-calendar-source-${calendar.id}',
                    ),
                    contentPadding: EdgeInsets.zero,
                    value: calendar.selected,
                    onChanged: busy
                        ? null
                        : (value) {
                            final selected = {...status!.selectedCalendarIds};
                            if (value ?? false) {
                              selected.add(calendar.id);
                            } else {
                              selected.remove(calendar.id);
                            }
                            if (selected.isEmpty) {
                              selected.add(calendar.id);
                            }
                            onSelectionChanged(selected.toList()..sort());
                          },
                    title: Text(calendar.summary),
                    subtitle: Text(calendar.accessRole),
                    controlAffinity: ListTileControlAffinity.leading,
                  ),
              ],
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  FilledButton.icon(
                    key: Key('$provider-calendar-sync-action'),
                    onPressed: busy ? null : onSync,
                    icon: busy
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Icon(Icons.refresh_rounded),
                    label: Text('Sync now'),
                  ),
                  TextButton(
                    key: Key('$provider-calendar-disconnect-action'),
                    onPressed: busy ? null : onDisconnect,
                    child: Text('Disconnect'),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}
