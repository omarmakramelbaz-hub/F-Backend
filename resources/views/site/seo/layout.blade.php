<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('site.seo.head', ['seo' => $seo])
    <meta name="theme-color" content="#091320">
    <link rel="icon" href="{{ \App\Services\Site\StorefrontSeo::url('/dashboard/branding/fasakhansta-logo-transparent.png') }}?v=20261017-3" type="image/png" sizes="any">
    <link rel="apple-touch-icon" href="{{ \App\Services\Site\StorefrontSeo::url('/dashboard/branding/fasakhansta-logo-transparent.png') }}?v=20261017-3">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('storefront/storefront.css') }}?v=20261017-3">
    <link rel="stylesheet" href="{{ asset('storefront/seo.css') }}?v=20261017-1">
</head>
<body class="seo-page">
<a class="skip-link" href="#content">انتقل للمحتوى</a>
<div class="announcement"><span>من قلب مصر، على سفرتك.</span><span>الفسيخ والرنجة واللمة الحلوة</span></div>
<header class="site-header seo-header">
    <a href="{{ \App\Services\Site\StorefrontSeo::url() }}" class="brand" aria-label="فسخانستا الرئيسية"><img src="{{ asset('dashboard/branding/fasakhansta-logo-transparent.png') }}" alt="" width="58" height="58"><span>فسخانستا<small>FASAKHANSTA</small></span></a>
    <nav aria-label="القائمة الرئيسية"><a class="active" href="{{ \App\Services\Site\StorefrontSeo::url('/products') }}">المنتجات</a><a href="{{ \App\Services\Site\StorefrontSeo::url() }}#delivery">المنيو والتوصيل</a></nav>
    <a class="button primary seo-header-order" href="{{ \App\Services\Site\StorefrontSeo::url() }}#delivery">ابدأ طلبك <span aria-hidden="true">←</span></a>
</header>
<main id="content" class="seo-main">
    <nav class="seo-breadcrumb section-wrap" aria-label="مسار الصفحة"><ol><li><a href="{{ \App\Services\Site\StorefrontSeo::url() }}">الرئيسية</a></li>@if(isset($category))<li><a href="{{ \App\Services\Site\StorefrontSeo::url('/products') }}">المنتجات</a></li><li aria-current="page">{{ $category['name'] }}</li>@else<li aria-current="page">المنتجات</li>@endif</ol></nav>
    @yield('content')
</main>
<footer><div class="footer-main section-wrap"><a href="{{ \App\Services\Site\StorefrontSeo::url() }}" class="brand"><img src="{{ asset('dashboard/branding/fasakhansta-logo-transparent.png') }}" alt="" width="58" height="58"><span>فسخانستا<small>FASAKHANSTA</small></span></a><p>طعم له ناسه. ولمة ليها أصولها.</p><div><a href="{{ \App\Services\Site\StorefrontSeo::url('/products') }}">دليل المنتجات</a><a href="{{ \App\Services\Site\StorefrontSeo::url() }}#delivery">الطلب والتوصيل</a></div></div><div class="footer-bottom section-wrap"><span>© {{ date('Y') }} فسخانستا. جميع الحقوق محفوظة.</span><span>المنيو والأسعار حسب المتجر المختار</span></div></footer>
</body>
</html>
