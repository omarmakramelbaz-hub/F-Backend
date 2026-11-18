<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\User;
use App\Http\Traits\FcmFirebase;

class NotifyTransferWallet extends Notification
{
    use Queueable;
    use FcmFirebase;
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    private $msg;
    private $price;
     private $name;
    public function __construct(User $msg,$price)
    {
        $this->msg=$msg;
        $this->price=$price;
        $this->name=$msg->account_type=='admin'?'من محفطة التطبيق':"من المستخدم ".$this->msg->name;
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
        
        $this->body_data =[
                'title'     =>  'لديك إشعار جديد ',
                'logo'     => $this->msg?->getFirstMediaUrl('photo_profile','thumb'),
                'text'      =>  "تم تحويل مبلغ قيمتة ".$this->price .$this->name ,
                'created_at' => $this->msg->created_at,
                'data'      => [ 
                    'notification_type' => 3,
                    'account_type'=>$notifiable->account_type,
                    'notification_sound' => 'long',

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


        if(!config('desktop_dashboard.local')&&$notifiable->my_tokens){
            $tokens= $notifiable->my_tokens ; 
            app(\App\Services\Dashboard\OrderProviderDelivery::class)->push($this,$tokens,$this->body_data);
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
