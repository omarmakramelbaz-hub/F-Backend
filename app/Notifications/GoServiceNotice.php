<?php
namespace App\Notifications;
use App\Http\Traits\FcmFirebase;
use Illuminate\Notifications\Notification;
class GoServiceNotice extends Notification {
 use FcmFirebase;
 public function __construct(private object $event){}
 public function via($notifiable){return ['database'];}
 public function toDatabase($notifiable){
  $body=['title'=>'GO — طلب خدمة','text'=>$this->event->message,'created_at'=>now(),'data'=>['notification_type'=>7,'go_service_job_id'=>(int)$this->event->job_id,'event_id'=>$this->event->event_key,'account_type'=>$notifiable->account_type,'notification_sound'=>'long']];
  if($notifiable->my_tokens)$this->sendFcmNotification($notifiable->my_tokens,$body);return $body;
 }
}
