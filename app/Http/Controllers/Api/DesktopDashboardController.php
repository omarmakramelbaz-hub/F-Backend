<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardReconciliation,DesktopDashboardBootstrap,DesktopDashboardMedia};
use Illuminate\Http\Request;

class DesktopDashboardController extends Controller
{
    public function bootstrap(Request $request,DesktopDashboardDevices $devices,DesktopDashboardBootstrap $bootstrap)
    {
        $device=$devices->device((string)$request->bearerToken());
        return response(DesktopDashboardBootstrap::json($bootstrap->export($device)),200,['Content-Type'=>'application/json','Cache-Control'=>'private, no-store']);
    }
    public function ingest(Request $request,DesktopDashboardDevices $devices,DesktopDashboardReconciliation $reconciliation)
    {
        $device=$devices->device((string)$request->bearerToken());
        return response()->json($reconciliation->ingest($device,$request->all()))->header('Cache-Control','private, no-store');
    }
    public function media(Request $request,DesktopDashboardDevices $devices,DesktopDashboardMedia $media)
    {
        $device=$devices->device((string)$request->bearerToken());
        $file=$media->download($device,(string)$request->query('ticket'));
        return response($file['bytes'],200,['Content-Type'=>$file['mime'],'Content-Length'=>(string)strlen($file['bytes']),
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
