<?php
namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StoreDeliveryRatesTest extends TestCase
{
    private $manifest;
    private const RECEIPT = 'store_delivery_release_20260923';
    private const MIGRATION = '2026_09_23_200000_align_store_delivery_rates';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Schema::create('resturants', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->string('name');
            foreach (['default_0_1', 'default_1_2', 'default_2_3', 'km_price', 'service_fees'] as $field) $table->decimal($field, 10, 2)->default(7);
            $table->string('status')->default('hide');
        });
        Schema::create('migrations', function (Blueprint $table) {
            $table->id(); $table->string('migration'); $table->integer('batch');
        });
        $this->manifest = json_decode(file_get_contents(database_path('data/2026-09-23-store-delivery.json')), true);
        foreach ($this->manifest['stores'] as $store) {
            DB::table('resturants')->insert(array_merge(['id'=>$store['id'], 'user_id'=>$store['user_id'], 'name'=>$store['name']], $store['before']));
        }
        foreach ([82,94,301,307,361,362,363,99999] as $id) {
            DB::table('resturants')->insert(['id'=>$id, 'user_id'=>1, 'name'=>'فرع شبرا']);
        }
        require_once database_path('migrations/'.self::MIGRATION.'.php');
    }

    public function test_only_three_fields_on_28_stores_change_with_idempotence_and_exact_rollback(): void
    {
        $before = DB::table('resturants')->orderBy('id')->get();
        $migration = new \AlignStoreDeliveryRates();
        $migration->up(); $migration->up();
        $this->assertSame(28, DB::table(self::RECEIPT)->count());
        $ids = array_column($this->manifest['stores'], 'id');
        foreach ($before as $row) {
            $expected = (array) $row;
            if (in_array((int) $row->id, $ids, true)) {
                foreach ($this->manifest['fields'] as $field) $expected[$field] = 50;
            }
            $this->assertEquals($expected, (array) DB::table('resturants')->find($row->id));
        }
        $migration->down();
        $this->assertEquals($before, DB::table('resturants')->orderBy('id')->get());
        $this->artisan('delivery:apply-store-rates-20260923')->assertExitCode(0);
        $this->assertEquals($before, DB::table('resturants')->orderBy('id')->get());
    }

    public function test_concurrent_price_change_rolls_back_every_store(): void
    {
        DB::table('resturants')->where('id',360)->update(['default_2_3'=>99]);
        $before = DB::table('resturants')->orderBy('id')->get();
        try { (new \AlignStoreDeliveryRates())->up(); $this->fail('Expected conflict'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('changed since review: 360', $e->getMessage()); }
        $this->assertEquals($before, DB::table('resturants')->orderBy('id')->get());
        $this->assertSame(0, DB::table(self::RECEIPT)->count());
    }

    public function test_changed_identity_blocks_release(): void
    {
        DB::table('resturants')->where('id',360)->update(['name'=>'فرع جديد']);
        $before = DB::table('resturants')->orderBy('id')->get();
        try { (new \AlignStoreDeliveryRates())->up(); $this->fail('Expected conflict'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('identity changed: 360', $e->getMessage()); }
        $this->assertEquals($before, DB::table('resturants')->orderBy('id')->get());
    }

    public function test_rollback_preserves_subsequent_dashboard_edits_atomically(): void
    {
        $migration = new \AlignStoreDeliveryRates(); $migration->up();
        DB::table('resturants')->where('id',360)->update(['default_2_3'=>99]);
        $before = DB::table('resturants')->orderBy('id')->get();
        try { $migration->down(); $this->fail('Expected conflict'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('rollback stopped: 360', $e->getMessage()); }
        $this->assertEquals($before, DB::table('resturants')->orderBy('id')->get());
    }

    public function test_command_applies_once_and_does_not_overwrite_future_edits(): void
    {
        $this->artisan('delivery:apply-store-rates-20260923')->assertExitCode(0);
        $this->assertSame(28, DB::table(self::RECEIPT)->count());
        DB::table('resturants')->where('id',360)->update(['default_2_3'=>99]);
        $this->artisan('delivery:apply-store-rates-20260923')->assertExitCode(0);
        $this->assertEquals(99, DB::table('resturants')->find(360)->default_2_3);
    }
}
