<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\App\AppUpdatePolicy;
use Illuminate\Http\Request;

/** «تحديث التطبيق الإجباري»: the customer app's lowest allowed version per platform and its store links. */
class AppUpdateController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:setting-list');
    }

    public function edit(AppUpdatePolicy $policy)
    {
        return view('admin.app_update', ['values' => $policy->get()]);
    }

    public function update(Request $request, AppUpdatePolicy $policy)
    {
        $policy->save($request->only(AppUpdatePolicy::FIELDS));
        return redirect()->back()->with('success', 'اتحفظ. أي عميل نسخته أقدم من الرقم ده هيظهرله التحديث الإجباري في الرئيسية.');
    }
}
