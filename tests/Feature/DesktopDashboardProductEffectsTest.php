<?php

namespace Tests\Feature;

use App\Events\ResturantProductUpdated;
use App\Models\ResturantProduct;
use App\Services\Dashboard\DesktopDashboardProductEffects;
use App\Services\Dashboard\OrderProviderDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DesktopDashboardProductEffectsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'desktop_dashboard.local' => false]);
        DB::purge('sqlite');
        Event::fake([ResturantProductUpdated::class]);
    }

    private function product(): ResturantProduct
    {
        $product = new ResturantProduct(['product_name' => 'original product', 'product_price' => '12.50']);
        $product->id = 37;
        return $product;
    }

    public function test_ordinary_online_calls_keep_the_original_broadcast_event(): void
    {
        app(DesktopDashboardProductEffects::class)->updated($this->product(), 37);
        Event::assertDispatchedTimes(ResturantProductUpdated::class, 1);
        Event::assertDispatched(ResturantProductUpdated::class, fn ($event) => $event->senderId === 37
            && $event->resturant->id === 37 && $event->broadcastOn()->name === 'product.updated'
            && $event->broadcastAs() === 'product.updated');
    }

    public function test_trusted_local_mode_skips_every_provider_inside_and_outside_a_guard(): void
    {
        config(['desktop_dashboard.local' => true]);
        $this->mock(OrderProviderDelivery::class, fn ($mock) => $mock->shouldNotReceive('event'));
        $effects = app(DesktopDashboardProductEffects::class);
        $effects->updated($this->product(), 37);
        $effects->guard(fn () => app(DesktopDashboardProductEffects::class)->updated($this->product(), 37));
        app()->terminate();
        Event::assertNotDispatched(ResturantProductUpdated::class);
    }

    public function test_prepared_events_wait_for_outer_commit_and_termination_and_rollback_discards_them(): void
    {
        $effects = app(DesktopDashboardProductEffects::class);
        $product = $this->product();
        $effects->guard(function () use ($product) {
            DB::beginTransaction();
            DB::beginTransaction();
            app(DesktopDashboardProductEffects::class)->updated($product, 37);
            DB::commit();
            Event::assertNotDispatched(ResturantProductUpdated::class);
            DB::commit();
        });
        Event::assertNotDispatched(ResturantProductUpdated::class);
        $product->product_price = '999.00';
        app()->terminate();
        Event::assertDispatchedTimes(ResturantProductUpdated::class, 1);
        Event::assertDispatched(ResturantProductUpdated::class, fn ($event) => $event->resturant->product_price === '12.50');
        $effects->guard(function () {
            DB::beginTransaction();
            app(DesktopDashboardProductEffects::class)->updated($this->product(), 38);
            DB::rollBack();
        });
        app()->terminate();
        Event::assertDispatchedTimes(ResturantProductUpdated::class, 1);
    }

    public function test_nested_guards_restore_ordinary_online_behavior_after_an_exception(): void
    {
        $effects = app(DesktopDashboardProductEffects::class);
        try {
            $effects->guard(fn () => app(DesktopDashboardProductEffects::class)->guard(function () {
                throw new \RuntimeException('guard failure');
            }));
            $this->fail('The original failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('guard failure', $error->getMessage());
        }
        $effects->updated($this->product(), 37);
        Event::assertDispatchedTimes(ResturantProductUpdated::class, 1);
        $this->assertSame('guard result', $effects->guard(fn () => 'guard result'));
    }
}
