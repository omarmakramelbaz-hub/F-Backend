<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardUpdates};
use Illuminate\Http\Request;

class DesktopDashboardUpdateController extends Controller
{
    public function status(Request $request,DesktopDashboardDevices $devices,DesktopDashboardUpdates $updates)
    {
        $devices->device((string)$request->bearerToken());
        return response()->json($updates->status())->header('Cache-Control','private, no-store');
    }

    public function installer(Request $request,string $sha,DesktopDashboardDevices $devices,DesktopDashboardUpdates $updates)
    {
        $devices->device((string)$request->bearerToken());$file=$updates->installer($sha);
        return response()->stream(function()use($file){
            try{
                $remaining=$file['bytes'];
                while($remaining>0){
                    $chunk=fread($file['stream'],min(65536,$remaining));
                    if($chunk===false||$chunk==='')break;
                    echo $chunk;$remaining-=strlen($chunk);if(connection_aborted())break;
                }
            }
            finally{fclose($file['stream']);}
        },200,['Content-Type'=>'application/octet-stream','Content-Length'=>(string)$file['bytes'],
            'Content-Disposition'=>'attachment; filename="GO-ERP-Update.exe"','Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff']);
    }
}
