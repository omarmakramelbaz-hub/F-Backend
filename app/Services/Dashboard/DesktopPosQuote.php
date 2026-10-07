<?php
namespace App\Services\Dashboard;

use App\Services\GoServices\Money;

/** Computes from a server-stored immutable catalog; the client supplies no prices or recipes. */
class DesktopPosQuote
{
    public function build(array $snapshot, array $cart, string $channel, int $delivery): array
    {
        abort_unless(in_array($channel, ['takeaway', 'dine', 'phone'], true), 422);
        abort_unless($delivery >= 0 && $delivery <= 100000000 && ($channel === 'phone' || $delivery === 0), 422);
        $variants = [];
        foreach ($snapshot['products'] as $product) foreach ($product['variants'] as $variant) {
            $variants[$product['id'].'|'.$variant['option_id']] = $variant + ['name'=>$product['name'], 'product_id'=>$product['id'], 'unit'=>$product['unit']];
        }
        $lines = []; $subtotal = 0;
        foreach ($cart['items'] as $item) {
            $v = $variants[$item['product_id'].'|'.$item['option_id']] ?? null;
            abort_unless($v && empty($item['feature_id']) && empty($item['product_clean']), 422, 'الصنف غير موجود في نسخة المينيو المحفوظة.');
            abort_unless($v['quantity_mode'] === 'select' || $v['quantity_mode'] === $item['quantity_mode'], 422, 'وحدة الصنف غير صحيحة.');
            $qty = $item['quantity_millis'];
            abort_unless($qty > 0 && $qty <= 1000000 && ($item['quantity_mode'] !== 'piece' || $qty % 1000 === 0), 422);
            $total = intdiv($v['unit_price_cents'] * $qty + 500, 1000); $subtotal += $total;
            $lines[] = ['product_id'=>$v['product_id'], 'name'=>$v['name'], 'option_id'=>$v['option_id'], 'option_label'=>$v['label'],
                'unit'=>$v['unit'], 'quantity_mode'=>$item['quantity_mode'], 'quantity_millis'=>$qty,
                'quantity'=>intdiv($qty,1000).'.'.str_pad((string)($qty%1000),3,'0',STR_PAD_LEFT),
                'unit_price_cents'=>$v['unit_price_cents'], 'total_cents'=>$total, 'inventory'=>$v['inventory']];
        }
        $discount = $cart['discount_cents'];
        abort_unless($lines && $subtotal <= 100000000 && $discount <= $subtotal, 422);
        abort_unless($discount === 0 || ($snapshot['can_discount'] && $cart['discount_reason'] !== ''), 403);
        $serviceBps = $channel === 'dine' ? $snapshot['service_bps'] : 0;
        $net = $subtotal - $discount; $service = Money::commission($net, $serviceBps);
        $tax = Money::commission($net + $service + $delivery, $snapshot['tax_bps']);
        $total = $net + $service + $delivery + $tax;
        abort_unless($total <= 100000000, 422);
        $q = ['items'=>$lines, 'branch'=>$snapshot['branch'], 'subtotal_cents'=>$subtotal, 'discount_cents'=>$discount,
            'tax_bps'=>$snapshot['tax_bps'], 'tax_cents'=>$tax, 'service_bps'=>$serviceBps, 'service_cents'=>$service,
            'delivery_cents'=>$delivery, 'total_cents'=>$total, 'tax_rate'=>Money::decimal($snapshot['tax_bps'])];
        $q['quote_hash'] = hash('sha256', json_encode($q, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $q;
    }
}
