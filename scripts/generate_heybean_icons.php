<?php

$root = dirname(__DIR__);
$sourcePath = $root.'/web/public/images/bean-logo.png';

if (! extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension is required.\n");
    exit(1);
}

$source = imagecreatefrompng($sourcePath);
if (! $source) {
    fwrite(STDERR, "Could not read {$sourcePath}\n");
    exit(1);
}

imagesavealpha($source, true);
$srcW = imagesx($source);
$srcH = imagesy($source);

// Remove only the edge-connected white background, preserving white face details
// enclosed by the logo outline.
$transparent = imagecolorallocatealpha($source, 255, 255, 255, 127);
$visited = str_repeat("\0", $srcW * $srcH);
$queue = new SplQueue();
$enqueue = function (int $x, int $y) use (&$queue, &$visited, $srcW, $srcH): void {
    if ($x < 0 || $y < 0 || $x >= $srcW || $y >= $srcH) {
        return;
    }
    $index = ($y * $srcW) + $x;
    if ($visited[$index] === "\1") {
        return;
    }
    $visited[$index] = "\1";
    $queue->enqueue([$x, $y]);
};
$isEdgeWhite = function (int $rgba): bool {
    $a = ($rgba >> 24) & 0x7F;
    $r = ($rgba >> 16) & 0xFF;
    $g = ($rgba >> 8) & 0xFF;
    $b = $rgba & 0xFF;

    return $a >= 120 || ($r >= 248 && $g >= 248 && $b >= 248);
};

for ($x = 0; $x < $srcW; $x++) {
    if ($isEdgeWhite(imagecolorat($source, $x, 0))) $enqueue($x, 0);
    if ($isEdgeWhite(imagecolorat($source, $x, $srcH - 1))) $enqueue($x, $srcH - 1);
}
for ($y = 0; $y < $srcH; $y++) {
    if ($isEdgeWhite(imagecolorat($source, 0, $y))) $enqueue(0, $y);
    if ($isEdgeWhite(imagecolorat($source, $srcW - 1, $y))) $enqueue($srcW - 1, $y);
}

while (! $queue->isEmpty()) {
    [$x, $y] = $queue->dequeue();
    if (! $isEdgeWhite(imagecolorat($source, $x, $y))) {
        continue;
    }
    imagesetpixel($source, $x, $y, $transparent);
    $enqueue($x + 1, $y);
    $enqueue($x - 1, $y);
    $enqueue($x, $y + 1);
    $enqueue($x, $y - 1);
}

$minX = $srcW; $minY = $srcH; $maxX = 0; $maxY = 0;
for ($y = 0; $y < $srcH; $y++) {
    for ($x = 0; $x < $srcW; $x++) {
        $rgba = imagecolorat($source, $x, $y);
        $a = ($rgba >> 24) & 0x7F;
        if ($a < 120) {
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }
    }
}

if ($minX >= $srcW || $minY >= $srcH) {
    $minX = 0; $minY = 0; $maxX = $srcW - 1; $maxY = $srcH - 1;
}

function ensure_dir(string $path): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function write_icon_png($source, int $minX, int $minY, int $maxX, int $maxY, int $size, string $path, float $safeArea = 0.78): void
{
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, true);
    imagesavealpha($canvas, false);

    // Clean white background, no alpha (App Store-safe).
    $bg = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $bg);

    $srcW = $maxX - $minX + 1;
    $srcH = $maxY - $minY + 1;
    $scale = min(($size * $safeArea) / $srcW, ($size * $safeArea) / $srcH);
    $dstW = (int) round($srcW * $scale);
    $dstH = (int) round($srcH * $scale);
    $dstX = (int) round(($size - $dstW) / 2);
    $dstY = (int) round(($size - $dstH) / 2);

    imagecopyresampled($canvas, $source, $dstX, $dstY, $minX, $minY, $dstW, $dstH, $srcW, $srcH);

    ensure_dir($path);
    imagepng($canvas, $path, 9);
    imagedestroy($canvas);
}

function write_ico_from_pngs(array $pngPaths, string $icoPath): void
{
    $entries = [];
    $data = '';
    $offset = 6 + (16 * count($pngPaths));
    foreach ($pngPaths as $pngPath) {
        $png = file_get_contents($pngPath);
        $info = getimagesize($pngPath);
        $w = $info[0];
        $h = $info[1];
        $entries[] = pack('CCCCvvVV', $w >= 256 ? 0 : $w, $h >= 256 ? 0 : $h, 0, 0, 1, 32, strlen($png), $offset);
        $data .= $png;
        $offset += strlen($png);
    }
    ensure_dir($icoPath);
    file_put_contents($icoPath, pack('vvv', 0, 1, count($pngPaths)).implode('', $entries).$data);
}

$ios = [
    'Icon-App-20x20@1x.png' => 20,
    'Icon-App-20x20@2x.png' => 40,
    'Icon-App-20x20@3x.png' => 60,
    'Icon-App-29x29@1x.png' => 29,
    'Icon-App-29x29@2x.png' => 58,
    'Icon-App-29x29@3x.png' => 87,
    'Icon-App-40x40@1x.png' => 40,
    'Icon-App-40x40@2x.png' => 80,
    'Icon-App-40x40@3x.png' => 120,
    'Icon-App-60x60@2x.png' => 120,
    'Icon-App-60x60@3x.png' => 180,
    'Icon-App-76x76@1x.png' => 76,
    'Icon-App-76x76@2x.png' => 152,
    'Icon-App-83.5x83.5@2x.png' => 167,
    'Icon-App-1024x1024@1x.png' => 1024,
];
foreach ($ios as $file => $size) {
    write_icon_png($source, $minX, $minY, $maxX, $maxY, $size, $root.'/app/ios/Runner/Assets.xcassets/AppIcon.appiconset/'.$file);
}

$android = [
    'mipmap-mdpi/ic_launcher.png' => 48,
    'mipmap-hdpi/ic_launcher.png' => 72,
    'mipmap-xhdpi/ic_launcher.png' => 96,
    'mipmap-xxhdpi/ic_launcher.png' => 144,
    'mipmap-xxxhdpi/ic_launcher.png' => 192,
];
foreach ($android as $file => $size) {
    write_icon_png($source, $minX, $minY, $maxX, $maxY, $size, $root.'/app/android/app/src/main/res/'.$file);
}

$mac = [16, 32, 64, 128, 256, 512, 1024];
foreach ($mac as $size) {
    write_icon_png($source, $minX, $minY, $maxX, $maxY, $size, $root.'/app/macos/Runner/Assets.xcassets/AppIcon.appiconset/app_icon_'.$size.'.png');
}

$webIcons = [
    'app/web/favicon.png' => 32,
    'app/web/icons/Icon-192.png' => 192,
    'app/web/icons/Icon-512.png' => 512,
    'app/web/icons/Icon-maskable-192.png' => 192,
    'app/web/icons/Icon-maskable-512.png' => 512,
    'web/public/favicon.png' => 32,
    'web/public/apple-touch-icon.png' => 180,
    'web/public/android-chrome-192x192.png' => 192,
    'web/public/android-chrome-512x512.png' => 512,
];
foreach ($webIcons as $file => $size) {
    write_icon_png($source, $minX, $minY, $maxX, $maxY, $size, $root.'/'.$file, str_contains($file, 'maskable') ? 0.62 : 0.78);
}

$tmpDir = sys_get_temp_dir().'/heybean-icons-'.bin2hex(random_bytes(4));
mkdir($tmpDir);
$icoSizes = [16, 32, 48, 256];
$icoPngs = [];
foreach ($icoSizes as $size) {
    $path = $tmpDir.'/icon-'.$size.'.png';
    write_icon_png($source, $minX, $minY, $maxX, $maxY, $size, $path);
    $icoPngs[] = $path;
}
write_ico_from_pngs($icoPngs, $root.'/web/public/favicon.ico');
write_ico_from_pngs($icoPngs, $root.'/app/windows/runner/resources/app_icon.ico');

array_map('unlink', $icoPngs);
rmdir($tmpDir);

imagedestroy($source);

echo "Generated HeyBean icon set from {$sourcePath}\n";
