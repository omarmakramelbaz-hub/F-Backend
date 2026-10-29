<?php

namespace App\Services\DesktopSync;

use App\Models\User;
use App\Services\Dashboard\BranchExpenses;
use App\Services\Dashboard\BranchShiftClosing;
use App\Services\Dashboard\BranchStock;
use App\Services\Dashboard\ExpenseCategories;
use App\Services\Dashboard\PosServiceTable;
use App\Services\Dashboard\TakeawayAccess;
use App\Services\Dashboard\TakeawayCatalog;
use App\Services\Dashboard\TakeawayService;
use App\Services\GoServices\Money;
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
            $entry = ['value' => $value, 'available' => true, 'snapshot_id' => $id, 'hash' => $hash];
            if (($known[$value] ?? null) !== $hash) $entry['catalog'] = $payload;
            $result[] = $entry;
        }
        return $result;
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
            for ($page = 1; ; $page++) {
                $listing = $catalog->listing(['branch' => $value, 'page' => $page, 'per_page' => 100], $actor);
                $categories = $listing['categories'];
                $rows = $catalog->rows($branch, array_column($listing['items'], 'id'), false);
                foreach ($listing['items'] as $product) {
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
                'can_discount' => true, 'products' => $products, 'categories' => $categories,
                'expense_categories' => $this->expenseCategories($value)];
        });
    }
}
