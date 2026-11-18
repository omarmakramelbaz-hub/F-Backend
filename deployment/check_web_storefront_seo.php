<?php
/** Deployment smoke check: no customer, branch, inventory or order writes. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $seo = \App\Services\Site\StorefrontSeo::class;
    if ($seo::BASE_URL !== 'https://fasakhaninja.com') throw new \RuntimeException;
    foreach (['storefront.products' => 'products', 'storefront.product-category' => 'products/{slug}',
              'storefront.sitemap' => 'sitemap.xml'] as $name => $uri) {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);
        if (!$route || $route->uri() !== $uri || !in_array('GET', $route->methods(), true)) throw new \RuntimeException;
    }
    // An isolated memory session avoids loading a visitor's session or using a database session driver.
    $session = new \Illuminate\Session\Store('seo-deployment-check', new \Illuminate\Session\ArraySessionHandler(5));
    $session->start();
    $request = \Illuminate\Http\Request::create($seo::BASE_URL, 'GET');
    $request->setLaravelSession($session);
    $app->instance('request', $request);
    $app->instance('session', $session);
    $app->instance('session.store', $session);
    $app->setLocale('ar');
    \Illuminate\Support\Facades\DB::listen(function ($query) {
        if (!preg_match('/^\s*(?:select|show|describe|explain|pragma)\b/i', $query->sql)) throw new \RuntimeException;
    });
    $pages = [
        [$seo::url(), view('site.home', ['storefrontBranches' => [], 'storefrontInfo' => []])->render()],
        [$seo::url('/products'), view('site.seo.index', ['seo' => $seo::indexMetadata(), 'categories' => $seo::categories()])->render()],
    ];
    foreach ($seo::categories() as $slug => $category) {
        $pages[] = [$seo::url('/products/'.$slug), view('site.seo.category', [
            'seo' => $seo::categoryMetadata($slug), 'category' => $category, 'categories' => $seo::categories(),
        ])->render()];
    }
    foreach ($pages as [$url, $html]) {
        if (substr_count($html, '<title>') !== 1 || substr_count($html, '<h1') !== 1
            || substr_count($html, 'rel="canonical"') !== 1
            || strpos($html, 'rel="canonical" href="'.$url.'"') === false
            || strpos($html, 'name="description"') === false
            || strpos($html, 'content="noindex') !== false
            || (strpos($html, 'https://fasakhaninja.com/#delivery') === false && strpos($html, 'href="#delivery"') === false)
            || !preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $match)
            || !is_array(json_decode($match[1], true, 512, JSON_THROW_ON_ERROR))) throw new \RuntimeException;
    }
    $xml = $seo::sitemapXml();
    $document = new \DOMDocument();
    if (!$document->loadXML($xml, LIBXML_NONET) || $document->getElementsByTagName('loc')->length !== count($pages)) throw new \RuntimeException;
    foreach ($pages as [$url]) {
        if (strpos($xml, '<loc>'.$url.'</loc>') === false) throw new \RuntimeException;
    }
    $robots = file_get_contents(public_path('robots.txt'));
    $login = file_get_contents(resource_path('views/admin/login.blade.php'));
    if (!preg_match('/^\s*Sitemap:\s*https:\/\/fasakhaninja\.com\/sitemap\.xml\s*$/m', $robots)
        || !preg_match('/<meta\b(?=[^>]*\bname=["\']robots["\'])(?=[^>]*\bcontent=["\'][^"\']*\bnoindex\b)[^>]*>/i', $login)) throw new \RuntimeException;
    echo json_encode(['status' => 'WEB_STOREFRONT_SEO_RUNTIME_OK', 'pages' => count($pages),
        'sitemap_urls' => count($pages), 'database_writes' => 0, 'order_flow_changed' => false]).PHP_EOL;
} catch (\Throwable $error) {
    fwrite(STDERR, 'WEB_STOREFRONT_SEO_RUNTIME_CHECK_FAILED'.PHP_EOL);
    exit(1);
}
