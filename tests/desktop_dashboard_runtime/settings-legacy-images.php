<?php
// Actual original online upload -> scoped export/download -> separate database/storage import -> original Blade/static gateway.
(function()use($application,$pdo,$profile,$database,$device,$check,$deny){
    $savedStorage=storage_path();$savedConfig=['database.connections.mysql.database'=>config('database.connections.mysql.database'),'desktop_dashboard.local'=>config('desktop_dashboard.local'),
        'desktop_dashboard.device_id'=>config('desktop_dashboard.device_id'),'filesystems.disks.local.root'=>config('filesystems.disks.local.root'),'filesystems.disks.public.root'=>config('filesystems.disks.public.root')];
    $savedRows=DB::table('settings')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
    $serverRoot=$profile.'/legacy-images-server';$localRoot=$profile.'/legacy-images-local';
    foreach([$serverRoot,$localRoot] as $root)foreach(['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'] as $directory)mkdir($root.'/'.$directory,0700,true);
    $useStorage=function(string $root){app()->useStoragePath($root);config(['filesystems.disks.local.root'=>$root.'/app','filesystems.disks.public.root'=>$root.'/app/public']);app('filesystem')->forgetDisk(['local','public']);};
    $bytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $temporary=tempnam(sys_get_temp_dir(),'settings-legacy-upload-');file_put_contents($temporary,$bytes);$stage='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));$web=null;
    try{
        $useStorage($serverRoot);config(['desktop_dashboard.local'=>false]);
        $helper=new class {use \App\Http\Traits\UploadImageTrait;};
        $raw=$helper->uploadImg(new \Illuminate\Http\UploadedFile($temporary,'legacy.png','image/png',null,true),'settings');
        $check((bool)preg_match('#^/uploadedImages//settings-[0-9]+\.png$#D',$raw)&&is_file($serverRoot.'/app/public'.$raw),'the unchanged original legacy upload helper produces its exact historical doubled folder separator');
        $controller=new \App\Http\Controllers\Dashboard\SettingsController;
        $future=$controller->uploadImg(new \Illuminate\Http\UploadedFile($temporary,'future.png','image/png',null,true),'settings');
        $check((bool)preg_match('#^/uploadedImages/settings-[0-9]+\.png$#D',$future)&&is_file($serverRoot.'/app/public'.$future),'future original connected settings uploads use the same controlled folder with one separator');
        foreach(['logo','favicon','advertise_image'] as $slot)DB::table('settings')->where('name',$slot)->update(['payload'=>json_encode($slot==='logo'?$raw:'')]);
        $scope=DB::table('desktop_dashboard_devices')->where('id',$device)->first();$snapshot=app(\App\Services\Dashboard\DesktopDashboardBootstrap::class)->export($scope);
        $manifest=$snapshot['media'];$canonical=\App\Services\Dashboard\DesktopDashboardMedia::settingsPath($raw);
        $check($snapshot['coverage']['media']&&count($manifest)===1&&$manifest[0]['path']===$canonical
            &&collect($snapshot['tables']['settings']['rows'])->firstWhere('name','logo')['payload']===json_encode($raw),'legacy settings export canonicalizes only the media destination while preserving the exact original database setting');
        $media=app(\App\Services\Dashboard\DesktopDashboardMedia::class);$download=$media->download($scope,$manifest[0]['ticket']);
        $check($download['bytes']===$bytes&&$download['sha256']===hash('sha256',$bytes),'the scoped legacy settings ticket downloads the actual original connected upload bytes');
        DB::table('settings')->where('name','logo')->update(['payload'=>json_encode($canonical)]);
        $deny(fn()=>$media->download($scope,$manifest[0]['ticket']),403,'a settings ticket binds the raw source path even when a changed path aliases the same canonical file');
        DB::table('settings')->where('name','logo')->update(['payload'=>json_encode($raw)]);
        $proof=app(\App\Services\Dashboard\DesktopDashboardSettingsImages::class)->proof($raw);
        $check($proof['path']===$raw&&$proof['sha256']===hash('sha256',$bytes),'replacement before-facts retain the exact original legacy path and actual image digest');
        $single='/'.$canonical;DB::table('settings')->where('name','logo')->update(['payload'=>json_encode($single)]);
        $singleManifest=$media->manifest(['settings'=>DB::table('settings')->where('name','logo')->get()->map(fn($row)=>(array)$row)->all()],$scope,\App\Models\User::withoutGlobalScopes()->findOrFail(10),(string)\Illuminate\Support\Str::uuid());
        $check($singleManifest['complete']&&$singleManifest['files'][0]['path']===$canonical&&$media->download($scope,$singleManifest['files'][0]['ticket'])['bytes']===$bytes
            &&DB::table('settings')->where('name','logo')->value('payload')===json_encode($single),'the exact single-leading-slash legacy representation also exports and downloads without changing its raw database path');
        DB::table('settings')->where('name','logo')->update(['payload'=>json_encode($raw)]);
        foreach(['//uploadedImages/legacy.png','///uploadedImages/legacy.png','/uploadedImages//legacy.png','/uploadedImages/../legacy.png','/uploadedImages/sub/legacy.png',
            '/uploadedImages/%2e%2e.png','/uploadedImages/legacy%2f.png','/uploadedImages\\legacy.png','/branding/logo.png','https://example.test/uploadedImages/legacy.png','file:///uploadedImages/legacy.png',
            '/uploadedImages//settings-.png','/uploadedImages//settings-123.php','/uploadedImages//settings-123%2epng','/uploadedImages//SETTINGS-123.png',
            '/uploadedImages//settings-123..png','/uploadedImages//settings-123.png/','/uploadedImages///settings-123.png'] as $bad){
            $check(\App\Services\Dashboard\DesktopDashboardMedia::settingsPath($bad)===null&&!\App\Services\Dashboard\DesktopDashboardMedia::safePath($bad),'legacy settings normalization rejects unsafe or unrelated path: '.$bad);
            DB::table('settings')->where('name','logo')->update(['payload'=>json_encode($bad)]);
            $invalid=$media->manifest(['settings'=>DB::table('settings')->where('name','logo')->get()->map(fn($row)=>(array)$row)->all()],$scope,\App\Models\User::withoutGlobalScopes()->findOrFail(10),(string)\Illuminate\Support\Str::uuid());
            $check(!$invalid['complete']&&$invalid['files']===[],'unsafe legacy settings paths cannot become usable media tickets');
        }
        DB::table('settings')->where('name','logo')->update(['payload'=>json_encode($raw)]);
        $pdo->exec('CREATE DATABASE `'.$stage.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$useStorage($localRoot);
        mkdir(dirname($localRoot.'/app/public/'.$canonical),0700,true);file_put_contents($localRoot.'/app/public/'.$canonical,$download['bytes']);
        config(['database.connections.mysql.database'=>$stage,'desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>$device]);DB::purge();app()->forgetInstance(\App\Models\GeneralSettings::class);
        $receipt=app(\App\Services\Dashboard\DesktopDashboardImport::class)->import($snapshot);$verified=app(\App\Services\Dashboard\DesktopDashboardImport::class)->verify($receipt);
        $check($verified['verified']&&DB::table('settings')->where('name','logo')->value('payload')===json_encode($raw)
            &&hash_file('sha256',$localRoot.'/app/public/'.$canonical)===hash('sha256',$bytes),'the actual staging importer verifies the canonical downloaded file in separate storage without rewriting the imported legacy setting');
        $template=file_get_contents($application.'/resources/views/admin/settings.blade.php');$start=strpos($template,'<label for="logo">');$end=strpos($template,'</div>',$start);
        $settings=app(\App\Models\GeneralSettings::class)->refresh();$compiled=app('blade.compiler')->compileString(substr($template,$start,$end-$start));ob_start();eval('?>'.$compiled);$rendered=ob_get_clean();
        preg_match('/<img src="([^"]+)"/',$rendered,$image);$imagePath=parse_url(html_entity_decode($image[1]??''),PHP_URL_PATH);
        $check(is_string($imagePath)&&$imagePath==='/storage/'.$raw,'the actual original local settings Blade image renders its unchanged imported legacy setting');
        $reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$port=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);
        $origin='http://127.0.0.1:'.$port;$token=bin2hex(random_bytes(32));$env=getenv();$env['DESKTOP_DASHBOARD_LOCAL']='true';$env['DESKTOP_DASHBOARD_STORAGE']=$localRoot;$env['DESKTOP_DASHBOARD_ORIGIN']=$origin;$env['DESKTOP_DASHBOARD_TOKEN']=$token;
        $web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,$application.'/desktop/router.php'],[['pipe','r'],['file',$localRoot.'/gateway.log','a'],['file',$localRoot.'/gateway.log','a']],$pipes,$application,$env);
        $context=stream_context_create(['http'=>['header'=>'X-Fasakhansta-Desktop: '.$token,'ignore_errors'=>true,'timeout'=>5]]);$body=false;
        for($n=0;$n<100;$n++){$body=@file_get_contents($origin.$imagePath,false,$context);if($body!==false)break;usleep(50000);}
        $check($body===$bytes&&str_contains($http_response_header[0]??'','200'),'the rendered legacy settings image is served from the imported separate public media root through the actual protected local gateway');
    }finally{
        if(is_resource($web)){fclose($pipes[0]);proc_terminate($web);proc_close($web);}if(is_file($temporary))unlink($temporary);
        config($savedConfig);DB::purge();$useStorage($savedStorage);config($savedConfig);app('filesystem')->forgetDisk(['local','public']);app()->forgetInstance(\App\Models\GeneralSettings::class);
        foreach($savedRows as $row)DB::table('settings')->where('id',$row['id'])->update($row);$pdo->exec('DROP DATABASE IF EXISTS `'.$stage.'`');
    }
})();
