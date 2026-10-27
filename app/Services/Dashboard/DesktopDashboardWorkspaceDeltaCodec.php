<?php
namespace App\Services\Dashboard;

/**
 * Pure protocol-2 table identities. The manifest must already be authenticated
 * by the workspace authority (server) or the sealed native registry (desktop).
 * This class checks its internal binding; it does not verify the server HMAC.
 *
 * Rows must have passed WorkspaceBootstrap's ordinary private-column redaction.
 * Only users.password has additional member-aware handling here. Original SQL
 * numeric, float, DDL and row-multiset canonicalization stays in the v1 codec.
 * Original row digests are namespaced by the v2 index/table envelope, rather
 * than reparsing every DDL once per row in large background captures.
 */
final class DesktopDashboardWorkspaceDeltaCodec
{
    public const MAX_MEMBERS=4096;
    public const MAX_MANIFEST_BYTES=16*1024*1024;
    public const DISABLED_PASSWORD='!desktop-disabled';
    private const SAFE=9007199254740991;
    private array $context;
    private array $passwordBindings=[];

    public function __construct(array $manifest)
    {
        self::need(self::exact($manifest,['format','kind','workspace_id','revision','root_fingerprint','members','roster_sha256','signature'])
            &&$manifest['format']===2&&$manifest['kind']==='desktop-dashboard-workspace-members'
            &&self::uuid($manifest['workspace_id'])&&self::positive($manifest['revision'])
            &&self::hash($manifest['root_fingerprint'])&&self::hash($manifest['roster_sha256'])&&self::hash($manifest['signature'])
            &&self::list($manifest['members'])&&count($manifest['members'])>0&&count($manifest['members'])<=self::MAX_MEMBERS);
        $last=0;
        foreach($manifest['members'] as $member){
            self::need(is_array($member)&&self::exact($member,['actor_id','role','enabled','aliases','branches','permissions','password_binding','grant'])
                &&self::positive($member['actor_id'])&&$member['actor_id']>$last
                &&in_array($member['role'],['admin','vendor','resturant_owner'],true)&&$member['enabled']===true
                &&self::aliases($member['aliases'])&&count($member['aliases'])>0
                &&self::strings($member['branches'],40,10000)&&self::hash($member['password_binding'])&&self::hash($member['grant'])
                &&is_array($member['permissions'])&&self::exact($member['permissions'],['super_admin','names','roles'])
                &&is_bool($member['permissions']['super_admin'])&&self::strings($member['permissions']['names'],4096,4096)
                &&self::strings($member['permissions']['roles'],4096,4096));
            foreach($member['branches'] as $branch)self::need((bool)preg_match('/^(?:f|gs):[1-9][0-9]{0,15}$/D',$branch)
                &&self::actor(substr($branch,strpos($branch,':')+1))!==null);
            $this->passwordBindings[$member['actor_id']]=$member['password_binding'];$last=$member['actor_id'];
        }
        $encoded=self::canonical($manifest);
        self::need(strlen($encoded)<=self::MAX_MANIFEST_BYTES
            &&hash_equals($manifest['roster_sha256'],hash('sha256',self::canonical($manifest['members']))));
        $this->context=['workspace_id'=>$manifest['workspace_id'],'revision'=>$manifest['revision'],
            'root_fingerprint'=>$manifest['root_fingerprint'],'roster_sha256'=>$manifest['roster_sha256']];
    }

    /** Public binding only: no opaque member grants, verifiers or credentials. */
    public function context(): array {return $this->context;}

    public static function schemaHash(string $ddl): string {return DesktopDashboardDeltaCodec::schemaHash($ddl);}

    /** Original canonical SQL row digest; the index/table envelope binds its workspace. */
    public function rowHash(string $table,array $row,string $ddl,?array $projection=null): string
    {
        [$rows,$actors]=$this->buckets($table,[$row],$projection);
        $key=array_key_first($rows);
        return DesktopDashboardDeltaCodec::rowHash($table,$rows[$key][0],$actors[$key],$ddl,$projection);
    }

    public function index(string $table,array $rows,string $ddl,?array $projection=null): array
    {
        return $this->indexed($table,$rows,$ddl,$projection)[0];
    }
    private function indexed(string $table,array $rows,string $ddl,?array $projection): array
    {
        $this->table($table);self::need(count($rows)<=DesktopDashboardDeltaCodec::MAX_ROWS);
        // Even an empty users table must validate its DDL against the named
        // table; it otherwise has no member bucket to call the original codec.
        $empty=DesktopDashboardDeltaCodec::index($table,[],1,$ddl,$projection);
        [$buckets,$actors]=$this->buckets($table,$rows,$projection);$hashes=[];$parts=[];
        foreach($buckets as $key=>$bucket){
            $part=DesktopDashboardDeltaCodec::index($table,$bucket,$actors[$key],$ddl,$projection);
            $parts[$key]=$part;
            foreach($part['hashes'] as $digest=>$count)$hashes[$digest]=($hashes[$digest]??0)+$count;
        }
        ksort($hashes,SORT_STRING);$schema=$empty['schema_sha256'];
        $base=['schema_sha256'=>$schema,'count'=>count($rows),'hashes'=>$hashes];
        DesktopDashboardDeltaCodec::validateIndex($base);
        $rowset=DesktopDashboardDeltaCodec::tableHash($hashes);
        $index=['format'=>2,'kind'=>'desktop-workspace-table-index']+$this->context+['table'=>$table]+$base
            +['rowset_sha256'=>$rowset,'table_sha256'=>$this->tableHash($table,$schema,$rowset,count($rows))];
        return [$index,$buckets,$actors,$parts];
    }

    /**
     * A context mismatch is refused unless the caller explicitly supplies an
     * independently authenticated predecessor manifest. Forward membership
     * transitions replace the target table; they never run during login.
     */
    public function validateIndex(string $table,array $index,?self $previous=null): array
    {
        $scope=$this->source($previous);$this->table($table);
        self::need(self::exact($index,['format','kind','workspace_id','revision','root_fingerprint','roster_sha256','table',
            'schema_sha256','count','hashes','rowset_sha256','table_sha256'])
            &&$index['format']===2&&$index['kind']==='desktop-workspace-table-index'
            &&$index['table']===$table&&self::matches($index,$scope->context)
            &&self::hash($index['rowset_sha256'])&&self::hash($index['table_sha256']));
        $base=['schema_sha256'=>$index['schema_sha256'],'count'=>$index['count'],'hashes'=>$index['hashes']];
        DesktopDashboardDeltaCodec::validateIndex($base);
        $rowset=DesktopDashboardDeltaCodec::tableHash($base['hashes']);
        self::need(hash_equals($index['rowset_sha256'],$rowset)&&hash_equals($index['table_sha256'],
            $scope->tableHash($table,$index['schema_sha256'],$rowset,$index['count'])));
        return $base;
    }

    public function delta(string $table,array $rows,string $ddl,array $before,?array $projection=null,?self $previous=null): array
    {
        $base=$this->validateIndex($table,$before,$previous);
        [$after,$buckets,$actors,$parts]=$this->indexed($table,$rows,$ddl,$projection);
        $replace=$previous!==null||$base['schema_sha256']!==$after['schema_sha256'];$add=[];
        $empty=['schema_sha256'=>$after['schema_sha256'],'count'=>0,'hashes'=>[]];
        foreach($buckets as $key=>$bucket){
            // SQL types, canonical transport floats and existing password
            // handling are all delegated to the original reviewed codec.
            // A bucket needs only old digests that still occur in that target
            // bucket to decide additions. Global removals are computed once
            // below. Passing the whole baseline per member is quadratic.
            $bucketBefore=$empty;
            if(!$replace){
                foreach($parts[$key]['hashes'] as $digest=>$unused)if(isset($base['hashes'][$digest]))
                    $bucketBefore['hashes'][$digest]=$base['hashes'][$digest];
                $bucketBefore['count']=array_sum($bucketBefore['hashes']);
            }
            $part=DesktopDashboardDeltaCodec::delta($table,$bucket,$actors[$key],$ddl,$bucketBefore,$projection);
            foreach($part['add'] as $row)$add[]=$row;
        }
        $remove=[];
        foreach($base['hashes'] as $digest=>$count){$difference=$replace?$count:$count-($after['hashes'][$digest]??0);if($difference>0)$remove[$digest]=$difference;}
        $value=['format'=>2,'kind'=>'desktop-workspace-table-delta']+$this->context+['table'=>$table,
            'before_sha256'=>$before['table_sha256'],'schema_sha256'=>$after['schema_sha256'],
            'remove'=>$remove,'add'=>$add,'rowset_sha256'=>$after['rowset_sha256'],
            'target_hash'=>$after['table_sha256'],'count'=>$after['count']];
        if($replace)$value['replace']=true;
        return $value;
    }

    public function apply(string $table,array $rows,string $ddl,array $delta,?array $projection=null): array
    {
        $this->validateDelta($table,$delta);
        self::need(!array_key_exists('replace',$delta)&&$delta['schema_sha256']===self::schemaHash($ddl));
        $before=$this->index($table,$rows,$ddl,$projection);
        self::need(hash_equals($delta['before_sha256'],$before['table_sha256']));
        $this->incoming($table,$delta['add'],$projection);
        [$buckets,$actors]=$this->buckets($table,$rows,$projection);
        [$added,$addedActors]=$this->buckets($table,$delta['add'],$projection);
        foreach($addedActors as $key=>$actor)$actors[$key]=$actor;
        $keys=array_unique(array_merge(array_keys($buckets),array_keys($added)));$remaining=$delta['remove'];$result=[];
        foreach($keys as $key){
            $old=$buckets[$key]??[];$new=$added[$key]??[];$actor=$actors[$key];
            $oldIndex=DesktopDashboardDeltaCodec::index($table,$old,$actor,$ddl,$projection);
            $newIndex=DesktopDashboardDeltaCodec::index($table,$new,$actor,$ddl,$projection);
            $remove=[];$target=$oldIndex['hashes'];
            foreach($oldIndex['hashes'] as $digest=>$count){
                $n=min($count,$remaining[$digest]??0);if($n>0){$remove[$digest]=$n;$remaining[$digest]-=$n;$target[$digest]-=$n;if($target[$digest]===0)unset($target[$digest]);}
            }
            foreach($newIndex['hashes'] as $digest=>$count)$target[$digest]=($target[$digest]??0)+$count;
            $legacy=['remove'=>$remove,'add'=>$new,'target_hash'=>DesktopDashboardDeltaCodec::tableHash($target),
                'count'=>count($old)-array_sum($remove)+count($new)];
            foreach(DesktopDashboardDeltaCodec::apply($table,$old,$actor,$ddl,$legacy,$projection) as $row)$result[]=$row;
        }
        self::need(array_sum($remaining)===0);$this->verifyTarget($table,$result,$ddl,$delta,$projection);return $result;
    }

    /** The caller verifies actual captured old rows before allowing replacement. */
    public function applyReplacement(string $table,array $rows,string $ddl,array $delta,array $before,
        ?array $projection=null,?self $previous=null): array
    {
        $this->validateDelta($table,$delta);$base=$this->validateIndex($table,$before,$previous);
        self::need(($delta['replace']??null)===true&&$rows===$delta['add']
            &&hash_equals($delta['before_sha256'],$before['table_sha256'])
            &&$delta['schema_sha256']===self::schemaHash($ddl)
            &&($previous!==null||$base['schema_sha256']!==$delta['schema_sha256']));
        $expected=$base['hashes'];$removed=$delta['remove'];ksort($expected,SORT_STRING);ksort($removed,SORT_STRING);
        self::need($expected===$removed);$this->incoming($table,$rows,$projection);
        // Reuse original safeRow/projection handling, including columns that
        // are deliberately excluded from an authenticated table projection.
        [$buckets,$actors]=$this->buckets($table,$rows,$projection);$result=[];
        foreach($buckets as $key=>$bucket){
            $index=DesktopDashboardDeltaCodec::index($table,$bucket,$actors[$key],$ddl,$projection);
            $legacy=['remove'=>[],'add'=>$bucket,'target_hash'=>DesktopDashboardDeltaCodec::tableHash($index['hashes']),'count'=>count($bucket)];
            foreach(DesktopDashboardDeltaCodec::apply($table,[],$actors[$key],$ddl,$legacy,$projection) as $row)$result[]=$row;
        }
        $this->verifyTarget($table,$result,$ddl,$delta,$projection);return $result;
    }

    private function source(?self $previous): self
    {
        if($previous===null)return $this;
        self::need($previous->context['workspace_id']===$this->context['workspace_id']
            &&$previous->context['root_fingerprint']===$this->context['root_fingerprint']
            &&$previous->context['revision']<$this->context['revision']);
        return $previous;
    }
    private function validateDelta(string $table,array $value): void
    {
        $keys=['format','kind','workspace_id','revision','root_fingerprint','roster_sha256','table',
            'before_sha256','schema_sha256','remove','add','rowset_sha256','target_hash','count'];
        if(array_key_exists('replace',$value)){$keys[]='replace';self::need($value['replace']===true);}
        self::need(self::exact($value,$keys)&&$value['format']===2&&$value['kind']==='desktop-workspace-table-delta'
            &&self::matches($value,$this->context)&&$value['table']===$table
            &&self::hash($value['before_sha256'])&&self::hash($value['schema_sha256'])
            &&is_array($value['remove'])&&self::list($value['add'])&&self::hash($value['rowset_sha256'])
            &&self::hash($value['target_hash'])&&is_int($value['count'])&&$value['count']>=0
            &&$value['count']<=DesktopDashboardDeltaCodec::MAX_ROWS&&count($value['add'])<=DesktopDashboardDeltaCodec::MAX_ROWS);
        DesktopDashboardDeltaCodec::tableHash($value['remove']);
        self::need(hash_equals($value['target_hash'],$this->tableHash($table,$value['schema_sha256'],$value['rowset_sha256'],$value['count'])));
    }
    private function verifyTarget(string $table,array $rows,string $ddl,array $delta,?array $projection): void
    {
        $target=$this->index($table,$rows,$ddl,$projection);
        self::need($target['count']===$delta['count']&&hash_equals($target['rowset_sha256'],$delta['rowset_sha256'])
            &&hash_equals($target['table_sha256'],$delta['target_hash']));
    }
    private function tableHash(string $table,string $schema,string $rowset,int $count): string
    {
        $this->table($table);self::need(self::hash($schema)&&self::hash($rowset)&&$count>=0&&$count<=DesktopDashboardDeltaCodec::MAX_ROWS);
        return hash('sha256',"desktop-workspace-table-v2\0".self::canonical($this->context+['table'=>$table,
            'schema_sha256'=>$schema,'rowset_sha256'=>$rowset,'count'=>$count]));
    }
    private function incoming(string $table,array $rows,?array $projection): void
    {
        if($table!=='users')return;
        foreach($rows as $row){
            self::need(is_array($row)&&array_key_exists('password',$row));$id=self::actor($row['id']??null);
            if($id!==null&&isset($this->passwordBindings[$id]))$this->password($id,$row['password']);
            else self::need($row['password']===self::DISABLED_PASSWORD);
        }
        self::need($projection===null);
    }
    /** Bucket members by original actor so v1 cannot disable another member's verifier. */
    private function buckets(string $table,array $rows,?array $projection): array
    {
        self::need(self::list($rows)&&count($rows)<=DesktopDashboardDeltaCodec::MAX_ROWS);$buckets=[];$actors=[];
        if($table!=='users')return [[$rows],[1]];
        self::need($projection===null);
        foreach($rows as $row){
            self::need(is_array($row)&&array_key_exists('password',$row));$id=self::actor($row['id']??null);
            if($id!==null&&isset($this->passwordBindings[$id])){$this->password($id,$row['password']);$key='actor:'.$id;$actor=$id;}
            else{$row['password']=self::DISABLED_PASSWORD;$key='nonmembers';$actor=1;}
            $buckets[$key][]=$row;$actors[$key]=$actor;
        }
        return [$buckets,$actors];
    }
    private function password(int $actor,$verifier): void
    {
        self::need(is_string($verifier)&&strlen($verifier)<=1024&&!str_starts_with($verifier,'!desktop-disabled'));
        $inspect=$verifier;
        if(preg_match('/^\$2[aby]\$(0[4-9]|[12][0-9]|3[01])\$[.\/A-Za-z0-9]{53}$/D',$verifier))$inspect=substr_replace($verifier,'y',2,1);
        else self::need((bool)preg_match('/^\$argon2(?:i|id)\$v=19\$m=[1-9][0-9]*,t=[1-9][0-9]*,p=[1-9][0-9]*\$[A-Za-z0-9+\/]{8,128}\$[A-Za-z0-9+\/]{16,128}$/D',$verifier));
        $info=password_get_info($inspect);
        self::need(in_array($info['algoName']??null,['bcrypt','argon2i','argon2id'],true)
            &&hash_equals($this->passwordBindings[$actor],hash('sha256',$verifier)));
    }
    private static function actor($value): ?int
    {
        if(is_bool($value))$value=$value?1:0;
        if(is_float($value)){if(!is_finite($value)||$value<1||$value>self::SAFE||floor($value)!==$value)return null;$value=sprintf('%.0F',$value);}
        if(is_int($value))return self::positive($value)?$value:null;
        if(!is_string($value)||!preg_match('/^\+?[0-9]{1,100}$/D',$value))return null;
        $digits=ltrim(ltrim($value,'+'),'0');if($digits===''||strlen($digits)>16||strlen($digits)===16&&strcmp($digits,(string)self::SAFE)>0)return null;
        $id=(int)$digits;return self::positive($id)?$id:null;
    }
    private function table(string $table): void {self::need((bool)preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$table));}
    private static function matches(array $value,array $context): bool {foreach($context as $key=>$expected)if(!array_key_exists($key,$value)||$value[$key]!==$expected)return false;return true;}
    private static function exact(array $value,array $keys): bool {return count($value)===count($keys)&&array_diff(array_keys($value),$keys)===[];}
    private static function list($value): bool {return is_array($value)&&($value===[]||array_keys($value)===range(0,count($value)-1));}
    private static function strings($values,int $length,int $count): bool
    {
        if(!self::list($values)||count($values)>$count)return false;$last=null;
        foreach($values as $value){if(!is_string($value)||$value===''||strlen($value)>$length||preg_match('/[\x00-\x1f\x7f]/',$value)||$last!==null&&strcmp($last,$value)>=0)return false;$last=$value;}return true;
    }
    /** Match native JavaScript's 320 UTF-16-unit alias budget, not UTF-8 bytes. */
    private static function aliases($values): bool
    {
        if(!self::strings($values,1280,2))return false;
        foreach($values as $value){
            $characters=preg_match_all('/./us',$value);$extra=preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$value);
            if($characters===false||$extra===false||$characters+$extra>320)return false;
        }
        return true;
    }
    private static function hash($value): bool {return is_string($value)&&(bool)preg_match('/^[a-f0-9]{64}$/D',$value);}
    private static function uuid($value): bool {return is_string($value)&&(bool)preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$value);}
    private static function positive($value): bool {return is_int($value)&&$value>0&&$value<=self::SAFE;}
    private static function canonical($value): string
    {
        $sort=function($item,int $depth=0)use(&$sort){
            self::need($depth<=32);
            if(is_array($item)){if(!self::list($item))ksort($item,SORT_STRING);foreach($item as &$child)$child=$sort($child,$depth+1);unset($child);return $item;}
            self::need($item===null||is_string($item)||is_int($item)||is_bool($item));return $item;
        };
        return json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR);
    }
    private static function need(bool $value): void {abort_unless($value,422,'وصف مزامنة مساحة عمل الجهاز غير صالح؛ البيانات المحفوظة لم تتغير.');}
}
