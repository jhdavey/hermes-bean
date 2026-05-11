import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'hermes_api_client.dart';

void main() {
  runApp(HermesBeanApp());
}

abstract class AuthTokenStore {
  Future<String?> loadToken();
  Future<void> saveToken(String token);
  Future<void> clearToken();
}

class SharedPreferencesAuthTokenStore implements AuthTokenStore {
  const SharedPreferencesAuthTokenStore();

  static const String _tokenKey = 'auth_token';

  @override
  Future<String?> loadToken() async {
    final preferences = await SharedPreferences.getInstance();
    return preferences.getString(_tokenKey);
  }

  @override
  Future<void> saveToken(String token) async {
    final preferences = await SharedPreferences.getInstance();
    await preferences.setString(_tokenKey, token);
  }

  @override
  Future<void> clearToken() async {
    final preferences = await SharedPreferences.getInstance();
    await preferences.remove(_tokenKey);
  }
}

class HermesBeanApp extends StatelessWidget {
  HermesBeanApp({
    super.key,
    HermesApiClient? apiClient,
    AuthTokenStore? tokenStore,
  }) : apiClient = apiClient ?? HermesApiClient(),
       tokenStore = tokenStore ?? const SharedPreferencesAuthTokenStore();

  final HermesApiClient apiClient;
  final AuthTokenStore tokenStore;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Hermes Bean',
      debugShowCheckedModeBanner: false,
      theme: HeyBeanTheme.lightTheme,
      home: CommandCenterShell(apiClient: apiClient, tokenStore: tokenStore),
    );
  }
}

class HeyBeanTheme {
  const HeyBeanTheme._();

  static const Color bg0 = Color(0xFFF8FBF6);
  static const Color bg1 = Color(0xFFF1F7EE);
  static const Color bg2 = Color(0xFFEAF2E6);
  static const Color surface = Color(0xFFFFFFFF);
  static const Color surface2 = Color(0xFFF6FAF4);
  static const Color text = Color(0xFF1F2937);
  static const Color muted = Color(0xFF64748B);
  static const Color border = Color(0x2694A3B8);
  static const Color borderStrong = Color(0x4D94A3B8);
  static const Color accent = Color(0xFF16A34A);
  static const Color accentStrong = Color(0xFF15803D);
  static const Color success = Color(0xFF22C55E);
  static const Color warning = Color(0xFFF59E0B);

  static const SystemUiOverlayStyle lightSystemOverlayStyle =
      SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.dark,
        statusBarBrightness: Brightness.light,
        systemNavigationBarColor: Colors.transparent,
        systemNavigationBarIconBrightness: Brightness.dark,
      );

  static ThemeData get lightTheme {
    final colorScheme =
        ColorScheme.fromSeed(
          brightness: Brightness.light,
          seedColor: accent,
        ).copyWith(
          primary: accent,
          secondary: accentStrong,
          tertiary: success,
          surface: surface,
          onSurface: text,
          outline: borderStrong,
          outlineVariant: border,
        );

    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.light,
      colorScheme: colorScheme,
      scaffoldBackgroundColor: Colors.transparent,
      canvasColor: bg0,
      appBarTheme: const AppBarTheme(
        centerTitle: false,
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        foregroundColor: text,
        systemOverlayStyle: lightSystemOverlayStyle,
      ),
      cardTheme: CardThemeData(
        color: surface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
          side: const BorderSide(color: border),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface2,
        hintStyle: const TextStyle(color: muted),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: accent,
          foregroundColor: Colors.white,
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: text,
          side: const BorderSide(color: borderStrong),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(foregroundColor: accentStrong),
      ),
    );
  }
}

enum _AuthPhase { loading, signedOut, signedIn }

enum _HomeDestination { today, tasks, bean, calendar, more }

class CommandCenterShell extends StatefulWidget {
  const CommandCenterShell({
    super.key,
    required this.apiClient,
    required this.tokenStore,
  });

  final HermesApiClient apiClient;
  final AuthTokenStore tokenStore;

  @override
  State<CommandCenterShell> createState() => _CommandCenterShellState();
}

class _CommandCenterShellState extends State<CommandCenterShell> {
  _AuthPhase _phase = _AuthPhase.loading;
  HermesUser? _user;
  HermesSession? _session;
  List<HermesTask> _tasks = const [];
  List<HermesReminder> _reminders = const [];
  List<HermesCalendarEvent> _calendar = const [];
  List<HermesApproval> _approvals = const [];
  List<HermesActivityEvent> _events = const [];
  final List<HermesMessage> _messages = const [
    HermesMessage(
      id: 0,
      role: 'assistant',
      content: 'Hi — I can plan, schedule, remind, and follow up.',
    ),
  ].toList();
  String? _error;
  bool _busy = false;
  _HomeDestination _selectedDestination = _HomeDestination.bean;

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  Future<void> _bootstrap() async {
    widget.apiClient.bearerToken ??= await widget.tokenStore.loadToken();
    if (widget.apiClient.bearerToken == null) {
      setState(() => _phase = _AuthPhase.signedOut);
      return;
    }
    await _loadSignedIn();
  }

  Future<void> _loadSignedIn({HermesUser? knownUser}) async {
    setState(() {
      _phase = _AuthPhase.loading;
      _error = null;
    });
    try {
      final user = knownUser ?? await widget.apiClient.me();
      final session = await widget.apiClient.startSession(
        title: 'Today',
        metadata: {'source': 'flutter'},
      );
      final results = await Future.wait<Object>([
        widget.apiClient.todaySummary(),
        widget.apiClient.pollActivityEvents(session.id),
      ]);
      final summary = results[0] as HermesTodaySummary;
      if (!mounted) return;
      setState(() {
        _user = user;
        _session = session;
        _tasks = summary.tasks;
        _reminders = summary.reminders;
        _calendar = summary.calendarEvents;
        _approvals = summary.approvals;
        _events = results[1] as List<HermesActivityEvent>;
        _phase = _AuthPhase.signedIn;
      });
    } catch (error) {
      if (!mounted) return;
      await widget.tokenStore.clearToken();
      widget.apiClient.bearerToken = null;
      setState(() {
        _error =
            'Session expired or the API could not be reached. Please sign in again.';
        _user = null;
        _session = null;
        _tasks = const [];
        _reminders = const [];
        _calendar = const [];
        _approvals = const [];
        _events = const [];
        _phase = _AuthPhase.signedOut;
      });
    }
  }

  Future<void> _login(
    String email,
    String password, {
    required bool rememberMe,
  }) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final auth = await widget.apiClient.login(
        email: email,
        password: password,
      );
      if (rememberMe) {
        await widget.tokenStore.saveToken(auth.token);
      } else {
        await widget.tokenStore.clearToken();
      }
      await _loadSignedIn(knownUser: auth.user);
    } catch (error) {
      setState(() => _error = 'Sign in failed: $error');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _register(String name, String email, String password) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final auth = await widget.apiClient.register(
        name: name,
        email: email,
        password: password,
      );
      await _loadSignedIn(knownUser: auth.user);
    } catch (error) {
      setState(() => _error = 'Registration failed: $error');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _sendChat(String content) async {
    final trimmed = content.trim();
    final session = _session;
    if (trimmed.isEmpty || session == null) return;
    setState(() {
      _busy = true;
      _messages.add(
        HermesMessage(id: _messages.length + 1, role: 'user', content: trimmed),
      );
    });
    try {
      final result = await widget.apiClient.sendMessage(
        sessionId: session.id,
        content: trimmed,
        metadata: {'source': 'flutter'},
      );
      final refreshedEvents = await widget.apiClient
          .pollActivityEvents(session.id)
          .catchError((_) => result.events);
      final refreshedSummary = await widget.apiClient.todaySummary().catchError(
        (_) => HermesTodaySummary(
          tasks: _tasks,
          reminders: _reminders,
          calendarEvents: _calendar,
          activityEvents: _events,
          approvals: _approvals,
          blockers: const [],
        ),
      );
      if (!mounted) return;
      setState(() {
        if (result.assistantMessage != null) {
          _messages.add(result.assistantMessage!);
        }
        _tasks = refreshedSummary.tasks;
        _reminders = refreshedSummary.reminders;
        _calendar = refreshedSummary.calendarEvents;
        _approvals = refreshedSummary.approvals;
        _events = _mergeEvents(result.events, refreshedEvents);
      });
    } catch (error) {
      setState(() {
        _messages.add(
          HermesMessage(
            id: _messages.length + 1,
            role: 'assistant',
            content: 'I could not reach the API. Try again soon.',
          ),
        );
        _error = 'Send failed: $error';
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  List<HermesActivityEvent> _mergeEvents(
    List<HermesActivityEvent> resultEvents,
    List<HermesActivityEvent> refreshedEvents,
  ) {
    final byKey = <String, HermesActivityEvent>{};
    for (final event in [...refreshedEvents, ...resultEvents]) {
      byKey['${event.id}:${event.eventType}'] = event;
    }
    return byKey.values.toList();
  }

  Future<void> _deleteAccount() async {
    setState(() => _busy = true);
    try {
      await widget.apiClient.deleteAccount();
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _phase = _AuthPhase.signedOut;
          _user = null;
        });
        await widget.tokenStore.clearToken();
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: HeyBeanTheme.lightSystemOverlayStyle,
      child: Container(
        key: const Key('heybean-background-gradient'),
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            stops: [0, .5, 1],
            colors: [HeyBeanTheme.bg0, HeyBeanTheme.bg1, HeyBeanTheme.bg2],
          ),
        ),
        child: Stack(
          children: [
            const Positioned.fill(
              key: Key('green-glow-left'),
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: RadialGradient(
                    center: Alignment(-1.12, -1.2),
                    radius: 1.1,
                    colors: [Color(0x1916A34A), Colors.transparent],
                  ),
                ),
              ),
            ),
            Scaffold(
              appBar: AppBar(
                titleSpacing: 20,
                title: const _BrandHeader(),
                actions: [
                  _StatusPill(
                    label: _phase == _AuthPhase.signedIn
                        ? 'Agent online'
                        : 'Signed out',
                    icon: Icons.bolt_rounded,
                  ),
                  const SizedBox(width: 16),
                ],
              ),
              body: SafeArea(child: _body()),
              bottomNavigationBar: _phase == _AuthPhase.signedIn
                  ? _HeyBeanBottomMenu(
                      selected: _selectedDestination,
                      onSelected: (destination) =>
                          setState(() => _selectedDestination = destination),
                    )
                  : null,
            ),
          ],
        ),
      ),
    );
  }

  Widget _body() {
    if (_phase == _AuthPhase.loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_phase == _AuthPhase.signedOut) {
      return _SignedOutScreen(
        onLogin: _login,
        onRegister: _register,
        busy: _busy,
        error: _error,
      );
    }
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 112),
      child: _CommandCenterContent(
        user: _user!,
        tasks: _tasks,
        reminders: _reminders,
        calendar: _calendar,
        approvals: _approvals,
        events: _events,
        messages: _messages,
        busy: _busy,
        error: _error,
        selectedDestination: _selectedDestination,
        onSelectDestination: (destination) =>
            setState(() => _selectedDestination = destination),
        onSend: _sendChat,
        onDeleteAccount: _deleteAccount,
      ),
    );
  }
}

class _SignedOutScreen extends StatefulWidget {
  const _SignedOutScreen({
    required this.onLogin,
    required this.onRegister,
    required this.busy,
    this.error,
  });

  final Future<void> Function(
    String email,
    String password, {
    required bool rememberMe,
  })
  onLogin;
  final Future<void> Function(String name, String email, String password)
  onRegister;
  final bool busy;
  final String? error;

  @override
  State<_SignedOutScreen> createState() => _SignedOutScreenState();
}

class _SignedOutScreenState extends State<_SignedOutScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _registerMode = false;
  bool _rememberMe = false;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _showForgotLoginDialog() async {
    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Forgot login?'),
        content: const Text(
          'Password reset is not wired yet. For local testing, create a new test account from this screen with any email and a 12+ character password.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('OK'),
          ),
        ],
      ),
    );
  }

  void _toggleMode() {
    setState(() => _registerMode = !_registerMode);
  }

  Future<void> _submit() {
    if (_registerMode) {
      return widget.onRegister(_name.text, _email.text, _password.text);
    }
    return widget.onLogin(_email.text, _password.text, rememberMe: _rememberMe);
  }

  @override
  Widget build(BuildContext context) {
    final title = _registerMode
        ? 'Create your Hermes Bean account'
        : 'Sign in to Hermes Bean';
    final subtitle = _registerMode
        ? 'Use any test email and a 12+ character password'
        : 'Live API-backed personal assistant';

    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 440),
        child: _ShellCard(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _SectionTitle(
                icon: _registerMode
                    ? Icons.person_add_alt_1_rounded
                    : Icons.lock_rounded,
                title: title,
                subtitle: subtitle,
              ),
              const SizedBox(height: 16),
              if (_registerMode) ...[
                TextField(
                  key: const Key('auth-name'),
                  controller: _name,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(labelText: 'Name'),
                ),
                const SizedBox(height: 12),
              ],
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
                decoration: InputDecoration(
                  labelText: 'Password',
                  helperText: _registerMode ? 'Minimum 12 characters' : null,
                ),
              ),
              if (!_registerMode) ...[
                const SizedBox(height: 8),
                CheckboxListTile(
                  key: const Key('remember-me-checkbox'),
                  value: _rememberMe,
                  onChanged: widget.busy
                      ? null
                      : (value) => setState(() => _rememberMe = value ?? false),
                  title: const Text('Remember me'),
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  dense: true,
                ),
              ],
              if (widget.error != null) ...[
                const SizedBox(height: 12),
                Text(
                  widget.error!,
                  style: const TextStyle(color: Colors.redAccent),
                ),
              ],
              const SizedBox(height: 16),
              FilledButton(
                key: const Key('auth-submit'),
                onPressed: widget.busy ? null : _submit,
                child: Text(
                  widget.busy
                      ? (_registerMode ? 'Creating account…' : 'Signing in…')
                      : (_registerMode ? 'Create account' : 'Sign in'),
                ),
              ),
              const SizedBox(height: 8),
              Wrap(
                alignment: WrapAlignment.spaceBetween,
                crossAxisAlignment: WrapCrossAlignment.center,
                spacing: 8,
                runSpacing: 4,
                children: [
                  TextButton(
                    key: Key(
                      _registerMode ? 'show-login-mode' : 'show-register-mode',
                    ),
                    onPressed: widget.busy ? null : _toggleMode,
                    child: Text(
                      _registerMode
                          ? 'Already have an account? Sign in'
                          : 'Create an account',
                    ),
                  ),
                  TextButton(
                    key: const Key('forgot-login-action'),
                    onPressed: widget.busy ? null : _showForgotLoginDialog,
                    child: const Text('Forgot login?'),
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

class _CommandCenterContent extends StatelessWidget {
  const _CommandCenterContent({
    required this.user,
    required this.tasks,
    required this.reminders,
    required this.calendar,
    required this.approvals,
    required this.events,
    required this.messages,
    required this.busy,
    required this.selectedDestination,
    required this.onSelectDestination,
    required this.onSend,
    required this.onDeleteAccount,
    this.error,
  });

  final HermesUser user;
  final List<HermesTask> tasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendar;
  final List<HermesApproval> approvals;
  final List<HermesActivityEvent> events;
  final List<HermesMessage> messages;
  final bool busy;
  final _HomeDestination selectedDestination;
  final ValueChanged<_HomeDestination> onSelectDestination;
  final Future<void> Function(String content) onSend;
  final Future<void> Function() onDeleteAccount;
  final String? error;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final pendingApprovals = approvals
            .where((approval) => (approval.status ?? 'pending') == 'pending')
            .toList();
        final beanPanel = Column(
          children: [
            if (pendingApprovals.isNotEmpty) ...[
              _ApprovalBanner(approval: pendingApprovals.first),
              const SizedBox(height: 16),
            ],
            _HeroChatCard(messages: messages, busy: busy, onSend: onSend),
            const SizedBox(height: 16),
            _ApprovalCard(approvals: pendingApprovals),
            const SizedBox(height: 16),
            _ProgressCard(user: user, error: error, taskCount: tasks.length),
            const SizedBox(height: 16),
            _TabSurface(
              tasks: tasks,
              reminders: reminders,
              calendar: calendar,
              events: events,
            ),
            const SizedBox(height: 16),
            _ActivityCard(events: events),
            const SizedBox(height: 16),
            _AccountCard(user: user, onDeleteAccount: onDeleteAccount),
          ],
        );
        final overviewPanel = Column(
          children: [
            _ProgressCard(user: user, error: error, taskCount: tasks.length),
            const SizedBox(height: 16),
            _TabSurface(
              tasks: tasks,
              reminders: reminders,
              calendar: calendar,
              events: events,
            ),
            const SizedBox(height: 16),
            _ActivityCard(events: events),
          ],
        );
        final selectedPanel = switch (selectedDestination) {
          _HomeDestination.today => overviewPanel,
          _HomeDestination.tasks => _TaskListCard(tasks: tasks),
          _HomeDestination.bean => beanPanel,
          _HomeDestination.calendar => _CalendarCard(calendar: calendar),
          _HomeDestination.more => Column(
            children: [
              _AccountCard(user: user, onDeleteAccount: onDeleteAccount),
              const SizedBox(height: 16),
              _ApprovalCard(approvals: pendingApprovals),
              const SizedBox(height: 16),
              _ActivityCard(events: events),
            ],
          ),
        };
        final right = Column(
          children: [
            _AccountCard(user: user, onDeleteAccount: onDeleteAccount),
            const SizedBox(height: 16),
            _ProgressCard(user: user, error: error, taskCount: tasks.length),
            const SizedBox(height: 16),
            _ActivityCard(events: events),
            const SizedBox(height: 16),
            _CalendarCard(calendar: calendar),
          ],
        );
        if (constraints.maxWidth < 900 ||
            selectedDestination != _HomeDestination.bean) {
          return selectedPanel;
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

class _BrandHeader extends StatelessWidget {
  const _BrandHeader();

  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(
          color: HeyBeanTheme.accent,
          borderRadius: BorderRadius.circular(14),
        ),
        child: const Icon(Icons.eco_rounded, color: Colors.white),
      ),
      const SizedBox(width: 12),
      Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'HeyBean',
            style: Theme.of(
              context,
            ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
          ),
          Text(
            'Bean assistant',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
              color: HeyBeanTheme.muted,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    ],
  );
}

class _HeroChatCard extends StatefulWidget {
  const _HeroChatCard({
    required this.messages,
    required this.busy,
    required this.onSend,
  });

  final List<HermesMessage> messages;
  final bool busy;
  final Future<void> Function(String content) onSend;

  @override
  State<_HeroChatCard> createState() => _HeroChatCardState();
}

class _HeroChatCardState extends State<_HeroChatCard> {
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.chat_bubble_rounded,
          title: 'Bean',
          subtitle: 'Chat-first command center for your household',
        ),
        const SizedBox(height: 14),
        _QuickPromptRail(onPrompt: widget.onSend),
        const SizedBox(height: 18),
        for (final message in widget.messages) ...[
          _MessageBubble(
            sender: message.role == 'user' ? 'You' : 'Hermes',
            message: message.content ?? '',
            alignRight: message.role == 'user',
          ),
          const SizedBox(height: 10),
        ],
        Container(
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(
            color: HeyBeanTheme.surface2,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: HeyBeanTheme.border),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: TextField(
                  key: const Key('chat-input'),
                  controller: _controller,
                  minLines: 1,
                  maxLines: 4,
                  textInputAction: TextInputAction.send,
                  onSubmitted: widget.busy
                      ? null
                      : (text) {
                          _controller.clear();
                          widget.onSend(text);
                        },
                  decoration: const InputDecoration(
                    hintText:
                        'Ask Bean to create tasks, reminders, or calendar events...',
                    border: InputBorder.none,
                    enabledBorder: InputBorder.none,
                    focusedBorder: InputBorder.none,
                    filled: false,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              FilledButton(
                key: const Key('primary-chat-action'),
                onPressed: widget.busy
                    ? null
                    : () {
                        final text = _controller.text;
                        _controller.clear();
                        widget.onSend(text);
                      },
                child: const Icon(Icons.arrow_upward_rounded, size: 18),
              ),
            ],
          ),
        ),
      ],
    ),
  );
}

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
            backgroundColor: const Color(0x1416A34A),
            side: const BorderSide(color: HeyBeanTheme.border),
            labelStyle: const TextStyle(
              color: HeyBeanTheme.text,
              fontWeight: FontWeight.w700,
            ),
          ),
      ],
    );
  }
}

class _MessageBubble extends StatelessWidget {
  const _MessageBubble({
    required this.sender,
    required this.message,
    this.alignRight = false,
  });

  final String sender;
  final String message;
  final bool alignRight;

  @override
  Widget build(BuildContext context) => Align(
    alignment: alignRight ? Alignment.centerRight : Alignment.centerLeft,
    child: Container(
      constraints: const BoxConstraints(maxWidth: 560),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: alignRight ? const Color(0x1F16A34A) : HeyBeanTheme.surface2,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: HeyBeanTheme.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            sender,
            style: const TextStyle(
              color: HeyBeanTheme.accentStrong,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 4),
          Text(message),
        ],
      ),
    ),
  );
}

class _ApprovalBanner extends StatelessWidget {
  const _ApprovalBanner({required this.approval});

  final HermesApproval approval;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(16),
    decoration: BoxDecoration(
      color: const Color(0xFFFFFBEB),
      borderRadius: BorderRadius.circular(22),
      border: Border.all(color: const Color(0xFFF59E0B).withValues(alpha: .42)),
      boxShadow: [
        BoxShadow(
          color: const Color(0xFFF59E0B).withValues(alpha: .12),
          blurRadius: 22,
          offset: const Offset(0, 12),
        ),
      ],
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            color: HeyBeanTheme.warning,
            borderRadius: BorderRadius.circular(16),
          ),
          child: const Icon(Icons.priority_high_rounded, color: Colors.white),
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Approval needed',
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: HeyBeanTheme.text,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                approval.title,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: HeyBeanTheme.muted,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(width: 12),
        FilledButton(
          key: const Key('review-approval-action'),
          onPressed: () => showDialog<void>(
            context: context,
            builder: (context) => AlertDialog(
              title: const Text('Pending approval'),
              content: Text(approval.title),
              actions: [
                TextButton(
                  onPressed: () => Navigator.of(context).pop(),
                  child: const Text('OK'),
                ),
              ],
            ),
          ),
          child: const Text('Review'),
        ),
      ],
    ),
  );
}

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
              child: const Text(
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
        const Icon(Icons.shield_rounded, color: HeyBeanTheme.warning),
        const SizedBox(width: 12),
        Expanded(
          child: Text(
            approval.title,
            style: const TextStyle(fontWeight: FontWeight.w700),
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
          const TabBar(
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            labelColor: HeyBeanTheme.accentStrong,
            unselectedLabelColor: HeyBeanTheme.muted,
            indicatorColor: HeyBeanTheme.accent,
            tabs: [
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
        if (error != null)
          Text(error!, style: const TextStyle(color: HeyBeanTheme.warning)),
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
            leading: const Icon(Icons.bolt_rounded),
            title: Text(event.eventType),
          ),
      ],
    ),
  );
}

class _TaskListCard extends StatelessWidget {
  const _TaskListCard({required this.tasks});

  final List<HermesTask> tasks;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.task_alt_rounded,
          title: 'Task list',
          subtitle: 'Open household and assistant tasks',
        ),
        const SizedBox(height: 12),
        if (tasks.isEmpty)
          const Text('No open tasks')
        else
          for (final task in tasks)
            ListTile(
              dense: true,
              leading: const Icon(Icons.check_circle_outline_rounded),
              title: Text(task.title),
              subtitle: task.status == null ? null : Text(task.status!),
            ),
      ],
    ),
  );
}

class _CalendarCard extends StatelessWidget {
  const _CalendarCard({required this.calendar});

  final List<HermesCalendarEvent> calendar;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.calendar_month_rounded,
          title: 'Calendar',
          subtitle: 'Live schedule',
        ),
        const SizedBox(height: 12),
        for (final event in calendar)
          ListTile(
            dense: true,
            title: Text(event.title),
            subtitle: Text(event.startsAt ?? 'Unscheduled'),
          ),
      ],
    ),
  );
}

class _AccountCard extends StatelessWidget {
  const _AccountCard({required this.user, required this.onDeleteAccount});

  final HermesUser user;
  final Future<void> Function() onDeleteAccount;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.settings_rounded,
          title: 'Profile',
          subtitle: 'Account and app settings',
        ),
        const SizedBox(height: 10),
        Text(user.email),
        const SizedBox(height: 10),
        OutlinedButton.icon(
          key: const Key('delete-account-action'),
          onPressed: onDeleteAccount,
          icon: const Icon(Icons.delete_outline_rounded),
          label: const Text('Delete account'),
        ),
      ],
    ),
  );
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({
    required this.icon,
    required this.title,
    required this.subtitle,
  });

  final IconData icon;
  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Icon(icon, color: HeyBeanTheme.accentStrong),
      const SizedBox(width: 10),
      Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title,
              style: Theme.of(
                context,
              ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800),
            ),
            Text(
              subtitle,
              style: Theme.of(
                context,
              ).textTheme.bodySmall?.copyWith(color: HeyBeanTheme.muted),
            ),
          ],
        ),
      ),
    ],
  );
}

class _ShellCard extends StatelessWidget {
  const _ShellCard({required this.child, this.glow = false});

  final Widget child;
  final bool glow;

  @override
  Widget build(BuildContext context) => Card(
    child: Container(
      decoration: glow
          ? BoxDecoration(
              borderRadius: BorderRadius.circular(18),
              boxShadow: const [
                BoxShadow(
                  color: Color(0x1716A34A),
                  blurRadius: 24,
                  offset: Offset(0, 12),
                ),
              ],
            )
          : null,
      padding: const EdgeInsets.all(18),
      child: child,
    ),
  );
}

class _MiniSurface extends StatelessWidget {
  const _MiniSurface({
    required this.label,
    required this.value,
    required this.icon,
  });

  final String label;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Container(
    width: 190,
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: HeyBeanTheme.surface2,
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: HeyBeanTheme.border),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, color: HeyBeanTheme.accentStrong),
        const SizedBox(height: 8),
        Text(label, style: const TextStyle(fontWeight: FontWeight.w800)),
        Text(
          value,
          maxLines: 3,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(color: HeyBeanTheme.muted),
        ),
      ],
    ),
  );
}

class _HeyBeanBottomMenu extends StatelessWidget {
  const _HeyBeanBottomMenu({required this.selected, required this.onSelected});

  final _HomeDestination selected;
  final ValueChanged<_HomeDestination> onSelected;

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.paddingOf(context).bottom;
    final dockBottomPadding = bottomInset > 0 ? bottomInset + 2 : 6.0;

    return SizedBox(
      key: const Key('heybean-bottom-menu'),
      height: 78 + dockBottomPadding,
      child: Stack(
        alignment: Alignment.topCenter,
        clipBehavior: Clip.none,
        children: [
          Positioned.fill(
            top: 22,
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: HeyBeanTheme.surface.withValues(alpha: .94),
                border: const Border(
                  top: BorderSide(color: HeyBeanTheme.border),
                ),
                boxShadow: const [
                  BoxShadow(
                    color: Color(0x1A020617),
                    blurRadius: 18,
                    offset: Offset(0, -4),
                  ),
                ],
              ),
              child: Padding(
                padding: EdgeInsets.fromLTRB(10, 7, 10, dockBottomPadding),
                child: Row(
                  children: [
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-today'),
                        icon: Icons.today_rounded,
                        label: 'Today',
                        selected: selected == _HomeDestination.today,
                        onPressed: () => onSelected(_HomeDestination.today),
                      ),
                    ),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-tasks'),
                        icon: Icons.task_alt_rounded,
                        label: 'Tasks',
                        selected: selected == _HomeDestination.tasks,
                        onPressed: () => onSelected(_HomeDestination.tasks),
                      ),
                    ),
                    const SizedBox(width: 84),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-calendar'),
                        icon: Icons.calendar_month_rounded,
                        label: 'Calendar',
                        selected: selected == _HomeDestination.calendar,
                        onPressed: () => onSelected(_HomeDestination.calendar),
                      ),
                    ),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-more'),
                        icon: Icons.more_vert_rounded,
                        label: 'More',
                        selected: selected == _HomeDestination.more,
                        onPressed: () => onSelected(_HomeDestination.more),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Positioned(
            top: 15,
            child: _BeanFab(
              selected: selected == _HomeDestination.bean,
              onPressed: () => onSelected(_HomeDestination.bean),
            ),
          ),
        ],
      ),
    );
  }
}

class _MenuIconButton extends StatelessWidget {
  const _MenuIconButton({
    super.key,
    required this.icon,
    required this.label,
    required this.onPressed,
    this.selected = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback onPressed;
  final bool selected;

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.transparent,
    child: InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: onPressed,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 1),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              icon,
              color: selected ? HeyBeanTheme.accentStrong : HeyBeanTheme.muted,
              size: 20,
            ),
            const SizedBox(height: 3),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: selected
                    ? HeyBeanTheme.accentStrong
                    : HeyBeanTheme.muted,
                fontSize: 10,
                fontWeight: selected ? FontWeight.w800 : FontWeight.w600,
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _BeanFab extends StatelessWidget {
  const _BeanFab({required this.selected, required this.onPressed});

  final bool selected;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.transparent,
    child: InkWell(
      key: const Key('nav-bean'),
      customBorder: const CircleBorder(),
      onTap: onPressed,
      child: Container(
        key: const Key('heybean-center-bean-button'),
        width: 64,
        height: 64,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: const LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [Color(0xFF22C55E), Color(0xFF16A34A), Color(0xFF15803D)],
          ),
          border: Border.all(
            color: selected ? Colors.white : const Color(0xFFE2E8F0),
            width: 4,
          ),
          boxShadow: const [
            BoxShadow(
              color: Color(0x3D16A34A),
              blurRadius: 24,
              offset: Offset(0, 10),
            ),
          ],
        ),
        child: const Icon(Icons.eco_rounded, color: Colors.white, size: 30),
      ),
    ),
  );
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.label, required this.icon});

  final String label;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
    decoration: BoxDecoration(
      color: const Color(0x1F16A34A),
      borderRadius: BorderRadius.circular(999),
      border: Border.all(color: HeyBeanTheme.border),
    ),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 16, color: HeyBeanTheme.accentStrong),
        const SizedBox(width: 6),
        Text(label),
      ],
    ),
  );
}
