<?php
// This entry point is used exclusively by the bundled desktop application.
use Illuminate\Contracts\Console\Kernel;

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
$app->make(Kernel::class)->bootstrap();
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
