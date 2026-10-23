<?php
// Execute the actual importer and media hasher with a synthetic transaction/lock observer.
namespace Illuminate\Support\Facades {
    final class Validator {
        public static function make($data,$rules){return new class {public function validate(){return [];}};}
    }
    final class DB {
        public static int $depth=0;
        public static function connection(){return new class {
            public function getConfig($name){return '127.0.0.1';}
            public function getDatabaseName(){return 'fasakhansta_dashboard_stage_0123456789abcdef';}
        };}
        public static function transactionLevel(){return self::$depth;}
        public static function transaction($work){
            self::$depth++;
            try{return $work();}finally{self::$depth--;$GLOBALS['scope_held']=false;}
        }
        public static function table($table){return new \MediaScopeQuery($table);}
    }
}
namespace App\Services\Dashboard {
    final class DesktopDashboardSource {public function matches($source){return true;}}
    final class DesktopDashboardPrintLedger {
        public function fence($device){
            if(\Illuminate\Support\Facades\DB::transactionLevel()!==1)throw new \RuntimeException('Print fence was not transactional.');
            $GLOBALS['scope_held']=true;
        }
    }
    // PHP resolves the actual Media::verifyFiles unqualified call through this observer.
    // The global function still reads and hashes a real temporary file.
    function hash_file($algorithm,$file){
        $GLOBALS['hash_depths'][]=\Illuminate\Support\Facades\DB::transactionLevel();
        if($GLOBALS['scope_held'])throw new \RuntimeException('Full attachment read retained the cashier print scope.');
        return \hash_file($algorithm,$file);
    }
}
namespace {
    function abort_unless($value,$status,$message=''){if(!$value)throw new \RuntimeException($message,$status);}
    function abort_if($value,$status,$message=''){abort_unless(!$value,$status,$message);}
    function config($key){return match($key){
        'desktop_dashboard.local'=>true,
        'desktop_dashboard.device_id'=>'11111111-1111-4111-8111-111111111111',
        'filesystems.disks.local.driver'=>'local',
        'filesystems.disks.local.root'=>storage_path('app'),default=>null,
    };}
    function storage_path($relative=''){return $GLOBALS['media_scope_root'].($relative===''?'':'/'.$relative);}
    function app($class){return new $class;}
    if(!function_exists('mb_strtolower')){function mb_strtolower($value){return strtolower($value);}}
    final class MediaScopeQuery {
        public function __construct(private string $table){}
        public function where($field,$a=null,$b=null){if(is_callable($field))$field($this);return $this;}
        public function orWhere(...$values){return $this;}
        public function lockForUpdate(){return $this;}
        public function first(){
            $r=$GLOBALS['media_scope_receipt'];$json=fn($value)=>\App\Services\Dashboard\DesktopDashboardBootstrap::json($value);
            return match($this->table){
                'desktop_dashboard_local_state'=>(object)['state'=>'ready','snapshot_id'=>$r['snapshot_id'],
                    'actor_id'=>$r['actor_id'],'schema_hash'=>$r['schema_hash'],'branches'=>$json($r['branches']),'coverage'=>$json($r['coverage'])],
                'desktop_dashboard_source_manifest'=>(object)['snapshot_id'=>$r['snapshot_id'],'source'=>$json($r['source']),
                    'sha256'=>hash('sha256',$json($r['source']))],
                'desktop_dashboard_media_manifest'=>(object)['snapshot_id'=>$r['snapshot_id'],'sha256'=>hash('sha256',$json($r['media']))],
                default=>throw new \RuntimeException('Unexpected table in verification proof.'),
            };
        }
        public function count(){return $this->table==='products'?1:0;}
        public function exists(){return $this->table==='users';}
    }
    $base=dirname(__DIR__);
    foreach(['DesktopDashboardBootstrap','DesktopDashboardMedia','DesktopDashboardMediaSeal','DesktopDashboardSchema','DesktopDashboardImport'] as $class)
        require $base.'/app/Services/Dashboard/'.$class.'.php';
    $root=sys_get_temp_dir().'/go-import-scope-proof-'.bin2hex(random_bytes(8));$GLOBALS['media_scope_root']=$root;
    mkdir($root.'/app/public/products',0700,true);
    $file=$root.'/app/public/products/check.png';$bytes='Synthetic read-lock regression bytes';file_put_contents($file,$bytes);
    $GLOBALS['media_scope_receipt']=$receipt=['format'=>1,'snapshot_id'=>'22222222-2222-4222-8222-222222222222',
        'device_id'=>config('desktop_dashboard.device_id'),'actor_id'=>1,'schema_hash'=>str_repeat('a',64),'branches'=>['f:1'],
        'coverage'=>[],'table_rows'=>['products'=>1],'tables'=>1,'rows'=>1,
        'source'=>['format'=>1,'files'=>1,'framework'=>'8.83.29','sha256'=>str_repeat('b',64)],
        'media'=>[['path'=>'products/check.png','sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes),'mime'=>'image/png']]];
    $GLOBALS['scope_held']=false;$GLOBALS['hash_depths']=[];
    try{
        $import=new \App\Services\Dashboard\DesktopDashboardImport;
        $ordinary=$import->verify($receipt);
        if(($ordinary['verified']??false)!==true||$GLOBALS['hash_depths']!==[0])
            throw new \RuntimeException('Ordinary verification did not hash after releasing scope.');
        $seal=\App\Services\Dashboard\DesktopDashboardMediaSeal::capture($receipt['media'],fn()=>$import->verify($receipt));
        if($GLOBALS['hash_depths']!==[0,0])throw new \RuntimeException('Preverification retained the scope while hashing.');
        $fast=$import->verify($receipt,$seal['seal']);
        if(($fast['verified']??false)!==true||$GLOBALS['hash_depths']!==[0,0])
            throw new \RuntimeException('Sealed archival unexpectedly read attachment bytes.');
        file_put_contents($file,'Changed synthetic attachment with another length.');
        try{$import->verify($receipt,$seal['seal']);throw new \LogicException('Changed attachment accepted.');}
        catch(\RuntimeException $error){if($error->getCode()!==409)throw $error;}
        echo "PASS actual Import.verify releases print scope before attachment hashing and sealed archival rechecks identities\n";
    }finally{
        unlink($file);rmdir($root.'/app/public/products');rmdir($root.'/app/public');rmdir($root.'/app');rmdir($root);
    }
}
