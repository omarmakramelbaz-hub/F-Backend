<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Schema,Validator};

/** A branch device claims each immutable shift report once. Reprints require management. */
class BranchShiftPrinting
{
    public function __construct(private TakeawayAccess $access) {}
    public function enqueue(int $closingId,int $actorId,string $key): void
    {
        abort_unless(Schema::hasTable('branch_shift_print_jobs'),503,'طباعة قفلة الفرع تحتاج تحديث قاعدة البيانات.');
        $closing=DB::table('branch_shift_closings')->where('id',$closingId)->first();abort_unless($closing,404);
        DB::table('branch_shift_print_jobs')->insertOrIgnore(['closing_id'=>$closingId,'branch'=>$closing->branch,'actor_id'=>$actorId,'request_key'=>$key,'status'=>DesktopDashboardPrintOwnership::owns('shift_report')?'device_owned':'pending','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        $job=DB::table('branch_shift_print_jobs')->where('actor_id',$actorId)->where('request_key',$key)->first();
        abort_unless($job&&(int)$job->closing_id===$closingId,409,'رقم أمر الطباعة مستخدم لقفلة أخرى.');
    }
    public function reprint(array $values,$actor): array
    {
        abort_unless(app(BranchShiftClosing::class)->canClose($actor),403);$actor=$this->access->actor($actor);
        $v=Validator::make($values,['closing_id'=>'required|integer|min:1','idempotency_key'=>'required|uuid'])->validate();
        app(BranchShiftClosing::class)->receipt((int)$v['closing_id'],$actor);
        return DB::transaction(function()use($v,$actor){$this->enqueue((int)$v['closing_id'],(int)$actor->id,$v['idempotency_key']);return ['success'=>true,'queued'=>true];},3);
    }
    private function receiver(string $branch,$actor)
    {
        $actor=$this->access->actor($actor);$this->access->branch($branch,$actor);
        abort_unless($this->access->receiverBranch($actor)===$branch,403,'طباعة القفلة من جهاز الفرع المحدد فقط.');return $actor;
    }
    public function listing(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:40'])->validate();$this->receiver($v['branch'],$actor);
        if(!Schema::hasTable('branch_shift_print_jobs'))return ['success'=>true,'jobs'=>[],'attention'=>0];
        $q=DB::table('branch_shift_print_jobs')->where('branch',$v['branch']);
        $jobs=(clone $q)->where('status','pending')->orderBy('id')->limit(10)->get(['id'])->map(fn($r)=>['id'=>(int)$r->id])->all();
        $attention=(clone $q)->where(function($q){$q->where('status','failed')->orWhere(function($q){$q->where('status','claimed')->where('claimed_at','<',now('UTC')->subMinutes(2));});})->count();
        return ['success'=>true,'jobs'=>$jobs,'attention'=>$attention];
    }
    public function claim(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:40','job_id'=>'required|integer|min:1','claim_token'=>'required|uuid'])->validate();$actor=$this->receiver($v['branch'],$actor);
        return DB::transaction(function()use($v,$actor){
            $row=DB::table('branch_shift_print_jobs')->where('branch',$v['branch'])->where('id',$v['job_id'])->lockForUpdate()->first();abort_unless($row,404);
            abort_unless($row->status==='pending',409,'أمر الطباعة التقطه جهاز آخر أو يحتاج مراجعة.');
            DB::table('branch_shift_print_jobs')->where('id',$row->id)->update(['status'=>'claimed','claimed_by'=>$actor->id,'claim_token'=>$v['claim_token'],'claimed_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return ['success'=>true,'print_url'=>route('branch-shift-print.receipt',['id'=>$row->id,'claim_token'=>$v['claim_token']])];
        },3);
    }
    public function complete(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:40','job_id'=>'required|integer|min:1','claim_token'=>'required|uuid','result'=>'required|in:invoked,failed'])->validate();$actor=$this->receiver($v['branch'],$actor);
        return DB::transaction(function()use($v,$actor){
            $row=DB::table('branch_shift_print_jobs')->where('branch',$v['branch'])->where('id',$v['job_id'])->lockForUpdate()->first();abort_unless($row,404);$this->claimed($row,$v['claim_token'],$actor);
            abort_unless($row->status==='claimed'||$row->status===$v['result'],409);
            if($row->status==='claimed')DB::table('branch_shift_print_jobs')->where('id',$row->id)->update(['status'=>$v['result'],'invoked_at'=>$v['result']==='invoked'?now('UTC'):null,'updated_at'=>now('UTC')]);
            return ['success'=>true,'status'=>$v['result']];
        },3);
    }
    private function claimed($row,string $token,$actor): void
    {
        abort_unless((int)$row->claimed_by===(int)$actor->id&&hash_equals((string)$row->claim_token,$token),403);
    }
    public function receipt(int $jobId,string $token,$actor): array
    {
        $row=DB::table('branch_shift_print_jobs')->where('id',$jobId)->first();abort_unless($row,404);$actor=$this->receiver($row->branch,$actor);$this->claimed($row,$token,$actor);
        abort_unless($row->status==='claimed',409);
        $closing=DB::table('branch_shift_closings')->where('id',$row->closing_id)->where('branch',$row->branch)->first();abort_unless($closing,404);
        return json_decode($closing->snapshot,true)+['number'=>'SHIFT-'.str_pad((string)$closing->id,6,'0',STR_PAD_LEFT),'sequence'=>(int)$closing->sequence];
    }
}
