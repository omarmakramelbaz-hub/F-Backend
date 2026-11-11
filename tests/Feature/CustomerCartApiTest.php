<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The customer app's cart (/api/user/cart/...) as it works today, pinned before its code is reorganised: prices with
 * the size and cleaning extras, adding the same item again, quantities, the cart's list and count, and emptying it.
 * The customer signs in with a real app token, through the same middleware as the app.
 */
class CustomerCartApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::clearResolvedInstance('db.schema');
        \App\Database\SchemaMemory::forgetEverything();
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('email')->nullable(); $t->string('mobile')->nullable(); $t->string('password')->nullable();
            $t->string('account_type')->default('user'); $t->string('app_scope')->nullable(); $t->string('status')->nullable();
            $t->unsignedBigInteger('added_by')->nullable(); $t->unsignedBigInteger('owner_resturant_id')->nullable(); $t->timestamps();
        });
        Schema::create('resturants', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->unsignedBigInteger('user_id')->nullable(); $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('lat')->nullable(); $t->string('lng')->nullable(); $t->string('status')->nullable(); $t->string('delivery_time')->nullable(); $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) { $t->id(); $t->string('name_ar')->nullable(); $t->string('name_en')->nullable(); $t->timestamps(); });
        Schema::create('products', function (Blueprint $t) {
            $t->id(); $t->string('name_ar')->nullable(); $t->string('name_en')->nullable(); $t->unsignedBigInteger('category_id')->nullable();
            $t->unsignedBigInteger('subcategory_id')->nullable(); $t->string('has_clean')->nullable(); $t->unsignedBigInteger('added_by')->nullable(); $t->timestamps();
        });
        Schema::create('product_features', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('product_id')->nullable(); $t->string('name'); $t->timestamps(); });
        Schema::create('resturant_products', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('product_id')->nullable(); $t->unsignedBigInteger('category_id')->nullable();
            $t->unsignedBigInteger('added_by')->nullable(); $t->string('product_name'); $t->text('product_description')->nullable();
            $t->string('product_price')->default('0'); $t->text('price')->nullable(); $t->string('status')->default('show'); $t->string('highest_rated')->default('no'); $t->timestamps();
        });
        Schema::create('carts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id')->nullable(); $t->boolean('is_order')->default(false); $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('resturant_product_id'); $t->decimal('qty', 10, 3)->default(1);
            $t->decimal('price', 10, 2)->default(0); $t->string('product_clean')->nullable(); $t->unsignedBigInteger('product_feature')->nullable();
            $t->decimal('updated_total', 10, 2)->nullable(); $t->string('reason_update_total')->nullable(); $t->timestamps();
        });
        Schema::create('media', function (Blueprint $t) {
            $t->id(); $t->morphs('model'); $t->uuid('uuid')->nullable(); $t->string('collection_name'); $t->string('name'); $t->string('file_name');
            $t->string('mime_type')->nullable(); $t->string('disk'); $t->string('conversions_disk')->nullable(); $t->unsignedBigInteger('size');
            $t->json('manipulations'); $t->json('custom_properties'); $t->json('generated_conversions'); $t->json('responsive_images');
            $t->unsignedInteger('order_column')->nullable(); $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) { $t->id(); $t->string('group'); $t->string('name'); $t->boolean('locked')->default(false); $t->json('payload'); $t->timestamps(); });
        DB::table('settings')->insert(['group' => 'general', 'name' => 'min_order_price', 'payload' => json_encode('150')]);

        DB::table('users')->insert([['id' => 7, 'name' => 'عميل', 'mobile' => '1000000007', 'account_type' => 'user', 'app_scope' => 'fasakhansta'],
            ['id' => 8, 'name' => 'عميل تاني', 'mobile' => '1000000008', 'account_type' => 'user', 'app_scope' => 'fasakhansta']]);
        DB::table('resturants')->insert([['id' => 301, 'name' => 'فرع شبرا', 'status' => 'open', 'delivery_time' => '45'], ['id' => 94, 'name' => 'فرع المحلة', 'status' => 'open', 'delivery_time' => '60']]);
        DB::table('categories')->insert(['id' => 1, 'name_ar' => 'فسيخ', 'name_en' => 'Feseekh']);
        DB::table('products')->insert(['id' => 5, 'name_ar' => 'فسيخ', 'name_en' => 'Feseekh', 'category_id' => 1, 'has_clean' => 'yes']);
        DB::table('product_features')->insert([['id' => 1, 'product_id' => 5, 'name' => 'half'], ['id' => 2, 'product_id' => 5, 'name' => 'quarter'],
            ['id' => 3, 'product_id' => 5, 'name' => 'large'], ['id' => 4, 'product_id' => 5, 'name' => 'combo']]);
        $extras = json_encode(['extra_combo' => 30, 'extra_large' => 50, 'extra_medium' => 20, 'extra_clean' => 15, 'extra_clear' => 25, 'extra_vacuim' => 40]);
        DB::table('resturant_products')->insert([
            ['id' => 10, 'resturant_id' => 301, 'product_id' => 5, 'category_id' => 1, 'product_name' => 'فسيخ كيلو', 'product_price' => '400', 'price' => $extras, 'highest_rated' => 'yes'],
            ['id' => 11, 'resturant_id' => 301, 'product_id' => 5, 'category_id' => 1, 'product_name' => 'رنجة', 'product_price' => '120', 'price' => $extras, 'highest_rated' => 'no'],
            ['id' => 12, 'resturant_id' => 301, 'product_id' => 5, 'category_id' => 1, 'product_name' => 'زيادة وردية 5', 'product_price' => '5', 'price' => $extras, 'highest_rated' => 'no'],
            ['id' => 20, 'resturant_id' => 94, 'product_id' => 5, 'category_id' => 1, 'product_name' => 'فسيخ المحلة', 'product_price' => '390', 'price' => $extras, 'highest_rated' => 'no'],
        ]);
    }

    private function as(int $user): self
    {
        // Each request reads its own token, as each app request does.
        app('auth')->forgetGuards();
        JWTAuth::unsetToken();
        app('tymon.jwt')->unsetToken();
        return $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser(User::query()->find($user)), 'Accept' => 'application/json']);
    }

    private function cart(int $user): array
    {
        return DB::table('carts')->where('user_id', $user)->orderBy('id')->get(['resturant_product_id', 'qty', 'price', 'product_feature', 'product_clean', 'is_order'])
            ->map(fn ($row) => [(int) $row->resturant_product_id, (float) $row->qty, (float) $row->price, $row->product_feature === null ? null : (int) $row->product_feature, $row->product_clean])->all();
    }

    public function test_the_price_counts_the_size_and_the_cleaning_extras(): void
    {
        $route = collect(app('router')->getRoutes())->first(fn ($route) => str_ends_with($route->getActionName(), 'CartController@product_calculate_price'));
        if (! $route) $this->markTestSkipped('No route for the price calculation.');
        $price = fn (array $values) => $this->as(7)->json($route->methods()[0], '/'.$route->uri(), ['resturant_product_id' => 10] + $values)->assertOk()->json('data');
        $this->assertEquals(400, $price([]));
        $this->assertEquals(200, $price(['product_feature' => 1]), 'half');
        $this->assertEquals(100, $price(['product_feature' => 2]), 'quarter');
        $this->assertEquals(450, $price(['product_feature' => 3]), 'large');
        $this->assertEquals(430, $price(['product_feature' => 4]), 'combo');
        $this->assertEquals(415, $price(['product_clean' => 'extra_clean']));
        $this->assertEquals(212.5, $price(['product_clean' => 'extra_clear', 'product_feature' => 1]), 'the extra is added before halving');
    }

    public function test_adding_items_prices_them_and_adding_the_same_again_adds_its_quantity(): void
    {
        $add = fn (array $values) => $this->as(7)->postJson('/api/user/cart/store', $values);
        $add(['resturant_product_id' => 10, 'qty' => 2, 'product_feature' => 1])->assertOk()->assertJsonPath('status', 'Success')
            ->assertJsonPath('data.price', 200)->assertJsonPath('data.qty', 2)->assertJsonPath('data.total', 400)
            ->assertJsonPath('data.resturant_name', 'فرع شبرا')->assertJsonPath('data.product_feature_name', 'half')->assertJsonPath('data.min_order_price', 150);
        $add(['resturant_product_id' => 10, 'qty' => 3, 'product_feature' => 1])->assertOk();
        $add(['resturant_product_id' => 10, 'qty' => 1, 'product_feature' => 3])->assertOk();
        $add(['resturant_product_id' => 11])->assertOk();
        // As today: the same item again with no quantity keeps its quantity (qty + null ?? 1 is qty).
        $add(['resturant_product_id' => 11])->assertOk();
        $this->assertSame([[10, 5.0, 200.0, 1, null], [10, 1.0, 450.0, 3, null], [11, 1.0, 120.0, null, null]], $this->cart(7));
        $this->assertSame([], $this->cart(8), 'Nothing in anyone else\'s cart');
    }

    public function test_staff_only_and_unknown_items_are_refused(): void
    {
        $this->as(7)->postJson('/api/user/cart/store', ['resturant_product_id' => 12])->assertStatus(400)->assertJsonPath('message', 'هذا الصنف غير متاح في التطبيق.');
        // As today: the cart's validation answers with 500 and the reason (CartRequest::failedValidation).
        $this->as(7)->postJson('/api/user/cart/store', ['resturant_product_id' => 999])->assertStatus(500)->assertJsonPath('status', 'Error');
        $this->as(7)->postJson('/api/user/cart/store', ['resturant_product_id' => 10, 'product_feature' => 99])->assertStatus(500)->assertJsonPath('status', 'Error');
        $this->assertSame([], $this->cart(7));
    }

    public function test_the_cart_list_count_and_totals_belong_to_the_customer(): void
    {
        $this->as(7)->getJson('/api/user/cart/count')->assertOk()->assertJsonPath('data', null);
        $this->as(7)->getJson('/api/user/cart/index')->assertOk()->assertJsonPath('data', null);
        $this->as(7)->postJson('/api/user/cart/store', ['resturant_product_id' => 10, 'qty' => 2])->assertOk();
        $this->as(7)->postJson('/api/user/cart/store', ['resturant_product_id' => 11, 'qty' => 1])->assertOk();
        $this->as(8)->postJson('/api/user/cart/store', ['resturant_product_id' => 20, 'qty' => 4])->assertOk();
        $this->as(7)->getJson('/api/user/cart/count')->assertOk()->assertJsonPath('data', 2);
        $this->as(8)->getJson('/api/user/cart/count')->assertOk()->assertJsonPath('data', 1);
    }

    public function test_changing_a_quantity_or_setting_it_to_zero_and_emptying_the_cart(): void
    {
        $this->as(7)->postJson('/api/user/cart/store', ['resturant_product_id' => 10, 'qty' => 2])->assertOk();
        $second = $this->as(7)->postJson('/api/user/cart/store', ['resturant_product_id' => 11, 'qty' => 1])->assertOk()->json('data.id');
        $first = DB::table('carts')->where('user_id', 7)->where('resturant_product_id', 10)->value('id');
        $this->as(7)->postJson("/api/user/cart/{$first}/update", ['qty' => 4])->assertOk()->assertJsonCount(2, 'data');
        $this->as(7)->postJson("/api/user/cart/{$second}/update", ['qty' => 0])->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([[10, 4.0, 400.0, null, null]], $this->cart(7));
        $this->as(7)->postJson('/api/user/cart/999/update', ['qty' => 2])->assertStatus(500)->assertJsonPath('status', 'Error');
        $this->as(8)->postJson('/api/user/cart/store', ['resturant_product_id' => 20, 'qty' => 1])->assertOk();
        $this->as(7)->postJson('/api/user/cart/remove')->assertOk();
        $this->assertSame([], $this->cart(7));
        $this->assertCount(1, $this->cart(8), 'Emptying one cart leaves the others');
    }

    public function test_a_customer_never_reads_or_changes_another_customers_cart_item(): void
    {
        $theirs = $this->as(7)->postJson('/api/user/cart/store', ['resturant_product_id' => 10, 'qty' => 2])->assertOk()->json('data.id');
        $before = $this->cart(7);
        $this->as(8)->getJson("/api/user/cart/{$theirs}/show")->assertStatus(500)->assertJsonPath('data', null);
        $this->as(8)->postJson("/api/user/cart/{$theirs}/update/item", ['resturant_product_id' => 11, 'qty' => 9])->assertStatus(500)->assertJsonPath('data', null);
        $this->as(8)->postJson("/api/user/cart/{$theirs}/update", ['qty' => 9])->assertStatus(500)->assertJsonPath('data', null);
        $this->as(8)->postJson("/api/user/cart/{$theirs}/update", ['qty' => 0])->assertStatus(500);
        $this->assertSame($before, $this->cart(7), 'Their cart is untouched');
        $this->assertSame([], $this->cart(8));
        // The owner still reads and changes it.
        $this->as(7)->getJson("/api/user/cart/{$theirs}/show")->assertOk()->assertJsonPath('data.qty', 2);
        $this->as(7)->postJson("/api/user/cart/{$theirs}/update/item", ['resturant_product_id' => 10, 'qty' => 3, 'product_feature' => 1])->assertOk()
            ->assertJsonPath('data.qty', 3)->assertJsonPath('data.price', 200);
    }

    public function test_a_signed_out_or_other_app_session_never_reaches_the_cart(): void
    {
        $this->getJson('/api/user/cart/count')->assertStatus(401);
        DB::table('users')->where('id', 7)->update(['app_scope' => 'go']);
        $this->as(7)->getJson('/api/user/cart/count')->assertStatus(401);
    }
}
