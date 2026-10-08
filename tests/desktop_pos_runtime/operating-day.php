<?php
require __DIR__.'/vendor/autoload.php';
use App\Services\Dashboard\{OperatingDay,EmployeeAttendanceRules};
use Carbon\Carbon;
function now($zone=null){return Carbon::now($zone);}
$checks=0;
function check($condition,$label){global $checks;if(!$condition)throw new RuntimeException($label);$checks++;echo 'PASS '.$label.PHP_EOL;}
foreach([
    ['2026-10-09 00:00:00','2026-10-08'],['2026-10-09 05:59:59','2026-10-08'],
    ['2026-10-09 06:00:00','2026-10-09'],['2026-10-09 23:59:59','2026-10-09'],
    ['2026-11-01 05:59:59','2026-10-31'],['2027-01-01 05:59:59','2026-12-31'],
    ['2028-03-01 05:59:59','2028-02-29'],
] as [$at,$expected]){
    $local=Carbon::parse($at,'Africa/Cairo');$before=$local->toIso8601String();
    check(OperatingDay::date($local)===$expected&&$local->toIso8601String()===$before,'Cairo operating date for '.$at.' without mutating the caller clock');
}
check(OperatingDay::date(Carbon::parse('2026-10-09 02:59:59','UTC'))==='2026-10-08'&&OperatingDay::date(Carbon::parse('2026-10-09 03:00:00','UTC'))==='2026-10-09','UTC storage crosses the Cairo operating boundary exactly at six');
foreach(['2026-04-23'=>23,'2026-10-29'=>25] as $day=>$hours){
    $start=OperatingDay::start($day);$end=OperatingDay::end($day);
    check($start->format('H:i')==='06:00'&&$end->format('H:i')==='06:00'&&($end->getTimestamp()-$start->getTimestamp())===$hours*3600,'Cairo daylight-saving day '.$day.' keeps both wall-clock boundaries at six');
}
$rule=(object)['shift'=>'evening','revision'=>1,'starts_at'=>'02:00','ends_at'=>'10:00','late_half_hour_cents'=>5000,'early_half_hour_cents'=>5000,'absence_cents'=>30000];
$snapshot=EmployeeAttendanceRules::snapshot($rule,'2026-10-08');
check($snapshot['scheduled_start']==='2026-10-08 23:00:00'&&$snapshot['scheduled_end']==='2026-10-09 07:00:00','shift starting after midnight belongs to the previous operating day');
$deductions=EmployeeAttendanceRules::deductions('evening',$snapshot,'2026-10-08 23:00:00','2026-10-09 07:00:00',0,31);
check($deductions['late']['amount']===0&&$deductions['early']['amount']===0,'on-time after-midnight shift has no penalties');
Carbon::setTestNow(Carbon::parse('2026-11-01 05:59:59','Africa/Cairo'));$meta=OperatingDay::metadata();
check($meta['date']==='2026-10-31'&&$meta['starts_at']==='2026-10-31T06:00:00+02:00'&&$meta['ends_at']==='2026-11-01T06:00:00+02:00','server metadata exposes the full prior-month operating window');
Carbon::setTestNow();echo $checks.' operating-day checks passed'.PHP_EOL;
