<?php
// Runs the complete original Laravel application against a disposable LOCAL MariaDB database.
// Pass the generated application directory and local MariaDB port; no production .env is loaded.
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardDevices,DesktopDashboardReconciliation,TakeawayService,BranchInventory,BranchExpenses,BranchPayroll,BranchShiftClosing};

$application=realpath($argv[1]??'');$port=(int)($argv[2]??0);
if(!$application||!is_file($application.'/desktop/bootstrap.php')||$port<1024||$port>65535)throw new RuntimeException('Usage: run.php application-directory local-mariadb-port');
$profile=sys_get_temp_dir().'/fasakhansta-dashboard-test-'.bin2hex(random_bytes(8));
foreach(['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'] as $dir)mkdir($profile.'/'.$dir,0700,true);
$database='desktop_dashboard_test_'.bin2hex(random_bytes(8));
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(function()use($pdo,$database){$pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`');});
foreach(['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_STORAGE'=>$profile,'APP_ENV'=>'desktop','APP_DEBUG'=>'false','APP_URL'=>'http://127.0.0.1:33408',
    'APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$database,'DB_USERNAME'=>'root','DB_PASSWORD'=>'',
    'CACHE_DRIVER'=>'file','SESSION_DRIVER'=>'file','APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php',
    'APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $key=>$value)putenv($key.'='.$value);
$app=require $application.'/desktop/bootstrap.php';
set_exception_handler(function(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);});
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-08T12:00:00Z'));
$count=0;
function check($condition,$message){global $count;if(!$condition)throw new RuntimeException($message);$count++;echo 'PASS '.$message.PHP_EOL;}
function denied(callable $fn,int $status,string $message){try{$fn();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){check($e->getStatusCode()===$status,$message);return;}catch(\Illuminate\Validation\ValidationException $e){check($status===422,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
check($app->version()==='8.83.29' && count($app['router']->getRoutes())>=565,'the original complete Laravel application boots with its original routes');
Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->unsignedBigInteger('owner_resturant_id')->nullable();$t->string('password')->nullable();$t->string('remember_token')->nullable();$t->string('email')->nullable();$t->string('mobile')->nullable();});
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');$t->string('phone')->default('01000000000');$t->string('address')->default('المنصورة');});
Schema::create('categories',function(Blueprint $t){$t->id();$t->string('name_ar');$t->string('name_en');});
Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->unsignedBigInteger('product_id');$t->unsignedBigInteger('category_id');$t->string('product_name');$t->decimal('product_price',14,2);$t->string('status');$t->text('price');});
Schema::create('orders',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('type');$t->string('status');$t->string('payment_type');$t->string('transfer_price_by')->nullable();$t->timestamps();});
foreach([
    '2026_10_03_140000_create_takeaway_pos.php'=>'CreateTakeawayPos',
    '2026_10_03_150000_create_pos_service_tickets.php'=>'CreatePosServiceTickets',
    '2026_10_04_030000_create_branch_expenses.php'=>'CreateBranchExpenses',
    '2026_10_04_060000_lock_pos_service_bills.php'=>'LockPosServiceBills',
    '2026_10_04_080000_create_branch_operations.php'=>'CreateBranchOperations',
    '2026_10_04_100000_create_branch_shift_closings.php'=>'CreateBranchShiftClosings',
    '2026_10_04_190000_create_branch_stock.php'=>'CreateBranchStock',
    '2026_10_04_210000_create_branch_inventory_recipes.php'=>'CreateBranchInventoryRecipes',
    '2026_10_06_120000_create_branch_expenses_categories.php'=>'CreateBranchExpensesCategories',
    '2026_10_06_200000_manage_expense_categories.php'=>'ManageExpenseCategories',
    '2026_10_06_140000_add_employee_wallet_phone.php'=>'AddEmployeeWalletPhone',
    '2026_10_08_130000_create_desktop_dashboard_journal.php'=>'CreateDesktopDashboardJournal',
] as $file=>$class){require $application.'/database/migrations/'.$file;(new $class)->up();}
DB::table('users')->insert([['id'=>1,'name'=>'الأونر','account_type'=>'admin'],['id'=>10,'name'=>'كاشير','account_type'=>'vendor'],['id'=>11,'name'=>'فرع آخر','account_type'=>'vendor']]);
DB::table('users')->where('id',10)->update(['password'=>password_hash('LocalTest123',PASSWORD_BCRYPT),'remember_token'=>'test-private-session-token']);
DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'الفرع الأول'],['id'=>101,'user_id'=>11,'name'=>'الفرع الثاني']]);
DB::table('categories')->insert(['id'=>1,'name_ar'=>'رنجة','name_en'=>'Herring']);
DB::table('resturant_products')->insert(['id'=>1,'resturant_id'=>100,'product_id'=>1,'category_id'=>1,'product_name'=>'رنجة','product_price'=>'100.00','status'=>'show','price'=>'{}']);
DB::table('branch_stock_recipes')->insert(['branch'=>'f:100','product_id'=>1,'unit'=>'kg','revision'=>1,'updated_by'=>1,'variants'=>json_encode(['0'=>[['ingredient_id'=>6,'name'=>'رنجة سمينة','unit'=>'kg','quantity_units'=>1000000]]])]);
DB::table('takeaway_tills')->insert(['branch'=>'f:100','tax_bps'=>1400,'balance_cents'=>100000,'revision'=>1]);
// Clone only this disposable fixture baseline. The remote database evolves independently below.
$remoteDatabase=$database.'_remote';$pdo->exec('CREATE DATABASE `'.$remoteDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(function()use($pdo,$remoteDatabase){$pdo->exec('DROP DATABASE IF EXISTS `'.$remoteDatabase.'`');});
foreach($pdo->query('SHOW TABLES FROM `'.$database.'`')->fetchAll(PDO::FETCH_COLUMN) as $table){
    $pdo->exec('CREATE TABLE `'.$remoteDatabase.'`.`'.$table.'` LIKE `'.$database.'`.`'.$table.'`');
    $pdo->exec('INSERT INTO `'.$remoteDatabase.'`.`'.$table.'` SELECT * FROM `'.$database.'`.`'.$table.'`');
}
$actor=User::withoutGlobalScopes()->findOrFail(1);$cashier=User::withoutGlobalScopes()->findOrFail(10);$journal=app(DesktopDashboardJournal::class);$device=(string)Str::uuid();
$receiveId=(string)Str::uuid();$receive=['branch'=>'f:100','idempotency_key'=>$receiveId,'ingredient_id'=>6,'unit'=>'kg','quantity'=>'2.000','supplier'=>'المورد'];
$received=$journal->execute($device,$receiveId,1,'branch-stock.receive',['values'=>$receive],[],fn()=>app(BranchInventory::class)->receive($receive,$actor));
check((int)DB::table('branch_inventory')->value('quantity_units')===2000000 && $journal->counts($device)['pending']===1,'original goods receipt and encrypted command commit together');
$duplicate=$journal->execute($device,$receiveId,1,'branch-stock.receive',['values'=>$receive],[],function(){throw new RuntimeException('A replay must never execute a business operation again.');});
check($duplicate===$received && DB::table('branch_inventory_movements')->count()===1,'retry returns the saved original receipt without another stock movement');
$changed=$receive;$changed['quantity']='3.000';denied(fn()=>$journal->execute($device,$receiveId,1,'branch-stock.receive',['values'=>$changed],[],fn()=>[]),409,'an operation UUID cannot be reused with changed data');
$saleId=(string)Str::uuid();$sale=['branch'=>'f:100','items'=>[['product_id'=>1,'option_id'=>'','quantity_mode'=>'weight','quantity'=>'0.250']],'discount'=>'0.00','payment_method'=>'cash','cash_received'=>'100.00','idempotency_key'=>$saleId];
$sale['quote_hash']=app(TakeawayService::class)->quote($sale,$actor)['quote_hash'];
$sold=$journal->execute($device,$saleId,1,'takeaway.checkout',['values'=>$sale],[$receiveId],fn()=>app(TakeawayService::class)->checkout($sale,$actor));
check((int)DB::table('takeaway_tills')->value('balance_cents')===102850 && (int)DB::table('branch_inventory')->value('quantity_units')===1750000,'original sale changes cash, stock and its outbox atomically');
check(count($journal->pending($device))===1 && $journal->pending($device)[0]['command_id']===$receiveId,'the outbox yields the earlier stock receipt before its dependent sale');
$receipt=['device_id'=>$device,'command_id'=>$receiveId,'committed'=>true,'result'=>$received];$journal->acknowledge($device,$receiveId,$receipt);$journal->acknowledge($device,$receiveId,$receipt);
check($journal->counts($device)['acknowledged']===1 && $journal->pending($device)[0]['command_id']===$saleId,'duplicate acknowledgements are harmless and unlock the dependent sale');
denied(fn()=>$journal->acknowledge($device,$saleId,['device_id'=>$device,'command_id'=>$receiveId,'committed'=>true]),422,'an unrelated response cannot remove a pending operation');
$journal->failed($device,$saleId,'connection ended after the remote response');
check($journal->pending($device)[0]['command_id']===$saleId,'losing an acknowledgement preserves the identical operation UUID');
$journal->acknowledge($device,$saleId,['device_id'=>$device,'command_id'=>$saleId,'committed'=>true,'result'=>$sold]);
$expenseId=(string)Str::uuid();$expense=['branch'=>'f:100','idempotency_key'=>$expenseId,'occurred_on'=>'2026-10-08','category'=>'purchases','description'=>'مشتريات','amount'=>'20.00','payment_method'=>'cash','approve'=>true];
$spent=$journal->execute($device,$expenseId,1,'branch-expenses.save',['values'=>$expense],[$saleId],fn()=>app(BranchExpenses::class)->save($expense,$actor));
check((int)DB::table('takeaway_tills')->value('balance_cents')===100850 && DB::table('branch_expenses')->value('status')==='approved','the original approval posts the cash expense and retains the command');
$deniedExpense=$expense;$deniedExpense['idempotency_key']=(string)Str::uuid();$before=DB::table('desktop_dashboard_commands')->count();
denied(fn()=>$journal->execute($device,$deniedExpense['idempotency_key'],10,'branch-expenses.save',['values'=>$deniedExpense],[],fn()=>app(BranchExpenses::class)->save($deniedExpense,$cashier)),403,'the real branch cashier cannot acquire owner approval rights offline');
check(DB::table('desktop_dashboard_commands')->count()===$before && DB::table('branch_expenses')->count()===1,'rejected permissions create neither expense nor queued success');
$employeeId=(string)Str::uuid();$employee=['branch'=>'f:100','idempotency_key'=>$employeeId,'name'=>'موظف الاختبار','phone'=>'01012345678','job_title'=>'كاشير','shift'=>'صباحي','hired_on'=>'2026-10-01','effective_month'=>'2026-10','salary'=>'5000.00','active'=>true];
$saved=$journal->execute($device,$employeeId,1,'employees.save',['values'=>$employee],[],fn()=>app(BranchPayroll::class)->employeeSave($employee,$actor));
$attendanceId=(string)Str::uuid();$attendance=['branch'=>'f:100','idempotency_key'=>$attendanceId,'employee_id'=>$saved['employee']['id'],'day'=>'2026-10-08','status'=>'present'];
$journal->execute($device,$attendanceId,1,'employees.attendance',['values'=>$attendance],[$employeeId],fn()=>app(BranchPayroll::class)->attendance($attendance,$actor));
check(DB::table('branch_employees')->count()===1 && DB::table('branch_employee_days')->value('status')==='present','the original employee and attendance services execute locally');
$advanceId=(string)Str::uuid();$advance=['branch'=>'f:100','idempotency_key'=>$advanceId,'employee_id'=>$saved['employee']['id'],'day'=>'2026-10-08','kind'=>'advance','amount'=>'50.00','reason'=>'سلفة'];
$journal->execute($device,$advanceId,1,'employees.entry',['values'=>$advance],[$employeeId],fn()=>app(BranchPayroll::class)->entry($advance,$actor));
check((int)DB::table('branch_employee_entries')->value('amount_cents')===5000,'the original payroll ledger records its local advance');
$closingId=(string)Str::uuid();$review=app(BranchShiftClosing::class)->data(['branch'=>'f:100'],$actor);
$closing=['branch'=>'f:100','idempotency_key'=>$closingId,'previous_closing_id'=>$review['previous_closing_id'],'review_token'=>$review['review_token'],'counted_cash'=>'1008.50','notes'=>'تقفيل محلي'];
$closed=$journal->execute($device,$closingId,1,'branch-shifts.close',['values'=>$closing,'facts'=>['shift'=>app(BranchShiftClosing::class)->desktopReview($closing,$actor)]],[$saleId,$expenseId,$advanceId],fn()=>app(BranchShiftClosing::class)->close($closing,$actor));
check((int)DB::table('takeaway_tills')->value('balance_cents')===0 && DB::table('branch_shift_closings')->count()===1,'the original shift closes locally and resets the drawer exactly once');
$snapshot=app(BranchShiftClosing::class)->receipt($closed['closing']['id'],$actor);
check($snapshot['expected_cash']==='1008.50' && $snapshot['channels']['takeaway']['count']===1 && $snapshot['expenses_total']==='20.00','the saved shift snapshot reconciles its original local sales and expenses');
$journal->failed($device,$expenseId,'changed server revision',true);
check($journal->pending($device)===[] && $journal->counts($device)['conflicts']===1,'a conflict preserves operations and blocks later dependent commands');
// Force a real InnoDB failure AFTER the original service has written its financial changes.
DB::statement("CREATE TRIGGER reject_dashboard_journal BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated outbox disk failure'");
$rollbackId=(string)Str::uuid();$rollback=$receive;$rollback['idempotency_key']=$rollbackId;$prior=(int)DB::table('branch_inventory')->value('quantity_units');$movements=DB::table('branch_inventory_movements')->count();
try{$journal->execute($device,$rollbackId,1,'branch-stock.receive',['values'=>$rollback],[],fn()=>app(BranchInventory::class)->receive($rollback,$actor));throw new RuntimeException('Expected database failure.');}catch(\Illuminate\Database\QueryException $error){}
check((int)DB::table('branch_inventory')->value('quantity_units')===$prior && DB::table('branch_inventory_movements')->count()===$movements,'a failed journal insert rolls back the original stock receipt in real InnoDB');
DB::statement('DROP TRIGGER reject_dashboard_journal');
$raw=DB::table('desktop_dashboard_commands')->where('command_id',$employeeId)->first();
check(!str_contains($raw->command_cipher,'موظف الاختبار') && !str_contains($raw->local_result_cipher,'5000'),'command inputs and saved results are encrypted at rest');
$counts=$journal->counts($device);DB::disconnect();DB::reconnect();
check($journal->counts($device)===$counts && DB::table('branch_shift_closings')->count()===1,'commands and business data survive reopening the local database');
$envelopes=[];
foreach(DB::table('desktop_dashboard_commands')->where('device_id',$device)->orderBy('sequence')->get() as $row){
    $savedCommand=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($row->local_result_cipher),true);
    $envelopes[]=['command_id'=>$row->command_id,'actor_id'=>(int)$row->actor_id,'route_name'=>$row->route_name,
        'payload'=>json_decode(\Illuminate\Support\Facades\Crypt::decryptString($row->command_cipher),true),
        'local_result'=>$savedCommand['result'],'local_references'=>$savedCommand['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>$row->created_at];
}
config(['database.connections.mysql.database'=>$remoteDatabase,'desktop_dashboard.enabled'=>true]);DB::purge();
config(['app.key'=>'base64:'.base64_encode(random_bytes(32))]);$app->forgetInstance('encrypter');\Illuminate\Support\Facades\Facade::clearResolvedInstance('encrypter');
// A server-side employee takes the local employee's integer ID before the offline device reconnects.
DB::table('branch_employees')->insert(['branch'=>'f:101','name'=>'موظف السيرفر','job_title'=>'كاشير','hired_on'=>'2026-10-01','active'=>true,'revision'=>1,'actor_id'=>1]);
// Other-branch operations also consume the receipt and expense IDs that the local device used.
$serverActor=User::withoutGlobalScopes()->findOrFail(1);
DB::table('resturant_products')->insert(['id'=>2,'resturant_id'=>101,'product_id'=>2,'category_id'=>1,'product_name'=>'صنف فرع آخر','product_price'=>'100.00','status'=>'show','price'=>'{}']);
$otherSale=['branch'=>'f:101','items'=>[['product_id'=>2,'option_id'=>'','quantity_mode'=>'piece','quantity'=>'1']],'discount'=>'0.00','payment_method'=>'cash','cash_received'=>'100.00','idempotency_key'=>(string)Str::uuid()];
$otherSale['quote_hash']=app(TakeawayService::class)->quote($otherSale,$serverActor)['quote_hash'];app(TakeawayService::class)->checkout($otherSale,$serverActor);
app(BranchExpenses::class)->save(['branch'=>'f:101','idempotency_key'=>(string)Str::uuid(),'occurred_on'=>'2026-10-08','category'=>'purchases','description'=>'مصروف فرع آخر','amount'=>'10.00','payment_method'=>'cash','approve'=>true],$serverActor);
$enrollment=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$device,'name'=>'اختبار المزامنة','nonce'=>bin2hex(random_bytes(32))],User::withoutGlobalScopes()->findOrFail(1));
$remoteDevice=app(DesktopDashboardDevices::class)->device($enrollment['token']);$reconciliation=app(DesktopDashboardReconciliation::class);
$bootstrap=app(\App\Services\Dashboard\DesktopDashboardBootstrap::class)->export($remoteDevice);
check($bootstrap['device_id']===$device && $bootstrap['actor_id']===1 && !$bootstrap['coverage']['full_dashboard'],'initial data is bound to the enrolled account and reports incomplete coverage');
$branchEnrollment=app(DesktopDashboardDevices::class)->enroll(['device_id'=>(string)Str::uuid(),'name'=>'جهاز الكاشير','nonce'=>bin2hex(random_bytes(32))],User::withoutGlobalScopes()->findOrFail(10));
$cashierBootstrap=app(\App\Services\Dashboard\DesktopDashboardBootstrap::class)->export(app(DesktopDashboardDevices::class)->device($branchEnrollment['token']));
check($cashierBootstrap['branches']===['f:100'] && count($cashierBootstrap['tables']['resturants']['rows'])===1 && count($cashierBootstrap['tables']['users']['rows'])===1,'a cashier initial dataset contains only the enrolled branch and account');
check(count($cashierBootstrap['tables']['takeaway_orders']['rows'])===0 && count($cashierBootstrap['tables']['branch_employees']['rows'])===0,'other-branch sales and employees cannot leak into the local dataset');
check(!isset($cashierBootstrap['tables']['desktop_dashboard_devices'],$cashierBootstrap['tables']['desktop_dashboard_commands']),'initial data never includes pairing tokens or another device journal');
check(password_verify('LocalTest123',$cashierBootstrap['tables']['users']['rows'][0]['password']) && $cashierBootstrap['tables']['users']['rows'][0]['remember_token']===null,'only the enrolled account password hash is cached; server sessions are excluded');
$ownerUsers=array_column($bootstrap['tables']['users']['rows'],null,'id');
check(!password_verify('LocalTest123',$ownerUsers[10]['password']),'other cached branch accounts have unusable password hashes');
$staging='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));$pdo->exec('CREATE DATABASE `'.$staging.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(function()use($pdo,$staging){$pdo->exec('DROP DATABASE IF EXISTS `'.$staging.'`');});
config(['database.connections.mysql.database'=>$staging,'desktop_dashboard.device_id'=>$branchEnrollment['device_id']]);DB::purge();
$corrupt=$cashierBootstrap;$corrupt['tables']['users']['rows'][0]['name']='تعديل أثناء النقل';
denied(fn()=>app(\App\Services\Dashboard\DesktopDashboardImport::class)->import($corrupt),422,'a damaged initial dataset is rejected before any local schema is created');
check(DB::select('SHOW TABLES')===[],'failed integrity validation leaves the new staging database empty');
$imported=app(\App\Services\Dashboard\DesktopDashboardImport::class)->import($cashierBootstrap);
check($imported['schema_hash']===$cashierBootstrap['schema_hash'] && DB::table('resturants')->count()===1 && DB::table('users')->count()===1,'the account dataset imports into a separate real local MariaDB schema');
check(Schema::hasTable('desktop_dashboard_commands') && DB::table('desktop_dashboard_commands')->count()===0,'an imported local database receives its own empty encrypted command journal');
$localCashier=User::withoutGlobalScopes()->findOrFail(10);
$localSummary=app(TakeawayService::class)->summary('f:100',$localCashier);
check($localSummary['ready'] && !$localSummary['permissions']['can_manage'],'the original dashboard service reads imported branch data with original cashier rights');
denied(fn()=>app(TakeawayService::class)->summary('f:101',$localCashier),404,'the imported original dashboard cannot switch to an unauthorized branch');
denied(fn()=>app(\App\Services\Dashboard\DesktopDashboardImport::class)->import($cashierBootstrap),409,'re-running setup cannot overwrite a populated local database');
config(['database.connections.mysql.database'=>$remoteDatabase,'desktop_dashboard.device_id'=>$device]);DB::purge();
foreach($envelopes as $envelope){
    $reply=$reconciliation->ingest($remoteDevice,$envelope);
    check($reply['committed'] && $reply['command_id']===$envelope['command_id'],'remote original service confirms '.$envelope['route_name']);
    $again=$reconciliation->ingest($remoteDevice,$envelope);
    check($reply===$again,'lost remote reply returns the identical committed '.$envelope['route_name'].' result');
}
check(DB::table('takeaway_orders')->where('branch','f:100')->count()===1 && DB::table('branch_inventory_movements')->where('branch','f:100')->where('source_type','pos')->count()===1,'reconnecting creates one sale and one recipe deduction on the server');
$remoteEmployee=DB::table('branch_employees')->where('branch','f:100')->value('id');
check((int)$remoteEmployee!== (int)$saved['employee']['id'] && (int)DB::table('branch_employee_days')->value('employee_id')===(int)$remoteEmployee,'attendance refers to the mapped server employee despite integer ID collision');
check((int)DB::table('branch_employee_entries')->value('employee_id')===(int)$remoteEmployee,'a dependent payroll entry uses the server employee ID');
check((int)DB::table('takeaway_tills')->value('balance_cents')===0 && (int)DB::table('branch_inventory')->value('quantity_units')===1750000,'server cash and inventory match the local original operations after shift reconciliation');
check(DB::table('branch_shift_closings')->count()===1 && DB::table('branch_shift_sources')->count()===2 && (int)DB::table('branch_shift_sources')->where('source','pos')->value('source_id')===2 && (int)DB::table('branch_shift_sources')->where('source','expense')->value('source_id')===2,'one synced closing maps and claims its single sale and expense sources despite ID collisions');
$altered=$envelopes[0];$altered['payload']['values']['quantity']='9.000';denied(fn()=>$reconciliation->ingest($remoteDevice,$altered),409,'the server rejects altered content with an already committed operation UUID');
DB::table('desktop_dashboard_devices')->where('id',$device)->update(['enabled'=>false]);denied(fn()=>$reconciliation->ingest($remoteDevice,$envelopes[0]),401,'revoked device authorization is checked before replaying its saved result');
require dirname(__DIR__,2).'/deployment/desktop_dashboard_inspect.php';
$inspection=FasakhanstaDesktopSchemaInspection::report($app);$encoded=json_encode($inspection,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
check(isset($inspection['tables']['users'],$inspection['tables']['desktop_dashboard_commands']) && count($inspection['routes'])>=565,'read-only inspection describes actual schema, indexes and routes');
check(!str_contains($encoded,$enrollment['token']) && !str_contains($encoded,config('app.key')) && !str_contains($encoded,'موظف السيرفر') && !str_contains($encoded,'COLUMN_DEFAULT'),'inspection exports no tokens, keys, business rows or column defaults');
$compact=FasakhanstaDesktopSchemaInspection::compact($inspection);
check($compact['tables']['users']['columns']['id']==='bigint(20) unsigned auto_increment' && $compact['route_count']>=565 && !isset($compact['routes']),'compact inspection retains actual legacy column types without dumping route bodies or business data');
// Exercise the actual loopback HTTP gateway, not a mocked controller or JavaScript substitute.
$reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$httpPort=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);
$origin='http://127.0.0.1:'.$httpPort;$browserToken=bin2hex(random_bytes(32));$controlToken=bin2hex(random_bytes(32));
$env=getenv();$env['DESKTOP_DASHBOARD_DEVICE_ID']=$device;$env['DESKTOP_DASHBOARD_ORIGIN']=$origin;$env['DESKTOP_DASHBOARD_TOKEN']=$browserToken;$env['DESKTOP_DASHBOARD_CONTROL_TOKEN']=$controlToken;
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
function gateway($url,$headers=[],?array $body=null): array {
    $context=stream_context_create(['http'=>['method'=>$body===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$body===null?'':json_encode($body),'ignore_errors'=>true,'timeout'=>5,'follow_location'=>0]]);
    $result=@file_get_contents($url,false,$context);preg_match('/^HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$status);
    return [(int)($status[1]??0),$result];
}
try{
    $headers=['X-Fasakhansta-Desktop: '.$browserToken,'Content-Type: application/json'];
    for($n=0;$n<100;$n++){[$status]=gateway($origin.'/_desktop/health',$headers);if($status===200)break;usleep(50000);}
    check($status===200,'the real protected PHP loopback gateway starts');
    check(gateway($origin.'/_desktop/health')[0]===403,'an ordinary local browser cannot access the private PHP service');
    check(gateway($origin.'/_desktop/control',$headers,['action'=>'pending'])[0]===403,'the dashboard browser credential cannot read or acknowledge its native outbox');
    $nativeHeaders=[...$headers,'X-Fasakhansta-Control: '.$controlToken];
    [$status,$body]=gateway($origin.'/_desktop/control',$nativeHeaders,['action'=>'pending']);$response=json_decode($body,true);
    check($status===200 && $response['counts']===$counts,'the separate native credential reads the actual durable local outbox');
    check(gateway($origin.'/_desktop/control',[...$nativeHeaders,'Origin: https://foreign.example'],['action'=>'pending'])[0]===403,'a foreign web origin cannot use the native control gateway');
    check(gateway($origin.'/storage/private.php',$headers)[0]===404,'public storage PHP paths cannot execute through the dashboard router');
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}
echo $count.' checks passed using the original Laravel application and real MariaDB'.PHP_EOL;
