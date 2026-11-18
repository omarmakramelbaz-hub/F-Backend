<?php

namespace App\Services\DesktopSync\Operations;

use App\Models\User;
use App\Services\Dashboard\BranchExpenses;
use App\Services\Dashboard\BranchPayroll;
use App\Services\Dashboard\BranchShiftClosing;
use App\Services\Dashboard\BranchStock;
use App\Services\Dashboard\CustomerDirectory;
use App\Services\Dashboard\DesktopPosQuote;
use App\Services\Dashboard\OperatingDay;
use App\Services\Dashboard\PosServiceTicket;
use App\Services\Dashboard\TakeawayAccess;
use App\Services\Dashboard\TakeawayService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * DesktopSyncOperations: Dine-in and delivery orders taken on the device: open, save, settle, act on, dispatch, batch,
 * and the delivery customers saved with them.
 */
trait ServiceTicketOperations
{
    /**
     * A dine-in or delivery order that was open on the dashboard, paid on the device while offline. The dashboard's own
     * settle applies (the order's saved total, its revision, the cash register); a page payment that reached the
     * dashboard before the connection dropped (same operation number) is the same payment.
     */
    private function ticketSettle(User $actor, string $branch, string $id, array $data): array
    {
        $data = Validator::make($data, ['channel' => 'required|in:dine,phone', 'ticket_id' => 'required|integer|min:1',
            'expected_revision' => 'required|integer|min:1', 'quote_hash' => 'required|string|size:64', 'cash_received' => 'required|string|max:14',
            'customer_phone' => 'nullable|string|max:30', 'service_consent' => 'nullable|boolean', 'expect' => 'nullable|array'])->validate();
        $tickets = app(PosServiceTicket::class);
        $actor = $this->access->actor($actor);
        $command = DB::table('pos_service_commands')->where('branch', $branch)->where('actor_id', $actor->id)->where('request_key', $id)->first();
        if ($command) {
            $ticket = $tickets->show($data['channel'], (int) $command->ticket_id, $actor)['ticket'];
            $order = DB::table('pos_service_tickets')->where('id', $command->ticket_id)->value('paid_order_id');
            return ['ticket' => ['id' => (int) $ticket['id'], 'revision' => (int) $ticket['revision'], 'status' => (string) $ticket['status']],
                'receipt' => $order ? $this->receipt((int) $order, $actor) : null, 'already_saved' => true];
        }
        // The order may have moved on since the device's copy (its bill printed, sent to the kitchen) without changing what it
        // costs: the payment then applies to the order as it is now. A different amount is never collected silently.
        $revision = (int) $data['expected_revision'];
        $row = DB::table('pos_service_tickets')->where('branch', $branch)->where('channel', $data['channel'])->where('id', (int) $data['ticket_id'])->first();
        if ($row && $row->payment_status === 'unpaid' && $row->status !== 'cancelled') {
            $saved = (string) (json_decode($row->quote_snapshot, true)['quote_hash'] ?? '');
            if (hash_equals($saved, $data['quote_hash'])) $revision = (int) $row->revision;
            // Changed on the device first (more items): the dashboard priced that change itself, so the payment applies when
            // the order has the same items and the same amount the device collected.
            elseif (! empty($data['expect']) && $this->sameOrder($row, $data['expect'])) {
                $revision = (int) $row->revision;
                $data['quote_hash'] = $saved;
            }
        }
        $values = ['branch' => $branch, 'idempotency_key' => $id, 'expected_revision' => $revision, 'quote_hash' => $data['quote_hash'],
            'payment_method' => 'cash', 'cash_received' => $data['cash_received'], 'payment_confirmed' => true];
        if ($data['channel'] === 'dine') $values += ['customer_phone' => $data['customer_phone'] ?? null, 'service_consent' => (bool) ($data['service_consent'] ?? false)];
        $result = $tickets->settle($data['channel'], (int) $data['ticket_id'], $values, $actor, true);
        return ['ticket' => ['id' => (int) $result['ticket']['id'], 'revision' => (int) $result['ticket']['revision'], 'status' => (string) $result['ticket']['status']],
            'receipt' => $this->receipt((int) $result['receipt']['id'], $actor)];
    }

    /**
     * A dine-in order opened on the device while offline that is still unpaid when the internet is back: it becomes a
     * dashboard order at today's prices (it was not paid yet), on its table, with what the device already did (sent to
     * the kitchen, bill printed), so it continues on the dashboard like any other order.
     */
    private function ticketOpen(User $actor, string $branch, string $id, array $data): array
    {
        if (($data['channel'] ?? 'dine') === 'phone') return $this->phoneOpen($actor, $branch, $id, $data);
        $data = Validator::make($data, ['table_id' => 'required|integer|min:1', 'waiter_name' => 'required|string|max:100',
            'guest_count' => 'required|integer|min:1|max:200', 'customer_name' => 'nullable|string|max:100', 'customer_phone' => 'nullable|string|max:30',
            'items' => 'required|array|min:1|max:100', 'discount' => 'nullable|string|max:14', 'discount_reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:500', 'kitchen_sent' => 'nullable|boolean', 'bill_requested' => 'nullable|boolean'])->validate();
        $tickets = app(PosServiceTicket::class);
        $actor = $this->access->actor($actor);
        $command = DB::table('pos_service_commands')->where('branch', $branch)->where('actor_id', $actor->id)->where('request_key', $id)->first();
        if (! $command) {
            $cart = ['branch' => $branch, 'items' => $data['items'], 'discount' => $data['discount'] ?? '0.00', 'discount_reason' => $data['discount_reason'] ?? ''];
            $quote = $tickets->quote('dine', $cart, $actor);
            $tickets->save('dine', $cart + ['idempotency_key' => $id, 'table_id' => (int) $data['table_id'], 'quote_hash' => $quote['quote_hash'],
                'waiter_name' => $data['waiter_name'], 'guest_count' => (int) $data['guest_count'], 'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => ($data['customer_phone'] ?? '') !== '' ? $data['customer_phone'] : null, 'notes' => $data['notes'] ?? null], $actor);
            $command = DB::table('pos_service_commands')->where('branch', $branch)->where('actor_id', $actor->id)->where('request_key', $id)->first();
        }
        $ticket = $tickets->show('dine', (int) $command->ticket_id, $actor)['ticket'];
        foreach (['send_kitchen' => 'kitchen_sent', 'request_bill' => 'bill_requested'] as $action => $flag) {
            if (empty($data[$flag]) || ($action === 'send_kitchen' && $ticket['kitchen_sent']) || ($action === 'request_bill' && $ticket['bill_locked'])) continue;
            $ticket = $tickets->action('dine', (int) $ticket['id'], ['branch' => $branch, 'idempotency_key' => $this->derivedKey($id, $action),
                'expected_revision' => (int) $ticket['revision'], 'action' => $action], $actor)['ticket'];
        }
        return ['ticket' => ['id' => (int) $ticket['id'], 'revision' => (int) $ticket['revision'], 'status' => (string) $ticket['status'],
            'number' => (string) ($ticket['number'] ?? '')]];
    }

    /**
     * Done on the device while offline to a dine-in or delivery order open on the dashboard: sent to the kitchen, or its
     * bill printed. Applied to the order as it is now when it still costs the same; already done there, nothing repeats.
     */
    private function ticketAction(User $actor, string $branch, string $id, array $data): array
    {
        $data = Validator::make($data, ['channel' => 'required|in:dine,phone', 'ticket_id' => 'required|integer|min:1',
            'action' => 'required|in:send_kitchen,request_bill,cancel', 'quote_hash' => 'required|string|size:64', 'expect' => 'nullable|array',
            'reason' => 'nullable|string|max:500'])->validate();
        abort_unless($data['action'] !== 'send_kitchen' || $data['channel'] === 'dine', 422, 'الإجراء غير متاح لهذا الطلب.');
        if ($data['action'] === 'cancel' && trim($data['reason'] ?? '') === '') throw ValidationException::withMessages(['reason' => 'اكتب سبب الإلغاء.']);
        $tickets = app(PosServiceTicket::class);
        $actor = $this->access->actor($actor);
        $summary = fn (array $ticket, bool $already = false) => ['ticket' => ['id' => (int) $ticket['id'], 'revision' => (int) $ticket['revision'],
            'status' => (string) $ticket['status']]] + ($already ? ['already_saved' => true] : []);
        $command = DB::table('pos_service_commands')->where('branch', $branch)->where('actor_id', $actor->id)->where('request_key', $id)->first();
        if ($command) return $summary($tickets->show($data['channel'], (int) $command->ticket_id, $actor)['ticket'], true);
        $row = DB::table('pos_service_tickets')->where('branch', $branch)->where('channel', $data['channel'])->where('id', (int) $data['ticket_id'])->first();
        abort_unless($row, 404, 'الطلب غير موجود على الداشبورد.');
        $ticket = $tickets->show($data['channel'], (int) $row->id, $actor)['ticket'];
        $done = ['send_kitchen' => $ticket['kitchen_sent'], 'request_bill' => $ticket['bill_locked'], 'cancel' => false][$data['action']];
        if ($done || $ticket['payment_status'] !== 'unpaid' || $ticket['status'] === 'cancelled') return $summary($ticket, true);
        abort_unless(hash_equals((string) $ticket['quote_hash'], $data['quote_hash']) || (! empty($data['expect']) && $this->sameOrder($row, $data['expect'])),
            409, 'تغيّر الطلب على الداشبورد قبل وصول ما تم على الجهاز.');
        $result = $tickets->action($data['channel'], (int) $row->id, ['branch' => $branch, 'idempotency_key' => $id,
            'expected_revision' => (int) $ticket['revision'], 'action' => $data['action']] + ($data['action'] === 'cancel' ? ['reason' => trim($data['reason'])] : []), $actor);
        return $summary($result['ticket']);
    }

    /**
     * A delivery order handed to a delivery company on the device while offline (the delivery board's dispatch). Applied
     * once; an order the dashboard already moved on (handed over, paid, cancelled) is left as it is.
     */
    private function ticketDispatch(User $actor, string $branch, string $id, array $data): array
    {
        $data = Validator::make($data, ['ticket_id' => 'required|integer|min:1', 'company_id' => 'required|integer|min:1'])->validate();
        $actor = $this->access->actor($actor);
        $summary = fn (object $row, bool $already = false) => ['ticket' => ['id' => (int) $row->id, 'revision' => (int) $row->revision,
            'status' => (string) $row->status]] + ($already ? ['already_saved' => true] : []);
        $row = DB::table('pos_service_tickets')->where('branch', $branch)->where('channel', 'phone')->where('id', (int) $data['ticket_id'])->first();
        abort_unless($row, 404, 'الطلب غير موجود على الداشبورد.');
        $done = DB::table('branch_operation_commands')->where('branch', $branch)->where('actor_id', $actor->id)->where('request_key', $id)->exists();
        if ($done || $row->payment_status !== 'unpaid' || ! in_array($row->status, ['new', 'preparing'], true)) return $summary($row, true);
        app(\App\Services\Dashboard\PhoneDeliveryBoard::class)->dispatch(['branch' => $branch, 'idempotency_key' => $id, 'ticket_id' => (int) $row->id,
            'company_id' => (int) $data['company_id'], 'expected_revision' => (int) $row->revision], $actor);
        return $summary(DB::table('pos_service_tickets')->where('id', $row->id)->first());
    }

    /**
     * Delivery orders a courier brought the money for, collected together on the device while offline (the delivery
     * board's batch). The dashboard records the same batch once; the device's copy may be older than the orders (handed
     * over, bill printed since), so each is collected as it is now when it still costs the same, and refused otherwise.
     */
    private function ticketBatch(User $actor, string $branch, string $id, array $data): array
    {
        $data = Validator::make($data, ['items' => 'required|array|min:1|max:50', 'items.*.id' => 'required|integer|min:1|distinct',
            'items.*.quote_hash' => 'required|string|size:64', 'courier_name' => 'nullable|string|max:100', 'total' => 'required|string|max:14',
            'cash_received' => 'required|string|max:14'])->validate();
        $actor = $this->access->actor($actor);
        $summary = fn (array $batch, bool $already = false) => ['batch' => ['id' => (int) $batch['id'], 'total' => (string) $batch['total'],
            'items' => array_map(fn ($line) => ['ticket_id' => (int) $line['ticket_id'], 'order_id' => (int) $line['order_id'], 'total' => (string) $line['total']],
                $batch['items'])]] + ($already ? ['already_saved' => true] : []);
        $done = DB::table('branch_operation_commands')->where('branch', $branch)->where('actor_id', $actor->id)->where('request_key', $id)->first();
        if ($done) return $summary(json_decode($done->result, true)['batch'], true);
        $items = [];
        foreach ($data['items'] as $item) {
            $revision = DB::table('pos_service_tickets')->where('branch', $branch)->where('channel', 'phone')->where('id', (int) $item['id'])->value('revision');
            abort_unless($revision !== null, 404, 'أحد الطلبات غير موجود على الداشبورد.');
            $items[] = ['id' => (int) $item['id'], 'revision' => (int) $revision, 'quote_hash' => $item['quote_hash']];
        }
        $result = app(\App\Services\Dashboard\PhoneDeliveryBoard::class)->finish(['branch' => $branch, 'idempotency_key' => $id, 'items' => $items,
            'courier_name' => $data['courier_name'] ?? '', 'total' => $data['total'], 'payment_method' => 'cash', 'cash_received' => $data['cash_received'],
            'payment_reference' => '', 'payment_confirmed' => true], $actor, true);
        return $summary($result['batch']);
    }

    /**
     * A dine-in order open on the dashboard, changed on the device while offline (more items, guests, notes) before its
     * bill. Applied when the order on the dashboard is still the one the device started from; a changed cart is priced by
     * the dashboard itself, an unchanged one keeps its prices.
     */
    private function ticketSave(User $actor, string $branch, string $id, array $data): array
    {
        $data = Validator::make($data, ['ticket_id' => 'required|integer|min:1', 'base' => 'required|array', 'base.items' => 'required|array|min:1|max:100',
            'items' => 'required|array|min:1|max:100', 'discount' => 'nullable|string|max:14', 'discount_reason' => 'nullable|string|max:500',
            'waiter_name' => 'required|string|max:100', 'guest_count' => 'required|integer|min:1|max:200', 'customer_name' => 'nullable|string|max:100',
            'customer_phone' => 'nullable|string|max:30', 'notes' => 'nullable|string|max:500'])->validate();
        $tickets = app(PosServiceTicket::class);
        $actor = $this->access->actor($actor);
        $summary = fn (array $ticket, bool $already = false) => ['ticket' => ['id' => (int) $ticket['id'], 'revision' => (int) $ticket['revision'],
            'status' => (string) $ticket['status'], 'total' => (string) $ticket['total']]] + ($already ? ['already_saved' => true] : []);
        $command = DB::table('pos_service_commands')->where('branch', $branch)->where('actor_id', $actor->id)->where('request_key', $id)->first();
        if ($command) return $summary($tickets->show('dine', (int) $command->ticket_id, $actor)['ticket'], true);
        $row = DB::table('pos_service_tickets')->where('branch', $branch)->where('channel', 'dine')->where('id', (int) $data['ticket_id'])->first();
        abort_unless($row, 404, 'الطلب غير موجود على الداشبورد.');
        abort_unless($row->payment_status === 'unpaid' && $row->status !== 'cancelled', 409, 'الطلب اتقفل على الداشبورد قبل وصول تعديل الجهاز.');
        abort_unless(empty($row->bill_issued_at) && $row->status !== 'awaiting_bill', 409, 'صدرت فاتورة الدفع على الداشبورد قبل وصول تعديل الجهاز.');
        abort_unless($this->sameCart($row, $data['base']), 409, 'تغيّر الطلب على الداشبورد قبل وصول تعديل الجهاز.');
        $cart = ['branch' => $branch, 'items' => $data['items'], 'discount' => $data['discount'] ?? '0.00', 'discount_reason' => $data['discount_reason'] ?? ''];
        $hash = $this->sameCart($row, $cart) ? (string) (json_decode($row->quote_snapshot, true)['quote_hash'] ?? '') : $tickets->quote('dine', $cart, $actor)['quote_hash'];
        $saved = $tickets->save('dine', $cart + ['idempotency_key' => $id, 'ticket_id' => (int) $row->id, 'expected_revision' => (int) $row->revision,
            'table_id' => (int) $row->table_id, 'quote_hash' => $hash, 'waiter_name' => $data['waiter_name'], 'guest_count' => (int) $data['guest_count'],
            'customer_name' => $data['customer_name'] ?? null, 'customer_phone' => ($data['customer_phone'] ?? '') !== '' ? $data['customer_phone'] : null,
            'notes' => $data['notes'] ?? null], $actor);
        return $summary($saved['ticket']);
    }

    /** The order on the dashboard has these items and discount (lines merged, in any order). */
    private function sameCart(object $row, array $cart): bool
    {
        $sales = app(TakeawayService::class);
        $current = json_decode($row->cart_snapshot, true) ?: [];
        try {
            $mine = $sales->canonicalCart(['branch' => $row->branch, 'items' => $cart['items'] ?? [], 'discount' => $cart['discount'] ?? '0.00',
                'discount_reason' => $cart['discount_reason'] ?? '']);
            $theirs = $sales->canonicalCart(['branch' => $row->branch, 'items' => $current['items'] ?? [], 'discount' => $current['discount'] ?? '0.00',
                'discount_reason' => $current['discount_reason'] ?? '']);
        } catch (ValidationException $error) {
            return false;
        }
        return PosServiceTicket::fingerprint($mine) === PosServiceTicket::fingerprint($theirs);
    }

    /** The same items and the same amount as the device has (after it changed the order). */
    private function sameOrder(object $row, array $expect): bool
    {
        $total = (string) (json_decode($row->quote_snapshot, true)['total'] ?? '');
        return $this->sameCart($row, $expect) && $total !== '' && \App\Services\GoServices\Money::minor($total) === (int) ($expect['total_cents'] ?? -1);
    }

    /**
     * A delivery order placed on the device while offline (located by the customer's coordinates, priced with the device's
     * menu and the branch tariff) and still unpaid when the internet is back: it becomes a dashboard order at today's
     * prices with the delivery fee the device charged, then what the device did follows (handed to the courier, bill).
     */
    private function phoneOpen(User $actor, string $branch, string $id, array $data): array
    {
        $data = Validator::make($data, ['channel' => 'required|in:phone', 'items' => 'required|array|min:1|max:100', 'discount' => 'nullable|string|max:14',
            'discount_reason' => 'nullable|string|max:500', 'customer_name' => 'required|string|max:100', 'customer_phone' => 'required|string|max:30',
            'address' => 'required|string|max:500', 'area' => 'nullable|string|max:150', 'delivery_notes' => 'nullable|string|max:500', 'notes' => 'nullable|string|max:500',
            'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'delivery_fee' => 'required|string|max:14',
            'delivery_company_id' => 'nullable|integer|min:1', 'marketing_consent' => 'nullable|boolean', 'kitchen_sent' => 'nullable|boolean',
            'kitchen_printed' => 'nullable|boolean', 'courier_company_id' => 'nullable|integer|min:1', 'bill_requested' => 'nullable|boolean'])->validate();
        $tickets = app(PosServiceTicket::class);
        $actor = $this->access->actor($actor);
        $command = fn () => DB::table('pos_service_commands')->where('branch', $branch)->where('actor_id', $actor->id)->where('request_key', $id)->first();
        if (! $command()) {
            $delivery = app(\App\Services\Dashboard\PhoneDelivery::class)->quote(['branch' => $branch, 'latitude' => $data['latitude'],
                'longitude' => $data['longitude'], 'location_confirmed' => true], $actor)['delivery'];
            $values = ['branch' => $branch, 'idempotency_key' => $id, 'items' => $data['items'], 'discount' => $data['discount'] ?? '0.00',
                'discount_reason' => $data['discount_reason'] ?? '', 'customer_name' => $data['customer_name'], 'customer_phone' => $data['customer_phone'],
                'address' => $data['address'], 'area' => $data['area'] ?? '', 'delivery_notes' => $data['delivery_notes'] ?? '', 'notes' => $data['notes'] ?? '',
                'latitude' => $delivery['latitude'], 'longitude' => $delivery['longitude'], 'location_confirmed' => true, 'delivery_quote_hash' => $delivery['delivery_quote_hash'],
                'delivery_company_id' => $data['delivery_company_id'] ?? null, 'service_consent' => true, 'marketing_consent' => (bool) ($data['marketing_consent'] ?? false),
                'send_to_kitchen' => (bool) ($data['kitchen_sent'] ?? false)];
            // The customer was charged the device's delivery fee: kept, as a fee set by the cashier when it differs.
            if ($delivery['delivery_fee'] !== $data['delivery_fee']) $values += ['delivery_fee_manual' => true, 'delivery_fee' => $data['delivery_fee']];
            $values['quote_hash'] = $tickets->quote('phone', $values, $actor)['quote_hash'];
            $tickets->save('phone', $values, $actor, true);
            // Its kitchen ticket was printed on the device: the branch printer does not print it a second time.
            if (! empty($data['kitchen_printed']) && Schema::hasTable('pos_branch_print_jobs')) {
                DB::table('pos_branch_print_jobs')->where('branch', $branch)->where('ticket_id', $command()->ticket_id)->where('status', 'pending')
                    ->update(['status' => 'invoked', 'invoked_at' => now('UTC'), 'updated_at' => now('UTC')]);
            }
        }
        $ticket = $tickets->show('phone', (int) $command()->ticket_id, $actor)['ticket'];
        $open = fn () => $ticket['payment_status'] === 'unpaid' && $ticket['status'] !== 'cancelled';
        if (! empty($data['courier_company_id']) && $open() && in_array($ticket['status'], ['new', 'preparing'], true)) {
            app(\App\Services\Dashboard\PhoneDeliveryBoard::class)->dispatch(['branch' => $branch, 'idempotency_key' => $this->derivedKey($id, 'dispatch'),
                'ticket_id' => (int) $ticket['id'], 'company_id' => (int) $data['courier_company_id'], 'expected_revision' => (int) $ticket['revision']], $actor);
            $ticket = $tickets->show('phone', (int) $ticket['id'], $actor)['ticket'];
        }
        if (! empty($data['bill_requested']) && $open() && ! $ticket['bill_locked']) {
            $ticket = $tickets->action('phone', (int) $ticket['id'], ['branch' => $branch, 'idempotency_key' => $this->derivedKey($id, 'request_bill'),
                'expected_revision' => (int) $ticket['revision'], 'action' => 'request_bill'], $actor)['ticket'];
        }
        return ['ticket' => ['id' => (int) $ticket['id'], 'revision' => (int) $ticket['revision'], 'status' => (string) $ticket['status'],
            'number' => (string) ($ticket['number'] ?? '')]];
    }

    /**
     * A delivery customer saved on the device while offline (the delivery page's customer step): the branch's customer
     * record with that number is created or updated, once.
     */
    private function customerSave(User $actor, string $branch, string $id, array $data): array
    {
        $data = Validator::make($data, ['name' => 'required|string|max:100', 'phone' => 'required|string|max:30', 'address' => 'required|string|max:500',
            'area' => 'nullable|string|max:150', 'delivery_notes' => 'nullable|string|max:500', 'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180'])->validate();
        $actor = $this->access->actor($actor);
        $done = DB::table('branch_operation_commands')->where('branch', $branch)->where('actor_id', $actor->id)->where('request_key', $id)->first();
        if ($done) {
            $saved = json_decode($done->result, true)['customer'] ?? [];
            return ['customer' => ['id' => (int) ($saved['id'] ?? 0), 'revision' => (int) ($saved['revision'] ?? 0)], 'already_saved' => true];
        }
        $row = DB::table('branch_customers')->where('branch', $branch)->where('phone_key', \App\Services\Dashboard\BranchCustomers::phoneKey($data['phone']))->first();
        $result = app(\App\Services\Dashboard\BranchCustomers::class)->save(['branch' => $branch, 'idempotency_key' => $id] + $data
            + ($row ? ['customer_id' => (int) $row->id, 'expected_revision' => (int) $row->revision] : []), $actor);
        return ['customer' => ['id' => (int) $result['customer']['id'], 'revision' => (int) $result['customer']['revision']]];
    }
}
