<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardUpdates};
use Illuminate\Http\Request;

class DesktopDashboardUpdateController extends Controller
{
    private function authenticate(Request $request,DesktopDashboardDevices $devices): void
    {
        if($request->hasHeader('X-Fasakhansta-Update-Protocol')){
            abort_unless($request->header('X-Fasakhansta-Update-Protocol')==='2',422);
            app(\App\Services\Dashboard\DesktopDashboardWorkspaceAuthority::class)->authenticate((string)$request->bearerToken());
        }else $devices->device((string)$request->bearerToken());
    }
    public function status(Request $request,DesktopDashboardDevices $devices,DesktopDashboardUpdates $updates)
    {
        $this->authenticate($request,$devices);
        return response()->json($updates->status())->header('Cache-Control','private, no-store');
    }

    public function installer(Request $request,string $sha,DesktopDashboardDevices $devices,DesktopDashboardUpdates $updates)
    {
        $this->authenticate($request,$devices);$file=$updates->installer($sha);
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
