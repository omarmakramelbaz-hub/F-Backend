<?php
// Exercise the real import receipt validator without booting or reading a production application.
final class CapacityRejected extends RuntimeException {
    public function __construct(public int $status) { parent::__construct('HTTP '.$status); }
}
function abort_if($condition,$status): void { if ($condition) throw new CapacityRejected($status); }
function abort_unless($condition,$status): void { abort_if(!$condition,$status); }
// Local minimal PHP need not load mbstring; these fixture paths are deliberately ASCII.
if (!function_exists('mb_strtolower')) { function mb_strtolower($value) { return strtolower($value); } }
require dirname(__DIR__).'/app/Services/Dashboard/DesktopDashboardMedia.php';
use App\Services\Dashboard\DesktopDashboardMedia as Media;
function verify($condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejected(callable $work,int $status): void {
    try { $work(); } catch (CapacityRejected $error) { verify($error->status===$status,'Unexpected status'); return; }
    throw new RuntimeException('Expected rejection');
}
$large=[];
for ($i=0;$i<128;$i++) $large[]=['path'=>'products/'.($i+1).'/original.png','bytes'=>Media::MAX_FILE,
    'sha256'=>str_repeat('a',64),'mime'=>'image/png'];
$extra=['path'=>'products/129/extra.png','bytes'=>1,'sha256'=>str_repeat('b',64),'mime'=>'image/png'];
verify(count(Media::receipt([...array_slice($large,0,64),$extra]))===65,'The real receipt admits the former one-GiB overflow');
verify(count(Media::receipt($large))===128,'The real receipt admits exactly two GiB');
rejected(fn()=>Media::receipt([...$large,$extra]),413);
$mixed=$large;
foreach ($mixed as $i=>&$part) if ($i%2) { $part['area']='private'; $part['path']='branch-expenses/'.$i.'.png'; }
unset($part);
verify(count(Media::receipt($mixed))===128,'Private attachments and public images share the complete capacity');
rejected(fn()=>Media::receipt([...$mixed,$extra]),413);
rejected(fn()=>Media::receipt([array_merge($extra,['bytes'=>Media::MAX_FILE+1])]),422);
rejected(fn()=>Media::receipt([$extra,array_merge($extra,['path'=>'PRODUCTS/129/EXTRA.PNG'])]),422);
rejected(fn()=>Media::receipt([array_merge($extra,['path'=>'../outside.png'])]),422);
rejected(fn()=>Media::receipt([array_merge($extra,['mime'=>'text/html'])]),422);
echo "PASS: actual PHP receipt accepts the complete account above one GiB, enforces two GiB and shared private/public capacity, and retains per-file, path, MIME and collision guards.\n";
