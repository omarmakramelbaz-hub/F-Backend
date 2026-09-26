<?php
namespace App\Services\GoServices;
use App\Models\User;
use App\Notifications\GoServiceNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
class Outbox {
 public function flush(int $limit=50):void {
  $events=DB::table('go_service_outbox')->whereNull('sent_at')->where('available_at','<=',now())->orderBy('id')->limit($limit)->get();
  foreach($events as $e){
   $claim=DB::table('go_service_outbox')->where('id',$e->id)->whereNull('sent_at')->where('available_at','<=',now())->update(['available_at'=>now()->addMinutes(2),'attempts'=>DB::raw('attempts + 1')]);if(!$claim)continue;
   try{
    $u=User::withoutGlobalScopes()->find($e->user_id);
    if($u&&$u->status==='accepted'){
     $open=!str_contains($e->event_key,':invited:')||($u->connected==='active'&&DB::table('go_service_jobs')->where('id',$e->job_id)->where('status','searching')->exists()&&DB::table('go_service_recipients')->where('job_id',$e->job_id)->where('partner_id',$e->user_id)->where('status','invited')->exists());
     if($open)Notification::send($u,new GoServiceNotice($e));
    }
    DB::table('go_service_outbox')->where('id',$e->id)->update(['sent_at'=>now()]);
   }catch(\Throwable $error){DB::table('go_service_outbox')->where('id',$e->id)->update(['available_at'=>now()->addMinutes(min(60,2**min(5,(int)$e->attempts)))]);\Log::warning('GO service notification deferred',['event_id'=>$e->id]);}
  }
 }
}
