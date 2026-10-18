<?php
declare(strict_types=1);

/** Small release library: never bootstrap Laravel or perform writes on import. */
class WhatsAppCaptureReleaseError extends \RuntimeException {}
function wac_fail(string $code): void { throw new WhatsAppCaptureReleaseError($code); }
function wac_json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES); }

/** Token positions protect braces inside comments, strings, and nested closures. */
function wac_method_span(string $source, string $class, string $method): array
{
    try { $raw=token_get_all($source,TOKEN_PARSE); } catch (Throwable $e) { wac_fail('PHP_SOURCE_INVALID'); }
    $tokens=[];$offset=0;
    foreach($raw as $token){$text=is_array($token)?$token[1]:$token;$tokens[]=[is_array($token)?$token[0]:null,$text,$offset];$offset+=strlen($text);}
    $classOpen=null;$classClose=null;$classMatches=0;$depth=0;
    for($i=0;$i<count($tokens);$i++){
        if($tokens[$i][0]!==T_CLASS)continue;
        $j=$i+1;while(isset($tokens[$j])&&in_array($tokens[$j][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))$j++;
        if(($tokens[$j][0]??null)!==T_STRING||$tokens[$j][1]!==$class)continue;
        $classMatches++;while(isset($tokens[$j])&&$tokens[$j][1]!=='{')$j++;
        if(!isset($tokens[$j]))wac_fail('TARGET_CLASS_INVALID');
        $start=$j;$n=1;
        while(isset($tokens[++$j])){if((($tokens[$j][0]===null&&$tokens[$j][1]==='{')||in_array($tokens[$j][0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true)))$n++;elseif($tokens[$j][0]===null&&$tokens[$j][1]==='}'&&--$n===0)break;}
        if(!isset($tokens[$j]))wac_fail('TARGET_CLASS_INVALID');$classOpen=$start;$classClose=$j;
    }
    if($classMatches!==1)wac_fail('TARGET_CLASS_NOT_UNIQUE');
    $matches=[];$depth=1;
    for($i=$classOpen+1;$i<$classClose;$i++){
        $token=$tokens[$i];
        if($depth===1&&$token[0]===T_FUNCTION){
            $j=$i+1;while(isset($tokens[$j])&&($tokens[$j][0]===T_WHITESPACE||$tokens[$j][1]==='&'))$j++;
            if(($tokens[$j][0]??null)===T_STRING&&$tokens[$j][1]===$method){
                $lineStart=strrpos(substr($source,0,$token[2]),"\n");$begin=$lineStart===false?0:$lineStart+1;
                $prefix=substr($source,$begin,$token[2]-$begin);
                if(!preg_match('/^[ \t]*(?:public|protected|private)[ \t]+(?:static[ \t]+)?$/D',$prefix))wac_fail('METHOD_DECLARATION_UNKNOWN');
                while(isset($tokens[$j])&&!($tokens[$j][0]===null&&in_array($tokens[$j][1],['{',';'],true)))$j++;
                if(($tokens[$j][1]??null)!=='{')wac_fail('METHOD_BODY_MISSING');$n=1;
                while(isset($tokens[++$j])){if((($tokens[$j][0]===null&&$tokens[$j][1]==='{')||in_array($tokens[$j][0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true)))$n++;elseif($tokens[$j][0]===null&&$tokens[$j][1]==='}'&&--$n===0)break;}
                if(!isset($tokens[$j]))wac_fail('METHOD_BODY_MISSING');
                $end=$tokens[$j][2]+1;$matches[]=[$begin,$end,substr($source,$begin,$end-$begin)];
            }
        }
        if((($token[0]===null&&$token[1]==='{')||in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true)))$depth++;elseif($token[0]===null&&$token[1]==='}')$depth--;
    }
    if(count($matches)!==1)wac_fail('TARGET_METHOD_NOT_UNIQUE');return $matches[0];
}

function wac_candidate(string $source, string $before, string $after): array
{
    [$start,$end,$method]=wac_method_span($source,'CustomerMessageEvents','inbound');
    if(hash_equals($after,$method))return[$source,true];
    if(!hash_equals($before,$method))wac_fail('UNKNOWN_CUSTOMER_INBOUND_METHOD');
    return[substr($source,0,$start).$after.substr($source,$end),false];
}

function wac_lint(string $bytes): void
{
    $pipes=[];$p=proc_open([PHP_BINARY,'-l'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))wac_fail('PHP_LINT_UNAVAILABLE');$offset=0;
    try{while($offset<strlen($bytes)){$n=fwrite($pipes[0],substr($bytes,$offset));if($n===false||$n===0)wac_fail('PHP_LINT_INPUT_FAILED');$offset+=$n;}}
    finally{fclose($pipes[0]);}
    stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)wac_fail('PHP_LINT_FAILED');
}

function wac_regular(string $path): string
{
    if(!is_file($path)||is_link($path)||realpath($path)!==$path)wac_fail('TARGET_FILE_NOT_REGULAR');
    $bytes=file_get_contents($path);if(!is_string($bytes))wac_fail('TARGET_READ_FAILED');return $bytes;
}

function wac_private_directory(string $path,string $root): string
{
    if($path===''||$path[0]!=='/'||str_contains($path,"\0"))wac_fail('PRIVATE_BACKUP_PATH_INVALID');
    $parts=explode('/',trim($path,'/'));$current='';
    foreach($parts as $part){if($part===''||$part==='.'||$part==='..')wac_fail('PRIVATE_BACKUP_PATH_INVALID');$current.='/'.$part;if(is_link($current))wac_fail('PRIVATE_BACKUP_SYMLINK_REFUSED');}
    if($path===$root||str_starts_with($path,$root.'/'))wac_fail('BACKUP_INSIDE_APPLICATION_REFUSED');
    if(!is_dir($path)&&!mkdir($path,0700,true))wac_fail('PRIVATE_BACKUP_CREATE_FAILED');
    if(realpath($path)!==$path||fileowner($path)!==posix_geteuid()||!chmod($path,0700))wac_fail('PRIVATE_BACKUP_OWNER_INVALID');return $path;
}

function wac_atomic(string $path,string $bytes,?string $expected,int $mode,int $uid,int $gid): void
{
    $check=static function()use($path,$expected){clearstatcache(true,$path);if($expected===null){if(file_exists($path)||is_link($path))wac_fail('TARGET_APPEARED');}
        elseif(!hash_equals($expected,hash('sha256',wac_regular($path))))wac_fail('TARGET_CHANGED');};
    $check();$temp=tempnam(dirname($path),'.whatsapp-capture-');if($temp===false)wac_fail('ATOMIC_STAGE_FAILED');
    try{if(file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes)||!chmod($temp,$mode)||!chown($temp,$uid)||!chgrp($temp,$gid))wac_fail('ATOMIC_STAGE_FAILED');
        if(hash_file('sha256',$temp)!==hash('sha256',$bytes))wac_fail('ATOMIC_STAGE_HASH_FAILED');$check();
        if($expected===null){if(!link($temp,$path))wac_fail('ATOMIC_NEW_TARGET_FAILED');unlink($temp);$temp=null;}
        else{if(!rename($temp,$path))wac_fail('ATOMIC_REPLACE_FAILED');$temp=null;}
        if(function_exists('opcache_invalidate'))opcache_invalidate($path,true);
    }finally{if($temp!==null&&is_file($temp))unlink($temp);}
}

function wac_indexes(string $table): array
{
    if(!in_array($table,['crm_customers','crm_customer_sources'],true))wac_fail('SCHEMA_TABLE_INVALID');
    $driver=\Illuminate\Support\Facades\DB::connection()->getDriverName();$indexes=[];
    if($driver==='mysql'){
        foreach(\Illuminate\Support\Facades\DB::select('SHOW INDEX FROM '.$table) as $row){$r=(array)$row;if((int)$r['Non_unique']!==0||($r['Sub_part']??null)!==null)continue;$indexes[$r['Key_name']][(int)$r['Seq_in_index']]=$r['Column_name'];}
    }elseif($driver==='sqlite'){
        foreach(\Illuminate\Support\Facades\DB::select('PRAGMA index_list("'.$table.'")') as $row){if(!(int)$row->unique||($row->partial??0))continue;$name=(string)$row->name;
            foreach(\Illuminate\Support\Facades\DB::select('PRAGMA index_info("'.str_replace('"','""',$name).'")')as $column)$indexes[$name][(int)$column->seqno]=(string)$column->name;}
    }else wac_fail('UNSUPPORTED_PREFLIGHT_DATABASE');
    foreach($indexes as &$columns){ksort($columns);$columns=array_values($columns);}unset($columns);return array_values($indexes);
}

function wac_schema(): void
{
    $schema=\Illuminate\Support\Facades\Schema::class;
    foreach(['crm_customers'=>['id','phone','phone_key','name','first_seen_at','last_seen_at','created_at','updated_at'],
        'crm_customer_sources'=>['customer_id','branch','channel','first_seen_at','last_seen_at','created_at','updated_at'],'crm_purchases'=>['id']]as $table=>$columns)
        if(!$schema::hasTable($table)||!$schema::hasColumns($table,$columns))wac_fail('CRM_SCHEMA_MISSING');
    if(!in_array(['phone'],wac_indexes('crm_customers'),true)||!in_array(['customer_id','branch','channel'],wac_indexes('crm_customer_sources'),true))wac_fail('CRM_UNIQUE_INDEX_MISSING');
    if(\Illuminate\Support\Facades\DB::connection()->getDriverName()==='mysql'){
        if(\Illuminate\Support\Facades\DB::connection()->getTablePrefix()!=='')wac_fail('PREFIXED_MYSQL_SCHEMA_REVIEW_REQUIRED');
        $engines=\Illuminate\Support\Facades\DB::select('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?)',['crm_customers','crm_customer_sources']);
        if(count($engines)!==2)wac_fail('CRM_TRANSACTIONAL_TABLE_MISSING');
        foreach($engines as $row)if(strtolower((string)$row->ENGINE)!=='innodb')wac_fail('CRM_INNODB_REQUIRED');
        $columns=\Illuminate\Support\Facades\DB::select('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',['crm_customer_sources','channel']);
        if(count($columns)!==1||(int)$columns[0]->CHARACTER_MAXIMUM_LENGTH<8)wac_fail('CRM_SOURCE_CHANNEL_TOO_SHORT');
    }
}

function wac_bootstrap(string $root): void
{
    if(posix_geteuid()===0)wac_fail('APPLICATION_BOOTSTRAP_AS_ROOT_REFUSED');
    require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
}
