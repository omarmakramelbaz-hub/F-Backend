<?php
// Disposable in-memory SQLite only; exercises the original payroll service and migrations.
require __DIR__.'/vendor/autoload.php';
date_default_timezone_set('Africa/Cairo');
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Facade,DB,Schema};
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Services\Dashboard\{BranchPayroll, EmployeeAttendanceRules, EmployeeAttendanceDeductionCap, OperatingDay, DesktopDashboardReferences};
function app($key=null){$c=Container::getInstance();return $key?$c->make($key):$c;}
function now($zone=null){return Carbon::now($zone);}
function config($key=null,$default=null){return app('config')->get($key,$default);}
function abort_unless($v,$code,$message=''){if(!$v)throw new HttpException($code,$message);}
function abort_if($v,$code,$message=''){if($v)throw new HttpException($code,$message);}
function abort($code,$message=''){throw new HttpException($code,$message);}
class AttendanceTestUser extends Illuminate\Database\Eloquent\Model {protected $table='users';public function can($name){return $this->id===2;}}
class_alias(AttendanceTestUser::class,'App\\Models\\User');
$c=new Container;Container::setInstance($c);$c->instance('config',new Illuminate\Config\Repository(['app'=>['timezone'=>'Africa/Cairo']]));
$capsule=new Capsule($c);$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);$capsule->setAsGlobal();$capsule->bootEloquent();
$c->instance('db',$capsule->getDatabaseManager());$c->bind('db.schema',fn()=>DB::connection()->getSchemaBuilder());Facade::setFacadeApplication($c);
$translator=new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader,'en');$c->instance('validator',new Illuminate\Validation\Factory($translator,$c));
Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->string('name');});
Schema::create('permissions',function(Blueprint $t){$t->id();$t->string('guard_name');$t->string('name');});
Schema::create('pos_service_tickets',fn(Blueprint $t)=>$t->id());
foreach(['2026_10_04_080000_create_branch_operations.php'=>'CreateBranchOperations','2026_10_06_140000_add_employee_wallet_phone.php'=>'AddEmployeeWalletPhone','2026_10_08_190000_add_employee_attendance_rules.php'=>'AddEmployeeAttendanceRules'] as $file=>$class){require dirname(__DIR__,2).'/database/migrations/'.$file;(new $class)->up();}
foreach([[1,'admin'],[2,'admin'],[10,'vendor'],[11,'vendor']] as [$id,$type])DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type]);
DB::table('permissions')->insert(['guard_name'=>'admin','name'=>'order-list']);
DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'Main'],['id'=>101,'user_id'=>11,'name'=>'Other']]);
$owner=AttendanceTestUser::find(1);$manager=AttendanceTestUser::find(10);$s=app(BranchPayroll::class);$count=0;
function check($v,$message){global $count;if(!$v)throw new RuntimeException($message);$count++;echo 'PASS '.$message.PHP_EOL;}
function denied(callable $fn,int $code,string $message){try{$fn();}catch(HttpException $e){check($e->getStatusCode()===$code,$message);return;}catch(Illuminate\Validation\ValidationException $e){check($code===422,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
function attendanceKey(){return Illuminate\Support\Str::uuid()->toString();}
function clock($time){Carbon::setTestNow(Carbon::parse($time,'Africa/Cairo'));}
function employee($name,$salary='3100.00',$hired='2026-01-01',$left=null){global $s,$owner;return $s->employeeSave(['branch'=>'f:100','name'=>$name,'job_title'=>'كاشير','shift'=>'صباحي','hired_on'=>$hired,'left_on'=>$left,'effective_month'=>'2026-01','salary'=>$salary,'active'=>true,'idempotency_key'=>attendanceKey()],$owner)['employee']['id'];}
function mark($id,$status='morning',$action='check_in',$day='2026-10-08',$extra=[]){global $s,$manager;$old=DB::table('branch_employee_days')->where('employee_id',$id)->where('day',$day)->first();return $s->attendance($extra+['branch'=>'f:100','employee_id'=>$id,'day'=>$day,'status'=>$status,'action'=>$action,'expected_revision'=>$old->revision??null,'idempotency_key'=>attendanceKey()],$manager);}
function total($id){return (int)DB::table('branch_employee_entries')->where('employee_id',$id)->where('kind','deduction')->whereNull('voided_at')->sum('amount_cents');}
clock('2026-10-08 10:00:00');
$rules=['branch'=>'f:100','morning_start'=>'10:00','morning_end'=>'18:00','morning_late'=>'10.00','morning_early'=>'5.00','evening_start'=>'20:00','evening_end'=>'04:00','evening_late'=>'10.00','evening_early'=>'5.00','absence'=>'300.00','idempotency_key'=>attendanceKey()];
denied(fn()=>$s->saveAttendanceRules($rules,$manager),403,'branch manager cannot set rates');
denied(fn()=>$s->saveAttendanceRules($rules,AttendanceTestUser::find(2)),403,'administrative admin cannot set owner-only rates');
$s->saveAttendanceRules($rules,$owner);check(DB::table('branch_attendance_rules')->count()===2,'owner configures both branch shifts');
check($s->saveAttendanceRules($rules,$owner)['replayed'],'rule save replay has no second revision');
$id=employee('On time');$a=mark($id)['attendance'];check($a['check_in']==='10:00:00'&&$a['checked_in_at']==='2026-10-08 07:00:00','check-in stores server UTC and shows Cairo time');check(total($id)===0,'on-time check-in has no deduction');
denied(fn()=>mark($id,'morning','check_in','2026-10-08',['check_in'=>'08:00']),422,'client cannot forge attendance time');
denied(fn()=>$s->attendance(['branch'=>'f:100','employee_id'=>$id,'day'=>'2026-10-08','status'=>'morning','action'=>'check_in','idempotency_key'=>attendanceKey()],AttendanceTestUser::find(11)),404,'other branch cannot record attendance');
clock('2026-10-08 10:29:59');$short=employee('Short delay');mark($short);check(total($short)===0,'less than a completed half hour is not charged');
clock('2026-10-08 10:30:00');$half=employee('Half hour');$halfResult=mark($half);check(total($half)===1000,'exactly thirty minutes charges one interval');
clock('2026-10-08 11:01:00');$late=employee('Late');$r=mark($late);check(total($late)===2000,'sixty-one minutes charges two intervals');
$s->entry(['branch'=>'f:100','employee_id'=>$late,'day'=>'2026-10-08','kind'=>'deduction','amount'=>'7.00','reason'=>'خصم يدوي','idempotency_key'=>attendanceKey()],$owner);
clock('2026-10-08 12:30:00');mark($late);check(total($late)===2700,'repeat check-in preserves original time and manual entry');
$modified=$rules;$modified['idempotency_key']=attendanceKey();$modified['expected_revision']=1;$modified['morning_late']='99.00';$modified['morning_early']='99.00';$s->saveAttendanceRules($modified,$owner);
clock('2026-10-08 17:00:00');mark($late,'morning','check_out');check(total($late)===3700,'early exit charges two intervals using original shift rates');
clock('2026-10-08 17:30:00');mark($late,'morning','check_out');check(total($late)===3700,'repeat checkout preserves exit time and deduction');
$halfEntry=DB::table('branch_employee_entries')->where('employee_id',$half)->where('source_key','attendance.late')->first();
$s->voidEntry(['branch'=>'f:100','entry_id'=>$halfEntry->id,'expected_revision'=>$halfEntry->revision,'reason'=>'إلغاء معتمد من الأونر','idempotency_key'=>attendanceKey()],$owner);
mark($half);check(total($half)===0,'repeat punch respects an explicit Owner cancellation');
denied(fn()=>$s->saveAttendanceRules(array_replace($modified,['idempotency_key'=>attendanceKey(),'expected_revision'=>1]),$owner),409,'stale Owner settings cannot overwrite a newer revision');
$outputs=app(DesktopDashboardReferences::class)->outputs('employees.attendance',['attendance'=>DB::table('branch_employee_days')->where('employee_id',$late)->first()?(array)DB::table('branch_employee_days')->where('employee_id',$late)->first():[]]);check(isset($outputs['employee_entry_late'],$outputs['employee_entry_early']),'offline references map every automatic payroll entry');
$lockedLate=(array)DB::table('branch_employee_days')->where('employee_id',$late)->where('day','2026-10-08')->first();$lockedEntries=DB::table('branch_employee_entries')->where('employee_id',$late)->get()->map(fn($row)=>(array)$row)->all();
denied(fn()=>mark($late,'unauthorized_absence','set_status'),409,'recorded attendance cannot change to absence after checkout');
denied(fn()=>mark($late,'preapproved_leave','set_status'),409,'recorded attendance cannot change to leave after checkout');
check((array)DB::table('branch_employee_days')->where('employee_id',$late)->where('day','2026-10-08')->first()===$lockedLate&&DB::table('branch_employee_entries')->where('employee_id',$late)->get()->map(fn($row)=>(array)$row)->all()===$lockedEntries,'rejected changes preserve recorded times notes revisions and deductions');
$absence=employee('Absence');$leave=employee('Leave');foreach([$absence,$leave] as $statusEmployee)$s->entry(['branch'=>'f:100','employee_id'=>$statusEmployee,'day'=>'2026-10-08','kind'=>'deduction','amount'=>'7.00','reason'=>'خصم يدوي','idempotency_key'=>attendanceKey()],$owner);
mark($absence,'unauthorized_absence','set_status');check(total($absence)===30700,'first absence records the Owner amount and preserves manual entry');
mark($absence,'unauthorized_absence','set_status');check(total($absence)===30700,'repeated absence cannot duplicate deduction');
mark($leave,'preapproved_leave','set_status');check(total($leave)===10700,'first leave deducts one day of salary');
check(DB::table('branch_employee_entries')->where('employee_id',$leave)->where('source_key','attendance.leave')->value('reason')==='إجازة','leave reason appears in deductions');
$zero=employee('Missing salary');DB::table('branch_employee_salaries')->where('employee_id',$zero)->delete();denied(fn()=>mark($zero,'preapproved_leave','set_status'),422,'leave requires recorded salary');check(!DB::table('branch_employee_days')->where('employee_id',$zero)->exists(),'failed deduction leaves no partial attendance');
denied(fn()=>mark($id,'morning','check_in','2026-10-07'),422,'historical manual check-in cannot use current time');
denied(fn()=>mark($id,'unauthorized_absence','set_status','2026-10-09'),422,'future attendance is rejected using Cairo date');
clock('2026-10-08 20:30:00');$night=employee('Night');mark($night,'evening');clock('2026-10-09 03:30:00');mark($night,'evening','check_out');check(total($night)===1500,'overnight shift uses start day and next-day departure');
$listed=$s->listing(['branch'=>'f:100','day'=>'2026-10-08'],$manager);check($listed['summary']['present']===5&&$listed['summary']['leave']===1&&$listed['summary']['absent']===1,'daily summary includes new morning evening and leave statuses');check(!$listed['can_manage_attendance'],'branch listing hides owner controls');
$statement=$s->statement(['branch'=>'f:100','employee_id'=>$leave,'month'=>'2026-10'],$owner)['statement'];check($statement['deduction']==='107.00'&&$statement['net']==='693.00'&&$statement['eligible_days']===8,'automatic deduction flows into salary accrued for eight operating days');
$s->close(['branch'=>'f:100','employee_id'=>$leave,'month'=>'2026-10','preview_hash'=>$statement['preview_hash'],'idempotency_key'=>attendanceKey()],$owner);denied(fn()=>mark($leave,'preapproved_leave','set_status'),409,'closed payroll prevents new attendance or deductions');
$feb=EmployeeAttendanceRules::deductions('preapproved_leave',[],null,null,290000,29);check($feb['leave']['amount']===10000,'leap-month daily rate uses actual month days');
clock('2026-10-08 11:30:00');$belowCap=employee('Below absence cap');mark($belowCap);check(total($belowCap)===29700,'lateness below the absence cap keeps the calculated deduction');
clock('2026-10-08 12:00:00');$capped=employee('Capped lateness');$cappedAttendance=mark($capped)['attendance'];check(total($capped)===30000,'lateness above the absence cap charges only the absence amount');
$cappedEntry=DB::table('branch_employee_entries')->where('employee_id',$capped)->where('source_key','attendance.late')->whereNull('voided_at')->first();
check($cappedAttendance['status']==='morning'&&$cappedAttendance['check_in']==='12:00:00'&&$cappedEntry->reason==='خصم تأخر عن العمل'&&str_contains($cappedEntry->notes,'الحد الأقصى'),'capped lateness preserves attendance time and explains the limit in deductions');
$s->entry(['branch'=>'f:100','employee_id'=>$capped,'day'=>'2026-10-08','kind'=>'deduction','amount'=>'7.00','reason'=>'خصم يدوي','idempotency_key'=>attendanceKey()],$owner);
clock('2026-10-08 12:30:00');mark($capped);check(total($capped)===30700&&DB::table('branch_employee_entries')->where('employee_id',$capped)->where('source_key','attendance.late')->whereNull('voided_at')->count()===1,'repeat capped check-in preserves manual deductions without duplicating the penalty');
clock('2026-10-08 17:00:00');mark($capped,'morning','check_out');check(total($capped)===30700&&!DB::table('branch_employee_entries')->where('employee_id',$capped)->where('source_key','attendance.early')->whereNull('voided_at')->exists(),'combined lateness and early departure share one daily absence cap');
$cappedStatement=$s->statement(['branch'=>'f:100','employee_id'=>$capped,'month'=>'2026-10'],$owner)['statement'];check($cappedStatement['deduction']==='307.00'&&$cappedStatement['net']==='493.00','capped deduction and manual entries flow into accrued net salary');
$lowerCap=array_replace($modified,['absence'=>'50.00','morning_late'=>'100.00','expected_revision'=>2,'idempotency_key'=>attendanceKey()]);$s->saveAttendanceRules($lowerCap,$owner);
mark($capped);check(total($capped)===30700,'later Owner changes preserve the cap captured when attendance was recorded');
clock('2026-10-08 11:30:00');$newCap=employee('New absence cap');mark($newCap);check(total($newCap)===5000,'new attendance uses the updated Owner absence cap');
$zeroCap=array_replace($lowerCap,['absence'=>'0.00','expected_revision'=>3,'idempotency_key'=>attendanceKey()]);$s->saveAttendanceRules($zeroCap,$owner);
$freeCap=employee('Zero absence cap');$freeAttendance=mark($freeCap)['attendance'];check(total($freeCap)===0&&$freeAttendance['check_in']==='11:30:00','zero absence amount also caps lateness at zero while recording attendance');
$boundary=EmployeeAttendanceRules::snapshot((object)['shift'=>'morning','revision'=>1,'starts_at'=>'10:00','ends_at'=>'18:00','late_half_hour_cents'=>10000,'early_half_hour_cents'=>9900,'absence_cents'=>30000],'2026-10-08');
$equal=EmployeeAttendanceRules::deductions('morning',$boundary,'2026-10-08 08:30:00',null,310000,31);check($equal['late']['amount']===30000,'lateness equal to the absence amount stays exactly at the limit');
$nightCap=EmployeeAttendanceRules::snapshot((object)['shift'=>'evening','revision'=>1,'starts_at'=>'20:00','ends_at'=>'04:00','late_half_hour_cents'=>10000,'early_half_hour_cents'=>9900,'absence_cents'=>30000],'2026-10-08');
$nightDeductions=EmployeeAttendanceRules::deductions('evening',$nightCap,'2026-10-08 19:00:00','2026-10-08 23:00:00',310000,31);check($nightDeductions['late']['amount']===30000&&$nightDeductions['early']['amount']===0,'evening overnight shift shares the absence cap between both penalties');
$split=EmployeeAttendanceRules::deductions('morning',$boundary,'2026-10-08 08:00:00','2026-10-08 13:00:00',310000,31);check($split['late']['amount']===20000&&$split['early']['amount']===10000,'two penalties each below absence still share the combined daily limit');
$screenshotRules=array_replace($zeroCap,['morning_start'=>'10:00','morning_end'=>'19:00','morning_late'=>'50.00','morning_early'=>'50.00','absence'=>'300.00','expected_revision'=>4,'idempotency_key'=>attendanceKey()]);$s->saveAttendanceRules($screenshotRules,$owner);
clock('2026-10-09 06:00:00');$earlyOnly=employee('Screenshot early checkout');mark($earlyOnly,'morning','check_in','2026-10-09');$earlyPunch=mark($earlyOnly,'morning','check_out','2026-10-09')['attendance'];
check(total($earlyOnly)===30000&&$earlyPunch['check_in']==='06:00:00'&&$earlyPunch['check_out']==='06:00:00','early checkout remains capped at absence when a new operating day starts');
$legacyEntry=DB::table('branch_employee_entries')->where('employee_id',$earlyOnly)->where('source_key','attendance.early')->first();
DB::table('branch_employee_entries')->where('id',$legacyEntry->id)->update(['amount_cents'=>175000,'notes'=>'35 نصف ساعة مكتملة.']);
$s->entry(['branch'=>'f:100','employee_id'=>$earlyOnly,'day'=>'2026-10-09','kind'=>'deduction','amount'=>'7.00','reason'=>'خصم يدوي','idempotency_key'=>attendanceKey()],$owner);
$legacyBefore=DB::table('branch_employee_days')->where('employee_id',$earlyOnly)->first();$preview=$s->statement(['branch'=>'f:100','employee_id'=>$earlyOnly,'month'=>'2026-10'],$owner)['statement'];
$s->entry(['branch'=>'f:100','employee_id'=>$capped,'day'=>'2026-10-08','kind'=>'deduction','amount'=>'2.00','reason'=>'خصم يدوي آخر','idempotency_key'=>attendanceKey()],$owner);
DB::table('branch_employee_entries')->insert(['branch'=>'f:100','employee_id'=>$capped,'day'=>'2026-10-08','kind'=>'deduction','amount_cents'=>19800,'source_key'=>'attendance.early','reason'=>'خصم انصراف مبكر','notes'=>'خصم قديم قبل السقف المشترك','revision'=>1,'actor_id'=>$manager->id,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$closedCap=employee('Closed old attendance');mark($closedCap,'morning','check_in','2026-10-09');mark($closedCap,'morning','check_out','2026-10-09');
DB::table('branch_employee_entries')->where('employee_id',$closedCap)->where('source_key','attendance.early')->update(['amount_cents'=>175000,'notes'=>'Closed legacy penalty']);
$closedStatement=$s->statement(['branch'=>'f:100','employee_id'=>$closedCap,'month'=>'2026-10'],$owner)['statement'];$s->close(['branch'=>'f:100','employee_id'=>$closedCap,'month'=>'2026-10','preview_hash'=>$closedStatement['preview_hash'],'idempotency_key'=>attendanceKey()],$owner);
$caps=app(EmployeeAttendanceDeductionCap::class);$dry=$caps->repair(true);check($dry['days']===2&&$dry['entries']===2&&total($earlyOnly)===175700,'cap repair preview identifies old penalties without changing ledger amounts');
$fixed=$caps->repair();check($fixed['days']===2&&$fixed['entries']===2&&total($earlyOnly)===30700&&total($capped)===30900,'repair reduces old early and combined penalties while preserving manual deductions');
check((array)DB::table('branch_employee_days')->where('employee_id',$earlyOnly)->first()===(array)$legacyBefore,'repair preserves recorded attendance time status notes and revision');
$correctedEntry=DB::table('branch_employee_entries')->where('id',$legacyEntry->id)->first();check($correctedEntry->revision===$legacyEntry->revision+1&&$correctedEntry->reason==='خصم انصراف مبكر'&&str_contains($correctedEntry->notes,'الحد الأقصى'),'repair retains deduction reason and records its adjustment');
check(total($closedCap)===175000&&$s->statement(['branch'=>'f:100','employee_id'=>$closedCap,'month'=>'2026-10'],$owner)['statement']['preview_hash']===$closedStatement['preview_hash'],'repair leaves closed payroll and its frozen statement unchanged');
check(!DB::table('branch_employee_entries')->where('id',$halfEntry->id)->whereNull('voided_at')->exists(),'repair respects deductions explicitly cancelled by the Owner');
$fixedStatement=$s->statement(['branch'=>'f:100','employee_id'=>$earlyOnly,'month'=>'2026-10'],$owner)['statement'];check($fixedStatement['deduction']==='307.00'&&$fixedStatement['net']==='593.00','repair refreshes saved daily deductions and accrued monthly net salary');
denied(fn()=>$s->close(['branch'=>'f:100','employee_id'=>$earlyOnly,'month'=>'2026-10','preview_hash'=>$preview['preview_hash'],'idempotency_key'=>attendanceKey()],$owner),409,'old payroll preview cannot close the corrected statement');
$repeatRepair=$caps->repair();check($repeatRepair['days']===0&&$repeatRepair['entries']===0&&DB::table('branch_employee_entries')->where('id',$legacyEntry->id)->value('revision')===$correctedEntry->revision,'repeated cap correction leaves ledger amounts revisions and counts unchanged');
mark($earlyOnly,'morning','check_out','2026-10-09');check(total($earlyOnly)===30700,'repeated checkout cannot restore an oversized repaired penalty');
$zeroLegacy=employee('Zero cap legacy');mark($zeroLegacy,'morning','check_in','2026-10-09');mark($zeroLegacy,'morning','check_out','2026-10-09');
$zeroDay=DB::table('branch_employee_days')->where('employee_id',$zeroLegacy)->first();$zeroSnapshot=json_decode($zeroDay->attendance_rule_snapshot,true);$zeroSnapshot['absence_cents']=0;DB::table('branch_employee_days')->where('id',$zeroDay->id)->update(['attendance_rule_snapshot'=>json_encode($zeroSnapshot)]);
$caps->repair();check(total($zeroLegacy)===0&&!DB::table('branch_employee_entries')->where('employee_id',$zeroLegacy)->whereNull('voided_at')->exists(),'repair removes zero-cap automatic charges from the active deduction count');
clock('2026-10-09 20:00:00');$operatingNight=employee('Operating night shift');mark($operatingNight,'evening','check_in','2026-10-09');
clock('2026-10-10 05:59:59');$overnightOut=mark($operatingNight,'evening','check_out','2026-10-09')['attendance'];
$beforeSix=$s->listing(['branch'=>'f:100'],$manager);check($beforeSix['filters']['day']==='2026-10-09'&&$overnightOut['check_out']==='05:59:59','overnight checkout and default attendance listing remain on the prior operating day before six');
denied(fn()=>mark($operatingNight,'morning','check_in','2026-10-10'),422,'next calendar date cannot be used for attendance before six');
denied(fn()=>$s->entry(['branch'=>'f:100','employee_id'=>$operatingNight,'day'=>'2026-10-10','kind'=>'deduction','amount'=>'1.00','reason'=>'خصم','idempotency_key'=>attendanceKey()],$owner),422,'manual payroll entries cannot use the next operating day before six');
clock('2026-10-10 06:00:00');$afterSix=$s->listing(['branch'=>'f:100'],$manager);check($afterSix['filters']['day']==='2026-10-10'&&$afterSix['operating_day']['start_hour']===6,'default attendance switches at exactly six with the actual operating window');
clock('2026-11-01 05:59:59');$monthBoundary=$s->listing(['branch'=>'f:100'],$manager);check($monthBoundary['filters']['day']==='2026-10-31'&&$monthBoundary['filters']['month']==='2026-10','payroll month stays October until six on the first calendar day of November');
clock('2026-11-01 06:00:00');$newMonth=$s->listing(['branch'=>'f:100'],$manager);check($newMonth['filters']['day']==='2026-11-01'&&$newMonth['filters']['month']==='2026-11','new payroll month begins at six on its first day');
// Accrual is based on elapsed employment days, with leave/absence charged once through the ledger.
function accrued($id,$month='2026-10'){global $s,$owner;return $s->statement(['branch'=>'f:100','employee_id'=>$id,'month'=>$month],$owner)['statement'];}
clock('2026-10-10 10:00:00');$accrual=employee('Ten elapsed days');$ten=accrued($accrual);
check($ten['eligible_days']===10&&$ten['earned_salary']==='1000.00'&&$ten['net']==='1000.00','ten elapsed days earn ten days of salary instead of the full month');
check($ten['as_of']==='2026-10-10'&&$ten['accrued_from']==='2026-10-01'&&$ten['accrued_through']==='2026-10-10','statement exposes the actual accrual dates');
foreach(['bonus'=>'200.00','advance'=>'100.00','deduction'=>'50.00'] as $kind=>$amount)$s->entry(['branch'=>'f:100','employee_id'=>$accrual,'day'=>'2026-10-10','kind'=>$kind,'amount'=>$amount,'reason'=>'حركة حساب المستحقات','idempotency_key'=>attendanceKey()],$owner);
$adjusted=accrued($accrual);check($adjusted['net']==='1050.00','bonus deduction and advance adjust accrued salary');
$leaveAccrualId=employee('Leave accrued days');foreach(['bonus'=>'200.00','advance'=>'100.00','deduction'=>'50.00'] as $kind=>$amount)$s->entry(['branch'=>'f:100','employee_id'=>$leaveAccrualId,'day'=>'2026-10-10','kind'=>$kind,'amount'=>$amount,'reason'=>'حركة حساب المستحقات','idempotency_key'=>attendanceKey()],$owner);
mark($leaveAccrualId,'preapproved_leave','set_status','2026-10-10');mark($leaveAccrualId,'preapproved_leave','set_status','2026-10-10');$leaveAccrued=accrued($leaveAccrualId);
check($leaveAccrued['eligible_days']===10&&$leaveAccrued['deduction']==='150.00'&&$leaveAccrued['net']==='950.00','leave deducts one daily wage once without removing the day twice');
mark($accrual,'unauthorized_absence','set_status','2026-10-10');$absenceAccrued=accrued($accrual);
check($absenceAccrued['eligible_days']===10&&$absenceAccrued['deduction']==='350.00'&&$absenceAccrued['net']==='750.00','absence uses the Owner amount once against elapsed salary');
$frozenAccrual=employee('Closed ten-day accrual');$frozenTen=accrued($frozenAccrual);$s->close(['branch'=>'f:100','employee_id'=>$frozenAccrual,'month'=>'2026-10','preview_hash'=>$frozenTen['preview_hash'],'idempotency_key'=>attendanceKey()],$owner);
clock('2026-10-11 05:59:59');$beforeAccrual=accrued($accrual);check($beforeAccrual['eligible_days']===10&&$beforeAccrual['preview_hash']===$absenceAccrued['preview_hash'],'accrued salary does not advance before the six oclock operating boundary');
clock('2026-10-11 06:00:00');$afterAccrual=accrued($accrual);check($afterAccrual['eligible_days']===11&&$afterAccrual['earned_salary']==='1100.00'&&$afterAccrual['net']==='850.00','accrued salary gains one daily wage exactly at six');
denied(fn()=>$s->close(['branch'=>'f:100','employee_id'=>$accrual,'month'=>'2026-10','preview_hash'=>$absenceAccrued['preview_hash'],'idempotency_key'=>attendanceKey()],$owner),409,'a preview from the preceding operating day cannot close a changed salary');
check(accrued($frozenAccrual)['net']==='1000.00'&&accrued($frozenAccrual)['eligible_days']===10,'closed payroll preserves the accrued amount captured at closing');
clock('2026-10-10 10:00:00');$newHire=employee('Joined on fifth','3100.00','2026-10-05');$newHireStatement=accrued($newHire);
check($newHireStatement['eligible_days']===6&&$newHireStatement['earned_salary']==='600.00','mid-month employment starts accrual on the hiring date');
$departed=employee('Left on eighth','3100.00','2026-10-05','2026-10-08');$departedStatement=accrued($departed);
check($departedStatement['eligible_days']===4&&$departedStatement['earned_salary']==='400.00'&&$departedStatement['accrued_through']==='2026-10-08','salary stops accruing on the recorded leaving date');
$futureHire=employee('Future hire','3100.00','2026-10-20');check(accrued($futureHire)['eligible_days']===0&&accrued($futureHire)['net']==='0.00','employee hired in the future has no accrued salary');
$futureMonth=accrued($newHire,'2026-11');check($futureMonth['eligible_days']===0&&$futureMonth['net']==='0.00','a future payroll month does not earn future days');
$monthEndEmployee=employee('Full past month');$past=accrued($monthEndEmployee,'2026-09');check($past['eligible_days']===30&&$past['earned_salary']==='3100.00','a completed past month retains its full monthly salary');
// Old future-dated records are excluded until their operating day, without deleting them.
DB::table('branch_employee_entries')->insert(['branch'=>'f:100','employee_id'=>$monthEndEmployee,'day'=>'2026-10-20','kind'=>'bonus','amount_cents'=>50000,'reason'=>'حركة مستقبلية قديمة','revision'=>1,'actor_id'=>$owner->id,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$futureEntry=accrued($monthEndEmployee);check($futureEntry['bonus']==='0.00'&&$futureEntry['net']==='1000.00'&&count($futureEntry['entries'])===0,'future ledger entries do not inflate current accrued net');
$listingAccrual=$s->listing(['branch'=>'f:100','search'=>'Ten elapsed days','day'=>'2026-10-01','month'=>'2026-10'],$manager);$exportAccrual=$s->listing(['branch'=>'f:100','search'=>'Ten elapsed days','month'=>'2026-10'],$manager,true);
check($listingAccrual['items'][0]['statement']['net']==='750.00'&&$exportAccrual['items'][0]['statement']['eligible_days']===10,'listing and export use the current operating day even when attendance is filtered to an older day');
clock('2026-11-01 05:59:59');check(accrued($monthEndEmployee)['earned_salary']==='3100.00'&&accrued($monthEndEmployee,'2026-11')['eligible_days']===0,'month-end salary completes before the new month begins at six');
clock('2026-11-01 06:00:00');check(accrued($monthEndEmployee,'2026-11')['eligible_days']===1&&accrued($monthEndEmployee,'2026-11')['earned_salary']==='103.33','new month accrual uses its own thirty-day daily rate at six');
clock('2028-02-10 10:00:00');$leapEmployee=employee('Leap month accrual','2900.00');check(accrued($leapEmployee,'2028-02')['eligible_days']===10&&accrued($leapEmployee,'2028-02')['earned_salary']==='1000.00','leap-month accrual uses twenty-nine actual days');
$roundedEmployee=employee('Fractional daily wage','3100.01');clock('2026-10-10 10:00:00');check(accrued($roundedEmployee)['earned_salary']==='1000.00','daily accrual rounds once at the final amount without floating-point drift');
// Once chosen, status and each recorded punch are immutable for every account.
clock('2026-10-12 10:30:00');$lockedArrival=employee('Locked morning');$lockedResult=mark($lockedArrival,'morning','check_in','2026-10-12');$beforeLock=$lockedResult['attendance'];
foreach(['evening'=>'check_in','preapproved_leave'=>'set_status','unauthorized_absence'=>'set_status'] as $status=>$action)denied(fn()=>mark($lockedArrival,$status,$action,'2026-10-12'),409,'morning arrival is fixed against '.$status.' before checkout');
denied(fn()=>$s->attendance(['branch'=>'f:100','employee_id'=>$lockedArrival,'day'=>'2026-10-12','status'=>'evening','action'=>'check_in','expected_revision'=>$beforeLock['revision'],'idempotency_key'=>attendanceKey()],$owner),409,'Owner cannot bypass the fixed attendance status');
clock('2026-10-12 11:30:00');$sameArrival=mark($lockedArrival,'morning','check_in','2026-10-12',['notes'=>'ملاحظة من محاولة مكررة']);check($sameArrival['attendance']===$beforeLock&&total($lockedArrival)===5000,'repeating arrival preserves its entire row and automatic deduction');
clock('2026-10-12 17:00:00');$lockedDeparture=mark($lockedArrival,'morning','check_out','2026-10-12')['attendance'];check($lockedDeparture['checked_out_at']==='2026-10-12 14:00:00','fixed arrival still permits the first departure');
clock('2026-10-12 18:00:00');check(mark($lockedArrival,'morning','check_out','2026-10-12')['attendance']===$lockedDeparture,'recorded departure time and revision cannot be replaced on a second request');
denied(fn()=>mark($lockedArrival,'evening','check_out','2026-10-12'),409,'departure cannot switch to another shift');
denied(fn()=>mark($absence,'preapproved_leave','set_status'),409,'absence cannot switch to leave');
denied(fn()=>mark($absence,'morning','check_in'),409,'absence cannot switch to attendance');
denied(fn()=>mark($leaveAccrualId,'unauthorized_absence','set_status','2026-10-10'),409,'leave cannot switch to absence');
$notesOnly=employee('Notes before attendance');$s->dailyNotes(['branch'=>'f:100','employee_id'=>$notesOnly,'day'=>'2026-10-12','notes'=>'ملاحظة قبل الحضور','idempotency_key'=>attendanceKey()],$manager);
$fromNotes=mark($notesOnly,'evening','check_in','2026-10-12')['attendance'];check($fromNotes['status']==='evening'&&$fromNotes['notes']==='ملاحظة قبل الحضور','notes-only unrecorded rows permit the first attendance selection');
$s->dailyNotes(['branch'=>'f:100','employee_id'=>$notesOnly,'day'=>'2026-10-12','notes'=>'ملاحظة بعد الحضور','expected_revision'=>$fromNotes['revision'],'idempotency_key'=>attendanceKey()],$manager);$afterNotes=DB::table('branch_employee_days')->where('employee_id',$notesOnly)->first();check($afterNotes->notes==='ملاحظة بعد الحضور'&&$afterNotes->checked_in_at===$fromNotes['checked_in_at'],'daily notes remain editable without changing recorded attendance');
clock('2026-10-13 20:00:00');$nextDay=mark($lockedArrival,'evening','check_in','2026-10-13')['attendance'];check($nextDay['status']==='evening'&&$nextDay['day']==='2026-10-13','a new operating day permits a new independent attendance selection');
clock('2026-10-14 10:00:00');$rolloverEmployee=employee('Independent daily punches');mark($rolloverEmployee,'morning','check_in','2026-10-14');
clock('2026-10-14 18:00:00');$previousDailyRow=mark($rolloverEmployee,'morning','check_out','2026-10-14')['attendance'];
clock('2026-10-15 05:59:59');$priorDayListing=$s->listing(['branch'=>'f:100','search'=>'Independent daily punches'],$manager);
check($priorDayListing['filters']['day']==='2026-10-14'&&$priorDayListing['items'][0]['attendance']['checked_out_at']===$previousDailyRow['checked_out_at'],'completed attendance remains visible until the last second before six');
clock('2026-10-15 06:00:00');$newDayListing=$s->listing(['branch'=>'f:100','search'=>'Independent daily punches'],$manager);
check($newDayListing['filters']['day']==='2026-10-15'&&$newDayListing['items'][0]['attendance']===null,'at six the same employee has no prior-day punch on the new daily row');
$newDailyRow=mark($rolloverEmployee,'evening','check_in','2026-10-15')['attendance'];check($newDailyRow['status']==='evening'&&$newDailyRow['check_in']==='06:00:00','new day permits a different shift and a fresh arrival');
clock('2026-10-15 18:00:00');mark($rolloverEmployee,'evening','check_out','2026-10-15');
check((array)DB::table('branch_employee_days')->where('employee_id',$rolloverEmployee)->where('day','2026-10-14')->first()===$previousDailyRow,'new arrival and departure leave the previous daily record unchanged');
$historyDaily=$s->listing(['branch'=>'f:100','search'=>'Independent daily punches','day'=>'2026-10-14'],$manager);check($historyDaily['items'][0]['attendance']['checked_out_at']===$previousDailyRow['checked_out_at'],'the previous attendance remains available through the date filter');
Carbon::setTestNow();echo $count.' attendance checks passed against the original payroll service'.PHP_EOL;
