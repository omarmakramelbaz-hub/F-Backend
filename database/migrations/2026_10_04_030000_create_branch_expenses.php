<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBranchExpenses extends Migration
{
    public function up(): void
    {
        Schema::create('branch_expenses',function(Blueprint $t){
            $t->engine='InnoDB';$t->id();$t->string('branch',40);$t->unsignedBigInteger('actor_id');
            $t->date('occurred_on');$t->string('category',40);$t->string('description',500);$t->unsignedBigInteger('amount_cents');
            $t->string('payment_method',20);$t->string('payment_reference',150)->nullable();$t->string('supplier',150)->nullable();$t->string('cost_center',150)->nullable();$t->text('notes')->nullable();
            $t->string('status',20)->default('pending');$t->unsignedInteger('revision')->default(1);$t->unsignedBigInteger('reviewer_id')->nullable();$t->timestamp('reviewed_at')->nullable();$t->string('review_reason',500)->nullable();
            $t->string('attachment_path')->nullable();$t->string('attachment_name')->nullable();$t->string('attachment_mime',100)->nullable();$t->char('attachment_hash',64)->nullable();
            $t->timestamps();$t->index(['branch','occurred_on']);$t->index(['branch','status','category']);
        });
        Schema::create('branch_expense_commands',function(Blueprint $t){
            $t->engine='InnoDB';$t->id();$t->string('branch',40);$t->unsignedBigInteger('actor_id');$t->uuid('request_key');$t->char('request_hash',64);$t->unsignedBigInteger('expense_id');$t->string('kind',20);$t->unsignedInteger('revision');$t->text('snapshot');$t->timestamps();
            $t->unique(['branch','actor_id','request_key'],'branch_expense_request_unique');$t->foreign('expense_id')->references('id')->on('branch_expenses')->onDelete('restrict');
        });
    }
    public function down(): void {Schema::dropIfExists('branch_expense_commands');Schema::dropIfExists('branch_expenses');}
}
