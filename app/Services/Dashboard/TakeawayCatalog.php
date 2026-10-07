<?php

namespace App\Services\Dashboard;

use App\Models\ResturantProduct;
use App\Services\GoServices\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\InvalidConversion;

class TakeawayCatalog
{
    private TakeawayAccess $access;
    private ?array $listingFeatures = null;
    private const CLEAN = ['extra_clear'=>'تنظيف إضافي', 'extra_clean'=>'تنظيف', 'extra_vacuim'=>'تغليف مفرغ'];
    private const FEATURES = ['half'=>'نصف', 'quarter'=>'ربع', 'combo'=>'كومبو', 'large'=>'كبير', 'medium'=>'وسط'];

    public function __construct(TakeawayAccess $access) { $this->access = $access; }

    public function listing(array $values, $actor): array
    {
        $branch = $this->access->branch($values['branch'], $actor);
        $this->requireCatalog($branch);
        $query = $this->query($branch);
        $search = trim($values['search'] ?? '');
        if ($search !== '') $query->where($this->nameColumn($branch), 'like', '%'.$search.'%');
        if (!empty($values['category_id'])) {
            abort_unless($branch['kind'] === 'f' && $this->access->has('resturant_products', 'category_id'), 422);
            $query->where('category_id', $values['category_id']);
        }
        $total = (clone $query)->count();
        $perPage = (int) ($values['per_page'] ?? 40);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($lastPage, (int) ($values['page'] ?? 1));
        $rows = $query->orderBy($this->nameColumn($branch))->orderBy('id')->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $images = $branch['kind'] === 'f' ? $this->images($rows->pluck('id')->all()) : [];
        $this->listingFeatures = [];
        if ($branch['kind'] === 'f' && $this->access->has('product_features', 'product_id')) {
            $productIds = $rows->pluck('product_id')->filter()->unique()->all();
            foreach (DB::table('product_features')->whereIn('product_id', $productIds)->whereIn('name', array_keys(self::FEATURES))->orderBy('id')->get() as $feature) {
                $this->listingFeatures[(int) $feature->id] = $feature;
            }
        }
        $items = [];
        foreach ($rows as $row) $items[] = $this->present($branch, $row, $images[$row->id] ?? '');
        $items=app(BranchStock::class)->decorate($branch['value'],$items);
        $this->listingFeatures = null;
        return ['success'=>true, 'ready'=>true, 'branch'=>$branch, 'items'=>$items, 'products'=>$items,
            'categories'=>$this->categories($branch),
            'pagination'=>['page'=>$page, 'last_page'=>$lastPage, 'per_page'=>$perPage, 'total'=>$total]];
    }

    /** Product ids always bind to the selected branch; locks are acquired in id order. */
    public function rows(array $branch, array $ids, bool $lock): array
    {
        $this->requireCatalog($branch);
        sort($ids, SORT_NUMERIC);
        $query = $this->query($branch)->whereIn('id', array_values(array_unique($ids)))->orderBy('id');
        if ($lock) $query->lockForUpdate();
        $rows = [];
        foreach ($query->get() as $row) $rows[(int) $row->id] = $row;
        return $rows;
    }

    public function line(array $branch, object $row, array $item, bool $lock = false): array
    {
        $available = $branch['kind'] === 'f' ? ($row->status ?? '') === 'show' : (bool) ($row->available ?? false);
        abort_unless($available, 409, 'أحد الأصناف غير متاح الآن. حدّث القائمة.');
        try {
            [$price, $optionId, $label] = $this->price($branch, $row, $item, $lock);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['items'=>'سعر الصنف أو الخيار غير صالح.']);
        }
        $quantity = (int) $item['quantity_millis'];
        $total = intdiv($price * $quantity + 500, 1000);
        abort_unless($total <= 100000000, 422, 'قيمة الصنف أكبر من الحد المسموح.');
        return ['product_id'=>(int) $row->id, 'name'=>(string) $row->{$this->nameColumn($branch)},
            'option_id'=>$optionId, 'option_label'=>$label, 'unit'=>$branch['kind'] === 'gs' ? (string) ($row->unit ?? '') : '',
            'quantity_mode'=>$item['quantity_mode'], 'quantity_millis'=>$quantity,
            'quantity'=>$this->quantity($quantity), 'unit_price_cents'=>$price, 'unit_price'=>Money::decimal($price),
            'total_cents'=>$total, 'total'=>Money::decimal($total)];
    }

    public function quantity(int $millis): string
    {
        return intdiv($millis, 1000).'.'.str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function price(array $branch, object $row, array $item, bool $lock): array
    {
        $optionId = (string) ($item['option_id'] ?? '');
        if ($branch['kind'] === 'gs') {
            abort_unless(empty($item['feature_id']) && empty($item['product_clean']), 422, 'الخيار لا ينتمي إلى الصنف.');
            $price = (int) $row->price_cents; $label = '';
            if ($optionId !== '') {
                $found = false;
                foreach ($this->goOptions($row) as $option) if ($option['id'] === $optionId) {
                    $price = $option['price_cents']; $label = $option['label']; $found = true; break;
                }
                abort_unless($found, 422, 'الخيار لا ينتمي إلى الصنف.');
            }
            $this->validPrice($price);
            return [$price, $optionId, $label];
        }
        $price = Money::minor($row->product_price ?? '');
        $featureId = (int) ($item['feature_id'] ?? 0);
        $clean = (string) ($item['product_clean'] ?? '');
        if ($optionId !== '') {
            abort_unless(preg_match('/^f:([0-9]+):(base|extra_clear|extra_clean|extra_vacuim)$/D', $optionId, $match), 422, 'الخيار لا ينتمي إلى الصنف.');
            $parsedClean = $match[2] === 'base' ? '' : $match[2];
            abort_unless((!$featureId || $featureId === (int) $match[1]) && (!$clean || $clean === $parsedClean), 422);
            $featureId = (int) $match[1]; $clean = $parsedClean;
        }
        $extras = json_decode((string) ($row->price ?? '{}'), true);
        $extras = is_array($extras) ? $extras : [];
        $labels = [];
        if ($clean !== '') {
            abort_unless(isset(self::CLEAN[$clean]) && array_key_exists($clean, $extras) && $extras[$clean] !== null && $extras[$clean] !== '', 422, 'الإضافة غير متاحة للصنف.');
            $extra = Money::minor($extras[$clean]); $this->validPrice($extra);
            $price += $extra; $labels[] = self::CLEAN[$clean];
        }
        if ($featureId > 0) {
            abort_unless($this->access->has('product_features', 'product_id') && !empty($row->product_id), 422, 'الخيار لا ينتمي إلى الصنف.');
            if (!$lock && $this->listingFeatures !== null) {
                $feature = $this->listingFeatures[$featureId] ?? null;
                if ($feature && (int) $feature->product_id !== (int) $row->product_id) $feature = null;
            } else {
                $query = DB::table('product_features')->where('id', $featureId)->where('product_id', $row->product_id);
                if ($lock) $query->lockForUpdate();
                $feature = $query->first();
            }
            abort_unless($feature && isset(self::FEATURES[$feature->name]), 422, 'الخيار لا ينتمي إلى الصنف.');
            $name = $feature->name;
            if ($name === 'half') $price = intdiv($price + 1, 2);
            elseif ($name === 'quarter') $price = intdiv($price + 2, 4);
            else {
                $key = 'extra_'.$name;
                abort_unless(array_key_exists($key, $extras) && $extras[$key] !== null && $extras[$key] !== '', 422, 'سعر الخيار غير مضبوط.');
                $extra = Money::minor($extras[$key]); $this->validPrice($extra); $price += $extra;
            }
            array_unshift($labels, self::FEATURES[$name]);
        }
        $this->validPrice($price);
        return [$price, $featureId || $clean ? 'f:'.$featureId.':'.($clean ?: 'base') : '', implode(' / ', $labels)];
    }

    private function present(array $branch, object $row, string $image): array
    {
        $available = $branch['kind'] === 'f' ? ($row->status ?? '') === 'show' : (bool) ($row->available ?? false);
        $price = null;
        try { [$cents] = $this->price($branch, $row, [], false); $price = Money::decimal($cents); }
        catch (\Throwable $error) { $available = false; }
        $options = [];
        if ($branch['kind'] === 'gs') foreach ($this->goOptions($row) as $option) {
            $options[] = ['id'=>$option['id'], 'label'=>$option['label'], 'price'=>Money::decimal($option['price_cents'])];
        } else {
            $features = [0];
            if (!empty($row->product_id) && $this->access->has('product_features', 'product_id')) {
                foreach ($this->listingFeatures ?? [] as $feature) if ((int) $feature->product_id === (int) $row->product_id) $features[] = (int) $feature->id;
            }
            foreach ($features as $featureId) foreach (array_merge([''], array_keys(self::CLEAN)) as $clean) {
                if (!$featureId && !$clean) continue;
                try {
                    [$cents, $id, $label] = $this->price($branch, $row, ['feature_id'=>$featureId, 'product_clean'=>$clean], false);
                    $options[] = ['id'=>$id, 'label'=>$label, 'price'=>Money::decimal($cents), 'feature_id'=>(int) $featureId, 'product_clean'=>$clean];
                } catch (\Throwable $error) { /* Unconfigured choices are not offered for sale. */ }
            }
        }
        return ['id'=>(int) $row->id, 'name'=>(string) $row->{$this->nameColumn($branch)}, 'price'=>$price, 'unit_price'=>$price,
            'available'=>$available, 'image_url'=>$branch['kind'] === 'gs' && !empty($row->image_path) ? Storage::disk('public')->url($row->image_path) : $image,
            'unit'=>$branch['kind'] === 'gs' ? (string) ($row->unit ?? '') : '', 'quantity_mode'=>'select',
            'category_id'=>$row->category_id ?? null, 'options'=>$options, 'revision'=>$row->revision ?? null];
    }

    private function goOptions(object $row): array
    {
        $options = json_decode((string) ($row->options ?? '[]'), true); $result = [];
        foreach (is_array($options) ? $options : [] as $option) {
            if (!is_array($option) || empty($option['id']) || !is_string($option['id']) || !isset($option['price_cents'])
                || !is_int($option['price_cents']) || $option['price_cents'] < 0 || $option['price_cents'] > 100000000) continue;
            $result[] = ['id'=>$option['id'], 'label'=>(string) ($option['label'] ?? ''), 'price_cents'=>$option['price_cents']];
        }
        return $result;
    }

    private function categories(array $branch): array
    {
        if ($branch['kind'] !== 'f' || !$this->access->has('resturant_products', 'category_id') || !$this->access->has('categories', 'id')) return [];
        $ids = $this->query($branch)->whereNotNull('category_id')->distinct()->pluck('category_id')->all();
        $column = app()->getLocale() === 'ar' ? 'name_ar' : 'name_en';
        if (!$this->access->has('categories', $column)) $column = $this->access->has('categories', 'name_ar') ? 'name_ar' : 'name';
        if (!$this->access->has('categories', $column)) return [];
        return DB::table('categories')->whereIn('id', $ids)->orderBy($column)->get()->map(fn ($row) => ['id'=>(int) $row->id, 'name'=>(string) $row->{$column}])->all();
    }

    private function requireCatalog(array $branch): void
    {
        $table = $branch['kind'] === 'gs' ? 'go_store_products' : 'resturant_products';
        abort_unless($this->access->has($table, $branch['kind'] === 'gs' ? 'price_cents' : 'product_price')
            && $this->access->has($table, $branch['kind'] === 'gs' ? 'user_id' : 'resturant_id'), 503, 'قائمة الفرع غير متاحة.');
    }

    private function query(array $branch)
    {
        return $branch['kind'] === 'gs' ? DB::table('go_store_products')->where('user_id', $branch['id'])
            : DB::table('resturant_products')->where('resturant_id', $branch['id']);
    }

    private function nameColumn(array $branch): string
    {
        return $branch['kind'] === 'gs' ? 'name' : ($this->access->has('resturant_products', 'product_name') ? 'product_name' : 'name_ar');
    }

    private function validPrice(int $price): void
    {
        if ($price < 0 || $price > 100000000) throw new \InvalidArgumentException('Invalid POS price');
    }

    private function images(array $ids): array
    {
        if (!$ids || !$this->access->has('media', 'model_id') || !$this->access->has('media', 'model_type')) return [];
        $images = [];
        foreach (ResturantProduct::withoutGlobalScopes()->whereIn('id', $ids)->with('media')->get() as $item) {
            try { $images[$item->id] = $item->getFirstMediaUrl('product_image', 'thumb'); }
            catch (InvalidConversion $error) { $images[$item->id] = $item->getFirstMediaUrl('product_image'); }
        }
        return $images;
    }
}
