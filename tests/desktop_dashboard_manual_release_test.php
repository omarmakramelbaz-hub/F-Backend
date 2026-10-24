<?php
/** Real filesystem spool tests; no Laravel bootstrap, database or shell calls. */
namespace {
    class ManualReleaseHttpFailure extends \RuntimeException {
        public function __construct(public int $status,string $message=''){parent::__construct($message);}
    }
    function abort_unless($condition,$status,$message=''){if(!$condition)throw new ManualReleaseHttpFailure($status,$message);}
    function abort($status,$message=''){throw new ManualReleaseHttpFailure($status,$message);}
    function config($key){return $GLOBALS['manual_release_config'][$key]??null;}
    function app($class){return $GLOBALS['manual_release_owner_access'];}
}
namespace App\Models {
    class User {
        public function __construct(public int $id){}
        public function getKey(){return $this->id;}
    }
}
namespace App\Services\Dashboard {
    class MetaInboxAccess {
        public int $calls=0;
        public function owner($actor): \App\Models\User {
            $this->calls++;
            // Persisted identity is authoritative; supplied actor fields and
            // selected-user session values cannot promote this actor.
            $fresh=$GLOBALS['manual_release_persisted'][$actor->id??0]??null;
            \abort_unless($fresh instanceof \App\Models\User&&in_array($fresh->id,[1,3],true),403);
            return $fresh;
        }
    }
}
namespace {
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardManualRelease.php';
    use App\Services\Dashboard\DesktopDashboardManualRelease;
    use App\Models\User;

    class ManualReleaseFixture extends DesktopDashboardManualRelease {
        public int $clock=1792843200;
        public function __construct(public string $directory){}
        protected function root(): string{return $this->directory;}
        protected function now(): int{return $this->clock;}
    }
    $checks=0;$fixtures=[];
    $GLOBALS['manual_release_config']=['desktop_dashboard.enabled'=>true,'desktop_dashboard.local'=>false];
    $GLOBALS['manual_release_owner_access']=new \App\Services\Dashboard\MetaInboxAccess;
    $owner=new User(1);$secondOwner=new User(3);$nonowner=new User(2);
    $GLOBALS['manual_release_persisted']=[1=>$owner,2=>$nonowner,3=>$secondOwner];
    function check($condition,string $name): void {
        global $checks;$checks++;if(!$condition)throw new \RuntimeException($name);
    }
    function blocked(callable $callback,int $status,string $name): void {
        try{$callback();throw new \RuntimeException('Expected refusal: '.$name);}
        catch(ManualReleaseHttpFailure $error){check($error->status===$status,$name);}
    }
    function removeFixture(string $path): void {
        if(is_link($path)||!is_dir($path)){if(file_exists($path)||is_link($path))unlink($path);return;}
        foreach(scandir($path) as $name)if($name!=='.'&&$name!=='..')removeFixture($path.'/'.$name);
        rmdir($path);
    }
    function record(string $path,array $value,int $mode): void {
        file_put_contents($path,json_encode($value,JSON_THROW_ON_ERROR));chmod($path,$mode);
    }
    function rootStatus(ManualReleaseFixture $service,array $changes=[]): array {
        return array_replace(['format'=>1,'kind'=>'go-erp-manual-release-status','state'=>'idle',
            'nonce'=>null,'updated_at'=>$service->clock,'version'=>null,'code'=>null],$changes);
    }
    function statusFile(ManualReleaseFixture $service,array $changes=[]): array {
        $value=rootStatus($service,$changes);record($service->directory.'/shared/status.json',$value,0640);return $value;
    }
    function fixture(): ManualReleaseFixture {
        global $fixtures;
        $path=sys_get_temp_dir().'/go-erp-manual-release-test-'.bin2hex(random_bytes(8));
        mkdir($path,0755);chmod($path,0755);mkdir($path.'/requests',0700);mkdir($path.'/shared',0750);
        chmod($path.'/requests',0700);chmod($path.'/shared',0750);$fixtures[]=$path;
        $service=new ManualReleaseFixture($path);statusFile($service);return $service;
    }
    function envelope(ManualReleaseFixture $service,string $nonce,int $actor=1): array {
        return ['format'=>1,'kind'=>'go-erp-manual-release-request','nonce'=>$nonce,
            'actor_id'=>$actor,'requested_at'=>$service->clock];
    }
    function pending(ManualReleaseFixture $service): string{return $service->directory.'/requests/pending.json';}
    function ledger(ManualReleaseFixture $service): string{return $service->directory.'/requests/last-request.json';}

    try{
        check(function_exists('posix_geteuid')&&posix_geteuid()===0,'root-owned read-only shared-status fixtures required');
        $a=str_repeat('a',32);$b=str_repeat('b',32);$c=str_repeat('c',32);

        $service=fixture();$before=$GLOBALS['manual_release_owner_access']->calls;
        check($service->canRequest($owner),'persisted system owner can request');
        check(!$service->canRequest($nonowner),'ordinary persisted admin cannot request');
        $forged=(object)['id'=>2,'owner'=>true,'selected_user_id'=>1];
        blocked(fn()=>$service->request($forged,$a),403,'caller fields cannot replace persisted owner');
        blocked(fn()=>$service->status($nonowner),403,'status restricted to owner');
        check($GLOBALS['manual_release_owner_access']->calls>$before,'all public access uses original owner gate');
        check(!file_exists(pending($service)),'unauthorized request never creates queue');
        foreach([['desktop_dashboard.enabled',false],['desktop_dashboard.local',true]] as [$key,$value]){
            $old=$GLOBALS['manual_release_config'][$key];$GLOBALS['manual_release_config'][$key]=$value;
            check(!$service->canRequest($owner),'server-only availability fails closed');
            blocked(fn()=>$service->status($owner),404,'disabled or local status refused');
            blocked(fn()=>$service->request($owner,$a),404,'disabled or local build request refused');
            $GLOBALS['manual_release_config'][$key]=$old;
        }

        $service=fixture();$status=rootStatus($service);
        check($service->status($owner)===$status,'strict shared root status read unchanged');
        foreach(['',str_repeat('A',32),'../'.str_repeat('a',32),str_repeat('a',33),"a\0b"] as $invalid)
            blocked(fn()=>$service->request($owner,$invalid),422,'nonce must be canonical32 lowercase hex');
        $result=$service->request($owner,$a);$request=envelope($service,$a);
        check($result===['request'=>$request,'idempotent'=>false,'status'=>$status],'new request uses fixed server envelope');
        check(json_decode(file_get_contents(pending($service)),true)===$request,'pending contains exact envelope only');
        check(json_decode(file_get_contents(ledger($service)),true)===$request,'web ledger retains same exact envelope');
        check((fileperms(pending($service))&0777)===0600&&(fileperms(ledger($service))&0777)===0600,'request and ledger remain private');
        $queueBefore=file_get_contents(pending($service));$ledgerBefore=file_get_contents(ledger($service));$service->clock+=10;
        $retry=$service->request($owner,$a);
        check($retry['idempotent']===true&&$retry['request']===$request,'same nonce keeps original actor and requested time');
        check(file_get_contents(pending($service))===$queueBefore&&file_get_contents(ledger($service))===$ledgerBefore,'idempotent retry writes nothing');
        blocked(fn()=>$service->request($secondOwner,$a),403,'another owner cannot reuse actor-bound web record');
        blocked(fn()=>$service->request($owner,$b),409,'unconsumed pending request cannot be overwritten');
        check(file_get_contents(pending($service))===$queueBefore,'busy queue bytes retained');
        unlink(pending($service));statusFile($service,['state'=>'ready','nonce'=>$a,'version'=>'0.4.4-preview.43']);
        $retry=$service->request($owner,$a);
        check($retry['idempotent']===true&&$retry['request']===$request&&!file_exists(pending($service)),'consumed request retries from ledger without requeue');
        blocked(fn()=>$service->request($owner,$b),429,'new request obeys300 second cooldown');
        $service->clock=$request['requested_at']+300;
        check($service->request($owner,$b)['idempotent']===false,'new nonce accepted at cooldown boundary');

        $service=fixture();record(pending($service),envelope($service,$a),0600);
        $retry=$service->request($owner,$a);
        check($retry['idempotent']===true&&!file_exists(ledger($service)),'queue-first crash recovers existing nonce without fabricating ledger');
        unlink(pending($service));statusFile($service,['state'=>'running','nonce'=>$a]);
        $retry=$service->request($owner,$a);
        check($retry['idempotent']===true&&$retry['request']===null&&!file_exists(pending($service)),'consumed root-status nonce is idempotent');
        foreach(['queued','running','waiting-ci','publishing'] as $active){
            statusFile($service,['state'=>$active,'nonce'=>$b]);
            blocked(fn()=>$service->request($owner,$c),409,'active root work never gets another request');
        }

        $service=fixture();$badStatuses=[['extra'=>'private'],['format'=>true],['kind'=>'foreign'],['state'=>'complete'],
            ['nonce'=>str_repeat('A',32)],['updated_at'=>true],['updated_at'=>0],['updated_at'=>1.5],
            ['version'=>'../../installer.exe'],['code'=>'private exception token'],['code'=>'GO_ERP_OK\nPRIVATE']];
        foreach($badStatuses as $change){
            statusFile($service,$change);blocked(fn()=>$service->status($owner),503,'invalid shared schema refused');
        }
        foreach(['{','[]',str_repeat(' ',8193)] as $raw){
            file_put_contents($service->directory.'/shared/status.json',$raw);chmod($service->directory.'/shared/status.json',0640);
            blocked(fn()=>$service->status($owner),503,'malformed or oversized root status refused');
        }

        foreach(['pending.json','last-request.json'] as $name){
            foreach([['actor_id'=>true],['requested_at'=>-1],['requested_at'=>1.5],['nonce'=>'foreign'],['kind'=>'command'],['command'=>'systemctl']] as $change){
                $service=fixture();record($service->directory.'/requests/'.$name,array_replace(envelope($service,$a),$change),0600);
                blocked(fn()=>$service->request($owner,$b),503,'malformed web spool record refused');
                check(!file_exists(pending($service))||$name==='pending.json','invalid ledger never queues work');
            }
        }

        foreach(['root','requests','shared'] as $target){
            $service=fixture();$path=$target==='root'?$service->directory:$service->directory.'/'.$target;
            chmod($path,0777);blocked(fn()=>$service->status($owner),503,'unsafe spool directory mode refused');
        }
        $service=fixture();rename($service->directory.'/requests',$service->directory.'/requests-original');
        symlink($service->directory.'/requests-original',$service->directory.'/requests');
        blocked(fn()=>$service->request($owner,$a),503,'symlink request directory refused');

        foreach(['status','pending','ledger','lock'] as $slot){
            foreach(['symlink','hardlink','mode'] as $damage){
                $service=fixture();$path=match($slot){'status'=>$service->directory.'/shared/status.json',
                    'pending'=>pending($service),'ledger'=>ledger($service),'lock'=>$service->directory.'/requests/request.lock'};
                if($slot!=='status')record($path,$slot==='lock'?['sentinel'=>'unchanged']:envelope($service,$a),0600);
                $original=file_get_contents($path);$other=$service->directory.'/outside-sentinel';
                if($damage==='symlink'){rename($path,$other);symlink($other,$path);}
                elseif($damage==='hardlink')link($path,$other);
                else chmod($path,$slot==='status'?0660:0644);
                blocked(fn()=>$slot==='status'?$service->status($owner):$service->request($owner,$b),503,'unsafe spool file rejected');
                check(file_get_contents($path)===$original,'refusal does not change target bytes');
            }
        }
        $service=fixture();unlink($service->directory.'/shared/status.json');
        posix_mkfifo($service->directory.'/shared/status.json',0640);
        blocked(fn()=>$service->status($owner),503,'FIFO status rejected before opening');

        echo "GO_ERP_MANUAL_RELEASE_TESTS_OK checks=$checks\n";
    }finally{
        foreach($fixtures as $path)removeFixture($path);
    }
}
