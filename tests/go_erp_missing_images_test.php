<?php
require dirname(__DIR__).'/deployment/go_erp_media_file_repair.php';
require dirname(__DIR__).'/app/Services/Dashboard/DesktopDashboardMedia.php';
use App\Services\Dashboard\DesktopDashboardMedia;
function need($ok,string $message): void { if (!$ok) throw new RuntimeException('TEST: '.$message); }
function refuses(callable $work,string $code): void {
    try { $work(); } catch (RuntimeException $e) { need(str_contains($e->getMessage(),$code),$e->getMessage()); return; }
    throw new RuntimeException('TEST: expected '.$code);
}
$temporary=[]; $assets=dirname(__DIR__).'/deployment/assets';
register_shutdown_function(function()use(&$temporary){
    foreach ($temporary as $root) {
        $walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($walk as $entry) { if ($entry->isDir()&&!$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
        rmdir($root);
    }
});
function fixture(): array {
    global $temporary;
    $root=sys_get_temp_dir().'/go-erp-missing-'.bin2hex(random_bytes(8)); mkdir($root,0700); $temporary[]=$root;
    mkdir($root.'/public',0755); mkdir($root.'/backup',0700);
    $db=new PDO('sqlite:'.$root.'/database.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE media (id INTEGER PRIMARY KEY, model_type TEXT, model_id INTEGER, uuid TEXT,
        collection_name TEXT, name TEXT, file_name TEXT, mime_type TEXT, disk TEXT, conversions_disk TEXT,
        size INTEGER, custom_properties TEXT, generated_conversions TEXT, responsive_images TEXT, updated_at TEXT)');
    $db->exec("CREATE TABLE accounting (id INTEGER PRIMARY KEY, balance TEXT); INSERT INTO accounting VALUES (1,'21349.55')");
    foreach (GoErpMediaFileRepair::missingPaths() as $path) {
        [$disk,$id,$name]=explode('/',$path,3);
        $q=$db->prepare('INSERT INTO media VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$id,$disk==='pending_vendor'?'App\\Models\\PendingVendor':'App\\Models\\User',(int)$id+9000,
            'uuid-'.$id,'original-collection','Original name',$name,'image/old',$disk,$disk,123456,
            '{"untouched":"original"}','{}','{}','2026-10-22 10:00:00']);
    }
    $db->exec("INSERT INTO media VALUES (9999,'App\\Models\\Product',7,'uuid-other','image','Other','other.jpg',
        'image/jpeg','products','products',2,'{}','{}','{}','2026-10-22 10:00:00')");
    file_put_contents($root.'/public/unrelated.txt','keep');
    return ['root'=>$root,'public'=>$root.'/public','backup'=>$root.'/backup','db'=>$db,
        'repair'=>new GoErpMediaFileRepair($db,$root.'/public',[DesktopDashboardMedia::class,'safePath'])];
}
function row(array $f,int $id): array {
    $q=$f['db']->prepare('SELECT * FROM media WHERE id=?'); $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC);
}
function plans(array $f): array {
    global $assets;
    return array_map(fn($path)=>$f['repair']->placeholderPlan(row($f,(int)explode('/',$path)[1]),$path,$assets),GoErpMediaFileRepair::missingPaths());
}
function place(array $f,string $path,string $bytes): void {
    $directory=dirname($f['public'].'/'.$path);
    if (!is_dir($directory)) mkdir($directory,0755,true);
    file_put_contents($f['public'].'/'.$path,$bytes);
}

$f=fixture(); $before=$f['db']->query('SELECT * FROM media ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$p=plans($f);
need(count($p)===8 && !array_filter($p,fn($plan)=>$plan['already_placeholder']),'exact eight absent targets');
need(count(glob($f['backup'].'/*'))===0 && count(glob($f['public'].'/*'))===1,'planning makes no filesystem changes');
need($f['db']->query('SELECT * FROM media ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'planning makes no database changes');
$result=$f['repair']->apply($p,$f['backup']);
need($result['explicit_missing_placeholders']===8 && $result['normalized']===0 && $result['restored']===0
    && $result['database_committed'],'explicit substitutes counted separately from restored originals');
foreach ($p as $plan) {
    $path=$plan['relative']; $bytes=file_get_contents($f['public'].'/'.$path); $actual=row($f,(int)$plan['before']['id']);
    need($bytes===file_get_contents($assets.'/'.$plan['source_relative']),'exact reviewed image bytes');
    need(hash('sha256',$bytes)===$plan['facts']['sha256'],'pinned placeholder hash');
    need(DesktopDashboardMedia::safePath($path),'installed media path validation accepts the unchanged original URL');
    need((new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)===DesktopDashboardMedia::MIMES[pathinfo($path,PATHINFO_EXTENSION)],'actual MIME matches URL extension');
    need(getimagesizefromstring($bytes)[0]===360 && getimagesizefromstring($bytes)[1]===240,'valid decoded image dimensions');
    foreach ($plan['before'] as $key=>$value) if (!in_array($key,['mime_type','size'],true)) need($actual[$key]===$value,'preserved field '.$key);
    need($actual['mime_type']===$plan['facts']['mime'] && (int)$actual['size']===strlen($bytes),'metadata describes explicit substitute');
    need((fileperms($f['public'].'/'.$path)&0777)===0644,'public image permissions');
}
need(row($f,9999)===$before[count($before)-1],'unrelated media row unchanged');
need(file_get_contents($f['public'].'/unrelated.txt')==='keep','unrelated files unchanged');
need($f['db']->query('SELECT balance FROM accounting')->fetchColumn()==='21349.55','accounting unchanged');
$journal=json_decode(file_get_contents($result['backup'].'/before.json'),true,512,JSON_THROW_ON_ERROR);
need($journal['plans']===$p,'complete original metadata and substitute provenance archived before changes');
need((fileperms($result['backup'])&0777)===0700 && (fileperms($result['backup'].'/before.json')&0777)===0600,'private audit permissions');
need(json_decode(file_get_contents($result['backup'].'/committed.json'),true,512,JSON_THROW_ON_ERROR)===$result,'durable commit receipt');
$retry=plans($f); need(count(array_filter($retry,fn($plan)=>$plan['already_placeholder']))===8,'identical files recognized on retry');
$after=$f['db']->query('SELECT * FROM media ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$f['repair']->apply($retry,$f['backup']);
need($f['db']->query('SELECT * FROM media ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$after,'repeat is idempotent');

$f=fixture(); $path=GoErpMediaFileRepair::missingPaths()[0]; $original=row($f,68);
foreach (['id'=>900,'file_name'=>'different.jpg','disk'=>'users','model_id'=>0] as $key=>$value) {
    $bad=$original; $bad[$key]=$value;
    refuses(fn()=>$f['repair']->placeholderPlan($bad,$path,$assets),'PLACEHOLDER_ROW');
}
$bad=$original; $bad['model_type']='App\\Models\\Product';
refuses(fn()=>$f['repair']->placeholderPlan($bad,$path,$assets),'PLACEHOLDER_MODEL');
refuses(fn()=>$f['repair']->placeholderPlan($original,'products/68/6J3A7531-Edit1_30840_lg.jpg',$assets),'PLACEHOLDER_PATH');
// A valid original appearing before planning is never overwritten, even of the same MIME.
$realImage=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAIAAAASFvFNAAAAFUlEQVR4nGN0S2liYGBgYGBgYoABABUYATBg6+eCAAAAAElFTkSuQmCC');
place($f,$path,$realImage);
refuses(fn()=>$f['repair']->placeholderPlan($original,$path,$assets),'ORIGINAL_NOW_PRESENT');
need(file_get_contents($f['public'].'/'.$path)===$realImage && row($f,68)===$original,'original file and row preserved');
unlink($f['public'].'/'.$path); symlink($f['root'].'/database.sqlite',$f['public'].'/'.$path);
refuses(fn()=>$f['repair']->placeholderPlan($original,$path,$assets),'LINK');
unlink($f['public'].'/'.$path);
// Asset alteration is rejected independently of extension and MIME.
mkdir($f['root'].'/assets',0755); file_put_contents($f['root'].'/assets/unavailable-image.jpg',$realImage);
refuses(fn()=>$f['repair']->placeholderPlan($original,$path,$f['root'].'/assets'),'PLACEHOLDER_ASSET');

// A concurrent upload after planning is preserved; metadata transaction rolls back.
$f=fixture(); $p=plans($f); place($f,$p[0]['relative'],$realImage);
refuses(fn()=>$f['repair']->apply($p,$f['backup']),'ABORTED_BACKUP');
need(row($f,68)===$p[0]['before'] && file_get_contents($f['public'].'/'.$p[0]['relative'])===$realImage,'concurrent original survives apply');
$failed=glob($f['backup'].'/*/failed.json'); need(count($failed)===1,'failed transaction receipt');
need(json_decode(file_get_contents($failed[0]),true)['database_committed']===false,'rollback reported truthfully');

// A later row edit rolls back earlier metadata while retaining known copied bytes for retry.
$f=fixture(); $p=plans($f); $f['db']->exec("UPDATE media SET name='Concurrent edit' WHERE id=69");
refuses(fn()=>$f['repair']->apply($p,$f['backup']),'ABORTED_BACKUP');
need(row($f,68)===$p[0]['before'] && row($f,69)['name']==='Concurrent edit','earlier metadata rolled back; concurrent edit retained');
need(file_get_contents($f['public'].'/'.$p[0]['relative'])===file_get_contents($assets.'/'.$p[0]['source_relative']),'copied substitute retained for safe retry');
$retry=plans($f); need($retry[0]['already_placeholder'],'partial copy recognized');
need($f['repair']->apply($retry,$f['backup'])['explicit_missing_placeholders']===8,'partial failure is recoverable without overwriting originals');
echo "PASS: eight pinned explicit substitutes, read-only planning, correct image MIME, unchanged URLs and unrelated data, private durable audit, idempotence, asset tampering, concurrent uploads, symlinks and transactional rollback.\n";
