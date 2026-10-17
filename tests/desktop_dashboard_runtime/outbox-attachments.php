<?php
// A real encrypted backlog must not load every later attachment before sending one.
use Illuminate\Support\Facades\{Crypt,DB};
use App\Services\Dashboard\DesktopDashboardJournal;

config(['database.connections.mysql.database'=>$phoneLocal,'desktop_dashboard.local'=>true]);DB::purge();
$backlogDevice=(string)\Illuminate\Support\Str::uuid();
$largeBytes=str_pad($attachmentBytes,5*1024*1024,"\n% retained attachment fixture\n");
$largePayload=$expensePayload;
$largePayload['files']['attachment']['base64']=base64_encode($largeBytes);
$largePayload['files']['attachment']['sha256']=hash('sha256',$largeBytes);
$firstBacklogId=null;
try{
    for($n=0;$n<16;$n++){
        $id=(string)\Illuminate\Support\Str::uuid();$firstBacklogId??=$id;
        $largePayload['values']['idempotency_key']=$id;
        DB::table('desktop_dashboard_commands')->insert(['device_id'=>$backlogDevice,'command_id'=>$id,'actor_id'=>1,
            'route_name'=>'branch-expenses.save','request_hash'=>app(DesktopDashboardJournal::class)->fingerprint($largePayload),
            'command_cipher'=>Crypt::encryptString(json_encode($largePayload,JSON_THROW_ON_ERROR)),
            'local_result_cipher'=>Crypt::encryptString(json_encode(['format'=>1,'result'=>$attachmentResult,'references'=>[]],JSON_THROW_ON_ERROR)),
            'dependencies'=>'[]','status'=>'pending','attempts'=>0,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    $probe=$profile.'/bounded-outbox.php';$probeLog=$profile.'/bounded-outbox.log';
    file_put_contents($probe, <<<'PHP'
<?php
$app=require $argv[1].'/desktop/bootstrap.php';
$commands=app(\App\Services\Dashboard\DesktopDashboardJournal::class)->pending($argv[2]);
$file=$commands[0]['payload']['files']['attachment']??[];
echo json_encode(['count'=>count($commands),'id'=>$commands[0]['command_id']??null,
    'bytes'=>strlen(base64_decode($file['base64']??'',true)),
    'sha256'=>hash('sha256',base64_decode($file['base64']??'',true))]);
PHP
    );
    $probeEnv=getenv();$probeEnv['DB_DATABASE']=$phoneLocal;$probeEnv['DESKTOP_DASHBOARD_LOCAL']='true';
    $probeEnv['APP_KEY']=config('app.key');
    $probeProcess=proc_open([PHP_BINARY,'-d','memory_limit=128M',$probe,$application,$backlogDevice],
        [['pipe','r'],['pipe','w'],['file',$probeLog,'a']],$probePipes,$application,$probeEnv);
    fclose($probePipes[0]);$probeOutput=stream_get_contents($probePipes[1]);$probeResult=json_decode($probeOutput,true);fclose($probePipes[1]);
    $probeExit=proc_close($probeProcess);
    if($probeExit!==0)fwrite(STDERR,substr($probeOutput.file_get_contents($probeLog),0,1000));
    check($probeExit===0&&($probeResult['count']??null)===1&&$probeResult['id']===$firstBacklogId
        &&$probeResult['bytes']===5*1024*1024&&$probeResult['sha256']===hash('sha256',$largeBytes),
        'an independent 128 MiB PHP process reads one complete 5 MiB attachment from a sixteen-file encrypted backlog');
    check(app(DesktopDashboardJournal::class)->counts($backlogDevice)['pending']===16,
        'bounded outbox reads preserve every later attachment and its durable pending status');
}finally{
    DB::table('desktop_dashboard_commands')->where('device_id',$backlogDevice)->delete();
    config(['database.connections.mysql.database'=>$remoteDatabase,'desktop_dashboard.device_id'=>$device,'desktop_dashboard.local'=>false]);DB::purge();
}
