<?php
/** Real saved-report/controller reads against the isolated shift integration fixture. */
class ShiftDetailsBaseController
{
    public array $middlewareCallbacks=[];
    public function middleware($callback){$this->middlewareCallbacks[]=$callback;}
}
class_alias(ShiftDetailsBaseController::class,'App\\Http\\Controllers\\Controller');
class ShiftDetailsResponse
{
    public array $headers=[];
    public function __construct(public string $name,public array $data){}
    public function header($name,$value){$this->headers[$name]=$value;return $this;}
}
function auth($guard=null){return new class {public function user(){return $GLOBALS['shiftDetailsActor'];}};}
function response(){return new class {public function view($name,$data){return new ShiftDetailsResponse($name,$data);}};}

require __DIR__.'/shift_report_integration_test.php';
require_once dirname(__DIR__).'/app/Http/Controllers/Dashboard/BranchShiftClosingController.php';

use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Dashboard\BranchShiftClosingController;

$controller=new BranchShiftClosingController;
$reportId=$first['closing']['id'];
$original=$shift->receipt($reportId,actor());
$tables=['branch_shift_closings','branch_shift_sources','branch_shift_print_jobs','branch_order_sequences','takeaway_tills','takeaway_till_entries','takeaway_orders','pos_service_tickets'];
$savedState=function()use($tables){$out=[];foreach($tables as $table)$out[$table]=json_encode(DB::table($table)->orderBy('id')->get()->all());return $out;};
$before=$savedState();$writes=[];
DB::listen(function($query)use(&$writes){if(preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i',$query->sql))$writes[]=$query->sql;});

foreach([1,2] as $manager){
    $shiftDetailsActor=actor($manager);
    $preview=$controller->middlewareCallbacks[0](null,fn()=>$controller->details($reportId,$shift));
    check($preview->name==='admin.branch_shifts.receipt'&&$preview->data['preview']===true,'management account '.$manager.' can preview the selected saved report');
    check($preview->data['report']===$original,'preview uses the original immutable closing snapshot');
    check($preview->headers['Cache-Control']==='private, no-store, max-age=0'&&$preview->headers['Referrer-Policy']==='no-referrer','private financial preview disables storage and referrer leakage');
}
foreach([4,10,11,12] as $branchActor){
    $shiftDetailsActor=actor($branchActor);
    denied(fn()=>$controller->middlewareCallbacks[0](null,fn()=>$controller->details($reportId,$shift)),403,'management middleware blocks branch account '.$branchActor.' from invoice preview');
    denied(fn()=>$controller->details($reportId,$shift),403,'saved report service independently blocks branch account '.$branchActor.' from invoice preview');
}
$shiftDetailsActor=actor(4);$shiftDetailsActor->account_type='admin';$shiftDetailsActor->owner_resturant_id=null;
denied(fn()=>$controller->details($reportId,$shift),403,'forged stale role cannot retrieve a financial preview');
$shiftDetailsActor=actor(1);
denied(fn()=>$controller->details(999999,$shift),404,'unknown closing preview does not fall back to another report');
$print=$controller->print($reportId,$shift);
check(!isset($print->data['preview'])&&$print->data['report']===$original,'print route keeps its existing private screen mode');
check($writes===[]&&$savedState()===$before,'preview and denial paths create no closing, print job, counter, sale or cash change');
if(getenv('SHIFT_TEST_VIEW')){
    // Optional isolated Illuminate View package enables actual Blade rendering without app boot.
    $loader=require $autoload;
    $loader->addPsr4('Illuminate\\View\\',rtrim(getenv('SHIFT_TEST_VIEW'),'/').'/');
    $compiler=new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem,sys_get_temp_dir());
    $compiled=$compiler->compileString(file_get_contents(dirname(__DIR__).'/resources/views/admin/branch_shifts/receipt.blade.php'));
    $render=function(array $data)use($compiled){
        $__env=new class {use \Illuminate\View\Concerns\ManagesLoops;};
        extract($data,EXTR_SKIP);ob_start();try{eval('?>'.$compiled);return ob_get_clean();}catch(\Throwable $e){ob_end_clean();throw $e;}
    };
    $html=$render(['report'=>$original,'preview'=>true]);
    check(strpos($html,'data-dashboard-invoice-details="1"')!==false&&strpos($html,'data-dashboard-receipt=')===false&&strpos($html,'name="dashboard-print-document"')===false,'rendered preview carries only the screen-details marker');
    check(strpos($html,'معاينة فاتورة الإغلاق المحفوظة')!==false&&preg_match('/@media\s+screen\{html,body\{background:/',$html)===1&&strpos($html,'.shift-paper{display:block;background:')!==false,'rendered preview enables screen paper with its own review notice');
    foreach(['<script','window.print(','<button','<form'] as $forbidden)check(stripos($html,$forbidden)===false,'rendered preview contains no '.$forbidden);
    check(!preg_match('/@(if|else|endif|foreach|endforeach|php|endphp)\b|\{\{/',$html),'real Blade rendering leaves no template directives or placeholders');
    check(strpos($html,$original['number'])!==false&&strpos($html,$original['expected_cash'])!==false&&strpos($html,$original['counted_cash'])!==false,'rendered preview shows the selected saved financial values');
    $printed=$render(['report'=>$original]);
    check(strpos($printed,'data-dashboard-receipt="branch-shift"')!==false&&strpos($printed,'name="dashboard-print-document"')!==false&&strpos($printed,'data-dashboard-invoice-details=')===false,'default rendered receipt preserves the branch print document markers');
    check(preg_match('/@media\s+screen\{\.shift-paper\{display:none}}/',$printed)===1&&strpos($printed,'.shift-paper{display:block;background:')===false&&strpos($printed,'تظهر الأرصدة في الورقة المطبوعة فقط.')!==false,'default rendered receipt keeps financial paper hidden on screen');
    if(getenv('SHIFT_TEST_PREVIEW_HTML'))file_put_contents(getenv('SHIFT_TEST_PREVIEW_HTML'),$html);
    if(getenv('SHIFT_TEST_PRINT_HTML'))file_put_contents(getenv('SHIFT_TEST_PRINT_HTML'),$printed);
}
echo 'SHIFT_REPORT_DETAILS_OK'.PHP_EOL;
