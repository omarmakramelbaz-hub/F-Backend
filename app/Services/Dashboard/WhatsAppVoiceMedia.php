<?php

namespace App\Services\Dashboard;

use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Browser recordings are decoded locally to a private, bounded Ogg/Opus file. */
class WhatsAppVoiceMedia
{
    public const MAX_BYTES = 8388608;
    public const MAX_SECONDS = 60;
    private const PROBE = '/usr/bin/ffprobe';
    private const CONVERTER = '/usr/bin/ffmpeg';
    private string $directory;
    private $runner;
    private array $owned = [];

    /** The optional command seam and directory are for isolated fixtures only. */
    public function __construct(?callable $runner = null, ?string $directory = null)
    {
        $this->runner = $runner;
        $this->directory = $directory ?? '/home/fasakha/whatsapp-voice-tmp';
    }

    public function available(): bool
    {
        return function_exists('proc_open') && function_exists('proc_get_status') && function_exists('proc_terminate')
            && function_exists('proc_close') && function_exists('posix_geteuid')
            && is_executable(self::PROBE) && is_executable(self::CONVERTER);
    }

    public function prepare(UploadedFile $file): array
    {
        if (!$this->available() || !$file->isValid()) throw new RuntimeException('VOICE_UNAVAILABLE');
        if (is_link($file->getPathname())) throw new RuntimeException('INVALID_VOICE');
        $source = $file->getRealPath();
        if (!is_string($source)) throw new RuntimeException('INVALID_VOICE');
        $metadata = @lstat($source);
        if (!$metadata || (($metadata['mode'] & 0170000) !== 0100000) || $metadata['nlink'] !== 1
            || $metadata['size'] < 1 || $metadata['size'] > self::MAX_BYTES) throw new RuntimeException('INVALID_VOICE');
        $this->privateDirectory();
        $working = $this->directory . '/voice-' . bin2hex(random_bytes(24));
        if (!@mkdir($working, 0700)) throw new RuntimeException('VOICE_UNAVAILABLE');
        $path = $working . '/voice.ogg';
        $this->owned[$path] = $working;
        try {
            $input = $working . '/recording';
            $reader = @fopen($source, 'rb');
            $writer = @fopen($input, 'xb');
            if (!$reader || !$writer) {
                if (is_resource($reader)) fclose($reader);
                if (is_resource($writer)) fclose($writer);
                throw new RuntimeException('INVALID_VOICE');
            }
            try {
                $current = fstat($reader);
                if ($current['dev'] !== $metadata['dev'] || $current['ino'] !== $metadata['ino']
                    || $current['size'] !== $metadata['size']) throw new RuntimeException('INVALID_VOICE');
                if (stream_copy_to_stream($reader, $writer, self::MAX_BYTES + 1) !== $metadata['size']) throw new RuntimeException('INVALID_VOICE');
                fflush($writer);
                chmod($input, 0600);
            } finally { fclose($reader); fclose($writer); }
            $inputHash = hash_file('sha256', $input);
            if (!is_string($inputHash)) throw new RuntimeException('INVALID_VOICE');
            $shape = $this->inspect($input);
            $this->validateShape($shape, false);
            $converted = $this->run([self::CONVERTER, '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-protocol_whitelist', 'file,pipe', '-threads', '1', '-i', $input, '-map', '0:a:0',
                '-vn', '-sn', '-dn', '-t', '61', '-ac', '1', '-ar', '48000', '-c:a', 'libopus',
                '-b:a', '32k', '-f', 'ogg', $path], 30);
            if ($converted['code'] !== 0 || !is_file($path) || is_link($path)
                || filesize($path) < 1 || filesize($path) > self::MAX_BYTES) throw new RuntimeException('INVALID_VOICE');
            chmod($path, 0600);
            $this->validateShape($this->inspect($path), true);
            @unlink($input);
            return ['path' => $path, 'mime' => 'audio/ogg', 'filename' => 'voice.ogg', 'input_sha256' => $inputHash];
        } catch (\Throwable $error) {
            $this->cleanup($path);
            throw new RuntimeException('INVALID_VOICE');
        }
    }

    public function cleanup(string $path): void
    {
        // Never remove a path supplied by a client or another service instance.
        if (!isset($this->owned[$path])) return;
        $working = $this->owned[$path];
        unset($this->owned[$path]);
        if (is_link($working) || !is_dir($working)) return;
        foreach (['recording', 'voice.ogg'] as $name) {
            $file = $working . '/' . $name;
            if (is_file($file) && !is_link($file)) @unlink($file);
        }
        @rmdir($working);
    }

    public function __destruct()
    {
        foreach (array_keys($this->owned) as $path) $this->cleanup($path);
    }

    private function privateDirectory(): void
    {
        $parent = dirname($this->directory);
        if (realpath($parent) !== $parent || !is_dir($parent) || !is_writable($parent)) throw new RuntimeException('VOICE_UNAVAILABLE');
        $parentMetadata = @lstat($parent);
        if (!$parentMetadata || !in_array($parentMetadata['uid'], [0, posix_geteuid()], true)
            || ($parentMetadata['mode'] & 0022)) throw new RuntimeException('VOICE_UNAVAILABLE');
        if (!file_exists($this->directory) && !@mkdir($this->directory, 0700)) throw new RuntimeException('VOICE_UNAVAILABLE');
        $m = @lstat($this->directory);
        if (!$m || is_link($this->directory) || (($m['mode'] & 0170000) !== 0040000)
            || $m['uid'] !== posix_geteuid() || ($m['mode'] & 0777) !== 0700) throw new RuntimeException('VOICE_UNAVAILABLE');
    }

    private function inspect(string $path): array
    {
        $result = $this->run([self::PROBE, '-v', 'error', '-protocol_whitelist', 'file,pipe',
            '-show_entries', 'format=format_name,duration:stream=codec_type,codec_name,channels,duration',
            '-of', 'json', '-i', $path], 10);
        if ($result['code'] !== 0) throw new RuntimeException('INVALID_VOICE');
        $decoded = json_decode($result['output'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('INVALID_VOICE');
        return $decoded;
    }

    private function validateShape(array $shape, bool $output): void
    {
        $streams = $shape['streams'] ?? null;
        $format = $shape['format']['format_name'] ?? null;
        if (!is_array($streams) || count($streams) !== 1 || ($streams[0]['codec_type'] ?? null) !== 'audio'
            || !is_string($format)) throw new RuntimeException('INVALID_VOICE');
        $codec = $streams[0]['codec_name'] ?? null;
        $formats = explode(',', $format);
        $supported = (in_array('ogg', $formats, true) && $codec === 'opus')
            || ((in_array('webm', $formats, true) || in_array('matroska', $formats, true)) && $codec === 'opus')
            || ((in_array('mp4', $formats, true) || in_array('mov', $formats, true)) && in_array($codec, ['aac', 'opus'], true));
        if (!$supported || ($output && (!in_array('ogg', $formats, true) || $codec !== 'opus'
            || ($streams[0]['channels'] ?? null) !== 1))) throw new RuntimeException('INVALID_VOICE');
        $duration = $shape['format']['duration'] ?? $streams[0]['duration'] ?? null;
        if ($duration !== null && is_numeric($duration)) {
            $seconds = (float) $duration;
            // Ogg/Opus encoder padding is at most a few milliseconds.
            if (!is_finite($seconds) || $seconds <= 0 || $seconds > self::MAX_SECONDS + 0.1) throw new RuntimeException('INVALID_VOICE');
        } elseif ($output) throw new RuntimeException('INVALID_VOICE');
    }

    private function run(array $arguments, int $timeout): array
    {
        if ($this->runner !== null) return ($this->runner)($arguments, $timeout);
        $pipes = [];
        $process = @proc_open($arguments, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes,
            null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) return ['code' => -1, 'output' => ''];
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $output = ''; $bytes = 0; $exit = -1; $started = microtime(true);
        try {
            while (true) {
                $read = [$pipes[1], $pipes[2]]; $write = null; $except = null;
                @stream_select($read, $write, $except, 0, 100000);
                foreach ($read as $pipe) {
                    $chunk = stream_get_contents($pipe, 65536);
                    $bytes += strlen($chunk);
                    if ($pipe === $pipes[1]) $output .= $chunk;
                }
                $state = proc_get_status($process);
                if (!$state['running']) { $exit = (int) $state['exitcode']; break; }
                if ($bytes > 1048576 || microtime(true) - $started > $timeout) {
                    proc_terminate($process, 9); $output = ''; break;
                }
            }
        } finally {
            fclose($pipes[1]); fclose($pipes[2]);
            $closed = proc_close($process);
            if ($exit < 0 && $closed >= 0) $exit = $closed;
        }
        return ['code' => $exit, 'output' => $output];
    }
}
