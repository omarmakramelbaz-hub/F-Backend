<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The courier app's orders (/api/delegate/...): who reads an order's details (the customer's name, address and mobile),
 * and who moves an order on (accepted, shipped, completed), for app orders and shipping orders alike.
 */
class CourierOrderApiTest extends TestCase
{
    use Concerns\CustomerAppSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerAppSchema();
        \Illuminate\Support\Facades\Notification::fake();
        DB::table('users')->insert([['id' => 20, 'name' => 'مندوب', 'mobile' => '1000000020', 'account_type' => 'delegate', 'status' => 'accepted', 'app_scope' => 'fasakhansta'],
            ['id' => 21, 'name' => 'مندوب تاني', 'mobile' => '1000000021', 'account_type' => 'delegate', 'status' => 'accepted', 'app_scope' => 'fasakhansta']]);
        DB::table('orders')->insert([
            ['id' => 500, 'user_id' => 7, 'resturant_id' => 301, 'status' => 'accepted', 'type' => 'current', 'user_address_id' => 70, 'payment_type' => 'cash', 'delivery_price' => 25, 'delegate_id' => 20],
            ['id' => 600, 'user_id' => 7, 'resturant_id' => 301, 'status' => 'accepted', 'type' => 'shipping', 'user_address_id' => 70, 'payment_type' => 'cash', 'delivery_price' => 40, 'delegate_id' => 20],
        ]);
        DB::table('carts')->where('user_id', 7)->update(['order_id' => 500, 'is_order' => true]);
    }

    protected function as(int $user): self
    {
        app('auth')->forgetGuards();
        JWTAuth::unsetToken();
        app('tymon.jwt')->unsetToken();
        return $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser(User::query()->find($user)), 'Accept' => 'application/json']);
    }

    private function status(int $order): ?string
    {
        return DB::table('orders')->where('id', $order)->value('status');
    }

    public function test_only_a_courier_reads_an_orders_details(): void
    {
        $this->as(20)->getJson('/api/delegate/orders/500')->assertOk()->assertJsonPath('data.user_mobile', '1000000007');
        // A customer never reads another customer's order (name, address, mobile) through the courier address.
        $this->as(8)->getJson('/api/delegate/orders/500')->assertForbidden();
        $this->assertSame([], array_filter([$this->as(8)->getJson('/api/delegate/orders/600')->json('data.user_mobile')]));
    }

    public function test_an_app_order_moves_on_only_by_its_own_courier(): void
    {
        // Another courier: refused (404 from the locked lookup today, 403 from the party check).
        $this->assertContains($this->as(21)->postJson('/api/delegate/orders/500/completed')->status(), [403, 404]);
        $this->as(8)->postJson('/api/delegate/accept_decline/orders/500', ['status' => 'shipped'])->assertForbidden();
        $this->assertSame('accepted', $this->status(500));
        $this->as(20)->postJson('/api/delegate/accept_decline/orders/500', ['status' => 'shipped'])->assertOk();
        $this->assertSame('shipped', $this->status(500));
    }

    public function test_a_courier_takes_an_app_order_sent_to_them_and_moves_it_on(): void
    {
        DB::table('orders')->where('id', 500)->update(['delegate_id' => null, 'status' => 'pending', 'delegate_from_out' => 'out_resturant']);
        DB::table('delegate_notifications')->insert(['order_id' => 500, 'delegate_id' => 21]);
        // Not sent to courier 20: refused; sent to 21: taken.
        $this->assertContains($this->as(20)->postJson('/api/delegate/accept_decline/orders/500', ['status' => 'accept'])->status(), [403, 404]);
        $this->as(21)->postJson('/api/delegate/accept_decline/orders/500', ['status' => 'accept'])->assertOk();
        $this->assertSame([21, 'accepted'], [(int) DB::table('orders')->where('id', 500)->value('delegate_id'), $this->status(500)]);
        $this->assertContains($this->as(20)->postJson('/api/delegate/accept_decline/orders/500', ['status' => 'shipped'])->status(), [403, 404]);
        $this->as(21)->postJson('/api/delegate/accept_decline/orders/500', ['status' => 'shipped'])->assertOk();
        $this->assertSame('shipped', $this->status(500));
    }

    public function test_a_shipping_order_moves_on_only_by_its_own_courier(): void
    {
        // Marking it shipped or completed (which settles its money) is for its courier alone.
        $this->as(8)->postJson('/api/delegate/accept_decline/orders/600', ['status' => 'shipped'])->assertForbidden();
        $this->as(21)->postJson('/api/delegate/accept_decline/orders/600', ['status' => 'shipped'])->assertForbidden();
        $this->as(8)->postJson('/api/delegate/orders/600/completed')->assertForbidden();
        $this->as(21)->postJson('/api/delegate/orders/600/completed')->assertForbidden();
        $this->as(8)->postJson('/api/delegate/transfer/order/600/price')->assertForbidden();
        $this->assertSame('accepted', $this->status(600));
        $this->as(20)->postJson('/api/delegate/accept_decline/orders/600', ['status' => 'shipped'])->assertOk();
        $this->assertSame('shipped', $this->status(600));
    }

    public function test_a_courier_invited_to_a_shipping_order_answers_the_invitation(): void
    {
        DB::table('orders')->where('id', 600)->update(['delegate_id' => null, 'status' => 'pending']);
        DB::table('delegate_notifications')->insert(['order_id' => 600, 'delegate_id' => 21]);
        // Not invited: refused.
        $this->as(20)->postJson('/api/delegate/accept_decline/orders/600', ['status' => 'accept'])->assertForbidden();
        $this->as(21)->postJson('/api/delegate/accept_decline/orders/600', ['status' => 'accept'])->assertOk();
    }
}
