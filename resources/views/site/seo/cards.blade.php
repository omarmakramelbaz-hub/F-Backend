<div class="seo-product-cards">
    @foreach($categories as $slug => $item)
    <a class="seo-product-card" href="{{ \App\Services\Site\StorefrontSeo::url('/products/'.$slug) }}"><span class="seo-card-number" aria-hidden="true">{{ $item['number'] }}</span><h3>{{ $item['name'] }}</h3><p>{{ $item['summary'] }}</p><span class="seo-card-link">اعرف اختياراتك <span aria-hidden="true">←</span></span></a>
    @endforeach
</div>
