<?php
try{
    $input=stream_get_contents(STDIN,64*1024*1024+1);
    if(!is_string($input)||strlen($input)>64*1024*1024)throw new RuntimeException('Workspace receipt is too large.');
    $value=json_decode($input,true,128,JSON_THROW_ON_ERROR);unset($input);
    $keys=is_array($value)?array_keys($value):[];sort($keys);
    if(!in_array($keys,[['expected_binding','receipt'],['action','expected_binding','receipt'],['action','expected_binding','handoff_token','receipt']],true)
        ||!is_array($value['receipt'])||!is_array($value['expected_binding'])
        ||(isset($value['action'])&&$value['action']!=='preverify'))throw new RuntimeException('Invalid workspace verification input.');
    $app=require __DIR__.'/bootstrap.php';$importer=$app->make(\App\Services\Dashboard\DesktopDashboardWorkspaceImport::class);
    if(($value['action']??null)==='preverify'){
        if(array_key_exists('handoff_token',$value)){
            if(!is_string($value['handoff_token']))throw new RuntimeException('Invalid workspace verification input.');
            $result=$app->make(\App\Services\Dashboard\DesktopDashboardWorkspaceHandoff::class)
                ->captureWorkspace($value['receipt'],$value['expected_binding'],$value['handoff_token']);
        }else{
            $checked=\App\Services\Dashboard\DesktopDashboardMediaSeal::capture($value['receipt']['media']??[],
                fn()=>$importer->verifyWorkspace($value['receipt'],$value['expected_binding']));
            $result=$checked['result']+['media_seal'=>$checked['seal']];
        }
    }else $result=$importer->verifyWorkspace($value['receipt'],$value['expected_binding']);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,"تعذر التحقق من مساحة عمل الجهاز؛ النسخة الحالية وعملياتها محفوظة.\n");exit(1);}
