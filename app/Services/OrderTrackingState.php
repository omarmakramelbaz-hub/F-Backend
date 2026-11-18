<?php

namespace App\Services;

/** One customer tracking identity and phase, independent of the dashboard's stored lifecycle. */
class OrderTrackingState
{
    public static function forOrder(object $order): array
    {
        $status = (string) ($order->status ?? '');
        $terminal = in_array($status, ['completed', 'delivered', 'cancelled', 'canceled', 'declined', 'rejected', 'expired'], true);
        if (in_array($status, ['completed', 'delivered'], true)) {
            $phase = 'delivered';
            $label = 'Delivered';
        } elseif ($terminal) {
            $phase = 'cancelled';
            $label = 'Cancelled';
        } elseif (in_array($status, ['shipped', 'out_for_delivery'], true)
            || ($status === 'accepted' && ($order->delegate_from_out ?? '') === 'in_resturant')) {
            $phase = 'on_the_way';
            $label = 'On the Way';
        } else {
            $phase = 'preparing';
            $label = 'Preparing';
        }
        $id = (int) $order->id;
        $number = trim((string) ($order->order_no ?? ''));
        $updated = $order->updated_at ?? null;
        $timestamp = $updated instanceof \DateTimeInterface ? $updated->getTimestamp() : strtotime((string) $updated);

        return [
            'order_id' => $id,
            'order_no' => $number !== '' ? $number : (string) $id,
            'status' => $status,
            'tracking_status' => $phase,
            'live_activity_status' => $label,
            'is_terminal' => $terminal,
            'updated_at' => $timestamp === false ? time() : $timestamp,
            'activity_id' => 'order_'.$id,
        ];
    }
}
