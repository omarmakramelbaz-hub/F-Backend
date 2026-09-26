<?php
namespace App\Services\GoServices;
/** Processed POST callbacks only; browser redirects never settle payments. */
final class PaymobHmac {
 private const KEYS=['amount_cents','created_at','currency','error_occured','has_parent_transaction','id','integration_id','is_3d_secure','is_auth','is_capture','is_refunded','is_standalone_payment','is_voided','order.id','owner','pending','source_data.pan','source_data.sub_type','source_data.type','success'];
 public static function digest(array $object,string $secret): string {
  $text=''; foreach(self::KEYS as $key){$value=$object;foreach(explode('.',$key) as $part)$value=is_array($value)?($value[$part]??null):null;$text.=is_bool($value)?($value?'true':'false'):(string)$value;}
  return hash_hmac('sha512',$text,$secret);
 }
 public static function valid(array $object,string $received,string $secret): bool {
  return $secret!==''&&(bool)preg_match('/^[a-f0-9]{128}$/iD',$received)&&hash_equals(self::digest($object,$secret),strtolower($received));
 }
 public static function truth($value): bool {return in_array($value,[true,1,'true','1'],true);}
}
