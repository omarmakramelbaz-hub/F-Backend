<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDesktopDashboardArchivedCommands extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_dashboard_archived_commands',function(Blueprint $t){
            $t->engine='InnoDB';$t->uuid('device_id');$t->uuid('command_id');$t->unsignedBigInteger('actor_id');
            $t->string('route_name',150);$t->char('request_hash',64);$t->string('source_database',64);
            $t->primary(['device_id','command_id'],'desktop_dashboard_archived_primary');
        });
    }
    public function down(): void {Schema::dropIfExists('desktop_dashboard_archived_commands');}
}
