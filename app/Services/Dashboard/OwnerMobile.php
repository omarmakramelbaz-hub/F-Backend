<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class OwnerMobile
{
    public function update(?object $actor, string $value, #[\SensitiveParameter] string $password): string
    {
        abort_unless($actor && (int) $actor->id === 1 && $actor->account_type === 'admin', 403);
        abort_if(config('desktop_dashboard.local'), 403, 'تعديل رقم دخول الأونر يتم من الداشبورد المتصل بالسيرفر.');
        $mobile = DashboardLoginIdentity::mobile($value);
        if ($mobile === null) {
            throw ValidationException::withMessages(['mobile' => 'اكتب رقم موبايل مصري صحيح، مثل 01012345678.']);
        }
        return DB::transaction(function () use ($mobile, $password): string {
            $owner = DB::table('users')->where('id', 1)->lockForUpdate()->first();
            abort_unless($owner && $owner->account_type === 'admin' && $owner->status === 'accepted', 403);
            if (!Hash::check($password, (string) $owner->password)) {
                throw ValidationException::withMessages(['current_password' => 'كلمة السر الحالية غير صحيحة.']);
            }
            $duplicate = DB::table('users')->where('id', '<>', 1)
                ->whereIn('account_type', DashboardLoginIdentity::ACCOUNT_TYPES)
                ->whereIn('mobile', DashboardLoginIdentity::mobileVariants($mobile))->lockForUpdate()->first(['id']);
            if ($duplicate) {
                throw ValidationException::withMessages(['mobile' => 'رقم الموبايل مستخدم بالفعل في حساب آخر للدخول إلى الداشبورد.']);
            }
            $changes = ['mobile' => $mobile];
            if (Schema::hasColumn('users', 'country_code')) { $changes['country_code'] = '20'; }
            if (Schema::hasColumn('users', 'updated_at')) { $changes['updated_at'] = now(); }
            // This form updates contact identity only: no profile, role, password or remember-token writes.
            DB::table('users')->where('id', 1)->update($changes);
            return $mobile;
        });
    }
}
