<?php
// Pure, synthetic delta protocol proofs. No application bootstrap, database or account data.
function abort_unless($condition,$status,$message=''){if(!$condition)throw new RuntimeException($message?:('HTTP '.$status));}
function abort_if($condition,$status,$message=''){abort_unless(!$condition,$status,$message);}
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardDeltaCodec.php';
use App\Services\Dashboard\DesktopDashboardDeltaCodec as Codec;

function check($condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function refused(callable $work): void {try{$work();}catch(Throwable $error){return;}throw new RuntimeException('Invalid delta input was accepted.');}
function ddl(string $table='products',int $next=10): string {
    return "CREATE TABLE `$table` (\n  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `name` varchar(100) NOT NULL,\n  `amount` decimal(12,3) DEFAULT NULL,\n  `rating` double DEFAULT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB AUTO_INCREMENT=$next DEFAULT CHARSET=utf8mb4";
}
function userDdl(): string {return "CREATE TABLE `users` (\n  `id` bigint unsigned NOT NULL,\n  `name` varchar(100) NOT NULL,\n  `password` varchar(255) NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";}
function rows(): array {return [['id'=>1,'name'=>'أول','amount'=>'10.500','rating'=>0.1],['id'=>2,'name'=>'Second','amount'=>null,'rating'=>null]];}
function sameRows(array $left,array $right,string $table,string $structure,int $actor=1): void {
    check(Codec::index($table,$left,$actor,$structure)===Codec::index($table,$right,$actor,$structure),'Reconstructed row multiset differs.');
}

$cases=[];
$cases['unchanged'] = static function(): void {
    $before=rows();$index=Codec::index('products',$before,1,ddl());
    $delta=Codec::delta('products',$before,1,ddl(),$index);
    check($delta['remove']===[]&&$delta['add']===[],'Unchanged rows were transferred.');
    check($delta['count']===2&&$delta['target_hash']===Codec::tableHash($index['hashes']),'Unchanged target is not bound to its index.');
    sameRows($before,Codec::apply('products',$before,1,ddl(),$delta),'products',ddl());
};
$cases['changed-added-deleted'] = static function(): void {
    $before=rows();$after=[['id'=>1,'name'=>'Changed','amount'=>'12.500','rating'=>0.3],['id'=>3,'name'=>'New','amount'=>null,'rating'=>null]];
    $delta=Codec::delta('products',$after,1,ddl(),Codec::index('products',$before,1,ddl()));
    check(array_sum($delta['remove'])===2&&count($delta['add'])===2,'Only the changed/new rows and removed digests must cross the wire.');
    sameRows($after,Codec::apply('products',$before,1,ddl(),$delta),'products',ddl());
};
$cases['empty-table'] = static function(): void {
    $before=rows();$delta=Codec::delta('products',[],1,ddl(),Codec::index('products',$before,1,ddl()));
    check($delta['add']===[]&&$delta['count']===0&&array_sum($delta['remove'])===2,'Empty target must explicitly remove prior rows.');
    check(Codec::apply('products',$before,1,ddl(),$delta)===[],'Deletion-only delta did not produce an empty table.');
};
$cases['duplicate-pivot-rows'] = static function(): void {
    $schema="CREATE TABLE `product_features` (\n  `product_id` bigint unsigned NOT NULL,\n  `feature_id` bigint unsigned NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $one=['product_id'=>12,'feature_id'=>7];$two=['product_id'=>12,'feature_id'=>9];
    $before=[$one,$one,$two];$after=[$one,$two,$two];
    $old=Codec::index('product_features',$before,1,$schema);$delta=Codec::delta('product_features',$after,1,$schema,$old);
    check(max($old['hashes'])===2&&array_sum($delta['remove'])===1&&count($delta['add'])===1,'Duplicate row multiplicities were collapsed.');
    sameRows($after,Codec::apply('product_features',$before,1,$schema,$delta),'product_features',$schema);
};
$cases['row-and-key-order'] = static function(): void {
    $before=rows();$other=array_reverse(array_map(static fn($row)=>array_reverse($row,true),$before));
    check(Codec::index('products',$before,1,ddl())===Codec::index('products',$other,1,ddl()),'Index depends on row or associative key order.');
};
$cases['php-precision'] = static function(): void {
    $previousSerialize=ini_get('serialize_precision');$previousPrecision=ini_get('precision');$proofs=[];
    try{
        foreach([[-1,14],[3,3],[17,17],[100,100]] as [$serialization,$display]){
            ini_set('serialize_precision',(string)$serialization);ini_set('precision',(string)$display);
            $proofs[]=Codec::index('products',rows(),1,ddl());
            check(ini_get('serialize_precision')===(string)$serialization&&ini_get('precision')===(string)$display,'Codec changed request PHP settings.');
        }
    }finally{ini_set('serialize_precision',$previousSerialize);ini_set('precision',$previousPrecision);}
    foreach($proofs as $proof)check($proof===$proofs[0],'Row hashes differ across PHP precision settings.');
};
$cases['pdo-numeric-variants'] = static function(): void {
    $one=['id'=>12,'name'=>'Same','amount'=>'010.500','rating'=>0.5];$two=['rating'=>'0.5','amount'=>10.5,'name'=>'Same','id'=>'00012'];
    check(Codec::rowHash('products',$one,1,ddl())===Codec::rowHash('products',$two,1,ddl()),'Schema-equivalent PDO numeric representations differ.');
};
$cases['sql-float-storage-precision'] = static function(): void {
    $single=str_replace('`rating` double','`rating` float',ddl());$row=rows()[0];$row['rating']=1.23;
    $wide=$row;$wide['rating']=unpack('Gvalue',pack('G',1.23))['value'];$text=$row;$text['rating']='1.23';
    check($row['rating']!==$wide['rating'],'Fixture did not expose PDO float widening.');
    check(Codec::rowHash('products',$row,1,$single)===Codec::rowHash('products',$wide,1,$single)
        &&Codec::rowHash('products',$row,1,$single)===Codec::rowHash('products',$text,1,$single),'Equivalent physical SQL FLOAT values hash differently.');
    $next=$row;$next['rating']=unpack('Gvalue',pack('N',unpack('Nvalue',pack('G',1.23))['value']+1))['value'];
    check(Codec::rowHash('products',$row,1,$single)!==Codec::rowHash('products',$next,1,$single),'Adjacent physical FLOAT values collapsed.');
    check(Codec::rowHash('products',$row,1,ddl())!==Codec::rowHash('products',$wide,1,ddl()),'DOUBLE lost its binary64 precision.');
    foreach(['float(0)','float(24)','float(12,4)'] as $type){$schema=str_replace('`rating` double','`rating` '.$type,ddl());check(Codec::rowHash('products',$row,1,$schema)===Codec::rowHash('products',$wide,1,$schema),'Single precision declaration was hashed as DOUBLE.');}
    foreach(['float(25)','float(53)','double precision','real'] as $type){$schema=str_replace('`rating` double','`rating` '.$type,ddl());check(Codec::rowHash('products',$row,1,$schema)!==Codec::rowHash('products',$wide,1,$schema),'Double precision declaration was hashed as FLOAT.');}
};
$cases['sql-float-range-and-roundtrip'] = static function(): void {
    $schema=str_replace('`rating` double','`rating` float',ddl());$old=rows();$target=$old;$target[0]['rating']=1.23;
    $previous=ini_get('serialize_precision');
    try{
        ini_set('serialize_precision','2');
        $delta=Codec::delta('products',$target,1,$schema,Codec::index('products',$old,1,$schema));
        $wire=json_decode(json_encode($delta,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR);
        $result=Codec::apply('products',$old,1,$schema,$wire);
        sameRows($target,$result,'products',$schema);
        $reloaded=$result;foreach($reloaded as &$row)if($row['rating']!==null)$row['rating']=unpack('Gvalue',pack('G',(float)$row['rating']))['value'];unset($row);
        $next=Codec::delta('products',$target,1,$schema,Codec::index('products',$reloaded,1,$schema));
        check($next['add']===[]&&$next['remove']===[],'Imported SQL FLOAT causes a permanent resend cycle.');
        $value=rows()[0];
        foreach(['00000001','7f7fffff','ff7fffff'] as $bits){$value['rating']=unpack('Gvalue',hex2bin($bits))['value'];check(is_string(Codec::rowHash('products',$value,1,$schema)),'Representable FLOAT boundary was rejected.');}
        foreach([3.5e38,-3.5e38,1e-50,-1e-50,'1e-9999'] as $number){$value['rating']=$number;refused(static fn()=>Codec::rowHash('products',$value,1,$schema));}
        $unsigned=str_replace('`rating` float','`rating` float unsigned',$schema);$value['rating']=-1.23;refused(static fn()=>Codec::rowHash('products',$value,1,$unsigned));
        $negative=rows()[0];$positive=$negative;$negative['rating']=-0.0;$positive['rating']=0.0;
        check(Codec::rowHash('products',$negative,1,$schema)===Codec::rowHash('products',$positive,1,$schema),'FLOAT zero sign creates a false change.');
    }finally{ini_set('serialize_precision',$previous);}
};
$cases['lossless-json-floats'] = static function(): void {
    $previous=ini_get('serialize_precision');$before=rows();$after=$before;$after[0]['rating']=0.12345678901234568;
    try{
        ini_set('serialize_precision','2');
        $delta=Codec::delta('products',$after,1,ddl(),Codec::index('products',$before,1,ddl()));
        check(is_string($delta['add'][0]['rating']),'Changed SQL float was not transported as a lossless string.');
        $wire=json_decode(json_encode($delta,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR);
        sameRows($after,Codec::apply('products',$before,1,ddl(),$wire),'products',ddl());
        check(ini_get('serialize_precision')==='2','Codec changed PHP serialization settings.');
    }finally{ini_set('serialize_precision',$previous);}
};
$cases['large-integers'] = static function(): void {
    $one=['id'=>'18446744073709551614','name'=>'Same','amount'=>null,'rating'=>null];$two=$one;$two['id']='18446744073709551615';
    check(Codec::rowHash('products',$one,1,ddl())!==Codec::rowHash('products',$two,1,ddl()),'Unsigned bigint values lost precision.');
};
$cases['sql-zero'] = static function(): void {
    $one=rows()[0];$two=$one;$one['rating']=-0.0;$two['rating']=0.0;
    check(Codec::rowHash('products',$one,1,ddl())===Codec::rowHash('products',$two,1,ddl()),'SQL floating zero sign creates a false change.');
};
$cases['authorized-projection'] = static function(): void {
    $schema="CREATE TABLE `pending_vendors` (\n  `id` bigint unsigned NOT NULL,\n  `profession_key` varchar(50) NOT NULL,\n  `type` varchar(50) NOT NULL,\n  `status` varchar(50) NOT NULL,\n  `source_app` varchar(50) NOT NULL,\n  `full_name` varchar(100) NOT NULL,\n  `mobile` varchar(50) NOT NULL,\n  `other_field` varchar(100) DEFAULT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $coverage=['pending_vendor_eligibility'=>['kind'=>'go-store-owner-only','source_fields'=>['id','profession_key','type','status','source_app'],'redacted_required_fields'=>['full_name','mobile']]];
    $projection=Codec::projection('pending_vendors',$coverage);
    $server=['id'=>1,'profession_key'=>'fixture','type'=>'fixture','status'=>'accepted','source_app'=>'fixture','full_name'=>'','mobile'=>''];
    $local=$server+['other_field'=>null];
    check(Codec::index('pending_vendors',[$server],1,$schema,$projection)===Codec::index('pending_vendors',[$local],1,$schema,$projection),'Authorized projected rows differ from their local default columns.');
    check(Codec::projection('users',$coverage)===null,'Pending-vendor projection escaped to another table.');
    refused(static fn()=>Codec::projection('pending_vendors',['pending_vendor_eligibility'=>['kind'=>'full-account']]));
};
$cases['disabled-password'] = static function(): void {
    $one=['id'=>2,'name'=>'Other','password'=>'!desktop-disabled-fixture-one'];$two=$one;$two['password']='!desktop-disabled-fixture-two';
    check(Codec::rowHash('users',$one,1,userDdl())===Codec::rowHash('users',$two,1,userDdl()),'Random disabled passwords make unchanged users appear modified.');
    $one['id']=$two['id']=1;
    check(Codec::rowHash('users',$one,1,userDdl())!==Codec::rowHash('users',$two,1,userDdl()),'The enrolled actor password change was ignored.');
};
$cases['schema-auto-counter'] = static function(): void {
    check(Codec::schemaHash(ddl('products',10))===Codec::schemaHash(ddl('products',99999)),'Next AUTO_INCREMENT counter changed structural identity.');
    $delta=Codec::delta('products',rows(),1,ddl('products',99999),Codec::index('products',rows(),1,ddl('products',10)));
    check(!array_key_exists('replace',$delta)&&$delta['add']===[]&&$delta['remove']===[],'A counter-only update replaced unchanged rows.');
    foreach([str_replace('decimal(12,3)','decimal(14,3)',ddl()),str_replace('ENGINE=InnoDB','ENGINE=MyISAM',ddl()),str_replace('PRIMARY KEY (`id`)','UNIQUE KEY `name_unique` (`name`)',ddl()),str_replace('  PRIMARY KEY',"  CONSTRAINT `owner_fk` FOREIGN KEY (`id`) REFERENCES `users` (`id`),\n  PRIMARY KEY",ddl())] as $changed)
        check(Codec::schemaHash(ddl())!==Codec::schemaHash($changed),'A real schema change was discarded.');
};
$cases['schema-counter-in-default'] = static function(): void {
    $one=str_replace('`name` varchar(100) NOT NULL',"`name` varchar(100) NOT NULL DEFAULT 'AUTO_INCREMENT=17'",ddl());
    $two=str_replace("'AUTO_INCREMENT=17'","'AUTO_INCREMENT=19'",$one);
    check(Codec::schemaHash($one)!==Codec::schemaHash($two),'AUTO_INCREMENT text inside a SQL value was discarded.');
};
$cases['malformed-index'] = static function(): void {
    $index=Codec::index('products',rows(),1,ddl());$digest=array_key_first($index['hashes']);
    foreach([['count'=>-1],['count'=>500001],['count'=>1],['hashes'=>['not-sha256'=>2]],['hashes'=>[$digest=>0]],['hashes'=>[$digest=>-1]],['hashes'=>[$digest=>'2']],['schema_sha256'=>'invalid-schema']] as $changes){
        $bad=array_replace($index,$changes);refused(static fn()=>Codec::delta('products',rows(),1,ddl(),$bad));
    }
};
$cases['malformed-delta'] = static function(): void {
    $before=rows();$delta=Codec::delta('products',[$before[0]],1,ddl(),Codec::index('products',$before,1,ddl()));
    foreach([['remove'=>['not-sha256'=>1]],['remove'=>[str_repeat('f',64)=>1]],['remove'=>[array_key_first($delta['remove'])=>-1]],['remove'=>[array_key_first($delta['remove'])=>5]],['count'=>-1],['count'=>2],['target_hash'=>str_repeat('0',64)],['add'=>[['id'=>3,'name'=>'tamper','amount'=>null,'rating'=>null]]]] as $changes){
        $bad=array_replace($delta,$changes);refused(static fn()=>Codec::apply('products',$before,1,ddl(),$bad));
    }
};
$cases['foreign-columns-and-numbers'] = static function(): void {
    $row=rows()[0];$unknown=$row;$unknown['unexpected']='fixture';refused(static fn()=>Codec::rowHash('products',$unknown,1,ddl()));
    foreach([INF,-INF,NAN] as $number){$bad=$row;$bad['rating']=$number;refused(static fn()=>Codec::rowHash('products',$bad,1,ddl()));}
    $bad=$row;$bad['id']='12-invalid';refused(static fn()=>Codec::rowHash('products',$bad,1,ddl()));
};
$cases['replacement-removed-column'] = static function(): void {
    $oldDdl="CREATE TABLE `products` (\n  `id` bigint unsigned NOT NULL,\n  `name` varchar(100) NOT NULL,\n  `old_notes` varchar(100) DEFAULT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $newDdl="CREATE TABLE `products` (\n  `id` bigint unsigned NOT NULL,\n  `name` varchar(100) NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $old=[['id'=>1,'name'=>'Before','old_notes'=>'PRIVATE fixture discarded field'],['id'=>2,'name'=>'Other','old_notes'=>null]];
    $after=[['id'=>1,'name'=>'Current']];$before=Codec::index('products',$old,1,$oldDdl);$delta=Codec::delta('products',$after,1,$newDdl,$before);
    check(($delta['replace']??null)===true&&$delta['remove']===$before['hashes']&&$delta['add']===$after,'A structural change did not explicitly replace the whole prior table.');
    $result=Codec::applyReplacement('products',$delta['add'],1,$newDdl,$delta,$before);
    check($result===$after&&!str_contains(json_encode($delta,JSON_THROW_ON_ERROR),'PRIVATE'),'Removed local-only columns survived the replacement.');
    refused(static fn()=>Codec::apply('products',$old,1,$newDdl,$delta));
};
$cases['replacement-new-column'] = static function(): void {
    $oldDdl="CREATE TABLE `products` (\n  `id` bigint unsigned NOT NULL,\n  `name` varchar(100) NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $old=[['id'=>1,'name'=>'Current']];$after=[['id'=>1,'name'=>'Current','amount'=>'12.500','rating'=>0.12345678901234568]];
    $before=Codec::index('products',$old,1,$oldDdl);$delta=Codec::delta('products',$after,1,ddl(),$before);
    check(($delta['replace']??null)===true&&$delta['remove']===$before['hashes'],'An added column reused an incompatible row baseline.');
    sameRows($after,Codec::applyReplacement('products',$delta['add'],1,ddl(),$delta,$before),'products',ddl());
};
$cases['replacement-empty-target'] = static function(): void {
    $oldDdl=str_replace('varchar(100)','varchar(90)',ddl());$before=Codec::index('products',rows(),1,$oldDdl);$delta=Codec::delta('products',[],1,ddl(),$before);
    check(($delta['replace']??null)===true&&$delta['remove']===$before['hashes']&&$delta['add']===[]&&$delta['count']===0,'Empty structural replacement lost its removal proof.');
    check(Codec::applyReplacement('products',[],1,ddl(),$delta,$before)===[],'Empty replacement did not validate.');
};
$cases['replacement-proof-and-target'] = static function(): void {
    $oldDdl=str_replace('varchar(100)','varchar(90)',ddl());$before=Codec::index('products',rows(),1,$oldDdl);$after=[rows()[0]];$delta=Codec::delta('products',$after,1,ddl(),$before);
    $reordered=$before;$reordered['hashes']=array_reverse($before['hashes'],true);
    sameRows($after,Codec::applyReplacement('products',$delta['add'],1,ddl(),$delta,$reordered),'products',ddl());
    foreach([['replace'=>false],['replace'=>'true'],['remove'=>[]],['remove'=>[str_repeat('f',64)=>2]],['count'=>2],['target_hash'=>str_repeat('0',64)]] as $change){
        $bad=array_replace($delta,$change);refused(static fn()=>Codec::applyReplacement('products',$bad['add'],1,ddl(),$bad,$before));
    }
    $forged=$before;$forged['hashes']=[str_repeat('f',64)=>2];
    refused(static fn()=>Codec::applyReplacement('products',$delta['add'],1,ddl(),$delta,$forged));
    $badBefore=$before;$badBefore['count']=1;refused(static fn()=>Codec::applyReplacement('products',$delta['add'],1,ddl(),$delta,$badBefore));
    $wrongRows=$delta['add'];$wrongRows[0]['name']='Tampered';refused(static fn()=>Codec::applyReplacement('products',$wrongRows,1,ddl(),$delta,$before));
    $without=$delta;unset($without['replace']);refused(static fn()=>Codec::applyReplacement('products',$without['add'],1,ddl(),$without,$before));
};
$cases['trusted-projection-transition'] = static function(): void {
    $schema="CREATE TABLE `pending_vendors` (\n  `id` bigint unsigned NOT NULL,\n  `profession_key` varchar(50) NOT NULL,\n  `type` varchar(50) NOT NULL,\n  `status` varchar(50) NOT NULL,\n  `source_app` varchar(50) NOT NULL,\n  `full_name` varchar(100) NOT NULL,\n  `mobile` varchar(50) NOT NULL,\n  `other_field` varchar(100) DEFAULT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $coverage=['pending_vendor_eligibility'=>['kind'=>'go-store-owner-only','source_fields'=>['id','profession_key','type','status','source_app'],'redacted_required_fields'=>['full_name','mobile']]];
    $projection=Codec::projection('pending_vendors',$coverage);
    $old=[['id'=>1,'profession_key'=>'fixture','type'=>'fixture','status'=>'accepted','source_app'=>'fixture','full_name'=>'PRIVATE name','mobile'=>'PRIVATE number','other_field'=>'PRIVATE omitted field']];
    $after=[['id'=>1,'profession_key'=>'fixture','type'=>'fixture','status'=>'accepted','source_app'=>'fixture','full_name'=>'','mobile'=>'']];
    $before=Codec::index('pending_vendors',$old,1,$schema);
    $delta=Codec::delta('pending_vendors',$after,1,$schema,$before,$projection);
    check(!array_key_exists('replace',$delta)&&$delta['remove']===$before['hashes'],'Projection-only change should use the ordinary wire delta and remove the full old baseline.');
    refused(static fn()=>Codec::applyReplacement('pending_vendors',$delta['add'],1,$schema,$delta,$before,$projection));
    refused(static fn()=>Codec::applyReplacement('pending_vendors',$delta['add'],1,$schema,$delta,$before,$projection,false));
    $result=Codec::applyReplacement('pending_vendors',$delta['add'],1,$schema,$delta,$before,$projection,true);
    check($result===$after&&!str_contains(json_encode($result,JSON_THROW_ON_ERROR),'PRIVATE'),'Trusted projection transition retained omitted private fields.');
    $partial=$delta;$partial['remove']=[];
    refused(static fn()=>Codec::applyReplacement('pending_vendors',$partial['add'],1,$schema,$partial,$before,$projection,true));
    $tampered=$delta;$tampered['add'][0]['other_field']='PRIVATE injected field';
    $clean=Codec::applyReplacement('pending_vendors',$tampered['add'],1,$schema,$tampered,$before,$projection,true);
    check($clean===$after,'A canonical-equivalent field outside the target projection survived reconstruction.');
    $retained=Codec::apply('pending_vendors',[$after[0]+['other_field'=>'PRIVATE retained field']],1,$schema,
        Codec::delta('pending_vendors',$after,1,$schema,Codec::index('pending_vendors',$after,1,$schema,$projection),$projection),$projection);
    check($retained===$after,'Retained rows were not restricted to the trusted target projection.');
};
$cases['disabled-password-sanitized'] = static function(): void {
    $actor=['id'=>1,'name'=>'Actor','password'=>'fixture-actor-password'];$other=['id'=>2,'name'=>'Other','password'=>'!desktop-disabled-random-fixture'];
    $delta=Codec::delta('users',[$actor,$other],1,userDdl(),Codec::index('users',[],1,userDdl()));
    check($delta['add'][0]['password']===$actor['password']&&$delta['add'][1]['password']==='!desktop-disabled','Transport did not sanitize only the nonactor password.');
    $delta['add'][1]['password']='$2y$10$FixtureOnlyNotARealPasswordHashAndNeverAuthorized';
    $applied=Codec::apply('users',[],1,userDdl(),$delta);
    check($applied[0]['password']===$actor['password']&&$applied[1]['password']==='!desktop-disabled','Canonical normalization allowed a nonactor password hash into local rows.');
    $oldDdl=str_replace('varchar(100)','varchar(90)',userDdl());$before=Codec::index('users',[$other],1,$oldDdl);
    $replacement=Codec::delta('users',[$actor,$other],1,userDdl(),$before);$replacement['add'][1]['password']='$2y$10$FixtureOnlyReplacementTamper';
    $applied=Codec::applyReplacement('users',$replacement['add'],1,userDdl(),$replacement,$before);
    check($applied[0]['password']===$actor['password']&&$applied[1]['password']==='!desktop-disabled','Structural replacement restored another account password.');
    $unchanged=Codec::delta('users',[$other],1,userDdl(),Codec::index('users',[$other],1,userDdl()));
    $retained=Codec::apply('users',[$other],1,userDdl(),$unchanged);
    check($retained[0]['password']==='!desktop-disabled','Unchanged retained rows were not sanitized.');
};

$selected=$argv[1]??null;
if($selected!==null){check(isset($cases[$selected]),'Unknown codec test case.');$cases[$selected]();echo 'PASS '.$selected.PHP_EOL;}
else{foreach($cases as $name=>$work){$work();echo 'PASS '.$name.PHP_EOL;}}
