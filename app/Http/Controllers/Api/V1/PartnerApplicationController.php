<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponses;
use App\Models\PendingVendor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
use App\Services\PartnerEmailVerification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class PartnerApplicationController extends Controller
{
    use ApiResponses;

    public static function professions(): array
    {
        return [
            'delivery_courier' => ['ar' => 'مندوب توصيل', 'en' => 'Delivery courier'],
            'store_owner' => ['ar' => 'صاحب مطعم أو متجر', 'en' => 'Shop or restaurant owner'],
            'appliance_technician' => ['ar' => 'فني صيانة ثلاجات وغسالات', 'en' => 'Fridge & washer technician'],
            'plumber' => ['ar' => 'سباك', 'en' => 'Plumber'],
            'painter' => ['ar' => 'نقاش', 'en' => 'Painter'],
            'tile_installer' => ['ar' => 'فني تركيب بلاط', 'en' => 'Tile installer'],
            'marble_installer' => ['ar' => 'فني تركيب رخام', 'en' => 'Marble installer'],
            'blacksmith' => ['ar' => 'حداد', 'en' => 'Blacksmith'],
            'electrician' => ['ar' => 'كهربائي', 'en' => 'Electrician'],
            'satellite_technician' => ['ar' => 'فني تركيب وصيانة الدش', 'en' => 'Satellite technician'],
            'furniture_carpenter' => ['ar' => 'نجار أثاث', 'en' => 'Furniture carpenter'],
            'ac_technician' => ['ar' => 'فني تكييف', 'en' => 'AC technician'],
            'construction_worker' => ['ar' => 'عامل بناء', 'en' => 'Construction worker'],
            'auto_mechanic' => ['ar' => 'ميكانيكي سيارات', 'en' => 'Auto mechanic'],
            'auto_electrician' => ['ar' => 'كهربائي سيارات', 'en' => 'Auto electrician'],
            'mens_barber' => ['ar' => 'كوافير رجالي', 'en' => 'Men barber'],
            'womens_hairdresser' => ['ar' => 'كوافيرة سيدات', 'en' => 'Women hairdresser'],
            'tailor' => ['ar' => 'خياط', 'en' => 'Tailor'],
            'male_cleaner' => ['ar' => 'عامل نظافة', 'en' => 'Male cleaner'],
            'female_cleaner' => ['ar' => 'عاملة نظافة', 'en' => 'Female cleaner'],
        ];
    }

    public function indexProfessions()
    {
        $data = collect(self::professions())->map(function ($labels, $key) {
            return [
                'key' => $key,
                'title_ar' => $labels['ar'],
                'title_en' => $labels['en'],
            ];
        })->values();

        return $this->successResponse($data, 'GO professions');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'mobile' => 'required|string|min:10|max:20',
            'email' => 'required|email:rfc|max:254',
            'email_verification_token' => 'required|string|size:64',
        ]);
        return Cache::lock('partner-application:'.hash('sha256', $this->normalizeMobile($data['mobile'])), 60)->block(5, function () use ($request, $data) {
            return app(PartnerEmailVerification::class)->consume($data['email_verification_token'], 'application', $data['mobile'], function ($proof) use ($request, $data) {
                if ($proof['email'] !== strtolower(trim($data['email']))) {
                    throw ValidationException::withMessages(['email' => 'استخدم البريد الذي تم تأكيده.']);
                }
                return $this->storeVerified($request, $proof['email']);
            });
        });
    }

    private function storeVerified(Request $request, string $email)
    {
        $validator = Validator::make($request->all(), [
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
            'full_name' => 'required|string|min:3|max:150',
            'age' => 'required|integer|min:18|max:75',
            'profession_key' => 'required|string|in:' . implode(',', array_keys(self::professions())),
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'mobile' => 'required|string|min:10|max:20',
            'payment_method' => 'required|in:vodafone_cash,instapay',
            'payment_identifier' => 'required|string|min:5|max:120',
            'work_radius_km' => 'required|integer|in:5,10,15,20',
            'terms_accepted' => 'accepted',
            'source_app' => 'nullable|in:go,fasakhansta',
            'partner_type' => 'nullable|in:profession,delegate,vendor',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $mobile = $this->normalizeMobile($request->mobile);

        if (User::withoutGlobalScopes()->where('app_scope', 'go_partner')->where('account_type', 'delegate')->where('mobile', $mobile)->exists()) {
            return $this->errorResponse('هذا الرقم مرتبط بحساب شريك. سجل الدخول أو استخدم استرجاع كلمة المرور.', 422);
        }

        $existing = PendingVendor::where('application_kind', 'partner')
            ->where('mobile', $mobile)
            ->whereIn('status', ['pending', 'accepted'])
            ->latest('id')
            ->first();

        if ($existing) {
            return $this->errorResponse(
                $existing->status === 'accepted'
                    ? 'تم قبول طلب انضمام بهذا الرقم بالفعل.'
                    : 'يوجد طلب انضمام قيد المراجعة بهذا الرقم.',
                422
            );
        }

        // Production-safe payload: older databases may not yet have the new
        // partner metadata columns. Store them only when the columns exist.
        $payload = [
            'added_by' => 1,
            'full_name' => trim($request->full_name),
            'email' => $email,
            'email_verified_at' => now(),
            'age' => (int) $request->age,
            'profession_key' => $request->profession_key,
            'lat' => $request->lat,
            'lng' => $request->lng,
            'location' => $request->lat . ',' . $request->lng,
            'mobile' => $mobile,
            'vodafone_cash_mobile' => $request->payment_method === 'vodafone_cash'
                ? $request->payment_identifier
                : null,
            'payment_method' => $request->payment_method,
            'payment_identifier' => $request->payment_identifier,
            'work_radius_km' => (int) $request->work_radius_km,
            'application_kind' => 'partner',
            'type' => $request->input('partner_type') === 'vendor' ? 'vendor' : 'delegate',
            'status' => 'pending',
            'terms_accepted_at' => now(),
        ];

        if (Schema::hasColumn('pending_vendors', 'source_app')) {
            $payload['source_app'] = $request->input('source_app', 'go');
        }
        if (Schema::hasColumn('pending_vendors', 'partner_type')) {
            $payload['partner_type'] = $request->input('partner_type', 'profession');
        }

        $application = PendingVendor::create($payload);

        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            $application->addMediaFromRequest('photo')
                ->toMediaCollection('partner_photo', 'pending_vendor');
        }

        return $this->successResponse([
            'application_id' => $application->id,
            'status' => $application->status,
            'profession_key' => $application->profession_key,
        ], 'تم استلام طلب الانضمام بنجاح.');
    }

    public function status(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string|min:10|max:20',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $mobile = preg_replace('/\D+/', '', (string) $request->mobile);
        if (substr($mobile, 0, 2) === '20' && strlen($mobile) > 10) {
            $mobile = substr($mobile, 2);
        }
        $mobile = ltrim($mobile, '0');

        $application = PendingVendor::where('application_kind', 'partner')
            ->where('mobile', $mobile)
            ->latest('id')
            ->first();

        if (!$application) {
            return $this->errorResponse('لا يوجد طلب انضمام بهذا الرقم.', 404);
        }

        return $this->successResponse([
            'application_id' => $application->id,
            'status' => $application->status,
            'decline_reason' => $application->decline_reason,
            'profession_key' => $application->profession_key,
            'profession' => self::professions()[$application->profession_key] ?? null,
            'work_radius_km' => $application->work_radius_km,
            'source_app' => Schema::hasColumn('pending_vendors', 'source_app')
                ? ($application->source_app ?: 'fasakhansta')
                : 'go',
            'can_create_account' => $application->status === 'accepted',
            'message' => $application->status === 'accepted'
                ? 'تمت الموافقة على طلب انضمامك. يمكنك الآن إنشاء حساب الشريك.'
                : ($application->status === 'declined'
                    ? ($application->decline_reason ?: 'لم تتم الموافقة على طلب الانضمام حالياً. يمكنك تقديم طلب جديد.')
                    : 'طلب الانضمام قيد المراجعة.'),
        ], 'Partner application status');
    }


    public function activate(Request $request)
    {
        $data = $request->validate([
            'mobile' => 'required|string|min:10|max:20',
            'password' => 'required|string|min:8|max:72|confirmed',
            'email_verification_token' => 'required|string|size:64',
        ]);
        return app(PartnerEmailVerification::class)->consume($data['email_verification_token'], 'activation', $data['mobile'], function ($proof) use ($data) {
            $application = PendingVendor::where('id', $proof['subjectId'])->where('application_kind', 'partner')
                ->where('mobile', $proof['mobile'])->lockForUpdate()->first();
            if (!$application || $application->status !== 'accepted' || !$application->email_verified_at
                || $application->email !== $proof['email'] || $application->partner_activated_at) {
                throw ValidationException::withMessages(['email_verification_token' => 'لا يمكن تفعيل هذا الطلب. راجع حالة الطلب أو استخدم استرجاع كلمة المرور.']);
            }
            $user = User::withoutGlobalScopes()->where('account_type', 'delegate')->where('app_scope', 'go_partner')
                ->where('mobile', $proof['mobile'])->lockForUpdate()->first();
            if ($user && ($user->status !== 'pending' || (int) $user->pending_vendor_id !== (int) $application->id)) {
                throw ValidationException::withMessages(['mobile' => 'الحساب موجود بالفعل. استخدم تسجيل الدخول أو استرجاع كلمة المرور.']);
            }
            $fields = [
                'added_by' => 1, 'name' => $application->full_name, 'mobile' => $proof['mobile'],
                'email' => $proof['email'], 'partner_auth_email' => $proof['email'], 'email_verified_at' => now(),
                'password' => $data['password'], 'account_type' => 'delegate', 'app_scope' => 'go_partner',
                'status' => 'accepted', 'pending_vendor_id' => $application->id,
            ];
            $user = $user ?: new User();
            $user->forceFill($fields)->save();
            try {
                if (!$user->hasRole(13)) $user->assignRole(13);
            } catch (\Throwable $e) {
                // Preserve compatibility when the optional legacy role is unavailable.
            }
            $application->update(['partner_activated_at' => now()]);
            return $this->successResponse(['status' => 'active', 'profession_key' => $application->profession_key, 'partner_id' => $user->id], 'تم تفعيل حساب الشريك. يمكنك تسجيل الدخول الآن.');
        });
    }

    public function partners(Request $request, string $professionKey)
    {
        if (!array_key_exists($professionKey, self::professions())) {
            return $this->errorResponse('المهنة غير موجودة.', 404);
        }

        $validator = Validator::make($request->all(), [
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $customerLat = (float) $request->lat;
        $customerLng = (float) $request->lng;

        $users = User::where('account_type', 'delegate')
            ->where('status', 'accepted')
            ->whereHas('pending_vendor', function ($query) use ($professionKey) {
                $query->where('application_kind', 'partner')
                    ->where('profession_key', $professionKey)
                    ->where('status', 'accepted');
            })
            ->with('pending_vendor')
            ->get();

        $data = $users->map(function ($user) use ($customerLat, $customerLng) {
            $application = $user->pending_vendor;
            if (!$application || $application->lat === null || $application->lng === null) {
                return null;
            }

            $distance = $this->distanceKm(
                $customerLat,
                $customerLng,
                (float) $application->lat,
                (float) $application->lng
            );

            if ($distance > (float) ($application->work_radius_km ?: 5)) {
                return null;
            }

            return [
                'id' => $user->id,
                'name' => $application->full_name ?: $user->name,
                'profession_key' => $application->profession_key,
                'distance_km' => round($distance, 1),
                'work_radius_km' => (int) $application->work_radius_km,
                'photo' => $application->getFirstMediaUrl('partner_photo', 'thumb')
                    ?: $application->getFirstMediaUrl('partner_photo'),
            ];
        })->filter()->sortBy('distance_km')->values();

        return $this->successResponse($data, 'Available GO partners');
    }


    private function normalizeMobile($value): string
    {
        $mobile = preg_replace('/\D+/', '', (string) $value);
        if (substr($mobile, 0, 2) === '20' && strlen($mobile) > 10) {
            $mobile = substr($mobile, 2);
        }
        return ltrim($mobile, '0');
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
