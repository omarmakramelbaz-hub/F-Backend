<?php
require __DIR__.'/../../app/Support/PartnerWorkArea.php';
use App\Support\PartnerWorkArea as Area;
function ok($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
foreach ([1, 7, 10, 255, '١٠', '۲۵'] as $n) ok(Area::radius($n) !== null, 'valid radius');
foreach ([null, '', 0, -1, 256, '1.5', '10km', '1e1', true, [], INF, NAN] as $n) ok(Area::radius($n) === null, 'invalid radius');
$details = ['account_type'=>'delegate', 'name'=>'A', 'lat'=>11, 'lng'=>22, 'work_lat'=>'٣١٫٠٤', 'work_lng'=>'٣١٫٣٨', 'work_radius_km'=>'٧'];
$area = Area::take($details);
ok($area === ['lat'=>31.04, 'lng'=>31.38, 'work_radius_km'=>7], 'normalized coverage');
ok($details === ['account_type'=>'delegate', 'name'=>'A', 'lat'=>11, 'lng'=>22], 'no form fields or live GPS changes');
$p = (object)['profession_key'=>'plumber', 'national_id'=>'test-only'];
Area::apply($p, $area);
ok($p->lat === 31.04 && $p->lng === 31.38 && $p->work_radius_km === 7, 'profile coverage');
ok($p->profession_key === 'plumber' && $p->national_id === 'test-only', 'unrelated data preserved');
$old = clone $p; Area::apply($p, null); ok($old == $p, 'missing fields preserve coverage');
$plain = ['account_type'=>'user', 'work_lat'=>1, 'work_lng'=>2, 'work_radius_km'=>5];
ok(Area::take($plain) === null && $plain === ['account_type'=>'user'], 'non-delegate strips coverage');
foreach ([['work_lat'=>91], ['work_lng'=>181], ['work_radius_km'=>0], ['work_lat'=>''], ['work_radius_km'=>'1.5']] as $bad) {
    $data = array_merge(['account_type'=>'delegate', 'work_lat'=>0, 'work_lng'=>0, 'work_radius_km'=>7], $bad);
    try { Area::take($data); throw new RuntimeException('invalid coverage accepted'); } catch (InvalidArgumentException $e) {}
}
$zero = ['account_type'=>'delegate', 'work_lat'=>0, 'work_lng'=>0, 'work_radius_km'=>1];
ok(Area::take($zero)['lat'] === 0.0, 'zero coordinates are not empty');
echo "PASS: work-area normalization, bounds, persistence mapping and GPS separation\n";
