<?php
namespace App\Services\Dashboard;

/** Reviewed local recordings; no browser TTS, remote voice request or runtime model load. */
class HomeHologramVoice
{
    public function forActor($actor): ?array
    {
        if (!$actor) return null;
        $name = trim((string) ($actor->name ?? ''));
        $base = preg_match('/^(?:omar|عمر)(?:\s|$)/iu', $name) ? 'go-welcome-omar' : 'go-welcome';
        $relative = 'dashboard/audio/'.$base;
        if (!is_file(public_path($relative.'.mp3')) || !is_file(public_path($relative.'.envelope.json'))) return null;
        $envelope = json_decode(file_get_contents(public_path($relative.'.envelope.json')), true);
        if (!is_array($envelope) || !isset($envelope['sample_rate'], $envelope['values'])
            || !is_numeric($envelope['sample_rate']) || $envelope['sample_rate'] < 1 || $envelope['sample_rate'] > 120
            || !is_array($envelope['values']) || count($envelope['values']) < 1 || count($envelope['values']) > 18000) return null;
        foreach ($envelope['values'] as $value) if (!is_numeric($value) || !is_finite((float) $value) || $value < 0 || $value > 1) return null;
        return ['url'=>asset($relative.'.mp3'), 'envelope'=>$envelope];
    }
}
