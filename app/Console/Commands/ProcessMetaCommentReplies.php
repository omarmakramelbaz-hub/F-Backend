<?php

namespace App\Console\Commands;

use App\Services\Dashboard\MetaCommentAiDispatchLock;
use App\Services\Dashboard\MetaCommentAiWorker;
use Illuminate\Console\Command;

class ProcessMetaCommentReplies extends Command
{
    protected $signature = 'meta:process-comment-replies {--check : Read-only local readiness, no provider requests} {--once : Run one bounded polling pass} {--budget=30} {--limit=4}';
    protected $description = 'Poll configured public social comments and dispatch replies only under the active Owner policy.';

    public function handle(MetaCommentAiWorker $worker)
    {
        try {
            if ($this->option('check')) {
                $result = $worker->check();
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
                return $result['ready'] && $result['configured'] ? 0 : 1;
            }
            $budget = (string) $this->option('budget'); $limit = (string) $this->option('limit');
            if (!$this->option('once') || !ctype_digit($budget) || (int) $budget < 1 || (int) $budget > 60
                || !ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 4) {
                $this->line('{"reason":"INVALID_OPTIONS"}'); return 1;
            }
            $lock = new MetaCommentAiDispatchLock(null, storage_path('app/meta-comment-ai/worker.lock'));
            $result = $lock->run(static fn () => $worker->run((int) $budget, (int) $limit), 0);
            $this->line(json_encode($result ?? ['busy' => true], JSON_THROW_ON_ERROR));
            return $result !== null && ($result['errors'] ?? 0) > 0 ? 1 : 0;
        } catch (\Throwable $error) {
            $this->line('{"reason":"COMMENT_AI_PROCESS_FAILED"}'); return 1;
        }
    }
}
