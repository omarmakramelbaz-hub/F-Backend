<?php
namespace App\Services\Dashboard;

/** Native supervisor commands use a physical root; actor operations additionally require an actual-login capability. */
final class DesktopDashboardWorkspaceControl
{
    public const ROOT_ACTIONS=['pending','acknowledge','failed','refresh-begin','refresh-cancel','refresh-status',
        'print-audit','print-outcomes','print-acknowledge'];
    public const ACTOR_ACTIONS=['outbox-review','outbox-retry','print-list','print-prepare','print-burn',
        'print-outcome','print-review','print-resolve'];
    public function __construct(private DesktopDashboardWorkspaceLocalAuthority $authority,
        private DesktopDashboardWorkspaceNativeSessions $sessions,private DesktopDashboardControl $commands) {}

    public function dispatch(array $value,string $bearer): array
    {
        $control=getenv('DESKTOP_DASHBOARD_CONTROL_TOKEN');
        abort_unless(config('desktop_dashboard.local')===true&&$this->authority->selected()
            &&is_string($control)&&preg_match('/^[a-f0-9]{64}$/D',$control)
            &&hash_equals($control,(string)($_SERVER['HTTP_X_FASAKHANSTA_CONTROL']??'')),403);
        $root=$this->authority->authenticateLocal($bearer);$device=$root->device_id;$action=$value['action']??null;
        abort_unless(is_string($action),422);
        abort_if(isset($value['workspace_actor']),422);
        if(in_array($action,self::ROOT_ACTIONS,true)){
            abort_if(array_key_exists('native_session',$value),422);
            return DesktopDashboardWorkspaceExecutionContext::rootControl($device,fn()=>$this->commands->dispatch($device,$value));
        }
        abort_unless(in_array($action,self::ACTOR_ACTIONS,true)&&is_array($value['native_session']??null),403);
        $capability=$value['native_session'];unset($value['native_session']);
        return $this->sessions->withSession($capability,function($scope)use($device,$value){
            abort_unless($scope->id===$device,403);
            if(array_key_exists('actor_id',$value))abort_unless($value['actor_id']===$scope->actor_id,403);
            return $this->commands->dispatch($device,$value);
        });
    }
}
