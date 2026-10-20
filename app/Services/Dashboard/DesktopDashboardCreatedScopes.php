<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\{Crypt,DB,Schema,Validator};

/** Append-only catalog creation entitlements. Financial enrollment always remains BASE. */
final class DesktopDashboardCreatedScopes
{
    public const TABLE='desktop_dashboard_created_scopes';
    public const ROUTE='go-stores.store';
    private const OWNER=['id','added_by','name','mobile','email','account_type','app_scope','status','balance','delegate_fees','created_at','updated_at','email_verified_at','pending_vendor_id','owner_resturant_id'];
    private const PROFILE=['user_id','name','kind','address','revision','created_at','updated_at'];
    private const COLUMNS=['grant_id','device_id','enrollment_id','command_id','actor_id','route_name','capability','entity','entity_id','origin_entity_id','branch'];
    public function available(): bool {return Schema::hasTable(self::TABLE);}
    public function active(): bool
    {
        if(config('desktop_dashboard.local')!==true||!Schema::hasTable('desktop_dashboard_local_state'))return false;
        $coverage=DB::table('desktop_dashboard_local_state')->where('device_id',config('desktop_dashboard.device_id'))->value('coverage');
        return (json_decode($coverage??'{}',true)['catalog_scopes']??null)===1;
    }
    public function enrollment(object $device): string
    {
        $branches=json_decode($device->branches,true,512,JSON_THROW_ON_ERROR);sort($branches,SORT_STRING);
        return $this->hash(['device_id'=>$device->id,'actor_id'=>(int)$device->actor_id,'enrollment_hash'=>$device->enrollment_hash,'enrolled_at'=>(string)$device->enrolled_at,'branches'=>$branches]);
    }
    /** Structural scope only. Calling this never grants independent User/settings/POS authority. */
    public function structural(object $device,User $actor): array
    {
        $fresh=$this->device($device,$actor);$branches=json_decode($fresh->branches,true,512,JSON_THROW_ON_ERROR);
        if(!$this->available())return $branches;
        foreach(DB::table(self::TABLE)->where('device_id',$fresh->id)->where('enrollment_id',$this->enrollment($fresh))->orderBy('grant_id')->limit(1001)->lockForUpdate()->get() as $index=>$row){
            abort_unless($index<1000,413);$descriptor=$this->read($row,$fresh,$actor);$branches[]=$descriptor['branch'];
        }
        return array_values(array_unique($branches));
    }
    /** Current actionable catalog scopes; dormant targets remain recorded only in structural(). */
    public function effective(object $device,User $actor): array
    {
        $fresh=$this->device($device,$actor);$branches=json_decode($fresh->branches,true,512,JSON_THROW_ON_ERROR);
        foreach($this->export($fresh,$actor) as $item){$descriptor=$item['descriptor'];if($this->live($descriptor,$actor))$branches[]=$descriptor['branch'];}
        return array_values(array_unique($branches));
    }
    public function catalog(object $device,string $branch,User $actor): void
    {
        // The original catalog authority is independent of both creation and financial rights.
        $current=app(DesktopDashboardGoStoreProfile::class)->authorizeActor($actor);$fresh=$this->device($device,$current);
        if(config('desktop_dashboard.local')===true&&isset($device->device_id))$this->assertStructural($device,$current);
        abort_unless(preg_match('/^gs:([1-9][0-9]{0,18})$/D',$branch,$m),403);
        if(in_array($branch,json_decode($fresh->branches,true,512,JSON_THROW_ON_ERROR),true))return;
        abort_unless($this->available(),403);
        $row=DB::table(self::TABLE)->where('device_id',$fresh->id)->where('enrollment_id',$this->enrollment($fresh))->where('branch',$branch)->lockForUpdate()->first();
        abort_unless($row,403,'المتجر خارج نطاق الكتالوج المصرح به للجهاز.');$descriptor=$this->read($row,$fresh,$current);$this->target($descriptor,$current);
    }
    public function captureLocal(string $device,string $command,int $actor,string $route,array $payload,array $result,array $references): void
    {
        if($route!==self::ROUTE||isset($payload['envelope']))return;
        abort_unless($this->active(),503,'تجهيز صلاحية كتالوج المتجر الجديد غير مكتمل.');
        $current=User::withoutGlobalScopes()->whereKey($actor)->lockForUpdate()->firstOrFail();$fresh=$this->device((object)['id'=>$device],$current);
        $state=DB::table('desktop_dashboard_local_state')->where('device_id',$device)->lockForUpdate()->first();
        abort_unless($state,409);$this->assertStructural($state,$current);
        $this->mint($fresh,$command,$route,$payload,$result,$references,(int)($result['created_scope']['id']??0));
        // Snapshot branches and coverage remain immutable for the native restart binding.
        // Later local capabilities are derived only from their committed Journal receipt.
    }
    public function captureCentral(object $device,array $envelope,array $payload,array $result,array $references): void
    {
        if($envelope['route_name']!==self::ROUTE)return;
        $origin=$envelope['local_result']['created_scope']??null;
        abort_unless(is_array($origin)&&($origin['kind']??null)==='gs'&&($origin['entity']??null)==='menu_store_owner'
            &&($origin['command_id']??null)===$envelope['command_id']&&is_int($origin['id']??null)&&$origin['id']>0,409);
        $keys=array_keys($envelope['local_references']??[]);sort($keys);abort_unless($keys===['dashboard_user','menu_store_owner','wallet_account'],409);
        foreach(['menu_store_owner','wallet_account','dashboard_user'] as $entity)abort_unless(($envelope['local_references'][$entity]??null)===$origin['id'],409);
        $fresh=$this->device($device,User::withoutGlobalScopes()->whereKey($device->actor_id)->lockForUpdate()->firstOrFail());
        $this->mint($fresh,$envelope['command_id'],self::ROUTE,$payload,$result,$references,$origin['id']);
    }
    public function captureNative(object $device,string $attempt,string $command,array $payload,array $result): void
    {
        if(!$this->available())return;
        $this->mint($device,$command,self::ROUTE,$payload,$result,$result['references']??[],(int)($result['created_scope']['id']??0),['kind'=>'remote-attempt','attempt_id'=>$attempt]);
    }
    private function mint(object $device,string $command,string $route,array $payload,array $result,array $references,int $origin,array $provenance=['kind'=>'journal']): array
    {
        abort_unless($this->available()&&DB::transactionLevel()>0,503);
        $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[self::TABLE]);abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,503);
        $actor=app(DesktopDashboardCreatedScopeAuthority::class)->authorize($route,User::withoutGlobalScopes()->whereKey($device->actor_id)->lockForUpdate()->firstOrFail());
        app(DesktopDashboardCreatedScopeAuthority::class)->validateCreated($route,$result,$payload,$actor);
        abort_unless($command===($result['created_scope']['command_id']??null)&&$origin>0,409);
        $expected=['dashboard_user','menu_store_owner','wallet_account'];$actual=array_keys($references);sort($actual);$resultKeys=array_keys($result['references']??[]);sort($resultKeys);abort_unless($actual===$expected&&$resultKeys===$expected,409);
        $id=$result['created_scope']['id'];foreach(['menu_store_owner','wallet_account','dashboard_user'] as $entity)abort_unless(($references[$entity]??null)===$id,409);
        $creator=DB::table('users')->where('id',$actor->id)->lockForUpdate()->first(['id','created_at']);
        $profile=DB::table('go_stores')->where('user_id',$id)->lockForUpdate()->first(['created_at']);
        abort_unless($creator&&$profile&&is_string($profile->created_at),409);
        $descriptor=['format'=>1,'device_id'=>$device->id,'enrollment_id'=>$this->enrollment($device),'command_id'=>$command,'actor_id'=>(int)$actor->id,
            'route_name'=>$route,'capability'=>'catalog','entity'=>'menu_store_owner','entity_id'=>$id,'origin_entity_id'=>$origin,'branch'=>'gs:'.$id,
            'owner_created_at'=>$result['after']['owner']['created_at'],'creator_created_at'=>$creator->created_at,'profile_created_at'=>$profile->created_at];
        $creation=$this->creation($device,$command,$result,$creator->created_at);$descriptor['result_sha256']=$this->hash($creation);
        $descriptor['grant_id']=$this->grantId($descriptor);$this->store($descriptor,$creation,$provenance);
        $stored=DB::table(self::TABLE)->where('grant_id',$descriptor['grant_id'])->lockForUpdate()->first();$this->read($stored,$device,$actor);return $descriptor;
    }
    /** Transport authenticated public creator receipts, never credential payloads or server ciphertext. */
    public function export(object $device,User $actor): array
    {
        $fresh=$this->device($device,$actor);if(!$this->available())return [];$out=[];
        foreach(DB::table(self::TABLE)->where('device_id',$fresh->id)->where('enrollment_id',$this->enrollment($fresh))->orderBy('grant_id')->limit(1001)->lockForUpdate()->get() as $index=>$row){
            abort_unless($index<1000,413);$descriptor=$this->read($row,$fresh,$actor);
            $value=json_decode(Crypt::decryptString($row->evidence_cipher),true,32,JSON_THROW_ON_ERROR);
            $out[]=['descriptor'=>$descriptor,'creation'=>$value['creation']];
        }
        return $out;
    }
    /** Immutable structural membership is separate from currently actionable catalog targets. */
    public function assertStructural(object $scope,User $actor): void
    {
        $fresh=$this->device($scope,$actor);$coverage=json_decode($scope->coverage??'{}',true,512,JSON_THROW_ON_ERROR);
        $base=json_decode($fresh->branches,true,512,JSON_THROW_ON_ERROR);$initial=[];
        foreach(DB::table(self::TABLE)->where('device_id',$fresh->id)->where('enrollment_id',$this->enrollment($fresh))->orderBy('grant_id')->limit(1001)->lockForUpdate()->get() as $index=>$row){
            abort_unless($index<1000,413);$descriptor=$this->read($row,$fresh,$actor);$value=json_decode(Crypt::decryptString($row->evidence_cipher),true,32,JSON_THROW_ON_ERROR);
            if(($value['provenance']['kind']??null)==='bootstrap')$initial[]=['descriptor'=>$descriptor,'creation'=>$value['creation']];
            else abort_unless(($value['provenance']['kind']??null)==='journal',503);
        }
        $structural=array_values(array_unique([...$base,...array_map(fn($item)=>$item['descriptor']['branch'],$initial)]));
        $branches=json_decode($scope->branches,true,512,JSON_THROW_ON_ERROR);
        abort_unless(($coverage['catalog_scopes']??null)===1&&($coverage['catalog_structural_branches']??null)===$structural
            &&hash_equals($coverage['catalog_grants_sha256']??'',hash('sha256',DesktopDashboardBootstrap::json($initial)))
            &&hash_equals($coverage['base_branches_sha256']??'',hash('sha256',DesktopDashboardBootstrap::json($base)))
            &&hash_equals($coverage['catalog_branches_sha256']??'',hash('sha256',DesktopDashboardBootstrap::json($branches)))
            &&is_array($branches)&&!array_diff($branches,$structural)&&!array_diff($base,$branches),409,'دليل نطاق الإدارة المحلي لا يطابق سجلات إنشاء المتاجر.');
    }
    public function restore(array $snapshot): void
    {
        abort_unless(DB::transactionLevel()>0&&($snapshot['coverage']['catalog_scopes']??null)===1&&$this->available(),503);
        $items=$snapshot['created_scopes']??null;abort_unless(is_array($items)&&array_is_list($items)&&count($items)<=1000,422);
        $actor=User::withoutGlobalScopes()->whereKey($snapshot['actor_id'])->lockForUpdate()->firstOrFail();$fresh=$this->device((object)['id'=>$snapshot['device_id']],$actor);$seen=[];
        foreach($items as $item){abort_unless(is_array($item)&&$this->keys($item,['descriptor','creation']),422);$descriptor=$item['descriptor'];$creation=$item['creation'];
            $this->validate($descriptor,$fresh,$actor);$this->validateCreation($creation,$descriptor);abort_if(isset($seen[$descriptor['grant_id']]),422);$seen[$descriptor['grant_id']]=true;
            $this->store($descriptor,$creation,['kind'=>'bootstrap','snapshot_id'=>$snapshot['snapshot_id'],'source_hash'=>hash('sha256',DesktopDashboardBootstrap::json($snapshot['source'])),'schema_hash'=>$snapshot['schema_hash']]);
        }
        abort_unless($this->effective($fresh,$actor)===$snapshot['branches']&&$this->structural($fresh,$actor)===($snapshot['coverage']['catalog_structural_branches']??null),409,'نطاق الكتالوج لا يطابق دليل إنشاء المتاجر المعتمد.');
    }
    private function store(array $descriptor,array $creation,array $provenance): void
    {
        $this->validateCreation($creation,$descriptor);$value=['descriptor'=>$descriptor,'creation'=>$creation,'provenance'=>$provenance];$hash=$this->hash($value);
        $old=DB::table(self::TABLE)->where('grant_id',$descriptor['grant_id'])->lockForUpdate()->first();
        if($old){abort_unless(hash_equals($old->descriptor_hash,$hash),409,'دليل إنشاء المتجر محفوظ ولا يمكن استبداله.');return;}
        $row=array_intersect_key($descriptor,array_flip(self::COLUMNS));$row+=['descriptor_hash'=>$hash,'evidence_cipher'=>Crypt::encryptString($this->json($value)),'created_at'=>now('UTC')];DB::table(self::TABLE)->insert($row);
    }
    private function read(object $row,object $device,User $actor): array
    {
        try{$value=json_decode(Crypt::decryptString($row->evidence_cipher),true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $e){abort(503,'دليل إنشاء المتجر غير صالح.');}
        abort_unless(is_array($value)&&hash_equals($row->descriptor_hash,$this->hash($value)),503);$descriptor=$value['descriptor']??[];
        foreach(self::COLUMNS as $column)abort_unless((string)($descriptor[$column]??'')===(string)$row->$column,503);
        $this->validate($descriptor,$device,$actor);$this->validateCreation($value['creation']??[],$descriptor);$provenance=$value['provenance']??[];
        if(($provenance['kind']??null)==='journal'){
            $entry=DB::table('desktop_dashboard_commands')->where('device_id',$device->id)->where('command_id',$descriptor['command_id'])->lockForUpdate()->first();
            abort_unless($entry&&(int)$entry->actor_id===(int)$actor->id&&$entry->route_name===self::ROUTE,503);
            $saved=json_decode(Crypt::decryptString($entry->local_result_cipher),true,512,JSON_THROW_ON_ERROR);$saved=$saved['result']??$saved;
            if(($saved['committed']??null)===true)$saved=$saved['result']??[];
            abort_unless(hash_equals($descriptor['result_sha256'],$this->hash($this->creation($device,$descriptor['command_id'],$saved,$descriptor['creator_created_at']))),503,'دليل إنشاء المتجر لا يطابق إيصال العملية الأصلي.');
        }elseif(($provenance['kind']??null)==='remote-attempt'){
            $entry=DB::table('desktop_dashboard_remote_attempts')->where('id',$provenance['attempt_id']??null)->where('device_id',$device->id)->lockForUpdate()->first();
            abort_unless($entry&&$entry->status==='committed'&&(int)$entry->actor_id===(int)$actor->id&&$entry->operation_id===$descriptor['command_id']&&$entry->method==='POST'&&$entry->path==='/admin/go-stores',503);
            $saved=json_decode(Crypt::decryptString($entry->response_cipher),true,512,JSON_THROW_ON_ERROR);
            abort_unless(($saved['go_store_create_route']??null)===self::ROUTE&&hash_equals($descriptor['result_sha256'],$this->hash($this->creation($device,$descriptor['command_id'],$saved['go_store_create_result']??[],$descriptor['creator_created_at']))),503);
        }else{
            abort_unless(($provenance['kind']??null)==='bootstrap'&&config('desktop_dashboard.local')===true,503);
            $manifest=DB::table('desktop_dashboard_source_manifest')->where('device_id',$device->id)->lockForUpdate()->first();
            $state=DB::table('desktop_dashboard_local_state')->where('device_id',$device->id)->lockForUpdate()->first();
            abort_unless($manifest&&$state&&$manifest->snapshot_id===$provenance['snapshot_id']&&$state->snapshot_id===$provenance['snapshot_id']
                &&hash_equals($manifest->sha256,$provenance['source_hash'])&&$state->schema_hash===$provenance['schema_hash'],503);
        }
        return $descriptor;
    }
    private function creation(object $device,string $command,array $result,?string $creator): array
    {
        return ['format'=>1,'device_id'=>$device->id,'actor_id'=>(int)$device->actor_id,'route_name'=>self::ROUTE,'command_id'=>$command,'creator_created_at'=>$creator,
            'created_scope'=>$result['created_scope']??null,'references'=>$result['references']??null,'after'=>$result['after']??null];
    }
    private function validateCreation(array $c,array $d): void
    {
        abort_unless(strlen($this->json($c))<=16384,413);
        abort_unless($this->keys($c,['format','device_id','actor_id','route_name','command_id','creator_created_at','created_scope','references','after'])
            &&$c['format']===1&&$c['device_id']===$d['device_id']&&$c['actor_id']===$d['actor_id']&&$c['route_name']===self::ROUTE&&$c['command_id']===$d['command_id']&&$c['creator_created_at']===$d['creator_created_at']
            &&is_array($c['created_scope'])&&$this->hash($c['created_scope'])===$this->hash(['kind'=>'gs','entity'=>'menu_store_owner','id'=>$d['entity_id'],'command_id'=>$d['command_id']])
            &&is_array($c['references'])&&count($c['references'])===3&&is_array($c['after'])&&$this->keys($c['after'],['owner','profile']),422);
        foreach(['menu_store_owner','wallet_account','dashboard_user'] as $entity)abort_unless(($c['references'][$entity]??null)===$d['entity_id'],422);
        $owner=$c['after']['owner'];$profile=$c['after']['profile'];abort_unless(is_array($owner)&&$this->keys($owner,self::OWNER)&&is_array($profile)&&$this->keys($profile,self::PROFILE),422);
        foreach([...array_values($owner),...array_values($profile)] as $value)abort_unless(is_scalar($value)||$value===null,422);
        abort_unless($owner['id']===$d['entity_id']&&$owner['added_by']===$d['actor_id']&&$owner['created_at']===$d['owner_created_at']&&$owner['account_type']==='vendor'&&$owner['app_scope']==='go_partner'
            &&$profile['user_id']===$d['entity_id']&&$profile['created_at']===$d['profile_created_at']&&hash_equals($d['result_sha256'],$this->hash($c)),422);
    }
    private function live(array $descriptor,User $actor): bool
    {
        try{$this->target($descriptor,app(DesktopDashboardGoStoreProfile::class)->authorizeActor($actor));return true;}catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error){if($error->getStatusCode()===403)return false;throw $error;}
    }
    private function validate(array $d,object $device,User $actor): void
    {
        $keys=array_keys($d);sort($keys);$expected=[...self::COLUMNS,'format','owner_created_at','creator_created_at','profile_created_at','result_sha256'];sort($expected);abort_unless($keys===$expected,422);
        Validator::make($d,['format'=>'required|in:1','grant_id'=>'required|regex:/^[a-f0-9]{64}$/D','device_id'=>'required|uuid','command_id'=>'required|uuid','enrollment_id'=>'required|regex:/^[a-f0-9]{64}$/D',
            'actor_id'=>'required|integer|min:1','entity_id'=>'required|integer|min:1','origin_entity_id'=>'required|integer|min:1','owner_created_at'=>'required|date','creator_created_at'=>'present|nullable|date','profile_created_at'=>'required|date','result_sha256'=>'required|regex:/^[a-f0-9]{64}$/D'])->validate();
        abort_unless($d['format']===1&&$d['device_id']===$device->id&&$d['actor_id']===(int)$actor->id&&$d['enrollment_id']===$this->enrollment($device)&&$d['route_name']===self::ROUTE
            &&$d['capability']==='catalog'&&$d['entity']==='menu_store_owner'&&is_int($d['entity_id'])&&is_int($d['origin_entity_id'])&&$d['branch']==='gs:'.$d['entity_id']&&hash_equals($d['grant_id'],$this->grantId($d)),403);
        $creator=DB::table('users')->where('id',$actor->id)->lockForUpdate()->first(['id','created_at','status']);
        abort_unless($creator&&$creator->created_at===$d['creator_created_at']&&!in_array($creator->status,['disabled','declined'],true),403,'هوية منشئ المتجر تغيّرت.');
    }
    private function target(array $d,User $actor): void
    {
        $owner=DB::table('users')->where('id',$d['entity_id'])->lockForUpdate()->first(['id','added_by','created_at','app_scope','account_type']);
        $profile=DB::table('go_stores')->where('user_id',$d['entity_id'])->lockForUpdate()->first(['user_id','created_at']);
        abort_unless($owner&&(int)$owner->added_by===(int)$actor->id&&$owner->created_at===$d['owner_created_at']&&$owner->app_scope==='go_partner'&&$owner->account_type==='vendor'
            &&$profile&&$profile->created_at===$d['profile_created_at'],403,'هوية حساب المتجر المنشأ تغيّرت.');
    }
    private function device(object $device,User $actor): object
    {
        abort_unless(DB::transactionLevel()>0,503);$id=$device->id??$device->device_id??null;
        $fresh=DB::table('desktop_dashboard_devices')->where('id',$id)->lockForUpdate()->first();
        abort_unless($fresh&&$fresh->enabled&&(int)$fresh->actor_id===(int)$actor->id,403);return $fresh;
    }
    private function keys(array $value,array $expected): bool {$keys=array_keys($value);sort($keys);sort($expected);return $keys===$expected;}
    private function grantId(array $d): string {return $this->hash(['catalog-created-scope:1',$d['device_id'],$d['enrollment_id'],$d['command_id'],'menu_store_owner',$d['origin_entity_id']]);}
    private function hash(array $v): string {return hash('sha256',$this->json($v));}
    private function json(array $v): string {$sort=function($v)use(&$sort){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);return array_map($sort,$v);};return json_encode($sort($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
}
