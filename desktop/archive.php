<?php
try{
    $input=stream_get_contents(STDIN,16*1024*1024+1);if(strlen($input)>16*1024*1024)throw new RuntimeException('Archive request is too large.');
    $value=json_decode($input,true,128,JSON_THROW_ON_ERROR);
    $app=require __DIR__.'/bootstrap.php';
    $result=$app->make(\App\Services\Dashboard\DesktopDashboardArchive::class)->seal($value['receipt'],$value['source'],$value['refresh_id'],$value['token']);
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,"تعذر حفظ حماية سجل العمليات السابق؛ بيانات الجهاز الحالية محفوظة.\n");exit(1);}
