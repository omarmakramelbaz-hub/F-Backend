<?php
// Current production integrations must retain branch scoping and the live consent contract.
use App\Services\Dashboard\{DesktopDashboardData,DesktopDashboardCommands,EmployeePayHours};
$scoped=app(DesktopDashboardData::class)->queries((object)['id'=>$device],$actor,['f:100']);
foreach(['branch_work_hours','branch_employee_pay_hours','branch_expense_category_overrides','branch_order_sequences','branch_stock_minimums','crm_customer_sources','crm_purchases'] as $table){
    check(isset($scoped[$table])&&str_contains($scoped[$table]->toSql(),'`branch` in (?)')&&$scoped[$table]->getBindings()===['f:100'],'current table '.$table.' is restricted even for the central owner');
}
check(str_contains($scoped['branch_expense_categories']->toSql(),'`origin_branch` is null')&&$scoped['branch_expense_categories']->getBindings()===['f:100'],'new branch categories exclude foreign origins while retaining historical shared names');
check(isset($scoped['branch_basic_recipes']),'the immutable base recipe is available in the local dataset');
check(str_contains($scoped['crm_customers']->toSql(),'crm_customer_sources')&&str_contains($scoped['crm_customers']->toSql(),'crm_purchases')&&$scoped['crm_customers']->getBindings()===['f:100','f:100'],'CRM imports identities through enrolled branch links only');
check($scoped['branch_stock_minimum_alerts']->count()===0&&$scoped['branch_shift_print_jobs']->count()===0,'provider alerts and existing print claims are never imported as pending local work');
check(EmployeePayHours::calculate(600000,30,30,600,300,0)['effective_monthly_salary']==='3000.00','part-time salary retains the current branch-hours formula');
$withoutConsent=['branch'=>'f:100','idempotency_key'=>(string)\Illuminate\Support\Str::uuid(),'items'=>[['product_id'=>1,'option_id'=>'','quantity_mode'=>'weight','quantity'=>'0.250']],'discount'=>'0.00','payment_method'=>'cash','cash_received'=>'100.00','customer_phone'=>'01012345678'];
$withoutConsent['quote_hash']=app(\App\Services\Dashboard\TakeawayService::class)->quote($withoutConsent,$actor)['quote_hash'];
denied(fn()=>app(DesktopDashboardCommands::class)->execute('takeaway.checkout',['values'=>$withoutConsent],$actor),422,'desktop replay enforces the current service consent checkbox');
check(\Illuminate\Support\Facades\DB::table('takeaway_orders')->count()===0,'a missing consent creates no receipt or CRM purchase');

// Roll back this focused stock-setting probe so the end-to-end ledger starts clean.
\Illuminate\Support\Facades\DB::beginTransaction();
try {
    $minimum=['branch'=>'f:100','idempotency_key'=>(string)\Illuminate\Support\Str::uuid(),'stock_type'=>'ingredient','stock_item_id'=>6,'minimum_quantity'=>'1.000','expected_revision'=>0];
    $first=app(DesktopDashboardCommands::class)->execute('branch-stock.minimum-save',['values'=>$minimum],$actor);
    $retry=app(DesktopDashboardCommands::class)->execute('branch-stock.minimum-save',['values'=>$minimum],$actor);
    check($first['stock']===$retry['stock']&&$first['operation']===$retry['operation']&&\Illuminate\Support\Facades\DB::table('branch_stock_minimums')->count()===1,'stock minimum replay uses the original idempotent branch operation');
    $stale=$minimum;$stale['idempotency_key']=(string)\Illuminate\Support\Str::uuid();
    denied(fn()=>app(DesktopDashboardCommands::class)->execute('branch-stock.minimum-save',['values'=>$stale],$actor),409,'a stale stock minimum revision cannot overwrite a newer setting');
    denied(fn()=>app(DesktopDashboardCommands::class)->execute('branch-stock.minimum-save',['values'=>$minimum],$cashier),403,'a cashier cannot acquire inventory management permissions offline');
} finally { \Illuminate\Support\Facades\DB::rollBack(); }

$localSchedule=new \Illuminate\Console\Scheduling\Schedule();
$scheduleMethod=new \ReflectionMethod(\App\Console\Kernel::class,'schedule');$scheduleMethod->setAccessible(true);
$scheduleMethod->invoke(app(\Illuminate\Contracts\Console\Kernel::class),$localSchedule);
check(count($localSchedule->events())===0,'the local application registers no central scheduler or provider dispatch');
