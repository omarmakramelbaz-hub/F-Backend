<?php

namespace App\Services\Dashboard;

use RuntimeException;

/** Keep the original layout resources on the desktop loopback origin. */
class DesktopDashboardAssets
{
    private static $manifest;

    public static function url(string $source): string
    {
        if (!config('desktop_dashboard.local')) {
            return $source;
        }
        if (str_starts_with($source, '//')) {
            $source = 'https:'.$source;
        }
        if (self::$manifest === null) {
            $file = public_path('dashboard/vendor/desktop-external/manifest.json');
            self::$manifest = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if ((self::$manifest['format'] ?? null) !== 1 || (self::$manifest['complete'] ?? null) !== true) {
                throw new RuntimeException('Unsupported desktop layout asset manifest.');
            }
        }
        $path = self::$manifest['assets'][$source]['path'] ?? null;
        if (!is_string($path) || !preg_match('#^[a-zA-Z0-9_./@-]+$#D', $path)
            || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path)) {
            throw new RuntimeException('The original layout resource is missing from the desktop bundle.');
        }

        return asset('dashboard/vendor/desktop-external/'.$path);
    }
}
