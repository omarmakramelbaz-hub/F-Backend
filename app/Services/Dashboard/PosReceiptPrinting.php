<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

/** One initial receipt delivery; repeat copies require a persisted owner/admin role. */
class PosReceiptPrinting
{
    private TakeawayAccess $access;
    private const TTL_SECONDS = 600;

    public function __construct(TakeawayAccess $access) { $this->access = $access; }

    /** Called only while inserting a new sale's original till entry, never on replay. */
    public function newSaleMetadata(object $order, $actor): array
    {
        $actor = $this->authorize($order, $actor);
        abort_unless((int) $order->actor_id === (int) $actor->id
            && $this->access->permissions($actor)['can_checkout'], 403);
        return ['version'=>1, 'nonce'=>bin2hex(random_bytes(16)),
            'expires_at'=>now('UTC')->getTimestamp() + self::TTL_SECONDS, 'consumed_at'=>(DesktopDashboardPrintOwnership::owns('sale_receipt')||DesktopDashboardPrintOwnership::owns('delivery_batch'))?now('UTC')->toIso8601String():null];
    }

    /** Only checkout and recovery responses may expose this URL to the original cashier. */
    public function firstPrintUrl(object $order, $actor): ?string
    {
        $actor = $this->authorize($order, $actor);
        if ((int) $order->actor_id !== (int) $actor->id || !$this->access->permissions($actor)['can_checkout']) return null;
        $entry = $this->entry($order)->first();
        $state = $entry ? $this->state($entry) : null;
        if (!$state) return null;
        $token = $this->token($order, $state);
        return $token === null ? null : route('takeaway.print', ['id'=>$order->id, 'initial_print'=>$token]);
    }

    /** A row lock makes concurrent uses of the same grant mutually exclusive. */
    public function consume(int $id, $actor, ?string $token = null): object
    {
        abort_unless(!app(DesktopDashboardPrintLedger::class)->active((string)config('desktop_dashboard.device_id')),409,'اطبع من جسر البرنامج المحلي لتسجيل صلاحية الطباعة.');
        return DB::transaction(function () use ($id, $actor, $token) {
            $order = DB::table('takeaway_orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            $actor = $this->authorize($order, $actor);
            if ($this->access->canReprintReceipts($actor)) return $order;
            abort_unless((int) $order->actor_id === (int) $actor->id
                && $this->access->permissions($actor)['can_checkout']
                && is_string($token) && preg_match('/\A[0-9a-f]{64}\z/D', $token), 403, 'إعادة طباعة الفاتورة متاحة للمالك والإدارة فقط.');
            $entry = $this->entry($order)->lockForUpdate()->first();
            $state = $entry ? $this->state($entry) : null;
            $expected = $state ? $this->token($order, $state) : null;
            abort_unless($expected !== null && hash_equals($expected, $token), 403, 'انتهت صلاحية الطباعة الأولى أو استُخدمت بالفعل.');
            $metadata = json_decode((string) $entry->metadata, true);
            $metadata['receipt_print']['consumed_at'] = now('UTC')->toIso8601String();
            DB::table('takeaway_till_entries')->where('id', $entry->id)
                ->update(['metadata'=>json_encode($metadata, JSON_UNESCAPED_UNICODE)]);
            return $order;
        }, 3);
    }

    /** Internal grant state is never included in ordinary till/history metadata. */
    public static function publicMetadata(?string $value): ?array
    {
        $metadata = json_decode($value ?? 'null', true);
        if (!is_array($metadata)) return null;
        unset($metadata['receipt_print']);
        return $metadata;
    }

    private function authorize(object $order, $actor)
    {
        $actor = $this->access->actor($actor);
        $this->access->branch((string) $order->branch, $actor);
        return $actor;
    }

    private function entry(object $order)
    {
        return DB::table('takeaway_till_entries')->where('order_id', $order->id)->where('kind', 'sale')
            ->where('branch', $order->branch)->where('actor_id', $order->actor_id);
    }

    private function state(object $entry): ?array
    {
        $metadata = json_decode((string) $entry->metadata, true);
        $state = is_array($metadata) ? ($metadata['receipt_print'] ?? null) : null;
        if (!is_array($state) || ($state['version'] ?? null) !== 1
            || !is_string($state['nonce'] ?? null) || !preg_match('/\A[0-9a-f]{32}\z/D', $state['nonce'])
            || !is_int($state['expires_at'] ?? null) || $state['expires_at'] <= now('UTC')->getTimestamp()
            || !array_key_exists('consumed_at', $state) || $state['consumed_at'] !== null) return null;
        return $state;
    }

    private function token(object $order, array $state): ?string
    {
        $key = config('app.key');
        if (!is_string($key) || $key === '') return null;
        if (str_starts_with($key, 'base64:')) $key = base64_decode(substr($key, 7), true);
        if (!is_string($key) || strlen($key) < 16) return null;
        // Only the stored nonce is public metadata; the app key never leaves the server.
        $payload = json_encode(['pos-receipt-first:v1', (int) $order->id, (string) $order->branch,
            (int) $order->actor_id, (string) $order->request_key, $state['expires_at'], $state['nonce']]);
        return hash_hmac('sha256', $payload, $key);
    }
}
