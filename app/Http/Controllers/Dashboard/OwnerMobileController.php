<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\OwnerMobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class OwnerMobileController extends Controller
{
    public function __invoke(Request $request, OwnerMobile $service)
    {
        $actor = auth('admin')->user();
        abort_unless($actor && (int) $actor->id === 1 && $actor->account_type === 'admin', 403);
        try {
            $data = Validator::make($request->only('mobile', 'current_password'), [
                'mobile' => ['required', 'string', 'max:40'],
                'current_password' => ['required', 'string'],
            ])->validate();
            $mobile = $service->update($actor, $data['mobile'], $data['current_password']);
        } catch (ValidationException $error) {
            if ($request->header('X-Dashboard-SPA') === '1' || $request->expectsJson()) {
                return response()->json(['success' => false, 'errors' => $error->errors(),
                    'message' => $error->validator->errors()->first()], 422);
            }
            // Return only the public input. Never flash the current password into the session.
            return back()->withErrors($error->errors(), 'ownerMobile')
                ->withInput($request->only('mobile'))->with('owner_mobile_open', true);
        }
        $destination = route('users.edit', ['user' => 1, 'account_type' => 'admin']);
        $message = 'تم حفظ رقم الموبايل. يمكنك تسجيل الدخول به وبنفس كلمة السر.';
        $request->session()->forget('owner_mobile_open');
        if ($request->header('X-Dashboard-SPA') === '1' || $request->expectsJson()) {
            $request->session()->forget('_old_input.mobile');
            return response()->json(['success' => true, 'message' => $message, 'redirect' => $destination]);
        }
        return redirect($destination)->with('success', $message)->withInput(['mobile' => $mobile]);
    }
}
