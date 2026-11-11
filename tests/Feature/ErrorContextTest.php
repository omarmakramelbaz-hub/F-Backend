<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** A logged error names the page it came from (method and path, never the query string). */
class ErrorContextTest extends TestCase
{
    public function test_a_logged_error_names_its_page_without_the_query_string(): void
    {
        Route::get('zz-error-context/{id}', fn () => throw new \RuntimeException('boom'));
        Log::spy();
        $this->get('/zz-error-context/5?mobile=01000000000&code=123456')->assertStatus(500);
        Log::shouldHaveReceived('error')->once()->withArgs(function ($message, $context) {
            return $message === 'boom' && ($context['url'] ?? null) === 'GET /zz-error-context/5'
                && !str_contains(json_encode($context['url']), '0100');
        });
    }
}
