#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Authenticated, private unpack. The one-time key is accepted only on stdin. */
function xls_trim(string $value): string {return preg_replace('/(*UCP)(?:^\s+|\s+$)/u','',$value)??$value;}
function xls_expand(array $packed): array {
    if(($packed['format']??null)!==1||!is_array($packed['source']??null)
        ||!is_array($packed['headers']??null)||count($packed['headers'])!==12
        ||!is_array($packed['rows']??null)||!is_array($packed['phones']??null))
        throw new RuntimeException('PACKED_FORMAT_INVALID');
    $customers=[];$profiles=[];$phoneIndex=0;
    $sourceHash=$packed['source']['backup_sha256']??'';
    if(!is_string($sourceHash)||!preg_match('/^[a-f0-9]{64}$/D',$sourceHash))throw new RuntimeException('SOURCE_HASH_INVALID');
    foreach($packed['rows'] as $offset=>$values){
        if(!is_array($values)||count($values)!==12)throw new RuntimeException('SOURCE_ROW_INVALID');
        foreach($values as $value)if(!is_string($value))throw new RuntimeException('SOURCE_VALUE_INVALID');
        $sourceId='xls:Sheet1:row:'.($offset+2);
        $raw=array_combine($packed['headers'],$values);$raw['_worksheet_row']=$offset+2;
        $customers[]=['source_id'=>$sourceId,'raw'=>$raw];$parts=[];
        foreach([6=>'',7=>'الشارع: ',8=>'الدور: ',9=>'الشقة: '] as $column=>$label)
            if(xls_trim($values[$column])!=='')$parts[]=$label.xls_trim($values[$column]);
        $address=implode('، ',$parts);
        for($column=2;$column<6;$column++){
            if(xls_trim($values[$column])==='')continue;
            $info=$packed['phones'][$phoneIndex++]??null;
            if(!is_array($info)||count($info)!==2||($info[0]!==null&&!is_string($info[0]))||!is_array($info[1]))
                throw new RuntimeException('PHONE_INFO_INVALID');
            $profiles[]=['source_id'=>$sourceId,
                'profile_key'=>hash('sha256',$sourceHash.':'.$sourceId.':phone:'.($column-1)),
                'phone'=>$info[0],'phone_raw'=>$values[$column],'name'=>xls_trim($values[1]),
                'address'=>$address,'area'=>xls_trim($values[11]),'delivery_notes'=>xls_trim($values[10]),
                'latitude'=>null,'longitude'=>null,'flags'=>$info[1]];
        }
    }
    if($phoneIndex!==count($packed['phones']))throw new RuntimeException('PHONE_COUNT_INVALID');
    return ['source'=>$packed['source'],'customers'=>$customers,'profiles'=>$profiles];
}

function xls_unpack_main(array $argv): int {
    try{
        umask(0077);
        if(count($argv)!==4)throw new RuntimeException('ARGUMENTS_INVALID');
        [$script,$input,$metadata,$output]=$argv;
        if(!is_file($input)||!is_file($metadata)||is_link($input)||is_link($metadata)
            ||file_exists($output)||is_link($output)||realpath(dirname($output))!==dirname($output)
            ||str_starts_with($output,'/home/fasakha/public_html/'))throw new RuntimeException('PRIVATE_PATH_INVALID');
        $meta=json_decode(file_get_contents($metadata),true,512,JSON_THROW_ON_ERROR);
        $bytes=file_get_contents($input);
        if(strlen($bytes)!==$meta['payload_b64_bytes']||!hash_equals($meta['payload_sha256'],hash('sha256',$bytes)))
            throw new RuntimeException('PAYLOAD_HASH_INVALID');
        $cipher=base64_decode(trim($bytes),true);unset($bytes);
        $magic="FASAKHANSTA-XLS-CUSTOMERS-AES256GCM-V1\n";
        if(!is_string($cipher)||strlen($cipher)!==$meta['encrypted_bytes']
            ||!hash_equals($meta['cipher_sha256'],hash('sha256',$cipher))||!str_starts_with($cipher,$magic))
            throw new RuntimeException('CIPHERTEXT_INVALID');
        $encoded=trim(stream_get_contents(STDIN,128));$key=base64_decode($encoded,true);
        if(strlen($encoded)!==44||!is_string($key)||strlen($key)!==32)throw new RuntimeException('KEY_INVALID');
        $offset=strlen($magic);$nonce=substr($cipher,$offset,12);$tag=substr($cipher,-16);
        $compressed=openssl_decrypt(substr($cipher,$offset+12,-16),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$magic);
        unset($key,$encoded,$cipher);
        if(!is_string($compressed))throw new RuntimeException('AUTHENTICATED_DECRYPTION_FAILED');
        $packed=gzdecode($compressed,16777216);unset($compressed);
        if(!is_string($packed)||strlen($packed)!==$meta['packed_bytes'])throw new RuntimeException('PACKED_LENGTH_INVALID');
        $doc=xls_expand(json_decode($packed,true,512,JSON_THROW_ON_ERROR));unset($packed);
        $data=json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR);
        if(count($doc['customers'])!==$meta['source_rows']||count($doc['profiles'])!==$meta['phone_profiles']
            ||strlen($data)!==$meta['json_bytes']||!hash_equals($meta['data_sha256'],hash('sha256',$data)))
            throw new RuntimeException('EXPANDED_DATA_HASH_INVALID');
        $handle=fopen($output,'x+b');if(!$handle)throw new RuntimeException('PRIVATE_WRITE_FAILED');
        try{
            $offset=0;while($offset<strlen($data)){
                $written=fwrite($handle,substr($data,$offset));if($written===false||$written===0)throw new RuntimeException('PRIVATE_WRITE_FAILED');$offset+=$written;
            }
            if(!fflush($handle)||(function_exists('fsync')&&!fsync($handle)))throw new RuntimeException('PRIVATE_SYNC_FAILED');
        }finally{fclose($handle);}
        echo "CUSTOMER_XLS_PRIVATE_DATA_VERIFIED\n";return 0;
    }catch(Throwable $error){
        $message=$error->getMessage();
        fwrite(STDERR,'CUSTOMER_XLS_UNPACK_FAILED: '.(preg_match('/^[A-Z_]+$/D',$message)?$message:'UNPACK_FAILED')."\n");return 1;
    }
}
if(!defined('CUSTOMER_XLS_UNPACK_LIBRARY'))exit(xls_unpack_main($argv));
