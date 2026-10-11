<?php
try{
    $value=json_decode(stream_get_contents(STDIN,65536),true,32,JSON_THROW_ON_ERROR);
    $app=require __DIR__.'/bootstrap.php';
    $state=\Illuminate\Support\Facades\DB::table('desktop_dashboard_local_state')->where('device_id',$value['deviceId'])->first();
    $manifest=\Illuminate\Support\Facades\DB::table('desktop_dashboard_media_manifest')->where('device_id',$value['deviceId'])->first();
    if(!$state||!$manifest||$state->snapshot_id!==$value['snapshotId']||$manifest->snapshot_id!==$state->snapshot_id
        ||(int)$state->actor_id!==$value['actorId']||$state->schema_hash!==$value['schemaHash'])throw new RuntimeException('Snapshot binding changed.');
    if(($value['previewOnly']??false)===true&&(($value['fullCoverage']??null)!==false
        ||json_decode($state->coverage,true,32,JSON_THROW_ON_ERROR)!==($value['coverage']??null)
        ||json_decode($state->branches,true,32,JSON_THROW_ON_ERROR)!==($value['branches']??null)))throw new RuntimeException('Preview scope binding changed.');
    if(isset($value['sourceFingerprint'])){
        $source=\Illuminate\Support\Facades\DB::table('desktop_dashboard_source_manifest')->where('device_id',$value['deviceId'])->first();
        if(!$source||$source->snapshot_id!==$state->snapshot_id
            ||!hash_equals($source->sha256,hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json($value['sourceFingerprint'])))
            ||json_decode($source->source,true)!==$value['sourceFingerprint']
            ||!app(\App\Services\Dashboard\DesktopDashboardSource::class)->matches($value['sourceFingerprint']))throw new RuntimeException('Source binding changed.');
    }
    $files=json_decode($manifest->files,true,128,JSON_THROW_ON_ERROR);
    if(!hash_equals($manifest->sha256,hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json($files))))throw new RuntimeException('Manifest binding changed.');
    if(($value['previewOnly']??false)===true){
        $canonical=array_map(function(array $part){ksort($part,SORT_STRING);return $part;},$files);
        $encoded=json_encode($canonical,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR);
        if(!is_string($value['mediaManifestHash']??null)||!hash_equals($value['mediaManifestHash'],hash('sha256',$encoded)))throw new RuntimeException('Preview media binding changed.');
    }
    \App\Services\Dashboard\DesktopDashboardMedia::verifyFiles($files);
    echo '{"verified":true}'.PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,"صور نسخة الجهاز غير مكتملة؛ سجلات الجهاز محفوظة لاسترجاع النسخة.\n");exit(1);}
