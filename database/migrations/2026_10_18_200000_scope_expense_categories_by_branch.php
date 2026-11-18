<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ScopeExpenseCategoriesByBranch extends Migration
{
    public function up(): void
    {
        if(!Schema::hasColumn('branch_expense_categories','origin_branch'))Schema::table('branch_expense_categories',function(Blueprint $table){
            $table->string('origin_branch',30)->nullable();
        });
        if(!Schema::hasTable('branch_expense_category_overrides'))Schema::create('branch_expense_category_overrides',function(Blueprint $table){
            $table->engine='InnoDB';$table->string('branch',30);$table->string('category_key',60);$table->string('name',80)->nullable();
            $table->boolean('active')->default(true);$table->unsignedInteger('revision')->default(1);$table->timestamps();
            $table->unique(['branch','category_key'],'expense_category_branch_key_unique');
        });
    }
    public function down(): void
    {
        // Branch names and visibility are user data. Retain them when code is rolled back.
    }
}
