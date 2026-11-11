<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- STOREFRONT_SEO_HEAD_START --}}
    @include('site.seo.head', ['seo' => \App\Services\Site\StorefrontSeo::homeMetadata()])
    <meta name="theme-color" content="#091320">
    {{-- STOREFRONT_SEO_HEAD_END --}}
    <link rel="icon" href="{{ asset('dashboard/branding/fasakhansta-logo-transparent.png') }}?v=20261017-3" type="image/png" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('dashboard/branding/fasakhansta-logo-transparent.png') }}?v=20261017-3">
    <link rel="preload" as="image" href="{{ asset('storefront/hero-motion-4k-poster.webp') }}">
    <link rel="stylesheet" href="{{ asset('dashboard/vendor/leaflet/leaflet.css') }}">
    <link rel="stylesheet" href="{{ asset('storefront/storefront.css') }}?v=20261017-6">
    <link rel="stylesheet" href="{{ asset('storefront/seo.css') }}?v=20261017-1">
    <script id="storefront-data" type="application/json">{!! json_encode(['branches'=>$storefrontBranches,'info'=>$storefrontInfo,'endpoints'=>['branches'=>route('storefront.branches'),'catalog'=>route('storefront.catalog'),'addresses'=>route('storefront.addresses'),'quote'=>route('storefront.quote'),'checkout'=>route('storefront.checkout')]], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
    <script src="{{ asset('dashboard/vendor/leaflet/leaflet.js') }}" defer></script>
    <script src="{{ asset('storefront/storefront.js') }}?v=20261111-start-order-1" defer></script>
    <link rel="stylesheet" href="{{ asset('storefront/motion.css') }}?v=20261111-1">
    <script src="{{ asset('storefront/motion.js') }}?v=20261111-1" defer></script>
</head>
<body id="fasakhansta-storefront-concept" class="storefront-motion" data-release="storefront-motion-20261111">
<a class="skip-link" href="#menu">انتقل للمينيو</a>
<div class="f-site">
@include('site.motion.header')
<main>
@include('site.motion.hero')
<div class="f-flavour-band" aria-label="أصناف فسخانستا"><span>فسيخ</span><b aria-hidden="true">✦</b><span>رنجة</span><b aria-hidden="true">✦</b><span>سردين</span><b aria-hidden="true">✦</b><span>بطارخ</span></div>
@include('site.motion.directory')
@include('site.motion.products')
<div class="motion-order-area">
<section id="delivery" class="delivery-section section-wrap" aria-labelledby="delivery-title">
        <div class="delivery-card">
            <div class="delivery-intro"><span class="eyebrow">اللمة تبدأ من عنوانك</span><h2 id="delivery-title">نوصّل لك فين؟</h2><p>حدد موقعك واكتب عنوانك ورقمك، علشان نعرض لك أقرب متاجر فسخانستا اللي بتخدم مكانك.</p><div class="delivery-steps"><span><b>١</b> حدد موقعك</span><span><b>٢</b> اختار متجرك</span><span><b>٣</b> جهّز طلبك</span></div></div>
            <form id="delivery-form" class="delivery-form">
                <div class="checkout-fields">
                    <label class="full">اسمك<input name="customer_name" autocomplete="name" required minlength="2" maxlength="100" placeholder="الاسم بالكامل"></label>
                    <label class="full">رقم الموبايل<input name="customer_phone" type="tel" autocomplete="tel" inputmode="tel" dir="ltr" required maxlength="30" placeholder="01xxxxxxxxx"></label>
                    <label class="full">المنطقة أو أقرب معلم<input name="area" autocomplete="address-level2" required minlength="2" maxlength="150" placeholder="ابحث عن منطقتك أو مكان قريب"><span id="address-suggestions" class="address-suggestions"></span></label>
                    <label class="full">عنوان التوصيل بالتفصيل<input name="address" autocomplete="street-address" required minlength="8" maxlength="500" placeholder="الشارع، رقم العمارة، الدور والشقة"></label>
                </div>
                <div class="location-heading"><strong>موقع التوصيل على الخريطة</strong><button type="button" class="text-link" id="geolocate">⌖ حدّث موقعي الحالي</button></div>
                <div id="delivery-map" class="delivery-map" aria-label="خريطة تحديد عنوان التوصيل"></div>
                <p id="location-help" class="muted" role="status" aria-live="polite">بنحدد موقعك تلقائيًا. اسمح باستخدام الموقع من المتصفح أول مرة.</p>
                <label class="confirm-location"><input name="location_confirmed" type="checkbox" required> الدبوس في مكان التوصيل الصحيح</label>
                <p class="privacy-note">بنحفظ اسمك ورقمك وعناوينك على الجهاز ده للطلبات الجاية، وبنستخدم بياناتك لتجهيز الطلب والتواصل معاك بخصوصه.</p>
                <button type="button" id="forget-customer" class="text-link" hidden>امسح بياناتي من الجهاز ده</button>
                <div id="delivery-error" class="form-error" role="alert"></div>
                <button id="find-stores" type="submit" class="button primary wide">اعرض المتاجر اللي بتخدمني <span aria-hidden="true">←</span></button>
            </form>
            <div id="delivery-summary" class="delivery-summary" hidden><span class="delivery-summary-icon" aria-hidden="true">⌖</span><div><strong>عنوان التوصيل المختار</strong><p id="delivery-address"></p><span id="delivery-phone" dir="ltr"></span><p id="saved-address-status" role="status" aria-live="polite"></p><button type="button" id="retry-saved-address" class="text-link" data-action="retry-address" hidden>حاول تحميل المتاجر تاني</button></div><button type="button" class="button secondary" data-action="edit-location">تعديل بيانات العنوان</button></div>
        </div>
    </section>
<section id="menu" class="menu-section section-wrap" aria-labelledby="menu-title">
        <div class="section-top"><div><span class="eyebrow">كل مزاج وليه اختياره</span><h2 id="menu-title">المنيو اللي يجمعنا.</h2></div></div>
        <div class="menu-tools"><div id="categories" class="categories" aria-label="أقسام المنيو"></div><label class="search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="search" type="search" placeholder="بتدور على إيه؟" aria-label="ابحث عن منتج"></label></div>
        <div id="menu-status" class="menu-status" role="status"><span class="empty-symbol" aria-hidden="true">⌖</span><h3>حدد مكان التوصيل الأول</h3><p>بعد تأكيد موقعك وعنوانك ورقمك، هتظهر المتاجر اللي تقدر تخدمك.</p><a class="button secondary" href="#delivery">حدد عنوانك ←</a></div>
        <div id="product-grid" class="product-grid"></div>
        <button id="show-more" class="button secondary show-more" hidden>عرض أصناف أكتر</button>
        <p class="menu-footnote">الأسعار بالجنيه المصري. التوصيل وأي ضريبة مطبقة بيظهروا بالتفصيل قبل تأكيد الطلب.</p>
    </section>
<section id="branches" class="branches-section section-wrap"><div class="section-top"><div><span class="eyebrow">فسخانستا أقرب ليك</span><h2>المتاجر اللي بتخدم عنوانك.</h2></div><span class="muted">حسب نطاقات التوصيل المسجلة في التطبيق</span></div><div class="store-tools" id="store-tools" hidden><div class="store-filters" aria-label="تصنيف المتاجر"><button class="store-filter active" data-action="store-filter" data-filter="all" aria-pressed="true">كل المتاجر</button><button class="store-filter" data-action="store-filter" data-filter="branches" aria-pressed="false">فروع فسخانستا</button><button class="store-filter" data-action="store-filter" data-filter="stores" aria-pressed="false">متاجر المناطق</button></div><label class="search store-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="store-search" type="search" placeholder="اسم المتجر أو المنطقة" aria-label="ابحث عن متجر أو منطقة"></label></div><p id="store-count" class="store-count" role="status"></p><div id="branch-cards" class="branch-cards"></div><button id="show-more-stores" class="button secondary store-show-more" hidden>عرض متاجر أكتر</button></section>
</div>
@include('site.motion.visit')
@include('site.motion.app')
@include('site.motion.contact')
</main>
@include('site.motion.footer')
</div>
<button class="mobile-cart" id="mobile-cart" data-action="cart" hidden><span>عرض السلة <b id="mobile-count">0</b></span><strong id="mobile-total"></strong><span aria-hidden="true">←</span></button>
<dialog id="product-dialog" class="product-dialog" aria-labelledby="product-title"><button class="close-dialog" data-close aria-label="إغلاق">×</button><div id="product-detail"></div></dialog>
<dialog id="cart-dialog" class="cart-dialog" aria-labelledby="cart-title"><header class="dialog-heading"><div><span class="eyebrow">اللمة بتبدأ من هنا</span><h2 id="cart-title">سلة طلبك.</h2></div><button class="close-dialog" data-close aria-label="إغلاق السلة">×</button></header><div id="cart-content"></div><div id="cart-footer"></div></dialog>
<dialog id="checkout-dialog" class="checkout-dialog" aria-labelledby="checkout-title"><header class="dialog-heading"><div><span class="eyebrow">فاضل خطوة على الأكلة الحلوة</span><h2 id="checkout-title">بيانات التوصيل.</h2></div><button class="close-dialog" data-close aria-label="إغلاق">×</button></header><form id="checkout-form"><div class="checkout-fields"><label>اسمك<input name="customer_name" autocomplete="name" required minlength="2" maxlength="100" placeholder="الاسم بالكامل"></label><label>رقم الموبايل<input name="customer_phone" type="tel" dir="ltr" readonly required></label><label class="full">عنوان التوصيل<input name="address" readonly required></label><label class="full">المنطقة<input name="area" readonly required></label></div><div class="checkout-location-note"><span>✓ هنوصّل لنفس العنوان والموقع اللي حددتهم.</span><button type="button" class="text-link" data-action="edit-location">تغيير مكان التوصيل</button></div><label class="notes-label">ملاحظات للمتجر <span class="muted">(اختياري)</span><textarea name="notes" rows="2" maxlength="350" placeholder="أي تفاصيل تحب تقولها للمتجر؟"></textarea></label><p class="privacy-note">بياناتك بتتسجل مع الطلب لتجهيزه والتواصل معاك بخصوصه.</p><div class="form-error" id="checkout-error" role="alert"></div><button type="submit" id="review-order" class="button primary wide">مراجعة الإجمالي والتوصيل <span aria-hidden="true">←</span></button></form><div id="review-panel" hidden></div></dialog>
<dialog id="change-location-dialog" class="confirm-dialog" aria-labelledby="location-confirm-title"><h2 id="location-confirm-title">تغيّر مكان التوصيل؟</h2><p>هنراجع المتاجر اللي بتخدم العنوان الجديد. تغيير الموقع هيبدأ سلة جديدة.</p><div class="confirm-actions"><button class="button primary" id="confirm-location-change">غيّر مكان التوصيل</button><button class="button secondary" data-close>احتفظ بطلبي</button></div></dialog>
<dialog id="confirm-branch-dialog" class="confirm-dialog" aria-labelledby="branch-confirm-title"><h2 id="branch-confirm-title">تغيّر الفرع؟</h2><p>كل فرع ليه منيو وأسعار خاصة. تغيير الفرع هيبدأ سلة جديدة.</p><div class="confirm-actions"><button class="button primary" id="confirm-branch">غيّر الفرع</button><button class="button secondary" data-close>احتفظ بالسلة</button></div></dialog>
<dialog id="legal-dialog" class="checkout-dialog" aria-labelledby="legal-title"><header class="dialog-heading"><h2 id="legal-title"></h2><button class="close-dialog" data-close aria-label="إغلاق">×</button></header><div id="legal-content" class="legal-content"></div></dialog>
<div class="toast" id="toast" role="status" hidden></div>
<noscript><p class="noscript">فعّل JavaScript علشان تقدر تعرض المينيو وتطلب من الموقع.</p></noscript>
</body>
</html>
