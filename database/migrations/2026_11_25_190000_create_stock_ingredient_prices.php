<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The owner's cost and selling price for each goods item, per kilo or per piece, shared by all branches. */
class CreateStockIngredientPrices extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stock_ingredient_prices')) Schema::create('stock_ingredient_prices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('ingredient_id')->unique();
            $t->unsignedBigInteger('cost_cents')->nullable();
            $t->unsignedBigInteger('price_cents')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ingredient_prices');
    }
}
