<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $index = DB::select(
            "SHOW INDEX FROM notifications WHERE Key_name = ?",
            ['notifications_notifiable_created_idx']
        );

        if (! empty($index)) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(
                ['notifiable_type', 'notifiable_id', 'created_at'],
                'notifications_notifiable_created_idx'
            );
        });
    }

    public function down()
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $index = DB::select(
            "SHOW INDEX FROM notifications WHERE Key_name = ?",
            ['notifications_notifiable_created_idx']
        );

        if (empty($index)) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_notifiable_created_idx');
        });
    }
};
