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
    public function session(Request $request,DesktopDashboardDevices $devices)
    {
        $device=$devices->device((string)$request->bearerToken());
        return \Illuminate\Support\Facades\DB::transaction(function()use($devices,$device){
            $device=$devices->lockAuthenticated($device);$actor=$devices->actor($device);
            abort_unless((int)auth('admin')->id()===(int)$actor->id,403,'حساب السيرفر لا يطابق حساب الجهاز.');
            foreach(json_decode($device->branches,true,512,JSON_THROW_ON_ERROR) as $branch)$devices->branch($device,$branch,$actor);
        return response()->json(['device_id'=>$device->id,'actor_id'=>(int)$actor->id,
            'connected_request_protocol'=>\App\Http\Middleware\DesktopDashboardJournal::CONNECTED_REQUEST_PROTOCOL])->header('Cache-Control','private, no-store');
        });
    }
}
