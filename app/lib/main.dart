import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

void main() {
  runApp(const HermesBeanApp());
}

class HermesBeanApp extends StatelessWidget {
  const HermesBeanApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Hermes Bean',
      debugShowCheckedModeBanner: false,
      theme: HeyBeanTheme.lightTheme,
      home: const CommandCenterShell(),
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
      textTheme: ThemeData.light().textTheme.apply(
        bodyColor: text,
        displayColor: text,
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
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 14,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: borderStrong),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: accent, width: 1.4),
        ),
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
        style: TextButton.styleFrom(
          foregroundColor: accentStrong,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),
      dividerColor: border,
      listTileTheme: const ListTileThemeData(iconColor: text),
    );
  }
}

class CommandCenterShell extends StatelessWidget {
  const CommandCenterShell({super.key});

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
            const Positioned.fill(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: RadialGradient(
                    center: Alignment(1.12, -1.05),
                    radius: .95,
                    colors: [Color(0x1A84CC16), Colors.transparent],
                  ),
                ),
              ),
            ),
            Scaffold(
              appBar: AppBar(
                titleSpacing: 20,
                title: const _BrandHeader(),
                actions: const [
                  _StatusPill(label: 'Agent online', icon: Icons.bolt_rounded),
                  SizedBox(width: 16),
                ],
              ),
              body: const SafeArea(
                child: SingleChildScrollView(
                  padding: EdgeInsets.fromLTRB(20, 8, 20, 24),
                  child: _CommandCenterContent(),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _CommandCenterContent extends StatelessWidget {
  const _CommandCenterContent();

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final isWide = constraints.maxWidth >= 900;
        final left = Column(
          children: const [
            _HeroChatCard(),
            SizedBox(height: 16),
            _ApprovalCard(),
            SizedBox(height: 16),
            _TabSurface(),
          ],
        );
        final right = Column(
          children: const [
            _ProgressCard(),
            SizedBox(height: 16),
            _ActivityCard(),
            SizedBox(height: 16),
            _CalendarCard(),
          ],
        );

        if (!isWide) {
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
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 40,
          height: 40,
          decoration: BoxDecoration(
            color: HeyBeanTheme.accent,
            borderRadius: BorderRadius.circular(14),
            boxShadow: const [
              BoxShadow(
                color: Color(0x3316A34A),
                blurRadius: 20,
                offset: Offset(0, 8),
              ),
            ],
          ),
          child: const Icon(Icons.eco_rounded, color: Colors.white),
        ),
        const SizedBox(width: 12),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Hermes Bean',
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w800,
                letterSpacing: -.4,
              ),
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
}

class _HeroChatCard extends StatelessWidget {
  const _HeroChatCard();

  @override
  Widget build(BuildContext context) {
    return _ShellCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const _SectionTitle(
            icon: Icons.chat_bubble_rounded,
            title: 'Hermes Bean',
            subtitle: 'Chat-first planning for your day',
          ),
          const SizedBox(height: 18),
          const _MessageBubble(
            sender: 'You',
            message: 'Can you organize today and draft a reply to Morgan?',
            alignRight: true,
          ),
          const SizedBox(height: 10),
          const _MessageBubble(
            sender: 'Hermes',
            message:
                'I found three urgent tasks, moved the design review prep to 2:30 PM, and prepared a draft reply for approval.',
          ),
          const SizedBox(height: 16),
          TextField(
            decoration: InputDecoration(
              hintText: 'Ask Hermes to plan, schedule, or follow up...',
              suffixIcon: Padding(
                padding: const EdgeInsets.all(6),
                child: FilledButton(
                  key: const Key('primary-chat-action'),
                  onPressed: () {},
                  child: const Icon(Icons.arrow_upward_rounded, size: 18),
                ),
              ),
            ),
          ),
        ],
      ),
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
  Widget build(BuildContext context) {
    return Align(
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
}

class _ApprovalCard extends StatelessWidget {
  const _ApprovalCard();

  @override
  Widget build(BuildContext context) {
    return _ShellCard(
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
              '“Thanks Morgan — I can confirm Thursday at 11 AM. I’ll bring the launch notes and open questions.”',
            ),
          ),
          const SizedBox(height: 14),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              FilledButton.icon(
                onPressed: () {},
                icon: const Icon(Icons.check_rounded),
                label: const Text('Approve'),
              ),
              OutlinedButton.icon(
                onPressed: () {},
                icon: const Icon(Icons.edit_rounded),
                label: const Text('Edit'),
              ),
              TextButton(onPressed: () {}, child: const Text('Dismiss')),
            ],
          ),
        ],
      ),
    );
  }
}

class _TabSurface extends StatelessWidget {
  const _TabSurface();

  @override
  Widget build(BuildContext context) {
    return _ShellCard(
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
              indicatorSize: TabBarIndicatorSize.label,
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
              children: const [
                _MiniSurface(
                  label: 'Today',
                  value: '5 focus blocks',
                  icon: Icons.today_rounded,
                ),
                _MiniSurface(
                  label: 'Tasks',
                  value: '8 open · 3 urgent',
                  icon: Icons.task_alt_rounded,
                ),
                _MiniSurface(
                  label: 'Reminders',
                  value: '2 due before lunch',
                  icon: Icons.notifications_active_rounded,
                ),
                _MiniSurface(
                  label: 'Calendar',
                  value: 'Next: Design review',
                  icon: Icons.calendar_month_rounded,
                ),
                _MiniSurface(
                  label: 'Activity',
                  value: '14 agent events',
                  icon: Icons.auto_awesome_rounded,
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
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
  Widget build(BuildContext context) {
    return Container(
      width: 172,
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
          const SizedBox(height: 10),
          Text(label, style: const TextStyle(fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text(value, style: const TextStyle(color: HeyBeanTheme.muted)),
        ],
      ),
    );
  }
}

class _ProgressCard extends StatelessWidget {
  const _ProgressCard();

  @override
  Widget build(BuildContext context) {
    const steps = [
      ('Scanning inbox', .92, HeyBeanTheme.success),
      ('Prioritizing tasks', .72, HeyBeanTheme.accent),
      ('Waiting for approval', .45, HeyBeanTheme.warning),
    ];

    return _ShellCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const _SectionTitle(
            icon: Icons.route_rounded,
            title: 'Agent progress',
            subtitle: 'Live planning states',
          ),
          const SizedBox(height: 16),
          for (final step in steps) ...[
            Text(step.$1, style: const TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            ClipRRect(
              borderRadius: BorderRadius.circular(999),
              child: LinearProgressIndicator(
                minHeight: 9,
                value: step.$2,
                color: step.$3,
                backgroundColor: const Color(0x1416A34A),
              ),
            ),
            const SizedBox(height: 14),
          ],
          const _StatusPill(
            label: 'Working on calendar conflicts',
            icon: Icons.sync_rounded,
          ),
        ],
      ),
    );
  }
}

class _ActivityCard extends StatelessWidget {
  const _ActivityCard();

  @override
  Widget build(BuildContext context) {
    const items = [
      ('9:12 AM', 'Created task “Review vendor terms”'),
      ('9:18 AM', 'Moved design review prep after standup'),
      ('9:21 AM', 'Drafted approval prompt for Morgan'),
      ('9:24 AM', 'Reminder queued for prescription pickup'),
    ];

    return _ShellCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const _SectionTitle(
            icon: Icons.timeline_rounded,
            title: 'Activity feed',
            subtitle: 'Recent Hermes actions',
          ),
          const SizedBox(height: 12),
          for (final item in items)
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const CircleAvatar(
                radius: 16,
                backgroundColor: Color(0x1F16A34A),
                child: Icon(
                  Icons.check_rounded,
                  size: 17,
                  color: HeyBeanTheme.accentStrong,
                ),
              ),
              title: Text(item.$2),
              subtitle: Text(item.$1),
            ),
        ],
      ),
    );
  }
}

class _CalendarCard extends StatelessWidget {
  const _CalendarCard();

  @override
  Widget build(BuildContext context) {
    return _ShellCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: const [
          _SectionTitle(
            icon: Icons.calendar_month_rounded,
            title: 'Calendar',
            subtitle: 'Today at a glance',
          ),
          SizedBox(height: 14),
          _ScheduleRow(time: '10:00', title: 'Product standup'),
          _ScheduleRow(time: '12:15', title: 'Lunch reminder'),
          _ScheduleRow(time: '14:30', title: 'Design review prep'),
        ],
      ),
    );
  }
}

class _ScheduleRow extends StatelessWidget {
  const _ScheduleRow({required this.time, required this.title});

  final String time;
  final String title;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        children: [
          Text(
            time,
            style: const TextStyle(
              color: HeyBeanTheme.muted,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: HeyBeanTheme.surface2,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: HeyBeanTheme.border),
              ),
              child: Text(
                title,
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ShellCard extends StatelessWidget {
  const _ShellCard({required this.child, this.glow = false});

  final Widget child;
  final bool glow;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .86),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: HeyBeanTheme.border),
        boxShadow: [
          BoxShadow(
            color: glow ? const Color(0x2616A34A) : const Color(0x101F2937),
            blurRadius: glow ? 28 : 18,
            offset: const Offset(0, 12),
          ),
        ],
      ),
      child: child,
    );
  }
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
  Widget build(BuildContext context) {
    return Row(
      children: [
        Container(
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            color: const Color(0x1F16A34A),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Icon(icon, color: HeyBeanTheme.accentStrong, size: 20),
        ),
        const SizedBox(width: 12),
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
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.label, required this.icon});

  final String label;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
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
          Text(
            label,
            style: const TextStyle(
              color: HeyBeanTheme.accentStrong,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
}
