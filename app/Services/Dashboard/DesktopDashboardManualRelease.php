<?php
namespace App\Services\Dashboard;

/** Owner requests are data in a fixed spool; only the root service runs builds. */
class DesktopDashboardManualRelease
{
    public const ERROR='تعذر التواصل مع خدمة تحديث التطبيق. حاول مرة أخرى بعد قليل.';
    private const SAFE=9007199254740991;
    private const ACTIVE=['queued','running','waiting-ci','publishing'];

    protected function root(): string { return '/var/lib/go-erp-manual-updates'; }
    protected function now(): int { return time(); }

    private function owner($actor)
    {
        abort_unless(config('desktop_dashboard.enabled')===true&&config('desktop_dashboard.local')!==true,404);
        return app(MetaInboxAccess::class)->owner($actor);
    }

    public function canRequest($actor): bool
    {
        try{$this->owner($actor);return true;}catch(\Throwable $error){return false;}
    }

    private function identity(array $info): array
    {
        return array_intersect_key($info,array_flip(['dev','ino','mode','uid','gid','nlink','size','mtime','ctime']));
    }

    private function stat(string $path): ?array
    {
        clearstatcache(true,$path);$info=@lstat($path);return is_array($info)?$info:null;
    }

    private function paths(): array
    {
        abort_unless(function_exists('posix_geteuid'),503,self::ERROR);
        $root=$this->root();$current='';
        abort_unless(str_starts_with($root,'/')&&!str_contains($root,'..'),503,self::ERROR);
        foreach(explode('/',$root) as $part){
            if($part==='')continue;$current.='/'.$part;$info=$this->stat($current);
            $sticky=$current!==$root&&is_array($info)&&($info['mode']&01000)!==0;
            abort_unless(is_array($info)&&($info['mode']&0170000)===0040000&&$info['uid']===0
                &&(($info['mode']&0022)===0||$sticky),503,self::ERROR);
        }
        foreach([$root=>[0,0755],$root.'/requests'=>[posix_geteuid(),0700],$root.'/shared'=>[0,0750]] as $path=>$wanted){
            $info=$this->stat($path);
            abort_unless(is_array($info)&&($info['mode']&0170000)===0040000
                &&$info['uid']===$wanted[0]&&($info['mode']&0777)===$wanted[1],503,self::ERROR);
        }
        return [$root.'/requests',$root.'/shared'];
    }

    private function read(string $path,int $owner,int $mode,bool $optional=false): ?array
    {
        $before=$this->stat($path);if($before===null&&$optional)return null;
        abort_unless(is_array($before)&&($before['mode']&0170000)===0100000&&$before['uid']===$owner
            &&$before['nlink']===1&&($before['mode']&0777)===$mode
            &&$before['size']>0&&$before['size']<=8192,503,self::ERROR);
        $stream=@fopen($path,'rb');abort_unless(is_resource($stream),503,self::ERROR);
        try{
            $opened=fstat($stream);$raw=stream_get_contents($stream,8193);$after=fstat($stream);$named=$this->stat($path);
            abort_unless(is_string($raw)&&strlen($raw)===$before['size']&&is_array($opened)&&is_array($after)
                &&is_array($named)&&$this->identity($before)===$this->identity($opened)
                &&$this->identity($before)===$this->identity($after)&&$this->identity($before)===$this->identity($named),503,self::ERROR);
        }finally{fclose($stream);}
        try{$value=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(\Throwable $error){abort(503,self::ERROR);}
        abort_unless(is_array($value),503,self::ERROR);return $value;
    }

    private function exact(array $value,array $keys): bool
    {
        return count($value)===count($keys)&&array_diff(array_keys($value),$keys)===[];
    }

    private function nonce($value): bool { return is_string($value)&&(bool)preg_match('/^[a-f0-9]{32}$/D',$value); }
    private function integer($value,int $minimum=0): bool { return is_int($value)&&$value>=$minimum&&$value<=self::SAFE; }

    private function envelope(array $value): array
    {
        abort_unless($this->exact($value,['format','kind','nonce','actor_id','requested_at'])
            &&$value['format']===1&&$value['kind']==='go-erp-manual-release-request'
            &&$this->nonce($value['nonce'])&&$this->integer($value['actor_id'],1)
            &&$this->integer($value['requested_at'],1),503,self::ERROR);
        return $value;
    }

    private function shared(string $directory): array
    {
        $value=$this->read($directory.'/status.json',0,0640);
        abort_unless($this->exact($value,['format','kind','state','nonce','updated_at','version','code'])
            &&$value['format']===1&&$value['kind']==='go-erp-manual-release-status'
            &&in_array($value['state'],['idle',...self::ACTIVE,'ready','failed'],true)
            &&($value['nonce']===null||$this->nonce($value['nonce']))&&$this->integer($value['updated_at'],1)
            &&($value['version']===null||(is_string($value['version'])
                &&preg_match('/^0\.4\.4-preview\.[1-9][0-9]{0,14}$/D',$value['version'])))
            &&($value['code']===null||(is_string($value['code'])
                &&preg_match('/^GO_ERP_[A-Z0-9_]{1,100}$/D',$value['code']))),503,self::ERROR);
        return $value;
    }

    public function status($actor): array
    {
        $this->owner($actor);[, $shared]=$this->paths();return $this->shared($shared);
    }

    private function locked(string $directory,callable $action): array
    {
        $path=$directory.'/request.lock';$stream=@fopen($path,'x+b');
        if(is_resource($stream)){abort_unless(@chmod($path,0600),503,self::ERROR);}
        else{
            $named=$this->stat($path);
            abort_unless(is_array($named)&&($named['mode']&0170000)===0100000
                &&$named['uid']===posix_geteuid()&&$named['nlink']===1&&($named['mode']&0777)===0600,503,self::ERROR);
            $stream=@fopen($path,'r+b');
        }
        abort_unless(is_resource($stream),503,self::ERROR);
        try{
            $opened=fstat($stream);$named=$this->stat($path);
            abort_unless(is_array($opened)&&is_array($named)&&($opened['mode']&0170000)===0100000
                &&$opened['uid']===posix_geteuid()&&$opened['nlink']===1&&($opened['mode']&0777)===0600
                &&$this->identity($opened)===$this->identity($named)&&flock($stream,LOCK_EX|LOCK_NB),503,self::ERROR);
            $verify=function()use($stream,$path,$opened){$current=fstat($stream);$named=$this->stat($path);
                abort_unless(is_array($current)&&is_array($named)&&$this->identity($opened)===$this->identity($current)
                    &&$this->identity($opened)===$this->identity($named),503,self::ERROR);};
            $verify();$result=$action($verify);$verify();return $result;
        }finally{flock($stream,LOCK_UN);fclose($stream);}
    }

    private function write(string $path,array $value): void
    {
        $temporary=dirname($path).'/.request-'.bin2hex(random_bytes(16)).'.tmp';
        $stream=@fopen($temporary,'x+b');abort_unless(is_resource($stream),503,self::ERROR);
        try{
            abort_unless(@chmod($temporary,0600),503,self::ERROR);
            $raw=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";$offset=0;
            while($offset<strlen($raw)){$count=fwrite($stream,substr($raw,$offset));abort_unless(is_int($count)&&$count>0,503,self::ERROR);$offset+=$count;}
            abort_unless(fflush($stream)&&(!function_exists('fsync')||fsync($stream)),503,self::ERROR);
            abort_unless(@rename($temporary,$path),503,self::ERROR);
        }finally{fclose($stream);if(is_file($temporary))@unlink($temporary);}
    }

    public function request($actor,string $nonce): array
    {
        $owner=$this->owner($actor);abort_unless($this->nonce($nonce),422,'طلب التحديث غير صالح.');
        $id=(int)$owner->getKey();abort_unless($this->integer($id,1),403);
        [$requests,$shared]=$this->paths();
        return $this->locked($requests,function($verify)use($requests,$shared,$nonce,$id){
            $status=$this->shared($shared);
            $pending=$this->read($requests.'/pending.json',posix_geteuid(),0600,true);
            $last=$this->read($requests.'/last-request.json',posix_geteuid(),0600,true);
            if($pending!==null)$pending=$this->envelope($pending);
            if($last!==null)$last=$this->envelope($last);
            foreach([$pending,$last] as $previous){
                if($previous!==null&&hash_equals($previous['nonce'],$nonce)){
                    abort_unless($previous['actor_id']===$id,403);
                    return ['request'=>$previous,'idempotent'=>true,'status'=>$status];
                }
            }
            if($status['nonce']===$nonce){
                return ['request'=>null,'idempotent'=>true,'status'=>$status];
            }
            abort_unless($pending===null&&!in_array($status['state'],self::ACTIVE,true),409,'يوجد تحديث قيد التنفيذ بالفعل.');
            $now=$this->now();$previous=$last['requested_at']??($status['nonce']!==null?$status['updated_at']:0);
            abort_unless($previous<=$now&&$now-$previous>=300,429,'انتظر خمس دقائق قبل طلب تحديث آخر.');
            $request=['format'=>1,'kind'=>'go-erp-manual-release-request','nonce'=>$nonce,'actor_id'=>$id,'requested_at'=>$now];
            $verify();
            // Commit the queue first. A retry recovers this exact nonce even if
            // recording the web ledger is interrupted. Root durably dedupes it.
            $this->write($requests.'/pending.json',$request);
            $this->write($requests.'/last-request.json',$request);
            return ['request'=>$request,'idempotent'=>false,'status'=>$status];
        });
    }
}
