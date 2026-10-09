<?php
namespace App\Services\Dashboard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Claims are never automatically recycled: a crashed printer needs explicit review. */
class PosBranchPrinting
{
    private TakeawayAccess $access;
    public function __construct(TakeawayAccess $access) { $this->access = $access; }
    public function enqueue(string $branch, int $ticketId, int $kitchenId): void
    {
        abort_unless(Schema::hasTable('pos_branch_print_jobs'),503,'طباعة الفرع لم تُجهز بعد.');
        DB::table('pos_branch_print_jobs')->insertOrIgnore(['branch'=>$branch,'ticket_id'=>$ticketId,'kitchen_id'=>$kitchenId,
            'status'=>'pending','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    public function listing(array $values, $actor): array
    {
        $v=Validator::make($values,['branch'=>['required','regex:/^(f|gs):[1-9][0-9]{0,18}$/D']])->validate();
        $this->receiver($v['branch'],$actor);
        $jobs=DB::table('pos_branch_print_jobs as p')->join('pos_service_tickets as t','t.id','=','p.ticket_id')
            ->where('p.branch',$v['branch'])->where('t.branch',$v['branch'])->where('t.channel','phone')->where('t.status','!=','cancelled');
        $pending=(clone $jobs)->where('p.status','pending')->orderBy('p.id')->limit(10)->get(['p.id','p.ticket_id'])->map(fn($r)=>['id'=>(int)$r->id,'ticket_id'=>(int)$r->ticket_id])->all();
        $attention=(clone $jobs)->where(function($q){$q->where('p.status','failed')->orWhere(function($q){$q->where('p.status','claimed')->where('p.claimed_at','<',now('UTC')->subMinutes(2));});})->count();
        $latest=DB::table('pos_service_tickets')->where('branch',$v['branch'])->where('channel','phone')->max('id');
        // Alert the receiving branch for active call-center orders, independently of printing.
        $incoming=DB::table('pos_service_tickets as t')->join('users as u','u.id','=','t.actor_id')
            ->where('t.branch',$v['branch'])->where('t.channel','phone')->where('t.payment_status','unpaid')
            ->whereIn('t.status',['new','preparing'])->where('u.account_type','admin')
            ->where(function($q){$q->whereNull('u.owner_resturant_id')->orWhere('u.owner_resturant_id',0);})
            ->orderByDesc('t.id')->limit(100)->pluck('t.id')->map(fn($id)=>(int)$id)->all();
        return ['success'=>true,'branch'=>$v['branch'],'jobs'=>$pending,'attention'=>$attention,'latest_ticket_id'=>(int)$latest,'incoming_ticket_ids'=>$incoming];
    }
    public function claim(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:32','job_id'=>'required|integer|min:1','claim_token'=>'required|uuid'])->validate();
        $this->receiver($v['branch'],$actor);
        return DB::transaction(function()use($v,$actor){
            // Serialize with cancellation and other branch POS commands.
            $this->access->branch($v['branch'],$actor,true);
            $row=DB::table('pos_branch_print_jobs')->where('branch',$v['branch'])->where('id',$v['job_id'])->lockForUpdate()->first();abort_unless($row,404);
            $ticket=DB::table('pos_service_tickets')->where('branch',$v['branch'])->where('channel','phone')->where('id',$row->ticket_id)->first();abort_unless($ticket&&$ticket->status!=='cancelled',409);
            // Even the same token cannot invoke twice after an ambiguous response.
            abort_unless($row->status==='pending',409,'أمر الطباعة التقطه جهاز آخر أو يحتاج مراجعة.');
            DB::table('pos_branch_print_jobs')->where('id',$row->id)->update(['status'=>'claimed','claim_token'=>$v['claim_token'],'claimed_by'=>$actor->id,'claimed_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return ['success'=>true,'job_id'=>(int)$row->id,'ticket_id'=>(int)$row->ticket_id,'claim_token'=>$v['claim_token'],
                'print_url'=>route('phone-orders.kitchen',['id'=>$row->kitchen_id])];
        },3);
    }
    public function complete(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:32','job_id'=>'required|integer|min:1','claim_token'=>'required|uuid','result'=>'required|in:invoked,failed'])->validate();$this->receiver($v['branch'],$actor);
        return DB::transaction(function()use($v,$actor){
            $row=DB::table('pos_branch_print_jobs')->where('branch',$v['branch'])->where('id',$v['job_id'])->lockForUpdate()->first();abort_unless($row,404);
            abort_unless((int)$row->claimed_by===(int)$actor->id&&hash_equals($row->claim_token??'',$v['claim_token']),403);
            abort_unless($row->status==='claimed'||$row->status===$v['result'],409);
            if($row->status==='claimed')DB::table('pos_branch_print_jobs')->where('id',$row->id)->update(['status'=>$v['result'],'invoked_at'=>$v['result']==='invoked'?now('UTC'):null,'updated_at'=>now('UTC')]);
            return ['success'=>true,'status'=>$v['result']];
        },3);
    }
    private function receiver(string $branch,$actor): void
    {
        abort_unless(Schema::hasTable('pos_branch_print_jobs'),503);
        $this->access->branch($branch,$actor);
        abort_unless($this->access->receiverBranch($actor)===$branch,403,'الطباعة التلقائية من حساب الفرع فقط.');
    }
}
