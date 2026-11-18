<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Validator};

/** Native metadata review; never changes an immutable business envelope or accepts a result. */
class DesktopDashboardOutboxReview
{
    private function scope(string $device): object
    {
        abort_unless(config('desktop_dashboard.local')&&DB::connection()->getConfig('host')==='127.0.0.1'
            &&(string)config('desktop_dashboard.device_id')===$device,403);
        $scope=app(DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);
        if($scope instanceof DesktopDashboardWorkspaceScope){$scope->revalidated();return $scope;}
        $fresh=DB::table('desktop_dashboard_devices')->where('id',$device)->lockForUpdate()->first();
        abort_unless($fresh&&$fresh->enabled&&(int)$fresh->actor_id===(int)$scope->actor_id,403,'ربط الجهاز أو حسابه لم يعد مفعّلًا.');
        DB::table('users')->where('id',$scope->actor_id)->lockForUpdate()->first();
        app(DesktopDashboardDevices::class)->actor($fresh);
        return $scope;
    }
    private function token(object $row): string
    {
        return hash('sha256',DesktopDashboardBootstrap::json([$row->device_id,$row->command_id,(int)$row->sequence,
            (int)$row->actor_id,$row->request_hash,$row->status,(int)$row->attempts,$row->updated_at,$row->last_error]));
    }
    public function inspect(string $device): array
    {
        return DB::transaction(function()use($device){
            $scope=$this->scope($device);
            $first=DB::table('desktop_dashboard_commands')->where('device_id',$device)->where('status','!=','acknowledged')->orderBy('sequence')->lockForUpdate()->first();
            if(!$scope instanceof DesktopDashboardWorkspaceScope)abort_if($first&&(int)$first->actor_id!==(int)$scope->actor_id,403);
            $row=$first?['command_id'=>$first->command_id,'sequence'=>(int)$first->sequence,'actor_id'=>(int)$first->actor_id,
                'route_name'=>$first->route_name,'status'=>$first->status,'attempts'=>(int)$first->attempts,
                'occurred_at'=>(string)$first->created_at,'last_error'=>(string)($first->last_error??''),'review_token'=>$this->token($first)]:null;
            return ['format'=>1,'device_id'=>$device,'actor_id'=>(int)$scope->actor_id,'snapshot_id'=>$scope->snapshot_id,
                'held'=>$scope->state!=='ready','counts'=>app(DesktopDashboardJournal::class)->counts($device),'first'=>$row];
        });
    }
    public function retry(string $device,string $command,string $token): array
    {
        Validator::make(['command'=>$command,'token'=>$token],['command'=>'required|uuid','token'=>'required|regex:/^[a-f0-9]{64}$/D'])->validate();
        return DB::transaction(function()use($device,$command,$token){
            $scope=$this->scope($device);abort_unless($scope->state==='ready',409,'تحديث بيانات الجهاز ما زال قيد التنفيذ.');
            $row=DB::table('desktop_dashboard_commands')->where('device_id',$device)->where('status','!=','acknowledged')->orderBy('sequence')->lockForUpdate()->first();
            abort_unless($row&&$row->command_id===$command&&(int)$row->actor_id===(int)$scope->actor_id
                &&$row->status==='conflict'&&hash_equals($this->token($row),$token),409,'حالة العملية تغيّرت؛ افتح المراجعة من جديد.');
            $fresh=$scope instanceof DesktopDashboardWorkspaceScope?$scope:DB::table('desktop_dashboard_devices')->where('id',$device)->first();$devices=app(DesktopDashboardDevices::class);$actor=$devices->actor($fresh);
            // Retrying queues the same envelope only. Every current original feature permission,
            // branch relationship and before-fact is checked again by normal central ingestion.
            foreach(json_decode($scope->branches,true,512,JSON_THROW_ON_ERROR) as $branch)$devices->branch($fresh,(string)$branch,$actor);
            DB::table('desktop_dashboard_commands')->where('sequence',$row->sequence)->update(['status'=>'pending','updated_at'=>now('UTC')]);
            return ['format'=>1,'command_id'=>$command,'queued'=>true,'counts'=>app(DesktopDashboardJournal::class)->counts($device)];
        });
    }
}
