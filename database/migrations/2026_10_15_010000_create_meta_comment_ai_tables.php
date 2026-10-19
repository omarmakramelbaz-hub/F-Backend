<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateMetaCommentAiTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('meta_comment_ai_settings')) {
            Schema::create('meta_comment_ai_settings', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->boolean('enabled')->default(false);
                $table->string('mode', 12)->default('always');
                $table->text('days');
                $table->string('start', 5)->default('10:00');
                $table->string('end', 5)->default('23:00');
                $table->unsignedInteger('revision')->default(0);
                $table->timestamp('enabled_at')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->timestamp('worker_seen_at')->nullable();
                $table->string('last_reason', 40)->nullable();
                $table->timestamps();
            });
        }
        DB::table('meta_comment_ai_settings')->insertOrIgnore([
            'id' => 1, 'enabled' => false, 'mode' => 'always',
            'days' => '[1,2,3,4,5,6,7]', 'start' => '10:00', 'end' => '23:00',
            'revision' => 0, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        if (!Schema::hasTable('meta_comment_ai_targets')) {
            Schema::create('meta_comment_ai_targets', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('account_key', 64);
                $table->char('target_hash', 64)->unique('meta_comment_ai_target_unique');
                $table->char('post_key', 64);
                $table->timestamp('comment_created_at')->nullable();
                $table->string('state', 20);
                $table->string('source', 10);
                $table->string('request_id', 36)->nullable();
                $table->unsignedInteger('revision')->nullable();
                $table->string('reason', 40)->nullable();
                $table->text('response_id')->nullable();
                $table->timestamps();
                $table->index(['account_key', 'state'], 'meta_comment_ai_account_state');
            });
        }
        if (!Schema::hasTable('meta_comment_ai_poll_states')) {
            Schema::create('meta_comment_ai_poll_states', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('account_key', 64)->unique('meta_comment_ai_poll_account');
                $table->longText('state')->nullable(); // Encrypted opaque provider cursors only.
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        // A source rollback must retain accepted/uncertain send guards and the disabled policy.
        // Deliberate data removal is a separate operator task.
    }
}
