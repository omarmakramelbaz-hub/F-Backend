<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDesktopDashboardRemoteAttempts extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_dashboard_remote_attempts',function(Blueprint $t){
            $t->engine='InnoDB';$t->uuid('id')->primary();$t->uuid('device_id');$t->unsignedBigInteger('actor_id');
            $t->string('method',10);$t->string('path',200);$t->string('status',20);
            $t->char('request_hash',64)->nullable();$t->longText('response_cipher')->nullable();$t->timestamps();
            $t->uuid('operation_id')->nullable();$t->unique(['device_id','operation_id']);
            $t->index(['device_id','status']);
        });
    }
    public function down(): void {Schema::dropIfExists('desktop_dashboard_remote_attempts');}
}
