<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardReconciliation,DesktopDashboardBootstrap};
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
}
