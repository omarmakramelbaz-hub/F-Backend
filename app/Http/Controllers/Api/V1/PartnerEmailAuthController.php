<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponses;
use App\Models\PendingVendor;
use App\Models\User;
use App\Services\PartnerEmailVerification;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PartnerEmailAuthController extends Controller
{
    use ApiResponses;

    public function requestCode(Request $request, PartnerEmailVerification $verification)
    {
        $data = $request->validate([
            'purpose' => 'required|in:application,activation,password_reset',
            'mobile' => 'required|string|min:10|max:20',
            'email' => 'required_if:purpose,application|nullable|email:rfc|max:254',
        ]);
        $mobile = PartnerEmailVerification::mobile($data['mobile']);
        $email = null;
        $subjectId = null;
        if ($data['purpose'] === 'application') {
            $email = strtolower(trim($data['email']));
        } elseif ($data['purpose'] === 'activation') {
            $application = PendingVendor::where('application_kind', 'partner')->where('mobile', $mobile)->latest('id')->first();
            if ($application && $application->status === 'accepted' && $application->email_verified_at && !$application->partner_activated_at) {
                $email = $application->email;
                $subjectId = $application->id;
            }
        } else {
            $user = User::withoutGlobalScopes()->where('app_scope', 'go_partner')->where('account_type', 'delegate')
                ->where('mobile', $mobile)->where('status', 'accepted')->first();
            if ($user && $user->partner_auth_email) {
                $email = $user->partner_auth_email;
                $subjectId = $user->id;
            }
        }
        return $this->successResponse(
            $verification->issue($data['purpose'], $mobile, $email, $subjectId, $request->ip()),
            $data['purpose'] === 'application' ? 'تم إرسال الكود إلى بريدك الإلكتروني.' : 'إذا كان الحساب مؤهلًا، سيصلك الكود على البريد المسجل. للحسابات القديمة دون بريد مؤكد، تواصل مع الدعم.'
        );
    }

    public function verifyCode(Request $request, PartnerEmailVerification $verification)
    {
        $data = $request->validate(['challenge_id' => 'required|uuid', 'code' => 'required|digits:6']);
        return $this->successResponse([
            'email_verification_token' => $verification->verify($data['challenge_id'], (string) $data['code']),
            'expires_in' => 600,
        ], 'تم تأكيد البريد الإلكتروني.');
    }

    public function resetPassword(Request $request, PartnerEmailVerification $verification)
    {
        $data = $request->validate([
            'mobile' => 'required|string|min:10|max:20',
            'email_verification_token' => 'required|string|size:64',
            'password' => 'required|string|min:8|max:72|confirmed',
        ]);
        return $verification->consume($data['email_verification_token'], 'password_reset', $data['mobile'], function ($proof) use ($data) {
            $user = User::withoutGlobalScopes()->where('id', $proof['subjectId'])->where('mobile', $proof['mobile'])
                ->where('app_scope', 'go_partner')->where('account_type', 'delegate')->where('status', 'accepted')->lockForUpdate()->first();
            if (!$user || $user->partner_auth_email !== $proof['email']) {
                throw ValidationException::withMessages(['email_verification_token' => 'تعذر تأكيد الحساب. اطلب كودًا جديدًا.']);
            }
            $user->password = $data['password'];
            $user->remember_token = null;
            $user->save();
            return $this->successResponse(null, 'تم تغيير كلمة المرور. سجل الدخول بكلمة المرور الجديدة.');
        });
    }
}
