<?php
namespace App\Services\Dashboard;

/** Complete reviewed module datasets are separate from whole-dashboard route acceptance. */
final class DesktopDashboardWorkspaceModuleCoverage
{
    private const KIND='desktop-workspace-module-coverage';
    private const MODULES=['accounts','account-addresses','settings'];
    private const COMMON=['users','resturants','roles','permissions','role_has_permissions','model_has_roles','model_has_permissions'];
    private const STRUCTURAL=['go_stores','pending_vendors'];

    public static function requiredTables(string $module): array
    {
        self::need(in_array($module,self::MODULES,true));
        $tables=array_merge(self::COMMON,$module==='settings'?['settings']:['user_address','orders']);sort($tables,SORT_STRING);return $tables;
    }
    public static function settingsNames(): array
    {
        $names=array_map(fn($property)=>$property->getName(),(new \ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(\ReflectionProperty::IS_PUBLIC));
        sort($names,SORT_STRING);return $names;
    }
    /** Same structural restaurant/GO eligibility used by the original settings adapter; never financial BASE. */
    public static function structural(array $tables): array
    {
        if(!isset($tables['users']['rows'],$tables['resturants']['rows']))return [];
        $branches=[];$users=[];$pending=[];
        foreach($tables['users']['rows']as$row)if(isset($row['id']))$users[(string)$row['id']]=$row;
        foreach($tables['pending_vendors']['rows']??[]as$row)if(isset($row['id']))$pending[(string)$row['id']]=$row;
        foreach($tables['resturants']['rows']as$row){self::need(self::branchId($row['id']??null));$branches[]='f:'.$row['id'];}
        foreach($tables['go_stores']['rows']??[]as$row){
            self::need(self::branchId($row['user_id']??null));$owner=$users[(string)$row['user_id']]??null;
            if(!$owner||($owner['app_scope']??null)!=='go_partner')continue;
            $eligible=($owner['account_type']??null)==='vendor';
            if(($owner['account_type']??null)==='delegate'&&!empty($owner['pending_vendor_id']))
                $eligible=($pending[(string)$owner['pending_vendor_id']]['profession_key']??null)==='store_owner';
            if($eligible)$branches[]='gs:'.$row['user_id'];
        }
        $branches=array_values(array_unique($branches));sort($branches,SORT_STRING);self::need(count($branches)<=10000);return $branches;
    }
    /** Source supplies ONLY parts certified against complete intended source counts in its locked export. */
    public static function issue(array $manifest,int $witnessId,array $tables,array $structural,?array $addressChildren=null): array
    {
        if(!self::witness($manifest,$witnessId,$tables)||!isset($tables['users'],$tables['resturants'])
            ||$structural!==self::structural($tables))return [];
        $claims=[];
        foreach(self::MODULES as$module){
            if($module==='account-addresses'&&$addressChildren===null)continue;
            $names=self::requiredTables($module);
            foreach(self::STRUCTURAL as$name)if(isset($tables[$name]))$names[]=$name;
            if($module==='account-addresses')$names=array_merge($names,$addressChildren);
            $names=array_values(array_unique($names));sort($names,SORT_STRING);
            if(array_diff($names,array_keys($tables)))continue;
            if($module==='settings'&&!self::settingsComplete($tables['settings']['rows']??[]))continue;
            if($module==='account-addresses'&&!self::addressConstraints($tables,$addressChildren))continue;
            $commitments=[];foreach($names as$name){
                self::need(self::table($name)&&is_array($tables[$name]['rows']??null)&&self::hash($tables[$name]['sha256']??null));
                $commitments[$name]=['rows'=>count($tables[$name]['rows']),'sha256'=>$tables[$name]['sha256']];
            }
            $claim=['format'=>1,'kind'=>self::KIND,'module'=>$module,'workspace_id'=>$manifest['workspace_id'],
                'manifest_revision'=>$manifest['revision'],'witness_actor_id'=>$witnessId,'tables'=>$commitments,
                'structural_branches'=>$structural,'structural_sha256'=>hash('sha256',DesktopDashboardWorkspaceAuthority::canonical($structural))];
            if($module==='account-addresses'){$children=array_values(array_unique($addressChildren));sort($children,SORT_STRING);$claim['address_children']=$children;}
            $claims[$module]=$claim;
        }
        return $claims;
    }
    /** Original import separately verifies these declared raw hashes/DDL/FKs before issuing native provenance. */
    public static function validateSnapshot(array $snapshot): void
    {
        $modules=$snapshot['coverage']['modules']??[];self::need(is_array($modules)&&!array_diff(array_keys($modules),self::MODULES));
        if(!$modules)return;
        $manifest=$snapshot['workspace_manifest']??null;$tables=$snapshot['tables']??null;
        self::need(is_array($manifest)&&is_array($tables));$structural=self::structural($tables);
        foreach($modules as$module=>$claim){
            self::shape($claim,$module,$manifest['workspace_id']??null,$manifest['revision']??null);
            self::need(self::witness($manifest,$claim['witness_actor_id'],$tables)&&$claim['structural_branches']===$structural);
            $required=self::requiredTables($module);
            foreach(self::STRUCTURAL as$name)if(isset($tables[$name]))$required[]=$name;
            if($module==='account-addresses'){
                self::need(self::addressConstraints($tables,$claim['address_children']));$required=array_merge($required,$claim['address_children']);
            }
            $required=array_values(array_unique($required));sort($required,SORT_STRING);$actual=array_keys($claim['tables']);sort($actual,SORT_STRING);
            self::need($actual===$required);
            foreach($claim['tables']as$name=>$part)self::need(isset($tables[$name])&&is_array($tables[$name]['rows']??null)
                &&count($tables[$name]['rows'])===$part['rows']&&($tables[$name]['sha256']??null)===$part['sha256']);
            if($module==='settings')self::need(self::settingsComplete($tables['settings']['rows']));
        }
    }
    /** Runtime receives only claims already sealed by typed import + local MAC, never request flags. */
    public static function forScope(DesktopDashboardWorkspaceScope $scope,string $module): array
    {
        $coverage=json_decode($scope->coverage??'{}',true,512,JSON_THROW_ON_ERROR);$claim=$coverage['modules'][$module]??null;
        self::need(is_array($claim),503);self::shape($claim,$module,$scope->id,$scope->manifestRevision());return $claim;
    }
    private static function shape($claim,string $module,$workspace,$revision): void
    {
        $fields=['format','kind','module','workspace_id','manifest_revision','witness_actor_id','tables','structural_branches','structural_sha256'];
        if($module==='account-addresses')$fields[]='address_children';
        self::need(in_array($module,self::MODULES,true)&&is_array($claim)&&self::exact($claim,$fields)
            &&$claim['format']===1&&$claim['kind']===self::KIND&&$claim['module']===$module
            &&$claim['workspace_id']===$workspace&&$claim['manifest_revision']===$revision&&self::positive($claim['witness_actor_id'])
            &&is_array($claim['tables'])&&count($claim['tables'])<=128&&is_array($claim['structural_branches'])
            &&array_is_list($claim['structural_branches'])&&count($claim['structural_branches'])<=10000&&self::hash($claim['structural_sha256'])
            &&hash_equals($claim['structural_sha256'],hash('sha256',DesktopDashboardWorkspaceAuthority::canonical($claim['structural_branches']))));
        $sorted=$claim['structural_branches'];sort($sorted,SORT_STRING);self::need($sorted===array_values(array_unique($claim['structural_branches'])));
        foreach($sorted as$branch)self::need(is_string($branch)&&(bool)preg_match('/^(?:f|gs):[1-9][0-9]{0,18}$/D',$branch));
        foreach($claim['tables']as$name=>$part)self::need(self::table($name)&&is_array($part)&&self::exact($part,['rows','sha256'])
            &&is_int($part['rows'])&&$part['rows']>=0&&$part['rows']<=500000&&self::hash($part['sha256']));
        if($module==='account-addresses'){
            self::need(is_array($claim['address_children'])&&array_is_list($claim['address_children'])&&count($claim['address_children'])<=128);
            $children=$claim['address_children'];sort($children,SORT_STRING);self::need($children===array_values(array_unique($claim['address_children'])));
            foreach($children as$name)self::need(self::table($name));
        }
    }
    private static function witness(array $manifest,int $id,array $tables): bool
    {
        $member=null;foreach($manifest['members']??[]as$value)if(($value['actor_id']??null)===$id){$member=$value;break;}
        if(!$member||$member['role']!=='admin'||($member['permissions']['super_admin']??null)!==true)return false;
        $users=array_values(array_filter($tables['users']['rows']??[],fn($user)=>(string)($user['id']??'')===(string)$id));
        if(count($users)!==1)return false;$user=$users[0];
        return ($user['account_type']??null)==='admin'&&empty($user['owner_resturant_id'])
            &&!in_array($user['status']??null,['disabled','declined'],true)&&is_string($user['password']??null)
            &&hash('sha256',$user['password'])===($member['password_binding']??null);
    }
    private static function settingsComplete(array $rows): bool
    {
        $names=[];foreach($rows as$row){if(($row['group']??null)!=='general'||!is_string($row['name']??null))return false;$names[]=$row['name'];}
        sort($names,SORT_STRING);return $names===self::settingsNames();
    }
    private static function addressConstraints(array $tables,array $children): bool
    {
        $actual=[];foreach($tables as$name=>$part)if(is_string($part['ddl']??null)
            &&preg_match('/REFERENCES\s+`user_address`\s*\(/i',$part['ddl']))$actual[]=$name;
        if(array_diff($actual,$children)||array_diff($children,array_keys($tables)))return false;
        $orders=$tables['orders']['ddl']??'';
        return !str_contains($orders,'`user_address_id`')||in_array('orders',$children,true);
    }
    private static function exact(array $value,array $fields): bool {$keys=array_keys($value);sort($keys);sort($fields);return $keys===$fields;}
    private static function positive($id): bool {return (is_int($id)||(is_string($id)&&preg_match('/^[1-9][0-9]*$/D',$id)))&&$id>0&&$id<=9007199254740991;}
    private static function branchId($id): bool {return (is_int($id)||is_string($id))&&(bool)preg_match('/^[1-9][0-9]{0,18}$/D',(string)$id);}
    private static function hash($value): bool {return is_string($value)&&(bool)preg_match('/^[a-f0-9]{64}$/D',$value);}
    private static function table($value): bool {return is_string($value)&&(bool)preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$value);}
    private static function need(bool $value,int $code=422): void {abort_unless($value,$code,'تغطية بيانات هذا القسم لم تُثبت في نسخة مساحة العمل.');}
}
