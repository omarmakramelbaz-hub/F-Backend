<?php

return [

    /*
     * Each settings class used in your application must be registered, you can
     * put them (manually) here.
     */
    'settings' => [
        \App\Models\GeneralSettings::class

    ],

    /*
     * In these directories settings migrations will be stored and ran when migrating. A settings
     * migration created via the make:settings-migration command will be stored in the first path or
     * a custom defined path when running the command.
     */
    'migrations_paths' => [
        database_path('settings'),
    ],

    /*
     * When no repository was set for a settings class the following repository
     * will be used for loading and saving settings.
     */
    'default_repository' => 'database',

    /*
     * Settings will be stored and loaded from these repositories.
     */
    'repositories' => [
        'database' => [
            // Only the isolated bundled graph defines this compatibility class.
            'type' => class_exists('FasakhanstaDesktopLegacySettingsRepository')
                ? FasakhanstaDesktopLegacySettingsRepository::class
                : Spatie\LaravelSettings\SettingsRepositories\DatabaseSettingsRepository::class,
            'model' => null,
            'table' => null,
            'connection' => null,
        ],
        'redis' => [
            'type' => Spatie\LaravelSettings\SettingsRepositories\RedisSettingsRepository::class,
            'connection' => null,
            'prefix' => null,
        ],
    ],

    /*
     * The contents of settings classes can be cached through your application,
     * settings will be stored within a provided Laravel store and can have an
     * additional prefix.
     */
    'cache' => [
        'enabled' => env('SETTINGS_CACHE_ENABLED', false),
        'store' => null,
        'prefix' => null,
        'ttl' => null,
    ],

    /*
     * These global casts will be automatically used whenever a property within
     * your settings class isn't a default PHP type.
     */
    'global_casts' => [
        DateTimeInterface::class => Spatie\LaravelSettings\SettingsCasts\DateTimeInterfaceCast::class,
        DateTimeZone::class => Spatie\LaravelSettings\SettingsCasts\DateTimeZoneCast::class,
        Spatie\DataTransferObject\DataTransferObject::class => Spatie\LaravelSettings\SettingsCasts\DtoCast::class,
    ],

    /*
     * The package will look for settings in these paths and automatically
     * register them.
     */
    // The bundled application has an explicit GeneralSettings binding above.
    // Discovering every App class on each private PHP request needlessly loads
    // unrelated controllers, provider clients and console commands on Windows.
    'auto_discover_settings' => env('DESKTOP_DASHBOARD_LOCAL', false) ? [] : [
        app()->path(),
    ],

    /*
     * Automatically discovered settings classes can be cached so they don't
     * need to be searched each time the application boots up.
     */
    'discovered_settings_cache_path' => storage_path('app/laravel-settings'),
];
