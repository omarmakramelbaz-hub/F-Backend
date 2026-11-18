<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Schema;

/** Native identities remain separated by channel AND business account. */
class MetaInboxAccess extends WhatsAppInboxAccess
{
    /** Only a persisted Owner may replace the business connection. */
    public function owner($actor): \App\Models\User
    {
        $fresh = $this->actor($actor);
        $owner = (int) $fresh->getKey() === 1
            || $fresh->roles()->where('guard_name', 'admin')->where('name', 'Super Admin')->exists();
        abort_unless($owner, 403);
        return $fresh;
    }

    public function canConnect($actor): bool
    {
        try {
            if (config('desktop_dashboard.local', false)) return false;
            $this->owner($actor);
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    public function available(): bool
    {
        return !config('desktop_dashboard.local', false)
            && Schema::hasTable('meta_inbox_conversations')
            && Schema::hasTable('meta_inbox_messages')
            && Schema::hasTable('meta_inbox_ingestion_failures');
    }

    public static function accounts(): array
    {
        $accounts = config('meta_messaging.accounts', []);
        if (!is_array($accounts)) return [];
        // Missing or invalid OAuth storage must not disable the existing connection.
        try {
            if (class_exists(MetaOAuthCredentials::class)) {
                $accounts = app(MetaOAuthCredentials::class)->overlay($accounts);
            }
        } catch (\Throwable $error) {
            // Keep the validated baseline accounts when private credentials are unavailable.
        }
        return array_values(array_filter($accounts, static function ($a) {
            return is_array($a) && in_array($a['channel'] ?? null, ['messenger', 'instagram'], true)
                && is_string($a['account_id'] ?? null) && preg_match('/\A[1-9][0-9]{0,31}\z/', $a['account_id']);
        }));
    }

    public static function account(string $channel, string $id): ?array
    {
        foreach (self::accounts() as $a) if ($a['channel'] === $channel && $a['account_id'] === $id) return $a;
        return null;
    }

    public static function scope($query, string $prefix = '')
    {
        return $query->where(function ($q) use ($prefix) {
            $q->whereRaw('1 = 0');
            foreach (self::accounts() as $a) {
                $q->orWhere(function ($pair) use ($prefix, $a) {
                    $pair->where($prefix.'waba_id', $a['channel'])->where($prefix.'phone_number_id', $a['account_id']);
                });
            }
        });
    }

    public static function label(string $channel, string $id): string
    {
        $account = self::account($channel, $id);
        return mb_substr((string) ($account['label'] ?? ($channel === 'instagram' ? 'Instagram' : 'Messenger')), 0, 100);
    }

    public static function fingerprint(): string
    {
        $pairs = array_map(static fn ($a) => $a['channel'].':'.$a['account_id'], self::accounts());
        sort($pairs);
        return hash('sha256', implode('|', $pairs));
    }
}
