<?php
namespace App\Services\Dashboard;

use Carbon\Carbon;
use App\Services\GoServices\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;

/** Employee accounting is a period ledger; it does not transfer money or alter the POS cash drawer. */
class BranchPayroll
{
    private BranchOperations $ops;
    public function __construct(BranchOperations $ops){$this->ops=$ops;}
    private function employee(int $id,string $branch,bool $lock=false): object
    {
        $q=DB::table('branch_employees')->where('branch',$branch)->where('id',$id);if($lock)$q->lockForUpdate();$row=$q->first();abort_unless($row,404);return $row;
    }
    private function openMonth(int $id,string $month): void {abort_if(DB::table('branch_payrolls')->where('employee_id',$id)->where('month',$month)->exists(),409,'تم إقفال هذا الشهر. لا يمكن تغيير حركاته.');}
    private function walletNumber($value): string
    {
        $value=strtr(trim((string)$value),array_combine(preg_split('//u','٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),range(0,9)));
        $value=preg_replace('/[\s()-]/u','',$value); $value=preg_replace('/^(?:\+20|0020)(1[0125][0-9]{8})$/','0$1',$value);
        abort_unless($value===''||preg_match('/^01[0125][0-9]{8}$/D',$value),422,'أدخل رقم محفظة مصري صحيحًا من 11 رقمًا.');
        return $value;
    }
    public function wallet(array $values,$actor): array
    {
        $v=Validator::make($values,$this->ops->rules()+['employee_id'=>'required|integer|min:1','wallet_phone'=>'nullable|string|max:30'])->validate();
        $number=$this->walletNumber($v['wallet_phone']??'');
        return $this->ops->write('employee.wallet',$v,$actor,function($branch,$actor)use($v,$number){
            $employee=$this->employee($v['employee_id'],$branch['value'],true); $this->ops->revision($employee,$v);
            DB::table('branch_employees')->where('id',$employee->id)->update(['wallet_phone'=>$number?:null,'revision'=>(int)$employee->revision+1,'actor_id'=>$actor->id,'updated_at'=>now('UTC')]);
            return ['employee'=>(array)$this->employee($employee->id,$branch['value'])];
        });
    }
    public function dailyNotes(array $values,$actor): array
    {
        $v=Validator::make($values,$this->ops->rules()+['employee_id'=>'required|integer|min:1','day'=>'required|date_format:Y-m-d|before_or_equal:today','notes'=>'nullable|string|max:1000'])->validate();
        abort_if($v['day']>OperatingDay::date(),422,'لا يمكن التسجيل ليوم تشغيل مستقبلي.');
        return $this->ops->write('employee.notes',$v,$actor,function($branch,$actor)use($v){
            $employee=$this->employee($v['employee_id'],$branch['value'],true); $this->employmentDay($employee,$v['day']); $this->openMonth($employee->id,substr($v['day'],0,7));
            $old=DB::table('branch_employee_days')->where('employee_id',$employee->id)->where('day',$v['day'])->first(); if($old)$this->ops->revision($old,$v);
            $data=['notes'=>trim($v['notes']??''),'revision'=>$old?(int)$old->revision+1:1,'actor_id'=>$actor->id,'updated_at'=>now('UTC')];
            if($old)DB::table('branch_employee_days')->where('id',$old->id)->update($data);
            else DB::table('branch_employee_days')->insert($data+['branch'=>$branch['value'],'employee_id'=>$employee->id,'day'=>$v['day'],'status'=>'unrecorded','created_at'=>now('UTC')]);
            return ['attendance'=>(array)DB::table('branch_employee_days')->where('employee_id',$employee->id)->where('day',$v['day'])->first()];
        });
    }
    public function employeeSave(array $values,$actor): array
    {
        $v=Validator::make($values,$this->ops->rules()+['employee_id'=>'nullable|integer|min:1','name'=>'required|string|max:100','phone'=>'nullable|string|max:30','wallet_phone'=>'nullable|string|max:30','job_title'=>'required|string|max:100','shift'=>'nullable|string|max:100','hired_on'=>'required|date_format:Y-m-d','left_on'=>'nullable|date_format:Y-m-d|after_or_equal:hired_on','notes'=>'nullable|string|max:1000','active'=>'required|boolean','salary'=>'required|string|max:14','effective_month'=>'required|date_format:Y-m'])->validate();
        if(array_key_exists('wallet_phone',$v))$v['wallet_phone']=$this->walletNumber($v['wallet_phone']);
        $v['left_on']=!empty($v['left_on'])?$v['left_on']:null;
        $amount=$this->ops->money($v['salary']);foreach(['name','phone','job_title','shift','notes'] as $key)$v[$key]=trim($v[$key]??'');abort_if($v['name']===''||$v['job_title']==='',422,'أدخل اسم الموظف ووظيفته.');
        return $this->ops->write('employee.save',$v,$actor,function($branch,$actor)use($v,$amount){
            $row=!empty($v['employee_id'])?$this->employee($v['employee_id'],$v['branch'],true):null;if($row)$this->ops->revision($row,$v);
            $data=array_intersect_key($v,array_flip(['branch','name','phone','wallet_phone','job_title','shift','hired_on','left_on','notes','active']));$data+=['left_on'=>null,'revision'=>$row?(int)$row->revision+1:1,'actor_id'=>$actor->id,'updated_at'=>now('UTC')];
            if($row){$id=$row->id;DB::table('branch_employees')->where('id',$id)->update($data);}else $id=DB::table('branch_employees')->insertGetId($data+['created_at'=>now('UTC')]);
            $previous=DB::table('branch_employee_salaries')->where('employee_id',$id)->where('effective_month','<=',$v['effective_month'])->orderByDesc('effective_month')->first();
            if(!$previous||(int)$previous->amount_cents!==$amount){
                abort_if(DB::table('branch_payrolls')->where('employee_id',$id)->where('month','>=',$v['effective_month'])->exists(),409,'لا يمكن تغيير راتب شهر مُقفل أو شهر قبله. اختر تاريخ سريان لاحقًا.');
                DB::table('branch_employee_salaries')->updateOrInsert(['employee_id'=>$id,'effective_month'=>$v['effective_month']],['branch'=>$v['branch'],'amount_cents'=>$amount,'actor_id'=>$actor->id,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            }
            return ['employee'=>(array)$this->employee($id,$v['branch'])];
        });
    }
    public function canManageAttendance($actor): bool {return $this->canVoidEntries($actor);}
    private function attendanceReady(): void {abort_unless(Schema::hasTable('branch_attendance_rules'),503,'يجب تثبيت تحديث الحضور أولًا.');}
    private function ruleData(object $r): array
    {
        return ['starts_at'=>substr($r->starts_at,0,5),'ends_at'=>substr($r->ends_at,0,5),
            'late_half_hour'=>Money::decimal((int)$r->late_half_hour_cents),'early_half_hour'=>Money::decimal((int)$r->early_half_hour_cents),
            'absence'=>Money::decimal((int)$r->absence_cents),'revision'=>(int)$r->revision];
    }
    public function saveAttendanceRules(array $values,$actor): array
    {
        abort_unless($this->canManageAttendance($actor),403,'إعداد مواعيد الحضور والخصومات متاح للأونر فقط.');$this->attendanceReady();
        $rules=$this->ops->rules()+['absence'=>'required|string|max:14'];
        foreach(['morning','evening'] as $shift){
            $rules[$shift.'_start']='required|date_format:H:i';$rules[$shift.'_end']='required|date_format:H:i';
            $rules[$shift.'_late']='required|string|max:14';$rules[$shift.'_early']='required|string|max:14';
        }
        $v=Validator::make($values,$rules)->validate();$amounts=['absence'=>$this->ops->money($v['absence'])];
        foreach(['morning','evening'] as $shift){
            abort_if($v[$shift.'_start']===$v[$shift.'_end'],422,'وقت الانصراف يجب أن يختلف عن وقت الحضور.');
            foreach(['late','early'] as $kind)$amounts[$shift.'_'.$kind]=$this->ops->money($v[$shift.'_'.$kind]);
        }
        return $this->ops->write('employee.attendance_rules',$v,$actor,function($branch,$actor)use($v,$amounts){
            $old=DB::table('branch_attendance_rules')->where('branch',$v['branch'])->where('shift','morning')->first();
            if($old)$this->ops->revision($old,$v);
            $revision=$old?(int)$old->revision+1:1;
            foreach(['morning','evening'] as $shift){
                $q=DB::table('branch_attendance_rules')->where('branch',$v['branch'])->where('shift',$shift);
                $data=['starts_at'=>$v[$shift.'_start'],'ends_at'=>$v[$shift.'_end'],
                    'late_half_hour_cents'=>$amounts[$shift.'_late'],'early_half_hour_cents'=>$amounts[$shift.'_early'],
                    'absence_cents'=>$amounts['absence'],'revision'=>$revision,'actor_id'=>$actor->id,'updated_at'=>now('UTC')];
                if($q->exists())$q->update($data);else $q->insert($data+['branch'=>$v['branch'],'shift'=>$shift,'created_at'=>now('UTC')]);
            }
            return ['revision'=>$revision];
        });
    }
    private function synchronizeDeductions(object $employee,string $day,array $wanted,$actor): void
    {
        foreach(['late','early','absence','leave'] as $kind){
            $source='attendance.'.$kind;$q=DB::table('branch_employee_entries')->where('employee_id',$employee->id)->where('day',$day)->where('source_key',$source);
            $old=$q->first();$deduction=$wanted[$kind]??null;
            if(!$deduction||$deduction['amount']<=0){
                if($old&&!$old->voided_at)$q->update(['voided_at'=>now('UTC'),'voided_by'=>$actor->id,'void_reason'=>'تغيرت حالة الحضور؛ لم يعد هذا الخصم مستحقًا.','revision'=>(int)$old->revision+1,'updated_at'=>now('UTC')]);
                continue;
            }
            // An explicit Owner cancellation remains cancelled when a punch is repeated.
            if($old&&$old->voided_at&&$old->void_reason!=='تغيرت حالة الحضور؛ لم يعد هذا الخصم مستحقًا.')continue;
            if($old&&!$old->voided_at&&(int)$old->amount_cents===$deduction['amount']&&$old->notes===$deduction['notes'])continue;
            $data=['amount_cents'=>$deduction['amount'],'reason'=>$deduction['reason'],'notes'=>$deduction['notes'],
                'voided_at'=>null,'voided_by'=>null,'void_reason'=>null,'revision'=>$old?(int)$old->revision+1:1,'actor_id'=>$actor->id,'updated_at'=>now('UTC')];
            if($old)$q->update($data);else $q->insert($data+['branch'=>$employee->branch,'employee_id'=>$employee->id,'day'=>$day,'kind'=>'deduction','source_key'=>$source,'created_at'=>now('UTC')]);
        }
    }
    public function attendance(array $values,$actor): array
    {
        $this->attendanceReady();
        $v=Validator::make($values,$this->ops->rules()+['employee_id'=>'required|integer|min:1','day'=>'required|date_format:Y-m-d',
            'status'=>'required|in:morning,evening,preapproved_leave,unauthorized_absence','action'=>'required|in:check_in,check_out,set_status',
            'check_in'=>'prohibited','check_out'=>'prohibited','checked_in_at'=>'prohibited','checked_out_at'=>'prohibited','notes'=>'nullable|string|max:1000'])->validate();
        abort_if($v['day']>OperatingDay::date(),422,'لا يمكن تسجيل الحضور ليوم مستقبلي.');
        return $this->ops->write('employee.attendance',$v,$actor,function($branch,$actor)use($v){
            $employee=$this->employee($v['employee_id'],$v['branch'],true);$this->employmentDay($employee,$v['day']);$this->openMonth($employee->id,substr($v['day'],0,7));
            $old=DB::table('branch_employee_days')->where('employee_id',$employee->id)->where('day',$v['day'])->first();if($old)$this->ops->revision($old,$v);
            $status=$v['status'];$present=in_array($status,['morning','evening'],true);$clock=now('Africa/Cairo');
            $snapshot=$old&&$old->status===$status?json_decode($old->attendance_rule_snapshot??'',true):null;
            if(!$snapshot){
                $shift=$present?$status:(in_array($employee->shift,['evening','مسائي','مساء'],true)?'evening':'morning');
                $rule=DB::table('branch_attendance_rules')->where('branch',$v['branch'])->where('shift',$shift)->first();
                abort_unless($rule||$status==='preapproved_leave',422,'يجب أن يحدد الأونر مواعيد وخصومات هذا الفرع أولًا.');
                $snapshot=$rule?EmployeeAttendanceRules::snapshot($rule,$v['day']):[];
            }
            $in=null;$out=null;
            if($present){
                abort_unless(in_array($v['action'],['check_in','check_out'],true),422,'اختر تسجيل الحضور أو الانصراف.');
                $wasPresent=$old&&in_array($old->status,['morning','evening'],true);
                $in=$wasPresent?$old->checked_in_at:null;$out=$wasPresent?$old->checked_out_at:null;
                if($v['action']==='check_in'){
                    abort_unless($v['day']===OperatingDay::date($clock),422,'تسجيل الحضور يتم ليوم التشغيل الحالي من 6 صباحًا إلى 6 صباحًا بتوقيت مصر.');
                    abort_if($out&&$old->status!==$status,409,'لا يمكن تغيير الوردية بعد تسجيل الانصراف.');
                    $in=$in?:$clock->copy()->utc()->toDateTimeString();
                }else{
                    abort_unless($wasPresent&&$old->status===$status&&$in,422,'سجّل حضور الموظف أولًا.');
                    $endDay=OperatingDay::date(Carbon::parse($snapshot['scheduled_end'],'UTC'));
                    abort_unless(in_array(OperatingDay::date($clock),[$v['day'],$endDay],true),422,'تسجيل الانصراف متاح خلال يوم الوردية فقط.');
                    $out=$out?:$clock->copy()->utc()->toDateTimeString();
                    abort_if($out<$in,422,'وقت الانصراف يسبق الحضور.');
                }
            }else abort_unless($v['action']==='set_status',422,'اختر حالة الإجازة أو الغياب.');
            $salary=DB::table('branch_employee_salaries')->where('employee_id',$employee->id)->where('effective_month','<=',substr($v['day'],0,7))->orderByDesc('effective_month')->first();
            abort_if($status==='preapproved_leave'&&!$salary,422,'سجّل راتب الموظف لحساب قيمة يوم الإجازة.');
            if($status==='preapproved_leave'&&!isset($snapshot['leave_salary_cents'])){
                $snapshot['leave_salary_cents']=(int)$salary->amount_cents;$snapshot['leave_month_days']=Carbon::parse($v['day'],'Africa/Cairo')->daysInMonth;
            }
            $wanted=EmployeeAttendanceRules::deductions($status,$snapshot,$in,$out,$snapshot['leave_salary_cents']??0,$snapshot['leave_month_days']??30);
            $data=['branch'=>$v['branch'],'status'=>$status,'checked_in_at'=>$in,'checked_out_at'=>$out,
                'check_in'=>$in?Carbon::parse($in,'UTC')->setTimezone('Africa/Cairo')->format('H:i:s'):null,
                'check_out'=>$out?Carbon::parse($out,'UTC')->setTimezone('Africa/Cairo')->format('H:i:s'):null,
                'attendance_rule_snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),
                'notes'=>array_key_exists('notes',$v)?trim($v['notes']):($old->notes??''),
                'revision'=>$old?(int)$old->revision+1:1,'actor_id'=>$actor->id,'updated_at'=>now('UTC')];
            if($old)DB::table('branch_employee_days')->where('id',$old->id)->update($data);
            else DB::table('branch_employee_days')->insert($data+['employee_id'=>$employee->id,'day'=>$v['day'],'created_at'=>now('UTC')]);
            $this->synchronizeDeductions($employee,$v['day'],$wanted,$actor);
            return ['attendance'=>(array)DB::table('branch_employee_days')->where('employee_id',$employee->id)->where('day',$v['day'])->first()];
        });
    }
    private function employmentDay(object $employee,string $day): void {abort_if($day<$employee->hired_on||($employee->left_on&&$day>$employee->left_on),422,'التاريخ خارج فترة عمل الموظف.');}
    public function entry(array $values,$actor): array
    {
        $v=Validator::make($values,$this->ops->rules()+['employee_id'=>'required|integer|min:1','day'=>'required|date_format:Y-m-d|before_or_equal:today','kind'=>'required|in:bonus,deduction,advance','amount'=>'required|string|max:14','reason'=>'required|string|max:500','notes'=>'nullable|string|max:1000'])->validate();$amount=$this->ops->money($v['amount'],false);abort_if(trim($v['reason'])==='',422,'اكتب سبب الحركة.');
        abort_if($v['day']>OperatingDay::date(),422,'لا يمكن التسجيل ليوم تشغيل مستقبلي.');
        return $this->ops->write('employee.entry',$v,$actor,function($branch,$actor)use($v,$amount){
            $employee=$this->employee($v['employee_id'],$v['branch'],true);$this->employmentDay($employee,$v['day']);$this->openMonth($employee->id,substr($v['day'],0,7));
            $id=DB::table('branch_employee_entries')->insertGetId(['branch'=>$v['branch'],'employee_id'=>$employee->id,'day'=>$v['day'],'kind'=>$v['kind'],'amount_cents'=>$amount,'reason'=>trim($v['reason']),'notes'=>trim($v['notes']??''),'actor_id'=>$actor->id,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return ['entry'=>(array)DB::table('branch_employee_entries')->where('id',$id)->first()];
        });
    }
    private function canVoidEntries($actor): bool
    {
        $owner=$this->ops->access->actor($actor);
        // The installation's primary owner is persisted user 1; copied admin roles do not grant this action.
        return (int)$owner->id===1&&$owner->account_type==='admin'&&empty($owner->owner_resturant_id);
    }
    public function voidEntry(array $values,$actor): array
    {
        abort_unless($this->canVoidEntries($actor),403,'إلغاء الحركة متاح لحساب الأونر فسخانينجا فقط.');
        $v=Validator::make($values,$this->ops->rules()+['entry_id'=>'required|integer|min:1','reason'=>'required|string|max:500'])->validate();abort_if(trim($v['reason'])==='',422,'اكتب سبب إلغاء الحركة.');
        return $this->ops->write('employee.void',$v,$actor,function($branch,$actor)use($v){
            $row=DB::table('branch_employee_entries')->where('branch',$v['branch'])->where('id',$v['entry_id'])->lockForUpdate()->first();abort_unless($row,404);$this->ops->revision($row,$v);abort_if($row->voided_at,409);$this->openMonth($row->employee_id,substr($row->day,0,7));
            DB::table('branch_employee_entries')->where('id',$row->id)->update(['voided_at'=>now('UTC'),'voided_by'=>$actor->id,'void_reason'=>trim($v['reason']),'revision'=>(int)$row->revision+1,'updated_at'=>now('UTC')]);return ['entry_id'=>(int)$row->id];
        });
    }
    public function statement(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:30','employee_id'=>'required|integer|min:1','month'=>'required|date_format:Y-m'])->validate();$this->ops->branches($v['branch'],$actor);$employee=$this->employee($v['employee_id'],$v['branch']);
        return ['success'=>true,'statement'=>$this->period($employee,$v['month'])+['can_void_entries'=>$this->canVoidEntries($actor)]];
    }
    public function entries(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:30','employee_id'=>'required|integer|min:1','day'=>'required|date_format:Y-m-d','kind'=>'required|in:bonus,deduction,advance'])->validate();
        $this->ops->branches($v['branch'],$actor);$employee=$this->employee($v['employee_id'],$v['branch']);
        $rows=DB::table('branch_employee_entries')->where('branch',$v['branch'])->where('employee_id',$employee->id)->where('day',$v['day'])->where('kind',$v['kind'])->whereNull('voided_at')->orderBy('created_at')->orderBy('id')->get();
        return ['success'=>true,'employee'=>(array)$employee,'day'=>$v['day'],'kind'=>$v['kind'],'count'=>$rows->count(),'total'=>Money::decimal((int)$rows->sum('amount_cents')),'items'=>$rows->map(function($row){return ['id'=>(int)$row->id,'time'=>Carbon::parse($row->created_at,'UTC')->setTimezone('Africa/Cairo')->format('H:i'),'amount'=>Money::decimal((int)$row->amount_cents),'reason'=>$row->reason,'notes'=>$row->notes];})->all()];
    }
    private function period(object $employee,string $month): array
    {
        $closed=DB::table('branch_payrolls')->where('employee_id',$employee->id)->where('month',$month)->first();
        if($closed)return json_decode($closed->snapshot,true)+['payroll_id'=>(int)$closed->id,'status'=>$closed->status,'revision'=>(int)$closed->revision,'paid_at'=>$closed->paid_at,'payment_method'=>$closed->payment_method,'payment_reference'=>$closed->payment_reference];
        $start=Carbon::createFromFormat('!Y-m',$month,'Africa/Cairo')->startOfMonth();$end=$start->copy()->endOfMonth();$days=$start->daysInMonth;
        $from=max($start->toDateString(),$employee->hired_on);$to=min($end->toDateString(),$employee->left_on??$end->toDateString());
        $worked=$to<$from?0:Carbon::parse($from)->diffInDays(Carbon::parse($to))+1;
        $salary=DB::table('branch_employee_salaries')->where('employee_id',$employee->id)->where('effective_month','<=',$month)->orderByDesc('effective_month')->first();
        $base=$salary?(int)$salary->amount_cents:0;$earned=intdiv($base*$worked+intdiv($days,2),$days);
        $entries=DB::table('branch_employee_entries')->where('employee_id',$employee->id)->whereBetween('day',[$start->toDateString(),$end->toDateString()])->orderBy('day')->orderBy('id')->get();$totals=['bonus'=>0,'deduction'=>0,'advance'=>0];
        foreach($entries as $entry)if(!$entry->voided_at)$totals[$entry->kind]+=(int)$entry->amount_cents;
        $attendance=DB::table('branch_employee_days')->where('employee_id',$employee->id)->whereBetween('day',[$start->toDateString(),$end->toDateString()])->orderBy('day')->get()->map(fn($r)=>(array)$r)->all();
        $net=$earned+$totals['bonus']-$totals['deduction']-$totals['advance'];
        $data=['employee'=>(array)$employee,'month'=>$month,'salary'=>Money::decimal($base),'salary_configured'=>(bool)$salary,'eligible_days'=>$worked,'month_days'=>$days,'earned_salary'=>Money::decimal($earned),'bonus'=>Money::decimal($totals['bonus']),'deduction'=>Money::decimal($totals['deduction']),'advance'=>Money::decimal($totals['advance']),'net'=>Money::decimal($net),'net_cents'=>$net,'attendance'=>$attendance,'entries'=>$entries->map(function($r){$a=(array)$r;$a['amount']=Money::decimal((int)$r->amount_cents);return $a;})->all()];
        $data['preview_hash']=PosServiceTicket::fingerprint($data);return $data+['status'=>'draft','revision'=>0,'payroll_id'=>null];
    }
    public function close(array $values,$actor): array
    {
        $v=Validator::make($values,$this->ops->rules()+['employee_id'=>'required|integer|min:1','month'=>'required|date_format:Y-m','preview_hash'=>'required|string|size:64'])->validate();
        return $this->ops->write('payroll.close',$v,$actor,function($branch,$actor)use($v){
            $employee=$this->employee($v['employee_id'],$v['branch'],true);$this->openMonth($employee->id,$v['month']);$statement=$this->period($employee,$v['month']);
            abort_unless($statement['salary_configured']&&hash_equals($statement['preview_hash'],$v['preview_hash']),409,'كشف المستحقات تغير أو الراتب غير مضبوط. راجع الكشف مجددًا.');
            abort_if($v['month']>substr(OperatingDay::date(),0,7),422,'لا يمكن إقفال شهر مستقبلي.');
            unset($statement['status'],$statement['revision'],$statement['payroll_id']);
            DB::table('branch_payrolls')->insert(['branch'=>$v['branch'],'employee_id'=>$employee->id,'month'=>$v['month'],'net_cents'=>$statement['net_cents'],'snapshot'=>json_encode($statement,JSON_UNESCAPED_UNICODE),'status'=>'closed','revision'=>1,'actor_id'=>$actor->id,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return ['statement'=>$this->period($employee,$v['month'])];
        });
    }
    public function pay(array $values,$actor): array
    {
        $v=Validator::make($values,$this->ops->rules()+['payroll_id'=>'required|integer|min:1','payment_method'=>'required|in:cash,bank,mobile_wallet','payment_reference'=>'nullable|string|max:150','payment_confirmed'=>'required|accepted'])->validate();
        return $this->ops->write('payroll.pay',$v,$actor,function($branch,$actor)use($v){
            $row=DB::table('branch_payrolls')->where('branch',$v['branch'])->where('id',$v['payroll_id'])->lockForUpdate()->first();abort_unless($row,404);$this->ops->revision($row,$v);abort_unless($row->status==='closed'&&(int)$row->net_cents>=0,409,'الكشف سُدد بالفعل أو عليه رصيد سالب يحتاج تسوية.');
            DB::table('branch_payrolls')->where('id',$row->id)->update(['status'=>'paid','revision'=>(int)$row->revision+1,'paid_at'=>now('UTC'),'paid_by'=>$actor->id,'payment_method'=>$v['payment_method'],'payment_reference'=>trim($v['payment_reference']??''),'updated_at'=>now('UTC')]);
            return ['statement'=>$this->period($this->employee($row->employee_id,$v['branch']),$row->month)];
        });
    }
    public function listing(array $values,$actor,bool $export=false): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:30','day'=>'nullable|date_format:Y-m-d','month'=>'nullable|date_format:Y-m','search'=>'nullable|string|max:100','job_title'=>'nullable|string|max:100','shift'=>'nullable|string|max:100','page'=>'nullable|integer|min:1'])->validate();$v['day']=$v['day']??OperatingDay::date();$v['month']=$v['month']??substr($v['day'],0,7);
        $branches=$this->ops->branches($v['branch'],$actor);$q=DB::table('branch_employees')->whereIn('branch',array_column($branches,'value'));
        $options=[];foreach(['job_title','shift'] as $key)$options[$key]=(clone $q)->whereNotNull($key)->where($key,'<>','')->distinct()->orderBy($key)->pluck($key)->all();
        if(!empty($v['search']))$q->where('name','like','%'.trim($v['search']).'%');foreach(['job_title','shift'] as $key)if(!empty($v[$key]))$q->where($key,$v[$key]);
        $count=(clone $q)->count();abort_if($export&&$count>1000,422,'اختر فرعًا أو وظيفة لتصدير حتى ألف موظف.');$last=max(1,(int)ceil($count/30));$page=min($last,(int)($v['page']??1));
        $employees=(clone $q)->orderByDesc('active')->orderBy('name')->offset($export?0:($page-1)*30)->limit($export?1000:30)->get();
        $ids=$employees->pluck('id');$days=DB::table('branch_employee_days')->whereIn('employee_id',$ids)->where('day',$v['day'])->get()->keyBy('employee_id');
        $daily=DB::table('branch_employee_entries')->whereIn('employee_id',$ids)->where('day',$v['day'])->whereNull('voided_at')->selectRaw('employee_id,kind,COUNT(*) AS count,SUM(amount_cents) AS amount')->groupBy('employee_id','kind')->get()->groupBy('employee_id');
        $closed=DB::table('branch_payrolls')->whereIn('employee_id',$ids)->where('month',substr($v['day'],0,7))->pluck('employee_id')->all();
        $items=[];foreach($employees as $employee){$statement=$this->period($employee,$v['month']);$item=(array)$employee;$item['attendance']=isset($days[$employee->id])?(array)$days[$employee->id]:null;$item['statement']=array_diff_key($statement,array_flip(['attendance','entries','employee']));$item['day_closed']=in_array($employee->id,$closed);$item['daily']=array_fill_keys(['bonus','deduction','advance'],['count'=>0,'amount'=>'0.00']);foreach($daily[$employee->id]??[] as $sum)$item['daily'][$sum->kind]=['count'=>(int)$sum->count,'amount'=>Money::decimal((int)$sum->amount)];$items[]=$item;}
        $today=DB::table('branch_employee_days')->whereIn('employee_id',(clone $q)->select('id'))->where('day',$v['day'])->selectRaw('status,COUNT(*) AS count')->groupBy('status')->pluck('count','status');
        $entrySums=DB::table('branch_employee_entries')->whereIn('employee_id',(clone $q)->select('id'))->where('day',$v['day'])->whereNull('voided_at')->selectRaw('kind,SUM(amount_cents) AS amount')->groupBy('kind')->pluck('amount','kind');
        $attendanceRules=[];if(Schema::hasTable('branch_attendance_rules'))foreach(DB::table('branch_attendance_rules')->whereIn('branch',array_column($branches,'value'))->get() as $rule)$attendanceRules[$rule->branch][$rule->shift]=$this->ruleData($rule);
        return ['success'=>true,'operating_day'=>OperatingDay::metadata(),'attendance_rules'=>$attendanceRules,'can_manage_attendance'=>$this->canManageAttendance($actor),'items'=>$items,'branches'=>$branches,'options'=>$options,'filters'=>$v,'pagination'=>['page'=>$page,'last_page'=>$last,'total'=>$count],'summary'=>['employees'=>$count,'present'=>(int)($today['present']??0)+(int)($today['morning']??0)+(int)($today['evening']??0),'absent'=>(int)($today['absent']??0)+(int)($today['unauthorized_absence']??0),'leave'=>(int)($today['paid_leave']??0)+(int)($today['unpaid_leave']??0)+(int)($today['preapproved_leave']??0),'deduction'=>Money::decimal((int)($entrySums['deduction']??0)),'bonus'=>Money::decimal((int)($entrySums['bonus']??0)),'advance'=>Money::decimal((int)($entrySums['advance']??0))]];
    }
}
