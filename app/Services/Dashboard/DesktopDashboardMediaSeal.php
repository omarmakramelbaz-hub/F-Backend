<?php
namespace App\Services\Dashboard;

/** A private native handoff: hash before the write fence, then recheck file identities. */
final class DesktopDashboardMediaSeal
{
    private static function identities(array $files): array
    {
        $result=[];
        foreach(DesktopDashboardMedia::receipt($files) as $part){
            $area=$part['area']??'public';
            $root=storage_path($area==='private'?'app':'app/public');
            clearstatcache(true,$root);$rootStat=@lstat($root);$base=realpath($root);
            abort_unless($base&&is_array($rootStat)&&($rootStat['mode']&0170000)===0040000,409,'مرفقات قاعدة التجهيز تغيّرت.');
            $cursor=$root;$segments=explode('/',$part['path']);
            foreach($segments as $index=>$segment){
                $cursor.=DIRECTORY_SEPARATOR.$segment;clearstatcache(true,$cursor);$stat=@lstat($cursor);
                $last=$index===count($segments)-1;
                abort_unless(is_array($stat)&&($stat['mode']&0170000)===($last?0100000:0040000),409,'مرفقات قاعدة التجهيز تغيّرت.');
            }
            $resolved=realpath($cursor);$prefix=rtrim(str_replace('\\','/',$base),'/').'/';
            $named=$resolved?str_replace('\\','/',$resolved):'';
            if(DIRECTORY_SEPARATOR==='\\'){$prefix=strtolower($prefix);$named=strtolower($named);}
            abort_unless(str_starts_with($named,$prefix)&&$stat['size']===$part['bytes'],409,'مرفقات قاعدة التجهيز تغيّرت.');
            $identity=[];
            foreach(['dev','ino','mode','nlink','size','mtime','ctime'] as $key){
                abort_unless(isset($stat[$key])&&is_int($stat[$key]),409,'مرفقات قاعدة التجهيز تغيّرت.');
                $identity[$key]=$stat[$key];
            }
            $result[]=['path'=>$part['path'],'area'=>$area,'sha256'=>$part['sha256'],'identity'=>$identity];
        }
        return $result;
    }

    /** The callback performs the existing full SHA-256/database verification. */
    public static function capture(array $files,callable $verify): array
    {
        $files=DesktopDashboardMedia::receipt($files);$before=self::identities($files);
        $result=$verify();
        abort_unless($before===self::identities($files),409,'مرفقات قاعدة التجهيز تغيّرت أثناء الفحص.');
        return ['result'=>$result,'seal'=>['format'=>1,
            'manifest_sha256'=>hash('sha256',DesktopDashboardBootstrap::json($files)),'files'=>$before]];
    }

    /** Only the native archive entry passes this proof; ordinary verification still hashes bytes. */
    public static function verify(array $files,array $seal): void
    {
        $files=DesktopDashboardMedia::receipt($files);
        abort_unless(array_keys($seal)===['format','manifest_sha256','files']&&$seal['format']===1
            &&is_string($seal['manifest_sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$seal['manifest_sha256'])
            &&hash_equals($seal['manifest_sha256'],hash('sha256',DesktopDashboardBootstrap::json($files)))
            &&is_array($seal['files'])&&$seal['files']===self::identities($files),409,'مرفقات قاعدة التجهيز تغيّرت بعد الفحص.');
    }
}
