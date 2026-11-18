<?php

namespace App\Services\Dashboard;

final class DashboardLoginIdentity
{
    public const ACCOUNT_TYPES = ['admin', 'vendor', 'resturant_owner'];

    public static function mobile(string $value): ?string
    {
        $phone = CustomerDirectory::normalizePhone($value);
        return $phone !== null && preg_match('/^\+201[0125][0-9]{8}$/D', $phone)
            ? substr($phone, 3) : null;
    }

    public static function mobileVariants(string $value): array
    {
        $mobile = self::mobile($value);
        return $mobile === null ? [] : [$mobile, '0'.$mobile, '+20'.$mobile, '20'.$mobile, '0020'.$mobile];
    }
}
