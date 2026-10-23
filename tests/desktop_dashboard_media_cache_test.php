<?php
// Real descriptor hashing and MIME detection; synthetic authorization/cache adapters only.
namespace Symfony\Component\HttpKernel\Exception {
    class HttpException extends \RuntimeException {public function __construct(private int $status){parent::__construct('Fixed refusal');}public function getStatusCode(){return $this->status;}}
}
namespace {
    function abort_unless($value,$status,$message=''){if(!$value)throw new \Symfony\Component\HttpKernel\Exception\HttpException($status);}
    function abort_if($value,$status,$message=''){abort_unless(!$value,$status);}
    function config($key,$default=null){return $GLOBALS['media_config'][$key]??$default;}
    function storage_path($path){return $GLOBALS['media_root'].'/'.$path;}
    class MediaQuery {
        public array $where=[];
        function where($key,$value){$this->where[$key]=$value;return $this;}
        function exists(){return $GLOBALS['media_allowed'];}
        function first(){return $GLOBALS['media_allowed']?(object)$GLOBALS['media_expense']:null;}
    }
}
namespace App\Models {class User {public int $id=1;}}
namespace Illuminate\Support\Facades {
    class Cache {
        public static array $values=[];public static int $gets=0,$puts=0;public static bool $throw=false;public static $onGet=null,$forced=null;
        static function get($key){self::$gets++;if(self::$onGet)(self::$onGet)();if(self::$throw)throw new \RuntimeException('Synthetic unavailable cache');return self::$forced??self::$values[$key]??null;}
        static function put($key,$value,$ttl){if(self::$throw)throw new \RuntimeException('Synthetic unavailable cache');\verify($ttl===30,'Cache TTL is exactly thirty seconds');self::$puts++;self::$values[$key]=$value;}
    }
    class Crypt {static function encryptString($value){return base64_encode($value);}static function decryptString($value){return base64_decode($value,true);}}
    class DB {static function transaction($work){return $work();}}
}
namespace App\Services\Dashboard {
    function time(){return $GLOBALS['media_now'];}
    function hash_update_stream($context,$stream,$length=-1){$GLOBALS['media_hashes']++;return \hash_update_stream($context,$stream,$length);}
    function fstat($stream){$GLOBALS['media_stats']++;if($GLOBALS['media_on_stat'])($GLOBALS['media_on_stat'])($GLOBALS['media_stats']);return \fstat($stream);}
    class DesktopDashboardDevices {
        function branch($device,$branch,$actor){\abort_unless($GLOBALS['media_allowed'],403);}
        function actor($device){return new \App\Models\User;}
        function enrollmentFingerprint($device){return str_repeat('a',64);}
        function lockAuthenticated($device){return $device;}
    }
    class DesktopDashboardData {function queries($device,$actor,$branches){return ['branch_expenses'=>new \MediaQuery,'go_store_products'=>new \MediaQuery];}}
    class DesktopDashboardBootstrap {static function json($value){return json_encode($value,JSON_THROW_ON_ERROR);}}
}
namespace {
    use Illuminate\Support\Facades\Cache;
    use App\Services\Dashboard\DesktopDashboardMedia as Media;
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardMedia.php';
    $GLOBALS['media_root']=sys_get_temp_dir().'/go-erp-media-cache-test-'.bin2hex(random_bytes(8));
    $GLOBALS['media_now']=time();$GLOBALS['media_hashes']=0;$GLOBALS['media_stats']=0;$GLOBALS['media_on_stat']=null;$GLOBALS['media_allowed']=true;
    $GLOBALS['media_config']=['filesystems.disks.local.driver'=>'local','filesystems.disks.local.root'=>$GLOBALS['media_root'].'/app'];
    mkdir(storage_path('app/branch-expenses'),0700,true);mkdir(storage_path('app/public'),0700,true);
    $file=storage_path('app/branch-expenses/invoice.pdf');$pdf="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";file_put_contents($file,$pdf);
    $GLOBALS['media_expense']=['id'=>17,'attachment_path'=>'branch-expenses/invoice.pdf','attachment_hash'=>hash('sha256',$pdf),'attachment_mime'=>'application/pdf'];
    $device=(object)['id'=>'synthetic-device','actor_id'=>1,'branches'=>'["f:1"]'];$actor=new \App\Models\User;
    $media=new Media(new \App\Services\Dashboard\DesktopDashboardDevices,new \App\Services\Dashboard\DesktopDashboardData);
    $proof=new \ReflectionMethod($media,'fileProof');$checks=0;
    function verify($value,$label){global $checks;if(!$value)throw new \RuntimeException($label);$checks++;}
    function refused($work,$status,$label){try{$work();throw new \RuntimeException('Expected refusal');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===$status,$label);}}
    function resetCache(){Cache::$values=[];Cache::$gets=0;Cache::$puts=0;Cache::$throw=false;Cache::$onGet=null;Cache::$forced=null;$GLOBALS['media_hashes']=0;$GLOBALS['media_stats']=0;$GLOBALS['media_on_stat']=null;}
    function identity($file){clearstatcache(true,$file);return array_intersect_key(lstat($file),array_flip(['dev','ino','mode','size','mtime','ctime']));}
    try{
        verify(class_exists('finfo'),'Actual fileinfo extension is loaded');
        $one=$proof->invoke($media,$file,'application/pdf','pdf');
        verify($one===['bytes'=>strlen($pdf),'sha256'=>hash('sha256',$pdf)],'Fresh proof hashes the actual descriptor bytes');
        for($i=0;$i<20;$i++)verify($proof->invoke($media,$file,'application/pdf','pdf')===$one,'Another device receives the same checked metadata');
        verify($GLOBALS['media_hashes']===1&&Cache::$gets===21&&Cache::$puts===1,'Twenty devices share one actual hash within the TTL');
        $cached=array_values(Cache::$values)[0];verify(array_keys($cached)===['format','identity','mime','sha256','expires_at'],'Cache stores only fixed descriptor metadata');
        verify(!str_contains(json_encode(Cache::$values),$GLOBALS['media_root'])&&!str_contains(json_encode(Cache::$values),'ticket'),'Cache contains no media path, account or ticket');
        $GLOBALS['media_now']+=31;$proof->invoke($media,$file,'application/pdf','pdf');verify($GLOBALS['media_hashes']===2,'Expired metadata is freshly hashed');

        resetCache();$proof->invoke($media,$file,'application/pdf','pdf');$saved=array_values(Cache::$values)[0];
        foreach([['sha256'=>'invalid'],['identity'=>[]],['mime'=>'image/png'],['expires_at'=>$GLOBALS['media_now']+31],['format'=>2],['unexpected'=>'PRIVATE']] as $bad){
            Cache::$forced=array_merge($saved,$bad);$before=$GLOBALS['media_hashes'];verify($proof->invoke($media,$file,'application/pdf','pdf')===$one,'Malformed metadata cannot replace file verification');verify($GLOBALS['media_hashes']===$before+1,'Invalid cache payload is rehashed');
        }
        Cache::$forced=null;Cache::$throw=true;$before=$GLOBALS['media_hashes'];verify($proof->invoke($media,$file,'application/pdf','pdf')===$one&&$GLOBALS['media_hashes']===$before+1,'Unavailable cache falls back to verified bytes');

        resetCache();$GLOBALS['media_on_stat']=function($call)use($file){if($call===2)touch($file,time()+10);};
        refused(fn()=>$proof->invoke($media,$file,'application/pdf','pdf'),409,'A file changing while hashing has no accepted proof');verify(Cache::$puts===0&&Cache::$values===[],'Transient mismatch is never cached');
        $GLOBALS['media_on_stat']=null;
        resetCache();$proof->invoke($media,$file,'application/pdf','pdf');$oldPuts=Cache::$puts;
        Cache::$onGet=function()use($file,$pdf){Cache::$onGet=null;rename($file,$file.'.old');file_put_contents($file,$pdf);};
        refused(fn()=>$proof->invoke($media,$file,'application/pdf','pdf'),409,'Replacement during cache lookup fails descriptor/name binding');verify(Cache::$puts===$oldPuts,'Replacement does not cache a transient result');

        resetCache();$GLOBALS['media_allowed']=false;
        refused(fn()=>$media->manifest(['branch_expenses'=>[$GLOBALS['media_expense']]],$device,$actor,'synthetic-snapshot'),403,'Unauthorized scope is refused before cache lookup');verify(Cache::$gets===0&&$GLOBALS['media_hashes']===0,'Forbidden account scope cannot probe the checksum cache');
        $GLOBALS['media_allowed']=true;
        $bad=$GLOBALS['media_expense'];$bad['attachment_path']='go-stores/other/image.png';
        $GLOBALS['media_allowed']=false;refused(fn()=>$media->manifest(['go_store_products'=>[['id'=>9,'image_path'=>$bad['attachment_path']]]],$device,$actor,'synthetic-snapshot'),403,'GO product authorization precedes cache lookup');verify(Cache::$gets===0,'A foreign store path cannot probe the cache');$GLOBALS['media_allowed']=true;

        resetCache();$snapshot=$media->manifest(['branch_expenses'=>[$GLOBALS['media_expense']]],$device,$actor,'synthetic-snapshot');verify($snapshot['complete']===true&&count($snapshot['files'])===1,'Authorized private media produces the existing scoped capability');
        verify($media->download($device,$snapshot['files'][0]['ticket'])['bytes']===$pdf,'Actual body still verifies after a cached manifest');
        $oldCache=array_values(Cache::$values)[0];$changed=str_replace('Catalog','Changed',$pdf);file_put_contents($file,$changed);
        Cache::$forced=array_merge($oldCache,['identity'=>identity($file),'expires_at'=>$GLOBALS['media_now']+30]);
        $stale=$media->manifest(['branch_expenses'=>[$GLOBALS['media_expense']]],$device,$actor,'synthetic-snapshot');
        verify($stale['complete']===true&&$stale['files'][0]['sha256']===hash('sha256',$pdf),'Synthetic same-stat stale metadata exercises the download guard');
        refused(fn()=>$media->download($device,$stale['files'][0]['ticket']),409,'Stale cache metadata cannot authorize changed body bytes');
        echo 'PASS '.$checks.' shared media checksum cache checks'.PHP_EOL;
    }finally{
        foreach(glob(storage_path('app/branch-expenses/*'))?:[] as $entry)unlink($entry);
        rmdir(storage_path('app/branch-expenses'));rmdir(storage_path('app/public'));rmdir(storage_path('app'));rmdir($GLOBALS['media_root']);
    }
}
