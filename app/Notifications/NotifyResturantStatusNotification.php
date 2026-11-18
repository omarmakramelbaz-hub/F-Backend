<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\User;
use App\Http\Traits\FcmFirebase;

class NotifyResturantStatusNotification extends Notification
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
        // Worded for whoever receives it (the daily balance job runs with nobody signed in): an admin reads the branch's name.
        if($notifiable->account_type == 'admin'){
            $text = 'المطعم '. $this->msg->base_resturant?->name. ' حاليا غير متاح لاستقبال طلبات جديدة عليك شحن المحفظة بالحد الأدني المطلوب ';
        }else{
            $text = 'المطعم حاليا غير متاح لاستقبال طلبات جديدة عليك شحن المحفظة بالحد الأدني المطلوب ';
        }
        $this->body_data =[
                'title'     =>  'لديك إشعار جديد ',
                'logo'     => $this->msg->base_resturant?->getFirstMediaUrl('logo','thumb'),
                'text'      => $text,
                'created_at' => now(),
                'data'      => [ 
                    'notification_type' => 3,   //wallet screen
                    'account_type'=>$notifiable->account_type,
                    'notification_sound' => 'default',
                    ],
            ];    
            
        //   if($notifiable->device_token){
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
