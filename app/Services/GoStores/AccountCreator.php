<?php

namespace App\Services\GoStores;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountCreator
{
    public function create(array $data, int $adminId): User
    {
        // Same lock as self-service GO applications, so an admin cannot race
        // another admin or an application for the same normalized identity.
        try {
            return Cache::lock('partner-application:'.hash('sha256', $data['mobile']), 60)->block(5, function () use ($data, $adminId) {
                return DB::transaction(function () use ($data, $adminId) {
                    $mobile = $data['mobile'];
                    $aliases = [$mobile, '0'.$mobile, '20'.$mobile, '+20'.$mobile, '0020'.$mobile];
                    if (User::withoutGlobalScopes()->where('app_scope', 'go_partner')->whereIn('mobile', $aliases)->exists()) {
                        throw ValidationException::withMessages(['mobile' => 'يوجد حساب شريك GO بهذا الرقم بالفعل. افتح الحساب الموجود من المتاجر أو الشركاء.']);
                    }
                    if (DB::table('pending_vendors')->where('application_kind', 'partner')->whereIn('mobile', $aliases)
                        ->whereIn('status', ['pending', 'accepted'])->exists()) {
                        throw ValidationException::withMessages(['mobile' => 'يوجد طلب انضمام بهذا الرقم. أكمل مراجعته من طلبات انضمام الشركاء.']);
                    }
                    $email = $data['email'] ?? null;
                    if ($email && User::withoutGlobalScopes()->where('app_scope', 'go_partner')->where('email', $email)->exists()) {
                        throw ValidationException::withMessages(['email' => 'البريد الإلكتروني مستخدم في حساب شريك GO آخر.']);
                    }

                    // Only explicit owner fields are accepted. Roles, balances,
                    // scope and activation cannot be overridden by form inputs.
                    $owner = User::create([
                        'added_by' => $adminId, 'name' => $data['owner_name'], 'mobile' => $mobile,
                        'email' => $email, 'password' => $data['password'],
                        'account_type' => 'vendor', 'app_scope' => 'go_partner', 'status' => 'accepted',
                        'balance' => 0, 'delegate_fees' => $data['commission_rate'],
                    ]);
                    DB::table('go_stores')->insert([
                        'user_id' => $owner->id, 'name' => $data['name'], 'kind' => $data['kind'],
                        'address' => $data['address'], 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    // Admin-entered contact email is not an ownership proof.
                    // Keep verified-email recovery unchanged and never mark it verified here.
                    return $owner;
                });
            });
        } catch (LockTimeoutException $error) {
            throw ValidationException::withMessages(['mobile' => 'جارٍ حفظ حساب بهذا الرقم. أعد المحاولة بعد لحظات.']);
        }
    }
}
