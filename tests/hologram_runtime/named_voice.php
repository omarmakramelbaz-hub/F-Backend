<?php
/** Real named-cache/ticket/controller protocol; no speech model, network or live database. */
require __DIR__.'/run.php';

use App\Services\Dashboard\HomeHologramVoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$before=$checks;
$voice=app(HomeHologramVoice::class);
$voice->fixtureRoot=sys_get_temp_dir().'/hologram-named-'.bin2hex(random_bytes(6));
$voice->fixtureScope=null;
$voice->fixtureNow=1793563200;
app('config')->set('home_hologram_voice', ['ticket_ttl_seconds'=>1800,'pronunciations'=>[]]);
$actor=(object)['id'=>10,'name'=>'Mariam Hassan','account_type'=>'vendor'];
$request=Request::create('https://fixture.invalid/admin/dashboard');$request->setLaravelSession($session);
$freshLogin=function($person,string $nonce='named-voice-login-0001')use($voice,$request,$session){$session->forget('go_hologram_login');return $voice->forLogin($person,$request,['nonce'=>$nonce,'name'=>'UNTRUSTED greeting name']);};
$token=function(array $d):string{preg_match('#/([a-f0-9]{64})/status$#',$d['status_url']??'',$m);if(!isset($m[1]))throw new RuntimeException('Descriptor did not contain an opaque authenticated ticket');return $m[1];};
$jobs=function()use($voice):array{$result=[];foreach(glob($voice->fixtureRoot.'/queue/*.json')?:[]as$p)$result[]=json_decode(file_get_contents($p),true);return $result;};
$jobFor=function($person)use($jobs):array{foreach($jobs()as$j)if($j['actor_id']===(string)$person->id&&$j['display_name']===trim($person->name))return$j;throw new RuntimeException('Expected private queued identity');};
// A real bundled MPEG substitutes for a worker result. Speech semantics are tested
// by the generation pipeline; this harness verifies publication and authorization.
$publish=function(array $identity,array $changes=[])use($voice,$root):array{
 $dir=$voice->fixtureRoot.'/ready/'.$identity['key'];if(!is_dir($dir))mkdir($dir,0700,true);
 copy($root.'/public/dashboard/audio/go-welcome-omar.mp3',$dir.'/audio.mp3');
 $envelope=json_decode(file_get_contents($root.'/public/dashboard/audio/go-welcome-omar.envelope.json'),true);
 file_put_contents($dir.'/envelope.json',json_encode(['sample_rate'=>50,'values'=>$envelope['values']]));
 $meta=$identity;unset($meta['created_at']);$meta+=['duration_seconds'=>count($envelope['values'])/50,'audio_sha256'=>hash_file('sha256',$dir.'/audio.mp3'),'envelope_sha256'=>hash_file('sha256',$dir.'/envelope.json')];$meta=array_replace($meta,$changes);
 file_put_contents($dir.'/metadata.json',json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return[$dir,$meta];
};
try{
 $d=$freshLogin($actor);$ticket=$token($d);
 same('pending',$d['state'],'Cold named cache returns promptly with pending descriptor');
 same(1500,$d['poll_after_ms'],'Readiness polling carries bounded cadence');
 same($voice->fixtureNow+1800,$d['expires_at'],'Ticket expiry is fixed at login');
 $voice->fixtureNow+=20;same($d['expires_at'],$voice->status($actor,$request,$ticket)['expires_at'],'Readiness polls never extend the login attempt expiry');$voice->fixtureNow-=20;
 same(false,isset($d['url']),'Pending cache never exposes an old generic or Omar asset');
 same(1,count($jobs()),'Cold login creates exactly one small private work item');
 $job=$jobFor($actor);same('Mariam Hassan',$job['display_name'],'Spoken work uses authenticated actor display name, not greeting payload');
 same('Mariam Hassan',$job['spoken_name'],'Complete English display name is retained');
 same(HomeHologramVoice::VOICE_VERSION,$job['voice_version'],'Every account uses the same approved voice version');
 same(HomeHologramVoice::TEMPLATE_VERSION,$job['template_version'],'Greeting template is versioned with its audio');
 $queueBefore=glob($voice->fixtureRoot.'/queue/*');for($i=0;$i<3;$i++)same('pending',$voice->status($actor,$request,$ticket)['state'],'Status reads an existing pending attempt');
 same($queueBefore,glob($voice->fixtureRoot.'/queue/*'),'Status polling cannot enqueue work');
 unlink($voice->fixtureRoot.'/queue/'.$job['key'].'.json');$voice->status($actor,$request,$ticket);same([],glob($voice->fixtureRoot.'/queue/*'),'Missing work is never recreated by a GET/status read');
 same('queued',$voice->prewarm($actor),'Explicit preparation may repair a missing work item');same(1,count($jobs()),'Explicit repeated preparation deduplicates account work');
 $voice->prewarm($actor);same(1,count($jobs()),'Repeated preparations do not duplicate work');
 $asset=$voice->fixtureRoot.'/ready/'.$job['key'];mkdir($asset,0700,true);copy($root.'/public/dashboard/audio/go-welcome-omar.mp3',$asset.'/audio.mp3');
 same('pending',$voice->status($actor,$request,$ticket)['state'],'Partially published recording cannot become ready');same(null,$voice->audio($actor,$request,$ticket),'Partial file cannot be streamed');
 [$dir,$metadata]=$publish($job);$ready=$voice->status($actor,$request,$ticket);
 same('ready',$ready['state'],'Only coherent complete publication becomes ready');same(50,$ready['envelope']['sample_rate'],'Named asset carries its own50Hz mouth envelope');
 same($dir.'/audio.mp3',$voice->audio($actor,$request,$ticket)['path'],'Authenticated audio resolves only to the verified private asset');
 same(false,str_contains($ready['url'],'Mariam'),'Audio URL does not disclose display name');same(false,str_contains($ready['url'],'dashboard/audio/'),'Personal recordings never use public bundled-asset paths');
 same('ready',$voice->prewarm($actor),'Prepared cache is reused without another generation');
 foreach([null,(object)['id'=>11,'name'=>$actor->name,'account_type'=>'vendor'],(object)['id'=>10,'name'=>'Laila Hassan','account_type'=>'vendor']]as$foreign){same(null,$voice->status($foreign,$request,$ticket),'Another actor or changed display cannot reuse the ticket');same(null,$voice->audio($foreign,$request,$ticket),'Another actor or changed display cannot stream the cached name');}
 foreach(['../audio',str_repeat('a',63),strtoupper($ticket),str_repeat('f',64)]as$bad)same(null,$voice->audio($actor,$request,$bad),'Malformed or unknown ticket cannot select a file');
 $copied=new Illuminate\Session\Store('voice_other',new Illuminate\Session\ArraySessionHandler(120));$copied->start();$copied->put('go_hologram_voice_ticket',$session->get('go_hologram_voice_ticket'));$other=Request::create('https://fixture.invalid/admin/dashboard');$other->setLaravelSession($copied);
 same(null,$voice->status($actor,$other,$ticket),'Copying a ticket record to another session does not transfer authority');
 $session->put('go_hologram_login',['nonce'=>'newer-login-not-yet-consumed']);same(null,$voice->status($actor,$request,$ticket),'A newer login nonce invalidates the older pending attempt');$session->forget('go_hologram_login');
 $sessionId=$session->getId();$session->migrate();same(null,$voice->audio($actor,$request,$ticket),'Session regeneration revokes the previous audio ticket');$session->setId($sessionId);
 $ticket=$token($freshLogin($actor,'named-voice-login-0002'));$voice->fixtureNow+=1801;same(null,$voice->status($actor,$request,$ticket),'Expired ticket is never renewed by polling');$voice->fixtureNow-=1801;
 $ticket=$token($freshLogin($actor,'named-voice-login-0003'));$old=$ticket;$ticket=$token($freshLogin($actor,'named-voice-login-0004'));same(null,$voice->status($actor,$request,$old),'Issuing a new login ticket revokes the prior token');
 app()->instance('owner.dashboard.runtime',['id'=>'tenant-a']);same(null,$voice->status($actor,$request,$ticket),'Same numeric user ID in another tenant cannot reuse central audio');$tenant=$freshLogin($actor,'named-tenant-login-0001');same('pending',$tenant['state'],'Another tenant cannot silently reuse ready central cache');$tenantJob=null;foreach($jobs()as$queued)if($queued['scope']==='tenant:tenant-a')$tenantJob=$queued;same(true,is_array($tenantJob)&&$tenantJob['key']!==$job['key'],'Tenant cache content keys are distinct');
 app()->instance('owner.dashboard.runtime',['prefix'=>'missing-id']);same('unavailable',$freshLogin($actor)['state'],'Untrusted/missing tenant identity cannot collapse into central');app()->forgetInstance('owner.dashboard.runtime');
 $ticket=$token($freshLogin($actor,'named-voice-login-0005'));
 file_put_contents($dir.'/audio.mp3','tampered');same('pending',$voice->status($actor,$request,$ticket)['state'],'Modified recording bytes invalidate readiness');same(null,$voice->audio($actor,$request,$ticket),'Modified bytes cannot stream');$publish($job);
 foreach([['sample_rate'=>50,'values'=>array_fill(0,50,'0.5')],['sample_rate'=>50,'values'=>array_fill(0,50,1.1)],['sample_rate'=>0,'values'=>[.5]],['sample_rate'=>50,'values'=>['bad'=>.5]]]as$invalid){file_put_contents($dir.'/envelope.json',json_encode($invalid));$meta=$metadata;$meta['envelope_sha256']=hash_file('sha256',$dir.'/envelope.json');file_put_contents($dir.'/metadata.json',json_encode($meta));same(null,$voice->audio($actor,$request,$ticket),'Malformed or wrong-duration mouth envelope fails closed');}$publish($job);
 unlink($dir.'/audio.mp3');symlink($root.'/public/dashboard/audio/go-welcome-omar.mp3',$dir.'/audio.mp3');same(null,$voice->audio($actor,$request,$ticket),'A symlink cannot escape private audio ownership');unlink($dir.'/audio.mp3');$publish($job);
 $publish($job,['voice_version'=>'unapproved-speaker']);same(null,$voice->audio($actor,$request,$ticket),'A different speaker/model cannot substitute for the approved voice');$publish($job);
 foreach([(object)['id'=>0,'name'=>'Mariam','account_type'=>'admin'],(object)['id'=>10,'name'=>'Mariam','account_type'=>'user'],(object)['id'=>10,'name'=>'<script>name</script>','account_type'=>'admin'],(object)['id'=>10,'name'=>'🙂','account_type'=>'admin']]as$invalid)same('unavailable',$freshLogin($invalid)['state'],'Invalid identities never fall back to another account recording');
 $arabic=(object)['id'=>11,'name'=>'عمر مكرم الباز','account_type'=>'admin'];$a=$freshLogin($arabic,'named-arabic-login-0001');same('pending',$a['state'],'Arabic account receives its own queued named recording');$aj=$jobFor($arabic);same(false,(bool)preg_match('/\p{Arabic}/u',$aj['spoken_name']),'Arabic display resolves to bounded Latin pronunciation for approved English voice');same($arabic->name,$aj['display_name'],'Original Arabic display identity remains exact');same(false,$aj['key']===$job['key'],'Different display/account does not collide with another cache');
 $normalized=(object)['id'=>12,'name'=>"  Mary\t O’Neil  ",'account_type'=>'resturant_owner'];same('pending',$freshLogin($normalized)['state'],'Whitespace and apostrophes do not make a legitimate display unavailable');$normalized->name='Mary O’Neil';same('Mary O’Neil',$jobFor($normalized)['spoken_name'],'Name normalization keeps the full display safely');
 foreach(['فرع المنصورة','نبروه','المحلة','فسخانستا','أحمد محمد فرع المنصورة']as$i=>$name){$entry=$voice->prewarmDetails((object)['id'=>100+$i,'name'=>$name,'account_type'=>'vendor']);same('queued',$entry['state'],'Arabic branch/brand/person display has a resolved recording job');same($name,$entry['display_name'],'Arabic identity is preserved in private inventory');same(false,(bool)preg_match('/\p{Arabic}/u',$entry['spoken_name']),'English voice receives resolved Latin pronunciation');}
 class HologramPhoneticFixtureVoice extends HologramFixtureVoice {protected function icuArabic(string $word): ?string {return null;}}
 $phonetic=new HologramPhoneticFixtureVoice($voice->fixtureRoot);
 foreach(['بكر'=>'Bakr','مرزوق'=>'Marzook','زرزور'=>'Zarzoor']as$name=>$spoken){$entry=$phonetic->prewarmDetails((object)['id'=>150,'name'=>$name,'account_type'=>'admin']);same('queued',$entry['state'],'Unlisted Arabic name remains supported without ICU');same($spoken,$entry['spoken_name'],'Bounded fallback produces deterministic pronunciation');same('phonetic',$entry['resolution'],'Approximated pronunciation is disclosed in private inventory');}
 foreach([130,150]as$length)same('queued',$voice->prewarmDetails((object)['id'=>151,'name'=>str_repeat('A',$length),'account_type'=>'vendor'])['state'],'Legitimate full display is accepted without truncation: '.$length);
 same('unavailable',$voice->prewarmDetails((object)['id'=>151,'name'=>str_repeat('A',151),'account_type'=>'vendor'])['state'],'Overlong name cannot create unbounded speech work');
 $longArabic=str_repeat('بكر ',32).'بك';$long=$phonetic->prewarmDetails((object)['id'=>152,'name'=>$longArabic,'account_type'=>'vendor']);same('queued',$long['state'],'A legitimate long Arabic display may expand into a longer pronunciation');same(true,strlen($long['spoken_name'])>130&&strlen($long['spoken_name'])<=HomeHologramVoice::SPOKEN_NAME_MAX,'Pronunciation expansion is bounded separately from display length');
 same('unavailable',$voice->prewarmDetails((object)['id'=>153,'name'=>'中文','account_type'=>'admin'])['state'],'Unsupported scripts fail explicitly without substituting a different account name');
 app('config')->set('home_hologram_voice.pronunciations',['central'=>['154'=>['display_name'=>'زرزور','spoken_name'=>'Zarzoor Family']]]);
 $override=$voice->prewarmDetails((object)['id'=>154,'name'=>'زرزور','account_type'=>'admin']);same('override',$override['resolution'],'Private pronunciation override is bound to exact actor and display');same('Zarzoor Family',$override['spoken_name'],'Verified override is used without changing the speaker');
 same(false,$voice->prewarmDetails((object)['id'=>154,'name'=>'مرزوق','account_type'=>'admin'])['resolution']==='override','Renaming cannot reuse the old pronunciation override');
 same(false,$voice->prewarmDetails((object)['id'=>155,'name'=>'زرزور','account_type'=>'admin'])['resolution']==='override','Another account cannot inherit a pronunciation override');
 $ticket=$token($freshLogin($actor,'named-http-login-0001'));Auth::guard('admin')->setUser(new HologramFixtureUser((array)$actor));$controller=new App\Http\Controllers\Dashboard\HomeHologramVoiceController();
 $media=Request::create('https://fixture.invalid/admin/dashboard/hologram-voice/'.$ticket.'/audio','GET',[],[],[],['HTTP_RANGE'=>'bytes=0-99']);$media->setLaravelSession($session);$response=$controller->audio($media,$voice,$ticket);$response->prepare($media);
 same(206,$response->getStatusCode(),'Authenticated media supports actual HTTP byte ranges');same('100',$response->headers->get('Content-Length'),'Range response reports only requested bytes');same('audio/mpeg',$response->headers->get('Content-Type'),'Protected media uses explicit audio MIME');same(true,$response->headers->hasCacheControlDirective('private')&&$response->headers->hasCacheControlDirective('no-store'),'Actual BinaryFileResponse remains private/no-store');same('nosniff',$response->headers->get('X-Content-Type-Options'),'Audio response cannot be MIME-sniffed');same(false,$response->headers->has('Last-Modified'),'Personal media does not acquire a public last-modified cache validator');
 $status=$controller->status($request,$voice,$ticket);same('ready',json_decode($status->getContent(),true)['state'],'Actual status controller returns descriptor without a wrapper');same(true,$status->headers->hasCacheControlDirective('private')&&$status->headers->hasCacheControlDirective('no-store'),'Readiness response is never a shared cache');
 $head=Request::create('https://fixture.invalid/admin/dashboard/hologram-voice/'.$ticket.'/audio','HEAD');$head->setLaravelSession($session);$headResponse=$controller->audio($head,$voice,$ticket);$headResponse->prepare($head);ob_start();$headResponse->sendContent();same('',ob_get_clean(),'Authenticated HEAD never emits audio bytes');
 $http=Request::create('https://fixture.invalid/admin/dashboard/hologram-voice/'.$ticket.'/status','GET');$http->setLaravelSession($session);$app->instance('request',$http);
 $routed=app('router')->dispatch($http);same(200,$routed->getStatusCode(),'Actual named-voice route authorizes the current dashboard actor');
 Auth::guard('admin')->logout();$guest=Request::create('https://fixture.invalid/admin/dashboard/hologram-voice/'.$ticket.'/audio','GET');$guest->setLaravelSession($session);$app->instance('request',$guest);same(302,app('router')->dispatch($guest)->getStatusCode(),'Anonymous audio request is stopped by the real dashboard guard');
 Auth::guard('admin')->setUser(new HologramFixtureUser((array)$actor));
 class HologramObserverVoiceSpy extends HomeHologramVoice {public array $seen=[];public function prewarm($actor): string {$this->seen[]=(int)$actor->id;return 'queued';}}
 $spy=new HologramObserverVoiceSpy();$app->instance(HomeHologramVoice::class,$spy);$observer=new App\Observers\HomeHologramVoiceObserver();$member=new HologramFixtureUser(['id'=>80,'name'=>'Member','account_type'=>'admin','balance'=>0]);$member->syncOriginal();$observer->created($member);
 $member->balance=100;$member->syncChanges();$observer->updated($member);same([80],$spy->seen,'Financial account updates never enqueue name audio');$member->syncOriginal();$member->name='Renamed Member';$member->syncChanges();$observer->updated($member);same([80,80],$spy->seen,'Changing the actual account display queues new personalized audio');$app->instance(HomeHologramVoice::class,$voice);
 $customer=new HologramFixtureUser(['id'=>81,'name'=>'Customer','account_type'=>'user']);$customer->syncOriginal();$app->instance(HomeHologramVoice::class,$spy);$observer->created($customer);$customer->name='Changed Customer';$customer->syncChanges();$observer->updated($customer);same([80,80],$spy->seen,'Customer directory changes never invoke voice preparation');$customer->syncOriginal();$customer->account_type='admin';$customer->syncChanges();$observer->updated($customer);same([80,80,81],$spy->seen,'A newly eligible dashboard account is prepared');$app->instance(HomeHologramVoice::class,$voice);
 $output=new Symfony\Component\Console\Output\BufferedOutput();$command=new App\Console\Commands\PrewarmHomeHologramVoice();$command->setLaravel($app);$command->setOutput(new Illuminate\Console\OutputStyle(new Symfony\Component\Console\Input\ArrayInput([]),$output));
 same(0,$command->handle($voice),'Prewarm succeeds only when every dashboard account resolves');$inventory=json_decode(file_get_contents($voice->fixtureRoot.'/prewarm-inventory.json'),true);same(['1','10','11','12','13'],array_column($inventory['accounts'],'actor_id'),'Private inventory covers every dashboard role and excludes customer directory');same(0600,fileperms($voice->fixtureRoot.'/prewarm-inventory.json')&0777,'Account/pronunciation inventory is private');same(false,str_contains($output->fetch(),'Actor'),'CLI stdout never dumps personal display names');
 row('users',['id'=>60,'name'=>'中文','account_type'=>'admin']);same(1,$command->handle($voice),'Unresolved dashboard account makes batch preparation exit nonzero');$inventory=json_decode(file_get_contents($voice->fixtureRoot.'/prewarm-inventory.json'),true);same(1,$inventory['counts']['unavailable'],'Unresolved account remains visible in the private rollout inventory');
 $originalRoot=$voice->fixtureRoot;$voice->fixtureRoot=$originalRoot.'/blocked';file_put_contents($voice->fixtureRoot,'not a directory');same('unavailable',$freshLogin($actor)['state'],'Unwritable cache reports unavailable without breaking login');same(false,$voice->writePrewarmInventory([],[]),'Failed private inventory write cannot report successful rollout');
 Auth::guard('admin')->logout();$loginRequest=Request::create('https://fixture.invalid/admin/signin','POST',['email'=>'hologram-fixture@example.invalid','password'=>'FixtureOnly123']);$loginRequest->setLaravelSession($session);$app->instance('request',$loginRequest);$home=app(App\Http\Controllers\Dashboard\HomeController::class);same(302,$home->signin($loginRequest)->getStatusCode(),'Successful login remains independent of an unavailable voice spool');same(10,Auth::guard('admin')->id(),'Voice failure cannot prevent actual account authentication');
 $dashboard=Request::create('https://fixture.invalid/admin/dashboard');$dashboard->setLaravelSession($session);$app->instance('request',$dashboard);same(200,$home->index($dashboard,new HologramFixtureOverview(app(App\Services\Dashboard\TakeawayAccess::class)))->getStatusCode(),'Authenticated dashboard still loads when name audio is unavailable');same('unavailable',$capture->boot['greeting']['audio']['state'],'Failed preparation is explicit without another speaker/name');unlink($voice->fixtureRoot);$voice->fixtureRoot=$originalRoot;
 echo 'PASS authenticated named voice protocol: '.($checks-$before)." assertions\n";
}finally{app()->forgetInstance('owner.dashboard.runtime');app('files')->deleteDirectory($voice->fixtureRoot);}
