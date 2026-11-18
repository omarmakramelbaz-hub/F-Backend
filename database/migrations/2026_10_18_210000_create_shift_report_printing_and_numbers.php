<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
class CreateShiftReportPrintingAndNumbers extends Migration
{
    public function up(): void
    {
        if(!Schema::hasTable('branch_shift_print_jobs'))Schema::create('branch_shift_print_jobs',function(Blueprint $t){
            $t->engine='InnoDB';$t->id();$t->unsignedBigInteger('closing_id');$t->string('branch',40)->index();$t->unsignedBigInteger('actor_id');$t->uuid('request_key');$t->string('status',20)->default('pending');$t->uuid('claim_token')->nullable();$t->unsignedBigInteger('claimed_by')->nullable();$t->timestamp('claimed_at')->nullable();$t->timestamp('invoked_at')->nullable();$t->timestamps();$t->unique(['actor_id','request_key'],'shift_print_request_unique');$t->index(['branch','status']);
        });
        if(!Schema::hasTable('branch_order_sequences'))Schema::create('branch_order_sequences',function(Blueprint $t){
            $t->engine='InnoDB';$t->id();$t->string('branch',40);$t->string('channel',12);$t->unsignedInteger('shift_sequence');$t->unsignedInteger('last_number')->default(0);$t->unique(['branch','channel','shift_sequence'],'branch_order_sequence_unique');
        });
        foreach(['orders','pos_service_tickets','takeaway_orders'] as $table){
            foreach(['shift_sequence','shift_order_number'] as $column)if(Schema::hasTable($table)&&!Schema::hasColumn($table,$column))Schema::table($table,function(Blueprint $t)use($column){$t->unsignedInteger($column)->nullable();});
        }
    }
    public function down(): void
    {
        // Keep historical report jobs and assigned order numbers on rollback.
    }
}
