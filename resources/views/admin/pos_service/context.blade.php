<div><dt>{{ __('pos_service.channel') }}</dt><dd>{{ __('pos_service.'.($channel ?? 'takeaway')) }}</dd></div>
@if(($channel ?? '') === 'dine')
    @if(!empty($context['table']['name']))<div><dt>{{ __('pos_service.table') }}</dt><dd>{{ $context['table']['name'] }}</dd></div>@endif
    @if(!empty($context['waiter_name']))<div><dt>{{ __('pos_service.waiter') }}</dt><dd>{{ $context['waiter_name'] }}</dd></div>@endif
    @if(!empty($context['guest_count']))<div><dt>{{ __('pos_service.guests') }}</dt><dd><bdi>{{ $context['guest_count'] }}</bdi></dd></div>@endif
@elseif(($channel ?? '') === 'phone')
    @if(!empty($context['customer_name']))<div><dt>{{ __('pos_service.customer') }}</dt><dd>{{ $context['customer_name'] }}</dd></div>@endif
    @if(!empty($context['customer_phone']))<div><dt>{{ __('pos_service.phone_number') }}</dt><dd><bdi>{{ $context['customer_phone'] }}</bdi></dd></div>@endif
    @if(!empty($context['area']))<div><dt>{{ __('pos_service.area') }}</dt><dd>{{ $context['area'] }}</dd></div>@endif
    @if(!empty($context['address']))<div class="full-width"><dt>{{ __('pos_service.address') }}</dt><dd>{{ $context['address'] }}</dd></div>@endif
    @if(!empty($context['delivery_notes']))<div class="full-width"><dt>{{ __('pos_service.delivery_notes') }}</dt><dd>{{ $context['delivery_notes'] }}</dd></div>@endif
@endif
