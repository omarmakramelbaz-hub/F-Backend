<?php

namespace Tests\Feature;

use App\Jobs\SyncOrderLiveActivity;
use App\Models\Order;
use App\Models\OrderActivitySubscription;
use App\Models\User;
use App\Services\OrderLiveActivity\Push;
use App\Services\OrderLiveActivity\Sync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderLiveActivityTest extends TestCase
{
    private $push;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Schema::clearResolvedInstance('db.schema');
        $this->withoutMiddleware();
        Bus::fake([SyncOrderLiveActivity::class]);
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('account_type')->nullable(); $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('resturant_id')->nullable();
            $t->string('status'); $t->string('type')->default('current'); $t->timestamps();
        });
        Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->timestamps(); });
        require_once base_path('database/migrations/2026_11_12_153000_create_order_activity_subscriptions_table.php');
        (new \CreateOrderActivitySubscriptionsTable())->up();
        DB::table('users')->insert([['id' => 1, 'name' => 'First', 'account_type' => 'user'], ['id' => 2, 'name' => 'Other', 'account_type' => 'user']]);
        DB::table('orders')->insert([
            ['id' => 10, 'user_id' => 1, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 20, 'user_id' => 2, 'status' => 'shipped', 'created_at' => now(), 'updated_at' => now()],
        ]);
        Schema::create('order_live_activities', function (Blueprint $t) {
            $t->id();
            foreach (['order_id','user_id','activity_id_hash','activity_token','fcm_token','token_hash','fcm_token_hash','state_hash','last_timestamp','attempts','next_attempt_at','ended_at','expires_at'] as $field) $t->text($field)->nullable();
            $t->timestamps();
        });
        DB::table('order_live_activities')->insert(['id' => 99, 'order_id' => 20, 'user_id' => 2, 'activity_id_hash' => 'synthetic-legacy-id', 'activity_token' => 'synthetic-legacy-encrypted-token']);
        $this->actingAs(User::withoutGlobalScopes()->find(1), 'api');
        $this->push = new class extends Push {
            public $sent = [];
            public $fail = false;
            public function send(OrderActivitySubscription $activity, array $state, bool $terminal): void {
                if ($this->fail) throw new \RuntimeException('Simulated delivery outage');
                $this->sent[] = ['activity' => $activity->id, 'state' => $state, 'terminal' => $terminal];
            }
        };
        $this->app->instance(Push::class, $this->push);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['order_id' => 10, 'platform' => 'ios', 'live_activity_id' => 'native-uuid-10',
            'live_activity_token' => str_repeat('ab', 32), 'fcm_token' => 'synthetic-fcm-registration-token'], $overrides);
    }

    private function register(array $overrides = []): void
    {
        $this->postJson('/api/user/order-activity-subscriptions', $this->payload($overrides))->assertOk();
    }

    public function test_existing_legacy_schema_and_rows_are_untouched_by_the_new_delivery_path(): void
    {
        $legacy = (array) DB::table('order_live_activities')->first();
        $columns = Schema::getColumnListing('order_live_activities');
        $this->register();
        $this->app->make(Sync::class)->order(Order::withoutGlobalScopes()->find(10));
        $this->assertSame($legacy, (array) DB::table('order_live_activities')->first());
        $this->assertSame($columns, Schema::getColumnListing('order_live_activities'));
        $this->assertSame(1, OrderActivitySubscription::count());
        $this->assertCount(1, $this->push->sent);
    }

    public function test_registration_is_idempotent_private_and_contains_both_delivery_tokens(): void
    {
        $response = $this->postJson('/api/user/order-activity-subscriptions', $this->payload())->assertOk();
        $response->assertJsonPath('data.activity_id', 'native-uuid-10');
        $this->assertStringNotContainsString(str_repeat('ab', 32), $response->getContent());
        $this->register(['fcm_token' => 'synthetic-rotated-fcm-token']);
        $this->assertSame(1, OrderActivitySubscription::count());
        $registration = OrderActivitySubscription::first();
        $this->assertSame('synthetic-rotated-fcm-token', $registration->fcm_token);
        $raw = DB::table('order_activity_subscriptions')->first();
        $this->assertNotSame(str_repeat('ab', 32), $raw->activity_token);
        $this->assertNotSame('synthetic-rotated-fcm-token', $raw->fcm_token);
        Bus::assertDispatchedAfterResponse(SyncOrderLiveActivity::class);
    }

    public function test_rotating_an_activity_token_retires_the_previous_delivery_target(): void
    {
        $this->register();
        $this->register(['live_activity_token' => str_repeat('cd', 32)]);
        $this->assertSame(2, OrderActivitySubscription::count());
        $this->assertSame(1, OrderActivitySubscription::whereNull('ended_at')->count());
        $this->assertNotNull(OrderActivitySubscription::orderBy('id')->first()->ended_at);
    }

    public function test_guests_cannot_register_an_activity(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withMiddleware();
        $this->postJson('/api/user/order-activity-subscriptions', $this->payload())->assertUnauthorized();
        $this->assertSame(0, OrderActivitySubscription::count());
    }

    public function test_order_ownership_and_payload_validation_are_enforced(): void
    {
        $this->postJson('/api/user/order-activity-subscriptions', $this->payload(['order_id' => 20]))->assertNotFound();
        $this->postJson('/api/user/order-activity-subscriptions', $this->payload(['live_activity_token' => 'wrong']))->assertStatus(422);
        $this->postJson('/api/user/order-activity-subscriptions', $this->payload(['fcm_token' => '']))->assertStatus(422);
        $this->assertSame(0, OrderActivitySubscription::count());
    }

    public function test_every_stage_updates_only_its_order_and_delivery_preserves_final_state(): void
    {
        $this->register();
        $sync = $this->app->make(Sync::class);
        foreach (['pending', 'shipped', 'completed'] as $status) {
            DB::table('orders')->where('id', 10)->update(['status' => $status, 'updated_at' => now()]);
            $sync->order(Order::withoutGlobalScopes()->find(10));
            $sync->order(Order::withoutGlobalScopes()->find(10)); // Idempotent retries.
        }
        $this->assertSame(['pending', 'shipped', 'completed'], array_column(array_column($this->push->sent, 'state'), 'orderStatus'));
        $this->assertSame(['10', '10', '10'], array_column(array_column($this->push->sent, 'state'), 'orderId'));
        $this->assertTrue($this->push->sent[2]['terminal']);
        $this->assertNotNull(OrderActivitySubscription::first()->ended_at);
        $message = Push::payload(OrderActivitySubscription::first(), $this->push->sent[2]['state'], true, 1000)['message'];
        $this->assertSame(str_repeat('ab', 32), $message['apns']['live_activity_token']);
        $this->assertSame('liveactivity', $message['apns']['headers']['apns-push-type']);
        $this->assertSame('end', $message['apns']['payload']['aps']['event']);
        $this->assertSame(1120, $message['apns']['payload']['aps']['dismissal-date']);
        $this->assertSame('completed', $message['apns']['payload']['aps']['content-state']['orderStatus']);
    }

    public function test_failed_delivery_is_retried_and_expired_or_foreign_registrations_are_skipped(): void
    {
        $this->register();
        $sync = $this->app->make(Sync::class);
        $this->push->fail = true;
        $sync->order(Order::withoutGlobalScopes()->find(10));
        $this->assertNull(OrderActivitySubscription::first()->last_status);
        $this->push->fail = false;
        $sync->order(Order::withoutGlobalScopes()->find(10));
        $this->assertCount(1, $this->push->sent);
        OrderActivitySubscription::query()->update(['last_status' => null, 'user_id' => 2]);
        $sync->order(Order::withoutGlobalScopes()->find(10));
        $this->assertCount(1, $this->push->sent);
        OrderActivitySubscription::query()->update(['user_id' => 1, 'expires_at' => now()->subMinute()]);
        $sync->order(Order::withoutGlobalScopes()->find(10));
        $this->assertCount(1, $this->push->sent);
    }

    public function test_unregister_is_scoped_to_the_signed_in_user_and_native_activity(): void
    {
        $this->register();
        $this->postJson('/api/user/order-activity-subscriptions/unregister', ['order_id' => 20, 'live_activity_id' => 'native-uuid-10'])->assertOk();
        $this->assertNull(OrderActivitySubscription::first()->ended_at);
        $this->postJson('/api/user/order-activity-subscriptions/unregister', ['order_id' => 10, 'live_activity_id' => 'native-uuid-10'])->assertOk();
        $this->assertNotNull(OrderActivitySubscription::first()->ended_at);
    }

    public function test_status_observer_dispatches_after_response_and_scheduler_catches_bulk_changes(): void
    {
        $this->register();
        Bus::fake([SyncOrderLiveActivity::class]);
        $order = Order::withoutGlobalScopes()->find(10);
        $order->update(['status' => 'shipped']);
        Bus::assertDispatchedAfterResponse(SyncOrderLiveActivity::class, function ($job) { return $job->orderId === 10; });
        // Query-builder transitions skip model observers; the scheduled reconcile still delivers them.
        DB::table('orders')->where('id', 10)->update(['status' => 'completed']);
        $this->artisan('orders:sync-live-activities')->assertExitCode(0);
        $this->assertSame('completed', $this->push->sent[0]['state']['orderStatus']);
    }
}
