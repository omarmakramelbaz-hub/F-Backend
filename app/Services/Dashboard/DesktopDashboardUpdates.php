<?php
namespace App\Services\Dashboard;

/** A release is offered only while its exact source is running on this server. */
class DesktopDashboardUpdates
{
    public const MAX_BYTES=1073741824;
    public const ERROR='لم يكتمل نشر تحديث البرنامج المتوافق؛ نسخة الجهاز وعملياته محفوظة.';

    public function __construct(private DesktopDashboardSource $source) {}

    private function requireServer(): void
    {
        abort_unless(config('desktop_dashboard.enabled')===true&&config('desktop_dashboard.local')!==true,503,self::ERROR);
    }

    private function exact($value,array $keys): bool
    {
        return is_array($value)&&count($value)===count($keys)&&array_diff(array_keys($value),$keys)===[];
    }

    private function validSource($value): bool
    {
        return $this->exact($value,['format','files','sha256','framework'])&&$value['format']===1
            &&is_int($value['files'])&&$value['files']>0&&$value['files']<=20000
            &&is_string($value['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$value['sha256'])
            &&is_string($value['framework'])&&preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$value['framework']);
    }

    private function sameSource(array $left,array $right): bool
    {
        return $this->validSource($left)&&$this->validSource($right)&&$left['files']===$right['files']
            &&$left['framework']===$right['framework']&&hash_equals($left['sha256'],$right['sha256']);
    }

    private function validVersion($value): bool
    {
        if(!is_string($value)||strlen($value)>100||!preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D',$value,$parts))return false;
        $numeric=[$parts[1],$parts[2],$parts[3]];
        foreach(explode('.',$parts[4]??'') as $identifier){
            if(preg_match('/^[0-9]+$/D',$identifier)){
                if(strlen($identifier)>1&&$identifier[0]==='0')return false;
                $numeric[]=$identifier;
            }
        }
        foreach($numeric as $number){
            if(strlen($number)>16||(strlen($number)===16&&strcmp($number,'9007199254740991')>0))return false;
        }
        return true;
    }

    private function guiPath(string $path): bool
    {
        return (bool)preg_match('#^public/(?:js/dashboard-[a-zA-Z0-9_.@-]*\.js|css/dashboard-[a-zA-Z0-9_.@-]*\.css)$#D',$path)
            &&!preg_match('#(?:^|/)(?:\.env(?:\..*)?|firebase(?:_credentials)?\.json|service[-_]account[^/]*\.json|[^/]+\.(?:key|pem)|error_log)$#i',$path);
    }

    private function validGui($value): bool
    {
        if(!is_array($value)||count($value)>2000)return false;
        $seen=[];
        foreach($value as $path=>$sha){
            if(!is_string($path)||!$this->guiPath($path)||isset($seen[strtolower($path)])
                ||!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha))return false;
            $seen[strtolower($path)]=true;
        }
        return true;
    }

    /** External dashboard GUI is pinned separately from legacy data-source identities. */
    private function gui(): array
    {
        $result=[];$seen=[];$total=0;$base=rtrim(base_path(),'/');
        abort_unless(str_starts_with($base,'/'),503,self::ERROR);
        foreach(['public/js'=>'.js','public/css'=>'.css'] as $relative=>$suffix){
            $directory=$base.'/'.$relative;$cursor='';$exists=true;
            foreach(explode('/',$directory) as $part){
                if($part==='')continue;abort_unless($part!=='.'&&$part!=='..',503,self::ERROR);$cursor.='/'.$part;
                clearstatcache(true,$cursor);$stat=@lstat($cursor);
                if($stat===false){$exists=false;break;}
                abort_unless(($stat['mode']&0170000)===0040000,503,self::ERROR);
            }
            if(!$exists)continue;
            clearstatcache(true,$directory);$directoryBefore=@lstat($directory);
            $names=@scandir($directory);abort_unless(is_array($names),503,self::ERROR);
            foreach($names as $name){
                if(!str_starts_with($name,'dashboard-')||!str_ends_with($name,$suffix))continue;
                $relativePath=$relative.'/'.$name;
                // Credentials/private names are excluded before a file stat/open.
                if(preg_match('#(?:^|/)(?:\.env(?:\..*)?|firebase(?:_credentials)?\.json|service[-_]account[^/]*\.json|[^/]+\.(?:key|pem)|error_log)$#i',$relativePath))continue;
                abort_unless($this->guiPath($relativePath)&&!isset($seen[strtolower($relativePath)])&&count($result)<2000,503,self::ERROR);
                $path=$directory.'/'.$name;clearstatcache(true,$path);$before=@lstat($path);
                abort_unless(is_array($before)&&($before['mode']&0170000)===0100000&&$before['nlink']===1
                    &&$before['size']>=0&&$before['size']<=8388608&&$total+$before['size']<=67108864,503,self::ERROR);
                $stream=@fopen($path,'rb');abort_unless(is_resource($stream),503,self::ERROR);
                try{
                    $opened=fstat($stream);abort_unless(is_array($opened)&&$this->identity($before)===$this->identity($opened),503,self::ERROR);
                    $bytes=stream_get_contents($stream,8388609);$after=fstat($stream);clearstatcache(true,$path);$named=@lstat($path);
                    abort_unless(is_string($bytes)&&strlen($bytes)===$before['size']&&is_array($after)&&is_array($named)
                        &&$this->identity($before)===$this->identity($after)&&$this->identity($after)===$this->identity($named),503,self::ERROR);
                }finally{fclose($stream);}
                $total+=strlen($bytes);$seen[strtolower($relativePath)]=true;
                $result[$relativePath]=hash('sha256',str_replace("\r\n","\n",$bytes));
            }
            clearstatcache(true,$directory);$directoryAfter=@lstat($directory);
            abort_unless(is_array($directoryBefore)&&is_array($directoryAfter)
                &&$this->identity($directoryBefore)===$this->identity($directoryAfter),503,self::ERROR);
        }
        ksort($result,SORT_STRING);return $result;
    }

    private function sameGui(array $expected,array $actual): bool
    {
        ksort($expected,SORT_STRING);ksort($actual,SORT_STRING);return $expected===$actual;
    }

    private function checkedStat(string $path,bool $directory=false): array
    {
        clearstatcache(true,$path);$stat=@lstat($path);
        abort_unless(is_array($stat)&&($stat['mode']&0170000)===($directory?0040000:0100000)
            &&$stat['uid']===0&&($stat['mode']&0022)===0&&($directory||$stat['nlink']===1),503,self::ERROR);
        return $stat;
    }

    private function identity(array $stat): array
    {
        return array_intersect_key($stat,array_flip(['dev','ino','mode','uid','gid','nlink','size','mtime','ctime']));
    }

    private function directory(): ?string
    {
        $path=storage_path('app/desktop-updates');
        // The fixed publication directory may be absent before first release.
        // Never follow symlink ancestors into another application's storage.
        $parent=dirname($path);$parts=explode('/',str_replace('\\','/',$parent));$current='';
        abort_unless(str_starts_with($parent,'/'),503,self::ERROR);
        foreach($parts as $part){
            if($part==='')continue;$current.='/'.$part;
            clearstatcache(true,$current);$stat=@lstat($current);
            abort_unless(is_array($stat)&&($stat['mode']&0170000)===0040000,503,self::ERROR);
        }
        clearstatcache(true,$path);
        if(@lstat($path)===false)return null;
        $this->checkedStat($path,true);return $path;
    }

    /** Open and bind the exact checked inode; never trust a later path lookup. */
    private function open(string $path,int $maximum): array
    {
        $before=$this->checkedStat($path);abort_unless($before['size']>0&&$before['size']<=$maximum,503,self::ERROR);
        $stream=@fopen($path,'rb');abort_unless(is_resource($stream),503,self::ERROR);
        $opened=fstat($stream);
        if(!is_array($opened)||$this->identity($before)!==$this->identity($opened)){
            fclose($stream);abort(503,self::ERROR);
        }
        return [$stream,$before];
    }

    private function unchanged(string $path,$stream,array $before): void
    {
        $after=fstat($stream);$named=$this->checkedStat($path);
        abort_unless(is_array($after)&&$this->identity($before)===$this->identity($after)
            &&$this->identity($after)===$this->identity($named),503,self::ERROR);
    }

    private function release(?string $directory): ?array
    {
        if($directory===null)return null;
        $path=$directory.'/current.json';clearstatcache(true,$path);
        if(@lstat($path)===false)return null;
        [$stream,$before]=$this->open($path,524288);
        try{
            $bytes=stream_get_contents($stream,524289);abort_unless(is_string($bytes)&&strlen($bytes)===$before['size'],503,self::ERROR);
            $this->unchanged($path,$stream,$before);
        }finally{fclose($stream);}
        try{$object=json_decode($bytes,false,32,JSON_THROW_ON_ERROR);$release=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $error){abort(503,self::ERROR);}
        abort_unless(is_object($object)&&is_object($object->gui??null)
            &&$this->exact($release,['version','sequence','source_revision','source','installer','gui'])
            &&$this->validVersion($release['version'])
            &&is_int($release['sequence'])&&$release['sequence']>0&&$release['sequence']<=9007199254740991
            &&is_string($release['source_revision'])&&preg_match('/^[a-f0-9]{40}$/D',$release['source_revision'])
            &&$this->validSource($release['source'])&&$this->validGui($release['gui'])
            &&$this->exact($release['installer'],['sha256','bytes','path']),503,self::ERROR);
        $installer=$release['installer'];
        abort_unless(is_string($installer['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$installer['sha256'])
            &&is_int($installer['bytes'])&&$installer['bytes']>0&&$installer['bytes']<=self::MAX_BYTES
            &&$installer['path']==='/api/desktop-dashboard/update/installer/'.$installer['sha256'],503,self::ERROR);
        // The publisher verifies the SHA before atomically installing these root-only
        // bytes. Checking stat here avoids rereading a large installer every minute.
        $file=$this->checkedStat($directory.'/'.$installer['sha256'].'.exe');
        abort_unless($file['size']===$installer['bytes'],503,self::ERROR);
        return $release;
    }

    public function status(): array
    {
        $this->requireServer();$directory=$this->directory();$release=$this->release($directory);
        $guiBefore=$release!==null?$this->gui():[];
        $actual=$this->source->fingerprint();abort_unless($this->validSource($actual),503,self::ERROR);
        $guiAfter=$release!==null?$this->gui():[];
        $ready=$release!==null&&$this->sameSource($release['source'],$actual)
            &&$this->sameGui($release['gui'],$guiBefore)&&$this->sameGui($guiBefore,$guiAfter);
        $public=$release;if($public!==null)unset($public['gui']);
        return ['format'=>1,'poll_seconds'=>60,'state'=>$ready?'ready':'unavailable',
            'server_source'=>$actual,'release'=>$ready?$public:null];
    }

    /** The caller owns and must close the returned, already hashed descriptor. */
    public function installer(string $sha): array
    {
        $this->requireServer();abort_unless(preg_match('/^[a-f0-9]{64}$/D',$sha),404);
        $directory=$this->directory();$release=$this->release($directory);
        abort_unless($release!==null&&hash_equals($release['installer']['sha256'],$sha),404);
        abort_unless($this->sameSource($release['source'],$this->source->fingerprint())
            &&$this->sameGui($release['gui'],$this->gui()),409,self::ERROR);
        $path=$directory.'/'.$sha.'.exe';[$stream,$before]=$this->open($path,self::MAX_BYTES);
        try{
            abort_unless($before['size']===$release['installer']['bytes'],503,self::ERROR);
            $digest=hash_init('sha256');$read=hash_update_stream($digest,$stream,self::MAX_BYTES+1);
            abort_unless($read===$before['size']&&hash_equals($sha,hash_final($digest)),503,self::ERROR);
            $this->unchanged($path,$stream,$before);
            // Publication or server code may have changed while hashing. Never send
            // the old bytes as the installer for a newly active source.
            $freshSource=$this->source->fingerprint();$freshGui=$this->gui();
            abort_unless($release===$this->release($this->directory())
                &&$this->sameSource($release['source'],$freshSource)&&$this->sameGui($release['gui'],$freshGui),409,self::ERROR);
            $this->unchanged($path,$stream,$before);
            abort_unless(rewind($stream),503,self::ERROR);
            return ['stream'=>$stream,'bytes'=>$before['size'],'sha256'=>$sha];
        }catch(\Throwable $error){fclose($stream);throw $error;}
    }
}
