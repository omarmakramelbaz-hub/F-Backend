<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDesktopPos extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_pos_devices', function (Blueprint $t) {
            $t->engine = 'InnoDB'; $t->uuid('id')->primary(); $t->string('branch', 40)->index();
            $t->unsignedBigInteger('actor_id'); $t->string('name', 100);
            $t->char('pair_hash', 64)->nullable()->unique(); $t->timestamp('pair_expires_at')->nullable();
            $t->char('token_hash', 64)->nullable()->unique(); $t->boolean('enabled')->default(true);
            $t->timestamp('last_seen_at')->nullable(); $t->timestamps();
        });
        Schema::create('desktop_pos_snapshots', function (Blueprint $t) {
            $t->engine = 'InnoDB'; $t->uuid('id')->primary(); $t->uuid('device_id')->index();
            $t->longText('payload'); $t->timestamps();
            $t->foreign('device_id')->references('id')->on('desktop_pos_devices')->onDelete('restrict');
        });
        Schema::create('desktop_pos_orders', function (Blueprint $t) {
            $t->engine = 'InnoDB'; $t->id(); $t->uuid('device_id'); $t->uuid('local_id'); $t->uuid('snapshot_id');
            $t->string('branch', 40)->index(); $t->string('channel', 10); $t->string('status', 20);
            $t->unsignedInteger('revision'); $t->longText('data'); $t->unsignedBigInteger('paid_order_id')->nullable();
            $t->timestamp('occurred_at'); $t->timestamps(); $t->unique(['device_id', 'local_id']);
        });
        Schema::create('desktop_pos_operations', function (Blueprint $t) {
            $t->engine = 'InnoDB'; $t->id(); $t->uuid('device_id'); $t->uuid('request_key');
            $t->char('request_hash', 64); $t->uuid('local_id'); $t->string('branch', 40)->index();
            $t->string('kind', 20); $t->unsignedInteger('revision'); $t->longText('result');
            $t->timestamp('occurred_at'); $t->timestamps(); $t->unique(['device_id', 'request_key']);
        });
    }
    public function down(): void
    {
        foreach (['desktop_pos_operations', 'desktop_pos_orders', 'desktop_pos_snapshots', 'desktop_pos_devices'] as $table) Schema::dropIfExists($table);
    }
}
