<?php

namespace App\Services\Dashboard;

/** The primary system owner is distinct from other accounts in the legacy admin cohort. */
final class DashboardAccountRole
{
    public static function isOwner(object $user): bool
    {
        return (int) $user->id === 1 && $user->account_type === 'admin';
    }

    public static function label(object $user): string
    {
        return self::isOwner($user)
            ? (app()->getLocale() === 'ar' ? 'أونر — مالك النظام' : 'Owner — System owner')
            : trans('main.'.$user->account_type);
    }
}
