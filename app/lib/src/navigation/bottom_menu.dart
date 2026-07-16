part of '../../main.dart';

class _SignedInBottomDock extends StatelessWidget {
  const _SignedInBottomDock({required this.menu});

  final Widget menu;

  @override
  Widget build(BuildContext context) =>
      SizedBox(key: const Key('signed-in-bottom-dock'), child: menu);
}

class _HeyBeanBottomMenu extends StatelessWidget {
  const _HeyBeanBottomMenu({
    required this.selected,
    required this.onSelected,
    required this.onMorePressed,
  });

  final _HomeDestination selected;
  final ValueChanged<_HomeDestination> onSelected;
  final VoidCallback onMorePressed;

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.paddingOf(context).bottom;
    final dockBottomPadding = bottomInset > 0 ? bottomInset + 2 : 6.0;

    return SizedBox(
      key: const Key('heybean-bottom-menu'),
      height: 74 + dockBottomPadding,
      child: Stack(
        alignment: Alignment.topCenter,
        clipBehavior: Clip.none,
        children: [
          Positioned.fill(
            top: _beanBottomMenuSurfaceInset,
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: HeyBeanTheme.surface.withValues(alpha: .97),
                border: Border(
                  top: BorderSide(color: _quietBorderColor(alpha: .42)),
                ),
                boxShadow: const [
                  BoxShadow(
                    color: Color(0x10020617),
                    blurRadius: 12,
                    offset: Offset(0, -2),
                  ),
                ],
              ),
              child: Padding(
                padding: EdgeInsets.fromLTRB(10, 7, 10, dockBottomPadding),
                child: Row(
                  children: [
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-tasks'),
                        icon: Icons.task_alt_rounded,
                        label: 'Tasks',
                        selected: selected == _HomeDestination.tasks,
                        onPressed: () => onSelected(_HomeDestination.tasks),
                      ),
                    ),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-reminders'),
                        icon: Icons.notifications_active_rounded,
                        label: 'Reminders',
                        selected: selected == _HomeDestination.reminders,
                        onPressed: () => onSelected(_HomeDestination.reminders),
                      ),
                    ),
                    const SizedBox(width: 86),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-notes'),
                        iconWidget: _BeanNotesIcon(
                          size: 24,
                          color: selected == _HomeDestination.notes
                              ? HeyBeanTheme.accentStrong
                              : HeyBeanTheme.muted,
                        ),
                        label: 'Notes',
                        selected: selected == _HomeDestination.notes,
                        onPressed: () => onSelected(_HomeDestination.notes),
                      ),
                    ),
                    Expanded(
                      child: _MenuIconButton(
                        key: const Key('nav-more'),
                        icon: Icons.more_horiz_rounded,
                        label: 'More',
                        selected: selected == _HomeDestination.settings,
                        onPressed: onMorePressed,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Positioned(
            top: 12,
            child: _CommandCenterFab(
              selected: selected == _HomeDestination.commandCenter,
              onPressed: () => onSelected(_HomeDestination.commandCenter),
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
    this.icon,
    this.iconWidget,
    required this.label,
    required this.onPressed,
    this.selected = false,
  });

  final IconData? icon;
  final Widget? iconWidget;
  final String label;
  final VoidCallback onPressed;
  final bool selected;

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.transparent,
    child: InkWell(
      borderRadius: BorderRadius.circular(999),
      onTap: onPressed,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 1),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          mainAxisSize: MainAxisSize.min,
          children: [
            iconWidget ??
                Icon(
                  icon,
                  color: selected ? HeyBeanTheme.text : HeyBeanTheme.muted,
                  size: 22,
                ),
            const SizedBox(height: 3),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: selected ? HeyBeanTheme.text : HeyBeanTheme.muted,
                fontSize: 10,
                fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _CommandCenterFab extends StatelessWidget {
  const _CommandCenterFab({required this.selected, required this.onPressed});

  final bool selected;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final activeColor = HeyBeanTheme.accentStrong;
    return GestureDetector(
      key: const Key('nav-command-center'),
      behavior: HitTestBehavior.opaque,
      onTap: onPressed,
      child: SizedBox(
        width: 88,
        height: 88,
        child: Center(
          child: Material(
            color: Colors.transparent,
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 180),
              key: const Key('heybean-center-command-center-button'),
              width: 64,
              height: 64,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: HeyBeanTheme.surface,
                border: Border.all(
                  color: selected ? activeColor : _quietBorderColor(alpha: .54),
                  width: 1.6,
                ),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: .08),
                    blurRadius: 14,
                    offset: const Offset(0, 6),
                  ),
                ],
              ),
              child: Icon(
                Icons.dashboard_customize_rounded,
                size: 30,
                color: selected ? activeColor : HeyBeanTheme.muted,
                semanticLabel: 'Command Center',
              ),
            ),
          ),
        ),
      ),
    );
  }
}
