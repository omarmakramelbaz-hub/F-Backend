<?php

namespace App\Console\Commands;

use App\Services\Dashboard\MetaAiControl;
use App\Services\Dashboard\MetaOrderWorkflow;
use App\Services\Dashboard\WhatsAppOrderWorkflow;
use Illuminate\Console\Command;

/** Existing capture workers retain their native, bounded projection duties. */
class ProcessMetaAiMessages extends Command
{
    protected $signature='meta:process-ai {--limit=5 : Maximum conversations per platform family (1-20)}';
    protected $description='Poll the fresh owner-controlled AI policy for WhatsApp, Messenger and Instagram.';

    public function handle(MetaAiControl $control,WhatsAppOrderWorkflow $whatsapp,MetaOrderWorkflow $meta)
    {
        $limit=(string)$this->option('limit');
        if (!preg_match('/\A[1-9][0-9]?\z/',$limit)||(int)$limit>20) {
            $this->line('{"error":"INVALID_LIMIT"}'); return 1;
        }
        $result=['whatsapp'=>['state'=>'DISABLED'],'meta'=>['state'=>'DISABLED']];
        $failed=false;
        foreach (['whatsapp'=>[$whatsapp,['whatsapp']],'meta'=>[$meta,['messenger','instagram']]] as $family=>[$workflow,$channels]) {
            try {
                $enabled=false;
                foreach ($channels as $channel) {
                    $gate=$control->processingGate($channel);
                    if (($gate['effective']??null)===true) $enabled=true;
                }
                if (!$enabled) continue;
                // Each workflow rechecks the fresh revision, native input bounds,
                // actor, schedule, platform window and durable reply claims.
                $metrics=$workflow->process((int)$limit);
                $safe=[];
                foreach (['threads_seen','baselined','analyzed','review_required','dispatched','replayed','errors',
                    'disabled','capture_pending','deferred'] as $name)
                    if (is_int($metrics[$name]??null)&&$metrics[$name]>=0) $safe[$name]=$metrics[$name];
                $result[$family]=['state'=>'PROCESSED','counts'=>$safe];
                if (($safe['errors']??0)>0) $failed=true;
            } catch (\Throwable $ignore) {
                $result[$family]=['state'=>'ERROR']; $failed=true;
            }
        }
        $this->line(json_encode($result,JSON_THROW_ON_ERROR));
        return $failed?1:0;
    }
}
