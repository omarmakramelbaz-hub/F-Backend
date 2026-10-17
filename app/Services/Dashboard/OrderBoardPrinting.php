<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Records invoice issuance; kitchen slips and financial posting are independent. */
class OrderBoardPrinting
{
    private const KIND = 'app.order.print';
    private TakeawayAccess $access;
    private ?bool $schemaReady = null;

    public function __construct(TakeawayAccess $access) { $this->access = $access; }

    public function ready(): bool
    {
        if ($this->schemaReady === null) {
            $this->schemaReady = Schema::hasTable('branch_operation_commands') && Schema::hasColumns('branch_operation_commands',
                ['branch', 'actor_id', 'request_key', 'request_hash', 'kind', 'result', 'created_at', 'updated_at']);
        }
        return $this->schemaReady;
    }

    /** Display only: authorization is repeated against the locked persisted order in issue(). */
    public function allowed(string $source, object $row, $actor): bool
    {
        if (!$this->ready()) return false;
        if ($this->access->canReprintReceipts($actor)) return true;
        return !$this->ended($source, $row) && !$this->printed($source, $row);
    }

    public function printed(string $source, object $row, bool $lock = false): bool
    {
        abort_unless($this->ready(), 503, 'تسجيل طباعة الفواتير غير مجهز.');
        [$branch, $key, $identity] = $this->identity($source, $row);
        // The first actor owns the audit entry, but its lock applies to every cashier.
        $query = DB::table('branch_operation_commands')->where('branch', $branch)
            ->where('request_key', $key)->where('kind', self::KIND);
        // A current read is essential after waiting for the source-row lock under MySQL REPEATABLE READ.
        if ($lock) $query->lockForUpdate();
        $markers = $query->get();
        foreach ($markers as $marker) {
            $result = json_decode((string) $marker->result, true);
            abort_unless(hash_equals(hash('sha256', $identity), (string) $marker->request_hash)
                && is_array($result) && ($result['source'] ?? null) === $source
                && ($result['order_id'] ?? null) === (int) $row->id
                && ($result['branch'] ?? null) === $branch && !empty($result['issued_at']),
                503, 'سجل طباعة الفاتورة يحتاج مراجعة.');
        }
        return $markers->isNotEmpty();
    }

    /** Both print routes and item saves serialize on the same source order row. */
    public function issue(string $source, int $id, $actor, OrderBoardService $board): void
    {
        DB::transaction(function () use ($source, $id, $actor, $board) {
            $actor = $this->access->actor($actor);
            $row = $board->scopedRow($source, $id, $actor, true);
            abort_unless($this->ready(), 503, 'تسجيل طباعة الفواتير غير مجهز.');
            $printed = $this->printed($source, $row, true);
            abort_unless($this->access->canReprintReceipts($actor) || (!$printed && !$this->ended($source, $row)),
                403, 'إعادة طباعة الفاتورة متاحة للمالك أو الإدارة فقط.');
            if ($printed) return;
            [$branch, $key, $identity] = $this->identity($source, $row);
            DB::table('branch_operation_commands')->insert(['branch'=>$branch, 'actor_id'=>(int) $actor->id,
                'request_key'=>$key, 'request_hash'=>hash('sha256', $identity), 'kind'=>self::KIND,
                'result'=>json_encode(['source'=>$source, 'order_id'=>$id, 'branch'=>$branch,
                    'issued_at'=>now('UTC')->toIso8601String()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at'=>now('UTC'), 'updated_at'=>now('UTC')]);
        }, 3);
    }

    private function ended(string $source, object $row): bool
    {
        // Unknown legacy statuses are displayed as ended and must not grant a fresh branch print.
        $active = ['legacy'=>['pending', 'new_order', 'another_delegate', 'accepted', 'shipped'],
            'store'=>['pending', 'awaiting_payment', 'preparing', 'ready', 'out_for_delivery'],
            'service'=>['searching', 'booked', 'in_progress', 'awaiting_confirmation', 'disputed'],
            'partner_service'=>['pending', 'accepted']];
        return !in_array((string) ($row->status ?? ''), $active[$source] ?? [], true);
    }

    private function identity(string $source, object $row): array
    {
        $branch = $source === 'legacy' ? 'f:'.(int) ($row->resturant_id ?? 0)
            : ($source === 'store' ? 'gs:'.(int) ($row->store_id ?? 0) : 'app:'.$source);
        $identity = self::KIND.'|'.$source.'|'.(int) $row->id.'|'.$branch;
        $hex = substr(hash('sha256', $identity), 0, 32);
        $key = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
        return [$branch, $key, $identity];
    }
}
