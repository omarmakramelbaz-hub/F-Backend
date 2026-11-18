@php
    $receipt = [
        'number' => $card['number'], 'created_at' => $card['created_at'], 'channel' => 'app',
        'channel_label' => $card['app_label'], 'print_marker' => 'order-board',
        'branch' => ['name' => $card['store']], 'cashier' => ['name' => ''],
        'context' => ['customer_name' => $card['customer'], 'customer_phone' => $card['phone'],
            'address' => $card['address'], 'courier' => $card['courier'] ?? null],
        'items' => $card['items'], 'show_unit_prices' => false,
        'summary_lines' => $card['totals'] ?? [], 'total' => $card['total'],
        'payment_method' => 'other', 'payment_label' => $card['payment_label'] ?: __('receipt.payment_unspecified'),
        'show_cash_tender' => false, 'payment_paid' => !empty($card['payment_paid']),
        'payment_failed' => !empty($card['payment_failed']), 'notes' => $card['notes'],
        'order_state' => $card['status_label'], 'can_reprint' => !empty($card['urls']['print']),
    ];
@endphp
@include('admin.printing.receipt', ['receipt' => $receipt, 'kitchenCopy' => false, 'preview' => false])
