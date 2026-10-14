<?php
namespace Illuminate\Console {
    class Command {
        public array $lines=[]; public $limit='5';
        public function option($name) { if ($name!=='limit') throw new \RuntimeException(); return $this->limit; }
        public function line($value): void { $this->lines[]=$value; }
    }
}
namespace App\Services\Dashboard {
    class MetaAiControl {
        public array $states=[]; public array $calls=[];
        public function gate($channel): array { throw new \RuntimeException('REPLY_GATE_CANNOT_AUTHORIZE_THE_SHARED_WORKER'); }
        public function processingGate($channel): array {
            $this->calls[]=$channel; $state=$this->states[$channel]??false;
            if ($state==='THROW') throw new \RuntimeException('PRIVATE_CONTROL_EXCEPTION');
            return ['effective'=>$state,'actor'=>'PRIVATE_OWNER','token'=>'PRIVATE_TOKEN'];
        }
    }
    class WhatsAppOrderWorkflow {
        public array $calls=[]; public bool $throw=false;
        public array $metrics=['threads_seen'=>5,'baselined'=>1,'analyzed'=>2,'review_required'=>1,'dispatched'=>1,'errors'=>0,'customer'=>'PRIVATE_CUSTOMER',
            'recipient'=>'01000000000','token'=>'PRIVATE_TOKEN','scanned'=>'PRIVATE_COUNT','review'=>-1];
        public function process(int $limit=10): array {
            $this->calls[]=$limit; if ($this->throw) throw new \RuntimeException('PRIVATE_ORDER_EXCEPTION'); return $this->metrics;
        }
    }
    class MetaOrderWorkflow extends WhatsAppOrderWorkflow {}
}
namespace {
    require dirname(__DIR__).'/app/Console/Commands/ProcessMetaAiMessages.php';
    use App\Console\Commands\ProcessMetaAiMessages;
    use App\Services\Dashboard\MetaAiControl;
    use App\Services\Dashboard\MetaOrderWorkflow;
    use App\Services\Dashboard\WhatsAppOrderWorkflow;
    $checks=0;
    function workerExpect($actual,$expected,string $label): void {
        $GLOBALS['checks']++; if ($actual!==$expected) throw new \RuntimeException('FAILED: '.$label);
    }
    function workerRun(array $states=[],string $limit='5',bool $failWhatsApp=false,bool $failMeta=false): array {
        $control=new MetaAiControl();$control->states=$states;
        $wa=new WhatsAppOrderWorkflow();$wa->throw=$failWhatsApp;
        $meta=new MetaOrderWorkflow();$meta->throw=$failMeta;
        $command=new ProcessMetaAiMessages();$command->limit=$limit;
        $exit=$command->handle($control,$wa,$meta);
        workerExpect(count($command->lines),1,'one bounded machine-readable result');
        $text=$command->lines[0];
        foreach (['PRIVATE_','01000000000','recipient','token','customer'] as $private)
            workerExpect(str_contains($text,$private),false,'worker result contains no private values or exception text');
        $data=json_decode($text,true,16,JSON_THROW_ON_ERROR);
        return [$exit,$data,$control,$wa,$meta];
    }
    [$exit,$data,$control,$wa,$meta]=workerRun();
    workerExpect([$exit,$data,$wa->calls,$meta->calls],[0,['whatsapp'=>['state'=>'DISABLED'],'meta'=>['state'=>'DISABLED']],[],[]],
        'fresh disabled default never executes either order workflow');
    workerExpect($control->calls,['whatsapp','messenger','instagram'],'both Meta channels and WhatsApp are checked freshly');
    foreach (['whatsapp','messenger','instagram'] as $channel) {
        [$exit,$data,,$wa,$meta]=workerRun([$channel=>true]);
        workerExpect([$exit,$wa->calls,$meta->calls],[0,$channel==='whatsapp'?[5]:[],$channel==='whatsapp'?[]:[5]],
            $channel.' independently selects the guarded platform family');
        workerExpect($data[$channel==='whatsapp'?'whatsapp':'meta']['counts'],['threads_seen'=>5,'baselined'=>1,'analyzed'=>2,'review_required'=>1,'dispatched'=>1,'errors'=>0],
            'only known nonnegative integer metrics leave the worker');
    }
    [$exit,$data,,$wa,$meta]=workerRun(['messenger'=>true,'instagram'=>true,'whatsapp'=>true],'20');
    workerExpect([$exit,$wa->calls,$meta->calls],[0,[20],[20]],'two enabled Meta channels share one bounded Meta process pass');
    [$exit,$data,,$wa,$meta]=workerRun(['whatsapp'=>1,'messenger'=>'yes','instagram'=>null]);
    workerExpect([$exit,$wa->calls,$meta->calls],[0,[],[]],'truthy malformed gate values never authorize processing');
    [$exit,$data,,$wa,$meta]=workerRun(['whatsapp'=>true,'messenger'=>true],'5',true);
    workerExpect([$exit,$data['whatsapp']['state'],$meta->calls],[1,'ERROR',[5]],'one platform failure does not prevent another authorized platform');
    [$exit,$data,,$wa,$meta]=workerRun(['whatsapp'=>true,'messenger'=>'THROW'],'5');
    workerExpect([$exit,$data['meta']['state'],$wa->calls],[1,'ERROR',[5]],'a failed control read fails closed for that family');
    foreach (['0','21','-1','01','1.0',' 5','5 ','999','PRIVATE_LIMIT'] as $limit) {
        [$exit,$data,$control,$wa,$meta]=workerRun(['whatsapp'=>true],$limit);
        workerExpect([$exit,$data,$control->calls,$wa->calls,$meta->calls],[1,['error'=>'INVALID_LIMIT'],[],[],[]],
            'invalid limit cannot touch controls or workflows');
    }
    $unit=file_get_contents(dirname(__DIR__).'/deployment/systemd/fasakhansta-meta-ai.service');
    workerExpect(str_contains($unit,"Type=oneshot\n"),true,'systemd prevents overlap within the polling worker');
    workerExpect(str_contains($unit,"User=fasakha\n"),true,'the new worker never runs application processing as root');
    workerExpect(str_contains($unit,'artisan meta:process-ai --limit=5'),true,'the new worker invokes only the bounded fresh-gate command');
    workerExpect(str_contains($unit,'configure'),false,'the polling worker cannot activate or rebaseline orders');
    echo 'META_AI_WORKER_COMMAND_TESTS='.$checks." PASS\n";
}
