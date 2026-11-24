<?php

namespace App\Support;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Small WebP copies of product and branch photos for the app's lists and cards, where the full photo (often 0.5-2 MB)
 * was downloaded only to be shown a few centimetres wide. A copy is made on its first request
 * (MediaThumbController), kept under storage/app/public/thumbs, and served by Apache as a plain file after that.
 * The media rows and their files are never touched, so uploads, menu copies and the desktop program see them as before.
 */
final class MediaThumb
{
    /** Longest side, in pixels, per collection: cards and logos stay sharp on 3x phone screens. */
    public const BOXES = ['product_image' => 600, 'logo' => 400, 'bg_image' => 1000];

    /** Longest side of the copy shown full width on a product's own page: sharp on 3x phones, far lighter than the original. */
    public const DETAIL_BOX = 1200;

    /** Only these disks hold photos the app lists. */
    public const DISKS = ['products', 'resturants'];

    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** The small copy's address for the model's first photo in the collection, or the photo's own address otherwise. */
    public static function first(?HasMedia $model, string $collection): ?string
    {
        if (! $model) return null;
        $media = $model->getFirstMedia($collection);
        $url = $media ? self::url($media, self::BOXES[$collection] ?? 0) : null;
        return $url ?? $model->getFirstMediaUrl($collection, 'thumb');
    }

    /** The large copy's address for a product page, or the photo's own address otherwise. */
    public static function detail(?HasMedia $model, string $collection): ?string
    {
        if (! $model) return null;
        $media = $model->getFirstMedia($collection);
        $url = $media ? self::url($media, self::DETAIL_BOX) : null;
        return $url ?? $model->getFirstMediaUrl($collection, 'thumb');
    }

    /** Every box a copy may be made at. */
    public static function sizes(): array
    {
        return array_values(array_unique([...array_values(self::BOXES), self::DETAIL_BOX]));
    }

    public static function url(Media $media, int $box): ?string
    {
        if (! in_array($box, self::sizes(), true) || ! in_array($media->disk, self::DISKS, true)) return null;
        $name = (string) $media->file_name;
        if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) return null;
        $base = pathinfo($name, PATHINFO_FILENAME);
        if ($base === '' || str_contains($base, '/') || str_contains($base, '\\')) return null;
        return url('/storage/thumbs/'.$box.'/'.$media->disk.'/'.$media->getKey().'/'.rawurlencode($base).'.webp');
    }

    /** Where the small copy of this photo is kept, served as /storage/thumbs/... once it exists. */
    public static function path(int $box, string $disk, int $id, string $base): string
    {
        return storage_path('app/public/thumbs/'.$box.'/'.$disk.'/'.$id.'/'.$base.'.webp');
    }
}
