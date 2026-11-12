<?php

namespace App\Http\Controllers\Api\V1\Home;

use App\Http\Controllers\Controller;
use App\Services\App\AppUpdatePolicy;

/** GET /api/app-version: the customer app's lowest allowed version per platform and its store link (forced update). */
class AppVersionController extends Controller
{
    public function show(AppUpdatePolicy $policy)
    {
        return response()->json(['status' => 'Success', 'data' => $policy->forApp()])->header('Cache-Control', 'no-store');
    }
}
