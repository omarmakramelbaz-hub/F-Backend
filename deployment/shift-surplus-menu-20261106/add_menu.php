<?php
declare(strict_types=1);

use Illuminate\Support\Facades\{DB, Schema};
use App\Models\{Product, ResturantProduct};

const SM_RELEASE = 'fasakhansta-shift-surplus-20261106';
const SM_VALUES = [1, 5, 10, 100];
class SurplusMenuException extends RuntimeException {}
function sm_fail(string $code): void { throw new SurplusMenuException($code); }
function sm_json($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function sm_name(int $value): string { return 'زيادة وردية '.$value.' جنيه'; }
function sm_key(string $name): string {
    return trim((string) preg_replace('/\s+/u', ' ', strtr($name,
        ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'])));
}

/** Only the physical Fasakhansta branches and their registered retail stores. */
function sm_scope(array $rows, array $physical, array $registered): array {
    $byId=[]; foreach ($rows as $r) $byId[(int)$r['id']]=$r;
    foreach ($physical as $id) if (!isset($byId[$id])) sm_fail('PHYSICAL_BRANCH_MISSING_'.$id);
    $ids=array_fill_keys($physical,true);
    foreach ($rows as $r) {
        $id=(int)$r['id']; $parent=(int)($r['parent_id']??0);
        if (isset($registered[$id]) && in_array($parent,[0,301],true)
            && sm_key((string)$r['name'])===sm_key($registered[$id])) $ids[$id]=true;
    }
    // Include newly created retail stores as well as the historical registered list.
    do {
        $changed=false;
        foreach ($rows as $r) if (!isset($ids[(int)$r['id']]) && isset($ids[(int)($r['parent_id']??0)])) {
            $ids[(int)$r['id']]=true; $changed=true;
        }
    } while ($changed);
    $out=[]; foreach ($ids as $id=>$_) $out[$id]=$byId[$id]; ksort($out,SORT_NUMERIC); return $out;
}

/** Plan is pure: ambiguous names or existing conflicting values never get overwritten. */
function sm_plan(array $branches, array $categories, array $products, array $menus): array {
    $cats=array_values(array_filter($categories,static fn($r)=>
        in_array(sm_key((string)$r['name_ar']),['المشروبات','مشروبات'],true) && empty($r['parent_id'])));
    if (count($cats)!==1) sm_fail('DRINKS_CATEGORY_MISSING_OR_AMBIGUOUS');
    $cat=$cats[0]; if ($cat['status']!=='show') sm_fail('DRINKS_CATEGORY_HIDDEN');
    $catalog=[]; $rows=[];
    foreach (SM_VALUES as $value) {
        $key=sm_name($value);
        $matches=array_values(array_filter($products,static fn($p)=>sm_key((string)$p['name_ar'])===$key));
        if (count($matches)>1) sm_fail('DUPLICATE_CATALOG_'.$value);
        $product=$matches[0]??null;
        if ($product && ((int)$product['category_id']!==(int)$cat['id'] || $product['status']!=='show'
            || !empty($product['subcategory_id']))) sm_fail('CATALOG_CONFLICT_'.$value);
        $catalog[$value]=$product;
        foreach ($branches as $id=>$branch) {
            $matches=array_values(array_filter($menus,static fn($p)=>(int)$p['resturant_id']===$id
                && sm_key((string)$p['product_name'])===$key));
            if (count($matches)>1) sm_fail('DUPLICATE_BRANCH_ITEM_'.$id.'_'.$value);
            $menu=$matches[0]??null;
            if ($menu && (!$product || (int)$menu['product_id']!==(int)$product['id']
                || (int)$menu['category_id']!==(int)$cat['id'] || $menu['status']!=='show'
                || !is_numeric($menu['product_price']) || (float)$menu['product_price']!==(float)$value))
                sm_fail('BRANCH_ITEM_CONFLICT_'.$id.'_'.$value);
            $rows[]=['branch_id'=>$id,'value'=>$value,'existing'=>$menu];
        }
    }
    return ['category'=>$cat,'branches'=>$branches,'catalog'=>$catalog,'items'=>$rows];
}

function sm_assets(): array {
    $manifest=json_decode(file_get_contents(__DIR__.'/assets.json'),true,512,JSON_THROW_ON_ERROR);
    if (array_map('intval',array_keys($manifest))!==SM_VALUES) sm_fail('ASSET_VALUES_INVALID');
    foreach ($manifest as $value=>&$asset) {
        $file=__DIR__.'/assets/egp-'.$value.'.jpg'; $size=@getimagesize($file);
        if (!$size || $size[2]!==IMAGETYPE_JPEG || $size[0]>600 || $size[1]>600
            || !hash_equals($asset['sha256'],hash_file('sha256',$file)?:'')) sm_fail('IMAGE_INVALID_'.$value);
        $asset['file']=$file;
    } unset($asset); return $manifest;
}

function sm_read(bool $lock=false): array {
    $q=DB::table('resturants')->orderBy('id'); if ($lock) $q->lockForUpdate();
    $all=array_map(static fn($r)=>(array)$r,$q->get()->all());
    $physical=\App\Services\Dashboard\FasakhanstaDeliveryOrigin::PHYSICAL_BRANCH_IDS;
    $registered=\App\Services\Dashboard\FasakhanstaDeliveryOrigin::CAIRO_ORIGINS;
    $branches=sm_scope($all,$physical,$registered);
    $q=DB::table('categories')->orderBy('id'); if ($lock) $q->lockForUpdate();
    $categories=array_map(static fn($r)=>(array)$r,$q->get()->all());
    $q=DB::table('products')->where('name_ar','like','%زيادة%وردية%')->orderBy('id'); if ($lock) $q->lockForUpdate();
    $products=array_map(static fn($r)=>(array)$r,$q->get()->all());
    $q=DB::table('resturant_products')->whereIn('resturant_id',array_keys($branches))
        ->where('product_name','like','%زيادة%وردية%')->orderBy('id'); if ($lock) $q->lockForUpdate();
    $menus=array_map(static fn($r)=>(array)$r,$q->get()->all());
    return sm_plan($branches,$categories,$products,$menus);
}

/** Validate the exact file used by the menu image accessor, rather than only its database row. */
function sm_image(ResturantProduct $model, int $value, array $assets): bool {
    $media=$model->getMedia('product_image'); if ($media->count()===0) return false;
    if ($media->count()!==1) sm_fail('MULTIPLE_IMAGES_'.$model->id);
    $image=$media->first(); $path=$image->getPath();
    if ($image->getCustomProperty('release')!==SM_RELEASE || !is_file($path) || is_link($path)
        || !hash_equals($assets[$value]['sha256'],hash_file('sha256',$path)?:''))
        sm_fail('IMAGE_CONFLICT_'.$model->id);
    $url=$image->getUrl(); $parts=parse_url($url);
    if (($parts['scheme']??'')!=='https' || !in_array($parts['host']??'',['fasakhaninja.com','www.fasakhaninja.com'],true)
        || !str_starts_with($parts['path']??'','/storage/')) sm_fail('IMAGE_URL_INVALID_'.$model->id);
    return true;
}

function sm_summary(array $plan,array $assets): array {
    $catalogMissing=count(array_filter($plan['catalog'],static fn($p)=>$p===null));
    $missing=0; $imagesMissing=0; $ids=[];
    foreach ($plan['items'] as $item) if ($item['existing']) $ids[]=(int)$item['existing']['id'];
    $models=ResturantProduct::withoutGlobalScopes()->with('media')->whereIn('id',$ids)->get()->keyBy('id');
    foreach ($plan['items'] as $item) {
        if (!$item['existing']) { $missing++; $imagesMissing++; continue; }
        $model=$models->get((int)$item['existing']['id']); if (!$model) sm_fail('MENU_ROW_MISSING');
        if (!sm_image($model,$item['value'],$assets)) $imagesMissing++;
    }
    return ['status'=>$catalogMissing===0 && $missing===0 && $imagesMissing===0?'ready':'pending',
        'release'=>SM_RELEASE,'category'=>['id'=>(int)$plan['category']['id'],'name'=>$plan['category']['name_ar']],
        'values_egp'=>SM_VALUES,'branch_store_count'=>count($plan['branches']),
        'expected_menu_items'=>count($plan['items']),'missing_catalog_items'=>$catalogMissing,
        'missing_menu_items'=>$missing,'missing_images'=>$imagesMissing,
        'branches'=>array_map(static fn($b)=>['id'=>(int)$b['id'],'name'=>$b['name']],array_values($plan['branches']))];
}

function sm_filter(string $table,array $row): array {
    return array_intersect_key($row,array_flip(Schema::getColumnListing($table)));
}

/** The deployed menu row belongs to a restaurant; its owner user_id is on resturants. */
function sm_required_schema(): array {
    return ['products'=>['id','added_by','name_ar','category_id','status'],
        'categories'=>['id','name_ar','parent_id','status'],
        'resturants'=>['id','parent_id','name','user_id','added_by'],
        'resturant_products'=>['id','added_by','resturant_id','product_id','category_id','product_name','product_price','price','status'],
        'media'=>['id','model_type','model_id','collection_name','disk','file_name','custom_properties']];
}
function sm_validate_schema(array $actual): void {
    foreach (sm_required_schema() as $table=>$columns) {
        if (!isset($actual[$table])) sm_fail('SCHEMA_TABLE_MISSING_'.$table);
        $missing=array_values(array_diff($columns,$actual[$table]));
        if ($missing) sm_fail('SCHEMA_COLUMNS_MISSING_'.$table.':'.implode(',',$missing));
    }
}
function sm_menu_values(array $branch,int $branchId,int $value,int $productId,int $categoryId,string $when,array $columns): array {
    return array_intersect_key(['added_by'=>(int)($branch['added_by']??1),
        'user_id'=>$branch['user_id'],'resturant_id'=>$branchId,'product_id'=>$productId,'category_id'=>$categoryId,
        'product_name'=>sm_name($value),'name_ar'=>sm_name($value),'name_en'=>sm_name($value),
        'product_price'=>number_format($value,2,'.',''),'price'=>sm_json(array_fill_keys(
            ['extra_combo','extra_large','extra_medium','extra_clean','extra_clear','extra_vacuim'],null)),
        'prices'=>'{}','product_description'=>'زيادة وردية بقيمة '.$value.' جنيه',
        'description'=>'زيادة وردية بقيمة '.$value.' جنيه','status'=>'show','highest_rated'=>'no',
        'created_at'=>$when,'updated_at'=>$when],array_flip($columns));
}
function sm_save(string $path,array $data): void {
    $bytes=sm_json($data)."\n"; $h=fopen($path,'x+b'); if (!$h) sm_fail('BACKUP_OPEN_FAILED');
    try {
        if (!chmod($path,0600)) sm_fail('BACKUP_PERMISSIONS_FAILED');
        for ($offset=0;$offset<strlen($bytes);$offset+=$n) {
            $n=fwrite($h,substr($bytes,$offset)); if ($n===false || $n===0) sm_fail('BACKUP_WRITE_FAILED');
        }
        if (!fflush($h) || (function_exists('fsync') && !fsync($h))) sm_fail('BACKUP_FLUSH_FAILED');
    } finally { fclose($h); }
    if (!hash_equals(hash('sha256',$bytes),hash_file('sha256',$path)?:'')) sm_fail('BACKUP_VERIFY_FAILED');
}

function sm_apply(array $assets): array {
    $newFiles=[];
    try {
        return DB::transaction(function() use($assets,&$newFiles) {
            $plan=sm_read(true); $before=sm_summary($plan,$assets);
            if ($before['status']==='ready') return $before+['data_writes'=>0];
            $base='/home/fasakha/menu-change-backups';
            if (is_link($base) || (!is_dir($base) && !mkdir($base,0700)) || !chmod($base,0700)) sm_fail('BACKUP_DIRECTORY_INVALID');
            $run=$base.'/'.SM_RELEASE.'-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(5));
            if (!mkdir($run,0700)) sm_fail('BACKUP_RUN_FAILED');
            sm_save($run.'/before.json',['release'=>SM_RELEASE,'plan'=>$plan,'summary'=>$before]);
            $when=gmdate('Y-m-d H:i:s'); $createdCatalog=[]; $createdMenu=[]; $mediaIds=[];
            foreach (SM_VALUES as $value) {
                $p=$plan['catalog'][$value];
                if (!$p) {
                    $row=sm_filter('products',['added_by'=>1,'category_id'=>(int)$plan['category']['id'],
                        'subcategory_id'=>null,'name_ar'=>sm_name($value),'name_en'=>sm_name($value),'status'=>'show',
                        'description'=>'زيادة وردية بقيمة '.$value.' جنيه','created_at'=>$when,'updated_at'=>$when]);
                    $id=DB::table('products')->insertGetId($row); $createdCatalog[]=$id; $p=['id'=>$id];
                }
                $plan['catalog'][$value]=$p;
            }
            foreach ($plan['items'] as $item) {
                $value=$item['value']; $id=$item['existing']['id']??null;
                if (!$id) {
                    $branch=$plan['branches'][$item['branch_id']];
                    $row=sm_menu_values($branch,$item['branch_id'],$value,(int)$plan['catalog'][$value]['id'],
                        (int)$plan['category']['id'],$when,Schema::getColumnListing('resturant_products'));
                    $id=DB::table('resturant_products')->insertGetId($row); $createdMenu[]=$id;
                }
                $model=ResturantProduct::withoutGlobalScopes()->findOrFail($id);
                if (!sm_image($model,$value,$assets)) {
                    // Public disk gives a valid /storage URL without the legacy products-disk prefix mismatch.
                    $media=$model->addMedia($assets[$value]['file'])->preservingOriginal()
                        ->withCustomProperties(['release'=>SM_RELEASE,'value_egp'=>$value])
                        ->toMediaCollection('product_image','public');
                    $newFiles[]=$media->getPath(); $mediaIds[]=(int)$media->id;
                }
            }
            $after=sm_summary(sm_read(true),$assets); if ($after['status']!=='ready') sm_fail('MENU_VERIFICATION_FAILED');
            $result=$after+['data_writes'=>count($createdCatalog)+count($createdMenu)+count($mediaIds),
                'created_catalog_ids'=>$createdCatalog,'created_menu_ids'=>$createdMenu,'created_media_ids'=>$mediaIds,'backup_directory'=>$run];
            sm_save($run.'/result.json',$result); return $result;
        },1);
    } catch (Throwable $e) {
        // Only remove files this attempt created after their database transaction has rolled back.
        $root=realpath(storage_path('app/public'));
        foreach ($newFiles as $file) if ($root && !is_link($file) && str_starts_with($file,$root.'/') && is_file($file)) {
            @unlink($file); @rmdir(dirname($file));
        }
        throw $e;
    }
}

function sm_preflight(): void {
    $db=DB::connection();
    if (realpath(base_path())!=='/home/fasakha/public_html' || app()->bound('owner.dashboard.runtime')
        || config('desktop_dashboard.local') || $db->getDriverName()!=='mysql' || $db->getTablePrefix()!==''
        || !in_array($db->getConfig('host'),['localhost','127.0.0.1','::1'],true)) sm_fail('PRIMARY_DATABASE_REQUIRED');
    if (!in_array(strtolower((string)parse_url(config('app.url'),PHP_URL_HOST)),['fasakhaninja.com','www.fasakhaninja.com'],true)) sm_fail('PRIMARY_DOMAIN_REQUIRED');
    $owner=DB::table('users')->where('id',1)->first();
    if (!$owner || $owner->account_type!=='admin' || $owner->status!=='accepted') sm_fail('PRIMARY_OWNER_REQUIRED');
    $required=sm_required_schema(); $actual=[];
    foreach ($required as $table=>$_) if (Schema::hasTable($table)) $actual[$table]=Schema::getColumnListing($table);
    sm_validate_schema($actual);
    $engines=DB::select('SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN (?,?,?,?,?)',
        array_merge([$db->getDatabaseName()],array_keys($required)));
    if (count($engines)!==count($required)) sm_fail('TABLE_ENGINE_MISSING');
    foreach ($engines as $row) if (strtolower($row->engine)!=='innodb') sm_fail('TRANSACTIONAL_TABLES_REQUIRED');
    if (config('filesystems.disks.public.driver')!=='local'
        || realpath(config('filesystems.disks.public.root'))!==realpath(storage_path('app/public'))
        || realpath(public_path('storage'))!==realpath(storage_path('app/public'))) sm_fail('PUBLIC_IMAGE_STORAGE_REQUIRED');
}

function sm_main(array $argv): int {
    $locked=false; $stage='arguments'; $lock='';
    try {
        umask(0077); ini_set('display_errors','0'); ini_set('log_errors','0');
        if (PHP_SAPI!=='cli' || count($argv)!==2 || !in_array($argv[1],['--check','--apply'],true)) sm_fail('ARGUMENTS_INVALID');
        $assets=sm_assets(); $stage='bootstrap'; $root='/home/fasakha/public_html'; chdir($root);
        require $root.'/vendor/autoload.php'; $app=require $root.'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $stage='preflight'; sm_preflight();
        if ($argv[1]==='--check') { echo sm_json(sm_summary(sm_read(),$assets))."\n"; return 0; }
        $stage='lock'; $lock=SM_RELEASE.':'.substr(hash('sha256',DB::connection()->getDatabaseName()),0,20);
        $r=DB::selectOne('SELECT GET_LOCK(?,0) AS acquired',[$lock]);
        if (!$r || (int)$r->acquired!==1) sm_fail('ANOTHER_MENU_UPDATE_RUNNING'); $locked=true;
        $stage='apply'; echo sm_json(sm_apply($assets))."\n"; return 0;
    } catch (Throwable $e) {
        fwrite(STDERR,sm_json(['status'=>'failed','stage'=>$stage,'error'=>$e instanceof SurplusMenuException?$e->getMessage():'OPERATION_FAILED'])."\n"); return 1;
    } finally { if ($locked) try { DB::selectOne('SELECT RELEASE_LOCK(?) AS released',[$lock]); } catch (Throwable $ignored) {} }
}
if (!defined('SURPLUS_MENU_LIBRARY')) exit(sm_main($argv));
