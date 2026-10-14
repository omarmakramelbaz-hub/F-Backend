<?php

namespace App\Services\Dashboard;

/** Serializes the final permission check and dispatch across workers and manual replies. */
class MetaCommentAiDispatchLock
{
    private $runner;
    private string $path;

    public function __construct(?callable $runner = null, ?string $path = null)
    {
        $this->runner = $runner;
        $this->path = $path ?? storage_path('app/meta-comment-ai/dispatch.lock');
    }

    public function run(callable $callback, int $timeoutMilliseconds = 2500)
    {
        $timeoutMilliseconds = max(0, min(30000, $timeoutMilliseconds));
        if ($this->runner !== null) return ($this->runner)($callback, $timeoutMilliseconds);
        $handle = $this->acquire($timeoutMilliseconds);
        if (!is_resource($handle)) return null;
        try { return $callback(); }
        finally { @flock($handle, LOCK_UN); @fclose($handle); }
    }

    private function acquire(int $timeoutMilliseconds)
    {
        $directory = dirname($this->path);
        clearstatcache(true, $directory);
        $directoryStat = @lstat($directory);
        if ($directoryStat === false) {
            $mask = umask(0077);
            try { @mkdir($directory, 0700); }
            finally { umask($mask); }
            clearstatcache(true, $directory);
            $directoryStat = @lstat($directory);
        }
        if (!$this->privateDirectory($directoryStat)) return null;

        clearstatcache(true, $this->path);
        $before = @lstat($this->path);
        $handle = null;
        if ($before === false) {
            $mask = umask(0077);
            try { $handle = @fopen($this->path, 'x+b'); }
            finally { umask($mask); }
            if (!is_resource($handle)) {
                clearstatcache(true, $this->path);
                $before = @lstat($this->path);
            }
        }
        if (!is_resource($handle)) {
            if (!$this->privateFile($before)) return null;
            $handle = @fopen($this->path, 'r+b');
        }
        if (!is_resource($handle)) return null;
        try {
            $opened = @fstat($handle);
            clearstatcache(true, $this->path);
            $atPath = @lstat($this->path);
            if (!$this->privateFile($opened) || !$this->privateFile($atPath)
                || !$this->sameFile($opened, $atPath)
                || (is_array($before) && !$this->sameFile($before, $opened))) return null;
            $deadline = hrtime(true) + $timeoutMilliseconds * 1000000;
            do {
                if (@flock($handle, LOCK_EX | LOCK_NB)) {
                    clearstatcache(true, $directory);
                    clearstatcache(true, $this->path);
                    $currentDirectory = @lstat($directory);
                    $currentFile = @lstat($this->path);
                    if (!$this->privateDirectory($currentDirectory)
                        || !$this->sameFile($directoryStat, $currentDirectory)
                        || !$this->privateFile($currentFile) || !$this->sameFile($opened, $currentFile)) return null;
                    $acquired = $handle;
                    $handle = null;
                    return $acquired;
                }
                $remaining = $deadline - hrtime(true);
                if ($remaining <= 0) break;
                usleep((int) min(20000, max(1, intdiv($remaining, 1000))));
            } while (hrtime(true) < $deadline);
            return null;
        } finally {
            if (is_resource($handle)) { @flock($handle, LOCK_UN); @fclose($handle); }
        }
    }

    private function privateDirectory($stat): bool
    {
        return is_array($stat) && ($stat['mode'] & 0170000) === 0040000
            && ($stat['mode'] & 0777) === 0700;
    }

    private function privateFile($stat): bool
    {
        return is_array($stat) && ($stat['mode'] & 0170000) === 0100000
            && ($stat['mode'] & 0777) === 0600 && ($stat['nlink'] ?? 0) === 1;
    }

    private function sameFile(array $left, array $right): bool
    {
        return $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
    }
}
