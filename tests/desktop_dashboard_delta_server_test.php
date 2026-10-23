<?php
// Synthetic server/HTTP delta proofs. There is intentionally no DB facade or application bootstrap.
namespace Symfony\Component\HttpKernel\Exception {
    class HttpException extends \RuntimeException {function __construct(private int $status,string $message=''){parent::__construct($message);}function getStatusCode(){return $this->status;}}
}
namespace App\Http\Controllers {class Controller {}}
namespace Illuminate\Http {
    class Request {
        public int $reads=0;public bool $streamRead=false;public $stream;
        function __construct(public string $body,public $length=null,public string $token='fixture-device-token',public string $contentType='application/octet-stream'){}
        function bearerToken(){return $this->token;}
        function header($name){if($name==='Content-Type')return $this->contentType;if($name!=='Content-Length')throw new \RuntimeException('Unexpected header read.');return $this->length;}
        function getContent($resource=false){$this->reads++;$this->streamRead=$resource;if(!$resource)return $this->body;$file=fopen('php://temp','w+b');fwrite($file,$this->body);rewind($file);$this->stream=$file;return $file;}
    }
}
namespace App\Services\Dashboard {
    class DesktopDashboardDeltaCapacity {function run(callable $work){return $work();}}
    class DesktopDashboardSchema {public const TABLES=['products','users','pending_vendors'];}
    class DesktopDashboardDevices {
        public int $calls=0;
        function device($token){$this->calls++;\abort_unless($token==='fixture-device-token',401,'Fixed device refusal.');return \fixtureDevice();}
    }
    class DesktopDashboardSource {
        public int $calls=0;public bool $changed=false;
        function matches($value){$this->calls++;return $value===\fixtureSource()&&!($this->changed&&$this->calls>1);}
    }
    class DesktopDashboardBootstrap {
        public int $calls=0;public array $snapshot;
        function __construct(){$this->snapshot=\fixtureSnapshot();}
        function export($device){$this->calls++;\check($device->id===\fixtureDevice()->id,'Exporter received a foreign device.');return $this->snapshot;}
        static function json($value){return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
    }
}
namespace {
    function abort($status,$message=''){throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message);}
    function abort_unless($condition,$status,$message=''){if(!$condition)abort($status,$message);}
    function abort_if($condition,$status,$message=''){if($condition)abort($status,$message);}
    function config($name){if($name==='desktop_dashboard.local')return $GLOBALS['fixture_local'];throw new \RuntimeException('Unexpected runtime configuration access.');}
    function app($name){if($name===\App\Services\Dashboard\DesktopDashboardDeltaCapacity::class)return new $name;if($name===\App\Services\Dashboard\DesktopDashboardSource::class)return $GLOBALS['fixture_source'];throw new \RuntimeException('Unexpected application/DB dependency.');}
    function response($body,$status,$headers){return (object)compact('body','status','headers');}
    function check($condition,$message){if(!$condition)throw new \RuntimeException($message);}
    function blocked(callable $work,int $status): void {try{$work();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){check($error->getStatusCode()===$status,'Wrong refusal status.');check(!str_contains($error->getMessage(),'PRIVATE'),'Raw details leaked into a refusal.');return;}throw new \RuntimeException('Expected refusal was not raised.');}
    function fixtureDevice(){return (object)['id'=>'11111111-1111-4111-8111-111111111111','actor_id'=>7];}
    function fixtureSource(){return ['format'=>1,'files'=>123,'sha256'=>str_repeat('a',64),'framework'=>'8.83.29'];}
    function productDdl(){return "CREATE TABLE `products` (\n  `id` bigint unsigned NOT NULL,\n  `name` varchar(100) NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";}
    function usersDdl(){return "CREATE TABLE `users` (\n  `id` bigint unsigned NOT NULL,\n  `name` varchar(100) NOT NULL,\n  `password` varchar(255) NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";}
    function fixtureSnapshot(){return ['format'=>1,'kind'=>'initial-dashboard-data','snapshot_id'=>'22222222-2222-4222-8222-222222222222','device_id'=>fixtureDevice()->id,'actor_id'=>7,'branches'=>['f:1'],'source'=>fixtureSource(),
        'tables'=>['products'=>['ddl'=>productDdl(),'rows'=>[['id'=>1,'name'=>'Changed'],['id'=>2,'name'=>'Unchanged']],'sha256'=>'initial-wire-sha'],
            'users'=>['ddl'=>usersDdl(),'rows'=>[['id'=>7,'name'=>'Actor','password'=>'fixture-actor-hash'],['id'=>8,'name'=>'Other','password'=>'!desktop-disabled-new-fixture']],'sha256'=>'initial-wire-sha']],
        'media'=>[['path'=>'products/1/fixture.jpg','sha256'=>str_repeat('b',64),'bytes'=>10,'mime'=>'image/jpeg','ticket'=>'fixture-only-ticket']],
        'coverage'=>['full_dashboard'=>false,'media'=>true]];}
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardDeltaCodec.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardDelta.php';
    require __DIR__.'/../app/Http/Controllers/Api/DesktopDashboardController.php';
    use App\Services\Dashboard\DesktopDashboardDeltaCodec as Codec;
    function environment(){
        $GLOBALS['fixture_local']=false;$GLOBALS['fixture_source']=new \App\Services\Dashboard\DesktopDashboardSource;
        $bootstrap=new \App\Services\Dashboard\DesktopDashboardBootstrap;$delta=new \App\Services\Dashboard\DesktopDashboardDelta($bootstrap);
        $old=$bootstrap->snapshot;$old['tables']['products']['rows'][0]['name']='Old';$old['tables']['users']['rows'][1]['password']='!desktop-disabled-old-fixture';
        $index=['format'=>1,'kind'=>'dashboard-delta-index','device_id'=>fixtureDevice()->id,'actor_id'=>7,'source'=>fixtureSource(),'tables'=>[]];
        foreach($old['tables'] as $name=>$part)$index['tables'][$name]=Codec::index($name,$part['rows'],7,$part['ddl']);
        return [$bootstrap,$delta,$index];
    }
    $cases=[];
    $cases['scoped-diff-only']=static function(){[$bootstrap,$delta,$index]=environment();$result=$delta->export(fixtureDevice(),$index);
        check($bootstrap->calls===1&&$result['kind']==='dashboard-data-delta','Authorized export was bypassed.');
        check($result['tables']['products']['delta']['add']===[['id'=>1,'name'=>'Changed']],'Unchanged rows crossed the wire.');
        check(array_sum($result['tables']['products']['delta']['remove'])===1&&$result['tables']['users']['delta']['add']===[],'Removal or deterministic password identity is wrong.');
        foreach($result['tables'] as $part)check(array_keys($part)===['ddl','delta']&&!isset($part['rows'],$part['sha256']),'Initial wire rows/checksums survived in a delta.');
        check($result['media']===$bootstrap->snapshot['media']&&$result['coverage']===$bootstrap->snapshot['coverage'],'Authenticated metadata/media capabilities changed.');
        check($result['delta_summary']===['changed_tables'=>1,'added_rows'=>1,'removed_rows'=>1],'Delta summary differs from actual transferred records.');};
    $cases['device-actor-binding']=static function(){[$bootstrap,$delta,$index]=environment();foreach([['device_id'=>'33333333-3333-4333-8333-333333333333'],['actor_id'=>8],['actor_id'=>'7'],['kind'=>'initial-dashboard-data'],['format'=>2]] as $change){$bad=array_replace($index,$change);blocked(static fn()=>$delta->export(fixtureDevice(),$bad),422);}check($bootstrap->calls===0,'A mismatched binding reached account export.');};
    $cases['source-binding']=static function(){[$bootstrap,$delta,$index]=environment();$index['source']['sha256']=str_repeat('f',64);blocked(static fn()=>$delta->export(fixtureDevice(),$index),409);check($bootstrap->calls===0,'A mismatched source reached export.');};
    $cases['source-race']=static function(){[$bootstrap,$delta,$index]=environment();$GLOBALS['fixture_source']->changed=true;blocked(static fn()=>$delta->export(fixtureDevice(),$index),409);check($bootstrap->calls===1,'Source race was not checked after export.');};
    $cases['table-roster-upgrades']=static function(){
        [$bootstrap,$delta,$index]=environment();unset($index['tables']['users']);$result=$delta->export(fixtureDevice(),$index);
        $expected=$bootstrap->snapshot['tables']['users']['rows'];$expected[1]['password']='!desktop-disabled';
        check($result['tables']['users']['delta']['add']===$expected&&$result['tables']['users']['delta']['remove']===[],'Newly present server table did not send its authorized rows.');
        [$bootstrap,$delta,$index]=environment();$index['tables']['pending_vendors']=$index['tables']['products'];$result=$delta->export(fixtureDevice(),$index);
        check(!isset($result['tables']['pending_vendors'])&&array_keys($result['tables'])===array_keys($bootstrap->snapshot['tables']),'Retired known table was reintroduced into server output.');
        [$bootstrap,$delta,$index]=environment();$index['tables']['not_authorized']=$index['tables']['products'];blocked(static fn()=>$delta->export(fixtureDevice(),$index),422);check($bootstrap->calls===0,'Unknown client table reached account export.');
    };
    $cases['new-empty-table']=static function(){[$bootstrap,$delta,$index]=environment();$schema=str_replace('`products`','`pending_vendors`',productDdl());$bootstrap->snapshot['tables']['pending_vendors']=['ddl'=>$schema,'rows'=>[],'sha256'=>'initial-wire-sha'];$result=$delta->export(fixtureDevice(),$index);$part=$result['tables']['pending_vendors'];check($part['ddl']===$schema&&$part['delta']['add']===[]&&$part['delta']['remove']===[]&&$part['delta']['count']===0,'New empty table was omitted or inherited client rows.');};
    $cases['structural-only-summary']=static function(){
        foreach(['new','replace','retire','counter'] as $change){
            [$bootstrap,$delta,$index]=environment();
            foreach($bootstrap->snapshot['tables'] as $table=>$part)$index['tables'][$table]=Codec::index($table,$part['rows'],7,$part['ddl']);
            $schema=str_replace('`products`','`pending_vendors`',productDdl());
            if($change==='new')$bootstrap->snapshot['tables']['pending_vendors']=['ddl'=>$schema,'rows'=>[],'sha256'=>'initial-wire-sha'];
            elseif($change==='replace'){
                $index['tables']['pending_vendors']=Codec::index('pending_vendors',[],7,str_replace('varchar(100)','varchar(90)',$schema));
                $bootstrap->snapshot['tables']['pending_vendors']=['ddl'=>$schema,'rows'=>[],'sha256'=>'initial-wire-sha'];
            }elseif($change==='retire')$index['tables']['pending_vendors']=Codec::index('pending_vendors',[],7,$schema);
            else $bootstrap->snapshot['tables']['products']['ddl'].=' AUTO_INCREMENT=999';
            $result=$delta->export(fixtureDevice(),$index);
            check($result['delta_summary']===['changed_tables'=>$change==='counter'?0:1,'added_rows'=>0,'removed_rows'=>0],'A structural-only change was hidden from refresh activation, or a mutable counter created a false change.');
            if($change==='replace')check(($result['tables']['pending_vendors']['delta']['replace']??null)===true,'Empty replacement omitted its structural marker.');
        }
    };
    $cases['changed-schema-server-rows']=static function(){[$bootstrap,$delta,$index]=environment();$oldSchema="CREATE TABLE `products` (\n  `id` bigint unsigned NOT NULL,\n  `name` varchar(100) NOT NULL,\n  `old_note` varchar(100) DEFAULT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";$old=[['id'=>1,'name'=>'Old','old_note'=>'PRIVATE client-only field']];$index['tables']['products']=Codec::index('products',$old,7,$oldSchema);$result=$delta->export(fixtureDevice(),$index);$part=$result['tables']['products'];check(($part['delta']['replace']??null)===true&&$part['delta']['remove']===$index['tables']['products']['hashes'],'Server did not bind structural replacement to the complete prior index.');check($part['delta']['add']===$bootstrap->snapshot['tables']['products']['rows']&&!str_contains(json_encode($result,JSON_THROW_ON_ERROR),'PRIVATE'),'Server replacement copied untrusted/retired client columns.');};
    $cases['reject-client-rows-and-sql']=static function(){[$bootstrap,$delta,$index]=environment();foreach([['rows'=>[['id'=>999,'name'=>'PRIVATE injected row']]],['ddl'=>'DROP TABLE users'],['projection'=>['id']]] as $extra){$bad=$index;$bad['tables']['products']+=$extra;blocked(static fn()=>$delta->export(fixtureDevice(),$bad),422);}check($bootstrap->calls===0,'Client rows, SQL or projection reached export.');};
    $cases['local-refusal']=static function(){[$bootstrap,$delta,$index]=environment();$GLOBALS['fixture_local']=true;blocked(static fn()=>$delta->export(fixtureDevice(),$index),403);check($bootstrap->calls===0,'Local runtime invoked server export.');};
    $cases['scoped-export-binding']=static function(){foreach(['device_id','actor_id'] as $field){[$bootstrap,$delta,$index]=environment();$bootstrap->snapshot[$field]=$field==='actor_id'?8:'33333333-3333-4333-8333-333333333333';blocked(static fn()=>$delta->export(fixtureDevice(),$index),422);}};
    $cases['total-row-limit']=static function(){[$bootstrap,$delta,$index]=environment();foreach($index['tables'] as &$part){$part['count']=250001;$part['hashes']=[str_repeat('c',64)=>250001];}unset($part);blocked(static fn()=>$delta->export(fixtureDevice(),$index),413);check($bootstrap->calls===0,'An excess aggregate row count reached account export.');};
    $cases['projected-eligibility']=static function(){[$bootstrap,$delta,$index]=environment();
        $schema="CREATE TABLE `pending_vendors` (\n  `id` bigint unsigned NOT NULL,\n  `profession_key` varchar(50) NOT NULL,\n  `type` varchar(50) NOT NULL,\n  `status` varchar(50) NOT NULL,\n  `source_app` varchar(50) NOT NULL,\n  `full_name` varchar(100) NOT NULL,\n  `mobile` varchar(50) NOT NULL,\n  `other_field` varchar(100) DEFAULT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $row=['id'=>1,'profession_key'=>'fixture','type'=>'fixture','status'=>'accepted','source_app'=>'fixture','full_name'=>'','mobile'=>''];
        $bootstrap->snapshot['coverage']['pending_vendor_eligibility']=['kind'=>'go-store-owner-only','source_fields'=>['id','profession_key','type','status','source_app'],'redacted_required_fields'=>['full_name','mobile']];
        $bootstrap->snapshot['tables']['pending_vendors']=['ddl'=>$schema,'rows'=>[$row],'sha256'=>'initial-wire-sha'];
        $projection=Codec::projection('pending_vendors',$bootstrap->snapshot['coverage']);
        $index['tables']['pending_vendors']=Codec::index('pending_vendors',[$row+['other_field'=>null]],7,$schema,$projection);
        $result=$delta->export(fixtureDevice(),$index);check($result['tables']['pending_vendors']['delta']['add']===[]&&$result['tables']['pending_vendors']['delta']['remove']===[],'Server eligibility projection was not applied consistently.');};
    $cases['http-private-response']=static function(){[$bootstrap,$delta,$index]=environment();$request=new \Illuminate\Http\Request(json_encode($index),null);$devices=new \App\Services\Dashboard\DesktopDashboardDevices;$controller=new \App\Http\Controllers\Api\DesktopDashboardController;$result=$controller->delta($request,$devices,$delta);
        check($devices->calls===1&&$result->status===200,'HTTP endpoint did not authenticate its device.');check($request->streamRead&&!is_resource($request->stream),'HTTP endpoint did not bound/close the raw input stream.');check($result->headers===['Content-Type'=>'application/json','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff'],'HTTP delta response is not private.');$decoded=json_decode($result->body,true,64,JSON_THROW_ON_ERROR);check($decoded['kind']==='dashboard-data-delta','Endpoint silently fell back to bootstrap.');};
    $cases['http-auth-before-body']=static function(){[$bootstrap,$delta,$index]=environment();$request=new \Illuminate\Http\Request('PRIVATE untrusted body',null,'wrong-token');$controller=new \App\Http\Controllers\Api\DesktopDashboardController;$devices=new \App\Services\Dashboard\DesktopDashboardDevices;blocked(static fn()=>$controller->delta($request,$devices,$delta),401);check($request->reads===0&&$bootstrap->calls===0,'Unauthenticated request consumed body/export.');};
    $cases['http-reject-json-media-type']=static function(){foreach(['application/json','text/plain','multipart/form-data',''] as $type){[$bootstrap,$delta,$index]=environment();$request=new \Illuminate\Http\Request(json_encode($index),null,'fixture-device-token',$type);$controller=new \App\Http\Controllers\Api\DesktopDashboardController;blocked(static fn()=>$controller->delta($request,new \App\Services\Dashboard\DesktopDashboardDevices,$delta),415);check($request->reads===0&&$bootstrap->calls===0,'Unbounded JSON/form body reached delta decoding/export.');}};
    $cases['http-length-limit']=static function(){foreach([(string)(Codec::MAX_INDEX_BYTES+1),'999999999999999999999','-1','not-a-number',123] as $length){[$bootstrap,$delta,$index]=environment();$request=new \Illuminate\Http\Request(json_encode($index),$length);$controller=new \App\Http\Controllers\Api\DesktopDashboardController;blocked(static fn()=>$controller->delta($request,new \App\Services\Dashboard\DesktopDashboardDevices,$delta),413);check($request->reads===0&&$bootstrap->calls===0,'Oversized/invalid declared body was read/exported.');}};
    $cases['http-invalid-json']=static function(){foreach(['{PRIVATE','null','"PRIVATE"','[]','{}'] as $body){[$bootstrap,$delta,$index]=environment();$request=new \Illuminate\Http\Request($body,null);$controller=new \App\Http\Controllers\Api\DesktopDashboardController;blocked(static fn()=>$controller->delta($request,new \App\Services\Dashboard\DesktopDashboardDevices,$delta),422);check($bootstrap->calls===0,'Invalid JSON fell back to full-account export.');}};
    $cases['http-empty-body']=static function(){[$bootstrap,$delta,$index]=environment();$request=new \Illuminate\Http\Request('',null);$controller=new \App\Http\Controllers\Api\DesktopDashboardController;blocked(static fn()=>$controller->delta($request,new \App\Services\Dashboard\DesktopDashboardDevices,$delta),413);check($bootstrap->calls===0,'Empty/truncated POST fell back to full-account export.');};
    $cases['http-actual-body-limit']=static function(){[$bootstrap,$delta,$index]=environment();$request=new \Illuminate\Http\Request(str_repeat(' ',Codec::MAX_INDEX_BYTES+1),null);$controller=new \App\Http\Controllers\Api\DesktopDashboardController;blocked(static fn()=>$controller->delta($request,new \App\Services\Dashboard\DesktopDashboardDevices,$delta),413);check($bootstrap->calls===0&&$request->streamRead&&!is_resource($request->stream),'Oversized actual input was exported or left open.');};
    $selected=$argv[1]??null;if($selected!==null){check(isset($cases[$selected]),'Unknown delta server test.');$cases[$selected]();echo 'PASS '.$selected.PHP_EOL;}else foreach($cases as $name=>$work){$work();echo 'PASS '.$name.PHP_EOL;}
}
