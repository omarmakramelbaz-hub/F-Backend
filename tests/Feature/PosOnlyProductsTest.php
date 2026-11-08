<?php

namespace Tests\Feature;

use App\Support\PosOnlyProducts;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Artisan, DB, Schema};
use Tests\TestCase;

/** The «زيادة وردية» items are sold by the staff at the branch only: never offered in the customer app or the website. */
class PosOnlyProductsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:']);
        DB::purge('sqlite'); Schema::clearResolvedInstance('db.schema');
        \App\Database\SchemaMemory::forgetEverything();
        Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->string('name'); });
        Schema::create('categories', function (Blueprint $t) { $t->id(); $t->string('name_ar'); });
        Schema::create('resturant_products', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('category_id')->nullable();
            $t->string('product_name'); $t->string('product_price')->default('1'); $t->string('status')->default('show');
        });
        DB::table('resturants')->insert(['id'=>301, 'name'=>'شبرا']);
        DB::table('categories')->insert([['id'=>1, 'name_ar'=>'فسيخ'], ['id'=>2, 'name_ar'=>'زيادة وردية']]);
        DB::table('resturant_products')->insert([
            ['id'=>10, 'resturant_id'=>301, 'category_id'=>1, 'product_name'=>'فسيخ كيلو', 'product_price'=>'500'],
            ['id'=>11, 'resturant_id'=>301, 'category_id'=>1, 'product_name'=>'زيادة وردية 1', 'product_price'=>'1'],
            ['id'=>12, 'resturant_id'=>301, 'category_id'=>1, 'product_name'=>'زياده ورديه 5', 'product_price'=>'5'],
            ['id'=>13, 'resturant_id'=>301, 'category_id'=>1, 'product_name'=>'زيادة الوردية 10', 'product_price'=>'10'],
            ['id'=>14, 'resturant_id'=>301, 'category_id'=>2, 'product_name'=>'100', 'product_price'=>'100'],
            ['id'=>15, 'resturant_id'=>301, 'category_id'=>1, 'product_name'=>'رنجة مدخنة', 'product_price'=>'80'],
        ]);
    }

    public function test_the_shift_top_up_items_are_found_by_their_name_or_their_category(): void
    {
        $this->assertSame([11, 12, 13, 14], PosOnlyProducts::ids());
        $this->assertTrue(PosOnlyProducts::is(12));
        $this->assertTrue(PosOnlyProducts::is((object) ['id'=>14]));
        $this->assertFalse(PosOnlyProducts::is(10));
        $this->assertFalse(PosOnlyProducts::is(null));
        $offered = PosOnlyProducts::without(collect([10, 11, 14, 15])->map(fn ($id) => (object) ['id'=>$id]));
        $this->assertSame([10, 15], $offered->pluck('id')->all());
    }

    public function test_the_list_of_staff_only_items_can_be_reviewed(): void
    {
        Artisan::call('products:pos-only');
        $out = Artisan::output();
        $this->assertStringContainsString('GO_ERP_POS_ONLY_PRODUCTS 4', $out);
        $this->assertStringContainsString('#14 100 | price 100 | show | category زيادة وردية | branch #301 شبرا', $out);
        $this->assertStringNotContainsString('فسيخ كيلو', $out);
    }

    public function test_the_app_and_the_website_leave_them_out_and_refuse_them(): void
    {
        $sources = ['app/Http/Resources/Api/Vendor/ResturantSingleResource.php'=>2, 'app/Http/Controllers/Api/V1/Home/CategoryController.php'=>1,
            'app/Http/Controllers/Api/V1/User/CartController.php'=>7, 'app/Services/Site/WebStorefront.php'=>2];
        foreach ($sources as $file => $uses) $this->assertSame($uses, substr_count(file_get_contents(base_path($file)), 'PosOnlyProducts::'), $file);
    }
}
