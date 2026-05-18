#!/usr/bin/env /usr/bin/python3
"""Generate HeyBean app launcher icons from the Bean logo.

Design: professional emerald gradient background with the Bean logo rendered in
white, optimized for iOS/Android launcher readability.
"""
from __future__ import annotations

import json
from pathlib import Path

from PIL import Image, ImageFilter

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "assets/images/bean/bean-logo.png"
# Professional green gradient: bright top-left highlight into deeper emerald.
GRADIENT_TOP_LEFT = (0x18, 0xB8, 0x68, 255)
GRADIENT_BOTTOM_RIGHT = (0x05, 0x72, 0x3D, 255)
HIGHLIGHT = (0x46, 0xE0, 0x94, 255)
FOREGROUND = (255, 255, 255, 255)
SHADOW = (0, 0, 0, 95)
MANIFEST_THEME = "#0B8F4D"


def _lerp(a: int, b: int, t: float) -> int:
    return int(round(a + (b - a) * t))


def make_background(size: int) -> Image.Image:
    """Create a subtle diagonal/radial emerald gradient."""
    image = Image.new("RGBA", (size, size))
    pixels = image.load()
    cx = size * 0.28
    cy = size * 0.22
    max_dist = (cx * cx + cy * cy) ** 0.5

    for y in range(size):
        for x in range(size):
            diagonal = (x + y) / max(1, (size - 1) * 2)
            base = tuple(
                _lerp(GRADIENT_TOP_LEFT[i], GRADIENT_BOTTOM_RIGHT[i], diagonal)
                for i in range(4)
            )

            # Soft top-left glow so the icon has depth without looking glossy.
            dist = ((x - cx) ** 2 + (y - cy) ** 2) ** 0.5
            glow = max(0.0, 1.0 - dist / max_dist) ** 2 * 0.26
            color = tuple(_lerp(base[i], HIGHLIGHT[i], glow) for i in range(4))
            pixels[x, y] = color

    return image


def make_icon(size: int, logo_width_ratio: float = 0.68) -> Image.Image:
    src = Image.open(SOURCE).convert("RGBA")
    alpha = src.getchannel("A")
    bbox = alpha.getbbox()
    if bbox is None:
        raise RuntimeError(f"{SOURCE} has no visible pixels")

    logo = src.crop(bbox)
    logo_alpha = logo.getchannel("A")
    white_logo = Image.new("RGBA", logo.size, FOREGROUND)
    white_logo.putalpha(logo_alpha)

    target_w = int(size * logo_width_ratio)
    target_h = int(target_w * white_logo.height / white_logo.width)
    # Keep tall variants within a safe zone so iOS rounded corners do not crowd it.
    max_h = int(size * 0.58)
    if target_h > max_h:
        target_h = max_h
        target_w = int(target_h * white_logo.width / white_logo.height)

    white_logo = white_logo.resize((target_w, target_h), Image.Resampling.LANCZOS)
    icon = make_background(size)
    x = (size - target_w) // 2
    y = (size - target_h) // 2

    # Tiny shadow improves contrast at phone-launcher sizes while staying clean.
    shadow_alpha = white_logo.getchannel("A").filter(ImageFilter.GaussianBlur(max(1, size // 96)))
    shadow = Image.new("RGBA", white_logo.size, SHADOW)
    shadow.putalpha(shadow_alpha.point(lambda value: int(value * 0.42)))
    offset = max(1, size // 80)
    icon.alpha_composite(shadow, (x, y + offset))
    icon.alpha_composite(white_logo, (x, y))
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


def generate_windows() -> None:
    target = ROOT / "windows/runner/resources/app_icon.ico"
    if not target.exists():
        return
    sizes = [16, 32, 48, 64, 128, 256]
    icons = [make_icon(size).convert("RGBA") for size in sizes]
    target.parent.mkdir(parents=True, exist_ok=True)
    icons[-1].save(target, format="ICO", sizes=[(s, s) for s in sizes], append_images=icons[:-1])


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
    save_png(ROOT / "assets/images/bean/app-icon-green-gradient-white.png", 1024, rgb=True)
    generate_ios()
    generate_android()
    generate_web()
    generate_macos()
    generate_windows()
    update_web_manifest()


if __name__ == "__main__":
    main()
