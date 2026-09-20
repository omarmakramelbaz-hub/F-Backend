<?php

namespace App\Notifications;

use App\Http\Traits\FcmFirebase;
use App\Models\PartnerServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NotifyPartnerServiceRequest extends Notification
{
    use Queueable, FcmFirebase;

    public function __construct(private PartnerServiceRequest $requestItem)
    {
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        $profession = \App\Http\Controllers\Api\V1\PartnerApplicationController::professions()[$this->requestItem->profession_key]['ar']
            ?? $this->requestItem->profession_key;

        $body = [
            'title' => 'لديك طلب خدمة جديد',
            'text' => 'تم إرسال طلب جديد في قسم ' . $profession,
            'created_at' => now(),
            'data' => [
                'notification_type' => 7,
                'partner_service_request_id' => (int) $this->requestItem->id,
                'profession_key' => $this->requestItem->profession_key,
                'customer_id' => (int) $this->requestItem->user_id,
                'account_type' => 'delegate',
                'notification_sound' => 'long',
            ],
        ];

        if ($notifiable->my_tokens) {
            $this->sendFcmNotification($notifiable->my_tokens, $body);
        }

        return $body;
    }

    public function toArray($notifiable)
    {
        return [];
    }
}
