<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBranchStock extends Migration
{
    public function up(): void
    {
        Schema::create('branch_stock', function (Blueprint $t) {
            $t->id(); $t->string('branch',30); $t->unsignedBigInteger('product_id'); $t->string('unit',10);
            $t->bigInteger('quantity_units')->default(0); $t->unsignedInteger('revision')->default(1); $t->timestamps();
            $t->unique(['branch','product_id']);
        });
        Schema::create('branch_stock_movements', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('stock_id'); $t->string('branch',30); $t->unsignedBigInteger('product_id');
            $t->string('product_name',255); $t->string('unit',10); $t->bigInteger('quantity_units'); $t->bigInteger('balance_units');
            $t->string('source_type',16); $t->string('source_id',60); $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('supplier',150)->nullable(); $t->string('notes',500)->nullable(); $t->timestamps();
            $t->unique(['stock_id','source_type','source_id'],'stock_source_unique'); $t->index(['branch','id']);
        });
    }
    public function down(): void {Schema::dropIfExists('branch_stock_movements');Schema::dropIfExists('branch_stock');}
}
