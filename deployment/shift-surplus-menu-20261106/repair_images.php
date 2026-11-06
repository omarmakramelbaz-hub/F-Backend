<?php
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use App\Models\ResturantProduct;
define('SURPLUS_MENU_LIBRARY',true);
require __DIR__.'/add_menu.php';

/** Publish an immutable copy under the original products disk; retain the working old URL. */
function sm_publish_product_image(string $root,int $id,string $name,string $source,string $sha): array {
    if ($id<1 || !preg_match('/^egp-(?:1|5|10|100)\.jpg$/D',$name) || !is_dir($root) || is_link($root)
        || !is_file($source) || is_link($source) || !hash_equals($sha,hash_file('sha256',$source)?:'')) sm_fail('IMAGE_COPY_INPUT_INVALID');
    $directory=$root.'/'.$id;
    if (is_link($directory) || (!is_dir($directory) && !mkdir($directory,0755))
        || realpath($directory)!==realpath($root).'/'.$id || !chmod($directory,0755)) sm_fail('IMAGE_COPY_DIRECTORY_INVALID');
    $target=$directory.'/'.$name; $created=false;
    if (is_link($target)) sm_fail('IMAGE_COPY_LINK_FORBIDDEN');
    if (file_exists($target)) {
        if (!is_file($target) || !hash_equals($sha,hash_file('sha256',$target)?:'')) sm_fail('IMAGE_COPY_CONFLICT');
    } else {
        $bytes=file_get_contents($source); if (!is_string($bytes) || !hash_equals($sha,hash('sha256',$bytes))) sm_fail('IMAGE_SOURCE_CHANGED');
        $h=fopen($target,'x+b'); if (!$h) sm_fail('IMAGE_COPY_CREATE_FAILED');
        try {
            for ($offset=0;$offset<strlen($bytes);$offset+=$n) {
                $n=fwrite($h,substr($bytes,$offset)); if ($n===false || $n===0) sm_fail('IMAGE_COPY_WRITE_FAILED');
            }
            if (!fflush($h) || (function_exists('fsync') && !fsync($h))) sm_fail('IMAGE_COPY_FLUSH_FAILED');
        } finally { fclose($h); }
        $created=true;
    }
    if (!chmod($target,0644) || !hash_equals($sha,hash_file('sha256',$target)?:'')) sm_fail('IMAGE_COPY_VERIFY_FAILED');
    return ['path'=>$target,'created'=>$created];
}

function sm_image_repair_state(array $assets,bool $lock=false): array {
    $plan=sm_read($lock);
    if ($lock) DB::table('media')->where('model_type',ResturantProduct::class)
        ->whereIn('model_id',array_column(array_column($plan['items'],'existing'),'id'))->orderBy('id')->lockForUpdate()->get();
    $summary=sm_summary($plan,$assets);
    if ($summary['status']!=='ready') sm_fail('EXISTING_MENU_MUST_BE_COMPLETE');
    $rows=[]; $samples=[]; $compatible=0;
    foreach ($plan['items'] as $item) {
        $model=ResturantProduct::withoutGlobalScopes()->findOrFail($item['existing']['id']);
        $media=$model->getFirstMedia('product_image'); $value=$item['value'];
        if (!$media || !in_array($media->disk,['public','products'],true)
            || !in_array($media->conversions_disk,[null,'','public','products'],true)
            || $media->file_name!=='egp-'.$value.'.jpg'
            || array_filter($media->generated_conversions??[]) || ($media->responsive_images??[])) sm_fail('UNEXPECTED_IMAGE_METADATA');
        $good=$media->disk==='products' && in_array($media->conversions_disk,[null,'','products'],true);
        if ($good) {
            // Execute the actual installed GO ERP image contract, including row identity and physical bytes.
            app(\App\Services\Dashboard\DesktopDashboardProductImages::class)->facts((int)$model->id);
            $compatible++;
        }
        $rows[]=['model'=>$model,'media'=>$media,'value'=>$value,'compatible'=>$good];
        if (!isset($samples[$value])) $samples[$value]=['value'=>$value,'url'=>$media->getUrl(),'sha256'=>$assets[$value]['sha256']];
    }
    return ['plan'=>$plan,'summary'=>$summary,'rows'=>$rows,'samples'=>array_values($samples),'compatible'=>$compatible];
}

function sm_repair_images(array $assets): array {
    return DB::transaction(function() use($assets) {
        $state=sm_image_repair_state($assets,true); $total=count($state['rows']);
        if ($state['compatible']===$total) return ['status'=>'ready','images_checked'=>$total,'images_repaired'=>0,'samples'=>$state['samples']];
        $base='/home/fasakha/menu-change-backups';
        if (is_link($base) || (!is_dir($base) && !mkdir($base,0700)) || !chmod($base,0700)) sm_fail('BACKUP_DIRECTORY_INVALID');
        $run=$base.'/shift-image-repair-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(5));
        if (!mkdir($run,0700)) sm_fail('BACKUP_RUN_FAILED');
        sm_save($run.'/before.json',['release'=>SM_RELEASE,'media'=>array_map(static fn($row)=>$row['media']->getAttributes(),$state['rows'])]);
        $root=storage_path('app/public/products'); $changed=0; $when=gmdate('Y-m-d H:i:s');
        foreach ($state['rows'] as $row) {
            if ($row['compatible']) continue;
            $media=$row['media']; $source=$media->getPath();
            sm_publish_product_image($root,(int)$media->id,$media->file_name,$source,$assets[$row['value']]['sha256']);
            DB::table('media')->where('id',$media->id)->update(['disk'=>'products','conversions_disk'=>'products','updated_at'=>$when]);
            // A fresh catalog/media fingerprint makes the existing client synchronization notice the image change.
            DB::table('resturant_products')->where('id',$row['model']->id)->update(['updated_at'=>$when]); $changed++;
        }
        $after=sm_image_repair_state($assets,true);
        if ($after['compatible']!==$total) sm_fail('DESKTOP_IMAGES_NOT_VERIFIED');
        $result=['status'=>'ready','images_checked'=>$total,'images_repaired'=>$changed,'samples'=>$after['samples'],'backup_directory'=>$run];
        sm_save($run.'/result.json',$result); return $result;
    },1);
}

function sm_repair_main(array $argv): int {
    $lock=''; $stage='bootstrap'; $locked=false;
    try {
        umask(0077); ini_set('display_errors','0'); ini_set('log_errors','0');
        if (PHP_SAPI!=='cli' || count($argv)!==2 || !in_array($argv[1],['--check','--apply'],true)) sm_fail('ARGUMENTS_INVALID');
        $root='/home/fasakha/public_html'; chdir($root); require $root.'/vendor/autoload.php';
        $app=require $root.'/bootstrap/app.php'; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $stage='preflight'; sm_preflight(); $assets=sm_assets();
        if (config('filesystems.disks.products.driver')!=='local'
            || realpath(config('filesystems.disks.products.root'))!==realpath(storage_path('app/public/products'))
            || !class_exists(\App\Services\Dashboard\DesktopDashboardProductImages::class)) sm_fail('PRODUCTS_MEDIA_CONTRACT_REQUIRED');
        if ($argv[1]==='--check') {
            $s=sm_image_repair_state($assets); echo sm_json(['status'=>$s['compatible']===count($s['rows'])?'ready':'pending',
                'images_checked'=>count($s['rows']),'desktop_compatible_images'=>$s['compatible'],'samples'=>$s['samples']])."\n"; return 0;
        }
        $lock=SM_RELEASE.':'.substr(hash('sha256',DB::connection()->getDatabaseName()),0,20);
        $r=DB::selectOne('SELECT GET_LOCK(?,0) AS acquired',[$lock]); if (!$r || (int)$r->acquired!==1) sm_fail('ANOTHER_MENU_UPDATE_RUNNING'); $locked=true;
        $stage='repair'; echo sm_json(sm_repair_images($assets))."\n"; return 0;
    } catch (Throwable $e) {
        fwrite(STDERR,sm_json(['status'=>'failed','stage'=>$stage,'error'=>$e instanceof SurplusMenuException?$e->getMessage():'IMAGE_REPAIR_FAILED'])."\n"); return 1;
    } finally { if ($locked) try { DB::selectOne('SELECT RELEASE_LOCK(?) AS released',[$lock]); } catch (Throwable $ignored) {} }
}
if (!defined('SURPLUS_IMAGE_REPAIR_LIBRARY')) exit(sm_repair_main($argv));
