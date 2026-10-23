<?php
// Private one-shot supervisor step. Never routed by the bundled HTTP server.
$phase='input';
try{
    if(PHP_SAPI!=='cli'||getenv('DESKTOP_DASHBOARD_LOCAL')!=='true')throw new RuntimeException('DESKTOP_DELTA_CLI_REQUIRED');
    $input='';$limit=384*1024*1024;
    while(!feof(STDIN)){
        $chunk=fread(STDIN,64*1024);if($chunk===false||strlen($input)>$limit-strlen($chunk))throw new RuntimeException('DESKTOP_DELTA_INPUT_LIMIT');
        $input.=$chunk;
    }
    $phase='json';$value=json_decode($input,true,512,JSON_THROW_ON_ERROR);unset($input);
    if(!is_array($value))throw new RuntimeException('DESKTOP_DELTA_INPUT');
    $phase='bootstrap';$app=require __DIR__.'/bootstrap.php';
    $phase='import';$result=$app->make(\App\Services\Dashboard\DesktopDashboardDeltaLocal::class)->run($value);
    require_once __DIR__.'/import-diagnostic.php';
    if(($value['action']??null)==='reconstruct')$result['php_profiles']=DesktopImportDiagnostic::profiles($value['delta']??null);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){
    fwrite(STDERR,"تعذر تجهيز تحديث البيانات؛ بيانات الجهاز وعملياته محفوظة.\n");
    require_once __DIR__.'/import-diagnostic.php';
    $report=DesktopImportDiagnostic::report($error,$phase,dirname(__DIR__),$value['delta']??null);
    // Only our fixed reason codes may supplement the bounded support report.
    if(preg_match('/^DESKTOP_DELTA_[A-Z_]{1,60}$/D',$error->getMessage()))$report['reason']=$error->getMessage();
    fwrite(STDERR,'DESKTOP_DELTA_DIAGNOSTIC '.json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
