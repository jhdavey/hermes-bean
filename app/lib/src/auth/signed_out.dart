part of '../../main.dart';

class _SignedOutScreen extends StatefulWidget {
  const _SignedOutScreen({
    required this.onLogin,
    required this.onStartSignup,
    required this.onForgotPassword,
    required this.tokenStore,
    required this.launchExternalUrl,
    required this.busy,
    this.error,
    this.notice,
  });

  final Future<void> Function(
    String email,
    String password, {
    required bool rememberMe,
  })
  onLogin;
  final VoidCallback onStartSignup;
  final _ForgotPasswordHandler onForgotPassword;
  final AuthTokenStore tokenStore;
  final ExternalUrlLauncher launchExternalUrl;
  final bool busy;
  final String? error;
  final String? notice;

  @override
  State<_SignedOutScreen> createState() => _SignedOutScreenState();
}

class _SignedOutScreenState extends State<_SignedOutScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _rememberMe = false;

  @override
  void initState() {
    super.initState();
    widget.tokenStore.loadRememberMe().then((rememberMe) {
      if (mounted) setState(() => _rememberMe = rememberMe);
    });
  }

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _showForgotPasswordDialog() async {
    await showDialog<void>(
      context: context,
      builder: (context) => _ForgotPasswordDialog(
        initialEmail: _email.text,
        onSubmit: widget.onForgotPassword,
      ),
    );
  }

  Future<void> _submit() {
    return widget.onLogin(_email.text, _password.text, rememberMe: _rememberMe);
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 16),
        child: Center(
          child: ConstrainedBox(
            constraints: BoxConstraints(
              minHeight: constraints.maxHeight - 32,
              maxWidth: 440,
            ),
            child: Center(
              child: KeyedSubtree(
                key: const Key('login-card'),
                child: _ShellCard(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Column(
                        key: const Key('login-header'),
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.center,
                        children: [
                          Row(
                            mainAxisSize: MainAxisSize.max,
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              ClipRRect(
                                borderRadius: BorderRadius.circular(8),
                                child: Image.asset(
                                  'assets/images/bean/bean-logo.png',
                                  key: const Key('login-header-logo'),
                                  width: 28,
                                  height: 28,
                                ),
                              ),
                              const SizedBox(width: 10),
                              Flexible(
                                child: Text(
                                  'Login',
                                  textAlign: TextAlign.center,
                                  softWrap: true,
                                  style: Theme.of(context).textTheme.titleMedium
                                      ?.copyWith(fontWeight: FontWeight.w800),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                      const SizedBox(height: 16),
                      TextField(
                        key: const Key('auth-email'),
                        controller: _email,
                        keyboardType: TextInputType.emailAddress,
                        textInputAction: TextInputAction.next,
                        decoration: const InputDecoration(labelText: 'Email'),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        key: const Key('auth-password'),
                        controller: _password,
                        obscureText: true,
                        textInputAction: TextInputAction.done,
                        onSubmitted: (_) => widget.busy ? null : _submit(),
                        decoration: const InputDecoration(
                          labelText: 'Password',
                        ),
                      ),
                      const SizedBox(height: 8),
                      CheckboxListTile(
                        key: const Key('remember-me-checkbox'),
                        value: _rememberMe,
                        onChanged: widget.busy
                            ? null
                            : (value) =>
                                  setState(() => _rememberMe = value ?? false),
                        title: Text('Remember me'),
                        contentPadding: EdgeInsets.zero,
                        controlAffinity: ListTileControlAffinity.leading,
                        dense: true,
                      ),
                      if (widget.error != null) ...[
                        const SizedBox(height: 12),
                        Text(
                          widget.error!,
                          style: TextStyle(color: Colors.redAccent),
                        ),
                      ],
                      if (widget.notice != null) ...[
                        const SizedBox(height: 12),
                        Text(
                          widget.notice!,
                          key: const Key('auth-notice'),
                          style: TextStyle(
                            color: HeyBeanTheme.accentStrong,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ],
                      const SizedBox(height: 16),
                      FilledButton(
                        key: const Key('auth-submit'),
                        onPressed: widget.busy ? null : _submit,
                        child: Text(widget.busy ? 'Signing in…' : 'Sign in'),
                      ),
                      const SizedBox(height: 14),
                      OutlinedButton.icon(
                        key: const Key('guided-signup-action'),
                        onPressed: widget.busy ? null : widget.onStartSignup,
                        icon: Icon(Icons.auto_awesome_rounded),
                        label: Text('Sign up with Bean'),
                        style: OutlinedButton.styleFrom(
                          minimumSize: const Size.fromHeight(54),
                          textStyle: const TextStyle(
                            fontSize: 17,
                            fontWeight: FontWeight.w900,
                          ),
                          side: BorderSide(
                            color: HeyBeanTheme.accent.withValues(alpha: .42),
                            width: 1.4,
                          ),
                          backgroundColor: HeyBeanTheme.accent.withValues(
                            alpha: HeyBeanTheme.isDark ? .12 : .08,
                          ),
                          foregroundColor: HeyBeanTheme.accentStrong,
                        ),
                      ),
                      const SizedBox(height: 6),
                      TextButton(
                        key: const Key('forgot-login-action'),
                        onPressed: widget.busy
                            ? null
                            : _showForgotPasswordDialog,
                        child: Text('Forgot password?'),
                      ),
                      const SizedBox(height: 8),
                      Wrap(
                        alignment: WrapAlignment.center,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        spacing: 8,
                        runSpacing: 4,
                        children: [
                          TextButton(
                            key: const Key('privacy-policy-link'),
                            onPressed: widget.busy
                                ? null
                                : () => widget.launchExternalUrl(
                                    _privacyPolicyUrl,
                                  ),
                            child: Text('Privacy'),
                          ),
                          TextButton(
                            key: const Key('terms-of-service-link'),
                            onPressed: widget.busy
                                ? null
                                : () => widget.launchExternalUrl(
                                    _termsOfServiceUrl,
                                  ),
                            child: Text('Terms'),
                          ),
                          TextButton(
                            key: const Key('support-link'),
                            onPressed: widget.busy
                                ? null
                                : () => widget.launchExternalUrl(_supportUrl),
                            child: Text('Support'),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _ForgotPasswordDialog extends StatefulWidget {
  const _ForgotPasswordDialog({
    required this.initialEmail,
    required this.onSubmit,
  });

  final String initialEmail;
  final _ForgotPasswordHandler onSubmit;

  @override
  State<_ForgotPasswordDialog> createState() => _ForgotPasswordDialogState();
}

class _ForgotPasswordDialogState extends State<_ForgotPasswordDialog> {
  late final TextEditingController _email;
  bool _sending = false;
  bool _sent = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _email = TextEditingController(text: widget.initialEmail.trim());
  }

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      await widget.onSubmit(_email.text.trim());
      if (!mounted) return;
      setState(() => _sent = true);
    } catch (error) {
      if (!mounted) return;
      setState(
        () => _error = beanFriendlyErrorMessage(
          error,
          action: 'send a password reset link',
        ),
      );
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_sent) {
      return AlertDialog(
        title: Text('Check your email'),
        content: Text(
          'If that email matches a HeyBean account, we sent a password reset link. After you reset it, come back here and sign in with your new password.',
        ),
        actions: [
          FilledButton(
            key: const Key('back-to-login-after-reset'),
            onPressed: () => Navigator.of(context).pop(),
            child: Text('Back to login'),
          ),
        ],
      );
    }

    return AlertDialog(
      title: Text('Reset password'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Enter the email used for your account and we’ll send a password reset link.',
          ),
          const SizedBox(height: 16),
          TextField(
            key: const Key('forgot-password-email'),
            controller: _email,
            enabled: !_sending,
            keyboardType: TextInputType.emailAddress,
            textInputAction: TextInputAction.done,
            onSubmitted: (_) => _sending ? null : _submit(),
            decoration: const InputDecoration(labelText: 'Account email'),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, style: TextStyle(color: Colors.redAccent)),
          ],
        ],
      ),
      actions: [
        TextButton(
          onPressed: _sending ? null : () => Navigator.of(context).pop(),
          child: Text('Cancel'),
        ),
        FilledButton(
          key: const Key('send-password-reset-link'),
          onPressed: _sending ? null : _submit,
          child: Text(_sending ? 'Sending…' : 'Send reset link'),
        ),
      ],
    );
  }
}
