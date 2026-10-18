<?php

// Exercise the production trait without booting Laravel or sending notifications.
namespace Illuminate\Notifications {
    abstract class Notification
    {
    }
}

namespace Illuminate\Support\Facades {
    class Notification
    {
        public static array $sent = [];

        public static function send($recipient, $notification): void
        {
            self::$sent[] = [$recipient, $notification];
        }
    }
}

namespace App\Models {
    class User
    {
        public static int $queries = 0;

        public static function where($column, $value): self
        {
            if (!(($column === 'account_type' && $value === 'admin')
                || ($column === 'roles_name' && $value === '["Super Admin"]'))) {
                throw new \RuntimeException('Administrator selection changed.');
            }

            ++self::$queries;

            return new self();
        }

        public function get(): array
        {
            return [(object) ['id' => 1], (object) ['id' => 2]];
        }
    }
}

namespace App\Notifications {
    class TestDispatchNotice extends \Illuminate\Notifications\Notification
    {
        public $payload;

        public function __construct($payload)
        {
            $this->payload = $payload;
        }
    }
}

namespace {
    require __DIR__ . '/../app/Http/Traits/SendNotification.php';

    $sender = new class {
        use \App\Http\Traits\SendNotification;
    };
    $payload = ['order_id' => 42];
    $sender->sendNotification('TestDispatchNotice', $payload);
    $sent = \Illuminate\Support\Facades\Notification::$sent;

    if (count($sent) !== 2 || $sent[0][0]->id !== 1 || $sent[1][0]->id !== 2) {
        throw new \RuntimeException('Each selected administrator must receive the notification.');
    }

    foreach ($sent as [$recipient, $notification]) {
        if (!$notification instanceof \App\Notifications\TestDispatchNotice || $notification->payload !== $payload) {
            throw new \RuntimeException('Notification class or payload was not preserved.');
        }
    }

    foreach (['UnknownNotice', '..\\Models\\Admin'] as $invalidClass) {
        try {
            $sender->sendNotification($invalidClass, $payload);
        } catch (\InvalidArgumentException $exception) {
            continue;
        }

        throw new \RuntimeException('An invalid notification class was accepted.');
    }

    if (\App\Models\User::$queries !== 2 || count(\Illuminate\Support\Facades\Notification::$sent) !== 2) {
        throw new \RuntimeException('Invalid input must not query recipients or send notifications.');
    }

    echo "PASS notification dispatch and invalid-class isolation\n";
}
