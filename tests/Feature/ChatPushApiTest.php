<?php

namespace Tests\Feature;

use App\Services\Chat\ChatPush;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * POST /api/user/chat_notify: the server sends a chat message's push (the app holds no Firebase key). Only the courier of
 * the customer's own order, or the support team, is told.
 */
class ChatPushApiTest extends TestCase
{
    use Concerns\CustomerAppSchema;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerAppSchema();
        Schema::table('users', function (Blueprint $t) { $t->string('fcm_id')->nullable(); $t->string('device_token')->nullable(); });
        DB::table('users')->insert([
            ['id' => 20, 'name' => 'المندوب', 'mobile' => '1000000020', 'account_type' => 'delegate', 'fcm_id' => 'courier-token', 'device_token' => null],
            ['id' => 21, 'name' => 'الدعم', 'mobile' => '1000000021', 'account_type' => 'admin', 'fcm_id' => null, 'device_token' => 'admin-token'],
        ]);
        DB::table('users')->where('id', 7)->update(['fcm_id' => 'customer-token']);
        DB::table('orders')->insert([
            ['id' => 501, 'user_id' => 7, 'delegate_id' => 20, 'type' => 'current', 'status' => 'shipped'],
            ['id' => 502, 'user_id' => 8, 'delegate_id' => 20, 'type' => 'shipping', 'status' => 'shipped'],
            ['id' => 503, 'user_id' => 7, 'delegate_id' => null, 'type' => 'current', 'status' => 'pending'],
        ]);
        $this->app->instance(ChatPush::class, new class($this->sent) extends ChatPush {
            public function __construct(private array &$sent) {}
            public function send(array $tokens, array $body): void { $this->sent[] = [$tokens, $body]; }
        });
    }

    private function notify(int $user, array $values)
    {
        $route = collect(app('router')->getRoutes())->first(fn ($route) => str_contains($route->getActionName(), 'ChatPushController'));
        return $this->withHeaders($this->appHeaders($user))->postJson('/'.$route->uri(), $values);
    }

    public function test_the_courier_of_the_customers_own_order_is_told(): void
    {
        $this->notify(7, ['chat' => 'order', 'chat_id' => 'DC501', 'message' => 'أنا على الباب'])->assertJson(['status' => 'Success']);
        $this->assertCount(1, $this->sent);
        [$tokens, $body] = $this->sent[0];
        $this->assertSame(['courier-token'], $tokens);
        $this->assertSame('أنا على الباب', $body['text']);
        $this->assertSame(['DC501', '8', 'user', 'courier-token', 'customer-token'], [$body['data']['order_id'], $body['data']['notification_type'],
            $body['data']['account_type'], $body['data']['receiver_device_token'], $body['data']['sender_device_token']]);
    }

    public function test_another_customers_order_or_one_without_a_courier_tells_no_one(): void
    {
        $this->notify(7, ['chat' => 'order', 'chat_id' => 'VD502', 'message' => 'دخيل'])->assertStatus(404);
        $this->notify(7, ['chat' => 'order', 'chat_id' => 'DC503', 'message' => 'لسه'])->assertStatus(404);
        $this->notify(7, ['chat' => 'order', 'chat_id' => '502', 'message' => 'شكل غلط'])->assertStatus(422);
        $this->assertSame([], $this->sent);
    }

    public function test_support_is_told_on_the_admin_device(): void
    {
        $this->notify(7, ['chat' => 'support', 'message' => 'محتاج مساعدة'])->assertJson(['status' => 'Success']);
        [$tokens, $body] = $this->sent[0];
        $this->assertSame(['admin-token'], $tokens);
        $this->assertSame(['7', '21', '4'], [$body['data']['sender_id'], $body['data']['receiver_id'], $body['data']['msg_type']]);
    }
}
