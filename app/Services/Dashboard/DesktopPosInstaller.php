<?php
namespace App\Services\Dashboard;

/** Private, bounded uploads of the reviewed Windows installer. Never executes uploaded files. */
class DesktopPosInstaller
{
    public const CHUNK_BYTES = 524288;
    private string $target;
    private int $bytes;
    private string $sha256;

    public function __construct(?string $target = null, ?int $bytes = null, ?string $sha256 = null)
    {
        $this->target = $target ?? config('desktop_pos.installer');
        $this->bytes = $bytes ?? config('desktop_pos.installer_bytes');
        $this->sha256 = $sha256 ?? config('desktop_pos.installer_sha256');
    }

    public function canUpload($actor, TakeawayAccess $access): bool
    {
        try {
            $actor = $access->actor($actor);
            return $actor->account_type === 'admin' && empty($actor->owner_resturant_id)
                && $access->permissions($actor)['can_manage'];
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { return false; }
    }

    public function start(int $bytes, ?string $previous = null): array
    {
        abort_unless($bytes === $this->bytes, 422, 'اختر ملف تثبيت البرنامج الذي تم تنزيله من هنا.');
        $directory = $this->directory();
        if ($previous) $this->cancel($previous);
        // Expired browser sessions can leave partial uploads. Only remove inactive old parts.
        foreach (glob($directory.'/*.part') ?: [] as $part) {
            if (filemtime($part) < time() - 86400) {
                $id = basename($part, '.part');
                if (preg_match('/\A[0-9a-f]{64}\z/D', $id)) {
                    $lock = fopen($directory.'/'.$id.'.lock', 'c');
                    if ($lock) {
                        if (flock($lock, LOCK_EX | LOCK_NB)) {
                            clearstatcache(true, $part);
                            if (is_file($part) && filemtime($part) < time() - 86400) unlink($part);
                            flock($lock, LOCK_UN);
                        }
                        fclose($lock);
                    }
                }
            }
        }
        $id = bin2hex(random_bytes(32));
        $part = $directory.'/'.$id.'.part';
        $handle = fopen($part, 'x+b');
        abort_unless($handle, 503, 'تعذر بدء الرفع. حاول مرة أخرى.');
        chmod($part, 0600); fclose($handle);
        return ['upload_id'=>$id, 'chunk_bytes'=>self::CHUNK_BYTES];
    }

    public function chunk(string $id, int $offset, string $source): int
    {
        clearstatcache(true, $source);
        $length = is_file($source) ? filesize($source) : 0;
        abort_unless($offset >= 0 && $offset < $this->bytes && $offset % self::CHUNK_BYTES === 0
            && $length === min(self::CHUNK_BYTES, $this->bytes - $offset), 422, 'جزء الملف غير مكتمل. أعد الرفع.');
        return $this->locked($id, function ($part) use ($offset, $source, $length) {
            clearstatcache(true, $part);
            abort_unless(is_file($part) && !is_link($part), 409, 'انتهت جلسة الرفع. اختر الملف مرة أخرى.');
            $handle = fopen($part, 'r+b');
            abort_unless($handle, 503, 'تعذر حفظ الملف.');
            try {
                $size = fstat($handle)['size'];
                abort_unless($offset <= $size && $size <= $this->bytes, 409, 'ترتيب أجزاء الملف غير صحيح.');
                $data = file_get_contents($source);
                abort_unless(is_string($data) && strlen($data) === $length, 422, 'تعذر قراءة جزء الملف.');
                if ($offset < $size) {
                    abort_unless($offset + $length <= $size, 409, 'جزء الملف السابق غير مكتمل.');
                    fseek($handle, $offset);
                    abort_unless(hash_equals(hash('sha256', $data), hash('sha256', fread($handle, $length))), 409, 'تغير جزء الملف أثناء الرفع.');
                    return $offset + $length;
                }
                fseek($handle, $offset);
                $written = 0;
                while ($written < $length) {
                    $next = fwrite($handle, substr($data, $written));
                    if ($next === false || $next === 0) {
                        ftruncate($handle, $offset);
                        abort(503, 'تعذر حفظ الملف. تحقق من المساحة المتاحة.');
                    }
                    $written += $next;
                }
                if (!fflush($handle)) { ftruncate($handle, $offset); abort(503, 'تعذر حفظ الملف.'); }
                return $offset + $length;
            } finally { fclose($handle); }
        });
    }

    public function finish(string $id): void
    {
        $this->locked($id, function ($part) {
            clearstatcache(true, $part);
            // Replaying a completion after a lost response does not publish twice.
            if (!is_file($part)) {
                abort_unless($this->valid($this->target), 409, 'انتهت جلسة الرفع. اختر الملف مرة أخرى.');
                return;
            }
            abort_unless(!is_link($part) && $this->valid($part), 422, 'الملف غير مكتمل أو مختلف عن النسخة المعتمدة. أعد تنزيله ورفعه.');
            abort_unless(!is_link($this->target), 503, 'تعذر إتاحة ملف البرنامج.');
            chmod($part, 0640);
            abort_unless(rename($part, $this->target), 503, 'تعذر إتاحة ملف البرنامج.');
        });
    }

    public function cancel(string $id): void
    {
        $this->locked($id, function ($part) { if (is_file($part) && !is_link($part)) unlink($part); });
    }

    private function valid(string $path): bool
    {
        clearstatcache(true, $path);
        return is_file($path) && !is_link($path) && filesize($path) === $this->bytes
            && hash_equals($this->sha256, hash_file('sha256', $path));
    }

    private function directory(): string
    {
        $parent = dirname($this->target);
        abort_unless(is_dir($parent) && realpath($parent) === $parent && !is_link($parent), 503, 'مجلد البرنامج غير جاهز.');
        $directory = $parent.'/uploads';
        if (!file_exists($directory)) abort_unless(mkdir($directory, 0700), 503, 'تعذر إنشاء مجلد الرفع.');
        abort_unless(is_dir($directory) && !is_link($directory), 503, 'مجلد الرفع غير جاهز.');
        return $directory;
    }

    private function locked(string $id, callable $callback)
    {
        abort_unless(preg_match('/\A[0-9a-f]{64}\z/D', $id), 422, 'جلسة الرفع غير صحيحة.');
        $base = $this->directory().'/'.$id;
        abort_unless(!is_link($base.'.lock'), 503, 'جلسة الرفع غير جاهزة.');
        $lock = fopen($base.'.lock', 'c');
        abort_unless($lock && flock($lock, LOCK_EX), 503, 'تعذر حفظ الملف.');
        chmod($base.'.lock', 0600);
        try { return $callback($base.'.part'); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
