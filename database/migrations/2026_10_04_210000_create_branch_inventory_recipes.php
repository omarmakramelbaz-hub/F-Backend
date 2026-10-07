<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};

class CreateBranchInventoryRecipes extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ingredients',function(Blueprint $t){$t->id();$t->string('name');$t->string('unit',10);$t->unsignedSmallInteger('position');$t->timestamps();});
        $names=[
            ['فسيخ كيلو 4 سمكات','kg'],['فسيخ كيلو 3 سمكات','kg'],['فسيخ كيلو سمكتين','kg'],['فسيخ كيلو سمكة','kg'],
            ['رنجة بطارخ','kg'],['رنجة سمينة','kg'],['رنجة تدخين أشجار الليمون','kg'],['سردين بلدي','kg'],
            ['علبة فسيخ صغيرة','piece'],['علبة فسيخ كبيرة','piece'],['علبة سردين صغيرة','piece'],['علبة سردين كبيرة','piece'],
            ['علبة بطارخ','piece'],['علبة ملوحة','piece'],['علبة أنشوجة','piece'],
            ['بصل','kg'],['فلفل','kg'],['طماطم','kg'],['ليمون','kg'],['خبز بلدي','piece'],['شيبسي','piece'],['بيبسي','piece'],['مياه','piece'],
        ];
        foreach($names as $i=>[$name,$unit])DB::table('stock_ingredients')->insert(['name'=>$name,'unit'=>$unit,'position'=>$i+1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        Schema::create('branch_inventory',function(Blueprint $t){
            $t->id();$t->string('branch',30);$t->unsignedBigInteger('ingredient_id');$t->bigInteger('quantity_units')->default(0);$t->unsignedInteger('revision')->default(1);$t->timestamps();$t->unique(['branch','ingredient_id']);
        });
        Schema::create('branch_inventory_movements',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('stock_id');$t->string('branch',30);$t->unsignedBigInteger('ingredient_id');$t->string('name');$t->string('unit',10);$t->bigInteger('quantity_units');$t->bigInteger('balance_units');$t->string('source_type',16);$t->string('source_id',60);$t->unsignedBigInteger('actor_id')->nullable();$t->string('supplier',150)->nullable();$t->string('notes',500)->nullable();$t->timestamps();
            $t->unique(['stock_id','source_type','source_id'],'ingredient_movement_source_unique');$t->index(['branch','id']);
        });
        Schema::create('branch_stock_recipes',function(Blueprint $t){
            $t->id();$t->string('branch',30);$t->unsignedBigInteger('product_id');$t->string('unit',10);$t->unsignedInteger('revision')->default(1);$t->longText('variants');$t->unsignedBigInteger('updated_by');$t->timestamps();$t->unique(['branch','product_id']);
        });
        Schema::create('branch_recipe_sales',function(Blueprint $t){
            $t->id();$t->string('branch',30);$t->string('source_type',16);$t->string('source_id',60);$t->longText('snapshot');$t->timestamps();$t->unique(['branch','source_type','source_id'],'recipe_sale_source_unique');
        });
        // Legacy SKU receipts/balances stay intact and visible for reconciliation.
        // Menu names cannot reliably identify which ingredient or recipe they represent.
    }
    public function down(): void
    {
        foreach(['branch_recipe_sales','branch_stock_recipes','branch_inventory_movements','branch_inventory','stock_ingredients'] as $table)Schema::dropIfExists($table);
    }
}
