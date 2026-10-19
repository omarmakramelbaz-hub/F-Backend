<?php

namespace App\Services\Dashboard;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/** Apply the attendance deadline on the server, even while every dashboard is closed. */
class EmployeeAutomaticAbsence
{
    private BranchOperations $ops;
    private string $activationPath;

    public function __construct(BranchOperations $ops,?string $activationPath=null)
    {
        $this->ops=$ops;
        $this->activationPath=$activationPath??dirname(base_path()).'/employee-auto-absence-backups/activation.json';
    }

    public function ready(): bool
    {
        if($this->activationDay()===null)return false;
        $tables=[
            'users'=>['id','account_type','owner_resturant_id'],
            'branch_operation_commands'=>['branch','actor_id','request_key','request_hash','kind','result','created_at','updated_at'],
            'branch_employees'=>['id','branch','active','hired_on','left_on','shift','revision','schedule_type','attendance_start','attendance_end','attendance_shift'],
            'branch_employee_days'=>['id','branch','employee_id','day','status','check_in','check_out','checked_in_at','checked_out_at','attendance_rule_snapshot','notes','revision','actor_id','created_at','updated_at'],
            'branch_employee_entries'=>['id','branch','employee_id','day','kind','source_key','amount_cents','reason','notes','voided_at','voided_by','void_reason','revision','actor_id','created_at','updated_at'],
            'branch_attendance_rules'=>['branch','shift','starts_at','ends_at','late_half_hour_cents','early_half_hour_cents','absence_cents','revision'],
            'branch_payrolls'=>['employee_id','month'],
        ];
        foreach($tables as $table=>$columns){
            if(!Schema::hasTable($table)||!Schema::hasColumns($table,$columns))return false;
        }
        return true;
    }

    /** Dry runs count proposed records without issuing operation receipts or changing the ledger. */
    public function run(bool $dryRun=false): array
    {
        $lock=null;
        if(!$dryRun){
            $lock=$this->jobLock();
            if($lock===null)return ['ready'=>true,'considered'=>0,'recorded'=>0,'skipped'=>1,'errors'=>0];
            if($lock===false)return ['ready'=>false,'considered'=>0,'recorded'=>0,'skipped'=>0,'errors'=>1];
        }
        try{return $this->execute($dryRun);}
        finally{
            if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
        }
    }

    /** Workers pause while the installer publishes, verifies or rolls back this release. */
    private function jobLock()
    {
        $path=dirname($this->activationPath).'/install.lock';
        if(is_link($path)||!is_file($path))return false;
        $file=@fopen($path,'rb');
        if($file===false)return false;
        $opened=fstat($file);$actual=@lstat($path);
        if(!$opened||!$actual||($opened['mode']&0170000)!==0100000
            ||($actual['mode']&0170000)!==0100000||($opened['mode']&0077)!==0
            ||$opened['dev']!==$actual['dev']||$opened['ino']!==$actual['ino']){
            fclose($file);return false;
        }
        $busy=0;
        if(!flock($file,LOCK_SH|LOCK_NB,$busy)){
            fclose($file);return $busy?null:false;
        }
        return $file;
    }

    private function execute(bool $dryRun): array
    {
        $counts=['ready'=>$this->ready(),'considered'=>0,'recorded'=>0,'skipped'=>0,'errors'=>0];
        if(!$counts['ready'])return $counts;
        $startsOn=$this->activationDay();
        if($startsOn===null){$counts['ready']=false;return $counts;}
        // Use the persisted Owner, never a selected dashboard user or the first branch employee.
        $actor=User::withoutGlobalScopes()->find(1);
        if(!$actor||$actor->account_type!=='admin'||!empty($actor->owner_resturant_id)){
            $counts['errors']=1;return $counts;
        }
        try{
            $actor=$this->ops->access->actor($actor);
            if(!$this->ops->access->permissions($actor)['can_checkout'])throw new RuntimeException('Owner cannot write attendance.');
        }catch(Throwable $error){$counts['errors']=1;return $counts;}

        $clock=now(OperatingDay::TIMEZONE)->copy();
        $today=OperatingDay::date($clock);
        $previous=OperatingDay::start($today)->subDay()->toDateString();
        DB::table('branch_employees')->where('active',true)->orderBy('id')->chunkById(100,function($employees)use(&$counts,$actor,$clock,$today,$previous,$startsOn,$dryRun){
            foreach($employees as $employee){
                foreach([$today,$previous] as $day){
                    $counts['considered']++;
                    try{
                        $this->assessment($employee,$day,$clock,$startsOn,false);
                        if($dryRun){
                            $this->ops->access->branch($employee->branch,$actor);
                            $counts['recorded']++;continue;
                        }
                        $values=['branch'=>$employee->branch,'employee_id'=>(int)$employee->id,'day'=>$day,
                            'idempotency_key'=>$this->requestKey((int)$employee->id,$day)];
                        $result=$this->ops->write('employee.automatic_absence',$values,$actor,function($branch,$owner)use($employee,$day,$clock,$startsOn){
                            // Same branch -> employee lock order as manual punches and payroll closing.
                            $fresh=DB::table('branch_employees')->where('id',$employee->id)->where('branch',$branch['value'])->lockForUpdate()->first();
                            if(!$fresh)throw new AutomaticAbsenceNotDue;
                            [$old,$snapshot,$deadline]=$this->assessment($fresh,$day,$clock,$startsOn,true);
                            $stamp=$clock->copy()->utc()->toDateTimeString();
                            $snapshot+=['automatically_recorded'=>true,'automatic_absence_policy'=>1,
                                'automatic_absence_deadline'=>$deadline->copy()->utc()->toDateTimeString()];
                            $data=['branch'=>$fresh->branch,'status'=>'unauthorized_absence',
                                'check_in'=>null,'check_out'=>null,'checked_in_at'=>null,'checked_out_at'=>null,
                                'attendance_rule_snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),
                                'notes'=>$old->notes??'','revision'=>$old?(int)$old->revision+1:1,
                                'actor_id'=>$owner->id,'updated_at'=>$stamp];
                            if($old)DB::table('branch_employee_days')->where('id',$old->id)->update($data);
                            else DB::table('branch_employee_days')->insert($data+['employee_id'=>$fresh->id,'day'=>$day,'created_at'=>$stamp]);
                            $this->deduction($fresh,$day,$snapshot,$owner,$stamp);
                            return ['recorded'=>true,'employee_id'=>(int)$fresh->id,'day'=>$day];
                        });
                        $counts[!empty($result['recorded'])&&empty($result['replayed'])?'recorded':'skipped']++;
                    }catch(AutomaticAbsenceNotDue $skip){$counts['skipped']++;}
                    catch(Throwable $error){$counts['errors']++;}
                }
            }
        });
        return $counts;
    }

    /** The standard 22:00 deadline never precedes a late employee's own scheduled shift. */
    public function deadline(object $employee,string $day): Carbon
    {
        $snapshot=$this->snapshot($employee,$day);
        return $this->snapshotDeadline($snapshot,$day);
    }

    private function snapshotDeadline(array $snapshot,string $day): Carbon
    {
        $cutoff=OperatingDay::at($day,'22:00');
        $start=Carbon::parse($snapshot['scheduled_start'],'UTC')->setTimezone(OperatingDay::TIMEZONE);
        return $start->greaterThanOrEqualTo($cutoff)
            ?Carbon::parse($snapshot['scheduled_end'],'UTC')->setTimezone(OperatingDay::TIMEZONE):$cutoff;
    }

    private function assessment(object $employee,string $day,Carbon $clock,string $startsOn,bool $lock): array
    {
        if($day<$startsOn)throw new AutomaticAbsenceNotDue;
        if(!(bool)$employee->active||substr($employee->hired_on,0,10)>$day
            ||(!empty($employee->left_on)&&substr($employee->left_on,0,10)<$day))throw new AutomaticAbsenceNotDue;
        if($clock->lessThan(OperatingDay::at($day,'22:00')))throw new AutomaticAbsenceNotDue;
        if(DB::table('branch_payrolls')->where('employee_id',$employee->id)->where('month',substr($day,0,7))->exists())throw new AutomaticAbsenceNotDue;
        $q=DB::table('branch_employee_days')->where('employee_id',$employee->id)->where('day',$day);
        if($lock)$q->lockForUpdate();
        $old=$q->first();
        if($old){
            if($old->branch!==$employee->branch||$old->status!=='unrecorded')throw new AutomaticAbsenceNotDue;
            foreach(['check_in','check_out','checked_in_at','checked_out_at'] as $key){
                if($old->$key!==null)throw new AutomaticAbsenceNotDue;
            }
        }
        $snapshot=$this->snapshot($employee,$day);
        $deadline=$this->snapshotDeadline($snapshot,$day);
        if($clock->lessThan($deadline))throw new AutomaticAbsenceNotDue;
        if($day!==OperatingDay::date($clock)){
            // Only a shift continuing past 06:00 may finish on the previous operating day.
            // Do not manufacture past normal-shift absences after a deployment or outage.
            $start=Carbon::parse($snapshot['scheduled_start'],'UTC');
            if($start->lessThan(OperatingDay::at($day,'22:00'))||$deadline->lessThan(OperatingDay::end($day)))throw new AutomaticAbsenceNotDue;
        }
        return [$old,$snapshot,$deadline];
    }

    private function activationDay(): ?string
    {
        if(is_link($this->activationPath)||!is_file($this->activationPath)||!is_readable($this->activationPath))return null;
        $size=filesize($this->activationPath);
        if($size===false||$size>256)return null;
        $text=file_get_contents($this->activationPath);
        if($text===false)return null;
        $data=json_decode($text,true);
        if(!is_array($data)||array_keys($data)!==['starts_on']||!is_string($data['starts_on'])
            ||!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$data['starts_on']))return null;
        try{
            $day=Carbon::createFromFormat('!Y-m-d',$data['starts_on'],OperatingDay::TIMEZONE);
            return $day&&$day->format('Y-m-d')===$data['starts_on']?$data['starts_on']:null;
        }catch(Throwable $error){return null;}
    }

    private function snapshot(object $employee,string $day): array
    {
        $partTime=($employee->schedule_type??'branch')==='part_time';
        $shift=$partTime?($employee->attendance_shift??null)
            :(in_array($employee->shift,['evening','مسائي','مساء'],true)?'evening':'morning');
        if(!in_array($shift,['morning','evening'],true))throw new RuntimeException('Attendance shift is not configured.');
        $rule=DB::table('branch_attendance_rules')->where('branch',$employee->branch)->where('shift',$shift)->first();
        if(!$rule)throw new RuntimeException('Attendance rules are not configured.');
        $start=$partTime?($employee->attendance_start??null):$rule->starts_at;
        $end=$partTime?($employee->attendance_end??null):$rule->ends_at;
        foreach([$start,$end] as $time){
            if(!is_string($time)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D',$time))throw new RuntimeException('Invalid attendance hours.');
        }
        if(substr($start,0,5)===substr($end,0,5))throw new RuntimeException('Attendance hours have zero duration.');
        foreach(['absence_cents','late_half_hour_cents','early_half_hour_cents'] as $key){
            if(!is_numeric($rule->$key)||(int)$rule->$key<0||(float)$rule->$key!==(float)(int)$rule->$key)throw new RuntimeException('Invalid attendance rates.');
        }
        return EmployeeAttendanceRules::forEmployee($rule,$employee,$day);
    }

    private function deduction(object $employee,string $day,array $snapshot,$owner,string $stamp): void
    {
        $wanted=EmployeeAttendanceRules::deductions('unauthorized_absence',$snapshot,null,null,0,30)['absence'];
        $wanted['notes'].=' تسجيل تلقائي لعدم تسجيل حالة حضور الموظف حتى الموعد المحدد.';
        $q=DB::table('branch_employee_entries')->where('employee_id',$employee->id)->where('day',$day)->where('source_key','attendance.absence');
        $old=$q->lockForUpdate()->first();
        if($old&&($old->branch!==$employee->branch||$old->kind!=='deduction'))throw new RuntimeException('Invalid automatic absence ledger source.');
        // An Owner cancellation stays cancelled, including after a scheduler retry.
        if($old&&$old->voided_at)return;
        if($wanted['amount']<=0)return;
        if($old&&(int)$old->amount_cents===$wanted['amount'])return;
        $data=['amount_cents'=>$wanted['amount'],'reason'=>$wanted['reason'],'notes'=>$wanted['notes'],
            'revision'=>$old?(int)$old->revision+1:1,'actor_id'=>$owner->id,'updated_at'=>$stamp];
        if($old)$q->update($data);
        else $q->insert($data+['branch'=>$employee->branch,'employee_id'=>$employee->id,'day'=>$day,'kind'=>'deduction',
            'source_key'=>'attendance.absence','created_at'=>$stamp]);
    }

    private function requestKey(int $employee,string $day): string
    {
        // RFC 4122 UUIDv5 under the URL namespace; retries share one immutable receipt.
        $hash=sha1(hex2bin('6ba7b8119dad11d180b400c04fd430c8').'fasakhansta:employee-auto-absence:v1:'.$employee.':'.$day);
        return substr($hash,0,8).'-'.substr($hash,8,4).'-5'.substr($hash,13,3).'-'
            .dechex((hexdec($hash[16])&3)|8).substr($hash,17,3).'-'.substr($hash,20,12);
    }
}

/** Roll back a no-longer-due candidate so it does not consume its operation receipt. */
class AutomaticAbsenceNotDue extends RuntimeException {}
