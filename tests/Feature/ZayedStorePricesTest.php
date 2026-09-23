<?php
namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ZayedStorePricesTest extends TestCase
{
    private $manifest;
    private const BACKUP = 'zayed_price_release_20260923';
    private const COMMAND = 'menu:align-stores-to-zayed-20260923';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:', 'cache.default'=>'array']);
        DB::purge('sqlite');
        Schema::create('resturants', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('name');
            $t->decimal('default_0_1')->default(50); $t->decimal('default_1_2')->default(50);
            $t->decimal('default_2_3')->default(50); $t->decimal('km_price')->default(5);
        });
        Schema::create('resturant_products', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('product_id');
            $t->string('product_name'); $t->decimal('product_price',10,2); $t->text('price');
            $t->string('status')->default('hide'); $t->text('product_description')->default('Preserve description');
        });
        Schema::create('migrations', function (Blueprint $t) { $t->id(); $t->string('migration'); $t->integer('batch'); });
        $this->manifest = json_decode(file_get_contents(database_path('data/2026-09-23-zayed-store-prices.json')), true);
        DB::table('resturants')->insert($this->manifest['stores']);
        foreach (array_merge($this->manifest['source'], $this->manifest['targets']) as $item) {
            $prices = array_combine($this->manifest['fields'], $item['values']); unset($prices['product_price']);
            // Exercise API casts of missing, null and blank zero options in production.
            foreach ($prices as $k=>$v) if ($v == 0) {
                if ($item['id'] % 3 === 0) unset($prices[$k]);
                elseif ($item['id'] % 3 === 1) $prices[$k] = null;
                else $prices[$k] = '';
            }
            $prices['unrelated_option'] = 'keep';
            DB::table('resturant_products')->insert(['id'=>$item['id'], 'resturant_id'=>$item['store'],
                'product_id'=>$item['product_id'], 'product_name'=>$item['name'], 'product_price'=>$item['values'][0], 'price'=>json_encode($prices)]);
        }
        foreach ([82,94,301,307,361,362,363,308,99999] as $i=>$store) {
            DB::table('resturants')->insert(['id'=>$store,'user_id'=>1,'name'=>'Protected location']);
            DB::table('resturant_products')->insert(['id'=>90000+$i,'resturant_id'=>$store,'product_id'=>54,
                'product_name'=>'فسيخ نبروه 4 سمكات','product_price'=>17500,'price'=>'{}']);
        }
        require_once database_path('migrations/2026_09_23_210000_align_store_prices_to_zayed.php');
    }

    public function test_all_1482_items_match_zayed_and_only_prices_change_with_exact_rollback(): void
    {
        $before=DB::table('resturant_products')->orderBy('id')->get()->keyBy('id');
        $stores=DB::table('resturants')->orderBy('id')->get();
        $m=new \AlignStorePricesToZayed(); $m->up(); $m->up();
        $this->assertSame(545, DB::table(self::BACKUP)->count());
        $sources=array_column($this->manifest['source'],null,'id');
        foreach ($this->manifest['targets'] as $item) {
            $row=DB::table('resturant_products')->find($item['id']); $price=json_decode($row->price,true);
            $want=$sources[$item['source_id']]['values'];
            foreach ($this->manifest['fields'] as $i=>$field) {
                $actual=$field==='product_price'?$row->product_price:($price[$field]??0);
                $this->assertEquals((float)$want[$i],(float)$actual);
            }
            $this->assertSame('keep',$price['unrelated_option']);
            $expected=(array)$before[$row->id];$actual=(array)$row;
            unset($expected['product_price'],$expected['price'],$actual['product_price'],$actual['price']);
            $this->assertSame($expected,$actual);
        }
        $this->assertEquals($stores,DB::table('resturants')->orderBy('id')->get());
        foreach ($before as $row) {
            if ((int)$row->resturant_id===310 || (int)$row->id>=90000) $this->assertEquals($row,DB::table('resturant_products')->find($row->id));
        }
        $m->down();
        $this->assertEquals($before,DB::table('resturant_products')->orderBy('id')->get()->keyBy('id'));
        $this->artisan(self::COMMAND)->assertExitCode(0);
        $this->assertEquals($before,DB::table('resturant_products')->orderBy('id')->get()->keyBy('id'));
    }

    public function test_source_change_stops_release(): void
    {
        DB::table('resturant_products')->where('id',988)->update(['product_price'=>999]);
        $this->assertAtomicFailure('Zayed source price changed');
    }

    public function test_target_change_stops_entire_release(): void
    {
        $item=end($this->manifest['targets']);
        DB::table('resturant_products')->where('id',$item['id'])->update(['product_price'=>999]);
        $this->assertAtomicFailure('Store menu price changed');
    }

    public function test_store_changed_to_branch_is_excluded(): void
    {
        DB::table('resturants')->where('id',309)->update(['name'=>'فرع جديد']);
        $this->assertAtomicFailure('Store identity changed');
    }

    public function test_matching_product_id_is_not_enough_when_pack_or_name_changes(): void
    {
        $item=end($this->manifest['targets']);
        DB::table('resturant_products')->where('id',$item['id'])->update(['product_name'=>'برميل فسيخ']);
        $this->assertAtomicFailure('Menu identity changed');
    }

    public function test_malformed_surcharge_is_not_treated_as_zero(): void
    {
        DB::table('resturant_products')->where('id',988)->update(['price'=>'{"extra_combo":"invalid"}']);
        $this->assertAtomicFailure('Invalid numeric price');
    }

    public function test_completed_command_preserves_later_dashboard_edits(): void
    {
        $this->artisan(self::COMMAND)->assertExitCode(0);
        $b=DB::table(self::BACKUP)->first();
        DB::table('resturant_products')->where('id',$b->menu_id)->update(['product_price'=>999]);
        $this->artisan(self::COMMAND)->assertExitCode(0);
        $this->assertEquals(999, DB::table('resturant_products')->find($b->menu_id)->product_price);
    }

    public function test_rollback_stops_atomically_for_later_price_edits(): void
    {
        $m=new \AlignStorePricesToZayed();$m->up();
        $b=DB::table(self::BACKUP)->orderByDesc('menu_id')->first();
        DB::table('resturant_products')->where('id',$b->menu_id)->update(['product_price'=>999]);
        $before=DB::table('resturant_products')->orderBy('id')->get();
        try {$m->down();$this->fail('Expected conflict');}
        catch (\RuntimeException $e) {$this->assertStringContainsString('rollback stopped',$e->getMessage());}
        $this->assertEquals($before,DB::table('resturant_products')->orderBy('id')->get());
        $this->assertSame(0,DB::table(self::BACKUP)->whereNotNull('rolled_back_at')->count());
    }

    private function assertAtomicFailure(string $message): void
    {
        $before=DB::table('resturant_products')->orderBy('id')->get();
        try {(new \AlignStorePricesToZayed())->up();$this->fail('Expected conflict');}
        catch (\RuntimeException $e) {$this->assertStringContainsString($message,$e->getMessage());}
        $this->assertEquals($before,DB::table('resturant_products')->orderBy('id')->get());
        $this->assertSame(0,DB::table(self::BACKUP)->count());
    }
}
