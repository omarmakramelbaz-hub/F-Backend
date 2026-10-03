<?php

namespace App\Services\Dashboard;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Commit order/notification evidence now; contact providers after the response is sent. */
class OrderProviderDelivery
{
    public function defer(callable $work, ?int $orderId = null, string $kind = 'notice'): void
    {
        $ran = false;
        $deliver = function () use ($work, $orderId, $kind, &$ran): void {
            if ($ran) return;
            $ran = true;
            try {
                $work();
            } catch (\Throwable $error) {
                // Provider exception messages may contain credentials, tokens, or customer details.
                Log::warning('Order provider delivery unavailable', [
                    'order_id' => $orderId, 'provider' => $kind, 'exception' => get_class($error),
                ]);
            }
        };
        $register = fn () => app()->terminating($deliver);
        if (DB::transactionLevel() > 0) DB::afterCommit($register);
        else $register();
    }

    /** Arrays are captured before a notification instance is reused for another recipient. */
    public function push(object $notification, $tokens, array $body, ?int $orderId = null): void
    {
        if (empty($tokens)) return;
        $sender = clone $notification;
        $this->defer(function () use ($sender, $tokens, $body) {
            $response = $sender->sendFcmNotification($tokens, $body);
            if ($response instanceof \Illuminate\Http\JsonResponse) {
                $result = $response->getData(true);
                $responses = $result['responses'] ?? null;
                if ($response->getStatusCode() >= 400 || !empty($result['errors']) || (is_array($responses)
                    && (!$responses || array_filter($responses, fn ($receipt) => !is_array($receipt) || empty($receipt['name']) || !empty($receipt['error']))))) {
                    throw new \RuntimeException('push_provider_refusal');
                }
            }
        }, $orderId, 'push');
    }

    public function event(object $event, ?int $orderId = null): void
    {
        $snapshot = clone $event;
        foreach (get_object_vars($snapshot) as $property => $value) {
            if ($value instanceof Model) $snapshot->{$property} = clone $value;
        }
        $this->defer(fn () => event($snapshot), $orderId, 'broadcast');
    }
}
