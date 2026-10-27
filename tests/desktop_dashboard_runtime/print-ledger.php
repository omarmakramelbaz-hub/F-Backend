<?php
// Explicit inactive-component test. Only a disposable loopback MariaDB is used.
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardPrintLedger,DesktopDashboardPrintLedgerSchema,DesktopDashboardJournal,TakeawayService,PosServiceTicket,PhoneDelivery,BranchShiftClosing};

$application=realpath($argv[1]??'');$port=(int)($argv[2]??0);
if(!$application||$port<1024||$port>65535)throw new RuntimeException('Expected generated application and private MariaDB port.');
$database='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));$other='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='fasakhansta_dashboard_effects'")->fetchColumn())throw new RuntimeException('Requires a brand-new disposable MariaDB instance.');
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$workers=[];$fixtureConnection=null;$beforeDatabaseDrop=static fn()=>true;
register_shutdown_function(function()use($pdo,$database,$other,&$beforeDatabaseDrop){
    // Release application/worker transactions before DROP can wait on their metadata locks.
    if(!$beforeDatabaseDrop()){fwrite(STDERR,'Private worker cleanup failed before database removal.'.PHP_EOL);exit(1);}
    foreach([$database,$other,'fasakhansta_dashboard_effects'] as $name)$pdo->exec('DROP DATABASE IF EXISTS `'.$name.'`');
});
$profile=sys_get_temp_dir().'/print-ledger-'.bin2hex(random_bytes(8));
foreach(['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'] as $dir)mkdir($profile.'/'.$dir,0700,true);
$loader=require $application.'/vendor/autoload.php';$loader->setPsr4('App\\',$application.'/app');
$device=(string)Str::uuid();
foreach(['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_DEVICE_ID'=>$device,'DESKTOP_DASHBOARD_STORAGE'=>$profile,'APP_ENV'=>'desktop','APP_DEBUG'=>'false','APP_URL'=>'http://127.0.0.1:33408',
    'APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$database,'DB_USERNAME'=>'root','DB_PASSWORD'=>'',
    'CACHE_DRIVER'=>'file','SESSION_DRIVER'=>'file','APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php',
    'APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
$app=require $application.'/desktop/bootstrap.php';
$fixtureConnection=DB::connection();$fixturePdo=$fixtureConnection->getPdo();
$beforeDatabaseDrop=function()use(&$workers,$fixtureConnection,$fixturePdo): bool {
    $clean=true;
    // Also release raw PDO transactions, which Laravel's transaction counter cannot see.
    foreach(array_merge([$fixtureConnection],array_values(DB::getConnections())) as $connection){
        try{$physical=$connection->getRawPdo();if(!$physical instanceof PDO)continue;if($connection->transactionLevel()>0)$connection->rollBack(0);if($physical->inTransaction())$physical->rollBack();}
        catch(Throwable $error){$clean=false;}
    }
    try{if($fixturePdo->inTransaction())$fixturePdo->rollBack();}catch(Throwable $error){$clean=false;}
    foreach($workers as $job){
        if(!is_resource($job->process))continue;
        $status=proc_get_status($job->process);
        if($status['running']){proc_terminate($job->process);$deadline=microtime(true)+2;do{usleep(20000);$status=proc_get_status($job->process);}while($status['running']&&microtime(true)<$deadline);}
        if($status['running']){proc_terminate($job->process,9);$deadline=microtime(true)+2;do{usleep(20000);$status=proc_get_status($job->process);}while($status['running']&&microtime(true)<$deadline);}
        if($status['running']){$clean=false;continue;}
        proc_close($job->process);$job->process=null;
    }
    return $clean;
};
set_exception_handler(function(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);});
$count=0;
function check($value,string $label): void {global $count;if(!$value)throw new RuntimeException($label);$count++;echo 'PASS '.$label.PHP_EOL;}
function denied(callable $fn,int $expected,string $label): void {try{$fn();}catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error){check($error->getStatusCode()===$expected,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->string('status')->default('accepted');$t->unsignedBigInteger('owner_resturant_id')->nullable();$t->string('password')->nullable();});
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');$t->string('phone')->default('01000000000');$t->string('address')->default('المنصورة');$t->decimal('lat',10,7)->default(30);$t->decimal('lng',10,7)->default(31);$t->decimal('km_price',14,2)->default(0);});
Schema::create('categories',function(Blueprint $t){$t->id();$t->string('name_ar');$t->string('name_en');});
Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->unsignedBigInteger('product_id');$t->unsignedBigInteger('category_id');$t->string('product_name');$t->decimal('product_price',14,2);$t->string('status');$t->text('price');});
Schema::create('orders',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('type');$t->string('status');$t->string('payment_type');$t->string('transfer_price_by')->nullable();$t->timestamps();});
foreach([
    '2026_10_03_140000_create_takeaway_pos.php'=>'CreateTakeawayPos','2026_10_03_150000_create_pos_service_tickets.php'=>'CreatePosServiceTickets',
    '2026_10_04_000001_create_pos_branch_print_jobs.php'=>'CreatePosBranchPrintJobs','2026_10_04_030000_create_branch_expenses.php'=>'CreateBranchExpenses',
    '2026_10_06_130000_create_phone_delivery_batches.php'=>'CreatePhoneDeliveryBatches','2026_10_04_060000_lock_pos_service_bills.php'=>'LockPosServiceBills','2026_10_04_080000_create_branch_operations.php'=>'CreateBranchOperations',
    '2026_10_04_100000_create_branch_shift_closings.php'=>'CreateBranchShiftClosings','2026_10_04_190000_create_branch_stock.php'=>'CreateBranchStock',
    '2026_10_04_210000_create_branch_inventory_recipes.php'=>'CreateBranchInventoryRecipes','2026_10_06_120000_create_branch_expenses_categories.php'=>'CreateBranchExpensesCategories',
    '2026_10_06_200000_manage_expense_categories.php'=>'ManageExpenseCategories','2026_10_06_140000_add_employee_wallet_phone.php'=>'AddEmployeeWalletPhone',
    '2026_10_08_190000_add_employee_attendance_rules.php'=>'AddEmployeeAttendanceRules','2026_10_17_000001_create_crm_customer_directory.php'=>'CreateCrmCustomerDirectory',
    '2026_10_18_160000_add_employee_part_time_schedule.php'=>'AddEmployeePartTimeSchedule','2026_10_18_180000_add_employee_pay_hours.php'=>'AddEmployeePayHours',
    '2026_10_18_190000_create_branch_basic_recipes.php'=>'CreateBranchBasicRecipes','2026_10_18_200000_scope_expense_categories_by_branch.php'=>'ScopeExpenseCategoriesByBranch',
    '2026_10_18_230000_create_branch_stock_minimum_alerts.php'=>'CreateBranchStockMinimumAlerts','2026_10_18_210000_create_shift_report_printing_and_numbers.php'=>'CreateShiftReportPrintingAndNumbers',
    '2026_10_08_130000_create_desktop_dashboard_journal.php'=>'CreateDesktopDashboardJournal','2026_10_08_160000_create_desktop_dashboard_local_state.php'=>'CreateDesktopDashboardLocalState',
] as $file=>$class){require $application.'/database/migrations/'.$file;(new $class)->up();}
DB::table('users')->insert([['id'=>1,'name'=>'الأونر','account_type'=>'admin'],['id'=>10,'name'=>'فرع واحد','account_type'=>'vendor'],['id'=>11,'name'=>'فرع آخر','account_type'=>'vendor']]);
DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'الفرع الأول'],['id'=>101,'user_id'=>11,'name'=>'الفرع الثاني']]);
DB::table('categories')->insert(['id'=>1,'name_ar'=>'رنجة','name_en'=>'Herring']);
DB::table('resturant_products')->insert(['id'=>1,'resturant_id'=>100,'product_id'=>1,'category_id'=>1,'product_name'=>'رنجة','product_price'=>'100.00','status'=>'show','price'=>'{}']);
DB::table('takeaway_tills')->insert(['branch'=>'f:100','tax_bps'=>0,'balance_cents'=>0,'revision'=>1]);
DB::table('desktop_dashboard_local_state')->insert(['device_id'=>$device,'actor_id'=>1,'snapshot_id'=>(string)Str::uuid(),'schema_hash'=>str_repeat('a',64),'branches'=>'["f:100"]','coverage'=>'{}','state'=>'ready','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$schema=app(DesktopDashboardPrintLedgerSchema::class);$ledger=app(DesktopDashboardPrintLedger::class);$journal=app(DesktopDashboardJournal::class);
DB::beginTransaction();denied(fn()=>$schema->install(),409,'provisioning refuses an ambient transaction without committing it');check(DB::transactionLevel()===1,'DDL guard leaves the caller transaction open');DB::rollBack();
$raw=DB::connection()->getPdo();$raw->beginTransaction();denied(fn()=>$schema->install(),409,'provisioning also refuses a raw PDO transaction invisible to Laravel');check($raw->inTransaction(),'raw transaction survives the DDL rejection');$raw->rollBack();
$schema->install();$schema->install();check(true,'fixed private InnoDB schema provisions idempotently outside business transactions');
denied(fn()=>$ledger->bind($device),401,'missing enrollment evidence fails closed instead of using snapshot identity');
DB::table('desktop_dashboard_devices')->insert(['id'=>$device,'actor_id'=>1,'name'=>'private ledger fixture','enrollment_hash'=>str_repeat('b',64),'token_hash'=>str_repeat('c',64),'branches'=>'["f:100"]','enabled'=>true,'enrolled_at'=>now('UTC'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$binding=$ledger->bind($device);check($binding===$ledger->bind($device),'binding uses persisted enrollment and retries the exact identity');
DB::table('desktop_dashboard_devices')->where('id',$device)->update(['branches'=>'[ "f:100" ]']);check($binding===$ledger->bind($device),'enrollment identity uses canonical branch facts rather than JSON formatting');DB::table('desktop_dashboard_devices')->where('id',$device)->update(['branches'=>'["f:100"]']);
$actor=\App\Models\User::withoutGlobalScopes()->findOrFail(1);
$sale=function()use($actor){$v=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'customer_phone'=>'01012345678','service_consent'=>true,'items'=>[['product_id'=>1,'option_id'=>'','quantity_mode'=>'weight','quantity'=>'0.250']],'discount'=>'0.00','payment_method'=>'cash','cash_received'=>'100.00'];$v['quote_hash']=app(TakeawayService::class)->quote($v,$actor)['quote_hash'];return $v;};
$write=function(array $v)use($ledger,$journal,$device,$actor){return $ledger->atomic($device,$v['idempotency_key'],'takeaway.checkout',1,'f:100',fn()=>$journal->execute($device,$v['idempotency_key'],1,'takeaway.checkout',['values'=>$v],[],fn()=>app(TakeawayService::class)->checkout($v,$actor,[],true)));};
$v=$sale();$saved=$write($v);$intent=$saved['intents'][0];
check($intent['kind']==='sale_receipt'&&$intent['local_references']['receipt']===$saved['result']['receipt']['id'],'original real checkout atomically captures typed receipt and till-entry intent');
check($ledger->read($device,$v['idempotency_key'],1,'f:100')===$saved['intents'],'private immutable descriptor round-trips through encrypted storage');
check($write($v)===$saved&&DB::table('takeaway_orders')->count()===1,'exact retry returns one receipt and one durable effect');
check(!str_contains(json_encode($intent),'first_print_url')&&!str_contains(json_encode($intent),'receipt_url')&&!str_contains(json_encode($intent),'can_reprint'),'durable snapshot excludes bearer print URLs and actor presentation flags');
$row=DB::table('fasakhansta_dashboard_effects.print_ledger_intents')->first();check($row->lifecycle_state==='intent'&&!str_contains($row->descriptor_cipher,'رنجة'),'stored private intent is encrypted and has no invocation permission');
DB::table('fasakhansta_dashboard_effects.print_ledger_intents')->where('effect_id',$row->effect_id)->delete();
denied(fn()=>$write($v),503,'retry cannot fabricate capture success when the accepted private intent row is missing');
DB::table('fasakhansta_dashboard_effects.print_ledger_intents')->insert((array)$row);
$changed=$v;$changed['cash_received']='200.00';denied(fn()=>$write($changed),409,'a changed business request cannot reuse the original effect UUID');
$receiptEntry=DB::table('takeaway_till_entries')->where('id',$intent['local_references']['sale_till_entry.primary'])->first();$metadata=json_decode($receiptEntry->metadata,true);$metadata['receipt_print']['consumed_at']=now('UTC')->toIso8601String();
DB::table('takeaway_till_entries')->where('id',$receiptEntry->id)->update(['metadata'=>json_encode($metadata)]);
check($write($v)===$saved,'later grant lifecycle metadata does not rewrite immutable initial receipt intent');
DB::beginTransaction();denied(fn()=>$write($sale()),409,'intent wrapper refuses nesting that could invert scope lock order');DB::rollBack();
$raw->beginTransaction();denied(fn()=>$write($sale()),409,'capture refuses an ambient raw PDO transaction');check($raw->inTransaction(),'raw capture guard does not close the caller transaction');$raw->rollBack();

// Force a real cross-schema InnoDB failure after original checkout/outbox writes.
$baseline=[DB::table('takeaway_orders')->count(),DB::table('desktop_dashboard_commands')->count(),DB::table('crm_purchases')->count(),DB::table('takeaway_tills')->value('balance_cents'),DB::table('fasakhansta_dashboard_effects.print_ledger_commands')->count()];
DB::statement("CREATE TRIGGER fasakhansta_dashboard_effects.reject_intent BEFORE INSERT ON fasakhansta_dashboard_effects.print_ledger_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test intent write failure'");
try{$write($sale());throw new RuntimeException('Expected private ledger failure.');}catch(\Illuminate\Database\QueryException $error){}finally{DB::statement('DROP TRIGGER fasakhansta_dashboard_effects.reject_intent');}
check($baseline===[DB::table('takeaway_orders')->count(),DB::table('desktop_dashboard_commands')->count(),DB::table('crm_purchases')->count(),DB::table('takeaway_tills')->value('balance_cents'),DB::table('fasakhansta_dashboard_effects.print_ledger_commands')->count()],'intent write failure rolls back original cash, CRM purchase, sale, journal and private command across schemas');
$rollback=$sale();try{$ledger->atomic($device,$rollback['idempotency_key'],'takeaway.checkout',1,'f:100',function()use($journal,$device,$rollback,$actor){$journal->execute($device,$rollback['idempotency_key'],1,'takeaway.checkout',['values'=>$rollback],[],fn()=>app(TakeawayService::class)->checkout($rollback,$actor,[],true));throw new RuntimeException('test after business commit boundary');});}catch(RuntimeException $error){check($error->getMessage()==='test after business commit boundary','test abort reaches the post-business boundary');}
check(!DB::table('desktop_dashboard_commands')->where('command_id',$rollback['idempotency_key'])->exists()&&DB::table('takeaway_orders')->count()===$baseline[0],'post-business exception rolls back both original journal and ledger atomically');
$misuse=$sale();denied(fn()=>$ledger->atomic($device,$misuse['idempotency_key'],'takeaway.checkout',1,'f:100',function()use($journal,$device,$misuse,$actor){$result=$journal->execute($device,$misuse['idempotency_key'],1,'takeaway.checkout',['values'=>$misuse],[],fn()=>app(TakeawayService::class)->checkout($misuse,$actor,[],true));DB::connection()->getPdo()->commit();return $result;}),503,'a callback that explicitly commits PDO cannot report successful atomic capture');
check(!DB::table('fasakhansta_dashboard_effects.print_ledger_commands')->where('command_id',$misuse['idempotency_key'])->exists(),'explicit callback transaction misuse creates no print capability');
$historical=$sale();$journal->execute($device,$historical['idempotency_key'],1,'takeaway.checkout',['values'=>$historical],[],fn()=>app(TakeawayService::class)->checkout($historical,$actor,[],true));
denied(fn()=>$write($historical),409,'ordinary unwired business writes remain valid but cannot be adopted as new print intent');
check(!DB::table('fasakhansta_dashboard_effects.print_ledger_commands')->where('command_id',$historical['idempotency_key'])->exists(),'historical receipt receives no fabricated pending effect');

// Independent native PHP processes wait on the real fixed scope row.
// proc_open pipes cannot be selected on Windows; atomic files carry only test rendezvous/results.
$concurrent=$sale();
$workerJson=static function(string $path): ?array {clearstatcache(true,$path);return is_file($path)?json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR):null;};
$spawn=function(array $values)use($application,$profile,&$workers,$workerJson){
    $root=$profile.'/private/worker-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
    $process=proc_open([PHP_BINARY,__DIR__.'/print-ledger-worker.php',$application],[0=>['pipe','r'],1=>['file',$root.'/stdout.log','a'],2=>['file',$root.'/stderr.log','a']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($process))throw new RuntimeException('Could not start local test worker.');
    $job=(object)['process'=>$process,'root'=>$root,'connectionId'=>null,'exitCode'=>null];$workers[]=$job;
    try{fwrite($pipes[0],json_encode(['values'=>$values,'ready_path'=>$root.'/ready.json','result_path'=>$root.'/result.json'],JSON_THROW_ON_ERROR));}finally{fclose($pipes[0]);}
    $deadline=microtime(true)+10;
    do{
        if(($ready=$workerJson($root.'/ready.json'))!==null){
            if(($ready['format']??null)!==1||!is_int($ready['connection_id']??null)||$ready['connection_id']<1)throw new RuntimeException('Worker identity is invalid.');
            $job->connectionId=$ready['connection_id'];return $job;
        }
        $status=proc_get_status($process);if(!$status['running']){$job->exitCode=$status['exitcode'];break;}usleep(20000);
    }while(microtime(true)<$deadline);
    throw new RuntimeException('Worker did not reach its transaction boundary: '.substr((string)@file_get_contents($root.'/stderr.log'),-2000));
};
$collect=function(object $job)use($workerJson): array {
    $deadline=microtime(true)+15;$reply=null;
    do{
        $reply=$workerJson($job->root.'/result.json');$status=proc_get_status($job->process);
        if(!$status['running']){$job->exitCode=$status['exitcode'];break;}usleep(20000);
    }while(microtime(true)<$deadline);
    if($status['running'])throw new RuntimeException('Private worker exceeded its result deadline.');
    $code=proc_close($job->process);$job->process=null;if($job->exitCode>=0)$code=$job->exitCode;
    $reply=$workerJson($job->root.'/result.json');
    if($code!==0||!is_array($reply))throw new RuntimeException('Private worker failed: '.substr((string)@file_get_contents($job->root.'/stderr.log'),-2000));
    return $reply;
};
$concurrentFacts=fn()=>[DB::table('takeaway_orders')->count(),DB::table('desktop_dashboard_commands')->count(),DB::table('crm_purchases')->count(),DB::table('takeaway_tills')->value('balance_cents'),DB::table('fasakhansta_dashboard_effects.print_ledger_commands')->count(),DB::table('fasakhansta_dashboard_effects.print_ledger_intents')->count()];
$beforeConcurrent=$concurrentFacts();
try{
    DB::beginTransaction();DB::table('fasakhansta_dashboard_effects.print_ledger_scopes')->where('device_id',$device)->lockForUpdate()->first();
    $parentId=(int)DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $one=$spawn($concurrent);$two=$spawn($concurrent);$workerIds=[$one->connectionId,$two->connectionId];
    check(count(array_unique([$parentId,...$workerIds]))===3,'the parent and both native workers use distinct real database connections');
    $deadline=microtime(true)+10;$blocked=false;
    do{
        $edges=[];
        $waits=$pdo->query('SELECT r.trx_mysql_thread_id AS waiter,b.trx_mysql_thread_id AS blocker,l.lock_table,l.lock_index,l.lock_data FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id')->fetchAll(PDO::FETCH_ASSOC);
        foreach($waits as $wait)if($wait['lock_table']==='`fasakhansta_dashboard_effects`.`print_ledger_scopes`'&&$wait['lock_index']==='PRIMARY'&&str_contains((string)$wait['lock_data'],$device))$edges[(int)$wait['waiter']][]=(int)$wait['blocker'];
        $reachesParent=function(int $id)use($edges,$parentId): bool {$pending=[$id];$seen=[];while($pending){$current=array_pop($pending);if($current===$parentId)return true;if(isset($seen[$current]))continue;$seen[$current]=true;foreach($edges[$current]??[] as $blocker)$pending[]=$blocker;}return false;};
        $blocked=isset($edges[$workerIds[0]],$edges[$workerIds[1]])&&$reachesParent($workerIds[0])&&$reachesParent($workerIds[1]);
        // InnoDB's shared lock-information snapshot refreshes only after 100 ms without a read.
        if($blocked||$workerJson($one->root.'/result.json')!==null||$workerJson($two->root.'/result.json')!==null)break;usleep(200000);
    }while(microtime(true)<$deadline);
    if(!$blocked)fwrite(STDERR,'PRINT_LEDGER_SCOPE_WAIT_DIAGNOSTIC '.json_encode(['parent'=>$parentId,'workers'=>$workerIds,'waits'=>$waits,'results'=>[$workerJson($one->root.'/result.json'),$workerJson($two->root.'/result.json')],'parent_transaction'=>DB::connection()->getPdo()->inTransaction(),'parent_level'=>DB::transactionLevel()],JSON_THROW_ON_ERROR).PHP_EOL);
    check($blocked,'independent workers cannot bypass the held fixed scope lock');
    check($beforeConcurrent===$concurrentFacts()&&$workerJson($one->root.'/result.json')===null&&$workerJson($two->root.'/result.json')===null,'both blocked workers leave all financial, journal and private effect facts unchanged before release');
    echo 'PRINT_LEDGER_SCOPE_LOCK_PROOF '.json_encode(['parent'=>$parentId,'workers'=>$workerIds,'scope'=>$device,'both_waiting'=>true,'business_unchanged'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
    DB::commit();
    $first=$collect($one);$second=$collect($two);
}finally{
    // A failed assertion must not enter shutdown DROP DATABASE while still owning this lock.
    if(!$beforeDatabaseDrop())throw new RuntimeException('Private worker cleanup did not complete.');
}
check($first['status']===200&&$first===$second,'two contending processes return the same original business result and immutable intent');
check(DB::table('takeaway_orders')->where('request_key',$concurrent['idempotency_key'])->count()===1&&DB::table('fasakhansta_dashboard_effects.print_ledger_intents')->where('command_id',$concurrent['idempotency_key'])->count()===1,'concurrent retries persist one financial sale and one effect slot');
$conflict=$concurrent;$conflict['cash_received']='500.00';try{check($collect($spawn($conflict))['status']===409,'a separate process cannot overwrite the accepted tuple with changed facts');}finally{if(!$beforeDatabaseDrop())throw new RuntimeException('Private worker cleanup did not complete.');}
$target=DB::table('fasakhansta_dashboard_effects.print_ledger_intents')->where('command_id',$concurrent['idempotency_key'])->first();
$targetCommand=DB::table('fasakhansta_dashboard_effects.print_ledger_commands')->where('command_id',$concurrent['idempotency_key'])->first();$sourceCommand=DB::table('fasakhansta_dashboard_effects.print_ledger_commands')->where('command_id',$v['idempotency_key'])->first();
DB::table('fasakhansta_dashboard_effects.print_ledger_intents')->where('effect_id',$target->effect_id)->update(['descriptor_cipher'=>$row->descriptor_cipher,'descriptor_hash'=>$row->descriptor_hash]);
DB::table('fasakhansta_dashboard_effects.print_ledger_commands')->where('command_id',$concurrent['idempotency_key'])->update(['intents_hash'=>$sourceCommand->intents_hash]);
denied(fn()=>$ledger->read($device,$concurrent['idempotency_key'],1,'f:100'),503,'transplanted valid encrypted receipt cannot impersonate another command even with copied plaintext hashes');
DB::table('fasakhansta_dashboard_effects.print_ledger_intents')->where('effect_id',$target->effect_id)->update(['descriptor_cipher'=>$target->descriptor_cipher,'descriptor_hash'=>$target->descriptor_hash]);
DB::table('fasakhansta_dashboard_effects.print_ledger_commands')->where('command_id',$concurrent['idempotency_key'])->update(['intents_hash'=>$targetCommand->intents_hash]);

foreach([['enabled',false,401],['actor_id',10,409],['branches','["f:101"]',409],['enrollment_hash',str_repeat('d',64),409]] as [$field,$bad,$status]){
    $old=DB::table('desktop_dashboard_devices')->where('id',$device)->value($field);DB::table('desktop_dashboard_devices')->where('id',$device)->update([$field=>$bad]);
    $called=false;denied(fn()=>$ledger->atomic($device,(string)Str::uuid(),'takeaway.checkout',1,'f:100',function()use(&$called){$called=true;return [];}),$status,'fresh persisted '.$field.' overrides any previously bound scope');
    check(!$called,'rejected '.$field.' never enters original business work');DB::table('desktop_dashboard_devices')->where('id',$device)->update([$field=>$old]);
}
DB::table('users')->where('id',1)->update(['status'=>'disabled']);denied(fn()=>$write($sale()),403,'a persisted disabled actor cannot capture another intent');DB::table('users')->where('id',1)->update(['status'=>'accepted']);
DB::table('desktop_dashboard_local_state')->where('device_id',$device)->update(['state'=>'held']);denied(fn()=>$write($sale()),409,'a held generation cannot create business or print intent');DB::table('desktop_dashboard_local_state')->where('device_id',$device)->update(['state'=>'ready']);
denied(fn()=>$ledger->read($device,$v['idempotency_key'],10,'f:100'),403,'a foreign actor cannot read the encrypted original receipt');
denied(fn()=>$ledger->read($device,$v['idempotency_key'],1,'f:101'),403,'owner privilege cannot expand the enrolled branch');
$local=config('desktop_dashboard.local');config(['desktop_dashboard.local'=>false]);denied(fn()=>$write($sale()),403,'a non-local application cannot write the fixed private schema');config(['desktop_dashboard.local'=>$local]);
$cashierDevice=(string)Str::uuid();DB::table('desktop_dashboard_devices')->insert(['id'=>$cashierDevice,'actor_id'=>10,'name'=>'branch authority fixture','enrollment_hash'=>str_repeat('d',64),'token_hash'=>str_repeat('e',64),'branches'=>'["f:100"]','enabled'=>true,'enrolled_at'=>now('UTC'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
DB::table('desktop_dashboard_local_state')->insert(['device_id'=>$cashierDevice,'actor_id'=>10,'snapshot_id'=>(string)Str::uuid(),'schema_hash'=>str_repeat('a',64),'branches'=>'["f:100"]','coverage'=>'{}','state'=>'ready','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
config(['desktop_dashboard.device_id'=>$cashierDevice]);$ledger->bind($cashierDevice);DB::table('resturants')->where('id',100)->update(['user_id'=>11]);$called=false;
denied(fn()=>$ledger->atomic($cashierDevice,(string)Str::uuid(),'takeaway.checkout',10,'f:100',function()use(&$called){$called=true;return [];}),404,'a fresh branch ownership change overrides the enrolled branch name');check(!$called,'a stale branch relationship never reaches business work');
DB::table('resturants')->where('id',100)->update(['user_id'=>10]);config(['desktop_dashboard.device_id'=>$device]);

// Execute the actual phone service, then advance the mutable ticket/kitchen pointer.
$phone=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'items'=>[['product_id'=>1,'quantity_mode'=>'weight','quantity'=>'0.250']],'discount'=>'0.00','customer_name'=>'عميل الاختبار','customer_phone'=>'01012345678','address'=>'المنصورة','latitude'=>30.001,'longitude'=>31.001,'location_confirmed'=>true,'service_consent'=>true,'send_to_kitchen'=>true];
$phone['delivery_quote_hash']=app(PhoneDelivery::class)->quote($phone,$actor)['delivery']['delivery_quote_hash'];$phone['quote_hash']=app(PosServiceTicket::class)->quote('phone',$phone,$actor)['quote_hash'];
$phoneWrite=fn()=>$ledger->atomic($device,$phone['idempotency_key'],'phone-orders.save',1,'f:100',fn()=>$journal->execute($device,$phone['idempotency_key'],1,'phone-orders.save',['values'=>$phone],[],fn()=>app(PosServiceTicket::class)->save('phone',$phone,$actor,true)));
$phoneSaved=$phoneWrite();$kitchenIntent=$phoneSaved['intents'][0];$ticket=$phoneSaved['result']['ticket'];
check($kitchenIntent['kind']==='phone_kitchen'&&$kitchenIntent['revision']===$ticket['revision'],'original phone save captures the generated immutable kitchen revision');
$action=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'expected_revision'=>$ticket['revision'],'action'=>'send_kitchen','service_consent'=>true];
$sent=$ledger->atomic($device,$action['idempotency_key'],'phone-orders.action',1,'f:100',fn()=>$journal->execute($device,$action['idempotency_key'],1,'phone-orders.action',['parameters'=>['id'=>$ticket['id']],'values'=>$action],[],fn()=>app(PosServiceTicket::class)->action('phone',$ticket['id'],$action,$actor,true)));
check($sent['intents'][0]['local_references']['kitchen.primary']!==$kitchenIntent['local_references']['kitchen.primary'],'later send-to-kitchen creates its own command-bound immutable output');
check($phoneWrite()['intents']===$phoneSaved['intents'],'retry does not rebind an earlier save to ticket.last_kitchen_id');

// Complete this real phone invoice before the fixture reviews a shift. The live
// pending-invoice guard must also apply when a local native print test closes.
$collectTicket=app(PosServiceTicket::class)->show('phone',$ticket['id'],$actor)['ticket'];
$collectValues=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'expected_revision'=>$collectTicket['revision'],
    'quote_hash'=>$collectTicket['quote_hash'],'payment_method'=>'cash','cash_received'=>'100.00','payment_confirmed'=>true];
$collected=$ledger->atomic($device,$collectValues['idempotency_key'],'phone-orders.settle',1,'f:100',fn()=>$journal->execute(
    $device,$collectValues['idempotency_key'],1,'phone-orders.settle',['parameters'=>['id'=>$ticket['id']],'values'=>$collectValues],[],
    fn()=>app(PosServiceTicket::class)->settle('phone',$ticket['id'],$collectValues,$actor,true)));
if(($collected['result']['ticket']['payment_status']??null)!=='paid')throw new RuntimeException('Print-ledger shift fixture did not settle its phone invoice.');

$review=app(BranchShiftClosing::class)->data(['branch'=>'f:100'],$actor);$closing=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'previous_closing_id'=>$review['previous_closing_id'],'review_token'=>$review['review_token'],'counted_cash'=>'25.00'];
$facts=app(BranchShiftClosing::class)->desktopReview($closing,$actor);
$closed=$ledger->atomic($device,$closing['idempotency_key'],'branch-shifts.close',1,'f:100',fn()=>$journal->execute($device,$closing['idempotency_key'],1,'branch-shifts.close',['values'=>$closing,'facts'=>['shift'=>$facts]],[],fn()=>app(BranchShiftClosing::class)->close($closing,$actor)));
check($closed['intents'][0]['kind']==='shift_report'&&$closed['intents'][0]['local_references']['shift']===$closed['result']['closing']['id'],'original immutable shift snapshot binds its exact report job');

// Schema drift is rejected before the business callback, never repaired in-place.
DB::statement('ALTER TABLE fasakhansta_dashboard_effects.print_ledger_intents MODIFY slot varchar(8) NOT NULL');
denied(fn()=>$write($sale()),503,'private schema capacity drift fails closed');DB::statement('ALTER TABLE fasakhansta_dashboard_effects.print_ledger_intents MODIFY slot varchar(24) NOT NULL COLLATE utf8mb4_bin');
DB::statement('ALTER TABLE fasakhansta_dashboard_effects.print_ledger_intents ENGINE=MyISAM');denied(fn()=>$write($sale()),503,'nontransactional private ledger fails closed');DB::statement('ALTER TABLE fasakhansta_dashboard_effects.print_ledger_intents ENGINE=InnoDB');
$schema->verify();
DB::statement('CREATE TABLE nontransactional_probe (id int NOT NULL) ENGINE=MyISAM');denied(fn()=>$write($sale()),503,'a nontransactional generation table is rejected before original work');DB::statement('DROP TABLE nontransactional_probe');

// Replacement generation deliberately reuses every business integer with changed data.
$original=$ledger->read($device,$phone['idempotency_key'],1,'f:100');
$pdo->exec('CREATE DATABASE `'.$other.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
foreach($pdo->query('SHOW TABLES FROM `'.$database.'`')->fetchAll(PDO::FETCH_COLUMN) as $table){$pdo->exec('CREATE TABLE `'.$other.'`.`'.$table.'` LIKE `'.$database.'`.`'.$table.'`');$pdo->exec('INSERT INTO `'.$other.'`.`'.$table.'` SELECT * FROM `'.$database.'`.`'.$table.'`');}
config(['database.connections.mysql.database'=>$other]);DB::purge();
DB::table('desktop_dashboard_local_state')->where('device_id',$device)->update(['snapshot_id'=>(string)Str::uuid(),'schema_hash'=>str_repeat('e',64)]);
DB::table('pos_service_kitchen_tickets')->where('id',$kitchenIntent['local_references']['kitchen.primary'])->update(['snapshot'=>'{"customer_name":"DIFFERENT GENERATION"}']);
check($ledger->read($device,$phone['idempotency_key'],1,'f:100')===$original,'retained intent ignores numeric-ID reuse and different snapshot contents in the next generation');
$called=false;denied(fn()=>$ledger->atomic($device,$phone['idempotency_key'],'phone-orders.save',1,'f:100',function()use(&$called){$called=true;return [];}),409,'old command cannot be adopted or rerun in a replacement generation');check(!$called,'generation mismatch is detected before original work');
$pdo->exec('DROP DATABASE `'.$database.'`');
check($ledger->read($device,$phone['idempotency_key'],1,'f:100')===$original,'original immutable receipt remains readable when the old business database is absent');
check(array_unique(DB::table('fasakhansta_dashboard_effects.print_ledger_intents')->pluck('lifecycle_state')->all())===['intent'],'all captured effects remain inert and cannot claim or invoke a printer');
check(!in_array('phone-orders.print-claim',\App\Services\Dashboard\DesktopDashboardRoutes::WRITES,true)&&!in_array('branch-shift-print.claim',\App\Services\Dashboard\DesktopDashboardRoutes::WRITES,true),'physical write routes remain outside ordinary business replay coverage');
require __DIR__.'/print-effects.php';
require __DIR__.'/workspace-print-effects.php';
echo $count.' private print-ledger checks passed using original Laravel and disposable MariaDB'.PHP_EOL;
