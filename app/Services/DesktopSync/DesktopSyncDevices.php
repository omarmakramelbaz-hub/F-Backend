<?php

namespace App\Services\DesktopSync;

use App\Models\User;
use App\Services\Dashboard\TakeawayAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A desktop device is set up once by any dashboard account; afterwards it authenticates with its own token. */
class DesktopSyncDevices
{
    public const ACCOUNT_TYPES = ['admin', 'vendor', 'resturant_owner'];

    public function ready(): bool
    {
        return Schema::hasTable('desktop_sync_devices') && Schema::hasTable('desktop_sync_snapshots')
            && Schema::hasTable('desktop_sync_operations');
    }

    /** Same identity rules as the dashboard login page: mobile (leading zero optional) or email. */
    public function findUser(string $login): ?User
    {
        $login = trim($login);
        if (str_starts_with($login, '0')) $login = substr($login, 1);
        if ($login === '') return null;
        return User::query()->where(function ($query) use ($login) {
            $query->where('mobile', $login)->orWhere('email', $login);
        })->whereIn('account_type', self::ACCOUNT_TYPES)->first();
    }

    public function authenticateUser(string $login, string $password): User
    {
        $user = $this->findUser($login);
        abort_unless($user && is_string($user->password) && Hash::check($password, $user->password), 401,
            'رقم الموبايل أو البريد أو كلمة المرور غير صحيحة.');
        return $user;
    }

    /** Branches this account may operate, in the dashboard's own "f:<id>" / "gs:<id>" form. */
    public function branchesFor(User $user): array
    {
        try {
            $branches = app(TakeawayAccess::class)->branches($user);
        } catch (HttpException $error) {
            $branches = [];
        }
        return array_values(array_map(fn ($branch) => [
            'value' => $branch['value'], 'kind' => $branch['kind'], 'name' => $branch['name'],
            'address' => $branch['address'], 'phone' => $branch['phone'],
        ], $branches));
    }

    /**
     * Without a branch choice an account that covers several branches gets the list to choose from.
     * The returned token is shown once; only its hash is stored.
     */
    public function enroll(string $login, string $password, string $name, ?array $chosen): array
    {
        abort_unless($this->ready(), 503, 'خدمة برنامج الكمبيوتر لم تُجهز بعد على السرفر.');
        $user = $this->authenticateUser($login, $password);
        $available = $this->branchesFor($user);
        abort_unless($available, 403, 'هذا الحساب ليس له فروع يمكن العمل عليها من البرنامج.');
        if ($chosen === null) {
            if (count($available) > 1) return ['choose_branches' => true, 'branches' => $available];
            $chosen = [$available[0]['value']];
        }
        $values = array_column($available, 'value');
        $chosen = array_values(array_unique(array_map('strval', $chosen)));
        abort_unless($chosen && ! array_diff($chosen, $values), 422, 'اختر فرعًا واحدًا على الأقل من فروع الحساب.');
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        $now = now('UTC');
        DB::table('desktop_sync_devices')->insert([
            'id' => $id, 'name' => mb_substr(trim($name) !== '' ? trim($name) : 'GO ERP', 0, 100),
            'token_hash' => hash('sha256', $token), 'enrolled_by' => (int) $user->id,
            'branches' => json_encode($chosen), 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $branches = array_values(array_filter($available, fn ($branch) => in_array($branch['value'], $chosen, true)));
        return ['choose_branches' => false, 'device' => ['id' => $id, 'branches' => $branches], 'token' => $token];
    }

    public function authenticate(?string $id, ?string $token): object
    {
        abort_unless($this->ready(), 503, 'خدمة برنامج الكمبيوتر لم تُجهز بعد على السرفر.');
        abort_unless(is_string($id) && Str::isUuid($id) && is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token), 401,
            'الجهاز يحتاج إعادة تجهيز.');
        $device = DB::table('desktop_sync_devices')->where('id', $id)->first();
        abort_unless($device && hash_equals($device->token_hash, hash('sha256', $token)) && $device->revoked_at === null, 401,
            'تم إيقاف هذا الجهاز أو يحتاج إعادة تجهيز.');
        DB::table('desktop_sync_devices')->where('id', $id)->update(['last_seen_at' => now('UTC')]);
        $device->branch_values = json_decode($device->branches, true) ?: [];
        return $device;
    }
}
