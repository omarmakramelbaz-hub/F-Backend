<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\User\CartController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The customer's cart: an order's total is price × quantity, so a quantity is kept only above zero and at most 1000 a
 * line (Arabic digits and half a kilo read as the app sends them); a line saved before with a wrong quantity stops the
 * checkout instead of lowering the order. An account adds a location to itself only.
 */
class CartQuantityApiTest extends TestCase
{
    use Concerns\CustomerAppSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerAppSchema();
        Notification::fake();
    }

    private function api(string $action, array $values, array $parameters = [], int $user = 7)
    {
        $route = collect(app('router')->getRoutes())->first(fn ($route) => str_ends_with($route->getActionName(), 'CartController@'.$action)
            || str_ends_with($route->getActionName(), 'UserController@'.$action));
        $uri = '/'.preg_replace_callback('/\{(\w+)\}/', fn ($m) => $parameters[$m[1]], $route->uri());
        return $this->withHeaders($this->appHeaders($user))->json($route->methods()[0], $uri, $values);
    }

    private function lines(): array
    {
        return DB::table('carts')->where('user_id', 7)->where('is_order', false)->orderBy('id')->pluck('qty')->map(fn ($q) => (float) $q)->all();
    }

    public function test_the_quantity_rule(): void
    {
        foreach ([[null, 1.0], ['', 1.0], ['2', 2.0], ['٣', 3.0], ['.5', 0.5], ['0,25', 0.25], [1000, 1000.0]] as [$value, $expected]) {
            $this->assertSame($expected, CartController::quantity($value), var_export($value, true));
        }
        foreach (['0', 0, '-2', -1, '1001', 'abc', '1e3', ['2'], '0.0001'] as $value) {
            $this->assertNull(CartController::quantity($value), var_export($value, true));
        }
    }

    public function test_a_negative_zero_or_huge_quantity_never_reaches_the_cart(): void
    {
        DB::table('carts')->where('user_id', 7)->delete();
        $this->api('store', ['resturant_product_id' => 10, 'qty' => -3])->assertJson(['status' => 'Error']);
        $this->api('store', ['resturant_product_id' => 10, 'qty' => 5000])->assertJson(['status' => 'Error']);
        $this->assertSame([], $this->lines());
        $this->api('store', ['resturant_product_id' => 10, 'qty' => '.5'])->assertJson(['status' => 'Success']);
        $this->assertSame([0.5], $this->lines());
        $id = DB::table('carts')->where('user_id', 7)->value('id');
        $this->api('update', ['qty' => -4], ['id' => $id])->assertJson(['status' => 'Error']);
        $this->assertSame([0.5], $this->lines());
        $this->api('update', ['qty' => 2], ['id' => $id]);
        $this->assertSame([2.0], $this->lines());
        $this->api('update_cart_item', ['resturant_product_id' => 10, 'qty' => -1], ['id' => $id])->assertJson(['status' => 'Error']);
        $this->assertSame([2.0], $this->lines());
        $this->api('update', ['qty' => 0], ['id' => $id]);
        $this->assertSame([], $this->lines(), 'Zero removes the line');
    }

    public function test_a_wrong_quantity_saved_before_stops_the_checkout(): void
    {
        DB::table('carts')->where('user_id', 7)->update(['qty' => -2]);
        $this->api('order_payment', ['user_address_id' => 70, 'delivery_price' => 25, 'payment_type' => 'cash'])
            ->assertJson(['status' => 'Error', 'message' => 'في صنف كميته غير صحيحة في السلة. عدّل الكمية أو امسح الصنف.']);
        $this->assertSame(0, DB::table('orders')->where('user_id', 7)->count());
    }

    public function test_an_account_adds_a_location_to_itself_only(): void
    {
        $before = DB::table('user_address')->where('user_id', 8)->count();
        $this->api('updateUserLocation', ['lat' => '30.1', 'lng' => '31.2', 'address' => 'x'], ['user' => 8], 7)->assertJson(['status' => 'Error']);
        $this->assertSame($before, DB::table('user_address')->where('user_id', 8)->count());
    }
}
