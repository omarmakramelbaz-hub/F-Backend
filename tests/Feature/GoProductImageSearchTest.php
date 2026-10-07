<?php

namespace Tests\Feature;

use App\Services\GoStores\ProductImageDownload;
use App\Services\GoStores\ProductImageSearch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoProductImageSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default'=>'array', 'go_product_images.enabled'=>true,
            'go_product_images.openai_key'=>'fixture-ai-key', 'go_product_images.search_key'=>'fixture-search-key']);
        Cache::flush();
        Storage::fake('public');
    }

    private function response(array $data): array
    {
        return ['status'=>'completed', 'output'=>[['type'=>'message', 'content'=>[
            ['type'=>'output_text', 'text'=>json_encode($data)],
        ]]]];
    }

    private function providers(array $choice): string
    {
        $file = UploadedFile::fake()->image('rice.jpg', 200, 200);
        $bytes = file_get_contents($file->getPathname());
        $download = \Mockery::mock(ProductImageDownload::class);
        $download->shouldReceive('fetch')->once()->with('https://images.example.com/rice.jpg')->andReturn($bytes);
        app()->instance(ProductImageDownload::class, $download);
        Http::fake([
            'api.openai.com/v1/responses' => Http::sequence()
                ->push($this->response(['generic_name'=>'rice', 'query'=>'rice grains plain background', 'searchable'=>true]))
                ->push($this->response($choice)),
            'api.search.brave.com/res/v1/images/search*' => Http::response(['results'=>[
                ['title'=>'Rice', 'url'=>'https://example.com/rice', 'properties'=>['url'=>'https://images.example.com/rice.jpg']],
            ]]),
        ]);
        return $bytes;
    }

    public function test_ai_search_and_vision_attach_only_the_inspected_unbranded_bytes(): void
    {
        $bytes = $this->providers(['index'=>0, 'confidence'=>0.97, 'visible_brand_or_text'=>false, 'matches_product'=>true]);
        $result = app(ProductImageSearch::class)->find('أرز براند تجريبي', 'supermarket');
        $this->assertSame($bytes, Storage::disk('public')->get($result['path']));
        $this->assertTrue($result['provenance']['unbranded']);
        Http::assertSent(function ($request) use ($bytes) {
            if ($request->url() !== 'https://api.openai.com/v1/responses') return false;
            $content = $request['input'][0]['content'];
            return count($content) === 3 && $content[2]['image_url'] === 'data:image/jpeg;base64,'.base64_encode($bytes)
                && $request['store'] === false && $request['text']['format']['strict'] === true;
        });
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.search.brave.com')
            && str_contains($request['q'], 'unbranded') && !str_contains($request['q'], 'براند'));
        // Repeated merchant products reuse the verified image without another provider call.
        $this->assertSame($result, app(ProductImageSearch::class)->find('أرز براند تجريبي', 'supermarket'));
        Http::assertSentCount(3);
    }

    public function test_visible_brand_or_text_is_rejected_even_with_high_confidence(): void
    {
        $this->providers(['index'=>0, 'confidence'=>0.99, 'visible_brand_or_text'=>true, 'matches_product'=>true]);
        $this->assertNull(app(ProductImageSearch::class)->find('أرز', 'supermarket'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_low_confidence_candidate_is_not_used_as_a_fallback(): void
    {
        $this->providers(['index'=>0, 'confidence'=>0.6, 'visible_brand_or_text'=>false, 'matches_product'=>true]);
        $this->assertNull(app(ProductImageSearch::class)->find('أرز', 'supermarket'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_ai_refusal_or_incomplete_response_is_retryable_instead_of_attaching_an_image(): void
    {
        Http::fake(['api.openai.com/v1/responses'=>Http::response(['status'=>'incomplete', 'output'=>[]])]);
        $this->expectException(\RuntimeException::class);
        app(ProductImageSearch::class)->find('أرز', 'supermarket');
    }

    public function test_disabled_provider_does_not_send_requests(): void
    {
        Http::fake();
        config(['go_product_images.enabled'=>false]);
        $this->assertFalse(app(ProductImageSearch::class)->configured());
        Http::assertNothingSent();
    }

    public function test_remote_image_cannot_target_local_addresses_or_authenticated_urls(): void
    {
        foreach (['http://example.com/a.jpg', 'https://127.0.0.1/a.jpg', 'https://[::1]/a.jpg',
            'https://user:pass@example.com/a.jpg', 'https://example.com:8443/a.jpg'] as $url) {
            try {
                (new ProductImageDownload())->fetch($url);
                $this->fail('Unsafe URL accepted');
            } catch (\RuntimeException $error) {
                $this->assertSame('Invalid image host', $error->getMessage());
            }
        }
    }

    public function test_live_check_reports_success_only_after_an_unbranded_image_is_saved(): void
    {
        $this->providers(['index'=>0, 'confidence'=>0.97, 'visible_brand_or_text'=>false, 'matches_product'=>true]);
        $this->artisan('go-stores:find-product-images', ['--check'=>'أرز'])
            ->expectsOutput('LIVE UNBRANDED IMAGE VERIFIED')->assertExitCode(0);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_live_check_fails_without_saving_a_branded_match(): void
    {
        $this->providers(['index'=>0, 'confidence'=>0.99, 'visible_brand_or_text'=>true, 'matches_product'=>true]);
        $this->artisan('go-stores:find-product-images', ['--check'=>'أرز'])
            ->expectsOutput('No confident unbranded match was found.')->assertExitCode(1);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_live_check_does_not_expose_provider_errors(): void
    {
        Http::fake(['api.openai.com/v1/responses'=>Http::response(['error'=>'secret-fixture'], 401)]);
        $this->artisan('go-stores:find-product-images', ['--check'=>'أرز'])
            ->expectsOutput('Live image search failed. Check provider credentials, quotas and server connectivity.')
            ->assertExitCode(1);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
