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
