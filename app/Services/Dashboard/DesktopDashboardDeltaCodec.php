<?php
namespace App\Services\Dashboard;

/** Pure, bounded row identities for transport deltas; no SQL or account state is read here. */
final class DesktopDashboardDeltaCodec
{
    public const MAX_ROWS=500000;
    public const MAX_TABLES=200;
    public const MAX_INDEX_BYTES=64*1024*1024;
    public const MAX_RESPONSE_BYTES=256*1024*1024;
    private const INTEGERS=['tinyint','smallint','mediumint','int','integer','bigint','bit','bool','boolean'];
    private const DECIMALS=['decimal','numeric','fixed'];
    private const FLOATS=['float','double','real'];

    private static function need(bool $value): void
    {
        abort_unless($value,422,'وصف مزامنة التعديلات غير صالح؛ بيانات الجهاز محفوظة.');
    }
    private static function exact(array $value,array $keys): bool
    {
        return count($value)===count($keys)&&array_diff(array_keys($value),$keys)===[];
    }
    /** SHOW CREATE's mutable table counter is not a structural change. */
    public static function schemaHash(string $ddl): string
    {
        self::need(strlen($ddl)>0&&strlen($ddl)<=1024*1024);
        $ddl=str_replace("\r\n","\n",$ddl);
        self::need((bool)preg_match('/^CREATE TABLE `[a-z][a-z0-9_]{0,63}` \(\n/sD',$ddl));
        $end=strrpos($ddl,"\n)");self::need($end!==false);
        $body=substr($ddl,0,$end+2);$tail=substr($ddl,$end+2);
        self::need((bool)preg_match('/^ ENGINE=[a-z][a-z0-9_]*\b/iD',$tail));
        // Only an unquoted table option is removed, never a column attribute,
        // quoted default, comment or string containing the same characters.
        $parts=preg_split('/(\x27(?:[^\x27\\\\]|\\\\.|\x27\x27)*\x27|"(?:[^"\\\\]|\\\\.|"")*"|`(?:[^`]|``)*`)/s',$tail,-1,PREG_SPLIT_DELIM_CAPTURE);
        self::need(is_array($parts));
        foreach($parts as $index=>$part)if($index%2===0)$parts[$index]=preg_replace('/ AUTO_INCREMENT=[0-9]+(?= |$)/i','',$part);
        $tail=implode('',$parts);
        return hash('sha256',$body.rtrim($tail));
    }
    /** The declaration is trusted local/server DDL, never supplied by the client to an exporter. */
    private static function columns(string $table,string $ddl): array
    {
        self::need((bool)preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$table)
            &&str_starts_with(str_replace("\r\n","\n",$ddl),'CREATE TABLE `'.$table.'` ('));
        self::schemaHash($ddl);$columns=[];
        preg_match_all('/^\s*`([a-zA-Z_][a-zA-Z0-9_]{0,63})`\s+([a-z]+)\b([^\n]*)$/mi',$ddl,$matches,PREG_SET_ORDER);
        foreach($matches as $match){
            self::need(!isset($columns[$match[1]]));
            $type=strtolower($match[2]);$scale=null;
            if(in_array($type,self::DECIMALS,true)){
                self::need((bool)preg_match('/^\s*\(([0-9]+),\s*([0-9]+)\)/',$match[3],$size)
                    &&(int)$size[1]>=1&&(int)$size[1]<=65&&(int)$size[2]<=(int)$size[1]);
                $scale=(int)$size[2];
            }
            // SHOW CREATE resolves FLOAT(p>24) and REAL aliases to their
            // physical type. Also handle the single-argument FLOAT(p) syntax
            // defensively; FLOAT(M,D) remains a single-precision column.
            $floatBits=$type==='float'?32:64;
            if($type==='float'&&preg_match('/^\s*\(([0-9]+)\)/',$match[3],$precision)){
                self::need((int)$precision[1]<=53);$floatBits=(int)$precision[1]>24?64:32;
            }
            $columns[$match[1]]=['type'=>$type,'scale'=>$scale,'float_bits'=>$floatBits,'unsigned'=>(bool)preg_match('/\bunsigned\b/i',$match[3])];
        }
        self::need(count($columns)>0&&count($columns)<=2048);return $columns;
    }
    private static function integer($value,bool $unsigned): string
    {
        if(is_bool($value))$value=$value?'1':'0';
        if(is_int($value))$value=(string)$value;
        if(is_float($value)){
            self::need(is_finite($value)&&abs($value)<=9007199254740991&&floor($value)===$value);
            $value=sprintf('%.0F',$value);
        }
        self::need(is_string($value)&&strlen($value)<=100&&(bool)preg_match('/^[+-]?[0-9]+$/D',$value));
        $negative=$value[0]==='-';$digits=ltrim(ltrim($value,'+-'),'0');$digits=$digits===''?'0':$digits;
        self::need(!$unsigned||!$negative||$digits==='0');return $negative&&$digits!=='0'?'-'.$digits:$digits;
    }
    private static function decimal($value,int $scale,bool $unsigned): string
    {
        if(is_int($value))$value=(string)$value;
        if(is_float($value)){self::need(is_finite($value));$value=sprintf('%.*F',$scale,$value);}
        self::need(is_string($value)&&strlen($value)<=200&&(bool)preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?$/D',$value,$parts));
        $whole=ltrim($parts[2],'0');$whole=$whole===''?'0':$whole;$fraction=rtrim($parts[3]??'','0');
        self::need(strlen($fraction)<=$scale);$negative=$parts[1]==='-'&&($whole!=='0'||$fraction!=='');
        self::need(!$unsigned||!$negative);return ($negative?'-':'').$whole.($fraction!==''?'.'.$fraction:'');
    }
    private static function floatNumber($value,array $column): float
    {
        self::need(is_int($value)||is_float($value)||(is_string($value)&&strlen($value)<=200
            &&(bool)preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D',$value)));
        $number=(float)$value;self::need(is_finite($number));
        if(is_string($value)&&$number===0.0)self::need(!(bool)preg_match('/[1-9]/',preg_split('/[eE]/',$value)[0]));
        self::need(!$column['unsigned']||$number>=0);
        if($column['float_bits']===32){
            $rounded=unpack('Gvalue',pack('G',$number))['value'];
            self::need(is_finite($rounded)&&($number===0.0||$rounded!==0.0));$number=$rounded;
        }
        return $number===0.0?0.0:$number;
    }
    private static function value($value,array $column): string
    {
        if($value===null)return 'null';
        if(in_array($column['type'],self::INTEGERS,true))return 'integer:'.self::integer($value,$column['unsigned']);
        if(in_array($column['type'],self::DECIMALS,true))return 'decimal:'.self::decimal($value,$column['scale'],$column['unsigned']);
        if(in_array($column['type'],self::FLOATS,true)){
            $number=self::floatNumber($value,$column);
            // Hash the SQL storage precision, not PDO's PHP representation.
            // Text 1.23 and its widened binary32 value identify the same FLOAT.
            $format=$column['float_bits']===32?'G':'E';
            return 'float'.$column['float_bits'].':'.bin2hex(pack($format,$number));
        }
        self::need(is_string($value)||is_int($value)||is_bool($value));
        return 'string:'.(is_bool($value)?($value?'1':'0'):(string)$value);
    }
    public static function rowHash(string $table,array $row,int $actor,string $ddl,?array $projection=null): string
    {
        return self::hashRow($table,$row,$actor,self::columns($table,$ddl),$projection);
    }
    private static function hashRow(string $table,array $row,int $actor,array $columns,?array $projection): string
    {
        self::need($actor>0);
        self::need(array_diff(array_keys($row),array_keys($columns))===[]);
        $selected=$projection??array_keys($columns);
        self::need(count($selected)>0&&count($selected)<=count($columns));
        foreach($selected as $name)self::need(is_string($name)&&isset($columns[$name])&&array_key_exists($name,$row));
        self::need(count($selected)===count(array_unique($selected,SORT_STRING)));
        sort($selected,SORT_STRING);$hash=hash_init('sha256');hash_update($hash,"dashboard-delta-row-v1\0".$table."\0");
        foreach($selected as $name){
            $value=$row[$name];
            // Bootstrap deliberately gives every other user's offline password
            // a random invalid marker. Its randomness is not a business change.
            if($table==='users'&&$name==='password'&&self::integer($row['id']??null,false)!==(string)$actor)$value='!desktop-disabled';
            $encoded=self::value($value,$columns[$name]);hash_update($hash,$name."\0".strlen($encoded).':'.$encoded."\0");
        }
        return hash_final($hash);
    }
    private static function safeRow(string $table,array $row,int $actor,?array $projection=null): array
    {
        if($projection!==null)$row=array_intersect_key($row,array_flip($projection));
        if($table==='users'&&array_key_exists('password',$row)&&self::integer($row['id']??null,false)!==(string)$actor)
            $row['password']='!desktop-disabled';
        return $row;
    }
    private static function transportRow(string $table,array $row,int $actor,array $columns,?array $projection=null): array
    {
        $row=self::safeRow($table,$row,$actor,$projection);
        foreach($row as $name=>$value)if($value!==null&&isset($columns[$name])&&in_array($columns[$name]['type'],self::FLOATS,true)){
            $number=self::floatNumber($value,$columns[$name]);
            // This protocol carries SQL float values as lossless numeric strings.
            // The staging SQL column restores its native type. PHP's ordinary
            // JSON encoder can otherwise truncate a float before the receiver
            // sees it when web serialize_precision differs from desktop PHP.
            $row[$name]=sprintf('%.17H',$number===0.0?0.0:$number);
        }
        return $row;
    }
    public static function tableHash(array $hashes): string
    {
        $total=0;foreach($hashes as $digest=>$count){
            self::need(is_string($digest)&&(bool)preg_match('/^[a-f0-9]{64}$/D',$digest)&&is_int($count)&&$count>0);
            $total+=$count;self::need($total<=self::MAX_ROWS);
        }
        ksort($hashes,SORT_STRING);$hash=hash_init('sha256');hash_update($hash,"dashboard-delta-table-v1\0");
        foreach($hashes as $digest=>$count)hash_update($hash,$digest.':'.$count."\n");return hash_final($hash);
    }
    public static function validateIndex(array $index): void
    {
        self::need(self::exact($index,['schema_sha256','count','hashes'])
            &&is_string($index['schema_sha256'])&&(bool)preg_match('/^[a-f0-9]{64}$/D',$index['schema_sha256'])
            &&is_int($index['count'])&&$index['count']>=0&&$index['count']<=self::MAX_ROWS&&is_array($index['hashes']));
        self::tableHash($index['hashes']);self::need(array_sum($index['hashes'])===$index['count']);
    }
    /** Projection is derived from authenticated snapshot coverage, never a request field. */
    public static function projection(string $table,array $coverage): ?array
    {
        if($table!=='pending_vendors'||!array_key_exists('pending_vendor_eligibility',$coverage))return null;
        $value=$coverage['pending_vendor_eligibility'];
        $fields=['id','profession_key','type','status','source_app'];$redacted=['full_name','mobile'];
        self::need(is_array($value)&&($value['kind']??null)==='go-store-owner-only'
            &&($value['source_fields']??null)===$fields&&($value['redacted_required_fields']??null)===$redacted);
        return array_merge($fields,$redacted);
    }
    public static function index(string $table,array $rows,int $actor,string $ddl,?array $projection=null): array
    {
        self::need(count($rows)<=self::MAX_ROWS);$hashes=[];$columns=self::columns($table,$ddl);
        foreach($rows as $row){self::need(is_array($row));$hash=self::hashRow($table,$row,$actor,$columns,$projection);$hashes[$hash]=($hashes[$hash]??0)+1;}
        ksort($hashes,SORT_STRING);return ['schema_sha256'=>self::schemaHash($ddl),'count'=>count($rows),'hashes'=>$hashes];
    }
    public static function delta(string $table,array $rows,int $actor,string $ddl,array $before,?array $projection=null): array
    {
        self::validateIndex($before);$after=self::index($table,$rows,$actor,$ddl,$projection);
        if(!hash_equals($before['schema_sha256'],$after['schema_sha256'])){
            // A changed structure replaces only this table in the new private
            // staging database. The active database is never altered here.
            $columns=self::columns($table,$ddl);
            return ['remove'=>$before['hashes'],
                'add'=>array_map(fn($row)=>self::transportRow($table,$row,$actor,$columns,$projection),$rows),
                'target_hash'=>self::tableHash($after['hashes']),'count'=>$after['count'],'replace'=>true];
        }
        $remove=[];$needed=[];$add=[];
        foreach($before['hashes'] as $digest=>$count){$difference=$count-($after['hashes'][$digest]??0);if($difference>0)$remove[$digest]=$difference;}
        foreach($after['hashes'] as $digest=>$count){$difference=$count-($before['hashes'][$digest]??0);if($difference>0)$needed[$digest]=$difference;}
        $columns=self::columns($table,$ddl);
        foreach($rows as $row){$digest=self::hashRow($table,$row,$actor,$columns,$projection);if(($needed[$digest]??0)>0){$add[]=self::transportRow($table,$row,$actor,$columns,$projection);$needed[$digest]--;}}
        return ['remove'=>$remove,'add'=>$add,'target_hash'=>self::tableHash($after['hashes']),'count'=>$after['count']];
    }
    public static function apply(string $table,array $rows,int $actor,string $ddl,array $delta,?array $projection=null): array
    {
        self::need(self::exact($delta,['remove','add','target_hash','count'])&&is_array($delta['remove'])&&is_array($delta['add'])
            &&is_string($delta['target_hash'])&&(bool)preg_match('/^[a-f0-9]{64}$/D',$delta['target_hash'])
            &&is_int($delta['count'])&&$delta['count']>=0&&$delta['count']<=self::MAX_ROWS);
        self::tableHash($delta['remove']);self::need(count($rows)<=self::MAX_ROWS&&count($delta['add'])<=self::MAX_ROWS);
        $remove=$delta['remove'];$result=[];$columns=self::columns($table,$ddl);
        foreach($rows as $row){self::need(is_array($row));$digest=self::hashRow($table,$row,$actor,$columns,$projection);
            if(($remove[$digest]??0)>0){$remove[$digest]--;continue;}$result[]=$row;
        }
        self::need(array_sum($remove)===0);
        foreach($delta['add'] as $row){self::need(is_array($row));self::hashRow($table,$row,$actor,$columns,$projection);$result[]=$row;self::need(count($result)<=self::MAX_ROWS);}
        self::need(count($result)===$delta['count']);$index=self::index($table,$result,$actor,$ddl,$projection);
        self::need(hash_equals($delta['target_hash'],self::tableHash($index['hashes'])));
        return array_map(fn($row)=>self::safeRow($table,$row,$actor,$projection),$result);
    }
    /**
     * The caller first verifies actual captured rows using their ORIGINAL DDL
     * and projection. This method binds complete removal to that verified
     * index, then validates new rows exclusively with the target schema.
     * Only a trusted caller comparing its two coverage manifests can authorize
     * a projection-only transition without a structural replacement marker.
     */
    public static function applyReplacement(string $table,array $rows,int $actor,string $ddl,array $delta,array $before,?array $projection=null,bool $projectionChanged=false): array
    {
        self::validateIndex($before);
        $keys=['remove','add','target_hash','count'];
        if(array_key_exists('replace',$delta)){$keys[]='replace';self::need($delta['replace']===true);}
        else self::need($projectionChanged);
        self::need(self::exact($delta,$keys)&&is_array($delta['remove'])&&is_array($delta['add'])
            &&$rows===$delta['add']&&is_string($delta['target_hash'])
            &&(bool)preg_match('/^[a-f0-9]{64}$/D',$delta['target_hash'])
            &&is_int($delta['count'])&&$delta['count']>=0&&$delta['count']<=self::MAX_ROWS);
        self::tableHash($delta['remove']);
        $expected=$before['hashes'];$removed=$delta['remove'];ksort($expected,SORT_STRING);ksort($removed,SORT_STRING);
        self::need($removed===$expected&&count($rows)===$delta['count']);
        $after=self::index($table,$rows,$actor,$ddl,$projection);
        self::need(hash_equals($delta['target_hash'],self::tableHash($after['hashes'])));
        return array_map(fn($row)=>self::safeRow($table,$row,$actor,$projection),$rows);
    }
}
