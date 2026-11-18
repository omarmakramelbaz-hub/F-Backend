<dialog id="ob-items-editor" class="ob-items-editor" aria-labelledby="ob-items-title">
    <form data-items-form class="tp-pos">
        <header class="tp-header obi-header">
            <div class="tp-heading"><h1 id="ob-items-title">{{ __('order_board.edit_items') }} <bdi data-items-number></bdi></h1><p>{{ __('order_board.pos_editor_help') }}</p></div>
            <span class="obi-branch"><i class="fas fa-store" aria-hidden="true"></i><strong data-items-branch></strong></span>
            <button type="button" data-items-close class="obi-close" aria-label="{{ __('order_board.close_editor') }}">×</button>
        </header>
        <div data-items-message class="tp-notice" role="status" aria-live="polite" hidden></div>
        <button type="button" data-items-reload class="obi-reload" hidden>{{ __('order_board.reload_items') }}</button>
        <div data-items-content class="tp-workspace" hidden>
            <section class="tp-invoice tp-panel" aria-labelledby="obi-invoice-title">
                <header class="tp-panel-heading"><h2 id="obi-invoice-title"><i class="far fa-file-alt" aria-hidden="true"></i>{{ __('order_board.current_invoice') }}</h2><span class="obi-line-count" data-items-count></span></header>
                <div class="tp-invoice-scroll">
                    <div class="tp-invoice-lines"><table><colgroup><col class="tp-col-item"><col class="tp-col-quantity"><col class="tp-col-price"><col class="tp-col-total"><col class="tp-col-remove"></colgroup><thead><tr><th>{{ __('order_board.item_name') }}</th><th>{{ __('order_board.item_quantity') }}</th><th>{{ __('order_board.unit_price') }}</th><th>{{ __('order_board.total') }}</th><th><span class="tp-sr-only">{{ __('order_board.remove_item') }}</span></th></tr></thead><tbody data-items-lines></tbody></table><p class="tp-empty" data-items-empty hidden>{{ __('order_board.empty_editor_invoice') }}</p></div>
                    <dl class="tp-totals"><div><dt>{{ __('order_board.items_subtotal') }}</dt><dd><bdi data-items-subtotal>0.00</bdi></dd></div><div><dt>{{ __('order_board.delivery_fee') }}</dt><dd><bdi data-items-total="delivery">0.00</bdi></dd></div><div><dt>{{ __('order_board.tax') }}</dt><dd><bdi data-items-total="tax">0.00</bdi></dd></div><div><dt>{{ __('order_board.service_fee') }}</dt><dd><bdi data-items-total="service">0.00</bdi></dd></div><div class="tp-grand-total"><dt>{{ __('order_board.total') }}</dt><dd><strong><bdi data-items-total="grand">0.00</bdi></strong><small>{{ __('order_board.currency') }}</small></dd></div></dl>
                    <p class="obi-hint" data-items-quantity-hint></p>
                </div>
                <div class="obi-invoice-actions"><button type="submit" class="tp-finish" data-items-save disabled><i class="fas fa-check" aria-hidden="true"></i><span data-items-save-label>{{ __('order_board.save_items') }}</span></button><button type="button" data-items-close class="obi-cancel">{{ __('order_board.cancel_edit') }}</button></div>
            </section>
            <section class="tp-catalog" aria-labelledby="obi-catalog-title">
                <header class="tp-catalog-heading"><h2 id="obi-catalog-title"><i class="fas fa-th-large" aria-hidden="true"></i>{{ __('order_board.branch_menu') }}</h2><label class="tp-search"><span class="tp-sr-only">{{ __('order_board.find_item') }}</span><i class="fas fa-search" aria-hidden="true"></i><input type="search" data-items-search autocomplete="off" placeholder="{{ __('order_board.find_item') }}"></label></header>
                <div class="tp-categories" data-items-categories role="group" aria-label="{{ __('order_board.menu_categories') }}"></div>
                <div class="tp-products" data-items-products></div>
                <p class="tp-catalog-message" data-items-no-products hidden>{{ __('order_board.no_items_found') }}</p>
            </section>
            <aside class="tp-weight tp-panel" aria-labelledby="obi-quantity-title">
                <div class="tp-unit-toggle" role="group" aria-label="{{ __('order_board.item_quantity') }}"><button type="button" data-items-unit="piece" aria-pressed="true">{{ __('order_board.piece_unit') }}</button><button type="button" data-items-unit="weight" aria-pressed="false">{{ __('order_board.weight') }}</button></div>
                <div class="tp-weight-display"><span id="obi-quantity-title" data-items-quantity-title>{{ __('order_board.current_quantity') }}</span><output data-items-quantity aria-live="polite"><bdi>1</bdi></output><small data-items-quantity-unit>{{ __('order_board.piece_unit') }}</small></div>
                <div class="tp-keypad" role="group" aria-label="{{ __('order_board.item_quantity') }}">
                    @foreach(['7','8','9','backspace','4','5','6','clear','1','2','3','confirm','.','0'] as $key)
                    <button type="button" data-items-key="{{ $key }}" @if($key === 'confirm') class="tp-key-confirm" @endif @if($key === 'backspace') aria-label="{{ __('order_board.backspace') }}" @endif>@if($key === 'backspace')<i class="fas fa-backspace" aria-hidden="true"></i>@elseif($key === 'clear')<span class="tp-key-clear">C</span>@elseif($key === 'confirm')<span>{{ __('order_board.confirm_quantity') }}</span><i class="fas fa-check" aria-hidden="true"></i>@else{{ $key }}@endif</button>
                    @endforeach
                </div>
                <div class="tp-weight-hint"><i class="far fa-lightbulb" aria-hidden="true"></i><p>{{ __('order_board.pos_quantity_hint') }}</p></div>
                <button type="button" class="tp-reset-weight" data-items-key="reset">{{ __('order_board.reset_quantity') }} <i class="fas fa-undo" aria-hidden="true"></i></button>
            </aside>
        </div>
        <div class="tp-modal-backdrop obi-options-backdrop" data-items-option-modal hidden><section class="tp-modal tp-panel" aria-labelledby="obi-options-title"><header class="tp-panel-heading"><h2 id="obi-options-title" data-items-options-title></h2><button type="button" data-items-options-close aria-label="{{ __('order_board.close_editor') }}">×</button></header><div class="tp-modal-content"><div class="tp-option-list" data-items-option-list></div></div></section></div>
    </form>
</dialog>
