<?php
namespace App\Services\Dashboard;

use Carbon\Carbon;

/** Money stays in integer cents; penalties apply to completed thirty-minute intervals. */
class EmployeeAttendanceRules
{
    public static function snapshot(object $rule,string $day): array
    {
        $start=Carbon::createFromFormat('!Y-m-d H:i',$day.' '.substr($rule->starts_at,0,5),'Africa/Cairo');
        $end=Carbon::createFromFormat('!Y-m-d H:i',$day.' '.substr($rule->ends_at,0,5),'Africa/Cairo');
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
        return $result;
    }
}
