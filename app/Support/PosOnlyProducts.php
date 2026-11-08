<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Items sold only by the staff at the branch (the cashier screens of the dashboard and its PC program): the «زيادة
 * وردية» items (1, 5, 10 and 100 pounds) that make up a shift's till. The customer app and the website never offer
 * them. An item is one by its own name, or by its category's name («زيادة وردية» / «زياده ورديه», «زيادة الوردية»).
 */
final class PosOnlyProducts
{
    public const PATTERN = '%زياد%ورد%';

    /** @return int[] */
    public static function ids(): array
    {
        return RequestFacts::remember('pos-only-products', function () {
            if (!Schema::hasTable('resturant_products')) return [];
            $name = Schema::hasColumn('resturant_products', 'product_name') ? 'product_name' : (Schema::hasColumn('resturant_products', 'name_ar') ? 'name_ar' : null);
            $categoryColumns = Schema::hasTable('categories') && Schema::hasColumn('resturant_products', 'category_id')
                ? array_values(array_filter(['name_ar', 'name', 'name_en'], fn ($column) => Schema::hasColumn('categories', $column))) : [];
            if ($name === null && $categoryColumns === []) return [];
            $query = DB::table('resturant_products')->where(function ($query) use ($name, $categoryColumns) {
                if ($name !== null) $query->where($name, 'like', self::PATTERN);
                if ($categoryColumns !== []) $query->orWhereIn('category_id', DB::table('categories')->where(function ($names) use ($categoryColumns) {
                    foreach ($categoryColumns as $column) $names->orWhere($column, 'like', self::PATTERN);
                })->select('id'));
            });
            return array_map('intval', $query->pluck('id')->all());
        });
    }

    public static function is($product): bool
    {
        $id = is_object($product) ? ($product->id ?? null) : $product;
        return is_numeric($id) && in_array((int) $id, self::ids(), true);
    }

    /** A list of products without the staff-only ones. */
    public static function without($products)
    {
        $ids = self::ids();
        return $ids === [] ? $products : $products->reject(fn ($product) => in_array((int) $product->id, $ids, true))->values();
    }
}
