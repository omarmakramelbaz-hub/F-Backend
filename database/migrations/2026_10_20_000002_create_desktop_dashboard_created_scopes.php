<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
class CreateDesktopDashboardCreatedScopes extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_dashboard_created_scopes',function(Blueprint $t){
            $t->engine='InnoDB';$t->char('grant_id',64)->primary();$t->uuid('device_id');$t->char('enrollment_id',64);$t->uuid('command_id');
            $t->unsignedBigInteger('actor_id');$t->string('route_name',150);$t->string('capability',24);$t->string('entity',40);
            $t->unsignedBigInteger('entity_id');$t->unsignedBigInteger('origin_entity_id');$t->string('branch',40);
            $t->char('descriptor_hash',64);$t->longText('evidence_cipher');$t->dateTime('created_at');
            $t->unique(['device_id','enrollment_id','command_id','entity'],'desktop_created_scope_origin_unique');
        });
    }
    public function down(): void {Schema::dropIfExists('desktop_dashboard_created_scopes');}
}
