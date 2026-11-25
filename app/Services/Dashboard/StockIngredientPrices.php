<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB, Schema, Validator};

/**
 * Owner-only cost and selling prices for goods items, one price per kilo or piece for all branches,
 * and the owner's switch of an item between weight and pieces.
 */
class StockIngredientPrices
{
    private ?bool $ready = null;

    public function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('stock_ingredient_prices') && Schema::hasTable('stock_ingredients');
    }

    public function canManage($actor): bool
    {
        return $actor && DashboardAccountRole::isOwner(app(TakeawayAccess::class)->actor($actor));
    }

    private function pounds(?int $cents): ?string
    {
        if ($cents === null) return null;
        return intdiv($cents, 100).($cents % 100 ? '.'.str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT) : '');
    }

    private function cents($value): ?int
    {
        if ($value === null || trim((string)$value) === '') return null;
        $text = \App\Support\Quantity::text((string)$value);
        abort_unless(preg_match('/^[0-9]{1,7}(?:\.[0-9]{1,2})?$/D', $text), 422, 'أدخل السعر بالجنيه، حتى رقمين بعد العلامة.');
        $p = explode('.', $text);
        return (int)$p[0] * 100 + (int)str_pad($p[1] ?? '', 2, '0');
    }

    private function present(?object $r): array
    {
        $cost = $r && $r->cost_cents !== null ? (int)$r->cost_cents : null;
        $price = $r && $r->price_cents !== null ? (int)$r->price_cents : null;
        return [
            'cost' => $this->pounds($cost), 'price' => $this->pounds($price), 'revision' => (int)($r->revision ?? 0),
            // Profit as a share of the selling price.
            'margin' => $cost !== null && $price ? round(($price - $cost) * 100 / $price, 1) : null,
        ];
    }

    /** Adds prices and the stock value (balance × cost) for the owner; other accounts get the listing unchanged. */
    public function decorate(array $data, $actor): array
    {
        if (!$this->ready() || !$this->canManage($actor) || empty($data['items'])) return $data;
        $rows = DB::table('stock_ingredient_prices')->whereIn('ingredient_id', array_column($data['items'], 'id'))->get()->keyBy('ingredient_id')->all();
        $value = 0;
        foreach ($data['items'] as &$item) {
            $r = $rows[$item['id']] ?? null;
            $item['prices'] = $this->present($r);
            $quantity = (string)($item['stock']['quantity'] ?? '0');
            if ($r && $r->cost_cents !== null && $quantity !== '' && $quantity[0] !== '-') {
                $parts = explode('.', $quantity);
                $units = (int)$parts[0] * 1000000 + (int)str_pad($parts[1] ?? '', 6, '0');
                $value += intdiv($units * (int)$r->cost_cents, 1000000);
            }
        }
        unset($item);
        $data['can_manage_prices'] = true;
        $data['stock_value_cents'] = $value;
        return $data;
    }

    public function save(array $values, $actor): array
    {
        abort_unless($this->ready(), 503, 'أسعار البضاعة تحتاج تحديث قاعدة البيانات.');
        abort_unless($this->canManage($actor), 403, 'أسعار البضاعة متاحة للأونر فقط.');
        $v = Validator::make($values, [
            'ingredient_id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:0',
            'cost' => 'present|nullable|string|max:16', 'price' => 'present|nullable|string|max:16',
        ])->validate();
        $cost = $this->cents($v['cost']);
        $price = $this->cents($v['price']);
        $actorId = (int)app(TakeawayAccess::class)->actor($actor)->id;
        return DB::transaction(function () use ($v, $cost, $price, $actorId) {
            abort_unless(DB::table('stock_ingredients')->where('id', $v['ingredient_id'])->exists(), 404, 'صنف البضاعة غير موجود.');
            $q = DB::table('stock_ingredient_prices')->where('ingredient_id', $v['ingredient_id']);
            $r = (clone $q)->lockForUpdate()->first();
            abort_unless((int)$v['expected_revision'] === (int)($r->revision ?? 0), 409, 'السعر اتغير؛ حدّث الصفحة قبل الحفظ.');
            $data = ['cost_cents' => $cost, 'price_cents' => $price, 'revision' => (int)($r->revision ?? 0) + 1, 'actor_id' => $actorId, 'updated_at' => now('UTC')];
            if ($r) $q->update($data);
            else DB::table('stock_ingredient_prices')->insert($data + ['ingredient_id' => $v['ingredient_id'], 'created_at' => now('UTC')]);
            return ['success' => true, 'prices' => $this->present((clone $q)->first())];
        }, 3);
    }

    /** Weight or pieces. Refused while a recipe, an expense purchase or a fractional balance depends on the current unit. */
    public function saveUnit(array $values, $actor): array
    {
        abort_unless($this->canManage($actor), 403, 'تغيير نوع الصنف متاح للأونر فقط.');
        $v = Validator::make($values, ['ingredient_id' => 'required|integer|min:1', 'unit' => 'required|in:kg,piece'])->validate();
        return DB::transaction(function () use ($v) {
            $i = DB::table('stock_ingredients')->where('id', $v['ingredient_id'])->lockForUpdate()->first();
            abort_unless($i, 404, 'صنف البضاعة غير موجود.');
            $label = $v['unit'] === 'kg' ? 'خامة بالوزن' : 'بضاعة بالقطعة';
            if ($i->unit === $v['unit']) return ['success' => true, 'unit' => $i->unit, 'label' => $label];
            abort_if(in_array($i->name, ExpenseStockPurchases::CATEGORIES, true), 422, 'الصنف ده مربوط بمشتريات المصروفات بالكيلو، فمينفعش يتغير.');
            if (Schema::hasTable('branch_stock_recipes')) {
                $id = (int)$i->id;
                $used = DB::table('branch_stock_recipes')->where(function ($q) use ($id) {
                    $q->where('variants', 'like', '%"ingredient_id":'.$id.',%')->orWhere('variants', 'like', '%"ingredient_id":'.$id.'}%');
                })->exists();
                abort_if($used, 422, 'الصنف ده داخل في وصفة منتج في المينيو. شيله من الوصفة الأول وبعدين غيّر نوعه.');
            }
            if ($v['unit'] === 'piece' && Schema::hasTable('branch_inventory')) {
                $fraction = DB::table('branch_inventory')->where('ingredient_id', $i->id)->whereRaw('MOD(quantity_units, 1000000) <> 0')->exists();
                abort_if($fraction, 422, 'رصيد الصنف فيه كسور كيلو في أحد الفروع. عدّل الرصيد لعدد صحيح الأول.');
            }
            DB::table('stock_ingredients')->where('id', $i->id)->update(['unit' => $v['unit'], 'updated_at' => now('UTC')]);
            if (Schema::hasTable('branch_stock_minimums')) {
                // A minimum kept in the old unit stops alerting by itself; say so by clearing it.
                DB::table('branch_stock_minimums')->where('stock_type', 'ingredient')->where('stock_item_id', $i->id)->update(['minimum_units' => null, 'active_low' => false, 'revision' => DB::raw('revision + 1')]);
            }
            return ['success' => true, 'unit' => $v['unit'], 'label' => $label];
        }, 3);
    }
}
