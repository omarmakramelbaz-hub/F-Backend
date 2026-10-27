<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\{DesktopDashboardWorkspaceAuthority,DesktopDashboardWorkspaceLocalAuthority,
    DesktopDashboardWorkspaceEnrollmentSeal,DesktopDashboardWorkspaceExecutionContext};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Device-wide setup is available from every eligible authenticated dashboard account. */
final class DesktopDashboardWorkspaceController extends Controller
{
    private function origin(): string
    {
        $origin=rtrim((string)config('app.url'),'/');$parts=parse_url($origin);
        abort_unless(is_array($parts)&&($parts['scheme']??null)==='https'&&isset($parts['host'])
            &&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['query'])&&!isset($parts['fragment'])
            &&(!isset($parts['path'])||$parts['path']===''),503,'عنوان السيرفر المعتمد غير مكتمل.');
        return $origin;
    }
    public function challenge(DesktopDashboardWorkspaceAuthority $authority)
    {
        abort_if(config('desktop_dashboard.local')===true,403);$authority->ready();
        $actor=auth('admin')->user();abort_unless($actor,401);
        $member=DB::transaction(fn()=>$authority->persistedMemberFacts((int)$actor->id));
        return response()->json(['protocol'=>2,'actor_id'=>$member['actor_id'],'role'=>$member['role'],
            'server_origin'=>$this->origin(),'csrf_token'=>csrf_token()])->withHeaders([
                'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public function enroll(Request $request,DesktopDashboardWorkspaceAuthority $authority,DesktopDashboardWorkspaceEnrollmentSeal $seal)
    {
        abort_if(config('desktop_dashboard.local')===true,403);
        $values=$request->validate(['device_id'=>'required|uuid','name'=>'required|string|max:100',
            'nonce'=>'required|string|size:64|regex:/^[a-f0-9]+$/D','public_key'=>'required|string|max:2048']);
        abort_if(array_diff(array_keys($request->except('_token')),['device_id','name','nonce','public_key']),422);
        $origin=$this->origin();
        // Seal before committing enrollment: malformed recipient keys cannot reserve a physical UUID.
        try{$wire=DB::transaction(function()use($values,$origin,$authority,$seal){
            $enrolled=$authority->enroll(array_intersect_key($values,array_flip(['device_id','name','nonce'])),auth('admin')->user());
            $envelope=$seal->seal(['credential'=>['workspaceId'=>$enrolled['workspace_id'],'serverOrigin'=>$origin,
                'token'=>$enrolled['token'],'enrollmentId'=>$enrolled['root_fingerprint']],
                'manifest'=>$enrolled['manifest']],$values['public_key'],$enrolled['workspace_id'],$values['nonce']);
            return DesktopDashboardWorkspaceEnrollmentSeal::encode($envelope);
        });}catch(\InvalidArgumentException $error){abort(422,'مفتاح تجهيز الجهاز غير صالح؛ أعد الطلب من البرنامج.');}
        return response($wire,200,['Content-Type'=>'application/json','Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff']);
    }
    public function session(Request $request,DesktopDashboardWorkspaceAuthority $authority)
    {
        abort_if(config('desktop_dashboard.local')===true,403);
        $root=$authority->authenticate((string)$request->bearerToken());
        $values=$request->validate(['actor_id'=>'required|integer|min:1','manifest_revision'=>'required|integer|min:1',
            'grant'=>'required|regex:/^[a-f0-9]{64}$/D']);
        abort_unless((int)auth('admin')->id()===(int)$values['actor_id'],403);
        $scope=$authority->scope($root,(int)$values['actor_id'],$values['grant'],(int)$values['manifest_revision']);
        return response()->json($this->sessionPayload($scope))->header('Cache-Control','private, no-store');
    }
    public function localSession(Request $request,DesktopDashboardWorkspaceLocalAuthority $authority)
    {
        abort_unless(config('desktop_dashboard.local')===true&&$authority->selected(),403);
        $root=$authority->authenticateLocal((string)$request->bearerToken());
        $scope=DesktopDashboardWorkspaceExecutionContext::current();
        abort_unless($scope&&$scope->isLocal()&&$scope->id===$root->device_id
            &&(int)auth('admin')->id()===$scope->actor_id,403);
        return response()->json($this->sessionPayload($scope))->withHeaders([
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    private function sessionPayload($scope): array
    {
        return ['protocol'=>2,'workspace_id'=>$scope->id,'custodian_actor_id'=>(int)$scope->root()->custodian_actor_id,
            'actor_id'=>$scope->actor_id,'role'=>$scope->member()['role'],'manifest_revision'=>$scope->manifestRevision(),
            'root_fingerprint'=>$scope->rootFingerprint()];
    }
}
