<?php

namespace Tests\Feature;

use App\Http\Middleware\RememberRequestFacts;
use App\Support\RequestFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Facts read once per page read: within one GET request a fact is read once; everywhere else (saving requests,
 * transactions, console work, the next request) it is read again, exactly as before.
 */
class RequestFactsTest extends TestCase
{
    private int $reads = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reads = 0;
        $read = function () {
            $first = RequestFacts::remember('probe', fn () => ++$this->reads);
            $second = RequestFacts::remember('probe', fn () => ++$this->reads);
            $missing = RequestFacts::remember('nothing', fn () => null);
            $again = RequestFacts::remember('nothing', function () {
                $this->reads += 100;
                return null;
            });
            $locked = DB::transaction(fn () => RequestFacts::remember('probe', fn () => ++$this->reads));
            return response()->json(['first' => $first, 'second' => $second, 'missing' => $missing, 'again' => $again, 'in_transaction' => $locked]);
        };
        Route::get('/_request_facts_probe', $read);
        Route::post('/_request_facts_probe', $read);
    }

    public function test_a_page_read_reads_each_fact_once_and_keeps_missing_ones_too(): void
    {
        $this->getJson('/_request_facts_probe')->assertOk()
            ->assertExactJson(['first' => 1, 'second' => 1, 'missing' => null, 'again' => null, 'in_transaction' => 2]);
        $this->assertSame(2, $this->reads, 'once for the page, once again inside the transaction');
    }

    public function test_every_request_starts_fresh_and_nothing_stays_after_it(): void
    {
        $this->getJson('/_request_facts_probe')->assertJsonPath('first', 1);
        $this->getJson('/_request_facts_probe')->assertJsonPath('first', 3);
        // After the answer: read every time, as everywhere outside a page read.
        $this->assertSame(5, RequestFacts::remember('probe', fn () => ++$this->reads));
        $this->assertSame(6, RequestFacts::remember('probe', fn () => ++$this->reads));
    }

    public function test_a_saving_request_reads_everything_again(): void
    {
        $this->postJson('/_request_facts_probe')->assertOk()
            ->assertExactJson(['first' => 1, 'second' => 2, 'missing' => null, 'again' => null, 'in_transaction' => 103]);
        $this->assertSame(103, $this->reads);
    }

    public function test_outside_a_request_everything_is_read_again(): void
    {
        $this->assertSame(1, RequestFacts::remember('probe', fn () => ++$this->reads));
        $this->assertSame(2, RequestFacts::remember('probe', fn () => ++$this->reads));
    }

    public function test_the_site_keeps_facts_for_every_page_read(): void
    {
        $this->assertContains(RememberRequestFacts::class, (new \ReflectionClass(\App\Http\Kernel::class))->getDefaultProperties()['middleware']);
    }
}
