<?php
namespace App\Http\Middleware;

use App\Services\Dashboard\{DesktopDashboardWorkspaceAuthority,DesktopDashboardWorkspaceExecutionContext};
use Closure;

/** Native-only central transport; the original PHP password session remains the actor authority. */
final class DesktopDashboardWorkspaceConnectedContext
{
    public const SIGNIN_ATTRIBUTE='desktop_workspace_verified_signin_scope';
    public const HEADERS=['X-Fasakhansta-Workspace-Device','X-Fasakhansta-Workspace-Token',
        'X-Fasakhansta-Workspace-Actor','X-Fasakhansta-Workspace-Revision','X-Fasakhansta-Workspace-Grant','X-Fasakhansta-Workspace-Root'];
    public const ATTEMPT_HEADERS=['X-Fasakhansta-Workspace-Attempt','X-Fasakhansta-Workspace-Capability'];
    public static function present($request): bool
    {
        foreach([...self::HEADERS,...self::ATTEMPT_HEADERS] as $header)if($request->hasHeader($header))return true;
        return false;
    }
    public static function signin($request): bool
    {
        return $request->isMethod('POST')&&$request->route()?->getName()==='admin.login'
            &&$request->route()?->getActionName()===\App\Http\Controllers\Dashboard\HomeController::class.'@signin';
    }
    public function handle($request,Closure $next)
    {
        if(!self::present($request))return $next($request);
        abort_unless(config('desktop_dashboard.local')!==true&&config('desktop_dashboard.workspace_enabled')===true,403);
        foreach(['X-Fasakhansta-Connected-Device','X-Fasakhansta-Connected-Token','X-Fasakhansta-Remote-Attempt','X-Fasakhansta-Remote-Capability'] as $legacy)
            abort_if($request->hasHeader($legacy),422,'لا يمكن خلط بروتوكولات ربط الداشبورد.');
        $values=[];foreach(self::HEADERS as $header){$value=$request->header($header);abort_unless(is_string($value)&&$value!=='',401);$values[$header]=$value;}
        $id=$values[self::HEADERS[0]];$token=$values[self::HEADERS[1]];$actor=$this->integer($values[self::HEADERS[2]]);$revision=$this->integer($values[self::HEADERS[3]]);
        $grant=$values[self::HEADERS[4]];$fingerprint=$values[self::HEADERS[5]];
        abort_unless(preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id)
            &&preg_match('/^[a-f0-9]{64}$/D',$token)&&preg_match('/^[a-f0-9]{64}$/D',$grant)&&preg_match('/^[a-f0-9]{64}$/D',$fingerprint),401);
        $attempt=$request->hasHeader(self::ATTEMPT_HEADERS[0]);$capability=$request->hasHeader(self::ATTEMPT_HEADERS[1]);
        abort_unless($attempt===$capability,422);
        if($attempt){
            abort_unless(is_string($request->header(self::ATTEMPT_HEADERS[0]))&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$request->header(self::ATTEMPT_HEADERS[0]))
                &&is_string($request->header(self::ATTEMPT_HEADERS[1]))&&preg_match('/^[a-f0-9]{64}$/D',$request->header(self::ATTEMPT_HEADERS[1])),422);
        }
        $authority=app(DesktopDashboardWorkspaceAuthority::class);$root=$authority->authenticate($token);
        abort_unless($root->id===$id&&hash_equals($authority->rootFingerprint($root),$fingerprint),401);
        $scope=$authority->scope($root,$actor,$grant,$revision);
        if(self::signin($request)){
            abort_if($attempt,422);
            // No actor context is installed before the original password guard
            // succeeds. The Journal may recognize only this issued, typed permit.
            abort_if($request->attributes->has(self::SIGNIN_ATTRIBUTE),409);
            $request->attributes->set(self::SIGNIN_ATTRIBUTE,$scope);
            try{
                $response=$next($request);$user=auth('admin')->user();
                if($user!==null){
                    try{abort_unless((int)$user->id===$actor,403,'حساب الجلسة لا يطابق الحساب الذي سجّل كلمة المرور.');$authority->assertScope($scope);}
                    catch(\Throwable $error){auth('admin')->logout();$request->session()->invalidate();$request->session()->regenerateToken();throw $error;}
                }
                return $response;
            }finally{$request->attributes->remove(self::SIGNIN_ATTRIBUTE);}
        }
        abort_unless((int)auth('admin')->id()===$actor,403,'حساب جلسة الداشبورد لا يطابق الحساب الحالي في البرنامج.');
        return DesktopDashboardWorkspaceExecutionContext::run($scope,fn()=>$next($request));
    }
    private function integer(string $value): int
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,15}$/D',$value)&&strlen($value)<=16
            &&(strlen($value)<16||strcmp($value,'9007199254740991')<=0),401);return (int)$value;
    }
}
