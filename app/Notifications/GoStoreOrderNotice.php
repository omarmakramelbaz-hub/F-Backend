<?php
namespace App\Notifications;

use App\Http\Traits\FcmFirebase;
use Illuminate\Notifications\Notification;

class GoStoreOrderNotice extends Notification
{
    use FcmFirebase;
    public function __construct(private object $order, private bool $toStore) {}
    public function via($notifiable) { return ['database']; }
    public function toDatabase($notifiable)
    {
        $body = ['title' => 'GO — طلب متجر', 'text' => ($this->toStore && $this->order->status === 'pending' ? 'طلب جديد رقم GS-' : 'تحديث الطلب GS-').$this->order->id,
            'created_at' => now(), 'data' => ['notification_type' => 12, 'go_store_order_id' => (int)$this->order->id,
                'event_id' => 'store:'.$this->order->id.':'.$this->order->revision, 'account_type' => $notifiable->account_type,
                'notification_sound' => $this->toStore && $this->order->status === 'pending' ? 'long' : 'default']];
        try {
            if ((!$this->toStore || $notifiable->connected === 'active') && $notifiable->my_tokens) $this->sendFcmNotification($notifiable->my_tokens, $body);
        } catch (\Throwable $e) { \Log::warning('GO store push unavailable', ['order_id' => $this->order->id]); }
        return $body;
    }
}
