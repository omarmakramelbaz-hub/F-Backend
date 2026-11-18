<?php

namespace App\Services\Dashboard;

use App\Support\MetaMessagingProtocol;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Optional display-name enrichment; never a message, reply or order source. */
class MetaProfileService
{
    private $transport;

    public function __construct(?callable $transport = null) { $this->transport = $transport; }

    public function refresh(int $limit = 10, ?int $conversation = null): array
    {
        if ($limit < 1 || $limit > 10) throw new \InvalidArgumentException('LIMIT');
        $metrics = ['scanned' => 0, 'attempted' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => 0];
        if (!app(MetaInboxAccess::class)->available()) return $metrics + ['unavailable' => 1];
        $deadline = microtime(true) + 20;
        // Rotate skipped rows through a bounded scan without changing message chronology.
        $query = MetaInboxAccess::scope(DB::table('meta_inbox_conversations'));
        if ($conversation !== null) {
            if ($conversation < 1) throw new \InvalidArgumentException('CONVERSATION');
            $query->where('id', $conversation);
        }
        $rows = $query->orderBy('updated_at')->orderBy('id')->limit(200)->get(['id']);
        foreach ($rows as $row) {
            if ($metrics['attempted'] >= $limit || microtime(true) >= $deadline) return $metrics;
            $metrics['scanned']++;
            try { $claim = $this->claim((int) $row->id); }
            catch (\Throwable $error) { $metrics['errors']++; continue; }
            if ($claim === null) { $metrics['skipped']++; continue; }
            $metrics['attempted']++;
            try {
                $reason = 'PROFILE_UNAVAILABLE'; $name = null;
                if ($this->currentAccount($claim) !== null) {
                    $response = $this->call($claim);
                    if (($response['status'] ?? null) === 200 && is_string($response['body'] ?? null)
                        && strlen($response['body']) <= 65536) {
                        $profile = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
                        if (is_array($profile) && !isset($profile['error'])) {
                            $reason = ($profile['id'] ?? null) === $claim['peer'] ? 'INVALID_PROFILE' : 'ID_MISMATCH';
                            if ($reason === 'INVALID_PROFILE') $name = $this->profileName($profile, $claim['channel']);
                        }
                    } elseif (in_array($response['status'] ?? null, [400, 401, 403, 404, 429], true)) {
                        $reason = 'META_REJECTED';
                    }
                } else $reason = 'ACCOUNT_CHANGED';
                $updated = $this->finish($claim, $name, $reason);
                $metrics[$updated ? 'updated' : 'failed']++;
            } catch (\Throwable $error) {
                // Failure is deliberately isolated from the capture/order worker.
                try { $this->finish($claim, null, 'PROFILE_UNAVAILABLE'); }
                catch (\Throwable $ignored) { $metrics['errors']++; }
                $metrics['failed']++;
            }
        }
        return $metrics;
    }

    private function claim(int $id): ?array
    {
        return DB::transaction(function () use ($id) {
            $thread = MetaInboxAccess::scope(DB::table('meta_inbox_conversations'))->where('id', $id)->lockForUpdate()->first();
            if (!$thread) return null;
            $customer = $this->customer($thread);
            if ($customer === null) { $this->rotate($thread); return null; }
            if ($this->knownName($customer)) { $this->rotate($thread); return null; }
            $account = MetaInboxAccess::account($thread->waba_id, $thread->phone_number_id);
            if (!$this->configured($account)) { $this->rotate($thread); return null; }
            $fingerprint = $this->accountFingerprint($account);
            $profile = is_array($customer['meta_profile'] ?? null) ? $customer['meta_profile'] : [];
            $now = gmdate('Y-m-d H:i:s');
            // A token/login/version change permits another attempt without waiting for the old failure.
            if (($profile['account_fingerprint'] ?? null) === $fingerprint
                && is_string($profile['next_attempt_at'] ?? null) && $profile['next_attempt_at'] > $now) { $this->rotate($thread); return null; }
            $claim = bin2hex(random_bytes(16));
            $attempts = min(255, max(0, (int) ($profile['attempts'] ?? 0))) + 1;
            $customer['meta_profile'] = ['state' => 'CLAIMED', 'claim_id' => $claim, 'attempts' => $attempts,
                'account_fingerprint' => $fingerprint, 'last_attempt_at' => $now,
                'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 300)];
            DB::table('meta_inbox_conversations')->where('id', $id)->update([
                'customer' => $this->encrypt($customer), 'updated_at' => now('UTC')]);
            return ['id' => $id, 'channel' => $thread->waba_id, 'account_id' => $thread->phone_number_id,
                'peer' => $customer['user_id'], 'peer_hash' => $thread->peer_hash, 'claim_id' => $claim,
                'account_fingerprint' => $fingerprint, 'account' => $account,
                'version' => $this->version()];
        }, 3);
    }

    private function finish(array $claim, ?string $name, string $reason): bool
    {
        return DB::transaction(function () use ($claim, $name, $reason) {
            $thread = MetaInboxAccess::scope(DB::table('meta_inbox_conversations'))
                ->where('id', $claim['id'])->lockForUpdate()->first();
            if (!$thread || $thread->waba_id !== $claim['channel'] || $thread->phone_number_id !== $claim['account_id']
                || !hash_equals($thread->peer_hash, $claim['peer_hash'])) return false;
            $customer = $this->customer($thread);
            if ($customer === null || $customer['user_id'] !== $claim['peer']
                || ($customer['meta_profile']['claim_id'] ?? null) !== $claim['claim_id']) return false;
            $known = $this->knownName($customer);
            $valid = $name !== null && !$known && $this->currentAccount($claim) !== null;
            if ($valid) $customer['name'] = $name;
            $attempts = (int) $customer['meta_profile']['attempts'];
            $customer['meta_profile'] = ['state' => $valid || $known ? 'COMPLETE' : 'RETRY',
                'attempts' => $attempts, 'account_fingerprint' => $claim['account_fingerprint'],
                'last_attempt_at' => gmdate('Y-m-d H:i:s'),
                'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + min(86400, 3600 * (2 ** min(5, max(0, $attempts - 1))))),
                'reason' => $valid || $known ? null : $reason];
            DB::table('meta_inbox_conversations')->where('id', $claim['id'])->update([
                'customer' => $this->encrypt($customer), 'updated_at' => now('UTC')]);
            return $valid;
        }, 3);
    }

    private function customer(object $thread): ?array
    {
        try {
            $plain = Crypt::decryptString($thread->customer);
            if (strlen($plain) > 65536) return null;
            $customer = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
            $peer = $customer['user_id'] ?? null;
            $scope = $thread->waba_id . ':' . $thread->phone_number_id . ':';
            if (!is_array($customer) || !MetaMessagingProtocol::id($peer)
                || !hash_equals($thread->peer_hash, hash_hmac('sha256', 'whatsapp-peer-v1:' . $scope . 'user:' . $peer, Crypt::getKey()))) return null;
            return $customer;
        } catch (\Throwable $error) { return null; }
    }

    private function profileName(array $profile, string $channel): ?string
    {
        if ($channel === 'messenger') {
            $first = $this->name($profile['first_name'] ?? null);
            $last = $this->name($profile['last_name'] ?? null);
            return $this->name(implode(' ', array_filter([$first, $last], static fn ($part) => $part !== null)));
        }
        $name = $this->name($profile['name'] ?? null);
        if ($name !== null) return $name;
        $username = $profile['username'] ?? null;
        return is_string($username) && preg_match('/\A[A-Za-z0-9._]{1,30}\z/', $username) ? '@' . $username : null;
    }

    private function name($value): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || strlen($value) > 800
            || mb_strlen($value, 'UTF-8') > 200 || preg_match('/[\x00-\x1f\x7f]/', $value)) return null;
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    private function knownName(array $customer): bool
    {
        return is_string($customer['name'] ?? null) && trim($customer['name']) !== '';
    }

    private function rotate(object $thread): void
    {
        // Equality guards preserve concurrent projection/profile edits. No encrypted data is rewritten.
        MetaInboxAccess::scope(DB::table('meta_inbox_conversations'))->where('id', $thread->id)
            ->where('customer', $thread->customer)->where('updated_at', $thread->updated_at)
            ->update(['updated_at' => now('UTC')]);
    }

    private function currentAccount(array $claim): ?array
    {
        $account = MetaInboxAccess::account($claim['channel'], $claim['account_id']);
        return $this->configured($account) && hash_equals($claim['account_fingerprint'], $this->accountFingerprint($account)) ? $account : null;
    }

    private function configured(?array $account): bool
    {
        $token = $account['access_token'] ?? null;
        return is_string($token) && strlen($token) >= 16 && strlen($token) <= 4096
            && !preg_match('/[\s\x00-\x1f\x7f]/', $token)
            && in_array($account['login'] ?? 'facebook', ['facebook', 'instagram'], true)
            && ($this->transport !== null || function_exists('curl_init'));
    }

    private function version(): string
    {
        $version = config('meta_messaging.api_version', 'v25.0');
        return is_string($version) && preg_match('/\Av[0-9]{1,2}\.0\z/', $version) ? $version : 'v25.0';
    }

    private function accountFingerprint(array $account): string
    {
        return hash_hmac('sha256', json_encode([$account['channel'], $account['account_id'], $account['access_token'],
            $account['login'] ?? 'facebook', $this->version()], JSON_THROW_ON_ERROR), Crypt::getKey());
    }

    private function call(array $claim): array
    {
        $host = $claim['channel'] === 'instagram' && ($claim['account']['login'] ?? 'facebook') === 'instagram'
            ? 'graph.instagram.com' : 'graph.facebook.com';
        $fields = $claim['channel'] === 'messenger' ? 'id,first_name,last_name' : 'id,name,username';
        $endpoint = 'https://' . $host . '/' . $claim['version'] . '/' . $claim['peer'] . '?fields=' . rawurlencode($fields);
        $token = $claim['account']['access_token'];
        if ($this->transport !== null) return ($this->transport)($endpoint, $token);
        $body = ''; $curl = curl_init($endpoint);
        curl_setopt_array($curl, [CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($c, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 65536) return 0;
                $body .= $chunk; return strlen($chunk);
            }]);
        try { $ok = curl_exec($curl); return ['status' => $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $ok === false ? '' : $body]; }
        finally { curl_close($curl); }
    }

    private function encrypt(array $data): string { return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)); }
}
