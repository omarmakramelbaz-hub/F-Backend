<?php
namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Site\StorefrontSeo;

class StorefrontSeoController extends Controller
{
    public function index()
    {
        app()->setLocale('ar');
        return response()->view('site.seo.index', [
            'seo' => StorefrontSeo::indexMetadata(), 'categories' => StorefrontSeo::categories(),
        ]);
    }

    public function category(string $slug)
    {
        $category = StorefrontSeo::category($slug);
        abort_unless($category, 404);
        app()->setLocale('ar');
        return response()->view('site.seo.category', [
            'seo' => StorefrontSeo::categoryMetadata($slug), 'category' => $category,
            'categories' => StorefrontSeo::categories(),
        ]);
    }

    public function sitemap()
    {
        return response(StorefrontSeo::sitemapXml(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
