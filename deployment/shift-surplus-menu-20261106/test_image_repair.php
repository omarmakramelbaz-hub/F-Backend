<?php
declare(strict_types=1);
define('SURPLUS_IMAGE_REPAIR_LIBRARY',true);
require __DIR__.'/repair_images.php';
function repair_expect(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
function repair_reject(callable $work,string $why): void {
    try { $work(); } catch (SurplusMenuException $e) { return; } throw new RuntimeException($why);
}
$base=sys_get_temp_dir().'/surplus-image-test-'.bin2hex(random_bytes(5));
mkdir($base,0700); mkdir($base.'/products',0755);
foreach (sm_assets() as $value=>$asset) {
    $source=$asset['file']; $before=hash_file('sha256',$source); $name='egp-'.$value.'.jpg';
    $copy=sm_publish_product_image($base.'/products',$value,$name,$source,$asset['sha256']);
    repair_expect($copy['created'] && hash_file('sha256',$copy['path'])===$before,'Publish exact denomination bytes to canonical products path');
    repair_expect(hash_file('sha256',$source)===$before,'Keep source and its working legacy URL intact');
    $again=sm_publish_product_image($base.'/products',$value,$name,$source,$asset['sha256']);
    repair_expect(!$again['created'] && $again['path']===$copy['path'],'Rerun must reuse the existing identical image');
    repair_expect((fileperms($copy['path'])&0777)===0644 && (fileperms(dirname($copy['path']))&0777)===0755,
        'Published product images must be readable through the web server');
    repair_reject(fn()=>sm_publish_product_image($base.'/products',$value,'../wrong.jpg',$source,$asset['sha256']),'Reject path traversal');
    file_put_contents($copy['path'],'conflicting image');
    repair_reject(fn()=>sm_publish_product_image($base.'/products',$value,$name,$source,$asset['sha256']),'Never overwrite a conflicting destination');
    repair_expect(file_get_contents($copy['path'])==='conflicting image','Leave the conflicting file unchanged');
    unlink($copy['path']); rmdir(dirname($copy['path']));
}
rmdir($base.'/products'); rmdir($base);
echo "SHIFT_SURPLUS_IMAGE_REPAIR_TESTS_OK\n";
