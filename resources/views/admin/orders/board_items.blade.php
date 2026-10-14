<dialog id="ob-items-editor" class="ob-items-editor" aria-labelledby="ob-items-title">
    <form data-items-form>
        <header class="obi-header">
            <div><h2 id="ob-items-title">{{ __('order_board.edit_items') }} <bdi data-items-number></bdi></h2><p>{{ __('order_board.edit_items_help') }}</p></div>
            <button type="button" data-items-close class="obi-close" aria-label="{{ __('order_board.close_editor') }}">×</button>
        </header>
        <div class="obi-body">
            <p data-items-message role="status" aria-live="polite" hidden></p>
            <button type="button" data-items-reload hidden>{{ __('order_board.reload_items') }}</button>
            <div data-items-content hidden>
                <p data-items-quantity-hint class="obi-hint"></p>
                <div data-items-lines class="obi-lines"></div>
                <section class="obi-add" aria-labelledby="obi-add-title">
                    <h3 id="obi-add-title">{{ __('order_board.add_item') }}</h3>
                    <label>{{ __('order_board.find_item') }}<input type="search" data-items-search autocomplete="off" placeholder="{{ __('order_board.find_item') }}"></label>
                    <div class="obi-add-controls">
                        <label class="obi-product-label">{{ __('order_board.item_name') }}<select data-items-product></select></label>
                        <label>{{ __('order_board.item_option') }}<select data-items-option></select></label>
                        <button type="button" data-items-add>{{ __('order_board.add_item') }} +</button>
                    </div>
                    <p data-items-no-products hidden>{{ __('order_board.no_items_found') }}</p>
                </section>
                <div class="obi-summary"><span>{{ __('order_board.items_subtotal') }}</span><strong><bdi data-items-subtotal>0.00</bdi> {{ __('order_board.currency') }}</strong></div>
                <p class="obi-hint">{{ __('order_board.items_total_hint') }}</p>
            </div>
        </div>
        <footer class="obi-footer"><button type="button" data-items-close>{{ __('order_board.cancel_edit') }}</button><button type="submit" data-items-save disabled>{{ __('order_board.save_items') }}</button></footer>
    </form>
</dialog>
