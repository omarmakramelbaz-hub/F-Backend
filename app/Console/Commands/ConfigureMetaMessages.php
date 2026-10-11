<?php
namespace App\Console\Commands;

use App\Services\Dashboard\MetaInboxAccess;
use App\Services\Dashboard\MetaInboxConsumer;
use App\Services\Dashboard\TakeawayAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConfigureMetaMessages extends Command
{
    protected $signature='meta:configure {--activate-orders : Start processing new confirmed orders using the current WhatsApp order actor and branch settings}';
    protected $description='Configure native Messenger/Instagram messaging without changing any external Agent settings.';
    public function handle()
    {
        $path=(string)env('META_MESSAGING_CONFIG_PATH',storage_path('app/private/meta-messaging.json'));
        try {
            if (is_link($path)||strpos(realpath(dirname($path))?:dirname($path),public_path())===0) throw new \RuntimeException();
            $saved=is_file($path)?json_decode(file_get_contents($path),true):[];
            $saved=is_array($saved)?$saved:[];
            if (!$this->option('activate-orders')) {
                $secret=$this->secret('Meta App Secret (hidden; Enter keeps the saved value)');
                $saved['app_secret']=$secret!==null&&$secret!==''?$secret:($saved['app_secret']??'');
                if (!preg_match('/\A[a-f0-9]{32}\z/i',$saved['app_secret'])) { $this->error('Invalid App Secret'); return 1; }
                $saved['verify_token']=$saved['verify_token']??bin2hex(random_bytes(24));
                $accounts=$saved['accounts']??[];
                foreach (['messenger'=>'Facebook Page ID','instagram'=>'Instagram professional account ID'] as $channel=>$label) {
                    $id=trim((string)$this->ask($label.' (Enter skips)'));
                    if ($id==='') continue;
                    if (!\App\Support\MetaMessagingProtocol::id($id)) { $this->error('Invalid account ID'); return 1; }
                    $token=$this->secret($channel.' access token (hidden)');
                    if (!is_string($token)||strlen($token)<16||strlen($token)>4096||preg_match('/\s/',$token)) { $this->error('Invalid token'); return 1; }
                    $login=$channel==='instagram'?$this->choice('Instagram API login method',['facebook','instagram'],0):'facebook';
                    $accounts=array_values(array_filter($accounts,fn($a)=>($a['channel']??null)!==$channel||($a['account_id']??null)!==$id));
                    $accounts[]=['channel'=>$channel,'account_id'=>$id,'access_token'=>$token,'login'=>$login,
                        'label'=>(string)$this->ask($channel.' display name','Fasakhansta')];
                }
                if (!$accounts) { $this->error('At least one account is required'); return 1; }
                $saved['accounts']=$accounts; $saved['api_version']='v25.0';
            } else {
                if (empty($saved['accounts'])) { $this->error('Configure accounts first'); return 1; }
                $id=config('whatsapp_orders.automation_actor_id'); $branches=config('whatsapp_orders.allowed_branch_ids',[]);
                $model=config('whatsapp_orders.model'); $key=config('whatsapp_orders.api_key');
                if (!is_numeric($id)||!$branches||!is_string($model)||$model===''||!is_string($key)||$key==='') {
                    $this->error('Configure the existing WhatsApp order automation actor, branch scope and extraction credentials first'); return 1;
                }
                $actor=app(MetaInboxAccess::class)->actor(\App\Models\User::withoutGlobalScopes()->find((int)$id));
                foreach ($branches as $branch) app(TakeawayAccess::class)->branch('f:'.$branch,$actor);
                // The root helper stops the dedicated timer. The lock also excludes manual workers.
                if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true);
                $lock=fopen(dirname($path).'/meta-messaging-worker.lock','c');
                if (!$lock||!flock($lock,LOCK_EX|LOCK_NB)) { $this->error('Meta worker is running'); return 1; }
                try {
                    $pending=(int)DB::table('meta_webhook_events')->whereNull('processed_at')->count();
                    if ($pending>5000) { $this->error('Process the pending capture before activation'); return 1; }
                    for ($i=0;$i<10;$i++) {
                        $capture=app(MetaInboxConsumer::class)->consume(500);
                        if ($capture['errors']>0||$capture['quarantined_events']>0) { $this->error('Capture needs review before activation'); return 1; }
                        if ($capture['events_processed']===0) break;
                    }
                    if (DB::table('meta_inbox_ingestion_failures')->exists()) { $this->error('Capture needs review before activation'); return 1; }
                    $saved['orders']=['enabled'=>true,'mode'=>'auto','automation_actor_id'=>(int)$id,
                        'allowed_branch_ids'=>array_values($branches),'activation_message_id'=>(int)DB::table('meta_inbox_messages')->max('id'),
                        'activation_event_id'=>(int)DB::table('meta_webhook_events')->max('id'),'activated_at'=>gmdate('Y-m-d H:i:s')];
                    $this->write($path,$saved);
                } finally { flock($lock,LOCK_UN); fclose($lock); }
                $this->info('New confirmed Meta orders activated. Rebuild configuration cache, then start the dedicated timer.'); return 0;
            }
            $this->write($path,$saved);
            $this->info('Callback URL: '.url('/api/meta/messaging/webhook'));
            $this->info('Verify token: '.$saved['verify_token']);
            $this->info('Credentials saved. Rebuild configuration cache. The Business Agent settings were not changed.'); return 0;
        } catch (\Throwable $error) { $this->error('Meta configuration failed; no credentials were printed.'); return 1; }
    }
    private function write(string $path,array $data): void
    {
        if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true);
        $tmp=tempnam(dirname($path),'.meta-'); chmod($tmp,0600);
        try {
            if (file_put_contents($tmp,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX)===false||!rename($tmp,$path)) throw new \RuntimeException();
        } finally { if (is_file($tmp)) unlink($tmp); }
    }
}
