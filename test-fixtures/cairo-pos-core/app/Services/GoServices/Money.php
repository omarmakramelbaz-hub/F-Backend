<?php
namespace App\Services\GoServices;
use InvalidArgumentException;
/** EGP minor units and percentage basis points; no floating-point money arithmetic. */
final class Money {
 public static function minor($value): int {
  $value=trim((string)$value);
  if (!preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/D',$value,$p)) throw new InvalidArgumentException('Invalid monetary value; at most two decimal places.');
  $n=((int)$p[2])*100+(int)str_pad($p[3]??'',2,'0');
  return ($p[1]??'')==='-'?-$n:$n;
 }
 public static function decimal(int $n): string { $s=$n<0?'-':''; $n=abs($n); return $s.intdiv($n,100).'.'.str_pad((string)($n%100),2,'0',STR_PAD_LEFT); }
 public static function rate($percent): int {
  if ($percent===null||$percent==='') throw new InvalidArgumentException('Set the individual commission in the dashboard first.');
  $n=self::minor($percent); if($n<0||$n>10000) throw new InvalidArgumentException('Commission must be between 0 and 100 percent.'); return $n;
 }
 public static function commission(int $price,int $bps): int {
  if($price<0||$price>100000000||$bps<0||$bps>10000) throw new InvalidArgumentException('Invalid commission input.');
  return intdiv($price*$bps+5000,10000);
 }
 public static function distance(float $lat1,float $lng1,float $lat2,float $lng2): float {
  $a=sin(deg2rad($lat2-$lat1)/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin(deg2rad($lng2-$lng1)/2)**2;
  return 6371*2*atan2(sqrt(max(0,min(1,$a))),sqrt(max(0,1-$a)));
 }
}
