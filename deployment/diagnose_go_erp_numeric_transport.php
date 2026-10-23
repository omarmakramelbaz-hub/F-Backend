<?php
// Constant synthetic values only. No Laravel bootstrap, credentials, account rows or database connection.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

final class GoErpNumericTransportDiagnostic
{
    public static function rows(bool $scale=true): array
    {
        $cases=[
            'float_point_one'=>[['value'=>0.1]],
            'float32_point_nine'=>[['value'=>unpack('g',pack('g',0.9))[1]]],
            'fraction'=>[['value'=>0.19999999999999998]],
            'coordinates'=>[['value'=>35.123456789012345]],
            'negative_zero'=>[['value'=>-0.0]],
            'exponents'=>[['small'=>1.0e-7,'large'=>1.0e21]],
            'integral_float'=>[['value'=>1.0]],
            'decimal_string'=>[['value'=>'12345678901234567890.12345678']],
            'large_integer'=>[['value'=>9007199254740993]],
            'unicode'=>[['value'=>'بيانات اختبار','url'=>'https://example.invalid/fixture']],
        ];
        if($scale){
            $cases['synthetic_11132_users']=[];
            for($i=1;$i<=11132;$i++)$cases['synthetic_11132_users'][]=['id'=>$i,'synthetic_value'=>0.1];
        }
        return $cases;
    }

    public static function settings(int $serialize,int $precision,callable $run)
    {
        $before=['serialize_precision'=>ini_get('serialize_precision'),'precision'=>ini_get('precision')];
        try{
            foreach(['serialize_precision'=>$serialize,'precision'=>$precision] as $name=>$value){
                if((string)ini_get($name)!==(string)$value&&@ini_set($name,(string)$value)===false)
                    throw new RuntimeException('Synthetic encoder setting is unavailable.');
                if((string)ini_get($name)!==(string)$value)throw new RuntimeException('Synthetic encoder setting did not apply.');
            }
            return $run();
        }finally{
            foreach($before as $name=>$value)if(ini_get($name)!==$value)ini_set($name,$value);
        }
    }

    public static function streamHash(array $rows): string
    {
        $hash=hash_init('sha256');hash_update($hash,'[');
        foreach($rows as $index=>$row){
            if($index)hash_update($hash,',');
            hash_update($hash,\App\Services\Dashboard\DesktopDashboardBootstrap::json($row));
        }
        hash_update($hash,']');return hash_final($hash);
    }

    public static function wire(array $cases): string
    {
        $tables=[];
        foreach($cases as $name=>$rows)$tables[$name]=['rows'=>$rows,'sha256'=>self::streamHash($rows)];
        return \App\Services\Dashboard\DesktopDashboardBootstrap::json([
            'kind'=>'go-erp-numeric-transport-fixture','synthetic_only'=>true,'tables'=>$tables,
        ]);
    }

    public static function inspect(string $wire): array
    {
        $snapshot=json_decode($wire,true,512,JSON_THROW_ON_ERROR);$matches=[];
        foreach($snapshot['tables'] as $name=>$part){
            // The exact pre-write table verification boundary from DesktopDashboardImport.
            $matches[$name]=hash_equals($part['sha256'],hash('sha256',
                \App\Services\Dashboard\DesktopDashboardBootstrap::json($part['rows'])));
        }
        $changed=$snapshot['tables']['float_point_one'];$changed['rows'][0]['value']=0.2;
        $tamperRejected=!hash_equals($changed['sha256'],hash('sha256',
            \App\Services\Dashboard\DesktopDashboardBootstrap::json($changed['rows'])));
        return [
            'matches'=>$matches,'tampered_row_rejected'=>$tamperRejected,
            'negative_zero_preserved'=>\App\Services\Dashboard\DesktopDashboardBootstrap::json($snapshot['tables']['negative_zero']['rows'][0]['value'])==='-0.0',
            'decimal_string_exact'=>$snapshot['tables']['decimal_string']['rows'][0]['value']==='12345678901234567890.12345678',
            'large_integer_exact'=>$snapshot['tables']['large_integer']['rows'][0]['value']===9007199254740993,
            'integral_float_preserved'=>is_float($snapshot['tables']['integral_float']['rows'][0]['value']),
            'point_one_json'=>\App\Services\Dashboard\DesktopDashboardBootstrap::json($snapshot['tables']['float_point_one']['rows']),
        ];
    }

    public static function report(): array
    {
        $configured=['serialize_precision'=>ini_get('serialize_precision'),'precision'=>ini_get('precision')];
        $cases=self::rows();$matrix=[];$wholeMatches=[];
        foreach([-1,17,14] as $server){
            $wire=self::settings($server,14,fn()=>self::wire($cases));
            $wholeMatches[(string)$server]=self::settings($server,14,function()use($cases){
                foreach($cases as $rows)if(!hash_equals(self::streamHash($rows),hash('sha256',
                    \App\Services\Dashboard\DesktopDashboardBootstrap::json($rows))))return false;
                return true;
            });
            foreach([-1,17,14] as $desktop){
                $observed=self::settings($desktop,14,fn()=>self::inspect($wire));
                $matrix[]=['server_serialize_precision'=>$server,'desktop_serialize_precision'=>$desktop,
                    'wire_bytes'=>strlen($wire),'failed_tables'=>array_keys(array_filter($observed['matches'],fn($ok)=>!$ok))]
                    +array_diff_key($observed,['matches'=>true]);
            }
        }
        $generic=[];
        foreach([3,14,100] as $precision)$generic[(string)$precision]=self::settings(-1,$precision,
            fn()=>hash('sha256',self::wire($cases)));
        return ['result'=>'GO_ERP_SYNTHETIC_NUMERIC_TRANSPORT','php_version'=>PHP_VERSION,'php_sapi'=>PHP_SAPI,
            'synthetic_only'=>true,'real_account_read'=>false,'database_connected'=>false,'synthetic_users'=>11132,
            'configured_ini'=>$configured,'ini_restored'=>$configured===['serialize_precision'=>ini_get('serialize_precision'),'precision'=>ini_get('precision')],
            'stream_hash_matches_whole_table'=>$wholeMatches,'generic_precision_control'=>$generic,'matrix'=>$matrix];
    }
}

try{
    if(PHP_INT_SIZE!==8||count($argv)>2||isset($argv[1])&&$argv[1]!=='--fixture-wire')
        throw new RuntimeException('Unsupported synthetic probe invocation.');
    require_once dirname(__DIR__).'/app/Services/Dashboard/DesktopDashboardBootstrap.php';
    if(($argv[1]??null)==='--fixture-wire')echo GoErpNumericTransportDiagnostic::wire(GoErpNumericTransportDiagnostic::rows(false)).PHP_EOL;
    else echo json_encode(GoErpNumericTransportDiagnostic::report(),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){
    fwrite(STDERR,"GO_ERP_SYNTHETIC_NUMERIC_TRANSPORT_FAILED\n");exit(1);
}
