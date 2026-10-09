<?php
namespace App\Services\Dashboard;

use Carbon\Carbon;

/** Money stays in integer cents; penalties apply to completed thirty-minute intervals. */
class EmployeeAttendanceRules
{
    public static function snapshot(object $rule,string $day): array
    {
        $start=OperatingDay::at($day,$rule->starts_at);
        $end=OperatingDay::at($day,$rule->ends_at);
        if($end->lessThanOrEqualTo($start))$end->addDay();
        return ['shift'=>$rule->shift,'rule_revision'=>(int)$rule->revision,
            'scheduled_start'=>$start->utc()->toDateTimeString(),'scheduled_end'=>$end->utc()->toDateTimeString(),
            'starts_at'=>substr($rule->starts_at,0,5),'ends_at'=>substr($rule->ends_at,0,5),
            'late_half_hour_cents'=>(int)$rule->late_half_hour_cents,
            'early_half_hour_cents'=>(int)$rule->early_half_hour_cents,'absence_cents'=>(int)$rule->absence_cents];
    }

    public static function deductions(string $status,array $snapshot,?string $in,?string $out,int $salary,int $monthDays): array
    {
        if($status==='preapproved_leave')return ['leave'=>['amount'=>intdiv($salary+intdiv($monthDays,2),$monthDays),'reason'=>'إجازة','notes'=>'قيمة يوم من الراتب ÷ عدد أيام الشهر ('.$monthDays.').']];
        if($status==='unauthorized_absence')return ['absence'=>['amount'=>$snapshot['absence_cents'],'reason'=>'غياب بدون إذن','notes'=>'طبقًا لإعدادات الأونر للفرع.']];
        if(!in_array($status,['morning','evening'],true))return [];
        $result=[];
        foreach(['late'=>[$in,$snapshot['scheduled_start'],$snapshot['late_half_hour_cents'],'خصم تأخر عن العمل'],
                 'early'=>[$out,$snapshot['scheduled_end'],$snapshot['early_half_hour_cents'],'خصم انصراف مبكر']] as $kind=>$v){
            if(!$v[0])continue;
            $actual=Carbon::parse($v[0],'UTC')->getTimestamp();$scheduled=Carbon::parse($v[1],'UTC')->getTimestamp();
            $seconds=max(0,$kind==='late'?$actual-$scheduled:$scheduled-$actual);$halves=intdiv($seconds,1800);
            $result[$kind]=['amount'=>$halves*$v[2],'reason'=>$v[3],'notes'=>intdiv($seconds,60).' دقيقة · '.$halves.' نصف ساعة مكتملة.'];
        }
        return self::capAttendanceDeductions($result,(int)$snapshot['absence_cents']);
    }

    /** One daily ceiling shared by late arrival and early departure, in that order. */
    public static function capAttendanceDeductions(array $deductions,int $absenceCents): array
    {
        $remaining=max(0,$absenceCents);
        $note='تم تطبيق الحد الأقصى لخصومات الحضور والانصراف بقيمة خصم الغياب بدون إذن.';
        foreach(['late','early'] as $kind){
            if(!isset($deductions[$kind]))continue;
            $amount=min($deductions[$kind]['amount'],$remaining);$remaining-=$amount;
            if($amount<$deductions[$kind]['amount']){
                $deductions[$kind]['amount']=$amount;
                if(!str_contains($deductions[$kind]['notes'],$note))$deductions[$kind]['notes'].=' '.$note;
            }
        }
        return $deductions;
    }
}
