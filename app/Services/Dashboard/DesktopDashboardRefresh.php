<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Schema,Validator};

/** Durable write fence before staging refreshed data. It never replaces or deletes a ledger. */
class DesktopDashboardRefresh
{
    public function lock(string $device): ?object
    {
        if(!config('desktop_dashboard.local')||(string)config('desktop_dashboard.device_id')==='')return null;
        abort_unless((string)config('desktop_dashboard.device_id')===$device,403,'سجل العمليات يخص جهازًا آخر.');
        abort_unless(DB::transactionLevel()>0&&Schema::hasTable('desktop_dashboard_local_state'),503,'حالة بيانات الجهاز لم تُجهّز بعد.');
        $row=DB::table('desktop_dashboard_local_state')->where('device_id',$device)->lockForUpdate()->first();
        abort_unless($row,503,'حالة بيانات الجهاز لم تُجهّز بعد.');
        return $row;
    }

    public function writable(string $device,int $actor): void
    {
        $row=$this->lock($device);if(!$row)return;
        abort_unless((int)$row->actor_id===$actor,403,'بيانات الجهاز تخص حسابًا آخر.');
        abort_unless($row->state==='ready',409,'يجري تجهيز تحديث بيانات الجهاز؛ العملية لم تُنفّذ.');
    }

    public function begin(string $device,string $id,string $token): array
    {
        $this->validate($device,$id,$token);
        return DB::transaction(function()use($device,$id,$token){
            $row=$this->lock($device);abort_unless($row,403);
            // A lost native reply resumes the same fence; a different supervisor cannot steal it.
            $attempt=DB::table('desktop_dashboard_refreshes')->where('refresh_id',$id)->first();
            if($attempt){
                $this->owner($attempt,$device,$token);
                abort_if($attempt->state==='held'&&($row->state!=='held'||$row->refresh_id!==$id),409);
                return json_decode($attempt->result,true,512,JSON_THROW_ON_ERROR);
            }
            abort_unless($row->state==='ready',409);
            // Check every device row in this private database, not just a sendable outbox page.
            abort_if(DB::table('desktop_dashboard_commands')->where('status','!=','acknowledged')->exists(),409,'توجد عمليات معلقة أو متعارضة؛ بيانات الجهاز لم تتغير.');
            $sequence=(int)(DB::table('desktop_dashboard_commands')->max('sequence')??0);
            DB::table('desktop_dashboard_local_state')->where('device_id',$device)->update([
                'state'=>'held','refresh_id'=>$id,'refresh_token_hash'=>hash('sha256',$token),
                'fenced_sequence'=>$sequence,'updated_at'=>now('UTC'),
            ]);
            $result=$this->status(DB::table('desktop_dashboard_local_state')->where('device_id',$device)->first());
            DB::table('desktop_dashboard_refreshes')->insert(['refresh_id'=>$id,'device_id'=>$device,
                'token_hash'=>hash('sha256',$token),'state'=>'held','result'=>DesktopDashboardBootstrap::json($result),
                'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return $result;
        });
    }

    public function cancel(string $device,string $id,string $token): array
    {
        $this->validate($device,$id,$token);
        return DB::transaction(function()use($device,$id,$token){
            $row=$this->lock($device);abort_unless($row,403);
            $attempt=DB::table('desktop_dashboard_refreshes')->where('refresh_id',$id)->first();abort_unless($attempt,409);
            $this->owner($attempt,$device,$token);
            $result=json_decode($attempt->result,true,512,JSON_THROW_ON_ERROR);
            if($attempt->state==='cancelled')return $result;
            abort_unless($attempt->state==='held'&&$row->state==='held'&&$row->refresh_id===$id,409);
            // Keep the fence identity for a lost cancellation reply. No automatic timeout can
            // reopen writes while a slow staging import still owns the database generation.
            DB::table('desktop_dashboard_local_state')->where('device_id',$device)->update(['state'=>'ready','updated_at'=>now('UTC')]);
            $result['held']=false;
            DB::table('desktop_dashboard_refreshes')->where('refresh_id',$id)->update(['state'=>'cancelled',
                'result'=>DesktopDashboardBootstrap::json($result),'updated_at'=>now('UTC')]);
            return $result;
        });
    }

    private function validate(string $device,string $id,string $token): void
    {
        abort_unless(config('desktop_dashboard.local')&&DB::connection()->getConfig('host')==='127.0.0.1'
            &&(string)config('desktop_dashboard.device_id')===$device,403);
        Validator::make(['id'=>$id,'token'=>$token],['id'=>'required|uuid','token'=>'required|regex:/^[a-f0-9]{64}$/D'])->validate();
        abort_unless(DB::transactionLevel()===0,409);
    }
    private function owner(object $attempt,string $device,string $token): void
    {
        abort_unless($attempt->device_id===$device&&is_string($attempt->token_hash)
            &&hash_equals($attempt->token_hash,hash('sha256',$token)),409,'عملية تجهيز أخرى تملك تحديث بيانات الجهاز.');
    }
    private function status(object $row): array
    {
        return ['format'=>1,'device_id'=>$row->device_id,'actor_id'=>(int)$row->actor_id,
            'snapshot_id'=>$row->snapshot_id,'schema_hash'=>$row->schema_hash,
            'branches'=>json_decode($row->branches,true,512,JSON_THROW_ON_ERROR),
            'coverage'=>json_decode($row->coverage,true,512,JSON_THROW_ON_ERROR),
            'held'=>$row->state==='held','refresh_id'=>$row->refresh_id,'fenced_sequence'=>(int)$row->fenced_sequence];
    }
}
