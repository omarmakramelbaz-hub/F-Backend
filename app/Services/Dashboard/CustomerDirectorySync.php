<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Live capture plus resumable, bounded customer-only historical reconciliation. */
class CustomerDirectorySync
{
    private CustomerDirectory $directory;
    private array $columns = [];
    public const SOURCES = ['users', 'user_address', 'branch_customers', 'pos_service_tickets', 'takeaway_orders', 'orders', 'go_store_orders'];

    public function __construct(CustomerDirectory $directory)
    {
        $this->directory = $directory;
    }

    /** This hook does not copy staff, couriers, partners or their phone numbers. */
    public function captureUser(object $user, bool $historical = false): void
    {
        if (!$this->customerAccount($user)) return;
        $profile = $this->userProfile($user);
        if ($this->has('user_address', ['id', 'user_id'])) {
            $latest = DB::table('user_address')->where('user_id', $user->id)->orderByDesc('id')->first();
            if ($latest && CustomerDirectory::normalizePhone((string)($latest->mobile ?? '')) === CustomerDirectory::normalizePhone($profile['phone'])) {
                $profile = $this->addressProfile($latest) + $profile;
            }
        }
        $this->capture($profile, 'app', '', $historical);
        // A changed account phone/name must also retain its existing branch associations.
        if ($this->has('orders', ['user_id', 'resturant_id', 'type'])) {
            $branches = DB::table('orders')->where('user_id', $user->id)->where('type', 'current')
                ->whereNotNull('resturant_id')->select('resturant_id')->distinct()->limit(250)->pluck('resturant_id');
            foreach ($branches as $id) if ((int)$id > 0) $this->capture($profile, 'app', 'f:'.$id, $historical);
        }
        if ($this->has('go_store_orders', ['customer_id', 'store_id'])) {
            $branches = DB::table('go_store_orders')->where('customer_id', $user->id)
                ->select('store_id')->distinct()->limit(250)->pluck('store_id');
            foreach ($branches as $id) if ((int)$id > 0) $this->capture($profile, 'app', 'gs:'.$id, $historical);
        }
    }

    public function captureAddress(object $address, bool $historical = false): void
    {
        $user = $this->user((int)($address->user_id ?? 0));
        if (!$user || !$this->customerAccount($user)) return;
        $profile = $this->addressProfile($address) + $this->userProfile($user);
        $this->capture($profile, 'app', '', $historical);
        // An unpurchased address never grants a branch access to that customer.
        if ($this->has('orders', ['user_address_id', 'resturant_id', 'type'])) {
            $branches = DB::table('orders')->where('user_address_id', $address->id)->where('type', 'current')
                ->whereNotNull('resturant_id')->select('resturant_id')->distinct()->limit(250)->pluck('resturant_id');
            foreach ($branches as $id) if ((int)$id > 0) $this->capture($profile, 'app', 'f:'.$id, $historical);
        }
    }

    /** $completedTransition is true only on a real live status transition. */
    public function captureOrder(object $order, bool $completedTransition = false, bool $historical = false): void
    {
        if (($order->type ?? '') !== 'current' || (int)($order->resturant_id ?? 0) < 1) return;
        $user = $this->user((int)($order->user_id ?? 0));
        if (!$user || !$this->customerAccount($user)) return;
        $branch = 'f:'.$order->resturant_id;
        $profile = $this->userProfile($user);
        $this->capture($profile, 'app', $branch, $historical);
        if (!empty($order->user_address_id) && $this->has('user_address', ['id', 'user_id'])) {
            $address = DB::table('user_address')->where('id', $order->user_address_id)->where('user_id', $user->id)->first();
            if ($address) $profile = $this->addressProfile($address) + $profile;
        }
        $this->capture($profile, 'app', $branch, $historical);
        if ($completedTransition && !$historical && ($order->status ?? '') === 'completed' && $this->legacyPaymentReady($order)) {
            $this->directory->purchase($profile, 'app', $branch, 'app_order', (string)$order->id, now('UTC'));
        }
        $this->cancelPurchase('app_order', $order);
    }

    /** GO store operations use the query builder and therefore call this explicitly. */
    public function captureGoOrder(object $order, bool $completedTransition = false, bool $historical = false): void
    {
        if ((int)($order->store_id ?? 0) < 1) return;
        $user = $this->user((int)($order->customer_id ?? 0));
        if (!$user || !$this->customerAccount($user)) return;
        $snapshot = $this->json($order->snapshot ?? null);
        $profile = $this->userProfile($user);
        if (is_string($snapshot['customer_name'] ?? null) && trim($snapshot['customer_name']) !== '') $profile['name'] = $snapshot['customer_name'];
        if (CustomerDirectory::normalizePhone((string)($snapshot['customer_mobile'] ?? ''))) $profile['phone'] = $snapshot['customer_mobile'];
        if (is_array($snapshot['address'] ?? null)) $profile = $this->addressProfile((object)$snapshot['address']) + $profile;
        $branch = 'gs:'.$order->store_id;
        $this->capture($profile, 'app', $branch, $historical);
        if ($completedTransition && !$historical && ($order->status ?? '') === 'completed'
            && in_array($order->payment_status ?? '', ['paid', 'cash_collected'], true)) {
            $this->directory->purchase($profile, 'app', $branch, 'go_store_order', (string)$order->id, now('UTC'));
        }
        $this->cancelPurchase('go_store_order', $order);
    }

    /** Authoritative send-time validation also catches later refunds or voids. */
    public function purchaseIsCompleted(object $purchase): bool
    {
        if (($purchase->source_type ?? '') === 'app_order') {
            if (!$this->has('orders', ['id', 'type', 'resturant_id', 'status'])) return false;
            $order = DB::table('orders')->where('id', $purchase->source_id)->first();
            return $order && $order->type === 'current' && (int)$order->resturant_id > 0
                && $order->status === 'completed' && 'f:'.$order->resturant_id === $purchase->branch
                && $this->legacyPaymentReady($order);
        }
        if (($purchase->source_type ?? '') === 'go_store_order') {
            if (!$this->has('go_store_orders', ['id', 'store_id', 'status', 'payment_status'])) return false;
            $order = DB::table('go_store_orders')->where('id', $purchase->source_id)->first();
            return $order && $order->status === 'completed' && 'gs:'.$order->store_id === $purchase->branch
                && in_array($order->payment_status, ['paid', 'cash_collected'], true) && !$this->failed($order);
        }
        return false;
    }

    /** At most $limit rows per source. Importing an old sale never queues feedback. */
    public function run(int $limit = 500): array
    {
        $limit = max(1, min($limit, 2000));
        $out = ['processed'=>0, 'sources'=>0, 'bootstrapped'=>true];
        if (!$this->directory->ready() || !Schema::hasTable('crm_customer_sync_states')) return $out + ['ready'=>false];
        foreach (self::SOURCES as $source) {
            if (!$this->has($source, ['id'])) continue;
            $result = DB::transaction(function () use ($source, $limit): array {
                $now = now('UTC');
                DB::table('crm_customer_sync_states')->insertOrIgnore([
                    'source'=>$source, 'cursor_id'=>0, 'bootstrap_max_id'=>(int)DB::table($source)->max('id'),
                    'activated_at'=>$now, 'change_cursor_at'=>$now, 'created_at'=>$now, 'updated_at'=>$now,
                ]);
                $state = DB::table('crm_customer_sync_states')->where('source', $source)->lockForUpdate()->first();
                $new = DB::table($source)->where('id', '>', $state->cursor_id);
                if ($state->bootstrapped_at === null) $new->where('id', '<=', $state->bootstrap_max_id);
                $rows = $new->orderBy('id')->limit($limit)->get();
                foreach ($rows as $row) $this->import($source, $row);
                $changes = ['updated_at'=>$now];
                if ($rows->isNotEmpty()) $changes['cursor_id'] = (int)$rows->last()->id;
                $lastId = $changes['cursor_id'] ?? (int)$state->cursor_id;
                $initialComplete = $state->bootstrapped_at !== null || $lastId >= (int)$state->bootstrap_max_id || $rows->count() < $limit;
                if ($initialComplete && $state->bootstrapped_at === null) $changes['bootstrapped_at'] = $now;
                // IDs recover inserts even when their retained updated_at is NULL/backdated.
                // Timestamp+ID keysets recover changes without re-scanning the full history.
                $remaining = $limit - $rows->count();
                if ($state->bootstrapped_at !== null && $remaining > 0 && $this->has($source, ['updated_at'])) {
                    $cutoff = $now->copy()->subSeconds(5);
                    $changed = DB::table($source)->where('id', '<=', $state->cursor_id)->where('updated_at', '<=', $cutoff)
                        ->where(function ($q) use ($state) {
                            $q->where('updated_at', '>', $state->change_cursor_at)
                                ->orWhere(function ($q) use ($state) { $q->where('updated_at', $state->change_cursor_at)->where('id', '>', $state->change_cursor_id); });
                        })->orderBy('updated_at')->orderBy('id')->limit($remaining)->get();
                    foreach ($changed as $row) $this->import($source, $row, false);
                    if ($changed->isNotEmpty()) {
                        $changes['change_cursor_at'] = $changed->last()->updated_at;
                        $changes['change_cursor_id'] = (int)$changed->last()->id;
                    }
                    $count = $changed->count();
                }
                DB::table('crm_customer_sync_states')->where('source', $source)->update($changes);
                return ['processed'=>$rows->count()+($count ?? 0), 'bootstrapped'=>$initialComplete];
            }, 3);
            $out['processed'] += $result['processed'];
            $out['sources']++;
            $out['bootstrapped'] = $out['bootstrapped'] && $result['bootstrapped'];
        }
        return $out + ['ready'=>true];
    }

    private function import(string $source, object $row, bool $historical = true): void
    {
        if ($source === 'users') { $this->captureUser($row, $historical); return; }
        if ($source === 'user_address') { $this->captureAddress($row, $historical); return; }
        if ($source === 'orders') { $this->captureOrder($row, false, $historical); return; }
        if ($source === 'go_store_orders') { $this->captureGoOrder($row, false, $historical); return; }
        if ($source === 'branch_customers') { $this->capture((array)$row, 'delivery', (string)($row->branch ?? ''), $historical); return; }
        if ($source === 'pos_service_tickets') {
            $channel = ($row->channel ?? '') === 'phone' ? 'delivery' : ($row->channel ?? '');
            if (in_array($channel, CustomerDirectory::CHANNELS, true)) $this->capture((array)$row, $channel, (string)($row->branch ?? ''), $historical);
            return;
        }
        $profile = $this->json($row->context_snapshot ?? null);
        if (is_array($profile['customer'] ?? null)) $profile = $profile['customer'] + $profile;
        $channel = ($row->channel ?? '') === 'phone' ? 'delivery' : ($row->channel ?? 'takeaway');
        if (in_array($channel, CustomerDirectory::CHANNELS, true)) $this->capture($profile, $channel, (string)($row->branch ?? ''), $historical);
    }

    private function capture(array $profile, string $channel, string $branch, bool $historical): void
    {
        if (!$this->directory->ready()) return;
        // Historical snapshots fill gaps, while saved profiles and live updates remain authoritative.
        if ($historical) {
            $phone = CustomerDirectory::normalizePhone((string)($profile['phone'] ?? $profile['customer_phone'] ?? $profile['mobile'] ?? ''));
            $existing = $phone ? DB::table('crm_customers')->where('phone', $phone)->first() : null;
            if ($existing) {
                foreach (['name', 'address', 'area', 'delivery_notes', 'latitude', 'longitude'] as $field) {
                    if ($existing->$field !== null && (string)$existing->$field !== '') {
                        unset($profile[$field]);
                        if ($field === 'name') unset($profile['customer_name']);
                        if ($field === 'latitude') unset($profile['lat']);
                        if ($field === 'longitude') unset($profile['lng']);
                    }
                }
            }
        }
        $this->directory->capture($profile, $channel, $branch);
    }

    private function customerAccount(object $user): bool
    {
        return ($user->account_type ?? '') === 'user' && in_array($user->app_scope ?? '', ['', 'fasakhansta', 'go', 'go_customer'], true);
    }

    private function user(int $id): ?object
    {
        return $id > 0 && $this->has('users', ['id']) ? DB::table('users')->where('id', $id)->first() : null;
    }

    private function userProfile(object $user): array
    {
        return ['phone'=>(string)($user->mobile ?? ''), 'name'=>(string)($user->name ?? '')];
    }

    private function addressProfile(object $address): array
    {
        $parts = array_filter([$address->address ?? $address->address_name ?? null, $address->street_name ?? null,
            !empty($address->building_no) ? 'عقار '.$address->building_no : null,
            !empty($address->floor_no) ? 'الدور '.$address->floor_no : null,
            !empty($address->apartment_no) ? 'شقة '.$address->apartment_no : null]);
        $profile = ['address'=>implode('، ', array_unique($parts)), 'area'=>$address->area_name ?? $address->area ?? '',
            'delivery_notes'=>$address->badge ?? $address->delivery_notes ?? '',
            'latitude'=>$address->latitude ?? $address->lat ?? null, 'longitude'=>$address->longitude ?? $address->lng ?? null];
        $phone = (string)($address->mobile ?? $address->phone ?? $address->customer_phone ?? '');
        if (CustomerDirectory::normalizePhone($phone)) $profile['phone'] = $phone;
        return $profile;
    }

    private function legacyPaymentReady(object $order): bool
    {
        if ($this->failed($order)) return false;
        if (($order->payment_type ?? '') === 'cash') return true;
        if (($order->payment_type ?? '') === 'wallet') {
            return $this->has('wallets', ['order_id', 'from_user', 'to_user', 'payment', 'status'])
                && DB::table('wallets')->where('order_id', $order->id)->where('from_user', $order->user_id)
                    ->whereNull('to_user')->where('payment', 'wallet')->where('status', 'completed')->exists();
        }
        if (!in_array($order->payment_type ?? '', ['online', 'v_cash', 'card', 'mobile_wallet'], true)) return false;
        if (!$this->has('payments', ['order_id', 'status'])) return false;
        $paid = DB::table('payments')->where('order_id', $order->id)->whereIn('status', ['1', 'true']);
        if ($this->has('payments', ['user_id'])) $paid->where('user_id', $order->user_id);
        return $paid->exists();
    }

    private function failed(object $order): bool
    {
        return in_array(strtolower((string)($order->payment_status ?? '')), ['failed', 'cancelled', 'canceled', 'refunded', 'refund_pending', 'reversed'], true)
            || in_array($order->status ?? '', ['cancelled', 'canceled', 'declined', 'rejected', 'expired', 'refunded'], true)
            || in_array($order->payment_failed ?? false, [true, 1, '1', 'true'], true);
    }

    private function cancelPurchase(string $source, object $order): void
    {
        if (!$this->directory->ready() || !$this->failed($order)) return;
        DB::table('crm_purchases')->where('source_type', $source)->where('source_id', (string)$order->id)
            ->where('feedback_status', 'pending')->update(['feedback_status'=>'cancelled', 'updated_at'=>now('UTC')]);
    }

    private function has(string $table, array $columns): bool
    {
        if (!array_key_exists($table, $this->columns)) $this->columns[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
        return !array_diff($columns, $this->columns[$table]);
    }

    private function json($value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
