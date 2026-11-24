<?php

namespace Tests\Feature;

use App\Http\Resources\Api\Vendor\ResturantProductResource;
use App\Models\ResturantProduct;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A branch's menu (/resturants/{id}/products) asks the database a fixed number of times however many items it has,
 * and shows each item exactly as before: its relations, image and the customer's latest cart line.
 */
class MenuQueriesApiTest extends TestCase
{
    use Concerns\CustomerAppSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerAppSchema();
        DB::table('categories')->insert([['id' => 1, 'name_ar' => 'أسماك', 'name_en' => 'Fish'], ['id' => 2, 'name_ar' => 'ملوحة', 'name_en' => 'Salted']]);
        DB::table('products')->where('id', 5)->update(['category_id' => 1, 'subcategory_id' => 2, 'has_clean' => 'yes']);
        DB::table('products')->insert(['id' => 6, 'name_ar' => 'رنجة', 'name_en' => 'Herring', 'category_id' => 2]);
        DB::table('product_features')->insert([['product_id' => 5, 'name' => 'half'], ['product_id' => 5, 'name' => 'quarter']]);
        DB::table('resturant_products')->where('id', 10)->update(['category_id' => 1, 'added_by' => 8]);
        $price = json_encode(['extra_combo' => 1, 'extra_large' => 2, 'extra_medium' => 3, 'extra_clean' => 4, 'extra_clear' => 5, 'extra_vacuim' => 6]);
        foreach (range(11, 18) as $id) {
            DB::table('resturant_products')->insert(['id' => $id, 'resturant_id' => 301, 'product_id' => $id % 2 ? 6 : 5,
                'category_id' => $id % 2 ? 2 : 1, 'product_name' => 'صنف '.$id, 'product_price' => (string) ($id * 10), 'price' => $price]);
        }
        // Two lines for item 12: the latest one shows; another customer's line never does.
        DB::table('carts')->insert([
            ['user_id' => 7, 'resturant_id' => 301, 'resturant_product_id' => 12, 'qty' => 1, 'price' => 1, 'order_id' => 40, 'created_at' => '2026-01-01 10:00:00'],
            ['user_id' => 7, 'resturant_id' => 301, 'resturant_product_id' => 12, 'qty' => 1.5, 'price' => 1, 'order_id' => 41, 'created_at' => '2026-02-01 10:00:00'],
            ['user_id' => 8, 'resturant_id' => 301, 'resturant_product_id' => 13, 'qty' => 3, 'price' => 1, 'order_id' => 42, 'created_at' => '2026-03-01 10:00:00'],
        ]);
    }

    /** Each item as the resource shows it when asked one by one (the old way), keyed by id. */
    private function oneByOne(): array
    {
        return ResturantProduct::query()->where('resturant_id', 301)->get()
            ->mapWithKeys(fn ($item) => [$item->id => json_decode(json_encode((new ResturantProductResource($item))->resolve(request())), true)])->all();
    }

    private function menuItems($response): array
    {
        return collect($response->json('data'))->flatMap(fn ($section) => $section['resturant_items'])->keyBy('id')->all();
    }

    public function test_the_menu_shows_every_item_as_before_for_a_signed_in_customer(): void
    {
        $headers = $this->appHeaders(7);
        DB::enableQueryLog();
        $response = $this->withHeaders($headers)->getJson('/api/resturants/301/products')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $items = $this->menuItems($response);
        $this->assertCount(9, $items);
        $this->assertSame(41, $items[12]['latest_order_id']);
        $this->assertSame(1.5, $items[12]['latest_order_quantity']);
        $this->assertNull($items[13]['latest_order_id']);
        $this->assertSame(2, $items[10]['latest_order_qty']);
        $this->assertSame(['half', 'quarter'], array_column($items[10]['features'], 'name'));
        $this->assertSame('عميل تاني', $items[10]['vendor_name']);
        $this->assertSame('فرع شبرا', $items[10]['resturant_name']);

        // The same items asked one by one, as the signed-in customer, give the same values.
        auth('api')->setUser(\App\Models\User::query()->find(7));
        $this->assertEquals($this->oneByOne(), $items);
        $this->assertLessThan(25, $queries, 'the menu asks the database a fixed number of times, not several per item');
    }

    public function test_a_guest_sees_the_menu_without_cart_lines(): void
    {
        $response = $this->getJson('/api/resturants/301/products')->assertOk();
        $items = $this->menuItems($response);
        $this->assertCount(9, $items);
        $this->assertNull($items[10]['latest_order_id']);
        $this->assertNull($items[12]['latest_order_quantity']);
    }

    public function test_the_query_count_does_not_grow_with_the_menu(): void
    {
        $count = function () {
            $headers = $this->appHeaders(7);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->withHeaders($headers)->getJson('/api/resturants/301/products')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();
            return $n;
        };
        $before = $count();
        foreach (range(19, 40) as $id) {
            DB::table('resturant_products')->insert(['id' => $id, 'resturant_id' => 301, 'product_id' => 5, 'category_id' => 1,
                'product_name' => 'صنف '.$id, 'product_price' => '10', 'price' => json_encode(['extra_combo' => 0, 'extra_large' => 0, 'extra_medium' => 0, 'extra_clean' => 0, 'extra_clear' => 0, 'extra_vacuim' => 0])]);
        }
        $this->assertSame($before, $count());
    }
}
