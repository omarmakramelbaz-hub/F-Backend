<?php

namespace Tests\Feature\Concerns;

trait AppRequests
{
    /**
     * Headers for an app request by this account, set up as on the server: a request there starts with nothing signed in
     * and nothing JWT-related built yet. Building the token here (JWTAuth) would otherwise leave the JWT library tied to
     * the guard that is the default now («admin»), and the request would run as a dashboard sign-in, which never happens live.
     */
    protected function appHeaders(int $user): array
    {
        $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser(\App\Models\User::query()->find($user));
        foreach (['tymon.jwt.auth', 'tymon.jwt.provider.auth', 'auth.driver'] as $instance) $this->app->forgetInstance($instance);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('tymon.jwt.auth');
        app('auth')->forgetGuards();
        app('auth')->shouldUse('admin'); // the configured default, which an earlier request's auth:api changed
        app('tymon.jwt')->unsetToken();
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }
}
