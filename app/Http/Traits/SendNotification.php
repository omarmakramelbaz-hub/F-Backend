<?php

namespace App\Http\Traits;

use App\Models\User;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

trait SendNotification
{
    public function sendNotification(string $modelNotification, $notifiable = [])
    {
        $notificationClass = 'App\\Notifications\\' . $modelNotification;

        if (!is_subclass_of($notificationClass, BaseNotification::class)) {
            throw new InvalidArgumentException('Unknown notification class.');
        }

        $admins = User::where('account_type', 'admin')
            ->where('roles_name', '["Super Admin"]')->get();

        foreach ($admins as $admin) {
            Notification::send($admin, new $notificationClass($notifiable));
        }
    }
}
