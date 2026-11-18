<?php
namespace App\Services\Dashboard;

use App\Services\GoServices\Money;

/** Branch-only counterpart of F-customer's delivery calculation. */
final class BranchDeliveryTariff
{
    public const EARTH_RADIUS = 6378137.0;
    public const TIERS = ['default_0_1', 'default_1_2', 'default_2_3'];

    public static function rates($source): array
    {
        $source = (array) $source;
        $rates = ['km_price' => self::money($source['km_price'] ?? null)];
        $hasTiers = false;
        foreach (self::TIERS as $field) {
            $hasTiers = $hasTiers || array_key_exists($field, $source);
            $rates[$field] = self::money($source[$field] ?? null);
        }
        // Older schemas without tariff columns keep their explicit per-km rate.
        // A present but empty tariff is never treated as a zero-priced delivery.
        $rates['legacy'] = !$hasTiers;
        return $rates;
    }

    private static function money($value): ?string
    {
        if ($value === null || $value === '') return null;
        try {
            $cents = Money::minor($value);
            return $cents >= 0 && $cents <= 100000000 ? Money::decimal($cents) : null;
        } catch (\InvalidArgumentException $error) {
            return null;
        }
    }

    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        // geolocator_platform_interface 4.2.6: the app bills this direct distance.
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2
            + sin($lngDelta / 2) ** 2 * cos(deg2rad($lat1)) * cos(deg2rad($lat2));
        return self::EARTH_RADIUS * 2 * asin(sqrt(max(0, min(1, $a))));
    }

    public static function price(float $distanceMeters, array $rates): array
    {
        abort_unless(is_finite($distanceMeters) && $distanceMeters >= 0, 422, 'مسافة التوصيل غير صالحة.');
        abort_unless(isset($rates['km_price']), 422, 'حدد سعر التوصيل في بيانات الفرع أولًا.');
        $km = $distanceMeters / 1000;
        $perKm = Money::minor($rates['km_price']);
        $tier = null;
        if ($perKm === 0) {
            $cents = 0;
            $rule = 'app_zero_rate';
        } elseif (!empty($rates['legacy'])) {
            $cents = (int) round($km * $perKm);
            $rule = 'branch_per_km_legacy';
        } else {
            // Preserve the existing app's nearest-kilometre tier selection.
            // Its rounded 3-km case must also use this branch, never global settings.
            $rounded = (int) floor($km + 0.5);
            $tier = [0 => 'default_0_1', 1 => 'default_1_2', 2 => 'default_2_3', 3 => 'default_2_3'][$rounded] ?? null;
            if ($tier !== null) {
                abort_unless(isset($rates[$tier]), 422, 'أكمل شرائح سعر التوصيل في بيانات الفرع أولًا.');
                $cents = Money::minor($rates[$tier]);
                $rule = 'app_branch_tier';
            } else {
                $cents = (int) round($km * $perKm);
                $rule = 'app_per_km';
            }
        }
        abort_unless($cents <= 100000000, 422, 'قيمة التوصيل أكبر من الحد المسموح.');
        return ['delivery_fee' => Money::decimal($cents), 'pricing_rule' => $rule,
            'pricing_tier' => $tier, 'pricing_distance_meters' => $distanceMeters,
            'pricing_distance_km' => number_format($km, 6, '.', ''), 'pricing_rates' => $rates];
    }
}
