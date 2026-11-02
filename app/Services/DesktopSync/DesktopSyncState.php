<?php

namespace App\Services\DesktopSync;

use App\Models\User;
use App\Services\Dashboard\BranchExpenses;
use App\Services\Dashboard\BranchShiftClosing;
use App\Services\Dashboard\BranchStock;
use App\Services\Dashboard\ExpenseCategories;
use App\Services\Dashboard\OperatingDay;
use App\Services\Dashboard\PosServiceTable;
use App\Services\Dashboard\PosServiceTicket;
use App\Services\Dashboard\TakeawayAccess;
use App\Services\Dashboard\TakeawayCatalog;
use App\Services\Dashboard\TakeawayService;
use App\Services\GoServices\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Validation\ValidationException;

/** What a desktop device keeps offline: who may log in, and each branch's priced menu version. */
class DesktopSyncState
{
    private TakeawayAccess $access;

    public function __construct(TakeawayAccess $access) { $this->access = $access; }

    /**
     * Accounts that may sign in on this device, with their password hashes so the device can verify
     * a login without the internet. Only accounts that can open at least one of the device branches.
     */
    public function users(object $device): array
    {
        $branches = $device->branch_values;
        $restaurants = [];
        $stores = [];
        foreach ($branches as $value) {
            if (preg_match('/^f:([1-9][0-9]*)$/D', $value, $match)) $restaurants[] = (int) $match[1];
            if (preg_match('/^gs:([1-9][0-9]*)$/D', $value, $match)) $stores[] = (int) $match[1];
        }
        $parents = $restaurants && $this->access->has('resturants', 'parent_id')
            ? DB::table('resturants')->whereIn('id', $restaurants)->whereNotNull('parent_id')->pluck('parent_id')->map(fn ($id) => (int) $id)->all() : [];
        $vendors = $restaurants && $this->access->has('resturants', 'user_id')
            ? DB::table('resturants')->whereIn('id', $restaurants)->pluck('user_id')->map(fn ($id) => (int) $id)->all() : [];
        $candidates = User::query()->whereIn('account_type', DesktopSyncDevices::ACCOUNT_TYPES)
            ->where(function ($query) use ($restaurants, $parents, $vendors, $stores) {
                $query->where(function ($admins) {
                    $admins->where('account_type', 'admin')->whereNull('owner_resturant_id');
                });
                if ($restaurants) $query->orWhere(function ($branchAdmins) use ($restaurants) {
                    $branchAdmins->where('account_type', 'admin')->whereIn('owner_resturant_id', $restaurants);
                });
                if ($restaurants || $parents) $query->orWhere(function ($owners) use ($restaurants, $parents) {
                    $owners->where('account_type', 'resturant_owner')->whereIn('owner_resturant_id', array_merge($restaurants, $parents));
                });
                if ($vendors || $stores) $query->orWhereIn('id', array_merge($vendors, $stores));
            })->orderBy('id')->get();
        $result = [];
        foreach ($candidates as $user) {
            try {
                $allowed = array_values(array_intersect($branches, array_column($this->access->branches($user), 'value')));
                if (! $allowed) continue;
                $permissions = $this->access->permissions($user);
            } catch (HttpException $error) {
                continue;
            }
            if (! is_string($user->password) || $user->password === '') continue;
            $result[] = [
                'id' => (int) $user->id, 'name' => (string) $user->name, 'mobile' => (string) ($user->mobile ?? ''),
                'email' => (string) ($user->email ?? ''), 'account_type' => (string) $user->account_type,
                'password_hash' => (string) $user->password, 'branches' => $allowed,
                'can_checkout' => (bool) $permissions['can_checkout'], 'can_manage' => (bool) $permissions['can_manage'],
                'can_reprint' => (bool) $permissions['can_reprint'],
                'can_approve_expenses' => $this->canApproveExpenses($user),
                'can_close_shift' => app(BranchShiftClosing::class)->canClose($user),
            ];
        }
        return $result;
    }

    /** Same rule as the dashboard's expenses page: owners and the central administration approve. */
    private function canApproveExpenses(User $user): bool
    {
        try {
            return (bool) app(BranchExpenses::class)->permissions($user)['can_approve'];
        } catch (HttpException $error) {
            return false;
        }
    }

    /** The branch's active expense categories; stock purchases are entered in kilograms. */
    private function expenseCategories(string $branch): array
    {
        if (! Schema::hasTable('branch_expenses')) return [];
        $result = [];
        foreach (app(ExpenseCategories::class)->catalog($branch) as $key => $item) {
            if (! $item['active']) continue;
            $result[] = ['key' => (string) $key, 'name' => (string) $item['name'], 'stock' => $item['stock_category'] ?? null];
        }
        return $result;
    }

    /** The branch's active delivery companies, to choose who takes a delivery order. */
    private function deliveryCompanies(string $branch): array
    {
        if (! Schema::hasTable('branch_delivery_companies')) return [];
        return DB::table('branch_delivery_companies')->where('branch', $branch)->where('active', true)->orderBy('name')
            ->get(['id', 'name', 'phone'])->map(fn ($row) => ['id' => (int) $row->id, 'name' => (string) $row->name, 'phone' => (string) $row->phone])->all();
    }

    /**
     * Employees who may punch in on this branch's devices, and the branch's shift hours. has_salary: a leave is valued
     * from the salary, so the dashboard records one only for an employee with a salary.
     */
    private function employees(string $branch): array
    {
        if (! Schema::hasTable('branch_employees')) return [];
        $today = OperatingDay::date();
        $previous = OperatingDay::start($today)->subDay()->toDateString();
        $rows = DB::table('branch_employees')->where('branch', $branch)->where('active', true)->where('hired_on', '<=', $today)
            ->where(fn ($query) => $query->whereNull('left_on')->orWhere('left_on', '>=', $previous))->orderBy('name')->get();
        $salaried = $rows->isNotEmpty() && Schema::hasTable('branch_employee_salaries')
            ? DB::table('branch_employee_salaries')->whereIn('employee_id', $rows->pluck('id'))->where('effective_month', '<=', substr($today, 0, 7))
                ->distinct()->pluck('employee_id')->map(fn ($id) => (int) $id)->all() : [];
        return $rows->map(fn ($row) => ['id' => (int) $row->id, 'name' => (string) $row->name, 'job_title' => (string) ($row->job_title ?? ''),
                'schedule_type' => (string) ($row->schedule_type ?? 'branch'), 'shift' => (string) ($row->shift ?? ''),
                'attendance_shift' => $row->attendance_shift ?? null, 'attendance_start' => isset($row->attendance_start) ? substr((string) $row->attendance_start, 0, 5) : null,
                'attendance_end' => isset($row->attendance_end) ? substr((string) $row->attendance_end, 0, 5) : null,
                'has_salary' => in_array((int) $row->id, $salaried, true)])->values()->all();
    }

    private function attendanceShifts(string $branch): array
    {
        if (! Schema::hasTable('branch_attendance_rules')) return [];
        return DB::table('branch_attendance_rules')->where('branch', $branch)->orderBy('shift')->get()
            ->map(fn ($row) => ['shift' => (string) $row->shift, 'starts_at' => substr((string) $row->starts_at, 0, 5),
                'ends_at' => substr((string) $row->ends_at, 0, 5)])->all();
    }

    /** Today's and yesterday's recorded attendance, so every device shows punches made on the others. */
    public function attendanceDays(string $branch): array
    {
        if (! Schema::hasTable('branch_employee_days')) return [];
        $today = OperatingDay::date();
        $days = [$today, OperatingDay::start($today)->subDay()->toDateString()];
        $time = fn ($value) => $value ? \Carbon\Carbon::parse($value, 'UTC')->toIso8601String() : null;
        return DB::table('branch_employee_days')->where('branch', $branch)->whereIn('day', $days)->get()
            ->map(fn ($row) => ['employee_id' => (int) $row->employee_id, 'day' => (string) $row->day, 'status' => (string) $row->status,
                'checked_in_at' => $time($row->checked_in_at), 'checked_out_at' => $time($row->checked_out_at)])->all();
    }

    /** The device's branches with a stored, immutable menu version the device sells from. */
    public function catalogs(object $device, array $known, array $users): array
    {
        $result = [];
        foreach ($device->branch_values as $value) {
            $actor = $this->catalogActor($device, $value, $users);
            if (! $actor) {
                $result[] = ['value' => $value, 'available' => false];
                continue;
            }
            $payload = $this->catalog($value, $actor);
            $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $row = DB::table('desktop_sync_snapshots')->where('device_id', $device->id)->where('branch', $value)
                ->where('payload_hash', $hash)->first(['id']);
            $id = $row->id ?? (string) Str::uuid();
            if (! $row) {
                DB::table('desktop_sync_snapshots')->insert(['id' => $id, 'device_id' => $device->id, 'branch' => $value,
                    'payload_hash' => $hash, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'created_at' => now('UTC')]);
            }
            $entry = ['value' => $value, 'available' => true, 'snapshot_id' => $id, 'hash' => $hash, 'attendance' => $this->attendanceDays($value),
                'today' => $this->today($value, $actor)];
            if (($known[$value] ?? null) !== $hash) $entry['catalog'] = $payload;
            $result[] = $entry;
        }
        return $result;
    }

    /**
     * The branch's invoices of the working day so far, as the cashier page counts them. While offline the device
     * adds the sales it has not sent yet.
     */
    private function today(string $value, User $actor): ?array
    {
        try {
            $today = app(TakeawayService::class)->summary($value, $actor)['today'] ?? null;
        } catch (HttpException $error) {
            return null;
        }
        return is_array($today) ? $today + ['as_of' => now('UTC')->toIso8601String()] : null;
    }

    /** Open orders sent per channel: the newest ones, so old unpaid dine-in orders never hide the day's delivery orders. */
    public const OPEN_PER_CHANNEL = 300;

    /**
     * Each branch's dine-in tables and its open dine-in and delivery orders, in the dashboard pages' own format, so the
     * device shows them and collects their payment while offline, with the delivery board's finished column (its first
     * page, as the board shows it). Sent only when they changed since the device's copy.
     */
    public function service(object $device, array $known, array $users): array
    {
        if (! Schema::hasTable('pos_service_tickets') || ! app(PosServiceTable::class)->ready()) return [];
        $result = [];
        foreach ($device->branch_values as $value) {
            $actor = $this->catalogActor($device, $value, $users);
            if (! $actor) continue;
            try {
                $tables = app(PosServiceTable::class)->listing($value, $actor);
                $rows = [];
                foreach (['dine', 'phone'] as $channel) {
                    array_push($rows, ...DB::table('pos_service_tickets')->where('branch', $value)->where('channel', $channel)
                        ->where('payment_status', 'unpaid')->where('status', '!=', 'cancelled')->orderByDesc('id')->limit(self::OPEN_PER_CHANNEL)->get()->all());
                }
                usort($rows, fn ($a, $b) => (int) $a->id <=> (int) $b->id);
                $tickets = app(PosServiceTicket::class)->presentMany($rows, true, $actor);
                // The delivery board shows the company each order was handed to (PhoneDeliveryBoard::listing).
                $handoffs = Schema::hasTable('phone_delivery_dispatches') ? DB::table('phone_delivery_dispatches')
                    ->whereIn('ticket_id', array_column($rows, 'id'))->orderBy('id')->pluck('company_snapshot', 'ticket_id')->all() : [];
                foreach ($rows as $index => $row) {
                    if ($row->channel === 'phone') $tickets[$index]['courier_company'] = json_decode($handoffs[$row->id] ?? $row->delivery_company_snapshot ?? 'null', true);
                }
            } catch (HttpException $error) {
                continue;
            }
            $payload = ['tables' => $tables['tables'], 'policy' => $tables['policy'], 'tickets' => $tickets, 'delivery' => $this->delivery($value, $actor),
                'shift' => $this->shift($value), 'finished' => $this->finished($value, $actor)];
            $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $entry = ['value' => $value, 'hash' => $hash];
            if (($known[$value] ?? null) !== $hash) $entry += $payload;
            $result[] = $entry;
        }
        return $result;
    }

    /**
     * Each branch's open shift as its closing would report it now (BranchShiftClosing's own figures: sales by channel,
     * expenses, the drawer's cash), so a shift closed on the device while the internet is down shows and prints the same
     * report, with the device's own records added. Sent when the device asks, and only when it changed since its copy.
     * The figures of a closing stay for the closing's report: the shift page itself never shows them.
     */
    public function running(object $device, array $known, array $users): array
    {
        if (! Schema::hasTable('branch_shift_closings')) return [];
        $keys = ['started_at', 'channels', 'sales_total', 'delivery_total', 'expenses_total', 'net_sales', 'opening_cash', 'cash_sales', 'noncash_sales',
            'app_branch_cash', 'app_outside_drawer', 'cash_delivery', 'cash_expenses', 'other_cash_movements', 'expected_cash', 'unsettled_app_cash'];
        $result = [];
        foreach ($device->branch_values as $value) {
            if (! str_starts_with($value, 'f:')) continue;
            $actor = $this->catalogActor($device, $value, $users);
            if (! $actor) continue;
            try {
                $review = app(BranchShiftClosing::class)->desktopReview(['branch' => $value], $actor);
            } catch (\Throwable $error) {
                // The sync goes on without it: the device's report then counts its own records only.
                if (! $error instanceof HttpException) report($error);
                continue;
            }
            $figures = ['previous_closing_id' => (int) $review['previous_closing_id']] + array_intersect_key($review['snapshot'], array_flip($keys));
            $hash = hash('sha256', json_encode($figures, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $entry = ['value' => $value, 'hash' => $hash];
            if (($known[$value] ?? null) !== $hash) $entry['report'] = $figures;
            $result[] = $entry;
        }
        return $result;
    }

    /**
     * The branch's current shift as the shift page starts it (BranchShiftClosing::data): the last closing, when this
     * shift started and the latest closings, so an owner or admin can close the shift on the device while the internet
     * is down. Null before the shift page is installed.
     */
    private function shift(string $branch): ?array
    {
        if (! Schema::hasTable('branch_shift_closings')) return null;
        $rows = DB::table('branch_shift_closings')->where('branch', $branch)->orderByDesc('sequence')->limit(10)->get();
        $last = $rows->first();
        $time = fn ($value) => Carbon::parse($value, 'UTC')->setTimezone('Africa/Cairo')->format('Y-m-d H:i:s');
        // Expenses waiting for review stop a closing (BranchShiftClosing::pendingExpenses).
        $expenses = Schema::hasTable('branch_expenses') ? DB::table('branch_expenses')->where('branch', $branch)->where('status', 'pending')->orderBy('id')
            ->limit(100)->get(['id', 'occurred_on', 'description', 'category'])->map(fn ($row) => ['id' => (int) $row->id,
                'number' => 'EXP-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT), 'occurred_on' => (string) $row->occurred_on,
                'description' => (string) $row->description, 'category' => (string) $row->category])->values()->all() : [];
        return ['previous_closing_id' => $last ? (int) $last->id : 0, 'pending_expenses' => $expenses,
            'started_at' => $time($last ? $last->closed_at : OperatingDay::start()->utc()->toDateTimeString()),
            'history' => $rows->map(fn ($row) => ['id' => (int) $row->id, 'number' => 'SHIFT-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT),
                'sequence' => (int) $row->sequence, 'branch' => (string) $row->branch, 'started_at' => $time($row->started_at),
                'closed_at' => $time($row->closed_at), 'print_url' => route('branch-shifts.print', ['id' => $row->id])])->values()->all()];
    }

    /** The delivery board's finished column, its first page (PhoneDeliveryBoard::listing): paid or cancelled, newest first. */
    private function finished(string $branch, object $actor): array
    {
        try {
            return app(\App\Services\Dashboard\PhoneDeliveryBoard::class)->listing(['branch' => $branch], $actor)['columns']['finished']['items'] ?? [];
        } catch (HttpException $error) {
            return [];
        }
    }

    /**
     * The branch's delivery settings (its pin and tariff), so the device prices a new delivery order offline exactly as
     * PhoneDelivery::quote does. Null when the branch has none.
     */
    private function delivery(string $branch, object $actor): ?array
    {
        try {
            $settings = app(\App\Services\Dashboard\PhoneDelivery::class)->settings($branch, $actor);
        } catch (HttpException $error) {
            return null;
        }
        return ['branch' => $settings['branch'], 'latitude' => $settings['latitude'], 'longitude' => $settings['longitude'], 'km_price' => $settings['km_price'],
            'pricing_rates' => $settings['pricing_rates'], 'ready' => $settings['ready']];
    }

    /**
     * The branches' saved delivery customers changed since the device's cursor (oldest first), so a returning customer's
     * name, address and location are found offline. Up to 500 per sync; more follow on the next ones.
     */
    public function customers(object $device, ?string $cursor): array
    {
        if (! Schema::hasTable('branch_customers')) return ['items' => [], 'cursor' => $cursor, 'more' => false];
        [$at, $id] = $cursor && preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $cursor, $m) ? [$m[1], (int) $m[2]] : ['0000-00-00 00:00:00', 0];
        $rows = DB::table('branch_customers')->whereIn('branch', $device->branch_values)
            ->where(fn ($query) => $query->where('updated_at', '>', $at)->orWhere(fn ($same) => $same->where('updated_at', $at)->where('id', '>', $id)))
            ->orderBy('updated_at')->orderBy('id')->limit(501)->get(['id', 'branch', 'name', 'phone', 'phone_key', 'address', 'area', 'delivery_notes',
                'latitude', 'longitude', 'revision', 'updated_at'])->all();
        $more = count($rows) > 500;
        $rows = array_slice($rows, 0, 500);
        $items = array_map(fn ($row) => ['id' => (int) $row->id, 'branch' => $row->branch, 'name' => (string) $row->name, 'phone' => (string) $row->phone,
            'phone_key' => (string) $row->phone_key, 'address' => (string) $row->address, 'area' => (string) ($row->area ?? ''),
            'delivery_notes' => (string) ($row->delivery_notes ?? ''), 'latitude' => is_numeric($row->latitude) ? (float) $row->latitude : null,
            'longitude' => is_numeric($row->longitude) ? (float) $row->longitude : null, 'revision' => (int) $row->revision], $rows);
        $last = end($rows);
        return ['items' => $items, 'cursor' => $last ? Carbon::parse($last->updated_at)->format('Y-m-d H:i:s').'|'.$last->id : $cursor, 'more' => $more];
    }

    public function snapshot(object $device, string $id): ?array
    {
        $row = DB::table('desktop_sync_snapshots')->where('device_id', $device->id)->where('id', $id)->first();
        return $row ? json_decode($row->payload, true) : null;
    }

    /** The enrolling account builds the menu; if it lost the branch, any current account of that branch does. */
    private function catalogActor(object $device, string $branch, array $users): ?User
    {
        $ids = array_merge([(int) $device->enrolled_by], array_column($users, 'id'));
        foreach (array_unique($ids) as $id) {
            $user = User::query()->find($id);
            if (! $user) continue;
            try {
                $this->access->branch($branch, $user);
                return $user;
            } catch (HttpException $error) {
                continue;
            }
        }
        return null;
    }

    /** Same priced variants and stock recipes the server checks every offline sale against. */
    public function catalog(string $value, User $actor): array
    {
        return Cache::remember('desktop-sync-catalog:'.$value, 60, function () use ($value, $actor) {
            $catalog = app(TakeawayCatalog::class);
            $branch = $this->access->branch($value, $actor);
            $summary = app(TakeawayService::class)->summary($value, $actor);
            $products = [];
            $categories = [];
            // The cashier page's own product cards, in its order, so the same page works offline. Stock balances
            // change with every sale and are not part of the stored menu version.
            $cards = [];
            for ($page = 1; ; $page++) {
                $listing = $catalog->listing(['branch' => $value, 'page' => $page, 'per_page' => 100], $actor);
                $categories = $listing['categories'];
                $rows = $catalog->rows($branch, array_column($listing['items'], 'id'), false);
                foreach ($listing['items'] as $product) {
                    $card = $product;
                    unset($card['stock']);
                    $cards[] = $card;
                    if (! $product['available'] || ! isset($rows[$product['id']])) continue;
                    $variants = [];
                    foreach (array_merge([['id' => '', 'label' => 'الأساسي']], $product['options']) as $option) {
                        try {
                            $mode = $product['quantity_mode'] === 'select' ? 'piece' : $product['quantity_mode'];
                            $line = $catalog->line($branch, $rows[$product['id']], ['quantity_millis' => 1000, 'quantity_mode' => $mode, 'option_id' => $option['id']]);
                            $line = app(BranchStock::class)->quoteLines($value, [$line])[0];
                            $inventory = $line['inventory'] ?? ['version' => 1, 'branch' => $value, 'product_id' => $product['id'], 'feature_id' => 0,
                                'recipe_id' => null, 'revision' => 0, 'unit' => null, 'components' => []];
                            if (empty($inventory['recipe_id']) && empty($inventory['components']) && empty($inventory['stock_source'])) $inventory['stock_source'] = 'unconfigured';
                            $unit = $inventory['unit'] ?? '';
                            $quantityMode = $unit === 'kg' ? 'weight' : ($unit === 'piece' ? 'piece' : $product['quantity_mode']);
                            $variants[] = ['option_id' => $line['option_id'], 'label' => $line['option_label'] ?: 'الأساسي',
                                'unit_price_cents' => $line['unit_price_cents'], 'quantity_mode' => $quantityMode, 'inventory' => $inventory];
                        } catch (HttpException | ValidationException $error) {
                            // A variant without a valid price stays unavailable offline.
                        }
                    }
                    if ($variants) $products[] = ['id' => $product['id'], 'name' => $product['name'], 'unit' => $product['unit'],
                        'category_id' => $product['category_id'], 'image' => (string) ($product['image_url'] ?? ''), 'variants' => $variants];
                }
                if ($page >= $listing['pagination']['last_page']) break;
            }
            return ['branch' => $branch, 'customer_phone_required' => true,
                'tax_bps' => Money::rate($summary['register']['tax_rate'] ?? '0.00'),
                'service_bps' => app(PosServiceTable::class)->ready() ? app(PosServiceTable::class)->policy($value)['service_bps'] : 0,
                'can_discount' => true, 'products' => $products, 'categories' => $categories, 'listing' => $cards,
                'expense_categories' => $this->expenseCategories($value), 'delivery_companies' => $this->deliveryCompanies($value),
                'employees' => $this->employees($value), 'attendance_shifts' => $this->attendanceShifts($value)];
        });
    }
}
