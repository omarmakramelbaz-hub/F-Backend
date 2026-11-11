<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The vendor app's menu (/api/vendor/items...): a branch's items are changed, hidden or removed by that branch's vendor
 * alone, never by another branch's vendor nor by a customer.
 */
class VendorMenuApiTest extends TestCase
{
    use Concerns\CustomerAppSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerAppSchema();
        DB::table('users')->insert([['id' => 30, 'name' => 'تاجر شبرا', 'mobile' => '1000000030', 'account_type' => 'vendor', 'status' => 'accepted', 'app_scope' => 'fasakhansta'],
            ['id' => 31, 'name' => 'تاجر المحلة', 'mobile' => '1000000031', 'account_type' => 'vendor', 'status' => 'accepted', 'app_scope' => 'fasakhansta']]);
        DB::table('resturants')->where('id', 301)->update(['user_id' => 30]);
        DB::table('resturants')->insert(['id' => 94, 'name' => 'فرع المحلة', 'user_id' => 31, 'status' => 'opened']);
    }

    protected function as(int $user): self
    {
        return $this->withHeaders($this->appHeaders($user));
    }

    private function item(): array
    {
        $row = DB::table('resturant_products')->where('id', 10)->first();
        return $row ? [(int) $row->resturant_id, (string) $row->product_price, (string) $row->status] : [];
    }

    public function test_only_the_branchs_vendor_changes_its_item(): void
    {
        $before = $this->item();
        foreach ([8, 31] as $stranger) {
            // A customer, or another branch's vendor: refused, nothing changes.
            $this->assertContains($this->as($stranger)->postJson('/api/vendor/items/10', ['product_price' => '1', 'resturant_id' => 94])->status(), [403, 404]);
            $this->assertContains($this->as($stranger)->putJson('/api/vendor/update/menu/item/10/status', ['status' => 'hide'])->status(), [403, 404, 422]);
            $this->assertContains($this->as($stranger)->deleteJson('/api/vendor/items/10')->status(), [403, 404]);
            $this->assertSame($before, $this->item(), "Untouched by user $stranger");
        }
        $this->as(30)->postJson('/api/vendor/items/10', ['product_price' => '420'])->assertOk();
        $this->assertSame([301, '420', 'show'], $this->item());
    }

    public function test_a_vendor_never_moves_an_item_to_another_branch(): void
    {
        $this->as(30)->postJson('/api/vendor/items/10', ['product_price' => '420', 'resturant_id' => 94, 'added_by' => 31])->assertOk();
        $this->assertSame(301, $this->item()[0]);
    }

    public function test_a_branch_is_opened_closed_and_edited_by_its_own_vendor_only(): void
    {
        foreach ([8, 31] as $stranger) {
            $this->assertContains($this->as($stranger)->postJson('/api/vendor/resturants/301', ['status' => 'closed'])->status(), [403, 404]);
            $this->assertContains($this->as($stranger)->postJson('/api/vendor/resturants/301/update', ['name' => 'x', 'user_id' => $stranger])->status(), [403, 404]);
        }
        $this->assertSame(['opened', 30, 'فرع شبرا'], [DB::table('resturants')->where('id', 301)->value('status'),
            (int) DB::table('resturants')->where('id', 301)->value('user_id'), DB::table('resturants')->where('id', 301)->value('name')]);
    }

    public function test_a_branchs_order_is_accepted_by_its_own_vendor_only(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        DB::table('orders')->insert(['id' => 500, 'user_id' => 7, 'resturant_id' => 301, 'status' => 'pending', 'type' => 'current', 'user_address_id' => 70,
            'payment_type' => 'cash', 'delivery_price' => 25]);
        DB::table('carts')->where('user_id', 7)->update(['order_id' => 500, 'is_order' => true]);
        foreach ([8, 31] as $stranger) {
            $this->assertContains($this->as($stranger)->postJson('/api/vendor/orders/500/accept', ['status' => 'accepted'])->status(), [403, 404]);
            $this->assertContains($this->as($stranger)->postJson('/api/vendor/update/orders/500/total/price', ['total' => 1, 'reason' => 'x'])->status(), [403, 404, 500]);
        }
        // Accepting tells the customer (accepted_notify); a stranger's taps change nothing.
        $this->assertSame(['pending', null], [DB::table('orders')->where('id', 500)->value('status'), DB::table('orders')->where('id', 500)->value('accepted_notify')]);
        $this->as(30)->postJson('/api/vendor/orders/500/accept', ['status' => 'accepted'])->assertOk();
        $this->assertSame('yes', DB::table('orders')->where('id', 500)->value('accepted_notify'));
    }
}
