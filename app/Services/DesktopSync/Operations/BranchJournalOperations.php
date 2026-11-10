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
 * DesktopSyncOperations: What a branch records during the day: expenses and their reviews, attendance, employee entries,
 * shift closings, stock received and the till.
 */
trait BranchJournalOperations
{
    /**
     * An expense paid from the drawer, recorded on the day it happened. The dashboard's own expense rules
     * apply (categories, stock purchases in kilograms, who may approve); the operation id makes it once-only.
     */
    private function expense(User $actor, string $branch, string $id, Carbon $when, array $data): array
    {
        $data = Validator::make($data, [
            'category' => 'required|string|max:60', 'description' => 'nullable|string|max:500', 'amount' => 'required|string|max:14',
            'supplier' => 'nullable|string|max:150', 'notes' => 'nullable|string|max:1000', 'quantity_kg' => 'nullable|string|max:14',
            'approve' => 'nullable|boolean',
        ])->validate();
        // The dashboard's expenses page may have reached the server just before the connection dropped; the device
        // then saved the same expense under the same operation number. The dashboard already has it: never twice.
        $command = DB::table('branch_expense_commands')->where('branch', $branch)->where('actor_id', $actor->id)->where('request_key', $id)->first(['expense_id']);
        if ($command) {
            $expense = app(BranchExpenses::class)->show((int) $command->expense_id, $actor)['expense'];
            return ['expense' => ['id' => (int) $expense['id'], 'number' => (string) $expense['number'], 'status' => (string) $expense['status'], 'already_saved' => true]];
        }
        $result = app(BranchExpenses::class)->save([
            'branch' => $branch, 'idempotency_key' => $id, 'occurred_on' => OperatingDay::date($when), 'category' => $data['category'],
            'description' => $data['description'] ?? '', 'amount' => $data['amount'], 'payment_method' => 'cash',
            'supplier' => $data['supplier'] ?? '', 'notes' => $data['notes'] ?? '',
            'quantity_kg' => ($data['quantity_kg'] ?? '') === '' ? null : $data['quantity_kg'], 'approve' => (bool) ($data['approve'] ?? false),
        ], $actor);
        $expense = $result['expense'];
        return ['expense' => ['id' => (int) $expense['id'], 'number' => (string) $expense['number'], 'status' => (string) $expense['status']]];
    }

    /**
     * An expense approved, rejected or cancelled on the device's expenses page while offline: the dashboard's own review
     * (BranchExpenses::review: who may review, the expense's state and revision, cash from the drawer, stock reversed)
     * under the page's operation number. An expense saved on the device is found by the operation that saved it (sent
     * before this one); its review applies to it as the dashboard has it now.
     */
    private function expenseReview(User $actor, string $branch, string $id, array $data): array
    {
        $data = Validator::make($data, [
            'expense_id' => 'nullable|integer|min:1|required_without:expense_key', 'expense_key' => 'nullable|uuid|required_without:expense_id',
            'action' => 'required|in:approve,reject,void', 'reason' => 'nullable|string|max:500', 'expected_revision' => 'nullable|integer|min:1',
        ])->validate();
        $expenseId = (int) ($data['expense_id'] ?? 0);
        $revision = $data['expected_revision'] ?? null;
        if (! $expenseId) {
            $expenseId = (int) DB::table('branch_expense_commands')->where('branch', $branch)->where('request_key', $data['expense_key'])
                ->where('kind', 'create')->value('expense_id');
            abort_unless($expenseId > 0, 409, 'المصروف ده ما وصلش للداشبورد، فمراجعته ما اتطبقتش.');
        }
        if ($revision === null) $revision = (int) DB::table('branch_expenses')->where('branch', $branch)->where('id', $expenseId)->value('revision');
        $result = app(BranchExpenses::class)->review($expenseId, ['branch' => $branch, 'idempotency_key' => $id, 'expected_revision' => $revision ?: null,
            'action' => $data['action'], 'reason' => $data['reason'] ?? ''], $actor);
        $expense = $result['expense'];
        return ['expense' => ['id' => (int) $expense['id'], 'number' => (string) $expense['number'], 'status' => (string) $expense['status'],
            'revision' => (int) $expense['revision']]];
    }

    /**
     * $status: the employees page's leave or absence for the day (set_status), recorded on the device; like a punch, the
     * dashboard keeps the first status of the day and replaces an automatic absence the device's record was too late for.
     */
    private function attendance(User $actor, string $branch, string $id, Carbon $when, array $data, bool $status = false): array
    {
        $data = Validator::make($data, [
            'employee_id' => 'required|integer|min:1', 'day' => 'required|date_format:Y-m-d',
            'status' => $status ? 'required|in:preapproved_leave,unauthorized_absence' : 'required|in:morning,evening,part_time',
            'action' => $status ? 'required|in:set_status' : 'required|in:check_in,check_out',
            'notes' => 'nullable|string|max:1000',
        ])->validate();
        if (trim((string) ($data['notes'] ?? '')) === '') unset($data['notes']);
        // The dashboard's employees page may have reached the server just before the connection dropped; the device
        // then saved the same punch under the same operation number. The dashboard already has it: never twice.
        $command = DB::table('branch_operation_commands')->where('branch', $branch)->where('actor_id', $actor->id)->where('request_key', $id)->first(['result']);
        $result = $command ? (json_decode($command->result, true) ?: []) : null;
        if (! $result || empty($result['attendance'])) {
            $result = app(BranchPayroll::class)->attendance(['branch' => $branch, 'idempotency_key' => $id] + $data, $actor, $when);
        }
        $day = $result['attendance'];
        $time = fn ($value) => $value ? Carbon::parse($value, 'UTC')->toIso8601String() : null;
        return ['attendance' => ['employee_id' => (int) $day['employee_id'], 'day' => (string) $day['day'], 'status' => (string) $day['status'],
            'checked_in_at' => $time($day['checked_in_at']), 'checked_out_at' => $time($day['checked_out_at'])]];
    }

    /**
     * A deduction, bonus or advance recorded on the employees page while the internet was down: the dashboard's own
     * entry (BranchPayroll::entry, its checks: a bonus stays the owner's, a closed month refuses it) under the page's
     * operation number. An entry the page saved online before the connection dropped is the same entry, never twice.
     */
    private function employeeEntry(User $actor, string $branch, string $id, array $data): array
    {
        $data = Validator::make($data, ['employee_id' => 'required|integer|min:1', 'day' => 'required|date_format:Y-m-d',
            'kind' => 'required|in:bonus,deduction,advance', 'amount' => 'required|string|max:14', 'reason' => 'required|string|max:500',
            'notes' => 'nullable|string|max:1000'])->validate();
        if (trim((string) ($data['notes'] ?? '')) === '') unset($data['notes']);
        $command = DB::table('branch_operation_commands')->where('branch', $branch)->where('actor_id', $actor->id)->where('request_key', $id)->first(['result']);
        $result = $command ? (json_decode($command->result, true) ?: []) : null;
        if (! $result || empty($result['entry'])) {
            $result = app(BranchPayroll::class)->entry(['branch' => $branch, 'idempotency_key' => $id] + $data, $actor);
        }
        $entry = $result['entry'];
        return ['entry' => ['id' => (int) $entry['id'], 'employee_id' => (int) $entry['employee_id'], 'day' => (string) $entry['day'],
            'kind' => (string) $entry['kind'], 'amount_cents' => (int) $entry['amount_cents']]];
    }

    /**
     * The shift an owner or admin closed on the device while the internet was down: the dashboard's own close
     * (BranchShiftClosing::close, its checks, report and branch printing). The device sends it alone, once everything it
     * recorded before it is in and before anything it recorded after it, so the shift counts exactly those; it is dated
     * when the device closed it. Another closing since, an invoice still open or an expense waiting for review refuses
     * it, as on the page. A close the page saved online before the connection dropped is the same closing.
     */
    private function shiftClose(User $actor, string $branch, string $id, Carbon $when, array $data): array
    {
        $data = Validator::make($data, ['previous_closing_id' => 'required|integer|min:0', 'counted_cash' => 'required|string|max:14',
            'notes' => 'nullable|string|max:1000'])->validate();
        $shifts = app(BranchShiftClosing::class);
        $done = $shifts->recover(['branch' => $branch, 'idempotency_key' => $id], $actor);
        if (! empty($done['found'])) return ['closing' => $done['closing']];
        return DB::transaction(function () use ($shifts, $actor, $branch, $id, $when, $data) {
            $current = $shifts->data(['branch' => $branch], $actor);
            abort_unless((int) $current['previous_closing_id'] === (int) $data['previous_closing_id'], 409,
                'تم إغلاق وردية أخرى قبل وصول قفلة الجهاز. راجع الوردية من الداشبورد.');
            $closing = $shifts->close(['branch' => $branch, 'idempotency_key' => $id, 'previous_closing_id' => $current['previous_closing_id'],
                'review_token' => $current['review_token'], 'counted_cash' => $data['counted_cash'], 'notes' => $data['notes'] ?? ''], $actor)['closing'];
            // Closed when the device closed it: never before the shift started, never after it reached the dashboard.
            $row = DB::table('branch_shift_closings')->where('id', $closing['id'])->first();
            $at = $when->copy()->setTimezone('UTC');
            if ($at->lt(Carbon::parse($row->closed_at, 'UTC')) && $at->gt(Carbon::parse($row->started_at, 'UTC'))) {
                $snapshot = json_decode($row->snapshot, true);
                $snapshot['closed_at'] = $at->copy()->setTimezone('Africa/Cairo')->format('Y-m-d H:i:s');
                DB::table('branch_shift_closings')->where('id', $row->id)
                    ->update(['closed_at' => $at->toDateTimeString(), 'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
            }
            return ['closing' => $shifts->recover(['branch' => $branch, 'idempotency_key' => $id], $actor)['closing']];
        });
    }

    /**
     * Goods received on the device's "إضافة بضاعة" page: the dashboard's own receipt (BranchStock::receive, its checks
     * and stock movement) under the page's operation number, dated when the device recorded it rather than when the
     * sync reached the dashboard. A receipt the page saved online before the connection dropped is the same receipt.
     */
    private function stockReceive(User $actor, string $branch, string $id, Carbon $when, array $data): array
    {
        $data = Validator::make($data, [
            'ingredient_id' => 'required|integer|min:1', 'unit' => 'required|in:kg,piece', 'quantity' => 'required|string|max:16',
            'supplier' => 'nullable|string|max:150', 'notes' => 'nullable|string|max:500',
        ])->validate();
        $command = DB::table('branch_operation_commands')->where('branch', $branch)->where('actor_id', $actor->id)->where('request_key', $id)->first(['result']);
        $result = $command ? (json_decode($command->result, true) ?: []) : null;
        if (! $result || empty($result['receipt'])) {
            $values = ['branch' => $branch, 'idempotency_key' => $id, 'ingredient_id' => (int) $data['ingredient_id'], 'unit' => $data['unit'],
                'quantity' => $data['quantity'], 'supplier' => trim((string) ($data['supplier'] ?? '')), 'notes' => trim((string) ($data['notes'] ?? ''))];
            $result = app(BranchStock::class)->receive($values, $actor);
            if (empty($result['replayed']) && ! empty($result['receipt']['id']) && isset($result['receipt']['ingredient_id']) && Schema::hasTable('branch_inventory_movements')) {
                DB::table('branch_inventory_movements')->where('id', (int) $result['receipt']['id'])->where('source_id', $actor->id.':'.$id)
                    ->update(['created_at' => $when->copy()->setTimezone('UTC')->toDateTimeString()]);
            }
        }
        return ['receipt' => $result['receipt'], 'stock' => $result['stock'] ?? null];
    }

    /**
     * Cash put in or taken out of the drawer by a manager. The device could not know the drawer's latest
     * revision while offline, so the movement applies on top of the current drawer, under the same locks
     * and balance rules as the dashboard.
     */
    private function till(User $actor, string $branch, string $id, array $data): array
    {
        $data = Validator::make($data, [
            'direction' => 'required|in:in,out', 'amount' => 'required|string|max:14', 'note' => 'required|string|max:500',
        ])->validate();
        return DB::transaction(function () use ($actor, $branch, $id, $data) {
            $this->access->branch($branch, $actor, true);
            $now = now('UTC');
            DB::table('takeaway_tills')->insertOrIgnore(['branch' => $branch, 'balance_cents' => 0, 'tax_bps' => 0, 'revision' => 1,
                'created_at' => $now, 'updated_at' => $now]);
            $till = DB::table('takeaway_tills')->where('branch', $branch)->lockForUpdate()->first();
            $result = app(TakeawayService::class)->changeRegister(['branch' => $branch, 'idempotency_key' => $id,
                'expected_revision' => (int) $till->revision, 'note' => $data['note'], 'direction' => $data['direction'],
                'amount' => $data['amount']], $actor, false);
            $entry = DB::table('takeaway_till_entries')->where('till_id', $till->id)->where('actor_id', $actor->id)
                ->where('request_key', $id)->first(['id', 'business_date']);
            return ['till' => ['id' => (int) ($entry->id ?? 0), 'business_date' => (string) ($entry->business_date ?? ''),
                'replayed' => (bool) ($result['replayed'] ?? false)]];
        }, 3);
    }
}
