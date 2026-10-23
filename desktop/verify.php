<?php
try{
    $input=stream_get_contents(STDIN,16*1024*1024+1);if(strlen($input)>16*1024*1024)throw new RuntimeException('Receipt is too large.');
    $value=json_decode($input,true,128,JSON_THROW_ON_ERROR);
    $app=require __DIR__.'/bootstrap.php';
    if(($value['action']??null)==='preverify'){
        $keys=array_keys($value);sort($keys);
        if(!in_array($keys,[['action','receipt'],['action','handoff_token','receipt']],true)||!is_array($value['receipt']))throw new RuntimeException('Invalid verification request.');
        $receipt=$value['receipt'];
        if(array_key_exists('handoff_token',$value)){
            if(!is_string($value['handoff_token']))throw new RuntimeException('Invalid verification request.');
            $result=$app->make(\App\Services\Dashboard\DesktopDashboardHandoff::class)->capture($receipt,$value['handoff_token']);
        }else{
            $checked=\App\Services\Dashboard\DesktopDashboardMediaSeal::capture($receipt['media']??[],
                fn()=>$app->make(\App\Services\Dashboard\DesktopDashboardImport::class)->verify($receipt));
            $result=$checked['result']+['media_seal'=>$checked['seal']];
        }
    }else $result=$app->make(\App\Services\Dashboard\DesktopDashboardImport::class)->verify($value);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,"تعذر التحقق من قاعدة التجهيز؛ بيانات الجهاز الحالية محفوظة.\n");exit(1);}
