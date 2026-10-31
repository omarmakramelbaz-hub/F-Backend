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
            $service->update($actor, $data['mobile'], $data['current_password']);
        } catch (ValidationException $error) {
            // Return only the public input. Never flash the current password into the session.
            return back()->withErrors($error->errors(), 'ownerMobile')
                ->withInput($request->only('mobile'))->with('owner_mobile_open', true);
        }
        return back()->with('success', 'تم حفظ رقم الموبايل. يمكنك تسجيل الدخول به وبنفس كلمة السر.');
    }
}
