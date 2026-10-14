<?php

namespace App\Services\Dashboard;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A shared per-comment receipt prevents automated and cross-actor duplicate sends. */
class MetaCommentReplyGate
{
    private const TABLE = 'meta_comment_ai_targets';
    private const HISTORY = 'meta_comment_reply_requests';
    private const PENDING = ['SENDING', 'UNKNOWN'];
    private const AI_TERMINAL = ['SENDING', 'UNKNOWN', 'ACCEPTED', 'SKIPPED'];

    public function ready(): bool
    {
        if (config('desktop_dashboard.local', false)) return false;
        try { return Schema::hasTable(self::TABLE) && Schema::hasTable(self::HISTORY); }
        catch (\Throwable $error) { return false; }
    }

    /** This is an early filter; claim() repeats every check under the target row lock. */
    public function mayAutomate(array $identity): bool
    {
        $identity = $this->identity($identity);
        if ($identity === null || !$this->ready()) return false;
        try {
            $row = $this->target($identity)->first();
            if ($row && (!$this->matches($row, $identity) || in_array($row->state, self::AI_TERMINAL, true))) return false;
            if ($this->history($identity, ['ACCEPTED'])->exists()) {
                $this->skip($identity, 'MANUAL_HANDLED');
                return false;
            }
            return !$this->history($identity, self::PENDING)->exists();
        } catch (\Throwable $error) { return false; }
    }

    /** Distinguish a currently running send from a truly uncertain, durable delivery. */
    public function pendingState(array $identity): ?string
    {
        $identity = $this->identity($identity);
        if ($identity === null || !$this->ready()) return null;
        $row = $this->target($identity)->first();
        if ($row && $row->state === 'UNKNOWN') return 'UNKNOWN';
        if ($this->history($identity, ['UNKNOWN'])->exists()) return 'UNKNOWN';
        if (($row && $row->state === 'SENDING') || $this->history($identity, ['SENDING'])->exists()) return 'SENDING';
        return null;
    }

    /** Call before adding the new manual request to the historical request table. */
    public function claim(array $identity, string $source, ?string $requestId = null, ?int $revision = null): ?array
    {
        $identity = $this->identity($identity);
        if ($identity === null || !$this->ready() || !in_array($source, ['manual', 'ai'], true)
            || ($revision !== null && ($revision < 0 || $revision > 4294967295))) return null;
        $requestId = $requestId ?? self::uuid();
        if (!$this->validUuid($requestId)) return null;
        $requestId = strtolower($requestId);
        try {
            return DB::transaction(function () use ($identity, $source, $requestId, $revision) {
                $time = now('UTC');
                // Insert first to obtain the unique identity before checking cross-actor history.
                // FAILED is transient inside this transaction and has not dispatched anything.
                DB::table(self::TABLE)->insertOrIgnore($identity + [
                    'state' => 'FAILED', 'source' => $source, 'request_id' => $requestId,
                    'revision' => $revision, 'reason' => null, 'response_id' => null,
                    'created_at' => $time, 'updated_at' => $time,
                ]);
                $row = $this->target($identity)->lockForUpdate()->first();
                if (!$row || !$this->matches($row, $identity)) return null;
                if (in_array($row->state, $source === 'ai' ? self::AI_TERMINAL : self::PENDING, true)) return null;
                if ($source === 'ai' && $this->history($identity, ['ACCEPTED'])->exists()) {
                    DB::table(self::TABLE)->where('id', $row->id)->update([
                        'state' => 'SKIPPED', 'source' => 'ai', 'reason' => 'MANUAL_HANDLED',
                        'request_id' => null, 'revision' => $revision, 'response_id' => null, 'updated_at' => $time,
                    ]);
                    return null;
                }
                if ($this->history($identity, self::PENDING)->exists()) return null;
                DB::table(self::TABLE)->where('id', $row->id)->update([
                    'state' => 'SENDING', 'source' => $source, 'request_id' => $requestId,
                    'revision' => $revision, 'reason' => null, 'response_id' => null, 'updated_at' => $time,
                ]);
                return ['id' => (int) $row->id, 'request_id' => $requestId];
            }, 3);
        } catch (\Throwable $error) { return null; }
    }

    public function finish(array $claim, string $state, ?string $reason = null, ?string $responseId = null): void
    {
        if (!is_int($claim['id'] ?? null) || $claim['id'] < 1 || !$this->validUuid($claim['request_id'] ?? null)
            || !in_array($state, ['ACCEPTED', 'UNKNOWN', 'FAILED', 'SKIPPED'], true)
            || !$this->validReason($reason)) throw new \InvalidArgumentException('INVALID_CLAIM');
        $response = $responseId === null ? null : Crypt::encryptString($responseId);
        DB::table(self::TABLE)->where('id', $claim['id'])
            ->where('request_id', strtolower($claim['request_id']))->where('state', 'SENDING')
            ->update(['state' => $state, 'reason' => $reason, 'response_id' => $response, 'updated_at' => now('UTC')]);
    }

    public function skip(array $identity, string $reason): void
    {
        $identity = $this->identity($identity);
        if ($identity === null || !$this->validReason($reason)) throw new \InvalidArgumentException('INVALID_TARGET');
        if (!$this->ready()) return;
        DB::transaction(function () use ($identity, $reason) {
            $time = now('UTC');
            DB::table(self::TABLE)->insertOrIgnore($identity + [
                'state' => 'SKIPPED', 'source' => 'ai', 'request_id' => null,
                'revision' => null, 'reason' => $reason, 'response_id' => null,
                'created_at' => $time, 'updated_at' => $time,
            ]);
            $row = $this->target($identity)->lockForUpdate()->first();
            if (!$row || !$this->matches($row, $identity) || in_array($row->state, self::AI_TERMINAL, true)) return;
            DB::table(self::TABLE)->where('id', $row->id)->update([
                'state' => 'SKIPPED', 'source' => 'ai', 'request_id' => null,
                'revision' => null, 'reason' => $reason, 'response_id' => null, 'updated_at' => $time,
            ]);
        }, 3);
    }

    private function target(array $identity)
    {
        return DB::table(self::TABLE)->where('account_key', $identity['account_key'])->where('target_hash', $identity['target_hash']);
    }

    private function history(array $identity, array $states)
    {
        return DB::table(self::HISTORY)->where('account_key', $identity['account_key'])
            ->where('target_hash', $identity['target_hash'])->whereIn('state', $states);
    }

    private function matches(object $row, array $identity): bool
    {
        return hash_equals((string) $row->account_key, $identity['account_key'])
            && hash_equals((string) $row->target_hash, $identity['target_hash'])
            && hash_equals((string) $row->post_key, $identity['post_key']);
    }

    private function identity(array $identity): ?array
    {
        foreach (['account_key', 'target_hash', 'post_key'] as $key) {
            if (!is_string($identity[$key] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $identity[$key]) !== 1) return null;
        }
        if (!array_key_exists('comment_created_at', $identity)) return null;
        $created = $identity['comment_created_at'];
        try {
            if ($created instanceof DateTimeInterface) $created = DateTimeImmutable::createFromInterface($created);
            elseif (is_string($created) && $created !== '' && strlen($created) <= 40) $created = new DateTimeImmutable($created, new DateTimeZone('UTC'));
            elseif ($created !== null) return null;
            if ($created !== null) $created = $created->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $error) { return null; }
        return ['account_key' => $identity['account_key'], 'target_hash' => $identity['target_hash'],
            'post_key' => $identity['post_key'], 'comment_created_at' => $created];
    }

    private function validReason(?string $reason): bool
    {
        return $reason === null || preg_match('/\A[A-Z][A-Z0-9_]{0,39}\z/', $reason) === 1;
    }

    private function validUuid($value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value) === 1;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
