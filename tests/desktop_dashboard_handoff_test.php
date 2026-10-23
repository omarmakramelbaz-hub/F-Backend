<?php
// Synthetic CLI state: no server, credentials, account data, or production SQL.
namespace Illuminate\Support\Facades {
    final class Validator {public static function make($data,$rules){return new class($data){public function __construct(private array $data){}public function validate(){return $this->data;}};}}
    final class DB {
        public static array $tables=[],$queries=[];public static int $level=0,$replayReads=0;public static bool $fenced=false;
        public static string $database='fasakhansta_dashboard_stage_2222222222222222',$host='127.0.0.1';public static object $source;
        public static function connection(){return new class{public function getConfig($key){return DB::$host;}public function getDatabaseName(){return DB::$database;}};}
        public static function transactionLevel(){return self::$level;}
        public static function transaction($work){self::$level++;try{return $work();}finally{self::$level--;}}
        public static function table($name){return new \FakeQuery($name);}
        public static function selectOne($sql,$bindings=[]){
            self::$queries[]=$sql;
            if(self::$fenced&&preg_match('/COUNT|information_schema|INSERT|SELECT.*desktop_dashboard_commands/i',$sql))throw new \RuntimeException('History SQL inside fence: '.$sql);
            if(str_contains($sql,'desktop_dashboard_local_state'))return clone self::$source;
            if(str_contains($sql,'COUNT'))return (object)['total'=>0];
            throw new \RuntimeException('Unexpected SQL');
        }
        public static function statement($sql,$bindings=[]){self::$queries[]=$sql;if(self::$fenced)throw new \RuntimeException('Copy SQL inside fence');return true;}
        public static function select($sql,$bindings=[]){
            if(self::$fenced)throw new \RuntimeException('Replay scan inside fence');
            if(!str_contains($sql,'desktop_dashboard_archived_commands')||!str_ends_with($sql,'ORDER BY device_id,command_id LIMIT 1000'))throw new \RuntimeException('Unbounded replay read');
            self::$replayReads++;
            $rows=array_filter(self::$tables['desktop_dashboard_archived_commands'],fn($row)=>!$bindings||$row->device_id>$bindings[0]||($row->device_id===$bindings[1]&&$row->command_id>$bindings[2]));
            return array_slice(array_values($rows),0,1000);
        }
    }
}
namespace App\Services\Dashboard {
    final class DesktopDashboardImport {
        public int $calls=0;
        public function verify(array $receipt,?array $seal=null):array{
            if(\Illuminate\Support\Facades\DB::$fenced)throw new \RuntimeException('Full source/count verification inside fence');
            $this->calls++;if($seal===null)DesktopDashboardMedia::verifyFiles($receipt['media']);else DesktopDashboardMediaSeal::verify($receipt['media'],$seal);
            return $receipt+['verified'=>true];
        }
    }
    final class DesktopDashboardPrintLedger {
        public int $fences=0,$enrollments=0;
        public function fence($device){\check(\Illuminate\Support\Facades\DB::transactionLevel()>0,'Print scope lock owns transaction');$this->fences++;}
        public function verifyEnrollment($device){$this->enrollments++;}
    }
    final class DesktopDashboardDevices {public function enrollmentFingerprint(object $device):string{return hash('sha256',json_encode($device));}}
}
namespace {
use Illuminate\Support\Facades\DB;
use App\Services\Dashboard\{DesktopDashboardHandoff as Handoff,DesktopDashboardArchive as Archive,DesktopDashboardBootstrap as Bootstrap};
final class FakeQuery {
    private array $where=[];
    public function __construct(private string $name){}
    public function where($key,$value){$this->where[$key]=$value;return $this;}
    public function orderBy($key){return $this;}public function lockForUpdate(){return $this;}
    private function rows(){return array_filter(DB::$tables[$this->name]??[],fn($row)=>array_all($this->where,fn($value,$key)=>($row->$key??null)===$value));}
    public function first($columns=['*']){DB::$queries[]=$this->name.'.first';$rows=$this->rows();return $rows?clone reset($rows):null;}
    public function exists(){DB::$queries[]=$this->name.'.exists';return count($this->rows())>0;}
    public function cursor(){if(DB::$fenced)throw new RuntimeException('Replay scan inside fence');DB::$replayReads++;foreach($this->rows() as $row)yield clone $row;}
    public function count(){if(DB::$fenced)throw new RuntimeException('Row count inside fence');return count($this->rows());}
}
// PHP 8.2/8.3 test compatibility; this helper is only for the synthetic facade.
if(!function_exists('array_all')){function array_all(array $values,callable $check):bool{foreach($values as $key=>$value)if(!$check($value,$key))return false;return true;}}
if(!function_exists('mb_strtolower')){function mb_strtolower($value){return strtolower($value);}}
function abort_unless($value,$status,$message=''){if(!$value)throw new RuntimeException($message?:'Refused',$status);}
function abort_if($value,$status,$message=''){abort_unless(!$value,$status,$message);}
function app($name){return $GLOBALS['services'][$name]??=new $name;}
function base_path(){return $GLOBALS['handoff_app'];}
function storage_path($relative=''){return $GLOBALS['handoff_root'].($relative?'/'.$relative:'');}
function config($key){return match($key){'desktop_dashboard.local'=>$GLOBALS['local'],'desktop_dashboard.device_id'=>$GLOBALS['device'],
    'filesystems.disks.local.driver'=>'local','filesystems.disks.local.root'=>storage_path('app'),default=>null};}
function check($value,$message){if(!$value)throw new RuntimeException($message);$GLOBALS['checks']++;}
function refuses(callable $work,$message){try{$work();}catch(RuntimeException $error){check(in_array($error->getCode(),[403,409],true),$message.' status');check(!str_contains($error->getMessage(),'synthetic-account-secret'),$message.' data-safe');return;}throw new RuntimeException('Expected refusal: '.$message);}
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardBootstrap.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardMedia.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardMediaSeal.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardHandoff.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardArchive.php';
$GLOBALS['checks']=0;$GLOBALS['local']=true;$GLOBALS['services']=[];
$GLOBALS['device']=$device='11111111-1111-4111-8111-111111111111';
$root=sys_get_temp_dir().'/go-handoff-'.bin2hex(random_bytes(8));$GLOBALS['handoff_root']=$root;$GLOBALS['handoff_app']=__DIR__;
mkdir($root.'/app/public/products',0700,true);file_put_contents($root.'/app/public/products/photo.png','synthetic attachment');
$receipt=['format'=>1,'device_id'=>$device,'actor_id'=>7,'snapshot_id'=>'33333333-3333-4333-8333-333333333333',
    'schema_hash'=>str_repeat('a',64),'source'=>['format'=>1,'sha256'=>str_repeat('b',64)],'branches'=>['f:1'],
    'coverage'=>['print_protocol'=>1],'tables'=>119,'rows'=>500000,'table_rows'=>['users'=>11132],
    'media'=>[['path'=>'products/photo.png','mime'=>'image/png','bytes'=>20,'sha256'=>hash('sha256','synthetic attachment')]]];
$old='fasakhansta_dashboard_stage_1111111111111111';$captureToken=str_repeat('c',64);$refreshToken=str_repeat('d',64);$id='44444444-4444-4444-8444-444444444444';
$rows=[];
for($i=1;$i<=12001;$i++)$rows[]=(object)['device_id'=>$device,'command_id'=>sprintf('%08x-5555-4555-8555-555555555555',$i),'actor_id'=>7,
    'route_name'=>'takeaway.checkout','request_hash'=>str_repeat('e',64),'source_database'=>$old];
$hash=hash_init('sha256');hash_update($hash,"desktop-dashboard-replays:1\n");foreach($rows as $row)hash_update($hash,json_encode((array)$row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
$receipt['delta_replay']=['format'=>1,'source_database'=>$old,'sequence'=>13000,'rows'=>count($rows),'sha256'=>hash_final($hash)];
DB::$tables=[
    'desktop_dashboard_local_state'=>[(object)['device_id'=>$device,'actor_id'=>7,'snapshot_id'=>$receipt['snapshot_id'],'schema_hash'=>$receipt['schema_hash'],
        'branches'=>Bootstrap::json($receipt['branches']),'coverage'=>Bootstrap::json($receipt['coverage']),'state'=>'ready']],
    'desktop_dashboard_source_manifest'=>[(object)['device_id'=>$device,'snapshot_id'=>$receipt['snapshot_id'],'sha256'=>hash('sha256',Bootstrap::json($receipt['source']))]],
    'desktop_dashboard_media_manifest'=>[(object)['device_id'=>$device,'snapshot_id'=>$receipt['snapshot_id'],'sha256'=>hash('sha256',Bootstrap::json($receipt['media']))]],
    'desktop_dashboard_devices'=>[(object)['id'=>$device,'actor_id'=>7,'enabled'=>true,'branches'=>Bootstrap::json(['f:1']),'enrollment_hash'=>str_repeat('9',64)]],
    'users'=>[(object)['id'=>7]],'desktop_dashboard_archived_commands'=>$rows];
DB::$source=(object)['device_id'=>$device,'actor_id'=>7,'snapshot_id'=>'66666666-6666-4666-8666-666666666666',
    'schema_hash'=>str_repeat('f',64),'state'=>'ready','refresh_id'=>$id,'refresh_token_hash'=>hash('sha256',$refreshToken),'fenced_sequence'=>13000];
putenv('DESKTOP_DASHBOARD_LOCAL=true');
try{
    if(PHP_SAPI!=='cli'){
        refuses(fn()=>(new Handoff)->capture($receipt,$captureToken),'HTTP cannot issue a private handoff');
        check(DB::$queries===[]&&app(App\Services\Dashboard\DesktopDashboardImport::class)->calls===0,'HTTP refuses before SQL or full verification');
        echo 'PASS HTTP private handoff refused'.PHP_EOL;return;
    }
    $handoff=new Handoff;$checked=$handoff->capture($receipt,$captureToken);$seal=$checked['media_seal'];$proof=$checked['archive_handoff'];
    unset($checked['media_seal'],$checked['archive_handoff']);$identity=$proof['payload']['source_identity'];
    check(app(App\Services\Dashboard\DesktopDashboardImport::class)->calls===1&&DB::$replayReads===13,'Full source/database and bounded replay pages run before fence');
    check($proof['payload']['replay']===$receipt['delta_replay']&&$proof['payload']['sequence']===13000,'Exact captured replay projection signed');
    check(!str_contains(json_encode($proof),$captureToken)&&!str_contains(json_encode($proof),'synthetic attachment'),'Proof contains neither private key nor attachment bytes');
    $calls=app(App\Services\Dashboard\DesktopDashboardImport::class)->calls;$reads=DB::$replayReads;
    DB::$source->state='held';DB::$fenced=true;DB::$queries=[];
    $archive=new Archive;$result=$archive->seal($checked,$old,$id,$refreshToken,$identity,$seal,$proof,$captureToken);
    check($result===['archived_commands'=>12001],'Large replay history uses signed prestaged count');
    check($calls===app(App\Services\Dashboard\DesktopDashboardImport::class)->calls&&$reads===DB::$replayReads,'No source hash/count/replay scan/copy under fence');
    check(count(array_filter(DB::$queries,fn($sql)=>str_contains($sql,'desktop_dashboard_archived_commands')))===0,'No historical replay query under fence');
    check(app(App\Services\Dashboard\DesktopDashboardPrintLedger::class)->enrollments>=2,'Print enrollment and scope are still verified');
    $before=count(DB::$queries);$bad=$proof;$bad['signature']=str_repeat('0',64);refuses(fn()=>$handoff->verify($checked,$seal,$bad,$captureToken,$old,$identity),'Forged proof');
    check(count(DB::$queries)===$before,'HMAC failure occurs before SQL');
    refuses(fn()=>$handoff->verify($checked,$seal,$proof,str_repeat('0',64),$old,$identity),'Wrong private key');
    $bad=$proof;$bad['kind']='ordinary-http-receipt';refuses(fn()=>$handoff->verify($checked,$seal,$bad,$captureToken,$old,$identity),'Different proof domain');
    $bad=$checked;$bad['rows']++;refuses(fn()=>$handoff->verify($bad,$seal,$proof,$captureToken,$old,$identity),'Changed receipt');
    $bad=$checked;unset($bad['verified']);refuses(fn()=>$handoff->verify($bad,$seal,$proof,$captureToken,$old,$identity),'Unverified receipt');
    $bad=$checked;$bad['delta_replay']['sequence']++;refuses(fn()=>$handoff->verify($bad,$seal,$proof,$captureToken,$old,$identity),'Changed captured sequence');
    $bad=$seal;$bad['files'][0]['identity']['ino']++;refuses(fn()=>$handoff->verify($checked,$bad,$proof,$captureToken,$old,$identity),'Changed media proof');
    refuses(fn()=>$handoff->verify($checked,$seal,$proof,$captureToken,'fasakhansta_dashboard',$identity),'Different old database');
    $bad=$identity;$bad['schema_hash']=str_repeat('0',64);refuses(fn()=>$handoff->verify($checked,$seal,$proof,$captureToken,$old,$bad),'Different old identity');
    DB::$source->fenced_sequence++;refuses(fn()=>$archive->seal($checked,$old,$id,$refreshToken,$identity,$seal,$proof,$captureToken),'Sale during download changes sequence');DB::$source->fenced_sequence--;
    $saved=DB::$source->snapshot_id;DB::$source->snapshot_id=$receipt['snapshot_id'];refuses(fn()=>$archive->seal($checked,$old,$id,$refreshToken,$identity,$seal,$proof,$captureToken),'Old snapshot changed after signing');DB::$source->snapshot_id=$saved;
    refuses(fn()=>$archive->seal($checked,$old,$id,str_repeat('0',64),$identity,$seal,$proof,$captureToken),'Wrong durable refresh capability');
    DB::$tables['desktop_dashboard_commands']=[(object)['device_id'=>$device]];refuses(fn()=>$handoff->verify($checked,$seal,$proof,$captureToken,$old,$identity),'Candidate accepted a command');DB::$tables['desktop_dashboard_commands']=[];
    DB::$tables['desktop_dashboard_devices'][0]->enrollment_hash=str_repeat('8',64);refuses(fn()=>$handoff->verify($checked,$seal,$proof,$captureToken,$old,$identity),'Candidate enrollment changed');DB::$tables['desktop_dashboard_devices'][0]->enrollment_hash=str_repeat('9',64);
    DB::$tables['desktop_dashboard_source_manifest'][0]->sha256=str_repeat('0',64);refuses(fn()=>$handoff->verify($checked,$seal,$proof,$captureToken,$old,$identity),'Candidate source manifest changed');DB::$tables['desktop_dashboard_source_manifest'][0]->sha256=hash('sha256',Bootstrap::json($receipt['source']));
    DB::$tables['desktop_dashboard_media_manifest'][0]->sha256=str_repeat('0',64);refuses(fn()=>$handoff->verify($checked,$seal,$proof,$captureToken,$old,$identity),'Candidate media manifest changed');DB::$tables['desktop_dashboard_media_manifest'][0]->sha256=hash('sha256',Bootstrap::json($receipt['media']));
    $GLOBALS['handoff_app']=dirname(__DIR__);refuses(fn()=>$handoff->verify($checked,$seal,$proof,$captureToken,$old,$identity),'Different retained runtime');$GLOBALS['handoff_app']=__DIR__;
    DB::$database='fasakhansta_dashboard_stage_3333333333333333';refuses(fn()=>$handoff->verify($checked,$seal,$proof,$captureToken,$old,$identity),'Different target generation');DB::$database='fasakhansta_dashboard_stage_2222222222222222';
    DB::$host='remote';refuses(fn()=>$handoff->verify($checked,$seal,$proof,$captureToken,$old,$identity),'Remote DB boundary');DB::$host='127.0.0.1';
    $GLOBALS['local']=false;refuses(fn()=>$handoff->verify($checked,$seal,$proof,$captureToken,$old,$identity),'Nonlocal configuration');$GLOBALS['local']=true;
    putenv('DESKTOP_DASHBOARD_LOCAL=false');refuses(fn()=>$handoff->verify($checked,$seal,$proof,$captureToken,$old,$identity),'Missing private CLI boundary');putenv('DESKTOP_DASHBOARD_LOCAL=true');
    file_put_contents($root.'/app/public/products/photo.png','changed attachment bytes');refuses(fn()=>$handoff->verify($checked,$seal,$proof,$captureToken,$old,$identity),'Attachment changed after verification');file_put_contents($root.'/app/public/products/photo.png','synthetic attachment');
    refuses(fn()=>$archive->seal($checked,$old,$id,$refreshToken,$identity,$seal,$proof,null),'Partial fast request cannot fall back');
    DB::$fenced=false;$archive->seal($checked,$old,$id,$refreshToken,$identity,$seal);
    check(app(App\Services\Dashboard\DesktopDashboardImport::class)->calls===$calls+1,'Ordinary archive retains full verification');
    check(count(array_filter(DB::$queries,fn($sql)=>str_starts_with($sql,'INSERT INTO')))===1,'Ordinary archive retains replay SQL copy');
    DB::$source->state='ready';$bad=$receipt;$bad['delta_replay']['sha256']=str_repeat('0',64);refuses(fn()=>$handoff->capture($bad,$captureToken),'Wrong prestaged replay digest');
    $bad=$receipt;$bad['archive_handoff']=[];refuses(fn()=>$handoff->capture($bad,$captureToken),'Caller receipt cannot shadow private proof');
    echo 'PASS '.$GLOBALS['checks'].' private preverified archive checks'.PHP_EOL;
}finally{
    unlink($root.'/app/public/products/photo.png');rmdir($root.'/app/public/products');rmdir($root.'/app/public');rmdir($root.'/app');rmdir($root);
}
}
