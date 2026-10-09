<?php

// Local codec conversion only. No Graph API, credentials, or customer messages.
$vendor = getenv('WA_INBOX_TEST_VENDOR');
if (!$vendor || !is_file($vendor)) { fwrite(STDERR, "VOICE_TEST_VENDOR_REQUIRED\n"); exit(1); }
require $vendor;
require __DIR__ . '/../app/Services/Dashboard/WhatsAppVoiceMedia.php';

use App\Services\Dashboard\WhatsAppVoiceMedia;
use Symfony\Component\HttpFoundation\File\UploadedFile;

$count = 0;
function checkVoice(bool $ok, string $label): void { global $count; if (!$ok) throw new RuntimeException($label); $count++; }
function refusedVoice(callable $operation, string $label): void
{
    try { $operation(); } catch (RuntimeException $error) { checkVoice(in_array($error->getMessage(), ['INVALID_VOICE', 'VOICE_UNAVAILABLE'], true), $label); return; }
    throw new RuntimeException($label);
}
function makeVoice(string $path, string $codec, string $format, int $seconds = 1): void
{
    $pipes = [];
    $p = proc_open(['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi',
        '-i', 'sine=frequency=440:sample_rate=48000', '-t', (string)$seconds, '-ac', '1', '-c:a', $codec,
        '-f', $format, $path], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($p) || proc_close($p) !== 0) throw new RuntimeException('FIXTURE_CODEC_FAILED');
}
$directory = sys_get_temp_dir() . '/wa-voice-test-' . bin2hex(random_bytes(12));
mkdir($directory, 0700);
try {
    $media = new WhatsAppVoiceMedia(null, $directory . '/private');
    checkVoice($media->available(), 'fixed codec tools available');
    foreach ([['libopus', 'webm', 'audio/webm'], ['libopus', 'ogg', 'audio/ogg'], ['aac', 'mp4', 'audio/mp4']] as $n => $spec) {
        $source = $directory . '/capture-' . $n;
        makeVoice($source, $spec[0], $spec[1]);
        $file = new UploadedFile($source, 'untrusted-;filename', $spec[2], UPLOAD_ERR_OK, true);
        $prepared = $media->prepare($file);
        checkVoice(is_file($prepared['path']) && $prepared['mime'] === 'audio/ogg' && $prepared['filename'] === 'voice.ogg', 'actual accepted browser capture converted');
        checkVoice($prepared['input_sha256'] === hash_file('sha256', $source), 'conversion binds original capture bytes');
        checkVoice((fileperms($prepared['path']) & 0777) === 0600 && strpos($prepared['path'], $directory . '/private/') === 0, 'private prepared audio');
        $media->cleanup($prepared['path']);
        checkVoice(!file_exists($prepared['path']), 'owned recording removed');
    }
    $bad = $directory . '/bad'; file_put_contents($bad, 'pretend opus; arbitrary uploaded bytes');
    refusedVoice(fn() => $media->prepare(new UploadedFile($bad, 'bad.ogg', 'audio/ogg', UPLOAD_ERR_OK, true)), 'MIME spoof rejected');
    $huge = $directory . '/huge'; $handle = fopen($huge, 'wb'); ftruncate($handle, WhatsAppVoiceMedia::MAX_BYTES + 1); fclose($handle);
    refusedVoice(fn() => $media->prepare(new UploadedFile($huge, 'big.webm', 'audio/webm', UPLOAD_ERR_OK, true)), 'oversized upload rejected');
    $long = $directory . '/long'; makeVoice($long, 'libopus', 'ogg', 61);
    refusedVoice(fn() => $media->prepare(new UploadedFile($long, 'long.ogg', 'audio/ogg', UPLOAD_ERR_OK, true)), 'actual excessive duration rejected');
    $linked = $directory . '/link'; symlink($directory . '/capture-0', $linked);
    refusedVoice(fn() => $media->prepare(new UploadedFile($linked, 'link.webm', 'audio/webm', UPLOAD_ERR_OK, true)), 'symlink upload rejected');
    $outside = $directory . '/unowned'; file_put_contents($outside, 'retain'); $media->cleanup($outside);
    checkVoice(file_get_contents($outside) === 'retain', 'cleanup cannot remove arbitrary path');
    checkVoice(count(glob($directory . '/private/voice-*')) === 0, 'failure staging removed');
    mkdir($directory . '/actual', 0700); symlink($directory . '/actual', $directory . '/unsafe');
    $unsafe = new WhatsAppVoiceMedia(null, $directory . '/unsafe');
    refusedVoice(fn() => $unsafe->prepare(new UploadedFile($directory . '/capture-0', 'safe.webm', 'audio/webm', UPLOAD_ERR_OK, true)), 'symlink staging directory rejected');
    $failed = new WhatsAppVoiceMedia(fn($args, $timeout) => ['code'=>1, 'output'=>'private error text never returned'], $directory . '/private');
    refusedVoice(fn() => $failed->prepare(new UploadedFile($directory . '/capture-0', 'capture.webm', 'audio/webm', UPLOAD_ERR_OK, true)), 'fixed error no subprocess leakage');
    checkVoice(count(glob($directory . '/private/voice-*')) === 0, 'failed command cleaned');
    mkdir($directory . '/writable-parent', 0777); chmod($directory . '/writable-parent', 0777);
    $writable = new WhatsAppVoiceMedia(null, $directory . '/writable-parent/private');
    refusedVoice(fn() => $writable->prepare(new UploadedFile($directory . '/capture-0', 'capture.webm', 'audio/webm', UPLOAD_ERR_OK, true)), 'other-user writable staging ancestor rejected');
    $run = new ReflectionMethod(WhatsAppVoiceMedia::class, 'run');
    $start = microtime(true);
    $timed = $run->invoke($media, ['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-re',
        '-f', 'lavfi', '-i', 'sine=frequency=440', '-t', '10', '-f', 'null', '-'], 1);
    checkVoice($timed['code'] !== 0 && $timed['output'] === '' && microtime(true) - $start < 3, 'actual subprocess timeout killed and reaped');
    $large = $run->invoke($media, [PHP_BINARY, '-n', '-r', 'fwrite(STDOUT,str_repeat("x",2097152));usleep(500000);'], 3);
    checkVoice($large['code'] !== 0 && $large['output'] === '', 'actual subprocess output capped without leakage');
    echo "WHATSAPP_VOICE_MEDIA_TESTS=$count\n";
} finally {
    unset($media, $unsafe, $failed);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($directory);
}
