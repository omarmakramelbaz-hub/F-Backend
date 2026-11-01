<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
try{
    if(PHP_SAPI!=='cli'||$argc!==2||!in_array($argv[1],['--inspect','--install'],true))throw new RuntimeException('GO_OWNER_SETUP_ARGUMENTS');
    $root='/home/fasakha/public_html';chdir($root);require $root.'/vendor/autoload.php';
    $app=require $root.'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=$app->make('db')->connection();$c=$db->getConfig();
    if($db->getDriverName()!=='mysql'||!in_array($c['host'],['localhost','127.0.0.1','::1'],true))throw new RuntimeException('GO_OWNER_LOCAL_MYSQL_REQUIRED');
    if(!preg_match('/^[a-zA-Z0-9_]+$/D',$db->getDatabaseName()))throw new RuntimeException('GO_OWNER_DATABASE_NAME');
    $owner=$db->table('users')->where('id',1)->first(['id','account_type','status']);
    if(!$owner||$owner->account_type!=='admin'||$owner->status!=='accepted')throw new RuntimeException('GO_OWNER_PRIMARY_REQUIRED');
    $url=rtrim((string)config('app.url'),'/');
    if(!preg_match('#^https://[a-zA-Z0-9.-]+$#D',$url))throw new RuntimeException('GO_OWNER_HTTPS_URL_REQUIRED');
    if(config('cors.supports_credentials'))throw new RuntimeException('GO_OWNER_PRIMARY_CORS_REVIEW_REQUIRED');
    if($argv[1]==='--install')\App\Services\OwnerDashboards\ControlSchema::ensure();
    $defaults=[];
    foreach((new ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC)as$property){
        if($property->isStatic()||$property->getDeclaringClass()->getName()!==\App\Models\GeneralSettings::class)continue;
        $type=$property->getType();$defaults[$property->getName()]=$type instanceof ReflectionNamedType&&$type->getName()==='bool'?false:'';
    }
    foreach(['min_order_price','service_fees','km_price','vendor_tax','tax','app_balance','shipping_km_price','shipping_min_price','shipping_cancelled_block_no','min_wallet','default_0_1','default_1_2','default_2_3']as$name)$defaults[$name]='0';
    $defaults['site_active']=true;$defaults['wallet_card_activate']='false';$defaults['payment_card_activate']='false';
    echo json_encode(['database'=>$db->getDatabaseName(),'url'=>$url,'settings_defaults'=>$defaults],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,preg_match('/^GO_OWNER_[A-Z_]+$/D',$e->getMessage())?$e->getMessage().PHP_EOL:"GO_OWNER_CENTRAL_SETUP_FAILED\n");exit(1);}
