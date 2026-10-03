@extends('admin.index')

@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard/css/dining-pos.css') }}?v={{ filemtime(public_path('dashboard/css/dining-pos.css')) }}">
@endpush

@section('content')
<div class="content-wrapper dining-wrapper">
<main id="dining-pos" data-dining-manage-till-url="{{ route('takeaway.index') }}" class="dp-pos" aria-labelledby="dining-title">
    <header class="dp-header">
        <div class="dp-heading"><h1 id="dining-title">{{ __('dining.title') }}</h1><p>{{ __('dining.subtitle') }}</p></div>
        <label class="dp-branch"><span class="dp-sr-only">{{ __('dining.branch') }}</span><i class="fas fa-map-marker-alt" aria-hidden="true"></i><select data-dining-branch>
            <option value="">{{ __('dining.select_branch') }}</option>
            @foreach($dining['branches'] ?? [] as $branch)
                <option value="{{ $branch['value'] ?? $branch['id'] }}" @if((string)($dining['selected_branch'] ?? '') === (string)($branch['value'] ?? $branch['id'])) selected @endif>{{ $branch['name'] }}</option>
            @endforeach
        </select></label>
        <div class="dp-cashier"><span class="dp-avatar"><i class="far fa-user" aria-hidden="true"></i></span><span><strong>{{ __('dining.cashier') }}</strong><small>{{ $dining['cashier']['name'] ?? auth('admin')->user()->name }}</small></span></div>
        <div class="dp-header-actions"><button type="button" data-dining-daily><i class="far fa-file-alt" aria-hidden="true"></i>{{ __('dining.daily_invoices') }}<strong data-dining-day-count hidden></strong></button><button type="button" data-dining-register><i class="fas fa-cash-register" aria-hidden="true"></i>{{ __('dining.cash_register') }}<bdi data-dining-register-balance>—</bdi></button><button type="button" data-dining-settings @if(empty($dining['permissions']['can_manage_tables'])) hidden @endif><i class="fas fa-sliders-h" aria-hidden="true"></i>{{ __('dining.floor_settings') }}</button></div>
    </header>
    <div class="dp-notice" data-dining-message role="status" aria-live="polite" hidden><span></span><button type="button" data-dining-retry hidden>{{ __('dining.retry') }}</button></div>
    <div class="dp-workspace">
        <section id="dining-invoice" class="dp-invoice dp-panel" aria-labelledby="invoice-title">
            <header class="dp-panel-heading"><h2 id="invoice-title"><i class="far fa-file-alt" aria-hidden="true"></i>{{ __('dining.current_invoice') }}</h2><button type="button" class="dp-clear" data-dining-clear><i class="far fa-trash-alt" aria-hidden="true"></i>{{ __('dining.clear_invoice') }}</button></header>
            <div class="dp-invoice-state" data-dining-state>{{ __('dining.select_table') }}</div>
            <div class="dp-invoice-scroll">
            <label class="dp-customer-name"><span>{{ __('dining.customer_name') }}</span><input data-dining-customer maxlength="100" placeholder="{{ __('dining.customer_name') }}"></label><div class="dp-table-meta"><label><span>{{ __('dining.waiter') }}</span><input data-dining-waiter maxlength="100" placeholder="{{ __('dining.waiter_placeholder') }}"></label><label><span>{{ __('dining.guests') }}</span><input data-dining-guests type="number" min="1" max="200" value="1"></label><label><span>{{ __('dining.table') }}</span><strong data-dining-table-number>—</strong></label></div>
            <div class="dp-invoice-lines"><table><colgroup><col class="dp-col-item"><col class="dp-col-quantity"><col class="dp-col-price"><col class="dp-col-total"><col class="dp-col-remove"></colgroup><thead><tr><th>{{ __('dining.item') }}</th><th>{{ __('dining.quantity') }}</th><th>{{ __('dining.price') }}</th><th>{{ __('dining.line_total') }}</th><th><span class="dp-sr-only">{{ __('dining.remove_item') }}</span></th></tr></thead><tbody data-dining-lines></tbody></table><p class="dp-empty" data-dining-empty-invoice>{{ __('dining.empty_invoice') }}</p></div>
            <label class="dp-notes"><span><i class="fas fa-plus" aria-hidden="true"></i>{{ __('dining.order_notes') }}</span><textarea data-dining-notes rows="1" maxlength="500" placeholder="{{ __('dining.notes_placeholder') }}"></textarea></label>
            <dl class="dp-totals"><div><dt>{{ __('dining.subtotal') }}</dt><dd><bdi data-dining-total="subtotal">—</bdi></dd></div><div><dt>{{ __('dining.discount') }}</dt><dd><bdi data-dining-total="discount">—</bdi><input class="dp-inline-discount" data-dining-discount data-dining-discount-wrap hidden aria-label="{{ __('dining.discount') }}" inputmode="decimal" type="text" value="0.00" maxlength="14" autocomplete="off"></dd></div><div><dt>{{ __('dining.tax') }} <span data-dining-tax-rate></span></dt><dd><bdi data-dining-total="tax">—</bdi></dd></div><div data-dining-service-row hidden><dt>{{ __('dining.service') }} <span data-dining-service-rate></span></dt><dd><bdi data-dining-total="service">—</bdi></dd></div></dl>
            <label class="dp-payment-reference" data-dining-discount-reason-wrap hidden><span>{{ __('dining.discount_reason') }}</span><input data-dining-discount-reason type="text" maxlength="500" autocomplete="off"></label>
            <div class="dp-payment-box"><p>{{ __('dining.payment_note') }}</p><fieldset class="dp-payments"><legend>{{ __('dining.payment_method') }}</legend><div data-dining-payments></div></fieldset>
            <fieldset class="dp-mixed-payment" data-dining-mixed-wrap hidden><legend>{{ __('dining.mixed_breakdown') }}</legend>@foreach(['cash','card','mobile_wallet'] as $tender)<label><span>{{ __('dining.'.($tender === 'mobile_wallet' ? 'wallet' : $tender)) }}</span><input data-dining-tender="{{ $tender }}" inputmode="decimal" value="0.00" maxlength="14" autocomplete="off"></label>@endforeach<small data-dining-mixed-total></small></fieldset>
            <label class="dp-payment-reference" data-dining-cash-wrap><span>{{ __('dining.cash_received') }} <bdi data-dining-change></bdi></span><input data-dining-cash-received type="text" inputmode="decimal" maxlength="14" autocomplete="off" placeholder="{{ __('dining.cash_received_placeholder') }}"></label>
            <label class="dp-payment-confirmation" data-dining-payment-confirm-wrap hidden><input type="checkbox" data-dining-payment-confirmed><span>{{ __('dining.payment_confirmed') }}</span></label>
            <label class="dp-payment-reference" data-dining-reference-wrap hidden><span>{{ __('dining.payment_reference') }}</span><input data-dining-payment-reference type="text" maxlength="100" autocomplete="off" placeholder="{{ __('dining.payment_reference_placeholder') }}"></label>
            </div></div>
            <div class="dp-actions"><dl class="dp-fixed-total"><div class="dp-grand-total"><dt>{{ __('dining.total') }}</dt><dd><strong><bdi data-dining-total="total">—</bdi></strong><small>{{ __('dining.currency') }}</small></dd></div></dl><button type="button" class="dp-finish" data-dining-save disabled><i class="fas fa-utensils" aria-hidden="true"></i><span>{{ __('dining.send_kitchen') }}</span><kbd>F9</kbd></button><div class="dp-actions-row"><button type="button" data-dining-cancel hidden>{{ __('dining.cancel_invoice') }}</button><button type="button" data-dining-request-bill disabled>{{ __('dining.request_bill') }}</button><button type="button" data-dining-print-kitchen disabled>{{ __('dining.print_kitchen') }}</button></div><button type="button" class="dp-settle" data-dining-settle disabled>{{ __('dining.settle') }}</button></div>
        </section>
        <section class="dp-catalog" aria-labelledby="catalog-title">
            <header class="dp-catalog-heading"><h2 id="catalog-title"><i class="fas fa-th-large" aria-hidden="true"></i>{{ __('dining.products') }}</h2><label class="dp-search"><span class="dp-sr-only">{{ __('dining.search') }}</span><i class="fas fa-search" aria-hidden="true"></i><input data-dining-search type="search" placeholder="{{ __('dining.search') }}" autocomplete="off"></label></header>
            <div class="dp-categories" data-dining-categories role="group" aria-label="{{ __('dining.products') }}"></div>
            <div class="dp-products" data-dining-products aria-live="polite"></div>
            <p class="dp-catalog-message" data-dining-catalog-message>{{ __('dining.choose_branch_first') }}</p>
            <nav class="dp-pagination" data-dining-pagination aria-label="{{ __('dining.products') }}" hidden><button type="button" data-dining-page="previous">{{ __('dining.previous') }}</button><span data-dining-page-label></span><button type="button" data-dining-page="next">{{ __('dining.next') }}</button></nav>
        </section>
        <section class="dp-floor" aria-labelledby="dining-floor-title"><h2 class="dp-sr-only" id="dining-floor-title">{{ __('dining.tables') }}</h2><div class="dp-floor-toolbar"><button type="button" data-dining-settings @if(empty($dining['permissions']['can_manage_tables'])) hidden @endif>{{ __('dining.add_table') }}</button><div class="dp-floor-filters" role="group" aria-label="{{ __('dining.table_status') }}">@foreach(['any','free','in_service','awaiting_bill'] as $status)<button type="button" data-dining-floor-filter="{{ $status }}" aria-pressed="{{ $status === 'any' ? 'true' : 'false' }}">{{ __('dining.status_'.$status) }}</button>@endforeach</div><label class="dp-table-search"><span class="dp-sr-only">{{ __('dining.search_table') }}</span><input data-dining-table-search type="search" placeholder="{{ __('dining.search_table') }}"></label><button type="button" class="dp-floor-refresh" data-dining-refresh aria-label="{{ __('dining.refresh') }}"><i class="fas fa-sync-alt" aria-hidden="true"></i></button></div><div class="dp-table-grid" data-dining-tables aria-live="polite"></div><p class="dp-floor-help" data-dining-floor-help>{{ __('dining.select_table') }}</p></section>
        <aside class="dp-table-detail dp-panel" aria-labelledby="selected-table-title"><h2 id="selected-table-title"><i class="fas fa-chair" aria-hidden="true"></i><span data-dining-detail-title>{{ __('dining.select_table') }}</span></h2><dl data-dining-detail></dl><button type="button" class="dp-bill-button" data-dining-request-bill disabled>{{ __('dining.request_bill') }}</button><button type="button" data-dining-print-bill disabled>{{ __('dining.print_bill') }}</button></aside>
        <aside class="dp-weight dp-panel" aria-labelledby="quantity-title">
            <div class="dp-unit-toggle" role="group" aria-label="{{ __('dining.quantity') }}"><button type="button" data-dining-unit="piece" aria-pressed="true">{{ __('dining.piece') }}</button><button type="button" data-dining-unit="weight" aria-pressed="false">{{ __('dining.weight') }}</button></div>
            <div class="dp-weight-display"><span id="quantity-title" data-dining-quantity-title>{{ __('dining.current_quantity') }}</span><output data-dining-quantity aria-live="polite"><bdi>1</bdi></output><small data-dining-quantity-unit>{{ __('dining.piece') }}</small></div>
            <div class="dp-keypad" role="group" aria-label="{{ __('dining.quantity') }}">
                @foreach(['7','8','9','backspace','4','5','6','clear','1','2','3','confirm','.','0'] as $key)
                    <button type="button" data-dining-key="{{ $key }}" @if($key === 'confirm') class="dp-key-confirm" @endif @if($key === 'backspace') aria-label="{{ __('dining.remove_item') }}" @endif>@if($key === 'backspace')<i class="fas fa-backspace" aria-hidden="true"></i>@elseif($key === 'clear')<span class="dp-key-clear">C</span>@elseif($key === 'confirm')<span data-dining-confirm-label>{{ __('dining.confirm_quantity') }}</span><i class="fas fa-check" aria-hidden="true"></i>@else{{ $key }}@endif</button>
                @endforeach
            </div>
            <div class="dp-weight-hint"><i class="far fa-lightbulb" aria-hidden="true"></i><p>{{ __('dining.quantity_hint') }}</p></div>
            <button type="button" class="dp-reset-weight" data-dining-weight-reset>{{ __('dining.clear_quantity') }} <i class="fas fa-undo" aria-hidden="true"></i></button>
        </aside>
    </div>
    <div class="dp-modal-backdrop" data-dining-modal hidden><section class="dp-modal dp-panel" role="dialog" aria-modal="true" aria-labelledby="pos-modal-title" tabindex="-1"><header class="dp-panel-heading"><h2 id="pos-modal-title"></h2><button type="button" data-dining-modal-close aria-label="{{ __('dining.close') }}"><i class="fas fa-times" aria-hidden="true"></i></button></header><div class="dp-modal-content" data-dining-modal-content></div></section></div>
</main>
</div>
@endsection

@push('custom-js')
<script type="application/json" id="dining-bootstrap">@json($dining)</script>
<script type="application/json" id="dining-translations">@json(trans('dining'))</script>
<script src="{{ asset('dashboard/js/dining-pos.js') }}?v={{ filemtime(public_path('dashboard/js/dining-pos.js')) }}"></script>
@endpush
