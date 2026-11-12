<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The customer app registers its order's Live Activity at POST /api/user/order-activity-subscriptions with its own
 * names (order_id, live_activity_id, live_activity_token, fcm_token); the server kept it under another address and
 * names, so not one registration ever arrived (no Live Activity was updated).
 */
class OrderLiveActivityApiTest extends TestCase
{
    use Concerns\CustomerAppSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerAppSchema();
        require_once database_path('migrations/2026_10_18_200000_create_order_live_activities_table.php');
        (new \CreateOrderLiveActivitiesTable())->up();
        DB::table('users')->whereIn('id', [7, 8])->update(['account_type' => 'user']);
        DB::table('orders')->insert(['id' => 500, 'user_id' => 7, 'resturant_id' => 301, 'status' => 'pending', 'type' => 'current', 'payment_type' => 'cash']);
    }

    private function appCall(int $user, string $path, array $values)
    {
        return $this->withHeaders($this->appHeaders($user))->postJson('/api/user/'.$path, $values);
    }

    private function payload(array $change = []): array
    {
        return array_replace(['order_id' => 500, 'live_activity_id' => 'A1B2C3D4-0000-1111-2222-333344445555',
            'live_activity_token' => str_repeat('ab', 32), 'fcm_token' => str_repeat('f', 40), 'platform' => 'ios'], $change);
    }

    public function test_the_apps_registration_reaches_the_server_once_and_its_unregister_too(): void
    {
        $this->appCall(7, 'order-activity-subscriptions', $this->payload())->assertOk()->assertJson(['status' => true]);
        $this->appCall(7, 'order-activity-subscriptions', $this->payload())->assertOk();
        $this->assertSame(1, DB::table('order_live_activities')->where('order_id', 500)->where('user_id', 7)->count());
        $row = DB::table('order_live_activities')->first();
        $this->assertStringNotContainsString(str_repeat('ab', 32), $row->activity_token, 'Kept encrypted');
        $this->appCall(7, 'order-activity-subscriptions/unregister', ['order_id' => 500, 'live_activity_id' => 'A1B2C3D4-0000-1111-2222-333344445555'])->assertOk();
        $this->assertSame(0, DB::table('order_live_activities')->count());
    }

    public function test_another_customers_order_or_a_bad_token_is_refused(): void
    {
        $this->appCall(8, 'order-activity-subscriptions', $this->payload())->assertStatus(404);
        $this->appCall(7, 'order-activity-subscriptions', $this->payload(['live_activity_token' => 'not-hex']))->assertStatus(422);
        $this->appCall(7, 'order-activity-subscriptions', $this->payload(['order_id' => null]))->assertStatus(422);
        $this->assertSame(0, DB::table('order_live_activities')->count());
    }

    public function test_the_update_carries_the_names_the_iphone_reads(): void
    {
        $sent = [];
        $this->app->instance(\App\Services\Dashboard\FcmLiveActivityTransport::class, new class($sent) extends \App\Services\Dashboard\FcmLiveActivityTransport {
            public function __construct(private array &$sent) {}
            public function send(string $fcmToken, string $activityToken, array $state, int $timestamp, bool $terminal): void { $this->sent[] = [$state, $terminal]; }
        });
        $this->appCall(7, 'order-activity-subscriptions', $this->payload())->assertOk();
        app(\App\Services\Dashboard\OrderLiveActivityService::class)->deliverOrder(500);
        $this->assertCount(1, $sent);
        [$state, $terminal] = $sent[0];
        $this->assertFalse($terminal);
        // LiveActivitiesAppAttributes.ContentState (ios/Runner/AppDelegate.swift): every field required, these types.
        $this->assertSame('500', $state['orderId']);
        $this->assertSame('Preparing', $state['orderStatus']);
        $this->assertSame('فرع شبرا', $state['restaurantName']);
        $this->assertIsInt($state['updatedAt']);
    }
}
