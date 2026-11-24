<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'whatsapp_inbox_messages';
    private const INDEX = 'wa_inbox_inbound_recent';

    public function up()
    {
        // The WhatsApp order poller (every 5 s) looks for inbound messages from the last 24 hours.
        // Without this index it read every message after the activation floor (~49k rows, ~140 ms);
        // with it, only the inbound messages inside the window.
        if (! Schema::hasTable(self::TABLE) || $this->present()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->index(['direction', 'sent_at', 'conversation_id'], self::INDEX);
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
        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('" . self::TABLE . "')"))->contains('name', self::INDEX);
        }

        return DB::select('SHOW INDEX FROM ' . self::TABLE . ' WHERE Key_name = ?', [self::INDEX]) !== [];
    }
};
