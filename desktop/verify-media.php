<?php
try{
    $value=json_decode(stream_get_contents(STDIN,65536),true,32,JSON_THROW_ON_ERROR);
    $app=require __DIR__.'/bootstrap.php';
    $state=\Illuminate\Support\Facades\DB::table('desktop_dashboard_local_state')->where('device_id',$value['deviceId'])->first();
    $manifest=\Illuminate\Support\Facades\DB::table('desktop_dashboard_media_manifest')->where('device_id',$value['deviceId'])->first();
    if(!$state||!$manifest||$state->snapshot_id!==$value['snapshotId']||$manifest->snapshot_id!==$state->snapshot_id
        ||(int)$state->actor_id!==$value['actorId']||$state->schema_hash!==$value['schemaHash'])throw new RuntimeException('Snapshot binding changed.');
    $files=json_decode($manifest->files,true,128,JSON_THROW_ON_ERROR);
    if(!hash_equals($manifest->sha256,hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json($files))))throw new RuntimeException('Manifest binding changed.');
    \App\Services\Dashboard\DesktopDashboardMedia::verifyFiles($files);
    echo '{"verified":true}'.PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,"صور نسخة الجهاز غير مكتملة؛ سجلات الجهاز محفوظة لاسترجاع النسخة.\n");exit(1);}
