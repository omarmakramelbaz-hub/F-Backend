<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDesktopDashboardJournal extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_dashboard_commands', function (Blueprint $t) {
            $t->engine='InnoDB';
            $t->bigIncrements('sequence');
            $t->uuid('device_id'); $t->uuid('command_id'); $t->unsignedBigInteger('actor_id');
            $t->string('route_name', 150); $t->char('request_hash', 64);
            $t->longText('command_cipher'); $t->longText('local_result_cipher');
            $t->longText('dependencies');
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('attempts')->default(0); $t->text('last_error')->nullable();
            $t->longText('server_result_cipher')->nullable(); $t->timestamp('acknowledged_at')->nullable();
            $t->timestamps(); $t->unique(['device_id','command_id']);
            $t->index(['status','sequence']);
        });
        Schema::create('desktop_dashboard_entities', function(Blueprint $t){
            $t->engine='InnoDB';$t->bigIncrements('id');$t->uuid('device_id');$t->string('entity',40);
            $t->unsignedBigInteger('local_id');$t->uuid('command_id');$t->timestamps();
            $t->unique(['device_id','entity','local_id'],'desktop_dashboard_entity_unique');
        });
        Schema::create('desktop_dashboard_devices', function(Blueprint $t){
            $t->engine='InnoDB';$t->uuid('id')->primary();$t->unsignedBigInteger('actor_id');$t->string('name',100);
            $t->char('enrollment_hash',64);$t->char('token_hash',64)->unique();$t->boolean('enabled')->default(true);
            $t->longText('branches');$t->timestamp('enrolled_at');$t->timestamps();
        });
    }
    public function down(): void { foreach(['desktop_dashboard_entities','desktop_dashboard_commands','desktop_dashboard_devices'] as $table)Schema::dropIfExists($table); }
}
