<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The customer app's checkout (/api/user/order/payment): the cart becomes an order priced from its items, the delivery
 * the app sent, the customer's tax and the service fee; paid in cash or from the wallet. What a customer may and may
 * not decide about their own order.
 */
class CustomerOrderPaymentApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'broadcasting.default' => 'null']);
        DB::purge('sqlite');
        Schema::clearResolvedInstance('db.schema');
        \App\Database\SchemaMemory::forgetEverything();
        Bus::fake();
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('email')->nullable(); $t->string('mobile')->nullable(); $t->string('password')->nullable();
            $t->string('account_type')->default('user'); $t->string('app_scope')->nullable(); $t->string('status')->nullable();
            $t->unsignedBigInteger('added_by')->nullable(); $t->unsignedBigInteger('owner_resturant_id')->nullable();
            $t->decimal('balance', 12, 2)->default(0); $t->integer('otp_first_order')->default(1); $t->string('otp_first_no')->nullable(); $t->timestamps();
        });
        Schema::create('resturants', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->unsignedBigInteger('user_id')->nullable(); $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('status')->nullable(); $t->string('open_at')->nullable(); $t->string('close_at')->nullable();
            $t->decimal('min_order_price', 10, 2)->default(0); $t->decimal('service_fees', 5, 2)->default(0);
            $t->string('lat')->nullable(); $t->string('lng')->nullable(); $t->string('km_price')->nullable(); $t->string('default_0_1')->nullable(); $t->string('default_1_2')->nullable(); $t->string('default_2_3')->nullable(); $t->timestamps();
        });
        Schema::create('resturant_products', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('product_id')->nullable(); $t->unsignedBigInteger('added_by')->nullable(); $t->text('product_description')->nullable(); $t->string('highest_rated')->default('no'); $t->string('product_name'); $t->string('product_price')->default('0'); $t->text('price')->nullable();
            $t->unsignedBigInteger('category_id')->nullable(); $t->string('status')->default('show'); $t->timestamps();
        });
        Schema::create('carts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id')->nullable(); $t->boolean('is_order')->default(false); $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('resturant_product_id'); $t->decimal('qty', 10, 3)->default(1);
            $t->decimal('price', 10, 2)->default(0); $t->string('product_clean')->nullable(); $t->unsignedBigInteger('product_feature')->nullable();
            $t->decimal('updated_total', 10, 2)->nullable(); $t->string('reason_update_total')->nullable(); $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->unsignedBigInteger('resturant_id')->nullable(); $t->string('status')->nullable();
            $t->string('type')->nullable(); $t->unsignedBigInteger('user_address_id')->nullable(); $t->string('schedule_date')->nullable();
            $t->string('payment_type')->nullable(); $t->decimal('delivery_price', 10, 2)->nullable(); $t->text('notes')->nullable(); $t->string('order_type')->nullable();
            $t->string('delegate_from_out')->nullable(); $t->unsignedBigInteger('delegate_id')->nullable(); $t->unsignedBigInteger('shipping_id')->nullable();
            $t->decimal('user_tax', 10, 2)->nullable(); $t->decimal('tax', 10, 2)->nullable(); $t->decimal('vendor_tax', 10, 2)->nullable();
            $t->string('order_no')->nullable(); $t->string('transfer_price_by')->nullable(); $t->timestamp('shipping_cancelled_block')->nullable(); $t->unsignedInteger('shift_sequence')->nullable(); $t->unsignedInteger('shift_order_number')->nullable(); $t->timestamps();
        });
        Schema::create('user_address', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('mobile')->nullable(); $t->string('lat')->nullable(); $t->string('lng')->nullable(); $t->timestamps(); });
        Schema::create('wallets', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('from_user')->nullable(); $t->unsignedBigInteger('to_user')->nullable(); $t->string('status')->nullable();
            $t->string('payment')->nullable(); $t->string('type')->nullable(); $t->decimal('amount', 12, 2)->default(0); $t->unsignedBigInteger('order_id')->nullable(); $t->timestamps();
        });
        Schema::create('resturant_areas', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('area_id')->nullable(); $t->string('lat')->nullable(); $t->string('lng')->nullable(); $t->string('expected_delivery')->nullable(); $t->timestamps(); });
        Schema::create('areas', function (Blueprint $t) { $t->id(); $t->string('title_ar')->nullable(); $t->string('title_en')->nullable(); $t->timestamps(); });
        Schema::create('delegate_notifications', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('order_id')->nullable(); $t->unsignedBigInteger('delegate_id')->nullable(); $t->timestamps(); });
        Schema::create('reviews', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('order_id')->nullable(); $t->unsignedBigInteger('user_id')->nullable(); $t->unsignedBigInteger('resturant_id')->nullable(); $t->integer('rate')->nullable(); $t->text('comment')->nullable(); $t->timestamps(); });
        Schema::create('media', function (Blueprint $t) {
            $t->id(); $t->morphs('model'); $t->uuid('uuid')->nullable(); $t->string('collection_name'); $t->string('name'); $t->string('file_name');
            $t->string('mime_type')->nullable(); $t->string('disk'); $t->string('conversions_disk')->nullable(); $t->unsignedBigInteger('size');
            $t->json('manipulations'); $t->json('custom_properties'); $t->json('generated_conversions'); $t->json('responsive_images');
            $t->unsignedInteger('order_column')->nullable(); $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) { $t->id(); $t->string('name_ar')->nullable(); $t->string('name_en')->nullable(); $t->timestamps(); });
        Schema::create('products', function (Blueprint $t) { $t->id(); $t->string('name_ar')->nullable(); $t->string('name_en')->nullable(); $t->unsignedBigInteger('category_id')->nullable(); $t->unsignedBigInteger('subcategory_id')->nullable(); $t->string('has_clean')->nullable(); $t->unsignedBigInteger('added_by')->nullable(); $t->timestamps(); });
        Schema::create('product_features', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('product_id')->nullable(); $t->string('name'); $t->timestamps(); });
        Schema::create('commissions', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('order_id')->nullable(); $t->unsignedBigInteger('user_id')->nullable(); $t->decimal('amount', 10, 2)->default(0); $t->timestamps(); });
        Schema::create('shippings', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('order_id')->nullable(); $t->decimal('actual_price', 10, 2)->nullable(); $t->timestamps(); });
        Schema::create('notifications', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('type'); $t->morphs('notifiable'); $t->text('data'); $t->timestamp('read_at')->nullable(); $t->timestamps(); });
        Schema::create('settings', function (Blueprint $t) { $t->id(); $t->string('group'); $t->string('name'); $t->boolean('locked')->default(false); $t->json('payload'); $t->timestamps(); });
        $settings = (new \ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(\ReflectionProperty::IS_PUBLIC);
        $values = ['tax' => '14', 'service_fees' => '0', 'min_order_price' => '100', 'km_price' => '5'];
        foreach ($settings as $property) {
            if ($property->isStatic()) continue;
            DB::table('settings')->insert(['group' => 'general', 'name' => $property->getName(), 'payload' => json_encode($values[$property->getName()] ?? '')]);
        }

        DB::table('users')->insert([['id' => 7, 'name' => 'عميل', 'mobile' => '1000000007', 'app_scope' => 'fasakhansta', 'balance' => 1000],
            ['id' => 8, 'name' => 'عميل تاني', 'mobile' => '1000000008', 'app_scope' => 'fasakhansta', 'balance' => 0]]);
        // Its tariff: 15, 20 and 25 for the first three kilometres, then 9 a kilometre.
        DB::table('resturants')->insert(['id' => 301, 'name' => 'فرع شبرا', 'status' => 'opened', 'min_order_price' => 100, 'service_fees' => 10,
            'lat' => '30.0800', 'lng' => '31.2450', 'km_price' => '9', 'default_0_1' => '15', 'default_1_2' => '20', 'default_2_3' => '25']);
        DB::table('products')->insert(['id' => 5, 'name_ar' => 'فسيخ', 'name_en' => 'Feseekh']);
        DB::table('resturant_products')->insert(['id' => 10, 'resturant_id' => 301, 'product_id' => 5, 'product_name' => 'فسيخ كيلو', 'product_price' => '400', 'price' => json_encode(['extra_combo' => 0, 'extra_large' => 0, 'extra_medium' => 0, 'extra_clean' => 0, 'extra_clear' => 0, 'extra_vacuim' => 0])]);
        // About 2.2 km from the branch (the 2-3 km price: 25), and another customer's address.
        DB::table('user_address')->insert([['id' => 70, 'user_id' => 7, 'mobile' => '1000000007', 'lat' => '30.0998', 'lng' => '31.2450'],
            ['id' => 80, 'user_id' => 8, 'mobile' => '1000000008', 'lat' => '30.0998', 'lng' => '31.2450']]);
        DB::table('carts')->insert(['user_id' => 7, 'resturant_id' => 301, 'resturant_product_id' => 10, 'qty' => 2, 'price' => 400]);
    }

    private function pay(array $values, int $user = 7)
    {
        app('auth')->forgetGuards();
        JWTAuth::unsetToken();
        app('tymon.jwt')->unsetToken();
        $route = collect(app('router')->getRoutes())->first(fn ($route) => str_ends_with($route->getActionName(), 'CartController@order_payment'));
        return $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser(User::query()->find($user)), 'Accept' => 'application/json'])
            ->json($route->methods()[0], '/'.$route->uri(), $values + ['user_address_id' => 70, 'delivery_price' => 25]);
    }

    private function order(): object
    {
        return \App\Models\Order::withoutGlobalScopes()->firstOrFail();
    }

    public function test_a_cash_order_takes_the_cart_and_waits_for_the_branch(): void
    {
        $id = $this->pay(['payment_type' => 'cash', 'notes' => 'من غير بصل'])->assertOk()->assertJsonPath('status', 'Success')->json('data.id');
        $order = $this->order();
        $this->assertSame([(int) $id, 7, 301, 'pending', 'current', 'cash', 'من غير بصل'],
            [$order->id, (int) $order->user_id, (int) $order->resturant_id, $order->status, $order->type, $order->payment_type, $order->notes]);
        // 800 of items + 25 delivery + 14% tax on the items (112).
        $this->assertEquals(800, $order->total);
        $this->assertEquals(112, (float) $order->user_tax);
        $this->assertEquals(937, $order->grand_total);
        $this->assertSame(0, DB::table('carts')->where('user_id', 7)->where('is_order', false)->count(), 'The cart became the order');
        Bus::assertDispatchedAfterResponse(\App\Jobs\SendOrderCreatedAfterResponse::class);
    }

    public function test_a_wallet_order_takes_its_whole_price_from_the_balance(): void
    {
        $live = new class { public array $events = []; public function event(object $event, ?int $orderId = null): void { $this->events[] = $event; } };
        $this->app->instance(\App\Services\Dashboard\OrderProviderDelivery::class, $live);
        $this->pay(['payment_type' => 'wallet'])->assertOk();
        $balance = collect($live->events)->first(fn ($event) => $event instanceof \App\Events\BalanceUpdated);
        $this->assertNotNull($balance, 'The app is told the new balance');
        $this->assertSame([7, 63.0], [(int) $balance->senderId, (float) $balance->user->balance]);
        $order = $this->order();
        $this->assertSame('pending', $order->status);
        $this->assertEquals(1000 - 937, (float) DB::table('users')->where('id', 7)->value('balance'));
        $this->assertEquals(937, (float) DB::table('wallets')->where('order_id', $order->id)->value('amount'));
    }

    public function test_a_customer_never_sets_what_the_order_costs_or_whose_and_which_it_is(): void
    {
        // A changed app asking for its order to be priced as a wallet top-up (nothing to pay), for another customer and branch,
        // already delivered, with the totals and taxes of its choice.
        $this->pay(['payment_type' => 'wallet', 'type' => 'wallet', 'user_id' => 8, 'resturant_id' => 94, 'status' => 'delivered',
            'user_tax' => 0, 'tax' => 0, 'vendor_tax' => 0, 'delegate_id' => 5, 'id' => 999])->assertOk();
        $order = $this->order();
        $this->assertSame(['current', 7, 301, 'pending', null], [$order->type, (int) $order->user_id, (int) $order->resturant_id, $order->status, $order->delegate_id]);
        $this->assertEquals(937, $order->grand_total);
        $this->assertEquals(1000 - 937, (float) DB::table('users')->where('id', 7)->value('balance'), 'The whole price is paid');
    }

    public function test_a_refused_checkout_leaves_the_cart_as_it_was(): void
    {
        $cartBefore = DB::table('carts')->where('user_id', 7)->get(['id', 'is_order', 'order_id'])->toArray();
        // Not enough in the wallet (937 to pay).
        DB::table('users')->where('id', 7)->update(['balance' => 900]);
        $this->pay(['payment_type' => 'wallet'])->assertStatus(500)->assertJsonPath('status', 'Error');
        // Below the branch's minimum order.
        DB::table('resturants')->where('id', 301)->update(['min_order_price' => 5000]);
        $this->pay(['payment_type' => 'cash'])->assertStatus(500)->assertJsonPath('status', 'Error');
        $this->assertEquals($cartBefore, DB::table('carts')->where('user_id', 7)->get(['id', 'is_order', 'order_id'])->toArray(), 'The cart is still there to pay');
        $this->assertSame(0, DB::table('orders')->count(), 'No order left behind');
        $this->assertEquals(900, (float) DB::table('users')->where('id', 7)->value('balance'));
        $this->assertSame(0, DB::table('wallets')->count());
    }

    public function test_the_wallet_pays_one_order_with_the_same_money_once(): void
    {
        DB::table('users')->where('id', 7)->update(['balance' => 937]);
        $this->pay(['payment_type' => 'wallet'])->assertOk();
        $this->assertEquals(0, (float) DB::table('users')->where('id', 7)->value('balance'));
        // A second order from the same (now empty) balance is refused, and its cart stays.
        DB::table('carts')->insert(['user_id' => 7, 'resturant_id' => 301, 'resturant_product_id' => 10, 'qty' => 2, 'price' => 400]);
        $this->pay(['payment_type' => 'wallet'])->assertStatus(500);
        $this->assertEquals(0, (float) DB::table('users')->where('id', 7)->value('balance'));
        $this->assertSame(1, DB::table('wallets')->count());
        $this->assertSame(1, DB::table('carts')->where('user_id', 7)->where('is_order', false)->count());
    }

    private function cancel(int $order, int $user = 7)
    {
        app('auth')->forgetGuards();
        JWTAuth::unsetToken();
        app('tymon.jwt')->unsetToken();
        return $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser(User::query()->find($user)), 'Accept' => 'application/json'])
            ->postJson('/api/user/cancel/order/'.$order);
    }

    public function test_a_customer_cancels_their_own_waiting_order_once(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $id = $this->pay(['payment_type' => 'cash'])->assertOk()->json('data.id');
        // Not someone else's order (OrderCancellationController serves this address): refused, nothing changes.
        $this->cancel($id, 8)->assertStatus(500)->assertJsonPath('status', 'Error');
        $this->assertSame('pending', DB::table('orders')->where('id', $id)->value('status'));
        $this->cancel($id)->assertOk()->assertJsonPath('status', 'Success');
        $this->assertSame('cancelled', DB::table('orders')->where('id', $id)->value('status'));
        // A second tap (or a stale screen) is answered as done, and changes nothing.
        $this->cancel($id)->assertOk()->assertJsonPath('message', __('api.already cancelled'));
        $this->assertSame('cancelled', DB::table('orders')->where('id', $id)->value('status'));
    }

    public function test_an_order_the_branch_accepted_is_not_cancelled_by_the_customer(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $id = $this->pay(['payment_type' => 'cash'])->assertOk()->json('data.id');
        DB::table('orders')->where('id', $id)->update(['status' => 'accepted']);
        $this->cancel($id)->assertStatus(500)->assertJsonPath('status', 'Error');
        $this->assertSame('accepted', DB::table('orders')->where('id', $id)->value('status'));
    }

    public function test_a_cancelled_wallet_order_is_paid_back_once_two_hours_later(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $id = $this->pay(['payment_type' => 'wallet'])->assertOk()->json('data.id');
        $this->assertEquals(63, (float) DB::table('users')->where('id', 7)->value('balance'));
        $this->cancel($id)->assertOk();
        $this->artisan('notify:moneyback')->assertExitCode(0);
        $this->assertEquals(63, (float) DB::table('users')->where('id', 7)->value('balance'), 'Not before two hours');
        $this->travel(121)->minutes();
        $this->artisan('notify:moneyback')->assertExitCode(0);
        $this->assertEquals(1000, (float) DB::table('users')->where('id', 7)->value('balance'), 'The whole price is back');
        $this->artisan('notify:moneyback')->assertExitCode(0);
        $this->assertEquals(1000, (float) DB::table('users')->where('id', 7)->value('balance'), 'Paid back once');
        $this->assertSame('admin', DB::table('orders')->where('id', $id)->value('transfer_price_by'));
        $this->assertEquals([937.0, 937.0], DB::table('wallets')->orderBy('id')->pluck('amount')->map(fn ($v) => (float) $v)->all());
    }

    public function test_an_order_goes_only_to_the_customers_own_address(): void
    {
        $this->pay(['payment_type' => 'cash', 'user_address_id' => 80])->assertStatus(500)->assertJsonPath('status', 'Error');
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(1, DB::table('carts')->where('user_id', 7)->where('is_order', false)->count(), 'The cart stays as it was');
    }

    public function test_a_delivery_below_the_branch_tariff_is_written_down_and_the_order_still_goes(): void
    {
        $expected = \App\Services\Dashboard\CustomerDeliveryCheck::expected(\App\Models\Resturant::withoutGlobalScopes()->find(301), \App\Models\UserAddress::find(70));
        $this->assertSame(['25.00', 'app_branch_tier', 'default_2_3'], [$expected['delivery_fee'], $expected['pricing_rule'], $expected['pricing_tier']]);
        \Illuminate\Support\Facades\Log::spy();
        $this->pay(['payment_type' => 'cash', 'delivery_price' => 24.5])->assertOk();
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
        DB::table('carts')->insert(['user_id' => 7, 'resturant_id' => 301, 'resturant_product_id' => 10, 'qty' => 2, 'price' => 400]);
        $this->pay(['payment_type' => 'cash', 'delivery_price' => 5])->assertOk();
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'GO_CUSTOMER_DELIVERY_BELOW_TARIFF'
            && $context['sent'] === '5' && $context['tariff'] === '25.00' && $context['customer'] === 7 && $context['branch'] === 301);
        $this->assertSame(2, DB::table('orders')->where('status', 'pending')->count(), 'Watched only: both orders went');
    }

    public function test_the_delivery_price_is_never_negative(): void
    {
        $this->pay(['payment_type' => 'cash', 'delivery_price' => -500])->assertStatus(500)->assertJsonPath('status', 'Error');
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(1, DB::table('carts')->where('user_id', 7)->where('is_order', false)->count(), 'The cart stays as it was');
    }
}
