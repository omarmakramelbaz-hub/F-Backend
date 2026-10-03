<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Order;
use App\Http\Traits\FcmFirebase;

class NotifyOrderPriceTransferToWalletNotification extends Notification
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
    public function __construct(Order $msg,$price)
    {
        $this->msg=$msg;
        $this->price=$price;
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
        if($this->msg->status=='declined'){
            $text="تم تحويل إجمالى سعر الطلب رقم ".$this->msg->order_no;
        }else{
            $text=  'تم إرسال المبلغ الزائد من قبل الطلب رقم   ' . $this->msg->order_no . ' بقيمة '. $this->price .' ج.م';
        }
        $this->body_data =[
                'title'     =>  'لديك إشعار جديد ',
                'logo'     => $this->msg?->resturant?->getFirstMediaUrl('logo','thumb'),
                'text'      =>$text,
                'created_at' => $this->msg->created_at,
                'data'      => [ 
                    'notification_type' => 1,
                    'order_id'          => (int) $this->msg->id,
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
            app(\App\Services\Dashboard\OrderProviderDelivery::class)->push($this, $tokens, $this->body_data, (int) $this->msg->id);
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
