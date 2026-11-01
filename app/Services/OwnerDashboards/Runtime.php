<?php
namespace App\Services\OwnerDashboards;

/** Set by the trusted tenant entry point, never by an HTTP parameter or a user role. */
final class Runtime
{
    public static function tenant(): bool { return app()->bound('owner.dashboard.runtime'); }
    public static function facts(): array { return self::tenant() ? app('owner.dashboard.runtime') : []; }
    public static function prefix(): string { return self::facts()['prefix'] ?? ''; }
    public static function control(): string { return '/home/fasakha/go-owner-dashboards/control'; }
    public static function blocked(string $id): bool
    {
        $file=self::control().'/deny/'.$id;clearstatcache(true,$file);
        return is_link($file)||!is_file($file)||@file_get_contents($file)!=='';
    }
    public static function primary(?object $actor): bool
    {
        return !self::tenant() && !config('desktop_dashboard.local') && $actor
            && (int)$actor->id === 1 && $actor->account_type === 'admin' && $actor->status === 'accepted';
    }
}
