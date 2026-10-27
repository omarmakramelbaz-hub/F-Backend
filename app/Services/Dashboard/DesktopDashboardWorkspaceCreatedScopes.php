<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\{Crypt,DB,Schema};

/** Root-bound catalog receipts. They never extend a member's signed financial BASE. */
final class DesktopDashboardWorkspaceCreatedScopes extends DesktopDashboardCreatedScopes
{
    private const ERROR='دليل إنشاء متجر مساحة العمل غير مكتمل؛ بيانات الجهاز محفوظة.';

    public function active(): bool
    {
        if(config('desktop_dashboard.local')!==true||getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL')!=='true'
            ||!Schema::hasTable('desktop_dashboard_local_state'))return false;
        $coverage=DB::table('desktop_dashboard_local_state')->where('device_id',config('desktop_dashboard.device_id'))->value('coverage');
        return (json_decode($coverage??'{}',true)['catalog_scopes']??null)===1;
    }
    public function enrollment(object $device): string
    {
        abort_unless($device instanceof DesktopDashboardWorkspaceScope,403);$device->assertIssued();return $device->rootFingerprint();
    }

    /** No scope issuance or local-authority call here: member validation invokes this method. */
    public function normalizeFreshBranches(array $captured,array $fresh,string $workspace,string $root): array
    {
        $additional=array_values(array_diff($fresh,$captured['branches']??[]));
        if(!$additional||!$this->available())return $fresh;
        $proven=[];
        foreach($this->query($workspace,$root)->whereIn('branch',$additional)->limit(1001)->get() as $index=>$row){
            abort_unless($index<1000,413);$item=$this->readItem($row,$workspace,$root);
            // Imported server targets already participate in signed BASE; only
            // an original local journal acceptance can explain a new local row.
            if($item['provenance']['kind']==='journal'){
                $this->target($item['descriptor']);$proven[]=$item['descriptor']['branch'];
            }
        }
        return array_values(array_filter($fresh,fn($branch)=>!in_array($branch,$proven,true)));
    }
    public function structural(object $device,User $actor): array
    {
        $scope=$this->scope($device,$actor);$base=$scope->branchGrants();
        // Settings/account administration has its own complete permission gate;
        // revoking catalog edits must not erase immutable structural evidence.
        if($actor->account_type!=='admin'||!empty($actor->owner_resturant_id))return $base;
        foreach($this->items($scope->id,$scope->rootFingerprint()) as $item)$base[]=$item['descriptor']['branch'];
        return array_values(array_unique($base));
    }
    public function effective(object $device,User $actor): array
    {
        $scope=$this->scope($device,$actor);$base=$scope->branchGrants();
        if(!$this->canCatalog($scope,$actor,false))return $base;
        foreach($this->items($scope->id,$scope->rootFingerprint()) as $item){
            try{$this->target($item['descriptor']);$base[]=$item['descriptor']['branch'];}
            catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error){if($error->getStatusCode()!==403)throw $error;}
        }
        return array_values(array_unique($base));
    }
    /** Catalog list/read authority is independent of profile-edit or financial grants. */
    public function readable(DesktopDashboardWorkspaceScope $scope,User $actor): array
    {
        $scope=$this->scope($scope,$actor);$base=$scope->branchGrants();
        if(!$this->catalogAllowed($scope,$actor,['resturant-list'],false))return $base;
        foreach($this->items($scope->id,$scope->rootFingerprint()) as $item){
            try{$this->target($item['descriptor']);$base[]=$item['descriptor']['branch'];}
            catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error){if($error->getStatusCode()!==403)throw $error;}
        }
        return array_values(array_unique($base));
    }
    public function catalog(object $device,string $branch,User $actor): void
    {
        $scope=$this->scope($device,$actor);$this->canCatalog($scope,$actor,true);
        abort_unless((bool)preg_match('/^gs:[1-9][0-9]{0,18}$/D',$branch),403);
        if(in_array($branch,$scope->branchGrants(),true))return;
        $row=$this->query($scope->id,$scope->rootFingerprint())->where('branch',$branch)->first();abort_unless($row,403);
        $item=$this->readItem($row,$scope->id,$scope->rootFingerprint());$this->target($item['descriptor']);
    }
    public function assertStructural(object $device,User $actor): void
    {
        $scope=$this->scope($device,$actor);$state=$scope->localState();abort_unless($state,403);
        $coverage=json_decode($state->coverage,true,512,JSON_THROW_ON_ERROR);
        abort_unless(($coverage['catalog_scopes']??null)===1,503,self::ERROR);
        // Imported catalog receipts are authenticated by their exact snapshot,
        // while later journal receipts remain append-only outside this hash.
        $initial=[];
        foreach($this->items($scope->id,$scope->rootFingerprint()) as $item)
            if($item['provenance']['kind']==='bootstrap')$initial[]=$this->publicItem($item);
        $base=json_decode($state->branches,true,512,JSON_THROW_ON_ERROR);abort_unless(is_array($base),503);
        foreach($this->coverage($base,$initial) as $key=>$expected)
            abort_unless(($coverage[$key]??null)===$expected,409,self::ERROR);
    }
    public function captureLocal(string $device,string $command,int $actor,string $route,array $payload,array $result,array $references): void
    {
        if($route!==self::ROUTE||isset($payload['envelope']))return;
        abort_unless($this->active(),503,self::ERROR);
        $scope=DesktopDashboardWorkspaceExecutionContext::current();
        abort_unless($scope&&$scope->isLocal()&&$scope->id===$device&&$scope->actor_id===$actor,403);
        $this->mint($scope,$command,$payload,$result,$references,(int)($result['created_scope']['id']??0),['kind'=>'journal']);
    }
    public function captureCentral(object $device,array $envelope,array $payload,array $result,array $references): void
    {
        if(($envelope['route_name']??null)!==self::ROUTE)return;
        $scope=$this->scope($device,$device->actor());$origin=$envelope['local_result']['created_scope']??null;
        abort_unless(is_array($origin)&&($origin['kind']??null)==='gs'&&($origin['entity']??null)==='menu_store_owner'
            &&($origin['command_id']??null)===$envelope['command_id']&&is_int($origin['id']??null)&&$origin['id']>0,409);
        $keys=array_keys($envelope['local_references']??[]);sort($keys);abort_unless($keys===['dashboard_user','menu_store_owner','wallet_account'],409);
        foreach($keys as $entity)abort_unless($envelope['local_references'][$entity]===$origin['id'],409);
        $this->mint($scope,$envelope['command_id'],$payload,$result,$references,$origin['id'],['kind'=>'journal']);
    }
    public function captureNative(object $device,string $attempt,string $command,array $payload,array $result): void
    {
        $scope=$this->scope($device,$device->actor());
        $this->mint($scope,$command,$payload,$result,$result['references']??[],(int)($result['created_scope']['id']??0),
            ['kind'=>'remote-attempt','attempt_id'=>$attempt]);
    }
    public function export(object $device,User $actor): array
    {
        $scope=$this->scope($device,$actor);return array_map(fn($item)=>$this->publicItem($item),$this->items($scope->id,$scope->rootFingerprint()));
    }
    public function exportAudience(DesktopDashboardWorkspaceSnapshotAudience $audience): array
    {
        $audience->assertIssued();$root=$audience->root();$manifest=$audience->manifest();
        abort_unless(DB::transactionLevel()>0,503);
        return array_map(fn($item)=>$this->publicItem($item),$this->items($root->id,$manifest['root_fingerprint']));
    }
    public function coverage(array $base,array $items): array
    {
        $structural=$base;foreach($items as $item)$structural[]=$item['descriptor']['branch'];
        $structural=array_values(array_unique($structural));sort($structural,SORT_STRING);
        return ['catalog_scopes'=>1,'catalog_grants_sha256'=>$this->publicItemsDigest($items)['sha256'],
            'catalog_structural_branches'=>$structural,'base_branches_sha256'=>hash('sha256',DesktopDashboardBootstrap::json($base)),
            'catalog_branches_sha256'=>hash('sha256',DesktopDashboardBootstrap::json($base))];
    }
    /** Public grammar only. It neither authenticates a source nor issues local authority. */
    public function validateSnapshotCatalog(array $snapshot,array $manifest): void
    {
        abort_unless(($snapshot['format']??null)===2&&is_string($manifest['workspace_id']??null)
            &&is_string($manifest['root_fingerprint']??null)&&($snapshot['device_id']??null)===$manifest['workspace_id']
            &&is_array($snapshot['branches']??null)&&array_is_list($snapshot['branches'])
            &&($snapshot['enrollment']['base_branches']??null)===$snapshot['branches']
            &&is_array($snapshot['coverage']??null)&&is_array($snapshot['created_scopes']??null)
            &&array_is_list($snapshot['created_scopes'])&&count($snapshot['created_scopes'])<=1000,422);
        $previous=null;
        foreach($snapshot['created_scopes'] as $item){
            abort_unless(is_array($item)&&$this->exact($item,['descriptor','creation'])&&is_array($item['creation']??null),422);
            $this->validateDescriptor($item['descriptor'],$manifest['workspace_id'],$manifest['root_fingerprint']);
            $this->validateCreation($item['creation'],$item['descriptor']);$id=$item['descriptor']['grant_id'];
            abort_unless($previous===null||strcmp($previous,$id)<0,422,'ترتيب أدلة إنشاء المتاجر غير صالح أو مكرر.');$previous=$id;
        }
        foreach($this->coverage($snapshot['branches'],$snapshot['created_scopes']) as $key=>$expected)
            abort_unless(($snapshot['coverage'][$key]??null)===$expected,422,'بيانات نطاق كتالوج مساحة العمل غير متطابقة.');
    }
    /** Exact public wire-array bytes, shared by server coverage and private source capture. */
    public function publicItemsDigest(array $items): array
    {
        abort_unless(array_is_list($items)&&count($items)<=1000,413);
        return ['count'=>count($items),'sha256'=>hash('sha256',DesktopDashboardBootstrap::json($items))];
    }

    /**
     * Private capture adapter. The caller has verified the source local MAC and
     * reads these rows and their proof dependencies from one RR PDO snapshot.
     * Its lookup is internal, never supplied by an HTTP/renderer request.
     */
    public function capturePublicItems(array $rows,string $workspace,string $root,callable $lookup): array
    {
        DesktopDashboardWorkspaceLocalSchema::assertNativeCli();
        abort_unless(array_is_list($rows)&&count($rows)<=1000,413);$items=[];$previous=null;
        foreach($rows as $row){
            abort_unless(is_array($row)&&is_string($row['grant_id']??null)
                &&($previous===null||strcmp($previous,$row['grant_id'])<0),422);$previous=$row['grant_id'];
            $items[]=$this->publicItem($this->readItemWithLookup((object)$row,$workspace,$root,$lookup));
        }
        return $items;
    }

    /** The importer verifies local authority first; this independently checks retained catalog evidence. */
    public function verifyWorkspaceCatalog(array $receipt,string $root): void
    {
        DesktopDashboardWorkspaceLocalSchema::assertNativeCli();
        abort_unless(is_string($receipt['device_id']??null)&&is_array($receipt['branches']??null)&&is_array($receipt['coverage']??null),422);
        $items=[];foreach($this->items($receipt['device_id'],$root) as $item)
            if($item['provenance']['kind']==='bootstrap')$items[]=$this->publicItem($item);
        foreach($this->coverage($receipt['branches'],$items) as $key=>$expected)
            abort_unless(($receipt['coverage'][$key]??null)===$expected,409,self::ERROR);
    }

    /** Public source receipts may be rebound only by the staging import's private provenance. */
    public function restoreWorkspace(array $snapshot,DesktopDashboardWorkspaceImportProvenance $verified): void
    {
        DesktopDashboardWorkspaceLocalSchema::assertNativeCli();$verified->assertVerified();$context=$verified->context();
        $manifest=$context['manifest'];$root=$manifest['root_fingerprint'];$workspace=$manifest['workspace_id'];
        abort_unless(DB::transactionLevel()>0&&($snapshot['device_id']??null)===$workspace
            &&($snapshot['snapshot_id']??null)===($context['snapshot']['snapshot_id']??null)
            &&($snapshot['schema_hash']??null)===($context['snapshot']['schema_hash']??null)
            &&DesktopDashboardWorkspaceAuthority::canonical($snapshot['source']??null)
                ===DesktopDashboardWorkspaceAuthority::canonical($context['snapshot']['source']??null)
            &&DesktopDashboardWorkspaceAuthority::canonical($snapshot['coverage']??null)
                ===DesktopDashboardWorkspaceAuthority::canonical($context['snapshot']['coverage']??null)
            &&($snapshot['coverage']['catalog_scopes']??null)===1&&$this->available(),422);
        $this->validateSnapshotCatalog($snapshot,$manifest);
        $items=$snapshot['created_scopes']??null;abort_unless(is_array($items)&&array_is_list($items)&&count($items)<=1000,422);$seen=[];
        foreach($items as $item){
            abort_unless(is_array($item)&&$this->exact($item,['descriptor','creation']),422);
            $this->validateDescriptor($item['descriptor'],$workspace,$root);$this->validateCreation($item['creation'],$item['descriptor']);
            abort_if(isset($seen[$item['descriptor']['grant_id']]),422);$seen[$item['descriptor']['grant_id']]=true;
            $this->storeItem($item['descriptor'],$item['creation'],['kind'=>'bootstrap','snapshot_id'=>$snapshot['snapshot_id'],
                'source_hash'=>hash('sha256',DesktopDashboardBootstrap::json($snapshot['source'])),'schema_hash'=>$snapshot['schema_hash']]);
        }
        abort_unless(hash_equals($snapshot['coverage']['catalog_grants_sha256']??'',hash('sha256',DesktopDashboardBootstrap::json($items))),422);
    }

    private function mint(DesktopDashboardWorkspaceScope $scope,string $command,array $payload,array $result,array $references,int $origin,array $provenance): void
    {
        abort_unless(DB::transactionLevel()>0&&$this->available()&&!DesktopDashboardWorkspaceExecutionContext::isReadOnly(),503);
        $actor=$scope->actor();$this->canCreate($scope,$actor);
        app(DesktopDashboardCreatedScopeAuthority::class)->validateCreated(self::ROUTE,$result,$payload,$actor);
        $id=$result['created_scope']['id']??null;
        abort_unless(is_int($id)&&$id>0&&$origin>0&&$command===($result['created_scope']['command_id']??null),409);
        $expected=['dashboard_user','menu_store_owner','wallet_account'];$keys=array_keys($references);sort($keys);
        abort_unless($keys===$expected,409);foreach($expected as $entity)abort_unless($references[$entity]===$id,409);
        $creator=$this->locking(DB::table('users')->where('id',$actor->id))->first(['created_at']);
        $profile=$this->locking(DB::table('go_stores')->where('user_id',$id))->first(['created_at']);abort_unless($creator&&$profile,409);
        $creation=$this->creationReceipt($scope->id,$scope->actor_id,$command,$result,$creator->created_at);
        if($provenance['kind']==='journal'){
            $row=$this->locking(DB::table(DesktopDashboardWorkspaceCommandAuthorities::TABLE)->where('workspace_id',$scope->id)->where('command_id',$command))->first();abort_unless($row,503);
            $proof=app(DesktopDashboardWorkspaceCommandAuthorities::class)->assertHistoryRow((array)$row,$scope->id,$scope->rootFingerprint());
        }else{
            $member=$scope->acceptedMember()??$scope->member();
            $proof=['format'=>2,'workspace_id'=>$scope->id,'root_fingerprint'=>$scope->rootFingerprint(),'actor_id'=>$scope->actor_id,
                'manifest_revision'=>$scope->acceptedManifestRevision()??$scope->manifestRevision(),'grant'=>$member['grant'],
                'command_sha256'=>app(DesktopDashboardJournal::class)->fingerprint([$scope->actor_id,self::ROUTE,$payload,[]])];
        }
        $descriptor=['format'=>2,'device_id'=>$scope->id,'enrollment_id'=>$scope->rootFingerprint(),'command_id'=>$command,
            'actor_id'=>$scope->actor_id,'route_name'=>self::ROUTE,'capability'=>'catalog','entity'=>'menu_store_owner',
            'entity_id'=>$id,'origin_entity_id'=>$origin,'branch'=>'gs:'.$id,'owner_created_at'=>$result['after']['owner']['created_at'],
            'creator_created_at'=>$creator->created_at,'profile_created_at'=>$profile->created_at,
            'result_sha256'=>$this->hash($creation),'workspace_authority'=>$proof];
        $descriptor['grant_id']=$this->grant($descriptor);$this->storeItem($descriptor,$creation,$provenance);
        $row=$this->query($scope->id,$scope->rootFingerprint())->where('grant_id',$descriptor['grant_id'])->first();$this->readItem($row,$scope->id,$scope->rootFingerprint());
    }
    private function storeItem(array $descriptor,array $creation,array $provenance): void
    {
        $this->validateCreation($creation,$descriptor);$value=['descriptor'=>$descriptor,'creation'=>$creation,'provenance'=>$provenance];$hash=$this->hash($value);
        $old=$this->locking(DB::table(self::TABLE)->where('grant_id',$descriptor['grant_id']))->first();
        if($old){abort_unless(hash_equals($old->descriptor_hash,$hash),409,self::ERROR);return;}
        $row=array_intersect_key($descriptor,array_flip(self::COLUMNS));
        DB::table(self::TABLE)->insert($row+['descriptor_hash'=>$hash,'evidence_cipher'=>Crypt::encryptString($this->json($value)),'created_at'=>now('UTC')]);
    }
    private function items(string $workspace,string $root): array
    {
        if(!$this->available())return [];$items=[];
        foreach($this->query($workspace,$root)->limit(1001)->get() as $index=>$row){abort_unless($index<1000,413);$items[]=$this->readItem($row,$workspace,$root);}
        return $items;
    }
    private function readItem(object $row,string $workspace,string $root): array
    {
        return $this->readItemWithLookup($row,$workspace,$root,function(string $table,array $where): ?object {
            return $this->locking(DB::table($table)->where($where))->first();
        });
    }
    private function readItemWithLookup(object $row,string $workspace,string $root,callable $lookup): array
    {
        abort_unless(is_string($row->evidence_cipher??null)&&strlen($row->evidence_cipher)<=65536,503,self::ERROR);
        try{$value=json_decode(Crypt::decryptString($row->evidence_cipher),true,64,JSON_THROW_ON_ERROR);}
        catch(\Throwable $error){abort(503,self::ERROR);}
        abort_unless(is_array($value)&&$this->exact($value,['descriptor','creation','provenance'])
            &&hash_equals($row->descriptor_hash,$this->hash($value)),503,self::ERROR);
        $descriptor=$value['descriptor'];$this->validateDescriptor($descriptor,$workspace,$root);$this->validateCreation($value['creation'],$descriptor);
        foreach(self::COLUMNS as $column)abort_unless((string)$row->$column===(string)$descriptor[$column],503,self::ERROR);
        $kind=$value['provenance']['kind']??null;
        if($kind==='journal'){
            $entry=$lookup('desktop_dashboard_commands',['device_id'=>$workspace,'command_id'=>$descriptor['command_id']]);
            $authority=$lookup(DesktopDashboardWorkspaceCommandAuthorities::TABLE,['workspace_id'=>$workspace,'command_id'=>$descriptor['command_id']]);
            abort_unless($entry&&$authority&&(int)$entry->actor_id===$descriptor['actor_id']&&$entry->route_name===self::ROUTE
                &&hash_equals($entry->request_hash,$authority->request_hash),503,self::ERROR);
            $proof=app(DesktopDashboardWorkspaceCommandAuthorities::class)->assertHistoryRow((array)$authority,$workspace,$root);
            abort_unless(DesktopDashboardWorkspaceAuthority::canonical($proof)===DesktopDashboardWorkspaceAuthority::canonical($descriptor['workspace_authority']),503,self::ERROR);
            try{$saved=json_decode(Crypt::decryptString($entry->local_result_cipher),true,512,JSON_THROW_ON_ERROR);}
            catch(\Throwable $error){abort(503,self::ERROR);}
            $saved=$saved['result']??$saved;if(($saved['committed']??null)===true)$saved=$saved['result']??[];
            abort_unless(hash_equals($descriptor['result_sha256'],$this->hash($this->creationReceipt($workspace,$descriptor['actor_id'],
                $descriptor['command_id'],$saved,$descriptor['creator_created_at']))),503,self::ERROR);
        }elseif($kind==='remote-attempt'){
            $entry=$lookup('desktop_dashboard_remote_attempts',['device_id'=>$workspace,'id'=>$value['provenance']['attempt_id']??null]);
            abort_unless($entry&&$entry->status==='committed'&&(int)$entry->actor_id===$descriptor['actor_id']
                &&$entry->operation_id===$descriptor['command_id']&&$entry->method==='POST'&&$entry->path==='/admin/go-stores',503,self::ERROR);
            try{$saved=json_decode(Crypt::decryptString($entry->response_cipher),true,512,JSON_THROW_ON_ERROR);}
            catch(\Throwable $error){abort(503,self::ERROR);}
            abort_unless(($saved['enrollment_binding']['fingerprint']??null)===$root&&($saved['go_store_create_route']??null)===self::ROUTE
                &&hash_equals($descriptor['result_sha256'],$this->hash($this->creationReceipt($workspace,$descriptor['actor_id'],
                    $descriptor['command_id'],$saved['go_store_create_result']??[],$descriptor['creator_created_at']))),503,self::ERROR);
        }else{
            abort_unless($kind==='bootstrap'&&config('desktop_dashboard.local')===true,503,self::ERROR);
            $source=$lookup('desktop_dashboard_source_manifest',['device_id'=>$workspace]);
            $state=$lookup('desktop_dashboard_local_state',['device_id'=>$workspace]);
            abort_unless($source&&$state&&$source->snapshot_id===$value['provenance']['snapshot_id']&&$state->snapshot_id===$value['provenance']['snapshot_id']
                &&hash_equals($source->sha256,$value['provenance']['source_hash'])&&$state->schema_hash===$value['provenance']['schema_hash'],503,self::ERROR);
        }
        return $value;
    }
    private function validateDescriptor($descriptor,string $workspace,string $root): void
    {
        abort_unless(is_array($descriptor)&&$this->exact($descriptor,[...self::COLUMNS,'format','owner_created_at','creator_created_at',
            'profile_created_at','result_sha256','workspace_authority'])&&$descriptor['format']===2
            &&$descriptor['device_id']===$workspace&&$descriptor['enrollment_id']===$root&&$descriptor['route_name']===self::ROUTE
            &&$descriptor['capability']==='catalog'&&$descriptor['entity']==='menu_store_owner'
            &&is_string($descriptor['command_id'])&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$descriptor['command_id'])
            &&is_string($descriptor['result_sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$descriptor['result_sha256'])
            &&is_int($descriptor['entity_id'])&&$descriptor['entity_id']>0&&is_int($descriptor['origin_entity_id'])&&$descriptor['origin_entity_id']>0
            &&is_int($descriptor['actor_id'])&&$descriptor['actor_id']>0&&$descriptor['branch']==='gs:'.$descriptor['entity_id']
            &&is_string($descriptor['grant_id'])&&hash_equals($descriptor['grant_id'],$this->grant($descriptor)),422,self::ERROR);
        $proof=app(DesktopDashboardWorkspaceCommandAuthorities::class)->proof($descriptor['workspace_authority'],$workspace,$root);
        abort_unless($proof['actor_id']===$descriptor['actor_id'],403,self::ERROR);
        foreach(['owner_created_at','creator_created_at','profile_created_at'] as $key)
            abort_unless(($key==='creator_created_at'&&$descriptor[$key]===null)||(is_string($descriptor[$key])
                &&(bool)preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/D',$descriptor[$key])),422);
    }
    private function scope(object $device,User $actor): DesktopDashboardWorkspaceScope
    {
        $scope=$device instanceof DesktopDashboardWorkspaceScope?$device:DesktopDashboardWorkspaceExecutionContext::current();
        abort_unless($scope&&$scope->actor_id===(int)$actor->id&&($device->id??$device->device_id)===$scope->id,403);
        $scope->assertIssued();return $scope;
    }
    private function canCatalog(DesktopDashboardWorkspaceScope $scope,User $actor,bool $enforce): bool
    {
        return $this->catalogAllowed($scope,$actor,['resturant-list','resturant-edit'],$enforce);
    }
    private function catalogAllowed(DesktopDashboardWorkspaceScope $scope,User $actor,array $required,bool $enforce): bool
    {
        $check=function()use($scope,$actor,$required,$enforce){
            $fresh=$this->locking(User::withoutGlobalScopes()->whereKey($actor->id))->first();
            $facts=(new DesktopDashboardWorkspaceAuthority)->persistedMemberFacts((int)$actor->id,!DesktopDashboardWorkspaceExecutionContext::isReadOnly());
            $allowed=$fresh&&$fresh->account_type==='admin'&&empty($fresh->owner_resturant_id)
                &&!in_array($fresh->status,['disabled','declined'],true)
                &&((int)$actor->id===1||(!array_diff($required,$scope->permissionFacts()['names'])
                    &&!array_diff($required,$facts['permissions']['names'])));
            if($enforce)abort_unless($allowed,403);return $allowed;
        };
        // A direct scope read has the same fresh authority boundary as a page
        // request. Callers do not need to open an undocumented transaction.
        return DB::transactionLevel()>0?$check():DB::transaction($check);
    }
    private function canCreate(DesktopDashboardWorkspaceScope $scope,User $actor): void
    {
        $fresh=$this->locking(User::withoutGlobalScopes()->whereKey($actor->id))->first();
        abort_unless($fresh&&$fresh->account_type==='admin'&&empty($fresh->owner_resturant_id)
            &&!in_array($fresh->status,['disabled','declined'],true)
            &&((int)$actor->id===1||!array_diff(['resturant-list','resturant-create'],$scope->permissionFacts()['names'])),403);
    }
    private function target(array $descriptor): void
    {
        $owner=$this->locking(DB::table('users')->where('id',$descriptor['entity_id']))->first(['added_by','created_at','account_type','app_scope','pending_vendor_id']);
        $profile=$this->locking(DB::table('go_stores')->where('user_id',$descriptor['entity_id']))->first(['created_at']);
        $ownerKind=$owner&&$owner->account_type==='vendor';
        if($owner&&$owner->account_type==='delegate'&&$owner->pending_vendor_id&&Schema::hasTable('pending_vendors'))
            $ownerKind=$this->locking(DB::table('pending_vendors')->where('id',$owner->pending_vendor_id))->value('profession_key')==='store_owner';
        abort_unless($owner&&$profile&&(int)$owner->added_by===$descriptor['actor_id']&&$owner->created_at===$descriptor['owner_created_at']
            &&$ownerKind&&$owner->app_scope==='go_partner'&&$profile->created_at===$descriptor['profile_created_at'],403,self::ERROR);
    }
    private function creationReceipt(string $workspace,int $actor,string $command,array $result,?string $creator): array
    {
        return ['format'=>1,'device_id'=>$workspace,'actor_id'=>$actor,'route_name'=>self::ROUTE,'command_id'=>$command,
            'creator_created_at'=>$creator,'created_scope'=>$result['created_scope']??null,'references'=>$result['references']??null,'after'=>$result['after']??null];
    }
    private function grant(array $descriptor): string
    {
        return $this->hash(['workspace-catalog-created-scope:2',$descriptor['device_id'],$descriptor['enrollment_id'],
            $descriptor['command_id'],$descriptor['actor_id'],'menu_store_owner',$descriptor['origin_entity_id']]);
    }
    private function publicItem(array $value): array {return ['descriptor'=>$value['descriptor'],'creation'=>$value['creation']];}
    private function query(string $workspace,string $root)
    {
        return $this->locking(DB::table(self::TABLE)->where('device_id',$workspace)->where('enrollment_id',$root)->orderBy('grant_id'));
    }
    private function locking($query) {return DesktopDashboardWorkspaceExecutionContext::isReadOnly()?$query:$query->lockForUpdate();}
    private function exact(array $value,array $keys): bool {$actual=array_keys($value);sort($actual);sort($keys);return $actual===$keys;}
}
