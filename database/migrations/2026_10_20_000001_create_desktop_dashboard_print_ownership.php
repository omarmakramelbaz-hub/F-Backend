<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
class CreateDesktopDashboardPrintOwnership extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_dashboard_print_ownership',function(Blueprint $t){
            $t->engine='InnoDB';$t->char('effect_id',64)->primary();$t->uuid('device_id');$t->uuid('command_id');$t->char('enrollment_id',64);
            $t->unsignedBigInteger('actor_id');$t->string('branch',40);$t->string('kind',24);$t->unsignedInteger('revision');$t->char('descriptor_hash',64);
            $t->longText('local_references');$t->longText('server_references');$t->longText('outcome')->nullable();$t->char('outcome_hash',64)->nullable();$t->timestamps();
            $t->unique(['device_id','command_id','kind'],'desktop_print_owner_unique');
        });
    }
    public function down(): void {Schema::dropIfExists('desktop_dashboard_print_ownership');}
}
