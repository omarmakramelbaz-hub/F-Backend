<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\MetaAiControl;
use App\Services\Dashboard\MetaInboxAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MetaAiControlController extends Controller
{
    public function state(Request $request, MetaInboxAccess $access, MetaAiControl $control)
    {
        try {
            $actor = $access->actor(auth('admin')->user());
            return $this->json($control->state($actor));
        } catch (\Throwable $error) { return $this->failure($error); }
    }

    public function update(Request $request, MetaInboxAccess $access, MetaAiControl $control)
    {
        try {
            $actor = $access->owner(auth('admin')->user());
            $raw = $request->getContent();
            if (strlen($raw) > 8192 || !$request->isJson()) return $this->json(['error' => 'INVALID_POLICY'], 422);
            try { $input = json_decode($raw, true, 10, JSON_THROW_ON_ERROR); }
            catch (\Throwable $error) { return $this->json(['error' => 'INVALID_POLICY'], 422); }
            if (!is_array($input) || count($input) < 2 || count($input) > 3
                || array_diff(array_keys($input), ['policy', 'expected_revision', 'external_agent_paused']) !== []
                || !is_array($input['policy'] ?? null) || !is_int($input['expected_revision'] ?? null)
                || (array_key_exists('external_agent_paused', $input) && !is_bool($input['external_agent_paused'])))
                return $this->json(['error' => 'INVALID_POLICY'], 422);
            return $this->json($control->update($actor, $input['policy'], $input['expected_revision'], $input['external_agent_paused'] ?? false));
        } catch (\Throwable $error) { return $this->failure($error); }
    }

    private function failure(\Throwable $error)
    {
        if ($error->getMessage() === 'EXTERNAL_AGENT_PAUSE_REQUIRED')
            return $this->json(['error' => 'EXTERNAL_AGENT_PAUSE_REQUIRED'], 422);
        if ($error instanceof HttpExceptionInterface) return $this->json(['error' => 'FORBIDDEN'], 403);
        $reason = $error->getMessage();
        if ($reason === 'REPLIES_PENDING') return $this->json(['error' => $reason], 409);
        if ($reason === 'REVISION_CONFLICT') return $this->json(['error' => $reason], 409);
        if ($reason === 'INVALID_POLICY') return $this->json(['error' => $reason], 422);
        return $this->json(['error' => 'UNAVAILABLE'], 503);
    }

    private function json(array $value, int $status = 200)
    {
        return response()->json($value, $status)->withHeaders(['Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
