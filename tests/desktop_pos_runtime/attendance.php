<?php
// Disposable in-memory SQLite only; exercises the original payroll service and migrations.
require __DIR__.'/vendor/autoload.php';
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Facade,DB,Schema};
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Services\Dashboard\{BranchPayroll,EmployeeAttendanceRules};
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
function employee($name,$salary='3100.00'){global $s,$owner;return $s->employeeSave(['branch'=>'f:100','name'=>$name,'job_title'=>'كاشير','shift'=>'صباحي','hired_on'=>'2026-01-01','effective_month'=>'2026-01','salary'=>$salary,'active'=>true,'idempotency_key'=>attendanceKey()],$owner)['employee']['id'];}
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
mark($late,'unauthorized_absence','set_status');check(total($late)===30700,'absence replaces attendance penalties and preserves manual entry');
mark($late,'unauthorized_absence','set_status');check(total($late)===30700,'repeated absence cannot duplicate deduction');
mark($late,'preapproved_leave','set_status');check(total($late)===10700,'leave replaces absence with one day of salary');
check(DB::table('branch_employee_entries')->where('employee_id',$late)->where('source_key','attendance.leave')->value('reason')==='إجازة','leave reason appears in deductions');
$zero=employee('Missing salary');DB::table('branch_employee_salaries')->where('employee_id',$zero)->delete();denied(fn()=>mark($zero,'preapproved_leave','set_status'),422,'leave requires recorded salary');check(!DB::table('branch_employee_days')->where('employee_id',$zero)->exists(),'failed deduction leaves no partial attendance');
denied(fn()=>mark($id,'morning','check_in','2026-10-07'),422,'historical manual check-in cannot use current time');
denied(fn()=>mark($id,'unauthorized_absence','set_status','2026-10-09'),422,'future attendance is rejected using Cairo date');
clock('2026-10-08 20:30:00');$night=employee('Night');mark($night,'evening');clock('2026-10-09 03:30:00');mark($night,'evening','check_out');check(total($night)===1500,'overnight shift uses start day and next-day departure');
$listed=$s->listing(['branch'=>'f:100','day'=>'2026-10-08'],$manager);check($listed['summary']['present']===4&&$listed['summary']['leave']===1,'daily summary includes new morning evening and leave statuses');check(!$listed['can_manage_attendance'],'branch listing hides owner controls');
$statement=$s->statement(['branch'=>'f:100','employee_id'=>$late,'month'=>'2026-10'],$owner)['statement'];check($statement['deduction']==='107.00'&&$statement['net']==='2993.00','automatic deduction flows into month net salary');
$s->close(['branch'=>'f:100','employee_id'=>$late,'month'=>'2026-10','preview_hash'=>$statement['preview_hash'],'idempotency_key'=>attendanceKey()],$owner);denied(fn()=>mark($late,'unauthorized_absence','set_status'),409,'closed payroll prevents new attendance or deductions');
$feb=EmployeeAttendanceRules::deductions('preapproved_leave',[],null,null,290000,29);check($feb['leave']['amount']===10000,'leap-month daily rate uses actual month days');
clock('2026-10-08 11:30:00');$belowCap=employee('Below absence cap');mark($belowCap);check(total($belowCap)===29700,'lateness below the absence cap keeps the calculated deduction');
clock('2026-10-08 12:00:00');$capped=employee('Capped lateness');$cappedAttendance=mark($capped)['attendance'];check(total($capped)===30000,'lateness above the absence cap charges only the absence amount');
$cappedEntry=DB::table('branch_employee_entries')->where('employee_id',$capped)->where('source_key','attendance.late')->whereNull('voided_at')->first();
check($cappedAttendance['status']==='morning'&&$cappedAttendance['check_in']==='12:00:00'&&$cappedEntry->reason==='خصم تأخر عن العمل'&&str_contains($cappedEntry->notes,'الحد الأقصى'),'capped lateness preserves attendance time and explains the limit in deductions');
$s->entry(['branch'=>'f:100','employee_id'=>$capped,'day'=>'2026-10-08','kind'=>'deduction','amount'=>'7.00','reason'=>'خصم يدوي','idempotency_key'=>attendanceKey()],$owner);
clock('2026-10-08 12:30:00');mark($capped);check(total($capped)===30700&&DB::table('branch_employee_entries')->where('employee_id',$capped)->where('source_key','attendance.late')->whereNull('voided_at')->count()===1,'repeat capped check-in preserves manual deductions without duplicating the penalty');
clock('2026-10-08 17:00:00');mark($capped,'morning','check_out');check(total($capped)===50500&&(int)DB::table('branch_employee_entries')->where('employee_id',$capped)->where('source_key','attendance.early')->value('amount_cents')===19800,'lateness cap leaves early departure deductions independent');
$cappedStatement=$s->statement(['branch'=>'f:100','employee_id'=>$capped,'month'=>'2026-10'],$owner)['statement'];check($cappedStatement['deduction']==='505.00'&&$cappedStatement['net']==='2595.00','capped deduction and manual entries flow into net salary');
$lowerCap=array_replace($modified,['absence'=>'50.00','morning_late'=>'100.00','expected_revision'=>2,'idempotency_key'=>attendanceKey()]);$s->saveAttendanceRules($lowerCap,$owner);
mark($capped);check(total($capped)===50500,'later Owner changes preserve the cap captured when attendance was recorded');
clock('2026-10-08 11:30:00');$newCap=employee('New absence cap');mark($newCap);check(total($newCap)===5000,'new attendance uses the updated Owner absence cap');
$zeroCap=array_replace($lowerCap,['absence'=>'0.00','expected_revision'=>3,'idempotency_key'=>attendanceKey()]);$s->saveAttendanceRules($zeroCap,$owner);
$freeCap=employee('Zero absence cap');$freeAttendance=mark($freeCap)['attendance'];check(total($freeCap)===0&&$freeAttendance['check_in']==='11:30:00','zero absence amount also caps lateness at zero while recording attendance');
$boundary=EmployeeAttendanceRules::snapshot((object)['shift'=>'morning','revision'=>1,'starts_at'=>'10:00','ends_at'=>'18:00','late_half_hour_cents'=>10000,'early_half_hour_cents'=>9900,'absence_cents'=>30000],'2026-10-08');
$equal=EmployeeAttendanceRules::deductions('morning',$boundary,'2026-10-08 08:30:00',null,310000,31);check($equal['late']['amount']===30000,'lateness equal to the absence amount stays exactly at the limit');
$nightCap=EmployeeAttendanceRules::snapshot((object)['shift'=>'evening','revision'=>1,'starts_at'=>'20:00','ends_at'=>'04:00','late_half_hour_cents'=>10000,'early_half_hour_cents'=>9900,'absence_cents'=>30000],'2026-10-08');
$nightDeductions=EmployeeAttendanceRules::deductions('evening',$nightCap,'2026-10-08 19:00:00','2026-10-08 23:00:00',310000,31);check($nightDeductions['late']['amount']===30000&&$nightDeductions['early']['amount']===39600,'evening overnight shift caps lateness without capping early departure');
Carbon::setTestNow();echo $count.' attendance checks passed against the original payroll service'.PHP_EOL;
