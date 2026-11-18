<?php
namespace App\Services\Dashboard;

/** One command dispatcher shared by both native protocols; their authentication stays at the boundary. */
final class DesktopDashboardControl
{
    public function __construct(private DesktopDashboardJournal $journal) {}

    public function dispatch(string $device,array $value): array
    {
        $token=getenv('DESKTOP_DASHBOARD_CONTROL_TOKEN');
        abort_unless(config('desktop_dashboard.local')===true&&is_string($token)&&$token!==''
            &&hash_equals($token,(string)($_SERVER['HTTP_X_FASAKHANSTA_CONTROL']??''))
            &&$device===(string)config('desktop_dashboard.device_id'),403);
        if(app(DesktopDashboardWorkspaceLocalAuthority::class)->selected()){
            $scope=DesktopDashboardWorkspaceExecutionContext::current();
            abort_unless(DesktopDashboardWorkspaceExecutionContext::isRootControl($device)
                ||($scope&&$scope->isLocal()&&$scope->id===$device),403);
        }
        $prints=app(DesktopDashboardPrintEffects::class);$refresh=app(DesktopDashboardRefresh::class);
        return match($value['action']??null){
            'pending'=>$this->pending($device),
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
            default=>abort(422,'أمر التشغيل المحلي غير معروف.'),
        };
    }
    private function pending(string $device): array
    {
        $value=['commands'=>$this->journal->pending($device),'counts'=>$this->journal->counts($device),
            'print_protocol'=>app(DesktopDashboardPrintLedger::class)->active($device)?1:0];
        if(app(DesktopDashboardWorkspaceLocalAuthority::class)->selected())
            $value['print_state']=app(DesktopDashboardPrintEffects::class)->workspaceState($device);
        return $value;
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
