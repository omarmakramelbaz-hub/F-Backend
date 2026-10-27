<?php
// Native input is one bounded private header, LF, then the server's exact JSON.
// Framing avoids escaping/copying a 256-MiB snapshot into another JSON string.
$phase='input';
try{
    $line=fgets(STDIN,64*1024+2);
    if(!is_string($line)||strlen($line)>64*1024||!str_ends_with($line,"\n"))throw new RuntimeException('Invalid workspace import framing.');
    $header=json_decode(substr($line,0,-1),true,32,JSON_THROW_ON_ERROR);
    $keys=is_array($header)?array_keys($header):[];sort($keys);
    if($keys!==['expected_binding','format','kind']||$header['format']!==2
        ||$header['kind']!=='desktop-workspace-import-input'||!is_array($header['expected_binding']))throw new RuntimeException('Invalid workspace import framing.');
    $input='';$limit=256*1024*1024;
    while(!feof(STDIN)){
        $chunk=fread(STDIN,64*1024);if($chunk===false)throw new RuntimeException('Workspace snapshot could not be read.');
        if(strlen($input)>$limit-strlen($chunk))throw new RuntimeException('Workspace snapshot is too large.');$input.=$chunk;
    }
    $phase='json';$snapshot=json_decode($input,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($snapshot))throw new RuntimeException('Workspace snapshot is invalid.');
    require_once __DIR__.'/snapshot-row-hashes.php';$rowHashes=DesktopSnapshotRowHashes::fromJson($input);
    unset($input,$line);$phase='bootstrap';$app=require __DIR__.'/bootstrap.php';$phase='import';
    $result=$app->make(\App\Services\Dashboard\DesktopDashboardWorkspaceImport::class)->importWorkspace($snapshot,$header['expected_binding'],$rowHashes);
    require_once __DIR__.'/import-diagnostic.php';$result['php_profiles']=DesktopImportDiagnostic::profiles($snapshot);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){
    fwrite(STDERR,"تعذر تجهيز مساحة عمل الجهاز؛ النسخة الحالية وعملياتها محفوظة.\n");
    require_once __DIR__.'/import-diagnostic.php';
    fwrite(STDERR,'DESKTOP_WORKSPACE_IMPORT_DIAGNOSTIC '.json_encode(DesktopImportDiagnostic::report($error,$phase,dirname(__DIR__),$snapshot??null),
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);exit(1);
}
