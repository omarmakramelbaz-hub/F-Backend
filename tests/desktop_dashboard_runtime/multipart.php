<?php
// Exercise actual multipart parsing and signed-in cookies on the disposable gateway.
$multipart=function(array $form,array $headers,string $bytes,string $filename='فاتورة المورد.pdf')use($origin,&$cookies){
    $boundary='desktop-fixture-'.bin2hex(random_bytes(12));$body='';
    foreach($form as $key=>$value)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$key."\"\r\n\r\n".$value."\r\n";
    $body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"attachment\"; filename=\"".$filename."\"\r\nContent-Type: application/pdf\r\n\r\n".$bytes."\r\n--".$boundary."--\r\n";
    $headers[]='Content-Type: multipart/form-data; boundary='.$boundary;$headers[]='Accept: application/json';
    if($cookies)$headers[]='Cookie: '.implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($cookies),$cookies));
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>15,'follow_location'=>0]]);
    $reply=@file_get_contents($origin.'/admin/branch-expenses/save',false,$context);$replyHeaders=$http_response_header??[];
    preg_match('/^HTTP\/\S+ (\d+)/',$replyHeaders[0]??'',$status);
    foreach($replyHeaders as $header)if(preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$header,$match))$cookies[$match[1]]=$match[2];
    return [(int)($status[1]??0),$reply];
};
