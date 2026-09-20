<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Auth\UserResource;
use App\Http\Traits\ApiResponses;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Tymon\JWTAuth\Facades\JWTAuth;

class SocialAuthController extends Controller
{
    use ApiResponses;

    /**
     * Redirect the user to the provider authentication page.
     * Kept for legacy server-driven OAuth flows.
     */
    public function redirectToProvider($provider)
    {
        $validated = $this->validateProvider($provider);
        if (!is_null($validated)) {
            return $validated;
        }

        if (!class_exists(Socialite::class)) {
            return $this->errorResponse('Server-driven social login is not available.', 501);
        }

        $redirectUrl = url('/api/auth/social/' . $provider . '/callback');

        return response()->json([
            'url' => Socialite::driver($provider)
                ->redirectUrl($redirectUrl)
                ->stateless()
                ->redirect()
                ->getTargetUrl(),
        ]);
    }

    /**
     * Complete a legacy server-driven OAuth callback.
     */
    public function handleProviderCallback($provider)
    {
        $validated = $this->validateProvider($provider);
        if (!is_null($validated)) {
            return $validated;
        }

        if (!class_exists(Socialite::class)) {
            return $this->errorResponse('Server-driven social login is not available.', 501);
        }

        try {
            $redirectUrl = url('/api/auth/social/' . $provider . '/callback');
            $providerUser = Socialite::driver($provider)
                ->redirectUrl($redirectUrl)
                ->stateless()
                ->user();
        } catch (\Throwable $e) {
            \Log::error('Social login callback error: ' . $e->getMessage());
            return $this->errorResponse(trans('auth.social_login_failed'), 400);
        }

        return $this->loginProviderUser($provider, $providerUser);
    }

    /**
     * Token-based social login for Flutter / SPA clients.
     *
     * Google authentication prefers an OpenID Connect ID token whose
     * audience is the configured Web OAuth client. Access-token validation
     * remains available for older clients and is restricted to the known
     * Web/Android OAuth client IDs.
     */
    public function handleProviderToken(Request $request, $provider)
    {
        $validated = $this->validateProvider($provider);
        if (!is_null($validated)) {
            return $validated;
        }

        $validator = Validator::make($request->all(), [
            'access_token' => $provider === 'facebook'
                ? 'required|string'
                : 'nullable|string|required_without:id_token',
            'id_token' => $provider === 'google'
                ? 'nullable|string|required_without:access_token'
                : 'nullable|string',
            'mobile' => 'nullable|string|max:30',
            'country_code' => 'nullable|string|max:10',
            'fcm_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            if ($provider === 'facebook') {
                $providerUser = $this->facebookUserFromToken($request->access_token);
            } elseif ($request->filled('id_token')) {
                $providerUser = $this->googleUserFromIdToken($request->id_token);
            } else {
                $providerUser = $this->googleUserFromAccessToken($request->access_token);
            }
        } catch (\Throwable $e) {
            \Log::error('Social token verification failed [' . $provider . ']: ' . $e->getMessage());
            return $this->errorResponse('تعذر التحقق من حساب ' . ucfirst($provider) . '.', 400);
        }

        return $this->loginProviderUser(
            $provider,
            $providerUser,
            $request->mobile,
            $request->country_code,
            $request->fcm_id
        );
    }

    /**
     * Validate a Facebook user token against this Meta app, then fetch profile.
     */
    protected function facebookUserFromToken(string $accessToken): array
    {
        $appId = (string) config('services.facebook.client_id');
        $appSecret = (string) config('services.facebook.client_secret');

        if ($appId === '' || $appSecret === '') {
            throw new \RuntimeException('Facebook backend credentials are missing.');
        }

        $debugResponse = Http::timeout(12)->get('https://graph.facebook.com/debug_token', [
            'input_token' => $accessToken,
            'access_token' => $appId . '|' . $appSecret,
        ]);

        if (!$debugResponse->successful()) {
            throw new \RuntimeException('Facebook token debug request failed.');
        }

        $debugData = $debugResponse->json('data');
        if (!is_array($debugData)
            || empty($debugData['is_valid'])
            || (string) ($debugData['app_id'] ?? '') !== $appId) {
            throw new \RuntimeException('Facebook token is invalid or belongs to another app.');
        }

        $profileResponse = Http::timeout(12)->get('https://graph.facebook.com/me', [
            'fields' => 'id,name,email,picture.width(200)',
            'access_token' => $accessToken,
        ]);

        if (!$profileResponse->successful()) {
            throw new \RuntimeException('Facebook profile request failed.');
        }

        $profile = $profileResponse->json();
        if (!is_array($profile) || empty($profile['id'])) {
            throw new \RuntimeException('Facebook profile is missing the user id.');
        }

        return [
            'id' => (string) $profile['id'],
            'name' => $profile['name'] ?? null,
            'email' => $profile['email'] ?? null,
            'nickname' => null,
        ];
    }

    /**
     * Verify the Google OpenID Connect ID token returned by the mobile app.
     * The ID token must be issued for our Web OAuth client (serverClientId).
     */
    protected function googleUserFromIdToken(string $idToken): array
    {
        $clientId = (string) config('services.google.client_id');
        if ($clientId === '') {
            throw new \RuntimeException('Google backend client id is missing.');
        }

        $tokenInfoResponse = Http::timeout(12)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);

        if (!$tokenInfoResponse->successful()) {
            throw new \RuntimeException('Google ID token validation failed.');
        }

        $tokenInfo = $tokenInfoResponse->json();
        if (!is_array($tokenInfo)) {
            throw new \RuntimeException('Google ID token response is invalid.');
        }

        $audience = (string) ($tokenInfo['aud'] ?? '');
        if ($audience === '' || !hash_equals($clientId, $audience)) {
            throw new \RuntimeException('Google ID token belongs to another client.');
        }

        $issuer = (string) ($tokenInfo['iss'] ?? '');
        if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new \RuntimeException('Google ID token issuer is invalid.');
        }

        if (empty($tokenInfo['sub'])) {
            throw new \RuntimeException('Google ID token is missing the user id.');
        }

        return [
            'id' => (string) $tokenInfo['sub'],
            'name' => $tokenInfo['name'] ?? null,
            'email' => $tokenInfo['email'] ?? null,
            'nickname' => null,
        ];
    }

    /**
     * Compatibility path for older app builds that only send a Google access
     * token. The token must have been issued to one of our known OAuth clients.
     */
    protected function googleUserFromAccessToken(string $accessToken): array
    {
        $webClientId = (string) config('services.google.client_id');
        $androidClientId = (string) config('services.google.android_client_id');
        $allowedClientIds = array_values(array_filter([$webClientId, $androidClientId]));

        if (empty($allowedClientIds)) {
            throw new \RuntimeException('Google backend client ids are missing.');
        }

        $tokenInfoResponse = Http::timeout(12)->get('https://oauth2.googleapis.com/tokeninfo', [
            'access_token' => $accessToken,
        ]);

        if (!$tokenInfoResponse->successful()) {
            throw new \RuntimeException('Google access token validation failed.');
        }

        $tokenInfo = $tokenInfoResponse->json();
        if (!is_array($tokenInfo)) {
            throw new \RuntimeException('Google access token response is invalid.');
        }

        $issuedTo = (string) (
            $tokenInfo['issued_to']
            ?? $tokenInfo['audience']
            ?? $tokenInfo['aud']
            ?? ''
        );

        if ($issuedTo === '' || !in_array($issuedTo, $allowedClientIds, true)) {
            throw new \RuntimeException('Google access token belongs to another client.');
        }

        $profileResponse = Http::timeout(12)
            ->withToken($accessToken)
            ->get('https://www.googleapis.com/oauth2/v3/userinfo');

        if (!$profileResponse->successful()) {
            throw new \RuntimeException('Google profile request failed.');
        }

        $profile = $profileResponse->json();
        if (!is_array($profile) || empty($profile['sub'])) {
            throw new \RuntimeException('Google profile is missing the user id.');
        }

        return [
            'id' => (string) $profile['sub'],
            'name' => $profile['name'] ?? null,
            'email' => $profile['email'] ?? null,
            'nickname' => null,
        ];
    }

    /**
     * Find/create the local user and return the response shape expected by
     * the customer application: data.user_data + data.register.
     */
    protected function loginProviderUser(
        string $provider,
        $providerUser,
        ?string $mobile = null,
        ?string $countryCode = null,
        ?string $fcmId = null
    ) {
        $scope = request()->header('X-App-Scope') === 'go' ? 'go' : 'fasakhansta';
        $providerId = trim((string) $this->providerValue($providerUser, 'id'));
        $email = $this->providerValue($providerUser, 'email');
        $email = is_string($email) ? trim($email) : null;

        if ($providerId === '') {
            return $this->errorResponse('لم يرجع مزود تسجيل الدخول رقم مستخدم صالح.', 400);
        }

        $socialAccount = SocialAccount::where('provider', $provider)
            ->where('provider_user_id', $providerId)
            ->whereHas('user', function ($query) use ($scope) {
                $query->where('account_type', 'user')
                    ->where('app_scope', $scope);
            })
            ->first();

        $user = $socialAccount?->user;
        $register = 0;

        if (!$user && !empty($email)) {
            $user = User::where('email', $email)
                ->where('account_type', 'user')
                ->where('app_scope', $scope)
                ->first();
        }

        if (!$user) {
            $register = 1;
            $name = $this->providerValue($providerUser, 'name')
                ?: $this->providerValue($providerUser, 'nickname')
                ?: ucfirst($provider) . ' User';

            $user = User::create([
                'added_by' => 1,
                'name' => $name,
                'email' => $email,
                'password' => Str::random(32),
                'email_verified_at' => !empty($email) ? now() : null,
                'account_type' => 'user',
                'app_scope' => $scope,
                'status' => 'accepted',
            ]);
        }

        if ($user->status === 'declined') {
            return $this->errorResponse(trans('api.your account has declined'), 403);
        }

        $user->socialAccounts()->updateOrCreate(
            [
                'provider' => $provider,
                'provider_user_id' => $providerId,
            ],
            ['provider' => $provider]
        );

        if (!empty($mobile)) {
            $mobileExists = User::where('account_type', 'user')
                ->where('app_scope', $scope)
                ->where('mobile', $mobile)
                ->where('id', '!=', $user->id)
                ->exists();

            if ($mobileExists) {
                return $this->errorResponse(trans('api.mobile already exist'), 422);
            }

            $user->mobile = $mobile;
            if (!empty($countryCode)) {
                $user->country_code = $countryCode;
            }
        }

        if (!empty($fcmId)) {
            $user->fcm_id = $fcmId;
            $user->newOrExistingToken($fcmId);
        }

        $user->save();

        $token = JWTAuth::fromUser($user);
        $userData = UserResource::make($user->fresh())->getToken($token);

        return $this->successResponse([
            'user_data' => $userData,
            'register' => $register,
        ], trans('api.signed'));
    }

    protected function providerValue($providerUser, string $field)
    {
        if (is_array($providerUser)) {
            return $providerUser[$field] ?? null;
        }

        $methodMap = [
            'id' => 'getId',
            'name' => 'getName',
            'email' => 'getEmail',
            'nickname' => 'getNickname',
        ];

        $method = $methodMap[$field] ?? null;
        if ($method && is_object($providerUser) && method_exists($providerUser, $method)) {
            return $providerUser->{$method}();
        }

        return null;
    }

    protected function validateProvider($provider)
    {
        if (!in_array($provider, ['facebook', 'google'])) {
            return $this->errorResponse(trans('auth.unsupported_provider'), 422);
        }

        return null;
    }
}
