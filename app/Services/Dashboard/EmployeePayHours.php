<?php
namespace App\Services\Dashboard;

use App\Services\GoServices\Money;
use Illuminate\Support\Facades\{DB,Schema};

/** Contract hours are monthly payroll terms, independent of attendance penalties. */
class EmployeePayHours
{
    public const DEFAULT_FULL_TIME_MINUTES=600;

    public static function scheduledMinutes($employee): ?int
    {
        $employee=(object)$employee;
        if(($employee->schedule_type??'branch')!=='part_time')return null;
        $read=function($value){$value=substr((string)$value,0,5);abort_unless(preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$value),422,'أكمل مواعيد الدوام الجزئي.');return (int)substr($value,0,2)*60+(int)substr($value,3,2);};
        $start=$read($employee->attendance_start??'');$end=$read($employee->attendance_end??'');
        $minutes=($end-$start+1440)%1440;abort_unless($minutes>0,422,'وقت الانصراف يجب أن يختلف عن وقت الحضور.');return $minutes;
    }

    public static function parseWorkHours(string $hours): int
    {
        $hours=trim($hours);abort_unless(preg_match('/^([0-9]{1,2})(?:\.([0-9]{1,2}))?$/D',$hours,$m),422,'أدخل ساعات العمل من 1 إلى 24 ساعة بدقائق كاملة.');
        $hundredths=(int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
        abort_unless($hundredths>=100&&$hundredths<=2400&&($hundredths*60)%100===0,422,'أدخل ساعات العمل من 1 إلى 24 ساعة بدقائق كاملة.');
        return intdiv($hundredths*60,100);
    }

    public static function branch(string $branch,string $month): array
    {
        $row=Schema::hasTable('branch_work_hours')?DB::table('branch_work_hours')->where('branch',$branch)->where('effective_month','<=',$month)->orderByDesc('effective_month')->first():null;
        $minutes=$row?(int)$row->full_time_minutes:self::DEFAULT_FULL_TIME_MINUTES;
        // Accepted decimal-hour settings always correspond to complete minutes.
        $hours=rtrim(rtrim(number_format($minutes/60,2,'.',''),'0'),'.');
        return ['full_time_minutes'=>$minutes,'work_hours'=>$hours,'effective_month'=>$month,
            'source_month'=>$row?$row->effective_month:null,'revision'=>$row?(int)$row->revision:0];
    }

    public static function employee(object $employee,string $month): ?int
    {
        $row=Schema::hasTable('branch_employee_pay_hours')?DB::table('branch_employee_pay_hours')->where('employee_id',$employee->id)->where('effective_month','<=',$month)->orderByDesc('effective_month')->first():null;
        return $row&&$row->scheduled_minutes!==null?(int)$row->scheduled_minutes:null;
    }

    public static function calculate(int $base,int $worked,int $days,int $fullMinutes,?int $scheduledMinutes,int $leaveDays): array
    {
        $scheduled=$scheduledMinutes??$fullMinutes;$missing=max(0,$fullMinutes-$scheduled);
        $denominator=$days*$fullMinutes;$chargedDays=max(0,$worked-min($worked,max(0,$leaveDays)));
        $deduction=intdiv($base*$missing*$chargedDays+intdiv($denominator,2),$denominator);
        $monthly=$base-intdiv($base*$missing+intdiv($fullMinutes,2),$fullMinutes);
        return ['full_time_minutes'=>$fullMinutes,'scheduled_minutes'=>$scheduled,
            'effective_monthly_salary'=>Money::decimal($monthly),'hours_deduction'=>Money::decimal($deduction),'hours_deduction_cents'=>$deduction];
    }
}
