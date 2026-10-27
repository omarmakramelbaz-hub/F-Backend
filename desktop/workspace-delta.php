<?php
// Native-only framing: one bounded JSON header + LF, followed by untouched server delta JSON for reconstruction.
$phase='input';
try{
    if(PHP_SAPI!=='cli'||getenv('DESKTOP_DASHBOARD_LOCAL')!=='true'||getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL')!=='true')
        throw new RuntimeException('DESKTOP_WORKSPACE_DELTA_CLI_REQUIRED');
    $headerLimit=128*1024*1024;$line='';
    do{
        $part=fgets(STDIN,64*1024+1);
        if(!is_string($part)||strlen($line)>$headerLimit-strlen($part))throw new RuntimeException('DESKTOP_WORKSPACE_DELTA_INPUT_FRAME');
        $line.=$part;
    }while(!str_ends_with($part,"\n"));
    $value=json_decode(substr($line,0,-1),true,128,JSON_THROW_ON_ERROR);unset($line);
    if(!is_array($value)||($value['format']??null)!==2||($value['kind']??null)!=='desktop-workspace-delta-input')
        throw new RuntimeException('DESKTOP_WORKSPACE_DELTA_INPUT_FRAME');
    [$required,$optional]=match($value['action']??null){
        'capture'=>[['source_database','device_id','actor_id','snapshot_id','schema_hash','source','capture_token','expected_binding'],[]],
        'reconstruct'=>[['capture_database','capture_token','capture','expected_binding'],[]],
        'discard-capture'=>[['capture_database','source_database','active_database','capture_token'],['capture']],
        'discard-stage'=>[['stage_database','source_database','active_database','capture_token'],['capture_database','receipt']],
        default=>throw new RuntimeException('DESKTOP_WORKSPACE_DELTA_ACTION'),
    };
    $required=array_merge(['format','kind','action'],$required);
    if(array_diff($required,array_keys($value))!==[]||array_diff(array_keys($value),array_merge($required,$optional))!==[])
        throw new RuntimeException('DESKTOP_WORKSPACE_DELTA_INPUT_FIELDS');
    unset($value['format'],$value['kind']);
    if($value['action']==='discard-stage'&&!array_key_exists('capture_database',$value)&&!array_key_exists('receipt',$value))
        throw new RuntimeException('DESKTOP_WORKSPACE_DELTA_INPUT_FIELDS');
    if($value['action']==='reconstruct'){
        $input='';$limit=256*1024*1024;
        while(!feof(STDIN)){
            $chunk=fread(STDIN,64*1024);
            if($chunk===false||strlen($input)>$limit-strlen($chunk))throw new RuntimeException('DESKTOP_WORKSPACE_DELTA_INPUT_LIMIT');
            $input.=$chunk;
        }
        $phase='json';$value['delta']=json_decode($input,true,512,JSON_THROW_ON_ERROR);unset($input);
        if(!is_array($value['delta']))throw new RuntimeException('DESKTOP_WORKSPACE_DELTA_INPUT');
    }elseif(stream_get_contents(STDIN,1)!=='')throw new RuntimeException('DESKTOP_WORKSPACE_DELTA_INPUT_FRAME');
    $phase='bootstrap';$app=require __DIR__.'/bootstrap.php';
    $phase='import';$result=$app->make(\App\Services\Dashboard\DesktopDashboardWorkspaceDeltaLocal::class)->run($value);
    require_once __DIR__.'/import-diagnostic.php';
    if($value['action']==='reconstruct')$result['php_profiles']=DesktopImportDiagnostic::profiles($value['delta']);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){
    fwrite(STDERR,"تعذر تجهيز تحديث مساحة العمل؛ بيانات الجهاز وعمليات كل الحسابات محفوظة.\n");
    require_once __DIR__.'/import-diagnostic.php';
    $report=DesktopImportDiagnostic::report($error,$phase,dirname(__DIR__),$value['delta']??null);
    if(preg_match('/^DESKTOP_WORKSPACE_DELTA_[A-Z_]{1,60}$/D',$error->getMessage()))$report['reason']=$error->getMessage();
    fwrite(STDERR,'DESKTOP_WORKSPACE_DELTA_DIAGNOSTIC '.json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
