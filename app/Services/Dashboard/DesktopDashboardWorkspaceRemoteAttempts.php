<?php
namespace App\Services\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Crypt,Validator,Schema};
use App\Models\User;

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
            if($row->status==='committed')abort_unless(is_string($row->request_hash??null)&&preg_match('/^[a-f0-9]{64}$/D',$row->request_hash)
                &&is_int($saved['status']??null)&&$saved['status']>=200&&$saved['status']<400
                &&is_string($saved['operation']??null)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$saved['operation'])
                &&is_string($saved['content']??null)&&strlen($saved['content'])<=1024*1024,503);
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

    /** Pair-free browsing/provider traffic cannot bypass an already uncertain workspace write. */
    public function authorizeDirect(DesktopDashboardWorkspaceScope $scope,Request $request): void
    {
        $this->serverScope($scope);
        // Ending this actor's own browser session does not settle or erase any
        // physical-workspace uncertainty, and must remain available during it.
        if(ltrim((string)$request->route()?->getActionName(),'\\')===\App\Http\Controllers\Dashboard\HomeController::class.'@adminLogout')return;
        abort_if(DesktopDashboardRoutes::journaled($request->route()?->getName()),422,
            'العملية تحتاج حجز نتيجتها من جسر البرنامج قبل إرسالها.');
        DB::transaction(function()use($scope){$this->authority->assertScope($scope);$this->settledOthers($scope->id,'');});
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

    /**
     * Connected credential/identity edits use the original server form. A NAME
     * edit still uses the reviewed adapter; neither path changes local grants.
     */
    protected function originalUserUpdate(Request $request,User $actor,object $device,object $row): ?array
    {
        if(!DesktopDashboardUserProfiles::handles($request->route()?->getName()))return null;
        abort_unless($device instanceof DesktopDashboardWorkspaceScope,403);$this->serverScope($device);
        $route=$request->route();
        abort_unless($route->getActionName()===\App\Http\Controllers\Dashboard\UserController::class.'@update'
            &&$route->uri()==='admin/users/{user}'&&$route->methods()===['PUT','PATCH']
            &&in_array('IsAdmin',$route->gatherMiddleware(),true)&&array_keys($route->parameters())===['user']
            &&in_array($request->method(),['PUT','PATCH'],true),409);
        abort_if($request->allFiles(),501,'مرفقات الحساب تحتاج مسار الرفع المتصل المعتمد.');
        $value=$request->route('user');$value=$value instanceof User?$value->getKey():$value;
        abort_unless(is_scalar($value)&&preg_match('/^[1-9][0-9]{0,18}$/D',(string)$value)
            &&(string)(int)$value===(string)$value,422);$target=(int)$value;
        $command=app(DesktopDashboardUserAccounts::class)->command($request);
        $savedRow=$row->status==='committed'?$row:DB::table('desktop_dashboard_remote_attempts')
            ->where('device_id',$device->id)->where('operation_id',$command)->lockForUpdate()->first();
        if($savedRow){
            abort_unless($savedRow->status==='committed'&&(int)$savedRow->actor_id===(int)$actor->id,409);
            try{$saved=json_decode(Crypt::decryptString($savedRow->response_cipher),true,512,JSON_THROW_ON_ERROR);}
            catch(\Throwable $error){abort(503);}
            if(!array_key_exists('workspace_user_update',$saved))return null;
            $binding=$this->userUpdateBinding($saved['workspace_user_update']);
            abort_unless($binding['target']===$target,409);
            $this->userUpdatePermission($device,$actor,$binding['account_type']);return $binding;
        }
        $before=DB::table('users')->where('id',$target)->lockForUpdate()->first();abort_unless($before,404);
        $values=$request->except('_token','_method','_desktop_command');
        $controls=['account_type','mobile','email','owner_resturant_id','pending_vendor_id','delegate_fees','min_wallet','area_id','gender'];
        $pending=['national_id','commercial_registration_no','driving_license_no','tax_no','owner_name','branches_no','location','vodafone_cash_mobile','another_mobile'];
        abort_if(array_diff(array_keys($values),['name','password','roles_name','added_by',...$controls,...$pending]),422);
        // The original controller derives its edit permission from this form
        // field. Bind it to the persisted target rather than a client claim.
        abort_unless(is_string($values['account_type']??null)&&$values['account_type']===$before->account_type,409);
        $changed=isset($values['password'])&&$values['password']!=='';
        foreach($controls as $key)if(array_key_exists($key,$values)){
            abort_unless($values[$key]===null||is_scalar($values[$key]),422);
            if((string)($values[$key]??'')!==(string)($before->$key??''))$changed=true;
        }
        if(array_key_exists('roles_name',$values)){
            abort_unless(is_array($values['roles_name'])&&count($values['roles_name'])<=100,422);
            $roles=array_values($values['roles_name']);foreach($roles as $role)abort_unless(is_string($role)&&$role!=='',422);sort($roles,SORT_STRING);
            $actual=DB::table('roles')->join('model_has_roles','roles.id','=','model_has_roles.role_id')
                ->where('model_has_roles.model_type',$actor->getMorphClass())->where('model_has_roles.model_id',$target)
                ->where('roles.guard_name','admin')->orderBy('roles.name')->lockForUpdate()->pluck('roles.name')->all();
            if($roles!==$actual)$changed=true;
        }
        if(array_intersect(array_keys($values),$pending)){$application=empty($before->pending_vendor_id)?null:DB::table('pending_vendors')->where('id',$before->pending_vendor_id)->lockForUpdate()->first();
            foreach($pending as $key)if(array_key_exists($key,$values)){
                abort_unless($values[$key]===null||is_scalar($values[$key]),422);
                if((string)($values[$key]??'')!==(string)($application->$key??''))$changed=true;
            }
        }
        if(!$changed)return null;
        $this->userUpdatePermission($device,$actor,$before->account_type);
        // The original repository updates the application and role pivots in
        // this same transaction. File effects are deliberately excluded above.
        $tables=['users','pending_vendors','roles','permissions','role_has_permissions','model_has_roles','model_has_permissions'];
        foreach(['crm_customers','crm_customer_sources'] as $table)if(Schema::hasTable($table))$tables[]=$table;
        foreach($tables as $table){$engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',
            [DB::connection()->getDatabaseName(),$table]);abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,503);}
        return ['format'=>2,'kind'=>'original-connected-user-update','target'=>$target,'account_type'=>$before->account_type];
    }

    protected function originalUserUpdateReply(string $route,array $saved,User $actor,object $device): bool
    {
        if(!array_key_exists('workspace_user_update',$saved))return false;
        abort_unless($route==='users.update'&&$device instanceof DesktopDashboardWorkspaceScope,503);
        $this->serverScope($device);$binding=$this->userUpdateBinding($saved['workspace_user_update']);
        $this->userUpdatePermission($device,$actor,$binding['account_type']);return true;
    }
    protected function verifyOriginalUserUpdateResponse(Request $request,$response,array $binding)
    {
        $location=$response->headers->get('Location')??'';$path=parse_url($location,PHP_URL_PATH);
        abort_unless(in_array($response->getStatusCode(),[302,303],true)&&is_string($path)&&str_starts_with($path,'/admin/'),409);
        $binding=$this->userUpdateBinding($binding);$edit=app('router')->getRoutes()->getByName('users.edit');
        abort_unless($edit&&$edit->getActionName()===\App\Http\Controllers\Dashboard\UserController::class.'@edit'
            &&$edit->uri()==='admin/users/{user}/edit',409);
        // Persist only a redirect made from bound public route facts. The
        // original arbitrary Referer/body and credential input are not retained.
        // A password coinciding with a route/type literal remains perfectly valid.
        return redirect()->to(route('users.edit',['user'=>$binding['target'],'account_type'=>$binding['account_type']],false),$response->getStatusCode());
    }
    private function userUpdateBinding($binding): array
    {
        abort_unless(is_array($binding)&&array_keys($binding)===['format','kind','target','account_type']
            &&$binding['format']===2&&$binding['kind']==='original-connected-user-update'
            &&is_int($binding['target'])&&$binding['target']>0&&is_string($binding['account_type'])
            &&preg_match('/^[a-z_]{1,40}$/D',$binding['account_type']),503);return $binding;
    }
    private function userUpdatePermission(DesktopDashboardWorkspaceScope $scope,User $actor,string $type): void
    {
        $name=$type.'-edit';$facts=$scope->permissionFacts();
        abort_unless((int)$actor->id===$scope->actor_id&&($facts['super_admin']||in_array($name,$facts['names'],true)),403);
        $permission=DB::table('permissions')->where('guard_name','admin')->where('name',$name)->lockForUpdate()->first();abort_unless($permission,403);
        $roles=DB::table('roles')->join('model_has_roles','roles.id','=','model_has_roles.role_id')
            ->where('model_has_roles.model_type',$actor->getMorphClass())->where('model_has_roles.model_id',$actor->id)
            ->where('roles.guard_name','admin')->lockForUpdate()->pluck('roles.id');
        $direct=DB::table('model_has_permissions')->where('model_type',$actor->getMorphClass())->where('model_id',$actor->id)
            ->where('permission_id',$permission->id)->lockForUpdate()->exists();
        abort_unless($direct||DB::table('role_has_permissions')->whereIn('role_id',$roles)->where('permission_id',$permission->id)->lockForUpdate()->exists(),403);
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
