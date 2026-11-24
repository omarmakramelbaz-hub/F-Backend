<?php

namespace App\Http\Controllers;

use App\Support\MediaThumb;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Makes a photo's small copy on its first request (see MediaThumb). Later requests never reach Laravel: Apache finds
 * the file. Whenever the copy cannot be made, the app is sent to the full photo instead.
 */
class MediaThumbController extends Controller
{
    public function show(string $box, string $disk, string $id, string $name)
    {
        $box = (int) $box;
        abort_unless(in_array($box, MediaThumb::BOXES, true) && in_array($disk, MediaThumb::DISKS, true) && ctype_digit($id), 404);
        $media = Media::query()->where('id', (int) $id)->where('disk', $disk)->first();
        abort_unless($media && pathinfo((string) $media->file_name, PATHINFO_FILENAME) === $name && MediaThumb::url($media, $box) !== null, 404);
        $source = $media->getPath();
        abort_unless(is_file($source), 404);
        $target = MediaThumb::path($box, $disk, (int) $media->getKey(), $name);
        if (! is_file($target) && ! $this->make($source, $target, $box)) {
            return redirect()->away($media->model ? $media->model->getFirstMediaUrl($media->collection_name, 'thumb') : url('/'), 302);
        }
        return response()->file($target, ['Content-Type' => 'image/webp', 'Cache-Control' => 'public, max-age=2592000']);
    }

    private function make(string $source, string $target, int $box): bool
    {
        if (! function_exists('imagewebp')) return false;
        @ini_set('memory_limit', '512M');
        $bytes = @file_get_contents($source);
        $image = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
        if (! $image) return false;
        $image = $this->upright($image, $source);
        [$width, $height] = [imagesx($image), imagesy($image)];
        $scale = min(1, $box / max($width, $height));
        [$w, $h] = [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
        $small = imagecreatetruecolor($w, $h);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagecopyresampled($small, $image, 0, 0, 0, 0, $w, $h, $width, $height);
        imagedestroy($image);
        $directory = dirname($target);
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) return false;
        // Written aside and moved into place, so a request never finds half a file.
        $temporary = $directory.'/.'.bin2hex(random_bytes(8)).'.part';
        $written = @imagewebp($small, $temporary, 80);
        imagedestroy($small);
        if (! $written || ! @rename($temporary, $target)) {
            @unlink($temporary);
            return is_file($target);
        }
        @chmod($target, 0644);
        return true;
    }

    /** Phone photos keep their rotation in EXIF; the small copy is stored the right way up. */
    private function upright($image, string $source)
    {
        if (! function_exists('exif_read_data')) return $image;
        $exif = @exif_read_data($source);
        $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        if ($angle === 0) return $image;
        $rotated = imagerotate($image, $angle, 0);
        if (! $rotated) return $image;
        imagedestroy($image);
        return $rotated;
    }
}
