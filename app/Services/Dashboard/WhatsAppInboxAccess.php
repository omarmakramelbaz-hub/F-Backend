<?php

namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/** Full WhatsApp history is restricted to the central Owner / Admin roles. */
class WhatsAppInboxAccess
{
    public const WABA_ID = '468336579702269';
    public const PHONE_ID = '515388018324075';

    public function actor($actor): User
    {
        // Reload persisted identity and permissions. A selected-user session cannot grant access.
        $fresh = app(TakeawayAccess::class)->actor($actor);
        abort_unless($fresh->account_type === 'admin' && empty($fresh->owner_resturant_id), 403);
        return $fresh;
    }

    public function canAccess($actor): bool
    {
        try {
            $this->actor($actor);
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    public function available(): bool
    {
        return !config('desktop_dashboard.local', false)
            && Schema::hasTable('whatsapp_inbox_conversations')
            && Schema::hasTable('whatsapp_inbox_messages')
            && Schema::hasTable('whatsapp_inbox_ingestion_failures');
    }
}
