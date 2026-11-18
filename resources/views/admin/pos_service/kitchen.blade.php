@php
    $ticket = $kitchen['ticket'];
    $receipt = $ticket;
    $receipt['number'] = $ticket['number'] ?? $ticket['id'];
    $receipt['created_at'] = $kitchen['created_at'];
    $receipt['revision'] = $kitchen['revision'];
    $receipt['context'] = $ticket;
    $receipt['print_marker'] = 'pos-kitchen';
@endphp
@include('admin.printing.receipt', ['receipt' => $receipt, 'kitchenCopy' => true, 'preview' => false])
