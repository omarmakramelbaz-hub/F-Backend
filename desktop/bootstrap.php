<?php
// This entry point is used exclusively by the bundled desktop application.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

if (getenv('DESKTOP_DASHBOARD_LOCAL') !== 'true') throw new RuntimeException('Desktop runtime is disabled.');
$loader = require dirname(__DIR__).'/vendor/autoload.php';
$storage = getenv('DESKTOP_DASHBOARD_STORAGE');
if (!$storage || !is_dir($storage)) throw new RuntimeException('Desktop storage is unavailable.');
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->useStoragePath($storage);
// Laravel 8 otherwise treats a Windows drive path as relative to the application directory.
if(preg_match('/^[a-z]:[\\\\\/]/i',$storage)){
    $drive=substr($storage,0,2);
    $app->addAbsoluteCachePathPrefix($drive.'\\');
    $app->addAbsoluteCachePathPrefix($drive.'/');
}
// The distribution never loads the production .env or production configuration caches.
$app->useEnvironmentPath($storage.'/private');
if (PHP_SAPI === 'cli-server') {
    // Match Laravel's HTTP entry point: providers see this actual request.
    // Console bootstrap also discovers Artisan commands and eagerly loads every
    // deferred provider, work that must not repeat on each browser request.
    $app->instance('request', Request::capture());
    $app->make(HttpKernel::class)->bootstrap();
} else {
    $app->make(Kernel::class)->bootstrap();
}
// Native remember tokens are private device state, not synchronized user rows.
// A snapshot intentionally redacts remember_token, so stock database tokens would
// invalidate a checked remember cookie on every data generation replacement.
if (!class_exists('FasakhanstaDesktopRememberProvider', false)) {
    final class FasakhanstaDesktopRememberProvider extends \Illuminate\Auth\EloquentUserProvider
    {
        private function transientToken($user,?string $token)
        {
            $user->setRememberToken($token);
            if(method_exists($user,'syncOriginalAttribute'))$user->syncOriginalAttribute($user->getRememberTokenName());
            return $user;
        }
        public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
        {
            $user=parent::retrieveByCredentials($credentials);
            // An old synchronized/legacy token must never skip generation of a
            // new private capability when the user explicitly checks remember.
            return $user?$this->transientToken($user,null):null;
        }
        private function binding($user): ?array
        {
            $device=(string)config('desktop_dashboard.device_id');$actor=$user->getAuthIdentifier();
            if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$device)
                ||!is_numeric($actor)||(int)$actor<1
                ||!in_array($user->account_type,['admin','vendor','resturant_owner'],true)
                ||in_array($user->status??null,['disabled','declined'],true))return null;
            if(getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL')==='true'){
                $workspace=app(\App\Services\Dashboard\DesktopDashboardWorkspaceLocalAuthority::class);
                if($workspace->selected())return $workspace->rememberBinding($user);
            }
            $state=\Illuminate\Support\Facades\DB::table('desktop_dashboard_local_state')->where('device_id',$device)->first(['actor_id']);
            if(!$state||(int)$state->actor_id!==(int)$actor)return null;
            $password=$user->getAuthPassword();
            if(!is_string($password)||strlen($password)<40||str_starts_with($password,'!desktop-disabled'))return null;
            return ['format'=>1,'device'=>$device,'actor'=>(int)$actor,
                'password_binding'=>hash_hmac('sha256',$password,app('encrypter')->getKey())];
        }
        private function directory(): string
        {
            $root=getenv('DESKTOP_DASHBOARD_AUTH_STORAGE');
            if(!is_string($root)||$root===''||basename(str_replace('\\','/',$root))!=='auth'
                ||(!str_starts_with($root,'/')&&!preg_match('/^[a-z]:[\\\\\/]/i',$root))
                ||is_link($root)||!is_dir(dirname($root)))throw new \RuntimeException('Desktop remember storage unavailable.');
            if(!is_dir($root)&&!mkdir($root,0700)&&!is_dir($root))throw new \RuntimeException('Desktop remember storage unavailable.');
            $real=realpath($root);$parent=realpath(dirname($root));
            if(!$real||!$parent||is_link($root)||dirname($real)!==$parent)throw new \RuntimeException('Desktop remember storage unavailable.');
            return $real;
        }
        private function locked(array $binding,callable $work)
        {
            $file=$this->directory().DIRECTORY_SEPARATOR.$binding['device'].'-'.$binding['actor'].'.enc';$lockPath=$file.'.lock';
            if(is_link($file)||is_link($lockPath))throw new \RuntimeException('Desktop remember storage invalid.');
            $lock=fopen($lockPath,'c+b');if(!$lock)throw new \RuntimeException('Desktop remember lock unavailable.');
            @chmod($lockPath,0600);
            try{
                if(!flock($lock,LOCK_EX|LOCK_NB))throw new \RuntimeException('Desktop remember lock busy.');
                try{return $work($file);}finally{flock($lock,LOCK_UN);}
            }finally{fclose($lock);}
        }
        public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
        {
            try{
                if(!is_string($token)||!preg_match('/^[a-zA-Z0-9]{40,128}$/D',$token))return null;
                $user=parent::retrieveById($identifier);if(!$user||!($binding=$this->binding($user)))return null;
                $record=$this->locked($binding,function($file){
                    if(!is_file($file)||is_link($file)||filesize($file)>8192)return null;
                    $bytes=file_get_contents($file);if(!is_string($bytes))return null;
                    return json_decode(app('encrypter')->decryptString($bytes),true,8,JSON_THROW_ON_ERROR);
                });
                if(!is_array($record)||count($record)!==7
                    ||array_diff(array_keys($record),['format','device','actor','password_binding','token_proof','issued_at','expires_at'])
                    ||array_intersect_key($record,$binding)!==$binding
                    ||!is_string($record['token_proof']??null)||!preg_match('/^[a-f0-9]{64}$/D',$record['token_proof'])
                    ||!is_int($record['issued_at']??null)||!is_int($record['expires_at']??null)
                    ||$record['issued_at']>time()+60||$record['expires_at']<=time()
                    ||$record['expires_at']-$record['issued_at']!==576000*60
                    ||!hash_equals($record['token_proof'],hash_hmac('sha256',$token,app('encrypter')->getKey())))return null;
                return $this->transientToken($user,$token);
            }catch(\Throwable $error){return null;}
        }
        public function updateRememberToken(\Illuminate\Contracts\Auth\Authenticatable $user, #[\SensitiveParameter] $token)
        {
            try{
                if(!is_string($token)||!preg_match('/^[a-zA-Z0-9]{40,128}$/D',$token)||!($binding=$this->binding($user)))
                    throw new \RuntimeException('Desktop remember binding unavailable.');
                $now=time();$record=$binding+['token_proof'=>hash_hmac('sha256',$token,app('encrypter')->getKey()),
                    'issued_at'=>$now,'expires_at'=>$now+576000*60];
                $encrypted=app('encrypter')->encryptString(json_encode($record,JSON_THROW_ON_ERROR));
                $this->locked($binding,function($file)use($encrypted){
                    $temporary=$file.'.'.bin2hex(random_bytes(8)).'.tmp';$handle=fopen($temporary,'xb');
                    if(!$handle)throw new \RuntimeException('Desktop remember write unavailable.');
                    @chmod($temporary,0600);
                    try{
                        try{
                            $offset=0;while($offset<strlen($encrypted)){
                                $written=fwrite($handle,substr($encrypted,$offset));if(!$written)throw new \RuntimeException('Desktop remember write incomplete.');$offset+=$written;
                            }
                            if(!fflush($handle)||(function_exists('fsync')&&!fsync($handle)))throw new \RuntimeException('Desktop remember write incomplete.');
                        }finally{fclose($handle);}
                        if(!rename($temporary,$file))throw new \RuntimeException('Desktop remember commit incomplete.');
                    }finally{if(is_file($temporary))unlink($temporary);}
                });
                // SessionGuard needs the original generated token to build its
                // encrypted recaller, but no synchronized user row is written.
                $this->transientToken($user,$token);
            }catch(\Throwable $error){throw new \RuntimeException('تعذر حفظ تذكر الدخول على الجهاز؛ أعد المحاولة.');}
        }
        public function forgetRemembered($user): void
        {
            if(!$user||!getenv('DESKTOP_DASHBOARD_AUTH_STORAGE'))return;
            $device=(string)config('desktop_dashboard.device_id');$actor=$user->getAuthIdentifier();
            if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$device)||!is_numeric($actor)||(int)$actor<1)return;
            try{
                $this->locked(['device'=>$device,'actor'=>(int)$actor],function($file){
                    if(is_file($file)&&!unlink($file))throw new \RuntimeException('Desktop remember revoke incomplete.');
                });
            }catch(\Throwable $error){throw new \RuntimeException('تعذر إلغاء تذكر الدخول على الجهاز؛ أعد المحاولة.');}
        }
        public function rehashPasswordIfRequired(\Illuminate\Contracts\Auth\Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force=false)
        {
            // The cached server hash remains authoritative across generations.
            // Validating the password still uses the normal inherited provider.
        }
    }
}
if(config('desktop_dashboard.local')){
    $app['auth']->provider('desktop-profile',function($app,array $config){
        if(!config('desktop_dashboard.local'))return new \Illuminate\Auth\EloquentUserProvider($app['hash'],$config['model']);
        return new FasakhanstaDesktopRememberProvider($app['hash'],$config['model']);
    });
    config(['auth.providers.admins.driver'=>'desktop-profile']);
    $app['events']->listen(\Illuminate\Auth\Events\Login::class,function($event){
        if(!config('desktop_dashboard.local')||$event->guard!=='admin'||$event->remember)return;
        $provider=auth('admin')->getProvider();
        if($provider instanceof FasakhanstaDesktopRememberProvider)$provider->forgetRemembered($event->user);
    });
    foreach([\Illuminate\Auth\Events\Logout::class,\Illuminate\Auth\Events\CurrentDeviceLogout::class] as $logoutEvent)
        $app['events']->listen($logoutEvent,function($event){
            if(!config('desktop_dashboard.local')||$event->guard!=='admin')return;
            $provider=auth('admin')->getProvider();
            if($provider instanceof FasakhanstaDesktopRememberProvider)$provider->forgetRemembered($event->user);
        });
}

// Native CLI reconciliation also invokes original controller middleware. Register
// the captured HTTP aliases and priority before any such replay is dispatched.
$app->make(HttpKernel::class);
// Named image disks have nested roots. Keep their original media rows, and route their
// generated URLs to the corresponding files inside this generation's private public disk.
foreach(config('filesystems.disks',[]) as $name=>$disk){
    $base=str_replace('\\','/',storage_path('app/public'));$root=str_replace('\\','/',$disk['root']??'');
    if(($disk['driver']??'')==='local'&&($root===$base||str_starts_with($root,$base.'/'))){
        $relative=trim(substr($root,strlen($base)),'/');
        config(['filesystems.disks.'.$name.'.url'=>rtrim(config('app.url'),'/').'/storage'.($relative?'/'.$relative:'')]);
    }
}
return $app;
