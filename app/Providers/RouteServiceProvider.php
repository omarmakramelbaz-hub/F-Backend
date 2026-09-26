<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot()
    {
        $this->configureRateLimiting();

        // Append the new scheduler job without changing any legacy schedules.
        $this->app->afterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) {
            $schedule->command('go-services:dispatch')->everyMinute()->withoutOverlapping();
        });

        $this->routes(function () {
            Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
            // Preserve the legacy cancellation override and all existing routes.
            Route::middleware('api')->prefix('api')->group(base_path('routes/api_cancel.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/go_services.php'));
            Route::middleware('web')->group(base_path('routes/web.php'));
            Route::middleware('web')->group(base_path('routes/admin.php'));
        });
    }

    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
