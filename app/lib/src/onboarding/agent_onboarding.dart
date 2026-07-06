part of '../../main.dart';

class _AgentPersonalityOption {
  const _AgentPersonalityOption({
    required this.key,
    required this.label,
    required this.description,
    required this.infoTitle,
    required this.infoDetails,
    required this.icon,
  });

  final String key;
  final String label;
  final String description;
  final String infoTitle;
  final List<String> infoDetails;
  final IconData icon;
}

const List<_AgentPersonalityOption> _agentPersonalityOptions = [
  _AgentPersonalityOption(
    key: 'balanced',
    label: 'Balanced',
    description:
        'A calm, practical default that keeps replies concise and useful.',
    infoTitle: 'A steady everyday helper',
    infoDetails: [
      'Keeps answers simple and low-drama.',
      'Gives clear confirmations and one helpful suggestion when it makes sense.',
      'Best when you want Bean to be useful without feeling too chatty.',
    ],
    icon: Icons.tune_rounded,
  ),
  _AgentPersonalityOption(
    key: 'coach',
    label: 'Coach',
    description:
        'An encouraging style that gives gentle nudges and helps you keep momentum.',
    infoTitle: 'A motivating helper for momentum',
    infoDetails: [
      'Celebrates small wins and helps you move forward.',
      'Suggests the next small step when things feel overloaded.',
      'Best when you want gentle nudges without guilt or pressure.',
    ],
    icon: Icons.emoji_events_rounded,
  ),
  _AgentPersonalityOption(
    key: 'organizer',
    label: 'Organizer',
    description:
        'A structured planner that pays close attention to dates, times, and follow-up.',
    infoTitle: 'A detail-focused planner',
    infoDetails: [
      'Keeps summaries tidy and schedule-aware.',
      'Asks for missing dates, times, categories, calendars, or reminders.',
      'Best when you want Bean to help keep the day clean and organized.',
    ],
    icon: Icons.view_agenda_rounded,
  ),
  _AgentPersonalityOption(
    key: 'creative',
    label: 'Creative',
    description:
        'A brainstorming partner that turns ideas into practical lists, notes, and plans.',
    infoTitle: 'A warm brainstorming partner',
    infoDetails: [
      'Helps with ideas, names, themes, checklists, and plans.',
      'Turns brainstorms into real tasks, reminders, and calendar events.',
      'Best when you want planning to feel a little more fun and imaginative.',
    ],
    icon: Icons.auto_awesome_rounded,
  ),
  _AgentPersonalityOption(
    key: 'direct',
    label: 'Direct',
    description:
        'A brief, action-first style that leads with the answer or completed work.',
    infoTitle: 'A concise operator',
    infoDetails: [
      'Leads with the answer or completed action.',
      'Asks only the minimum follow-up needed to move work forward.',
      'Best when you want Bean to be brief and efficient.',
    ],
    icon: Icons.bolt_rounded,
  ),
  _AgentPersonalityOption(
    key: 'gentle',
    label: 'Gentle',
    description:
        'A patient, low-pressure style that keeps busy days feeling manageable.',
    infoTitle: 'A calm companion',
    infoDetails: [
      'Keeps the tone soft and settled.',
      'Breaks busy days into manageable next steps.',
      'Best when you want Bean to help without adding urgency.',
    ],
    icon: Icons.spa_rounded,
  ),
];

const List<String> _onboardingPriorityOptions = [
  'Work',
  'Family',
  'Health',
  'Planning',
  'Reminders',
  'Focus',
];

class _AgentOnboardingOverlay extends StatefulWidget {
  const _AgentOnboardingOverlay({
    super.key,
    required this.initialPersonality,
    required this.initialPriorities,
    required this.initialContext,
    required this.busy,
    this.editMode = false,
    this.onCancel,
    required this.onComplete,
  });

  final String initialPersonality;
  final List<String> initialPriorities;
  final String initialContext;
  final bool busy;
  final bool editMode;
  final VoidCallback? onCancel;
  final Future<void> Function({
    required String agentPersonality,
    required List<String> onboardingPriorities,
    String? onboardingContext,
  })
  onComplete;

  @override
  State<_AgentOnboardingOverlay> createState() =>
      _AgentOnboardingOverlayState();
}

class _AgentOnboardingOverlayState extends State<_AgentOnboardingOverlay> {
  late String _selectedPersonality;
  late Set<String> _selectedPriorities;
  late TextEditingController _context;
  int _step = 0;

  @override
  void initState() {
    super.initState();
    _selectedPersonality = widget.initialPersonality;
    _selectedPriorities = widget.initialPriorities.isEmpty
        ? {'Planning', 'Reminders'}
        : widget.initialPriorities.toSet();
    _context = TextEditingController(text: widget.initialContext);
  }

  @override
  void dispose() {
    _context.dispose();
    super.dispose();
  }

  void _togglePriority(String priority) {
    setState(() {
      if (_selectedPriorities.contains(priority)) {
        _selectedPriorities.remove(priority);
      } else {
        _selectedPriorities.add(priority);
      }
    });
  }

  Future<void> _save() async {
    await widget.onComplete(
      agentPersonality: _selectedPersonality,
      onboardingPriorities: _selectedPriorities.toList(),
      onboardingContext: _context.text.trim().isEmpty
          ? null
          : _context.text.trim(),
    );
  }

  Future<void> _next() async {
    if (widget.editMode) {
      await _save();
      return;
    }
    if (_step < 3) {
      setState(() => _step += 1);
      return;
    }
    await _save();
  }

  @override
  Widget build(BuildContext context) {
    return Positioned.fill(
      child: ColoredBox(
        color: Colors.black.withValues(alpha: .45),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 440),
                child: _ShellCard(
                  child: AnimatedSwitcher(
                    duration: const Duration(milliseconds: 180),
                    child: Column(
                      key: ValueKey('agent-onboarding-step-$_step'),
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        _SectionTitle(
                          icon: widget.editMode
                              ? Icons.tune_rounded
                              : _step == 3
                              ? Icons.check_circle_rounded
                              : Icons.auto_awesome_rounded,
                          title: widget.editMode
                              ? 'Edit Bean preferences'
                              : _step == 3
                              ? 'You’re all set'
                              : 'Let’s personalize Bean',
                          subtitle: widget.editMode
                              ? 'Review the current settings and save only what you want to change.'
                              : _step == 3
                              ? 'You can update these settings any time in the Bean section of Settings.'
                              : 'A few quick choices help Bean understand your style and priorities.',
                        ),
                        const SizedBox(height: 18),
                        if (widget.editMode) ...[
                          _personalityStep(),
                          const SizedBox(height: 18),
                          _prioritiesStep(),
                          const SizedBox(height: 18),
                          _contextStep(),
                        ] else ...[
                          if (_step == 0) _personalityStep(),
                          if (_step == 1) _prioritiesStep(),
                          if (_step == 2) _contextStep(),
                          if (_step == 3)
                            Text(
                              'Bean will use your personality, priorities, and context to shape tone, planning, reminders, and follow-up. Look for Bean in Settings whenever you want to change them.',
                              style: TextStyle(color: HeyBeanTheme.muted),
                            ),
                        ],
                        const SizedBox(height: 18),
                        if (widget.editMode)
                          Row(
                            children: [
                              Expanded(
                                child: OutlinedButton(
                                  key: const Key('agent-preferences-cancel'),
                                  onPressed: widget.busy
                                      ? null
                                      : widget.onCancel,
                                  child: Text('Cancel'),
                                ),
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                child: FilledButton(
                                  key: const Key('agent-preferences-save'),
                                  onPressed: widget.busy ? null : _save,
                                  child: Text(widget.busy ? 'Saving…' : 'Save'),
                                ),
                              ),
                            ],
                          )
                        else
                          FilledButton(
                            key: Key(
                              _step == 3
                                  ? 'agent-onboarding-finish'
                                  : 'agent-onboarding-next',
                            ),
                            onPressed: widget.busy ? null : _next,
                            child: Text(
                              widget.busy
                                  ? 'Saving…'
                                  : _step == 3
                                  ? 'Finish'
                                  : 'Next',
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
      ),
    );
  }

  void _showPersonalityInfo() {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) => SafeArea(
        child: ConstrainedBox(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.sizeOf(context).height * .86,
          ),
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Container(
                      width: 28,
                      height: 28,
                      decoration: BoxDecoration(
                        color: HeyBeanTheme.accent.withValues(alpha: .12),
                        shape: BoxShape.circle,
                      ),
                      child: Icon(
                        Icons.info_outline_rounded,
                        size: 18,
                        color: HeyBeanTheme.accent,
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        'Bean personality options',
                        style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Text(
                  'Choose the style that best matches how you want Bean to help. You can change this any time in Settings.',
                  style: TextStyle(color: HeyBeanTheme.muted),
                ),
                const SizedBox(height: 18),
                for (final option in _agentPersonalityOptions) ...[
                  _PersonalityInfoRow(option: option),
                  if (option != _agentPersonalityOptions.last)
                    const SizedBox(height: 14),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _personalityStep() => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Row(
        children: [
          Expanded(
            child: Text(
              'Choose Bean’s personality',
              style: TextStyle(fontWeight: FontWeight.w800),
            ),
          ),
          IconButton(
            key: const Key('agent-personality-info'),
            tooltip: 'More info about Bean personalities',
            visualDensity: VisualDensity.compact,
            padding: EdgeInsets.zero,
            constraints: const BoxConstraints.tightFor(width: 32, height: 32),
            icon: Icon(Icons.info_outline_rounded, size: 20),
            color: HeyBeanTheme.accent,
            onPressed: _showPersonalityInfo,
          ),
        ],
      ),
      const SizedBox(height: 10),
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: _agentPersonalityOptions.map((option) {
          final selected = option.key == _selectedPersonality;
          return ChoiceChip(
            key: Key('agent-personality-${option.key}'),
            selected: selected,
            avatar: Icon(
              option.icon,
              size: 18,
              color: selected ? Colors.white : HeyBeanTheme.accent,
            ),
            label: Text(option.label),
            onSelected: widget.busy
                ? null
                : (_) => setState(() => _selectedPersonality = option.key),
          );
        }).toList(),
      ),
      const SizedBox(height: 8),
      Text(
        _agentPersonalityOptions
            .firstWhere((option) => option.key == _selectedPersonality)
            .description,
        style: TextStyle(color: HeyBeanTheme.muted),
      ),
    ],
  );

  Widget _prioritiesStep() => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(
        'What should Bean prioritize?',
        style: TextStyle(fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 10),
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: _onboardingPriorityOptions.map((priority) {
          final selected = _selectedPriorities.contains(priority);
          return FilterChip(
            key: Key('onboarding-priority-$priority'),
            selected: selected,
            label: Text(priority),
            onSelected: widget.busy ? null : (_) => _togglePriority(priority),
          );
        }).toList(),
      ),
    ],
  );

  Widget _contextStep() => TextField(
    key: const Key('onboarding-context'),
    controller: _context,
    minLines: 3,
    maxLines: 4,
    textInputAction: TextInputAction.newline,
    decoration: _longFormInputDecoration(
      labelText: 'Anything Bean should know?',
      hintText:
          'Example: I work nights, protect family time, and need gentle nudges.',
    ),
  );
}

class _PersonalityInfoRow extends StatelessWidget {
  const _PersonalityInfoRow({required this.option});

  final _AgentPersonalityOption option;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: HeyBeanTheme.surface2,
      borderRadius: BorderRadius.circular(18),
      border: Border.all(color: HeyBeanTheme.border),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(option.icon, size: 18, color: HeyBeanTheme.accent),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                option.label,
                style: TextStyle(fontWeight: FontWeight.w800),
              ),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          option.infoTitle,
          style: TextStyle(
            color: HeyBeanTheme.muted,
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 8),
        for (final detail in option.infoDetails)
          Padding(
            padding: const EdgeInsets.only(bottom: 5),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('• ', style: TextStyle(color: HeyBeanTheme.muted)),
                Expanded(
                  child: Text(
                    detail,
                    style: TextStyle(color: HeyBeanTheme.muted),
                  ),
                ),
              ],
            ),
          ),
      ],
    ),
  );
}
