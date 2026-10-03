@extends('admin.index')

@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard/css/takeaway-pos.css') }}?v={{ filemtime(public_path('dashboard/css/takeaway-pos.css')) }}">
@endpush

@section('content')
<div class="content-wrapper takeaway-wrapper">
<main id="takeaway-pos" class="tp-pos" aria-labelledby="takeaway-title">
    <header class="tp-header">
        <div class="tp-heading"><h1 id="takeaway-title">{{ __('takeaway.title') }}</h1><p>{{ __('takeaway.subtitle') }}</p></div>
        <label class="tp-branch"><span class="tp-sr-only">{{ __('takeaway.branch') }}</span><i class="fas fa-map-marker-alt" aria-hidden="true"></i><select data-pos-branch>
            <option value="">{{ __('takeaway.select_branch') }}</option>
            @foreach($pos['branches'] ?? [] as $branch)
                <option value="{{ $branch['value'] ?? $branch['id'] }}" @if((string)($pos['selected_branch'] ?? '') === (string)($branch['value'] ?? $branch['id'])) selected @endif>{{ $branch['name'] }}</option>
            @endforeach
        </select></label>
        <div class="tp-cashier"><span class="tp-avatar"><i class="far fa-user" aria-hidden="true"></i></span><span><strong>{{ __('takeaway.cashier') }}</strong><small>{{ $pos['cashier']['name'] ?? auth('admin')->user()->name }}</small></span></div>
        <div class="tp-header-actions"><button type="button" data-pos-daily><i class="far fa-file-alt" aria-hidden="true"></i>{{ __('takeaway.daily_invoices') }}<strong data-pos-day-count hidden></strong></button><button type="button" data-pos-register><i class="fas fa-cash-register" aria-hidden="true"></i>{{ __('takeaway.cash_register') }}<bdi data-pos-register-balance>—</bdi></button></div>
    </header>
    <div class="tp-notice" data-pos-message role="status" aria-live="polite" hidden><span></span><button type="button" data-pos-retry hidden>{{ __('takeaway.retry') }}</button></div>
    <div class="tp-workspace">
        <section class="tp-invoice tp-panel" aria-labelledby="invoice-title">
            <header class="tp-panel-heading"><h2 id="invoice-title"><i class="far fa-file-alt" aria-hidden="true"></i>{{ __('takeaway.current_invoice') }}</h2><button type="button" class="tp-clear" data-pos-clear><i class="far fa-trash-alt" aria-hidden="true"></i>{{ __('takeaway.clear_invoice') }}</button></header>
            <div class="tp-invoice-scroll">
            <div class="tp-invoice-lines"><table><colgroup><col class="tp-col-item"><col class="tp-col-quantity"><col class="tp-col-price"><col class="tp-col-total"><col class="tp-col-remove"></colgroup><thead><tr><th>{{ __('takeaway.item') }}</th><th>{{ __('takeaway.quantity') }}</th><th>{{ __('takeaway.price') }}</th><th>{{ __('takeaway.line_total') }}</th><th><span class="tp-sr-only">{{ __('takeaway.remove_item') }}</span></th></tr></thead><tbody data-pos-lines></tbody></table><p class="tp-empty" data-pos-empty-invoice>{{ __('takeaway.empty_invoice') }}</p></div>
            <label class="tp-notes"><span><i class="fas fa-plus" aria-hidden="true"></i>{{ __('takeaway.order_notes') }}</span><textarea data-pos-notes rows="1" maxlength="500" placeholder="{{ __('takeaway.notes_placeholder') }}"></textarea></label>
            <dl class="tp-totals"><div><dt>{{ __('takeaway.subtotal') }}</dt><dd><bdi data-pos-total="subtotal">—</bdi></dd></div><div><dt>{{ __('takeaway.discount') }}</dt><dd><bdi data-pos-total="discount">—</bdi><input class="tp-inline-discount" data-pos-discount data-pos-discount-wrap hidden aria-label="{{ __('takeaway.discount') }}" inputmode="decimal" type="text" value="0.00" maxlength="14" autocomplete="off"></dd></div><div><dt>{{ __('takeaway.tax') }} <span data-pos-tax-rate></span></dt><dd><bdi data-pos-total="tax">—</bdi></dd></div><div data-pos-service-row hidden><dt>{{ __('takeaway.service') }}</dt><dd><bdi data-pos-total="service">—</bdi></dd></div><div class="tp-grand-total"><dt>{{ __('takeaway.total') }}</dt><dd><strong><bdi data-pos-total="total">—</bdi></strong><small>{{ __('takeaway.currency') }}</small></dd></div></dl>
            <label class="tp-payment-reference" data-pos-discount-reason-wrap hidden><span>{{ __('takeaway.discount_reason') }}</span><input data-pos-discount-reason type="text" maxlength="500" autocomplete="off"></label>
            <fieldset class="tp-payments"><legend>{{ __('takeaway.payment_method') }}</legend><div data-pos-payments></div></fieldset>
            <label class="tp-payment-reference" data-pos-cash-wrap><span>{{ __('takeaway.cash_received') }} <bdi data-pos-change></bdi></span><input data-pos-cash-received type="text" inputmode="decimal" maxlength="14" autocomplete="off" placeholder="{{ __('takeaway.cash_received_placeholder') }}"></label>
            <label class="tp-payment-confirmation" data-pos-payment-confirm-wrap hidden><input type="checkbox" data-pos-payment-confirmed><span>{{ __('takeaway.payment_confirmed') }}</span></label>
            <label class="tp-payment-reference" data-pos-reference-wrap hidden><span>{{ __('takeaway.payment_reference') }}</span><input data-pos-payment-reference type="text" maxlength="100" autocomplete="off" placeholder="{{ __('takeaway.payment_reference_placeholder') }}"></label>
            </div>
            <button type="button" class="tp-finish" data-pos-finish disabled><i class="fas fa-print" aria-hidden="true"></i><span>{{ __('takeaway.finish_sale') }}</span><kbd>F9</kbd></button>
            <div class="tp-saved" data-pos-saved hidden><span></span><a data-pos-reprint target="_blank" rel="noopener">{{ __('takeaway.print_invoice') }}</a><button type="button" data-pos-new>{{ __('takeaway.new_invoice') }}</button></div>
        </section>
        <section class="tp-catalog" aria-labelledby="catalog-title">
            <header class="tp-catalog-heading"><h2 id="catalog-title"><i class="fas fa-th-large" aria-hidden="true"></i>{{ __('takeaway.products') }}</h2><label class="tp-search"><span class="tp-sr-only">{{ __('takeaway.search') }}</span><i class="fas fa-search" aria-hidden="true"></i><input data-pos-search type="search" placeholder="{{ __('takeaway.search') }}" autocomplete="off"></label></header>
            <div class="tp-categories" data-pos-categories role="group" aria-label="{{ __('takeaway.products') }}"></div>
            <div class="tp-products" data-pos-products aria-live="polite"></div>
            <p class="tp-catalog-message" data-pos-catalog-message>{{ __('takeaway.choose_branch_first') }}</p>
            <nav class="tp-pagination" data-pos-pagination aria-label="{{ __('takeaway.products') }}" hidden><button type="button" data-pos-page="previous">{{ __('takeaway.previous') }}</button><span data-pos-page-label></span><button type="button" data-pos-page="next">{{ __('takeaway.next') }}</button></nav>
        </section>
        <aside class="tp-weight tp-panel" aria-labelledby="quantity-title">
            <div class="tp-unit-toggle" role="group" aria-label="{{ __('takeaway.quantity') }}"><button type="button" data-pos-unit="piece" aria-pressed="true">{{ __('takeaway.piece') }}</button><button type="button" data-pos-unit="weight" aria-pressed="false">{{ __('takeaway.weight') }}</button></div>
            <div class="tp-weight-display"><span id="quantity-title" data-pos-quantity-title>{{ __('takeaway.current_quantity') }}</span><output data-pos-quantity aria-live="polite"><bdi>1</bdi></output><small data-pos-quantity-unit>{{ __('takeaway.piece') }}</small></div>
            <div class="tp-keypad" role="group" aria-label="{{ __('takeaway.quantity') }}">
                @foreach(['7','8','9','backspace','4','5','6','clear','1','2','3','confirm','.','0'] as $key)
                    <button type="button" data-pos-key="{{ $key }}" @if($key === 'confirm') class="tp-key-confirm" @endif @if($key === 'backspace') aria-label="{{ __('takeaway.remove_item') }}" @endif>@if($key === 'backspace')<i class="fas fa-backspace" aria-hidden="true"></i>@elseif($key === 'clear')<span class="tp-key-clear">C</span>@elseif($key === 'confirm')<span data-pos-confirm-label>{{ __('takeaway.confirm_quantity') }}</span><i class="fas fa-check" aria-hidden="true"></i>@else{{ $key }}@endif</button>
                @endforeach
            </div>
            <div class="tp-weight-hint"><i class="far fa-lightbulb" aria-hidden="true"></i><p>{{ __('takeaway.quantity_hint') }}</p></div>
            <button type="button" class="tp-reset-weight" data-pos-weight-reset>{{ __('takeaway.clear_quantity') }} <i class="fas fa-undo" aria-hidden="true"></i></button>
        </aside>
    </div>
    <div class="tp-modal-backdrop" data-pos-modal hidden><section class="tp-modal tp-panel" role="dialog" aria-modal="true" aria-labelledby="pos-modal-title" tabindex="-1"><header class="tp-panel-heading"><h2 id="pos-modal-title"></h2><button type="button" data-pos-modal-close aria-label="{{ __('takeaway.close') }}"><i class="fas fa-times" aria-hidden="true"></i></button></header><div class="tp-modal-content" data-pos-modal-content></div></section></div>
</main>
</div>
@endsection

@push('custom-js')
<script type="application/json" id="takeaway-bootstrap">@json($pos)</script>
<script type="application/json" id="takeaway-translations">@json(trans('takeaway'))</script>
<script src="{{ asset('dashboard/js/takeaway-pos.js') }}?v={{ filemtime(public_path('dashboard/js/takeaway-pos.js')) }}"></script>
@endpush
