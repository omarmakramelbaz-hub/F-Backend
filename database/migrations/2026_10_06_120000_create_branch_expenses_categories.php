<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
class CreateBranchExpensesCategories extends Migration
{
    public function up(): void
    {
        Schema::create('branch_expense_categories',function(Blueprint $t){
            $t->engine='InnoDB';$t->id();$t->string('name',80);$t->char('name_hash',64)->unique();
            $t->unsignedBigInteger('created_by');$t->uuid('request_key')->unique();$t->timestamps();
        });
    }
    public function down(): void {Schema::dropIfExists('branch_expense_categories');}
}
