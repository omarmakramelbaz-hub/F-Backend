<?php
try{
    $input=stream_get_contents(STDIN,1024*1024+1);if(strlen($input)>1024*1024)throw new RuntimeException('Receipt is too large.');
    $receipt=json_decode($input,true,128,JSON_THROW_ON_ERROR);
    $app=require __DIR__.'/bootstrap.php';
    $result=$app->make(\App\Services\Dashboard\DesktopDashboardImport::class)->verify($receipt);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,"تعذر التحقق من قاعدة التجهيز؛ بيانات الجهاز الحالية محفوظة.\n");exit(1);}
