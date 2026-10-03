<div class="ob-menu-backdrop" data-menu-backdrop hidden></div>
<aside id="branch-menu" class="ob-menu" data-menu-url="{{ route('order-board.menu') }}" data-menu-action="{{ url('admin/order-board/menu') }}" aria-labelledby="branch-menu-title" hidden>
    <header class="ob-menu-heading">
        <div><h2 id="branch-menu-title">{{ __('order_board.branch_menu') }}</h2><p data-menu-branch></p></div>
        <button type="button" data-menu-close aria-label="{{ __('order_board.menu_close') }}">×</button>
    </header>
    <div class="ob-menu-search">
        <label class="ob-sr-only" for="branch-menu-search">{{ __('order_board.menu_search') }}</label>
        <input id="branch-menu-search" type="search" placeholder="{{ __('order_board.menu_search') }}" autocomplete="off">
        <button type="button" data-menu-refresh aria-label="{{ __('order_board.menu_refresh') }}">↻</button>
    </div>
    <p class="ob-menu-message" data-menu-message role="status" aria-live="polite" hidden></p>
    <div class="ob-menu-items" data-menu-items aria-busy="false"></div>
    <footer class="ob-menu-footer">
        <button type="button" data-menu-page="previous" disabled>{{ __('order_board.previous') }}</button>
        <span data-menu-count></span>
        <button type="button" data-menu-page="next" disabled>{{ __('order_board.next') }}</button>
    </footer>
</aside>
