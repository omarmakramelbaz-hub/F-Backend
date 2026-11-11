<?php

namespace Tests\Feature\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tables the customer, courier and vendor app APIs read, in an in-memory database, with one branch (Shubra, 301)
 * and its tariff, an item, two customers (7 with 1000 in the wallet, 8), customer 7's address 70 and cart.
 */
trait CustomerAppSchema
{
    use AppRequests;

    protected function customerAppSchema(): void
    {
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
            $t->string('order_no')->nullable(); $t->string('transfer_price_by')->nullable(); $t->timestamp('shipping_cancelled_block')->nullable(); $t->unsignedInteger('shift_sequence')->nullable(); $t->unsignedInteger('shift_order_number')->nullable(); $t->string('accepted_notify')->nullable(); $t->timestamps();
        });
        Schema::create('user_address', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('mobile')->nullable(); $t->string('lat')->nullable(); $t->string('lng')->nullable(); $t->timestamps(); });
        Schema::create('wallets', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('from_user')->nullable(); $t->unsignedBigInteger('to_user')->nullable(); $t->string('status')->nullable();
            $t->string('payment')->nullable(); $t->string('type')->nullable(); $t->decimal('amount', 12, 2)->default(0); $t->unsignedBigInteger('order_id')->nullable(); $t->timestamps();
        });
        Schema::create('resturant_areas', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('area_id')->nullable(); $t->string('lat')->nullable(); $t->string('lng')->nullable(); $t->string('expected_delivery')->nullable(); $t->timestamps(); });
        Schema::create('areas', function (Blueprint $t) { $t->id(); $t->string('title_ar')->nullable(); $t->string('title_en')->nullable(); $t->timestamps(); });
        Schema::create('delegate_notifications', function (Blueprint $t) { $t->id(); $t->string('status')->nullable(); $t->unsignedBigInteger('order_id')->nullable(); $t->unsignedBigInteger('delegate_id')->nullable(); $t->timestamps(); });
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

        // Customers are «accepted» from sign-up, as the app's registration makes them.
        DB::table('users')->insert([['id' => 7, 'name' => 'عميل', 'mobile' => '1000000007', 'app_scope' => 'fasakhansta', 'status' => 'accepted', 'balance' => 1000],
            ['id' => 8, 'name' => 'عميل تاني', 'mobile' => '1000000008', 'app_scope' => 'fasakhansta', 'status' => 'accepted', 'balance' => 0]]);
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
}
