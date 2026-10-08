<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DesktopDashboardDevices;
use Illuminate\Http\Request;

class DesktopDashboardController extends Controller
{
    public function enroll(Request $request,DesktopDashboardDevices $devices)
    {
        return response()->json($devices->enroll($request->all(),auth('admin')->user()))->header('Cache-Control','private, no-store');
    }
}
