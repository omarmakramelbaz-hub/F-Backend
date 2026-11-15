<?php

namespace App\Services\Chat;

use App\Http\Traits\FcmFirebase;

/**
 * Sends a chat message's push from the server, with the server's own Firebase credentials. The customer app used to send
 * it itself with a Firebase key built into the app; it now asks the server (ChatPushController).
 */
class ChatPush
{
    use FcmFirebase;

    /** @param array{title:string,text:string,data:array} $body */
    public function send(array $tokens, array $body): void
    {
        $tokens = array_values(array_filter(array_map(fn ($token) => trim((string) $token), $tokens)));
        if ($tokens) $this->sendFcmNotification($tokens, $body);
    }
}
