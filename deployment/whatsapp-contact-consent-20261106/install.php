<?php
declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\Dashboard\WhatsAppCustomerDirectory;

const WCP_KEY='f8d0ed26-2026-4116-a019-000000000001';
const WCP_KIND='whatsapp.contact.consent.defaults';
const WCP_OLD='d8443c1e42eeb2f4ca9813312751ea949732004fed850e299d569f933a6e67c3';
const WCP_NEW='3238b006a5a5692b69fd1548d3fcf697bc59a3bc68f6489f096c12465ab7e752';

class WcpException extends RuntimeException {}
function wcp_fail(string $code): void {throw new WcpException($code);}
function wcp_json($v): string {return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function wcp_hash(): string {return hash('sha256','owner-policy:whatsapp-contact-20261106;undecided-only;service+marketing;preserve-withdrawals');}
function wcp_scope(bool $lock=false): array {
    $q=DB::table('crm_customers')->whereIn('id',DB::table('crm_customer_sources')->select('customer_id')->where('channel','whatsapp'))->orderBy('id');
    if($lock)$q->lockForUpdate();
    return $q->get()->map(fn($r)=>(array)$r)->all();
}
function wcp_counts(array $rows): array {
    $c=['total'=>count($rows),'changed'=>0,'unchanged'=>0,'skipped_optouts'=>0,'skipped_denials'=>0,'service_eligible'=>0,'marketing_eligible'=>0];
    foreach($rows as $r){
        $changes=WhatsAppCustomerDirectory::consentDefaults($r,'preview');
        $c[$changes?'changed':'unchanged']++;
        if($r['opted_out_at']!==null)$c['skipped_optouts']++;
        $denied=false;
        foreach(['service','marketing'] as $purpose){
            if(!(bool)$r[$purpose.'_consent']&&($r[$purpose.'_consented_at']!==null
                ||trim((string)$r[$purpose.'_consent_evidence'])!==''||$r[$purpose.'_consented_by']!==null))$denied=true;
            $c[$purpose.'_eligible']+=(int)((bool)$r[$purpose.'_consent']&&!empty($r[$purpose.'_consented_at'])&&$r['opted_out_at']===null);
        }
        if($denied&&$r['opted_out_at']===null)$c['skipped_denials']++;
    }
    return $c;
}
function wcp_receipt(): ?array {
    $r=DB::table('branch_operation_commands')->where('branch','')->where('actor_id',1)->where('request_key',WCP_KEY)->first();
    if(!$r)return null;
    if($r->kind!==WCP_KIND||$r->request_hash!==wcp_hash())wcp_fail('POLICY_RECEIPT_MISMATCH');
    $v=json_decode($r->result,true,512,JSON_THROW_ON_ERROR);
    if(($v['status']??'')!=='applied'||($v['policy']??'')!=='whatsapp-contact-20261106')wcp_fail('POLICY_RECEIPT_INVALID');
    return $v;
}
function wcp_preview(): array {
    $old=wcp_receipt();
    return ['status'=>$old?'already_applied':'dry_run','data_writes'=>0,'counts'=>wcp_counts(wcp_scope()),'previous_application'=>$old];
}
function wcp_private_base(string $base): string {
    if(!$base||$base[0]!=='/'||is_link($base)||str_contains($base,"\0")||array_intersect(explode('/',$base),['.','..']))wcp_fail('PRIVATE_PATH_INVALID');
    if(!is_dir($base)&&!mkdir($base,0700,true))wcp_fail('PRIVATE_DIRECTORY_FAILED');
    $real=realpath($base);
    if(!$real||$real==='/home/fasakha/public_html'||str_starts_with($real,'/home/fasakha/public_html/'))wcp_fail('PRIVATE_DIRECTORY_REQUIRED');
    if(!chmod($real,0700))wcp_fail('PRIVATE_DIRECTORY_PERMISSIONS');
    return $real;
}
function wcp_write(string $path,string $bytes,int $mode=0600): void {
    $h=fopen($path,'x+b');if(!$h)wcp_fail('PRIVATE_WRITE_OPEN_FAILED');
    try{
        if(!chmod($path,$mode))wcp_fail('PRIVATE_WRITE_PERMISSIONS');
        for($offset=0;$offset<strlen($bytes);$offset+=$n){$n=fwrite($h,substr($bytes,$offset));if($n===false||$n===0)wcp_fail('PRIVATE_WRITE_FAILED');}
        if(!fflush($h)||(function_exists('fsync')&&!fsync($h)))wcp_fail('PRIVATE_WRITE_FLUSH_FAILED');
    }finally{fclose($h);}
    if(!hash_equals(hash('sha256',$bytes),hash_file('sha256',$path)?:''))wcp_fail('PRIVATE_WRITE_VERIFY_FAILED');
}
function wcp_apply(string $backupBase): array {
    return DB::transaction(function()use($backupBase){
        $rows=wcp_scope(true);$old=wcp_receipt();
        if($old)return ['status'=>'already_applied','data_writes'=>0,'counts'=>wcp_counts($rows),'previous_application'=>$old];
        $base=wcp_private_base($backupBase);$run=$base.'/backfill-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(5));
        if(!mkdir($run,0700))wcp_fail('BACKUP_RUN_FAILED');
        $fields=['id','phone','service_consent','marketing_consent','service_consented_at','marketing_consented_at',
            'service_consent_evidence','marketing_consent_evidence','service_consented_by','marketing_consented_by','opted_out_at','updated_at'];
        $snapshot=array_map(fn($r)=>array_intersect_key($r,array_flip($fields)),$rows);
        wcp_write($run.'/before.json',wcp_json(['version'=>1,'policy'=>'whatsapp-contact-20261106','customers'=>$snapshot])."\n");
        $counts=wcp_counts($rows);$when=gmdate('Y-m-d H:i:s');
        foreach($rows as $row){
            $changes=WhatsAppCustomerDirectory::consentDefaults($row,$when);
            if($changes)DB::table('crm_customers')->where('id',$row['id'])->update($changes+['updated_at'=>$when]);
        }
        $after=wcp_counts(wcp_scope(true));
        if($after['changed']!==0)wcp_fail('CONSENT_DEFAULTS_VERIFICATION_FAILED');
        $counts['service_eligible']=$after['service_eligible'];$counts['marketing_eligible']=$after['marketing_eligible'];
        $result=['status'=>'applied','policy'=>'whatsapp-contact-20261106','data_writes'=>$counts['changed'],'counts'=>$counts,
            'backup_directory'=>$run,'evidence_type'=>'owner_policy','recorded_by'=>1,'recorded_at'=>$when];
        DB::table('branch_operation_commands')->insert(['branch'=>'','actor_id'=>1,'request_key'=>WCP_KEY,
            'request_hash'=>wcp_hash(),'kind'=>WCP_KIND,'result'=>wcp_json($result),'created_at'=>$when,'updated_at'=>$when]);
        return $result;
    },1);
}
function wcp_preflight(): void {
    if(getenv('WCP_TESTING')==='1')wcp_fail('TEST_MODE_FORBIDDEN');
    $db=DB::connection();
    if(realpath(base_path())!=='/home/fasakha/public_html'||app()->bound('owner.dashboard.runtime')
        ||config('desktop_dashboard.local')||$db->getDriverName()!=='mysql'||$db->getTablePrefix()!==''
        ||!in_array($db->getConfig('host'),['localhost','127.0.0.1','::1'],true))wcp_fail('PRIMARY_DATABASE_REQUIRED');
    if(!in_array(strtolower((string)parse_url(config('app.url'),PHP_URL_HOST)),['fasakhaninja.com','www.fasakhaninja.com'],true))wcp_fail('PRIMARY_DOMAIN_REQUIRED');
    $owner=DB::table('users')->where('id',1)->first();
    if(!$owner||$owner->account_type!=='admin'||$owner->status!=='accepted')wcp_fail('PRIMARY_OWNER_REQUIRED');
    $tables=['crm_customers'=>['id','phone','service_consent','marketing_consent','service_consented_at','marketing_consented_at',
        'service_consent_evidence','marketing_consent_evidence','service_consented_by','marketing_consented_by','opted_out_at','updated_at'],
        'crm_customer_sources'=>['id','customer_id','channel'],'branch_operation_commands'=>['id','branch','actor_id','request_key','request_hash','kind','result','created_at','updated_at']];
    foreach($tables as $table=>$fields)if(!Schema::hasTable($table)||array_diff($fields,Schema::getColumnListing($table)))wcp_fail('REQUIRED_SCHEMA_MISSING');
    $marks=implode(',',array_fill(0,count($tables),'?'));
    $engines=DB::select('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('.$marks.')',array_merge([$db->getDatabaseName()],array_keys($tables)));
    if(count($engines)!==count($tables))wcp_fail('TABLE_ENGINE_MISSING');
    foreach($engines as $r)if(strtolower((string)$r->engine)!=='innodb')wcp_fail('TRANSACTIONAL_TABLES_REQUIRED');
}
function wcp_source_state(string $source): array {
    $target='/home/fasakha/public_html/app/Services/Dashboard/WhatsAppCustomerDirectory.php';
    if(!is_file($source)||is_link($source)||!hash_equals(WCP_NEW,hash_file('sha256',$source)?:''))wcp_fail('PATCH_SOURCE_MISMATCH');
    if(is_link($target)||!is_file($target)||realpath(dirname($target))!==dirname($target))wcp_fail('INSTALLED_SOURCE_PATH_INVALID');
    $old=hash_file('sha256',$target);
    if(!in_array($old,[WCP_OLD,WCP_NEW],true))wcp_fail('INSTALLED_SOURCE_REQUIRES_REVIEW');
    return [$target,$old];
}
function wcp_install_source(string $source,string $base): bool {
    [$target,$old]=wcp_source_state($source);if($old===WCP_NEW)return false;
    $base=wcp_private_base($base);
    wcp_write($base.'/WhatsAppCustomerDirectory-before-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(5)).'.php',file_get_contents($target));
    $temp=$target.'.wcp-'.bin2hex(random_bytes(6));
    try{
        wcp_write($temp,file_get_contents($source),0644);
        if(!hash_equals(WCP_OLD,hash_file('sha256',$target)?:''))wcp_fail('INSTALLED_SOURCE_CHANGED');
        if(!rename($temp,$target))wcp_fail('PATCH_RENAME_FAILED');
    }finally{if(is_file($temp))unlink($temp);}
    return true;
}
function wcp_main(array $argv): int {
    $lock='';$locked=false;$stage='arguments';
    try{
        umask(0077);ini_set('display_errors','0');ini_set('log_errors','0');
        if(PHP_SAPI!=='cli'||count($argv)!==3||!in_array($argv[1],['--check','--apply'],true))wcp_fail('ARGUMENTS_INVALID');
        $source=$argv[2];$stage='bootstrap';$root='/home/fasakha/public_html';chdir($root);
        require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $stage='preflight';wcp_preflight();wcp_source_state($source);
        if(!class_exists(WhatsAppCustomerDirectory::class,false))require $source;
        if(!method_exists(WhatsAppCustomerDirectory::class,'consentDefaults'))wcp_fail('CLASS_ALREADY_LOADED_RETRY');
        if($argv[1]==='--check'){echo wcp_json(wcp_preview())."\n";return 0;}
        $lock='whatsapp-contact-policy:'.substr(hash('sha256',DB::connection()->getDatabaseName()),0,32);
        $r=DB::selectOne('SELECT GET_LOCK(?,0) AS acquired',[$lock]);
        if(!$r||(int)$r->acquired!==1)wcp_fail('ANOTHER_POLICY_UPDATE_RUNNING');$locked=true;
        $base='/home/fasakha/whatsapp-contact-consent-updates';
        $stage='source_install';$changed=wcp_install_source($source,$base);
        $stage='backfill';$result=wcp_apply($base);$result['source_installed']=$changed;
        echo wcp_json($result)."\n";return 0;
    }catch(Throwable $e){
        fwrite(STDERR,wcp_json(['status'=>'failed','stage'=>$stage,'error'=>$e instanceof WcpException?$e->getMessage():'OPERATION_FAILED'])."\n");return 1;
    }finally{if($locked){try{DB::selectOne('SELECT RELEASE_LOCK(?) AS released',[$lock]);}catch(Throwable $ignored){}}}
}
if(!defined('WCP_LIBRARY'))exit(wcp_main($argv));
