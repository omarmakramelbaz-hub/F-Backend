<?php

namespace App\Support;

/** Fixed coverage belongs to PendingVendor, never the delegate's live GPS. */
final class PartnerWorkArea
{
    public const MAX_RADIUS_KM = 255; // Existing unsignedTinyInteger column.
    public const FIELDS = ['work_lat', 'work_lng', 'work_radius_km'];

    public static function normalize($value)
    {
        return is_string($value) ? strtr(trim($value), [
            '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4',
            '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9',
            '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4',
            '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9', '٫'=>'.',
        ]) : $value;
    }

    public static function radius($value): ?int
    {
        $value = self::normalize($value);
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]{1,3}$/D', (string) $value)) {
            return null;
        }
        $radius = (int) $value;
        return $radius >= 1 && $radius <= self::MAX_RADIUS_KM ? $radius : null;
    }

    /** Strip form-only fields before User::create/update can mass-assign them. */
    public static function take(array &$details): ?array
    {
        $values = array_intersect_key($details, array_flip(self::FIELDS));
        foreach (self::FIELDS as $field) {
            unset($details[$field]);
        }
        // Older repository callers without coverage fields leave saved coverage intact.
        if (!$values || ($details['account_type'] ?? null) !== 'delegate') {
            return null;
        }
        $lat = self::normalize($values['work_lat'] ?? null);
        $lng = self::normalize($values['work_lng'] ?? null);
        $radius = self::radius($values['work_radius_km'] ?? null);
        if (!is_numeric($lat) || !is_numeric($lng) || !is_finite((float) $lat) || !is_finite((float) $lng)
            || abs((float) $lat) > 90 || abs((float) $lng) > 180 || $radius === null) {
            throw new \InvalidArgumentException('Invalid partner work area.');
        }
        return ['lat' => round((float) $lat, 7), 'lng' => round((float) $lng, 7), 'work_radius_km' => $radius];
    }

    public static function apply(object $profile, ?array $area): void
    {
        if ($area === null) {
            return;
        }
        $profile->lat = $area['lat'];
        $profile->lng = $area['lng'];
        $profile->work_radius_km = $area['work_radius_km'];
        // Retain the legacy location field for existing dashboard readers.
        $profile->location = sprintf('%.7F, %.7F', $area['lat'], $area['lng']);
    }
}
