#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__.'/lookup_helpers.php';

function lci_lint_candidate(string $path): void {
    $pipes=[];
    $p=proc_open([PHP_BINARY,'-l',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p)) lci_fail('Cannot start PHP syntax check.');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if(proc_close($p)!==0) lci_fail('Candidate failed PHP syntax check.');
}

function lci_lint_bytes(string $bytes): void {
    $pipes=[]; $p=proc_open([PHP_BINARY,'-l'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p)) lci_fail('Cannot start PHP syntax check.');
    $offset=0;
    while($offset<strlen($bytes)) { $n=fwrite($pipes[0],substr($bytes,$offset)); if($n===false||$n===0) lci_fail('Cannot supply candidate for syntax check.'); $offset+=$n; }
    fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if(proc_close($p)!==0) lci_fail('Candidate failed PHP syntax check.');
}

function lci_replace_lookup(string $target, string $bytes, string $expectedCurrent): void {
    $temp=null;
    try {
        if(!hash_equals($expectedCurrent,hash_file('sha256',$target)?:'')) lci_fail('Target changed before atomic replacement.');
        $temp=tempnam(dirname($target),'.legacy-customer-lookup-');
        if($temp===false||file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes)) lci_fail('Cannot stage atomic replacement.');
        if(!chown($temp,fileowner($target))||!chgrp($temp,filegroup($target))||!chmod($temp,fileperms($target)&0777)) lci_fail('Cannot preserve target ownership and mode.');
        if(hash_file('sha256',$temp)!==hash('sha256',$bytes)) lci_fail('Staged candidate checksum differs.');
        if(!hash_equals($expectedCurrent,hash_file('sha256',$target)?:'')) lci_fail('Target changed before atomic replacement.');
        if(!rename($temp,$target)) lci_fail('Cannot replace target atomically.'); $temp=null;
        if(function_exists('opcache_invalidate')) opcache_invalidate($target,true);
    } finally { if($temp!==null&&is_file($temp)) unlink($temp); }
}

function lci_lookup_install(array $argv): int {
    $stage='arguments'; $temp=null;
    try {
        umask(0077); $args=[];
        for($i=1;$i<count($argv);$i++) {
            if(!str_starts_with($argv[$i],'--')) lci_fail('Unexpected positional argument.');
            $v=explode('=',substr($argv[$i],2),2); $key=$v[0];
            if(!in_array($key,['root','backup-dir','restore-from','dry-run','apply','help'],true)||array_key_exists($key,$args)) lci_fail('Unknown or duplicated option.');
            if(in_array($key,['dry-run','apply','help'],true)) { if(count($v)!==1) lci_fail('Flag does not accept a value.'); $args[$key]=true; }
            else { $args[$key]=$v[1]??($argv[++$i]??null); if(!is_string($args[$key])||str_starts_with($args[$key],'--')) lci_fail('Option requires a value.'); }
        }
        if(isset($args['help'])) { echo "Usage: php install_phone_lookup.php --root /home/fasakha/public_html [--dry-run | --apply --backup-dir /private/backups]\nRestore own new patch after failed import: --apply --restore-from /private/backups/run/PosServicePhone.before.php\nDefault: dry-run without filesystem writes; exact known base, V1, V2 or this V3 lookup only.\n"; return 0; }
        if(isset($args['apply'],$args['dry-run'])) lci_fail('Choose apply or dry-run.');
        $apply=isset($args['apply']); $root=realpath($args['root']??'');
        if(!$root||!is_dir($root)||!is_file($root.'/artisan')) lci_fail('Laravel root is invalid.');
        $relative='/app/Services/Dashboard/PosServicePhone.php'; $target=$root.$relative;
        if(!is_file($target)||is_link($target)||realpath($target)!==$target) lci_fail('PosServicePhone target is missing or symlinked.');
        $source=file_get_contents($target); $snippet=file_get_contents(__DIR__.'/phone_lookup_insertion.txt');
        $v1Snippet=file_get_contents(__DIR__.'/phone_lookup_v1_reference.txt');
        $v2Snippet=file_get_contents(__DIR__.'/phone_lookup_v2_reference.txt');
        if($source===false||$snippet===false||$v1Snippet===false||$v2Snippet===false) lci_fail('Cannot read source or insertion snippet.');
        $snippet=rtrim($snippet,"\r\n")."\n";
        $v1Snippet=rtrim($v1Snippet,"\r\n")."\n";
        $v2Snippet=rtrim($v2Snippet,"\r\n")."\n";
        $anchor="        \$query=DB::table('pos_service_tickets')->where('channel','phone')->whereIn('branch',\$scope);\n";
        $baseSha='1a42ef9691ef731dbe23d469a6b0ffb7658dd229b331045faec223c4cffeada5';
        $v1Sha='be4c837346b9a7d6973514b29f31dc44c75d447ac145351d1a17adc049671c18';
        $baseOf=function(string $bytes)use($baseSha,$v1Sha,$v1Snippet,$v2Snippet):string {
            $sha=hash('sha256',$bytes);
            if($sha===$baseSha) return $bytes;
            if($sha===$v1Sha&&substr_count($bytes,$v1Snippet)===1) {
                $base=str_replace($v1Snippet,'',$bytes);
                if(hash('sha256',$base)===$baseSha) return $base;
            }
            if(substr_count($bytes,$v2Snippet)===1) {
                $base=str_replace($v2Snippet,'',$bytes);
                if(hash('sha256',$base)===$baseSha) return $base;
            }
            lci_fail('Unknown PosServicePhone version; a new compatibility review is required.');
        };
        $sourceSha=hash('sha256',$source);
        if(isset($args['restore-from'])) {
            if(!$apply) lci_fail('Restoring a lookup backup requires --apply.');
            $backup=realpath($args['restore-from']);
            if(!$backup||!is_file($backup)||is_link($args['restore-from'])||$backup===$root||str_starts_with($backup,$root.'/')) lci_fail('Restore backup must be a regular private file outside Laravel root.');
            $before=file_get_contents($backup);
            if($before===false) lci_fail('Cannot read restore backup.');
            $restoreBase=$baseOf($before);
            if(substr_count($restoreBase,$anchor)!==1) lci_fail('Restore backup lacks reviewed insertion anchor.');
            $patched=str_replace($anchor,$snippet.$anchor,$restoreBase);
            if($sourceSha!==hash('sha256',$patched)) lci_fail('Target changed after this lookup was installed; refusing automatic restore.');
            lci_lint_bytes($before); lci_replace_lookup($target,$before,$sourceSha);
            echo lci_json(['status'=>'restored','file_sha256'=>hash('sha256',$before)])."\n"; return 0;
        }
        if(str_contains($source,'UNIFIED_CUSTOMER_DIRECTORY_LOOKUP_V3_BEGIN')) {
            if(substr_count($source,$snippet)!==1||hash('sha256',str_replace($snippet,'',$source))!==$baseSha) lci_fail('Existing lookup patch differs from this reviewed release.');
            echo lci_json(['status'=>'already_installed','file_sha256'=>$sourceSha])."\n"; return 0;
        }
        $baseSource=$baseOf($source);
        if(substr_count($baseSource,$anchor)!==1) lci_fail('Reviewed phone lookup insertion anchor is missing or repeated.');
        $candidate=str_replace($anchor,$snippet.$anchor,$baseSource);
        $sha=hash('sha256',$candidate);
        if(!$apply) {
            lci_lint_bytes($candidate);
            echo lci_json(['status'=>'dry_run','target_writes'=>0,'filesystem_writes'=>0,'compatible'=>true,'php_lint'=>true,'candidate_sha256'=>$sha])."\n"; return 0;
        }
        if(empty($args['backup-dir'])) lci_fail('--backup-dir outside the Laravel root is required for candidate lint and backups.');
        $stage='candidate'; $base=lci_private_directory($args['backup-dir'],$root);
        $run=$base.'/lookup-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4));
        if(!mkdir($run,0700)) lci_fail('Cannot create private lookup check directory.');
        $candidatePath=$run.'/PosServicePhone.candidate.php';
        $h=fopen($candidatePath,'x'); if(!$h) lci_fail('Cannot create candidate file.');
        try { if(fwrite($h,$candidate)!==strlen($candidate)||!fflush($h)) lci_fail('Cannot write candidate file.'); } finally { fclose($h); }
        if(!chmod($candidatePath,0600)) lci_fail('Cannot secure candidate file.');
        lci_lint_candidate($candidatePath);
        $stage='backup';
        $backup=$run.'/PosServicePhone.before.php';
        if(file_put_contents($backup,$source,LOCK_EX)!==strlen($source)||!chmod($backup,0600)||hash_file('sha256',$backup)!==$sourceSha) lci_fail('Cannot create verified source backup.');
        lci_private_json($run.'/manifest.json',['format'=>1,'target'=>$relative,'source_sha256'=>$sourceSha,'candidate_sha256'=>$sha]);
        if(!hash_equals($sourceSha,hash_file('sha256',$target)?:'')) lci_fail('Target changed after review.');
        $stage='atomic_replace'; lci_replace_lookup($target,$candidate,$sourceSha);
        echo lci_json(['status'=>'installed','php_lint'=>true,'file_sha256'=>$sha,'backup_created'=>true,'backup_file'=>$backup])."\n"; return 0;
    } catch(Throwable $e) {
        $safe=$e instanceof LegacyCustomerImportException?$e->getMessage():'Lookup installation failed.';
        fwrite(STDERR,lci_json(['status'=>'failed','stage'=>$stage,'error'=>$safe])."\n"); return 1;
    } finally { if($temp!==null&&is_file($temp)) unlink($temp); }
}

exit(lci_lookup_install($argv));
