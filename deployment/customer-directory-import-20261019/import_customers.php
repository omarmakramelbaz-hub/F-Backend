#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Standalone Laravel customer import. No application source files are modified.
 * Dry-run performs no DDL or data writes. Apply creates only its own provenance
 * tables before one transaction containing all customer/provenance data writes.
 * Define LEGACY_CUSTOMER_IMPORT_LIBRARY before requiring this file to test its
 * pure parsing/projection functions without starting Laravel.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

const LCI_TABLES = [
    'legacy_customer_import_datasets',
    'legacy_customer_import_customers',
    'legacy_customer_import_profiles',
];
const LCI_VERSION = 1;

class LegacyCustomerImportException extends RuntimeException {}
function lci_fail(string $message): void { throw new LegacyCustomerImportException($message); }
function lci_json($value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function lci_text_key(string $value): string {
    return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value), 'UTF-8');
}
function lci_required_string(object $row, string $field, int $max = 0): string {
    if (!property_exists($row, $field) || !is_string($row->$field)) lci_fail('Invalid input string field: '.$field);
    if ($max && mb_strlen($row->$field, 'UTF-8') > $max) lci_fail('Input string exceeds supported length: '.$field);
    return $row->$field;
}

/** Normalizer callback must be the installed CustomerDirectory::normalizePhone. */
function lci_decode(string $bytes, callable $normalizePhone): array {
    $doc = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
    if (!$doc instanceof stdClass || !isset($doc->source, $doc->customers, $doc->profiles)
        || !$doc->source instanceof stdClass || !is_array($doc->customers) || !is_array($doc->profiles)) {
        lci_fail('Input must contain source object, customers array, and profiles array.');
    }
    $backup = lci_required_string($doc->source, 'backup_sha256');
    if (!preg_match('/^[a-f0-9]{64}$/D', $backup)) lci_fail('source.backup_sha256 must be lowercase SHA-256.');
    $database = lci_required_string($doc->source, 'database', 255);
    $finished = lci_required_string($doc->source, 'backup_finished_at', 100);
    if (trim($database) === '' || trim($finished) === '') lci_fail('Source metadata must be nonempty.');
    $customers = []; $profiles = []; $phones = []; $keys = [];
    foreach ($doc->customers as $row) {
        if (!$row instanceof stdClass || !isset($row->raw) || !$row->raw instanceof stdClass) lci_fail('Each customer must have a raw object.');
        $id = lci_required_string($row, 'source_id', 191);
        if ($id === '' || isset($customers[$id])) lci_fail('Empty or duplicated customer source_id.');
        $raw = lci_json($row->raw);
        $customers[$id] = ['source_id'=>$id, 'raw_json'=>$raw, 'raw_sha256'=>hash('sha256', $raw)];
    }
    foreach ($doc->profiles as $row) {
        if (!$row instanceof stdClass) lci_fail('Each profile must be an object.');
        $id = lci_required_string($row, 'source_id', 191);
        $key = lci_required_string($row, 'profile_key');
        if (!isset($customers[$id])) lci_fail('Profile references an unknown customer source_id.');
        if (!preg_match('/^[a-f0-9]{64}$/D', $key) || isset($keys[$key])) lci_fail('Invalid or duplicated profile_key.');
        $keys[$key] = true;
        if (!property_exists($row, 'phone') || ($row->phone !== null && !is_string($row->phone))) lci_fail('Profile phone must be canonical text or null.');
        if ($row->phone !== null && ($normalizePhone($row->phone) !== $row->phone || strlen($row->phone) > 20)) lci_fail('Profile phone is not canonical or valid.');
        $p = ['source_id'=>$id, 'profile_key'=>$key, 'phone'=>$row->phone];
        foreach (['phone_raw','name','address','area','delivery_notes'] as $field) $p[$field] = lci_required_string($row, $field);
        $lat=$row->latitude??null; $lng=$row->longitude??null;
        if(($lat===null)!==($lng===null)) lci_fail('Profile coordinates must be a complete pair or null.');
        if($lat!==null && (!is_numeric($lat)||!is_numeric($lng)||!is_finite((float)$lat)||!is_finite((float)$lng)
            ||abs((float)$lat)>90||abs((float)$lng)>180||(float)$lat==0.0||(float)$lng==0.0)) lci_fail('Profile coordinates are invalid; preserve uncertain locations only in raw data.');
        $p['latitude']=$lat===null?null:number_format((float)$lat,7,'.','');
        $p['longitude']=$lng===null?null:number_format((float)$lng,7,'.','');
        if (!isset($row->flags) || !is_array($row->flags)) lci_fail('Profile flags must be an array.');
        foreach ($row->flags as $flag) if (!is_string($flag)) lci_fail('Profile flag must be text.');
        $p['flags_json'] = lci_json($row->flags);
        $p['profile_json'] = lci_json($row);
        $p['profile_sha256'] = hash('sha256', $p['profile_json']);
        $p['phone_key'] = $row->phone === null ? null : (str_starts_with($row->phone, '+20') ? '0'.substr($row->phone, 3) : substr($row->phone, 1));
        $p['lookup_eligible'] = $row->phone !== null;
        foreach (['name'=>100,'address'=>500,'area'=>150,'delivery_notes'=>500] as $field=>$limit) {
            if (mb_strlen(trim($p[$field]), 'UTF-8') > $limit) $p['lookup_eligible'] = false;
        }
        $profiles[] = $p;
        if ($p['phone'] !== null) $phones[$p['phone']][] = $p;
    }
    if (!$customers) lci_fail('Input contains no source customers.');
    ksort($customers); ksort($phones);
    return ['sha256'=>hash('sha256', $bytes), 'source_json'=>lci_json($doc->source), 'source'=>$doc->source,
        'customers'=>$customers, 'profiles'=>$profiles, 'phones'=>$phones];
}

/** Conservative projection. Conflicting names/addresses suppress all enrichment. */
function lci_projection(array $profiles): array {
    $values = []; $identities=[]; $oversize = 0;
    usort($profiles, fn($a,$b)=>strcmp($a['profile_key'], $b['profile_key']));
    foreach (['name'=>100,'address'=>500,'area'=>150,'delivery_notes'=>500] as $field=>$limit) {
        $values[$field] = [];
        foreach ($profiles as $profile) {
            $value = trim($profile[$field]);
            if ($value === '') continue;
            if(in_array($field,['name','address'],true)) $identities[$field][lci_text_key($value)]=true;
            if (mb_strlen($value, 'UTF-8') > $limit) { $oversize++; continue; }
            $key = lci_text_key($value);
            if (!isset($values[$field][$key])) $values[$field][$key] = $value;
        }
    }
    $conflict = count($identities['name']??[]) > 1 || count($identities['address']??[]) > 1;
    $chosen = [];
    if (!$conflict) foreach ($values as $field=>$set) if (count($set) === 1) $chosen[$field] = reset($set);
    return ['fields'=>$chosen, 'conflict'=>$conflict, 'oversize_fields'=>$oversize,
        'shared_source_phone'=>count(array_unique(array_column($profiles, 'source_id'))) > 1];
}

function lci_columns(): array {
    return [
        'crm_customers'=>['id','phone','phone_key','name','address','area','delivery_notes','latitude','longitude',
            'service_consent','marketing_consent','service_consented_at','marketing_consented_at','service_consent_evidence',
            'marketing_consent_evidence','service_consented_by','marketing_consented_by','opted_out_at',
            'first_seen_at','last_seen_at','created_at','updated_at'],
        'crm_customer_sources'=>['id','customer_id','branch','channel','first_seen_at','last_seen_at','created_at','updated_at'],
        'legacy_customer_import_datasets'=>['id','schema_version','data_sha256','backup_sha256','source_database','backup_finished_at','source_json','branch','raw_count','profile_count','created_at'],
        'legacy_customer_import_customers'=>['id','dataset_id','source_id','raw_json','raw_sha256','created_at'],
        'legacy_customer_import_profiles'=>['id','dataset_id','source_id','profile_key','profile_sha256','profile_json','phone','phone_key','phone_raw',
            'name','address','area','delivery_notes','latitude','longitude','flags_json','lookup_eligible','crm_customer_id','branch','created_at'],
    ];
}

function lci_unique_index(string $table, array $columns): bool {
    $sets=[]; $driver=DB::connection()->getDriverName();
    if($driver==='mysql') {
        foreach(DB::select('SHOW INDEX FROM `'.$table.'`') as $row) {
            if((int)$row->Non_unique===0) $sets[$row->Key_name][(int)$row->Seq_in_index]=(string)$row->Column_name;
        }
    } elseif($driver==='sqlite' && getenv('LEGACY_CUSTOMER_IMPORT_TESTING')==='1') {
        foreach(DB::select('PRAGMA index_list("'.$table.'")') as $index) if((int)$index->unique===1) {
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$index->name)) lci_fail('Unsupported test index name.');
            foreach(DB::select('PRAGMA index_info("'.$index->name.'")') as $row) $sets[$index->name][(int)$row->seqno]=(string)$row->name;
        }
    }
    foreach($sets as $set) { ksort($set); if(array_values($set)===$columns) return true; }
    return false;
}

/** V2 searches shared contacts by phone across F/global records. */
function lci_profile_phone_index_ready(): bool {
    $table=LCI_TABLES[2]; $name='legacy_customer_profile_phone_key';
    if(!Schema::hasTable($table)) return false;
    $sets=[]; $driver=DB::connection()->getDriverName();
    if($driver==='mysql') {
        foreach(DB::select('SHOW INDEX FROM `'.$table.'`') as $row) $sets[$row->Key_name][(int)$row->Seq_in_index]=(string)$row->Column_name;
    } elseif($driver==='sqlite'&&getenv('LEGACY_CUSTOMER_IMPORT_TESTING')==='1') {
        foreach(DB::select('PRAGMA index_list("'.$table.'")') as $index) {
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$index->name)) lci_fail('Unsupported test index name.');
            foreach(DB::select('PRAGMA index_info("'.$index->name.'")') as $row) $sets[$index->name][(int)$row->seqno]=(string)$row->name;
        }
    }
    foreach($sets as &$set) { ksort($set); $set=array_values($set); } unset($set);
    if(isset($sets[$name])&&$sets[$name]!==['phone_key']) lci_fail('Known lookup index name has an incompatible definition.');
    foreach($sets as $set) if($set===['phone_key']) return true;
    return false;
}

function lci_preflight(string $branch, bool $allowMissingImportTables): void {
    foreach (lci_columns() as $table=>$columns) {
        if (!Schema::hasTable($table)) {
            if ($allowMissingImportTables && in_array($table, LCI_TABLES, true)) continue;
            lci_fail('Required table is missing: '.$table);
        }
        if (array_diff($columns, Schema::getColumnListing($table))) lci_fail('Required columns are missing from: '.$table);
    }
    $unique=['crm_customers'=>['phone'],'crm_customer_sources'=>['customer_id','branch','channel'],
        LCI_TABLES[0]=>['data_sha256'],LCI_TABLES[1]=>['dataset_id','source_id'],LCI_TABLES[2]=>['dataset_id','profile_key']];
    foreach($unique as $table=>$columns) if(Schema::hasTable($table)&&!lci_unique_index($table,$columns)) lci_fail('Required unique index is missing from: '.$table);
    if(DB::connection()->getDriverName()==='mysql') {
        $tables=array_keys(lci_columns()); $marks=implode(',',array_fill(0,count($tables),'?'));
        foreach(DB::select('SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('.$marks.')',array_merge([DB::connection()->getDatabaseName()],$tables)) as $row) {
            if(strtolower((string)$row->engine)!=='innodb') lci_fail('Customer and provenance tables must use InnoDB.');
        }
        $defs=DB::select("SELECT COLUMN_NAME AS column_name, CHARACTER_MAXIMUM_LENGTH AS max_length, IS_NULLABLE AS is_nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='crm_customers'",[DB::connection()->getDatabaseName()]);
        $defs=array_column(array_map(fn($r)=>(array)$r,$defs),null,'column_name');
        foreach(['phone'=>20,'phone_key'=>20,'name'=>100,'address'=>500,'area'=>150,'delivery_notes'=>500] as $column=>$length) {
            if((int)($defs[$column]['max_length']??0)<$length) lci_fail('CRM field length is incompatible: '.$column);
            if(!in_array($column,['phone','phone_key'],true)&&($defs[$column]['is_nullable']??'')!=='YES') lci_fail('CRM optional fields must accept null.');
        }
    }
    if ($branch !== '') {
        if (!preg_match('/^f:([1-9][0-9]{0,18})$/D', $branch, $m) || !Schema::hasTable('resturants')
            || !DB::table('resturants')->where('id', $m[1])->exists()) lci_fail('Explicit branch must reference an existing Fasakhansta resturant.');
    }
    if (!class_exists('App\\Services\\Dashboard\\CustomerDirectory')) lci_fail('Installed CustomerDirectory service is unavailable.');
}

/** DDL intentionally precedes the customer data transaction; never called in dry-run. */
function lci_create_tables(): void {
    if (!Schema::hasTable(LCI_TABLES[0])) Schema::create(LCI_TABLES[0], function(Blueprint $t) {
        $t->engine='InnoDB'; $t->bigIncrements('id'); $t->unsignedInteger('schema_version');
        $t->char('data_sha256',64)->unique(); $t->char('backup_sha256',64); $t->string('source_database',255);
        $t->string('backup_finished_at',100); $t->longText('source_json'); $t->string('branch',30);
        $t->unsignedBigInteger('raw_count'); $t->unsignedBigInteger('profile_count'); $t->timestamp('created_at');
    });
    if (!Schema::hasTable(LCI_TABLES[1])) Schema::create(LCI_TABLES[1], function(Blueprint $t) {
        $t->engine='InnoDB'; $t->bigIncrements('id'); $t->unsignedBigInteger('dataset_id'); $t->string('source_id',191);
        $t->longText('raw_json'); $t->char('raw_sha256',64); $t->timestamp('created_at');
        $t->unique(['dataset_id','source_id'],'legacy_customer_raw_unique');
    });
    if (!Schema::hasTable(LCI_TABLES[2])) Schema::create(LCI_TABLES[2], function(Blueprint $t) {
        $t->engine='InnoDB'; $t->bigIncrements('id'); $t->unsignedBigInteger('dataset_id'); $t->string('source_id',191);
        $t->char('profile_key',64); $t->char('profile_sha256',64); $t->longText('profile_json');
        $t->string('phone',20)->nullable(); $t->string('phone_key',20)->nullable(); $t->text('phone_raw');
        $t->text('name'); $t->longText('address'); $t->text('area'); $t->longText('delivery_notes'); $t->longText('flags_json');
        $t->decimal('latitude',10,7)->nullable(); $t->decimal('longitude',10,7)->nullable();
        $t->boolean('lookup_eligible')->default(false); $t->unsignedBigInteger('crm_customer_id')->nullable();
        $t->string('branch',30); $t->timestamp('created_at');
        $t->unique(['dataset_id','profile_key'],'legacy_customer_profile_unique');
        $t->index(['branch','phone_key'],'legacy_customer_profile_lookup'); $t->index(['dataset_id','source_id'],'legacy_customer_profile_source');
        $t->index(['phone_key'],'legacy_customer_profile_phone_key');
    });
    if(!lci_profile_phone_index_ready()) Schema::table(LCI_TABLES[2],function(Blueprint $t) {
        $t->index(['phone_key'],'legacy_customer_profile_phone_key');
    });
    if(!lci_profile_phone_index_ready()) lci_fail('Shared phone lookup index could not be established.');
}

function lci_existing(array $phones, bool $lock = false): array {
    $customers = []; $links = [];
    foreach (array_chunk($phones,500) as $chunk) {
        $q = DB::table('crm_customers')->whereIn('phone',$chunk)->orderBy('phone');
        if ($lock) $q->lockForUpdate();
        foreach ($q->get() as $row) {
            if(isset($customers[$row->phone])) lci_fail('Duplicate CRM phone identity detected.');
            $customers[$row->phone] = (array)$row;
        }
    }
    foreach (array_chunk(array_column($customers,'id'),500) as $chunk) {
        $q = DB::table('crm_customer_sources')->whereIn('customer_id',$chunk)->orderBy('id');
        if ($lock) $q->lockForUpdate();
        foreach ($q->get() as $row) $links[] = (array)$row;
    }
    return ['customers'=>$customers,'links'=>$links];
}

function lci_plan(array $data, string $branch, array $existing): array {
    $counts = ['source_customers'=>count($data['customers']), 'source_profiles'=>count($data['profiles']),
        'profiles_with_valid_phone'=>0, 'profiles_without_valid_phone'=>0, 'lookup_eligible_profiles'=>0,
        'distinct_phones'=>count($data['phones']), 'shared_source_phones'=>0, 'conflicting_phones'=>0,
        'oversize_fields'=>0, 'existing_identity_conflicts'=>0, 'existing_identity_uncertain'=>0,
        'crm_insert'=>0, 'crm_enrich'=>0, 'crm_unchanged'=>0, 'source_links_insert'=>0];
    foreach ($data['profiles'] as $p) {
        $counts[$p['phone'] === null ? 'profiles_without_valid_phone':'profiles_with_valid_phone']++;
        if ($p['lookup_eligible']) $counts['lookup_eligible_profiles']++;
    }
    $links=[]; foreach($existing['links'] as $link) $links[$link['customer_id'].'|'.$link['branch'].'|'.$link['channel']]=true;
    $actions=[];
    foreach ($data['phones'] as $phone=>$profiles) {
        $p=lci_projection($profiles); $row=$existing['customers'][$phone]??null; $changes=[];
        $mismatch=false; $matched=false; $existingIdentity=false;
        if($row) foreach(['name','address'] as $field) {
            if($row[$field]!==null&&trim((string)$row[$field])!=='') {
                $existingIdentity=true;
                if(isset($p['fields'][$field])) {
                    if(lci_text_key((string)$row[$field])!==lci_text_key($p['fields'][$field])) $mismatch=true;
                    else $matched=true;
                }
            }
        }
        $uncertain=$row&&$existingIdentity&&!$matched;
        if(!$mismatch&&!$uncertain) foreach($p['fields'] as $field=>$value) if (!$row || $row[$field] === null || trim((string)$row[$field])==='') $changes[$field]=$value;
        $counts['existing_identity_conflicts']+=(int)$mismatch; $counts['existing_identity_uncertain']+=(int)$uncertain;
        $counts['shared_source_phones']+=(int)$p['shared_source_phone']; $counts['conflicting_phones']+=(int)$p['conflict']; $counts['oversize_fields']+=$p['oversize_fields'];
        $counts[$row ? ($changes ? 'crm_enrich':'crm_unchanged'):'crm_insert']++;
        $missingLink=!$row || !isset($links[$row['id'].'|'.$branch.'|delivery']);
        $counts['source_links_insert']+=(int)$missingLink;
        $actions[$phone]=['id'=>$row['id']??null,'changes'=>$changes,'missing_link'=>$missingLink];
    }
    return ['counts'=>$counts,'actions'=>$actions];
}

function lci_private_directory(string $path, string $root): string {
    if ($path==='' || $path[0]!=='/' || strpos($path,"\0")!==false) lci_fail('Backup directory must be an absolute path.');
    $parts=explode('/',trim($path,'/'));
    if (in_array('..',$parts,true)||in_array('.',$parts,true)||$path==='/') lci_fail('Backup directory path is unsafe.');
    $probe=rtrim($path,'/'); $missing=[];
    while (!file_exists($probe)) { array_unshift($missing,basename($probe)); $probe=dirname($probe); }
    $real=realpath($probe); if ($real===false) lci_fail('Cannot resolve backup directory.');
    $resolved=rtrim($real,'/').($missing?'/'.implode('/',$missing):'');
    if ($resolved===$root || str_starts_with($resolved,$root.'/')) lci_fail('Backup directory must be outside the Laravel web root.');
    if (is_link($path)) lci_fail('Backup directory must not be a symlink.');
    if (!is_dir($path) && !mkdir($path,0700,true)) lci_fail('Cannot create backup directory.');
    if (!chmod($path,0700)) lci_fail('Cannot secure backup directory.');
    return realpath($path) ?: lci_fail('Cannot resolve created backup directory.');
}

function lci_private_json(string $path, array $value): void {
    $h=fopen($path,'x'); if ($h===false) lci_fail('Cannot create private backup snapshot.');
    try {
        if (!chmod($path,0600)) lci_fail('Cannot secure private snapshot.');
        $bytes=lci_json($value)."\n"; $offset=0;
        while($offset<strlen($bytes)) { $n=fwrite($h,substr($bytes,$offset)); if($n===false||$n===0) lci_fail('Cannot write complete backup snapshot.'); $offset+=$n; }
        if (!fflush($h)) lci_fail('Cannot flush private snapshot.');
        if (function_exists('fsync') && !fsync($h)) lci_fail('Cannot sync private snapshot.');
    } finally { fclose($h); }
}

function lci_replay(array $data, string $branch): bool {
    if (!Schema::hasTable(LCI_TABLES[0])) return false;
    $row=DB::table(LCI_TABLES[0])->where('data_sha256',$data['sha256'])->first();
    if (!$row) return false;
    if ((int)$row->schema_version!==LCI_VERSION || $row->branch!==$branch || $row->backup_sha256!==$data['source']->backup_sha256
        || $row->source_json!==$data['source_json'] || (int)$row->raw_count!==count($data['customers']) || (int)$row->profile_count!==count($data['profiles'])) lci_fail('Recorded dataset does not match requested import.');
    $raw=DB::table(LCI_TABLES[1])->where('dataset_id',$row->id)->get();
    $profiles=DB::table(LCI_TABLES[2])->where('dataset_id',$row->id)->get();
    if(count($raw)!==count($data['customers'])||count($profiles)!==count($data['profiles'])) lci_fail('Recorded dataset is incomplete.');
    foreach($raw as $r) if(!isset($data['customers'][$r->source_id]) || $data['customers'][$r->source_id]['raw_sha256']!==$r->raw_sha256 || hash('sha256',$r->raw_json)!==$r->raw_sha256) lci_fail('Recorded raw provenance has changed.');
    $expected=array_column($data['profiles'],null,'profile_key');
    foreach($profiles as $r) {
        if(!isset($expected[$r->profile_key]) || $expected[$r->profile_key]['profile_sha256']!==$r->profile_sha256 || hash('sha256',$r->profile_json)!==$r->profile_sha256) lci_fail('Recorded profile provenance has changed.');
        foreach(['source_id','phone','phone_key','phone_raw','name','address','area','delivery_notes','latitude','longitude','flags_json','lookup_eligible'] as $field) {
            if(in_array($field,['latitude','longitude'],true) && $r->$field!==null) {
                if(number_format((float)$r->$field,7,'.','')!==$expected[$r->profile_key][$field]) lci_fail('Recorded profile coordinates have changed.');
                continue;
            }
            if($field==='lookup_eligible' ? (bool)$r->$field!==(bool)$expected[$r->profile_key][$field] : $r->$field!==$expected[$r->profile_key][$field]) lci_fail('Recorded profile projection has changed.');
        }
        if($r->branch!==$branch) lci_fail('Recorded profile scope has changed.');
    }
    return true;
}

function lci_main(array $argv): int {
    $locked=false; $lockName=''; $stage='arguments';
    try {
        umask(0077);
        $allowed=['root','data','dry-run','apply','branch','backup-dir','expected-data-sha256','help']; $args=[];
        for($i=1;$i<count($argv);$i++) {
            if(!str_starts_with($argv[$i],'--')) lci_fail('Unexpected positional argument.');
            $part=explode('=',substr($argv[$i],2),2); $key=$part[0];
            if(!in_array($key,$allowed,true)||array_key_exists($key,$args)) lci_fail('Unknown or duplicated option.');
            if(in_array($key,['dry-run','apply','help'],true)) { if(count($part)!==1) lci_fail('Flag does not accept a value.'); $args[$key]=true; }
            else { $args[$key]=$part[1]??($argv[++$i]??null); if(!is_string($args[$key])||str_starts_with($args[$key],'--')) lci_fail('Option requires a value.'); }
        }
        if(isset($args['help'])) { echo "Usage: php import_customers.php --root /home/fasakha/public_html --data /private/customers.json [--dry-run | --apply --backup-dir /private/backups] [--branch f:ID] [--expected-data-sha256 HEX]\nDefault: dry-run; omitted branch means unified directory records. F delivery phone lookup is available to authorized staff; no branch is inferred.\n"; return 0; }
        if(isset($args['apply'],$args['dry-run'])) lci_fail('Choose apply or dry-run.');
        $apply=isset($args['apply']); $branch=$args['branch']??'';
        if($branch!==''&&!preg_match('/^f:[1-9][0-9]{0,18}$/D',$branch)) lci_fail('Branch must be empty or f:ID.');
        $root=realpath($args['root']??''); $input=realpath($args['data']??'');
        if(!$root||!is_dir($root)||!is_file($root.'/artisan')||!is_file($root.'/bootstrap/app.php')||!is_file($root.'/vendor/autoload.php')) lci_fail('Laravel root is invalid.');
        if(!$input||!is_file($input)||!is_readable($input)||filesize($input)>268435456) lci_fail('Data file is missing, unreadable, or exceeds 256 MiB.');
        $bytes=file_get_contents($input); if($bytes===false) lci_fail('Cannot read input file.');
        $sha=hash('sha256',$bytes);
        if(isset($args['expected-data-sha256'])&&(!preg_match('/^[a-f0-9]{64}$/D',$args['expected-data-sha256'])||!hash_equals($args['expected-data-sha256'],$sha))) lci_fail('Input SHA-256 does not match expected value.');
        $stage='bootstrap'; require $root.'/vendor/autoload.php'; $app=require $root.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $stage='input'; $data=lci_decode($bytes,['App\\Services\\Dashboard\\CustomerDirectory','normalizePhone']); unset($bytes);
        $stage='preflight'; lci_preflight($branch,true);
        $driver=DB::connection()->getDriverName();
        if($driver!=='mysql' && !($driver==='sqlite' && getenv('LEGACY_CUSTOMER_IMPORT_TESTING')==='1')) lci_fail('Unsupported database driver.');
        $replay=lci_replay($data,$branch); $indexPending=!lci_profile_phone_index_ready();
        if($replay&&(!$apply||!$indexPending)) { echo lci_json(['status'=>'already_imported','data_sha256'=>$sha,'source_customers'=>count($data['customers']),
            'source_profiles'=>count($data['profiles']),'lookup_phone_index_pending'=>$indexPending,'data_writes'=>0])."\n"; return 0; }
        if(!$apply) {
            $plan=lci_plan($data,$branch,lci_existing(array_keys($data['phones'])));
            echo lci_json(['status'=>'dry_run','schema_writes'=>0,'data_writes'=>0,'data_sha256'=>$sha,'global_directory_records'=>$branch==='',
                'lookup_phone_index_pending'=>$indexPending,'counts'=>$plan['counts']])."\n"; return 0;
        }
        if(empty($args['backup-dir'])) lci_fail('--backup-dir outside the Laravel root is required on apply.');
        $stage='backup_directory'; $backupBase=lci_private_directory($args['backup-dir'],$root);
        if(!hash_equals($sha,hash_file('sha256',$input)?:'')) lci_fail('Input changed after validation.');
        $stage='import_lock';
        if($driver==='mysql') { $lockName='legacy-customer-import:'.substr(hash('sha256',DB::connection()->getDatabaseName()),0,32); $lock=DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired',[$lockName]); if(!$lock||(int)$lock->acquired!==1) lci_fail('Another customer import is running.'); $locked=true; }
        $stage='provenance_schema'; lci_create_tables(); lci_preflight($branch,false);
        $stage='transaction';
        $result=DB::transaction(function() use($data,$branch,$sha,$input,$backupBase) {
            if(lci_replay($data,$branch)) return ['status'=>'already_imported','data_sha256'=>$sha,'lookup_phone_index_pending'=>false,'data_writes'=>0];
            $existing=lci_existing(array_keys($data['phones']),true); $plan=lci_plan($data,$branch,$existing);
            $runDir=$backupBase.'/'.gmdate('Ymd\THis\Z').'-'.substr($sha,0,12).'-'.bin2hex(random_bytes(4));
            if(!mkdir($runDir,0700)) lci_fail('Cannot create backup run directory.');
            lci_private_json($runDir.'/before.json',['format'=>1,'data_sha256'=>$sha,'backup_sha256'=>$data['source']->backup_sha256,'branch'=>$branch,
                'customers'=>array_values($existing['customers']),'source_links'=>$existing['links']]);
            lci_private_json($runDir.'/plan.json',['format'=>1,'data_sha256'=>$sha,'source'=>$data['source'],'branch'=>$branch,'counts'=>$plan['counts']]);
            if(!hash_equals($sha,hash_file('sha256',$input)?:'')) lci_fail('Input changed before data writes.');
            $when=gmdate('Y-m-d H:i:s');
            $dataset=DB::table(LCI_TABLES[0])->insertGetId(['schema_version'=>LCI_VERSION,'data_sha256'=>$sha,'backup_sha256'=>$data['source']->backup_sha256,
                'source_database'=>$data['source']->database,'backup_finished_at'=>$data['source']->backup_finished_at,'source_json'=>$data['source_json'],
                'branch'=>$branch,'raw_count'=>count($data['customers']),'profile_count'=>count($data['profiles']),'created_at'=>$when]);
            $ids=[]; $created=0; $enriched=0; $linked=0;
            $new=[]; $newPhones=[];
            foreach($plan['actions'] as $phone=>$action) {
                $row=$existing['customers'][$phone]??null;
                if($row) {
                    $ids[$phone]=(int)$row['id'];
                    if($action['changes']) { DB::table('crm_customers')->where('id',$row['id'])->update($action['changes']+['updated_at'=>$when]); $enriched++; }
                } else {
                    $new[]=$action['changes']+array_fill_keys(['name','address','area','delivery_notes'],null)+[
                        'phone'=>$phone,'phone_key'=>str_starts_with($phone,'+20')?'0'.substr($phone,3):substr($phone,1),
                        'service_consent'=>false,'marketing_consent'=>false,'first_seen_at'=>$when,'last_seen_at'=>$when,'created_at'=>$when,'updated_at'=>$when];
                    $newPhones[]=$phone;
                }
            }
            foreach(array_chunk($new,300) as $chunk) {
                $inserted=DB::table('crm_customers')->insertOrIgnore($chunk);
                if((int)$inserted!==count($chunk)) lci_fail('CRM identities changed during import; rerun dry-run.');
                $created+=(int)$inserted;
            }
            foreach(array_chunk($newPhones,500) as $chunk) foreach(DB::table('crm_customers')->whereIn('phone',$chunk)->lockForUpdate()->get() as $row) $ids[$row->phone]=(int)$row->id;
            if(count($ids)!==count($data['phones'])) lci_fail('Could not establish all CRM identities.');
            $newLinks=[];
            foreach($plan['actions'] as $phone=>$action) if($action['missing_link']) $newLinks[]=['customer_id'=>$ids[$phone],'branch'=>$branch,'channel'=>'delivery',
                'first_seen_at'=>$when,'last_seen_at'=>$when,'created_at'=>$when,'updated_at'=>$when];
            foreach(array_chunk($newLinks,300) as $chunk) $linked+=DB::table('crm_customer_sources')->insertOrIgnore($chunk);
            foreach(array_chunk(array_values($data['customers']),200) as $chunk) {
                foreach($chunk as &$row) $row+=['dataset_id'=>$dataset,'created_at'=>$when]; unset($row);
                DB::table(LCI_TABLES[1])->insert($chunk);
            }
            foreach(array_chunk($data['profiles'],100) as $chunk) {
                foreach($chunk as &$row) $row+=['dataset_id'=>$dataset,'crm_customer_id'=>$row['phone']===null?null:$ids[$row['phone']],'branch'=>$branch,'created_at'=>$when]; unset($row);
                DB::table(LCI_TABLES[2])->insert($chunk);
            }
            return ['status'=>'applied','data_sha256'=>$sha,'global_directory_records'=>$branch==='','counts'=>$plan['counts'],
                'written'=>['raw_customers'=>count($data['customers']),'profiles'=>count($data['profiles']),'crm_created'=>$created,'crm_fields_enriched_rows'=>$enriched,'source_links_created'=>$linked]];
        },1);
        echo lci_json($result)."\n"; return 0;
    } catch(Throwable $e) {
        // Never echo database exceptions or source values: they can contain PII.
        $safe=$e instanceof JsonException?'Invalid JSON input.':($e instanceof LegacyCustomerImportException?$e->getMessage():'Operation failed; no customer values are printed.');
        if(strlen($safe)>250 || preg_match('/SQLSTATE|insert into|bindings/i',$safe)) $safe='Operation failed; inspect locally without exposing customer data.';
        fwrite(STDERR,lci_json(['status'=>'failed','stage'=>$stage,'error'=>$safe])."\n"); return 1;
    } finally {
        if($locked) { try { DB::selectOne('SELECT RELEASE_LOCK(?) AS released',[$lockName]); } catch(Throwable $ignored) {} }
    }
}

if(!defined('LEGACY_CUSTOMER_IMPORT_LIBRARY')) exit(lci_main($argv));
