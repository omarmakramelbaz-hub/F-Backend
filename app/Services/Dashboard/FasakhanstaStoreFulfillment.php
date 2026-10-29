<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB, Schema};

/** Stock and accounting destination only; the order and its public store identity stay unchanged. */
class FasakhanstaStoreFulfillment
{
    public const RESTAURANT_ID = 301;
    public const BRANCH = 'f:301';

    public static function branch(string $origin): string
    {
        // Go stores and noncanonical identifiers never enter the restaurant namespace.
        if (!preg_match('/\Af:([1-9][0-9]{0,18})\z/', $origin, $match)
            || (string) (int) $match[1] !== $match[1]) return $origin;
        return 'f:'.self::restaurantId((int) $match[1]);
    }

    public static function restaurantId(int $origin): int
    {
        if ($origin < 1 || in_array($origin, FasakhanstaDeliveryOrigin::PHYSICAL_BRANCH_IDS, true)) return $origin;
        $row = DB::table('resturants')->where('id', $origin)->first();
        return $row && self::isStore($row) ? self::RESTAURANT_ID : $origin;
    }

    /** Accounting ownership: a store contributes to Shubra, and is not counted twice as a branch. */
    public static function sourceRestaurantIdsForBranch(int $branchId): array
    {
        if ($branchId !== self::RESTAURANT_ID) {
            return self::restaurantId($branchId) === $branchId ? [$branchId] : [];
        }
        $ids = [self::RESTAURANT_ID];
        foreach (DB::table('resturants')->where(function ($query) {
            $query->where('parent_id', self::RESTAURANT_ID)
                ->orWhereIn('id', array_keys(FasakhanstaDeliveryOrigin::CAIRO_ORIGINS));
        })->orderBy('id')->get() as $row) {
            if (self::isStore($row)) $ids[] = (int) $row->id;
        }
        return array_values(array_unique($ids));
    }

    private static function isStore(object $row): bool
    {
        $id = (int) $row->id;
        if (in_array($id, FasakhanstaDeliveryOrigin::PHYSICAL_BRANCH_IDS, true)) return false;
        $parent = (int) ($row->parent_id ?? 0);
        // Live direct children are authoritative, including stores created after this release.
        if ($parent === self::RESTAURANT_ID) return true;
        // Older retail identities can have no parent; never override a different actual parent.
        return $parent === 0
            && FasakhanstaDeliveryOrigin::matchesRegisteredName('f:'.$id, (string) ($row->name ?? ''));
    }

    /**
     * Translate order lines into Shubra SKUs for inventory only. Prices, quantities, labels and
     * the caller's order identity are preserved. Call before creating a fresh inventory snapshot.
     * Unknown or ambiguous product/portion links fail before any stock can be deducted.
     */
    public static function inventoryLines(string $origin, array $lines): array
    {
        $target = self::branch($origin);
        if ($target === $origin || !$lines) return $lines;
        abort_unless(DB::table('resturants')->where('id', self::RESTAURANT_ID)->exists(), 422, 'فرع شبرا غير متاح لتنفيذ طلب الإستور.');
        $sourceId = (int) substr($origin, 2);
        $products = DB::table('resturant_products')->where('resturant_id', $sourceId)
            ->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');
        $targets = DB::table('resturant_products')->where('resturant_id', self::RESTAURANT_ID)->get();
        $shared = []; $names = [];
        foreach ($targets as $product) {
            if ((int) ($product->product_id ?? 0) > 0) $shared[(int) $product->product_id][] = $product;
            $name = self::normalizedName((string) ($product->product_name ?? ''));
            if ($name !== '') $names[$name][] = $product;
        }
        $mappedProducts = []; $mappedFeatures = [];
        foreach ($lines as &$line) {
            $sourceProductId = (int) ($line['product_id'] ?? 0);
            $source = $products[$sourceProductId] ?? null;
            abort_unless($source, 422, 'صنف الإستور غير مرتبط بمينيو الإستور: #'.$sourceProductId);
            if (!isset($mappedProducts[$sourceProductId])) {
                $candidates = (int) ($source->product_id ?? 0) > 0 ? ($shared[(int) $source->product_id] ?? []) : [];
                if (count($candidates) > 1) {
                    // The shared product identifies a family: regular/plus/top meals,
                    // jar weights and herring varieties can share it. Resolve only an
                    // exact displayed SKU within that family; never choose its first row.
                    $name = self::normalizedName((string) ($source->product_name ?? ''));
                    $named = array_values(array_filter($candidates, static fn ($candidate) => $name !== ''
                        && self::normalizedName((string) ($candidate->product_name ?? '')) === $name));
                    if ($named) $candidates = $named;
                }
                if (!$candidates) $candidates = $names[self::normalizedName((string) ($source->product_name ?? ''))] ?? [];
                abort_unless(count($candidates) === 1, 422, (count($candidates) > 1
                    ? 'يوجد أكثر من ربط لصنف الإستور في فرع شبرا: '
                    : 'يلزم ربط صنف الإستور ببضاعة فرع شبرا: ').(string) $source->product_name);
                $mappedProducts[$sourceProductId] = $candidates[0];
            }
            $destination = $mappedProducts[$sourceProductId];
            $featureId = (int) ($line['feature_id'] ?? 0); $option = null;
            if (!empty($line['option_id'])) {
                abort_unless(preg_match('/\Af:([0-9]+):(base|extra_clear|extra_clean|extra_vacuim)\z/', (string) $line['option_id'], $option), 422, 'خيار صنف الإستور غير صالح.');
                abort_unless($featureId === 0 || $featureId === (int) $option[1], 422, 'حجم صنف الإستور لا يطابق الخيار المسجل.');
                $featureId = (int) $option[1];
            }
            $featureKey = $sourceProductId.':'.$featureId;
            if (!array_key_exists($featureKey, $mappedFeatures)) {
                $mappedFeatures[$featureKey] = self::featureId($source, $destination, $featureId);
            }
            $destinationFeature = $mappedFeatures[$featureKey];
            $line['origin_branch'] = $origin;
            $line['origin_product_id'] = $sourceProductId;
            $line['origin_feature_id'] = $featureId;
            $line['product_id'] = (int) $destination->id;
            $line['feature_id'] = $destinationFeature;
            if ($option) $line['option_id'] = 'f:'.$destinationFeature.':'.$option[2];
            // A snapshot made for the store must never be trusted as a Shubra recipe.
            unset($line['inventory']);
        }
        unset($line);
        return $lines;
    }

    private static function featureId(object $source, object $target, int $id): int
    {
        if ($id === 0) return 0;
        abort_unless($id > 0 && (int) ($source->product_id ?? 0) > 0
            && (int) ($target->product_id ?? 0) > 0 && Schema::hasTable('product_features'), 422, 'يلزم ربط حجم صنف الإستور بصنف فرع شبرا: '.(string) $source->product_name);
        $feature = DB::table('product_features')->where('id', $id)->where('product_id', $source->product_id)->first();
        abort_unless($feature, 422, 'حجم صنف الإستور لا ينتمي للصنف: '.(string) $source->product_name);
        $matches = [];
        $name = self::normalizedName((string) $feature->name);
        foreach (DB::table('product_features')->where('product_id', $target->product_id)->get() as $candidate) {
            if ($name !== '' && self::normalizedName((string) $candidate->name) === $name) $matches[] = $candidate;
        }
        abort_unless(count($matches) === 1, 422, (count($matches) > 1
            ? 'يوجد أكثر من ربط لحجم صنف الإستور في فرع شبرا: '
            : 'يلزم ربط حجم صنف الإستور في فرع شبرا: ').(string) $source->product_name.' / '.(string) $feature->name);
        return (int) $matches[0]->id;
    }

    private static function normalizedName(string $name): string
    {
        // Exact text matching after presentation-only cleanup; weights, punctuation and words stay.
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[ـ\x{064B}-\x{065F}]/u', '', $name))), 'UTF-8');
    }
}
