<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardUpdates,DesktopDashboardManualRelease,TakeawayAccess};
use Illuminate\Http\Request;

class DesktopDashboardController extends Controller
{
    public function index(TakeawayAccess $access,DesktopDashboardUpdates $updates,DesktopDashboardManualRelease $manual)
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
        $manualOwner=!$local&&$manual->canRequest(auth('admin')->user());
        return response()->view('admin.desktop-dashboard',compact('local','release','manualOwner'))
            ->header('Cache-Control','private, no-store');
    }
    public function releaseStatus(DesktopDashboardManualRelease $manual,DesktopDashboardUpdates $updates)
    {
        $status=$manual->status(auth('admin')->user());
        return response()->json($this->releaseStatusPayload($status,$updates))->header('Cache-Control','private, no-store');
    }
    public function requestRelease(Request $request,DesktopDashboardManualRelease $manual,DesktopDashboardUpdates $updates)
    {
        $value=$request->validate(['nonce'=>['required','string','regex:/^[a-f0-9]{32}$/D']]);
        $accepted=$manual->request(auth('admin')->user(),$value['nonce']);
        return response()->json(['accepted'=>true,'idempotent'=>$accepted['idempotent'],
            'nonce'=>$value['nonce'],...$this->releaseStatusPayload($accepted['status'],$updates)],202)
            ->header('Cache-Control','private, no-store');
    }
    private function releaseStatusPayload(array $status,DesktopDashboardUpdates $updates): array
    {
        $release=null;
        try{$current=$updates->status();if($current['state']==='ready')$release=$current['release'];}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){if($error->getStatusCode()!==503)throw $error;}
        return ['status'=>$status,'download'=>['ready'=>$release!==null,
            'version'=>$release['version']??null,'url'=>$release!==null?route('desktop-dashboard.download'):null]];
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
