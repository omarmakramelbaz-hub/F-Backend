<?php
namespace App\Services\GoServices;

final class WalletPolicy
{
    public const MINIMUM_CENTS = 5000;
    public const MESSAGE = 'يرجى شحن المحفظة لضمان استمرار الخدمة. الحد الأدنى لقبول الطلبات الجديدة 50 ج.م.';

    public static function summary(object $user): array
    {
        $balance = Money::minor($user->balance ?? '0');
        return ['balance' => Money::decimal($balance), 'minimum_balance' => '50.00',
            'can_accept_orders' => $balance >= self::MINIMUM_CENTS,
            'top_up_required' => Money::decimal(max(0, self::MINIMUM_CENTS - $balance)),
            'debt' => Money::decimal(max(0, -$balance)),
            'message' => $balance < self::MINIMUM_CENTS ? self::MESSAGE : null];
    }

    public static function requireMinimum(object $user): void
    {
        abort_if(Money::minor($user->balance ?? '0') < self::MINIMUM_CENTS, 409, self::MESSAGE);
    }
}
