<?php
namespace App\Services\Dashboard;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\{Crypt,DB,Validator};

/**
 * Permanent immutable print intents committed with original journal business work.
 * Native claims and burns live separately in DesktopDashboardPrintEffects.
 */
final class DesktopDashboardPrintLedger
{
    public const ROUTES=['takeaway.checkout','dining.settle','phone-orders.settle','phone-orders.save','phone-orders.action','dining.action','branch-shifts.close','phone-orders.finish-batch'];

    public function __construct(private DesktopDashboardPrintLedgerSchema $schema, private DesktopDashboardPrintAuthority $printAuthority) {}

    public function active(string $device): bool
    {
        if(config('desktop_dashboard.local')!==true||!\Illuminate\Support\Facades\Schema::hasTable('desktop_dashboard_local_state'))return false;
        $coverage=DB::table('desktop_dashboard_local_state')->where('device_id',$device)->value('coverage');
        return (json_decode($coverage??'{}',true)['print_protocol']??null)===1;
    }
    /** Must precede generation-state/business locks, including refresh and non-print writes. */
    public function fence(string $device): void
    {
        if(!$this->active($device))return;
        abort_unless(DB::transactionLevel()>0,503);$this->scope($this->schema->connection(),$device);
    }
    public function verifyEnrollment(string $device): void
    {
        $db=$this->schema->connection();abort_unless($db->transactionLevel()>0,503);$this->schema->verify($db);
        $scope=$this->scope($db,$device);$fresh=$this->enrollment($db,$device,true);$this->matchingEnrollment($scope,$fresh);
        if(($fresh->workspace_protocol??null)!==2)$this->printAuthority->actor((int)$fresh->actor_id);
    }

    /** Revalidate current authority before original SQL work or returning a cached print-producing reply. */
    public function guardJournal(string $device,int $actor,string $branch,string $route): void
    {
        $db=$this->schema->connection();$this->authority($db,$device,$actor,$branch,true);
        $current=$this->printAuthority->actor($actor);
        if($route==='branch-shifts.close')abort_unless($this->printAuthority->canClose($current),403);
    }

    /** Called inside the original journal transaction, after its immutable business row exists. */
    public function captureJournal(string $device,string $command,int $actor,string $branch): array
    {
        $db=$this->schema->connection();abort_unless($db->transactionLevel()>0,503);$this->schema->verify($db);
        [$scope,$state]=$this->authority($db,$device,$actor,$branch,true);
        $entry=$db->table('desktop_dashboard_commands')->where('device_id',$device)->where('command_id',$command)->lockForUpdate()->first();abort_unless($entry,409);
        $existing=$db->table($this->schema->table('print_ledger_commands'))->where('device_id',$device)->where('command_id',$command)->first();
        if($existing){abort_unless($existing->origin_database===$db->getDatabaseName()&&$existing->snapshot_id===$state->snapshot_id,409);return $this->storedIntents($db,$existing);}
        $payload=json_decode(Crypt::decryptString($entry->command_cipher),true,512,JSON_THROW_ON_ERROR);abort_if(isset($payload['envelope']),409);
        $intents=json_decode($this->json($this->derive($db,$entry,$payload,$scope,$state)),true,512,JSON_THROW_ON_ERROR);
        // A call-center kitchen remains owned by the central branch receiver.
        $current=$this->printAuthority->actor($actor);
        $intents=array_values(array_filter($intents,fn($intent)=>in_array($intent['kind'],['sale_receipt','bill_receipt','dining_kitchen','delivery_batch'],true)||($intent['kind']==='shift_report'?$this->printAuthority->canClose($current):$this->printAuthority->receiverBranch($current)===$branch)));
        $db->table($this->schema->table('print_ledger_commands'))->insert(['device_id'=>$device,'command_id'=>$command,'enrollment_id'=>$scope->enrollment_id,'actor_id'=>$actor,
            'branch'=>$branch,'route_name'=>$entry->route_name,'request_hash'=>$entry->request_hash,'origin_database'=>$db->getDatabaseName(),
            'snapshot_id'=>$state->snapshot_id,'schema_hash'=>$state->schema_hash,'intents_hash'=>$this->hash($intents),'created_at'=>now('UTC')]);
        foreach($intents as $intent)$db->table($this->schema->table('print_ledger_intents'))->insert(['effect_id'=>$intent['effect_id'],'device_id'=>$device,'enrollment_id'=>$scope->enrollment_id,
            'command_id'=>$command,'kind'=>$intent['kind'],'slot'=>$intent['slot'],'actor_id'=>$actor,'branch'=>$branch,'revision'=>$intent['revision'],'descriptor_hash'=>$this->hash($intent),
            'descriptor_cipher'=>Crypt::encryptString($this->json($intent)),'lifecycle_state'=>'intent','created_at'=>now('UTC')]);
        return $intents;
    }
    public function ownership(string $device,string $command): array
    {
        $db=$this->schema->connection();$row=$db->table($this->schema->table('print_ledger_commands'))->where('device_id',$device)->where('command_id',$command)->first();
        if(!$row)return [];
        return array_map(fn($intent)=>array_intersect_key($intent,array_flip(['effect_id','enrollment_id','origin_command_id','kind','slot','revision','local_references','snapshot_sha256'])),$this->storedIntents($db,$row));
    }

    /** Requires authoritative persisted enrollment evidence transported by bootstrap. */
    public function bind(string $device): string
    {
        $db=$this->schema->connection();$this->identity($device);
        abort_unless($db->transactionLevel()===0&&!$db->getPdo()->inTransaction(),409);$this->schema->verify($db);
        return $db->transaction(function()use($db,$device){
            $fresh=$this->enrollment($db,$device,false);$identity=$this->enrollmentIdentity($fresh);
            $db->table($this->schema->table('print_ledger_scopes'))->insertOrIgnore([
                'device_id'=>$device,'enrollment_id'=>$identity,'actor_id'=>$fresh->actor_id,
                'enrollment_cipher'=>Crypt::encryptString($this->json($this->enrollmentFacts($fresh))),
                'schema_version'=>DesktopDashboardPrintLedgerSchema::VERSION,'created_at'=>now('UTC'),
            ]);
            $scope=$this->scope($db,$device);
            $fresh=$this->enrollment($db,$device,true);
            $this->matchingEnrollment($scope,$fresh);
            if(($fresh->workspace_protocol??null)!==2)$this->printAuthority->actor((int)$fresh->actor_id);
            return $scope->enrollment_id;
        });
    }

    /**
     * Owns the OUTER transaction, fixed-scope lock and original SQL-only business work.
     * The callback must call the existing business journal on this connection. It must
     * not switch connections, perform DDL, invoke providers, or produce native effects.
     */
    public function atomic(string $device,string $command,string $route,int $actor,string $branch,callable $work): array
    {
        $db=$this->schema->connection();$this->identity($device);
        Validator::make(compact('command','route','actor','branch'),[
            'command'=>'required|uuid','route'=>'required|in:'.implode(',',self::ROUTES),'actor'=>'required|integer|min:1',
            'branch'=>['required','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],
        ])->validate();
        abort_unless($db->transactionLevel()===0&&!$db->getPdo()->inTransaction(),409,'The intent wrapper must own its transaction.');
        $this->schema->verify($db);$pdo=$db->getPdo();$database=$db->getDatabaseName();
        abort_if($db->selectOne('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE=? AND ENGINE<>? LIMIT 1',[$database,'BASE TABLE','InnoDB']),503,'The private generation must use transactional tables.');
        try { return $db->transaction(function()use($db,$pdo,$database,$device,$command,$route,$actor,$branch,$work){
            [$scope,$state]=$this->authority($db,$device,$actor,$branch,true);
            $old=$db->table($this->schema->table('print_ledger_commands'))->where('device_id',$device)->where('command_id',$command)->first();
            $before=$db->table('desktop_dashboard_commands')->where('device_id',$device)->where('command_id',$command)->first();
            // Never adopt old possibly printed work retroactively, or replay old IDs
            // inside a new generation that happens to reuse the same integers.
            abort_if($before&&!$old,409,'Historical commands cannot acquire new print intent.');
            if($old)abort_unless($before&&$old->origin_database===$database&&$old->snapshot_id===$state->snapshot_id
                &&$old->schema_hash===$state->schema_hash,409,'The original intent belongs to another generation.');
            $result=$work();
            abort_unless($this->schema->connection()===$db&&$db->getPdo()===$pdo&&$db->transactionLevel()===1&&$pdo->inTransaction()
                &&$db->selectOne('SELECT DATABASE() AS name')->name===$database,503,'Business work changed its transaction connection.');
            $entry=$db->table('desktop_dashboard_commands')->where('device_id',$device)->where('command_id',$command)->lockForUpdate()->first();
            abort_unless($entry&&(int)$entry->actor_id===$actor&&$entry->route_name===$route,409,'The original business journal did not commit matching work.');
            $payload=json_decode(Crypt::decryptString($entry->command_cipher),true,512,JSON_THROW_ON_ERROR);
            abort_if(isset($payload['envelope']),409,'A central replay cannot create a local effect.');
            abort_unless(($payload['values']['idempotency_key']??null)===$command&&($payload['values']['branch']??null)===$branch,409);
            $saved=json_decode(Crypt::decryptString($entry->local_result_cipher),true,512,JSON_THROW_ON_ERROR);
            $saved=isset($saved['format'],$saved['result'])?$saved['result']:$saved;
            abort_unless(is_array($result)&&hash_equals($this->hash($saved),$this->hash($result)),409,'Business result differs from its durable journal.');
            $intents=json_decode($this->json($this->derive($db,$entry,$payload,$scope,$state)),true,512,JSON_THROW_ON_ERROR);
            $intentsHash=$this->hash($intents);
            if($old){
                abort_unless($old->route_name===$route&&(int)$old->actor_id===$actor&&$old->branch===$branch
                    &&$old->enrollment_id===$scope->enrollment_id&&hash_equals($old->request_hash,$entry->request_hash)
                    &&hash_equals($old->intents_hash,$intentsHash),409,'Existing intent is immutable.');
                $this->storedIntents($db,$old);
            }else{
                $db->table($this->schema->table('print_ledger_commands'))->insert([
                    'device_id'=>$device,'command_id'=>$command,'enrollment_id'=>$scope->enrollment_id,'actor_id'=>$actor,
                    'branch'=>$branch,'route_name'=>$route,'request_hash'=>$entry->request_hash,'origin_database'=>$database,
                    'snapshot_id'=>$state->snapshot_id,'schema_hash'=>$state->schema_hash,'intents_hash'=>$intentsHash,'created_at'=>now('UTC'),
                ]);
                foreach($intents as $intent)$db->table($this->schema->table('print_ledger_intents'))->insert([
                    'effect_id'=>$intent['effect_id'],'device_id'=>$device,'enrollment_id'=>$scope->enrollment_id,'command_id'=>$command,
                    'kind'=>$intent['kind'],'slot'=>$intent['slot'],'actor_id'=>$actor,'branch'=>$branch,'revision'=>$intent['revision'],
                    'descriptor_hash'=>$this->hash($intent),'descriptor_cipher'=>Crypt::encryptString($this->json($intent)),
                    'lifecycle_state'=>'intent','created_at'=>now('UTC'),
                ]);
            }
            return ['result'=>$result,'intents'=>$intents];
        }); } catch (\Throwable $error) {
            // Laravel 8 may itself throw while rolling back a callback's raw PDO
            // commit/rollback. Discard that desynchronized connection; never return
            // captured success or leave a phantom transaction for the next caller.
            if($db->transactionLevel()>0&&!$pdo->inTransaction()){
                DB::purge($db->getName());
                abort(503,'The SQL-only callback ended its transaction outside the ledger.');
            }
            throw $error;
        }
    }

    /** Permanent immutable lookup, including after replacement generation IDs drift. */
    public function read(string $device,string $command,int $actor,string $branch): array
    {
        $db=$this->schema->connection();$this->identity($device);$this->schema->verify($db);
        Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();
        abort_unless($db->transactionLevel()===0&&!$db->getPdo()->inTransaction(),409);
        return $db->transaction(function()use($db,$device,$command,$actor,$branch){
            [$scope]=$this->authority($db,$device,$actor,$branch,false);
            $row=$db->table($this->schema->table('print_ledger_commands'))->where('device_id',$device)->where('command_id',$command)->first();
            abort_unless($row&&(int)$row->actor_id===$actor&&$row->branch===$branch&&$row->enrollment_id===$scope->enrollment_id,404);
            return $this->storedIntents($db,$row);
        });
    }

    public function readInTransaction(string $device,string $command,int $actor,string $branch): array
    {
        $db=$this->schema->connection();abort_unless($db->transactionLevel()>0,503);
        [$scope]=$this->authority($db,$device,$actor,$branch,false);
        $row=$db->table($this->schema->table('print_ledger_commands'))->where('device_id',$device)->where('command_id',$command)->first();
        abort_unless($row&&(int)$row->actor_id===$actor&&$row->branch===$branch&&$row->enrollment_id===$scope->enrollment_id,404);
        return $this->storedIntents($db,$row);
    }

    /** Internal native metadata lookup: old actor evidence is retained without granting a new print. */
    public function readImmutableInTransaction(string $device,string $command,int $actor,string $branch): array
    {
        $db=$this->schema->connection();abort_unless($db->transactionLevel()>0
            && app(DesktopDashboardWorkspacePrintAuthority::class)->selected(),403);
        $scope=$this->scope($db,$device);$root=$this->enrollment($db,$device,true);$this->matchingEnrollment($scope,$root);
        $row=$db->table($this->schema->table('print_ledger_commands'))->where('device_id',$device)->where('command_id',$command)->first();
        abort_unless($row&&(int)$row->actor_id===$actor&&$row->branch===$branch&&$row->enrollment_id===$scope->enrollment_id,404);
        $evidence=$db->table(DesktopDashboardWorkspaceCommandAuthorities::TABLE)->where('workspace_id',$device)->where('command_id',$command)->first();
        abort_unless($evidence&&(int)$evidence->actor_id===$actor&&hash_equals($row->request_hash,$evidence->request_hash),503);
        app(DesktopDashboardWorkspaceCommandAuthorities::class)->assertHistoryRow((array)$evidence,$device,$scope->enrollment_id);
        return $this->storedIntents($db,$row);
    }

    private function authority(Connection $db,string $device,int $actor,string $branch,bool $write): array
    {
        $scope=$this->scope($db,$device);
        if(app(DesktopDashboardWorkspacePrintAuthority::class)->selected()) {
            $workspace=app(DesktopDashboardWorkspacePrintAuthority::class);
            $facade=$workspace->actor($device,$actor,$branch,$write);
            $fresh=$workspace->root($device,true);$this->matchingEnrollment($scope,$fresh);
            $this->printAuthority->branch($branch,$this->printAuthority->actor($actor));
            return [$scope,$facade->localState()];
        }
        $state=$db->table('desktop_dashboard_local_state')->where('device_id',$device)->lockForUpdate()->first();
        abort_unless($state&&(int)$state->actor_id===$actor,403);
        if($write)abort_unless($state->state==='ready',409);
        $fresh=$this->enrollment($db,$device,true);$this->matchingEnrollment($scope,$fresh);
        abort_unless((int)$fresh->actor_id===$actor,403);
        $current=$this->printAuthority->actor((int)$fresh->actor_id);
        abort_unless(in_array($branch,json_decode($fresh->branches,true,512,JSON_THROW_ON_ERROR),true),403);
        abort_unless(in_array($branch,json_decode($state->branches,true,512,JSON_THROW_ON_ERROR),true),403);
        $this->printAuthority->branch($branch,$current);
        return [$scope,$state];
    }

    private function enrollment(Connection $db,string $device,bool $lock): object
    {
        if(app(DesktopDashboardWorkspacePrintAuthority::class)->selected())
            return app(DesktopDashboardWorkspacePrintAuthority::class)->root($device,$lock);
        $query=$db->table('desktop_dashboard_devices')->where('id',$device);if($lock)$query->lockForUpdate();
        $row=$query->first();abort_unless($row&&$row->enabled,401,'Authoritative enrollment evidence is unavailable.');
        return $row;
    }
    private function scope(Connection $db,string $device): object
    {
        $row=$db->table($this->schema->table('print_ledger_scopes'))->where('device_id',$device)->lockForUpdate()->first();
        abort_unless($row&&(int)$row->schema_version===DesktopDashboardPrintLedgerSchema::VERSION,503,'Private print scope is not provisioned.');return $row;
    }
    private function matchingEnrollment(object $scope,object $fresh): void
    {
        abort_unless((int)$scope->actor_id===(int)$fresh->actor_id&&hash_equals($scope->enrollment_id,$this->enrollmentIdentity($fresh))
            &&hash_equals($scope->enrollment_id,$this->hash(json_decode(Crypt::decryptString($scope->enrollment_cipher),true,512,JSON_THROW_ON_ERROR))),409,'Persisted enrollment changed; old effects cannot be adopted.');
    }
    private function enrollmentFacts(object $row): array
    {
        if(($row->workspace_protocol??null)===2)return app(DesktopDashboardWorkspacePrintAuthority::class)->facts($row);
        $branches=json_decode($row->branches,true,512,JSON_THROW_ON_ERROR);
        Validator::make(['id'=>$row->id,'actor'=>$row->actor_id,'hash'=>$row->enrollment_hash,'at'=>$row->enrolled_at,'branches'=>$branches],[
            'id'=>'required|uuid','actor'=>'required|integer|min:1','hash'=>'required|regex:/^[a-f0-9]{64}$/D','at'=>'required|date',
            'branches'=>'required|array|min:1','branches.*'=>['required','distinct','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],
        ])->validate();sort($branches,SORT_STRING);
        return ['device_id'=>$row->id,'actor_id'=>(int)$row->actor_id,'enrollment_hash'=>$row->enrollment_hash,'enrolled_at'=>(string)$row->enrolled_at,'branches'=>$branches];
    }
    private function enrollmentIdentity(object $row): string {return $this->hash($this->enrollmentFacts($row));}
    private function identity(string $device): void
    {
        Validator::make(['device'=>$device],['device'=>'required|uuid'])->validate();
        abort_unless($device===(string)config('desktop_dashboard.device_id'),403);
    }

    private function derive(Connection $db,object $entry,array $payload,object $scope,object $state): array
    {
        $base=['protocol'=>1,'device_id'=>$entry->device_id,'enrollment_id'=>$scope->enrollment_id,'origin_command_id'=>$entry->command_id,
            'actor_id'=>(int)$entry->actor_id,'branch'=>$payload['values']['branch'],'origin_route'=>$entry->route_name,
            'request_hash'=>$entry->request_hash,'origin_database'=>$db->getDatabaseName(),'snapshot_id'=>$state->snapshot_id,'schema_hash'=>$state->schema_hash];
        $route=$entry->route_name;$intents=[];
        if($route==='phone-orders.finish-batch'){
            $batch=$this->one($db->table('phone_delivery_batches')->where('branch',$base['branch'])->where('actor_id',$entry->actor_id)->where('request_key',$entry->command_id));
            $snapshot=json_decode($batch->snapshot,true,512,JSON_THROW_ON_ERROR)+['id'=>(int)$batch->id];$refs=['delivery_batch'=>(int)$batch->id];$lines=collect($snapshot['items'])->keyBy('ticket_id');
            foreach($payload['values']['items'] as $index=>$item){$id=$item['id'];if(is_array($id))$id=$id['$desktop_ref']['local_id']??null;abort_unless(isset($lines[$id]),409);$refs['receipt.'.$index]=(int)$lines[$id]['order_id'];}
            $intents[]=$this->intent($base,'delivery_batch',1,$refs,$snapshot);
        }elseif(in_array($route,['takeaway.checkout','dining.settle','phone-orders.settle'],true)){
            $order=$this->one($db->table('takeaway_orders')->where('branch',$base['branch'])->where('actor_id',$entry->actor_id)->where('request_key',$entry->command_id));
            $sale=$this->one($db->table('takeaway_till_entries')->where('order_id',$order->id)->where('kind','sale')->where('branch',$base['branch'])->where('actor_id',$entry->actor_id)->where('request_key',$entry->command_id));
            abort_unless(hash_equals($order->request_hash,$sale->request_hash),409);
            $grant=json_decode($sale->metadata,true,512,JSON_THROW_ON_ERROR)['receipt_print']??null;
            abort_unless(is_array($grant)&&($grant['version']??null)===1&&preg_match('/^[a-f0-9]{32}$/D',$grant['nonce']??'')
                &&is_int($grant['expires_at']??null),409,'The original receipt grant is unavailable.');
            // Persist immutable stored rows, never actor-dependent presenter URLs/flags.
            $snapshot=['order'=>(array)$order,'items'=>$db->table('takeaway_order_items')->where('order_id',$order->id)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all(),
                'grant'=>['version'=>1,'nonce'=>$grant['nonce'],'expires_at'=>$grant['expires_at']],
                'presentation'=>app(TakeawayService::class)->receipt((int)$order->id,$this->printAuthority->actor((int)$entry->actor_id))];
            unset($snapshot['order']['updated_at']);
            $intents[]=$this->intent($base,'sale_receipt',1,['receipt'=>(int)$order->id,'sale_till_entry.primary'=>(int)$sale->id],$snapshot);
        }elseif($route==='branch-shifts.close'){
            $closing=$this->one($db->table('branch_shift_closings')->where('branch',$base['branch'])->where('actor_id',$entry->actor_id)->where('request_key',$entry->command_id));
            $job=$this->one($db->table('branch_shift_print_jobs')->where('closing_id',$closing->id)->where('branch',$base['branch'])->where('actor_id',$entry->actor_id)->where('request_key',$entry->command_id));
            $intents[]=$this->intent($base,'shift_report',1,['shift'=>(int)$closing->id,'shift_print_job.primary'=>(int)$job->id],json_decode($closing->snapshot,true,512,JSON_THROW_ON_ERROR)+['number'=>'SHIFT-'.str_pad((string)$closing->id,6,'0',STR_PAD_LEFT),'sequence'=>(int)$closing->sequence]);
        }else{
            $op=$this->one($db->table('pos_service_commands')->where('branch',$base['branch'])->where('actor_id',$entry->actor_id)->where('request_key',$entry->command_id));
            if(($payload['values']['action']??null)==='request_bill'){
                $saved=json_decode(Crypt::decryptString($entry->local_result_cipher),true,512,JSON_THROW_ON_ERROR);$result=$saved['result']??$saved;
                $intents[]=$this->intent($base,'bill_receipt',(int)$op->revision,['ticket'=>(int)$op->ticket_id,'bill_command.primary'=>(int)$op->id],['ticket'=>$result['ticket']]);
            }
            $want=$route==='phone-orders.save'?(bool)($payload['values']['send_to_kitchen']??false):(($payload['values']['action']??null)==='send_kitchen');
            if($want){
                $kitchen=$this->one($db->table('pos_service_kitchen_tickets')->where('ticket_id',$op->ticket_id)->where('branch',$base['branch'])->where('actor_id',$entry->actor_id)->where('revision',$op->revision));
                $meta=json_decode($op->metadata??'{}',true,512,JSON_THROW_ON_ERROR);
                if($route==='phone-orders.action')abort_unless((int)($meta['kitchen_id']??0)===(int)$kitchen->id,409);
                $refs=['ticket'=>(int)$op->ticket_id,'kitchen.primary'=>(int)$kitchen->id];$kind='dining_kitchen';
                if($route!=='dining.action'){$job=$this->one($db->table('pos_branch_print_jobs')->where('kitchen_id',$kitchen->id)->where('ticket_id',$op->ticket_id)->where('branch',$base['branch']));$refs['print_job.primary']=(int)$job->id;$kind='phone_kitchen';}
                $intents[]=$this->intent($base,$kind,(int)$kitchen->revision,$refs,['ticket'=>json_decode($kitchen->snapshot,true,512,JSON_THROW_ON_ERROR),'revision'=>(int)$kitchen->revision,'created_at'=>(string)$kitchen->created_at]);
            }
        }
        usort($intents,fn($a,$b)=>[$a['kind'],$a['slot']]<=>[$b['kind'],$b['slot']]);return $intents;
    }
    private function intent(array $base,string $kind,int $revision,array $references,array $snapshot): array
    {
        // Stored kitchen snapshots contain presenter URLs. Strip them recursively;
        // future rendering must derive a protected URL from this typed identity.
        $clean=function($value)use(&$clean){if(!is_array($value))return $value;$result=[];foreach($value as $key=>$part){if(is_string($key)&&(preg_match('/(?:^|_)(?:url|token)$/D',$key)||in_array($key,['can_reprint','first_print_url'],true)))continue;$result[$key]=$clean($part);}return $result;};
        $snapshot=$clean($snapshot);$encoded=$this->json($snapshot);abort_if(strlen($encoded)>4*1024*1024,413);
        $effect=$this->hash([$base['device_id'],$base['enrollment_id'],$base['origin_command_id'],$kind,'primary']);
        return $base+['effect_id'=>$effect,'kind'=>$kind,'slot'=>'primary','revision'=>$revision,'local_references'=>$references,'snapshot_sha256'=>hash('sha256',$encoded),'snapshot'=>$snapshot];
    }
    private function validateStoredIntent(array $value,object $intent,object $command): void
    {
        $expected=['protocol','device_id','enrollment_id','origin_command_id','actor_id','branch','origin_route','request_hash','origin_database','snapshot_id','schema_hash',
            'effect_id','kind','slot','revision','local_references','snapshot_sha256','snapshot'];
        $keys=array_keys($value);sort($keys);sort($expected);abort_unless($keys===$expected,503);
        $bindings=['device_id'=>'device_id','enrollment_id'=>'enrollment_id','origin_command_id'=>'command_id','branch'=>'branch','origin_route'=>'route_name',
            'request_hash'=>'request_hash','origin_database'=>'origin_database','snapshot_id'=>'snapshot_id','schema_hash'=>'schema_hash'];
        foreach($bindings as $field=>$column)abort_unless(($value[$field]??null)===$command->$column,503,'Private intent identity does not match its command.');
        abort_unless($value['protocol']===1&&$value['actor_id']===(int)$command->actor_id&&$value['actor_id']===(int)$intent->actor_id
            &&$value['device_id']===$intent->device_id&&$value['enrollment_id']===$intent->enrollment_id&&$value['origin_command_id']===$intent->command_id
            &&$value['branch']===$intent->branch&&$value['kind']===$intent->kind&&$value['slot']==='primary'&&$intent->slot==='primary'
            &&is_int($value['revision'])&&$value['revision']>0&&$value['revision']===(int)$intent->revision,503);
        $refs=match($value['kind']){'delivery_batch'=>array_merge(['delivery_batch'],array_map(fn($index)=>'receipt.'.$index,array_keys($value['snapshot']['items']??[]))),'sale_receipt'=>['receipt','sale_till_entry.primary'],'phone_kitchen'=>['ticket','kitchen.primary','print_job.primary'],'dining_kitchen'=>['ticket','kitchen.primary'],'bill_receipt'=>['ticket','bill_command.primary'],'shift_report'=>['shift','shift_print_job.primary'],default=>[]};
        $actual=array_keys($value['local_references']);sort($actual);sort($refs);abort_unless($refs&&$actual===$refs,503);
        foreach($value['local_references'] as $id)abort_unless(is_int($id)&&$id>0,503);
        abort_unless($value['effect_id']===$intent->effect_id
            &&hash_equals($value['effect_id'],$this->hash([$value['device_id'],$value['enrollment_id'],$value['origin_command_id'],$value['kind'],$value['slot']]))
            &&is_array($value['snapshot'])&&hash_equals($value['snapshot_sha256'],hash('sha256',$this->json($value['snapshot']))),503,'Private intent snapshot or stable effect identity is invalid.');
    }
    private function storedIntents(Connection $db,object $command): array
    {
        $intents=[];
        foreach($db->table($this->schema->table('print_ledger_intents'))->where('device_id',$command->device_id)->where('command_id',$command->command_id)->orderBy('kind')->orderBy('slot')->get() as $intent){
            $value=json_decode(Crypt::decryptString($intent->descriptor_cipher),true,512,JSON_THROW_ON_ERROR);
            abort_unless($intent->lifecycle_state==='intent'&&hash_equals($intent->descriptor_hash,$this->hash($value)),503);
            $this->validateStoredIntent($value,$intent,$command);$intents[]=$value;
        }
        abort_unless(hash_equals($command->intents_hash,$this->hash($intents)),503,'Private intent contents are incomplete.');
        return $intents;
    }
    private function one($query): object
    {
        $rows=$query->lockForUpdate()->limit(2)->get();abort_unless($rows->count()===1,409,'Immutable business output is missing or ambiguous.');return $rows[0];
    }
    private function json(array $value): string
    {
        $sort=function($value)use(&$sort){if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);return array_map($sort,$value);};
        return json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
    }
    private function hash(array $value): string {return hash('sha256',$this->json($value));}
}
