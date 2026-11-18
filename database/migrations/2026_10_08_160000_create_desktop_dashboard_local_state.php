<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDesktopDashboardLocalState extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_dashboard_local_state',function(Blueprint $t){
            $t->engine='InnoDB';$t->uuid('device_id')->primary();$t->unsignedBigInteger('actor_id');
            $t->uuid('snapshot_id');$t->char('schema_hash',64);$t->longText('branches');
            $t->longText('coverage');$t->string('state',16)->default('ready');
            $t->uuid('refresh_id')->nullable();$t->char('refresh_token_hash',64)->nullable();
            $t->unsignedBigInteger('fenced_sequence')->nullable();$t->timestamps();
        });
        Schema::create('desktop_dashboard_refreshes',function(Blueprint $t){
            $t->engine='InnoDB';$t->uuid('refresh_id')->primary();$t->uuid('device_id');
            $t->char('token_hash',64);$t->string('state',16);$t->longText('result');$t->timestamps();
        });
    }
    public function down(): void {Schema::dropIfExists('desktop_dashboard_refreshes');Schema::dropIfExists('desktop_dashboard_local_state');}
}
