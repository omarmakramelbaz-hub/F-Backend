<?php
namespace App\Services\Dashboard;

use App\Services\GoServices\Money;

/** Read-only delivery breakdown from the original, settled POS receipts. */
final class PhoneDeliveryBatchReceipt
{
    public static function present(array $batch, iterable $receipts): array
    {
        $byId = [];
        foreach ($receipts as $receipt) $byId[(int) $receipt->id] = $receipt;

        $deliveryTotal = 0;
        $ordersTotal = 0;
        $complete = !empty($batch['items']);
        foreach ($batch['items'] as &$line) {
            $line['delivery'] = null;
            $line['total_without_delivery'] = null;
            $receipt = $byId[(int) ($line['order_id'] ?? 0)] ?? null;
            $total = Money::minor($line['total']);
            // Never borrow amounts from another ticket, branch, channel or a changed receipt.
            if (!$receipt || $receipt->branch !== $batch['branch']['value'] || $receipt->channel !== 'phone'
                || (int) $receipt->ticket_id !== (int) $line['ticket_id']
                || (int) $receipt->total_cents !== $total || !isset($receipt->delivery_cents)
                || (int) $receipt->delivery_cents < 0 || (int) $receipt->delivery_cents > $total) {
                $complete = false;
                continue;
            }
            $delivery = (int) $receipt->delivery_cents;
            $line['delivery'] = Money::decimal($delivery);
            $line['total_without_delivery'] = Money::decimal($total - $delivery);
            $deliveryTotal += $delivery;
            $ordersTotal += $total - $delivery;
        }
        unset($line);
        $complete = $complete && $ordersTotal + $deliveryTotal === Money::minor($batch['total']);
        $batch['delivery_breakdown_complete'] = $complete;
        $batch['delivery_total'] = $complete ? Money::decimal($deliveryTotal) : null;
        $batch['orders_total'] = $complete ? Money::decimal($ordersTotal) : null;
        return $batch;
    }
}
