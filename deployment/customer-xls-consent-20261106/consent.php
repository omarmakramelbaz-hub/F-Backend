<?php
declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const XC_DATA_SHA = '3d4ac4be1e0b4aebdcb564c60e767716f3e9b1d602d2fe8657724dd9c32ace19';
const XC_SOURCE_SHA = 'fd9b40b0b5bd9e84484b2a791bff08bcc119c5e5cd683d70649991bdd29ce051';
const XC_REQUEST_KEY = '61106cee-c617-49f0-8e0f-720001337351';
const XC_KIND = 'customer.xls.owner.consent';
const XC_DECLARATION = 'جميعهم موافق ع المتابعه ع الواتساب ةالإعلانات';

class XlsConsentException extends RuntimeException {}
function xc_fail(string $code): void { throw new XlsConsentException($code); }
function xc_json($value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function xc_limits(): array {
    return getenv('XLS_CONSENT_TESTING')==='1'
        ? ['raw'=>9,'profiles'=>9,'valid_profiles'=>8,'phones'=>7]
        : ['raw'=>36919,'profiles'=>38033,'valid_profiles'=>37492,'phones'=>37351];
}
function xc_request_hash(): string {
    return hash('sha256',xc_json(['version'=>1,'dataset'=>XC_DATA_SHA,'source'=>XC_SOURCE_SHA,
        'owner_id'=>1,'evidence_type'=>'owner_attestation','declaration'=>XC_DECLARATION,
        'purposes'=>['whatsapp_followup','marketing'],'scope'=>'imported_crm_ids']));
}
function xc_evidence(): string {
    return 'Owner attestation, 2026-11-06; source XLS SHA256 '.XC_SOURCE_SHA
        .'; owner states all imported contacts agreed to WhatsApp follow-up and advertising. Declaration: '.XC_DECLARATION;
}

/** Resolve identities only from the successfully applied, pinned import. */
function xc_scope(bool $lock=false): array {
    $q=DB::table('legacy_customer_import_datasets')->where('data_sha256',XC_DATA_SHA);
    if($lock)$q->lockForUpdate();$dataset=$q->first();$limits=xc_limits();
    if(!$dataset||(int)$dataset->schema_version!==1||$dataset->backup_sha256!==XC_SOURCE_SHA
        ||$dataset->branch!==''||(int)$dataset->raw_count!==$limits['raw']
        ||(int)$dataset->profile_count!==$limits['profiles'])xc_fail('IMPORTED_DATASET_MISMATCH');
    $q=DB::table('legacy_customer_import_profiles')->where('dataset_id',$dataset->id)->orderBy('id');
    if($lock)$q->lockForUpdate();$profiles=$q->get();$ids=[];$phones=[];$valid=0;
    if(count($profiles)!==$limits['profiles'])xc_fail('IMPORTED_PROFILE_COUNT_MISMATCH');
    foreach($profiles as $p){
        if($p->phone===null){if($p->crm_customer_id!==null)xc_fail('INVALID_PHONE_LINKED');continue;}
        $valid++;$canonical=\App\Services\Dashboard\CustomerDirectory::normalizePhone((string)$p->phone);
        $id=(int)$p->crm_customer_id;
        if($id<1||$canonical!==$p->phone)xc_fail('PROFILE_IDENTITY_INVALID');
        if((isset($ids[$id])&&$ids[$id]!==$canonical)||(isset($phones[$canonical])&&$phones[$canonical]!==$id))xc_fail('PROFILE_IDENTITY_CONFLICT');
        $ids[$id]=$canonical;$phones[$canonical]=$id;
    }
    if($valid!==$limits['valid_profiles']||count($ids)!==$limits['phones']||count($phones)!==$limits['phones'])xc_fail('IMPORTED_CONTACT_COUNT_MISMATCH');
    ksort($ids,SORT_NUMERIC);$rows=[];
    foreach(array_chunk(array_keys($ids),500) as $chunk){
        $q=DB::table('crm_customers')->whereIn('id',$chunk)->orderBy('id');if($lock)$q->lockForUpdate();
        foreach($q->get() as $row){
            if(\App\Services\Dashboard\CustomerDirectory::normalizePhone((string)$row->phone)!==$ids[(int)$row->id])xc_fail('CRM_IDENTITY_CHANGED');
            $rows[(int)$row->id]=(array)$row;
        }
    }
    if(count($rows)!==count($ids))xc_fail('IMPORTED_CUSTOMER_MISSING');
    return $rows;
}
function xc_changes(array $row,string $when): array {
    $changes=[];$reconsent=$row['opted_out_at']!==null;
    foreach(['service','marketing'] as $purpose){
        if(!(bool)$row[$purpose.'_consent']||empty($row[$purpose.'_consented_at'])
            ||trim((string)$row[$purpose.'_consent_evidence'])===''||$reconsent){
            $changes[$purpose.'_consent']=true;$changes[$purpose.'_consented_at']=$when;
            $changes[$purpose.'_consent_evidence']=xc_evidence();$changes[$purpose.'_consented_by']=1;
        }
    }
    if($reconsent)$changes['opted_out_at']=null;
    if($changes)$changes['updated_at']=$when;
    return $changes;
}
function xc_counts(array $rows): array {
    $counts=['total'=>count($rows),'changed'=>0,'unchanged'=>0,'service_eligible'=>0,'marketing_eligible'=>0,'reactivated_optouts'=>0];
    foreach($rows as $row){
        $changed=(bool)xc_changes($row,'preview');$counts[$changed?'changed':'unchanged']++;
        $counts['reactivated_optouts']+=(int)($row['opted_out_at']!==null);
        foreach(['service','marketing'] as $purpose)
            $counts[$purpose.'_eligible']+=(int)((bool)$row[$purpose.'_consent']&&!empty($row[$purpose.'_consented_at'])&&$row['opted_out_at']===null);
    }
    return $counts;
}
function xc_receipt(): ?array {
    $row=DB::table('branch_operation_commands')->where('branch','')->where('actor_id',1)->where('request_key',XC_REQUEST_KEY)->first();
    if(!$row)return null;
    if($row->request_hash!==xc_request_hash()||$row->kind!==XC_KIND)xc_fail('CONSENT_RECEIPT_MISMATCH');
    $result=json_decode($row->result,true,512,JSON_THROW_ON_ERROR);
    if(($result['status']??'')!=='applied'||($result['dataset_sha256']??'')!==XC_DATA_SHA
        ||($result['counts']['total']??0)!==xc_limits()['phones'])xc_fail('CONSENT_RECEIPT_INVALID');
    return $result;
}
function xc_preview(): array {
    $rows=xc_scope();$receipt=xc_receipt();
    return ['status'=>$receipt?'already_applied':'dry_run','data_writes'=>0,'dataset_sha256'=>XC_DATA_SHA,
        'counts'=>xc_counts($rows),'previous_application'=>$receipt];
}
function xc_snapshot(string $directory,array $rows): string {
    if(!$directory||$directory[0]!=='/'||str_contains($directory,"\0")
        ||array_intersect(explode('/',$directory),['.','..'])||is_link($directory))xc_fail('BACKUP_PATH_INVALID');
    if(!is_dir($directory)&&!mkdir($directory,0700,true))xc_fail('BACKUP_DIRECTORY_FAILED');
    $base=realpath($directory);
    if(!$base||$base==='/home/fasakha/public_html'||str_starts_with($base,'/home/fasakha/public_html/'))xc_fail('PRIVATE_BACKUP_REQUIRED');
    if(!chmod($base,0700))xc_fail('BACKUP_PERMISSIONS_FAILED');
    $run=$base.'/xls-consent-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6));
    if(!mkdir($run,0700))xc_fail('BACKUP_RUN_FAILED');
    $fields=['id','phone','service_consent','marketing_consent','service_consented_at','marketing_consented_at',
        'service_consent_evidence','marketing_consent_evidence','service_consented_by','marketing_consented_by','opted_out_at','updated_at'];
    $saved=[];foreach($rows as $row)$saved[]=array_intersect_key($row,array_flip($fields));
    $bytes=xc_json(['version'=>1,'dataset_sha256'=>XC_DATA_SHA,'request_key'=>XC_REQUEST_KEY,
        'owner_declaration'=>XC_DECLARATION,'customers'=>$saved])."\n";
    $h=fopen($run.'/before.json','x+b');if(!$h)xc_fail('SNAPSHOT_OPEN_FAILED');
    try{
        if(!chmod($run.'/before.json',0600))xc_fail('SNAPSHOT_PERMISSIONS_FAILED');
        for($offset=0;$offset<strlen($bytes);$offset+=$n){$n=fwrite($h,substr($bytes,$offset));if($n===false||$n===0)xc_fail('SNAPSHOT_WRITE_FAILED');}
        if(!fflush($h)||(function_exists('fsync')&&!fsync($h)))xc_fail('SNAPSHOT_FLUSH_FAILED');
    }finally{fclose($h);}
    if(!hash_equals(hash('sha256',$bytes),hash_file('sha256',$run.'/before.json')?:''))xc_fail('SNAPSHOT_VERIFICATION_FAILED');
    return $run;
}

/** Receipt and all consent changes commit together. A replay never reapplies consent. */
function xc_apply(string $backupBase): array {
    return DB::transaction(function()use($backupBase){
        $rows=xc_scope(true);$old=xc_receipt();
        if($old)return ['status'=>'already_applied','data_writes'=>0,'dataset_sha256'=>XC_DATA_SHA,
            'counts'=>xc_counts($rows),'previous_application'=>$old];
        $run=xc_snapshot($backupBase,$rows);$before=xc_counts($rows);$when=gmdate('Y-m-d H:i:s');
        foreach($rows as $row){$changes=xc_changes($row,$when);if($changes)DB::table('crm_customers')->where('id',$row['id'])->update($changes);}
        $after=xc_counts(xc_scope(true));
        if($after['service_eligible']!==$after['total']||$after['marketing_eligible']!==$after['total']||$after['reactivated_optouts']!==0)xc_fail('CONSENT_VERIFICATION_FAILED');
        $counts=$before;$counts['service_eligible']=$after['service_eligible'];$counts['marketing_eligible']=$after['marketing_eligible'];
        $result=['status'=>'applied','data_writes'=>$before['changed'],'dataset_sha256'=>XC_DATA_SHA,
            'counts'=>$counts,'backup_directory'=>$run,'recorded_at'=>$when,'recorded_by'=>1,
            'evidence_type'=>'owner_attestation','owner_declaration'=>XC_DECLARATION];
        DB::table('branch_operation_commands')->insert(['branch'=>'','actor_id'=>1,'request_key'=>XC_REQUEST_KEY,
            'request_hash'=>xc_request_hash(),'kind'=>XC_KIND,'result'=>xc_json($result),'created_at'=>$when,'updated_at'=>$when]);
        return $result;
    },1);
}
function xc_preflight(): void {
    if(getenv('XLS_CONSENT_TESTING')==='1')xc_fail('TEST_MODE_FORBIDDEN');
    $root='/home/fasakha/public_html';$db=DB::connection();
    if(realpath(base_path())!==$root||app()->bound('owner.dashboard.runtime')||config('desktop_dashboard.local')
        ||$db->getDriverName()!=='mysql'||$db->getTablePrefix()!==''
        ||!in_array($db->getConfig('host'),['localhost','127.0.0.1','::1'],true))xc_fail('PRIMARY_DATABASE_REQUIRED');
    if(!in_array(strtolower((string)parse_url(config('app.url'),PHP_URL_HOST)),['fasakhaninja.com','www.fasakhaninja.com'],true))xc_fail('PRIMARY_DOMAIN_REQUIRED');
    $owner=DB::table('users')->where('id',1)->first();
    if(!$owner||$owner->account_type!=='admin'||$owner->status!=='accepted')xc_fail('PRIMARY_OWNER_REQUIRED');
    $expected=['crm_customers'=>['id','phone','service_consent','marketing_consent','service_consented_at','marketing_consented_at',
            'service_consent_evidence','marketing_consent_evidence','service_consented_by','marketing_consented_by','opted_out_at','updated_at'],
        'legacy_customer_import_datasets'=>['id','schema_version','data_sha256','backup_sha256','raw_count','profile_count','branch'],
        'legacy_customer_import_profiles'=>['id','dataset_id','phone','crm_customer_id'],
        'branch_operation_commands'=>['id','branch','actor_id','request_key','request_hash','kind','result','created_at','updated_at']];
    foreach($expected as $table=>$columns)if(!Schema::hasTable($table)||array_diff($columns,Schema::getColumnListing($table)))xc_fail('REQUIRED_SCHEMA_MISSING');
    $tables=array_keys($expected);$marks=implode(',',array_fill(0,count($tables),'?'));
    $engines=DB::select('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('.$marks.')',array_merge([$db->getDatabaseName()],$tables));
    if(count($engines)!==count($tables))xc_fail('TABLE_ENGINE_MISSING');
    foreach($engines as $row)if(strtolower((string)$row->engine)!=='innodb')xc_fail('TRANSACTIONAL_TABLES_REQUIRED');
}
function xc_main(array $argv): int {
    $locked=false;$lock='';$stage='arguments';
    try{
        umask(0077);ini_set('display_errors','0');ini_set('log_errors','0');
        if(PHP_SAPI!=='cli'||count($argv)!==2||!in_array($argv[1],['--check','--apply'],true))xc_fail('ARGUMENTS_INVALID');
        $stage='bootstrap';$root='/home/fasakha/public_html';chdir($root);
        require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $stage='preflight';xc_preflight();
        if($argv[1]==='--check'){echo xc_json(xc_preview())."\n";return 0;}
        $lock='xls-consent:'.substr(hash('sha256',DB::connection()->getDatabaseName()),0,40);
        $stage='lock';$r=DB::selectOne('SELECT GET_LOCK(?,0) AS acquired',[$lock]);
        if(!$r||(int)$r->acquired!==1)xc_fail('ANOTHER_CONSENT_UPDATE_RUNNING');$locked=true;
        $stage='apply';echo xc_json(xc_apply('/home/fasakha/current-customer-consent-updates'))."\n";return 0;
    }catch(Throwable $e){
        fwrite(STDERR,xc_json(['status'=>'failed','stage'=>$stage,'error'=>$e instanceof XlsConsentException?$e->getMessage():'OPERATION_FAILED'])."\n");return 1;
    }finally{if($locked){try{DB::selectOne('SELECT RELEASE_LOCK(?) AS released',[$lock]);}catch(Throwable $ignored){}}}
}
if(!defined('XLS_CONSENT_LIBRARY'))exit(xc_main($argv));
