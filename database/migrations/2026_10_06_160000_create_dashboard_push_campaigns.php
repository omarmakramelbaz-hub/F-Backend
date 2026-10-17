<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDashboardPushCampaigns extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('dashboard_push_campaigns')) Schema::create('dashboard_push_campaigns', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('actor_id'); $t->uuid('request_key'); $t->string('request_hash', 64);
            $t->string('actor_scope', 64); $t->string('account_type', 30); $t->string('title', 150); $t->text('body');
            $t->string('status', 20)->default('queued'); $t->unsignedInteger('invalid')->default(0); $t->string('reason', 40)->nullable();
            $t->uuid('claim')->nullable(); $t->timestamp('claimed_at')->nullable(); $t->timestamps();
            $t->unique(['actor_id', 'request_key'], 'dashboard_push_request_unique'); $t->index(['status', 'id']);
        });
        if (!Schema::hasTable('dashboard_push_devices')) Schema::create('dashboard_push_devices', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('campaign_id'); $t->string('token_hash', 64); $t->text('token')->nullable();
            $t->string('status', 20)->default('pending'); $t->string('reason', 40)->nullable();
            $t->unique(['campaign_id', 'token_hash'], 'dashboard_push_device_unique'); $t->index(['campaign_id', 'status', 'id'], 'dashboard_push_device_pending');
        });
    }
    public function down()
    {
        Schema::dropIfExists('dashboard_push_devices'); Schema::dropIfExists('dashboard_push_campaigns');
    }
}
