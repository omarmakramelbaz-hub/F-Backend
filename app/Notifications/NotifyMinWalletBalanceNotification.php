<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\User;
use App\Http\Traits\FcmFirebase;

class NotifyMinWalletBalanceNotification extends Notification
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

        $diff = 2;//2 hour
        $this->body_data =[
                'title'     =>  'لديك إشعار جديد ',
                'logo'     => $notifiable->account_type=='vendor'?$this->msg->base_resturant?->getFirstMediaUrl('logo','thumb'):$this->msg->getFirstMediaUrl('photo_profile','thumb'),
                'text'      => 'رصيد محفظتك اصبح أقل من الحد الأدني المطلوب ، اشحن رصيدك في خلال فتره السماح وهيا '.  $diff. ' ساعه بدأ من الان',
                'created_at' => now(),
                'data'      => [ 
                    'notification_type' => 3,   //wallet screen
                    'account_type'=>$notifiable->account_type,
                    'notification_sound' => 'default',
                    ],
            ];    
            
        // if($notifiable->device_token){
        //  $tokens= $notifiable->device_token ; 
        //   $this->sendFcmNotification( $tokens ,$this->body_data) ;
        // }
        // if($notifiable->fcm_id){
        //      $tokens= $notifiable->fcm_id ; 
        //       $this->sendFcmNotification( $tokens ,$this->body_data) ;
        // }


        if($notifiable->my_tokens){
            $tokens= $notifiable->my_tokens ; 
            app(\App\Services\Dashboard\OrderProviderDelivery::class)->push($this, $tokens, $this->body_data);
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
