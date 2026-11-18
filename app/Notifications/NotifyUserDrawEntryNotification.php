<?php

namespace App\Notifications;

use App\Http\Traits\FcmFirebase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * «مسابقة السحب» by products: the customer bought a draw product and is in the draw. The notice carries the entry codes
 * to follow the draw with; the winning code shows on the draw screen once it is drawn.
 */
class NotifyUserDrawEntryNotification extends Notification
{
    use Queueable;
    use FcmFirebase;

    public function __construct(private object $wheel, private array $codes) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        $prize = $this->wheel->prize_amount === null ? '' : ' بقيمة '.rtrim(rtrim(number_format((float) $this->wheel->prize_amount, 2, '.', ','), '0'), '.').' جنيه';
        $count = count($this->codes);
        $codes = implode(' - ', array_slice($this->codes, 0, 10)).($count > 10 ? ' و'.($count - 10).' غيرهم' : '');
        $this->body_data = [
            'title' => 'دخلت مسابقة السحب '.(string) $this->wheel->name,
            'text' => 'مبروك! شراؤك أهّلك لسحب'.$prize.'. '.($count === 1 ? 'كود الدخول: ' : 'أكواد الدخول ('.$count.'): ').$codes
                .'. تابع الكود الفائز على شاشة المسابقة.',
            'created_at' => now(),
            'data' => [
                'notification_type' => 2,
                'coupon_wheel_id' => (int) $this->wheel->id,
                'user_coupon_codes' => array_values($this->codes),
                'user_coupon_code' => $this->codes[0] ?? null,
                'user_id' => $notifiable->id,
                'account_type' => $notifiable->account_type,
            ],
        ];
        if ($notifiable->my_tokens) {
            try { $this->sendFcmNotification($notifiable->my_tokens, $this->body_data); }
            catch (\Throwable $error) { \Log::warning('Draw entry push unavailable', ['user_id' => (int) $notifiable->id, 'error_class' => get_class($error)]); }
        }
        return $this->body_data;
    }
}
