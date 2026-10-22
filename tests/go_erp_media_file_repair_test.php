<?php
require dirname(__DIR__).'/deployment/go_erp_media_file_repair.php';
require dirname(__DIR__).'/app/Services/Dashboard/DesktopDashboardMedia.php';
use App\Services\Dashboard\DesktopDashboardMedia;

function check($value, string $message): void { if (!$value) throw new RuntimeException('TEST: '.$message); }
function reject(callable $work, string $code): void {
    try { $work(); } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(),$code),'unexpected failure '.$error->getMessage()); return;
    }
    throw new RuntimeException('TEST: expected rejection '.$code);
}
$images=[
 'png'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAIAAAASFvFNAAAAFUlEQVR4nGN0S2liYGBgYGBgYoABABUYATBg6+eCAAAAAElFTkSuQmCC'),
 'webp'=>base64_decode('UklGRjIAAABXRUJQVlA4ICYAAACQAQCdASoDAAIAAUAmJQBOl0AAhgAA/u9219qYJIyl/18AU8SAAA=='),
 'jpg'=>base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAACAAMDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDnaKKK9I4D/9k='),
];
$temporary=[];
register_shutdown_function(function()use(&$temporary){
    foreach ($temporary as $root) {
        if (!is_dir($root)) continue;
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { if ($entry->isDir()&&!$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
        rmdir($root);
    }
});
function fixture(): array {
    global $temporary;
    $root=sys_get_temp_dir().'/go-erp-media-repair-'.bin2hex(random_bytes(8)); mkdir($root,0700); $temporary[]=$root;
    foreach (['public','backup','recovery'] as $name) mkdir($root.'/'.$name,0700);
    $pdo=new PDO('sqlite:'.$root.'/database.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE media (id INTEGER PRIMARY KEY, model_type TEXT, model_id INTEGER, uuid TEXT, collection_name TEXT, name TEXT,
        file_name TEXT, mime_type TEXT, disk TEXT, conversions_disk TEXT, size INTEGER, generated_conversions TEXT, responsive_images TEXT, updated_at TEXT)');
    $pdo->exec("CREATE TABLE accounting (id INTEGER PRIMARY KEY, balance TEXT); INSERT INTO accounting VALUES (1,'21349.55')");
    $repair=new GoErpMediaFileRepair($pdo,$root.'/public',[DesktopDashboardMedia::class,'safePath']);
    return ['root'=>$root,'public'=>$root.'/public','backup'=>$root.'/backup','recovery'=>$root.'/recovery','pdo'=>$pdo,'repair'=>$repair];
}
function row(array $f,int $id): array {
    $q=$f['pdo']->prepare('SELECT * FROM media WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC);
}
function add(array $f,int $id,string $name,string $bytes,string $mime='image/png',bool $exists=true): array {
    $q=$f['pdo']->prepare('INSERT INTO media VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$id,'App\\Models\\Product',$id,'uuid-'.$id,'product_image','Original name',$name,$mime,'products','products',strlen($bytes),'{}','{}','2026-10-22 10:00:00']);
    if ($exists) { mkdir($f['public'].'/products/'.$id,0755,true);file_put_contents($f['public'].'/products/'.$id.'/'.$name,$bytes); }
    return row($f,$id);
}

// Reproduce the exact observed 307 extensionless + 2 mislabeled images with real PNG/JPEG/WebP bytes.
$f=fixture();$plans=[];$originals=[];$types=['png','jpg','webp'];
for ($i=1;$i<=309;$i++) {
    $type=$i<=307?$types[$i%3]:'jpg';$name=$i<=307?'mediaOriginal'.$i:'mislabeled.png';
    $r=add($f,$i,$name,$images[$type]); $path='products/'.$i.'/'.$name;
    $plans[]=$f['repair']->plan($r,$path);$originals[$path]=$images[$type];
}
$result=$f['repair']->apply($plans,$f['backup']);
check($result['normalized']===309 && $result['restored']===0 && $result['database_committed'],'all 309 verified images normalized');
foreach ($plans as $plan) {
    $r=row($f,(int)$plan['before']['id']);$path='products/'.$r['id'].'/'.$r['file_name'];
    check(DesktopDashboardMedia::safePath($path),'existing client accepts repaired original path');
    check(file_get_contents($f['public'].'/'.$plan['relative'])===$originals[$plan['relative']],'original URL retains exact original bytes');
    check(file_get_contents($f['public'].'/'.$path)===$originals[$plan['relative']],'new URL retains exact original bytes');
    check((new finfo(FILEINFO_MIME_TYPE))->file($f['public'].'/'.$path)===DesktopDashboardMedia::MIMES[pathinfo($path,PATHINFO_EXTENSION)],'extension matches independently detected image MIME');
    foreach ($plan['before'] as $key=>$value) if (!in_array($key,['file_name','mime_type','size'],true)) check($r[$key]===$value,'unrelated media metadata retained: '.$key);
    reject(fn()=>$f['repair']->plan($r,$path),'ALREADY_VALID');
}
check($f['pdo']->query('SELECT balance FROM accounting')->fetchColumn()==='21349.55','accounting unchanged');
check(is_file($result['backup'].'/before.json')&&is_file($result['backup'].'/committed.json'),'durable original-row journal and commit receipt');

$f=fixture();$restore=[];
for ($i=1;$i<=8;$i++) {
    $r=add($f,$i,'original.png',$images['png'],'image/png',false);$path='products/'.$i.'/original.png';
    reject(fn()=>$f['repair']->plan($r,$path),'MISSING_NO_BACKUP');
    mkdir($f['recovery'].'/products/'.$i,0700,true);file_put_contents($f['recovery'].'/'.$path,$images['png']);
    $restore[]=$f['repair']->plan($r,$path,$f['recovery']);
}
$before=$f['pdo']->query('SELECT * FROM media ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$result=$f['repair']->apply($restore,$f['backup']);
check($result['restored']===8 && $result['normalized']===0,'eight original same-path backup images restored');
check($f['pdo']->query('SELECT * FROM media ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'restoration leaves every database field unchanged');

$f=fixture();$r=add($f,1,'wrong.png',$images['jpg'],'image/png',false);
mkdir($f['recovery'].'/products/1',0700,true);file_put_contents($f['recovery'].'/products/1/wrong.png',$images['jpg']);
reject(fn()=>$f['repair']->plan($r,'products/1/wrong.png',$f['recovery']),'BACKUP_METADATA');

$f=fixture();$r=add($f,1,'mediaOriginal',$images['png']);
file_put_contents($f['public'].'/products/1/mediaOriginal.png',$images['jpg']);
reject(fn()=>$f['repair']->plan($r,'products/1/mediaOriginal'),'DESTINATION_COLLISION');
unlink($f['public'].'/products/1/mediaOriginal.png');
symlink($f['root'].'/database.sqlite',$f['public'].'/products/1/mediaOriginal.png');
reject(fn()=>$f['repair']->plan($r,'products/1/mediaOriginal'),'LINK');
unlink($f['public'].'/products/1/mediaOriginal.png');
$p=$f['repair']->plan($r,'products/1/mediaOriginal');
file_put_contents($f['public'].'/products/1/mediaOriginal',$images['jpg']);
reject(fn()=>$f['repair']->apply([$p],$f['backup']),'ABORTED_BACKUP');
check(row($f,1)===$r,'changed source cannot update metadata');

$f=fixture();$r1=add($f,1,'mediaOne',$images['png']);$r2=add($f,2,'mediaTwo',$images['jpg']);
$p1=$f['repair']->plan($r1,'products/1/mediaOne');$p2=$f['repair']->plan($r2,'products/2/mediaTwo');
$f['pdo']->exec("UPDATE media SET name='Concurrent image edit' WHERE id=2");
reject(fn()=>$f['repair']->apply([$p1,$p2],$f['backup']),'ABORTED_BACKUP');
check(row($f,1)===$r1,'later row conflict rolls back earlier media updates');
check(row($f,2)['name']==='Concurrent image edit','concurrent editor value retained');
check(file_get_contents($f['public'].'/products/1/mediaOne')===$images['png'],'rollback retains original bytes');
check(is_file($f['public'].'/products/1/mediaOne.png'),'verified copied bytes retained for recoverable retry');

$f=fixture();$r=add($f,1,'program.php',$images['png']);
reject(fn()=>$f['repair']->plan($r,'products/1/program.php'),'ORIGINAL_EXTENSION');
reject(fn()=>$f['repair']->plan($r,'../1/program.php'),'ORIGINAL_EXTENSION');
$r=add($f,2,'mediaText','<html>not an image</html>');
reject(fn()=>$f['repair']->plan($r,'products/2/mediaText'),'CONTENT_TYPE');
$r=add($f,3,'mediaLink',$images['png']);unlink($f['public'].'/products/3/mediaLink');
symlink($f['root'].'/database.sqlite',$f['public'].'/products/3/mediaLink');
reject(fn()=>$f['repair']->plan($r,'products/3/mediaLink'),'LINK');
$r=add($f,4,'mediaConverted',$images['png']);
$f['pdo']->exec('UPDATE media SET generated_conversions=\'{"thumb":true}\' WHERE id=4');
reject(fn()=>$f['repair']->plan(row($f,4),'products/4/mediaConverted'),'DERIVED_METADATA_NEEDS_REVIEW');

echo "PASS: 309 real image repairs, 8 original backup restorations, preserved originals and accounting, durable receipts, idempotence, collisions, links, content validation and transactional rollback.\n";
