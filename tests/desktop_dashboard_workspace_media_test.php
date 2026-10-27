<?php
// Actual v2 ticket/file engine; only the already-authenticated authority/query boundary is synthetic.
namespace Symfony\Component\HttpKernel\Exception {
    class HttpException extends \RuntimeException {
        public function __construct(private int $status){parent::__construct('Fixed synthetic refusal');}
        public function getStatusCode(){return $this->status;}
    }
}
namespace {
    function abort($status,$message=''){throw new \Symfony\Component\HttpKernel\Exception\HttpException($status);}
    function abort_unless($ok,$status,$message=''){if(!$ok)abort($status,$message);}
    function abort_if($ok,$status,$message=''){abort_unless(!$ok,$status,$message);}
    function config($key,$default=null){return $GLOBALS['workspace_media_config'][$key]??$default;}
    function storage_path($path=''){return $GLOBALS['workspace_media_root'].($path===''?'':'/'.$path);}
    if(!function_exists('mb_strtolower')){function mb_strtolower($value){return strtolower($value);}}
    final class WorkspaceMediaQuery {
        private array $where=[],$columns=[];
        public function __construct(private array $data){}
        public function where($column,$value){$this->where[$column]=$value;return $this;}
        public function select($columns){$this->columns=$columns;return $this;}
        private function rows(){return array_filter($this->data,function($row){foreach($this->where as $key=>$value)if(($row[$key]??null)!=$value)return false;return true;});}
        public function exists(){return (bool)$this->rows();}
        public function first(){$rows=$this->rows();return ($row=reset($rows))?(object)$row:null;}
        public function cursor(){foreach($this->rows() as $row)yield(object)($this->columns?array_intersect_key($row,array_flip($this->columns)):$row);}
    }
}
namespace App\Models {class User {public int $id=10;}}
namespace Illuminate\Support\Facades {
    class Cache {public static function get($key){return null;}public static function put($key,$value,$ttl){}}
    class Crypt {
        public static function encryptString($value){return base64_encode($value);}
        public static function decryptString($value){$raw=base64_decode($value,true);if($raw===false)throw new \RuntimeException('Invalid synthetic ciphertext');return $raw;}
    }
    class Schema {public static function hasColumn($table,$column){return true;}}
    class DB {
        public static int $depth=0;
        public static function transaction($work){self::$depth++;try{return $work();}finally{self::$depth--;}}
        public static function table($table){return new \WorkspaceMediaQuery($GLOBALS['workspace_media_rows'][$table]??[]);}
    }
}
namespace App\Services\Dashboard {
    class DesktopDashboardDevices {}
    class DesktopDashboardData {}
    class DesktopDashboardBootstrap {public static function json($value){return json_encode($value,JSON_THROW_ON_ERROR);}}
    class EmployeeIdentity {public const SIDES=['front','back'];public static function validPath($path){return DesktopDashboardMedia::safePath($path,'private');}}
    final class DesktopDashboardWorkspaceSnapshotAudience {
        public function __construct(private array $binding,public bool $issued=true){}
        public function context(){\abort_unless($this->issued,403);return $this->binding;}
    }
    class DesktopDashboardWorkspaceAuthority {
        public array $binding;public bool $enabled=true;
        public function __construct(){ $this->binding=['workspace_id'=>'11111111-1111-4111-8111-111111111111','revision'=>1,'root_fingerprint'=>str_repeat('a',64),'roster_sha256'=>str_repeat('b',64)]; }
        public function assertSnapshotAudience($audience){\abort_unless($this->enabled&&$audience->issued&&$audience->context()===$this->binding,403);}
        public function lockAuthenticated($root){\abort_unless($this->enabled&&$root->id===$this->binding['workspace_id']&&$root->fingerprint===$this->binding['root_fingerprint'],401);return $root;}
        public function snapshotAudience($root){return new DesktopDashboardWorkspaceSnapshotAudience($this->binding);}
        public static function canonical($value){return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    }
    class DesktopDashboardWorkspaceData {
        public int $calls=0;
        public function queries(DesktopDashboardWorkspaceSnapshotAudience $audience){
            $audience->context();$this->calls++;$queries=[];
            foreach($GLOBALS['workspace_media_union'] as $table=>$rows)$queries[$table]=new \WorkspaceMediaQuery($rows);
            return $queries;
        }
    }
}
namespace {
    use App\Services\Dashboard\{DesktopDashboardWorkspaceAuthority as Authority,DesktopDashboardWorkspaceSnapshotAudience as Audience,
        DesktopDashboardWorkspaceData as Data,DesktopDashboardWorkspaceMedia as Media};
    use Illuminate\Support\Facades\Crypt;
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardMedia.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceMedia.php';
    $checks=0;
    function check($ok,$label){global $checks;if(!$ok)throw new \RuntimeException($label);$checks++;}
    function refused($work,$status,$label){try{$work();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){check($error->getStatusCode()===$status,$label);return;}throw new \RuntimeException($label.' was accepted');}
    function payload($ticket){return json_decode(Crypt::decryptString($ticket),true,32,JSON_THROW_ON_ERROR);}
    function ticket($value){return Crypt::encryptString(json_encode($value,JSON_THROW_ON_ERROR));}
    function put($path,$bytes){$name=storage_path($path);if(!is_dir(dirname($name)))mkdir(dirname($name),0700,true);file_put_contents($name,$bytes);}
    function erase($path){if(is_link($path)||is_file($path)){unlink($path);return;}foreach(new \DirectoryIterator($path) as $entry)if(!$entry->isDot())erase($entry->getPathname());rmdir($path);}
    $GLOBALS['workspace_media_root']=sys_get_temp_dir().'/go-erp-workspace-media-'.bin2hex(random_bytes(8));
    $GLOBALS['workspace_media_config']=['filesystems.disks.local.driver'=>'local','filesystems.disks.local.root'=>storage_path('app'),
        'filesystems.disks.public.driver'=>'local','filesystems.disks.public.root'=>storage_path('app/public'),
        'media-library.path_generator'=>\Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator::class];
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB5kAAAAASUVORK5CYII=');
    $pdf="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    foreach(['1/a.png','2/b.png','2/conversions/thumb.png','3/c.png','4/fk-only.png','stores/b.png'] as $path)put('app/public/'.$path,$png);
    put('app/branch-expenses/b.pdf',$pdf);
    $rows=[];foreach([[1,'Resturant',100,'a.png'],[2,'Resturant',101,'b.png'],[3,'Resturant',999,'c.png'],[4,'User',99,'fk-only.png']] as [$id,$model,$owner,$name])
        $rows[]=['id'=>$id,'model_type'=>'App\\Models\\'.$model,'model_id'=>$owner,'file_name'=>$name,'disk'=>'public','conversions_disk'=>'public'];
    $expense=['id'=>27,'branch'=>'f:101','attachment_path'=>'branch-expenses/b.pdf','attachment_hash'=>hash('sha256',$pdf),'attachment_mime'=>'application/pdf'];
    $product=['id'=>31,'user_id'=>11,'image_path'=>'stores/b.png'];
    $GLOBALS['workspace_media_rows']=['media'=>$rows];
    $GLOBALS['workspace_media_union']=['resturants'=>[['id'=>100],['id'=>101]],'users'=>[['id'=>10],['id'=>11]],
        'branch_expenses'=>[$expense],'go_store_products'=>[$product]];
    $authority=new Authority;$data=new Data;$media=new Media(new \App\Services\Dashboard\DesktopDashboardDevices,new \App\Services\Dashboard\DesktopDashboardData,$authority,$data);
    $root=(object)['id'=>$authority->binding['workspace_id'],'fingerprint'=>$authority->binding['root_fingerprint'],'custodian_actor_id'=>10];
    $audience=new Audience($authority->binding);$snapshot='22222222-2222-4222-8222-222222222222';
    try{
        check(class_exists('finfo'),'Actual MIME verification is available');
        $result=$media->manifestForAudience(['media'=>$rows,'go_store_products'=>[$product],'branch_expenses'=>[$expense]],$audience,$snapshot);
        $files=array_column($result['files'],null,'path');
        check(isset($files['1/a.png'],$files['2/b.png'],$files['2/conversions/thumb.png'],$files['stores/b.png'],$files['branch-expenses/b.pdf']),
            'Union scope includes both A and B files while A is custody only');
        check(!isset($files['3/c.png'],$files['4/fk-only.png'])&&!$result['complete'],'Excluded C and FK-only user media never receive capabilities');
        foreach($files as $file){
            $value=payload($file['ticket']);
            check($value['format']===2&&$value['kind']==='desktop-workspace-media-ticket'&&$value['snapshot_id']===$snapshot,'V2 tickets identify the workspace snapshot');
            check(!array_key_exists('actor_id',$value)&&!array_key_exists('grant',$value)&&!array_key_exists('password_binding',$value),'Media ticket contains no actor credential authority');
            check($media->downloadWorkspace($root,$file['ticket'])['bytes']===(($file['area']??'public')==='private'?$pdf:$png),'Actual scoped image/private bytes download exactly');
        }
        $good=$files['2/b.png']['ticket'];$value=payload($good);
        foreach(['workspace_id'=>'33333333-3333-4333-8333-333333333333','revision'=>2,'root_fingerprint'=>str_repeat('c',64),'roster_sha256'=>str_repeat('d',64),
            'format'=>1,'kind'=>'desktop-dashboard-media-ticket','expires_at'=>time()-1,'unknown'=>'untrusted'] as $key=>$bad){
            $tampered=$value;$tampered[$key]=$bad;refused(fn()=>$media->downloadWorkspace($root,ticket($tampered)),403,'Foreign/stale/expired/unsupported ticket '.$key);
        }
        refused(fn()=>$media->downloadWorkspace($root,'%%%'),422,'Malformed encrypted ticket');
        $badRoot=clone $root;$badRoot->id='33333333-3333-4333-8333-333333333333';
        refused(fn()=>$media->downloadWorkspace($badRoot,$good),401,'Another physical workspace cannot reuse a capability');
        $authority->binding['revision']++;refused(fn()=>$media->downloadWorkspace($root,$good),403,'Fresh revision invalidates cached file tickets');$authority->binding['revision']--;
        $authority->enabled=false;refused(fn()=>$media->downloadWorkspace($root,$good),401,'Revoked physical root invalidates cached file tickets');$authority->enabled=true;
        refused(fn()=>$media->manifestForAudience([],new Audience($authority->binding,false),$snapshot),403,'Unissued audience cannot authorize a manifest');
        $foreign=$product;$foreign['id']=999;$foreign['image_path']='3/c.png';
        refused(fn()=>$media->manifestForAudience(['go_store_products'=>[$foreign]],$audience,$snapshot),403,'Dataset rows cannot broaden union query authorization');
        $wide=$product;$wide['id']=9007199254740993;$GLOBALS['workspace_media_union']['go_store_products']=[$wide];
        $wideResult=$media->manifestForAudience(['go_store_products'=>[$wide]],$audience,$snapshot);
        check($wideResult['complete']&&count($wideResult['files'])===1,'Original positive PHP proof IDs above the JS-safe range remain eligible');
        check($media->downloadWorkspace($root,$wideResult['files'][0]['ticket'])['bytes']===$png,'Opaque encrypted proof IDs retain exact server integer identity');
        $GLOBALS['workspace_media_union']['go_store_products']=[$product];
        $private=payload($files['branch-expenses/b.pdf']['ticket']);$private['area']='public';
        refused(fn()=>$media->downloadWorkspace($root,ticket($private)),422,'Private PDF cannot be recast as public media');
        $previous=$GLOBALS['workspace_media_union']['resturants'];$GLOBALS['workspace_media_union']['resturants']=[['id'=>100]];
        refused(fn()=>$media->downloadWorkspace($root,$good),403,'Fresh source queries must still authorize B media');$GLOBALS['workspace_media_union']['resturants']=$previous;
        put('app/public/2/b.png',$png.'changed');refused(fn()=>$media->downloadWorkspace($root,$good),409,'Changed body cannot enter a previous coherent snapshot');put('app/public/2/b.png',$png);
        $bad=payload($good);$bad['path']='../outside.png';refused(fn()=>$media->downloadWorkspace($root,ticket($bad)),422,'Unsafe paths reject before file resolution');
        put('outside.png',$png);symlink(storage_path('outside.png'),storage_path('app/public/stores/escape.png'));
        $escape=$product;$escape['image_path']='stores/escape.png';$GLOBALS['workspace_media_union']['go_store_products']=[$escape];
        $blocked=$media->manifestForAudience(['go_store_products'=>[$escape]],$audience,$snapshot);check(!$blocked['complete']&&$blocked['files']===[],'A symlink escaping public storage has no file capability');
        echo 'PASS '.$checks.' workspace audience media, file and ticket checks'.PHP_EOL;
    }finally{erase($GLOBALS['workspace_media_root']);}
}
