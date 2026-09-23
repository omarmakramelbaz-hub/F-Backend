<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EnsureGoSchema
{
    public function handle($request, Closure $next)
    {
        $scope = $request->header('X-App-Scope');
        if (!in_array($scope, ['go', 'go_partner'], true) && !$request->is('api/partner-auth/*') && !$request->is('api/partner-applications*')) {
            return $next($request);
        }

        if ($this->needsBootstrap()) {
            try {
                Cache::lock('go-schema-bootstrap', 60)->block(20, function () {
                    if (!Schema::hasColumn('pending_vendors', 'partner_activated_at') || !Schema::hasColumn('users', 'partner_auth_email')) {
                        Artisan::call('migrate', [
                            '--path' => 'database/migrations/2026_09_22_000001_add_partner_verified_email.php',
                            '--force' => true,
                        ]);
                    }
                    if (!Schema::hasColumn('pending_vendors', 'application_kind')) {
                        Artisan::call('migrate', [
                            '--path' => 'database/migrations/2026_09_20_000001_add_go_partner_fields_to_pending_vendors_table.php',
                            '--force' => true,
                        ]);
                    }

                    if (!Schema::hasColumn('users', 'app_scope')) {
                        Artisan::call('migrate', [
                            '--path' => 'database/migrations/2026_09_20_000002_add_app_scope_to_users_table.php',
                            '--force' => true,
                        ]);
                    }

                    if (!Schema::hasTable('partner_service_requests')) {
                        Artisan::call('migrate', [
                            '--path' => 'database/migrations/2026_09_20_000003_create_partner_service_requests_table.php',
                            '--force' => true,
                        ]);
                    }
                });
            } catch (\Throwable $e) {
                Log::error('GO schema bootstrap failed', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($this->needsBootstrap()) {
            return response()->json([
                'status' => false,
                'message' => 'جاري تجهيز تحديث GO على الخادم. حاول مرة أخرى بعد لحظات.',
            ], 503);
        }

        return $next($request);
    }

    private function needsBootstrap(): bool
    {
        return !Schema::hasColumn('pending_vendors', 'application_kind')
            || !Schema::hasColumn('pending_vendors', 'partner_activated_at')
            || !Schema::hasColumn('users', 'partner_auth_email')
            || !Schema::hasColumn('users', 'app_scope')
            || !Schema::hasTable('partner_service_requests');
    }
}
