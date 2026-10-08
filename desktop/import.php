<?php
// The supervisor sends the paired server's JSON over stdin to an unused local staging database.
try{
    $input=stream_get_contents(STDIN,256*1024*1024+1);if(strlen($input)>256*1024*1024)throw new RuntimeException('Snapshot is too large.');
    $snapshot=json_decode($input,true,512,JSON_THROW_ON_ERROR);
    $app=require __DIR__.'/bootstrap.php';
    $result=$app->make(\App\Services\Dashboard\DesktopDashboardImport::class)->import($snapshot);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,"تعذر تجهيز قاعدة البيانات المحلية؛ لم تُغيّر بيانات الجهاز الحالية.\n");exit(1);}
