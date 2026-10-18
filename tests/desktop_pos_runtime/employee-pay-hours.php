<?php
// Disposable SQLite only. Reuse the attendance/part-time regression suite and real services.
use App\Services\Dashboard\EmployeePayHours;
use App\Services\GoServices\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__.'/employee-part-time.php';
if(!class_exists('AddEmployeePayHours'))require dirname(__DIR__,2).'/database/migrations/2026_10_18_180000_add_employee_pay_hours.php';

clock('2026-10-31 18:00:00');
$closedBefore=DB::table('branch_payrolls')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
(new AddEmployeePayHours)->up();
$historyBefore=DB::table('branch_employee_pay_hours')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
(new AddEmployeePayHours)->up();
check(DB::table('branch_employee_pay_hours')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all()===$historyBefore,'pay-hours migration rerun does not rewrite contract history');
check(DB::table('branch_payrolls')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all()===$closedBefore,'pay-hours migration preserves every stored closed payroll snapshot');

foreach([[12,102],[13,103]] as [$userId,$branchId]){
    DB::table('users')->insert(['id'=>$userId,'name'=>'Hours actor '.$userId,'account_type'=>'vendor']);
    DB::table('resturants')->insert(['id'=>$branchId,'user_id'=>$userId,'name'=>'Hours branch '.$branchId]);
}

function payEmployee(string $name,array $changes=[]): int {
    global $s,$owner;
    $payload=partTimePayload($name,array_replace(['branch'=>'f:102','effective_month'=>'2026-10'],$changes));
    return (int)$s->employeeSave($payload,$owner)['employee']['id'];
}
function paySave(int $id,array $changes=[]): array {
    global $s,$owner;
    $row=DB::table('branch_employees')->where('id',$id)->first();
    $month=$changes['effective_month']??'2026-10';
    $salary=DB::table('branch_employee_salaries')->where('employee_id',$id)->where('effective_month','<=',$month)->orderByDesc('effective_month')->value('amount_cents');
    return $s->employeeSave(partTimePayload($row->name,array_replace([
        'branch'=>$row->branch,'employee_id'=>$id,'expected_revision'=>$row->revision,
        'hired_on'=>$row->hired_on,'left_on'=>$row->left_on,'effective_month'=>$month,
        'salary'=>Money::decimal((int)$salary),'schedule_type'=>$row->schedule_type,
        'attendance_start'=>$row->attendance_start?substr($row->attendance_start,0,5):null,
        'attendance_end'=>$row->attendance_end?substr($row->attendance_end,0,5):null,
        'attendance_shift'=>$row->attendance_shift,
    ],$changes)),$owner);
}
function payStatement(int $id,string $month='2026-10'): array {
    global $s,$owner;
    $branch=DB::table('branch_employees')->where('id',$id)->value('branch');
    return $s->statement(['branch'=>$branch,'employee_id'=>$id,'month'=>$month],$owner)['statement'];
}
function payMark(int $id,string $status,string $action,string $day): array {
    global $s,$owner;
    $row=DB::table('branch_employees')->where('id',$id)->first();
    $old=DB::table('branch_employee_days')->where('employee_id',$id)->where('day',$day)->first();
    return $s->attendance(['branch'=>$row->branch,'employee_id'=>$id,'day'=>$day,'status'=>$status,'action'=>$action,
        'expected_revision'=>$old->revision??null,'idempotency_key'=>attendanceKey()],$owner)['attendance'];
}
function payWorkPayload(string $branch,string $month,string $hours): array {
    $v=['branch'=>$branch,'setting'=>'work_hours','effective_month'=>$month,'work_hours'=>$hours,'idempotency_key'=>attendanceKey()];
    $revision=EmployeePayHours::branch($branch,$month)['revision'];
    if($revision)$v['expected_revision']=$revision;
    return $v;
}

check(EmployeePayHours::scheduledMinutes(['schedule_type'=>'branch'])===null,'branch schedule follows its monthly full-time hours');
check(EmployeePayHours::scheduledMinutes(['schedule_type'=>'part_time','attendance_start'=>'19:10:00','attendance_end'=>'01:00:00'])===350,'overnight custom hours use wall-clock minutes across midnight');
check(EmployeePayHours::scheduledMinutes((object)['schedule_type'=>'part_time','attendance_start'=>'01:00','attendance_end'=>'05:00'])===240,'after-midnight custom hours retain their four-hour duration');
$defaultHours=EmployeePayHours::branch('f:102','2026-10');
check($defaultHours['full_time_minutes']===600&&$defaultHours['work_hours']==='10'&&$defaultHours['revision']===0,'unconfigured branches default to ten daily work hours');
$tenHours=EmployeePayHours::calculate(710000,31,31,600,600,0);
check($tenHours['effective_monthly_salary']==='7100.00'&&$tenHours['hours_deduction_cents']===0,'ten assigned hours retain the complete base salary');
$overnightHours=EmployeePayHours::calculate(710000,31,31,600,350,0);
check($overnightHours['effective_monthly_salary']==='4141.67'&&$overnightHours['hours_deduction']==='2958.33','five hours fifty minutes prorate a 7100 monthly salary once to cents');
$longHours=EmployeePayHours::calculate(310000,31,31,600,720,0);
check($longHours['effective_monthly_salary']==='3100.00'&&$longHours['hours_deduction_cents']===0,'custom hours above the full-time baseline cannot increase salary or create a negative deduction');
$fraction=EmployeePayHours::calculate(310001,10,31,600,330,0);
check($fraction['effective_monthly_salary']==='1705.01'&&$fraction['hours_deduction_cents']===45000,'fractional base salary and five-and-a-half hours round the final accrued deduction once');
$noDays=EmployeePayHours::calculate(310000,0,31,600,300,0);
check($noDays['hours_deduction_cents']===0,'a future contract with no accrued days has no hours deduction');

$full=payEmployee('Hours full ten',['salary'=>'7100.00','attendance_end'=>'04:00']);
$fullStatement=payStatement($full);
check($fullStatement['salary']==='7100.00'&&$fullStatement['net']==='7100.00'&&$fullStatement['hours_deduction']==='0.00','full-time equivalent custom shifts leave saved salary and net unchanged');
$nightHours=payEmployee('Hours nineteen ten',['salary'=>'7100.00','attendance_start'=>'19:10','attendance_end'=>'01:00']);
$nightStatement=payStatement($nightHours);
check($nightStatement['full_time_minutes']===600&&$nightStatement['scheduled_minutes']===350&&$nightStatement['net']==='4141.67','overnight 19:10 to 01:00 contract earns 4141.67 for a full month');
check($nightStatement['salary']==='7100.00'&&$nightStatement['earned_salary']==='7100.00'&&$nightStatement['hours_deduction']==='2958.33'&&$nightStatement['deduction']==='0.00','contract reduction is separate from recorded salary and attendance/manual deduction ledger');
check(!DB::table('branch_employee_days')->where('employee_id',$nightHours)->exists(),'contract hours accrue correctly even before an employee records attendance');
$five=payEmployee('Hours five');
check(payStatement($five)['net']==='1550.00','five assigned hours earn half the ten-hour monthly base');

// Monthly history, rather than the mutable attendance editor or its snapshots, controls pay.
$futureHours=payEmployee('Hours future contract',['salary'=>'7100.00','attendance_end'=>'04:00']);
paySave($futureHours,['effective_month'=>'2026-11','attendance_end'=>'23:00']);
$futureRow=DB::table('branch_employees')->where('id',$futureHours)->first();
check(EmployeePayHours::employee($futureRow,'2026-10')===600&&EmployeePayHours::employee($futureRow,'2026-11')===300,'a November five-hour contract preserves October ten-hour pay history');
check(payStatement($futureHours)['net']==='7100.00','saving a future shorter schedule does not recalculate current month pay');
$attendanceRules=DB::table('branch_attendance_rules')->where('branch','f:100')->get();
foreach(['f:102','f:103'] as $branch)foreach($attendanceRules as $r){$copy=(array)$r;unset($copy['id']);$copy['branch']=$branch;DB::table('branch_attendance_rules')->insert($copy);}
$futurePunch=payMark($futureHours,'part_time','check_in','2026-10-31');
check(json_decode($futurePunch['attendance_rule_snapshot'],true)['ends_at']==='23:00'&&payStatement($futureHours)['net']==='7100.00','new shorter attendance snapshot cannot override an earlier monthly pay contract');
paySave($futureHours,['effective_month'=>'2026-10','attendance_end'=>'23:00']);
check(EmployeePayHours::employee(DB::table('branch_employees')->where('id',$futureHours)->first(),'2026-10')===300&&payStatement($futureHours)['net']==='3550.00','saving unchanged editor hours into another effective month updates that month contract');
paySave($five,['effective_month'=>'2026-11','schedule_type'=>'branch']);
$followRow=DB::table('branch_employees')->where('id',$five)->first();
check(EmployeePayHours::employee($followRow,'2026-10')===300&&EmployeePayHours::employee($followRow,'2026-11')===null,'switching to branch hours in November retains the October custom contract');
check(payStatement($five)['net']==='1550.00','clearing the current custom schedule preserves a preceding monthly payroll');

// Work-hour settings have independent owner authorization, month history and revision checks.
$eightPayload=payWorkPayload('f:103','2026-10','8');
denied(fn()=>$s->saveAttendanceRules($eightPayload,AttendanceTestUser::find(13)),403,'branch manager cannot change full-time work hours');
denied(fn()=>$s->saveAttendanceRules($eightPayload,AttendanceTestUser::find(2)),403,'administrative admin cannot change owner-only work hours');
$s->saveAttendanceRules($eightPayload,$owner);
check($s->saveAttendanceRules($eightPayload,$owner)['replayed'],'repeating branch work-hours save is idempotent');
check(DB::table('branch_work_hours')->where('branch','f:103')->where('effective_month','2026-10')->count()===1&&EmployeePayHours::branch('f:103','2026-10')['full_time_minutes']===480,'one eight-hour contract is stored for the chosen branch and month');
check(EmployeePayHours::branch('f:103','2026-09')['full_time_minutes']===600&&EmployeePayHours::branch('f:102','2026-10')['full_time_minutes']===600,'branch work-hour changes preserve prior months and other branches');
foreach(['0','24.01','25','8.01','8:30','-1','abc'] as $invalid)denied(fn()=>$s->saveAttendanceRules(payWorkPayload('f:103','2026-11',$invalid),$owner),422,'invalid or fractional-minute work hours are rejected: '.$invalid);
denied(fn()=>$s->saveAttendanceRules(array_replace(payWorkPayload('f:103','2026-10','9'),['expected_revision'=>99]),$owner),409,'stale work-hour settings cannot overwrite the month contract');
$branchWorker=payEmployee('Hours branch follower',['branch'=>'f:103','schedule_type'=>'branch']);
$branchPart=payEmployee('Hours five of eight',['branch'=>'f:103']);
check(payStatement($branchWorker)['scheduled_minutes']===480&&payStatement($branchWorker)['net']==='3100.00','employee following branch hours retains full pay after changing the branch baseline');
check(payStatement($branchPart)['full_time_minutes']===480&&payStatement($branchPart)['effective_monthly_salary']==='1937.50'&&payStatement($branchPart)['net']==='1937.50','five-hour employee is prorated against the configured eight-hour branch baseline');
$s->saveAttendanceRules(payWorkPayload('f:103','2026-11','12'),$owner);
check(EmployeePayHours::branch('f:103','2026-10')['full_time_minutes']===480&&EmployeePayHours::branch('f:103','2026-11')['full_time_minutes']===720,'future branch contract leaves current month baseline unchanged');
$s->saveAttendanceRules(payWorkPayload('f:103','2026-12','8.5'),$owner);
check(EmployeePayHours::branch('f:103','2026-12')['full_time_minutes']===510,'decimal branch work hours store exact complete minutes');
$workListing=$s->listing(['branch'=>'f:103','month'=>'2026-10'],$owner);
check(($workListing['work_hours']['f:103']??null)===EmployeePayHours::branch('f:103','2026-10'),'employee listing publishes selected-month work-hour settings for each accessible branch');

// Accrual days remain independent of punches; active leave is charged exactly once.
clock('2026-10-10 18:00:00');
$leaveHours=payEmployee('Hours accrued leave');
$beforeLeave=payStatement($leaveHours);
check($beforeLeave['eligible_days']===10&&$beforeLeave['earned_salary']==='1000.00'&&$beforeLeave['hours_deduction']==='500.00'&&$beforeLeave['net']==='500.00','ten eligible days earn half base pay for a five-hour contract without attendance');
payMark($leaveHours,'preapproved_leave','set_status','2026-10-10');
$activeLeave=payStatement($leaveHours);
check($activeLeave['deduction']==='100.00'&&$activeLeave['hours_deduction']==='450.00'&&$activeLeave['net']==='450.00','active full-day leave excludes that day planned-hours shortfall to prevent double charging');
$leaveEntry=DB::table('branch_employee_entries')->where('employee_id',$leaveHours)->where('source_key','attendance.leave')->first();
$s->voidEntry(['branch'=>'f:102','entry_id'=>$leaveEntry->id,'expected_revision'=>$leaveEntry->revision,'reason'=>'اعتماد إلغاء خصم الإجازة','idempotency_key'=>attendanceKey()],$owner);
$voidLeave=payStatement($leaveHours);
check($voidLeave['deduction']==='0.00'&&$voidLeave['hours_deduction']==='500.00'&&$voidLeave['net']==='500.00','voided leave deduction no longer exempts its day from the planned-hours reduction');
$lateHours=payEmployee('Hours actual lateness');
clock('2026-10-10 18:30:00');payMark($lateHours,'part_time','check_in','2026-10-10');
$lateHoursStatement=payStatement($lateHours);
check($lateHoursStatement['hours_deduction']==='500.00'&&$lateHoursStatement['deduction']==='10.00'&&$lateHoursStatement['net']==='490.00','actual lateness remains a separate penalty in addition to the planned contract reduction');
clock('2026-10-10 22:30:00');payMark($lateHours,'part_time','check_out','2026-10-10');
check(payStatement($lateHours)['hours_deduction']==='500.00'&&payStatement($lateHours)['deduction']==='15.00'&&payStatement($lateHours)['net']==='485.00','early departure adds its existing penalty without reducing contract hours again');
foreach(['bonus'=>'200.00','advance'=>'100.00','deduction'=>'50.00'] as $kind=>$amount)$s->entry(['branch'=>'f:102','employee_id'=>$lateHours,'day'=>'2026-10-10','kind'=>$kind,'amount'=>$amount,'reason'=>'حركة مستقلة عن ساعات الدوام','idempotency_key'=>attendanceKey()],$owner);
$adjustedHours=payStatement($lateHours);
check($adjustedHours['bonus']==='200.00'&&$adjustedHours['advance']==='100.00'&&$adjustedHours['deduction']==='65.00'&&$adjustedHours['net']==='535.00','bonus advance and manual deduction each adjust hours-based net exactly once');
$absenceHours=payEmployee('Hours absence penalty');payMark($absenceHours,'unauthorized_absence','set_status','2026-10-10');
check(payStatement($absenceHours)['hours_deduction']==='500.00'&&payStatement($absenceHours)['deduction']==='300.00'&&payStatement($absenceHours)['net']==='200.00','Owner-configured absence remains a fixed separate penalty from contractual hours');
$hireHours=payEmployee('Hours employment clipping',['hired_on'=>'2026-10-05','left_on'=>'2026-10-08']);
check(payStatement($hireHours)['eligible_days']===4&&payStatement($hireHours)['hours_deduction']==='200.00'&&payStatement($hireHours)['net']==='200.00','planned-hours deduction uses only accrued employment days between hiring and departure');
clock('2026-10-11 05:59:59');
check(payStatement($leaveHours)['eligible_days']===10&&payStatement($leaveHours)['hours_deduction']==='500.00','planned-hours accrual stays on the preceding operating day before six');
clock('2026-10-11 06:00:00');
check(payStatement($leaveHours)['eligible_days']===11&&payStatement($leaveHours)['hours_deduction']==='550.00'&&payStatement($leaveHours)['net']==='550.00','planned-hours reduction and base accrual advance together at six');
clock('2028-02-10 18:00:00');
$leapHours=payEmployee('Hours leap month',['salary'=>'2900.00','effective_month'=>'2028-02']);
check(payStatement($leapHours,'2028-02')['eligible_days']===10&&payStatement($leapHours,'2028-02')['hours_deduction']==='500.00'&&payStatement($leapHours,'2028-02')['net']==='500.00','leap-month hours reduction uses twenty-nine actual month days');

// Preview facts and locked statements retain all new salary inputs.
clock('2026-10-31 18:00:00');
$reviewHours=payEmployee('Hours desktop review');
$reviewStatement=payStatement($reviewHours);
$reviewValues=['branch'=>'f:102','employee_id'=>$reviewHours,'month'=>'2026-10','preview_hash'=>$reviewStatement['preview_hash']];
$withoutDesktop=getenv('ATTENDANCE_WITHOUT_DESKTOP')==='1';
if($withoutDesktop){
    foreach(['desktopReview','desktopFacts','reconcileDesktop'] as $method)check(!method_exists($s,$method),'scoped live payroll preserves the absence of '.$method);
    $closedHours=$s->close($reviewValues+['idempotency_key'=>attendanceKey()],$owner)['statement'];
}else{
    $review=$s->desktopReview($reviewValues,$owner);
    check(($review['period']['hours_deduction_cents']??null)===155000&&($review['period']['full_time_minutes']??null)===600&&($review['period']['scheduled_minutes']??null)===300,'desktop payroll review captures the hours reduction and its contract inputs');
    $changedReview=$review;$changedReview['period']['hours_deduction_cents']++;
    denied(fn()=>$s->reconcileDesktop($reviewValues+['idempotency_key'=>attendanceKey()],$changedReview,$owner),409,'desktop reconciliation rejects changed hours-based payroll facts');
    $closedHours=$s->reconcileDesktop($reviewValues+['idempotency_key'=>attendanceKey()],$review,$owner)['statement'];
}
check($closedHours['status']==='closed'&&$closedHours['net']==='1550.00'&&$closedHours['hours_deduction']==='1550.00',$withoutDesktop?'original payroll preview closes exactly the reviewed hours-based salary':'unchanged desktop review closes exactly the reviewed hours-based salary');
denied(fn()=>paySave($reviewHours,['attendance_end'=>'22:00']),409,'closed payroll rejects retrospective employee contract-hour changes');
paySave($reviewHours,['effective_month'=>'2026-11','attendance_end'=>'22:00']);
check(payStatement($reviewHours)['preview_hash']===$closedHours['preview_hash']&&payStatement($reviewHours)['net']==='1550.00','future employee hours cannot change a previously closed statement');
$branchClose=payStatement($branchPart);
$s->close(['branch'=>'f:103','employee_id'=>$branchPart,'month'=>'2026-10','preview_hash'=>$branchClose['preview_hash'],'idempotency_key'=>attendanceKey()],$owner);
denied(fn()=>$s->saveAttendanceRules(payWorkPayload('f:103','2026-10','9'),$owner),409,'closed branch payroll prevents retrospective full-time-hour changes');
$s->saveAttendanceRules(payWorkPayload('f:103','2026-11','11'),$owner);
check(payStatement($branchPart)['preview_hash']===$branchClose['preview_hash']&&payStatement($branchPart)['net']==='1937.50','later branch work-hour settings preserve a closed prior-month statement');

// A pre-existing custom schedule has no reliable historical pay contract before installation.
$legacyHours=payEmployee('Hours legacy migration',['effective_month'=>'2026-01']);
DB::table('branch_employee_pay_hours')->where('employee_id',$legacyHours)->delete();
(new AddEmployeePayHours)->up();
$legacyRow=DB::table('branch_employees')->where('id',$legacyHours)->first();
check(DB::table('branch_employee_pay_hours')->where('employee_id',$legacyHours)->value('effective_month')==='2026-10'&&EmployeePayHours::employee($legacyRow,'2026-10')===300,'migration initializes a legacy custom employee only in the installation operating month');
check(EmployeePayHours::employee($legacyRow,'2026-09')===null&&payStatement($legacyHours,'2026-09')['net']==='3100.00','unknown older custom schedules are not reconstructed from the current employee editor');
// Simulate an old caller creating a custom employee after up() but before its replacement.
$installWindowHours=payEmployee('Hours installer creation window',['attendance_start'=>'18:00','attendance_end'=>'05:00']);
DB::table('branch_employee_pay_hours')->where('employee_id',$installWindowHours)->delete();
$beforeFinalSeed=DB::table('branch_employee_pay_hours')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
(new AddEmployeePayHours)->seedCurrentContracts();
$installWindowContract=DB::table('branch_employee_pay_hours')->where('employee_id',$installWindowHours)->first();
check($installWindowContract->effective_month==='2026-10'&&(int)$installWindowContract->scheduled_minutes===660,'post-install seed catches an employee created during replacement and stores its raw custom minutes');
check(DB::table('branch_employee_pay_hours')->where('employee_id','<>',$installWindowHours)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all()===$beforeFinalSeed,'post-install seed preserves every already-recorded monthly contract');
$allHistory=DB::table('branch_employee_pay_hours')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
clock('2026-11-30 18:00:00');(new AddEmployeePayHours)->up();
check(DB::table('branch_employee_pay_hours')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all()===$allHistory,'migration rerun in another month cannot add baselines over existing contracts');
check(DB::table('branch_payrolls')->whereIn('id',array_column($closedBefore,'id'))->orderBy('id')->get()->map(fn($row)=>(array)$row)->all()===$closedBefore,'migration reruns after contract edits still preserve the original frozen payroll rows');

// An older offline database must not accept custom hours without recording their pay contract.
$beforeMissingHistory=(array)DB::table('branch_employees')->where('id',$installWindowHours)->first();
Illuminate\Support\Facades\Schema::rename('branch_employee_pay_hours','branch_employee_pay_hours_unavailable');
try{
    denied(fn()=>paySave($installWindowHours,['effective_month'=>'2026-11','attendance_end'=>'04:00']),503,'explicit schedule save requires installed pay-hours history');
    check((array)DB::table('branch_employees')->where('id',$installWindowHours)->first()===$beforeMissingHistory,'missing pay-hours schema leaves employee schedule and revision unchanged');
}finally{
    Illuminate\Support\Facades\Schema::rename('branch_employee_pay_hours_unavailable','branch_employee_pay_hours');
}

Carbon::setTestNow();
echo $count.' attendance, part-time and contract pay-hours checks passed'.PHP_EOL;
