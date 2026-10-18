<?php
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
// The existing suite builds a disposable SQLite database and verifies branch attendance first.
require __DIR__.'/attendance.php';
function partTimePayload($name='Part time',array $overrides=[]): array {
    return array_replace(['branch'=>'f:100','name'=>$name,'job_title'=>'كاشير','shift'=>'جزئي',
        'hired_on'=>'2026-01-01','effective_month'=>'2026-01','salary'=>'3100.00','active'=>true,
        'schedule_type'=>'part_time','attendance_start'=>'18:00','attendance_end'=>'23:00',
        'attendance_shift'=>'evening','idempotency_key'=>attendanceKey()],$overrides);
}
function savePartTime($id,array $changes=[]): array {
    global $s,$owner;
    $row=DB::table('branch_employees')->where('id',$id)->first();
    return $s->employeeSave(partTimePayload($row->name,array_replace(['employee_id'=>$id,
        'expected_revision'=>$row->revision,'attendance_start'=>substr($row->attendance_start??'18:00',0,5),
        'attendance_end'=>substr($row->attendance_end??'23:00',0,5),
        'attendance_shift'=>$row->attendance_shift??'evening'], $changes)),$owner)['employee'];
}
clock('2026-10-18 18:00:00');
$oldEmployee=DB::table('branch_employees')->where('id',$lockedArrival)->first();
check($oldEmployee->schedule_type==='branch'&&$oldEmployee->attendance_start===null,'existing employees retain branch schedules');
(new AddEmployeePartTimeSchedule)->up();
check(DB::table('branch_employees')->where('id',$lockedArrival)->value('schedule_type')==='branch','repeating the additive migration preserves employee records');
$currentRules=DB::table('branch_attendance_rules')->where('branch','f:100')->where('shift','morning')->first();
$s->saveAttendanceRules(array_replace($rules,['morning_start'=>'09:00','evening_start'=>'15:00',
    'evening_end'=>'23:59','expected_revision'=>$currentRules->revision,'idempotency_key'=>attendanceKey()]),$owner);
foreach([
    ['attendance_start'=>null], ['attendance_end'=>null], ['attendance_shift'=>null],
    ['attendance_start'=>'25:00'], ['attendance_end'=>'18:00'], ['attendance_shift'=>'other'],
    ['schedule_type'=>'other']
] as $invalid)denied(fn()=>$s->employeeSave(partTimePayload('Invalid',$invalid),$owner),422,'invalid or incomplete part-time schedule rejected: '.json_encode($invalid));
check(!DB::table('branch_employees')->where('name','Invalid')->exists(),'invalid schedules leave no employee or salary records');
$payload=partTimePayload('Six PM worker');$created=$s->employeeSave($payload,$owner);$part=$created['employee']['id'];
check($s->employeeSave($payload,$owner)['employee']['id']===$part,'retrying employee creation preserves one part-time employee');
$onTime=mark($part,'part_time','check_in','2026-10-18')['attendance'];
$snapshot=json_decode($onTime['attendance_rule_snapshot'],true);
check($onTime['status']==='part_time'&&$onTime['check_in']==='18:00:00'&&total($part)===0,'six PM arrival has no lateness despite nine AM and three PM branch starts');
check($snapshot['starts_at']==='18:00'&&$snapshot['ends_at']==='23:00'&&$snapshot['rule_shift']==='evening','attendance snapshots employee hours and the selected branch deduction rates');
$listing=$s->listing(['branch'=>'f:100','search'=>'Six PM worker','day'=>'2026-10-18'],$manager);
check($listing['summary']['present']===1&&$listing['items'][0]['schedule_type']==='part_time','part-time attendance counts as present and appears with its custom schedule');
denied(fn()=>mark($part,'morning','check_in','2026-10-18'),409,'a recorded part-time status cannot switch to morning');
denied(fn()=>mark($lockedArrival,'part_time','check_in','2026-10-18'),422,'branch employees cannot claim a part-time schedule');
$latePart=$s->employeeSave(partTimePayload('Late part-time'),$owner)['employee']['id'];
denied(fn()=>mark($latePart,'evening','check_in','2026-10-18'),422,'part-time employee cannot use the branch evening clock');
clock('2026-10-18 18:29:59');mark($latePart,'part_time','check_in','2026-10-18');check(total($latePart)===0,'part-time delay below thirty minutes is not charged');
$halfPart=$s->employeeSave(partTimePayload('Half-hour part-time'),$owner)['employee']['id'];
clock('2026-10-18 18:30:00');$halfPunch=mark($halfPart,'part_time','check_in','2026-10-18')['attendance'];
check(total($halfPart)===1000,'exact thirty-minute part-time delay uses the selected evening penalty');
savePartTime($halfPart,['attendance_start'=>'20:00','attendance_end'=>'01:00','attendance_shift'=>'morning']);
clock('2026-10-18 22:30:00');$out=mark($halfPart,'part_time','check_out','2026-10-18')['attendance'];
check(total($halfPart)===1500&&$out['attendance_rule_snapshot']===$halfPunch['attendance_rule_snapshot'],'editing employee hours and rates after arrival preserves the day snapshot for departure');
check(mark($halfPart,'part_time','check_out','2026-10-18')['attendance']===$out&&total($halfPart)===1500,'repeated part-time departure cannot change time or duplicate penalties');
$legacyPayload=partTimePayload('Six PM worker',['employee_id'=>$part,'expected_revision'=>1]);
foreach(['schedule_type','attendance_start','attendance_end','attendance_shift'] as $key)unset($legacyPayload[$key]);
$legacySave=$s->employeeSave($legacyPayload,$owner)['employee'];
check($legacySave['schedule_type']==='part_time'&&substr($legacySave['attendance_start'],0,5)==='18:00','older employee editors preserve custom schedules when fields are omitted');
denied(fn()=>savePartTime($part,['expected_revision'=>1,'attendance_start'=>'20:00']),409,'stale employee editor cannot overwrite the schedule');
$beforeSwitch=DB::table('branch_employee_days')->where('employee_id',$part)->first();
savePartTime($part,['schedule_type'=>'branch']);
clock('2026-10-18 23:00:00');mark($part,'part_time','check_out','2026-10-18');
check(total($part)===0&&DB::table('branch_employees')->where('id',$part)->value('attendance_start')===null,'switching to branch schedule clears custom hours while the existing day still checks out with its snapshot');
$absencePart=$s->employeeSave(partTimePayload('Part-time absence'),$owner)['employee']['id'];
mark($absencePart,'unauthorized_absence','set_status','2026-10-18');check(total($absencePart)===30000,'part-time absence retains the branch absence deduction');
$leavePart=$s->employeeSave(partTimePayload('Part-time leave'),$owner)['employee']['id'];
mark($leavePart,'preapproved_leave','set_status','2026-10-18');check(total($leavePart)===10000,'part-time leave uses the employee recorded monthly wage');
clock('2026-10-19 20:00:00');$newHours=mark($halfPart,'part_time','check_in','2026-10-19')['attendance'];
check(json_decode($newHours['attendance_rule_snapshot'],true)['starts_at']==='20:00'&&total($halfPart)===1500,'new day uses the changed employee hours without recalculating past deductions');
clock('2026-10-20 00:30:00');mark($halfPart,'part_time','check_out','2026-10-19');check(total($halfPart)===2000,'overnight part-time departure belongs to the previous operating day');
$afterSwitch=mark($part,'evening','check_in','2026-10-19')['attendance'];check($afterSwitch['status']==='evening','next day follows branch schedule after removing the part-time override');
$beforeSix=$s->employeeSave(partTimePayload('After midnight worker',['attendance_start'=>'01:00','attendance_end'=>'05:00']),$owner)['employee']['id'];
clock('2026-10-20 01:00:00');$midnight=mark($beforeSix,'part_time','check_in','2026-10-19')['attendance'];
clock('2026-10-20 05:00:00');mark($beforeSix,'part_time','check_out','2026-10-19');
check(total($beforeSix)===0&&$midnight['day']==='2026-10-19','custom shifts starting after midnight obey the six AM operating day');
$statement=$s->statement(['branch'=>'f:100','employee_id'=>$halfPart,'month'=>'2026-10'],$owner)['statement'];
check($statement['salary']==='3100.00'&&$statement['deduction']==='20.00','part-time monthly payroll uses its recorded wage and actual custom attendance penalties');
$s->close(['branch'=>'f:100','employee_id'=>$halfPart,'month'=>'2026-10','preview_hash'=>$statement['preview_hash'],'idempotency_key'=>attendanceKey()],$owner);
denied(fn()=>mark($halfPart,'part_time','check_in','2026-10-19'),409,'closed payroll also locks part-time attendance');
denied(fn()=>$s->employeeSave(partTimePayload('Cross-branch',['employee_id'=>$part,'expected_revision'=>4,'branch'=>'f:101']),$manager),404,'branch manager cannot move or edit another branch employee schedule');
Carbon::setTestNow();echo $count.' attendance and part-time checks passed against the original payroll service'.PHP_EOL;
