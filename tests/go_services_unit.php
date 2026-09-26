<?php
require __DIR__.'/../app/Services/GoServices/Money.php';
require __DIR__.'/../app/Services/GoServices/PaymobHmac.php';
use App\Services\GoServices\Money;
use App\Services\GoServices\PaymobHmac;
$n=0;
function check($actual,$expected,string $label):void{global $n;++$n;if($actual!==$expected)throw new RuntimeException($label.' failed');}
foreach(['0'=>0,'0.01'=>1,'1.2'=>120,'500.00'=>50000,'-3.45'=>-345,'9999999999.99'=>999999999999] as $v=>$c)check(Money::minor($v),$c,'decimal');
foreach(['1e3','1.234','NaN','',null,'--1'] as $v){try{Money::minor($v);throw new RuntimeException('invalid amount accepted');}catch(InvalidArgumentException $e){++$n;}}
for($i=-100000;$i<=100000;$i+=137)check(Money::minor(Money::decimal($i)),$i,'round-trip');
check(Money::commission(50000,Money::rate('10')),5000,'individual commission');
check(Money::commission(101,Money::rate('50')),51,'half-up rounding');
check(Money::commission(99999,Money::rate('0')),0,'explicit zero');
foreach([null,'',-1,101] as $rate){try{Money::rate($rate);throw new RuntimeException('invalid commission accepted');}catch(InvalidArgumentException $e){++$n;}}
check(Money::distance(30,31,30,31),0.0,'same point');
check(abs(Money::distance(0,0,0,1)-111.19492664455873)<0.0001,true,'haversine');
$o=['amount_cents'=>50000,'created_at'=>'2026-09-26T10:00:00','currency'=>'EGP','error_occured'=>false,'has_parent_transaction'=>false,'id'=>7,'integration_id'=>9,'is_3d_secure'=>true,'is_auth'=>false,'is_capture'=>false,'is_refunded'=>false,'is_standalone_payment'=>true,'is_voided'=>false,'order'=>['id'=>12],'owner'=>5,'pending'=>false,'source_data'=>['pan'=>'1234','sub_type'=>'MasterCard','type'=>'card'],'success'=>true];
$expected=hash_hmac('sha512','500002026-09-26T10:00:00EGPfalsefalse79truefalsefalsefalsetruefalse125false1234MasterCardcardtrue','unit-test-key');
check(PaymobHmac::digest($o,'unit-test-key'),$expected,'official key order');
check(PaymobHmac::valid($o,$expected,'unit-test-key'),true,'signature');
$o['amount_cents']=1;
check(PaymobHmac::valid($o,$expected,'unit-test-key'),false,'tampered amount');
check(PaymobHmac::valid($o,$expected,''),false,'missing key');
check(PaymobHmac::truth('false'),false,'boolean parsing');
echo "PASS $n money, distance and HMAC assertions\n";
