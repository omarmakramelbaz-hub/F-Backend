<?php
if (PHP_SAPI !== 'cli' || getenv('ERP_DEMO') !== '1') { exit(1); }
umask(0077);
$state = __DIR__.'/state';
foreach (['','/storage/sessions','/storage/cache','/storage/views'] as $directory) {
    if (!is_dir($state.$directory)) { mkdir($state.$directory, 0700, true); }
}
$lock = fopen($state.'/setup.lock', 'c');
flock($lock, LOCK_EX);
if (is_file($state.'/ready.json')) { echo "ERP trial already prepared; existing trial data preserved.\n"; exit; }
if (is_file($state.'/demo.sqlite') && filesize($state.'/demo.sqlite') > 0) {
    fwrite(STDERR, "An incomplete trial database exists. Refusing to overwrite it.\n"); exit(1);
}
if (!is_file($state.'/app.key')) { file_put_contents($state.'/app.key', 'base64:'.base64_encode(random_bytes(32))); }
touch($state.'/demo.sqlite');
$app = require __DIR__.'/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use App\Services\Erp\Actor;
use App\Services\Erp\Ledger;
use App\Services\Erp\Stock;
use App\Services\Erp\Purchasing;
use App\Services\Erp\Manufacturing;

DB::transaction(function () {
    Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('account_type'); $t->string('password'); $t->rememberToken(); $t->timestamps(); });
    Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->string('name'); });
    Schema::create('orders', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('resturant_id'); $t->string('order_no'); $t->string('status'); $t->string('type'); $t->string('payment_type'); $t->timestamp('created_at'); });
    require_once dirname(__DIR__,3).'/database/migrations/2026_09_30_180000_create_erp_foundation.php';
    require_once dirname(__DIR__,3).'/database/migrations/2026_09_30_193000_create_erp_operations.php';
    (new CreateErpFoundation)->up();
    (new CreateErpOperations)->up();
    $owner = new Actor('legacy:1','المالك — تجربة','owner',null,Actor::CAPABILITIES);
    DB::table('users')->insert(['id'=>1,'name'=>$owner->name,'account_type'=>'admin','password'=>Hash::make(bin2hex(random_bytes(24)))]);
    DB::table('erp_warehouses')->insert(['id'=>1,'branch_id'=>null,'name'=>'المخزن المركزي']);
    foreach (['المنصورة','المحلة','نبروه','شبرا الخيمة'] as $index=>$name) {
        $id=$index+1;
        DB::table('resturants')->insert(['id'=>$id,'name'=>'فسخانستا '.$name.' — تجربة']);
        DB::table('erp_branches')->insert(['id'=>$id,'restaurant_id'=>$id,'name'=>$name,'active'=>1]);
        DB::table('erp_warehouses')->insert(['id'=>$id+1,'branch_id'=>$id,'name'=>'مخزن '.$name]);
        DB::table('erp_employees')->insert(['id'=>$id,'branch_id'=>$id,'name'=>'موظف تجريبي '.$id,'phone'=>null,'job_title'=>'مسؤول تجهيز','hired_on'=>now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'),'salary_minor'=>660000,'active'=>1]);
        DB::table('erp_salary_rates')->insert(['employee_id'=>$id,'effective_month'=>now()->subMonthNoOverflow()->format('Y-m'),'salary_minor'=>660000]);
        foreach (['pending','accepted','shipped','completed'] as $n=>$status) {
            DB::table('orders')->insert(['resturant_id'=>$id,'order_no'=>'DEMO-'.$id.'-'.$n,'status'=>$status,'type'=>'current','payment_type'=>'cash','created_at'=>now()]);
        }
    }
    DB::table('resturants')->insert(['id'=>5,'name'=>'فرع إضافي للتجربة']);
    foreach ([['deputy_manager',null,'نائب المدير'],['branch_manager',1,'مدير المنصورة']] as $index=>[$role,$branch,$name]) {
        \App\Models\Erp\StaffUser::create(['id'=>$index+1,'name'=>$name.' — تجربة','email'=>($index===0?'deputy':'branch').'@demo.test','password'=>Hash::make(bin2hex(random_bytes(24))),'role'=>$role,'branch_id'=>$branch,'permissions'=>Actor::defaults($role),'active'=>1]);
    }
    foreach ([['RAW-F','فسيخ خام','kg','raw'],['RAW-R','رنجة خام','kg','raw'],['RAW-S','سردين خام','kg','raw'],['PACK','علبة تغليف','piece','packaging'],['FIN-F','فسيخ مجهز','kg','finished']] as $index=>[$sku,$name,$unit,$category]) {
        DB::table('erp_items')->insert(['id'=>$index+1,'sku'=>$sku,'name'=>$name,'unit'=>$unit,'category'=>$category,'minimum_milli'=>5000,'active'=>1]);
    }
    (new Ledger)->initialize($owner,'25000',true);
    foreach ([1,2,3,4,5] as $warehouse) {
        foreach ([1,2,3,4] as $item) {
            (new Stock)->post($owner,['request_key'=>'demo-opening-'.$warehouse.'-'.$item,'type'=>'opening','warehouse_id'=>$warehouse,'item_id'=>$item,'quantity'=>$item===4?'100':'20','unit_cost'=>$item===4?'5':'200','reason'=>'رصيد تجريبي فقط','reference'=>'DEMO']);
        }
    }
    DB::table('erp_suppliers')->insert(['id'=>1,'name'=>'مورد أسماك — تجربة','active'=>1]);
    (new Purchasing)->receive($owner,['request_key'=>'demo-purchase-000001','supplier_id'=>1,'warehouse_id'=>1,'invoice_number'=>'DEMO-001','invoice_date'=>now('Africa/Cairo')->format('Y-m-d'),'notes'=>'فاتورة تجريبية','lines'=>[['item_id'=>1,'quantity'=>'10','unit_cost'=>'210']]]);
    $recipe=(new Manufacturing)->recipe($owner,['name'=>'تجهيز فسيخ — وصفة تجريبية','output_item_id'=>5,'output_quantity'=>'1','notes'=>'أرقام للتجربة وليست معيار تشغيل فعلي','lines'=>[['item_id'=>1,'quantity'=>'1.2'],['item_id'=>4,'quantity'=>'1']]]);
    (new Manufacturing)->produce($owner,['request_key'=>'demo-production-0001','recipe_id'=>$recipe,'warehouse_id'=>2,'factor'=>'1','actual_output'=>'1','notes'=>'دفعة تجريبية']);
});
file_put_contents($state.'/ready.json', json_encode(['kind'=>'fasakhansta-erp-trial','created_at'=>date(DATE_ATOM)]));
echo "ERP trial prepared with synthetic data and owner/deputy/branch trial sessions.\n";
