<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
class CreatePosBranchPrintJobs extends Migration
{
    public function up()
    {
        if (Schema::hasTable('pos_branch_print_jobs')) return;
        Schema::create('pos_branch_print_jobs', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('branch',32); $t->unsignedBigInteger('ticket_id');
            $t->unsignedBigInteger('kitchen_id')->unique(); $t->string('status',20)->default('pending');
            $t->uuid('claim_token')->nullable(); $t->unsignedBigInteger('claimed_by')->nullable();
            $t->timestamp('claimed_at')->nullable(); $t->timestamp('invoked_at')->nullable(); $t->timestamps();
            $t->index(['branch','status','id']);
        });
    }
    public function down() { Schema::dropIfExists('pos_branch_print_jobs'); }
}
