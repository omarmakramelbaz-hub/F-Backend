<?php
namespace App\Services\Dashboard;

use App\Models\User;

/** Private capability minted only after the original password/remember guard established the actual actor. */
final class DesktopDashboardWorkspaceNativeSessions
{
    private const FIELDS=['format','kind','workspace_id','root_fingerprint','actor_id','manifest_revision',
        'password_binding','snapshot_id','schema_hash','control_binding','nonce','mac'];
    public function __construct(private DesktopDashboardWorkspaceLocalAuthority $local) {}

    public function issue(DesktopDashboardWorkspaceScope $scope): array
    {
        abort_unless($scope->isLocal()&&(int)auth('admin')->id()===$scope->actor_id,403);
        $this->local->assertScope($scope);$state=$scope->localState();abort_unless($state,403);
        $value=['format'=>2,'kind'=>'desktop-workspace-native-session','workspace_id'=>$scope->id,
            'root_fingerprint'=>$scope->rootFingerprint(),'actor_id'=>$scope->actor_id,
            'manifest_revision'=>$scope->manifestRevision(),'password_binding'=>$scope->member()['password_binding'],
            'snapshot_id'=>$state->snapshot_id,'schema_hash'=>$state->schema_hash,'control_binding'=>$this->controlBinding(),
            'nonce'=>bin2hex(random_bytes(32))];
        return $value+['mac'=>$this->mac($value)];
    }

    /** Establishes no browser cookie; the actor is derived from a verified capability. */
    public function withSession(array $value,callable $work)
    {
        $keys=array_keys($value);sort($keys);$expected=self::FIELDS;sort($expected);
        abort_unless($keys===$expected&&$value['format']===2&&$value['kind']==='desktop-workspace-native-session'
            &&is_int($value['actor_id'])&&$value['actor_id']>0&&$value['actor_id']<=9007199254740991
            &&is_int($value['manifest_revision'])&&$value['manifest_revision']>0,403);
        foreach(['root_fingerprint','password_binding','schema_hash','control_binding','nonce','mac'] as $key)
            abort_unless(is_string($value[$key])&&preg_match('/^[a-f0-9]{64}$/D',$value[$key]),403);
        foreach(['workspace_id','snapshot_id'] as $key)
            abort_unless(is_string($value[$key])&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$value[$key]),403);
        $unsigned=$value;unset($unsigned['mac']);
        abort_unless(hash_equals($value['mac'],$this->mac($unsigned))
            &&hash_equals($value['control_binding'],$this->controlBinding())
            &&$value['workspace_id']===(string)config('desktop_dashboard.device_id'),403);
        $actor=User::withoutGlobalScopes()->find($value['actor_id']);abort_unless($actor,403);
        $this->local->rememberBinding($actor);
        $guard=auth('admin');$previous=$guard->getUser();
        abort_if($previous&&(int)$previous->id!==$value['actor_id'],403);
        try{
            $guard->setUser($actor);$scope=$this->local->currentScope($actor);$state=$scope->localState();
            abort_unless($scope->id===$value['workspace_id']&&$scope->rootFingerprint()===$value['root_fingerprint']
                &&$scope->manifestRevision()===$value['manifest_revision']
                &&hash_equals($scope->member()['password_binding'],$value['password_binding'])
                &&$state->snapshot_id===$value['snapshot_id']&&$state->schema_hash===$value['schema_hash'],403);
            return DesktopDashboardWorkspaceExecutionContext::run($scope,fn()=>$work($scope));
        }finally{if($previous)$guard->setUser($previous);else $guard->forgetUser();}
    }
    private function controlBinding(): string
    {
        $token=getenv('DESKTOP_DASHBOARD_CONTROL_TOKEN');
        abort_unless(config('desktop_dashboard.local')===true&&$this->local->selected()
            &&is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token),403);
        return hash('sha256',$token);
    }
    private function mac(array $value): string
    {
        $key=app('encrypter')->getKey();abort_unless(is_string($key)&&strlen($key)>=32,503);
        return hash_hmac('sha256',"desktop-workspace-native-session-v2\0".DesktopDashboardWorkspaceAuthority::canonical($value),$key);
    }
}
