<?php

namespace Tests\Feature;

use App\Models\ResturantProduct;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApprovedMenuPricesTest extends TestCase
{
    private $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('account_type')->nullable();
        });
        Schema::create('resturant_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('resturant_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name');
            $table->decimal('product_price', 10, 2);
            $table->text('price');
            $table->string('status');
        });
        Schema::create('migrations', function (Blueprint $table) {
            $table->id(); $table->string('migration'); $table->integer('batch');
        });
        Schema::create('product_features', function (Blueprint $table) {
            $table->id(); $table->string('name');
        });
        DB::table('product_features')->insert([['id' => 1, 'name' => 'half'], ['id' => 2, 'name' => 'combo']]);
        $this->manifest = json_decode(file_get_contents(database_path('data/2026-09-23-menu-prices.json')), true);
        foreach ($this->manifest['changes'] as $change) {
            $price = ['extra_clean' => 20, 'extra_clear' => 50, 'extra_combo' => 0,
                'extra_medium' => 0, 'extra_large' => 0, 'extra_vacuim' => 30, 'other_preserved' => 99];
            foreach ($change['changes'] as $field => $values) {
                if ($field !== 'product_price') $price[$field] = $values['before'];
            }
            DB::table('resturant_products')->insert(['id' => $change['id'], 'resturant_id' => $change['branch'],
                'product_id' => $change['product_id'], 'product_name' => $change['name'],
                'product_price' => $change['base_before'], 'price' => json_encode($price), 'status' => $change['status']]);
        }
        DB::table('resturant_products')->insert(['id' => 99999, 'resturant_id' => 999, 'product_id' => 20,
            'product_name' => 'Unrelated branch', 'product_price' => 999, 'price' => '{}', 'status' => 'hide']);
        require_once database_path('migrations/2026_09_23_190000_align_approved_branch_menu_prices.php');
    }

    public function test_release_changes_only_approved_prices_and_is_idempotent(): void
    {
        $before = DB::table('resturant_products')->orderBy('id')->get()->keyBy('id');
        $migration = new \AlignApprovedBranchMenuPrices();
        $migration->up();
        $migration->up();
        $this->assertSame(91, DB::table('menu_price_release_20260923')->count());
        foreach ($this->manifest['changes'] as $change) {
            $row = DB::table('resturant_products')->where('id', $change['id'])->first();
            $prices = json_decode($row->price, true);
            foreach ($change['changes'] as $field => $values) {
                $this->assertEquals($values['after'], $field === 'product_price' ? $row->product_price : $prices[$field]);
            }
            $this->assertSame($before[$row->id]->status, $row->status);
            $this->assertSame(99, $prices['other_preserved']);
            $this->assertSame(30, $prices['extra_vacuim']);
        }
        $this->assertEquals($before[99999], DB::table('resturant_products')->where('id', 99999)->first());
        // Verify the same totals used by checkout, including half weight and combo.
        $this->assertEquals(250, ResturantProduct::find(225)->calculate_price(null, 'extra_clear'));
        $this->assertEquals(125, ResturantProduct::find(225)->calculate_price(1, 'extra_clear'));
        $this->assertEquals(260, ResturantProduct::find(781)->calculate_price(null, 'extra_clear'));
        $this->assertEquals(150, ResturantProduct::find(766)->calculate_price(2, null));
        $this->assertEquals(950, ResturantProduct::find(576)->calculate_price(null, 'extra_clear'));
    }

    public function test_conflicting_price_rolls_back_the_entire_release(): void
    {
        $last = end($this->manifest['changes']);
        DB::table('resturant_products')->where('id', $last['id'])->update(['product_price' => 999]);
        $before = DB::table('resturant_products')->orderBy('id')->get()->toJson();
        try {
            (new \AlignApprovedBranchMenuPrices())->up();
            $this->fail('A concurrent price change must stop the release.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('changed since review', $e->getMessage());
        }
        $this->assertSame($before, DB::table('resturant_products')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('menu_price_release_20260923')->count());
    }

    public function test_wrong_branch_identity_stops_all_changes(): void
    {
        DB::table('resturant_products')->where('id', 220)->update(['resturant_id' => 999]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('identity mismatch');
        (new \AlignApprovedBranchMenuPrices())->up();
    }

    public function test_rollback_restores_exact_values_and_prevents_scheduled_reapplication(): void
    {
        $before = DB::table('resturant_products')->orderBy('id')->get()->toJson();
        $migration = new \AlignApprovedBranchMenuPrices();
        $migration->up();
        $migration->down();
        $this->assertSame($before, DB::table('resturant_products')->orderBy('id')->get()->toJson());
        $this->artisan('menu:apply-approved-20260923')->assertExitCode(0);
        $this->assertSame($before, DB::table('resturant_products')->orderBy('id')->get()->toJson());
    }

    public function test_receipt_preserves_future_dashboard_edits(): void
    {
        DB::table('migrations')->insert(['migration' => '2026_09_23_190000_align_approved_branch_menu_prices', 'batch' => 1]);
        DB::table('resturant_products')->where('id', 220)->update(['product_price' => 555]);
        $this->artisan('menu:apply-approved-20260923')->assertExitCode(0);
        $this->assertEquals(555, DB::table('resturant_products')->where('id', 220)->value('product_price'));
    }

    public function test_rollback_refuses_to_overwrite_new_dashboard_prices(): void
    {
        $migration = new \AlignApprovedBranchMenuPrices();
        $migration->up();
        DB::table('resturant_products')->where('id', 2578)->update(['product_price' => 555]);
        $before = DB::table('resturant_products')->orderBy('id')->get()->toJson();
        try {
            $migration->down();
            $this->fail('A later dashboard edit must stop rollback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rollback stopped', $e->getMessage());
        }
        $this->assertSame($before, DB::table('resturant_products')->orderBy('id')->get()->toJson());
    }
}
