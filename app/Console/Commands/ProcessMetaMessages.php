<?php
namespace App\Console\Commands;

use App\Services\Dashboard\MetaInboxConsumer;
use App\Services\Dashboard\MetaOrderWorkflow;
use Illuminate\Console\Command;

class ProcessMetaMessages extends Command
{
    protected $signature='meta:process-messages {--limit=10}';
    protected $description='Project Meta messages and process newly confirmed orders using configured channel boundaries.';
    public function handle(MetaInboxConsumer $inbox,MetaOrderWorkflow $orders)
    {
        $limit=(string)$this->option('limit');
        if (!ctype_digit($limit)||(int)$limit<1||(int)$limit>20) { $this->line('{"error":"INVALID_LIMIT"}'); return 1; }
        try {
            $lockPath=storage_path('app/private/meta-messaging-worker.lock');
            if (!is_dir(dirname($lockPath))) mkdir(dirname($lockPath),0700,true);
            $lock=fopen($lockPath,'c');
            if (!$lock||!flock($lock,LOCK_EX|LOCK_NB)) { $this->line('{"busy":1}'); return 0; }
            try {
                $capture=$inbox->consume(500);
                $result=$capture['errors']>0?['capture_failed'=>1]:$orders->process((int)$limit);
                $this->line(json_encode(['capture'=>$capture,'orders'=>$result],JSON_THROW_ON_ERROR));
                return $capture['errors']>0||($result['errors']??0)>0?1:0;
            } finally { flock($lock,LOCK_UN); fclose($lock); }
        } catch (\Throwable $error) { $this->line('{"error":"META_PROCESS_FAILED"}'); return 1; }
    }
}
