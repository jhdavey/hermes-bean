part of '../../main.dart';

class _DeleteAccountConfirmationDialog extends StatefulWidget {
  const _DeleteAccountConfirmationDialog();

  @override
  State<_DeleteAccountConfirmationDialog> createState() =>
      _DeleteAccountConfirmationDialogState();
}

class _DeleteAccountConfirmationDialogState
    extends State<_DeleteAccountConfirmationDialog> {
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _submit() {
    Navigator.of(context).pop(_controller.text.trim() == 'DELETE');
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text('Delete account permanently?'),
    content: SingleChildScrollView(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'This permanently deletes your HeyBean account, assistant history, tasks, reminders, calendar events, and account data. Export anything you need before continuing.',
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('delete-account-confirmation-field'),
            controller: _controller,
            textInputAction: TextInputAction.done,
            onSubmitted: (_) => _submit(),
            decoration: const InputDecoration(
              labelText: 'Type DELETE to confirm',
            ),
          ),
        ],
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(false),
        child: Text('Cancel'),
      ),
      FilledButton(
        key: const Key('delete-account-confirmation-submit'),
        style: _destructiveFilledButtonStyle(),
        onPressed: _submit,
        child: Text('Delete account'),
      ),
    ],
  );
}

class _AccountCard extends StatelessWidget {
  const _AccountCard({
    required this.user,
    required this.onEmailChanged,
    required this.onDeleteAccount,
    required this.onSignOut,
    required this.launchExternalUrl,
    this.showLegalLinks = true,
    this.beforeAccountActions,
  });

  final HermesUser user;
  final Future<void> Function(String email) onEmailChanged;
  final Future<void> Function() onDeleteAccount;
  final Future<void> Function() onSignOut;
  final ExternalUrlLauncher launchExternalUrl;
  final bool showLegalLinks;
  final Widget? beforeAccountActions;

  Future<void> _editEmail(BuildContext context) async {
    final nextEmail = await _showEmailEditor(context, initialEmail: user.email);
    if (nextEmail == null || nextEmail.trim() == user.email) return;
    await onEmailChanged(nextEmail);
  }

  Future<bool> _confirmDeleteAccount(BuildContext context) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => const _DeleteAccountConfirmationDialog(),
    );
    return confirmed == true;
  }

  Future<void> _requestDeleteAccount(BuildContext context) async {
    if (await _confirmDeleteAccount(context)) {
      await onDeleteAccount();
    }
  }

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.settings_rounded,
          title: 'Profile',
          subtitle: 'Account and app settings',
          infoKey: Key('profile-info'),
          infoTitle: 'Profile and account',
          infoBullets: [
            'Edit your email here if your sign-in address changes.',
            'Privacy Policy, Terms of Use, and Support links open the hosted HeyBean pages.',
            'Delete account permanently removes your HeyBean account and data after confirmation.',
          ],
        ),
        const SizedBox(height: 10),
        _CompactItemTile(
          icon: Icons.email_outlined,
          title: 'Email',
          subtitle: user.email,
          trailing: TextButton(
            key: const Key('settings-edit-email-action'),
            onPressed: () => _editEmail(context),
            child: Text('Edit'),
          ),
        ),
        const SizedBox(height: 10),
        if (beforeAccountActions != null) ...[
          beforeAccountActions!,
          const SizedBox(height: 10),
        ],
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            OutlinedButton.icon(
              key: const Key('sign-out-action'),
              onPressed: onSignOut,
              icon: Icon(Icons.logout_rounded),
              label: Text('Sign out'),
            ),
            FilledButton.icon(
              key: const Key('delete-account-action'),
              style: _destructiveFilledButtonStyle(),
              onPressed: () => _requestDeleteAccount(context),
              icon: Icon(Icons.delete_outline_rounded),
              label: Text('Delete account'),
            ),
          ],
        ),
        if (showLegalLinks) ...[
          const SizedBox(height: 10),
          _SettingsLegalLinksRow(launchExternalUrl: launchExternalUrl),
        ],
      ],
    ),
  );
}

class _SettingsLegalLinksRow extends StatelessWidget {
  const _SettingsLegalLinksRow({required this.launchExternalUrl});

  final ExternalUrlLauncher launchExternalUrl;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final style = theme.textTheme.bodyMedium?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      fontWeight: FontWeight.w400,
    );
    final buttonStyle = TextButton.styleFrom(
      foregroundColor: theme.colorScheme.onSurfaceVariant,
      minimumSize: Size.zero,
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
      textStyle: style,
    );

    return Align(
      alignment: Alignment.center,
      child: Wrap(
        alignment: WrapAlignment.center,
        spacing: 12,
        runSpacing: 6,
        children: [
          TextButton(
            key: const Key('settings-privacy-policy-link'),
            style: buttonStyle,
            onPressed: () => launchExternalUrl(_privacyPolicyUrl),
            child: Text('Privacy Policy'),
          ),
          TextButton(
            key: const Key('settings-terms-of-service-link'),
            style: buttonStyle,
            onPressed: () => launchExternalUrl(_termsOfServiceUrl),
            child: Text('Terms of Use'),
          ),
          TextButton(
            key: const Key('settings-support-link'),
            style: buttonStyle,
            onPressed: () => launchExternalUrl(_supportUrl),
            child: Text('Support'),
          ),
        ],
      ),
    );
  }
}

Future<String?> _showEmailEditor(
  BuildContext context, {
  required String initialEmail,
}) => showDialog<String>(
  context: context,
  builder: (context) => _EmailEditorDialog(initialEmail: initialEmail),
);

class _EmailEditorDialog extends StatefulWidget {
  const _EmailEditorDialog({required this.initialEmail});

  final String initialEmail;

  @override
  State<_EmailEditorDialog> createState() => _EmailEditorDialogState();
}

class _EmailEditorDialogState extends State<_EmailEditorDialog> {
  late final TextEditingController _controller;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: widget.initialEmail);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text('Update email'),
    content: TextField(
      key: const Key('settings-email-editor-field'),
      controller: _controller,
      keyboardType: TextInputType.emailAddress,
      autofocus: true,
      decoration: const InputDecoration(labelText: 'Email address'),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(),
        child: Text('Cancel'),
      ),
      FilledButton(
        key: const Key('settings-email-editor-save'),
        onPressed: () => Navigator.of(context).pop(_controller.text.trim()),
        child: Text('Save'),
      ),
    ],
  );
}
