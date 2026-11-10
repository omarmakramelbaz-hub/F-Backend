<?php

namespace App\Support;

/**
 * The version of the dashboard's scripts: it changes whenever a dashboard script or the page frame changes. A window
 * left open (a branch device on the home page all night) compares it with the version it loaded and opens the page
 * again once, so it never keeps running old scripts after an update.
 */
final class DashboardShell
{
    private static ?string $version = null;

    public static function version(): string
    {
        if (self::$version !== null) return self::$version;
        $files = array_merge(
            glob(public_path('dashboard/js/*.js')) ?: [],
            [resource_path('views/admin/layouts/footer.blade.php'), resource_path('views/admin/layouts/header.blade.php')]
        );
        sort($files);
        $stamps = array_map(fn ($file) => basename($file).':'.(@filemtime($file) ?: 0), $files);
        return self::$version = substr(md5(implode('|', $stamps)), 0, 12);
    }
}
