#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__.'/whatsapp_capture_release.php';

function wac_restore(string $root,string $requested): int
{
    $run=realpath($requested);
    if(!$run||$run!==$requested||is_link($requested)||$run===$root||str_starts_with($run,$root.'/')||fileowner($run)!==posix_geteuid())wac_fail('RESTORE_DIRECTORY_INVALID');
    $receipt=json_decode(wac_regular($run.'/receipt.json'),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($receipt)||($receipt['format']??null)!==1||($receipt['root']??null)!==$root||($receipt['target']??null)!=='app/Services/Dashboard/CustomerMessageEvents.php'
        ||!is_bool($receipt['service_existed']??null)||($receipt['uid']??null)!==posix_geteuid()||!is_int($receipt['gid']??null)||!is_int($receipt['mode']??null))wac_fail('RESTORE_RECEIPT_INVALID');
    $before=wac_regular($run.'/CustomerMessageEvents.before.php');$after=wac_regular($run.'/CustomerMessageEvents.after.php');
    $target=$root.'/'.$receipt['target'];$serviceTarget=$root.'/app/Services/Dashboard/WhatsAppCustomerDirectory.php';
    $service=wac_regular(__DIR__.'/app/Services/Dashboard/WhatsAppCustomerDirectory.php');
    if(!hash_equals(hash('sha256',$before),(string)($receipt['before_sha256']??''))||!hash_equals(hash('sha256',$after),(string)($receipt['after_sha256']??''))
        ||!hash_equals(hash('sha256',$service),(string)($receipt['service_sha256']??'')))wac_fail('RESTORE_BACKUP_HASH_MISMATCH');
    [$proved]=wac_candidate($before,file_get_contents(__DIR__.'/inbound.before.txt'),file_get_contents(__DIR__.'/inbound.after.txt'));
    if(!hash_equals($proved,$after)||!hash_equals($after,wac_regular($target))||!hash_equals($service,wac_regular($serviceTarget)))wac_fail('RESTORE_TARGET_CHANGED');
    wac_lint($before);wac_atomic($target,$before,hash('sha256',$after),$receipt['mode'],$receipt['uid'],$receipt['gid']);
    if(!$receipt['service_existed']){
        if(!hash_equals($before,wac_regular($target))||!hash_equals($service,wac_regular($serviceTarget))||!unlink($serviceTarget))wac_fail('RESTORE_SERVICE_REMOVE_REFUSED');
    }
    echo wac_json(['status'=>'WHATSAPP_CUSTOMER_CAPTURE_RESTORED','database_writes'=>0])."\n";return 0;
}

function wac_install(array $argv): int
{
    $stage='arguments';$changedService=false;$changedHook=false;$run=null;$source=null;$candidate=null;$target=null;$serviceTarget=null;
    try{
        umask(0077);$args=[];
        for($i=1;$i<count($argv);$i++){
            if(!str_starts_with($argv[$i],'--'))wac_fail('POSITIONAL_ARGUMENT_REFUSED');$key=substr($argv[$i],2);
            if(!in_array($key,['root','backup-dir','restore-from','check','apply','help'],true)||array_key_exists($key,$args))wac_fail('UNKNOWN_OR_DUPLICATE_OPTION');
            if(in_array($key,['check','apply','help'],true))$args[$key]=true;else{$args[$key]=$argv[++$i]??null;if(!is_string($args[$key])||str_starts_with($args[$key],'--'))wac_fail('OPTION_VALUE_MISSING');}
        }
        if(isset($args['help'])){echo "Usage: php install_whatsapp_customer_capture.php --root /home/fasakha/public_html [--check | --apply --backup-dir /private/backups]\nExact guarded code rollback: --apply --restore-from /private/backups/capture-RUN\nDefault: read-only check. No customer ZIP or database migrations.\n";return 0;}
        if(isset($args['check'],$args['apply']))wac_fail('CHOOSE_CHECK_OR_APPLY');$apply=isset($args['apply']);
        if(!function_exists('token_get_all')||!function_exists('posix_geteuid'))wac_fail('PHP_TOKENIZER_AND_POSIX_REQUIRED');
        $root=realpath($args['root']??'');if(!$root||!is_dir($root)||!is_file($root.'/artisan'))wac_fail('LARAVEL_ROOT_INVALID');
        if(posix_geteuid()===0||fileowner($root)!==posix_geteuid())wac_fail('APPLICATION_OWNER_REQUIRED');
        if(isset($args['restore-from'])){if(!$apply)wac_fail('RESTORE_REQUIRES_APPLY');$stage='explicit_restore';return wac_restore($root,$args['restore-from']);}
        $target=$root.'/app/Services/Dashboard/CustomerMessageEvents.php';$serviceTarget=$root.'/app/Services/Dashboard/WhatsAppCustomerDirectory.php';
        $source=wac_regular($target);$before=file_get_contents(__DIR__.'/inbound.before.txt');$after=file_get_contents(__DIR__.'/inbound.after.txt');
        $service=wac_regular(__DIR__.'/app/Services/Dashboard/WhatsAppCustomerDirectory.php');
        if(!is_string($before)||!is_string($after))wac_fail('RELEASE_METHOD_MISSING');
        [$candidate,$installed]=wac_candidate($source,$before,$after);
        $serviceExists=file_exists($serviceTarget)||is_link($serviceTarget);
        if($serviceExists&&!hash_equals($service,wac_regular($serviceTarget)))wac_fail('UNKNOWN_WHATSAPP_CUSTOMER_SERVICE');
        if($installed&&!$serviceExists)wac_fail('INSTALLED_HOOK_MISSING_SERVICE');
        foreach([$target,dirname($target)]as $path)if(fileowner($path)!==posix_geteuid())wac_fail('TARGET_OWNER_INVALID');
        $mode=fileperms($target)&0777;$uid=fileowner($target);$gid=filegroup($target);
        if(!is_writable($target)||!is_writable(dirname($target)))wac_fail('TARGET_NOT_WRITABLE');
        // The existing durable inbox callback must remain in the current consumer.
        $consumer=wac_regular($root.'/app/Services/Dashboard/WhatsAppInboxConsumer.php');
        $consumeMethod=wac_method_span($consumer,'WhatsAppInboxConsumer','consumeIds')[2];
        $consumerReference=file_get_contents(__DIR__.'/consumer.consumeIds.reference.txt');
        if(!is_string($consumerReference)||!hash_equals($consumerReference,$consumeMethod))wac_fail('UNKNOWN_DURABLE_INBOX_CALLBACK');
        $stage='lint';wac_lint($candidate);wac_lint($service);
        $stage='schema';wac_bootstrap($root);wac_schema();
        if(!class_exists(\App\Services\Dashboard\CustomerDirectory::class)||!method_exists(\App\Services\Dashboard\CustomerDirectory::class,'phoneKey'))wac_fail('CUSTOMER_DIRECTORY_DEPENDENCY_MISSING');
        $sourceHash=hash('sha256',$source);$candidateHash=hash('sha256',$candidate);$serviceHash=hash('sha256',$service);
        if(!$apply){echo wac_json(['status'=>'WHATSAPP_CUSTOMER_CAPTURE_CHECK_OK','already_installed'=>$installed,'application_writes'=>0,'database_writes'=>0,'candidate_sha256'=>$candidateHash])."\n";return 0;}
        if($installed){echo wac_json(['status'=>'WHATSAPP_CUSTOMER_CAPTURE_ALREADY_INSTALLED','application_writes'=>0,'database_writes'=>0])."\n";return 0;}
        if(empty($args['backup-dir']))wac_fail('PRIVATE_BACKUP_REQUIRED');
        $stage='backup';$base=wac_private_directory($args['backup-dir'],$root);$run=$base.'/capture-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4));
        if(!mkdir($run,0700))wac_fail('BACKUP_CREATE_FAILED');
        foreach(['CustomerMessageEvents.before.php'=>$source,'CustomerMessageEvents.after.php'=>$candidate]as $file=>$bytes){$path=$run.'/'.$file;
            if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes)||!chmod($path,0600)||hash_file('sha256',$path)!==hash('sha256',$bytes))wac_fail('BACKUP_VERIFY_FAILED');}
        $receipt=['format'=>1,'root'=>$root,'target'=>'app/Services/Dashboard/CustomerMessageEvents.php','before_sha256'=>$sourceHash,
            'after_sha256'=>$candidateHash,'service_sha256'=>$serviceHash,'service_existed'=>$serviceExists,'mode'=>$mode,'uid'=>$uid,'gid'=>$gid];
        if(file_put_contents($run.'/receipt.json',wac_json($receipt)."\n",LOCK_EX)===false||!chmod($run.'/receipt.json',0600))wac_fail('BACKUP_RECEIPT_FAILED');
        if(!hash_equals($source,wac_regular($target)))wac_fail('HOOK_CHANGED_AFTER_PREFLIGHT');
        $stage='service';if(!$serviceExists){wac_atomic($serviceTarget,$service,null,$mode,$uid,$gid);$changedService=true;}
        $stage='hook';wac_atomic($target,$candidate,$sourceHash,$mode,$uid,$gid);$changedHook=true;
        if(!hash_equals($candidate,wac_regular($target))||!hash_equals($service,wac_regular($serviceTarget)))wac_fail('POST_INSTALL_HASH_MISMATCH');
        echo wac_json(['status'=>'WHATSAPP_CUSTOMER_CAPTURE_INSTALLED','backup'=>$run,'application_writes'=>($changedService?2:1),'database_writes'=>0])."\n";return 0;
    }catch(Throwable $e){
        $restored=true;
        if($changedHook){try{wac_atomic($target,$source,hash('sha256',$candidate),$mode,$uid,$gid);$changedHook=false;}catch(Throwable $restoreError){$restored=false;}}
        if($changedService&&!$changedHook){try{if(!hash_equals($source,wac_regular($target)))wac_fail('HOOK_CHANGED_BEFORE_SERVICE_ROLLBACK');if(!hash_equals($service,wac_regular($serviceTarget)))wac_fail('SERVICE_CHANGED_BEFORE_ROLLBACK');if(!unlink($serviceTarget))wac_fail('SERVICE_REMOVE_FAILED');}catch(Throwable $restoreError){$restored=false;}}
        fwrite(STDERR,wac_json(['status'=>'WHATSAPP_CUSTOMER_CAPTURE_FAILED','stage'=>$stage,'error'=>$e instanceof WhatsAppCaptureReleaseError?$e->getMessage():'CAPTURE_INSTALLATION_FAILED','automatic_restore_ok'=>$restored,'backup'=>$run])."\n");return 1;
    }
}
if(!defined('WHATSAPP_CAPTURE_INSTALL_LIBRARY'))exit(wac_install($argv));
