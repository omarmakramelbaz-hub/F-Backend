<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Repeats one artisan command inside a single long-running process.
 *
 * The WhatsApp and Meta pollers used to start a fresh PHP process every few seconds from systemd timers, and each
 * start boots the whole application. Here the application boots once and the command runs again after a pause,
 * the same pause the timers used. The process exits on its own so systemd starts a fresh one:
 * - after --max-runtime seconds,
 * - when it uses more than --max-memory MB,
 * - after a deploy (php artisan queue:restart),
 * - on SIGTERM, once the current run finishes.
 */
class RunCommandLoop extends Command
{
    protected $signature = 'loop:run
        {target : The artisan command line to repeat, with its options}
        {--sleep=5 : Seconds to wait after each run}
        {--max-runtime=900 : Seconds before the process exits so systemd starts a fresh one}
        {--max-memory=192 : Megabytes of memory before the process exits}
        {--max-runs=0 : Stop after this many runs (0 = no limit)}';

    protected $description = 'Repeat one artisan command in a single long-running process';

    private bool $stopping = false;

    public function handle(): int
    {
        $target = trim((string) $this->argument('target'));
        if ($target === '' || str_starts_with($target, 'loop:run')) {
            $this->error('A target command is required.');
            return 1;
        }

        $sleep = max(0, (int) $this->option('sleep'));
        $maxRuntime = max(1, (int) $this->option('max-runtime'));
        $maxMemory = max(32, (int) $this->option('max-memory')) * 1024 * 1024;
        $maxRuns = max(0, (int) $this->option('max-runs'));

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stopping = true);
            pcntl_signal(SIGINT, fn () => $this->stopping = true);
        }

        $startedAt = time();
        $restartMark = $this->restartMark();
        $runs = 0;

        while (true) {
            try {
                Artisan::call($target);
                $output = Artisan::output();
                if ($output !== '') {
                    $this->getOutput()->write($output);
                }
            } catch (Throwable $error) {
                // One failed run must not stop the poller; the next run tries again.
                report($error);
                $this->error($target.' failed: '.class_basename($error));
            }

            $runs++;
            if ($this->shouldExit($runs, $maxRuns, $startedAt, $maxRuntime, $maxMemory, $restartMark)) {
                return 0;
            }

            // Sleep in one-second steps so SIGTERM stops the loop promptly.
            for ($i = 0; $i < $sleep && !$this->stopping; $i++) {
                sleep(1);
            }
            if ($this->stopping) {
                return 0;
            }
        }
    }

    private function shouldExit(int $runs, int $maxRuns, int $startedAt, int $maxRuntime, int $maxMemory, $restartMark): bool
    {
        return $this->stopping
            || ($maxRuns > 0 && $runs >= $maxRuns)
            || time() - $startedAt >= $maxRuntime
            || memory_get_usage(true) >= $maxMemory
            || $this->restartMark() !== $restartMark;
    }

    private function restartMark()
    {
        try {
            return Cache::get('illuminate:queue:restart');
        } catch (Throwable $error) {
            return null;
        }
    }
}
