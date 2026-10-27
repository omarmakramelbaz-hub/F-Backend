<?php
// Execute the exact private entry script with an isolated bootstrap observer; no Laravel, database or network.
$folder=sys_get_temp_dir().'/desktop-workspace-delta-entry-'.bin2hex(random_bytes(8));mkdir($folder,0700,true);
copy(__DIR__.'/../desktop/workspace-delta.php',$folder.'/workspace-delta.php');
file_put_contents($folder.'/bootstrap.php', <<<'PHP'
<?php
file_put_contents(__DIR__.'/booted','1',FILE_APPEND);
return new class {
    public function make($name){return new class {
        public function run(array $value):array {
            return ['action'=>$value['action'],'expected_revision'=>$value['expected_binding']['revision']??null,
                'delta_kind'=>$value['delta']['kind']??null,
                'exact_id'=>isset($value['delta']['tables']['products']['delta']['add'][0]['id'])?(string)$value['delta']['tables']['products']['delta']['add'][0]['id']:null,
                'source_binding'=>$value['capture']['identity']['workspace']??null,
                'framing_removed'=>!isset($value['format'])&&!isset($value['kind'])];
        }
    };}
};
PHP);
file_put_contents($folder.'/import-diagnostic.php', <<<'PHP'
<?php
class DesktopImportDiagnostic {
    public static function profiles($snapshot){return ['bounded'=>'synthetic'];}
    public static function report($error,$phase,$root,$snapshot){return ['phase'=>$phase];}
}
PHP);
$checks=0;
function checkEntry($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function invokeEntry(string $input,array $override=[]):array {
    global $folder;$env=getenv();$env['DESKTOP_DASHBOARD_LOCAL']='true';$env['DESKTOP_DASHBOARD_WORKSPACE_LOCAL']='true';foreach($override as $key=>$value)$env[$key]=$value;
    $process=proc_open([PHP_BINARY,'-n',$folder.'/workspace-delta.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,$folder,$env);
    if(!is_resource($process))throw new RuntimeException('Synthetic CLI child could not start');
    fwrite($pipes[0],$input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($process),$out,$err];
}
function framed(array $header,string $body=''):string{return json_encode(['format'=>2,'kind'=>'desktop-workspace-delta-input']+$header,JSON_THROW_ON_ERROR)."\n".$body;}
function entryRefuses(string $input,string $label,array $override=[]):void {
    global $folder;$before=is_file($folder.'/booted')?strlen(file_get_contents($folder.'/booted')):0;
    [$status,$out,$err]=invokeEntry($input,$override);checkEntry($status===1&&$out==='',$label.' has no success reply');
    checkEntry((is_file($folder.'/booted')?strlen(file_get_contents($folder.'/booted')):0)===$before,$label.' refuses before bootstrap');
    checkEntry(!str_contains($err,'SYNTHETIC_NATIVE_SECRET'),$label.' error contains no raw input');
}
try{
    $capture=['action'=>'capture','source_database'=>'fasakhansta_dashboard','device_id'=>'11111111-1111-4111-8111-111111111111','actor_id'=>101,
        'snapshot_id'=>'22222222-2222-4222-8222-222222222222','schema_hash'=>str_repeat('a',64),'source'=>[],
        'capture_token'=>'SYNTHETIC_NATIVE_SECRET','expected_binding'=>['revision'=>1]];
    [$status,$out,$err]=invokeEntry(framed($capture));checkEntry($status===0,'Synthetic entry bootstrap failed: '.$out.$err);$value=json_decode($out,true,32,JSON_THROW_ON_ERROR);
    checkEntry($status===0&&$err===''&&$value['action']==='capture'&&$value['expected_revision']===1&&$value['framing_removed'],'Capture uses only framed source expectations');
    $reconstruct=['action'=>'reconstruct','capture_database'=>'fasakhansta_dashboard_stage_aaaaaaaaaaaaaaaa','capture_token'=>'SYNTHETIC_NATIVE_SECRET',
        'capture'=>['identity'=>['workspace'=>['manifest_revision'=>1]]],'expected_binding'=>['revision'=>2]];
    $body='{"format":2,"kind":"workspace-data-delta","tables":{"products":{"delta":{"add":[{"id":9007199254740993,"amount":"123456789012345.125"}]}}}}';
    [$status,$out,$err]=invokeEntry(framed($reconstruct,$body));$value=json_decode($out,true,32,JSON_THROW_ON_ERROR);
    checkEntry($status===0&&$err===''&&$value['exact_id']==='9007199254740993','Raw server JSON preserves integers beyond JavaScript safe range');
    checkEntry($value['expected_revision']===2&&$value['source_binding']['manifest_revision']===1,'Target and captured source expectations remain separate');
    checkEntry($value['delta_kind']==='workspace-data-delta'&&isset($value['php_profiles']),'Reconstruction retains delta kind and diagnostics');
    $discard=['action'=>'discard-capture','capture_database'=>'fasakhansta_dashboard_stage_aaaaaaaaaaaaaaaa','source_database'=>'fasakhansta_dashboard',
        'active_database'=>'fasakhansta_dashboard','capture_token'=>'SYNTHETIC_NATIVE_SECRET'];
    [$status,$out,$err]=invokeEntry(framed($discard));checkEntry($status===0&&$err===''&&json_decode($out,true)['action']==='discard-capture','Cleanup is a header-only action');
    $bad=$capture;$bad['unexpected']='SYNTHETIC_NATIVE_SECRET';entryRefuses(framed($bad),'Unknown capture field');
    $bad=$reconstruct;$bad['delta']=['kind'=>'parsed-in-native'];entryRefuses(framed($bad,$body),'Nested parsed delta field');
    $bad=$discard;$bad['expected_binding']=[];entryRefuses(framed($bad),'Unexpected cleanup authority field');
    entryRefuses(framed($capture,'{}'),'Capture with trailing body');
    entryRefuses(framed($reconstruct,'{broken SYNTHETIC_NATIVE_SECRET'),'Malformed raw delta');
    entryRefuses(json_encode($capture),'Unframed original-protocol input');
    entryRefuses(framed($capture),'Nonlocal native environment',['DESKTOP_DASHBOARD_LOCAL'=>'false']);
    entryRefuses(framed($capture),'Missing workspace native environment',['DESKTOP_DASHBOARD_WORKSPACE_LOCAL'=>'false']);
    echo 'PASS '.$checks.' framed workspace delta CLI checks'.PHP_EOL;
}finally{foreach(glob($folder.'/*') as $file)unlink($file);rmdir($folder);}
