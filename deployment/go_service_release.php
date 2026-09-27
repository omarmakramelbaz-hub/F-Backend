<?php
/** CLI-only launch guard. Never send production credentials or backups to CI. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;
use App\Http\Controllers\Api\V1\GoServiceMarketplaceController;
use App\Services\GoServices\Marketplace;
use App\Services\GoServices\Money;
use App\Models\User;
require_once __DIR__.'/go_service_routes.php';
class GoLaunchFailure extends RuntimeException {}
function must($condition, string $message): void { if (!$condition) throw new GoLaunchFailure($message); }
function command(array $args, ?string $cwd = null): string {
    $p = new Process($args, $cwd); $p->setTimeout(180); $p->run();
    must($p->isSuccessful(), 'A required local command failed; output withheld to protect configuration.');
    return trim($p->getOutput());
}
function inspect(): array {
    must(PHP_VERSION_ID >= 80200, 'PHP 8.2 or later is required.');
    $routeIssues = goServiceRouteIssues(app('router'));
    must($routeIssues === [], 'GO route verification failed: '.implode(' ', $routeIssues));
    $dispatch = array_values(array_filter(app(\Illuminate\Console\Scheduling\Schedule::class)->events(),
        static fn($event)=>str_contains($event->command??'', 'go-services:dispatch')));
    must(count($dispatch)===1 && $dispatch[0]->expression==='* * * * *' && $dispatch[0]->withoutOverlapping,
        'GO service dispatch must be scheduled once every minute with overlap protection.');
    must(DB::connection()->getDriverName() === 'mysql', 'This release expects the existing MySQL deployment.');
    must(!config('settings.cache.enabled', false), 'Settings caching must be disabled for atomic application-wallet updates.');
    must(config('settings.default_repository', 'database') === 'database', 'The database settings repository is required.');
    $required = [
        'users' => ['id','name','status','account_type','app_scope','connected','pending_vendor_id','delegate_fees','balance','mobile','email'],
        'pending_vendors' => ['id','application_kind','status','profession_key','lat','lng','work_radius_km'],
        'wallets' => ['id','from_user','to_user','status','payment','type','amount','created_at','updated_at'],
        'settings' => ['id','group','name','payload'],
    ];
    foreach ($required as $table => $columns) {
        foreach ($columns as $column) must(Schema::hasColumn($table, $column), 'Missing required column: '.$table.'.'.$column);
        $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [DB::connection()->getDatabaseName(), DB::connection()->getTablePrefix().$table]);
        must($engine && strtoupper($engine->engine) === 'INNODB', 'Transactional InnoDB is required for '.$table);
    }
    $wallet = DB::table('settings')->where('group','general')->where('name','app_balance')->get();
    must($wallet->count() === 1, 'Exactly one main application-wallet setting is required.');
    $balance = json_decode($wallet[0]->payload, true, 512, JSON_THROW_ON_ERROR);
    must(is_numeric($balance), 'Main application-wallet balance is not numeric.');
    return ['database'=>'mysql','transactional_wallets'=>true,'settings_cache'=>false,'routes_ready'=>true,'dispatch_ready'=>true,'schema_ready'=>Schema::hasTable('go_service_jobs'),'enabled'=>(bool)config('go_services.enabled',false)];
}
function backup(string $root, string $directory): void {
    must(command(['git','status','--porcelain','--untracked-files=no'],$root) === '', 'Tracked server edits exist. Refusing to overwrite them.');
    must(!is_link($directory), 'Backup directory must not be a symbolic link.');
    if (!is_dir($directory)) must(mkdir($directory,0700,true), 'Cannot create the private backup directory.');
    chmod($directory,0700);
    $db = DB::connection()->getConfig();
    must(empty($db['url']), 'Database URL deployments require an explicitly reviewed backup configuration.');
    $dump = (new ExecutableFinder())->find('mysqldump');
    must($dump !== null, 'mysqldump is required before this production release.');
    $ini = $directory.'/mysql-client.cnf'; $archive = $directory.'/database.sql.gz';
    $quote = static function($value): string { return '"'.str_replace(['\\','"',"\n","\r"],['\\\\','\\"','\\n','\\r'],(string)$value).'"'; };
    $client = "[client]\nuser=".$quote($db['username'])."\npassword=".$quote($db['password']??'')."\nhost=".$quote($db['host']??'127.0.0.1')."\nport=".(int)($db['port']??3306)."\n";
    if (!empty($db['unix_socket'])) $client .= 'socket='.$quote($db['unix_socket'])."\n";
    must(file_put_contents($ini,$client) !== false, 'Cannot prepare the private backup client.'); chmod($ini,0600);
    $out = gzopen($archive,'wb6'); must($out !== false,'Cannot write the database backup.');
    try {
        $p = new Process([$dump,'--defaults-extra-file='.$ini,'--single-transaction','--quick','--no-tablespaces','--skip-lock-tables',(string)$db['database']]);
        $p->setTimeout(480);
        $p->run(static function($type,$bytes) use($out) { if ($type === Process::OUT) must(gzwrite($out,$bytes) !== false,'Database backup write failed.'); });
        must($p->isSuccessful(),'Database backup failed; deployment was not started.');
    } finally { gzclose($out); @unlink($ini); }
    chmod($archive,0600); must(filesize($archive)>100,'Database backup is empty.');
    must(copy($root.'/.env',$directory.'/environment.before'),'Cannot back up the environment.'); chmod($directory.'/environment.before',0600);
    $sha = command(['git','rev-parse','HEAD'],$root);
    file_put_contents($directory.'/manifest.json',json_encode(['previous_commit'=>$sha,'database_sha256'=>hash_file('sha256',$archive),'created_at'=>date(DATE_ATOM)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/backup.ok',"complete\n");
}
/** Real Laravel controllers/services, with an in-memory DB and faked outbound
 * notifications/HTTP. Production tables and balances are never mutated. */
function smoke(string $root): int {
    must(extension_loaded('pdo_sqlite'),'pdo_sqlite is required for the isolated launch test.');
    $previous = DB::getDefaultConnection(); $oldConfig = config('go_services');
    config(['database.connections.go_launch_memory'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]]);
    DB::purge('go_launch_memory'); DB::setDefaultConnection('go_launch_memory'); Schema::clearResolvedInstance('db.schema');
    must(DB::connection()->getDriverName()==='sqlite' && DB::connection()->getDatabaseName()===':memory:','Isolation guard failed. No schema changes performed.');
    Notification::fake(); Http::fake();
    config(['go_services.enabled'=>true,'go_services.batch_size'=>2,'go_services.paymob.enabled'=>false]);
    $checks = 0;
    $eq = static function($actual,$expected,string $label) use(&$checks):void { $checks++; must($actual===$expected,'Isolated launch test failed: '.$label); };
    try {
        Schema::create('users',function(Blueprint $t){$t->bigIncrements('id');$t->string('name');$t->string('status');$t->string('account_type');$t->string('app_scope');$t->string('connected');$t->unsignedBigInteger('pending_vendor_id')->nullable();$t->decimal('delegate_fees',6,2)->nullable();$t->decimal('balance',14,2);$t->string('mobile')->default('1010000000');$t->string('email')->default('fixture@example.test');});
        Schema::create('pending_vendors',function(Blueprint $t){$t->bigIncrements('id');$t->string('application_kind');$t->string('status');$t->string('profession_key');$t->decimal('lat',10,7);$t->decimal('lng',11,7);$t->integer('work_radius_km');});
        Schema::create('settings',function(Blueprint $t){$t->bigIncrements('id');$t->string('group');$t->string('name');$t->text('payload');});
        Schema::create('wallets',function(Blueprint $t){$t->bigIncrements('id');$t->unsignedBigInteger('from_user')->nullable();$t->unsignedBigInteger('to_user')->nullable();$t->string('status');$t->string('payment');$t->string('type');$t->decimal('amount',14,2);$t->timestamps();});
        require_once $root.'/database/migrations/2026_09_26_090000_create_go_service_marketplace.php';
        (new CreateGoServiceMarketplace())->up();
        DB::table('settings')->insert(['group'=>'general','name'=>'app_balance','payload'=>'"1000.00"']);
        foreach([1,2] as $id) DB::table('users')->insert(['id'=>$id,'name'=>'Launch fixture','status'=>'accepted','account_type'=>'user','app_scope'=>'go','connected'=>'active','balance'=>'1000.00']);
        for($id=10;$id<=15;$id++){
            DB::table('pending_vendors')->insert(['id'=>$id,'application_kind'=>'partner','status'=>'accepted','profession_key'=>'plumber','lat'=>30,'lng'=>31+($id-10)*0.001,'work_radius_km'=>5]);
            DB::table('users')->insert(['id'=>$id,'name'=>'Launch fixture','status'=>'accepted','account_type'=>'delegate','app_scope'=>'go_partner','connected'=>'active','balance'=>'1000.00','delegate_fees'=>$id===11?'20.00':'10.00','pending_vendor_id'=>$id]);
        }
        $as = static function(int $id):void { auth('api')->setUser((new User())->forceFill((array)DB::table('users')->where('id',$id)->first())); };
        $request = static fn(array $data)=>Request::create('/api/go-services/jobs','POST',$data,[],[],['HTTP_ACCEPT'=>'application/json']);
        $api = new GoServiceMarketplaceController(new Marketplace());
        $data = static fn($response)=>$response->getData(true)['data'];
        $balance = static fn(int $id)=>Money::minor(DB::table('users')->where('id',$id)->value('balance'));
        $appBalance = static fn()=>Money::minor(json_decode(DB::table('settings')->where('name','app_balance')->value('payload'),true));
        $create = static function(string $key)use($as,$request,$api,$data):int {
            $as(1);return $data($api->store($request(['request_key'=>$key,'profession_key'=>'plumber','description'=>'Fix the leaking kitchen sink pipe','area'=>'Fixture area','address'=>'Private fixture address','phone'=>'01010000000','lat'=>30,'lng'=>31])))['id'];
        };
        $quote = static function(int $job,int $partner)use($as,$request,$api,$data):int {
            $as($partner);$j=$data($api->quote($request(['price'=>'100.00','scope'=>'Fix the pipe; final labour price','materials_included'=>false,'arrival_minutes'=>30,'duration_minutes'=>60]),$job));return $j['offers'][0]['id'];
        };
        $j=$create('launch-cash-job-0001');$eq($create('launch-cash-job-0001'),$j,'create retry');
        $eq(DB::table('go_service_recipients')->where('job_id',$j)->count(),2,'initial dispatch');
        $o=$quote($j,10);$other=$quote($j,11);$eq($balance(10),100000,'quotation has no charge');
        $as(10);$eq($data($api->show($j))['phone'],null,'phone protected before selection');
        $as(1);$api->reject($j,$o);$eq(DB::table('go_service_recipients')->where('job_id',$j)->count(),4,'rejection dispatches others');
        $eq(DB::table('go_service_offers')->where('id',$other)->value('status'),'offered','other quote retained');
        $api->accept($request(['payment_method'=>'cash']),$j,$other);$api->accept($request(['payment_method'=>'cash']),$j,$other);
        $eq($balance(11),98000,'individual 20 percent commission once');$eq($appBalance(),102000,'main wallet credited once');
        $as(11);$api->status($request(['status'=>'in_progress']),$j);$api->status($request(['status'=>'awaiting_confirmation']),$j);
        $as(1);$api->status($request(['status'=>'completed','cash_paid'=>true]),$j);$api->status($request(['status'=>'completed','cash_paid'=>true]),$j);
        $eq($balance(11),98000,'cash completion no second debit');$eq(DB::table('go_service_assignments')->count(),0,'assignment released');
        $j=$create('launch-wallet-job-0002');$o=$quote($j,10);$as(1);$api->accept($request(['payment_method'=>'wallet']),$j,$o);
        $eq($balance(1),90000,'customer wallet hold');$eq($balance(10),99000,'partner 10 percent commission');
        $as(10);$api->status($request(['status'=>'in_progress']),$j);$api->status($request(['status'=>'awaiting_confirmation']),$j);
        $as(1);$api->status($request(['status'=>'completed']),$j);$api->status($request(['status'=>'completed']),$j);
        $eq($balance(10),109000,'gross payout prevents double commission');$eq($appBalance(),103000,'only commissions are revenue');
        $j=$create('launch-cancel-job-0003');$o=$quote($j,10);$as(1);$api->accept($request(['payment_method'=>'wallet']),$j,$o);
        $api->status($request(['status'=>'cancelled','reason'=>'Fixture cancellation']),$j);$api->status($request(['status'=>'cancelled','reason'=>'Fixture retry']),$j);
        $eq($balance(1),90000,'customer refund once');$eq($balance(10),109000,'commission refund once');$eq($appBalance(),103000,'commission reversal once');
        $j=$create('launch-insufficient-0004');$o=$quote($j,10);DB::table('users')->where('id',1)->update(['balance'=>'0.00']);$as(1);
        try{$api->accept($request(['payment_method'=>'wallet']),$j,$o);throw new GoLaunchFailure('Insufficient funds were accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$eq($e->getStatusCode(),422,'insufficient funds rejected');}
        $eq($balance(10),109000,'failed acceptance rolls back partner debit');$eq($appBalance(),103000,'failed acceptance rolls back app credit');
        $eq(DB::table('go_service_assignments')->count(),0,'failed acceptance rolls back assignment');
        $as(2);try{$api->show($j);throw new GoLaunchFailure('Another customer read a private job.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$eq($e->getStatusCode(),403,'ownership enforced');}
        Http::assertNothingSent();
        return $checks;
    } finally { DB::disconnect('go_launch_memory'); DB::setDefaultConnection($previous); Schema::clearResolvedInstance('db.schema'); config(['go_services'=>$oldConfig]); }
}
function flag(string $root,bool $value):void {
    $path=$root.'/.env';$before=file_get_contents($path);must($before!==false,'Cannot read environment.');
    $pattern='/^(?:export[ \t]+)?GO_SERVICES_ENABLED[ \t]*=.*$/m';
    $count=preg_match_all($pattern,$before);must($count<=1,'Ambiguous duplicate GO_SERVICES_ENABLED entries.');
    $line='GO_SERVICES_ENABLED='.($value?'true':'false');
    $after=$count===1?preg_replace($pattern,$line,$before):rtrim($before)."\n".$line."\n";
    $temp=tempnam($root,'.go-env-');must($temp!==false,'Cannot prepare atomic environment update.');$stat=stat($path);
    try { must(file_put_contents($temp,$after)!==false,'Cannot write environment update.');chmod($temp,$stat['mode']&0777);chown($temp,$stat['uid']);chgrp($temp,$stat['gid']);must(hash_equals(hash('sha256',$before),hash_file('sha256',$path)),'Environment changed during release; stopped.');must(rename($temp,$path),'Atomic environment replacement failed.'); }
    finally { if(is_file($temp))unlink($temp); }
}
$stage='arguments';
try {
    // Bootstrap can rebuild Laravel's shared package/service manifests. Keep
    // these readable by the web worker; private artifacts get a separate mask.
    umask(0022);$mode=$argv[1]??'';$root=realpath($argv[2]??'');$run=$argv[3]??'0';
    must(in_array($mode,['inspect','backup','test','activate','disable'],true),'Unknown release mode.');
    must($root!==false&&is_file($root.'/artisan')&&is_file($root.'/.env'),'Invalid Laravel application directory.');must(ctype_digit($run),'Invalid release run identifier.');
    $stage='bootstrap';require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Kernel::class)->bootstrap();
    $dir=dirname($root).'/go-services-backups/run-'.$run;$receipt=$root.'/storage/app/go-services-launch.json';
    if($mode==='disable'){
        $r=is_file($receipt)?json_decode(file_get_contents($receipt),true):null;
        if($r&&($r['run']??null)===$run){flag($root,false);echo "GO launch disabled after failed verification; existing jobs remain manageable.\n";}
        exit;
    }
    $stage='schema and wallet preflight';$report=inspect();
    if($mode==='inspect'){echo json_encode($report,JSON_THROW_ON_ERROR)."\n";exit;}
    if($mode==='backup'){$stage='private backup';umask(0077);backup($root,$dir);echo "Private database/environment backup verified. No deployment performed by this step.\n";exit;}
    $stage='isolated Laravel controller test';$checks=smoke($root);echo 'PASS '.$checks." isolated Laravel marketplace checks; no real balances or gateway calls.\n";
    if($mode==='test')exit;
    $stage='activation preconditions';must(is_file($dir.'/backup.ok'),'A verified backup for this deployment run is required.');must($report['schema_ready'],'The marketplace migration is not installed.');
    must(!config('go_services.paymob.enabled',false),'Dedicated electronic checkout needs separate gateway acceptance; refusing automatic activation.');
    if(is_file($receipt)){echo "Launch already recorded. Existing feature-flag choice retained.\n";exit;}
    $stage='enable cash and application-wallet marketplace';flag($root,true);
    umask(0077); // The operator-only launch receipt is not a Laravel cache file.
    must(file_put_contents($receipt,json_encode(['run'=>$run,'checks'=>$checks,'activated_at'=>date(DATE_ATOM),'backup_directory'=>$dir],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))!==false,'Cannot record launch receipt.');
    echo "__GO_RELEASE_ENABLED__\n";
} catch(Throwable $e) {
    fwrite(STDERR,'GO release stopped at '.$stage.'. '.($e instanceof GoLaunchFailure?$e->getMessage():'Failure type: '.get_class($e).'; sensitive details withheld.')."\n");exit(1);
}
