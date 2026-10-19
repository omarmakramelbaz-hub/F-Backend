<section class="seo-home-products section-wrap" aria-labelledby="discover-products-title">
    <div class="seo-section-heading"><div><span class="eyebrow">اختيارات على أصولها</span><h2 id="discover-products-title">اكتشف منتجات فسخانستا.</h2></div><a class="text-link" href="{{ \App\Services\Site\StorefrontSeo::url('/products') }}">دليل المنتجات <span aria-hidden="true">←</span></a></div>
    @include('site.seo.cards', ['categories' => \App\Services\Site\StorefrontSeo::categories()])
    <p class="seo-home-note">الأصناف والأسعار بتظهر من منيو متجرك بعد تحديد عنوان وموقع التوصيل.</p>
</section>
