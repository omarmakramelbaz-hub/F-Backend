<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$phase='BOOTSTRAP';
try{
    if(PHP_SAPI!=='cli'||$argc!==3||!in_array($argv[2],['initialize','health','queue','schedule','isolation','crud'],true))throw new RuntimeException('GO_OWNER_CONSOLE_ARGUMENTS');
    require '/home/fasakha/public_html/vendor/autoload.php';
    $r=\App\Services\OwnerDashboards\Bootstrap::read($argv[1],$argv[2]==='initialize'||$argv[2]==='health');
    $app=\App\Services\OwnerDashboards\Bootstrap::application($r);
    $kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
    $db=$app->make('db')->connection();
    if($db->getDatabaseName()!==$r['database']||$db->getConfig('username')!==$r['db_user'])throw new RuntimeException('GO_OWNER_DATABASE_BINDING_MISMATCH');
    if($argv[2]==='initialize'){
        $phase='CONFIGURATION';
        // Cache only this clean environment. Never copy the primary application's cached configuration.
        $configuration=$app['config']->all();
        file_put_contents($r['base'].'/bootstrap/cache/config.php','<?php return '.var_export($configuration,true).';'.PHP_EOL,LOCK_EX);
        echo "GO_OWNER_TENANT_INITIALIZED\n";
    }elseif($argv[2]==='health'){
        $phase='EMPTY_HOME';
        $user=$db->table('users')->where('id',1)->first();
        if(!$user||$user->account_type!=='admin'||$user->status!=='accepted'||(int)$db->table('users')->count()!==1)throw new RuntimeException('GO_OWNER_EMPTY_OWNER_REQUIRED');
        foreach(['resturants','orders','pending_vendors','takeaway_orders','branch_expenses','employees','wallets','go_stores']as$table){
            if($app['db']->connection()->getSchemaBuilder()->hasTable($table)&&$db->table($table)->exists())throw new RuntimeException('GO_OWNER_BUSINESS_DATA_NOT_EMPTY');
        }
        if($app['db']->connection()->getSchemaBuilder()->hasTable('owner_dashboards'))throw new RuntimeException('GO_OWNER_CONTROL_PLANE_COPIED');
        if(!\Illuminate\Support\Facades\Hash::check('OwnerHealthFixtureOnly', $user->password)&&($r['test_fixture']??false))throw new RuntimeException('GO_OWNER_FIXTURE_PASSWORD');
        $actor=\App\Models\User::withoutGlobalScopes()->findOrFail(1);
        if(!$actor->can('admin-create')||!$actor->can('resturant-create')||\App\Services\OwnerDashboards\Runtime::primary($actor))throw new RuntimeException('GO_OWNER_PERMISSION_BOUNDARY');
        $data=$app->make(\App\Services\Dashboard\HomeOverview::class)->data([],$actor);
        $request=\Illuminate\Http\Request::create(config('app.url').'/admin/dashboard','GET',[],[],[],['SCRIPT_NAME'=>$r['prefix'].'/index.php','SCRIPT_FILENAME'=>$r['public'].'/index.php']);
        $route=$app['router']->getRoutes()->getByName('admin_dash');
        $request->setRouteResolver(static fn()=>$route);
        $app->instance('request',$request);$app['url']->setRequest($request);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
        $session=$app['session.store'];$session->start();$request->setLaravelSession($session);$session->put('id_user',1);
        auth('admin')->setUser($actor);
        $phase='HOME_RENDER';
        $html=$app->make(\App\Http\Controllers\Dashboard\HomeController::class)->index($request,$app->make(\App\Services\Dashboard\HomeOverview::class))->getContent();
        if(!str_contains($html,$r['prefix'].'/admin/')||str_contains($html,'href="'.config('app.url').'/admin/owners"'))throw new RuntimeException('GO_OWNER_HOME_BOUNDARY');
        $app['view']->make('admin.login',['settings'=>$app->make(\App\Models\GeneralSettings::class)])->render();
        // The real home service must boot on the empty schema, not a special placeholder home screen.
        echo json_encode(['status'=>'GO_OWNER_TENANT_HEALTHY','branch_count'=>count($app->make(\App\Services\Dashboard\TakeawayAccess::class)->branches($actor)),'business_data_empty'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
    }elseif($argv[2]==='isolation'){
        if(!($r['test_fixture']??false)||!is_array($r['probe_databases']??null))throw new RuntimeException('GO_OWNER_FIXTURE_REQUIRED');
        foreach($r['probe_databases']as$other){
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',$other)||$other===$r['database'])throw new RuntimeException('GO_OWNER_ISOLATION_ARGUMENT');
            foreach(['SELECT COUNT(*) FROM `'.$other.'`.`users`','UPDATE `'.$other.'`.`users` SET name=name WHERE id=1']as$sql){
                $denied=false;
                try{$db->getPdo()->query($sql);}catch(PDOException$e){$denied=in_array($e->errorInfo[1]??0,[1044,1142],true);}
                if(!$denied)throw new RuntimeException('GO_OWNER_CROSS_DATABASE_ACCESS');
            }
        }
        echo "GO_OWNER_DATABASE_ISOLATION_VERIFIED\n";
    }elseif($argv[2]==='crud'){
        $phase='LOCAL_ACCOUNT';
        if(!($r['test_fixture']??false))throw new RuntimeException('GO_OWNER_FIXTURE_REQUIRED');
        $session=$app['session.store'];$session->start();$session->put('id_user',1);
        $actor=\App\Models\User::withoutGlobalScopes()->findOrFail(1);auth('admin')->setUser($actor);
        $create=function(string$type,string$mobile,array$extra=[])use($app,$session){
            $input=['name'=>'Fixture '.$type,'email'=>$type.'@example.invalid','mobile'=>$mobile,'account_type'=>$type,
                'added_by'=>1,'password'=>'TenantFixtureOnly123','roles_name'=>[$type],'country_code'=>'20']+$extra;
            $request=\Illuminate\Http\Request::create(config('app.url').'/admin/users','POST',$input);
            $request->setLaravelSession($session);$app->instance('request',$request);
            $user=$app->make(\App\Repositories\UserRepository::class)->createUser($input);
            if(!is_object($user)||!$user->id)throw new RuntimeException('GO_OWNER_LOCAL_ACCOUNT_CREATE_FAILED');
            return$user;
        };
        $manager=$create('admin','1012345678');$vendor=$create('vendor','1112345678');
        $phase='LOCAL_BRANCH';
        $input=['added_by'=>1,'user_id'=>$vendor->id,'name'=>'Fixture private branch','status'=>'opened','control'=>'show',
            'resturant_phone'=>'01012345678','min_order_price'=>0,'km_price'=>0,'delivery_time'=>'30','open_at'=>'00:00','close_at'=>'23:59'];
        $request=\Illuminate\Http\Request::create(config('app.url').'/admin/resturants','POST',$input);$request->setLaravelSession($session);$app->instance('request',$request);
        $branch=$app->make(\App\Repositories\ResturantRepository::class)->createResturant($input);
        if(!$branch||!$branch->id)throw new RuntimeException('GO_OWNER_LOCAL_BRANCH_CREATE_FAILED');
        $restaurantOwner=$create('resturant_owner','1212345678',['owner_resturant_id'=>$branch->id]);
        $request=\Illuminate\Http\Request::create(config('app.url').'/admin/users?account_type=admin');$app->instance('request',$request);
        $app['auth']->forgetGuards();
        if(!auth('admin')->attempt(['id'=>$manager->id,'password'=>'TenantFixtureOnly123']))throw new RuntimeException('GO_OWNER_STAFF_LOGIN_FAILED');
        try{$app->make(\App\Services\OwnerDashboards\Registry::class)->listing(auth('admin')->user());throw new RuntimeException('GO_OWNER_STAFF_CONTROL_ACCESS');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException$e){if($e->getStatusCode()!==403)throw$e;}
        echo "GO_OWNER_LOCAL_ACCOUNTS_AND_BRANCH_VERIFIED\n";
    }else{
        $command=$argv[2]==='queue'?'queue:work --stop-when-empty --max-time=15 --tries=2':'schedule:run';
        $kernel->call($command,[],new \Symfony\Component\Console\Output\NullOutput());
    }
}catch(Throwable $e){fwrite(STDERR,preg_match('/^GO_OWNER_[A-Z_]+$/D',$e->getMessage())?$e->getMessage().PHP_EOL:'GO_OWNER_TENANT_'.$phase.'_FAILED'.PHP_EOL);exit(1);}
