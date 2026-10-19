<?php

// The captured application remains compatible with its Laravel 8 server. Only
// the bundled engine loads these renamed official middleware classes.
foreach ([
    'Fideloper\\Proxy\\TrustProxies' => \Illuminate\Http\Middleware\TrustProxies::class,
    'Fruitcake\\Cors\\HandleCors' => \Illuminate\Http\Middleware\HandleCors::class,
    'Spatie\\Permission\\Middlewares\\RoleMiddleware' => \Spatie\Permission\Middleware\RoleMiddleware::class,
    'Spatie\\Permission\\Middlewares\\PermissionMiddleware' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
    'Spatie\\Permission\\Middlewares\\RoleOrPermissionMiddleware' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
] as $legacy => $current) {
    if (!class_exists($legacy)) {
        class_alias($current, $legacy);
    }
}

/** The captured settings table intentionally has no unique (group,name) index. */
final class FasakhanstaDesktopLegacySettingsRepository extends \Spatie\LaravelSettings\SettingsRepositories\DatabaseSettingsRepository
{
    public function updatePropertiesPayload(string $group,array $properties): void
    {
        // Match the original package's UPDATE-only behavior. MySQL ignores an
        // upsert uniqueBy argument without a real matching unique index.
        foreach($properties as $name=>$payload)$this->getBuilder()->where('group',$group)->where('name',$name)
            ->update(['payload'=>$this->encode($payload)]);
    }
}
