<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\User;
use App\Http\Traits\FcmFirebase;

class NotifyDelegateStatusNotification extends Notification
{
    use Queueable;
    use FcmFirebase;
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    private $msg;
    public function __construct(User $msg)
    {
        $this->msg=$msg;
    }
    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['database'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toDatabase($notifiable)
    {
        $tokens = [];
        $types  = [];
        $title  = '';
        // Worded for whoever receives it: an admin reads whose account it is. It is sent by the daily balance jobs and the
        // wallet top-up, where nobody is signed in.
        if($notifiable->account_type == 'admin'){
            if($this->msg->status=='accepted'){
                $title="تم تفعيل حساب ". $this->msg->name ." الأن يمكنك إستقبال طلبات";
            }elseif($this->msg->status=='disabled'){
                $title="الحساب الخاص ب ".$this->msg->name." حاليا غير متاح لاستقبال طلبات جديدة عليك شحن المحفظة بالحد الأدني المطلوب";
            }
        }else{
            if($this->msg->status=='accepted'){
                $title="تم تفعيل الحساب الأن يمكنك إستقبال طلبات";
            }elseif($this->msg->status=='disabled'){
                $title="الحساب حاليا غير متاح لاستقبال طلبات جديدة عليك شحن المحفظة بالحد الأدني المطلوب";
            }
        }
    
        $this->body_data =[
                'title'     =>  'لديك إشعار جديد ',
                'logo'     => $this->msg?->getFirstMediaUrl('photo_profile','thumb'),
                'text'      =>$title,
                'created_at' => now(),
                'data'      => [ 
                    'notification_type' => 3,   //wallet screen
                    'account_type'=>$notifiable->account_type,
                    'notification_sound' => 'default',
                    ],
            ];    
            
        //  if($notifiable->device_token){
        //  $tokens= $notifiable->device_token ; 
        //   $this->sendFcmNotification( $tokens ,$this->body_data) ;
        // }
        // if($notifiable->fcm_id){
        //      $tokens= $notifiable->fcm_id ; 
        //       $this->sendFcmNotification( $tokens ,$this->body_data) ;
        // }


        if($notifiable->my_tokens){
            $tokens= $notifiable->my_tokens ; 
            $this->sendFcmNotification( $tokens ,$this->body_data) ;
        }
        
      return $this->body_data;   
        
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
