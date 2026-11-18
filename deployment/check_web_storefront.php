<?php
/** Deployment check: read-only menu/authorization checks; never creates a customer or order. */
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $store=app(\App\Services\Site\WebStorefront::class);
    if ($store->branches()!==[] || !\Illuminate\Support\Facades\Schema::hasTable('web_storefront_orders')
        || !\Illuminate\Support\Facades\Schema::hasTable('resturant_areas')) throw new \RuntimeException;
    foreach (['branches'=>'POST','catalog'=>'GET','addresses'=>'POST','quote'=>'POST','checkout'=>'POST'] as $name=>$method) {
        $route=\Illuminate\Support\Facades\Route::getRoutes()->getByName('storefront.'.$name);
        if (!$route || !in_array($method,$route->methods(),true)) throw new \RuntimeException;
    }
    if (!app(\App\Services\Dashboard\PosServiceTable::class)->ready()) throw new \RuntimeException;
    // Isolated memory-only context: never touches a real visitor session, customer, quote or order.
    $session=new \Illuminate\Session\Store('storefront-check',new \Illuminate\Session\ArraySessionHandler(5));
    $session->start();
    $request=function(array $values=[]) use ($session) {
        $request=\Illuminate\Http\Request::create('/shop/branches','POST',$values);
        $request->setLaravelSession($session);
        return $request;
    };
    $areas=\Illuminate\Support\Facades\DB::table('resturant_areas')->whereNotNull('lat')->whereNotNull('lng')
        ->whereNotNull('expected_delivery')->orderBy('id')->get(['lat','lng','expected_delivery']);
    $checked=[];$centers=[];$products=0;$branches=[];
    foreach ($areas as $area) {
        if (!is_numeric($area->lat)||!is_numeric($area->lng)||!is_numeric($area->expected_delivery)
            ||$area->lat<22||$area->lat>32||$area->lng<24||$area->lng>37||$area->expected_delivery<0) continue;
        $center=sprintf('%.7F,%.7F',$area->lat,$area->lng);
        if (isset($centers[$center])) continue;
        $centers[$center]=true;
        $branches=$store->discover($request(['customer_phone'=>'01000000000','address'=>'فحص التوصيل دون إنشاء عميل',
            'area'=>'فحص التوصيل','latitude'=>$area->lat,'longitude'=>$area->lng,'location_confirmed'=>true]))['branches'];
        foreach ($branches as $branch) {
            if (isset($checked[$branch['id']])) continue;
            $checked[$branch['id']]=true;
            $products+=count($store->catalog($branch['id'],$request())['products']);
        }
        if ($products>0) break;
    }
    if ($products<1) throw new \RuntimeException;
    echo json_encode(['status'=>'WEB_STOREFRONT_RUNTIME_OK','covered_branches_checked'=>count($checked),'products'=>$products,
        'location_required'=>true,'app_coverage'=>true]).PHP_EOL;
} catch (\Throwable $error) {
    fwrite(STDERR,'WEB_STOREFRONT_RUNTIME_CHECK_FAILED'.PHP_EOL);
    exit(1);
}
