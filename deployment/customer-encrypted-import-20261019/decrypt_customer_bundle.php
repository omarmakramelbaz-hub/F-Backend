#!/usr/bin/env php
<?php
declare(strict_types=1);

const CUSTOMER_CIPHER_BYTES=6744747;
const CUSTOMER_CIPHER_SHA256='a39247c6d0ef339aed7017f82d2570f1cfef793591ad8dc8d59cd2f866795932';
const CUSTOMER_ZIP_BYTES=6744684;
const CUSTOMER_ZIP_SHA256='f842c1a0b8106bb884f1cb915113fa0e366e1823b86e0e5435279ec901ae9e6e';
const CUSTOMER_MAGIC="FASAKHANSTA-CUSTOMERS-AES256GCM-V1\n";

function customer_bundle_fail(string $code): never { throw new RuntimeException($code); }
function customer_bundle_path(string $path): void {
    if($path===''||$path[0]!=='/'||str_contains($path,"\0"))customer_bundle_fail('PATH_INVALID');
    $current='';
    foreach(explode('/',substr($path,1)) as $part){
        if($part===''||$part==='.'||$part==='..')customer_bundle_fail('PATH_INVALID');
        $current.='/'.$part;if(is_link($current))customer_bundle_fail('SYMLINK_REFUSED');
    }
}
function customer_bundle_existing(string $path): bool {
    clearstatcache(true,$path);
    if(!file_exists($path)&&!is_link($path))return false;
    if(is_link($path)||!is_file($path)||realpath($path)!==$path)customer_bundle_fail('EXISTING_OUTPUT_INVALID');
    $handle=fopen($path,'rb');if(!$handle)customer_bundle_fail('EXISTING_OUTPUT_INVALID');
    try{
        $stat=fstat($handle);
        if(!$stat||($stat['mode']&0170000)!==0100000||$stat['nlink']!==1||$stat['size']!==CUSTOMER_ZIP_BYTES)customer_bundle_fail('EXISTING_OUTPUT_DIFFERS');
        // Never chmod a pathname in the account-owned home while running as root.
        if(($stat['mode']&0777)!==0600)customer_bundle_fail('OUTPUT_PERMISSIONS_FAILED');
        $hash=hash_init('sha256');
        if(hash_update_stream($hash,$handle)!==CUSTOMER_ZIP_BYTES||!hash_equals(CUSTOMER_ZIP_SHA256,hash_final($hash)))customer_bundle_fail('EXISTING_OUTPUT_DIFFERS');
        clearstatcache(true,$path);$current=lstat($path);
        if(!$current||$current['dev']!==$stat['dev']||$current['ino']!==$stat['ino']||($current['mode']&0170000)!==0100000)customer_bundle_fail('EXISTING_OUTPUT_INVALID');
        return true;
    }finally{fclose($handle);}
}
function customer_bundle_main(array $argv): int {
    $temp=null;$key=null;$plain=null;
    try{
        umask(0077);$args=[];
        for($i=1;$i<count($argv);$i++){
            $name=$argv[$i];if(!in_array($name,['--encrypted','--output','--root'],true)||isset($args[$name]))customer_bundle_fail('ARGUMENT_INVALID');
            $value=$argv[++$i]??null;if(!is_string($value)||str_starts_with($value,'--'))customer_bundle_fail('ARGUMENT_VALUE_MISSING');$args[$name]=$value;
        }
        if(count($args)!==3)customer_bundle_fail('ARGUMENT_MISSING');
        if(!function_exists('openssl_decrypt')||!in_array('aes-256-gcm',openssl_get_cipher_methods(),true))customer_bundle_fail('PHP_OPENSSL_GCM_REQUIRED');
        $input=$args['--encrypted'];$output=$args['--output'];$root=$args['--root'];
        foreach([$input,$output,$root]as $path)customer_bundle_path($path);
        if(!is_dir($root)||realpath($root)!==$root||!is_file($root.'/artisan'))customer_bundle_fail('APPLICATION_ROOT_INVALID');
        $parent=dirname($output);
        if(realpath($parent)!==$parent||!is_dir($parent)||$output===$root||str_starts_with($output,$root.'/'))customer_bundle_fail('OUTPUT_MUST_BE_PRIVATE');
        if(!is_file($input)||realpath($input)!==$input||filesize($input)!==CUSTOMER_CIPHER_BYTES)customer_bundle_fail('CIPHERTEXT_INVALID');
        $bytes=file_get_contents($input);
        if(!is_string($bytes)||strlen($bytes)!==CUSTOMER_CIPHER_BYTES||!hash_equals(CUSTOMER_CIPHER_SHA256,hash('sha256',$bytes))||!str_starts_with($bytes,CUSTOMER_MAGIC))customer_bundle_fail('CIPHERTEXT_INVALID');
        // The one-time secret is accepted only on stdin, never argv or environment.
        $encoded=trim(stream_get_contents(STDIN,128));
        $key=base64_decode($encoded,true);
        if(strlen($encoded)!==44||!is_string($key)||strlen($key)!==32||base64_encode($key)!==$encoded)customer_bundle_fail('DECRYPTION_KEY_INVALID');
        $offset=strlen(CUSTOMER_MAGIC);$nonce=substr($bytes,$offset,12);$tag=substr($bytes,$offset+12,16);$ciphertext=substr($bytes,$offset+28);
        $plain=openssl_decrypt($ciphertext,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,CUSTOMER_MAGIC);
        unset($key,$encoded,$bytes,$ciphertext);
        if(!is_string($plain)||strlen($plain)!==CUSTOMER_ZIP_BYTES||!hash_equals(CUSTOMER_ZIP_SHA256,hash('sha256',$plain)))customer_bundle_fail('DECRYPTION_AUTHENTICATION_FAILED');
        if(customer_bundle_existing($output)){
            echo "CUSTOMER_PRIVATE_ZIP_ALREADY_READY\n";return 0;
        }
        // Exclusive create avoids following a replacement symlink as root.
        $stage=$parent.'/.customer-import-'.bin2hex(random_bytes(16));
        $h=@fopen($stage,'x+b');if(!$h)customer_bundle_fail('PRIVATE_STAGE_FAILED');$temp=$stage;
        try{
            $offset=0;while($offset<strlen($plain)){$written=fwrite($h,substr($plain,$offset));if($written===false||$written===0)customer_bundle_fail('PRIVATE_STAGE_WRITE_FAILED');$offset+=$written;}
            if(!fflush($h)||(function_exists('fsync')&&!fsync($h)))customer_bundle_fail('PRIVATE_STAGE_SYNC_FAILED');
            $stat=fstat($h);
            if(!$stat||($stat['mode']&0170000)!==0100000||($stat['mode']&0777)!==0600||$stat['nlink']!==1||$stat['size']!==CUSTOMER_ZIP_BYTES||!rewind($h))customer_bundle_fail('PRIVATE_STAGE_HASH_FAILED');
            $hash=hash_init('sha256');
            if(hash_update_stream($hash,$h)!==CUSTOMER_ZIP_BYTES||!hash_equals(CUSTOMER_ZIP_SHA256,hash_final($hash)))customer_bundle_fail('PRIVATE_STAGE_HASH_FAILED');
            clearstatcache(true,$temp);$current=lstat($temp);
            if(!$current||$current['dev']!==$stat['dev']||$current['ino']!==$stat['ino']||($current['mode']&0170000)!==0100000)customer_bundle_fail('PRIVATE_STAGE_HASH_FAILED');
        }finally{fclose($h);}
        unset($plain);
        // A same-directory hard-link publication cannot replace a racing target.
        if(!@link($temp,$output)){
            if(!customer_bundle_existing($output))customer_bundle_fail('PRIVATE_OUTPUT_PUBLISH_FAILED');
        }
        if(!unlink($temp))customer_bundle_fail('PRIVATE_STAGE_REMOVE_FAILED');$temp=null;
        if(!customer_bundle_existing($output))customer_bundle_fail('PRIVATE_OUTPUT_MISSING');
        echo "CUSTOMER_PRIVATE_ZIP_READY\n";return 0;
    }catch(Throwable $e){
        $allowed=['PATH_INVALID','SYMLINK_REFUSED','EXISTING_OUTPUT_INVALID','EXISTING_OUTPUT_DIFFERS','OUTPUT_PERMISSIONS_FAILED','ARGUMENT_INVALID','ARGUMENT_VALUE_MISSING','ARGUMENT_MISSING','PHP_OPENSSL_GCM_REQUIRED','APPLICATION_ROOT_INVALID','OUTPUT_MUST_BE_PRIVATE','CIPHERTEXT_INVALID','DECRYPTION_KEY_INVALID','DECRYPTION_AUTHENTICATION_FAILED','PRIVATE_STAGE_FAILED','PRIVATE_STAGE_WRITE_FAILED','PRIVATE_STAGE_SYNC_FAILED','PRIVATE_STAGE_HASH_FAILED','PRIVATE_OUTPUT_PUBLISH_FAILED','PRIVATE_STAGE_REMOVE_FAILED','PRIVATE_OUTPUT_MISSING'];
        fwrite(STDERR,'CUSTOMER_BUNDLE_FAILED: '.(in_array($e->getMessage(),$allowed,true)?$e->getMessage():'DECRYPTION_FAILED')."\n");return 1;
    }finally{unset($key,$plain);if(is_string($temp)&&is_file($temp))unlink($temp);}
}
exit(customer_bundle_main($argv));
