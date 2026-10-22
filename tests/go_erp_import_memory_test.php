<?php
/** Real Laravel validation and native 256 MiB import preflight; synthetic data only. */
use App\Services\Dashboard\{DesktopDashboardBootstrap as Canonical,DesktopDashboardImport as Importer,DesktopDashboardSchema,DesktopDashboardSource};
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\{DatabasePresenceVerifier,Factory};

$autoload=$argv[1]??'';
if(!is_file($autoload))throw new RuntimeException('Pass the runtime Composer autoloader as argument 1.');
require $autoload;
foreach(['Bootstrap','Import','Schema','Media','Source'] as $service)
    require_once dirname(__DIR__).'/app/Services/Dashboard/DesktopDashboard'.$service.'.php';

final class PreDdlFence extends RuntimeException {}
final class ImportRejected extends RuntimeException {}
final class DisconnectedDatabase implements ConnectionResolverInterface
{
    public int $fences=0;
    public function connection($name=null){if($name!==null)throw new RuntimeException('Unexpected connection.');return $this;}
    public function getDefaultConnection(){return 'disconnected';}
    public function setDefaultConnection($name){throw new RuntimeException('Connection mutation blocked.');}
    public function getConfig($name){if($name!=='host')throw new RuntimeException('Unexpected configuration.');return '127.0.0.1';}
    public function getDatabaseName(){return 'fasakhansta_dashboard_stage_0000000000000000';}
    public function select($query,$bindings=[]){if($query!=='SHOW TABLES'||$bindings!==[])throw new RuntimeException('Query blocked.');return [];}
    public function statement($query,$bindings=[]){
        if($query!=='SET FOREIGN_KEY_CHECKS=0'||$bindings!==[])throw new RuntimeException('Statement blocked.');
        ++$this->fences;throw new PreDdlFence;
    }
    public function __call($method,$arguments){throw new RuntimeException('Database operation blocked.');}
}
function check($condition,string $label): void {if(!$condition)throw new RuntimeException($label);}
function environment(): array
{
    $container=new class extends Container {
        public function abort($code,$message='',array $headers=[]){throw new ImportRejected('Import rejected.',(int)$code);}
    };
    Container::setInstance($container);Facade::clearResolvedInstances();Facade::setFacadeApplication($container);
    $container->instance('config',new Illuminate\Config\Repository(['desktop_dashboard'=>['local'=>true,'device_id'=>deviceId()]]));
    $translator=new class implements Illuminate\Contracts\Translation\Translator {
        public function get($key,array $replace=[],$locale=null){return $key;}
        public function choice($key,$number,array $replace=[],$locale=null){return $key;}
        public function getLocale(){return 'en';}
        public function setLocale($locale){}
    };
    $db=new DisconnectedDatabase;$container->instance('db',$db);
    $factory=new Factory($translator,$container);
    // Exercise the real typed framework constructor, too; no stub Validator or DB connection.
    $factory->setPresenceVerifier(new DatabasePresenceVerifier($db));
    $container->instance('validator',$factory);
    $container->instance(DesktopDashboardSource::class,new class extends DesktopDashboardSource {
        public function matches($value): bool {return $value===['synthetic'=>true];}
    });
    return [$factory,$db];
}
function deviceId(): string {return '11111111-1111-4111-8111-111111111111';}
function rootRules(): array {return [
    'format'=>'required|in:1','kind'=>'required|in:initial-dashboard-data',
    'snapshot_id'=>'required|uuid','device_id'=>'required|uuid','actor_id'=>'required|integer|min:1',
    'branches'=>'required|array|min:1','branches.*'=>['required','distinct','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],
    'schema_hash'=>'required|regex:/^[a-f0-9]{64}$/D','tables'=>'required|array','coverage'=>'required|array'];}
function enrollmentRules(): array {return ['enrollment.enrollment_hash'=>'required|regex:/^[a-f0-9]{64}$/D','enrollment.enrolled_at'=>'required|date'];}
function metadata(array $snapshot,bool $enrollment=false): array {
    static $method;$method??=new ReflectionMethod(Importer::class,'validationMetadata');$method->setAccessible(true);
    return $method->invoke(null,$snapshot,$enrollment?['enrollment']:['format','kind','snapshot_id','device_id','actor_id','branches','schema_hash','tables','coverage'],
        $enrollment?[]:['tables','coverage']);
}
function snapshotHeader(): array {return ['format'=>1,'kind'=>'initial-dashboard-data','snapshot_id'=>'22222222-2222-4222-8222-222222222222',
    'device_id'=>deviceId(),'actor_id'=>1,'branches'=>['f:1'],'source'=>['synthetic'=>true],
    'coverage'=>['full_dashboard'=>false],'media'=>[],
    'enrollment'=>['enrollment_hash'=>str_repeat('a',64),'enrolled_at'=>'2026-10-22 10:34:59','base_branches'=>['f:1']]];}
function ddl(string $name): string {
    $columns=['`id` bigint NOT NULL','`c00` double NULL','`c01` bigint NULL'];
    for($column=2;$column<32;++$column)$columns[]='`c'.sprintf('%02d',$column).'` varchar(64) NULL';
    return 'CREATE TABLE `'.$name.'` ('.implode(',',$columns).',PRIMARY KEY (`id`)) ENGINE=InnoDB';
}
function schemaHash(array $tables): string {
    $schema=[];foreach($tables as $name=>$part)$schema[$name]=hash('sha256',$part['ddl']);ksort($schema);
    return hash('sha256',Canonical::json($schema));
}
function smallSnapshot(): array {
    $tables=['users'=>['ddl'=>ddl('users'),'rows'=>[['id'=>1,'c00'=>1.0,'c01'=>9007199254740993]]]];
    $tables['users']['sha256']=hash('sha256',Canonical::json($tables['users']['rows']));
    return snapshotHeader()+['tables'=>$tables,'schema_hash'=>schemaHash($tables)];
}
function assertFence(array $snapshot,bool $accepted): void {
    [, $db]=environment();$rejected=false;
    try{(new Importer)->import($snapshot);throw new RuntimeException('Importer crossed the disconnected fence.');}
    catch(PreDdlFence $error){check($accepted&&$db->fences===1,'Unexpected pre-DDL acceptance.');return;}
    catch(ImportRejected|Illuminate\Validation\ValidationException $error){$rejected=true;}
    check(!$accepted&&$rejected&&$db->fences===0,'Expected rejection before the write fence.');
}
function semantics(): int {
    [$factory]=environment();$valid=smallSnapshot();$cases=[$valid];
    foreach(['tables','coverage'] as $key){
        $case=$valid;unset($case[$key]);$cases[]=$case;
        foreach([null,false,0,'x',[],['deep'=>['not'=>'a valid table']]] as $value){$case=$valid;$case[$key]=$value;$cases[]=$case;}
    }
    foreach([[],['f:0'],['f:1','f:1'],['unknown:1'],['gs:2','f:1']] as $value){$case=$valid;$case['branches']=$value;$cases[]=$case;}
    foreach(['actor_id'=>0,'device_id'=>'bad','schema_hash'=>'bad','kind'=>'bad'] as $key=>$value){$case=$valid;$case[$key]=$value;$cases[]=$case;}
    $count=0;
    foreach($cases as $case){
        $before=serialize($case);$original=$factory->make($case,rootRules());$projected=$factory->make(metadata($case),rootRules());
        check($original->fails()===$projected->fails()&&$original->failed()===$projected->failed(),'Root validation semantics changed.');
        check(serialize($case)===$before,'Root projection mutated its input.');++$count;
    }
    $cases=[$valid];$case=$valid;unset($case['enrollment']);$cases[]=$case;
    foreach([null,false,'x',[],['enrollment_hash'=>'bad','enrolled_at'=>'2026-10-22'],
        ['enrollment_hash'=>str_repeat('a',64),'enrolled_at'=>'bad']] as $value){$case=$valid;$case['enrollment']=$value;$cases[]=$case;}
    foreach($cases as $case){
        $before=serialize($case);$original=$factory->make($case,enrollmentRules());$projected=$factory->make(metadata($case,true),enrollmentRules());
        check($original->fails()===$projected->fails()&&$original->failed()===$projected->failed(),'Enrollment validation semantics changed.');
        check(serialize($case)===$before,'Enrollment projection mutated its input.');++$count;
    }
    assertFence($valid,true);
    $bad=$valid;$bad['tables']['users']['ddl'].='; DROP TABLE users';$bad['schema_hash']=schemaHash($bad['tables']);assertFence($bad,false);
    $bad=$valid;$bad['tables']['users']['rows'][0]['id']=2;assertFence($bad,false);
    $bad=$valid;$bad['schema_hash']=str_repeat('0',64);assertFence($bad,false);
    $bad=$valid;$bad['tables']['users']['rows'][]=$bad['tables']['users']['rows'][0];
    $bad['tables']['users']['sha256']=hash('sha256',Canonical::json($bad['tables']['users']['rows']));assertFence($bad,false);
    return $count;
}
function put($stream,string $value): void {check(fwrite($stream,$value)===strlen($value),'Synthetic fixture write failed.');}
function generateWire(string $path): array {
    $stream=fopen($path,'wb');check($stream!==false,'Synthetic fixture unavailable.');
    $names=array_merge(['users'],array_values(array_diff(DesktopDashboardSchema::TABLES,['users'])));
    check(count($names)===119,'The production table allowlist changed.');
    $head=Canonical::json(snapshotHeader());put($stream,substr($head,0,-1).',"tables":{');$schema=[];$rows=0;
    foreach($names as $index=>$name){
        if($index)put($stream,',');$ddl=ddl($name);$schema[$name]=hash('sha256',$ddl);
        put($stream,Canonical::json($name).':{"ddl":'.Canonical::json($ddl).',"rows":[');
        $hash=hash_init('sha256');hash_update($hash,'[');$total=$index<10?3400:0;
        for($id=1;$id<=$total;++$id){
            $row=['id'=>$id,'c00'=>1.0,'c01'=>9007199254740993];
            for($column=2;$column<32;++$column)$row['c'.sprintf('%02d',$column)]='synthetic-value';
            $json=Canonical::json($row);if($id>1){put($stream,',');hash_update($hash,',');}
            put($stream,$json);hash_update($hash,$json);++$rows;
        }
        hash_update($hash,']');put($stream,'],"sha256":'.Canonical::json(hash_final($hash)).'}');
    }
    ksort($schema);put($stream,'},"schema_hash":'.Canonical::json(hash('sha256',Canonical::json($schema))).'}');fclose($stream);
    clearstatcache(true,$path);$bytes=filesize($path);check($rows===34000&&$bytes>=24*1024*1024&&$bytes<=26*1024*1024,'Synthetic snapshot scale changed.');
    return ['tables'=>119,'rows'=>$rows,'wire_bytes'=>$bytes];
}
function child(string $mode,string $path): void {
    $reserve=str_repeat('r',1024*1024);$done=false;
    register_shutdown_function(function()use(&$reserve,&$done){
        $reserve=null;if($done)return;$error=error_get_last();
        if($error&&in_array($error['type'],[E_ERROR,E_CORE_ERROR,E_COMPILE_ERROR],true))
            echo json_encode(['classification'=>str_contains($error['message'],'Allowed memory size')?'memory-limit':'fatal','peak'=>memory_get_peak_usage(true)]).PHP_EOL;
    });
    try{
        [$factory]=environment();$stream=fopen($path,'rb');check($stream!==false,'Synthetic fixture unavailable.');$input='';
        while(!feof($stream)){$chunk=fread($stream,64*1024);check($chunk!==false,'Synthetic fixture read failed.');$input.=$chunk;}
        fclose($stream);$snapshot=json_decode($input,true,512,JSON_THROW_ON_ERROR);unset($input,$chunk);
        if($mode==='old-root')$factory->make($snapshot,rootRules())->validate();
        elseif($mode==='old-enrollment')$factory->make($snapshot,enrollmentRules())->validate();
        elseif($mode==='new'){
            try{(new Importer)->import($snapshot);throw new RuntimeException('Missing pre-DDL fence.');}catch(PreDdlFence $error){}
            $factory->make(metadata($snapshot,true),enrollmentRules())->validate();
        }else throw new RuntimeException('Unknown child mode.');
        $done=true;echo json_encode(['classification'=>'passed','peak'=>memory_get_peak_usage(true)]).PHP_EOL;
    }catch(Throwable $error){
        $done=true;echo json_encode(['classification'=>'exception','type'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()]).PHP_EOL;exit(1);
    }
}
function runChild(string $autoload,string $mode,string $path): array {
    $command=[PHP_BINARY];$ini=get_cfg_var('cfg_file_path');
    if(is_string($ini)&&is_file($ini))array_push($command,'-c',$ini);else $command[]='-n';
    array_push($command,'-d','memory_limit=256M','-d','display_errors=0','-d','log_errors=0',__FILE__,$autoload,'--child',$mode,$path);
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    check(is_resource($process),'Native PHP child unavailable.');fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    $result=json_decode(trim($output),true);check(is_array($result),'Native child did not return a bounded diagnostic.');
    check($stderr==='','Native child emitted unexpected diagnostics.');
    $result['exit']=$exit;return $result;
}

if(($argv[2]??'')==='--child'){child($argv[3]??'',$argv[4]??'');exit;}
$count=semantics();$path=tempnam(sys_get_temp_dir(),'go-erp-synthetic-import-');check(is_string($path),'Synthetic fixture unavailable.');@chmod($path,0600);
try{
    $scale=generateWire($path);$results=[];
    foreach(['old-root','old-enrollment','new'] as $mode)$results[$mode]=runChild(realpath($autoload),$mode,$path);
    check($results['old-root']['classification']==='memory-limit'&&$results['old-root']['exit']!==0,'Old root validation did not reproduce the 256 MiB regression: '.json_encode($results['old-root']));
    $oldEnrollment=$results['old-enrollment'];
    check(($oldEnrollment['classification']==='memory-limit'&&$oldEnrollment['exit']!==0)
        ||($oldEnrollment['classification']==='passed'&&$oldEnrollment['exit']===0&&$oldEnrollment['peak']-$results['new']['peak']>=32*1024*1024),
        'Old enrollment validation did not demonstrate the full-graph copy: '.json_encode($oldEnrollment));
    check($results['new']['classification']==='passed'&&$results['new']['exit']===0&&$results['new']['peak']<256*1024*1024,'Projected native import failed: '.json_encode($results['new']));
    $version=Illuminate\Foundation\Application::VERSION;
    echo json_encode(['result'=>'GO_ERP_IMPORT_MEMORY_TEST_PASSED','framework'=>$version,'equivalence_cases'=>$count]+$scale+['children'=>$results],JSON_UNESCAPED_SLASHES).PHP_EOL;
}finally{unlink($path);}
