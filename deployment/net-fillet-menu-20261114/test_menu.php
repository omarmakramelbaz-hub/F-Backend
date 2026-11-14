<?php
declare(strict_types=1);
define('NET_FILLET_MENU_LIBRARY', true);
require_once __DIR__.'/add_menu.php';

$testChecks = 0;
function menu_check(bool $condition, string $message): void
{
    global $testChecks;
    if (!$condition) throw new RuntimeException($message);
    ++$testChecks;
}
function menu_refuses(callable $call, string $expected): void
{
    try { $call(); }
    catch (NetFilletMenuException $error) {
        menu_check($error->getMessage() === $expected,
            'Expected '.$expected.'; got '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected refusal: '.$expected);
}
function menu_input_plan(array $input): array
{
    return nf_plan($input['scope'], $input['categories'], $input['products'], $input['menus'],
        $input['features'], $input['recipes'], $input['ingredients']);
}
function menu_missing_counts(array $plan): array
{
    // These pure data counts intentionally avoid nf_summary's Laravel/media readers.
    $out = ['category'=>$plan['category'] ? 0 : 1, 'catalog'=>0, 'features'=>0, 'menu'=>0, 'recipes'=>0];
    foreach ($plan['catalog'] as $row) if ($row === null) ++$out['catalog'];
    foreach ($plan['features'] as $row) if ($row === null) ++$out['features'];
    foreach ($plan['items'] as $item) {
        if ($item['existing'] === null) ++$out['menu'];
        if ($item['recipe'] === null) ++$out['recipes'];
    }
    return $out;
}
function menu_fixture(array $scope, array $ingredients): array
{
    $category = ['id'=>700,'name_ar'=>NF_CATEGORY,'parent_id'=>null,'status'=>'show'];
    $input = ['scope'=>$scope,'categories'=>[$category],'products'=>[],'menus'=>[],
        'features'=>[],'recipes'=>[],'ingredients'=>$ingredients];
    $index = 0;
    foreach (nf_specs() as $family=>$spec) {
        $productId = 800 + ++$index;
        $input['products'][] = ['id'=>$productId,'name_ar'=>$spec['name'],'category_id'=>700,
            'subcategory_id'=>null,'status'=>'show','has_clean'=>0];
        $input['features'][] = ['id'=>900+$index,'product_id'=>$productId,'name'=>'kilo'];
        $ingredient = NetFilletRecipeContract::resolveIngredient($ingredients, $family);
        foreach ($scope as $branchId=>$branch) {
            $sku = 10000 + $branchId * 10 + $index;
            $input['menus'][] = ['id'=>$sku,'resturant_id'=>$branchId,'product_id'=>$productId,
                'category_id'=>700,'product_name'=>$spec['name'],'status'=>'show',
                'product_price'=>number_format(nf_price($branchId,$spec),2,'.',''),'price'=>nf_extras()];
            $input['recipes'][] = ['id'=>20000+$sku,'branch'=>'f:'.$branchId,'product_id'=>$sku,
                'unit'=>'kg','revision'=>1,'variants'=>NetFilletRecipeContract::variantsJson($ingredient)];
        }
    }
    return $input;
}
function menu_conflict(array $input, string $table, int $index, string $key, $value, string $error): void
{
    $input[$table][$index][$key] = $value;
    menu_refuses(fn()=>menu_input_plan($input), $error);
}

$locations = [
    ['id'=>94,'name'=>'المحلة','parent_id'=>null],
    ['id'=>301,'name'=>'شبرا','parent_id'=>null],
    ['id'=>307,'name'=>'المنصورة','parent_id'=>null],
    ['id'=>363,'name'=>'نبروه','parent_id'=>null],
    ['id'=>309,'name'=>'المقطم ستور','parent_id'=>null],
    ['id'=>310,'name'=>'زايد ستور','parent_id'=>99],
    ['id'=>311,'name'=>'ستور جديد','parent_id'=>301],
    ['id'=>312,'name'=>'  ستور   ٣  ','parent_id'=>0],
    ['id'=>313,'name'=>'اسم مختلف','parent_id'=>0],
    ['id'=>900,'name'=>'تاجر جملة مستقل','parent_id'=>null],
    ['id'=>901,'name'=>'متجر نشاط آخر','parent_id'=>900],
];
$registered = [309=>'المقطم ستور',310=>'زايد ستور',312=>'ستور 3',313=>'متجر مسجل'];
$scope = nf_scope($locations,$registered);
menu_check(array_keys($scope) === [94,301,307,309,311,312,363],
    'Scope contains all physical branches, a direct Shubra child and matching registered retail fronts only.');
menu_check(!isset($scope[310],$scope[313],$scope[900],$scope[901]),
    'Foreign parent, mismatched registry name and unrelated wholesale business are excluded.');
foreach (NF_PHYSICAL as $physical) {
    $without = array_values(array_filter($locations,fn($r)=>$r['id'] !== $physical));
    menu_refuses(fn()=>nf_scope($without,$registered),'PHYSICAL_BRANCH_MISSING_'.$physical);
}

$specs = nf_specs();
menu_check(array_keys($specs) === ['feseekh','herring','sardine'], 'Exactly the three approved families.');
foreach (NF_PHYSICAL as $id) {
    menu_check(array_map(fn($spec)=>nf_price($id,$spec),$specs)
        === ['feseekh'=>1000,'herring'=>600,'sardine'=>600], 'Physical branch per-kilogram prices.');
}
foreach ([309,311,312] as $id) {
    menu_check(array_map(fn($spec)=>nf_price($id,$spec),$specs)
        === ['feseekh'=>1400,'herring'=>800,'sardine'=>800], 'Store per-kilogram prices.');
}

$ingredients = [
    ['id'=>2,'name'=>'فسيخ كيلو ٣ سمكات','unit'=>'kg'],
    ['id'=>6,'name'=>'رنجة سمينة','unit'=>'kg'],
    ['id'=>8,'name'=>'سردين بلدي','unit'=>'kg'],
    ['id'=>9,'name'=>'رنجة بطارخ','unit'=>'kg'],
];
$empty = ['scope'=>$scope,'categories'=>[],'products'=>[],'menus'=>[],
    'features'=>[],'recipes'=>[],'ingredients'=>$ingredients];
$emptyBefore = nf_json($empty);
$initial = menu_input_plan($empty);
menu_check(menu_missing_counts($initial)
    === ['category'=>1,'catalog'=>3,'features'=>3,'menu'=>21,'recipes'=>21], 'Initial plan creates only missing menu data.');
menu_check(array_map(fn($row)=>$row['id'],$initial['ingredients'])
    === ['feseekh'=>2,'herring'=>6,'sardine'=>8], 'All three goods bind to their exact approved raw stock.');
menu_check(count($initial['items']) === 3 * count($scope), 'Every selected location receives exactly three items.');
menu_check(nf_json(menu_input_plan($empty)) === nf_json($initial), 'Repeated initial plans are deterministic.');
menu_check(nf_json($empty) === $emptyBefore, 'Planning leaves supplied data unchanged.');

$ready = menu_fixture($scope,$ingredients);
$readyBefore = nf_json($ready);
$plan = menu_input_plan($ready);
menu_check(menu_missing_counts($plan)
    === ['category'=>0,'catalog'=>0,'features'=>0,'menu'=>0,'recipes'=>0], 'Fully installed plan requires no category/catalog/feature/menu/recipe inserts.');
menu_check(nf_json(menu_input_plan($ready)) === nf_json($plan), 'Repeated ready plans are idempotent.');
menu_check(nf_json($ready) === $readyBefore, 'Ready planning performs no data mutation.');
foreach ($plan['items'] as $item) {
    $family = $item['family'];
    menu_check($item['existing']['product_id'] === $plan['catalog'][$family]['id'],
        'Every branch and store retains the same shared catalog link per family.');
    menu_check(json_decode($item['recipe']['variants'],true)
        === NetFilletRecipeContract::variants($plan['ingredients'][$family]), 'Every planned recipe consumes exactly 2 kg of its raw fish.');
}
$withUnrelated = $ready;
$withUnrelated['categories'][] = ['id'=>999,'name_ar'=>'أصناف أخرى','parent_id'=>null,'status'=>'show'];
$withUnrelated['products'][] = ['id'=>999,'name_ar'=>'منتج آخر','category_id'=>999,
    'subcategory_id'=>null,'status'=>'show','has_clean'=>0];
$withUnrelated['menus'][] = ['id'=>999,'resturant_id'=>301,'product_id'=>999,'product_name'=>'منتج آخر'];
menu_check(nf_json(menu_input_plan($withUnrelated)) === nf_json($plan), 'Unrelated existing menu data remains outside the change.');

$duplicate = $ready;
$duplicate['categories'][] = $duplicate['categories'][0];
menu_refuses(fn()=>menu_input_plan($duplicate),'DUPLICATE_CATEGORY');
menu_conflict($ready,'categories',0,'parent_id',5,'CATEGORY_CONFLICT');
menu_conflict($ready,'categories',0,'status','hide','CATEGORY_CONFLICT');
foreach (['category_id'=>999,'subcategory_id'=>999,'status'=>'hide','has_clean'=>1] as $key=>$value) {
    menu_conflict($ready,'products',0,$key,$value,'CATALOG_CONFLICT_feseekh');
}
$duplicate = $ready;
$duplicate['products'][] = $duplicate['products'][0];
menu_refuses(fn()=>menu_input_plan($duplicate),'DUPLICATE_CATALOG_feseekh');
$missingCategory = $ready;
$missingCategory['categories'] = [];
menu_refuses(fn()=>menu_input_plan($missingCategory),'CATALOG_CONFLICT_feseekh');

$baseMenu = $ready['menus'][0];
foreach (['category_id'=>999,'product_id'=>999,'status'=>'hide','product_price'=>'invalid'] as $key=>$value) {
    menu_conflict($ready,'menus',0,$key,$value,'MENU_CONFLICT_94_feseekh');
}
menu_conflict($ready,'menus',0,'product_price','1400.00','MENU_CONFLICT_94_feseekh');
$storeIndex = array_search(309,array_column($ready['menus'],'resturant_id'),true);
menu_check(is_int($storeIndex),'Store SKU exists in fixture.');
menu_conflict($ready,'menus',$storeIndex,'product_price','1000.00','MENU_CONFLICT_309_feseekh');
$extras = json_decode(nf_extras(),true);
$extras['extra_clear'] = '20';
menu_conflict($ready,'menus',0,'price',nf_json($extras),'SURCHARGE_CONFLICT_'.$baseMenu['id']);
menu_conflict($ready,'menus',0,'price','not json','SURCHARGE_CONFLICT_'.$baseMenu['id']);
$duplicate = $ready;
$duplicate['menus'][] = $baseMenu;
menu_refuses(fn()=>menu_input_plan($duplicate),'DUPLICATE_MENU_94_feseekh');
$missingCatalog = $ready;
$missingCatalog['products'] = array_slice($ready['products'],1);
menu_refuses(fn()=>menu_input_plan($missingCatalog),'MENU_CONFLICT_94_feseekh');

menu_conflict($ready,'features',0,'name','half','FEATURE_CONFLICT_feseekh');
$duplicate = $ready;
$duplicate['features'][] = $duplicate['features'][0];
menu_refuses(fn()=>menu_input_plan($duplicate),'FEATURE_CONFLICT_feseekh');
$noFeature = $ready;
$noFeature['features'] = array_slice($ready['features'],1);
menu_check(menu_missing_counts(menu_input_plan($noFeature))['features'] === 1,
    'A missing kilo feature is planned as an insertion.');

$shubraDuplicate = $ready;
$shubra = array_values(array_filter($ready['menus'],fn($m)=>$m['resturant_id']===301))[0];
$shubra['id'] = 99999;
$shubra['product_name'] = 'اسم آخر بنفس الكتالوج';
$shubraDuplicate['menus'][] = $shubra;
menu_refuses(fn()=>menu_input_plan($shubraDuplicate),'AMBIGUOUS_SHUBRA_CATALOG_feseekh');

$recipeError = 'RECIPE_CONFLICT_'.$baseMenu['id'];
menu_conflict($ready,'recipes',0,'unit','piece',$recipeError);
$wrong = NetFilletRecipeContract::variants($plan['ingredients']['feseekh']);
$wrong[0][0]['quantity_units'] = 1000000;
menu_conflict($ready,'recipes',0,'variants',nf_json((object)$wrong),$recipeError);
$wrong[0][0]['quantity_units'] = 2000000;
$wrong[0][0]['ingredient_id'] = 9;
menu_conflict($ready,'recipes',0,'variants',nf_json((object)$wrong),$recipeError);
$wrong = NetFilletRecipeContract::variants($plan['ingredients']['feseekh']);
$wrong[123] = $wrong[0];
menu_conflict($ready,'recipes',0,'variants',nf_json((object)$wrong),$recipeError);
$duplicate = $ready;
$duplicate['recipes'][] = $duplicate['recipes'][0];
menu_refuses(fn()=>menu_input_plan($duplicate),'DUPLICATE_RECIPE_'.$baseMenu['id']);
$missingRecipe = $ready;
$missingRecipe['recipes'] = array_slice($ready['recipes'],1);
menu_check(menu_missing_counts(menu_input_plan($missingRecipe))['recipes'] === 1,
    'A missing recipe is planned as an insertion.');

echo 'NET_FILLET_MENU_PLAN_OK checks='.$testChecks.PHP_EOL;
