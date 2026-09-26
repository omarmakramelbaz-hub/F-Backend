<?php
namespace App\Console\Commands;
use App\Services\GoServices\Marketplace;
use App\Services\GoServices\Outbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class GoServiceDispatch extends Command {
 protected $signature='go-services:dispatch';
 protected $description='Dispatch job waves, expire unpaid reservations, and deliver service notifications';
 public function handle(){
  if(!Schema::hasTable('go_service_jobs'))return 0;$m=new Marketplace();
  if(config('go_services.enabled'))DB::table('go_service_jobs')->where('status','searching')->where('next_dispatch_at','<=',now())->orderBy('id')->chunkById(100,function($jobs)use($m){foreach($jobs as $j){try{$m->distribute((int)$j->id);}catch(\Throwable $e){report($e);}}});
  // Settle obligations even when new job intake is disabled.
  DB::table('go_service_jobs')->where('status','booked')->where('payment_status','unpaid')->where('payment_due_at','<=',now())->orderBy('id')->chunkById(100,function($jobs)use($m){foreach($jobs as $j){try{$m->transition((int)$j->id,0,'cancelled','انتهت مهلة الدفع دون تأكيد.',true);}catch(\Throwable $e){report($e);}}});
  (new Outbox())->flush(100);return 0;
 }
}
