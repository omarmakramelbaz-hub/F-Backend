<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\HomeHologramVoice;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HomeHologramVoiceController extends Controller
{
    public function status(Request $request, HomeHologramVoice $voice, string $ticket)
    {
        $descriptor = $voice->status(auth('admin')->user(), $request, $ticket);
        abort_unless($descriptor, 404);
        return response()->json($descriptor)->withHeaders($this->headers());
    }

    public function audio(Request $request, HomeHologramVoice $voice, string $ticket)
    {
        $recording = $voice->audio(auth('admin')->user(), $request, $ticket);
        abort_unless($recording, 404);
        // Symfony's BinaryFileResponse handles authenticated Range/HEAD requests.
        return new BinaryFileResponse($recording['path'], 200, $this->headers() + [
            'Content-Type' => 'audio/mpeg', 'Content-Disposition' => 'inline; filename="go-welcome.mp3"',
        ], false, null, false, false);
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];
    }
}
