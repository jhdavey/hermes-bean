part of '../../main.dart';

class HeyBeanColorTheme {
  const HeyBeanColorTheme({
    required this.key,
    required this.label,
    required this.bg0,
    required this.bg1,
    required this.bg2,
    required this.surface2,
    required this.accent,
    required this.accentStrong,
    required this.accentInk,
    required this.success,
  });

  final String key;
  final String label;
  final Color bg0;
  final Color bg1;
  final Color bg2;
  final Color surface2;
  final Color accent;
  final Color accentStrong;
  final Color accentInk;
  final Color success;
}

const List<HeyBeanColorTheme> heyBeanColorThemes = [
  HeyBeanColorTheme(
    key: 'green',
    label: 'Green',
    bg0: Color(0xFFFFFFFF),
    bg1: Color(0xFFF8FBF6),
    bg2: Color(0xFFF1F7EE),
    surface2: Color(0xFFFBFCFB),
    accent: Color(0xFF7BC98C),
    accentStrong: Color(0xFF52A869),
    accentInk: Color(0xFF173A28),
    success: Color(0xFF7BC98C),
  ),
  HeyBeanColorTheme(
    key: 'gray',
    label: 'Gray',
    bg0: Color(0xFFF9FAFB),
    bg1: Color(0xFFF1F5F9),
    bg2: Color(0xFFE2E8F0),
    surface2: Color(0xFFFBFCFD),
    accent: Color(0xFF94A3B8),
    accentStrong: Color(0xFF64748B),
    accentInk: Color(0xFF263241),
    success: Color(0xFF94A3B8),
  ),
  HeyBeanColorTheme(
    key: 'blue',
    label: 'Blue',
    bg0: Color(0xFFF8FBFF),
    bg1: Color(0xFFEFF6FF),
    bg2: Color(0xFFDBEAFE),
    surface2: Color(0xFFFBFDFF),
    accent: Color(0xFF8CC9FF),
    accentStrong: Color(0xFF3DA2F5),
    accentInk: Color(0xFF173451),
    success: Color(0xFF8CC9FF),
  ),
  HeyBeanColorTheme(
    key: 'purple',
    label: 'Purple',
    bg0: Color(0xFFFBF9FF),
    bg1: Color(0xFFF5F0FF),
    bg2: Color(0xFFEDE9FE),
    surface2: Color(0xFFFCFBFF),
    accent: Color(0xFFC4B5FD),
    accentStrong: Color(0xFF8B5CF6),
    accentInk: Color(0xFF2F1B54),
    success: Color(0xFFC4B5FD),
  ),
  HeyBeanColorTheme(
    key: 'pink',
    label: 'Pink',
    bg0: Color(0xFFFFF8FB),
    bg1: Color(0xFFFDF2F8),
    bg2: Color(0xFFFCE7F3),
    surface2: Color(0xFFFFFBFD),
    accent: Color(0xFFF9A8D4),
    accentStrong: Color(0xFFEC4899),
    accentInk: Color(0xFF4A1730),
    success: Color(0xFFF9A8D4),
  ),
  HeyBeanColorTheme(
    key: 'red',
    label: 'Red',
    bg0: Color(0xFFFFFAFA),
    bg1: Color(0xFFFEF2F2),
    bg2: Color(0xFFFEE2E2),
    surface2: Color(0xFFFFFAFA),
    accent: Color(0xFFFCA5A5),
    accentStrong: Color(0xFFEF4444),
    accentInk: Color(0xFF4F1717),
    success: Color(0xFFFCA5A5),
  ),
  HeyBeanColorTheme(
    key: 'orange',
    label: 'Orange',
    bg0: Color(0xFFFFFAF5),
    bg1: Color(0xFFFFF7ED),
    bg2: Color(0xFFFFEDD5),
    surface2: Color(0xFFFFFAF5),
    accent: Color(0xFFFDBA74),
    accentStrong: Color(0xFFF97316),
    accentInk: Color(0xFF4A2207),
    success: Color(0xFFFDBA74),
  ),
  HeyBeanColorTheme(
    key: 'gold',
    label: 'Gold',
    bg0: Color(0xFFFFFDF7),
    bg1: Color(0xFFFFFBEB),
    bg2: Color(0xFFFEF3C7),
    surface2: Color(0xFFFFFDF7),
    accent: Color(0xFFFCD34D),
    accentStrong: Color(0xFFD97706),
    accentInk: Color(0xFF3F2C07),
    success: Color(0xFFFCD34D),
  ),
  HeyBeanColorTheme(
    key: 'teal',
    label: 'Teal',
    bg0: Color(0xFFF7FFFD),
    bg1: Color(0xFFF0FDFA),
    bg2: Color(0xFFCCFBF1),
    surface2: Color(0xFFFBFFFE),
    accent: Color(0xFF7DD3C7),
    accentStrong: Color(0xFF14B8A6),
    accentInk: Color(0xFF113C38),
    success: Color(0xFF7DD3C7),
  ),
  HeyBeanColorTheme(
    key: 'indigo',
    label: 'Indigo',
    bg0: Color(0xFFF9FAFF),
    bg1: Color(0xFFEEF2FF),
    bg2: Color(0xFFE0E7FF),
    surface2: Color(0xFFFBFBFF),
    accent: Color(0xFFA5B4FC),
    accentStrong: Color(0xFF6366F1),
    accentInk: Color(0xFF202857),
    success: Color(0xFFA5B4FC),
  ),
];

HeyBeanColorTheme heyBeanColorThemeForKey(String key) =>
    heyBeanColorThemes.firstWhere(
      (theme) => theme.key == key.trim().toLowerCase(),
      orElse: () => heyBeanColorThemes.first,
    );

class HeyBeanThemeModeOption {
  const HeyBeanThemeModeOption({
    required this.key,
    required this.label,
    required this.subtitle,
    required this.materialThemeMode,
    this.brightness,
  });

  final String key;
  final String label;
  final String subtitle;
  final ThemeMode materialThemeMode;
  final Brightness? brightness;
}

const List<HeyBeanThemeModeOption> heyBeanThemeModes = [
  HeyBeanThemeModeOption(
    key: 'auto',
    label: 'Auto',
    subtitle: 'Use device setting',
    materialThemeMode: ThemeMode.system,
  ),
  HeyBeanThemeModeOption(
    key: 'light',
    label: 'Light',
    subtitle: 'Always light',
    materialThemeMode: ThemeMode.light,
    brightness: Brightness.light,
  ),
  HeyBeanThemeModeOption(
    key: 'dark',
    label: 'Dark',
    subtitle: 'Always dark',
    materialThemeMode: ThemeMode.dark,
    brightness: Brightness.dark,
  ),
];

HeyBeanThemeModeOption heyBeanThemeModeForKey(String key) =>
    heyBeanThemeModes.firstWhere(
      (mode) => mode.key == key.trim().toLowerCase(),
      orElse: () => heyBeanThemeModes.first,
    );

class HeyBeanTheme {
  const HeyBeanTheme._();

  static HeyBeanColorTheme _current = heyBeanColorThemes.first;
  static bool isDark = false;
  static Color bg0 = _current.bg0;
  static Color bg1 = _current.bg1;
  static Color bg2 = _current.bg2;
  static Color surface = const Color(0xFFFFFFFF);
  static Color surface2 = _current.surface2;
  static Color surfaceSoft = const Color(0xFFF6FAF4);
  static Color text = const Color(0xFF2D3748);
  static Color muted = const Color(0xFF667085);
  static Color border = const Color(0xFFD9DDE3);
  static Color borderStrong = const Color(0xFFCBD1DA);
  static Color accent = _current.accent;
  static Color accentStrong = _current.accentStrong;
  static Color accentInk = _current.accentInk;
  static Color success = _current.success;
  static Color warning = const Color(0xFFF59E0B);
  static Color destructive = const Color(0xFFDC2626);

  static const Color lightSurface = Color(0xFFFFFFFF);
  static const Color lightSurfaceSoft = Color(0xFFF6FAF4);
  static const Color lightText = Color(0xFF2D3748);
  static const Color lightMuted = Color(0xFF667085);
  static const Color lightBorder = Color(0xEBD9DDE3);
  static const Color lightBorderStrong = Color(0xFFD9DDE3);
  static const Color lightWarning = Color(0xFFF59E0B);
  static const Color lightDestructive = Color(0xFFDC2626);

  static const Color darkBg0 = Color(0xFF0B0F14);
  static const Color darkBg1 = Color(0xFF10151C);
  static const Color darkBg2 = Color(0xFF151B23);
  static const Color darkSurface = Color(0xFF141A20);
  static const Color darkSurface2 = Color(0xFF19212A);
  static const Color darkSurfaceSoft = Color(0xFF1F2933);
  static const Color darkInputSurface = Color(0xFF111820);
  static const Color darkText = Color(0xFFF4F7FB);
  static const Color darkMuted = Color(0xFFA7B0BD);
  static const Color darkBorder = Color(0x2E94A3B8);
  static const Color darkBorderStrong = Color(0x4D94A3B8);
  static const Color darkWarning = Color(0xFFFBBF24);
  static const Color darkDestructive = Color(0xFFFB7185);

  static const SystemUiOverlayStyle lightSystemOverlayStyle =
      SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.dark,
        statusBarBrightness: Brightness.light,
        systemNavigationBarColor: Colors.transparent,
        systemNavigationBarIconBrightness: Brightness.dark,
      );

  static const SystemUiOverlayStyle darkSystemOverlayStyle =
      SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.light,
        statusBarBrightness: Brightness.dark,
        systemNavigationBarColor: Colors.transparent,
        systemNavigationBarIconBrightness: Brightness.light,
      );

  static void useTheme(String key, {Brightness brightness = Brightness.light}) {
    _current = heyBeanColorThemeForKey(key);
    isDark = brightness == Brightness.dark;
    bg0 = isDark ? darkBg0 : _current.bg0;
    bg1 = isDark ? darkBg1 : _current.bg1;
    bg2 = isDark ? darkBg2 : _current.bg2;
    surface = isDark ? darkSurface : lightSurface;
    surface2 = isDark ? darkSurface2 : _current.surface2;
    surfaceSoft = isDark ? darkSurfaceSoft : lightSurfaceSoft;
    text = isDark ? darkText : lightText;
    muted = isDark ? darkMuted : lightMuted;
    border = isDark ? darkBorder : lightBorder;
    borderStrong = isDark ? darkBorderStrong : lightBorderStrong;
    accent = _current.accent;
    accentStrong = _current.accentStrong;
    accentInk = _current.accentInk;
    success = _current.success;
    warning = isDark ? darkWarning : lightWarning;
    destructive = isDark ? darkDestructive : lightDestructive;
  }

  static ThemeData lightThemeFor(String key) {
    useTheme(key, brightness: Brightness.light);
    return themeDataFor(key, Brightness.light);
  }

  static ThemeData darkThemeFor(String key) =>
      themeDataFor(key, Brightness.dark);

  static ThemeData themeDataFor(String key, Brightness brightness) {
    final isDarkTheme = brightness == Brightness.dark;
    final colorTheme = heyBeanColorThemeForKey(key);
    final bgColor = isDarkTheme ? darkBg0 : colorTheme.bg0;
    final surfaceColor = isDarkTheme ? darkSurface : lightSurface;
    final surface2Color = isDarkTheme ? darkSurface2 : colorTheme.surface2;
    final inputSurfaceColor = isDarkTheme
        ? darkInputSurface
        : surfaceColor.withValues(alpha: .88);
    final textColor = isDarkTheme ? darkText : lightText;
    final mutedColor = isDarkTheme ? darkMuted : lightMuted;
    final borderColor = isDarkTheme ? darkBorder : lightBorder;
    final borderStrongColor = isDarkTheme
        ? darkBorderStrong
        : lightBorderStrong;
    final accentColor = colorTheme.accent;
    final accentStrongColor = colorTheme.accentStrong;
    final accentInkColor = colorTheme.accentInk;
    final colorScheme =
        ColorScheme.fromSeed(
          brightness: brightness,
          seedColor: accentColor,
        ).copyWith(
          primary: accentColor,
          onPrimary: accentInkColor,
          primaryContainer: accentColor,
          onPrimaryContainer: accentInkColor,
          secondary: accentStrongColor,
          tertiary: colorTheme.success,
          surface: surfaceColor,
          surfaceContainerHighest: surface2Color,
          onSurface: textColor,
          onSurfaceVariant: mutedColor,
          outline: borderStrongColor,
          outlineVariant: borderColor,
          error: isDarkTheme ? darkDestructive : lightDestructive,
        );
    final baseTextTheme = ThemeData(
      brightness: brightness,
      fontFamily: 'Plus Jakarta Sans',
      fontFamilyFallback: const ['Avenir Next', 'Inter', 'Roboto', 'Arial'],
    ).textTheme;
    final textTheme = baseTextTheme.apply(
      bodyColor: textColor,
      displayColor: textColor,
    );

    return ThemeData(
      useMaterial3: true,
      brightness: brightness,
      colorScheme: colorScheme,
      fontFamily: 'Plus Jakarta Sans',
      fontFamilyFallback: const ['Avenir Next', 'Inter', 'Roboto', 'Arial'],
      textTheme: textTheme,
      scaffoldBackgroundColor: Colors.transparent,
      canvasColor: bgColor,
      dividerColor: borderColor,
      appBarTheme: AppBarTheme(
        centerTitle: false,
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        foregroundColor: textColor,
        systemOverlayStyle: isDarkTheme
            ? darkSystemOverlayStyle
            : lightSystemOverlayStyle,
      ),
      cardTheme: CardThemeData(
        color: surfaceColor,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: BorderSide(color: borderColor),
        ),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: surfaceColor,
        surfaceTintColor: Colors.transparent,
        titleTextStyle: textTheme.titleLarge?.copyWith(
          color: textColor,
          fontWeight: FontWeight.w700,
        ),
        contentTextStyle: textTheme.bodyMedium?.copyWith(
          color: mutedColor,
          fontWeight: FontWeight.w500,
          height: 1.35,
        ),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: BorderSide(color: borderColor),
        ),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: surfaceColor,
        modalBackgroundColor: surfaceColor,
        surfaceTintColor: Colors.transparent,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
        ),
      ),
      dividerTheme: DividerThemeData(
        color: borderColor,
        thickness: 1,
        space: 1,
      ),
      chipTheme: ChipThemeData(
        backgroundColor: surface2Color,
        selectedColor: accentColor.withValues(alpha: .14),
        disabledColor: surface2Color.withValues(alpha: .54),
        labelStyle: TextStyle(color: textColor, fontWeight: FontWeight.w600),
        secondaryLabelStyle: TextStyle(
          color: accentStrongColor,
          fontWeight: FontWeight.w700,
        ),
        side: BorderSide(color: borderColor),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: inputSurfaceColor,
        hintStyle: TextStyle(color: mutedColor, fontWeight: FontWeight.w500),
        helperStyle: TextStyle(
          color: mutedColor,
          fontWeight: FontWeight.w500,
          height: 1.25,
        ),
        labelStyle: TextStyle(color: mutedColor, fontWeight: FontWeight.w600),
        floatingLabelStyle: TextStyle(
          color: accentStrongColor,
          fontWeight: FontWeight.w700,
        ),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 14,
          vertical: 12,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: borderColor),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: borderColor),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(
            color: accentColor.withValues(alpha: .62),
            width: 1,
          ),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(
            color: isDarkTheme ? darkDestructive : lightDestructive,
          ),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(
            color: isDarkTheme ? darkDestructive : lightDestructive,
            width: 1,
          ),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: accentColor,
          foregroundColor: accentInkColor,
          textStyle: const TextStyle(fontWeight: FontWeight.w700),
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(999),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: textColor,
          backgroundColor: isDarkTheme
              ? surface2Color.withValues(alpha: .46)
              : Colors.transparent,
          textStyle: const TextStyle(fontWeight: FontWeight.w700),
          side: BorderSide(color: borderStrongColor),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(999),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: accentStrongColor,
          textStyle: const TextStyle(fontWeight: FontWeight.w700),
        ),
      ),
      iconTheme: IconThemeData(color: textColor),
      primaryIconTheme: IconThemeData(color: textColor),
      segmentedButtonTheme: SegmentedButtonThemeData(
        style: ButtonStyle(
          textStyle: WidgetStateProperty.all(
            const TextStyle(fontWeight: FontWeight.w700),
          ),
          foregroundColor: WidgetStateProperty.resolveWith(
            (states) => states.contains(WidgetState.selected)
                ? accentInkColor
                : textColor,
          ),
          backgroundColor: WidgetStateProperty.resolveWith(
            (states) => states.contains(WidgetState.selected)
                ? accentColor
                : surfaceColor,
          ),
          side: WidgetStateProperty.resolveWith(
            (states) => BorderSide(
              color: states.contains(WidgetState.selected)
                  ? accentStrongColor.withValues(alpha: .42)
                  : borderColor,
            ),
          ),
        ),
      ),
      switchTheme: SwitchThemeData(
        thumbColor: WidgetStateProperty.resolveWith(
          (states) =>
              states.contains(WidgetState.selected) ? accentColor : mutedColor,
        ),
        trackColor: WidgetStateProperty.resolveWith(
          (states) => states.contains(WidgetState.selected)
              ? accentColor.withValues(alpha: .28)
              : borderColor,
        ),
      ),
    );
  }

  static ThemeData get lightTheme => lightThemeFor(_current.key);
}

InputDecoration _longFormInputDecoration({
  String? labelText,
  String? hintText,
  Widget? prefixIcon,
}) => InputDecoration(
  labelText: labelText,
  hintText: hintText,
  prefixIcon: prefixIcon,
  alignLabelWithHint: true,
  filled: true,
  fillColor: _quietMutedSurfaceColor(alpha: .34),
  contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
  border: OutlineInputBorder(
    borderRadius: BorderRadius.circular(16),
    borderSide: BorderSide(color: _quietBorderColor(alpha: .44)),
  ),
  enabledBorder: OutlineInputBorder(
    borderRadius: BorderRadius.circular(16),
    borderSide: BorderSide(color: _quietBorderColor(alpha: .44)),
  ),
  focusedBorder: OutlineInputBorder(
    borderRadius: BorderRadius.circular(16),
    borderSide: BorderSide(
      color: HeyBeanTheme.accent.withValues(alpha: .48),
      width: 1,
    ),
  ),
);

ButtonStyle _destructiveFilledButtonStyle({double radius = 14}) =>
    FilledButton.styleFrom(
      backgroundColor: HeyBeanTheme.destructive,
      foregroundColor: Colors.white,
      disabledBackgroundColor: HeyBeanTheme.destructive.withValues(alpha: .36),
      disabledForegroundColor: Colors.white70,
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(radius),
      ),
    );

ButtonStyle _destructiveIconButtonStyle() => IconButton.styleFrom(
  backgroundColor: HeyBeanTheme.destructive,
  foregroundColor: Colors.white,
  disabledBackgroundColor: HeyBeanTheme.destructive.withValues(alpha: .28),
  disabledForegroundColor: Colors.white70,
);

Future<bool> _confirmDestructiveAction(
  BuildContext context, {
  required String title,
  required String message,
  required String confirmLabel,
}) async {
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(title),
      content: Text(message),
      actions: [
        TextButton(
          key: const Key('destructive-cancel-action'),
          onPressed: () => Navigator.of(context).pop(false),
          child: Text('Cancel'),
        ),
        FilledButton.icon(
          key: const Key('destructive-confirm-action'),
          style: _destructiveFilledButtonStyle(),
          onPressed: () => Navigator.of(context).pop(true),
          icon: Icon(Icons.delete_outline_rounded),
          label: Text(confirmLabel),
        ),
      ],
    ),
  );
  return confirmed == true;
}

List<Object> _initialSyncWorkspaceIds({
  required List<int> linkedWorkspaceIds,
  required int? workspaceId,
  required String? activeWorkspaceId,
}) {
  final activeNumericId = activeWorkspaceId == null
      ? null
      : int.tryParse(activeWorkspaceId);
  final currentWorkspaceId = activeNumericId ?? workspaceId;

  return linkedWorkspaceIds
      .where((id) => id != currentWorkspaceId)
      .map<Object>((id) => id)
      .toList();
}

Object _workspaceValue(HermesWorkspace workspace) =>
    workspace.numericId ?? workspace.id;

bool _workspaceValuesMatch(Object? first, Object? second) {
  if (first == null || second == null) return first == second;
  return first.toString() == second.toString();
}

int? _workspaceValueToInt(Object? value) {
  if (value == null) return null;
  if (value is int) return value;
  return int.tryParse(value.toString());
}

Object? _workspaceValueForId(
  List<HermesWorkspace> workspaces,
  String? workspaceId,
) {
  if (workspaceId == null) return null;
  for (final workspace in workspaces) {
    if (workspace.id == workspaceId ||
        workspace.numericId?.toString() == workspaceId) {
      return _workspaceValue(workspace);
    }
  }
  return int.tryParse(workspaceId) ?? workspaceId;
}

Future<List<Object>?> _confirmWorkspaceDeleteSelection(
  BuildContext context, {
  required String itemTitle,
  required String itemType,
  required List<HermesWorkspace> workspaces,
  required String? activeWorkspaceId,
  required int? workspaceId,
  required List<int> linkedWorkspaceIds,
}) async {
  final linkedIds = <int>{
    if (workspaceId != null) workspaceId,
    ...linkedWorkspaceIds,
  };
  if (linkedIds.isEmpty && activeWorkspaceId != null) {
    final activeId = int.tryParse(activeWorkspaceId);
    if (activeId != null) linkedIds.add(activeId);
  }

  final workspaceById = {
    for (final workspace in workspaces)
      if (workspace.numericId != null) workspace.numericId!: workspace,
  };
  final choices =
      linkedIds
          .map(
            (id) =>
                workspaceById[id] ??
                HermesWorkspace(
                  id: id.toString(),
                  name: id == workspaceId
                      ? 'Current workspace'
                      : 'Workspace $id',
                ),
          )
          .toList()
        ..sort((a, b) {
          if (a.numericId == workspaceId) return -1;
          if (b.numericId == workspaceId) return 1;
          return a.name.toLowerCase().compareTo(b.name.toLowerCase());
        });

  if (choices.isEmpty) {
    final confirmed = await _confirmDestructiveAction(
      context,
      title: 'Delete $itemType?',
      message: 'This will remove "$itemTitle".',
      confirmLabel: 'Delete',
    );
    return confirmed ? const [] : null;
  }

  final selectedIds = choices
      .map((workspace) => workspace.numericId ?? workspace.id)
      .toSet();
  return showDialog<List<Object>>(
    context: context,
    builder: (context) => StatefulBuilder(
      builder: (context, setDialogState) {
        final canDelete = selectedIds.isNotEmpty;
        return AlertDialog(
          title: Text('Delete $itemType from'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  '"$itemTitle" is linked across workspaces. Choose where to remove it.',
                ),
                const SizedBox(height: 10),
                for (final workspace in choices)
                  CheckboxListTile(
                    key: Key('$itemType-delete-workspace-${workspace.id}'),
                    contentPadding: EdgeInsets.zero,
                    value: selectedIds.contains(
                      workspace.numericId ?? workspace.id,
                    ),
                    onChanged: (value) => setDialogState(() {
                      final id = workspace.numericId ?? workspace.id;
                      if (value ?? false) {
                        selectedIds.add(id);
                      } else {
                        selectedIds.remove(id);
                      }
                    }),
                    title: Text(
                      workspace.isPersonal ? 'Personal' : workspace.name,
                    ),
                    subtitle: workspace.numericId == workspaceId
                        ? Text('Current copy')
                        : null,
                  ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: Text('Cancel'),
            ),
            FilledButton(
              key: Key('$itemType-delete-selected-workspaces-action'),
              style: _destructiveFilledButtonStyle(),
              onPressed: canDelete
                  ? () => Navigator.of(context).pop(selectedIds.toList())
                  : null,
              child: Text('Delete $itemType'),
            ),
          ],
        );
      },
    ),
  );
}
