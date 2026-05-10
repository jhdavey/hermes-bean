import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'hermes_api_client.dart';

void main() {
  runApp(HermesBeanApp());
}

class HermesBeanApp extends StatelessWidget {
  HermesBeanApp({super.key, HermesApiClient? apiClient})
    : apiClient = apiClient ?? HermesApiClient();

  final HermesApiClient apiClient;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Hermes Bean',
      debugShowCheckedModeBanner: false,
      theme: HeyBeanTheme.lightTheme,
      home: CommandCenterShell(apiClient: apiClient),
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

class CommandCenterShell extends StatefulWidget {
  const CommandCenterShell({super.key, required this.apiClient});

  final HermesApiClient apiClient;

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

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  Future<void> _bootstrap() async {
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
        widget.apiClient.listTasks(),
        widget.apiClient.listReminders(),
        widget.apiClient.listCalendarEvents(),
        widget.apiClient.pollActivityEvents(session.id),
      ]);
      if (!mounted) return;
      setState(() {
        _user = user;
        _session = session;
        _tasks = results[0] as List<HermesTask>;
        _reminders = results[1] as List<HermesReminder>;
        _calendar = results[2] as List<HermesCalendarEvent>;
        _events = results[3] as List<HermesActivityEvent>;
        _phase = _AuthPhase.signedIn;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = 'Using demo fallback while offline.';
        _user =
            knownUser ??
            const HermesUser(
              id: 0,
              name: 'Demo Bean',
              email: 'demo@example.com',
            );
        _session = const HermesSession(id: 0, status: 'offline', title: 'Demo');
        _tasks = const [
          HermesTask(id: 1, title: 'Review launch plan', status: 'open'),
        ];
        _reminders = const [
          HermesReminder(id: 2, title: 'Stand up', dueAt: '9:00 AM'),
        ];
        _calendar = const [
          HermesCalendarEvent(
            id: 3,
            title: 'Design review',
            startsAt: '2:30 PM',
          ),
        ];
        _events = const [
          HermesActivityEvent(id: 4, eventType: 'offline.demo_fallback'),
        ];
        _phase = _AuthPhase.signedIn;
      });
    }
  }

  Future<void> _login(String email, String password) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final auth = await widget.apiClient.login(
        email: email,
        password: password,
      );
      await _loadSignedIn(knownUser: auth.user);
    } catch (error) {
      setState(() => _error = 'Sign in failed: $error');
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
      if (!mounted) return;
      setState(() {
        if (result.assistantMessage != null) {
          _messages.add(result.assistantMessage!);
        }
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
      return _SignedOutScreen(onSubmit: _login, busy: _busy, error: _error);
    }
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
      child: _CommandCenterContent(
        user: _user!,
        tasks: _tasks,
        reminders: _reminders,
        calendar: _calendar,
        events: _events,
        messages: _messages,
        busy: _busy,
        error: _error,
        onSend: _sendChat,
        onDeleteAccount: _deleteAccount,
      ),
    );
  }
}

class _SignedOutScreen extends StatefulWidget {
  const _SignedOutScreen({
    required this.onSubmit,
    required this.busy,
    this.error,
  });

  final Future<void> Function(String email, String password) onSubmit;
  final bool busy;
  final String? error;

  @override
  State<_SignedOutScreen> createState() => _SignedOutScreenState();
}

class _SignedOutScreenState extends State<_SignedOutScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 440),
        child: _ShellCard(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const _SectionTitle(
                icon: Icons.lock_rounded,
                title: 'Sign in to Hermes Bean',
                subtitle: 'Live API-backed personal assistant',
              ),
              const SizedBox(height: 16),
              TextField(
                key: const Key('auth-email'),
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                decoration: const InputDecoration(labelText: 'Email'),
              ),
              const SizedBox(height: 12),
              TextField(
                key: const Key('auth-password'),
                controller: _password,
                obscureText: true,
                decoration: const InputDecoration(labelText: 'Password'),
              ),
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
                onPressed: widget.busy
                    ? null
                    : () => widget.onSubmit(_email.text, _password.text),
                child: Text(widget.busy ? 'Signing in…' : 'Sign in'),
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
    required this.events,
    required this.messages,
    required this.busy,
    required this.onSend,
    required this.onDeleteAccount,
    this.error,
  });

  final HermesUser user;
  final List<HermesTask> tasks;
  final List<HermesReminder> reminders;
  final List<HermesCalendarEvent> calendar;
  final List<HermesActivityEvent> events;
  final List<HermesMessage> messages;
  final bool busy;
  final Future<void> Function(String content) onSend;
  final Future<void> Function() onDeleteAccount;
  final String? error;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final left = Column(
          children: [
            _HeroChatCard(messages: messages, busy: busy, onSend: onSend),
            const SizedBox(height: 16),
            const _ApprovalCard(),
            const SizedBox(height: 16),
            _TabSurface(
              tasks: tasks,
              reminders: reminders,
              calendar: calendar,
              events: events,
            ),
          ],
        );
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
        if (constraints.maxWidth < 900) {
          return Column(children: [left, const SizedBox(height: 16), right]);
        }
        return Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(flex: 7, child: left),
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
            'Hermes Bean',
            style: Theme.of(
              context,
            ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
          ),
          Text(
            'Command center',
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
          title: 'Hermes Bean',
          subtitle: 'Chat-first planning for your day',
        ),
        const SizedBox(height: 18),
        for (final message in widget.messages) ...[
          _MessageBubble(
            sender: message.role == 'user' ? 'You' : 'Hermes',
            message: message.content ?? '',
            alignRight: message.role == 'user',
          ),
          const SizedBox(height: 10),
        ],
        TextField(
          key: const Key('chat-input'),
          controller: _controller,
          decoration: InputDecoration(
            hintText: 'Ask Hermes to plan, schedule, or follow up...',
            suffixIcon: Padding(
              padding: const EdgeInsets.all(6),
              child: FilledButton(
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
            ),
          ),
        ),
      ],
    ),
  );
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

class _ApprovalCard extends StatelessWidget {
  const _ApprovalCard();

  @override
  Widget build(BuildContext context) => _ShellCard(
    glow: true,
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          icon: Icons.verified_user_rounded,
          title: 'Approve draft reply',
          subtitle: 'Hermes needs your confirmation before sending',
        ),
        const SizedBox(height: 14),
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: HeyBeanTheme.surface2,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: HeyBeanTheme.border),
          ),
          child: const Text(
            '“Thanks Morgan — I can confirm Thursday at 11 AM.”',
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
          title: 'Account settings',
          subtitle: 'Privacy and App Store compliance',
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
