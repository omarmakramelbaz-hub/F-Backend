<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The delivery price of a customer app order as the branch's own tariff gives it (BranchDeliveryTariff: the branch's
 * prices for the first, second and third kilometre, then its price per kilometre, from the branch to the address).
 *
 * For now it only watches: a checkout that sent less than the tariff is written to the log, never refused, so that any
 * difference between the app's sum and this one is seen before checkouts are held to it.
 */
class CustomerDeliveryCheck
{
    /** A difference of up to this (in pounds) is the app's rounding, never written down. */
    public const ROUNDING = 1.0;

    /** The tariff's price for this branch and address, or null when the branch or the address lacks what it needs. */
    public static function expected(object $branch, object $address): ?array
    {
        $coordinates = [$branch->lat ?? null, $branch->lng ?? null, $address->lat ?? null, $address->lng ?? null];
        foreach ($coordinates as $value) if (!is_numeric($value)) return null;
        [$branchLat, $branchLng, $addressLat, $addressLng] = array_map('floatval', $coordinates);
        foreach ([[$branchLat, $branchLng], [$addressLat, $addressLng]] as [$lat, $lng]) {
            if (abs($lat) > 90 || abs($lng) > 180 || ($lat === 0.0 && $lng === 0.0)) return null;
        }
        $rates = BranchDeliveryTariff::rates($branch instanceof \Illuminate\Database\Eloquent\Model ? $branch->getAttributes() : (array) $branch);
        try {
            return BranchDeliveryTariff::price(BranchDeliveryTariff::distance($branchLat, $branchLng, $addressLat, $addressLng), $rates);
        } catch (HttpExceptionInterface $incomplete) {
            return null; // the branch's tariff is not filled in: nothing to compare with
        }
    }

    /** Writes down a checkout whose delivery is below the branch's tariff; the checkout goes on either way. */
    public static function watch(object $branch, object $address, $sent, int $customerId): void
    {
        $expected = self::expected($branch, $address);
        if ($expected === null || !is_numeric($sent)) return;
        $difference = (float) $expected['delivery_fee'] - (float) $sent;
        if ($difference <= self::ROUNDING) return;
        Log::warning('GO_CUSTOMER_DELIVERY_BELOW_TARIFF', [
            'customer' => $customerId, 'branch' => (int) ($branch->id ?? 0), 'address' => (int) ($address->id ?? 0),
            'sent' => (string) $sent, 'tariff' => $expected['delivery_fee'], 'difference' => round($difference, 2),
            'distance_km' => $expected['pricing_distance_km'], 'rule' => $expected['pricing_rule'], 'tier' => $expected['pricing_tier'],
        ]);
    }
}
