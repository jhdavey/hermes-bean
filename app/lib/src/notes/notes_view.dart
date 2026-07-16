part of '../../main.dart';

class _NotesView extends StatefulWidget {
  const _NotesView({
    required this.folders,
    required this.notes,
    required this.workspaces,
    this.activeWorkspaceId,
    this.openNoteId,
    required this.onFolderCreated,
    required this.onFolderDeleted,
    required this.onNoteSaved,
    required this.onNoteDeleted,
  });

  final List<HermesNoteFolder> folders;
  final List<HermesNote> notes;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
  final int? openNoteId;
  final Future<HermesNoteFolder> Function(String name) onFolderCreated;
  final Future<void> Function(HermesNoteFolder folder) onFolderDeleted;
  final Future<HermesNote> Function(
    HermesNote? note, {
    required String title,
    required String bodyHtml,
    required String plainText,
    int? folderId,
    bool clearFolder,
    bool? isPinned,
    Map<String, Object?>? metadata,
    List<Object>? syncToWorkspaceIds,
  })
  onNoteSaved;
  final Future<void> Function(HermesNote note) onNoteDeleted;

  @override
  State<_NotesView> createState() => _NotesViewState();
}

class _NotesViewState extends State<_NotesView> {
  final _titleController = TextEditingController();
  final _bodyController = _FormattedNoteTextController();
  final _searchController = TextEditingController();
  final _titleFocusNode = FocusNode();
  final _bodyFocusNode = FocusNode();
  final Set<String> _activeTypingFormats = {};
  String _folderFilter = 'all';
  String _noteSort = 'recent';
  int? _selectedId;
  int? _editingFolderId;
  bool _searchExpanded = false;
  final Set<String> _pendingFolderNames = <String>{};
  Timer? _autosaveTimer;
  String _lastBodyText = '';

  @override
  void initState() {
    super.initState();
    _titleFocusNode.addListener(_handleEditorFocusChanged);
    _bodyFocusNode.addListener(_handleEditorFocusChanged);
    _selectRequestedNote();
  }

  @override
  void didUpdateWidget(covariant _NotesView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!widget.notes.any((note) => note.id == _selectedId)) {
      _autosaveTimer?.cancel();
      _selectedId = null;
      _editingFolderId = null;
    }
    final selectedFolderId = int.tryParse(_folderFilter);
    if (selectedFolderId != null &&
        !widget.folders.any((folder) => folder.id == selectedFolderId)) {
      _folderFilter = 'all';
    }
    if (oldWidget.openNoteId != widget.openNoteId) {
      _selectRequestedNote();
    }
  }

  @override
  void dispose() {
    _autosaveTimer?.cancel();
    _titleController.dispose();
    _bodyController.dispose();
    _searchController.dispose();
    _titleFocusNode.removeListener(_handleEditorFocusChanged);
    _bodyFocusNode.removeListener(_handleEditorFocusChanged);
    _titleFocusNode.dispose();
    _bodyFocusNode.dispose();
    super.dispose();
  }

  List<HermesNote> get _filteredNotes {
    final search = _searchController.text.trim().toLowerCase();
    final filtered = _sortedNotes(widget.notes).where((note) {
      final folderMatches =
          _folderFilter == 'all' ||
          (_folderFilter == 'pinned' && note.isPinned) ||
          (_folderFilter == 'unfiled' && note.folderId == null) ||
          note.folderId?.toString() == _folderFilter;
      if (!folderMatches) return false;
      if (search.isEmpty) return true;
      final folder = _folderFor(note.folderId);
      return [note.title, note.plainText, folder?.name].whereType<String>().any(
        (value) => value.toLowerCase().contains(search),
      );
    }).toList();
    return _sortNotesForList(filtered);
  }

  HermesNote? get _selectedNote => widget.notes
      .where((note) => note.id == _selectedId)
      .cast<HermesNote?>()
      .firstOrNull;

  void _selectNote(HermesNote? note) {
    final noteFormats = _noteFormatsFromMetadata(note?.metadata);
    final normalizedBody = _normalizeCheckedCheckboxMarkers(
      note?.plainText ?? _plainTextFromHtml(note?.bodyHtml),
    );
    _selectedId = note?.id;
    _editingFolderId = note?.folderId;
    _titleController.text = note?.title ?? '';
    _bodyController.text = normalizedBody.text;
    _bodyController.setFormats([...noteFormats, ...normalizedBody.formats]);
    _lastBodyText = _bodyController.text;
    _activeTypingFormats.clear();
  }

  HermesNoteFolder? _folderFor(int? id) =>
      widget.folders.where((folder) => folder.id == id).firstOrNull;

  String get _currentFolderTitle {
    switch (_folderFilter) {
      case 'pinned':
        return 'Pinned';
      case 'unfiled':
        return 'Unfiled';
      case 'all':
        return 'All Notes';
    }
    return _folderFor(int.tryParse(_folderFilter))?.name ?? 'All Notes';
  }

  void _selectRequestedNote() {
    final noteId = widget.openNoteId;
    if (noteId == null) return;
    final note = widget.notes
        .where((candidate) => candidate.id == noteId)
        .cast<HermesNote?>()
        .firstOrNull;
    if (note == null) return;
    _selectNote(note);
    _focusBodyOnNextFrame();
  }

  List<HermesNote> _sortNotesForList(List<HermesNote> notes) {
    final sorted = [...notes];
    switch (_noteSort) {
      case 'title':
        sorted.sort(
          (a, b) => a.title.toLowerCase().compareTo(b.title.toLowerCase()),
        );
      default:
        sorted.sort((a, b) {
          final aTime = DateTime.tryParse(a.updatedAt ?? '') ?? DateTime(1970);
          final bTime = DateTime.tryParse(b.updatedAt ?? '') ?? DateTime(1970);
          return bTime.compareTo(aTime);
        });
    }
    return sorted;
  }

  Future<void> _newNote() async {
    await _flushAutosave();
    final saved = await widget.onNoteSaved(
      null,
      title: 'New Note',
      bodyHtml: '',
      plainText: '',
      folderId: int.tryParse(_folderFilter),
      clearFolder: int.tryParse(_folderFilter) == null,
      metadata: const {},
    );
    if (!mounted) return;
    setState(() => _selectNote(saved));
    _focusBodyOnNextFrame();
  }

  Future<void> _save({
    bool? isPinned,
    Map<String, Object?>? metadata,
    List<Object>? syncToWorkspaceIds,
  }) async {
    final note = _selectedNote;
    if (note == null || (_noteIsLocked(note) && metadata == null)) return;
    final title = _titleController.text.trim().isEmpty
        ? 'New Note'
        : _titleController.text.trim();
    final plain = _normalizedNotePlainText(_bodyController.text);
    final saved = await widget.onNoteSaved(
      note,
      title: title,
      bodyHtml: _htmlFromFormattedPlainText(plain, _bodyController.formats),
      plainText: plain,
      folderId: _editingFolderId,
      clearFolder: _editingFolderId == null,
      isPinned: isPinned ?? note.isPinned,
      metadata: _metadataWithNoteFormats(
        metadata ?? note.metadata,
        _bodyController.formats,
      ),
      syncToWorkspaceIds: syncToWorkspaceIds,
    );
    if (mounted && _selectedId == note.id) {
      setState(() {
        _selectedId = saved.id;
        _editingFolderId = saved.folderId;
      });
    }
  }

  Future<void> _togglePin() async {
    final note = _selectedNote;
    if (note == null) return;
    await _flushAutosave();
    await _save(isPinned: !note.isPinned, metadata: note.metadata);
  }

  Future<void> _toggleLock() async {
    final note = _selectedNote;
    if (note == null) return;
    await _flushAutosave();
    final metadata = {...note.metadata, 'locked': !_noteIsLocked(note)};
    await _save(metadata: metadata);
    if (!mounted) return;
    if (metadata['locked'] == true) FocusScope.of(context).unfocus();
  }

  Future<void> _moveSelectedNoteToFolder(int? folderId) async {
    final note = _selectedNote;
    if (note == null) return;
    await _flushAutosave();
    setState(() => _editingFolderId = folderId);
    await _save(metadata: note.metadata);
  }

  Future<void> _showNoteWorkspaceSheet() async {
    final note = _selectedNote;
    if (note == null) return;
    await _flushAutosave();
    if (!mounted) return;
    final selectedIds = _initialSyncWorkspaceIds(
      linkedWorkspaceIds: note.linkedWorkspaceIds,
      workspaceId: note.workspaceId,
      activeWorkspaceId: widget.activeWorkspaceId,
    ).toSet();
    final selected = await showModalBottomSheet<List<Object>>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) => _NoteWorkspaceSyncSheet(
        note: note,
        workspaces: widget.workspaces,
        activeWorkspaceId: widget.activeWorkspaceId,
        initialSyncWorkspaceIds: selectedIds,
      ),
    );
    if (selected == null) return;
    await _save(metadata: note.metadata, syncToWorkspaceIds: selected);
  }

  Future<void> _deleteNote() async {
    final note = _selectedNote;
    if (note == null) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Delete note?'),
        content: Text('This will permanently delete "${note.title}".'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text('Delete'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    _autosaveTimer?.cancel();
    await widget.onNoteDeleted(note);
    if (!mounted) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _selectedId = null;
      _editingFolderId = null;
    });
  }

  Future<void> _newFolder() async {
    final name = await showDialog<String>(
      context: context,
      builder: (context) => const _NewNoteFolderDialog(),
    );
    if (name == null || name.isEmpty) return;
    final normalizedName = name.trim();
    final nameKey = normalizedName.toLowerCase();
    final existing = widget.folders
        .where((folder) => folder.name.trim().toLowerCase() == nameKey)
        .cast<HermesNoteFolder?>()
        .firstOrNull;
    if (existing != null) {
      if (mounted) setState(() => _folderFilter = existing.id.toString());
      return;
    }
    if (!_pendingFolderNames.add(nameKey)) return;
    try {
      final folder = await widget.onFolderCreated(normalizedName);
      if (mounted) setState(() => _folderFilter = folder.id.toString());
    } finally {
      _pendingFolderNames.remove(nameKey);
    }
  }

  Future<bool> _deleteFolder(HermesNoteFolder folder) async {
    final noteCount = widget.notes
        .where((note) => note.folderId == folder.id)
        .length;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Delete folder?'),
        content: Text(
          noteCount == 0
              ? 'This will delete "${folder.name}".'
              : 'This will delete "${folder.name}". The $noteCount ${noteCount == 1 ? 'note' : 'notes'} in it will stay in All Notes.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text('Delete'),
          ),
        ],
      ),
    );
    if (confirmed != true) return false;
    await widget.onFolderDeleted(folder);
    if (!mounted) return true;
    if (_folderFilter == folder.id.toString()) {
      setState(() => _folderFilter = 'all');
    }
    return true;
  }

  Future<void> _showNotesListOptionsSheet() async {
    await showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (sheetContext) => _NotesListOptionsSheet(
        folders: widget.folders,
        notes: widget.notes,
        selectedFolder: _folderFilter,
        selectedSort: _noteSort,
        onFilterSelected: (value) {
          Navigator.pop(sheetContext);
          if (mounted) setState(() => _folderFilter = value);
        },
        onSortSelected: (value) {
          Navigator.pop(sheetContext);
          if (mounted) setState(() => _noteSort = value);
        },
        onNewFolder: () {
          Navigator.pop(sheetContext);
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) unawaited(_newFolder());
          });
        },
        onDeleteFolder: _deleteFolder,
      ),
    );
  }

  void _toggleNotesSearch() {
    setState(() {
      if (_searchExpanded || _searchController.text.isNotEmpty) {
        _searchController.clear();
        _searchExpanded = false;
      } else {
        _searchExpanded = true;
      }
    });
  }

  Future<void> _showMoveFolderSheet() async {
    final selectedFolderId = await showModalBottomSheet<int?>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: Icon(Icons.folder_open_rounded),
              title: Text('All Notes'),
              onTap: () => Navigator.pop(context, -2),
            ),
            ...widget.folders.map(
              (folder) => ListTile(
                leading: Icon(Icons.folder_rounded),
                title: Text(folder.name),
                onTap: () => Navigator.pop(context, folder.id),
              ),
            ),
            ListTile(
              leading: Icon(Icons.create_new_folder_rounded),
              title: Text('New folder'),
              onTap: () async {
                Navigator.pop(context, -1);
              },
            ),
          ],
        ),
      ),
    );
    if (!mounted) return;
    if (selectedFolderId == -1) {
      await _newFolder();
      final folderId = int.tryParse(_folderFilter);
      if (folderId != null) await _moveSelectedNoteToFolder(folderId);
      return;
    }
    if (selectedFolderId == -2) {
      await _moveSelectedNoteToFolder(null);
      return;
    }
    if (selectedFolderId == null) return;
    await _moveSelectedNoteToFolder(selectedFolderId);
  }

  void _handleEditorFocusChanged() {
    if (mounted) setState(() {});
  }

  void _dismissEditorFocus(PointerDownEvent event) {
    _titleFocusNode.unfocus();
    _bodyFocusNode.unfocus();
  }

  void _openNote(HermesNote note) {
    unawaited(_flushAutosave());
    setState(() => _selectNote(note));
    _focusBodyOnNextFrame();
  }

  Future<void> _closeNote() async {
    await _flushAutosave();
    if (!mounted) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _selectedId = null;
      _editingFolderId = null;
    });
  }

  void _focusBodyOnNextFrame() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _bodyFocusNode.requestFocus();
    });
  }

  void _queueAutosave() {
    final note = _selectedNote;
    if (note == null || _noteIsLocked(note)) return;
    _autosaveTimer?.cancel();
    // The shell applies this draft locally before the next frame and coalesces
    // server writes in the background. Navigation never owns the draft.
    unawaited(_save());
  }

  Future<void> _flushAutosave() async {
    final note = _selectedNote;
    if (note == null || _noteIsLocked(note)) return;
    _autosaveTimer?.cancel();
    await _save();
  }

  bool _noteIsLocked(HermesNote? note) {
    final locked = note?.metadata['locked'] ?? note?.metadata['is_locked'];
    return locked == true || locked?.toString() == 'true';
  }

  void _toggleInlineFormat(String kind) {
    if (_noteIsLocked(_selectedNote)) return;
    final selection = _bodyController.selection;
    final text = _bodyController.text;
    final start = selection.start < 0 ? text.length : selection.start;
    final end = selection.end < 0 ? text.length : selection.end;
    final range = TextRange(
      start: math.min(start, end).clamp(0, text.length),
      end: math.max(start, end).clamp(0, text.length),
    );
    if (range.isCollapsed) {
      setState(() {
        if (_activeTypingFormats.contains(kind)) {
          _activeTypingFormats.remove(kind);
        } else {
          _activeTypingFormats.add(kind);
        }
      });
      _bodyFocusNode.requestFocus();
      return;
    }

    if (_bodyController.rangeFullyHasFormat(kind, range.start, range.end)) {
      _bodyController.removeFormat(kind, range);
    } else {
      _bodyController.addFormat(_NoteTextFormat(range.start, range.end, kind));
    }
    _bodyController.selection = TextSelection(
      baseOffset: range.start,
      extentOffset: range.end,
    );
    _queueAutosave();
    _keepBodyToolbarOpen();
  }

  void _applyLineFormat(String kind) {
    if (_noteIsLocked(_selectedNote)) return;
    final lineRange = _currentLineRange();
    if (lineRange == null) return;
    if (lineRange.isCollapsed) {
      setState(() {
        if (_activeTypingFormats.contains(kind)) {
          _activeTypingFormats.remove(kind);
        } else {
          _activeTypingFormats.add(kind);
        }
      });
      _bodyFocusNode.requestFocus();
      return;
    }
    if (_bodyController.rangeFullyHasFormat(
      kind,
      lineRange.start,
      lineRange.end,
    )) {
      _bodyController.removeFormat(kind, lineRange);
    } else {
      _bodyController.addFormat(
        _NoteTextFormat(lineRange.start, lineRange.end, kind),
        replaceKinds: {'heading'},
      );
    }
    _queueAutosave();
    _keepBodyToolbarOpen();
  }

  void _insertListPrefix(String prefix) {
    if (_noteIsLocked(_selectedNote)) return;
    final lineRange = _currentLineRange();
    if (lineRange == null) return;
    final text = _bodyController.text;
    final line = text.substring(lineRange.start, lineRange.end);
    final marker = _noteLineMarkerForLine(line, lineRange.start);
    final indentationLength = _noteLineIndentationLength(line);
    final insertion =
        marker?.markerStart ?? lineRange.start + indentationLength;
    final nextText = marker == null
        ? text.replaceRange(insertion, insertion, prefix)
        : marker.marker == prefix
        ? text
        : text.replaceRange(marker.markerStart, marker.markerEnd, prefix);
    if (nextText == text) {
      _keepBodyToolbarOpen();
      return;
    }
    _replaceBodyTextFromFormatter(
      previousText: text,
      nextText: nextText,
      selectionOffset: insertion + prefix.length,
    );
    if (prefix == '• ') {
      _bodyController.removeFormat(
        'checkbox_checked',
        TextRange(start: insertion, end: insertion + 1),
      );
    }
    _queueAutosave();
    _keepBodyToolbarOpen();
  }

  void _insertCheckboxPrefix() {
    if (_noteIsLocked(_selectedNote)) return;
    final lineRange = _currentLineRange();
    if (lineRange == null) return;
    final text = _bodyController.text;
    final line = text.substring(lineRange.start, lineRange.end);
    final marker = _noteLineMarkerForLine(line, lineRange.start);
    final indentationLength = _noteLineIndentationLength(line);
    final insertion =
        marker?.markerStart ?? lineRange.start + indentationLength;
    final nextText = marker == null
        ? text.replaceRange(insertion, insertion, '☐ ')
        : marker.isUncheckedCheckbox
        ? text
        : text.replaceRange(marker.markerStart, marker.markerEnd, '☐ ');
    if (nextText == text) {
      _keepBodyToolbarOpen();
      return;
    }
    _replaceBodyTextFromFormatter(
      previousText: text,
      nextText: nextText,
      selectionOffset: insertion + 2,
    );
    _bodyController.removeFormat(
      'checkbox_checked',
      TextRange(start: insertion, end: insertion + 1),
    );
    _queueAutosave();
    _keepBodyToolbarOpen();
  }

  void _handleBodyPointerUp(PointerUpEvent event) {
    if (_noteIsLocked(_selectedNote)) return;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || !_bodyFocusNode.hasFocus) return;
      _toggleCheckboxAtSelection();
    });
  }

  void _toggleCheckboxAtSelection() {
    final text = _bodyController.text;
    final offset = _bodyController.selection.baseOffset;
    if (offset < 0 || offset > text.length) return;
    final before = text.lastIndexOf('\n', math.max(0, offset - 1));
    final lineStart = before == -1 ? 0 : before + 1;
    final after = text.indexOf('\n', offset);
    final lineEnd = after == -1 ? text.length : after;
    final line = text.substring(lineStart, lineEnd);
    final marker = _noteLineMarkerForLine(line, lineStart);
    if (marker == null || !marker.isCheckbox) return;
    if (offset < marker.markerStart || offset > marker.markerEnd) return;
    if (marker.isCheckedCheckbox) {
      _bodyController.text = text.replaceRange(
        marker.markerStart,
        marker.markerEnd,
        '☐ ',
      );
      _lastBodyText = _bodyController.text;
    }
    final range = TextRange(
      start: marker.markerStart,
      end: marker.markerStart + 1,
    );
    if (_bodyController.rangeFullyHasFormat(
      'checkbox_checked',
      range.start,
      range.end,
    )) {
      _bodyController.removeFormat('checkbox_checked', range);
    } else {
      _bodyController.addFormat(
        _NoteTextFormat(range.start, range.end, 'checkbox_checked'),
      );
    }
    _bodyController.selection = TextSelection.collapsed(
      offset: math.min(marker.markerEnd, _bodyController.text.length),
    );
    _queueAutosave();
    _keepBodyToolbarOpen();
  }

  void _replaceBodyTextFromFormatter({
    required String previousText,
    required String nextText,
    required int selectionOffset,
  }) {
    _bodyController.value = _bodyController.value.copyWith(
      text: nextText,
      selection: TextSelection.collapsed(
        offset: selectionOffset.clamp(0, nextText.length),
      ),
      composing: TextRange.empty,
    );
    _bodyController.reconcileTextChange(
      previousText: previousText,
      activeFormats: const {},
    );
    _lastBodyText = _bodyController.text;
    _bodyController.clampFormats();
  }

  void _keepBodyToolbarOpen() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || _noteIsLocked(_selectedNote)) return;
      _bodyFocusNode.requestFocus();
    });
  }

  TextRange? _currentLineRange() {
    final text = _bodyController.text;
    if (text.isEmpty) return const TextRange(start: 0, end: 0);
    final selection = _bodyController.selection;
    final offset =
        (selection.baseOffset < 0 ? text.length : selection.baseOffset).clamp(
          0,
          text.length,
        );
    final before = text.lastIndexOf('\n', math.max(0, offset - 1));
    final after = text.indexOf('\n', offset);
    return TextRange(
      start: before == -1 ? 0 : before + 1,
      end: after == -1 ? text.length : after,
    );
  }

  void _insertDivider() {
    if (_noteIsLocked(_selectedNote)) return;
    final text = _bodyController.text;
    final selection = _bodyController.selection;
    final start = selection.start < 0 ? text.length : selection.start;
    final end = selection.end < 0 ? text.length : selection.end;
    final prefix = start == 0 || text.substring(0, start).endsWith('\n')
        ? ''
        : '\n';
    final divider = '$prefix────────────\n';
    _bodyController.text = text.replaceRange(start, end, divider);
    _bodyController.selection = TextSelection.collapsed(
      offset: start + divider.length,
    );
    _queueAutosave();
  }

  void _handleBodyChanged(String _) {
    final previousText = _lastBodyText;
    _continueListAfterLineBreak(previousText);
    _bodyController.reconcileTextChange(
      previousText: previousText,
      activeFormats: _activeTypingFormats,
    );
    _lastBodyText = _bodyController.text;
    _bodyController.clampFormats();
    _queueAutosave();
  }

  void _continueListAfterLineBreak(String previousText) {
    final currentText = _bodyController.text;
    if (previousText == currentText) return;

    var prefix = 0;
    while (prefix < previousText.length &&
        prefix < currentText.length &&
        previousText.codeUnitAt(prefix) == currentText.codeUnitAt(prefix)) {
      prefix += 1;
    }

    var previousSuffix = previousText.length;
    var currentSuffix = currentText.length;
    while (previousSuffix > prefix &&
        currentSuffix > prefix &&
        previousText.codeUnitAt(previousSuffix - 1) ==
            currentText.codeUnitAt(currentSuffix - 1)) {
      previousSuffix -= 1;
      currentSuffix -= 1;
    }

    if (previousSuffix != prefix) return;
    final inserted = currentText.substring(prefix, currentSuffix);
    if (inserted != '\n') return;

    final beforeLineBreak = previousText.lastIndexOf(
      '\n',
      math.max(0, prefix - 1),
    );
    final lineStart = beforeLineBreak == -1 ? 0 : beforeLineBreak + 1;
    final previousLine = previousText.substring(lineStart, prefix);
    final continuation = _continuedLinePrefix(previousLine);
    if (continuation == null) return;

    final nextText = currentText.replaceRange(
      prefix,
      prefix + 1,
      '\n$continuation',
    );
    final nextOffset = prefix + 1 + continuation.length;
    _bodyController.value = _bodyController.value.copyWith(
      text: nextText,
      selection: TextSelection.collapsed(offset: nextOffset),
      composing: TextRange.empty,
    );
  }

  String? _continuedLinePrefix(String line) {
    final match = RegExp(r'^(\s*)(☐ |☑ |• )').firstMatch(line);
    if (match == null) {
      if (line.trim().isEmpty) return null;
      final indentationLength = _noteLineIndentationLength(line);
      return indentationLength == 0
          ? null
          : line.substring(0, indentationLength);
    }
    final indentation = match.group(1) ?? '';
    final marker = match.group(2);
    if (marker == '• ') return '$indentation• ';
    return '$indentation☐ ';
  }

  void _indentSelectedLines(int amount) {
    if (_noteIsLocked(_selectedNote)) return;
    final previousText = _bodyController.text;
    if (previousText.isEmpty) return;
    final selection = _bodyController.selection;
    final baseOffset =
        (selection.baseOffset < 0 ? previousText.length : selection.baseOffset)
            .clamp(0, previousText.length);
    final extentOffset =
        (selection.extentOffset < 0
                ? previousText.length
                : selection.extentOffset)
            .clamp(0, previousText.length);
    final start = math.min(baseOffset, extentOffset);
    final end = math.max(baseOffset, extentOffset);
    final firstLineBreak = previousText.lastIndexOf(
      '\n',
      math.max(0, start - 1),
    );
    final firstLineStart = firstLineBreak == -1 ? 0 : firstLineBreak + 1;
    final affectedEnd = end > start && end > 0 ? end - 1 : end;

    final lineStarts = <int>[];
    var cursor = firstLineStart;
    while (cursor <= affectedEnd && cursor < previousText.length) {
      lineStarts.add(cursor);
      final nextBreak = previousText.indexOf('\n', cursor);
      if (nextBreak == -1) break;
      cursor = nextBreak + 1;
    }
    if (lineStarts.isEmpty) lineStarts.add(firstLineStart);

    var nextText = previousText;
    var nextBase = baseOffset;
    var nextExtent = extentOffset;
    for (final lineStart in lineStarts.reversed) {
      if (amount > 0) {
        nextText = nextText.replaceRange(lineStart, lineStart, '  ');
        if (lineStart <= nextBase) nextBase += 2;
        if (lineStart <= nextExtent) nextExtent += 2;
        continue;
      }

      final available = nextText.length - lineStart;
      if (available <= 0) continue;
      final removeLength = nextText.startsWith('  ', lineStart)
          ? 2
          : nextText.startsWith(' ', lineStart) ||
                nextText.startsWith('\t', lineStart)
          ? 1
          : 0;
      if (removeLength == 0) continue;
      nextText = nextText.replaceRange(lineStart, lineStart + removeLength, '');
      if (lineStart < nextBase) {
        nextBase -= math.min(removeLength, nextBase - lineStart);
      }
      if (lineStart < nextExtent) {
        nextExtent -= math.min(removeLength, nextExtent - lineStart);
      }
    }

    if (nextText == previousText) {
      _keepBodyToolbarOpen();
      return;
    }
    _bodyController.value = _bodyController.value.copyWith(
      text: nextText,
      selection: TextSelection(
        baseOffset: nextBase.clamp(0, nextText.length),
        extentOffset: nextExtent.clamp(0, nextText.length),
      ),
      composing: TextRange.empty,
    );
    _bodyController.reconcileTextChange(
      previousText: previousText,
      activeFormats: const {},
    );
    _lastBodyText = _bodyController.text;
    _bodyController.clampFormats();
    _queueAutosave();
    _keepBodyToolbarOpen();
  }

  @override
  Widget build(BuildContext context) {
    final selected = _selectedNote;
    final notes = _filteredNotes;
    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 180),
      switchInCurve: Curves.easeOut,
      switchOutCurve: Curves.easeIn,
      child: selected == null
          ? _buildListScreen(notes)
          : _buildDetailScreen(selected),
    );
  }

  Widget _buildListScreen(List<HermesNote> notes) {
    final pinned = notes.where((note) => note.isPinned).toList();
    final unpinned = notes.where((note) => !note.isPinned).toList();
    return Container(
      key: const Key('notes-view'),
      color: HeyBeanTheme.bg0,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 14, 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Padding(
                        padding: const EdgeInsets.only(left: 6),
                        child: Text(
                          _currentFolderTitle,
                          key: const Key('notes-folder-title'),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: HeyBeanTheme.text,
                            fontSize: 24,
                            fontWeight: FontWeight.w700,
                            letterSpacing: 0,
                          ),
                        ),
                      ),
                    ),
                    IconButton.outlined(
                      key: const Key('notes-search-toggle'),
                      onPressed: _toggleNotesSearch,
                      icon: Icon(
                        _searchExpanded || _searchController.text.isNotEmpty
                            ? Icons.close_rounded
                            : Icons.search_rounded,
                      ),
                      tooltip: _searchExpanded
                          ? 'Close search'
                          : 'Search notes',
                      style: IconButton.styleFrom(
                        foregroundColor: HeyBeanTheme.muted,
                        side: BorderSide(color: _quietBorderColor(alpha: .36)),
                        backgroundColor: _quietSurfaceColor(alpha: .72),
                        fixedSize: const Size(34, 34),
                      ),
                    ),
                    const SizedBox(width: 8),
                    IconButton.outlined(
                      key: const Key('notes-list-menu'),
                      onPressed: _showNotesListOptionsSheet,
                      icon: Icon(Icons.more_vert_rounded),
                      tooltip: 'Notes options',
                      style: IconButton.styleFrom(
                        foregroundColor: HeyBeanTheme.muted,
                        side: BorderSide(color: _quietBorderColor(alpha: .36)),
                        backgroundColor: _quietSurfaceColor(alpha: .72),
                        fixedSize: const Size(34, 34),
                      ),
                    ),
                  ],
                ),
                AnimatedSwitcher(
                  duration: const Duration(milliseconds: 160),
                  switchInCurve: Curves.easeOut,
                  switchOutCurve: Curves.easeIn,
                  transitionBuilder: (child, animation) => SizeTransition(
                    sizeFactor: animation,
                    axisAlignment: -1,
                    child: FadeTransition(opacity: animation, child: child),
                  ),
                  child: _searchExpanded || _searchController.text.isNotEmpty
                      ? Padding(
                          key: const Key('notes-search-expanded'),
                          padding: const EdgeInsets.fromLTRB(6, 10, 0, 0),
                          child: _searchField(),
                        )
                      : const SizedBox(
                          key: Key('notes-search-collapsed'),
                          height: 0,
                        ),
                ),
              ],
            ),
          ),
          Expanded(
            child: notes.isEmpty
                ? _emptyNotesList()
                : ListView.builder(
                    key: const Key('notes-list-screen'),
                    padding: const EdgeInsets.fromLTRB(0, 0, 0, 96),
                    itemCount:
                        (pinned.isNotEmpty ? 1 : 0) +
                        (unpinned.isNotEmpty ? 1 : 0),
                    itemBuilder: (context, index) {
                      if (pinned.isNotEmpty && index == 0) {
                        return _NoteSection(
                          title: 'Pinned',
                          notes: pinned,
                          onTap: _openNote,
                        );
                      }
                      return _NoteSection(
                        title: null,
                        notes: unpinned,
                        onTap: _openNote,
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }

  Widget _buildDetailScreen(HermesNote selected) {
    final locked = _noteIsLocked(selected);
    final toolbarVisible =
        !locked && (_titleFocusNode.hasFocus || _bodyFocusNode.hasFocus);
    return Container(
      key: ValueKey('note-detail-${selected.id}'),
      color: HeyBeanTheme.surface,
      child: Stack(
        children: [
          Column(
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(8, 8, 8, 6),
                child: Row(
                  children: [
                    IconButton(
                      key: const Key('note-detail-back'),
                      onPressed: _closeNote,
                      icon: Icon(Icons.arrow_back_ios_new_rounded),
                      tooltip: 'Notes',
                    ),
                    Expanded(
                      child: TextField(
                        key: const Key('note-detail-title'),
                        controller: _titleController,
                        focusNode: _titleFocusNode,
                        readOnly: locked,
                        textAlign: TextAlign.center,
                        maxLines: 1,
                        textInputAction: TextInputAction.done,
                        onChanged: (_) => _queueAutosave(),
                        onTapOutside: _dismissEditorFocus,
                        style: TextStyle(
                          color: HeyBeanTheme.text,
                          fontSize: 17,
                          fontWeight: FontWeight.w800,
                          letterSpacing: 0,
                        ),
                        decoration: InputDecoration(
                          isDense: true,
                          contentPadding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 8,
                          ),
                          border: InputBorder.none,
                          enabledBorder: InputBorder.none,
                          focusedBorder: UnderlineInputBorder(
                            borderSide: BorderSide(
                              color: HeyBeanTheme.accent.withValues(alpha: .45),
                              width: 1.4,
                            ),
                          ),
                          disabledBorder: InputBorder.none,
                          errorBorder: InputBorder.none,
                          focusedErrorBorder: InputBorder.none,
                          hintText: 'New Note',
                          suffixIcon: locked
                              ? Icon(
                                  Icons.lock_rounded,
                                  size: 16,
                                  color: HeyBeanTheme.muted,
                                )
                              : null,
                          suffixIconConstraints: const BoxConstraints(
                            minWidth: 26,
                            minHeight: 26,
                          ),
                        ),
                      ),
                    ),
                    PopupMenuButton<String>(
                      key: const Key('note-detail-menu'),
                      icon: Icon(Icons.more_vert_rounded),
                      tooltip: 'Note actions',
                      onSelected: (value) async {
                        switch (value) {
                          case 'pin':
                            await _togglePin();
                            break;
                          case 'move':
                            await _showMoveFolderSheet();
                            break;
                          case 'workspaces':
                            await _showNoteWorkspaceSheet();
                            break;
                          case 'lock':
                            await _toggleLock();
                            break;
                          case 'delete':
                            await _deleteNote();
                            break;
                        }
                      },
                      itemBuilder: (context) => [
                        PopupMenuItem<String>(
                          value: 'pin',
                          child: Text(
                            selected.isPinned ? 'Unpin Note' : 'Pin Note',
                          ),
                        ),
                        PopupMenuItem<String>(
                          value: 'move',
                          child: Text('Move to Folder'),
                        ),
                        PopupMenuItem<String>(
                          key: Key('note-workspaces-action'),
                          value: 'workspaces',
                          child: Text('Workspaces'),
                        ),
                        PopupMenuItem<String>(
                          value: 'lock',
                          child: Text(locked ? 'Unlock Note' : 'Lock Note'),
                        ),
                        const PopupMenuDivider(),
                        PopupMenuItem<String>(
                          value: 'delete',
                          child: Text(
                            'Delete Note',
                            style: TextStyle(color: HeyBeanTheme.destructive),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              if (locked)
                Container(
                  margin: const EdgeInsets.fromLTRB(18, 4, 18, 8),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: HeyBeanTheme.accent.withValues(alpha: .12),
                    border: Border.all(
                      color: HeyBeanTheme.accent.withValues(alpha: .25),
                    ),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Row(
                    children: const [
                      Icon(Icons.lock_rounded, size: 18),
                      SizedBox(width: 10),
                      Expanded(child: Text('This note is locked for editing.')),
                    ],
                  ),
                ),
              Expanded(
                child: ListView(
                  padding: EdgeInsets.fromLTRB(
                    18,
                    4,
                    18,
                    toolbarVisible ? 88 : 28,
                  ),
                  children: [
                    Listener(
                      behavior: HitTestBehavior.translucent,
                      onPointerUp: _handleBodyPointerUp,
                      child: TextField(
                        key: const Key('note-body-field'),
                        controller: _bodyController,
                        focusNode: _bodyFocusNode,
                        readOnly: locked,
                        minLines: 10,
                        maxLines: null,
                        keyboardType: TextInputType.multiline,
                        textAlignVertical: TextAlignVertical.top,
                        onChanged: _handleBodyChanged,
                        onTapOutside: _dismissEditorFocus,
                        decoration: const InputDecoration(
                          border: InputBorder.none,
                          enabledBorder: InputBorder.none,
                          focusedBorder: InputBorder.none,
                          disabledBorder: InputBorder.none,
                          errorBorder: InputBorder.none,
                          focusedErrorBorder: InputBorder.none,
                          hintText: 'Start writing',
                        ),
                        style: TextStyle(fontSize: 17, height: 1.55),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          AnimatedPositioned(
            duration: const Duration(milliseconds: 180),
            curve: Curves.easeOut,
            left: 0,
            right: 0,
            bottom: toolbarVisible ? 0 : -70,
            child: IgnorePointer(
              ignoring: !toolbarVisible,
              child: AnimatedOpacity(
                duration: const Duration(milliseconds: 140),
                opacity: toolbarVisible ? 1 : 0,
                child: TextFieldTapRegion(child: _keyboardToolbar()),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _emptyNotesList() => Center(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        _BeanNotesIcon(color: HeyBeanTheme.muted, size: 42),
        const SizedBox(height: 10),
        Text('No notes', style: TextStyle(fontWeight: FontWeight.w800)),
        const SizedBox(height: 12),
        _CreateButton(onPressed: _newNote, tooltip: 'New note'),
      ],
    ),
  );

  Widget _keyboardToolbar() => SafeArea(
    top: false,
    child: Material(
      elevation: 0,
      color: HeyBeanTheme.surface,
      child: Container(
        height: 48,
        decoration: BoxDecoration(
          border: Border(top: BorderSide(color: _quietBorderColor(alpha: .42))),
        ),
        child: ListView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
          children: [
            _formatButton(
              'H1',
              () => _applyLineFormat('heading'),
              active: _activeTypingFormats.contains('heading'),
              key: const Key('note-format-heading'),
            ),
            _formatButton(
              'B',
              () => _toggleInlineFormat('bold'),
              active: _activeTypingFormats.contains('bold'),
              key: const Key('note-format-bold'),
            ),
            _formatButton(
              'I',
              () => _toggleInlineFormat('italic'),
              active: _activeTypingFormats.contains('italic'),
              key: const Key('note-format-italic'),
            ),
            _formatIconButton(
              Icons.check_box_outlined,
              _insertCheckboxPrefix,
              key: const Key('note-format-checkbox'),
            ),
            _formatIconButton(
              Icons.format_list_bulleted_rounded,
              () => _insertListPrefix('• '),
              key: const Key('note-format-bullet-list'),
            ),
            _formatIconButton(
              Icons.format_indent_decrease_rounded,
              () => _indentSelectedLines(-1),
              key: const Key('note-format-outdent'),
            ),
            _formatIconButton(
              Icons.format_indent_increase_rounded,
              () => _indentSelectedLines(1),
              key: const Key('note-format-indent'),
            ),
            _formatIconButton(
              Icons.horizontal_rule_rounded,
              _insertDivider,
              key: const Key('note-format-divider'),
            ),
          ],
        ),
      ),
    ),
  );

  Widget _formatButton(
    String label,
    VoidCallback onPressed, {
    bool active = false,
    Key? key,
  }) => Padding(
    padding: const EdgeInsets.only(right: 8),
    child: OutlinedButton(
      key: key,
      onPressed: onPressed,
      style: OutlinedButton.styleFrom(
        backgroundColor: active
            ? HeyBeanTheme.accent.withValues(alpha: .08)
            : null,
        foregroundColor: active ? HeyBeanTheme.text : HeyBeanTheme.muted,
        side: BorderSide(
          color: active
              ? HeyBeanTheme.accentStrong.withValues(alpha: .42)
              : _quietBorderColor(alpha: .36),
        ),
        minimumSize: const Size(36, 32),
        padding: const EdgeInsets.symmetric(horizontal: 9),
      ),
      child: Text(label, style: TextStyle(fontWeight: FontWeight.w600)),
    ),
  );

  Widget _formatIconButton(IconData icon, VoidCallback onPressed, {Key? key}) =>
      Padding(
        padding: const EdgeInsets.only(right: 8),
        child: IconButton.outlined(
          key: key,
          onPressed: onPressed,
          icon: Icon(icon),
          style: IconButton.styleFrom(
            foregroundColor: HeyBeanTheme.muted,
            side: BorderSide(color: _quietBorderColor(alpha: .36)),
          ),
        ),
      );

  Widget _searchField() => TextField(
    key: const Key('notes-search-field'),
    controller: _searchController,
    autofocus: true,
    textAlignVertical: TextAlignVertical.center,
    decoration: InputDecoration(
      prefixIcon: Icon(Icons.search),
      hintText: 'Search',
      isDense: true,
      filled: true,
      fillColor: _quietSurfaceColor(alpha: .72),
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.all(Radius.circular(999)),
        borderSide: BorderSide(color: HeyBeanTheme.border),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.all(Radius.circular(999)),
        borderSide: BorderSide(color: HeyBeanTheme.border),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: const BorderRadius.all(Radius.circular(999)),
        borderSide: BorderSide(
          color: HeyBeanTheme.accentStrong.withValues(alpha: .48),
          width: 1,
        ),
      ),
    ),
    onChanged: (_) => setState(() {}),
  );
}

class _NotesListOptionsSheet extends StatefulWidget {
  const _NotesListOptionsSheet({
    required this.folders,
    required this.notes,
    required this.selectedFolder,
    required this.selectedSort,
    required this.onFilterSelected,
    required this.onSortSelected,
    required this.onNewFolder,
    required this.onDeleteFolder,
  });

  final List<HermesNoteFolder> folders;
  final List<HermesNote> notes;
  final String selectedFolder;
  final String selectedSort;
  final ValueChanged<String> onFilterSelected;
  final ValueChanged<String> onSortSelected;
  final VoidCallback onNewFolder;
  final Future<bool> Function(HermesNoteFolder folder) onDeleteFolder;

  @override
  State<_NotesListOptionsSheet> createState() => _NotesListOptionsSheetState();
}

class _NotesListOptionsSheetState extends State<_NotesListOptionsSheet> {
  final Set<int> _deletedFolderIds = {};
  final Set<int> _deletingFolderIds = {};

  int _countForFolder(int? folderId) =>
      widget.notes.where((note) => note.folderId == folderId).length;

  Future<void> _deleteFolder(HermesNoteFolder folder) async {
    if (_deletingFolderIds.contains(folder.id)) return;
    setState(() => _deletingFolderIds.add(folder.id));
    try {
      final deleted = await widget.onDeleteFolder(folder);
      if (!mounted) return;
      if (deleted) {
        setState(() => _deletedFolderIds.add(folder.id));
      }
    } finally {
      if (mounted) setState(() => _deletingFolderIds.remove(folder.id));
    }
  }

  @override
  Widget build(BuildContext context) {
    final maxHeight = MediaQuery.sizeOf(context).height * 0.82;
    final visibleFolders = widget.folders
        .where((folder) => !_deletedFolderIds.contains(folder.id))
        .toList();
    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: maxHeight),
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(16, 2, 16, 18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              _NotesOptionsSection(
                title: 'View',
                children: [
                  _NotesOptionRow(
                    key: const Key('notes-filter-all'),
                    iconWidget: _BeanNotesIcon(
                      size: 22,
                      color: widget.selectedFolder == 'all'
                          ? HeyBeanTheme.text
                          : HeyBeanTheme.muted,
                    ),
                    label: 'All Notes',
                    count: widget.notes.length,
                    selected: widget.selectedFolder == 'all',
                    onTap: () => widget.onFilterSelected('all'),
                  ),
                  _NotesOptionRow(
                    key: const Key('notes-filter-pinned'),
                    icon: Icons.push_pin_rounded,
                    label: 'Pinned',
                    count: widget.notes.where((note) => note.isPinned).length,
                    selected: widget.selectedFolder == 'pinned',
                    onTap: () => widget.onFilterSelected('pinned'),
                  ),
                  _NotesOptionRow(
                    key: const Key('notes-filter-unfiled'),
                    icon: Icons.folder_open_rounded,
                    label: 'Unfiled',
                    count: _countForFolder(null),
                    selected: widget.selectedFolder == 'unfiled',
                    onTap: () => widget.onFilterSelected('unfiled'),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              _NotesOptionsSection(
                title: 'Folders',
                trailing: _CreateButton(
                  key: const Key('notes-new-folder'),
                  onPressed: widget.onNewFolder,
                  tooltip: 'New folder',
                ),
                emptyText: visibleFolders.isEmpty ? 'No folders yet' : null,
                children: [
                  for (final folder in visibleFolders)
                    _NotesOptionRow(
                      key: Key('notes-filter-folder-${folder.id}'),
                      icon: Icons.folder_rounded,
                      label: folder.name,
                      count: _countForFolder(folder.id),
                      selected: widget.selectedFolder == folder.id.toString(),
                      onTap: () =>
                          widget.onFilterSelected(folder.id.toString()),
                      trailing: IconButton(
                        key: Key('delete-note-folder-${folder.id}'),
                        tooltip: 'Delete ${folder.name}',
                        onPressed: _deletingFolderIds.contains(folder.id)
                            ? null
                            : () => unawaited(_deleteFolder(folder)),
                        icon: Icon(Icons.delete_outline_rounded),
                        color: HeyBeanTheme.destructive,
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 14),
              _NotesOptionsSection(
                title: 'Sort',
                children: [
                  _NotesOptionRow(
                    key: const Key('notes-sort-recent'),
                    icon: Icons.schedule_rounded,
                    label: 'Most recently edited',
                    selected: widget.selectedSort == 'recent',
                    onTap: () => widget.onSortSelected('recent'),
                  ),
                  _NotesOptionRow(
                    key: const Key('notes-sort-title'),
                    icon: Icons.sort_by_alpha_rounded,
                    label: 'Title',
                    selected: widget.selectedSort == 'title',
                    onTap: () => widget.onSortSelected('title'),
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

class _NotesOptionsSection extends StatelessWidget {
  const _NotesOptionsSection({
    required this.title,
    required this.children,
    this.emptyText,
    this.trailing,
  });

  final String title;
  final List<Widget> children;
  final String? emptyText;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(2, 0, 2, 8),
        child: Row(
          children: [
            Expanded(
              child: Text(
                title,
                style: TextStyle(
                  color: HeyBeanTheme.muted,
                  fontSize: 15,
                  fontWeight: FontWeight.w800,
                  letterSpacing: 0,
                ),
              ),
            ),
            if (trailing != null) trailing!,
          ],
        ),
      ),
      DecoratedBox(
        decoration: _quietSurfaceDecoration(
          radius: 14,
          color: _quietSurfaceColor(alpha: .82),
          borderAlpha: .36,
        ),
        child: children.isEmpty
            ? Padding(
                padding: const EdgeInsets.all(16),
                child: Text(
                  emptyText ?? 'Nothing to show',
                  style: TextStyle(
                    color: HeyBeanTheme.muted,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              )
            : Column(
                children: [
                  for (var index = 0; index < children.length; index++) ...[
                    children[index],
                    if (index != children.length - 1)
                      Divider(height: 1, indent: 56, endIndent: 12),
                  ],
                ],
              ),
      ),
    ],
  );
}

class _NotesOptionRow extends StatelessWidget {
  const _NotesOptionRow({
    super.key,
    this.icon,
    this.iconWidget,
    required this.label,
    required this.selected,
    required this.onTap,
    this.count,
    this.trailing,
  });

  final IconData? icon;
  final Widget? iconWidget;
  final String label;
  final int? count;
  final bool selected;
  final VoidCallback onTap;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Material(
    color: selected
        ? HeyBeanTheme.accent.withValues(alpha: 0.08)
        : HeyBeanTheme.surface,
    child: InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 6, 8, 6),
        child: SizedBox(
          height: 46,
          child: Row(
            children: [
              iconWidget ??
                  Icon(
                    icon,
                    color: selected ? HeyBeanTheme.text : HeyBeanTheme.muted,
                    size: 20,
                  ),
              const SizedBox(width: 14),
              Expanded(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: HeyBeanTheme.text,
                    fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                  ),
                ),
              ),
              if (count != null)
                Padding(
                  padding: const EdgeInsets.only(left: 8),
                  child: Text(
                    '$count',
                    style: TextStyle(
                      color: HeyBeanTheme.muted,
                      fontSize: 13,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ),
              if (selected)
                Padding(
                  padding: const EdgeInsets.only(left: 10),
                  child: Icon(
                    Icons.check_rounded,
                    color: HeyBeanTheme.muted,
                    size: 20,
                  ),
                ),
              if (trailing != null)
                Padding(
                  padding: const EdgeInsets.only(left: 4),
                  child: trailing,
                ),
            ],
          ),
        ),
      ),
    ),
  );
}

class _NoteWorkspaceSyncSheet extends StatefulWidget {
  const _NoteWorkspaceSyncSheet({
    required this.note,
    required this.workspaces,
    required this.activeWorkspaceId,
    required this.initialSyncWorkspaceIds,
  });

  final HermesNote note;
  final List<HermesWorkspace> workspaces;
  final String? activeWorkspaceId;
  final Set<Object> initialSyncWorkspaceIds;

  @override
  State<_NoteWorkspaceSyncSheet> createState() =>
      _NoteWorkspaceSyncSheetState();
}

class _NoteWorkspaceSyncSheetState extends State<_NoteWorkspaceSyncSheet> {
  late final Set<int> _selectedWorkspaceIds;

  @override
  void initState() {
    super.initState();
    _selectedWorkspaceIds = widget.initialSyncWorkspaceIds
        .map(_workspaceValueToInt)
        .whereType<int>()
        .toSet();
  }

  @override
  Widget build(BuildContext context) {
    final primaryWorkspaceId =
        widget.note.workspaceId ??
        _workspaceValueToInt(widget.activeWorkspaceId);
    final primaryWorkspace = widget.workspaces
        .where((workspace) => workspace.numericId == primaryWorkspaceId)
        .cast<HermesWorkspace?>()
        .firstOrNull;
    final syncTargets =
        widget.workspaces
            .where((workspace) => workspace.numericId != null)
            .where((workspace) => workspace.numericId != primaryWorkspaceId)
            .toList()
          ..sort(
            (a, b) => a.name.toLowerCase().compareTo(b.name.toLowerCase()),
          );

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 2, 16, 18),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Note workspaces',
              style: TextStyle(
                color: HeyBeanTheme.text,
                fontSize: 20,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              'Choose which additional workspaces this note is synced to.',
              style: TextStyle(
                color: HeyBeanTheme.muted,
                fontWeight: FontWeight.w500,
              ),
            ),
            const SizedBox(height: 14),
            _NotesOptionsSection(
              title: 'Current copy',
              children: [
                _NotesOptionRow(
                  icon: Icons.home_work_outlined,
                  label: primaryWorkspace == null
                      ? 'Current workspace'
                      : primaryWorkspace.isPersonal
                      ? 'Personal'
                      : primaryWorkspace.name,
                  selected: true,
                  onTap: () {},
                  trailing: Padding(
                    padding: EdgeInsets.only(left: 8, right: 8),
                    child: Text(
                      'Fixed',
                      style: TextStyle(
                        color: HeyBeanTheme.muted,
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 14),
            _NotesOptionsSection(
              title: 'Also sync to',
              emptyText: syncTargets.isEmpty
                  ? 'No other workspaces available'
                  : null,
              children: [
                for (final workspace in syncTargets)
                  _NotesOptionRow(
                    key: Key('note-sync-workspace-${workspace.id}'),
                    icon: Icons.account_tree_outlined,
                    label: workspace.isPersonal ? 'Personal' : workspace.name,
                    selected: _selectedWorkspaceIds.contains(
                      workspace.numericId,
                    ),
                    onTap: () {
                      final workspaceId = workspace.numericId;
                      if (workspaceId == null) return;
                      setState(() {
                        if (_selectedWorkspaceIds.contains(workspaceId)) {
                          _selectedWorkspaceIds.remove(workspaceId);
                        } else {
                          _selectedWorkspaceIds.add(workspaceId);
                        }
                      });
                    },
                  ),
              ],
            ),
            const SizedBox(height: 18),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => Navigator.pop(context),
                    child: Text('Cancel'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: FilledButton(
                    key: const Key('note-sync-workspaces-save'),
                    onPressed: () => Navigator.pop(
                      context,
                      _selectedWorkspaceIds.toList()..sort(),
                    ),
                    child: Text('Save'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _NoteTextFormat {
  const _NoteTextFormat(this.start, this.end, this.kind);

  final int start;
  final int end;
  final String kind;

  bool get isValid => end > start && start >= 0;

  Map<String, Object?> toJson() => {'start': start, 'end': end, 'kind': kind};

  static _NoteTextFormat? fromJson(Object? value) {
    if (value is! Map) return null;
    final start = _readIntFromObject(value['start']);
    final end = _readIntFromObject(value['end']);
    final kind = value['kind']?.toString();
    if (start == null || end == null || kind == null || kind.isEmpty) {
      return null;
    }
    final format = _NoteTextFormat(start, end, kind);
    return format.isValid ? format : null;
  }
}

class _NormalizedNoteText {
  const _NormalizedNoteText(this.text, this.formats);

  final String text;
  final List<_NoteTextFormat> formats;
}

class _NoteLineMarker {
  const _NoteLineMarker({
    required this.lineStart,
    required this.indentationLength,
    required this.marker,
  });

  final int lineStart;
  final int indentationLength;
  final String marker;

  int get markerStart => lineStart + indentationLength;
  int get markerEnd => markerStart + marker.length;
  bool get isBullet => marker == '• ';
  bool get isCheckbox => marker == '☐ ' || marker == '☑ ';
  bool get isUncheckedCheckbox => marker == '☐ ';
  bool get isCheckedCheckbox => marker == '☑ ';
}

_NoteLineMarker? _noteLineMarkerForLine(String line, int lineStart) {
  final indentationLength = _noteLineIndentationLength(line);
  if (line.length < indentationLength + 2) return null;
  final marker = line.substring(indentationLength, indentationLength + 2);
  if (marker != '• ' && marker != '☐ ' && marker != '☑ ') return null;
  return _NoteLineMarker(
    lineStart: lineStart,
    indentationLength: indentationLength,
    marker: marker,
  );
}

int _noteLineIndentationLength(String line) {
  var index = 0;
  while (index < line.length) {
    final codeUnit = line.codeUnitAt(index);
    if (codeUnit != 32 && codeUnit != 9) break;
    index += 1;
  }
  return index;
}

_NormalizedNoteText _normalizeCheckedCheckboxMarkers(String? value) {
  final text = value ?? '';
  if (!text.contains('☑ ')) return _NormalizedNoteText(text, const []);
  final buffer = StringBuffer();
  final formats = <_NoteTextFormat>[];
  var sourceOffset = 0;
  for (final line in text.split('\n')) {
    if (sourceOffset > 0) buffer.write('\n');
    final outputLineStart = buffer.length;
    final marker = _noteLineMarkerForLine(line, outputLineStart);
    if (marker?.isCheckedCheckbox == true) {
      final markerOffset = marker!.markerStart - outputLineStart;
      buffer.write(line.substring(0, markerOffset));
      buffer.write('☐ ');
      buffer.write(line.substring(markerOffset + 2));
      formats.add(
        _NoteTextFormat(
          marker.markerStart,
          marker.markerStart + 1,
          'checkbox_checked',
        ),
      );
    } else {
      buffer.write(line);
    }
    sourceOffset += line.length + 1;
  }
  return _NormalizedNoteText(buffer.toString(), formats);
}

class _FormattedNoteTextController extends TextEditingController {
  List<_NoteTextFormat> _formats = const [];

  List<_NoteTextFormat> get formats => List.unmodifiable(_formats);

  void setFormats(List<_NoteTextFormat> formats) {
    _formats = _clampedFormats(formats, text.length);
    notifyListeners();
  }

  void addFormat(
    _NoteTextFormat format, {
    Set<String> replaceKinds = const {},
  }) {
    final clamped = _clampedFormat(format, text.length);
    if (clamped == null) return;
    _formats = [
      for (final existing in _formats)
        if (!_formatOverlaps(existing, clamped) ||
            (!replaceKinds.contains(existing.kind) &&
                existing.kind != clamped.kind))
          existing,
      clamped,
    ];
    notifyListeners();
  }

  void removeFormat(String kind, TextRange range) {
    if (range.isCollapsed) return;
    final next = <_NoteTextFormat>[];
    for (final existing in _formats) {
      if (existing.kind != kind ||
          existing.end <= range.start ||
          existing.start >= range.end) {
        next.add(existing);
        continue;
      }
      if (existing.start < range.start) {
        next.add(_NoteTextFormat(existing.start, range.start, existing.kind));
      }
      if (existing.end > range.end) {
        next.add(_NoteTextFormat(range.end, existing.end, existing.kind));
      }
    }
    _formats = _clampedFormats(next, text.length);
    notifyListeners();
  }

  bool rangeFullyHasFormat(String kind, int start, int end) {
    if (start >= end) return false;
    final clampedStart = start.clamp(0, text.length);
    final clampedEnd = end.clamp(0, text.length);
    if (clampedStart >= clampedEnd) return false;
    var coveredUntil = clampedStart;
    final matching =
        _formats
            .where(
              (format) =>
                  format.kind == kind &&
                  format.end > clampedStart &&
                  format.start < clampedEnd,
            )
            .toList()
          ..sort((a, b) => a.start.compareTo(b.start));
    for (final format in matching) {
      if (format.start > coveredUntil) return false;
      if (format.end > coveredUntil) coveredUntil = format.end;
      if (coveredUntil >= clampedEnd) return true;
    }
    return false;
  }

  void reconcileTextChange({
    required String previousText,
    required Set<String> activeFormats,
  }) {
    final nextText = text;
    if (previousText == nextText) {
      clampFormats();
      return;
    }

    var prefix = 0;
    while (prefix < previousText.length &&
        prefix < nextText.length &&
        previousText.codeUnitAt(prefix) == nextText.codeUnitAt(prefix)) {
      prefix += 1;
    }

    var previousSuffix = previousText.length;
    var nextSuffix = nextText.length;
    while (previousSuffix > prefix &&
        nextSuffix > prefix &&
        previousText.codeUnitAt(previousSuffix - 1) ==
            nextText.codeUnitAt(nextSuffix - 1)) {
      previousSuffix -= 1;
      nextSuffix -= 1;
    }

    final removedLength = previousSuffix - prefix;
    final insertedLength = nextSuffix - prefix;
    final delta = insertedLength - removedLength;
    final shifted = <_NoteTextFormat>[];
    for (final format in _formats) {
      if (format.end <= prefix) {
        shifted.add(format);
      } else if (format.start >= previousSuffix) {
        shifted.add(
          _NoteTextFormat(
            format.start + delta,
            format.end + delta,
            format.kind,
          ),
        );
      } else {
        final start = math.min(format.start, prefix);
        final end = format.end > previousSuffix
            ? format.end + delta
            : math.max(start, prefix);
        if (end > start) shifted.add(_NoteTextFormat(start, end, format.kind));
      }
    }

    for (final kind in activeFormats) {
      if (insertedLength <= 0) continue;
      shifted.add(_NoteTextFormat(prefix, prefix + insertedLength, kind));
    }

    _formats = _normalizeFormats(shifted, nextText.length);
    notifyListeners();
  }

  void clampFormats() {
    _formats = _clampedFormats(_formats, text.length);
  }

  @override
  TextSpan buildTextSpan({
    required BuildContext context,
    TextStyle? style,
    required bool withComposing,
  }) {
    final baseStyle = style ?? TextStyle();
    final value = text;
    if (value.isEmpty) {
      return TextSpan(style: baseStyle, text: value);
    }

    final boundaries = <int>{0, value.length};
    for (final format in _clampedFormats(_formats, value.length)) {
      boundaries
        ..add(format.start)
        ..add(format.end);
    }
    for (final markerStart in _checkboxMarkerStarts(value)) {
      boundaries
        ..add(markerStart)
        ..add(markerStart + 1)
        ..add(markerStart + 2);
    }
    final sorted = boundaries.toList()..sort();
    final spans = <InlineSpan>[];
    for (var index = 0; index < sorted.length - 1; index++) {
      final start = sorted[index];
      final end = sorted[index + 1];
      if (start == end) continue;
      if (_isCheckboxMarkerStart(value, start) && end == start + 1) {
        spans.add(
          WidgetSpan(
            alignment: PlaceholderAlignment.middle,
            child: _NoteCheckboxMarker(
              checked:
                  value.startsWith('☑ ', start) ||
                  rangeFullyHasFormat('checkbox_checked', start, start + 1),
            ),
          ),
        );
        continue;
      }
      spans.add(
        TextSpan(
          text: value.substring(start, end),
          style: _styleForOffset(baseStyle, start),
        ),
      );
    }
    return TextSpan(style: baseStyle, children: spans);
  }

  Iterable<int> _checkboxMarkerStarts(String value) sync* {
    var lineStart = 0;
    while (lineStart < value.length) {
      final lineEndIndex = value.indexOf('\n', lineStart);
      final lineEnd = lineEndIndex == -1 ? value.length : lineEndIndex;
      final marker = _noteLineMarkerForLine(
        value.substring(lineStart, lineEnd),
        lineStart,
      );
      if (marker != null && marker.isCheckbox) yield marker.markerStart;
      if (lineEndIndex == -1) break;
      lineStart = lineEndIndex + 1;
    }
  }

  bool _isCheckboxMarkerStart(String value, int index) {
    if (index < 0 || index >= value.length - 1) return false;
    final before = value.lastIndexOf('\n', math.max(0, index - 1));
    final lineStart = before == -1 ? 0 : before + 1;
    final after = value.indexOf('\n', index);
    final lineEnd = after == -1 ? value.length : after;
    final marker = _noteLineMarkerForLine(
      value.substring(lineStart, lineEnd),
      lineStart,
    );
    return marker != null && marker.isCheckbox && marker.markerStart == index;
  }

  TextStyle _styleForOffset(TextStyle baseStyle, int offset) {
    var next = baseStyle;
    for (final format in _formats) {
      if (format.start > offset || format.end <= offset) continue;
      switch (format.kind) {
        case 'bold':
          next = next.merge(TextStyle(fontWeight: FontWeight.w900));
          break;
        case 'italic':
          next = next.merge(TextStyle(fontStyle: FontStyle.italic));
          break;
        case 'heading':
          next = next.merge(
            TextStyle(fontSize: 25, fontWeight: FontWeight.w900),
          );
          break;
      }
    }
    return next;
  }
}

class _NoteCheckboxMarker extends StatelessWidget {
  const _NoteCheckboxMarker({required this.checked});

  final bool checked;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(right: 2),
    child: SizedBox(
      width: 17,
      height: 17,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: checked ? HeyBeanTheme.accent : Colors.transparent,
          border: Border.all(
            color: checked ? HeyBeanTheme.accentStrong : HeyBeanTheme.muted,
            width: 1.6,
          ),
          borderRadius: BorderRadius.circular(4),
        ),
        child: checked
            ? Icon(Icons.check_rounded, size: 14, color: Colors.white)
            : null,
      ),
    ),
  );
}

class _NewNoteFolderDialog extends StatefulWidget {
  const _NewNoteFolderDialog();

  @override
  State<_NewNoteFolderDialog> createState() => _NewNoteFolderDialogState();
}

class _NewNoteFolderDialogState extends State<_NewNoteFolderDialog> {
  late final TextEditingController _controller;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text('New folder'),
    content: TextField(
      key: const Key('new-note-folder-name'),
      controller: _controller,
      autofocus: true,
      textCapitalization: TextCapitalization.sentences,
      textInputAction: TextInputAction.done,
      onSubmitted: (_) => _submit(),
      decoration: const InputDecoration(hintText: 'Folder name'),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: Text('Cancel'),
      ),
      FilledButton(
        key: const Key('new-note-folder-create'),
        onPressed: _submit,
        child: Text('Save'),
      ),
    ],
  );

  void _submit() {
    Navigator.pop(context, _controller.text.trim());
  }
}

class _NoteSection extends StatelessWidget {
  const _NoteSection({
    required this.title,
    required this.notes,
    required this.onTap,
  });

  final String? title;
  final List<HermesNote> notes;
  final ValueChanged<HermesNote> onTap;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 14),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (title != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 8, 18, 6),
            child: Text(
              title!,
              style: TextStyle(
                color: HeyBeanTheme.muted,
                fontSize: 12,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        Column(
          children: [
            for (var index = 0; index < notes.length; index++) ...[
              _NoteListTile(note: notes[index], onTap: onTap),
              if (index != notes.length - 1)
                Divider(height: 1, indent: 18, endIndent: 18),
            ],
          ],
        ),
      ],
    ),
  );
}

class _NoteListTile extends StatelessWidget {
  const _NoteListTile({required this.note, required this.onTap});

  final HermesNote note;
  final ValueChanged<HermesNote> onTap;

  @override
  Widget build(BuildContext context) {
    final text = (note.plainText ?? '').trim();
    return ListTile(
      key: Key('note-list-item-${note.id}'),
      onTap: () => onTap(note),
      contentPadding: const EdgeInsets.fromLTRB(18, 4, 12, 4),
      minVerticalPadding: 10,
      title: Row(
        children: [
          Expanded(
            child: Text(
              note.title,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(fontWeight: FontWeight.w600),
            ),
          ),
          if (note.isPinned)
            Padding(
              padding: EdgeInsets.only(left: 8),
              child: Icon(Icons.push_pin_rounded, size: 15),
            ),
        ],
      ),
      subtitle: Text(
        text.isEmpty ? 'No additional text' : text,
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(color: HeyBeanTheme.muted),
      ),
      trailing: Icon(Icons.chevron_right_rounded, color: HeyBeanTheme.muted),
    );
  }
}

String _plainTextFromHtml(String? html) => (html ?? '')
    .replaceAll(RegExp(r'<br\s*/?>', caseSensitive: false), '\n')
    .replaceAll(RegExp(r'<[^>]+>'), '')
    .trim();

String _normalizedNotePlainText(String value) {
  if (value.trim().isEmpty) return '';
  return value
      .replaceFirst(RegExp(r'^\n+'), '')
      .replaceFirst(RegExp(r'\n+$'), '');
}

String _htmlFromFormattedPlainText(
  String plain,
  List<_NoteTextFormat> formats,
) {
  final safeFormats = _clampedFormats(formats, plain.length);
  final lines = plain.split('\n');
  var offset = 0;
  final html = <String>[];
  for (final line in lines) {
    final lineStart = offset;
    final lineEnd = offset + line.length;
    final rendered = _formattedInlineHtml(line, lineStart, safeFormats);
    final isHeading = safeFormats.any(
      (format) =>
          format.kind == 'heading' &&
          format.start < lineEnd &&
          format.end > lineStart,
    );
    html.add(isHeading ? '<h1>$rendered</h1>' : '<div>$rendered</div>');
    offset = lineEnd + 1;
  }
  return html.join();
}

String _formattedInlineHtml(
  String line,
  int lineStart,
  List<_NoteTextFormat> formats,
) {
  if (line.isEmpty) return '';
  final lineEnd = lineStart + line.length;
  final boundaries = <int>{lineStart, lineEnd};
  for (final format in formats) {
    if (format.end <= lineStart || format.start >= lineEnd) continue;
    boundaries
      ..add(format.start.clamp(lineStart, lineEnd))
      ..add(format.end.clamp(lineStart, lineEnd));
  }
  final sorted = boundaries.toList()..sort();
  final chunks = <String>[];
  for (var index = 0; index < sorted.length - 1; index++) {
    final start = sorted[index];
    final end = sorted[index + 1];
    if (start == end) continue;
    var chunk = _escapeHtml(line.substring(start - lineStart, end - lineStart));
    final active = formats.where(
      (format) =>
          format.start < end &&
          format.end > start &&
          (format.kind == 'bold' || format.kind == 'italic'),
    );
    if (active.any((format) => format.kind == 'bold')) {
      chunk = '<strong>$chunk</strong>';
    }
    if (active.any((format) => format.kind == 'italic')) {
      chunk = '<em>$chunk</em>';
    }
    chunks.add(chunk);
  }
  return chunks.join();
}

String _escapeHtml(String value) => value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;');

Map<String, Object?> _metadataWithNoteFormats(
  Map<String, Object?> metadata,
  List<_NoteTextFormat> formats,
) {
  final next = Map<String, Object?>.from(metadata);
  final validFormats = _clampedFormats(formats, 1 << 30);
  if (validFormats.isEmpty) {
    next.remove('flutter_note_formats');
  } else {
    next['flutter_note_formats'] = validFormats
        .map((format) => format.toJson())
        .toList();
  }
  return next;
}

List<_NoteTextFormat> _noteFormatsFromMetadata(Map<String, Object?>? metadata) {
  final raw = metadata?['flutter_note_formats'];
  if (raw is! List) return const [];
  return _clampedFormats(
    raw.map(_NoteTextFormat.fromJson).whereType<_NoteTextFormat>().toList(),
    1 << 30,
  );
}

List<_NoteTextFormat> _clampedFormats(
  List<_NoteTextFormat> formats,
  int textLength,
) => [
  for (final format in formats)
    if (_clampedFormat(format, textLength) case final clamped?) clamped,
];

List<_NoteTextFormat> _normalizeFormats(
  List<_NoteTextFormat> formats,
  int textLength,
) {
  final clamped = _clampedFormats(formats, textLength)
    ..sort((a, b) {
      final kindCompare = a.kind.compareTo(b.kind);
      if (kindCompare != 0) return kindCompare;
      return a.start.compareTo(b.start);
    });
  final normalized = <_NoteTextFormat>[];
  for (final format in clamped) {
    if (normalized.isNotEmpty) {
      final previous = normalized.last;
      if (previous.kind == format.kind && previous.end >= format.start) {
        normalized[normalized.length - 1] = _NoteTextFormat(
          previous.start,
          math.max(previous.end, format.end),
          previous.kind,
        );
        continue;
      }
    }
    normalized.add(format);
  }
  return normalized;
}

_NoteTextFormat? _clampedFormat(_NoteTextFormat format, int textLength) {
  final start = format.start.clamp(0, textLength);
  final end = format.end.clamp(0, textLength);
  final clamped = _NoteTextFormat(start, end, format.kind);
  return clamped.isValid ? clamped : null;
}

bool _formatOverlaps(_NoteTextFormat a, _NoteTextFormat b) =>
    a.start < b.end && b.start < a.end;

int? _readIntFromObject(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  return int.tryParse(value?.toString() ?? '');
}
