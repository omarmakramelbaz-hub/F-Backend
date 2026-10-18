<?php
/** Real SQLite listing and write authorization regressions; no production bootstrap or network. */
require __DIR__.'/expense_stock_integration_test.php';

use App\Services\Dashboard\{BranchCustomers,BranchExpenses,BranchPayroll,DeliveryCompanies};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$startChecks=$checks;
foreach([
    '2026_10_03_150000_create_pos_service_tickets.php'=>'CreatePosServiceTickets',
    '2026_10_04_080000_create_branch_operations.php'=>'CreateBranchOperations',
    '2026_10_08_190000_add_employee_attendance_rules.php'=>'AddEmployeeAttendanceRules',
] as $file=>$class){require_once database_path('migrations/'.$file);(new $class)->up();}
DB::table('users')->insert(['id'=>4,'name'=>'Branch administrator','account_type'=>'admin','owner_resturant_id'=>100]);
foreach(['f:100','f:101'] as $index=>$branch){
    DB::table('branch_employees')->insert(['branch'=>$branch,'name'=>'Employee '.$branch,'job_title'=>'Cashier','hired_on'=>'2026-10-01','actor_id'=>1]);
    DB::table('branch_delivery_companies')->insert(['branch'=>$branch,'name'=>'Company '.$branch,'phone'=>'0100000000'.($index+1),'actor_id'=>1]);
    DB::table('branch_customers')->insert(['branch'=>$branch,'name'=>'Customer '.$branch,'phone_key'=>'0100000000'.($index+1),'phone'=>'0100000000'.($index+1),'address'=>'Address','actor_id'=>1]);
}
$services=['employees'=>app(BranchPayroll::class),'delivery companies'=>app(DeliveryCompanies::class),'customers'=>app(BranchCustomers::class),'expenses'=>app(BranchExpenses::class)];
foreach($services as $name=>$service){
    foreach([1,2] as $id){
        $implicit=$service->listing([],actor($id));$explicit=$service->listing(['branch'=>'all'],actor($id));
        check(array_column($implicit['items'],'id')===array_column($explicit['items'],'id'),$name.' omitted branch matches all branches for administrator '.$id);
        check(array_column($implicit['branches'],'value')===['f:100','f:101'],$name.' defaults include both accessible branches');
        $selected=$service->listing(['branch'=>'f:101'],actor($id));
        check(array_column($selected['branches'],'value')===['f:101']&&count($selected['items'])>0,$name.' honors an explicit branch');
    }
    foreach([4,10,12] as $id){
        denied(fn()=>$service->listing([],actor($id)),422,$name.' requires branch for a restricted account '.$id);
        denied(fn()=>$service->listing(['branch'=>'all'],actor($id)),403,$name.' denies all branches for a restricted account '.$id);
        $selected=$service->listing(['branch'=>'f:100'],actor($id));
        check(array_column($selected['branches'],'value')===['f:100'],$name.' keeps restricted branch visibility');
    }
    foreach(['',null] as $invalid)denied(fn()=>$service->listing(['branch'=>$invalid],actor(1)),422,$name.' does not widen an invalid explicit branch');
    $stale=actor(2);DB::table('users')->where('id',2)->update(['owner_resturant_id'=>100]);
    denied(fn()=>$service->listing([],$stale),422,$name.' rechecks current branch binding before defaulting');
    denied(fn()=>$service->listing(['branch'=>'all'],$stale),403,$name.' rejects all after reassignment');
    DB::table('users')->where('id',2)->update(['owner_resturant_id'=>null]);
}

// Registry mode also includes customers who have not bought from a branch yet.
require_once database_path('migrations/2026_10_17_000001_create_crm_customer_directory.php');(new CreateCrmCustomerDirectory)->up();
foreach(['f:100','f:101',''] as $index=>$branch){
    $id=DB::table('crm_customers')->insertGetId(['phone'=>'+20100000000'.($index+1),'phone_key'=>'0100000000'.($index+1),'name'=>'Registry '.$index,'first_seen_at'=>now('UTC'),'last_seen_at'=>now('UTC')]);
    DB::table('crm_customer_sources')->insert(['customer_id'=>$id,'branch'=>$branch,'channel'=>$branch===''?'app':'delivery','first_seen_at'=>now('UTC'),'last_seen_at'=>now('UTC')]);
}
$registry=app(BranchCustomers::class);
check($registry->listing([],actor(1))['pagination']['total']===3,'customer registry defaults include branch and unassigned app customers');
check($registry->listing(['branch'=>'f:100'],actor(1))['pagination']['total']===1,'customer registry keeps an explicit branch scoped');
denied(fn()=>$registry->listing(['branch'=>'all'],actor(4)),403,'customer registry does not widen a branch-bound administrator');

$writes=[
    fn()=>app(BranchPayroll::class)->employeeSave(['branch'=>'all','idempotency_key'=>keyFor(9100),'name'=>'New','job_title'=>'Cashier','hired_on'=>'2026-10-01','active'=>true,'salary'=>'1000.00','effective_month'=>'2026-10'],actor(1)),
    fn()=>app(DeliveryCompanies::class)->save(['branch'=>'all','idempotency_key'=>keyFor(9101),'name'=>'New','phone'=>'01000000001','active'=>true],actor(1)),
    fn()=>$registry->save(['branch'=>'all','idempotency_key'=>keyFor(9102),'name'=>'New','phone'=>'01000000004','address'=>'Address'],actor(1)),
    fn()=>app(BranchExpenses::class)->save(payload(9103,['branch'=>'all']),actor(1)),
];
$before=databaseSnapshot();
foreach($writes as $write){
    try{$write();throw new RuntimeException('Expected branch validation');}
    catch(ValidationException $error){check(isset($error->errors()['branch']),'writes reject all as their destination branch');}
}
check(databaseSnapshot()===$before&&DB::table('branch_operation_commands')->count()===0,'default all-mode reads never authorize writes across branches');
echo ($checks-$startChecks).' all-branch operations checks passed'.PHP_EOL;
