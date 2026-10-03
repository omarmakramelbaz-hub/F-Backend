<?php

namespace App\Services\Dashboard;

use App\Models\ResturantProduct;
use App\Services\GoServices\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Exceptions\InvalidConversion;

/** Scoped availability controls for the branch selected on the application order board. */
class OrderBoardMenu
{
    private OrderBoardService $board;
    private array $columns = [];

    public function __construct(OrderBoardService $board)
    {
        $this->board = $board;
    }

    public function listing(Request $request, $actor): array
    {
        $values = $request->validate([
            'branch'=>['required', 'regex:/^(f|gs):[0-9]+$/'],
            'search'=>'nullable|string|max:100', 'page'=>'nullable|integer|min:1',
            'per_page'=>'nullable|integer|min:1|max:40',
        ]);
        [$kind, $branchId] = explode(':', $values['branch']);
        $branch = $this->branch($kind, (int) $branchId, $actor);
        $result = ['success'=>true, 'ready'=>$this->ready($kind), 'branch'=>$branch,
            'can_toggle'=>$this->canToggle($actor), 'items'=>[], 'message'=>'',
            'pagination'=>['page'=>1, 'last_page'=>1, 'per_page'=>20, 'total'=>0, 'next_url'=>null, 'previous_url'=>null]];
        if (!$result['ready']) {
            $result['can_toggle'] = false;
            $result['message'] = 'قائمة الأصناف غير متاحة حاليًا.';
            return $result;
        }
        $query = $this->products($kind, (int) $branchId);
        $search = trim($values['search'] ?? '');
        if ($search !== '') $query->where($kind === 'gs' ? 'name' : $this->restaurantNameColumn(), 'like', '%'.$search.'%');
        $total = (clone $query)->count();
        $perPage = (int) ($values['per_page'] ?? 20);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($lastPage, (int) ($values['page'] ?? 1));
        $rows = $query->orderBy($kind === 'gs' ? 'name' : $this->restaurantNameColumn())->orderBy('id')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $images = $kind === 'f' ? $this->restaurantImages($rows->pluck('id')->all()) : [];
        foreach ($rows as $row) $result['items'][] = $this->present($kind, $row, $images[$row->id] ?? '');
        $parameters = ['branch'=>$values['branch'], 'search'=>$search, 'per_page'=>$perPage];
        $result['pagination'] = ['page'=>$page, 'last_page'=>$lastPage, 'per_page'=>$perPage, 'total'=>$total,
            'next_url'=>$page < $lastPage ? route('order-board.menu', $parameters + ['page'=>$page + 1]) : null,
            'previous_url'=>$page > 1 ? route('order-board.menu', $parameters + ['page'=>$page - 1]) : null];
        return $result;
    }

    public function setAvailability(Request $request, string $kind, int $branchId, int $productId, $actor): array
    {
        $values = $request->validate([
            'available'=>'required|boolean', 'expected_available'=>'required|boolean',
            'expected_revision'=>$kind === 'gs' ? 'required|integer|min:1' : 'nullable|integer|min:0',
        ]);
        abort_unless($this->board->canAccess($actor) && $this->canToggle($actor), 403);
        abort_unless($this->ready($kind), 503, 'قائمة الأصناف غير متاحة حاليًا.');
        $row = DB::transaction(function () use ($kind, $branchId, $productId, $actor, $values) {
            // Recheck the selected branch while holding its ownership row before locking the item.
            $this->branch($kind, $branchId, $actor, true);
            $query = $this->products($kind, $branchId)->where('id', $productId);
            $row = (clone $query)->lockForUpdate()->first();
            abort_unless($row, 404);
            $current = $kind === 'gs' ? (bool) $row->available : $row->status === 'show';
            $target = (bool) $values['available'];
            if ($current === $target) return $row;
            abort_unless($current === (bool) $values['expected_available'], 409, 'تغيرت حالة الصنف. حدّث القائمة.');
            if ($kind === 'gs') {
                abort_unless((int) $row->revision === (int) $values['expected_revision'], 409, 'تم تعديل الصنف. حدّث القائمة.');
                $changes = ['available'=>$target, 'revision'=>(int) $row->revision + 1];
            } else {
                abort_unless(in_array($row->status, ['show', 'hide'], true), 409, 'حالة الصنف غير متاحة للتعديل.');
                $changes = ['status'=>$target ? 'show' : 'hide'];
            }
            $table = $kind === 'gs' ? 'go_store_products' : 'resturant_products';
            if ($this->has($table, 'updated_at')) $changes['updated_at'] = now();
            $query->update($changes);
            return $this->products($kind, $branchId)->where('id', $productId)->first();
        }, 3);
        $images = $kind === 'f' ? $this->restaurantImages([$productId]) : [];
        return ['success'=>true, 'message'=>$values['available'] ? 'تم تشغيل الصنف.' : 'تم إيقاف الصنف.',
            'item'=>$this->present($kind, $row, $images[$productId] ?? '')];
    }

    public function canToggle($actor): bool
    {
        if (!$actor) return false;
        if ($this->board->isAdmin($actor)) return (int) $actor->id === 1 || $actor->can('resturant-edit');
        return $this->board->canAccess($actor);
    }

    private function branch(string $kind, int $id, $actor, bool $lock = false): array
    {
        abort_unless($this->board->canAccess($actor), 403);
        abort_unless(in_array($kind, ['f', 'gs'], true), 404);
        if ($kind === 'f') {
            abort_unless($this->has('resturants', 'id'), 404);
            $query = DB::table('resturants')->where('id', $id);
            if ($lock) $query->lockForUpdate();
            $row = $query->first();
            abort_unless($row && in_array($id, array_map('intval', $this->board->restaurantIds($actor)), true), 404);
            return ['value'=>'f:'.$id, 'kind'=>'f', 'id'=>$id, 'label'=>$row->name ?? 'الفرع'];
        }
        // GO catalog store_id is the owner's users.id, not a profile's own row ID.
        abort_if($actor->account_type === 'admin' && !empty($actor->owner_resturant_id), 404);
        if (!$this->board->isAdmin($actor)) abort_unless((int) $actor->id === $id, 404);
        abort_unless($this->has('go_stores', 'user_id'), 404);
        $ownerQuery = DB::table('users')->where('id', $id);
        if ($lock) $ownerQuery->lockForUpdate();
        $owner = $ownerQuery->first();
        $query = DB::table('go_stores')->where('user_id', $id);
        if ($lock) $query->lockForUpdate();
        $row = $query->first();
        abort_unless($row && $owner && ($owner->app_scope ?? '') === 'go_partner' && $this->isStoreOwner($owner), 404);
        return ['value'=>'gs:'.$id, 'kind'=>'gs', 'id'=>$id, 'label'=>$row->name ?? 'المتجر'];
    }

    private function products(string $kind, int $branchId)
    {
        return $kind === 'gs' ? DB::table('go_store_products')->where('user_id', $branchId)
            : DB::table('resturant_products')->where('resturant_id', $branchId);
    }

    private function isStoreOwner(object $owner): bool
    {
        if (($owner->account_type ?? '') === 'vendor') return true;
        return ($owner->account_type ?? '') === 'delegate' && !empty($owner->pending_vendor_id)
            && $this->has('pending_vendors', 'profession_key')
            && DB::table('pending_vendors')->where('id', $owner->pending_vendor_id)->where('profession_key', 'store_owner')->exists();
    }

    private function present(string $kind, object $row, string $image): array
    {
        if ($kind === 'gs') {
            $options = [];
            foreach (json_decode($row->options ?? '[]', true) ?: [] as $option) {
                $options[] = ['id'=>$option['id'] ?? '', 'label'=>$option['label'] ?? '',
                    'price'=>isset($option['price_cents']) ? Money::decimal((int) $option['price_cents']) : null];
            }
            return ['id'=>(int) $row->id, 'name'=>$row->name, 'price'=>Money::decimal((int) $row->price_cents),
                'image_url'=>!empty($row->image_path) ? Storage::disk('public')->url($row->image_path) : '',
                'available'=>(bool) $row->available, 'status'=>$row->available ? 'show' : 'hide',
                'revision'=>(int) $row->revision, 'unit'=>$row->unit ?? '', 'options'=>$options];
        }
        return ['id'=>(int) $row->id, 'name'=>$row->{$this->restaurantNameColumn()},
            'price'=>isset($row->product_price) ? number_format((float) $row->product_price, 2, '.', '') : null,
            'image_url'=>$image, 'available'=>$row->status === 'show', 'status'=>$row->status,
            'revision'=>null, 'unit'=>'', 'options'=>[]];
    }

    private function restaurantImages(array $ids): array
    {
        if (!$ids || !$this->has('media', 'model_id') || !$this->has('media', 'model_type')) return [];
        $images = [];
        foreach (ResturantProduct::withoutGlobalScopes()->whereIn('id', $ids)->with('media')->get() as $item) {
            try {
                $images[$item->id] = $item->getFirstMediaUrl('product_image', 'thumb');
            } catch (InvalidConversion $error) {
                // Older uploads retain thumbnail metadata even when the model no longer declares it.
                $images[$item->id] = $item->getFirstMediaUrl('product_image');
            }
        }
        return $images;
    }

    private function restaurantNameColumn(): string
    {
        return $this->has('resturant_products', 'product_name') ? 'product_name' : 'name_ar';
    }

    private function ready(string $kind): bool
    {
        $table = $kind === 'gs' ? 'go_store_products' : 'resturant_products';
        $required = $kind === 'gs' ? ['id','user_id','name','price_cents','available','revision'] : ['id','resturant_id','status'];
        foreach ($required as $column) if (!$this->has($table, $column)) return false;
        return $kind === 'gs' || $this->has($table, 'product_name') || $this->has($table, 'name_ar');
    }

    private function has(string $table, string $column): bool
    {
        if (!array_key_exists($table, $this->columns)) $this->columns[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
        return in_array($column, $this->columns[$table], true);
    }
}
