<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

/**
 * Private CLI proof for an inactive, fully checked native delta generation.
 * The native supervisor alone retains the random capture token and never serves
 * this candidate or mutates it between preverification and activation. Retained
 * application bundles were independently authenticated before this handoff.
 */
final class DesktopDashboardHandoff
{
    private const KIND='desktop-preverified-handoff';
    private const DOMAIN="desktop-preverify:1\n";
    private const ERROR='دليل فحص قاعدة التجهيز غير متطابق؛ بيانات الجهاز الحالية محفوظة.';

    /** Full source, row-count, replay and attachment work happens before the write fence. */
    public function capture(array $receipt,string $token): array
    {
        $this->boundary($receipt,$token);
        $replay=$this->replay($receipt['delta_replay']??null);
        $checked=DesktopDashboardMediaSeal::capture($receipt['media']??[],
            fn()=>app(DesktopDashboardImport::class)->verify($receipt));
        $receipt=$checked['result'];
        abort_unless($this->replayDigest($receipt)===$replay,409,self::ERROR);
        $state=DB::transaction(fn()=>$this->bindings($receipt));
        $source=DB::selectOne('SELECT device_id,actor_id,snapshot_id,schema_hash,state FROM `'.$replay['source_database'].'`.`desktop_dashboard_local_state` WHERE device_id=?',[$receipt['device_id']]);
        abort_unless($source&&$source->device_id===$receipt['device_id']&&(int)$source->actor_id===$receipt['actor_id']
            &&$source->state==='ready'&&$this->uuid($source->snapshot_id)&&$this->digest($source->schema_hash),409,self::ERROR);
        $payload=['database'=>DB::connection()->getDatabaseName(),'application'=>$this->application(),
            'receipt_sha256'=>$this->hash($receipt),'source_database'=>$replay['source_database'],
            'source_identity'=>['snapshot_id'=>$source->snapshot_id,'schema_hash'=>$source->schema_hash],
            'sequence'=>$replay['sequence'],'replay'=>$replay,'state_sha256'=>$this->hash($state),
            'media_seal_sha256'=>$this->hash($checked['seal'])];
        return $receipt+['media_seal'=>$checked['seal'],'archive_handoff'=>['format'=>1,'kind'=>self::KIND,
            'payload'=>$payload,'signature'=>hash_hmac('sha256',self::DOMAIN.$this->json($payload),hex2bin($token))]];
    }

    /** Authenticate first, then inspect fixed indexed rows and attachment identities only. */
    public function verify(array $receipt,array $seal,array $proof,string $token,string $source,?array $identity): array
    {
        $this->boundary($receipt,$token);
        abort_unless(array_keys($proof)===['format','kind','payload','signature']&&$proof['format']===1
            &&$proof['kind']===self::KIND&&is_array($proof['payload'])&&$this->digest($proof['signature']),409,self::ERROR);
        $payload=$proof['payload'];
        abort_unless(array_keys($payload)===['database','application','receipt_sha256','source_database','source_identity','sequence','replay','state_sha256','media_seal_sha256']
            &&hash_equals(hash_hmac('sha256',self::DOMAIN.$this->json($payload),hex2bin($token)),$proof['signature']),409,self::ERROR);
        $replay=$this->replay($receipt['delta_replay']??null);
        abort_unless(($receipt['verified']??null)===true&&$payload['database']===DB::connection()->getDatabaseName()
            &&$payload['application']===$this->application()&&$payload['receipt_sha256']===$this->hash($receipt)
            &&$payload['source_database']===$source&&$source===$replay['source_database']
            &&is_array($identity)&&array_keys($identity)===['snapshot_id','schema_hash']
            &&$this->uuid($identity['snapshot_id'])&&$this->digest($identity['schema_hash'])
            &&$payload['source_identity']===$identity&&$payload['sequence']===$replay['sequence']
            &&$payload['replay']===$replay&&$this->digest($payload['state_sha256'])
            &&$payload['media_seal_sha256']===$this->hash($seal),409,self::ERROR);
        abort_unless($payload['state_sha256']===$this->hash(DB::transaction(fn()=>$this->bindings($receipt))),409,self::ERROR);
        DesktopDashboardMediaSeal::verify($receipt['media']??[],$seal);
        return $payload;
    }

    private function boundary(array $receipt,string $token): void
    {
        abort_unless(PHP_SAPI==='cli'&&getenv('DESKTOP_DASHBOARD_LOCAL')==='true'&&config('desktop_dashboard.local')===true
            &&DB::connection()->getConfig('host')==='127.0.0.1'&&DB::transactionLevel()===0
            &&preg_match('/^fasakhansta_dashboard_stage_[a-f0-9]{16}$/D',DB::connection()->getDatabaseName())
            &&$this->digest($token)&&is_string($receipt['device_id']??null)
            &&$receipt['device_id']===(string)config('desktop_dashboard.device_id')&&$this->uuid($receipt['device_id'])
            &&is_int($receipt['actor_id']??null)&&$receipt['actor_id']>0
            &&!array_key_exists('media_seal',$receipt)&&!array_key_exists('archive_handoff',$receipt),403,self::ERROR);
    }

    /** These rows are private and immutable from preverification until activation. */
    private function bindings(array $receipt): array
    {
        app(DesktopDashboardPrintLedger::class)->fence($receipt['device_id']);
        $state=DB::table('desktop_dashboard_local_state')->where('device_id',$receipt['device_id'])->lockForUpdate()->first();
        abort_unless($state&&$state->state==='ready'&&$state->snapshot_id===($receipt['snapshot_id']??null)
            &&(int)$state->actor_id===$receipt['actor_id']&&$state->schema_hash===($receipt['schema_hash']??null)
            &&json_decode($state->branches,true,512,JSON_THROW_ON_ERROR)===($receipt['branches']??null)
            &&json_decode($state->coverage,true,512,JSON_THROW_ON_ERROR)===($receipt['coverage']??null),409,self::ERROR);
        foreach(['desktop_dashboard_commands','desktop_dashboard_entities','desktop_dashboard_refreshes'] as $table)
            abort_unless(!DB::table($table)->exists(),409,self::ERROR);
        abort_unless(DB::table('users')->where('id',$receipt['actor_id'])->exists(),409,self::ERROR);
        $source=DB::table('desktop_dashboard_source_manifest')->where('device_id',$receipt['device_id'])->first(['snapshot_id','sha256']);
        $media=DB::table('desktop_dashboard_media_manifest')->where('device_id',$receipt['device_id'])->first(['snapshot_id','sha256']);
        abort_unless($source&&$source->snapshot_id===$receipt['snapshot_id']&&$source->sha256===hash('sha256',DesktopDashboardBootstrap::json($receipt['source']??null))
            &&$media&&$media->snapshot_id===$receipt['snapshot_id']&&$this->digest($media->sha256),409,self::ERROR);
        $binding=['snapshot_id'=>$state->snapshot_id,'schema_hash'=>$state->schema_hash,'actor_id'=>(int)$state->actor_id,
            'branches'=>json_decode($state->branches,true,512,JSON_THROW_ON_ERROR),'coverage'=>json_decode($state->coverage,true,512,JSON_THROW_ON_ERROR),
            'source_sha256'=>$source->sha256,'media_sha256'=>$media->sha256];
        if(($receipt['coverage']['print_protocol']??null)===1){
            app(DesktopDashboardPrintLedger::class)->verifyEnrollment($receipt['device_id']);
            $device=DB::table('desktop_dashboard_devices')->where('id',$receipt['device_id'])->lockForUpdate()->first();
            abort_unless($device&&$device->enabled&&(int)$device->actor_id===$receipt['actor_id'],409,self::ERROR);
            $binding['enrollment_sha256']=app(DesktopDashboardDevices::class)->enrollmentFingerprint($device);
            $binding['base_branches']=json_decode($device->branches,true,512,JSON_THROW_ON_ERROR);
        }
        return $binding;
    }

    private function replay($value): array
    {
        abort_unless(is_array($value)&&array_keys($value)===['format','source_database','sequence','rows','sha256']
            &&$value['format']===1&&is_string($value['source_database'])
            &&preg_match('/^fasakhansta_dashboard(?:_stage_[a-f0-9]{16})?$/D',$value['source_database'])
            &&$value['source_database']!==DB::connection()->getDatabaseName()
            &&is_int($value['sequence'])&&$value['sequence']>=0&&is_int($value['rows'])&&$value['rows']>=0
            &&$this->digest($value['sha256']),409,self::ERROR);
        return $value;
    }

    /** Recheck the prestaged UUID projection before signing; never called at cutover. */
    private function replayDigest(array $receipt): array
    {
        $proof=$this->replay($receipt['delta_replay']);$hash=hash_init('sha256');hash_update($hash,"desktop-dashboard-replays:1\n");$rows=0;$after=null;
        // MySQL PDO may buffer cursor() results. Primary-key pages cap memory
        // independently of the retained UUID history's eventual size.
        do{
            $page=DB::select('SELECT device_id,command_id,actor_id,route_name,request_hash,source_database FROM desktop_dashboard_archived_commands'
                .($after===null?'':' WHERE device_id>? OR (device_id=? AND command_id>?)').' ORDER BY device_id,command_id LIMIT 1000',
                $after===null?[]:[$after[0],$after[0],$after[1]]);
            foreach($page as $entry){
                abort_unless($entry->device_id===$receipt['device_id']&&(int)$entry->actor_id===$receipt['actor_id']
                    &&$this->uuid($entry->command_id)&&is_string($entry->route_name)&&strlen($entry->route_name)>0&&strlen($entry->route_name)<=150
                    &&$this->digest($entry->request_hash)&&is_string($entry->source_database)
                    &&preg_match('/^fasakhansta_dashboard(?:_stage_[a-f0-9]{16})?$/D',$entry->source_database),409,self::ERROR);
                $row=['device_id'=>$entry->device_id,'command_id'=>$entry->command_id,'actor_id'=>(int)$entry->actor_id,
                    'route_name'=>$entry->route_name,'request_hash'=>$entry->request_hash,'source_database'=>$entry->source_database];
                hash_update($hash,json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");$rows++;
                $after=[$entry->device_id,$entry->command_id];
            }
        }while(count($page)===1000);
        return ['format'=>1,'source_database'=>$proof['source_database'],'sequence'=>$proof['sequence'],'rows'=>$rows,'sha256'=>hash_final($hash)];
    }

    private function application(): string
    {
        $path=realpath(base_path());abort_unless(is_string($path)&&is_dir($path),409,self::ERROR);
        $path=str_replace('\\','/',$path);return DIRECTORY_SEPARATOR==='\\'?strtolower($path):$path;
    }
    private function digest($value): bool{return is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)===1;}
    private function uuid($value): bool{return is_string($value)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$value)===1;}
    private function hash(array $value): string{return hash('sha256',$this->json($value));}
    private function json(array $value): string
    {
        $normalize=function($part)use(&$normalize){
            if(!is_array($part))return $part;
            if(!array_is_list($part))ksort($part,SORT_STRING);
            foreach($part as $key=>$child)$part[$key]=$normalize($child);
            return $part;
        };
        return json_encode($normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
}
