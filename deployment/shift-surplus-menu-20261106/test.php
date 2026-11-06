<?php
declare(strict_types=1);
define('SURPLUS_MENU_LIBRARY',true);
require __DIR__.'/add_menu.php';
function sm_expect(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function sm_reject(callable $work,string $message): void {
    try { $work(); } catch (SurplusMenuException $e) { return; } throw new RuntimeException($message);
}
$physical=[94,301,307,363];
$rows=[]; foreach ($physical as $id) $rows[]=['id'=>$id,'parent_id'=>null,'name'=>'Branch '.$id];
$rows[]=['id'=>309,'parent_id'=>null,'name'=>'المقطم ستور'];
$rows[]=['id'=>400,'parent_id'=>301,'name'=>'New store'];
$rows[]=['id'=>401,'parent_id'=>400,'name'=>'New child store'];
$rows[]=['id'=>777,'parent_id'=>null,'name'=>'Unrelated restaurant'];
$rows[]=['id'=>888,'parent_id'=>777,'name'=>'Unrelated child'];
$branches=sm_scope($rows,$physical,[309=>'المقطم ستور']);
sm_expect(array_keys($branches)===[94,301,307,309,363,400,401],'Include all registered and new descendant stores, exclude unrelated restaurants');
sm_reject(fn()=>sm_scope(array_slice($rows,1),$physical,[]),'Missing physical branch must block');
$cats=[['id'=>5,'name_ar'=>'المشروبات','parent_id'=>null,'status'=>'show']];
$plan=sm_plan($branches,$cats,[],[]);
sm_expect(count($plan['items'])===28 && array_keys($plan['catalog'])===SM_VALUES,'Four values per scoped branch and store');
sm_expect(array_values(array_unique(array_column($plan['items'],'value')))===[1,5,10,100],'Include the added 100 EGP value');
sm_reject(fn()=>sm_plan($branches,[],[],[]),'Absent category must block');
sm_reject(fn()=>sm_plan($branches,[$cats[0],$cats[0]],[],[]),'Duplicate category must block');
sm_reject(fn()=>sm_plan($branches,[array_replace($cats[0],['status'=>'hide'])],[],[]),'Hidden category must block');
$products=[]; $menus=[]; $id=0;
foreach (SM_VALUES as $v) {
    $products[]=['id'=>$v,'name_ar'=>sm_name($v),'category_id'=>5,'status'=>'show','subcategory_id'=>null];
    foreach ($branches as $bid=>$_) $menus[]=['id'=>++$id,'resturant_id'=>$bid,'product_id'=>$v,
        'product_name'=>sm_name($v),'product_price'=>(string)$v.'.00','category_id'=>5,'status'=>'show'];
}
$full=sm_plan($branches,$cats,$products,$menus);
sm_expect(count(array_filter($full['catalog']))===4 && count(array_filter(array_column($full['items'],'existing')))===28,'Rerun must reuse every matching item');
$arabic=$products; $arabic[0]['name_ar']='زيادة  وردية ١ جنيه';
sm_expect(sm_plan($branches,$cats,$arabic,$menus)['catalog'][1]['id']===1,'Normalize Arabic digits and whitespace for matching only');
sm_reject(fn()=>sm_plan($branches,$cats,array_merge($products,[$products[0]]),$menus),'Duplicate global item must block');
sm_reject(fn()=>sm_plan($branches,$cats,$products,array_merge($menus,[$menus[0]])),'Duplicate branch item must block');
$bad=$menus; $bad[0]['product_price']='2.00';
sm_reject(fn()=>sm_plan($branches,$cats,$products,$bad),'Never overwrite another existing price');
$bad=$menus; $bad[0]['product_id']=100;
sm_reject(fn()=>sm_plan($branches,$cats,$products,$bad),'Never relink a conflicting existing product');
$bad=$menus; $bad[0]['category_id']=9;
sm_reject(fn()=>sm_plan($branches,$cats,$products,$bad),'Never move a conflicting existing item');
$assets=sm_assets(); sm_expect(count($assets)===4,'All four correct JPEG files and hashes must validate');
echo "SHIFT_SURPLUS_MENU_TESTS_OK\n";
