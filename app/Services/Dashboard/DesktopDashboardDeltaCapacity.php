<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

/** Bound simultaneous account scans without holding business rows or queuing PHP workers. */
final class DesktopDashboardDeltaCapacity
{
    public function __construct(private ?object $pdo=null) {}

    public function run(callable $work)
    {
        $pdo=$this->pdo??DB::connection()->getPdo();$held=null;
        try{
            for($slot=1;$slot<=2;$slot++){
                $name='go-erp-desktop-delta:slot:'.$slot;
                $query=$pdo->prepare('SELECT GET_LOCK(?,0)');$query->execute([$name]);$value=$query->fetchColumn();
                abort_unless($value===0||$value==='0'||$value===1||$value==='1',503,
                    'تعذر تجهيز مزامنة التعديلات الآن؛ بيانات الجهاز محفوظة.');
                if((int)$value===1){$held=$name;break;}
            }
            abort_unless($held!==null,429,'السيرفر يجهّز تحديث أجهزة أخرى؛ ستُعاد المزامنة تلقائيًا.', ['Retry-After'=>'30']);
            return $work();
        }finally{
            if($held!==null){
                $query=$pdo->prepare('SELECT RELEASE_LOCK(?)');$query->execute([$held]);
                abort_unless((string)$query->fetchColumn()==='1',503,
                    'تعذر تأكيد اكتمال فحص التعديلات؛ بيانات الجهاز محفوظة.');
            }
        }
    }
}
