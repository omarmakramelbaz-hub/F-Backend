<?php

namespace App\Services\Dashboard;

use Illuminate\Database\Eloquent\Builder;

/** Count authorized people separately from their registered device tokens. */
class DashboardPushAudience
{
    public function collect(Builder $query): array
    {
        $counts = ['users' => 0, 'with_devices' => 0, 'without_devices' => 0, 'devices' => 0, 'invalid' => 0];
        $tokens = []; $unique = [];
        (clone $query)->select('users.id')->with(['tokens:id,user_id,token'])->chunkById(500, function ($users) use (&$counts, &$tokens, &$unique) {
            foreach ($users as $user) {
                $counts['users']++; $hasDevice = false;
                foreach ($user->tokens as $device) {
                    $token = $device->token;
                    if (!DashboardPushSender::validToken($token)) { $counts['invalid']++; $tokens[] = $token; continue; }
                    $hasDevice = true;
                    $hash = hash('sha256', $token);
                    if (!isset($unique[$hash])) { $unique[$hash] = true; $tokens[] = $token; }
                }
                $counts[$hasDevice ? 'with_devices' : 'without_devices']++;
            }
        });
        $counts['devices'] = count($unique);
        return ['counts' => $counts, 'tokens' => $tokens];
    }
}
