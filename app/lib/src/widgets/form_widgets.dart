part of '../../main.dart';

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({
    required this.icon,
    required this.title,
    required this.subtitle,
    this.infoKey,
    this.infoTitle,
    this.infoBullets = const [],
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final Key? infoKey;
  final String? infoTitle;
  final List<String> infoBullets;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Icon(icon, color: HeyBeanTheme.muted, size: 18),
      const SizedBox(width: 8),
      Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title,
              style: Theme.of(
                context,
              ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
            ),
            if (subtitle.isNotEmpty)
              Text(
                subtitle,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: HeyBeanTheme.muted,
                  fontWeight: FontWeight.w500,
                ),
              ),
          ],
        ),
      ),
      if (infoTitle != null && infoBullets.isNotEmpty) ...[
        const SizedBox(width: 8),
        _InfoIconButton(key: infoKey, title: infoTitle!, bullets: infoBullets),
      ],
    ],
  );
}

class _FormEditorHeader extends StatelessWidget {
  const _FormEditorHeader({
    required this.icon,
    required this.title,
    required this.subtitle,
    this.titleKey,
    this.trailing,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final Key? titleKey;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.only(bottom: 12),
    decoration: BoxDecoration(
      border: Border(bottom: BorderSide(color: _quietBorderColor(alpha: .34))),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 30,
          height: 30,
          decoration: BoxDecoration(
            color: _quietMutedSurfaceColor(alpha: .38),
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: _quietBorderColor(alpha: .30)),
          ),
          child: Icon(icon, color: HeyBeanTheme.muted, size: 17),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                key: titleKey,
                style: Theme.of(context).textTheme.titleLarge?.copyWith(
                  color: HeyBeanTheme.text,
                  fontWeight: FontWeight.w700,
                  height: 1.1,
                ),
              ),
              if (subtitle.isNotEmpty) ...[
                const SizedBox(height: 4),
                Text(
                  subtitle,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: HeyBeanTheme.muted,
                    height: 1.35,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ],
            ],
          ),
        ),
        if (trailing != null) ...[const SizedBox(width: 12), trailing!],
      ],
    ),
  );
}

class _MobileFormSection extends StatelessWidget {
  const _MobileFormSection({
    super.key,
    required this.title,
    required this.children,
    this.subtitle,
    this.icon,
    this.iconWidget,
    this.infoKey,
    this.infoTitle,
    this.infoBullets = const [],
    this.primary = false,
  });

  final String title;
  final String? subtitle;
  final IconData? icon;
  final Widget? iconWidget;
  final Key? infoKey;
  final String? infoTitle;
  final List<String> infoBullets;
  final List<Widget> children;
  final bool primary;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(vertical: 12),
    decoration: BoxDecoration(
      border: Border(
        bottom: BorderSide(
          color: _quietBorderColor(alpha: primary ? .44 : .30),
        ),
      ),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (iconWidget != null || icon != null) ...[
              iconWidget ?? Icon(icon, size: 17, color: HeyBeanTheme.muted),
              const SizedBox(width: 8),
            ],
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: TextStyle(
                      color: HeyBeanTheme.text,
                      fontSize: 13,
                      height: 1.2,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  if (subtitle != null && subtitle!.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      subtitle!,
                      style: TextStyle(
                        color: HeyBeanTheme.muted,
                        fontSize: 12,
                        height: 1.35,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            if (infoTitle != null && infoBullets.isNotEmpty) ...[
              const SizedBox(width: 8),
              _InfoIconButton(
                key: infoKey,
                title: infoTitle!,
                bullets: infoBullets,
              ),
            ],
          ],
        ),
        if (children.isNotEmpty) ...[
          const SizedBox(height: 10),
          for (var index = 0; index < children.length; index++) ...[
            if (index > 0) const SizedBox(height: 10),
            children[index],
          ],
        ],
      ],
    ),
  );
}

class _MobileFormSwitch extends StatelessWidget {
  const _MobileFormSwitch({
    required this.value,
    required this.onChanged,
    required this.title,
    required this.subtitle,
    this.icon,
    this.widgetKey,
  });

  final bool value;
  final ValueChanged<bool>? onChanged;
  final String title;
  final String subtitle;
  final IconData? icon;
  final Key? widgetKey;

  @override
  Widget build(BuildContext context) => Container(
    key: widgetKey,
    decoration: BoxDecoration(
      borderRadius: BorderRadius.circular(12),
      border: Border.all(color: _quietBorderColor(alpha: .34)),
    ),
    child: SwitchListTile(
      value: value,
      onChanged: onChanged,
      contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      dense: true,
      title: Text(
        title,
        style: TextStyle(
          color: HeyBeanTheme.text,
          fontSize: 13,
          fontWeight: FontWeight.w600,
        ),
      ),
      subtitle: Text(
        subtitle,
        style: TextStyle(
          color: HeyBeanTheme.muted,
          fontSize: 12,
          height: 1.35,
          fontWeight: FontWeight.w500,
        ),
      ),
      secondary: icon == null
          ? null
          : Icon(icon, color: HeyBeanTheme.muted, size: 20),
    ),
  );
}

class _InfoIconButton extends StatelessWidget {
  const _InfoIconButton({
    super.key,
    required this.title,
    required this.bullets,
  });

  final String title;
  final List<String> bullets;

  @override
  Widget build(BuildContext context) => IconButton(
    tooltip: 'More info about $title',
    visualDensity: VisualDensity.compact,
    icon: Icon(
      Icons.info_outline_rounded,
      semanticLabel: 'More info',
      size: 18,
      color: HeyBeanTheme.muted,
    ),
    onPressed: () => _showInfoSheet(context, title: title, bullets: bullets),
  );
}

Future<void> _showInfoSheet(
  BuildContext context, {
  required String title,
  required List<String> bullets,
}) => showModalBottomSheet<void>(
  context: context,
  isScrollControlled: true,
  backgroundColor: Colors.transparent,
  builder: (context) => SafeArea(
    top: false,
    child: Container(
      key: Key(
        'info-sheet-${title.toLowerCase().replaceAll(RegExp(r'[^a-z0-9]+'), '-').replaceAll(RegExp(r'^-+|-+$'), '')}',
      ),
      margin: const EdgeInsets.all(12),
      padding: const EdgeInsets.fromLTRB(18, 12, 18, 18),
      decoration: BoxDecoration(
        color: HeyBeanTheme.surface,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: _quietBorderColor(alpha: .46)),
        boxShadow: const [
          BoxShadow(
            color: Color(0x16000000),
            blurRadius: 22,
            offset: Offset(0, 10),
          ),
        ],
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Center(
              child: Container(
                width: 44,
                height: 5,
                decoration: BoxDecoration(
                  color: HeyBeanTheme.border,
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Text(
              title,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                color: HeyBeanTheme.text,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 12),
            for (final bullet in bullets)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.only(top: 5),
                      child: Icon(
                        Icons.circle,
                        size: 6,
                        color: HeyBeanTheme.muted,
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        bullet,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: HeyBeanTheme.text,
                          height: 1.35,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            const SizedBox(height: 4),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: () => Navigator.of(context).pop(),
                child: Text('Got it'),
              ),
            ),
          ],
        ),
      ),
    ),
  ),
);

class _ShellCard extends StatelessWidget {
  const _ShellCard({required this.child, this.glow = false});

  final Widget child;
  final bool glow;

  @override
  Widget build(BuildContext context) => Container(
    padding: EdgeInsets.only(top: glow ? 12 : 4, bottom: 4),
    decoration: glow ? _sectionDividerDecoration(alpha: .38) : null,
    child: child,
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
        Text(label, style: TextStyle(fontWeight: FontWeight.w800)),
        Text(
          value,
          maxLines: 3,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(color: HeyBeanTheme.muted),
        ),
      ],
    ),
  );
}
