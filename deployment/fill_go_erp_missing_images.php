<?php
// One-time, operator-approved substitutes at exactly eight previously diagnosed public paths.
use Illuminate\Support\Facades\DB;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardData,DesktopDashboardMedia};
require __DIR__.'/go_erp_media_file_repair.php';
$phase='arguments'; $changes=null; $reserve=str_repeat('x',131072);
register_shutdown_function(function()use(&$phase,&$changes,&$reserve){
    $fatal=error_get_last();
    if (!$fatal || !in_array($fatal['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)) return;
    $reserve=null;
    echo json_encode(['result'=>'GO_ERP_MISSING_IMAGES_FATAL','phase'=>$phase,
        'changes_committed'=>$changes['database_committed']??null,
        'backup'=>$changes['backup']??null,'instruction'=>'Check committed.json and failed.json before retrying.']).PHP_EOL;
});
try {
    if (PHP_SAPI!=='cli' || count($argv)!==4
        || !in_array($argv[2],['--check','--apply-missing-placeholders'],true)) throw new RuntimeException('GO_ERP_MISSING_IMAGES_ARGUMENTS');
    [$script,$root,$mode,$backupRoot]=$argv;
    if (realpath($root)!==$root || !is_file($root.'/vendor/autoload.php')) throw new RuntimeException('GO_ERP_MISSING_IMAGES_APPLICATION');
    require $root.'/vendor/autoload.php'; $app=require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($mode==='--check') DB::statement('SET SESSION TRANSACTION READ ONLY');
    $phase='device';
    $device=DB::table('desktop_dashboard_devices')->where('enabled',true)->where('actor_id',1)
        ->orderByDesc('enrolled_at')->orderByDesc('created_at')->first();
    if (!$device) throw new RuntimeException('GO_ERP_MISSING_IMAGES_DEVICE_UNAVAILABLE');
    $devices=app(DesktopDashboardDevices::class); $actor=$devices->actor($device);
    if ((int)$actor->id!==1 || !app(DesktopDashboardData::class)->allAdministration($actor))
        throw new RuntimeException('GO_ERP_MISSING_IMAGES_OWNER_REQUIRED');
    $branches=json_decode($device->branches,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($branches) || !$branches) throw new RuntimeException('GO_ERP_MISSING_IMAGES_BRANCHES');
    foreach ($branches as $branch) $devices->branch($device,$branch,$actor);
    if (config('media-library.path_generator')!==Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator::class)
        throw new RuntimeException('GO_ERP_MISSING_IMAGES_PATH_GENERATOR');
    $public=realpath(storage_path('app/public')); $assets=realpath(__DIR__.'/assets');
    if (!$public || !$assets) throw new RuntimeException('GO_ERP_MISSING_IMAGES_ROOT');
    foreach (['pending_vendor','users'] as $disk) {
        if (config('filesystems.disks.'.$disk.'.driver')!=='local'
            || realpath((string)config('filesystems.disks.'.$disk.'.root'))!==$public.'/'.$disk)
            throw new RuntimeException('GO_ERP_MISSING_IMAGES_DISK');
    }
    $repair=new GoErpMediaFileRepair(DB::connection()->getPdo(),$public,
        [DesktopDashboardMedia::class,'safePath'],DB::connection()->getTablePrefix().'media');
    $phase='plan'; $plans=[];
    foreach (GoErpMediaFileRepair::missingPaths() as $path) {
        $id=(int)explode('/',$path,3)[1]; $row=DB::table('media')->where('id',$id)->first();
        if (!$row) throw new RuntimeException('GO_ERP_MISSING_IMAGES_ROW_MISSING');
        $plans[]=$repair->placeholderPlan((array)$row,$path,$assets);
    }
    $summary=['result'=>'GO_ERP_MISSING_IMAGES_PLAN','mode'=>$mode,'target_count'=>count($plans),
        'new_placeholders'=>count(array_filter($plans,fn($p)=>!$p['already_placeholder'])),
        'already_identical'=>count(array_filter($plans,fn($p)=>$p['already_placeholder'])),
        'paths'=>GoErpMediaFileRepair::missingPaths(),'original_images_recovered'=>0,'offline_ready'=>false];
    if ($mode==='--apply-missing-placeholders') {
        // Printed before any writes, so loss of the final response remains observable.
        echo json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
        $phase='apply'; $changes=$repair->apply($plans,$backupRoot);
        $phase='verify';
        foreach ($plans as $plan) {
            $row=(array)DB::table('media')->where('id',$plan['before']['id'])->first();
            $verified=$repair->placeholderPlan($row,$plan['relative'],$assets);
            if (!$verified['already_placeholder'] || $row['mime_type']!==$verified['facts']['mime']
                || (int)$row['size']!==$verified['facts']['bytes']) throw new RuntimeException('GO_ERP_MISSING_IMAGES_VERIFY');
        }
        $summary['result']='GO_ERP_MISSING_IMAGES_APPLIED'; $summary['changes']=$changes;
        $summary['verified_placeholders']=count($plans);
        $summary['next_step']='Open GO ERP online, then Program > Prepare for offline work. Verify preparation before disconnecting.';
    }
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['result'=>'GO_ERP_MISSING_IMAGES_FAILED','phase'=>$phase,
        'code'=>$error instanceof RuntimeException && str_starts_with($error->getMessage(),'GO_ERP_')
            ?$error->getMessage():'GO_ERP_MISSING_IMAGES_RUNTIME_FAILED',
        'changes_committed'=>$changes['database_committed']??false,'backup'=>$changes['backup']??null,
        'type'=>get_class($error)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}
