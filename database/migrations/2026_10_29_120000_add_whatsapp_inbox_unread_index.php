<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'whatsapp_inbox_messages';
    private const INDEX = 'wa_inbox_thread_direction';

    public function up()
    {
        // Unread counts read only the inbound messages after each conversation's read marker.
        if (! Schema::hasTable(self::TABLE) || $this->present()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->index(['conversation_id', 'direction', 'id'], self::INDEX);
        });
    }

    public function down()
    {
        if (! Schema::hasTable(self::TABLE) || ! $this->present()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }

    private function present(): bool
    {
        return DB::select('SHOW INDEX FROM ' . self::TABLE . ' WHERE Key_name = ?', [self::INDEX]) !== [];
    }
};
