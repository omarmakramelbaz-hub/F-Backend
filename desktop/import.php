<?php
// The supervisor sends the paired server's JSON over stdin to an unused local staging database.
$phase='input';
try{
    // PHP on Windows may reserve the requested stream length before reading a small
    // snapshot. Read bounded chunks instead of reserving the entire 256 MiB ceiling.
    $input='';$limit=256*1024*1024;
    while(!feof(STDIN)){
        $chunk=fread(STDIN,64*1024);if($chunk===false)throw new RuntimeException('Snapshot could not be read.');
        if(strlen($input)>$limit-strlen($chunk))throw new RuntimeException('Snapshot is too large.');
        $input.=$chunk;
    }
    $phase='json';
    $snapshot=json_decode($input,true,512,JSON_THROW_ON_ERROR);
    unset($input);$phase='bootstrap';
    $app=require __DIR__.'/bootstrap.php';
    $phase='import';
    $result=$app->make(\App\Services\Dashboard\DesktopDashboardImport::class)->import($snapshot);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){
    fwrite(STDERR,"تعذر تجهيز قاعدة البيانات المحلية؛ لم تُغيّر بيانات الجهاز الحالية.\n");
    // Record only classification and location, never SQL, snapshot values or credentials.
    fwrite(STDERR,'DESKTOP_IMPORT_DIAGNOSTIC '.json_encode(['phase'=>$phase,'type'=>get_class($error),
        'file'=>basename($error->getFile()),'line'=>$error->getLine(),
        'status'=>$error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$error->getStatusCode():null],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
