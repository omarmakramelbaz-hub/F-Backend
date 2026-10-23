<?php
// Synthetic native staging files only; no database, server, or account data.
function abort_unless($value,$status,$message=''){if(!$value)throw new RuntimeException($message?:'Refused', $status);}
function abort_if($value,$status,$message=''){abort_unless(!$value,$status,$message);}
if(!function_exists('mb_strtolower')){function mb_strtolower($value){return strtolower($value);}}
function storage_path($relative=''){return $GLOBALS['seal_root'].($relative===''?'':DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative));}
function config($key){return match($key){'filesystems.disks.local.driver'=>'local','filesystems.disks.local.root'=>storage_path('app'),default=>null};}
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardBootstrap.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardMedia.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardMediaSeal.php';
use App\Services\Dashboard\DesktopDashboardMediaSeal as Seal;
use App\Services\Dashboard\DesktopDashboardMedia as Media;
$root=sys_get_temp_dir().'/go-media-seal-'.bin2hex(random_bytes(12));$GLOBALS['seal_root']=$root;
mkdir($root.'/app/public/products',0700,true);mkdir($root.'/app/branch-expenses',0700,true);
$file=$root.'/app/public/products/photo.png';$private=$root.'/app/branch-expenses/receipt.pdf';
$bytes="synthetic verified image\0bytes";$secret='private synthetic attachment';
file_put_contents($file,$bytes);file_put_contents($private,$secret);
$files=[['path'=>'products/photo.png','sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes),'mime'=>'image/png'],
    ['path'=>'branch-expenses/receipt.pdf','sha256'=>hash('sha256',$secret),'bytes'=>strlen($secret),'mime'=>'application/pdf','area'=>'private']];
$checks=0;
function check($value,$name){global $checks;if(!$value)throw new RuntimeException($name);$checks++;}
function refuses($work,$name){try{$work();}catch(RuntimeException $error){check($error->getCode()===409,$name);return;}throw new RuntimeException('Expected refusal: '.$name);}
try{
    $calls=0;$captured=Seal::capture($files,function()use(&$calls,$files){$calls++;Media::verifyFiles($files);return ['verified'=>true];});
    check($calls===1&&$captured['result']===['verified'=>true],'Full verification runs before proof');
    Seal::verify($files,$captured['seal']);check(true,'Unchanged independent native handoff accepts exact proof');
    check(count($captured['seal']['files'])===2&&$captured['seal']['files'][1]['area']==='private','Private/public scope remains distinct');
    $forged=$captured['seal'];$forged['manifest_sha256']=str_repeat('0',64);refuses(fn()=>Seal::verify($files,$forged),'Forged manifest refuses');
    $forged=$captured['seal'];$forged['files'][0]['identity']['ino']++;refuses(fn()=>Seal::verify($files,$forged),'Forged inode refuses');
    file_put_contents($file,'changed bytes with different size');refuses(fn()=>Seal::verify($files,$captured['seal']),'Changed file refuses after full verification');
    file_put_contents($file,$bytes);
    refuses(fn()=>Seal::capture($files,function()use($file){file_put_contents($file,'changed while verifying');return []; }),'Mutation during full verification refuses');
    file_put_contents($file,$bytes);
    if(function_exists('symlink')&&PHP_OS_FAMILY!=='Windows'){
        unlink($file);symlink($private,$file);refuses(fn()=>Seal::capture($files,fn()=>[]),'Linked image cannot use private file');unlink($file);file_put_contents($file,$bytes);
    }
    $captured=Seal::capture($files,fn()=>[]);$changed=$files;$changed[1]['sha256']=str_repeat('a',64);
    refuses(fn()=>Seal::verify($changed,$captured['seal']),'Same path/stat cannot authorize a different expected digest');
    check(!str_contains(json_encode($captured),$bytes)&&!str_contains(json_encode($captured),$secret),'Proof contains no attachment contents');
    echo 'PASS '.$checks.' native media handoff checks'.PHP_EOL;
}finally{
    foreach([$file,$private] as $path)if(file_exists($path)||is_link($path))unlink($path);
    foreach([$root.'/app/public/products',$root.'/app/public',$root.'/app/branch-expenses',$root.'/app',$root] as $path)rmdir($path);
}
