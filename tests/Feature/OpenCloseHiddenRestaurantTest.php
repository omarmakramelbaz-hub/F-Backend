<?php
namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OpenCloseHiddenRestaurantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('resturants');
        Schema::create('resturants', function ($t) {
            $t->id(); $t->string('status')->nullable(); $t->string('open_at')->nullable(); $t->string('close_at')->nullable(); $t->timestamps();
        });
        Schema::dropIfExists('orders');
        Schema::create('orders', function ($t) {
            $t->id(); $t->unsignedBigInteger('resturant_id'); $t->string('order_type'); $t->date('schedule_date')->nullable(); $t->string('status'); $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('orders');
        Schema::dropIfExists('resturants');
        parent::tearDown();
    }

    private function statusAt(string $time): array
    {
        Carbon::setTestNow(Carbon::parse('2026-11-25 '.$time));
        $this->artisan('notify:open_close_resturant')->assertExitCode(0);
        return DB::table('resturants')->orderBy('id')->pluck('status', 'id')->all();
    }

    public function test_hidden_branch_stays_hidden_through_opening_and_closing_times(): void
    {
        DB::table('resturants')->insert([
            ['id' => 1, 'status' => 'hide', 'open_at' => '09:00', 'close_at' => '23:00'],
            ['id' => 2, 'status' => 'closed', 'open_at' => '09:00', 'close_at' => '23:00'],
            ['id' => 3, 'status' => null, 'open_at' => '09:00', 'close_at' => '23:00'],
        ]);

        $this->assertSame([1 => 'hide', 2 => 'opened', 3 => 'opened'], $this->statusAt('09:00'));
        $this->assertSame([1 => 'hide', 2 => 'closed', 3 => 'closed'], $this->statusAt('23:00'));
    }
}
