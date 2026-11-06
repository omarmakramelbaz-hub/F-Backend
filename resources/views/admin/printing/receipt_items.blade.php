<table class="ft-items @if(!$kitchenCopy && !$showUnitPrices) ft-no-price @endif">
    <colgroup><col class="ft-name-col"><col class="ft-qty-col">@if(!$kitchenCopy)@if($showUnitPrices)<col class="ft-price-col">@endif<col class="ft-amount-col">@endif</colgroup>
    <thead><tr><th scope="col">{{ __('takeaway.item') }}</th><th scope="col" class="ft-qty">{{ __('takeaway.quantity_short') }}</th>@if(!$kitchenCopy)@if($showUnitPrices)<th scope="col" class="ft-price">{{ __('takeaway.price') }}</th>@endif<th scope="col" class="ft-amount">{{ __('takeaway.line_total') }}</th>@endif</tr></thead>
    <tbody>
    @foreach($receipt['items'] as $item)
        @php
            $quantity = $item['display_quantity'] ?? (($item['quantity_mode'] ?? '') === 'piece' ? explode('.', (string) $item['quantity'])[0] : $item['quantity']);
            $unit = $item['quantity_unit'] ?? (isset($item['quantity_mode']) ? __('takeaway.'.($item['quantity_mode'] === 'weight' ? 'kg' : 'piece')) : '');
        @endphp
        <tr>
            <td><strong class="ft-item-name">{{ $item['name'] }}</strong>@if(!empty($item['option_label']))<small class="ft-option">{{ $item['option_label'] }}</small>@endif</td>
            <td class="ft-qty"><bdi>{{ $quantity }}</bdi>@if($unit !== '')<small class="ft-unit">{{ $unit }}</small>@endif</td>
            @if(!$kitchenCopy)
                @if($showUnitPrices)<td class="ft-price"><bdi>{{ $money($item['unit_price'] ?? null) }}</bdi></td>@endif
                <td class="ft-amount"><bdi>{{ $money($item['total'] ?? $item['line_total'] ?? null) }}</bdi></td>
            @endif
        </tr>
    @endforeach
    </tbody>
</table>
