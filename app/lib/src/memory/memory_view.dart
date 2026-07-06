part of '../../main.dart';

class _MemoryView extends StatefulWidget {
  const _MemoryView({
    required this.items,
    required this.summaries,
    required this.history,
    required this.onRefresh,
    required this.onCreated,
    required this.onUpdated,
    required this.onDeleted,
  });

  final List<HermesMemoryItem> items;
  final List<HermesMemorySummary> summaries;
  final List<HermesRequestHistoryItem> history;
  final Future<void> Function() onRefresh;
  final Future<HermesMemoryItem> Function({
    required String content,
    String type,
    String? title,
  })
  onCreated;
  final Future<HermesMemoryItem> Function(
    HermesMemoryItem item, {
    required String content,
    required String type,
    String? title,
  })
  onUpdated;
  final Future<void> Function(HermesMemoryItem item) onDeleted;

  @override
  State<_MemoryView> createState() => _MemoryViewState();
}

class _MemoryViewState extends State<_MemoryView> {
  final _searchController = TextEditingController();
  final _newContentController = TextEditingController();
  final _newTitleController = TextEditingController();
  String _typeFilter = 'all';
  String _newType = 'fact';
  bool _saving = false;
  bool _refreshing = false;

  @override
  void dispose() {
    _searchController.dispose();
    _newContentController.dispose();
    _newTitleController.dispose();
    super.dispose();
  }

  List<HermesMemoryItem> get _filteredItems {
    final search = _searchController.text.trim().toLowerCase();
    return _sortedMemoryItems(widget.items)
        .where((item) {
          if (_typeFilter != 'all' && item.type != _typeFilter) return false;
          if (search.isEmpty) return true;
          return [item.title, item.content, item.type].whereType<String>().any(
            (value) => value.toLowerCase().contains(search),
          );
        })
        .toList(growable: false);
  }

  Future<void> _create() async {
    final content = _newContentController.text.trim();
    if (content.isEmpty || _saving) return;
    setState(() => _saving = true);
    try {
      await widget.onCreated(
        content: content,
        type: _newType,
        title: _newTitleController.text.trim(),
      );
      if (!mounted) return;
      _newContentController.clear();
      _newTitleController.clear();
      setState(() {});
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _refresh() async {
    if (_refreshing) return;
    setState(() => _refreshing = true);
    try {
      await widget.onRefresh();
    } finally {
      if (mounted) setState(() => _refreshing = false);
    }
  }

  Future<void> _edit(HermesMemoryItem item) async {
    final edited = await showModalBottomSheet<_MemoryEditResult>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => _MemoryEditSheet(item: item),
    );
    if (edited == null || !mounted) return;
    await widget.onUpdated(
      item,
      content: edited.content,
      type: edited.type,
      title: edited.title,
    );
  }

  Future<void> _forget(HermesMemoryItem item) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Forget knowledge?'),
        content: Text(
          item.title?.trim().isNotEmpty == true ? item.title! : item.content,
          maxLines: 3,
          overflow: TextOverflow.ellipsis,
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text('Cancel'),
          ),
          FilledButton(
            style: _destructiveFilledButtonStyle(),
            onPressed: () => Navigator.pop(context, true),
            child: Text('Forget'),
          ),
        ],
      ),
    );
    if (confirmed == true) await widget.onDeleted(item);
  }

  @override
  Widget build(BuildContext context) {
    final activeCount = widget.items
        .where((item) => item.status == 'active')
        .length;
    final highConfidence = widget.items
        .where((item) => (item.confidence ?? 0) >= 85)
        .length;
    final items = _filteredItems;
    return Column(
      key: const Key('memory-view'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _ShellCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _SectionTitle(
                icon: Icons.psychology_alt_rounded,
                title: "Bean's Knowledge",
                subtitle:
                    '$activeCount active facts • $highConfidence high confidence',
                infoKey: const Key('memory-info'),
                infoTitle: "Bean's Knowledge help",
                infoBullets: const [
                  'Save durable facts, preferences, instructions, projects, and routines that Bean should remember.',
                  "Use Notes for documents and longer writing; use Bean's Knowledge for concise assistant context.",
                  "Recent request history helps Bean answer recall questions without turning every request into durable knowledge.",
                ],
              ),
              const SizedBox(height: 14),
              Wrap(
                spacing: 10,
                runSpacing: 10,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  SizedBox(
                    width: 240,
                    child: TextField(
                      controller: _searchController,
                      decoration: const InputDecoration(
                        prefixIcon: Icon(Icons.search_rounded),
                        hintText: 'Search knowledge',
                      ),
                      onChanged: (_) => setState(() {}),
                    ),
                  ),
                  DropdownButton<String>(
                    value: _typeFilter,
                    items: [
                      const DropdownMenuItem(
                        value: 'all',
                        child: Text('All types'),
                      ),
                      ..._memoryTypeOptions.map(
                        (type) => DropdownMenuItem(
                          value: type,
                          child: Text(_memoryTypeLabel(type)),
                        ),
                      ),
                    ],
                    onChanged: (value) =>
                        setState(() => _typeFilter = value ?? 'all'),
                  ),
                  OutlinedButton.icon(
                    onPressed: _refreshing ? null : _refresh,
                    icon: _refreshing
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Icon(Icons.refresh_rounded),
                    label: Text(_refreshing ? 'Refreshing' : 'Refresh'),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              _MemoryComposer(
                titleController: _newTitleController,
                contentController: _newContentController,
                type: _newType,
                saving: _saving,
                onTypeChanged: (value) => setState(() => _newType = value),
                onSubmit: _create,
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        if (items.isEmpty)
          const _EmptySurface(
            label:
                'No matching knowledge. Add knowledge above or adjust the search/filter.',
          )
        else
          ...items.map(
            (item) => Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: _MemoryItemTile(
                item: item,
                onEdit: () => _edit(item),
                onForget: () => _forget(item),
              ),
            ),
          ),
        if (widget.summaries.isNotEmpty) ...[
          const SizedBox(height: 8),
          _MemorySummarySection(summaries: widget.summaries),
        ],
        if (widget.history.isNotEmpty) ...[
          const SizedBox(height: 14),
          _RequestHistorySection(history: widget.history),
        ],
      ],
    );
  }
}

class _MemoryComposer extends StatelessWidget {
  const _MemoryComposer({
    required this.titleController,
    required this.contentController,
    required this.type,
    required this.saving,
    required this.onTypeChanged,
    required this.onSubmit,
  });

  final TextEditingController titleController;
  final TextEditingController contentController;
  final String type;
  final bool saving;
  final ValueChanged<String> onTypeChanged;
  final VoidCallback onSubmit;

  @override
  Widget build(BuildContext context) => DecoratedBox(
    decoration: _quietSurfaceDecoration(
      color: _quietMutedSurfaceColor(alpha: .48),
      borderAlpha: .38,
    ),
    child: Padding(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Add knowledge',
            style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: titleController,
            decoration: const InputDecoration(hintText: 'Optional title'),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: contentController,
            minLines: 2,
            maxLines: 5,
            decoration: _longFormInputDecoration(
              hintText: 'Something Bean should remember',
            ),
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              DropdownButton<String>(
                value: type,
                items: _memoryTypeOptions
                    .map(
                      (option) => DropdownMenuItem(
                        value: option,
                        child: Text(_memoryTypeLabel(option)),
                      ),
                    )
                    .toList(),
                onChanged: (value) {
                  if (value != null) onTypeChanged(value);
                },
              ),
              const Spacer(),
              FilledButton.icon(
                onPressed: saving ? null : onSubmit,
                icon: saving
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Icon(Icons.add_rounded),
                label: Text(saving ? 'Saving' : 'Remember'),
              ),
            ],
          ),
        ],
      ),
    ),
  );
}

class _MemoryItemTile extends StatelessWidget {
  const _MemoryItemTile({
    required this.item,
    required this.onEdit,
    required this.onForget,
  });

  final HermesMemoryItem item;
  final VoidCallback onEdit;
  final VoidCallback onForget;

  @override
  Widget build(BuildContext context) {
    final title = item.title?.trim().isNotEmpty == true
        ? item.title!.trim()
        : _memoryTypeLabel(item.type);
    final updated = _formatNaturalDateTime(item.updatedAt);
    final confidence = item.confidence == null ? null : '${item.confidence}%';
    return _ShellCard(
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: onEdit,
        child: Padding(
          padding: const EdgeInsets.all(2),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  _MemoryTypeChip(type: item.type),
                  if (confidence != null) ...[
                    const SizedBox(width: 8),
                    Text(
                      confidence,
                      style: TextStyle(
                        color: HeyBeanTheme.muted,
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                  const Spacer(),
                  IconButton(
                    tooltip: 'Edit knowledge',
                    onPressed: onEdit,
                    icon: Icon(Icons.edit_rounded),
                  ),
                  IconButton(
                    tooltip: 'Forget knowledge',
                    onPressed: onForget,
                    icon: Icon(Icons.delete_outline_rounded),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Text(
                title,
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w900),
              ),
              const SizedBox(height: 6),
              Text(item.content, style: TextStyle(height: 1.35)),
              if (updated.isNotEmpty) ...[
                const SizedBox(height: 8),
                Text(
                  'Updated $updated',
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
      ),
    );
  }
}

class _MemoryTypeChip extends StatelessWidget {
  const _MemoryTypeChip({required this.type});

  final String type;

  @override
  Widget build(BuildContext context) => DecoratedBox(
    decoration: BoxDecoration(
      color: HeyBeanTheme.accent.withValues(alpha: .12),
      borderRadius: BorderRadius.circular(999),
      border: Border.all(color: HeyBeanTheme.border),
    ),
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      child: Text(
        _memoryTypeLabel(type),
        style: TextStyle(fontSize: 12, fontWeight: FontWeight.w800),
      ),
    ),
  );
}

class _MemoryEditResult {
  const _MemoryEditResult({
    required this.title,
    required this.content,
    required this.type,
  });

  final String title;
  final String content;
  final String type;
}

class _MemoryEditSheet extends StatefulWidget {
  const _MemoryEditSheet({required this.item});

  final HermesMemoryItem item;

  @override
  State<_MemoryEditSheet> createState() => _MemoryEditSheetState();
}

class _MemoryEditSheetState extends State<_MemoryEditSheet> {
  late final TextEditingController _titleController;
  late final TextEditingController _contentController;
  late String _type;

  @override
  void initState() {
    super.initState();
    _titleController = TextEditingController(text: widget.item.title ?? '');
    _contentController = TextEditingController(text: widget.item.content);
    _type = _memoryTypeOptions.contains(widget.item.type)
        ? widget.item.type
        : 'fact';
  }

  @override
  void dispose() {
    _titleController.dispose();
    _contentController.dispose();
    super.dispose();
  }

  void _save() {
    final content = _contentController.text.trim();
    if (content.isEmpty) return;
    Navigator.pop(
      context,
      _MemoryEditResult(
        title: _titleController.text.trim(),
        content: content,
        type: _type,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.viewInsetsOf(context).bottom;
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(18, 0, 18, bottom + 18),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'Edit knowledge',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _titleController,
              decoration: const InputDecoration(hintText: 'Optional title'),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _contentController,
              autofocus: true,
              minLines: 4,
              maxLines: 8,
              decoration: _longFormInputDecoration(
                hintText: 'Knowledge content',
              ),
            ),
            const SizedBox(height: 10),
            DropdownButton<String>(
              value: _type,
              items: _memoryTypeOptions
                  .map(
                    (option) => DropdownMenuItem(
                      value: option,
                      child: Text(_memoryTypeLabel(option)),
                    ),
                  )
                  .toList(),
              onChanged: (value) {
                if (value != null) setState(() => _type = value);
              },
            ),
            const SizedBox(height: 10),
            FilledButton(onPressed: _save, child: Text('Save')),
          ],
        ),
      ),
    );
  }
}

class _MemorySummarySection extends StatelessWidget {
  const _MemorySummarySection({required this.summaries});

  final List<HermesMemorySummary> summaries;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Summaries',
          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w900),
        ),
        const SizedBox(height: 10),
        ...summaries
            .take(4)
            .map(
              (summary) => Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      summary.title,
                      style: TextStyle(fontWeight: FontWeight.w800),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      summary.content,
                      style: TextStyle(color: HeyBeanTheme.muted),
                    ),
                  ],
                ),
              ),
            ),
      ],
    ),
  );
}

class _RequestHistorySection extends StatelessWidget {
  const _RequestHistorySection({required this.history});

  final List<HermesRequestHistoryItem> history;

  @override
  Widget build(BuildContext context) => _ShellCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Recent requests',
          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w900),
        ),
        const SizedBox(height: 10),
        ...history.take(10).map((item) {
          final created = _formatNaturalDateTime(item.createdAt);
          return Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: EdgeInsets.only(top: 2),
                  child: Icon(
                    Icons.history_rounded,
                    size: 17,
                    color: HeyBeanTheme.muted,
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        item.content,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(fontWeight: FontWeight.w700),
                      ),
                      if (created.isNotEmpty)
                        Text(
                          created,
                          style: TextStyle(
                            color: HeyBeanTheme.muted,
                            fontSize: 12,
                          ),
                        ),
                    ],
                  ),
                ),
              ],
            ),
          );
        }),
      ],
    ),
  );
}
