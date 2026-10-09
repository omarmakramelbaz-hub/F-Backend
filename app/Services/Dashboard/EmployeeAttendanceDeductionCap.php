<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Reduce existing automatic penalties; never rebuild punches or create ledger entries. */
class EmployeeAttendanceDeductionCap
{
    public function repair(bool $dryRun=false): array
    {
        foreach(['branch_employees','branch_employee_days','branch_employee_entries','branch_payrolls'] as $table){
            if(!Schema::hasTable($table))throw new RuntimeException('Attendance payroll table missing: '.$table);
        }
        if(!Schema::hasColumn('branch_employee_entries','source_key')||!Schema::hasColumn('branch_employee_days','attendance_rule_snapshot')){
            throw new RuntimeException('Install the attendance schema before correcting deductions.');
        }
        $counts=['days'=>0,'entries'=>0,'closed'=>0];
        DB::table('branch_employee_days')->whereIn('status',['morning','evening'])->whereNotNull('attendance_rule_snapshot')
            ->whereExists(function($q){
                $q->selectRaw('1')->from('branch_employee_entries')->whereColumn('employee_id','branch_employee_days.employee_id')
                    ->whereColumn('day','branch_employee_days.day')->where('kind','deduction')->whereNull('voided_at')
                    ->whereIn('source_key',['attendance.late','attendance.early']);
            })->orderBy('id')->chunkById(100,function($days)use(&$counts,$dryRun){
                foreach($days as $day){
                    $result=DB::transaction(function()use($day,$dryRun){
                        // Match the employee lock used by punches, ledger edits and payroll closing.
                        $employee=DB::table('branch_employees')->where('id',$day->employee_id)->lockForUpdate()->first();
                        if(!$employee)return ['days'=>0,'entries'=>0,'closed'=>0];
                        $record=DB::table('branch_employee_days')->where('id',$day->id)->lockForUpdate()->first();
                        if(!$record||!in_array($record->status,['morning','evening'],true))return ['days'=>0,'entries'=>0,'closed'=>0];
                        if(DB::table('branch_payrolls')->where('employee_id',$employee->id)->where('month',substr($record->day,0,7))->exists()){
                            return ['days'=>0,'entries'=>0,'closed'=>1];
                        }
                        $snapshot=json_decode($record->attendance_rule_snapshot,true);
                        if(!is_array($snapshot)||!isset($snapshot['absence_cents'])||!is_int($snapshot['absence_cents'])||$snapshot['absence_cents']<0){
                            throw new RuntimeException('Invalid absence cap in attendance record '.$record->id);
                        }
                        $rows=DB::table('branch_employee_entries')->where('employee_id',$employee->id)->where('day',$record->day)
                            ->where('branch',$employee->branch)->where('kind','deduction')->whereNull('voided_at')
                            ->whereIn('source_key',['attendance.late','attendance.early'])->lockForUpdate()->get();
                        $deductions=[];
                        foreach($rows as $entry)$deductions[substr($entry->source_key,11)]=['amount'=>(int)$entry->amount_cents,'notes'=>$entry->notes??''];
                        $capped=EmployeeAttendanceRules::capAttendanceDeductions($deductions,$snapshot['absence_cents']);$changed=0;
                        foreach($rows as $entry){
                            $wanted=$capped[substr($entry->source_key,11)];
                            if($wanted['amount']===(int)$entry->amount_cents)continue;
                            $changed++;
                            if($dryRun)continue;
                            $values=['amount_cents'=>$wanted['amount'],'notes'=>$wanted['notes'],'revision'=>(int)$entry->revision+1,'updated_at'=>now('UTC')];
                            if($wanted['amount']===0)$values+=['voided_at'=>now('UTC'),'voided_by'=>null,'void_reason'=>'تغيرت حالة الحضور؛ لم يعد هذا الخصم مستحقًا.'];
                            DB::table('branch_employee_entries')->where('id',$entry->id)->update($values);
                        }
                        return ['days'=>$changed?1:0,'entries'=>$changed,'closed'=>0];
                    });
                    foreach($result as $key=>$value)$counts[$key]+=$value;
                }
            });
        return $counts;
    }
}
