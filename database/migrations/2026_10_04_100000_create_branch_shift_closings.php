<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
class CreateBranchShiftClosings extends Migration
{
    public function up(): void
    {
        Schema::create('branch_shift_closings',function(Blueprint $t){
            $t->engine='InnoDB';$t->id();$t->string('branch',40)->index();$t->unsignedInteger('sequence');$t->unsignedBigInteger('actor_id');$t->uuid('request_key');$t->char('request_hash',64);
            $t->timestamp('activated_at');$t->timestamp('started_at');$t->timestamp('closed_at');$t->bigInteger('counted_cents');$t->bigInteger('expected_cents');$t->bigInteger('variance_cents');$t->bigInteger('till_balance_cents');$t->longText('snapshot');$t->text('notes')->nullable();$t->timestamps();
            $t->unique(['branch','sequence']);$t->unique(['branch','actor_id','request_key'],'branch_shift_request_unique');
        });
        Schema::create('branch_shift_sources',function(Blueprint $t){
            $t->engine='InnoDB';$t->id();$t->unsignedBigInteger('closing_id');$t->string('branch',40);$t->string('source',20);$t->unsignedBigInteger('source_id');$t->unique(['branch','source','source_id'],'branch_shift_source_unique');$t->foreign('closing_id')->references('id')->on('branch_shift_closings')->onDelete('restrict');
        });
    }
    public function down(): void {Schema::dropIfExists('branch_shift_sources');Schema::dropIfExists('branch_shift_closings');}
}
