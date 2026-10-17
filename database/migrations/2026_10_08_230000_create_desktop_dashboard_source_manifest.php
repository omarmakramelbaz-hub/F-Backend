<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDesktopDashboardSourceManifest extends Migration
{
    public function up()
    {
        if(Schema::hasTable('desktop_dashboard_source_manifest'))return;
        Schema::create('desktop_dashboard_source_manifest',function(Blueprint $table){
            $table->engine='InnoDB';$table->uuid('device_id')->primary();$table->uuid('snapshot_id');
            $table->char('sha256',64);$table->text('source');
        });
    }
    public function down(){Schema::dropIfExists('desktop_dashboard_source_manifest');}
}
