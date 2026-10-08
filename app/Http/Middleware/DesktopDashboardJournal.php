<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\{DB,Schema};
use App\Services\Dashboard\DesktopDashboardJournal as Journal;
use App\Services\Dashboard\DesktopDashboardRoutes;

class DesktopDashboardJournal
{
    public function handle($request,Closure $next)
    {
        if (!config('desktop_dashboard.local')) {
            if($request->hasHeader('X-Fasakhansta-Remote-Attempt')||$request->hasHeader('X-Fasakhansta-Remote-Capability'))
                return app(\App\Services\Dashboard\DesktopDashboardRemoteAttempts::class)->handle($request,$next);
            return $next($request);
        }
        $route=$request->route()?->getName();
        if (!DesktopDashboardRoutes::journaled($route)) {
            $action=strtolower((string)$request->route()?->getActionName());
            $login=$route==='admin.login';
            $unsafeRead=preg_match('/@(?:[^@]*(?:delete|destroy|update|save|send|accept|finish|clear|notificationtest)|testnotification)$/D',$action)
                || in_array($request->path(),['clear-cache','clear-compiled','linkstoragse','send_order_email','admin/clear-cache-admin','admin/test-notification'],true);
            $readPost=in_array($route,DesktopDashboardRoutes::READ_POSTS,true)||($request->isMethod('POST')&&in_array($request->route()?->getActionName(),DesktopDashboardRoutes::READ_POST_ACTIONS,true));
            abort_if((!$request->isMethod('GET')&&!$request->isMethod('HEAD')&&!$login&&!$readPost)||$unsafeRead,501,'مزامنة هذا القسم لم تُجهّز بعد؛ لم تُنفّذ العملية.');
            if($login)return $next($request);
            // Some legacy GET actions write data. HTTP reads must never silently commit
            // a business change without a corresponding reconciled command.
            abort_unless(DB::transactionLevel()===0,503);
            DB::statement('SET TRANSACTION READ ONLY');
            try{return DB::transaction(fn()=>$next($request));}
            catch(\Illuminate\Database\QueryException $error){
                if((int)($error->errorInfo[1]??0)===1792)abort(501,'هذه الصفحة طلبت تعديلًا لم تُجهّز مزامنته؛ لم تُنفّذ العملية.');
                throw $error;
            }
        }
        abort_unless(Schema::hasTable('desktop_dashboard_commands'),503,'قاعدة العمليات المحلية لم تُجهّز بعد.');
        $actor=auth('admin')->user();abort_unless($actor,403);
        if(\App\Services\Dashboard\DesktopDashboardLegacy::handles($route)){
            $legacy=app(\App\Services\Dashboard\DesktopDashboardLegacy::class);$legacy->authorize($actor);
            $command=(string)($request->header('X-Fasakhansta-Command')?:$request->input('_desktop_command'));
            $payload=$legacy->payload($request,$command);
            // Journal metadata must never reach the original mass-assignment repositories.
            $request->request->remove('_desktop_command');$response=null;
            $result=app(Journal::class)->execute((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$route,$payload,[],
                function()use($legacy,$route,$next,$request,&$response){return $legacy->capture($route,function()use($next,$request,&$response){return $response=$next($request);});});
            return $response??$legacy->response($result);
        }
        // Existing forms already supply an immutable UUID. Do not generate another after losing a reply.
        $command=(string)$request->input('idempotency_key');
        $payload=['parameters'=>$request->route()->parameters(),'values'=>$request->except('_token','attachment'),'files'=>app(\App\Services\Dashboard\DesktopDashboardExpenseAttachments::class)->files($request,$route)];
        if(in_array($route,['branch-shifts.close','employees.close'],true)){
            $facts=app(Journal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$route);
            if($facts!==null)$payload['facts']=$facts;
            elseif($route==='branch-shifts.close')$payload['facts']['shift']=app(\App\Services\Dashboard\BranchShiftClosing::class)->desktopReview($request->all(),$actor);
            else $payload['facts']['payroll']=app(\App\Services\Dashboard\BranchPayroll::class)->desktopReview($request->all(),$actor);
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
