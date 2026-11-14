<?php
declare(strict_types=1);

use Illuminate\Support\Facades\{DB, Schema};
use App\Models\ResturantProduct;
use App\Services\Dashboard\{BranchInventory, DesktopDashboardProductImages, FasakhanstaDeliveryOrigin, FasakhanstaStoreFulfillment};

require_once __DIR__.'/recipe_contract.php';
const NF_RELEASE = 'fasakhansta-net-fillet-20261114';
const NF_CATEGORY = 'مخلي صافي بالوزن';
const NF_PHYSICAL = [94,301,307,363];
class NetFilletMenuException extends RuntimeException {}
function nf_fail(string $code): void { throw new NetFilletMenuException($code); }
function nf_json($value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function nf_key(string $value): string {
    return trim((string)preg_replace('/\s+/u',' ',strtr($value,['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'])));
}
function nf_specs(): array {
    return [
        'feseekh'=>['name'=>'فسيخ مخلي صافي','branch_price'=>1000,'store_price'=>1400,'source_id'=>732,'source_catalog'=>53,'source_name'=>'فسيخ نبروه 3 قطعه'],
        'herring'=>['name'=>'رنجة مخلي صافي','branch_price'=>600,'store_price'=>800,'source_id'=>782,'source_catalog'=>20,'source_name'=>'رنجه هيرنج'],
        'sardine'=>['name'=>'سردين مخلي صافي','branch_price'=>600,'store_price'=>800,'source_id'=>779,'source_catalog'=>37,'source_name'=>'سردين'],
    ];
}
/** Restrict additions to actual physical branches and verified Shubra retail fronts. */
function nf_scope(array $rows,array $registered): array {
    $byId=[];$scope=[];
    foreach($rows as $r)$byId[(int)$r['id']]=$r;
    foreach(NF_PHYSICAL as $id){if(!isset($byId[$id]))nf_fail('PHYSICAL_BRANCH_MISSING_'.$id);$scope[$id]=$byId[$id];}
    foreach($rows as $r){
        $id=(int)$r['id'];$parent=(int)($r['parent_id']??0);
        if(in_array($id,NF_PHYSICAL,true))continue;
        if($parent===301||(isset($registered[$id])&&$parent===0&&nf_key((string)$r['name'])===nf_key($registered[$id])))$scope[$id]=$r;
    }
    ksort($scope,SORT_NUMERIC);return $scope;
}
function nf_price(int $id,array $spec): int { return $spec[in_array($id,NF_PHYSICAL,true)?'branch_price':'store_price']; }
function nf_one(array $matches,string $error): ?array { if(count($matches)>1)nf_fail($error);return $matches[0]??null; }
function nf_extras(): string { return nf_json(array_fill_keys(['extra_combo','extra_large','extra_medium','extra_clean','extra_clear','extra_vacuim'],null)); }

/** Pure plan: only missing data can be inserted; existing conflicting data is never overwritten. */
function nf_plan(array $scope,array $categories,array $products,array $menus,array $features,array $recipes,array $ingredients): array {
    $category=nf_one(array_values(array_filter($categories,fn($r)=>nf_key((string)$r['name_ar'])===NF_CATEGORY)),'DUPLICATE_CATEGORY');
    if($category&&(!empty($category['parent_id'])||$category['status']!=='show'))nf_fail('CATEGORY_CONFLICT');
    $catalog=[];$planned=[];$goods=[];$kilos=[];
    foreach(nf_specs() as $family=>$spec){
        $goods[$family]=NetFilletRecipeContract::resolveIngredient($ingredients,$family);
        $p=nf_one(array_values(array_filter($products,fn($r)=>nf_key((string)$r['name_ar'])===$spec['name'])),'DUPLICATE_CATALOG_'.$family);
        if($p&&(!$category||(int)$p['category_id']!==(int)$category['id']||!empty($p['subcategory_id'])||$p['status']!=='show'||(int)$p['has_clean']!==0))nf_fail('CATALOG_CONFLICT_'.$family);
        $catalog[$family]=$p;
        $pf=$p?array_values(array_filter($features,fn($f)=>(int)$f['product_id']===(int)$p['id'])):[];
        if(count($pf)>1||($pf&&$pf[0]['name']!=='kilo'))nf_fail('FEATURE_CONFLICT_'.$family);
        $kilos[$family]=$pf[0]??null;
        foreach($scope as $branchId=>$branch){
            $m=nf_one(array_values(array_filter($menus,fn($r)=>(int)$r['resturant_id']===$branchId&&nf_key((string)$r['product_name'])===$spec['name'])),'DUPLICATE_MENU_'.$branchId.'_'.$family);
            if($m&&(!$p||!$category||(int)$m['product_id']!==(int)$p['id']||(int)$m['category_id']!==(int)$category['id']||$m['status']!=='show'||!is_numeric($m['product_price'])||(float)$m['product_price']!==(float)nf_price($branchId,$spec)))nf_fail('MENU_CONFLICT_'.$branchId.'_'.$family);
            if($m){
                $extras=json_decode((string)$m['price'],true);
                if(!is_array($extras)||$extras!==json_decode(nf_extras(),true))nf_fail('SURCHARGE_CONFLICT_'.$m['id']);
            }
            $recipe=$m?nf_one(array_values(array_filter($recipes,fn($r)=>$r['branch']==='f:'.$branchId&&(int)$r['product_id']===(int)$m['id'])),'DUPLICATE_RECIPE_'.$m['id']):null;
            if($recipe&&($recipe['unit']!=='kg'||json_decode($recipe['variants'],true)!==NetFilletRecipeContract::variants($goods[$family])))nf_fail('RECIPE_CONFLICT_'.$m['id']);
            $planned[]=['branch_id'=>$branchId,'family'=>$family,'existing'=>$m,'recipe'=>$recipe];
        }
    }
    // A shared catalog ID must resolve to one and only one Shubra SKU for store orders.
    foreach($catalog as $family=>$p)if($p){
        $all=array_values(array_filter($menus,fn($m)=>(int)$m['resturant_id']===301&&(int)$m['product_id']===(int)$p['id']));
        if(count($all)>1)nf_fail('AMBIGUOUS_SHUBRA_CATALOG_'.$family);
    }
    return ['scope'=>$scope,'category'=>$category,'catalog'=>$catalog,'features'=>$kilos,'ingredients'=>$goods,'items'=>$planned];
}
function nf_rows($query,bool $lock=false): array { if($lock)$query->lockForUpdate();return array_map(fn($r)=>(array)$r,$query->get()->all()); }
function nf_read(bool $lock=false): array {
    $locations=nf_rows(DB::table('resturants')->orderBy('id'),$lock);
    if(FasakhanstaDeliveryOrigin::PHYSICAL_BRANCH_IDS!==NF_PHYSICAL)nf_fail('PHYSICAL_SCOPE_CHANGED');
    $scope=nf_scope($locations,FasakhanstaDeliveryOrigin::CAIRO_ORIGINS);
    foreach($scope as $id=>$row)if(!in_array($id,NF_PHYSICAL,true)&&FasakhanstaStoreFulfillment::branch('f:'.$id)!=='f:301')nf_fail('STORE_FULFILLMENT_CONFLICT_'.$id);
    return nf_plan($scope,
        nf_rows(DB::table('categories')->orderBy('id'),$lock),
        nf_rows(DB::table('products')->orderBy('id'),$lock),
        nf_rows(DB::table('resturant_products')->whereIn('resturant_id',array_keys($scope))->orderBy('id'),$lock),
        nf_rows(DB::table('product_features')->orderBy('id'),$lock),
        nf_rows(DB::table('branch_stock_recipes')->whereIn('branch',array_map(fn($id)=>'f:'.$id,array_keys($scope)))->orderBy('id'),$lock),
        nf_rows(DB::table('stock_ingredients')->orderBy('id'),$lock));
}
function nf_file(string $path): array {
    $root=realpath(storage_path('app/public/products'));$real=realpath($path);
    if(!$root||!$real||is_link($path)||!str_starts_with($real,$root.'/')||!is_file($path))nf_fail('IMAGE_PATH_INVALID');
    $size=getimagesize($path);$bytes=filesize($path);
    if(!$size||!in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)||$bytes<1||$bytes>5*1024*1024)nf_fail('IMAGE_BYTES_INVALID');
    return ['path'=>$path,'sha256'=>hash_file('sha256',$path),'bytes'=>$bytes];
}
/** Reuse each corresponding raw fish image currently shown in Shubra's menu. */
function nf_sources(): array {
    $sources=[];
    foreach(nf_specs() as $family=>$spec){
        $model=ResturantProduct::withoutGlobalScopes()->find($spec['source_id']);
        if(!$model||(int)$model->resturant_id!==301||(int)$model->product_id!==$spec['source_catalog']||nf_key($model->product_name)!==$spec['source_name'])nf_fail('SOURCE_SKU_CHANGED_'.$family);
        $media=$model->getFirstMedia('product_image');if(!$media||$media->disk!=='products')nf_fail('SOURCE_IMAGE_MISSING_'.$family);
        $parts=parse_url($model->getFirstMediaUrl('product_image','thumb'));
        if(($parts['scheme']??'')!=='https'||!in_array($parts['host']??'',['fasakhaninja.com','www.fasakhaninja.com'],true)||!str_starts_with($parts['path']??'','/storage/products/'))nf_fail('SOURCE_IMAGE_URL_CONTRACT_CHANGED_'.$family);
        $sources[$family]=nf_file($media->getPath())+['source_menu_id'=>(int)$model->id,'source_media_id'=>(int)$media->id];
    }
    return $sources;
}
function nf_image(ResturantProduct $model,string $family,array $sources): ?array {
    $images=$model->getMedia('product_image');if($images->count()===0)return null;
    if($images->count()!==1)nf_fail('MULTIPLE_TARGET_IMAGES_'.$model->id);
    $media=$images->first();$file=nf_file($media->getPath());
    if($media->disk!=='products'||$media->getCustomProperty('release')!==NF_RELEASE||$media->getCustomProperty('family')!==$family||!hash_equals($sources[$family]['sha256'],$file['sha256']))nf_fail('TARGET_IMAGE_CONFLICT_'.$model->id);
    $url=$model->getFirstMediaUrl('product_image','thumb');$parts=parse_url($url);
    if(($parts['scheme']??'')!=='https'||!in_array($parts['host']??'',['fasakhaninja.com','www.fasakhaninja.com'],true)||!preg_match('#^/storage/products/[1-9][0-9]*/[^/]+$#D',$parts['path']??''))nf_fail('TARGET_IMAGE_URL_INVALID_'.$model->id);
    // Ensures GO ERP's image transport can read the actual saved media and bytes.
    app(DesktopDashboardProductImages::class)->facts((int)$model->id);
    return ['url'=>$url,'sha256'=>$file['sha256']];
}
function nf_summary(array $plan,array $sources): array {
    $missing=['category'=>$plan['category']?0:1,'catalog'=>0,'features'=>0,'menu'=>0,'recipes'=>0,'images'=>0];$samples=[];$verified=0;$mapped=0;
    $inventory=app(BranchInventory::class);
    foreach($plan['catalog'] as $family=>$p){if(!$p)$missing['catalog']++;if(!$plan['features'][$family])$missing['features']++;}
    foreach($plan['items'] as $item){
        $family=$item['family'];$id=$item['branch_id'];
        if(!$item['existing']){$missing['menu']++;$missing['recipes']++;$missing['images']++;continue;}
        $sku=(int)$item['existing']['id'];$image=nf_image(ResturantProduct::withoutGlobalScopes()->findOrFail($sku),$family,$sources);
        if(!$image)$missing['images']++;elseif(!isset($samples[$family]))$samples[$family]=['family'=>$family]+$image;
        if(!$item['recipe']){$missing['recipes']++;continue;}
        NetFilletRecipeContract::verifySnapshots($inventory,'f:'.$id,$sku,$plan['ingredients'][$family],(int)($plan['features'][$family]['id']??0));$verified++;
        $decorated=$inventory->decorate('f:'.$id,[['id'=>$sku]]);
        if(($decorated[0]['unit']??'')!=='kg'||($decorated[0]['quantity_mode']??'')!=='weight')nf_fail('WEIGHT_MODE_FAILED_'.$sku);
        if(!in_array($id,NF_PHYSICAL,true)){
            $line=['product_id'=>$sku,'name'=>$item['existing']['product_name'],'quantity_millis'=>250,'feature_id'=>0];
            $lines=FasakhanstaStoreFulfillment::inventoryLines('f:'.$id,[$line]);
            $snap=$inventory->snapshots('f:301',$lines)[0]['inventory'];
            if($snap['branch']!=='f:301'||$snap['unit']!=='kg'||$snap['components']!==NetFilletRecipeContract::variants($plan['ingredients'][$family])[0])nf_fail('STORE_STOCK_MAPPING_FAILED_'.$sku);
            $mapped++;
        }
    }
    return ['release'=>NF_RELEASE,'status'=>array_sum($missing)===0?'ready':'pending','category'=>NF_CATEGORY,'unit'=>'kg',
        'branch_count'=>4,'store_count'=>count($plan['scope'])-4,'location_count'=>count($plan['scope']),'expected_menu_items'=>3*count($plan['scope']),
        'stock_ratio'=>'2 kg raw / 1 kg net','prices_egp'=>nf_specs(),'missing'=>$missing,'recipes_verified'=>$verified,'store_mappings_verified'=>$mapped,'samples'=>array_values($samples)];
}
function nf_filter(string $table,array $data): array { return array_intersect_key($data,array_flip(Schema::getColumnListing($table))); }
function nf_save(string $path,array $data): void {
    $bytes=nf_json($data)."\n";$h=fopen($path,'x+b');if(!$h)nf_fail('BACKUP_OPEN_FAILED');
    try{if(!chmod($path,0600))nf_fail('BACKUP_CHMOD_FAILED');$at=0;while($at<strlen($bytes)){$n=fwrite($h,substr($bytes,$at));if(!$n)nf_fail('BACKUP_WRITE_FAILED');$at+=$n;}if(!fflush($h)||(function_exists('fsync')&&!fsync($h)))nf_fail('BACKUP_FLUSH_FAILED');}finally{fclose($h);}
    if(!hash_equals(hash('sha256',$bytes),hash_file('sha256',$path)))nf_fail('BACKUP_VERIFY_FAILED');
}
function nf_apply(array $sources): array {
    $newMedia=[];
    try{return DB::transaction(function()use($sources,&$newMedia){
        $plan=nf_read(true);$before=nf_summary($plan,$sources);if($before['status']==='ready')return $before+['data_writes'=>0];
        $base='/home/fasakha/menu-change-backups';if(is_link($base)||(!is_dir($base)&&!mkdir($base,0700))||!chmod($base,0700))nf_fail('BACKUP_DIRECTORY_INVALID');
        $run=$base.'/'.NF_RELEASE.'-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(5));if(!mkdir($run,0700))nf_fail('BACKUP_RUN_FAILED');
        nf_save($run.'/before.json',['release'=>NF_RELEASE,'plan'=>$plan,'sources'=>$sources,'summary'=>$before]);
        $now=gmdate('Y-m-d H:i:s');$created=['category'=>[],'catalog'=>[],'features'=>[],'menu'=>[],'recipes'=>[],'media'=>[]];
        $categoryId=(int)($plan['category']['id']??0);
        if(!$categoryId){$categoryId=DB::table('categories')->insertGetId(nf_filter('categories',['added_by'=>1,'parent_id'=>null,'name_ar'=>NF_CATEGORY,'name_en'=>NF_CATEGORY,'status'=>'show','order'=>(int)DB::table('categories')->max('order')+1,'created_at'=>$now,'updated_at'=>$now]));$created['category'][]=$categoryId;}
        foreach(nf_specs() as $family=>$spec){
            $productId=(int)($plan['catalog'][$family]['id']??0);
            if(!$productId){$productId=DB::table('products')->insertGetId(nf_filter('products',['added_by'=>1,'category_id'=>$categoryId,'subcategory_id'=>null,'name_ar'=>$spec['name'],'name_en'=>$spec['name'],'has_clean'=>0,'status'=>'show','created_at'=>$now,'updated_at'=>$now]));$created['catalog'][]=$productId;}
            $plan['catalog'][$family]=['id'=>$productId];
            if(!$plan['features'][$family]){$featureId=DB::table('product_features')->insertGetId(nf_filter('product_features',['added_by'=>1,'product_id'=>$productId,'name'=>'kilo','created_at'=>$now,'updated_at'=>$now]));$created['features'][]=$featureId;}
        }
        foreach($plan['items'] as $item){
            $family=$item['family'];$spec=nf_specs()[$family];$branchId=$item['branch_id'];$branch=$plan['scope'][$branchId];$sku=(int)($item['existing']['id']??0);
            if(!$sku){$sku=DB::table('resturant_products')->insertGetId(nf_filter('resturant_products',['added_by'=>(int)($branch['added_by']??1),'resturant_id'=>$branchId,'product_id'=>$plan['catalog'][$family]['id'],'category_id'=>$categoryId,'product_name'=>$spec['name'],'product_description'=>'مخلي صافي بعد التخلية، السعر للكيلو الصافي ويُحسب حسب الوزن المطلوب.','product_price'=>number_format(nf_price($branchId,$spec),2,'.',''),'price'=>nf_extras(),'status'=>'show','highest_rated'=>'no','created_at'=>$now,'updated_at'=>$now]));$created['menu'][]=$sku;}
            if(!$item['recipe']){$recipeId=DB::table('branch_stock_recipes')->insertGetId(['branch'=>'f:'.$branchId,'product_id'=>$sku,'unit'=>'kg','revision'=>1,'variants'=>NetFilletRecipeContract::variantsJson($plan['ingredients'][$family]),'updated_by'=>1,'created_at'=>$now,'updated_at'=>$now]);$created['recipes'][]=$recipeId;}
            $model=ResturantProduct::withoutGlobalScopes()->findOrFail($sku);
            if(!nf_image($model,$family,$sources)){
                $media=$model->addMedia($sources[$family]['path'])->preservingOriginal()->withCustomProperties(['release'=>NF_RELEASE,'family'=>$family,'source_menu_id'=>$sources[$family]['source_menu_id']])->toMediaCollection('product_image','products');
                $newMedia[]=$media;$created['media'][]=(int)$media->id;
            }
        }
        $after=nf_summary(nf_read(true),$sources);if($after['status']!=='ready')nf_fail('VERIFICATION_FAILED');
        $result=$after+['created'=>$created,'data_writes'=>array_sum(array_map('count',$created)),'backup_directory'=>$run];nf_save($run.'/result.json',$result);return $result;
    },1);}catch(Throwable $e){
        // The database rolled back. Delete only physical image files created by this attempt.
        foreach($newMedia as $media){try{app(\Spatie\MediaLibrary\MediaCollections\Filesystem::class)->removeAllFiles($media);}catch(Throwable $ignored){}}
        throw $e;
    }
}
function nf_required_schema(): array {
    return ['products'=>['id','added_by','category_id','subcategory_id','name_ar','name_en','has_clean','status'],
        'categories'=>['id','added_by','parent_id','name_ar','name_en','status','order'],
        'resturants'=>['id','parent_id','name','added_by'],
        'resturant_products'=>['id','added_by','resturant_id','product_id','category_id','product_name','product_price','price','status'],
        'product_features'=>['id','added_by','product_id','name'],
        'stock_ingredients'=>['id','name','unit'],
        'branch_stock_recipes'=>['id','branch','product_id','unit','revision','variants','updated_by','created_at','updated_at'],
        'media'=>['id','model_type','model_id','collection_name','disk','file_name','custom_properties']];
}
function nf_preflight(): void {
    $db=DB::connection();
    if(realpath(base_path())!=='/home/fasakha/public_html'||app()->bound('owner.dashboard.runtime')||config('desktop_dashboard.local')||$db->getDriverName()!=='mysql'||$db->getTablePrefix()!==''||!in_array($db->getConfig('host'),['localhost','127.0.0.1','::1'],true))nf_fail('PRIMARY_DATABASE_REQUIRED');
    if(!in_array(strtolower((string)parse_url(config('app.url'),PHP_URL_HOST)),['fasakhaninja.com','www.fasakhaninja.com'],true))nf_fail('PRIMARY_DOMAIN_REQUIRED');
    $owner=DB::table('users')->where('id',1)->first();if(!$owner||$owner->account_type!=='admin'||$owner->status!=='accepted')nf_fail('PRIMARY_OWNER_REQUIRED');
    $required=nf_required_schema();foreach($required as $table=>$columns){if(!Schema::hasTable($table)||array_diff($columns,Schema::getColumnListing($table)))nf_fail('SCHEMA_MISMATCH_'.$table);}
    foreach(['branch_recipe_sales','branch_inventory','branch_inventory_movements'] as $table)if(!Schema::hasTable($table))nf_fail('STOCK_SCHEMA_MISSING_'.$table);
    $tables=array_keys($required);$engines=DB::select('SELECT TABLE_NAME AS name,ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('.implode(',',array_fill(0,count($tables),'?')).')',array_merge([$db->getDatabaseName()],$tables));
    if(count($engines)!==count($tables))nf_fail('TABLE_ENGINES_MISSING');foreach($engines as $r)if(strtolower($r->engine)!=='innodb')nf_fail('TRANSACTIONAL_TABLES_REQUIRED');
    foreach([BranchInventory::class=>['snapshots','decorate'],FasakhanstaStoreFulfillment::class=>['branch','inventoryLines'],DesktopDashboardProductImages::class=>['facts']] as $class=>$methods)foreach($methods as $method)if(!method_exists($class,$method))nf_fail('RUNTIME_METHOD_MISSING_'.$method);
    $disk=config('filesystems.disks.products');if(($disk['driver']??'')!=='local'||realpath($disk['root']??'')!==realpath(storage_path('app/public/products'))||realpath(public_path('storage'))!==realpath(storage_path('app/public')))nf_fail('PRODUCT_IMAGE_STORAGE_REQUIRED');
}
function nf_main(array $argv): int {
    $locked=false;$stage='arguments';$lock='fasakhansta-menu-writes';
    try{
        umask(0077);ini_set('display_errors','0');ini_set('log_errors','0');
        if(PHP_SAPI!=='cli'||count($argv)!==2||!in_array($argv[1],['--check','--apply'],true))nf_fail('ARGUMENTS_INVALID');
        $stage='bootstrap';$root='/home/fasakha/public_html';chdir($root);require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $stage='preflight';nf_preflight();$sources=nf_sources();
        if($argv[1]==='--check'){$stage='check';$result=DB::transaction(fn()=>nf_summary(nf_read(),$sources),1);echo nf_json($result)."\n";return 0;}
        $stage='lock';$r=DB::selectOne('SELECT GET_LOCK(?,0) AS acquired',[$lock]);if(!$r||(int)$r->acquired!==1)nf_fail('ANOTHER_MENU_UPDATE_RUNNING');$locked=true;
        $stage='apply';echo nf_json(nf_apply($sources))."\n";return 0;
    }catch(Throwable $e){fwrite(STDERR,nf_json(['status'=>'failed','stage'=>$stage,'error'=>$e instanceof NetFilletMenuException?$e->getMessage():($e instanceof InvalidArgumentException?$e->getMessage():'OPERATION_FAILED')])."\n");return 1;}
    finally{if($locked)try{DB::selectOne('SELECT RELEASE_LOCK(?) AS released',[$lock]);}catch(Throwable $ignored){}}
}
if(!defined('NET_FILLET_MENU_LIBRARY'))exit(nf_main($argv));
