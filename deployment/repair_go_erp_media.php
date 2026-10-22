<?php
// CLI only. No application source replacement, deletion, or change outside media metadata.
use Illuminate\Support\Facades\DB;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardData,DesktopDashboardMedia};
require __DIR__.'/go_erp_media_file_repair.php';
$phase='arguments';
try {
    if (PHP_SAPI!=='cli' || count($argv)!==5 || !in_array($argv[2],['--check','--apply'],true)) throw new RuntimeException('GO_ERP_MEDIA_ARGUMENTS');
    [$script,$root,$mode,$recoveryRoot,$backupRoot]=$argv;
    if (realpath($root)!==$root || !is_file($root.'/vendor/autoload.php')) throw new RuntimeException('GO_ERP_MEDIA_APPLICATION');
    require $root.'/vendor/autoload.php'; $app=require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($mode==='--check') DB::statement('SET SESSION TRANSACTION READ ONLY');
    $phase='device';
    $device=DB::table('desktop_dashboard_devices')->where('enabled',true)->where('actor_id',1)
        ->orderByDesc('enrolled_at')->orderByDesc('created_at')->first();
    if (!$device) throw new RuntimeException('GO_ERP_MEDIA_DEVICE_UNAVAILABLE');
    $actor=app(DesktopDashboardDevices::class)->actor($device); $data=app(DesktopDashboardData::class);
    $branches=json_decode($device->branches,true,512,JSON_THROW_ON_ERROR);
    $queries=$data->queries($device,$actor,$branches);
    $dataset=$data->rows($queries); $mediaService=app(DesktopDashboardMedia::class);
    $manifest=$mediaService->manifest($dataset,$device,$actor,(string)Illuminate\Support\Str::uuid());
    $public=realpath(storage_path('app/public'));
    if (!$public) throw new RuntimeException('GO_ERP_MEDIA_PUBLIC_ROOT');
    $index=[];
    foreach ($dataset['media']??[] as $row) {
        $disk=$row['disk']; $diskRoot=realpath((string)config('filesystems.disks.'.$disk.'.root'));
        if (config('filesystems.disks.'.$disk.'.driver')!=='local' || !$diskRoot
            || !($diskRoot===$public || str_starts_with($diskRoot,$public.'/'))) continue;
        $prefix=trim(substr($diskRoot,strlen($public)),'/');
        $path=($prefix?$prefix.'/':'').$row['id'].'/'.$row['file_name'];
        if (isset($index[$path])) throw new RuntimeException('GO_ERP_MEDIA_DUPLICATE_SOURCE');
        $index[$path]=$row;
    }
    $phase='plan'; $plans=[]; $unresolved=[];
    $repair=new GoErpMediaFileRepair(DB::connection()->getPdo(),$public,[DesktopDashboardMedia::class,'safePath'],DB::connection()->getTablePrefix().'media');
    foreach ($manifest['issues'] as $issue) {
        $path=$issue['path']??'';
        if (($issue['area']??'public')!=='public' || !isset($index[$path])) { $unresolved[]=$issue; continue; }
        try { $plans[]=$repair->plan($index[$path],$path,is_dir($recoveryRoot)?realpath($recoveryRoot):null); }
        catch (Throwable $error) { $unresolved[]=['path'=>$path,'reason'=>$error instanceof RuntimeException?$error->getMessage():'GO_ERP_MEDIA_INSPECTION_FAILED']; }
    }
    $summary=['result'=>'GO_ERP_MEDIA_REPAIR_PLAN','mode'=>$mode,'issues_before'=>count($manifest['issues']),
        'normalizations'=>count(array_filter($plans,fn($p)=>$p['kind']==='normalize-extension')),
        'backup_restorations'=>count(array_filter($plans,fn($p)=>$p['kind']==='restore-same-backup-path')),
        'unresolved_count'=>count($unresolved),'unresolved'=>$unresolved];
    if ($mode==='--apply' && $plans) {
        $phase='apply'; $summary['changes']=$repair->apply($plans,$backupRoot);
        $phase='verify'; $dataset=$data->rows($queries);
        $verified=$mediaService->manifest($dataset,$device,$actor,(string)Illuminate\Support\Str::uuid());
        $summary['result']='GO_ERP_MEDIA_REPAIR_APPLIED';
        $summary['media_complete']=$verified['complete']; $summary['issues_remaining']=count($verified['issues']);
        $summary['remaining_issues']=$verified['issues']; $summary['available_files']=count($verified['files']);
    } else { $summary['media_complete']=$manifest['complete']; }
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['result'=>'GO_ERP_MEDIA_REPAIR_FAILED','phase'=>$phase,
        'code'=>$error instanceof RuntimeException && str_starts_with($error->getMessage(),'GO_ERP_')?$error->getMessage():'GO_ERP_MEDIA_REPAIR_RUNTIME_FAILED',
        'changes_committed'=>$summary['changes']['database_committed']??false,
        'backup'=>$summary['changes']['backup']??null,
        'type'=>get_class($error)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}
