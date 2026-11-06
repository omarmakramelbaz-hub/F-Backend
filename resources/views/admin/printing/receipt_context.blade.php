@if(!empty($context['customer_name']))<div><dt>{{ __('pos_service.customer') }}</dt><dd>{{ $context['customer_name'] }}</dd></div>@endif
@if(!empty($context['customer_phone']))<div><dt>{{ __('receipt.mobile_short') }}</dt><dd><bdi>{{ $context['customer_phone'] }}</bdi></dd></div>@endif
@if($channel === 'dine')
    @if(!empty($context['table']['name']))<div><dt>{{ __('pos_service.table') }}</dt><dd>{{ $context['table']['name'] }}</dd></div>@endif
    @if(!empty($context['waiter_name']))<div><dt>{{ __('pos_service.waiter') }}</dt><dd>{{ $context['waiter_name'] }}</dd></div>@endif
    @if(!empty($context['guest_count']))<div><dt>{{ __('pos_service.guests') }}</dt><dd><bdi>{{ $context['guest_count'] }}</bdi></dd></div>@endif
@endif
@if(!empty($context['area']))<div><dt>{{ __('pos_service.area') }}</dt><dd>{{ $context['area'] }}</dd></div>@endif
@if(!empty($context['address']))<div><dt>{{ __('receipt.address_short') }}</dt><dd>{{ $context['address'] }}</dd></div>@endif
@if(!empty($context['delivery_notes']))<div><dt>{{ __('pos_service.delivery_notes') }}</dt><dd>{{ $context['delivery_notes'] }}</dd></div>@endif
@if(!empty($context['delivery_company']['name']))<div><dt>{{ __('receipt.delivery_company') }}</dt><dd>{{ $context['delivery_company']['name'] }}@if(!empty($context['delivery_company']['phone'])) · <bdi>{{ $context['delivery_company']['phone'] }}</bdi>@endif</dd></div>@endif
@if(!empty($context['courier']['name']))<div><dt>{{ __('receipt.courier') }}</dt><dd>{{ $context['courier']['name'] }}@if(!empty($context['courier']['phone'])) · <bdi>{{ $context['courier']['phone'] }}</bdi>@endif</dd></div>@endif
