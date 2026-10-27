<?php
namespace App\Services\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Crypt,Validator};

/** Original terminal decisions with one physical fence and the actual member as actor. */
final class DesktopDashboardWorkspaceRemoteAttempts extends DesktopDashboardRemoteAttempts
{
    public function __construct(DesktopDashboardDevices $devices,private DesktopDashboardWorkspaceAuthority $authority)
    {
        parent::__construct($devices);
    }

    public function decideWorkspace(DesktopDashboardWorkspaceScope $scope,array $values,bool $cancel): array
    {
        $this->serverScope($scope);
        $keys=array_keys($values);sort($keys);
        abort_unless($keys===['id','method','path']&&is_string($values['id'])
            &&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$values['id']),422);
        return DesktopDashboardWorkspaceExecutionContext::run($scope,function()use($scope,$values,$cancel){
            return DB::transaction(function()use($scope,$values,$cancel){
                $this->authority->assertScope($scope);
                $existing=DB::table('desktop_dashboard_remote_attempts')->where('id',$values['id'])->lockForUpdate()->first();
                // A reservation whose result is unknown holds the complete
                // workspace. Switching accounts cannot create a second writer.
                if(!$cancel&&!$existing)$this->settledOthers($scope->id,$values['id']);
                $result=parent::decide($scope,$values,$cancel);
                abort_unless($result['device_id']===$scope->id&&$result['actor_id']===$scope->actor_id,409);
                $result['format']=2;$result['kind']='desktop-workspace-remote-attempt';
                $result['workspace']=['workspace_id'=>$scope->id,'root_fingerprint'=>$scope->rootFingerprint(),
                    'manifest_revision'=>$scope->manifestRevision()];
                return $result;
            });
        });
    }

    /** Historical membership can settle a reservation; it can never authorize its business request. */
    public function settleWorkspace(object $root,array $proof,array $values): array
    {
        abort_unless(config('desktop_dashboard.local')!==true&&config('desktop_dashboard.workspace_enabled')===true,403);
        $keys=array_keys($proof);sort($keys);
        abort_unless($keys===['actor_id','grant','manifest_revision']&&is_int($proof['actor_id'])&&$proof['actor_id']>0
            &&$proof['actor_id']<=9007199254740991&&is_int($proof['manifest_revision'])&&$proof['manifest_revision']>0
            &&$proof['manifest_revision']<=9007199254740991&&is_string($proof['grant'])&&preg_match('/^[a-f0-9]{64}$/D',$proof['grant']),422);
        $keys=array_keys($values);sort($keys);abort_unless($keys===['id','method','path'],422);
        $v=Validator::make($values,['id'=>'required|uuid','method'=>'required|in:GET,POST,PUT,PATCH,DELETE','path'=>'required|string|max:200'])->validate();
        abort_unless(str_starts_with($v['path'],'/admin/')&&!preg_match('/[\x00-\x20\x7f?#\\\\]/',$v['path']),422);
        return DB::transaction(function()use($root,$proof,$v){
            $fresh=$this->authority->lockAuthenticated($root);$fingerprint=$this->authority->rootFingerprint($fresh);
            $member=$this->authority->validateHistoricalMember($fresh,$proof['actor_id'],$proof['grant'],$proof['manifest_revision']);
            abort_unless($member['actor_id']===$proof['actor_id'],403);
            $row=DB::table('desktop_dashboard_remote_attempts')->where('id',$v['id'])->lockForUpdate()->first();
            if(!$row){
                // A lost reserve reply may mean no reservation arrived. The
                // tombstone prevents its delayed original from executing later.
                DB::table('desktop_dashboard_remote_attempts')->insert(['id'=>$v['id'],'device_id'=>$fresh->id,'actor_id'=>$proof['actor_id'],
                    'method'=>$v['method'],'path'=>$v['path'],'status'=>'cancelled',
                    'response_cipher'=>Crypt::encryptString(json_encode(['enrollment_binding'=>['format'=>1,'fingerprint'=>$fingerprint]],JSON_THROW_ON_ERROR)),
                    'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
                $row=DB::table('desktop_dashboard_remote_attempts')->where('id',$v['id'])->first();
            }
            abort_unless($row&&$row->device_id===$fresh->id&&(int)$row->actor_id===$proof['actor_id']
                &&$row->method===$v['method']&&$row->path===$v['path'],409);
            try{$saved=json_decode(Crypt::decryptString($row->response_cipher??''),true,512,JSON_THROW_ON_ERROR);}
            catch(\Throwable $error){abort(503,'دليل نتيجة العملية المحفوظة يحتاج مراجعة.');}
            abort_unless(is_array($saved)&&($saved['enrollment_binding']['format']??null)===1
                &&is_string($saved['enrollment_binding']['fingerprint']??null)
                &&hash_equals($fingerprint,$saved['enrollment_binding']['fingerprint']),401);
            if($row->status==='ready'){
                DB::table('desktop_dashboard_remote_attempts')->where('id',$row->id)->update(['status'=>'cancelled','updated_at'=>now('UTC')]);$row->status='cancelled';
            }
            abort_unless(in_array($row->status,['committed','cancelled'],true),503);
            // No business callback, response body, new execution capability or
            // replacement actor is produced by terminal recovery.
            return ['format'=>2,'kind'=>'desktop-workspace-remote-attempt','id'=>$row->id,'device_id'=>$row->device_id,
                'actor_id'=>(int)$row->actor_id,'method'=>$row->method,'path'=>$row->path,'status'=>$row->status,'capability'=>null,
                'workspace'=>['workspace_id'=>$fresh->id,'root_fingerprint'=>$fingerprint,'manifest_revision'=>$proof['manifest_revision']]];
        });
    }

    /** The group facade cannot fall back to a request-supplied legacy device. */
    public function decide(object $device,array $values,bool $cancel): array
    {
        abort_unless($device instanceof DesktopDashboardWorkspaceScope,403);
        return $this->decideWorkspace($device,$values,$cancel);
    }

    public function handle(Request $request,callable $next)
    {
        $scope=DesktopDashboardWorkspaceExecutionContext::current();
        abort_unless($scope instanceof DesktopDashboardWorkspaceScope&&(int)auth('admin')->id()===$scope->actor_id,403);
        $this->serverScope($scope);
        foreach(['X-Fasakhansta-Remote-Attempt','X-Fasakhansta-Remote-Capability','X-Fasakhansta-Connected-Device','X-Fasakhansta-Connected-Token'] as $legacy)
            abort_if($request->hasHeader($legacy),422);
        [$id,$capability]=$this->attemptHeaders($request);
        DB::transaction(function()use($scope,$id){
            $this->authority->assertScope($scope);
            $row=DB::table('desktop_dashboard_remote_attempts')->where('id',$id)->lockForUpdate()->first();
            abort_unless($row&&$row->device_id===$scope->id&&(int)$row->actor_id===$scope->actor_id,409);
            if($row->status==='ready')$this->settledOthers($scope->id,$id);
        });
        return parent::handle($request,$next);
    }

    protected function attemptHeaders(Request $request): array
    {
        $id=$request->header('X-Fasakhansta-Workspace-Attempt');$capability=$request->header('X-Fasakhansta-Workspace-Capability');
        abort_unless(is_string($id)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id)
            &&is_string($capability)&&preg_match('/^[a-f0-9]{64}$/D',$capability),422);
        return [$id,$capability];
    }
    protected function resolveDevice(object $candidate): object
    {
        $scope=DesktopDashboardWorkspaceExecutionContext::current();
        abort_unless($scope instanceof DesktopDashboardWorkspaceScope&&$candidate->device_id===$scope->id
            &&(int)$candidate->actor_id===$scope->actor_id&&(int)auth('admin')->id()===$scope->actor_id,403);
        $this->serverScope($scope);return $scope;
    }
    private function serverScope(DesktopDashboardWorkspaceScope $scope): void
    {
        abort_unless(config('desktop_dashboard.local')!==true&&config('desktop_dashboard.workspace_enabled')===true&&!$scope->isLocal(),403);
        $scope->assertIssued();$this->authority->assertScope($scope);
    }
    private function settledOthers(string $workspace,string $attempt): void
    {
        abort_if(DB::table('desktop_dashboard_remote_attempts')->where('device_id',$workspace)
            ->where('id','<>',$attempt)->where('status','ready')->lockForUpdate()->exists(),409,
            'نتيجة عملية سابقة على الجهاز لم تُحسم بعد؛ انتظر تأكيدها قبل تنفيذ عملية أخرى.');
    }
}
