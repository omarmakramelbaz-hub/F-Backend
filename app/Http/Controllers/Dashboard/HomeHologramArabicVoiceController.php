<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\HomeHologramArabicVoice;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HomeHologramArabicVoiceController extends Controller
{
    public function speak(Request $request, HomeHologramArabicVoice $voice)
    {
        $headers = ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];
        if ((int) $request->server('CONTENT_LENGTH', 0) > 8192) {
            return response()->json(['message' => 'النص الصوتي غير صالح.'], 422, $headers);
        }
        $result = $voice->synthesize(auth('admin')->user(), $request->input('text'));
        if (($result['state'] ?? '') !== 'ready') {
            $state = $result['state'] ?? 'unavailable';
            $code = $state === 'invalid' ? 422 : ($state === 'busy' ? 429 : 503);
            return response()->json(['message' => $state === 'busy' ? 'الصوت مشغول حاليًا؛ حاول تاني.' : 'تعذّر تشغيل الرد الصوتي.'], $code, $headers);
        }
        $response = new BinaryFileResponse($result['path'], 200, $headers + [
            'Content-Type' => 'audio/wav', 'Content-Disposition' => 'inline; filename="go-arabic.wav"',
        ], false, null, false, false);
        return $response->deleteFileAfterSend(true);
    }
}
