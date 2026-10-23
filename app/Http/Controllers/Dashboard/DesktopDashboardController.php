<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardUpdates,TakeawayAccess};
use Illuminate\Http\Request;

class DesktopDashboardController extends Controller
{
    public function index(TakeawayAccess $access,DesktopDashboardUpdates $updates)
    {
        $this->programAccess($access);
        $local=config('desktop_dashboard.local')===true;$release=null;
        if(!$local){
            try{
                $status=$updates->status();
                if($status['state']==='ready')$release=$status['release'];
            }catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){
                if($error->getStatusCode()!==503)throw $error;
                // A publication being replaced cannot advertise an old installer.
            }
        }
        return response()->view('admin.desktop-dashboard',compact('local','release'))
            ->header('Cache-Control','private, no-store');
    }
    public function download(TakeawayAccess $access,DesktopDashboardUpdates $updates)
    {
        $this->programAccess($access);
        abort_unless(config('desktop_dashboard.local')!==true,404);
        $status=$updates->status();
        abort_unless($status['state']==='ready'&&is_array($status['release']),503,DesktopDashboardUpdates::ERROR);
        $file=$updates->installer($status['release']['installer']['sha256']);
        return response()->stream(function()use($file){
            try{
                $remaining=$file['bytes'];
                while($remaining>0){
                    $chunk=fread($file['stream'],min(65536,$remaining));
                    if($chunk===false||$chunk==='')break;
                    echo $chunk;$remaining-=strlen($chunk);if(connection_aborted())break;
                }
            }finally{fclose($file['stream']);}
        },200,['Content-Type'=>'application/octet-stream','Content-Length'=>(string)$file['bytes'],
            'Content-Disposition'=>'attachment; filename="GO-ERP-Setup.exe"','Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff']);
    }
    private function programAccess(TakeawayAccess $access): void
    {
        abort_unless(config('desktop_dashboard.enabled')===true||config('desktop_dashboard.local')===true,404);
        abort_unless($access->canAccess(auth('admin')->user()),403);
    }
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
