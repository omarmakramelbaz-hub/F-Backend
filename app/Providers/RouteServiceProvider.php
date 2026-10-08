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

        $this->routes(function () {
            Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
            // Preserve the legacy cancellation override and all existing routes.
            Route::middleware('api')->prefix('api')->group(base_path('routes/api_cancel.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/go_services.php'));
            Route::middleware('web')->group(base_path('routes/web.php'));
            Route::middleware('web')->group(base_path('routes/admin.php'));
            Route::middleware('web')->group(base_path('routes/order_board.php'));
            Route::middleware('web')->group(base_path('routes/takeaway.php'));
            Route::middleware('web')->group(base_path('routes/pos_service.php'));
            Route::middleware('web')->group(base_path('routes/desktop_pos.php'));
            Route::middleware('web')->group(base_path('routes/desktop_dashboard.php'));
        });
    }

    protected function configureRateLimiting()
    {
        RateLimiter::for('desktop-pos', function (Request $request) {
            return Limit::perMinute(300)->by(hash('sha256', $request->bearerToken() ?? $request->ip()));
        });
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
