<?php
namespace App\Http\Traits;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Notification;
trait SendNotification {

    public function sendNotification(string $modelNotification, $notifiable = [])
    {
        $notificationClass = 'App\\Notifications\\' . $modelNotification;

        if (!is_subclass_of($notificationClass, \Illuminate\Notifications\Notification::class)) {
            throw new \InvalidArgumentException('Unknown notification class.');
        }

        $admins = \App\Models\User::where('account_type', 'admin')
            ->where('roles_name', '["Super Admin"]')->get();

        foreach ($admins as $admin) {
            \Illuminate\Support\Facades\Notification::send($admin, new $notificationClass($notifiable));
        }
    }
}