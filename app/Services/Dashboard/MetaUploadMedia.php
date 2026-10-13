<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Private, bounded media preparation. Only expiring capabilities can expose a prepared file to Meta. */
class MetaUploadMedia
{
    public const MAX_IMAGE_BYTES = 8388608;
    public const MAX_BYTES = 20971520;
    public const MAX_AUDIO_BYTES = 8388608;
    public const MAX_SECONDS = 60;
    private const PROBE = '/usr/bin/ffprobe';
    private const CONVERTER = '/usr/bin/ffmpeg';
    private $runner;
    private string $directory;
    private array $owned = [];

    public function __construct(?callable $runner = null, ?string $directory = null)
    {
        $this->runner = $runner;
        $this->directory = $directory ?? '/home/fasakha/meta-media-tmp';
    }

    public function audioAvailable(): bool
    {
        return function_exists('proc_open') && function_exists('posix_geteuid')
            && is_executable(self::PROBE) && is_executable(self::CONVERTER);
    }

    public function prepare(UploadedFile $file, bool $voice = false): array
    {
        if (!$file->isValid() || is_link($file->getPathname())) throw new RuntimeException('INVALID_MEDIA');
        $source = $file->getRealPath(); $m = is_string($source) ? @lstat($source) : false;
        if (!$m || (($m['mode'] & 0170000) !== 0100000) || $m['nlink'] !== 1
            || $m['size'] < 1 || $m['size'] > ($voice ? self::MAX_AUDIO_BYTES : self::MAX_BYTES)) throw new RuntimeException('INVALID_MEDIA');
        $this->privateDirectory(); $this->purgeExpired();
        $id = bin2hex(random_bytes(24)); $work = $this->directory . '/' . $id;
        if (!@mkdir($work, 0700)) throw new RuntimeException('MEDIA_UNAVAILABLE');
        $path = $work . '/payload'; $this->owned[$path] = $work;
        try {
            $input = $work . '/input'; $reader = @fopen($source, 'rb'); $writer = @fopen($input, 'xb');
            if (!$reader || !$writer) {
                if (is_resource($reader)) fclose($reader); if (is_resource($writer)) fclose($writer);
                throw new RuntimeException('INVALID_MEDIA');
            }
            try {
                $fresh = fstat($reader);
                if ($fresh['dev'] !== $m['dev'] || $fresh['ino'] !== $m['ino'] || $fresh['size'] !== $m['size']
                    || stream_copy_to_stream($reader, $writer, self::MAX_BYTES + 1) !== $m['size']) throw new RuntimeException('INVALID_MEDIA');
                fflush($writer); chmod($input, 0600);
            } finally { fclose($reader); fclose($writer); }
            $hash = hash_file('sha256', $input); $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($input);
            if (!is_string($hash) || !is_string($mime)) throw new RuntimeException('INVALID_MEDIA');
            $audio = $voice || in_array($mime, ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/ogg', 'video/webm', 'audio/webm'], true);
            if (!$audio && $mime === 'video/mp4' && $this->audioAvailable()) {
                $streams = $this->inspect($input)['streams'] ?? [];
                $audio = count($streams) === 1 && ($streams[0]['codec_type'] ?? null) === 'audio';
            }
            // Browser MP4 recordings may be detected as video/mp4; inspect actual streams before accepting a voice.
            if ($audio) {
                if (!$this->audioAvailable()) throw new RuntimeException('VOICE_UNAVAILABLE');
                if ($m['size'] > self::MAX_AUDIO_BYTES) throw new RuntimeException('INVALID_MEDIA');
                $shape = $this->inspect($input); $this->audioShape($shape);
                $conversion = $this->run([self::CONVERTER, '-nostdin', '-hide_banner', '-loglevel', 'error',
                    '-protocol_whitelist', 'file,pipe', '-threads', '1', '-i', $input, '-map', '0:a:0', '-vn', '-sn', '-dn',
                    '-t', '61', '-ac', '1', '-ar', '48000', '-c:a', 'aac', '-b:a', '96k', '-movflags', '+faststart', '-f', 'ipod', $path], 30);
                if ($conversion['code'] !== 0 || !is_file($path) || filesize($path) < 1 || filesize($path) > self::MAX_AUDIO_BYTES) throw new RuntimeException('INVALID_MEDIA');
                $this->audioShape($this->inspect($path), true); $kind = 'audio'; $mime = 'audio/mp4'; $name = $voice ? 'voice.m4a' : 'audio.m4a';
            } elseif (in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
                $shape = @getimagesize($input);
                if ($m['size'] > self::MAX_IMAGE_BYTES || !is_array($shape) || ($shape['mime'] ?? null) !== $mime
                    || $shape[0] < 1 || $shape[1] < 1 || $shape[0] * $shape[1] > 40000000) throw new RuntimeException('INVALID_MEDIA');
                $kind = 'image'; $name = 'image.' . ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'][$mime];
            } elseif ($mime === 'video/mp4') {
                if (!$this->audioAvailable()) throw new RuntimeException('MEDIA_UNAVAILABLE');
                $shape = $this->inspect($input); $streams = $shape['streams'] ?? []; $videos = 0;
                foreach ($streams as $stream) {
                    if (($stream['codec_type'] ?? null) === 'video' && ($stream['codec_name'] ?? null) === 'h264') $videos++;
                    elseif (($stream['codec_type'] ?? null) !== 'audio' || ($stream['codec_name'] ?? null) !== 'aac') throw new RuntimeException('INVALID_MEDIA');
                }
                if ($videos !== 1 || count($streams) > 2 || !in_array('mp4', explode(',', $shape['format']['format_name'] ?? ''), true)) throw new RuntimeException('INVALID_MEDIA');
                $kind = 'video'; $name = 'video.mp4';
            } else {
                $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
                // OOXML files are ZIP containers. Some finfo databases identify only that container; keep their approved extension.
                $allowed = ['application/pdf' => ['pdf'], 'text/plain' => ['txt'], 'application/zip' => ['zip', 'docx', 'xlsx', 'pptx'],
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx']];
                if (!isset($allowed[$mime]) || !in_array($extension, $allowed[$mime], true)) throw new RuntimeException('INVALID_MEDIA');
                $kind = 'file'; $name = 'attachment.' . $extension;
            }
            if (!$audio && !@rename($input, $path)) throw new RuntimeException('MEDIA_UNAVAILABLE');
            if (is_file($input)) @unlink($input); chmod($path, 0600);
            return ['path' => $path, 'id' => $id, 'type' => $kind, 'mime' => $mime, 'filename' => $name,
                'input_sha256' => $hash, 'bytes' => filesize($path)];
        } catch (\Throwable $e) {
            $this->cleanup($path);
            throw new RuntimeException(in_array($e->getMessage(), ['VOICE_UNAVAILABLE', 'MEDIA_UNAVAILABLE'], true) ? $e->getMessage() : 'INVALID_MEDIA');
        }
    }

    /** Instagram Login's documented Send API accepts a URL; no unsupported upload edge is guessed. */
    public function expose(array $media): string
    {
        $path = $media['path'] ?? ''; $work = $this->owned[$path] ?? null;
        if (!is_string($work)) throw new RuntimeException('MEDIA_UNAVAILABLE');
        $expires = time() + 600;
        $details = ['expires' => $expires, 'mime' => $media['mime'], 'filename' => $media['filename'],
            'bytes' => $media['bytes'], 'sha256' => hash_file('sha256', $path)];
        $manifest = $work . '/ticket.json';
        if (file_put_contents($manifest, json_encode($details, JSON_THROW_ON_ERROR), LOCK_EX) === false || !chmod($manifest, 0600)) throw new RuntimeException('MEDIA_UNAVAILABLE');
        $ticket = $media['id'] . '.' . $expires; $ticket .= '.' . $this->signature($ticket);
        $url = route('meta-replies.temporary-media', ['ticket' => $ticket]);
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || !is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass'])) throw new RuntimeException('MEDIA_UNAVAILABLE');
        return $url;
    }

    public function temporary(string $ticket): array
    {
        if (!preg_match('/\A([0-9a-f]{48})\.([1-9][0-9]{9})\.([0-9a-f]{64})\z/', $ticket, $parts)
            || (int) $parts[2] <= time() || (int) $parts[2] > time() + 600
            || !hash_equals($this->signature($parts[1] . '.' . $parts[2]), $parts[3])) throw new RuntimeException('MEDIA_EXPIRED');
        $this->privateDirectory(); $work = $this->directory . '/' . $parts[1];
        $path = $work . '/payload'; $manifest = $work . '/ticket.json';
        $m = @lstat($work);
        if (!$m || is_link($work) || (($m['mode'] & 0170000) !== 0040000) || $m['uid'] !== posix_geteuid()
            || ($m['mode'] & 0777) !== 0700 || !$this->privateFile($manifest, 2048) || !$this->privateFile($path, self::MAX_BYTES)) throw new RuntimeException('MEDIA_EXPIRED');
        $details = json_decode((string) file_get_contents($manifest), true, 8, JSON_THROW_ON_ERROR);
        if (($details['expires'] ?? null) !== (int) $parts[2] || !is_int($details['bytes'] ?? null)
            || filesize($path) !== $details['bytes'] || !is_string($details['sha256'] ?? null)
            || !hash_equals($details['sha256'], hash_file('sha256', $path))) throw new RuntimeException('MEDIA_EXPIRED');
        $types = ['image/jpeg', 'image/png', 'image/gif', 'audio/mp4', 'video/mp4', 'application/pdf', 'text/plain', 'application/zip',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
        if (!in_array($details['mime'] ?? null, $types, true) || !preg_match('/\A(?:image|video|voice|audio|attachment)\.[a-z0-9]{2,5}\z/', $details['filename'] ?? '')) throw new RuntimeException('MEDIA_EXPIRED');
        return ['path' => $path, 'mime' => $details['mime'], 'filename' => $details['filename']];
    }

    public function retain(string $path): void { unset($this->owned[$path]); }

    public function cleanup(string $path): void
    {
        if (!isset($this->owned[$path])) return;
        $work = $this->owned[$path]; unset($this->owned[$path]); $this->removeWork($work);
    }

    public function __destruct() { foreach (array_keys($this->owned) as $path) $this->cleanup($path); }

    private function privateDirectory(): void
    {
        if (!function_exists('posix_geteuid')) throw new RuntimeException('MEDIA_UNAVAILABLE');
        $parent = dirname($this->directory); $m = @lstat($parent);
        if (realpath($parent) !== $parent || !$m || !is_dir($parent) || !is_writable($parent)
            || !in_array($m['uid'], [0, posix_geteuid()], true) || ($m['mode'] & 0022)) throw new RuntimeException('MEDIA_UNAVAILABLE');
        if (!file_exists($this->directory) && !@mkdir($this->directory, 0700)) throw new RuntimeException('MEDIA_UNAVAILABLE');
        $m = @lstat($this->directory);
        if (!$m || is_link($this->directory) || (($m['mode'] & 0170000) !== 0040000)
            || $m['uid'] !== posix_geteuid() || ($m['mode'] & 0777) !== 0700) throw new RuntimeException('MEDIA_UNAVAILABLE');
    }

    private function privateFile(string $path, int $limit): bool
    {
        $m = @lstat($path);
        return $m && !is_link($path) && (($m['mode'] & 0170000) === 0100000) && $m['uid'] === posix_geteuid()
            && ($m['mode'] & 0777) === 0600 && $m['nlink'] === 1 && $m['size'] > 0 && $m['size'] <= $limit;
    }

    private function signature(string $value): string { return hash_hmac('sha256', 'meta-media-capability-v1:' . $value, Crypt::getKey()); }

    private function purgeExpired(): void
    {
        $handle = @opendir($this->directory); if (!$handle) return; $count = 0;
        try {
            while (($name = readdir($handle)) !== false && $count < 200) {
                if (!preg_match('/\A[0-9a-f]{48}\z/', $name)) continue; $count++;
                $work = $this->directory . '/' . $name; $m = @lstat($work);
                if ($m && !is_link($work) && $m['mtime'] < time() - 660) $this->removeWork($work);
            }
        } finally { closedir($handle); }
    }

    private function removeWork(string $work): void
    {
        if (is_link($work) || !is_dir($work)) return;
        foreach (['input', 'payload', 'ticket.json'] as $name) { $file = $work . '/' . $name; if (is_file($file) && !is_link($file)) @unlink($file); }
        @rmdir($work);
    }

    private function inspect(string $path): array
    {
        $r = $this->run([self::PROBE, '-v', 'error', '-protocol_whitelist', 'file,pipe',
            '-show_entries', 'format=format_name,duration:stream=codec_type,codec_name,channels,duration', '-of', 'json', '-i', $path], 10);
        if ($r['code'] !== 0) throw new RuntimeException('INVALID_MEDIA');
        $shape = json_decode($r['output'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($shape)) throw new RuntimeException('INVALID_MEDIA'); return $shape;
    }

    private function audioShape(array $shape, bool $output = false): void
    {
        $streams = $shape['streams'] ?? null; $formats = explode(',', $shape['format']['format_name'] ?? '');
        $duration = $shape['format']['duration'] ?? $streams[0]['duration'] ?? null;
        $invalidDuration = $duration !== null && (!is_numeric($duration) || !is_finite((float) $duration)
            || (float) $duration <= 0 || (float) $duration > self::MAX_SECONDS + 0.1);
        if (!is_array($streams) || count($streams) !== 1 || ($streams[0]['codec_type'] ?? null) !== 'audio'
            || !array_intersect($formats, ['ogg', 'webm', 'matroska', 'mov', 'mp4', 'wav', 'mp3', 'aac'])
            || !in_array($streams[0]['codec_name'] ?? null, ['opus', 'aac', 'mp3', 'pcm_s16le', 'pcm_s24le', 'pcm_f32le'], true)
            || $invalidDuration || ($output && $duration === null)
            || ($output && (($streams[0]['codec_name'] ?? null) !== 'aac' || ($streams[0]['channels'] ?? null) !== 1 || !in_array('mp4', $formats, true)))) throw new RuntimeException('INVALID_MEDIA');
    }

    private function run(array $arguments, int $timeout): array
    {
        if ($this->runner !== null) return ($this->runner)($arguments, $timeout);
        $pipes = []; $process = @proc_open($arguments, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) return ['code' => -1, 'output' => ''];
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $output = ''; $bytes = 0; $exit = -1; $started = microtime(true);
        try {
            while (true) {
                $read = [$pipes[1], $pipes[2]]; $write = null; $except = null; @stream_select($read, $write, $except, 0, 100000);
                foreach ($read as $pipe) { $chunk = stream_get_contents($pipe, 65536); $bytes += strlen($chunk); if ($pipe === $pipes[1]) $output .= $chunk; }
                $state = proc_get_status($process);
                if (!$state['running']) { $exit = (int) $state['exitcode']; break; }
                if ($bytes > 1048576 || microtime(true) - $started > $timeout) { proc_terminate($process, 9); $output = ''; break; }
            }
        } finally { fclose($pipes[1]); fclose($pipes[2]); $closed = proc_close($process); if ($exit < 0 && $closed >= 0) $exit = $closed; }
        return ['code' => $exit, 'output' => $output];
    }
}
