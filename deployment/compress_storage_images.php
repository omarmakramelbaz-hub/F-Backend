<?php
/**
 * Shrinks oversized uploaded photos in place (same path, same URL, same format).
 *
 *   php deployment/compress_storage_images.php <dir> [--apply] [--backup=/root/img-backup] [--min-kb=200] [--max-side=1200]
 *
 * Without --apply it only reports what it would save. With --apply every file it rewrites is
 * first copied to the backup folder under the same relative path, and a file is replaced only
 * when the new one is smaller. PNG/WebP keep transparency; images with an EXIF rotation are
 * rotated upright first so they look the same.
 */

if (PHP_SAPI !== 'cli') exit(1);
ini_set('memory_limit', '1536M'); // a 6000px phone photo needs ~150 MB once decoded
if (!function_exists('imagecreatefromjpeg')) { fwrite(STDERR, "PHP GD is required\n"); exit(1); }

$args = array_slice($argv, 1);
$dir = null; $apply = false; $backup = '/root/img-backup-' . date('Ymd-His'); $minKb = 200; $maxSide = 1200;
foreach ($args as $arg) {
    if ($arg === '--apply') $apply = true;
    elseif (strpos($arg, '--backup=') === 0) $backup = rtrim(substr($arg, 9), '/');
    elseif (strpos($arg, '--min-kb=') === 0) $minKb = max(50, (int) substr($arg, 9));
    elseif (strpos($arg, '--max-side=') === 0) $maxSide = max(400, (int) substr($arg, 11));
    elseif ($arg[0] !== '-') $dir = rtrim(realpath($arg) ?: '', '/');
}
if (!$dir || !is_dir($dir)) { fwrite(STDERR, "Usage: php compress_storage_images.php <dir> [--apply]\n"); exit(1); }

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
$seen = $changed = 0; $before = $after = 0;
foreach ($files as $file) {
    $path = $file->getPathname();
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || $file->getSize() < $minKb * 1024) continue;
    $seen++;
    $info = @getimagesize($path);
    if (!$info) continue;
    $image = $ext === 'png' ? @imagecreatefrompng($path) : ($ext === 'webp' ? @imagecreatefromwebp($path) : @imagecreatefromjpeg($path));
    if (!$image) continue;

    // Upright first: the EXIF rotation is lost when the photo is saved again.
    if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle) { $rotated = imagerotate($image, $angle, 0); if ($rotated) { imagedestroy($image); $image = $rotated; } }
    }

    $w = imagesx($image); $h = imagesy($image);
    $scale = min(1, $maxSide / max($w, $h));
    if ($scale < 1) {
        $nw = (int) round($w * $scale); $nh = (int) round($h * $scale);
        $resized = imagecreatetruecolor($nw, $nh);
        if ($ext !== 'jpg' && $ext !== 'jpeg') { imagealphablending($resized, false); imagesavealpha($resized, true); }
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($image); $image = $resized;
    }

    $tmp = $path . '.shrink-tmp';
    $ok = $ext === 'png' ? imagepng($image, $tmp, 9) : ($ext === 'webp' ? imagewebp($image, $tmp, 80) : imagejpeg($image, $tmp, 80));
    imagedestroy($image);
    clearstatcache();
    $old = filesize($path); $new = $ok && is_file($tmp) ? filesize($tmp) : 0;
    if (!$new || $new >= $old * 0.9) { @unlink($tmp); continue; }

    $changed++; $before += $old; $after += $new;
    if ($apply) {
        $relative = substr($path, strlen($dir) + 1);
        $copy = $backup . '/' . $relative;
        if (!is_dir(dirname($copy))) mkdir(dirname($copy), 0700, true);
        if (!copy($path, $copy)) { @unlink($tmp); fwrite(STDERR, "backup failed: $path\n"); exit(1); }
        $perms = fileperms($path) & 0777; $owner = fileowner($path); $group = filegroup($path);
        rename($tmp, $path);
        chmod($path, $perms); @chown($path, $owner); @chgrp($path, $group);
    } else {
        @unlink($tmp);
    }
}

printf("%s: %d large photos checked, %d smaller, %.1f MB -> %.1f MB%s\n", $apply ? 'APPLIED' : 'DRY RUN', $seen, $changed,
    $before / 1048576, $after / 1048576, $apply ? " (originals in $backup)" : '');
