<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardReferences};

// Real original drag endpoint, local journal and mapped server controller replay.
config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
$orderCreates=[];$orderIds=[];
$orderState=function()use(&$orderIds){return DB::table('categories')->whereIn('id',$orderIds)->orderBy('id')->pluck('order','id')->all();};
$orderEnvelope=function($id)use($ownerStage){
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
    $row=DB::table('desktop_dashboard_commands')->where('command_id',$id)->first();$saved=json_decode(Crypt::decryptString($row->local_result_cipher),true);
    return ['command_id'=>$id,'actor_id'=>1,'route_name'=>$row->route_name,'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true),
        'local_result'=>$saved['result'],'local_references'=>$saved['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String()];
};
try{
    for($n=0;$n<100;$n++){if($http('/_desktop/health')[0]===200)break;usleep(50000);}
    [$status,$page]=$http('/admin/categorys');preg_match('/name="csrf-token" content="([^"]+)"/',$page,$orderCsrf);
    verify($status===200&&isset($orderCsrf[1])&&str_contains($page,'data-desktop-category-generation="'.$ownerSnapshot['snapshot_id'].'"'),
        'the original category drag table carries its exact imported generation without changing its layout');
    foreach([1,2] as $index){
        $create=['_token'=>$orderCsrf[1],'_desktop_command'=>(string)Str::uuid(),'added_by'=>1,'name_ar'=>'قسم ترتيب محلي '.$index,'name_en'=>'Order fixture '.$index,'status'=>'show'];
        verify($http('/admin/categorys',$create)[0]===302,'an original locally created category is available for later ordering');
        $orderCreates[]=$create['_desktop_command'];$orderIds[]=(int)DB::table('categories')->where('name_ar',$create['name_ar'])->value('id');
    }
    $before=$orderState();$commandCount=DB::table('desktop_dashboard_commands')->count();
    $orderForm=['_token'=>$orderCsrf[1],'_desktop_command'=>(string)Str::uuid(),'order'=>[['id'=>$orderIds[1],'position'=>1],['id'=>$orderIds[0],'position'=>2]]];
    $invalidOrders=[[],[['id'=>$orderIds[0],'position'=>1],['id'=>$orderIds[0],'position'=>2]],
        [['id'=>$orderIds[0],'position'=>1],['id'=>$orderIds[1],'position'=>1]],[['id'=>$orderIds[0],'position'=>'1.5']],
        [['id'=>$orderIds[0],'position'=>0]],[['id'=>$orderIds[0],'position'=>1,'other'=>'field']],
        ];
    foreach($invalidOrders as $bad){
        $invalid=$orderForm;$invalid['order']=$bad;
        verify($http('/admin/post-sortable',$invalid,['Accept: application/json'])[0]===422&&$orderState()===$before&&DB::table('desktop_dashboard_commands')->count()===$commandCount,
            'invalid or missing drag selections change neither categories nor the journal');
    }
    $missing=$orderForm;$missing['order'][]=['id'=>999999999,'position'=>3];
    verify($http('/admin/post-sortable',$missing,['Accept: application/json'])[0]===404&&$orderState()===$before&&DB::table('desktop_dashboard_commands')->count()===$commandCount,
        'a missing category refuses the entire local selection before changing another row');
    DB::statement("CREATE TRIGGER reject_category_order_journal BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW BEGIN IF NEW.route_name='categorys.reorder' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated category order journal failure'; END IF; END");
    try{verify($http('/admin/post-sortable',$orderForm,['Accept: application/json'])[0]===500&&$orderState()===$before&&DB::table('desktop_dashboard_commands')->count()===$commandCount,
        'failure to persist the order journal rolls back every original category position');}
    finally{DB::statement('DROP TRIGGER reject_category_order_journal');}
    $client=null;
    DB::beginTransaction();DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->lockForUpdate()->first();
    try{
        $headers=['X-Fasakhansta-Desktop: '.$browserToken,'Accept: application/json','Content-Type: application/x-www-form-urlencoded',
            'Cookie: '.implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($cookies),$cookies))];
        $code= <<<'PHP'
$context=stream_context_create(['http'=>['method'=>'POST','header'=>json_decode($argv[2],true),'content'=>$argv[3],'ignore_errors'=>true,'timeout'=>15,'follow_location'=>0]]);
$body=file_get_contents($argv[1],false,$context);preg_match('/^HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$status);echo json_encode([(int)($status[1]??0),$body]);
PHP;
        $client=proc_open([PHP_BINARY,'-r',$code,$origin.'/admin/post-sortable',json_encode($headers),http_build_query($orderForm)],
            [['pipe','r'],['file',$profile.'/order-concurrent.json','w'],['file',$profile.'/order-concurrent-error.log','w']],$clientPipes,$application,$env);
        $waiting=false;
        for($n=0;$n<200;$n++){
            foreach($pdo->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $process)
                if(str_contains($process['Info']??'','desktop_dashboard_local_state')&&stripos($process['Info']??'','for update')!==false&&$process['Command']!=='Sleep')$waiting=true;
            if($waiting)break;usleep(10000);
        }
        verify($waiting&&proc_get_status($client)['running'],'an actual concurrent drag waits for the local write fence before collecting selected category facts');
        DB::table('categories')->where('id',$orderIds[0])->update(['order'=>4]);DB::table('categories')->where('id',$orderIds[1])->update(['order'=>5]);DB::commit();
        fclose($clientPipes[0]);$clientExit=proc_close($client);$client=null;
        [$status,$body]=json_decode(file_get_contents($profile.'/order-concurrent.json'),true,512,JSON_THROW_ON_ERROR);
        verify($clientExit===0&&$status===200,'the concurrent original drag commits after its preceding local transaction finishes');
    }finally{
        if(DB::transactionLevel())DB::rollBack();
        if(is_resource($client)){fclose($clientPipes[0]);proc_terminate($client);proc_close($client);}
    }
    verify($status===200&&(json_decode($body,true)['status']??null)==='success'&&$orderState()===[$orderIds[0]=>2,$orderIds[1]=>1],
        'the original drag endpoint returns the JSON expected by its page and journals every position atomically');
    $orderForm['order']=array_reverse($orderForm['order']);
    verify($http('/admin/post-sortable',$orderForm,['Accept: application/json'])[1]===$body&&DB::table('desktop_dashboard_commands')->count()===$commandCount+1,
        'a lost local order response reuses the canonical UUID even with reordered transport entries');
    $changed=$orderForm;$changed['order'][0]['position']=3;
    verify($http('/admin/post-sortable',$changed,['Accept: application/json'])[0]===409&&$orderState()===[$orderIds[0]=>2,$orderIds[1]=>1],
        'one order UUID cannot change its already committed desired positions');
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}

// Independent creations may arrive in another order, yielding non-monotonic mapped IDs.
foreach(array_reverse($orderCreates) as $id){
    $command=$orderEnvelope($id);
    config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
    $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$id,$receipt);
}
$command=$orderEnvelope($orderForm['_desktop_command']);
verify(count($command['dependencies'])===2,'category ordering depends on both locally created category commands');
verify(array_column(array_column($command['payload']['facts']['catalog_rows'],'state'),'row')[0]['order']===4,
    'the durable order command captures the preceding committed local position rather than a stale pre-fence state');
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
$resolved=app(DesktopDashboardReferences::class)->resolve($remoteDevice->id,$command['payload']);$mappedIds=array_column($resolved['values']['order'],'id');
verify($mappedIds[0]>$mappedIds[1]&&$mappedIds!==$orderIds,'category-order references retain each intended position across non-monotonic server IDs');
DB::table('categories')->where('id',$mappedIds[0])->update(['order'=>4]);DB::table('categories')->where('id',$mappedIds[1])->update(['order'=>5]);
$original=DB::table('categories')->where('id',$mappedIds[0])->value('order');
DB::table('categories')->where('id',$mappedIds[0])->update(['order'=>99]);
try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed category order was overwritten.');}
catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&DB::table('categories')->where('id',$mappedIds[0])->value('order')===99&&DB::table('categories')->where('id',$mappedIds[1])->value('order')===5,
    'one changed server category rejects the entire drag order and retains every other position');}
DB::table('categories')->where('id',$mappedIds[0])->update(['order'=>$original]);
$currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('category-edit');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Revoked category ordering was accepted.');}
catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403&&(int)DB::table('categories')->whereIn('id',$mappedIds)->sum('order')===9,
    'current original category-edit authority is required before ordering any server row');}
$currentRole->givePermissionTo('category-edit');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
$receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command)&&DB::table('categories')->where('id',$mappedIds[0])->value('order')===2
    &&DB::table('categories')->where('id',$mappedIds[1])->value('order')===1,'the original controller reconciles every mapped category position exactly once');
verify(auth('admin')->getUser()===null,'category-order replay restores the surrounding API principal');
config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$command['command_id'],$receipt);
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
