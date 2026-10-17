@php
    $stages = ['new' => 'fa-shopping-cart', 'preparing' => 'fa-utensils', 'courier' => 'fa-motorcycle', 'completed' => 'fa-check-circle'];
@endphp
@foreach($stages as $stage => $icon)
    <section class="ob-column ob-stage-{{ $stage }}" data-column="{{ $stage }}" aria-labelledby="ob-column-{{ $stage }}">
        <header class="ob-column-header">
            <i class="fas {{ $icon }}" aria-hidden="true"></i>
            <h2 id="ob-column-{{ $stage }}">{{ __('order_board.'.$stage) }}</h2>
            <span class="ob-column-count" data-board-count="{{ $stage }}">{{ $board['counts'][$stage] ?? 0 }}</span>
        </header>
        <div class="ob-card-list" data-column-list="{{ $stage }}" tabindex="0" aria-label="{{ __('order_board.'.$stage) }}">
            @forelse($board['groups'][$stage] ?? [] as $card)
                @include('admin.orders.board_card', ['card' => $card])
            @empty
                <div class="ob-empty"><i class="fas {{ $icon }}" aria-hidden="true"></i><p>{{ __('order_board.empty') }}</p></div>
            @endforelse
        </div>
        @php($page = $board['pages'][$stage] ?? null)
        <footer class="ob-column-footer" data-current-page="{{ $page['page'] ?? 1 }}" @unless($page) hidden @endunless>
            @if($page)
                <span class="ob-page-range"><bdi>{{ $page['from'] }}–{{ $page['to'] }}</bdi> {{ __('order_board.of') }} <bdi>{{ $page['total'] }}</bdi></span>
                <div class="ob-page-links">
                    @if(!empty($page['previous_url']))
                        <a href="{{ $page['previous_url'] }}" data-board-page aria-label="{{ __('order_board.previous') }} · {{ __('order_board.'.$stage) }}">{{ __('order_board.previous') }}</a>
                    @endif
                    @if(!empty($page['next_url']))
                        <a href="{{ $page['next_url'] }}" data-board-page aria-label="{{ __('order_board.next') }} · {{ __('order_board.'.$stage) }}">{{ __('order_board.next') }}</a>
                    @endif
                </div>
            @endif
        </footer>
    </section>
@endforeach
