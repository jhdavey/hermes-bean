#!/usr/bin/env /usr/bin/python3
"""Generate HeyBean app launcher icons from the Bean logo.

Design: clean white background with the Bean logo rendered in black, optimized
for iOS/Android launcher readability.
"""
from __future__ import annotations

import json
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
REPO_ROOT = ROOT.parent
SOURCE = ROOT / "assets/images/bean/bean-logo.png"
FOREGROUND = (0x12, 0x12, 0x12, 255)
BACKGROUND = (255, 255, 255, 255)
MANIFEST_THEME = "#FFFFFF"


def make_icon(size: int, logo_width_ratio: float = 0.68) -> Image.Image:
    src = Image.open(SOURCE).convert("RGBA")
    alpha = src.getchannel("A")
    bbox = alpha.getbbox()
    if bbox is None:
        raise RuntimeError(f"{SOURCE} has no visible pixels")

    logo = src.crop(bbox)
    logo_alpha = logo.getchannel("A")
    black_logo = Image.new("RGBA", logo.size, FOREGROUND)
    black_logo.putalpha(logo_alpha)

    target_w = int(size * logo_width_ratio)
    target_h = int(target_w * black_logo.height / black_logo.width)
    # Keep tall variants within a safe zone so iOS rounded corners do not crowd it.
    max_h = int(size * 0.58)
    if target_h > max_h:
        target_h = max_h
        target_w = int(target_h * black_logo.width / black_logo.height)

    black_logo = black_logo.resize((target_w, target_h), Image.Resampling.LANCZOS)
    icon = Image.new("RGBA", (size, size), BACKGROUND)
    x = (size - target_w) // 2
    y = (size - target_h) // 2
    icon.alpha_composite(black_logo, (x, y))
    return icon


def save_png(path: Path, size: int, *, ratio: float = 0.68, rgb: bool = False) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    icon = make_icon(size, ratio)
    if rgb:
        icon = icon.convert("RGB")
    icon.save(path, "PNG", optimize=True)


def parse_size(size: str, scale: str) -> int:
    base = float(size.split("x", 1)[0])
    multiplier = int(scale.rstrip("x"))
    return int(round(base * multiplier))


def generate_ios() -> None:
    appicon = ROOT / "ios/Runner/Assets.xcassets/AppIcon.appiconset"
    data = json.loads((appicon / "Contents.json").read_text())
    for image in data["images"]:
        filename = image.get("filename")
        if not filename:
            continue
        size = parse_size(image["size"], image["scale"])
        # iOS marketing icon must be RGB/no alpha.
        save_png(appicon / filename, size, rgb=True)


def generate_macos() -> None:
    appicon = ROOT / "macos/Runner/Assets.xcassets/AppIcon.appiconset"
    if not appicon.exists():
        return
    data = json.loads((appicon / "Contents.json").read_text())
    for image in data["images"]:
        filename = image.get("filename")
        if not filename:
            continue
        size = parse_size(image["size"], image["scale"])
        save_png(appicon / filename, size, rgb=True)


def generate_android() -> None:
    sizes = {
        "mipmap-mdpi": 48,
        "mipmap-hdpi": 72,
        "mipmap-xhdpi": 96,
        "mipmap-xxhdpi": 144,
        "mipmap-xxxhdpi": 192,
    }
    res = ROOT / "android/app/src/main/res"
    for folder, size in sizes.items():
        save_png(res / folder / "ic_launcher.png", size, rgb=True)


def generate_web() -> None:
    save_png(ROOT / "web/favicon.png", 32, rgb=True)
    save_png(ROOT / "web/icons/Icon-192.png", 192, rgb=True)
    save_png(ROOT / "web/icons/Icon-512.png", 512, rgb=True)
    # Maskable icons need extra safe-zone padding.
    save_png(ROOT / "web/icons/Icon-maskable-192.png", 192, ratio=0.58, rgb=True)
    save_png(ROOT / "web/icons/Icon-maskable-512.png", 512, ratio=0.58, rgb=True)


def save_ico(path: Path, sizes: list[int]) -> None:
    icons = [make_icon(size).convert("RGBA") for size in sizes]
    path.parent.mkdir(parents=True, exist_ok=True)
    icons[-1].save(
        path,
        format="ICO",
        sizes=[(size, size) for size in sizes],
        append_images=icons[:-1],
    )


def generate_windows() -> None:
    target = ROOT / "windows/runner/resources/app_icon.ico"
    if not target.exists():
        return
    save_ico(target, [16, 32, 48, 64, 128, 256])


def generate_laravel_web() -> None:
    public = REPO_ROOT / "web/public"
    if not public.exists():
        return
    save_png(public / "favicon.png", 32, rgb=True)
    save_png(public / "apple-touch-icon.png", 180, rgb=True)
    save_png(public / "android-chrome-192x192.png", 192, rgb=True)
    save_png(public / "android-chrome-512x512.png", 512, rgb=True)
    save_ico(public / "favicon.ico", [16, 32, 48, 256])

    manifest = public / "site-manifest.json"
    if manifest.exists():
        data = json.loads(manifest.read_text())
        data["background_color"] = MANIFEST_THEME
        data["theme_color"] = MANIFEST_THEME
        manifest.write_text(json.dumps(data, indent=2) + "\n")


def update_web_manifest() -> None:
    manifest = ROOT / "web/manifest.json"
    if not manifest.exists():
        return
    data = json.loads(manifest.read_text())
    data["background_color"] = MANIFEST_THEME
    data["theme_color"] = MANIFEST_THEME
    manifest.write_text(json.dumps(data, indent=4) + "\n")


def main() -> None:
    if not SOURCE.exists():
        raise FileNotFoundError(SOURCE)
    # Canonical generated preview/source for future regeneration.
    save_png(ROOT / "assets/images/bean/app-icon-white-black.png", 1024, rgb=True)
    generate_ios()
    generate_android()
    generate_web()
    generate_macos()
    generate_windows()
    generate_laravel_web()
    update_web_manifest()


if __name__ == "__main__":
    main()
