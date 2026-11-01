<?php
namespace App\Services\OwnerDashboards;

use RuntimeException;

final class Bootstrap
{
    public static function read(string $file,bool $initializing=false): array
    {
        if(is_link($file)||!is_file($file))throw new RuntimeException('GO_OWNER_RUNTIME_MISSING');
        $r=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
        if(($r['format']??null)!==1||!preg_match('/^[0-9a-f-]{36}$/D',$r['id']??'')
            ||!preg_match('/^[0-9a-f]{16}$/D',$r['slug']??'')
            ||$r['prefix']!==''||!preg_match('/^go-'.preg_quote($r['slug'],'/').'\.[a-z0-9.-]+$/D',$r['host']??'')
            ||$r['origin']!=='https://'.$r['host']
            ||$r['base']!=='/home/fasakha/go-owner-dashboards/tenants/'.$r['id']
            ||$r['public']!=='/home/fasakha/public_html/public/dashboards/'.$r['slug']
            ||dirname($file)!==$r['base']||realpath($r['base'])!==$r['base']
            ||realpath($r['public'])!==$r['public'])throw new RuntimeException('GO_OWNER_RUNTIME_INVALID');
        if(!$initializing){
            if($r['state']!=='active'||Runtime::blocked($r['id']))throw new RuntimeException('GO_OWNER_DASHBOARD_DISABLED');
            $cache=$r['base'].'/bootstrap/cache/config.php';
            if(is_link($cache)||!is_file($cache)||!hash_equals($r['config_sha256']??'',hash_file('sha256',$cache)))
                throw new RuntimeException('GO_OWNER_CONFIG_NOT_READY');
        }
        return $r;
    }

    public static function application(array $r): Application
    {
        $app=new Application($r['base'],$r['public']);
        $app->instance('owner.dashboard.runtime',$r);
        $app->instance('path.public',$r['public']);
        $app->useStoragePath($r['base'].'/storage');
        $app->singleton(\Illuminate\Contracts\Http\Kernel::class,\App\Http\Kernel::class);
        $app->singleton(\Illuminate\Contracts\Console\Kernel::class,\App\Console\Kernel::class);
        $app->singleton(\Illuminate\Contracts\Debug\ExceptionHandler::class,\App\Exceptions\Handler::class);
        return $app;
    }
}
