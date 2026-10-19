<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateCrmCustomerSyncStates extends Migration
{
    public function up(): void
    {
        Schema::create('crm_customer_sync_states', function (Blueprint $table) {
            $table->string('source', 40)->primary();
            $table->unsignedBigInteger('cursor_id')->default(0);
            $table->unsignedBigInteger('bootstrap_max_id')->default(0);
            $table->timestamp('activated_at');
            $table->timestamp('bootstrapped_at')->nullable();
            $table->timestamp('change_cursor_at')->nullable();
            $table->unsignedBigInteger('change_cursor_id')->default(0);
            $table->timestamps();
        });
        $now = \Carbon\Carbon::now('UTC');
        DB::table('crm_customer_sync_states')->insert(['source'=>'phone_requirement_cutover',
            'activated_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_customer_sync_states');
    }
}
