<?php
try{
    $input=stream_get_contents(STDIN,64*1024*1024+1);
    if(!is_string($input)||strlen($input)>64*1024*1024)throw new RuntimeException('Workspace archive input is too large.');
    $value=json_decode($input,true,128,JSON_THROW_ON_ERROR);unset($input);
    $keys=is_array($value)?array_keys($value):[];sort($keys);
    if($keys!==['archive_handoff','expected_binding','handoff_token','media_seal','receipt','refresh_id','source','source_identity','token'])
        throw new RuntimeException('Invalid workspace archive input.');
    foreach(['receipt','expected_binding','source_identity','media_seal','archive_handoff'] as $key)
        if(!is_array($value[$key]))throw new RuntimeException('Invalid workspace archive input.');
    foreach(['source','refresh_id','token','handoff_token'] as $key)
        if(!is_string($value[$key]))throw new RuntimeException('Invalid workspace archive input.');
    $app=require __DIR__.'/bootstrap.php';
    $result=$app->make(\App\Services\Dashboard\DesktopDashboardWorkspaceArchive::class)->sealWorkspace($value['receipt'],$value['expected_binding'],
        $value['source'],$value['refresh_id'],$value['token'],$value['source_identity'],$value['media_seal'],$value['archive_handoff'],$value['handoff_token']);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,"تعذر تأكيد تفعيل مساحة عمل الجهاز؛ النسخة السابقة وعملياتها محفوظة.\n");exit(1);}
