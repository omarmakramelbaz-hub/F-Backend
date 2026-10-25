<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Website cards operate their original phone ticket; they never create a second sale. */
class WebsiteOrderBoardActions
{
    private array $companyChoices = [];

    public function __construct(
        private BranchOperations $ops,
        private PosServiceTicket $tickets,
        private PhoneDeliveryBoard $delivery
    ) {}

    public function available(object $row, $actor): array
    {
        if (!$this->ops->access->permissions($actor)['can_checkout']
            || ($row->channel ?? '') !== 'phone' || ($row->payment_status ?? '') !== 'unpaid') return [];
        $locked = !empty($row->bill_issued_at) || ($row->status ?? '') === 'awaiting_bill';
        if ($row->status === 'new' && !$locked) {
            return empty($row->last_kitchen_id) ? ['accept', 'reject'] : ['accept'];
        }
        if ($row->status === 'preparing' || ($row->status === 'new' && $locked)) return ['dispatch'];
        if (in_array($row->status, ['out_for_delivery', 'finished'], true)) return ['complete'];
        return [];
    }

    public function companies(object $row, $actor): array
    {
        if (!in_array('dispatch', $this->available($row, $actor), true)
            || !Schema::hasTable('branch_delivery_companies')) return [];
        $this->ops->access->branch($row->branch, $actor);
        return $this->companyChoices[$row->branch] ??= DB::table('branch_delivery_companies')->where('branch', $row->branch)->where('active', true)
            ->orderBy('name')->get(['id', 'name'])->map(fn ($company) => [
                'id'=>(int) $company->id, 'name'=>(string) $company->name,
            ])->all();
    }

    public function transition(int $id, array $values, $actor, OrderBoardService $board): array
    {
        $v = Validator::make($values, [
            'action'=>'required|in:accept,reject,dispatch,complete',
            'expected_status'=>'required|string|max:32', 'expected_revision'=>'required|integer|min:1',
            'idempotency_key'=>'required|uuid', 'reason'=>'nullable|string|max:500',
            'company_id'=>'nullable|integer|min:1', 'quote_hash'=>'nullable|string|size:64',
            'cash_received'=>'nullable|string|max:14', 'payment_confirmed'=>'nullable|boolean',
        ])->validate();
        // Scope is checked before even looking up an idempotency receipt.
        $hint = $board->scopedRow('website', $id, $actor);
        $v['branch'] = $hint->branch;
        $v['ticket_id'] = $id;
        return $this->ops->write('website.board.action', $v, $actor, function ($branch, $actor) use ($board, $id, $v) {
            $row = $board->scopedRow('website', $id, $actor, true);
            abort_unless($row->status === $v['expected_status'] && (int) $row->revision === (int) $v['expected_revision'],
                409, 'تغيرت حالة الطلب. حدّث الصفحة.');
            abort_unless(in_array($v['action'], $this->available($row, $actor), true),
                409, 'الإجراء غير متاح لهذا الطلب.');
            $command = ['branch'=>$row->branch, 'expected_revision'=>(int) $row->revision,
                'idempotency_key'=>self::innerKey($v['idempotency_key'], $v['action'])];
            if ($v['action'] === 'accept') {
                // The customer's website order is authority to prepare it, not marketing consent.
                $this->tickets->action('phone', $id, $command + ['action'=>'send_kitchen'], $actor);
            } elseif ($v['action'] === 'reject') {
                $this->tickets->action('phone', $id, $command + ['action'=>'cancel',
                    'reason'=>trim($v['reason'] ?? '') ?: 'رفض طلب الموقع'], $actor);
            } elseif ($v['action'] === 'dispatch') {
                abort_unless(!empty($v['company_id']), 422, 'اختر شركة التوصيل أولًا.');
                $this->delivery->dispatch($command + ['ticket_id'=>$id, 'company_id'=>(int) $v['company_id']], $actor);
            } else {
                abort_unless(in_array($v['payment_confirmed'] ?? null, [true, 1, '1'], true),
                    422, 'أكد تحصيل قيمة الطلب فعليًا قبل إنهائه.');
                abort_unless(!empty($v['quote_hash']), 422, 'راجع إجمالي الطلب قبل التحصيل.');
                $this->tickets->settle('phone', $id, $command + ['quote_hash'=>$v['quote_hash'],
                    'payment_method'=>'cash', 'cash_received'=>$v['cash_received'] ?? '',
                    'payment_confirmed'=>true], $actor);
            }
            return ['ticket_id'=>$id, 'action'=>$v['action']];
        });
    }

    private static function innerKey(string $key, string $action): string
    {
        $hex = hash('sha256', 'website.board.action|'.$key.'|'.$action);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
