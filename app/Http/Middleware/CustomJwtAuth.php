<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;

class CustomJwtAuth
{
    public function handle(Request $request, Closure $next)
    {
        try {
            // The token is checked here (expired or invalid get their own message), and the account is the one auth:api
            // already found. Never JWTAuth::authenticate(): it signs the account into whichever guard is the default at
            // that moment, which would make an app account look like a dashboard («admin») sign-in to the code after it.
            JWTAuth::parseToken()->checkOrFail();
            $user = auth('api')->user();
            // Check if the user is active (or any other custom logic)
            if (!$user || $user->status == 'declined') {
                return response()->json(['message' => __('api.User is inactive')], 401);
            }

            // Keep Fasakhansta, GO Customer and GO Partners sessions isolated.
            // Existing legacy delivery delegates are temporarily allowed in
            // GO Partners so the current courier fleet is not locked out.
            $requestedScope = $request->header('X-App-Scope');
            $expectedScope = $requestedScope === 'go'
                ? 'go'
                : ($requestedScope === 'go_partner' ? 'go_partner' : 'fasakhansta');
            $userScope = $user->app_scope ?: 'fasakhansta';

            $legacyGoPartnerDelegate = $expectedScope === 'go_partner'
                && $user->account_type === 'delegate'
                && $userScope === 'fasakhansta';

            if ($userScope !== $expectedScope && !$legacyGoPartnerDelegate) {
                return response()->json([
                    'message' => 'هذه الجلسة تخص تطبيقاً آخر. سجل الدخول إلى التطبيق الحالي مرة أخرى.',
                ], 401);
            }
        } catch (TokenExpiredException $e) {
            return response()->json(['message' => __('api.Token has expired')], 401);
        } catch (JWTException $e) {
            return response()->json(['message' => __('api.Token is invalid')], 401);
        }

        return $next($request);
    }
}

?>