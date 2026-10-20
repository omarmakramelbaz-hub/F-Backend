<?php
// Independent PHP/database connection for the withdrawal concurrency fixture.
[$script,$application,$port,$database,$profile,$gate,$command]=array_pad($argv,7,'');
if(!is_file($application.'/desktop/bootstrap.php')||!preg_match('/^desktop_wallet_cash_[a-f0-9]+$/D',$database)
    ||!is_dir($profile)||!preg_match('/^[0-9]+$/D',$port))throw new RuntimeException('Invalid private wallet worker arguments');
foreach(['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_STORAGE'=>$profile,'APP_ENV'=>'desktop','APP_DEBUG'=>'false','APP_URL'=>'http://127.0.0.1:43144',
    'APP_KEY'=>getenv('WALLET_FIXTURE_KEY'),'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>$port,'DB_DATABASE'=>$database,'DB_USERNAME'=>'root','DB_PASSWORD'=>'',
    'CACHE_DRIVER'=>'file','SESSION_DRIVER'=>'file','APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php',
    'APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $name=>$value)putenv($name.'='.$value);
$app=require $application.'/desktop/bootstrap.php';
file_put_contents($gate.'.'.$command.'.ready','ready');
for($n=0;$n<500&&!is_file($gate);$n++)usleep(10000);
if(!is_file($gate))throw new RuntimeException('Private withdrawal fixture gate did not open');
try{
    $actor=\App\Models\User::withoutGlobalScopes()->findOrFail(1);
    $result=app(\App\Services\Dashboard\WalletCashOperations::class)->apply('wallets.withdraw.store',
        ['from_user'=>20,'amount'=>'60.00','idempotency_key'=>$command],$actor);
    echo json_encode(['status'=>200,'balance_after'=>$result['balance_after']]);
}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){echo json_encode(['status'=>$error->getStatusCode()]);}
