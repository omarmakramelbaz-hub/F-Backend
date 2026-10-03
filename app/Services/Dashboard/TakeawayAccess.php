<?php

namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** POS authorization uses persisted relationships, never the dashboard's selected-user session. */
class TakeawayAccess
{
    private array $columns = [];

    public function actor($actor): User
    {
        abort_unless($actor && $actor->id, 403);
        $fresh = User::withoutGlobalScopes()->find($actor->id);
        abort_unless($fresh && in_array($fresh->account_type, ['admin', 'vendor', 'resturant_owner', 'delegate'], true), 403);
        if ($fresh->account_type === 'admin' && empty($fresh->owner_resturant_id)) {
            abort_unless($this->superAdmin($fresh) || $this->permission($fresh, 'order-list'), 403);
        }
        return $fresh;
    }

    public function canAccess($actor): bool
    {
        try { return count($this->branches($actor)) > 0; }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { return false; }
    }

    public function branches($actor): array
    {
        $actor = $this->actor($actor);
        $result = [];
        if ($this->has('resturants', 'user_id') && $actor->account_type !== 'delegate') {
            $query = DB::table('resturants');
            if ($actor->account_type === 'admin') {
                if (!empty($actor->owner_resturant_id)) $query->where('id', $actor->owner_resturant_id);
            } elseif ($actor->account_type === 'resturant_owner') {
                $parent = (int) ($actor->owner_resturant_id ?? 0);
                $query->where(function ($q) use ($parent) {
                    $q->where('id', $parent);
                    if ($this->has('resturants', 'parent_id')) $q->orWhere('parent_id', $parent);
                });
                if ($parent < 1) $query->whereRaw('1 = 0');
            } else $query->where('user_id', $actor->id);
            foreach ($query->orderBy('id')->get() as $row) $result[] = $this->present('f', $row);
        }
        // GO's catalog and till belong to users.id, not an unrelated profile id.
        if ($this->goReady() && empty($actor->owner_resturant_id) && $actor->account_type !== 'resturant_owner') {
            $query = DB::table('go_stores')->join('users', 'users.id', '=', 'go_stores.user_id')
                ->where('users.app_scope', 'go_partner')->where('users.status', 'accepted')
                ->select('go_stores.*', 'users.account_type');
            $query->addSelect($this->has('users', 'pending_vendor_id') ? 'users.pending_vendor_id' : DB::raw('NULL AS pending_vendor_id'));
            if ($actor->account_type !== 'admin') $query->where('users.id', $actor->id);
            foreach ($query->orderBy('go_stores.user_id')->get() as $row) {
                if ($this->storeOwner($row)) $result[] = $this->present('gs', $row);
            }
        }
        return $result;
    }

    public function branch(string $value, $actor, bool $lock = false): array
    {
        abort_unless(preg_match('/^(f|gs):([1-9][0-9]{0,18})$/D', $value, $match), 404);
        $actor = $this->actor($actor);
        $kind = $match[1]; $id = (int) $match[2];
        if ($kind === 'f') {
            abort_unless($this->has('resturants', 'user_id') && $actor->account_type !== 'delegate', 404);
            $query = DB::table('resturants')->where('id', $id);
            if ($lock) $query->lockForUpdate();
            $row = $query->first();
            abort_unless($row, 404);
            if ($actor->account_type === 'admin') {
                abort_unless(empty($actor->owner_resturant_id) || (int) $actor->owner_resturant_id === $id, 404);
            } elseif ($actor->account_type === 'resturant_owner') {
                $parent = (int) ($actor->owner_resturant_id ?? 0);
                abort_unless($parent > 0 && ($id === $parent || (int) ($row->parent_id ?? 0) === $parent), 404);
            } else abort_unless((int) $row->user_id === (int) $actor->id, 404);
            return $this->present($kind, $row);
        }
        abort_unless($this->goReady() && empty($actor->owner_resturant_id)
            && $actor->account_type !== 'resturant_owner', 404);
        if ($actor->account_type !== 'admin') abort_unless((int) $actor->id === $id, 404);
        $owner = DB::table('users')->where('id', $id);
        $query = DB::table('go_stores')->where('user_id', $id);
        if ($lock) { $owner->lockForUpdate(); $query->lockForUpdate(); }
        $owner = $owner->first(); $row = $query->first();
        abort_unless($owner && $row && ($owner->app_scope ?? '') === 'go_partner'
            && ($owner->status ?? '') === 'accepted' && $this->storeOwner($owner), 404);
        return $this->present($kind, $row);
    }

    public function permissions($actor): array
    {
        $actor = $this->actor($actor);
        $adminManage = $actor->account_type === 'admin' && ($this->superAdmin($actor)
            || $this->permission($actor, 'order-edit') || $this->permission($actor, 'order-create'));
        $adminWrite = $adminManage || ($actor->account_type === 'admin' && $this->permission($actor, 'order-list'));
        // The call-center can operate orders; cash/settings grants remain independent.
        $write = $actor->account_type !== 'admin' || !empty($actor->owner_resturant_id) || $adminWrite;
        return ['can_checkout'=>$write, 'can_manage_tables'=>$write, 'can_manage'=>$actor->account_type === 'resturant_owner' || $adminManage];
    }

    public function ready(): bool
    {
        return Schema::hasTable('takeaway_tills') && Schema::hasTable('takeaway_orders')
            && Schema::hasTable('takeaway_order_items') && Schema::hasTable('takeaway_till_entries');
    }

    public function has(string $table, string $column): bool
    {
        if (!array_key_exists($table, $this->columns)) $this->columns[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
        return in_array($column, $this->columns[$table], true);
    }

    private function storeOwner(object $owner): bool
    {
        if (($owner->account_type ?? '') === 'vendor') return true;
        return ($owner->account_type ?? '') === 'delegate' && !empty($owner->pending_vendor_id)
            && $this->has('pending_vendors', 'profession_key')
            && DB::table('pending_vendors')->where('id', $owner->pending_vendor_id)->where('profession_key', 'store_owner')->exists();
    }

    private function goReady(): bool
    {
        return $this->has('go_stores', 'user_id') && $this->has('users', 'account_type')
            && $this->has('users', 'app_scope') && $this->has('users', 'status');
    }

    private function present(string $kind, object $row): array
    {
        $id = $kind === 'f' ? (int) $row->id : (int) $row->user_id;
        return ['value'=>$kind.':'.$id, 'id'=>$id, 'kind'=>$kind, 'name'=>(string) ($row->name ?? ''),
            'label'=>(string) ($row->name ?? ''), 'address'=>(string) ($row->address ?? ''), 'phone'=>(string) ($row->phone ?? '')];
    }

    private function superAdmin(User $actor): bool
    {
        if ((int) $actor->id === 1) return true;
        if (!Schema::hasTable('roles') || !Schema::hasTable('model_has_roles')) return false;
        return $actor->roles()->where('guard_name', 'admin')->where('name', 'Super Admin')->exists();
    }

    public function receiverBranch($actor): ?string
    {
        $actor = $this->actor($actor);
        if ($actor->account_type === 'admin' && empty($actor->owner_resturant_id)) return null;
        $branches = $this->branches($actor);
        return count($branches) === 1 && $this->permissions($actor)['can_checkout'] ? $branches[0]['value'] : null;
    }

    private function permission(User $actor, string $name): bool
    {
        // A partially restored database may have no permission catalog yet.
        if (!Schema::hasTable('permissions') || !DB::table('permissions')->where('guard_name', 'admin')->where('name', $name)->exists()) return false;
        return $actor->can($name);
    }
}
