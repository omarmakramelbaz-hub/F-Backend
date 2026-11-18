<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDesktopDashboardMediaManifest extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_dashboard_media_manifest',function(Blueprint $t){
            $t->engine='InnoDB';$t->uuid('device_id')->primary();$t->uuid('snapshot_id');
            $t->char('sha256',64);$t->longText('files');
        });
    }
    public function down(): void {Schema::dropIfExists('desktop_dashboard_media_manifest');}
}
