import 'dart:async';
import 'dart:convert';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import 'bean_api_client.dart';
import 'firebase_options.dart';

const _tokenKey = 'heybean.auth.token';
const _green = Color(0xff58ad6b);

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  if (HeyBeanFirebaseOptions.configured) {
    await Firebase.initializeApp(
      options: HeyBeanFirebaseOptions.currentPlatform,
    );
  }
  runApp(const HeyBeanApp());
}

class HeyBeanApp extends StatefulWidget {
  const HeyBeanApp({super.key, this.apiClient});

  final BeanApiClient? apiClient;

  @override
  State<HeyBeanApp> createState() => _HeyBeanAppState();
}

class _HeyBeanAppState extends State<HeyBeanApp> {
  late final BeanApiClient _api = widget.apiClient ?? BeanApiClient();
  String _theme = 'green';
  ThemeMode _mode = ThemeMode.system;

  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'HeyBean',
    debugShowCheckedModeBanner: false,
    themeMode: _mode,
    theme: _themeData(Brightness.light),
    darkTheme: _themeData(Brightness.dark),
    home: BeanRoot(
      api: _api,
      onAppearance: (theme, mode) => setState(() {
        _theme = theme;
        _mode = switch (mode) {
          'light' => ThemeMode.light,
          'dark' => ThemeMode.dark,
          _ => ThemeMode.system,
        };
      }),
    ),
  );

  ThemeData _themeData(Brightness brightness) {
    final colors = <String, Color>{
      'green': _green,
      'blue': const Color(0xff3977d4),
      'purple': const Color(0xff7953c6),
      'orange': const Color(0xffd46f35),
      'teal': const Color(0xff238f83),
      'gray': const Color(0xff68747c),
    };
    final seed = colors[_theme] ?? _green;
    return ThemeData(
      colorScheme: ColorScheme.fromSeed(
        seedColor: seed,
        brightness: brightness,
      ),
      useMaterial3: true,
      scaffoldBackgroundColor: brightness == Brightness.dark
          ? const Color(0xff101512)
          : const Color(0xfff5f8f3),
      cardTheme: CardThemeData(
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(22),
          side: BorderSide(
            color: brightness == Brightness.dark
                ? Colors.white12
                : const Color(0xffdfe8df),
          ),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide.none,
        ),
      ),
    );
  }
}

class BeanRoot extends StatefulWidget {
  const BeanRoot({super.key, required this.api, required this.onAppearance});

  final BeanApiClient api;
  final void Function(String theme, String mode) onAppearance;

  @override
  State<BeanRoot> createState() => _BeanRootState();
}

class _BeanRootState extends State<BeanRoot> {
  bool _starting = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _restore();
  }

  Future<void> _restore() async {
    final store = await SharedPreferences.getInstance();
    widget.api.token = store.getString(_tokenKey);
    if (!mounted) return;
    setState(() => _starting = false);
  }

  Future<void> _signedIn(String token) async {
    final store = await SharedPreferences.getInstance();
    await store.setString(_tokenKey, token);
    widget.api.token = token;
    if (mounted) setState(() => _error = null);
  }

  Future<void> _signedOut() async {
    final store = await SharedPreferences.getInstance();
    await store.remove(_tokenKey);
    widget.api.token = null;
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    if (_starting) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (widget.api.token?.isNotEmpty != true) {
      return AuthScreen(api: widget.api, error: _error, onSignedIn: _signedIn);
    }
    return ProductivityShell(
      api: widget.api,
      onSignedOut: _signedOut,
      onAppearance: widget.onAppearance,
    );
  }
}

class AuthScreen extends StatefulWidget {
  const AuthScreen({
    super.key,
    required this.api,
    required this.onSignedIn,
    this.error,
  });

  final BeanApiClient api;
  final Future<void> Function(String token) onSignedIn;
  final String? error;

  @override
  State<AuthScreen> createState() => _AuthScreenState();
}

class _AuthScreenState extends State<AuthScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _register = false;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = _register
          ? await widget.api.register(
              name: _name.text.trim(),
              email: _email.text.trim(),
              password: _password.text,
            )
          : await widget.api.login(_email.text.trim(), _password.text);
      await widget.onSignedIn(result['token'].toString());
    } catch (error) {
      if (mounted) setState(() => _error = error.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    body: SafeArea(
      child: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 440),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(28),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Image.asset('assets/images/bean/bean-logo.png', height: 64),
                    const SizedBox(height: 20),
                    Text(
                      _register ? 'Create your account' : 'Welcome back',
                      style: Theme.of(context).textTheme.headlineMedium,
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 8),
                    Text(
                      _register
                          ? 'Organize calendars, tasks, reminders, notes, and workspaces.'
                          : 'Sign in to manage your day.',
                      textAlign: TextAlign.center,
                    ),
                    if ((_error ?? widget.error) != null)
                      ErrorBanner(_error ?? widget.error!),
                    const SizedBox(height: 20),
                    if (_register)
                      TextField(
                        controller: _name,
                        textInputAction: TextInputAction.next,
                        decoration: const InputDecoration(labelText: 'Name'),
                      ),
                    if (_register) const SizedBox(height: 12),
                    TextField(
                      controller: _email,
                      keyboardType: TextInputType.emailAddress,
                      textInputAction: TextInputAction.next,
                      decoration: const InputDecoration(labelText: 'Email'),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _password,
                      obscureText: true,
                      onSubmitted: (_) => _submit(),
                      decoration: const InputDecoration(labelText: 'Password'),
                    ),
                    const SizedBox(height: 20),
                    FilledButton(
                      onPressed: _busy ? null : _submit,
                      child: Text(
                        _busy
                            ? 'Please wait…'
                            : _register
                            ? 'Create account'
                            : 'Sign in',
                      ),
                    ),
                    TextButton(
                      onPressed: _busy
                          ? null
                          : () => setState(() => _register = !_register),
                      child: Text(
                        _register
                            ? 'Already have an account?'
                            : 'Create an account',
                      ),
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

class ProductivityShell extends StatefulWidget {
  const ProductivityShell({
    super.key,
    required this.api,
    required this.onSignedOut,
    required this.onAppearance,
  });

  final BeanApiClient api;
  final Future<void> Function() onSignedOut;
  final void Function(String theme, String mode) onAppearance;

  @override
  State<ProductivityShell> createState() => _ProductivityShellState();
}

class _ProductivityShellState extends State<ProductivityShell> {
  int _tab = 0;
  bool _loading = true;
  String? _error;
  Map<String, dynamic> _user = {};
  List<Map<String, dynamic>> _tasks = [];
  List<Map<String, dynamic>> _reminders = [];
  List<Map<String, dynamic>> _events = [];
  List<Map<String, dynamic>> _notes = [];

  Object? get _workspaceId =>
      _user['active_workspace']?['id'] ?? _user['default_workspace_id'];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (mounted) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    try {
      _user = _map(await widget.api.get('/auth/me'));
      final path = widget.api.workspacePath;
      final results = await Future.wait([
        widget.api.get(path('/tasks', _workspaceId)),
        widget.api.get(path('/tasks/past', _workspaceId)),
        widget.api.get(path('/reminders', _workspaceId)),
        widget.api.get(
          path(
            '/calendar-events?skip_google_sync=1&skip_outlook_sync=1',
            _workspaceId,
          ),
        ),
        widget.api
            .get(path('/notes', _workspaceId))
            .catchError((_) => <dynamic>[]),
      ]);
      _tasks = _merge(_maps(results[0]), _maps(results[1]));
      _reminders = _maps(results[2]);
      _events = _maps(results[3]);
      _notes = _maps(results[4]);
      widget.onAppearance(
        (_user['theme'] ?? 'green').toString(),
        (_user['theme_mode'] ?? 'auto').toString(),
      );
      unawaited(_registerNotifications());
    } on BeanApiException catch (error) {
      if (error.statusCode == 401) {
        await widget.onSignedOut();
        return;
      }
      _error = error.message;
    } catch (error) {
      _error = error.toString();
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _registerNotifications() async {
    if (!HeyBeanFirebaseOptions.configured) return;
    final platform = switch (Theme.of(context).platform) {
      TargetPlatform.android => 'android',
      TargetPlatform.iOS => 'ios',
      TargetPlatform.macOS => 'macos',
      _ => 'web',
    };
    final messaging = FirebaseMessaging.instance;
    await messaging.requestPermission();
    final token = await messaging.getToken();
    if (token == null) return;
    await widget.api.post('/push-notification-tokens', {
      'token': token,
      'platform': platform,
    });
  }

  @override
  Widget build(BuildContext context) {
    final titles = ['Calendar', 'Tasks', 'Reminders', 'Notes', 'Settings'];
    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            Image.asset('assets/images/bean/bean-logo.png', height: 34),
            const SizedBox(width: 10),
            Text(titles[_tab]),
          ],
        ),
        actions: [
          WorkspaceMenu(user: _user, onSelected: _switchWorkspace),
          IconButton(
            onPressed: _load,
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh',
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(onRefresh: _load, child: _body()),
      floatingActionButton: _tab < 4
          ? FloatingActionButton.extended(
              onPressed: _create,
              icon: const Icon(Icons.add),
              label: Text(
                _tab == 0
                    ? 'Event'
                    : _tab == 1
                    ? 'Task'
                    : _tab == 2
                    ? 'Reminder'
                    : 'Note',
              ),
            )
          : null,
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (value) => setState(() => _tab = value),
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.calendar_month_outlined),
            selectedIcon: Icon(Icons.calendar_month),
            label: 'Calendar',
          ),
          NavigationDestination(
            icon: Icon(Icons.check_box_outlined),
            selectedIcon: Icon(Icons.check_box),
            label: 'Tasks',
          ),
          NavigationDestination(
            icon: Icon(Icons.notifications_outlined),
            selectedIcon: Icon(Icons.notifications),
            label: 'Reminders',
          ),
          NavigationDestination(
            icon: Icon(Icons.notes_outlined),
            selectedIcon: Icon(Icons.notes),
            label: 'Notes',
          ),
          NavigationDestination(
            icon: Icon(Icons.settings_outlined),
            selectedIcon: Icon(Icons.settings),
            label: 'Settings',
          ),
        ],
      ),
    );
  }

  Widget _body() {
    if (_error != null) {
      return ListView(
        padding: const EdgeInsets.all(16),
        children: [
          ErrorBanner(_error!),
          FilledButton(onPressed: _load, child: const Text('Try again')),
        ],
      );
    }
    return switch (_tab) {
      0 => CalendarCommandCenter(
        events: _events,
        tasks: _tasks,
        reminders: _reminders,
        onOpen: (kind, item) => _editResource(kind, item),
      ),
      1 => ResourceList(
        items: _tasks,
        empty: 'No tasks yet.',
        icon: Icons.check_box_outlined,
        dateKey: 'due_at',
        statusKey: 'status',
        onToggle: (item) => _toggle('task', item),
        onTap: (item) => _editResource('task', item),
      ),
      2 => ResourceList(
        items: _reminders,
        empty: 'No reminders yet.',
        icon: Icons.notifications_outlined,
        dateKey: 'remind_at',
        statusKey: 'status',
        onToggle: (item) => _toggle('reminder', item),
        onTap: (item) => _editResource('reminder', item),
      ),
      3 => NotesView(notes: _notes, onSave: _saveNote, onDelete: _deleteNote),
      _ => SettingsView(
        user: _user,
        api: widget.api,
        onSaveUser: _saveUser,
        onReload: _load,
        onSignedOut: widget.onSignedOut,
      ),
    };
  }

  Future<void> _switchWorkspace(Object? id) async {
    await _run(() async {
      await widget.api.patch('/workspaces/default', {'workspace_id': id});
      await _load();
    });
  }

  Future<void> _create() async {
    if (_tab == 3) {
      await _saveNote(null, {'title': 'Untitled note', 'plain_text': ''});
      return;
    }
    await _editResource(
      _tab == 0
          ? 'event'
          : _tab == 1
          ? 'task'
          : 'reminder',
      null,
    );
  }

  Future<void> _editResource(String kind, Map<String, dynamic>? item) async {
    final result = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => ResourceDialog(kind: kind, item: item),
    );
    if (result == null) return;
    await _run(() async {
      final resource = kind == 'event' ? 'calendar-events' : '${kind}s';
      final id = item?['id'];
      final path = id == null
          ? widget.api.workspacePath('/$resource', _workspaceId)
          : '/$resource/$id';
      if (result.remove('delete') == true) {
        await widget.api.delete('/$resource/$id');
      } else if (id == null) {
        await widget.api.post(path, result);
      } else {
        await widget.api.patch(path, result);
      }
      await _load();
    });
  }

  Future<void> _toggle(String kind, Map<String, dynamic> item) async {
    final completed = item['status'] == 'completed';
    await _run(() async {
      await widget.api.patch('/${kind}s/${item['id']}', {
        'status': completed
            ? (kind == 'task' ? 'open' : 'scheduled')
            : 'completed',
      });
      await _load();
    });
  }

  Future<void> _saveNote(
    Map<String, dynamic>? note,
    Map<String, dynamic> values,
  ) async {
    await _run(() async {
      if (note == null) {
        await widget.api.post(
          widget.api.workspacePath('/notes', _workspaceId),
          values,
        );
      } else {
        await widget.api.patch('/notes/${note['id']}', values);
      }
      await _load();
    });
  }

  Future<void> _deleteNote(Map<String, dynamic> note) async {
    await _run(() async {
      await widget.api.delete('/notes/${note['id']}');
      await _load();
    });
  }

  Future<void> _saveUser(Map<String, dynamic> values) async {
    await _run(() async {
      _user = await widget.api.patch('/auth/me', values);
      widget.onAppearance(
        (_user['theme'] ?? 'green').toString(),
        (_user['theme_mode'] ?? 'auto').toString(),
      );
      setState(() {});
    });
  }

  Future<void> _run(Future<void> Function() action) async {
    try {
      await action();
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(error.toString())));
      }
    }
  }
}

class CalendarCommandCenter extends StatelessWidget {
  const CalendarCommandCenter({
    super.key,
    required this.events,
    required this.tasks,
    required this.reminders,
    required this.onOpen,
  });
  final List<Map<String, dynamic>> events;
  final List<Map<String, dynamic>> tasks;
  final List<Map<String, dynamic>> reminders;
  final void Function(String kind, Map<String, dynamic> item) onOpen;

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    final end = DateTime(now.year, now.month, now.day, 23, 59, 59);
    final agenda =
        <({String kind, Map<String, dynamic> item, DateTime when})>[];
    for (final item in events) {
      final start = _parseDate(item['starts_at']);
      final finish = _parseDate(item['ends_at']) ?? start;
      if (start != null &&
          !start.isAfter(end) &&
          finish != null &&
          !finish.isBefore(now)) {
        agenda.add((kind: 'event', item: item, when: start));
      }
    }
    for (final item in tasks) {
      final due = _parseDate(item['due_at']);
      if (item['status'] != 'completed' && due != null && !due.isAfter(end)) {
        agenda.add((kind: 'task', item: item, when: due));
      }
    }
    for (final item in reminders) {
      final due = _parseDate(item['remind_at']);
      if (item['status'] == 'scheduled' && due != null && !due.isAfter(end)) {
        agenda.add((kind: 'reminder', item: item, when: due));
      }
    }
    agenda.sort((a, b) => a.when.compareTo(b.when));
    final allEvents = [...events]
      ..sort(
        (a, b) => (a['starts_at'] ?? '').toString().compareTo(
          (b['starts_at'] ?? '').toString(),
        ),
      );
    return LayoutBuilder(
      builder: (context, constraints) {
        final center = Card(
          child: Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Command center',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 4),
                Text(
                  'Today and what comes next.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                const SizedBox(height: 14),
                if (agenda.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 20),
                    child: Text('Nothing else scheduled for today.'),
                  ),
                for (final entry in agenda)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: SizedBox(
                      width: 54,
                      child: Text(DateFormat.jm().format(entry.when)),
                    ),
                    title: Text((entry.item['title'] ?? 'Untitled').toString()),
                    subtitle: Text(
                      entry.kind == 'event'
                          ? (entry.item['location'] ?? 'Event').toString()
                          : entry.kind == 'task'
                          ? 'Task'
                          : 'Reminder',
                    ),
                    onTap: () => onOpen(entry.kind, entry.item),
                  ),
                const Divider(),
                for (final offset in [1, 2])
                  _UpcomingDay(
                    events: events,
                    day: DateTime(now.year, now.month, now.day + offset),
                    onOpen: (item) => onOpen('event', item),
                  ),
              ],
            ),
          ),
        );
        final calendar = Card(
          child: Column(
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(18, 18, 18, 8),
                child: Align(
                  alignment: Alignment.centerLeft,
                  child: Text(
                    'Calendar',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                ),
              ),
              if (allEvents.isEmpty)
                const Padding(
                  padding: EdgeInsets.all(40),
                  child: Text('No calendar events yet.'),
                ),
              for (final item in allEvents)
                ListTile(
                  leading: const Icon(Icons.event),
                  title: Text((item['title'] ?? 'Untitled').toString()),
                  subtitle: Text(_dateLabel(item['starts_at'])),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => onOpen('event', item),
                ),
            ],
          ),
        );
        final children = constraints.maxWidth >= 850
            ? [
                Expanded(flex: 3, child: calendar),
                const SizedBox(width: 12),
                SizedBox(width: 360, child: center),
              ]
            : [center, const SizedBox(height: 12), calendar];
        return SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 100),
          child: constraints.maxWidth >= 850
              ? Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: children,
                )
              : Column(children: children),
        );
      },
    );
  }
}

class _UpcomingDay extends StatelessWidget {
  const _UpcomingDay({
    required this.events,
    required this.day,
    required this.onOpen,
  });
  final List<Map<String, dynamic>> events;
  final DateTime day;
  final ValueChanged<Map<String, dynamic>> onOpen;
  @override
  Widget build(BuildContext context) {
    final next = day.add(const Duration(days: 1));
    final items = events.where((item) {
      final date = _parseDate(item['starts_at']);
      return date != null && !date.isBefore(day) && date.isBefore(next);
    }).toList();
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            DateFormat('EEEE, MMM d').format(day),
            style: Theme.of(context).textTheme.labelLarge,
          ),
          if (items.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 8),
              child: Text('No events'),
            ),
          for (final item in items)
            ListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: Text((item['title'] ?? 'Untitled').toString()),
              subtitle: Text(_dateLabel(item['starts_at'])),
              onTap: () => onOpen(item),
            ),
        ],
      ),
    );
  }
}

class ResourceList extends StatelessWidget {
  const ResourceList({
    super.key,
    required this.items,
    required this.empty,
    required this.icon,
    required this.dateKey,
    required this.onTap,
    this.statusKey,
    this.onToggle,
  });
  final List<Map<String, dynamic>> items;
  final String empty;
  final IconData icon;
  final String dateKey;
  final String? statusKey;
  final void Function(Map<String, dynamic>) onTap;
  final void Function(Map<String, dynamic>)? onToggle;

  @override
  Widget build(BuildContext context) {
    final sorted = [...items]
      ..sort(
        (a, b) => (a[dateKey] ?? '').toString().compareTo(
          (b[dateKey] ?? '').toString(),
        ),
      );
    return ListView(
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 100),
      children: [
        if (sorted.isEmpty) const SizedBox(height: 100),
        if (sorted.isEmpty) Center(child: Text(empty)),
        for (final item in sorted)
          Card(
            child: ListTile(
              leading: statusKey == null
                  ? Icon(icon)
                  : Checkbox(
                      value: item[statusKey] == 'completed',
                      onChanged: (_) => onToggle?.call(item),
                    ),
              title: Text(
                (item['title'] ?? 'Untitled').toString(),
                style: TextStyle(
                  decoration: item[statusKey] == 'completed'
                      ? TextDecoration.lineThrough
                      : null,
                ),
              ),
              subtitle: Text(_dateLabel(item[dateKey])),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => onTap(item),
            ),
          ),
      ],
    );
  }
}

class ResourceDialog extends StatefulWidget {
  const ResourceDialog({super.key, required this.kind, this.item});
  final String kind;
  final Map<String, dynamic>? item;
  @override
  State<ResourceDialog> createState() => _ResourceDialogState();
}

class _ResourceDialogState extends State<ResourceDialog> {
  late final _title = TextEditingController(
    text: widget.item?['title']?.toString() ?? '',
  );
  late final _details = TextEditingController(
    text: (widget.item?['description'] ?? widget.item?['notes'] ?? '')
        .toString(),
  );
  late final _location = TextEditingController(
    text: widget.item?['location']?.toString() ?? '',
  );
  late DateTime _when =
      _parseDate(
        widget.item?[widget.kind == 'event'
            ? 'starts_at'
            : widget.kind == 'task'
            ? 'due_at'
            : 'remind_at'],
      ) ??
      DateTime.now().add(const Duration(hours: 1));

  @override
  void dispose() {
    _title.dispose();
    _details.dispose();
    _location.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text('${widget.item == null ? 'Add' : 'Edit'} ${widget.kind}'),
    content: SingleChildScrollView(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          TextField(
            controller: _title,
            decoration: const InputDecoration(labelText: 'Title'),
          ),
          const SizedBox(height: 12),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Date and time'),
            subtitle: Text(_dateLabel(_when.toIso8601String())),
            trailing: const Icon(Icons.edit_calendar),
            onTap: _pickDate,
          ),
          if (widget.kind == 'event')
            TextField(
              controller: _location,
              decoration: const InputDecoration(labelText: 'Location'),
            ),
          if (widget.kind == 'event') const SizedBox(height: 12),
          TextField(
            controller: _details,
            maxLines: 4,
            decoration: const InputDecoration(labelText: 'Details'),
          ),
        ],
      ),
    ),
    actions: [
      if (widget.item != null)
        TextButton(
          onPressed: () => Navigator.pop(context, {'delete': true}),
          child: const Text('Delete'),
        ),
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Cancel'),
      ),
      FilledButton(
        onPressed: () {
          final value = _when.toUtc().toIso8601String();
          final body = <String, dynamic>{'title': _title.text.trim()};
          if (widget.kind == 'task') {
            body.addAll({
              'due_at': value,
              'status': widget.item?['status'] ?? 'open',
              'type': 'todo',
              'description': _details.text,
            });
          }
          if (widget.kind == 'reminder') {
            body.addAll({
              'remind_at': value,
              'status': widget.item?['status'] ?? 'scheduled',
              'notes': _details.text,
            });
          }
          if (widget.kind == 'event') {
            body.addAll({
              'starts_at': value,
              'ends_at': _when
                  .add(const Duration(hours: 1))
                  .toUtc()
                  .toIso8601String(),
              'all_day': false,
              'status': widget.item?['status'] ?? 'scheduled',
              'location': _location.text,
              'description': _details.text,
            });
          }
          Navigator.pop(context, body);
        },
        child: const Text('Save'),
      ),
    ],
  );

  Future<void> _pickDate() async {
    final date = await showDatePicker(
      context: context,
      firstDate: DateTime(2000),
      lastDate: DateTime(2100),
      initialDate: _when,
    );
    if (date == null || !mounted) return;
    final time = await showTimePicker(
      context: context,
      initialTime: TimeOfDay.fromDateTime(_when),
    );
    if (time == null) return;
    setState(
      () => _when = DateTime(
        date.year,
        date.month,
        date.day,
        time.hour,
        time.minute,
      ),
    );
  }
}

class NotesView extends StatefulWidget {
  const NotesView({
    super.key,
    required this.notes,
    required this.onSave,
    required this.onDelete,
  });
  final List<Map<String, dynamic>> notes;
  final Future<void> Function(Map<String, dynamic>?, Map<String, dynamic>)
  onSave;
  final Future<void> Function(Map<String, dynamic>) onDelete;
  @override
  State<NotesView> createState() => _NotesViewState();
}

class _NotesViewState extends State<NotesView> {
  Map<String, dynamic>? _selected;
  @override
  Widget build(BuildContext context) {
    if (_selected != null) {
      return NoteEditor(
        note: _selected!,
        onBack: () => setState(() => _selected = null),
        onSave: widget.onSave,
        onDelete: widget.onDelete,
      );
    }
    return ListView(
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 100),
      children: [
        if (widget.notes.isEmpty)
          const Padding(
            padding: EdgeInsets.all(40),
            child: Center(child: Text('No notes yet.')),
          ),
        for (final note in widget.notes)
          Card(
            child: ListTile(
              title: Text((note['title'] ?? 'Untitled').toString()),
              subtitle: Text(
                (note['plain_text'] ?? '').toString(),
                maxLines: 2,
              ),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => setState(() => _selected = note),
            ),
          ),
      ],
    );
  }
}

class NoteEditor extends StatefulWidget {
  const NoteEditor({
    super.key,
    required this.note,
    required this.onBack,
    required this.onSave,
    required this.onDelete,
  });
  final Map<String, dynamic> note;
  final VoidCallback onBack;
  final Future<void> Function(Map<String, dynamic>?, Map<String, dynamic>)
  onSave;
  final Future<void> Function(Map<String, dynamic>) onDelete;
  @override
  State<NoteEditor> createState() => _NoteEditorState();
}

class _NoteEditorState extends State<NoteEditor> {
  late final _title = TextEditingController(
    text: widget.note['title']?.toString() ?? '',
  );
  late final _body = TextEditingController(
    text: widget.note['plain_text']?.toString() ?? '',
  );
  @override
  void dispose() {
    _title.dispose();
    _body.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.all(16),
    children: [
      Row(
        children: [
          IconButton(
            onPressed: widget.onBack,
            icon: const Icon(Icons.arrow_back),
          ),
          Expanded(
            child: TextField(
              controller: _title,
              style: Theme.of(context).textTheme.headlineSmall,
              decoration: const InputDecoration(hintText: 'Title'),
            ),
          ),
        ],
      ),
      const SizedBox(height: 12),
      TextField(
        controller: _body,
        minLines: 18,
        maxLines: null,
        decoration: const InputDecoration(hintText: 'Start writing…'),
      ),
      const SizedBox(height: 16),
      Row(
        children: [
          TextButton(
            onPressed: () async {
              await widget.onDelete(widget.note);
              widget.onBack();
            },
            child: const Text('Delete'),
          ),
          const Spacer(),
          FilledButton(
            onPressed: () => widget.onSave(widget.note, {
              'title': _title.text,
              'plain_text': _body.text,
            }),
            child: const Text('Save'),
          ),
        ],
      ),
    ],
  );
}

class SettingsView extends StatefulWidget {
  const SettingsView({
    super.key,
    required this.user,
    required this.api,
    required this.onSaveUser,
    required this.onReload,
    required this.onSignedOut,
  });
  final Map<String, dynamic> user;
  final BeanApiClient api;
  final Future<void> Function(Map<String, dynamic>) onSaveUser;
  final Future<void> Function() onReload;
  final Future<void> Function() onSignedOut;
  @override
  State<SettingsView> createState() => _SettingsViewState();
}

class _SettingsViewState extends State<SettingsView> {
  late final _name = TextEditingController(
    text: widget.user['name']?.toString() ?? '',
  );
  late final _email = TextEditingController(
    text: widget.user['email']?.toString() ?? '',
  );
  String _theme = 'green';
  String _mode = 'auto';
  @override
  void initState() {
    super.initState();
    _theme = (widget.user['theme'] ?? 'green').toString();
    _mode = (widget.user['theme_mode'] ?? 'auto').toString();
  }

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.fromLTRB(12, 8, 12, 60),
    children: [
      SettingsCard(
        title: 'Profile',
        child: Column(
          children: [
            TextField(
              controller: _name,
              decoration: const InputDecoration(labelText: 'Name'),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _email,
              decoration: const InputDecoration(labelText: 'Email'),
            ),
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                onPressed: () => widget.onSaveUser({
                  'name': _name.text,
                  'email': _email.text,
                }),
                child: const Text('Save profile'),
              ),
            ),
          ],
        ),
      ),
      SettingsCard(
        title: 'Appearance',
        child: Row(
          children: [
            Expanded(
              child: DropdownButtonFormField(
                initialValue: _theme,
                decoration: const InputDecoration(labelText: 'Color'),
                items: ['green', 'blue', 'purple', 'orange', 'teal', 'gray']
                    .map(
                      (v) => DropdownMenuItem(
                        value: v,
                        child: Text(v[0].toUpperCase() + v.substring(1)),
                      ),
                    )
                    .toList(),
                onChanged: (v) => setState(() => _theme = v!),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: DropdownButtonFormField(
                initialValue: _mode,
                decoration: const InputDecoration(labelText: 'Mode'),
                items: ['auto', 'light', 'dark']
                    .map(
                      (v) => DropdownMenuItem(
                        value: v,
                        child: Text(v[0].toUpperCase() + v.substring(1)),
                      ),
                    )
                    .toList(),
                onChanged: (v) => setState(() => _mode = v!),
              ),
            ),
            IconButton(
              onPressed: () =>
                  widget.onSaveUser({'theme': _theme, 'theme_mode': _mode}),
              icon: const Icon(Icons.save),
            ),
          ],
        ),
      ),
      SettingsCard(
        title: 'Calendar connections',
        child: Column(
          children: [
            ProviderTile(
              label: 'Google Calendar',
              provider: 'google',
              api: widget.api,
              onDone: widget.onReload,
            ),
            ProviderTile(
              label: 'Microsoft Outlook',
              provider: 'outlook',
              api: widget.api,
              onDone: widget.onReload,
            ),
          ],
        ),
      ),
      SettingsCard(
        title: 'Subscription',
        child: ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.credit_card),
          title: Text((widget.user['subscription_tier'] ?? 'Base').toString()),
          subtitle: const Text('View plans and manage billing on heybean.org.'),
          trailing: const Icon(Icons.open_in_new),
          onTap: () => launchUrl(
            Uri.parse('https://heybean.org/pricing?source=flutter'),
            mode: LaunchMode.externalApplication,
          ),
        ),
      ),
      SettingsCard(
        title: 'Feedback',
        child: ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.bug_report_outlined),
          title: const Text('Report an issue'),
          onTap: _reportIssue,
        ),
      ),
      SettingsCard(
        title: 'Account',
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            OutlinedButton(
              onPressed: _export,
              child: const Text('Export account data'),
            ),
            OutlinedButton(onPressed: _logout, child: const Text('Sign out')),
            TextButton(
              onPressed: _deleteAccount,
              child: const Text('Delete account'),
            ),
          ],
        ),
      ),
    ],
  );

  Future<void> _reportIssue() async {
    final controller = TextEditingController();
    final send = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Report an issue'),
        content: TextField(
          controller: controller,
          maxLines: 6,
          decoration: const InputDecoration(hintText: 'What happened?'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Send'),
          ),
        ],
      ),
    );
    if (send == true && controller.text.trim().isNotEmpty) {
      await widget.api.submitIssue(
        message: controller.text.trim(),
        pageUrl: 'heybean://settings',
      );
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text('Issue report sent.')));
      }
    }
    controller.dispose();
  }

  Future<void> _export() async {
    final data = await widget.api.get('/account/export');
    final uri = Uri.dataFromString(
      const JsonEncoder.withIndent('  ').convert(data),
      mimeType: 'application/json',
    );
    await launchUrl(uri);
  }

  Future<void> _logout() async {
    await widget.api
        .post('/auth/logout')
        .catchError((_) => <String, dynamic>{});
    await widget.onSignedOut();
  }

  Future<void> _deleteAccount() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Delete account?'),
        content: const Text(
          'This permanently removes your account and its data.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (confirmed == true) {
      await widget.api.delete('/account');
      await widget.onSignedOut();
    }
  }
}

class ProviderTile extends StatelessWidget {
  const ProviderTile({
    super.key,
    required this.label,
    required this.provider,
    required this.api,
    required this.onDone,
  });
  final String label;
  final String provider;
  final BeanApiClient api;
  final Future<void> Function() onDone;
  @override
  Widget build(BuildContext context) => ListTile(
    contentPadding: EdgeInsets.zero,
    leading: const Icon(Icons.calendar_today),
    title: Text(label),
    subtitle: const Text('Connect or synchronize this calendar provider.'),
    trailing: const Icon(Icons.open_in_new),
    onTap: () async {
      try {
        final result = await api.post('/$provider-calendar/auth-url');
        final url = result['url'] ?? result['auth_url'];
        if (url != null) {
          await launchUrl(
            Uri.parse(url.toString()),
            mode: LaunchMode.externalApplication,
          );
        }
        await onDone();
      } catch (error) {
        if (context.mounted) {
          ScaffoldMessenger.of(
            context,
          ).showSnackBar(SnackBar(content: Text(error.toString())));
        }
      }
    },
  );
}

class WorkspaceMenu extends StatelessWidget {
  const WorkspaceMenu({
    super.key,
    required this.user,
    required this.onSelected,
  });
  final Map<String, dynamic> user;
  final ValueChanged<Object?> onSelected;
  @override
  Widget build(BuildContext context) {
    final spaces = _maps(user['workspaces']);
    final active =
        user['active_workspace']?['id'] ?? user['default_workspace_id'];
    if (spaces.length < 2) return const SizedBox.shrink();
    return PopupMenuButton<Object?>(
      tooltip: 'Switch workspace',
      initialValue: active,
      onSelected: onSelected,
      icon: const Icon(Icons.space_dashboard_outlined),
      itemBuilder: (_) => [
        for (final space in spaces)
          PopupMenuItem(
            value: space['id'],
            child: Text((space['name'] ?? 'Workspace').toString()),
          ),
      ],
    );
  }
}

class SettingsCard extends StatelessWidget {
  const SettingsCard({super.key, required this.title, required this.child});
  final String title;
  final Widget child;
  @override
  Widget build(BuildContext context) => Card(
    margin: const EdgeInsets.only(bottom: 12),
    child: Padding(
      padding: const EdgeInsets.all(18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 14),
          child,
        ],
      ),
    ),
  );
}

class ErrorBanner extends StatelessWidget {
  const ErrorBanner(this.message, {super.key});
  final String message;
  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(top: 16),
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: Theme.of(context).colorScheme.errorContainer,
      borderRadius: BorderRadius.circular(12),
    ),
    child: Text(message),
  );
}

Map<String, dynamic> _map(dynamic value) =>
    value is Map ? Map<String, dynamic>.from(value) : <String, dynamic>{};
List<Map<String, dynamic>> _maps(dynamic value) => value is List
    ? value
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .toList()
    : <Map<String, dynamic>>[];
List<Map<String, dynamic>> _merge(
  List<Map<String, dynamic>> a,
  List<Map<String, dynamic>> b,
) => [
  ...{
    for (final item in [...a, ...b]) item['id'].toString(): item,
  }.values,
];
DateTime? _parseDate(dynamic value) =>
    value == null ? null : DateTime.tryParse(value.toString())?.toLocal();
String _dateLabel(dynamic value) {
  final date = _parseDate(value);
  return date == null ? 'No date' : DateFormat.yMMMd().add_jm().format(date);
}
