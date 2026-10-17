<?php
// Reuses the isolated SQLite fixture, never bootstraps the production application.
require __DIR__.'/run.php';

use App\Services\Dashboard\{DesktopPosInstaller,TakeawayAccess};
use Illuminate\Support\Facades\DB;

$root=sys_get_temp_dir().'/desktop-installer-test-'.bin2hex(random_bytes(8));
mkdir($root,0700);
$target=$root.'/Fasakhansta-POS-Setup.exe';
$source=$root.'/chunk';
$data='MZ'.str_repeat('a',DesktopPosInstaller::CHUNK_BYTES+39);
$uploads=new DesktopPosInstaller($target,strlen($data),hash('sha256',$data));
$access=new TakeawayAccess;
$before=$count;
function env($key,$default=null){return $default;}
function storage_path($path=''){global $root;return $root.'/'.$path;}
function cleanInstallerDirectory(string $directory): void {
    foreach(scandir($directory) as $name){if($name==='.'||$name==='..')continue;$path=$directory.'/'.$name;
        if(is_dir($path)&&!is_link($path))cleanInstallerDirectory($path);else unlink($path);}
    rmdir($directory);
}
try {
    DB::table('users')->insert(['id'=>1,'name'=>'إدارة','account_type'=>'admin','owner_resturant_id'=>null]);
    DB::table('users')->insert(['id'=>21,'name'=>'مدير فرع','account_type'=>'admin','owner_resturant_id'=>100]);
    DB::table('users')->insert(['id'=>22,'name'=>'مالك','account_type'=>'resturant_owner','owner_resturant_id'=>100]);
    check($uploads->canUpload(TestUser::find(1),$access),'central admin can upload installer');
    check(!$uploads->canUpload(TestUser::find(21),$access)&&!$uploads->canUpload(TestUser::find(22),$access)
        &&!$uploads->canUpload(TestUser::find(10),$access),'branch admins, owners and vendors cannot replace global installer');
    $oldActor=TestUser::find(1);DB::table('users')->where('id',1)->update(['account_type'=>'vendor']);
    check(!$uploads->canUpload($oldActor,$access),'uploader authorization uses persisted account after revocation');
    denied(fn()=>$uploads->start(strlen($data)+1),422,'unexpected installer size rejected before allocating');
    $id=$uploads->start(strlen($data))['upload_id'];
    file_put_contents($source,substr($data,0,DesktopPosInstaller::CHUNK_BYTES));
    denied(fn()=>$uploads->chunk('../escape',0,$source),422,'invalid upload path cannot traverse staging directory');
    file_put_contents($source,substr($data,DesktopPosInstaller::CHUNK_BYTES));
    denied(fn()=>$uploads->chunk($id,DesktopPosInstaller::CHUNK_BYTES,$source),409,'out-of-order chunk cannot leave gaps');
    file_put_contents($source,substr($data,0,DesktopPosInstaller::CHUNK_BYTES));
    check($uploads->chunk($id,0,$source)===DesktopPosInstaller::CHUNK_BYTES,'first chunk persisted');
    check($uploads->chunk($id,0,$source)===DesktopPosInstaller::CHUNK_BYTES
        &&filesize($root.'/uploads/'.$id.'.part')===DesktopPosInstaller::CHUNK_BYTES,'lost chunk response replays without duplicate bytes');
    file_put_contents($source,str_repeat('b',DesktopPosInstaller::CHUNK_BYTES));
    denied(fn()=>$uploads->chunk($id,0,$source),409,'changed retry payload rejected');
    file_put_contents($target,'previous installer');
    denied(fn()=>$uploads->finish($id),422,'incomplete upload never publishes');
    check(file_get_contents($target)==='previous installer','existing download survives failed completion');
    file_put_contents($source,substr($data,DesktopPosInstaller::CHUNK_BYTES));
    $uploads->chunk($id,DesktopPosInstaller::CHUNK_BYTES,$source);$uploads->finish($id);
    check(file_get_contents($target)===$data&&!file_exists($root.'/uploads/'.$id.'.part'),'verified bytes atomically replace available download');
    $uploads->finish($id);check(file_get_contents($target)===$data,'lost completion response safely replays');
    $bad=$uploads->start(strlen($data))['upload_id'];
    file_put_contents($source,str_repeat('x',DesktopPosInstaller::CHUNK_BYTES));$uploads->chunk($bad,0,$source);
    file_put_contents($source,substr($data,DesktopPosInstaller::CHUNK_BYTES));$uploads->chunk($bad,DesktopPosInstaller::CHUNK_BYTES,$source);
    denied(fn()=>$uploads->finish($bad),422,'same-size modified executable rejected by trusted SHA-256');
    check(file_get_contents($target)===$data,'corrupt replacement preserves working download');
    $replacement=$uploads->start(strlen($data),$bad)['upload_id'];
    check(!file_exists($root.'/uploads/'.$bad.'.part'),'restarting removes previous private partial');
    $uploads->cancel($replacement);
    check(!file_exists($root.'/uploads/'.$replacement.'.part'),'cancel removes partial without touching published executable');
    check((fileperms($target)&0777)===0640&&(fileperms($root.'/uploads')&0777)===0700,'installer and staging permissions remain private');
    $orphan=$uploads->start(strlen($data))['upload_id'];touch($root.'/uploads/'.$orphan.'.part',time()-90000);
    $fresh=$uploads->start(strlen($data))['upload_id'];
    check(!file_exists($root.'/uploads/'.$orphan.'.part')&&file_exists($root.'/uploads/'.$fresh.'.part'),'old orphan removed while current upload retained');
    $uploads->cancel($fresh);
    $symlink=$uploads->start(strlen($data))['upload_id'];
    unlink($root.'/uploads/'.$symlink.'.part');symlink($target,$root.'/uploads/'.$symlink.'.part');
    file_put_contents($source,substr($data,0,DesktopPosInstaller::CHUNK_BYTES));
    denied(fn()=>$uploads->chunk($symlink,0,$source),409,'staging symlink cannot mutate published executable');

    // Optional release-artifact integration check: all real 110 MiB pass through the same writer.
    if(isset($argv[1])){
        $artifact=$argv[1];$release=require dirname(__DIR__,2).'/config/desktop_pos.php';
        $full=new DesktopPosInstaller($target,$release['installer_bytes'],$release['installer_sha256']);
        $upload=$full->start(filesize($artifact))['upload_id'];$handle=fopen($artifact,'rb');$offset=0;
        try{while(!feof($handle)){$chunk=fread($handle,DesktopPosInstaller::CHUNK_BYTES);if($chunk==='')break;
            file_put_contents($source,$chunk);$offset=$full->chunk($upload,$offset,$source);}}
        finally{fclose($handle);}
        $full->finish($upload);
        check(filesize($target)===$release['installer_bytes']&&hash_file('sha256',$target)===$release['installer_sha256'],'real Windows installer uploads and publishes with exact release SHA-256');
    }
    echo ($count-$before).' installer checks passed'.PHP_EOL;
} finally { cleanInstallerDirectory($root); }
