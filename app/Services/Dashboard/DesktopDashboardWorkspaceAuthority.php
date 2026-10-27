<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\{DB, Schema, Validator};

/** Server-issued protocol-2 membership; the initiating actor is custody/audit only. */
class DesktopDashboardWorkspaceAuthority
{
    public const ROLES = ['admin','vendor','resturant_owner'];
    public const MAX_MEMBERS = 4096;
    public const MAX_MANIFEST_BYTES = 16 * 1024 * 1024;
    private const SAFE = 9007199254740991;
    private object $issuanceCapability;
    private array $columns = [];

    public function __construct() { $this->issuanceCapability = new \stdClass; }

    public function ready(): void
    {
        abort_unless(config('desktop_dashboard.enabled') === true && config('desktop_dashboard.workspace_enabled') === true,
            503, 'التجهيز المشترك للجهاز لم يُفعّل بعد.');
        foreach (['desktop_dashboard_workspaces','desktop_dashboard_workspace_members','desktop_dashboard_workspace_manifests'] as $table)
            abort_unless(Schema::hasTable($table),503,'مخطط التجهيز المشترك غير مكتمل.');
        abort_unless($this->has('users',['id','account_type','status','password','email','mobile','owner_resturant_id']),503);
    }

    /** Native preparation transport must seal this response; it is not a public status payload. */
    public function enroll(array $values, $authenticatedActor): array
    {
        $this->ready();
        Validator::make($values, ['device_id'=>'required|uuid','name'=>'required|string|max:100',
            'nonce'=>'required|string|size:64|regex:/^[a-f0-9]+$/D'])->after(function($validator) use ($values) {
                if (array_diff(array_keys($values),['device_id','name','nonce'])) $validator->errors()->add('workspace','Unsupported enrollment authority.');
            })->validate();
        $id=strtolower($values['device_id']);
        return $this->transaction(function() use ($id,$values,$authenticatedActor) {
            $root=DB::table('desktop_dashboard_workspaces')->where('id',$id)->lockForUpdate()->first();
            // All paths take the physical lock first. Manifest then locks the roster in ID order.
            $actor=$this->freshActor($authenticatedActor,false);
            if ($root) {
                abort_unless($root->enabled && hash_equals($root->enrollment_hash,hash('sha256',$values['nonce'])),409,
                    'التجهيز المشترك مسجل بالفعل أو تم إيقافه.');
                $custodian=$this->safeInteger($root->custodian_actor_id);
            } else {
                $custodian=(int)$actor->id;
                $token=$this->token($id,$custodian,$values['nonce']); $now=now('UTC');
                DB::table('desktop_dashboard_workspaces')->insert(['id'=>$id,'custodian_actor_id'=>$custodian,
                    'name'=>$values['name'],'enrollment_hash'=>hash('sha256',$values['nonce']),
                    'token_hash'=>hash('sha256',$token),'enabled'=>true,'manifest_revision'=>0,
                    'enrolled_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
                $root=DB::table('desktop_dashboard_workspaces')->where('id',$id)->lockForUpdate()->first();
            }
            $token=$this->token($id,$custodian,$values['nonce']);
            abort_unless(hash_equals($root->token_hash,hash('sha256',$token)),409,'هوية التجهيز المشترك تغيرت.');
            $manifest=$this->manifest($root);
            abort_unless(in_array((int)$actor->id,array_column($manifest['members'],'actor_id'),true),403,
                'الحساب لم يعد متاحًا لتجهيز الجهاز.');
            return ['protocol'=>2,'workspace_id'=>$id,'token'=>$token,
                'root_fingerprint'=>$this->rootFingerprint($root),'manifest'=>$manifest];
        });
    }

    public function authenticate(string $token): object
    {
        $this->ready(); abort_unless(preg_match('/^[a-f0-9]{64}$/D',$token),401);
        $root=DB::table('desktop_dashboard_workspaces')->where('token_hash',hash('sha256',$token))->where('enabled',true)->first();
        abort_unless($root,401,'ربط مساحة عمل الجهاز متوقف.'); $this->rootFingerprint($root); return $root;
    }

    /** Reuses a caller's snapshot transaction, so roster and exported rows share one consistent read. */
    public function lockAuthenticated(object $root): object
    {
        $this->ready(); abort_unless(DB::transactionLevel()>0,503);
        $fingerprint=$this->rootFingerprint($root);
        $fresh=DB::table('desktop_dashboard_workspaces')->where('id',$root->id)->lockForUpdate()->first();
        abort_unless($fresh && $fresh->enabled && hash_equals($fingerprint,$this->rootFingerprint($fresh)),401,
            'ربط مساحة عمل الجهاز تغير؛ العمليات المحفوظة لم تُحذف.');
        return $fresh;
    }

    public function manifest(object $authenticatedRoot): array
    {
        $this->ready();
        return $this->transaction(function() use ($authenticatedRoot) {
            $root=$this->lockAuthenticated($authenticatedRoot); $rawMembers=$this->eligibleMembers();
            $fingerprint=hash('sha256',self::canonical($rawMembers));
            $oldRevision=$this->safeInteger($root->manifest_revision,true);
            if ($oldRevision>0 && is_string($root->membership_fingerprint)
                && hash_equals($root->membership_fingerprint,$fingerprint))
                return $this->checkedStoredManifest($root);
            abort_unless($oldRevision<self::SAFE,409,'نسخة عضوية الجهاز تحتاج مراجعة.');
            $revision=$oldRevision+1; $rootFingerprint=$this->rootFingerprint($root); $members=[];
            foreach ($rawMembers as $member) {
                $member['grant']=$this->sign('member', ['workspace_id'=>$root->id,'root_fingerprint'=>$rootFingerprint,
                    'revision'=>$revision,'member'=>$member]); $members[]=$member;
            }
            $manifest=['format'=>2,'kind'=>'desktop-dashboard-workspace-members','workspace_id'=>$root->id,
                'revision'=>$revision,'root_fingerprint'=>$rootFingerprint,'members'=>$members,
                'roster_sha256'=>hash('sha256',self::canonical($members))];
            $manifest['signature']=$this->sign('manifest',$manifest); $json=self::canonical($manifest);
            abort_if(strlen($json)>self::MAX_MANIFEST_BYTES,413,'سجل حسابات الجهاز يحتاج تجهيزًا على دفعات.');
            $now=now('UTC'); $ids=[];
            foreach ($members as $member) {
                $ids[]=$member['actor_id']; $key=['workspace_id'=>$root->id,'actor_id'=>$member['actor_id']];
                $row=DB::table('desktop_dashboard_workspace_members')->where($key)->lockForUpdate()->first();
                $attributes=['enabled'=>true,'manifest_revision'=>$revision,'grant_hash'=>hash('sha256',$member['grant']),
                    'member_json'=>self::canonical($member),'updated_at'=>$now];
                if ($row) DB::table('desktop_dashboard_workspace_members')->where($key)->update($attributes);
                else DB::table('desktop_dashboard_workspace_members')->insert($key+$attributes+['created_at'=>$now]);
            }
            $removed=DB::table('desktop_dashboard_workspace_members')->where('workspace_id',$root->id)->where('enabled',true);
            if ($ids) $removed->whereNotIn('actor_id',$ids);
            $removed->update(['enabled'=>false,'updated_at'=>$now]);
            DB::table('desktop_dashboard_workspace_manifests')->insert(['workspace_id'=>$root->id,'revision'=>$revision,
                'roster_sha256'=>$manifest['roster_sha256'],'signature'=>$manifest['signature'],
                'manifest_json'=>$json,'created_at'=>$now,'updated_at'=>$now]);
            DB::table('desktop_dashboard_workspaces')->where('id',$root->id)->update(['manifest_revision'=>$revision,
                'membership_fingerprint'=>$fingerprint,'manifest_json'=>$json,'updated_at'=>$now]);
            return $manifest;
        });
    }

    /** Fresh server facts, never caller-supplied roles, branch IDs or cached Spatie authority. */
    public function eligibleMembers(): array
    {
        $this->ready(); abort_unless(DB::transactionLevel()>0,503);
        $actors=User::withoutGlobalScopes()->whereIn('account_type',self::ROLES)
            ->where(fn($q)=>$q->whereNull('status')->orWhereNotIn('status',['disabled','declined']))
            ->orderBy('id')->lockForUpdate()->get();
        $facts=$this->permissionFacts(); $members=[];
        foreach ($actors as $actor) {
            if (($member=$this->memberFacts($actor,$facts))===null) continue;
            $members[]=$member;
            abort_if(count($members)>self::MAX_MEMBERS,413,'عدد حسابات الجهاز يحتاج تجهيزًا على دفعات.');
        }
        return $members;
    }

    public function validateMember(object $root, int $actorId, string $grant, int $revision): array
    {
        abort_unless($actorId>0 && $actorId<=self::SAFE && $revision>0 && $revision<=self::SAFE
            && preg_match('/^[a-f0-9]{64}$/D',$grant),403);
        return $this->transaction(function() use ($root,$actorId,$grant,$revision) {
            [$manifest,$member]=$this->currentMember($root,$actorId);
            abort_unless($manifest['revision']===$revision,409,'تغيّرت حسابات الجهاز؛ يلزم تحديث سجل الصلاحيات.');
            abort_unless(hash_equals($member['grant'],$grant),403,'دليل صلاحيات الحساب غير صحيح.'); return $member;
        });
    }

    public function scope(object $root, int $actorId, string $grant, int $revision): DesktopDashboardWorkspaceScope
    {
        return $this->transaction(function() use ($root,$actorId,$grant,$revision) {
            $fresh=$this->lockAuthenticated($root); $member=$this->validateMember($fresh,$actorId,$grant,$revision);
            $actor=$this->freshActor((object)['id'=>$actorId]);
            return DesktopDashboardWorkspaceScope::issue($this,$this->issuanceCapability,$fresh,$actor,$member,
                $this->rootFingerprint($fresh),$revision);
        });
    }
    public function assertScope(DesktopDashboardWorkspaceScope $scope): void
    {
        $scope->assertIssued(); $member=$scope->member();
        $fresh=$this->validateMember($scope->root(),$member['actor_id'],$member['grant'],$scope->manifestRevision());
        abort_unless(self::canonical($fresh)===self::canonical($member),403);
        if (($accepted=$scope->acceptedMember())!==null) {
            $old=$this->validateHistoricalMember($scope->root(),$member['actor_id'],$accepted['grant'],
                $scope->acceptedManifestRevision());
            abort_unless(self::canonical($old)===self::canonical($accepted) && $old['role']===$fresh['role'],403);
        }
    }

    /** Historical proof is audit evidence, not permission to log in or execute a command today. */
    public function validateHistoricalMember(object $root, int $actorId, string $grant, int $revision): array
    {
        abort_unless($actorId>0 && $actorId<=self::SAFE && $revision>0 && $revision<=self::SAFE
            && preg_match('/^[a-f0-9]{64}$/D',$grant),403);
        return $this->transaction(function() use ($root,$actorId,$grant,$revision) {
            $fresh=$this->lockAuthenticated($root);
            $history=DB::table('desktop_dashboard_workspace_manifests')->where('workspace_id',$fresh->id)
                ->where('revision',$revision)->first();
            abort_unless($history,409,'دليل عضوية العملية المحفوظة غير متاح؛ لم تُحذف العملية.');
            $manifest=$this->checkedManifest($history->manifest_json,$fresh,$revision);
            foreach ($manifest['members'] as $member) if ($member['actor_id']===$actorId) {
                abort_unless(hash_equals($member['grant'],$grant),403); return $member;
            }
            abort(403);
        });
    }

    /** Validate captured authority and fresh authority separately; adapters must honor the intersection. */
    public function scopeForReplay(object $root, int $actorId, string $grant, int $revision): DesktopDashboardWorkspaceScope
    {
        return $this->transaction(function() use ($root,$actorId,$grant,$revision) {
            $fresh=$this->lockAuthenticated($root);
            $accepted=$this->validateHistoricalMember($fresh,$actorId,$grant,$revision);
            [$manifest,$member]=$this->currentMember($fresh,$actorId);
            abort_unless($member['role']===$accepted['role'],403,'صلاحية الحساب تغيّرت؛ العملية المحفوظة تحتاج مراجعة.');
            $actor=$this->freshActor((object)['id'=>$actorId]);
            return DesktopDashboardWorkspaceScope::issue($this,$this->issuanceCapability,$fresh,$actor,$member,
                $this->rootFingerprint($fresh),$manifest['revision'],$accepted,$revision);
        });
    }
    public function assertIssuanceCapability(object $capability): void
    {
        if ($capability!==$this->issuanceCapability) throw new \LogicException('Workspace authority cannot be supplied by a request.');
    }

    public function rootFingerprint(object $root): string
    {
        if ($root instanceof DesktopDashboardWorkspaceScope) { $root->assertIssued(); return $root->rootFingerprint(); }
        abort_unless(is_string($root->id??null) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$root->id),401);
        foreach (['enrollment_hash','token_hash'] as $field)
            abort_unless(is_string($root->$field??null) && preg_match('/^[a-f0-9]{64}$/D',$root->$field),401);
        $time=$root->enrolled_at??null;
        if ($time instanceof \DateTimeInterface) $time=\Carbon\Carbon::instance($time)->utc()->format('Y-m-d H:i:s');
        abort_unless(is_string($time) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$time),401);
        return hash('sha256',self::canonical(['format'=>2,'kind'=>'desktop-dashboard-workspace-root',
            'workspace_id'=>$root->id,'custodian_actor_id'=>$this->safeInteger($root->custodian_actor_id??null),
            'enrollment_hash'=>$root->enrollment_hash,'token_hash'=>$root->token_hash,'enrolled_at'=>$time]));
    }

    /** Cross-language protocol canonicalization: UTF-8 byte-sorted object keys, ordered arrays. */
    public static function canonical($value): string
    {
        $sort=function($item) use (&$sort) {
            if (is_array($item)) {
                $list=array_keys($item)===range(0,count($item)-1) || $item===[];
                if (!$list) ksort($item,SORT_STRING);
                foreach ($item as &$child) $child=$sort($child); unset($child); return $item;
            }
            if (is_object($item)) { $vars=get_object_vars($item); ksort($vars,SORT_STRING); return (object)array_map($sort,$vars); }
            if (is_float($item)) throw new \InvalidArgumentException('Workspace protocol numbers must be integers.');
            return $item;
        };
        return json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    private function checkedStoredManifest(object $root): array
    {
        return $this->checkedManifest($root->manifest_json,$root,(int)$root->manifest_revision);
    }
    /** Ordinary authority checks read one actor; full roster work belongs to preparation/synchronization. */
    private function currentMember(object $root, int $actorId): array
    {
        $fresh=$this->lockAuthenticated($root);
        $manifest=(int)$fresh->manifest_revision>0 ? $this->checkedStoredManifest($fresh) : $this->manifest($fresh);
        $actor=$this->freshActor((object)['id'=>$actorId]);
        $facts=$this->memberFacts($actor,$this->permissionFacts($actorId));
        abort_unless($facts!==null,403,'الحساب غير متاح للعمل على هذا الجهاز.');
        foreach ($manifest['members'] as $member) if ($member['actor_id']===$actorId) {
            $captured=$member; unset($captured['grant']);
            if (self::canonical($captured)===self::canonical($facts)) return [$manifest,$member];
            break;
        }
        // A change to this actor must produce fresh signed authority before it can be used.
        $manifest=$this->manifest($fresh);
        foreach ($manifest['members'] as $member) if ($member['actor_id']===$actorId) return [$manifest,$member];
        abort(403,'الحساب غير متاح للعمل على هذا الجهاز.');
    }
    private function checkedManifest($json, object $root, int $revision): array
    {
        abort_unless(is_string($json) && strlen($json)<=self::MAX_MANIFEST_BYTES,503);
        try { $value=json_decode($json,true,64,JSON_THROW_ON_ERROR); } catch (\JsonException $e) { abort(503); }
        abort_unless(is_array($value) && array_keys($value)===['format','kind','members','revision','root_fingerprint','roster_sha256','signature','workspace_id']
            && $value['format']===2 && $value['kind']==='desktop-dashboard-workspace-members'
            && $value['workspace_id']===$root->id && $value['revision']===$revision
            && $value['root_fingerprint']===$this->rootFingerprint($root) && is_array($value['members'])
            && is_string($value['roster_sha256']) && hash_equals($value['roster_sha256'],hash('sha256',self::canonical($value['members'])))
            && is_string($value['signature']),503,'سجل عضوية الجهاز غير مكتمل.');
        $body=$value; unset($body['signature']);
        abort_unless(hash_equals($value['signature'],$this->sign('manifest',$body)) && self::canonical($value)===$json,503,
            'توقيع سجل عضوية الجهاز غير صالح.'); return $value;
    }
    private function freshActor($actor, bool $lock=true): User
    {
        $id=$this->safeInteger($actor->id??null);
        $query=User::withoutGlobalScopes()->whereKey($id); if ($lock) $query->lockForUpdate();
        $fresh=$query->first();
        abort_unless($fresh && in_array($fresh->account_type,self::ROLES,true)
            && !in_array($fresh->status??null,['disabled','declined'],true)
            && $this->usablePassword($fresh->getAuthPassword()) && $this->aliases($fresh),403,'الحساب غير متاح لتجهيز الجهاز.');
        return $fresh;
    }
    private function usablePassword($value): bool
    {
        return is_string($value) && strlen($value)>=40 && strlen($value)<=1024
            && (password_get_info($value)['algoName']??'unknown')!=='unknown';
    }
    private function aliases(User $actor): array
    {
        $result=[];
        foreach ([$actor->email,$actor->mobile] as $value) {
            if (!is_string($value) || strlen($value)>1280 || preg_match('/[\x00-\x1f\x7f]/',$value)) continue;
            $value=mb_strtolower(trim($value),'UTF-8'); if (str_starts_with($value,'0')) $value=substr($value,1);
            if ($value!=='' && mb_strlen($value,'UTF-8')<=320) $result[]=$value;
        }
        return $this->set($result);
    }
    private function permissionFacts(?int $actorId = null): array
    {
        $defaults=['permissions'=>'permissions','roles'=>'roles','model_has_roles'=>'model_has_roles',
            'model_has_permissions'=>'model_has_permissions','role_has_permissions'=>'role_has_permissions'];
        $tables=array_replace($defaults,(array)config('permission.table_names',[]));
        foreach ($defaults as $key=>$unused) abort_unless(is_string($tables[$key])
            && preg_match('/^[a-zA-Z0-9_]+$/D',$tables[$key]) && Schema::hasTable($tables[$key]),503,'سجل صلاحيات الحسابات غير مكتمل.');
        $facts=[];
        if ($actorId===null) {
            foreach ($defaults as $key=>$unused) $facts[$key]=DB::table($tables[$key])->lockForUpdate()->get();
            return $facts;
        }
        $type=(new User)->getMorphClass();
        foreach (['model_has_roles','model_has_permissions'] as $key)
            $facts[$key]=DB::table($tables[$key])->where('model_type',$type)->where('model_id',$actorId)->lockForUpdate()->get();
        $roleIds=$facts['model_has_roles']->pluck('role_id')->all();
        $facts['roles']=DB::table($tables['roles'])->whereIn('id',$roleIds)->lockForUpdate()->get();
        $facts['role_has_permissions']=DB::table($tables['role_has_permissions'])->whereIn('role_id',$roleIds)->lockForUpdate()->get();
        $facts['permissions']=DB::table($tables['permissions'])->where('guard_name','admin')->lockForUpdate()->get();
        return $facts;
    }
    private function memberFacts(User $actor, array $facts): ?array
    {
        if (!$this->usablePassword($actor->getAuthPassword()) || !($aliases=$this->aliases($actor))) return null;
        $id=$this->safeInteger($actor->id); $permissions=$this->permissions($id,$facts);
        return ['actor_id'=>$id,'role'=>$actor->account_type,'enabled'=>true,'aliases'=>$aliases,
            'branches'=>$this->branches($actor,$permissions),'permissions'=>$permissions,
            // Import can check this without the server signing key; the HMAC grant authenticates it.
            'password_binding'=>hash('sha256',$actor->getAuthPassword())];
    }
    private function permissions(int $actor, array $facts): array
    {
        $type=(new User)->getMorphClass(); $roleIds=[]; $permissionIds=[]; $roles=[];
        foreach ($facts['model_has_roles'] as $row) if ($row->model_type===$type && (int)$row->model_id===$actor) $roleIds[(int)$row->role_id]=true;
        $validRoleIds=[];
        foreach ($facts['roles'] as $row) if ($row->guard_name==='admin' && isset($roleIds[(int)$row->id])) {
            $validRoleIds[(int)$row->id]=true; $roles[]=$row->name;
        }
        foreach ($facts['model_has_permissions'] as $row) if ($row->model_type===$type && (int)$row->model_id===$actor) $permissionIds[(int)$row->permission_id]=true;
        foreach ($facts['role_has_permissions'] as $row) if (isset($validRoleIds[(int)$row->role_id])) $permissionIds[(int)$row->permission_id]=true;
        $super=$actor===1 || in_array('Super Admin',$roles,true); $names=[];
        foreach ($facts['permissions'] as $row) if ($row->guard_name==='admin' && ($super || isset($permissionIds[(int)$row->id]))) $names[]=$row->name;
        $names=$this->set($names); $roles=$this->set($roles);
        abort_if(count($names)>4096 || count($roles)>4096,413);
        return ['super_admin'=>$super,'names'=>$names,'roles'=>$roles];
    }
    private function branches(User $actor, array $permissions): array
    {
        $result=[]; $admin=$actor->account_type==='admin'; $parent=(int)($actor->owner_resturant_id??0);
        // A valid dashboard admin can have only nonfinancial modules, such as contracts.
        if ($admin && !$parent && !$permissions['super_admin'] && !in_array('order-list',$permissions['names'],true)) return [];
        if ($this->has('resturants',['id','user_id'])) {
            $query=DB::table('resturants');
            if ($admin && $parent) $query->where('id',$parent);
            elseif ($actor->account_type==='resturant_owner') {
                $query->where(function($q) use ($parent) { $q->where('id',$parent);
                    if ($this->has('resturants',['parent_id'])) $q->orWhere('parent_id',$parent); });
                if ($parent<1) $query->whereRaw('1=0');
            } elseif (!$admin) $query->where('user_id',$actor->id);
            foreach ($query->orderBy('id')->lockForUpdate()->pluck('id') as $id) $result[]='f:'.$this->safeInteger($id);
        }
        if (!$parent && $actor->account_type!=='resturant_owner' && $this->has('go_stores',['user_id'])
            && $this->has('users',['account_type','app_scope','status'])) {
            $query=DB::table('go_stores')->join('users','users.id','=','go_stores.user_id')
                ->where('users.app_scope','go_partner')->where('users.status','accepted');
            if (!$admin) $query->where('users.id',$actor->id);
            foreach ($query->orderBy('go_stores.user_id')->lockForUpdate()->get(['users.id','users.account_type',
                $this->has('users',['pending_vendor_id'])?'users.pending_vendor_id':DB::raw('NULL AS pending_vendor_id')]) as $row) {
                $allowed=$row->account_type==='vendor';
                if (!$allowed && $row->account_type==='delegate' && !empty($row->pending_vendor_id)
                    && $this->has('pending_vendors',['id','profession_key']))
                    $allowed=DB::table('pending_vendors')->where('id',$row->pending_vendor_id)->lockForUpdate()->value('profession_key')==='store_owner';
                if ($allowed) $result[]='gs:'.$this->safeInteger($row->id);
            }
        }
        $result=$this->set($result); abort_if(count($result)>10000,413); return $result;
    }
    private function set(array $values): array
    {
        foreach ($values as $value) abort_unless(is_string($value) && $value!=='' && !preg_match('/[\x00-\x1f\x7f]/',$value),503);
        $values=array_values(array_unique($values,SORT_STRING)); sort($values,SORT_STRING); return $values;
    }
    private function has(string $table, array $columns): bool
    {
        if (!isset($this->columns[$table])) $this->columns[$table]=Schema::hasTable($table)?Schema::getColumnListing($table):[];
        return !array_diff($columns,$this->columns[$table]);
    }
    private function safeInteger($value, bool $zero=false): int
    {
        abort_unless((is_int($value)||(is_string($value)&&preg_match('/^(?:0|[1-9][0-9]*)$/D',$value)))
            && $value>=($zero?0:1) && $value<=self::SAFE,401); return (int)$value;
    }
    private function key(): string
    {
        $key=config('app.key'); abort_unless(is_string($key),503);
        if (str_starts_with($key,'base64:')) $key=base64_decode(substr($key,7),true);
        abort_unless(is_string($key) && strlen($key)>=32,503,'مفتاح توقيع مساحة الجهاز غير متاح.'); return $key;
    }
    private function token(string $id, int $custodian, string $nonce): string
    {
        return $this->sign('enrollment',['workspace_id'=>$id,'custodian_actor_id'=>$custodian,'nonce'=>$nonce]);
    }
    private function sign(string $domain, array $value): string
    {
        return hash_hmac('sha256',"desktop-workspace-v2\0".$domain."\0".self::canonical($value),$this->key());
    }
    private function transaction(callable $work)
    {
        return DB::transactionLevel()>0 ? $work() : DB::transaction($work,3);
    }
}
