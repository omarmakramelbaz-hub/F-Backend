<table class="ft-items @if(!$kitchenCopy && !$showUnitPrices) ft-no-price @endif">
    <colgroup><col class="ft-name-col"><col class="ft-qty-col">@if(!$kitchenCopy)@if($showUnitPrices)<col class="ft-price-col">@endif<col class="ft-amount-col">@endif</colgroup>
    <thead><tr><th scope="col">{{ __('takeaway.item') }}</th><th scope="col" class="ft-qty">{{ __('takeaway.quantity_short') }}</th>@if(!$kitchenCopy)@if($showUnitPrices)<th scope="col" class="ft-price">{{ __('takeaway.price') }}</th>@endif<th scope="col" class="ft-amount">{{ __('receipt.line_total_short') }}</th>@endif</tr></thead>
    <tbody>
    @foreach($receipt['items'] as $item)
        @php
            $quantity = $item['display_quantity'] ?? (($item['quantity_mode'] ?? '') === 'piece' ? explode('.', (string) $item['quantity'])[0] : $item['quantity']);
            if (strpos((string) $quantity, '.') !== false) $quantity = rtrim(rtrim((string) $quantity, '0'), '.');
            $unit = $item['quantity_unit'] ?? (isset($item['quantity_mode']) ? __('takeaway.'.($item['quantity_mode'] === 'weight' ? 'kg' : 'piece')) : '');
        @endphp
        <tr>
            <td><span class="ft-item-label"><strong class="ft-item-name">{{ $item['name'] }}</strong>@if(!empty($item['option_label']))<span class="ft-option"> · {{ $item['option_label'] }}</span>@endif</span></td>
            <td class="ft-qty"><span class="ft-cell-value"><bdi>{{ $quantity }}</bdi>@if($unit !== '') <span class="ft-unit">{{ $unit }}</span>@endif</span></td>
            @if(!$kitchenCopy)
                @if($showUnitPrices)<td class="ft-price"><span class="ft-cell-value"><bdi>{{ $compactMoney($item['unit_price'] ?? null) }}</bdi></span></td>@endif
                <td class="ft-amount"><span class="ft-cell-value"><bdi>{{ $compactMoney($item['total'] ?? $item['line_total'] ?? null) }}</bdi></span></td>
            @endif
        </tr>
    @endforeach
    </tbody>
</table>
