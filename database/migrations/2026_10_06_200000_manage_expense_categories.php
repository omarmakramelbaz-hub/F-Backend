<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
class ManageExpenseCategories extends Migration
{
    public function up(): void
    {
        Schema::create('branch_expense_category_settings',function(Blueprint $t){
            $t->engine='InnoDB';$t->string('category_key',60)->primary();$t->string('name',80)->nullable();
            $t->boolean('active')->default(true);$t->unsignedInteger('revision')->default(1);$t->timestamps();
        });
        Schema::create('branch_expense_category_commands',function(Blueprint $t){
            $t->engine='InnoDB';$t->id();$t->uuid('request_key')->unique();$t->char('request_hash',64);
            $t->unsignedBigInteger('actor_id');$t->string('action',16);$t->text('snapshot');$t->timestamp('created_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('branch_expense_category_commands');Schema::dropIfExists('branch_expense_category_settings');
    }
}
