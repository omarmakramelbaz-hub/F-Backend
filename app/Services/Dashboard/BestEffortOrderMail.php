<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Order state and wallet changes remain authoritative when an email provider is unavailable. */
class BestEffortOrderMail
{
    public function send(int $orderId, string $view, array $data, callable $message): void
    {
        $deliver = function () use ($orderId, $view, $data, $message): void {
            try {
                Mail::send($view, $data, $message);
            } catch (\Throwable $error) {
                // Transport error messages can include account details; never log or return them.
                Log::warning('Order status email unavailable', [
                    'order_id'=>$orderId, 'exception'=>get_class($error),
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($deliver);
        } else {
            $deliver();
        }
    }
}
