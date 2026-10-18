#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__.'/../whatsapp_capture_release.php';

function wui_release(): array
{
    $files=[
        'directory-save.before.txt'=>'f4bb2f5b5ac7832cee53bb5eeaed8acc4ecc6af8c2aa56a5e08564949f87a99e',
        'directory-save.after.txt'=>'2ed401336189df722815a7a40ba5dfd43308a470f06b51db263fefd5f23c13e3',
        'customer-crm.before.js'=>'c544283df4d0587e93993daeba1a0c9a94906d2b5c56f395281d09bb1175a8d5',
        'customer-crm.after.js'=>'af4902f1b4293f12bf6ad4aeffa25d73239be7a9f6dc59cb65e354c83b8f6296',
    ];$out=[];
    foreach($files as $name=>$hash){$bytes=wac_regular(__DIR__.'/'.$name);if(!hash_equals($hash,hash('sha256',$bytes)))wac_fail('UI_RELEASE_HASH_INVALID');$out[$name]=$bytes;}
    return $out;
}

function wui_candidate(string $kind,string $source,array $release): array
{
    if($kind==='backend'){
        [$start,$end,$method]=wac_method_span($source,'BranchCustomers','directorySave');
        if(hash_equals($release['directory-save.after.txt'],$method))return[$source,true];
        if(!hash_equals($release['directory-save.before.txt'],$method))wac_fail('UNKNOWN_DIRECTORY_SAVE_METHOD');
        return[substr($source,0,$start).$release['directory-save.after.txt'].substr($source,$end),false];
    }
    if($kind!=='frontend')wac_fail('UI_TARGET_KIND_INVALID');
    // These two presentation edits must preserve later template-setup changes.
    // Each reviewed expression occurs exactly once in either its before or after form.
    $edits=[
        "var names={takeaway:'عميل تيك أواي',dine:'عميل صالة',delivery:'عميل دليفري',app:'عميل تطبيق'};"
            =>"var names={takeaway:'عميل تيك أواي',dine:'عميل صالة',delivery:'عميل دليفري',app:'عميل تطبيق',whatsapp:'واتساب'};",
        "if(!item.branch)f.appendChild(n('p','عميل تطبيق لم يرتبط بفرع بعد.','op-help'));"
            =>"if(!item.branch)f.appendChild(n('p','عميل لم يرتبط بفرع بعد.','op-help'));",
    ];
    $installed=true;$candidate=$source;$proof=$release['customer-crm.before.js'];
    foreach($edits as $before=>$after){
        if(substr_count($proof,$before)!==1||substr_count($proof,$after)!==0)wac_fail('UI_RELEASE_ANCHOR_INVALID');
        $proof=str_replace($before,$after,$proof);
        $old=substr_count($candidate,$before);$new=substr_count($candidate,$after);
        if($old+$new!==1)wac_fail('UNKNOWN_OR_AMBIGUOUS_CUSTOMER_CRM_ANCHOR');
        if($old===1){$candidate=str_replace($before,$after,$candidate);$installed=false;}
    }
    if(!hash_equals($release['customer-crm.after.js'],$proof))wac_fail('UI_RELEASE_PATCH_PROOF_INVALID');
    return[$candidate,$installed];
}

function wui_definitions(): array
{
    return ['backend'=>'app/Services/Dashboard/BranchCustomers.php','frontend'=>'public/dashboard/js/customer-crm.js'];
}

function wui_plan(string $root,string $kind,string $from,string $to): array
{
    $relative=wui_definitions()[$kind]??null;if(!$relative)wac_fail('UI_TARGET_KIND_INVALID');$path=$root.'/'.$relative;
    foreach([$path,dirname($path)]as $owned)if(fileowner($owned)!==posix_geteuid())wac_fail('UI_TARGET_OWNER_INVALID');
    if(!is_writable($path)||!is_writable(dirname($path)))wac_fail('UI_TARGET_NOT_WRITABLE');
    return ['kind'=>$kind,'relative'=>$relative,'path'=>$path,'from'=>$from,'to'=>$to,'mode'=>fileperms($path)&0777,'uid'=>fileowner($path),'gid'=>filegroup($path)];
}

function wui_save(string $path,string $bytes): void
{
    $h=fopen($path,'x');if(!$h)wac_fail('UI_BACKUP_CREATE_FAILED');
    try{if(!chmod($path,0600)||fwrite($h,$bytes)!==strlen($bytes)||!fflush($h))wac_fail('UI_BACKUP_WRITE_FAILED');if(function_exists('fsync')&&!fsync($h))wac_fail('UI_BACKUP_SYNC_FAILED');}
    finally{fclose($h);}if(hash_file('sha256',$path)!==hash('sha256',$bytes))wac_fail('UI_BACKUP_VERIFY_FAILED');
}

function wui_restore_plans(string $root,string $requested,array $release): array
{
    $run=realpath($requested);
    if(!$run||$run!==$requested||is_link($requested)||$run===$root||str_starts_with($run,$root.'/')||fileowner($run)!==posix_geteuid())wac_fail('UI_RESTORE_DIRECTORY_INVALID');
    $receipt=json_decode(wac_regular($run.'/receipt.json'),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($receipt)||($receipt['format']??null)!==1||($receipt['root']??null)!==$root||!is_array($receipt['targets']??null)||!$receipt['targets'])wac_fail('UI_RESTORE_RECEIPT_INVALID');
    $plans=[];$seen=[];
    foreach($receipt['targets']as $entry){
        $kind=$entry['kind']??'';$relative=wui_definitions()[$kind]??null;
        if(!$relative||isset($seen[$kind])||($entry['relative']??null)!==$relative||($entry['uid']??null)!==posix_geteuid()
            ||!is_int($entry['gid']??null)||!is_int($entry['mode']??null)||$entry['mode']<0||$entry['mode']>0777)wac_fail('UI_RESTORE_RECEIPT_INVALID');$seen[$kind]=true;
        $before=wac_regular($run.'/'.$kind.'.before');$after=wac_regular($run.'/'.$kind.'.after');
        if(!hash_equals(hash('sha256',$before),(string)($entry['before_sha256']??''))||!hash_equals(hash('sha256',$after),(string)($entry['after_sha256']??'')))wac_fail('UI_RESTORE_BACKUP_HASH_INVALID');
        [$proved,$installed]=wui_candidate($kind,$before,$release);
        if($installed||!hash_equals($proved,$after)||!hash_equals($after,wac_regular($root.'/'.$relative)))wac_fail('UI_RESTORE_TARGET_CHANGED');
        if($kind==='backend')wac_lint($before);
        $plan=wui_plan($root,$kind,$after,$before);
        if($plan['uid']!==$entry['uid']||$plan['gid']!==$entry['gid']||$plan['mode']!==$entry['mode'])wac_fail('UI_RESTORE_TARGET_METADATA_CHANGED');
        $plans[]=$plan;
    }
    return array_reverse($plans);
}

function wui_install(array $argv): int
{
    $stage='arguments';$applied=[];$run=null;
    try{
        umask(0077);$args=[];
        for($i=1;$i<count($argv);$i++){
            if(!str_starts_with($argv[$i],'--'))wac_fail('UI_POSITIONAL_ARGUMENT_REFUSED');$key=substr($argv[$i],2);
            if(!in_array($key,['root','backup-dir','restore-from','check','apply','help'],true)||array_key_exists($key,$args))wac_fail('UI_UNKNOWN_OR_DUPLICATE_OPTION');
            if(in_array($key,['check','apply','help'],true))$args[$key]=true;else{$args[$key]=$argv[++$i]??null;if(!is_string($args[$key])||str_starts_with($args[$key],'--'))wac_fail('UI_OPTION_VALUE_MISSING');}
        }
        if(isset($args['help'])){echo "Usage: php ui/install_customer_crm_whatsapp_ui.php --root /home/fasakha/public_html [--check | --apply --backup-dir /private/backups]\nGuarded rollback: --apply --restore-from /private/backups/ui-RUN\nDefault: read-only check; no Laravel bootstrap or database writes.\n";return 0;}
        if(isset($args['check'],$args['apply']))wac_fail('UI_CHOOSE_CHECK_OR_APPLY');$apply=isset($args['apply']);
        if(!function_exists('token_get_all')||!function_exists('posix_geteuid'))wac_fail('UI_TOKENIZER_AND_POSIX_REQUIRED');
        $root=realpath($args['root']??'');if(!$root||!is_dir($root)||!is_file($root.'/artisan'))wac_fail('UI_LARAVEL_ROOT_INVALID');
        if(posix_geteuid()===0||fileowner($root)!==posix_geteuid())wac_fail('UI_APPLICATION_OWNER_REQUIRED');$release=wui_release();
        if(isset($args['restore-from'])){
            if(!$apply)wac_fail('UI_RESTORE_REQUIRES_APPLY');$stage='restore_preflight';$plans=wui_restore_plans($root,$args['restore-from'],$release);
            $stage='restore';foreach($plans as $plan){wac_atomic($plan['path'],$plan['to'],hash('sha256',$plan['from']),$plan['mode'],$plan['uid'],$plan['gid']);$applied[]=$plan;}
            echo wac_json(['status'=>'CRM_WHATSAPP_UI_RESTORED','application_writes'=>count($applied),'database_writes'=>0])."\n";return 0;
        }
        $stage='preflight';$plans=[];
        foreach(wui_definitions()as $kind=>$relative){$source=wac_regular($root.'/'.$relative);[$candidate,$installed]=wui_candidate($kind,$source,$release);$plan=wui_plan($root,$kind,$source,$candidate);
            if($kind==='backend')wac_lint($candidate);if(!$installed)$plans[]=$plan;}
        if(!$apply){echo wac_json(['status'=>'CRM_WHATSAPP_UI_CHECK_OK','already_installed'=>!$plans,'application_writes'=>0,'filesystem_writes'=>0,'database_writes'=>0,'pending_targets'=>count($plans)])."\n";return 0;}
        if(!$plans){echo wac_json(['status'=>'CRM_WHATSAPP_UI_ALREADY_INSTALLED','application_writes'=>0,'database_writes'=>0])."\n";return 0;}
        if(empty($args['backup-dir']))wac_fail('UI_PRIVATE_BACKUP_REQUIRED');$stage='backup';$base=wac_private_directory($args['backup-dir'],$root);$run=$base.'/ui-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4));
        if(!mkdir($run,0700))wac_fail('UI_BACKUP_DIRECTORY_FAILED');$receipt=['format'=>1,'root'=>$root,'targets'=>[]];
        foreach($plans as $plan){wui_save($run.'/'.$plan['kind'].'.before',$plan['from']);wui_save($run.'/'.$plan['kind'].'.after',$plan['to']);
            $receipt['targets'][]=array_intersect_key($plan,array_flip(['kind','relative','mode','uid','gid']))+['before_sha256'=>hash('sha256',$plan['from']),'after_sha256'=>hash('sha256',$plan['to'])];}
        wui_save($run.'/receipt.json',wac_json($receipt)."\n");
        $stage='apply';foreach($plans as $plan){wac_atomic($plan['path'],$plan['to'],hash('sha256',$plan['from']),$plan['mode'],$plan['uid'],$plan['gid']);$applied[]=$plan;}
        foreach($plans as $plan)if(!hash_equals($plan['to'],wac_regular($plan['path'])))wac_fail('UI_POST_INSTALL_HASH_INVALID');
        echo wac_json(['status'=>'CRM_WHATSAPP_UI_INSTALLED','backup'=>$run,'application_writes'=>count($applied),'database_writes'=>0])."\n";return 0;
    }catch(Throwable $e){
        $restored=true;foreach(array_reverse($applied)as $plan){try{wac_atomic($plan['path'],$plan['from'],hash('sha256',$plan['to']),$plan['mode'],$plan['uid'],$plan['gid']);}catch(Throwable $restoreError){$restored=false;}}
        fwrite(STDERR,wac_json(['status'=>'CRM_WHATSAPP_UI_FAILED','stage'=>$stage,'error'=>$e instanceof WhatsAppCaptureReleaseError?$e->getMessage():'CRM_UI_INSTALLATION_FAILED','automatic_restore_ok'=>$restored,'backup'=>$run])."\n");return 1;
    }
}
if(!defined('WHATSAPP_CRM_UI_INSTALL_LIBRARY'))exit(wui_install($argv));
