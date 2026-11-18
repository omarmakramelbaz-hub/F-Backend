@php
    // Keep the legacy order's saved quantities and its existing money accessors.
    $legacyMoney = static fn ($value) => number_format((float) $value, 2, '.', '');
    $legacyItems = [];
    foreach ($invoice->carts as $line) {
        $options = [];
        if (!empty($line->product_feature)) {
            $feature = \App\Models\ProductFeature::where('id', $line->product_feature)->first();
            if ($feature) $options[] = __('main.'.$feature->name);
        }
        if (in_array($line->product_clean ?? '', ['extra_clean', 'extra_clear', 'extra_vacuim'], true)) $options[] = __('main.'.$line->product_clean);
        $legacyItems[] = ['name' => $line->resturant_product?->product_name ?? '', 'option_label' => implode(' · ', $options),
            'quantity' => $line->qty, 'quantity_unit' => __('takeaway.piece'),
            'unit_price' => $legacyMoney($line->price), 'total' => $legacyMoney($line->price * $line->qty)];
    }
    $address = $invoice->user_address;
    $legacyAddress = array_filter([
        $address?->area_name, $address?->street_name, $address?->address_name,
        filled($address?->floor_no) ? __('receipt.floor_no').': '.$address->floor_no : null,
        filled($address?->apartment_no) ? __('receipt.apartment_no').': '.$address->apartment_no : null,
    ], static fn ($part) => $part !== null && $part !== '');
    $receipt = [
        'number' => $invoice->order_no, 'created_at' => $invoice->created_at,
        'channel' => 'app', 'channel_label' => __('receipt.app'), 'print_marker' => 'legacy-order',
        'branch' => ['name' => $invoice->resturant?->name ?? ''], 'cashier' => ['name' => ''],
        'context' => ['customer_name' => $invoice->user?->name ?? '',
            'customer_phone' => !empty($invoice->user?->mobile) ? '0'.$invoice->user->mobile : '',
            'address' => implode('، ', $legacyAddress)],
        'items' => $legacyItems, 'payment_method' => $invoice->payment_type,
        'payment_label' => __('main.'.$invoice->payment_type), 'show_cash_tender' => false,
        'subtotal' => $legacyMoney($invoice->total), 'discount' => '0.00',
        'delivery' => $legacyMoney($invoice->delivery_price), 'service' => $legacyMoney($invoice->service_fees),
        'tax' => $legacyMoney($invoice->user_tax), 'total' => $legacyMoney($invoice->grand_total),
    ];
    $restaurant = $invoice->resturant;
    if ($restaurant && method_exists($restaurant, 'getFirstMediaUrl')) {
        $legacyLogo = $restaurant->getFirstMediaUrl('logo', 'thumb');
        if ($legacyLogo !== '') $receipt['logo_url'] = $legacyLogo;
    }
@endphp
@include('admin.printing.receipt', ['receipt' => $receipt, 'kitchenCopy' => false, 'preview' => false])
