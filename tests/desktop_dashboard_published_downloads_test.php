<?php
/** Approved current/previous installers stay pinned by SHA across manual builds. */
namespace {
    class PublishedDownloadsHttpFailure extends \RuntimeException {
        public function __construct(public int $status,string $message=''){parent::__construct($message);}
    }
    function abort_unless($condition,$status,$message=''){if(!$condition)throw new PublishedDownloadsHttpFailure($status,$message);}
    function abort_if($condition,$status,$message=''){if($condition)throw new PublishedDownloadsHttpFailure($status,$message);}
    function abort($status,$message=''){throw new PublishedDownloadsHttpFailure($status,$message);}
    function config($key){return $GLOBALS['published_downloads_config'][$key]??null;}
    function storage_path($path=''){return $GLOBALS['published_downloads_application'].'/storage'.($path!==''?'/'.$path:'');}
    function base_path($path=''){return $GLOBALS['published_downloads_application'].($path!==''?'/'.$path:'');}
}
namespace App\Services\Dashboard {
    function lstat($path){
        $info=\lstat($path);
        // Some test containers map only UID0 and cannot chown fixtures. Keep
        // every other real stat field, exercising the ownership guard only.
        if(is_array($info)&&($GLOBALS['published_downloads_foreign_owner']??null)===$path){
            $info['uid']=1;$info[4]=1;
        }
        return $info;
    }
    // Run the actual hash first, then simulate a legitimate pointer rotation or
    // forbidden inode replacement before the service's final file recheck.
    function hash_update_stream($context,$stream,$length=-1){
        $read=\hash_update_stream($context,$stream,$length);
        $hook=$GLOBALS['published_downloads_after_hash']??null;
        if($hook!==null){$GLOBALS['published_downloads_after_hash']=null;$hook();}
        return $read;
    }
}
namespace {
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardSource.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardUpdates.php';
    use App\Services\Dashboard\{DesktopDashboardSource,DesktopDashboardUpdates};
    class PublishedSource extends DesktopDashboardSource {
        public int $calls=0;
        public bool $forbid=true;
        public array $value=['format'=>1,'files'=>1356,'framework'=>'8.83.29','sha256'=>''];
        public function fingerprint(): array {
            $this->calls++;
            if($this->forbid)throw new \RuntimeException('Historical download must not read live source');
            return $this->value;
        }
    }
    class PublishedFixture extends DesktopDashboardUpdates {
        public function __construct(DesktopDashboardSource $source,public string $directory){parent::__construct($source);}
        protected function publishedRoot(): string{return $this->directory.'/shared';}
    }
    $checks=0;$fixtures=[];
    $GLOBALS['published_downloads_config']=['desktop_dashboard.enabled'=>true,'desktop_dashboard.local'=>false];
    $GLOBALS['published_downloads_after_hash']=null;
    $GLOBALS['published_downloads_foreign_owner']=null;
    function check($condition,string $label): void {global $checks;$checks++;if(!$condition)throw new \RuntimeException($label);}
    function blocked(callable $callback,int $status,string $label): void {
        try{$callback();throw new \RuntimeException('Expected refusal: '.$label);}
        catch(PublishedDownloadsHttpFailure $error){check($error->status===$status,$label);}
    }
    function removeFixture(string $path): void {
        if(is_link($path)||!is_dir($path)){if(file_exists($path)||is_link($path))unlink($path);return;}
        foreach(scandir($path) as $name)if($name!=='.'&&$name!=='..')removeFixture($path.'/'.$name);
        rmdir($path);
    }
    function installerBytes(int $version): string{return "MZ\0approved native installer version ".$version."\n";}
    function release(int $version): array {
        $bytes=installerBytes($version);$sha=hash('sha256',$bytes);
        return ['version'=>'0.4.4-preview.'.$version,'sequence'=>40000000000+$version,
            'source_revision'=>hash('sha1','accepted candidate '.$version),
            'source'=>['format'=>1,'files'=>1300+$version,'framework'=>'8.83.29',
                'sha256'=>hash('sha256','accepted source '.$version)],
            'installer'=>['sha256'=>$sha,'bytes'=>strlen($bytes),
                'path'=>'/api/desktop-dashboard/update/installer/'.$sha],
            'gui'=>['public/js/dashboard-historical.js'=>hash('sha256','accepted GUI '.$version)]];
    }
    function publicRelease(array $value): array {unset($value['gui']);return $value;}
    function manifest(PublishedFixture $service,array $current,?array $previous): array {
        $value=['format'=>1,'kind'=>'go-erp-published-downloads','current'=>$current,'previous'=>$previous];
        $path=$service->directory.'/shared/downloads.json';file_put_contents($path,json_encode($value,JSON_THROW_ON_ERROR));chmod($path,0640);return $value;
    }
    function binary(PublishedFixture $service,int $version): string {
        $release=release($version);$path=$service->directory.'/shared/installers/'.$release['installer']['sha256'].'.exe';
        file_put_contents($path,installerBytes($version));chmod($path,0640);return $path;
    }
    function fixture(): array {
        global $fixtures;
        $path=sys_get_temp_dir().'/go-erp-published-downloads-test-'.bin2hex(random_bytes(8));$fixtures[]=$path;
        mkdir($path,0750);chmod($path,0750);mkdir($path.'/shared',0750);chmod($path.'/shared',0750);
        mkdir($path.'/shared/installers',0750);chmod($path.'/shared/installers',0750);
        $source=new PublishedSource;$source->value['sha256']=str_repeat('f',64);
        $service=new PublishedFixture($source,$path);return [$service,$source];
    }
    function downloaded(array $file,string $expected,string $version): void {
        check(is_resource($file['stream'])&&$file['bytes']===strlen($expected),'download returns checked descriptor and exact size');
        try{check(stream_get_contents($file['stream'])===$expected,'approved installer descriptor rewound and streamed exactly');}
        finally{fclose($file['stream']);}
        check($file['sha256']===hash('sha256',$expected)&&$file['version']===$version,'download retains selected SHA and version');
    }
    try{
        check(function_exists('posix_geteuid')&&posix_geteuid()===0,'root-owned publication fixtures required');
        $current=release(42);$previous=release(41);$next=release(44);
        [$service,$source]=fixture();
        check($service->publishedDownloads()===['current'=>null,'previous'=>null],'absent history manifest offers no downloads');
        blocked(fn()=>$service->publishedInstaller($current['installer']['sha256']),404,'absent published SHA unavailable');
        foreach(['current','previous','../current.json',str_repeat('A',64),str_repeat('a',65),"a\0b"] as $invalid)
            blocked(fn()=>$service->publishedInstaller($invalid),404,'only canonical SHA can select published installer');
        binary($service,42);binary($service,41);manifest($service,$current,$previous);
        check($service->publishedDownloads()===['current'=>publicRelease($current),'previous'=>publicRelease($previous)],
            'adjacent public current and previous releases retain accepted identities');
        check($source->calls===0,'historical listing never requires current live source or GUI equality');
        downloaded($service->publishedInstaller($current['installer']['sha256']),installerBytes(42),'0.4.4-preview.42');
        downloaded($service->publishedInstaller($previous['installer']['sha256']),installerBytes(41),'0.4.4-preview.41');
        check($source->calls===0,'historical approved downloads do not read changed live source');
        blocked(fn()=>$service->publishedInstaller(str_repeat('e',64)),404,'unlisted SHA cannot download even if well formed');
        $unlisted=$service->directory.'/shared/installers/'.str_repeat('e',64).'.exe';file_put_contents($unlisted,'MZunapproved');chmod($unlisted,0640);
        blocked(fn()=>$service->publishedInstaller(str_repeat('e',64)),404,'an unlisted filesystem binary never grants approval');
        manifest($service,$current,null);
        check($service->publishedDownloads()===['current'=>publicRelease($current),'previous'=>null],'first approved release has no previous button');
        blocked(fn()=>$service->publishedInstaller($previous['installer']['sha256']),404,'unlisted old binary remains unavailable');
        manifest($service,$current,$previous);

        foreach([['desktop_dashboard.enabled',false],['desktop_dashboard.local',true]] as [$key,$value]){
            $old=$GLOBALS['published_downloads_config'][$key];$GLOBALS['published_downloads_config'][$key]=$value;
            blocked(fn()=>$service->publishedDownloads(),503,'history listing requires enabled central server');
            blocked(fn()=>$service->publishedInstaller($current['installer']['sha256']),503,'local runtime cannot offer historical server binaries');
            $GLOBALS['published_downloads_config'][$key]=$old;
        }

        $hookCalls=0;binary($service,44);
        $GLOBALS['published_downloads_after_hash']=function()use($service,$next,$current,&$hookCalls){
            $hookCalls++;manifest($service,$next,$current);
        };
        downloaded($service->publishedInstaller($current['installer']['sha256']),installerBytes(42),'0.4.4-preview.42');
        check($hookCalls===1,'real hash boundary exercised during accepted pointer rotation');
        check($service->publishedDownloads()===['current'=>publicRelease($next),'previous'=>publicRelease($current)],
            'new current44 retains pinned42 as previous');

        $invalidWrappers=[];$valid=['format'=>1,'kind'=>'go-erp-published-downloads','current'=>$current,'previous'=>$previous];
        foreach([['format'=>true],['kind'=>'foreign'],['current'=>null],['extra'=>'private']] as $change)$invalidWrappers[]=array_replace($valid,$change);
        $bad=$valid;$bad['previous']['sequence']=$current['sequence'];$invalidWrappers[]=$bad;
        $bad=$valid;$bad['previous']['sequence']=$current['sequence']+1;$invalidWrappers[]=$bad;
        foreach(['version','source_revision'] as $field){$bad=$valid;$bad['previous'][$field]=$current[$field];$invalidWrappers[]=$bad;}
        $bad=$valid;$bad['previous']['installer']=$current['installer'];$invalidWrappers[]=$bad;
        foreach($invalidWrappers as $wrapper){
            file_put_contents($service->directory.'/shared/downloads.json',json_encode($wrapper));chmod($service->directory.'/shared/downloads.json',0640);
            blocked(fn()=>$service->publishedDownloads(),503,'invalid wrapper or nonprevious release ordering refused');
        }
        foreach(['current','previous'] as $slot){
            foreach(['invalid_version','invalid_source','invalid_installer_path','oversized_installer','extra_release_field'] as $kind){
                $wrapper=$valid;
                if($kind==='invalid_version')$wrapper[$slot]['version']='../../program.exe';
                if($kind==='invalid_source')$wrapper[$slot]['source']['sha256']='private';
                if($kind==='invalid_installer_path')$wrapper[$slot]['installer']['path']='https://external.invalid/setup.exe';
                if($kind==='oversized_installer')$wrapper[$slot]['installer']['bytes']=1073741825;
                if($kind==='extra_release_field')$wrapper[$slot]['command']='systemctl start';
                file_put_contents($service->directory.'/shared/downloads.json',json_encode($wrapper));chmod($service->directory.'/shared/downloads.json',0640);
                blocked(fn()=>$service->publishedDownloads(),503,'canonical release contract remains enforced for both slots');
            }
        }
        foreach(['{','[]',str_repeat(' ',2097153)] as $raw){
            file_put_contents($service->directory.'/shared/downloads.json',$raw);chmod($service->directory.'/shared/downloads.json',0640);
            blocked(fn()=>$service->publishedDownloads(),503,'broken or oversized history manifest rejected');
        }

        foreach(['root','shared','installers'] as $slot){
            [$badService]=fixture();binary($badService,42);manifest($badService,$current,null);
            $path=match($slot){'root'=>$badService->directory,'shared'=>$badService->directory.'/shared',
                'installers'=>$badService->directory.'/shared/installers'};
            chmod($path,0777);blocked(fn()=>$badService->publishedDownloads(),503,'unsafe publication directory refused');
        }
        foreach(['manifest','installer'] as $slot){
            foreach(['symlink','hardlink','mode','owner'] as $damage){
                [$badService]=fixture();$installer=binary($badService,42);manifest($badService,$current,null);
                $path=$slot==='manifest'?$badService->directory.'/shared/downloads.json':$installer;
                $outside=$badService->directory.'/outside';
                if($damage==='symlink'){rename($path,$outside);symlink($outside,$path);}
                elseif($damage==='hardlink')link($path,$outside);
                elseif($damage==='mode')chmod($path,0660);
                elseif(!@chown($path,65534))$GLOBALS['published_downloads_foreign_owner']=$path;
                blocked(fn()=>$badService->publishedDownloads(),503,'unsafe historical manifest or binary refused');
                $GLOBALS['published_downloads_foreign_owner']=null;
            }
        }
        [$badService]=fixture();binary($badService,42);manifest($badService,$current,null);
        rename($badService->directory.'/shared/installers',$badService->directory.'/original-installers');
        symlink($badService->directory.'/original-installers',$badService->directory.'/shared/installers');
        blocked(fn()=>$badService->publishedDownloads(),503,'symlink installer directory refused');
        [$badService]=fixture();$path=binary($badService,42);manifest($badService,$current,null);
        file_put_contents($path,'short');chmod($path,0640);
        blocked(fn()=>$badService->publishedDownloads(),503,'declared installer byte size checked before advertising');
        $bytes=installerBytes(42);file_put_contents($path,str_repeat('x',strlen($bytes)));chmod($path,0640);
        blocked(fn()=>$badService->publishedInstaller($current['installer']['sha256']),503,'same-length corruption rejected by real SHA verification');
        binary($badService,42);$GLOBALS['published_downloads_after_hash']=function()use($path,$bytes){
            unlink($path);file_put_contents($path,$bytes);chmod($path,0640);
        };
        blocked(fn()=>$badService->publishedInstaller($current['installer']['sha256']),503,'installer inode replacement after hash refused');

        // Keep the original compatibility-bound APIs unchanged: a server with
        // different current source still hides/rejects the canonical old feed.
        [$guarded,$actualSource]=fixture();$actualSource->forbid=false;
        $GLOBALS['published_downloads_application']=$guarded->directory.'/application';
        mkdir($GLOBALS['published_downloads_application'],0700);mkdir(storage_path(),0700);
        mkdir(storage_path('app'),0700);mkdir(storage_path('app/desktop-updates'),0755);
        $canonical=storage_path('app/desktop-updates/current.json');file_put_contents($canonical,json_encode($current));chmod($canonical,0644);
        $canonicalInstaller=storage_path('app/desktop-updates/'.$current['installer']['sha256'].'.exe');
        file_put_contents($canonicalInstaller,installerBytes(42));chmod($canonicalInstaller,0644);
        check($guarded->status()['state']==='unavailable','original status still rejects changed current source or GUI');
        blocked(fn()=>$guarded->installer($current['installer']['sha256']),409,'original installer source guard remains strict');
        binary($guarded,42);manifest($guarded,$current,null);
        downloaded($guarded->publishedInstaller($current['installer']['sha256']),installerBytes(42),'0.4.4-preview.42');
        echo "GO_ERP_PUBLISHED_DOWNLOADS_TESTS_OK checks=$checks\n";
    }finally{
        foreach($fixtures as $path)removeFixture($path);
    }
}
