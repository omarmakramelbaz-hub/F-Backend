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
        private DesktopDashboardWorkspaceNativeSessions $sessions,private DesktopDashboardJournal $journal) {}

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
            return DesktopDashboardWorkspaceExecutionContext::rootControl($device,fn()=>$this->execute($device,$value));
        }
        abort_unless(in_array($action,self::ACTOR_ACTIONS,true)&&is_array($value['native_session']??null),403);
        $capability=$value['native_session'];unset($value['native_session']);
        return $this->sessions->withSession($capability,function($scope)use($device,$value){
            abort_unless($scope->id===$device,403);
            if(array_key_exists('actor_id',$value))abort_unless($value['actor_id']===$scope->actor_id,403);
            return $this->execute($device,$value);
        });
    }
    private function execute(string $device,array $value): array
    {
        $prints=app(DesktopDashboardPrintEffects::class);$refresh=app(DesktopDashboardRefresh::class);
        return match($value['action']){
            'pending'=>['commands'=>$this->journal->pending($device),'counts'=>$this->journal->counts($device),
                'print_protocol'=>app(DesktopDashboardPrintLedger::class)->active($device)?1:0],
            'acknowledge'=>$this->acknowledge($device,$value),
            'failed'=>$this->failed($device,$value),
            'refresh-begin'=>$refresh->begin($device,(string)($value['refresh_id']??''),(string)($value['token']??'')),
            'refresh-cancel'=>$refresh->cancel($device,(string)($value['refresh_id']??''),(string)($value['token']??'')),
            'refresh-status'=>$refresh->inspect($device,(string)($value['refresh_id']??''),(string)($value['token']??'')),
            'outbox-review'=>app(DesktopDashboardOutboxReview::class)->inspect($device),
            'outbox-retry'=>app(DesktopDashboardOutboxReview::class)->retry($device,(string)($value['command_id']??''),(string)($value['review_token']??'')),
            'print-audit'=>$prints->audit($device),'print-outcomes'=>$prints->pendingOutcomes($device),
            'print-acknowledge'=>$prints->acknowledge($device,(array)($value['receipt']??[])),
            'print-list'=>$prints->listing($device,$value),'print-prepare'=>$prints->prepare($device,$value),
            'print-burn'=>$prints->burn($device,$value),'print-outcome'=>$prints->outcome($device,$value),
            'print-review'=>$prints->review($device,$value),'print-resolve'=>$prints->resolve($device,$value),
            default=>abort(422),
        };
    }
    private function acknowledge(string $device,array $value): array
    {
        $this->journal->acknowledge($device,(string)($value['command_id']??''),(array)($value['receipt']??[]));
        return ['counts'=>$this->journal->counts($device)];
    }
    private function failed(string $device,array $value): array
    {
        $this->journal->failed($device,(string)($value['command_id']??''),(string)($value['message']??''),($value['conflict']??false)===true);
        return ['counts'=>$this->journal->counts($device)];
    }
}
