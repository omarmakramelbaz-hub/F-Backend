<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Devices of the GO ERP desktop app, their menu versions and every operation they sent. */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('desktop_sync_devices')) {
            Schema::create('desktop_sync_devices', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name', 100);
                $table->char('token_hash', 64)->unique();
                $table->unsignedBigInteger('enrolled_by')->index();
                $table->text('branches');
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('desktop_sync_snapshots')) {
            Schema::create('desktop_sync_snapshots', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('device_id');
                $table->string('branch', 40);
                $table->char('payload_hash', 64);
                $table->longText('payload');
                $table->timestamp('created_at')->nullable();
                $table->index(['device_id', 'branch', 'payload_hash'], 'desktop_sync_snapshot_lookup');
            });
        }

        if (! Schema::hasTable('desktop_sync_operations')) {
            Schema::create('desktop_sync_operations', function (Blueprint $table) {
                $table->id();
                $table->uuid('device_id');
                $table->uuid('op_id');
                $table->string('kind', 40);
                $table->unsignedBigInteger('actor_id');
                $table->string('branch', 40);
                $table->char('request_hash', 64);
                $table->string('status', 20);
                $table->text('result');
                $table->timestamp('occurred_at');
                $table->timestamps();
                $table->unique(['device_id', 'op_id'], 'desktop_sync_operation_unique');
                $table->index(['branch', 'created_at'], 'desktop_sync_operation_branch');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('desktop_sync_operations');
        Schema::dropIfExists('desktop_sync_snapshots');
        Schema::dropIfExists('desktop_sync_devices');
    }
};
