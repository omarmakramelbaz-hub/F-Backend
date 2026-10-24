<?php
/** Read the real download guards as the web account, without booting Laravel. */
namespace GoErpDownloadDiagnostic {
    final class Failure extends \RuntimeException {}
    final class Framework { public function version(): string { return \Illuminate\Foundation\Application::VERSION; } }

    function root(): string { return $GLOBALS['go_download_root']; }
    function fail(int $status=503): void { throw new Failure('', $status); }
    function fixedFile(string $path,int $limit): string
    {
        clearstatcache(true,$path);$before=@lstat($path);
        if(!is_array($before)||($before['mode']&0170000)!==0100000||$before['nlink']!==1
            ||$before['size']<1||$before['size']>$limit)fail();
        $raw=file_get_contents($path);clearstatcache(true,$path);$after=@lstat($path);
        if(!is_string($raw)||strlen($raw)!==$before['size']||$before!==$after)fail();
        return $raw;
    }
    function metadata(string $path): array
    {
        clearstatcache(true,$path);$value=@lstat($path);
        if(!is_array($value))return ['exists'=>false,'kind'=>null,'uid'=>null,'mode'=>null,'bytes'=>null,'readable'=>false];
        $kind=match($value['mode']&0170000){0040000=>'directory',0100000=>'file',0120000=>'link',default=>'other'};
        return ['exists'=>true,'kind'=>$kind,'uid'=>$value['uid'],'mode'=>sprintf('%04o',$value['mode']&07777),
            'bytes'=>$kind==='file'?$value['size']:null,'readable'=>$kind!=='link'&&is_readable($path)];
    }
    function stage(array &$report,string $name,callable $read)
    {
        try{$result=$read();$report['checks'][$name]=['ok'=>true,'http_status'=>null];return $result;}
        catch(\Throwable $error){$code=$error instanceof Failure?$error->getCode():503;
            $report['checks'][$name]=['ok'=>false,'http_status'=>is_int($code)&&$code>=400&&$code<=599?$code:503];return null;}
    }
    function source($value): ?array
    {
        if(!is_array($value)||count($value)!==4||array_diff(array_keys($value),['format','files','sha256','framework'])
            ||($value['format']??null)!==1||!is_int($value['files']??null)||$value['files']<1||$value['files']>20000
            ||!is_string($value['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$value['sha256'])
            ||!is_string($value['framework']??null)||!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$value['framework']))return null;
        return $value;
    }
    function inspect(string $base): array
    {
        $GLOBALS['go_download_root']=$base;$GLOBALS['go_download_flags']=[];
        $blocked=null;$buffer=ob_get_level();ob_start();
        set_error_handler(function($level,$message)use(&$blocked,$base){
            if(!(error_reporting()&$level))return false;
            // A failed source read can identify a public code path, never bytes.
            if(str_starts_with($message,'file_get_contents('.$base.'/')){
                $tail=substr($message,strlen('file_get_contents('.$base.'/'));
                $relative=explode(')', $tail,2)[0];
                if(strlen($relative)<=240&&preg_match('#^(?:app|bootstrap|config|database|desktop|resources|routes|public)/[A-Za-z0-9_.@/-]+$#D',$relative)
                    &&!str_contains($relative,'..')&&!preg_match('#(?:^|/)(?:\.env|firebase|service[-_]account)|\.(?:pem|key)$#i',$relative))$blocked=$relative;
            }
            throw new Failure('',503);
        });
        $report=['format'=>1,'kind'=>'go-erp-download-diagnostic','ok'=>true,'mode'=>'read_only',
            'effective_uid'=>function_exists('posix_geteuid')?posix_geteuid():null,
            'configuration'=>['source'=>null,'enabled'=>null,'local'=>null],
            'publication'=>['directory'=>null,'manifest'=>null,'installer'=>null],
            'checks'=>[],'actual_source'=>null,'release_source'=>null,'source_matches'=>null,
            'gui'=>['actual_files'=>null,'release_files'=>null,'matches'=>null],
            'status'=>['state'=>'error','version'=>null],'blocked_source'=>null,
            'application_writes'=>false,'database_accessed'=>false,'credentials_disclosed'=>false];
        try{
            $loaded=stage($report,'autoload',function()use($base){require $base.'/vendor/autoload.php';return true;});
            if($loaded!==true)return $report;
            $configured=stage($report,'configuration',function()use($base,&$report){
                $cache=$base.'/bootstrap/cache/config.php';
                if(@lstat($cache)!==false){
                    $raw=fixedFile($cache,4*1024*1024);
                    $value=(static function($path){return require $path;})($cache);
                    if(fixedFile($cache,4*1024*1024)!==$raw||!is_array($value)||!is_array($value['desktop_dashboard']??null))fail();
                    $flags=$value['desktop_dashboard'];$report['configuration']['source']='cache';
                }else{
                    \Dotenv\Dotenv::create(\Illuminate\Support\Env::getRepository(),$base,'.env')->safeLoad();
                    $path=$base.'/config/desktop_dashboard.php';$raw=fixedFile($path,16384);
                    $flags=(static function($path){return require $path;})($path);
                    if(fixedFile($path,16384)!==$raw||!is_array($flags))fail();
                    $report['configuration']['source']='environment';
                }
                foreach(['enabled','local'] as $key)$report['configuration'][$key]=is_bool($flags[$key]??null)?$flags[$key]:null;
                $GLOBALS['go_download_flags']=$flags;return true;
            });
            if($configured!==true)return $report;
            $classes=stage($report,'classes',function()use($base){
                foreach(['DesktopDashboardSource','DesktopDashboardUpdates'] as $name){
                    $class='App\\Services\\Dashboard\\'.$name;
                    if(!class_exists($class,false))require $base.'/app/Services/Dashboard/'.$name.'.php';
                }return true;
            });
            if($classes!==true)return $report;
            $folder=$base.'/storage/app/desktop-updates';
            $report['publication']['directory']=metadata($folder);
            $report['publication']['manifest']=metadata($folder.'/current.json');
            $source=new \App\Services\Dashboard\DesktopDashboardSource;
            $updates=new \App\Services\Dashboard\DesktopDashboardUpdates($source);
            $private=static fn($name,...$args)=>(new \ReflectionMethod($updates,$name))->invoke($updates,...$args);
            $directory=stage($report,'directory',fn()=>$private('directory'));
            $release=stage($report,'release',fn()=>$private('release',$directory));
            if(is_array($release)){
                $report['release_source']=source($release['source']??null);
                $report['gui']['release_files']=count($release['gui']);
                $report['publication']['installer']=metadata($folder.'/'.$release['installer']['sha256'].'.exe');
            }
            $gui=stage($report,'gui',fn()=>$private('gui'));
            if(is_array($gui)){
                $report['gui']['actual_files']=count($gui);
                if(is_array($release))$report['gui']['matches']=$private('sameGui',$release['gui'],$gui);
            }
            $actual=stage($report,'source',fn()=>$source->fingerprint());$report['actual_source']=source($actual);
            if($report['actual_source']!==null&&$report['release_source']!==null)
                $report['source_matches']=$private('sameSource',$report['release_source'],$report['actual_source']);
            $status=stage($report,'status',fn()=>$updates->status());
            if(is_array($status)&&in_array($status['state']??null,['ready','unavailable'],true)){
                $report['status']['state']=$status['state'];
                $version=$status['release']['version']??null;
                if(is_string($version)&&preg_match('/^0\.4\.4-preview\.[1-9][0-9]{0,15}$/D',$version))$report['status']['version']=$version;
            }
            $report['blocked_source']=$blocked;
            return $report;
        }finally{
            restore_error_handler();while(ob_get_level()>$buffer)ob_end_clean();
            unset($GLOBALS['go_download_flags'],$GLOBALS['go_download_root']);
        }
    }
    function main(): int
    {
        if(PHP_SAPI!=='cli'||explode('.',gethostname()?:'')[0]!=='srv1836524'){
            echo "GO_ERP_DOWNLOAD_DIAGNOSTIC_UNAVAILABLE\n";return 1;
        }
        try{$report=inspect('/home/fasakha/public_html');
            echo 'GO_ERP_DOWNLOAD_DIAGNOSTIC '.json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            return $report['checks']['autoload']['ok']&&($report['checks']['configuration']['ok']??false)?0:1;
        }catch(\Throwable $error){echo "GO_ERP_DOWNLOAD_DIAGNOSTIC_UNAVAILABLE\n";return 1;}
    }
}
namespace {
    // Composer loads helpers, not providers; these fixed adapters avoid creating
    // a Laravel container, logger, cache, session or database connection.
    function base_path($path=''){return \GoErpDownloadDiagnostic\root().($path!==''?'/'.$path:'');}
    function storage_path($path=''){return base_path('storage').($path!==''?'/'.$path:'');}
    function config($key,$default=null){return str_starts_with($key,'desktop_dashboard.')?($GLOBALS['go_download_flags'][substr($key,18)]??$default):$default;}
    function env($key,$default=null){return \Illuminate\Support\Env::get($key,$default);}
    function app($abstract=null,$parameters=[]){if($abstract!==null)\GoErpDownloadDiagnostic\fail();return new \GoErpDownloadDiagnostic\Framework;}
    function abort($code,$message='',$headers=[]){\GoErpDownloadDiagnostic\fail((int)$code);}
    function abort_unless($value,$code,$message='',$headers=[]){if(!$value)abort($code);}
    function abort_if($value,$code,$message='',$headers=[]){if($value)abort($code);}
    if(!defined('GO_ERP_DOWNLOAD_DIAGNOSTIC_LIBRARY'))exit(\GoErpDownloadDiagnostic\main());
}
