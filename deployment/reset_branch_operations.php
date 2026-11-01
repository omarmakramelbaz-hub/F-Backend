<?php
// Clears the branches' trial operations so real work starts from zero: the cashier, dine-in and delivery sales with
// their prints, kitchen tickets and order numbers; expenses; the cash drawers and shift closings; and the stock balances
// with their movements. Kept: the customer app's orders (and their numbering), menus, tables, customers, employees,
// salaries, settings and the desktop devices. With --employees, attendance days and employee deductions, bonuses and
// advances are cleared too.
//
// Run as the site user by deployment/reset_branch_operations_root.py, which keeps the backup and runs, in order:
//   plan            what would change, counts only
//   export          every row it would change (the backup), with a fingerprint
//   apply           clears and zeroes in one transaction, only if the tables still match the backup's fingerprint
//   restore         puts a backup back, only while the cleared tables are still empty
declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class GoErpResetStop extends RuntimeException {}

final class GoErpBranchReset
{
    /** Cleared completely, each before the tables it points to. */
    public const CLEARED = ['pos_service_kitchen_tickets', 'pos_service_commands', 'phone_delivery_batch_items', 'phone_delivery_batches',
        'phone_delivery_dispatches', 'pos_branch_print_jobs', 'pos_service_tickets', 'branch_recipe_sales', 'takeaway_till_entries',
        'takeaway_order_items', 'takeaway_orders', 'branch_shift_print_jobs', 'branch_shift_sources', 'branch_shift_closings',
        'expense_inventory_receipts', 'branch_expense_commands', 'branch_expenses', 'branch_stock_movements', 'branch_inventory_movements',
        'branch_stock_minimum_alerts'];
    /** With --employees: the attendance days and money movements of employees (employees and salaries stay). */
    public const EMPLOYEES = ['branch_employee_entries', 'branch_employee_days'];
    /** Kept rows put back to zero: the drawers' balances, the stock balances, and every table free. */
    public const ZEROED = ['takeaway_tills' => ['balance_cents', 0], 'branch_stock' => ['quantity_units', 0],
        'branch_inventory' => ['quantity_units', 0], 'pos_service_tables' => ['active_ticket_id', null]];
    /** Order numbers that start again; the app's keep counting, its orders are kept. */
    public const CHANNELS = ['takeaway', 'dine', 'phone'];

    private bool $employees;

    public function __construct(bool $employees = false)
    {
        $this->employees = $employees;
    }

    /** The cleared tables that exist here, in deletion order. */
    private function cleared(): array
    {
        $tables = array_merge($this->employees ? self::EMPLOYEES : [], self::CLEARED);
        return array_values(array_filter($tables, fn ($table) => Schema::hasTable($table)));
    }

    private function zeroed(): array
    {
        return array_filter(self::ZEROED, fn ($spec, $table) => Schema::hasTable($table) && Schema::hasColumn($table, $spec[0]), ARRAY_FILTER_USE_BOTH);
    }

    private function sequences(): ?\Illuminate\Database\Query\Builder
    {
        return Schema::hasTable('branch_order_sequences') ? DB::table('branch_order_sequences')->whereIn('channel', self::CHANNELS) : null;
    }

    /** What would change: counts, first and last record, per branch for the main records. Nothing is changed. */
    public function plan(): array
    {
        $tables = [];
        foreach ($this->cleared() as $table) {
            $row = ['rows' => DB::table($table)->count()];
            if ($row['rows'] && Schema::hasColumn($table, 'created_at')) {
                $row['first'] = DB::table($table)->min('created_at');
                $row['last'] = DB::table($table)->max('created_at');
            }
            if ($row['rows'] && in_array($table, ['takeaway_orders', 'pos_service_tickets', 'branch_expenses', 'branch_shift_closings'], true)) {
                $row['branches'] = DB::table($table)->select('branch', DB::raw('COUNT(*) AS c'))->groupBy('branch')->pluck('c', 'branch')
                    ->map(fn ($count) => (int) $count)->all();
            }
            $tables[$table] = $row;
        }
        foreach (['takeaway_orders' => 'total_cents', 'branch_expenses' => 'amount_cents'] as $table => $column) {
            if (isset($tables[$table]) && $tables[$table]['rows'] && Schema::hasColumn($table, $column)) {
                $tables[$table]['total'] = self::money((int) DB::table($table)->sum($column));
            }
        }
        $zeroed = [];
        foreach ($this->zeroed() as $table => [$column, $value]) {
            $query = DB::table($table)->whereNotNull($column)->where($column, '!=', 0);
            $zeroed[$table] = ['rows' => DB::table($table)->count(), 'to_change' => (clone $query)->count()];
            if ($table === 'takeaway_tills') {
                $zeroed[$table]['drawers'] = DB::table($table)->pluck('balance_cents', 'branch')->map(fn ($cents) => self::money((int) $cents))->all();
            }
        }
        $kept = [];
        foreach (['orders' => 'customer app orders', 'branch_customers' => 'customers', 'branch_employees' => 'employees',
            'pos_service_tables' => 'tables', 'desktop_sync_operations' => 'device operations (never applied twice)'] as $table => $label) {
            if (Schema::hasTable($table)) $kept[$label] = DB::table($table)->count();
        }
        if (! $this->employees) {
            foreach (self::EMPLOYEES as $table) if (Schema::hasTable($table)) $kept[$table.' (add --employees to clear)'] = DB::table($table)->count();
        }
        $sequences = $this->sequences();
        return ['cleared' => $tables, 'zeroed' => $zeroed, 'order_numbers_restarting' => $sequences ? $sequences->count() : 0, 'kept' => $kept,
            'missing' => array_values(array_diff(array_merge($this->employees ? self::EMPLOYEES : [], self::CLEARED), $this->cleared()))];
    }

    /** Proves the backup holds exactly what is cleared: rows and highest number of every table, and the zeroed values. */
    public function fingerprint(): array
    {
        $print = [];
        foreach ($this->cleared() as $table) {
            $print[$table] = [DB::table($table)->count(), (int) DB::table($table)->max('id')];
        }
        foreach ($this->zeroed() as $table => [$column]) {
            $print[$table.'.'.$column] = [DB::table($table)->count(), (string) DB::table($table)->whereNotNull($column)->sum($column),
                DB::table($table)->whereNotNull($column)->count()];
        }
        $sequences = $this->sequences();
        if ($sequences) $print['branch_order_sequences'] = [(clone $sequences)->count(), (string) (clone $sequences)->sum('last_number')];
        return $print;
    }

    /** Every row the reset changes, to keep before it runs. */
    public function export(): array
    {
        $tables = [];
        foreach ($this->cleared() as $table) {
            $tables[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $zeroed = [];
        foreach ($this->zeroed() as $table => [$column]) {
            $zeroed[$table] = ['column' => $column, 'values' => DB::table($table)->orderBy('id')->pluck($column, 'id')->all()];
        }
        $sequences = $this->sequences();
        return ['format' => 1, 'employees' => $this->employees, 'tables' => $tables, 'zeroed' => $zeroed,
            'sequences' => $sequences ? (clone $sequences)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all() : [],
            'fingerprint' => $this->fingerprint()];
    }

    /** Clears and zeroes in one transaction, only if nothing changed since the backup was taken. */
    public function apply(array $expected): array
    {
        return DB::transaction(function () use ($expected) {
            // Locked first: no sale, expense or stock movement can slip in between the check and the clearing.
            foreach ($this->cleared() as $table) DB::table($table)->lockForUpdate()->pluck('id');
            foreach (array_keys($this->zeroed()) as $table) DB::table($table)->lockForUpdate()->pluck('id');
            if ($sequences = $this->sequences()) $sequences->lockForUpdate()->pluck('id');
            if ($this->fingerprint() !== $expected) throw new GoErpResetStop('CHANGED_SINCE_BACKUP');
            $deleted = [];
            foreach ($this->cleared() as $table) $deleted[$table] = DB::table($table)->delete();
            $zeroed = [];
            foreach ($this->zeroed() as $table => [$column, $value]) {
                $query = DB::table($table)->whereNotNull($column)->where($column, '!=', 0);
                $changes = [$column => $value];
                if (Schema::hasColumn($table, 'revision')) $changes['revision'] = DB::raw('revision + 1');
                if (Schema::hasColumn($table, 'updated_at')) $changes['updated_at'] = now('UTC');
                $zeroed[$table] = $query->update($changes);
            }
            $restarted = ($sequences = $this->sequences()) ? $sequences->delete() : 0;
            return ['deleted' => $deleted, 'zeroed' => $zeroed, 'order_numbers_restarted' => $restarted];
        }, 1);
    }

    /** Puts a backup back, only while the cleared tables are still empty (before real work recorded anything). */
    public function restore(array $backup): array
    {
        if (($backup['format'] ?? null) !== 1 || ! is_array($backup['tables'] ?? null)) throw new GoErpResetStop('NOT_A_BACKUP');
        $this->employees = (bool) ($backup['employees'] ?? false);
        return DB::transaction(function () use ($backup) {
            foreach (array_keys($backup['tables']) as $table) {
                if (! Schema::hasTable($table)) throw new GoErpResetStop('MISSING_TABLE '.$table);
                if (DB::table($table)->lockForUpdate()->count() > 0) throw new GoErpResetStop('RECORDED_SINCE_RESET '.$table);
            }
            $restored = [];
            // Parents first: the reverse of the clearing order.
            foreach (array_reverse(array_merge($this->employees ? self::EMPLOYEES : [], self::CLEARED)) as $table) {
                if (! isset($backup['tables'][$table])) continue;
                foreach (array_chunk($backup['tables'][$table], 200) as $rows) DB::table($table)->insert($rows);
                $restored[$table] = count($backup['tables'][$table]);
            }
            foreach ($backup['zeroed'] ?? [] as $table => $values) {
                foreach ($values['values'] as $id => $value) DB::table($table)->where('id', (int) $id)->update([$values['column'] => $value]);
            }
            foreach (array_chunk($backup['sequences'] ?? [], 200) as $rows) DB::table('branch_order_sequences')->insertOrIgnore($rows);
            return ['restored' => $restored];
        }, 1);
    }

    private static function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}

// As a script: php reset_branch_operations.php MODE SITE [--employees] (apply and restore read their input on stdin).
if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    $reply = function (array $data, int $code = 0): void {
        fwrite(STDOUT, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
        exit($code);
    };
    $mode = $argv[1] ?? '';
    $site = $argv[2] ?? '';
    if (! in_array($mode, ['plan', 'export', 'apply', 'restore'], true) || ! is_file($site.'/artisan')) $reply(['error' => 'USAGE'], 2);
    try {
        require $site.'/vendor/autoload.php';
        $app = require $site.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $reset = new GoErpBranchReset(in_array('--employees', $argv, true));
        $input = in_array($mode, ['apply', 'restore'], true) ? json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR) : null;
        $reply(match ($mode) {
            'plan' => $reset->plan(),
            'export' => $reset->export(),
            'apply' => $reset->apply($input),
            'restore' => $reset->restore($input),
        });
    } catch (GoErpResetStop $stop) {
        $reply(['error' => $stop->getMessage()], 1);
    } catch (Throwable $error) {
        // Never the database's own message: it can name the database or hold data.
        $reply(['error' => 'FAILED '.get_class($error).' '.(string) $error->getCode()], 1);
    }
}
