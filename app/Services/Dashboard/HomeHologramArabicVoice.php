<?php
namespace App\Services\Dashboard;

/** A private, bounded local speech process. No database, network or greeting worker. */
class HomeHologramArabicVoice
{
    public const MAX_CHARACTERS = 400;
    public const MAX_TEXT_BYTES = 1600;
    public const MAX_AUDIO_BYTES = 2097152;

    public function synthesize($actor, $text): array
    {
        if (!$actor || !in_array($actor->account_type ?? null, DashboardLoginIdentity::ACCOUNT_TYPES, true)
            || !preg_match('/^[1-9][0-9]{0,19}$/D', (string) ($actor->id ?? ''))) return ['state' => 'unavailable'];
        $text = $this->normalize($text);
        if ($text === null) return ['state' => 'invalid'];
        $lock = null; $output = null; $path = null; $process = null; $pipes = [];
        try {
            if (!function_exists('proc_open') || !function_exists('proc_get_status') || !function_exists('proc_terminate')) return ['state' => 'unavailable'];
            $command = $this->command();
            if (!$command || !$this->privateDirectory($this->root())) return ['state' => 'unavailable'];
            $lockPath = $this->lockPath();
            if (!$this->safeParents(dirname($lockPath)) || !$this->regular($lockPath, 0)) return ['state' => 'unavailable'];
            $lock = @fopen($lockPath, 'r+b');
            if (!$lock) return ['state' => 'unavailable'];
            if (!flock($lock, LOCK_EX | LOCK_NB)) return ['state' => 'busy'];
            $this->cleanExpired();
            $path = $this->root().'/speech.'.bin2hex(random_bytes(16)).'.wav';
            $output = @fopen($path, 'x+b');
            if (!$output || !@chmod($path, 0600)) return ['state' => 'unavailable'];
            $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => $output, 2 => ['file', '/dev/null', 'a']], $pipes,
                $this->root(), ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8'], ['bypass_shell' => true]);
            if (!is_resource($process)) return ['state' => 'unavailable'];
            $written = @fwrite($pipes[0], $text); @fclose($pipes[0]); unset($pipes[0]);
            if ($written !== strlen($text)) return ['state' => 'unavailable'];
            $deadline = microtime(true) + $this->timeout(); $exit = -1;
            while (true) {
                $status = proc_get_status($process); $bytes = fstat($output)['size'] ?? self::MAX_AUDIO_BYTES + 1;
                if ($bytes > self::MAX_AUDIO_BYTES) return ['state' => 'unavailable'];
                if (!$status['running']) { $exit = (int) $status['exitcode']; break; }
                if (microtime(true) >= $deadline) return ['state' => 'unavailable'];
                usleep(20000);
            }
            $closed = proc_close($process); $process = null;
            if ($exit < 0) $exit = $closed;
            @fflush($output); @fclose($output); $output = null;
            if ($exit !== 0 || !$this->validWave($path)) return ['state' => 'unavailable'];
            $ready = $path; $path = null;
            return ['state' => 'ready', 'path' => $ready];
        } catch (\Throwable $error) {
            return ['state' => 'unavailable'];
        } finally {
            foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
            if (is_resource($process)) {
                @proc_terminate($process, 15); usleep(30000);
                $status = @proc_get_status($process);
                if (is_array($status) && $status['running']) @proc_terminate($process, 9);
                @proc_close($process);
            }
            if (is_resource($output)) @fclose($output);
            if ($path !== null && !is_link($path)) @unlink($path);
            if (is_resource($lock)) { @flock($lock, LOCK_UN); @fclose($lock); }
        }
    }

    protected function root(): string { return storage_path('app/go-hologram-arabic'); }
    protected function lockPath(): string { return (string) config('home_hologram_arabic_voice.lock_path', '/var/lib/go-hologram-arabic/speech.lock'); }
    protected function timeout(): float { return max(.1, min(8, (float) config('home_hologram_arabic_voice.timeout_seconds', 8))); }
    protected function command(): ?array
    {
        $command = config('home_hologram_arabic_voice.command', ['/usr/bin/python3', '-I', '-B', '/usr/local/lib/go-hologram-arabic/synthesize.py']);
        if (!is_array($command) || count($command) !== 4 || $command[1] !== '-I' || $command[2] !== '-B') return null;
        // The distribution's stable Python path may be an OS-owned symlink.
        if (!is_string($command[0]) || !str_starts_with($command[0], '/') || !($command[0] = realpath($command[0]))) return null;
        foreach ([$command[0], $command[3]] as $file) {
            if (!is_string($file) || !str_starts_with($file, '/') || !$this->safeParents(dirname($file)) || !$this->regular($file, 16777216)) return null;
        }
        return is_executable($command[0]) && is_readable($command[3]) ? array_values($command) : null;
    }
    protected function normalize($text): ?string
    {
        if (!is_string($text) || strlen($text) > self::MAX_TEXT_BYTES || !preg_match('//u', $text)
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>\[\]{}]/u', $text)) return null;
        $text = trim(preg_replace('/[\p{Z}\s]+/u', ' ', $text));
        $length = preg_match_all('/./us', $text);
        return $length >= 1 && $length <= self::MAX_CHARACTERS ? $text : null;
    }
    protected function safeParents(string $directory): bool
    {
        if (!str_starts_with($directory, '/')) return false;
        for ($path = $directory; $path !== dirname($path); $path = dirname($path)) {
            if (is_link($path) || !is_dir($path)) return false;
        }
        return true;
    }
    protected function regular(string $path, int $maximum): bool
    {
        clearstatcache(true, $path); $info = @lstat($path);
        return $info && ($info['mode'] & 0170000) === 0100000 && $info['nlink'] === 1 && $info['size'] <= $maximum;
    }
    protected function privateDirectory(string $path): bool
    {
        if (!$this->safeParents(dirname($path))) return false;
        if (!file_exists($path) && !@mkdir($path, 0700)) return false;
        $info = @lstat($path);
        return $info && !is_link($path) && ($info['mode'] & 0170777) === 0040700 && is_writable($path);
    }
    protected function cleanExpired(): void
    {
        $seen = 0;
        foreach (new \DirectoryIterator($this->root()) as $item) {
            if (++$seen > 256) break;
            if (preg_match('/^speech\.[a-f0-9]{32}\.wav$/D', $item->getFilename()) && !$item->isLink()
                && $item->isFile() && $item->getMTime() < time() - 120) @unlink($item->getPathname());
        }
    }
    protected function validWave(string $path): bool
    {
        if (!$this->regular($path, self::MAX_AUDIO_BYTES) || filesize($path) < 44) return false;
        $stream = @fopen($path, 'rb'); if (!$stream) return false;
        try { $header = fread($stream, 44); } finally { fclose($stream); }
        if (strlen($header) !== 44 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 8) !== 'WAVEfmt '
            || substr($header, 36, 4) !== 'data') return false;
        $format = unpack('Vriff/Vfmt/vcodec/vchannels/Vrate/Vbytes/vblock/vbits/Vdata',
            substr($header, 4, 4).substr($header, 16, 20).substr($header, 40, 4));
        $data = filesize($path) - 44; $duration = $data / 44100;
        return $format['riff'] === filesize($path) - 8 && $format['fmt'] === 16 && $format['codec'] === 1
            && $format['channels'] === 1 && $format['rate'] === 22050 && $format['bytes'] === 44100
            && $format['block'] === 2 && $format['bits'] === 16 && $format['data'] === $data
            && $data % 2 === 0 && $duration >= .1 && $duration <= 30;
    }
}
