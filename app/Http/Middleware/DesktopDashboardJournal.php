<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Schema;
use App\Services\Dashboard\DesktopDashboardJournal as Journal;
use App\Services\Dashboard\DesktopDashboardRoutes;

class DesktopDashboardJournal
{
    public function handle($request,Closure $next)
    {
        if (!config('desktop_dashboard.local')) return $next($request);
        $route=$request->route()?->getName();
        if (!DesktopDashboardRoutes::journaled($route)) {
            $action=strtolower((string)$request->route()?->getActionName());
            $login=$route==='admin.login';
            $unsafeRead=preg_match('/@(?:[^@]*(?:delete|destroy|update|save|send|accept|finish|clear|notificationtest)|testnotification)$/D',$action)
                || in_array($request->path(),['clear-cache','clear-compiled','linkstoragse','send_order_email','admin/clear-cache-admin','admin/test-notification'],true);
            abort_if((!$request->isMethod('GET')&&!$request->isMethod('HEAD')&&!$login)||$unsafeRead,501,'مزامنة هذا القسم لم تُجهّز بعد؛ لم تُنفّذ العملية.');
            return $next($request);
        }
        abort_unless(Schema::hasTable('desktop_dashboard_commands'),503,'قاعدة العمليات المحلية لم تُجهّز بعد.');
        $actor=auth('admin')->user();abort_unless($actor,403);
        // Existing forms already supply an immutable UUID. Do not generate another after losing a reply.
        $command=(string)$request->input('idempotency_key');
        $payload=['parameters'=>$request->route()->parameters(),'values'=>$request->except('_token'),'files'=>[]];
        if($route==='branch-shifts.close')$payload['facts']['shift']=app(\App\Services\Dashboard\BranchShiftClosing::class)->desktopReview($request->all(),$actor);
        foreach($request->allFiles() as $name=>$file) {
            abort_unless($file instanceof \Illuminate\Http\UploadedFile && $file->isValid(),422,'المرفق غير صالح.');
            abort_if($file->getSize()>10*1024*1024,422,'المرفق أكبر من الحد المسموح.');
            $payload['files'][$name]=['name'=>$file->getClientOriginalName(),'mime'=>$file->getMimeType(),'sha256'=>hash_file('sha256',$file->getRealPath()),'base64'=>base64_encode(file_get_contents($file->getRealPath()))];
        }
        $status=200;
        $result=app(Journal::class)->execute((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$route,$payload,[],function()use($next,$request,&$status){
            $response=$next($request);$status=$response->getStatusCode();
            // Abort rolls back all writes made by the original service and records no success.
            if ($status>=400) throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
            $result=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
            if (!is_array($result)) throw new \LogicException('Expected the original dashboard JSON response.');
            return $result;
        });
        return response()->json($result,$status)->header('Cache-Control','private, no-store');
    }
}
