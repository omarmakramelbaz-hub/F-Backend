<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Middleware\MetaOAuthCallbackPrivacy;
use App\Services\Dashboard\MetaInboxAccess;
use App\Services\Dashboard\MetaOAuthCredentials;
use App\Services\Dashboard\MetaOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Owner-only authorization with a separate review step before saving account tokens. */
class MetaConnectionController extends Controller
{
    private const STATE_KEY = 'meta_oauth.state';
    private const PENDING_KEY = 'meta_oauth.pending';
    private const NOTICE_KEY = 'meta_oauth.notice';
    private const TTL = 600;

    public function index(Request $request, MetaInboxAccess $access, MetaOAuthCredentials $credentials)
    {
        try {
            $actor = $this->owner($access);
            $status = $credentials->status();
            $settings = !empty($status['configured']) ? $credentials->settings() : null;
            $pending = $settings ? $this->readPending($request, $actor, $settings) : null;
            if (!$settings) $request->session()->forget(self::PENDING_KEY);
            return response()->view('admin.meta.connection', ['metaConnection' => [
                'configured' => $settings !== null,
                'callback_url' => $settings['redirect_uri'] ?? null,
                'accounts' => $this->safeAccounts($status['accounts'] ?? []),
                'credentials_available' => !empty($status['credentials_available']),
                'pending_accounts' => $this->safeAccounts($pending['accounts'] ?? []),
                'has_pending' => $pending !== null,
                'notice' => $this->safeNotice($request->session()->pull(self::NOTICE_KEY)),
                'start_url' => route('meta-connection.start'),
                'confirm_url' => route('meta-connection.confirm'),
                'inbox_url' => route('meta-inbox.index'),
            ]])->withHeaders($this->privateHeaders());
        } catch (HttpException $error) {
            return $this->denied($error->getStatusCode());
        } catch (\Throwable $error) {
            return response($this->message('failed'), 503)->withHeaders($this->privateHeaders());
        }
    }

    public function start(Request $request, MetaInboxAccess $access)
    {
        try {
            $actor = $this->owner($access);
            $request->session()->forget([self::STATE_KEY, self::PENDING_KEY]);
            $settings = app(MetaOAuthCredentials::class)->settings();
            $state = bin2hex(random_bytes(32));
            $request->session()->put(self::STATE_KEY, [
                'hash' => hash('sha256', $state),
                'actor_id' => (string) $actor->getKey(),
                'issued_at' => time(),
                'context' => $this->contextHash($settings),
            ]);
            $url = app(MetaOAuthService::class)->authorizationUrl($state);
            return redirect()->away($url, 303)->withHeaders($this->privateHeaders());
        } catch (HttpException $error) {
            return $this->denied($error->getStatusCode());
        } catch (\Throwable $error) {
            $request->session()->forget([self::STATE_KEY, self::PENDING_KEY]);
            return $this->cleanRedirect($request, 'failed');
        }
    }

    public function callback(Request $request, MetaInboxAccess $access)
    {
        // Raw callback parameters have already been removed from the HTTP request.
        $input = app(MetaOAuthCallbackPrivacy::class)->consume();
        $saved = $request->session()->pull(self::STATE_KEY);
        $request->session()->forget(self::PENDING_KEY);
        try {
            $actor = $this->owner($access);
            if (!is_array($saved) || !empty($input['invalid'])
                || !is_string($input['state'] ?? null)
                || !is_string($saved['hash'] ?? null)
                || !hash_equals($saved['hash'], hash('sha256', $input['state']))
                || !$this->boundAndFresh($saved, $actor)) {
                return $this->cleanRedirect($request, 'expired');
            }
            $settings = app(MetaOAuthCredentials::class)->settings();
            if (!is_string($saved['context'] ?? null)
                || !hash_equals($saved['context'], $this->contextHash($settings))) {
                return $this->cleanRedirect($request, 'expired');
            }
            if (!empty($input['cancelled'])) return $this->cleanRedirect($request, 'cancelled');
            if (!is_string($input['code'] ?? null) || $input['code'] === '') {
                return $this->cleanRedirect($request, 'failed');
            }
            $pending = app(MetaOAuthService::class)->discover($input['code']);
            if (!is_array($pending) || !$this->safeAccounts($pending['accounts'] ?? [])) {
                return $this->cleanRedirect($request, 'no_accounts');
            }
            $encrypted = Crypt::encryptString(json_encode([
                'actor_id' => (string) $actor->getKey(), 'issued_at' => time(),
                'context' => $saved['context'], 'pending' => $pending,
            ], JSON_THROW_ON_ERROR));
            // Bound memory use even if an upstream API returns malformed output.
            if (strlen($encrypted) > 2000000) return $this->cleanRedirect($request, 'failed');
            $request->session()->put(self::PENDING_KEY, $encrypted);
            return $this->cleanRedirect($request, 'review');
        } catch (\Throwable $error) {
            // Never log an OAuth exception: codes and Graph responses may include credentials.
            return $this->cleanRedirect($request, 'failed');
        } finally {
            unset($input, $pending, $encrypted);
        }
    }

    public function confirm(Request $request, MetaInboxAccess $access)
    {
        // Pending credentials are one-use, including when validation or activation fails.
        $encrypted = $request->session()->pull(self::PENDING_KEY);
        try {
            $actor = $this->owner($access);
            $settings = app(MetaOAuthCredentials::class)->settings();
            $pending = $this->decodePending($encrypted, $actor, $settings);
            if ($pending === null) return $this->cleanRedirect($request, 'expired');
            $selected = $request->input('accounts');
            if (!is_array($selected) || !$selected || count($selected) > 100) {
                return $this->cleanRedirect($request, 'selection_required');
            }
            foreach ($selected as $key) {
                if (!is_string($key) || !preg_match('/\A(?:messenger|instagram):[1-9][0-9]{0,31}\z/', $key)) {
                    return $this->cleanRedirect($request, 'failed');
                }
            }
            if (count(array_unique($selected)) !== count($selected)) return $this->cleanRedirect($request, 'failed');
            app(MetaOAuthService::class)->activate($pending, array_values($selected));
            return $this->cleanRedirect($request, 'saved');
        } catch (HttpException $error) {
            return $this->denied($error->getStatusCode());
        } catch (\Throwable $error) {
            return $this->cleanRedirect($request, 'failed');
        } finally {
            unset($encrypted, $pending);
        }
    }

    private function owner(MetaInboxAccess $access)
    {
        abort_if(config('desktop_dashboard.local', false), 403);
        return $access->owner(auth('admin')->user());
    }

    private function readPending(Request $request, $actor, array $settings): ?array
    {
        $encrypted = $request->session()->get(self::PENDING_KEY);
        $pending = $this->decodePending($encrypted, $actor, $settings);
        if ($encrypted !== null && $pending === null) {
            $request->session()->forget(self::PENDING_KEY);
            $request->session()->flash(self::NOTICE_KEY, 'expired');
        }
        return $pending;
    }

    private function decodePending($encrypted, $actor, array $settings): ?array
    {
        if (!is_string($encrypted) || $encrypted === '' || strlen($encrypted) > 2000000) return null;
        try {
            $envelope = json_decode(Crypt::decryptString($encrypted), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($envelope) || !$this->boundAndFresh($envelope, $actor)
                || !is_string($envelope['context'] ?? null)
                || !hash_equals($envelope['context'], $this->contextHash($settings))
                || !is_array($envelope['pending'] ?? null)) return null;
            return $envelope['pending'];
        } catch (\Throwable $error) {
            return null;
        }
    }

    private function boundAndFresh(array $saved, $actor): bool
    {
        return is_string($saved['actor_id'] ?? null)
            && hash_equals((string) $actor->getKey(), $saved['actor_id'])
            && is_int($saved['issued_at'] ?? null)
            && $saved['issued_at'] <= time() + 5
            && $saved['issued_at'] >= time() - self::TTL;
    }

    private function contextHash(array $settings): string
    {
        return hash('sha256', json_encode([
            'app_id' => $settings['app_id'] ?? null, 'config_id' => $settings['config_id'] ?? null,
            'redirect_uri' => $settings['redirect_uri'] ?? null, 'api_version' => $settings['api_version'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    /** This is the only account representation allowed to enter the rendered page. */
    private function safeAccounts($accounts): array
    {
        if (!is_array($accounts)) return [];
        $safe = [];
        foreach (array_slice($accounts, 0, 100) as $account) {
            if (!is_array($account) || !in_array($account['channel'] ?? null, ['messenger', 'instagram'], true)
                || !is_string($account['account_id'] ?? null)
                || !preg_match('/\A[1-9][0-9]{0,31}\z/', $account['account_id'])) continue;
            $channel = $account['channel'];
            $key = $channel.':'.$account['account_id'];
            $label = is_string($account['label'] ?? null) ? $account['label'] : '';
            $label = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $label) ?? '');
            $safe[$key] = ['key' => $key, 'channel' => $channel, 'account_id' => $account['account_id'],
                'label' => mb_substr($label !== '' ? $label : ($channel === 'messenger' ? 'Messenger' : 'Instagram'), 0, 100)];
        }
        return array_values($safe);
    }

    private function cleanRedirect(Request $request, string $notice)
    {
        $request->session()->flash(self::NOTICE_KEY, $this->safeNotice($notice));
        return redirect()->route('meta-connection.index', [], 303)->withHeaders($this->privateHeaders());
    }

    private function safeNotice($value): ?string
    {
        return is_string($value) && in_array($value, ['failed', 'expired', 'cancelled', 'no_accounts', 'review',
            'selection_required', 'saved'], true) ? $value : null;
    }

    private function message(string $value): string
    {
        return app()->getLocale() === 'ar' ? 'تعذّر إكمال اتصال ميتا. حاول الربط مرة أخرى.'
            : 'Unable to complete the Meta connection. Please connect again.';
    }

    private function denied(int $status)
    {
        return response(app()->getLocale() === 'ar' ? 'هذه الصفحة متاحة للمالك فقط.' : 'This page is available to the Owner only.',
            $status)->withHeaders($this->privateHeaders());
    }

    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow'];
    }
}
