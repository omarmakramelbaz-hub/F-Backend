<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

class LinkExpenseInventoryPurchases extends Migration
{
    public function up(): void
    {
        foreach (['stock_ingredients', 'branch_inventory', 'branch_inventory_movements', 'branch_expenses'] as $table) {
            if (!Schema::hasTable($table)) throw new RuntimeException('Expense inventory upgrade requires '.$table.'.');
        }
        // Check every existing identity before changing the schema or adding any material.
        foreach (['فلفل', 'طماطم', 'ليمون', 'بصل', 'زيت', 'خل'] as $name) {
            $rows = DB::table('stock_ingredients')->where('name', $name)->get();
            if ($rows->count() > 1 || ($rows->count() === 1 && $rows->first()->unit !== 'kg')) {
                throw new RuntimeException('Ambiguous or non-kilogram purchase ingredient: '.$name);
            }
        }
        if (!Schema::hasTable('expense_inventory_receipts')) {
            Schema::create('expense_inventory_receipts', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->unsignedBigInteger('expense_id')->unique();
                $table->string('branch', 30);
                $table->string('category_key', 40);
                $table->unsignedBigInteger('ingredient_id');
                $table->string('item_name');
                $table->bigInteger('quantity_units');
                $table->unsignedBigInteger('receipt_movement_id');
                $table->unsignedBigInteger('reversal_movement_id')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->timestamps();
                $table->index(['branch', 'ingredient_id']);
            });
        }
        DB::transaction(function () {
            foreach (['فلفل', 'طماطم', 'ليمون', 'بصل', 'زيت', 'خل'] as $name) {
                if (DB::table('stock_ingredients')->where('name', $name)->exists()) continue;
                DB::table('stock_ingredients')->insert([
                    'name'=>$name, 'unit'=>'kg', 'position'=>(int)DB::table('stock_ingredients')->max('position') + 1,
                    'created_at'=>now('UTC'), 'updated_at'=>now('UTC'),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Receipts and ingredients may be referenced by posted expenses or recipe snapshots.
        // Keep their audit and stock identities when an application release is rolled back.
    }
}
