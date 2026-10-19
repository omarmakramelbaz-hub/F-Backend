@php
    $receipt = $ticket;
    $receipt['number'] = $ticket['number'] ?? strtoupper($ticket['channel']).'-'.$ticket['id'];
    $receipt['cashier'] = $ticket['cashier'] ?? ['name' => $ticket['waiter_name'] ?? ''];
    $receipt['payment_method'] = 'unpaid';
    $receipt['payment_reference'] = '';
    $receipt['cash_received'] = '0.00';
    $receipt['change'] = '0.00';
    $receipt['unpaid'] = true;
    $receipt['print_marker'] = 'pos-ticket';
    $receipt['context'] = $ticket;
@endphp
@include('admin.takeaway.receipt', ['receipt' => $receipt])
