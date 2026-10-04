<?php
namespace App\Http\Controllers\Dashboard;
use App\Http\Controllers\Controller;
use App\Services\Dashboard\{BranchOperations,BranchCustomers,BranchPayroll,DeliveryCompanies,TakeawayAccess};
use Illuminate\Http\Request;

class BranchOperationsController extends Controller
{
    public function __construct(){ $this->middleware(function($request,$next){abort_unless(app(TakeawayAccess::class)->canAccess(auth('admin')->user()),403);return $next($request);}); }
    private function module(Request $r): string {return explode('.',$r->route()->getName())[0];}
    private function service(Request $r){return app(['customers'=>BranchCustomers::class,'delivery-companies'=>DeliveryCompanies::class,'employees'=>BranchPayroll::class][$this->module($r)]);}
    private function actor(Request $r){$actor=auth('admin')->user();if($this->module($r)==='employees')abort_unless(in_array($actor->account_type,['admin','vendor','resturant_owner'],true),403);return $actor;}
    public function index(Request $r,TakeawayAccess $access)
    {
        $actor=$this->actor($r);$branches=$access->branches($actor);abort_unless(count($branches),403);$module=$this->module($r);$selected=$r->input('branch',$branches[0]['value']);
        $initial=$this->service($r)->listing($r->all()+['branch'=>$selected],$actor);$urls=[];
        foreach(['data','save'] as $name)$urls[$name]=route($module.'.'.$name);
        if($module==='employees')foreach(['attendance','entry','void-entry','statement','entries','close','pay','export'] as $name)$urls[$name]=route($module.'.'.$name);
        $urls['recover']=route('branch-operations.recover');$urls['orders']=route('phone-orders.index');
        $boot=['module'=>$module,'branches'=>$branches,'selected_branch'=>$selected,'initial'=>$initial,'actor_id'=>(int)$actor->id,'allow_all'=>$actor->account_type==='admin'&&empty($actor->owner_resturant_id),'today'=>now('Africa/Cairo')->toDateString(),'urls'=>$urls];
        return view('admin.branch_operations.index',compact('boot'));
    }
    public function data(Request $r){return response()->json($this->service($r)->listing($r->all(),$this->actor($r)));}
    public function save(Request $r){$s=$this->service($r);return response()->json($this->module($r)==='employees'?$s->employeeSave($r->all(),$this->actor($r)):$s->save($r->all(),$this->actor($r)));}
    public function attendance(Request $r,BranchPayroll $s){return response()->json($s->attendance($r->all(),$this->actor($r)));}
    public function entry(Request $r,BranchPayroll $s){return response()->json($s->entry($r->all(),$this->actor($r)));}
    public function entries(Request $r,BranchPayroll $s){return response()->json($s->entries($r->all(),$this->actor($r)));}
    public function voidEntry(Request $r,BranchPayroll $s){return response()->json($s->voidEntry($r->all(),$this->actor($r)));}
    public function statement(Request $r,BranchPayroll $s){return response()->json($s->statement($r->all(),$this->actor($r)));}
    public function close(Request $r,BranchPayroll $s){return response()->json($s->close($r->all(),$this->actor($r)));}
    public function pay(Request $r,BranchPayroll $s){return response()->json($s->pay($r->all(),$this->actor($r)));}
    public function recover(Request $r,BranchOperations $s){return response()->json($s->recover($r->all(),auth('admin')->user()));}
    public function export(Request $r,BranchPayroll $s)
    {
        $data=$s->listing($r->all(),$this->actor($r),true);
        return response()->streamDownload(function()use($data){$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['الفرع','الاسم','الوظيفة','الشهر','الراتب','المستحق من الراتب','المكافآت','الخصومات','السلف','الصافي','الحالة']);foreach($data['items'] as $e){$s=$e['statement'];$row=[$e['branch'],$e['name'],$e['job_title'],$s['month'],$s['salary'],$s['earned_salary'],$s['bonus'],$s['deduction'],$s['advance'],$s['net'],$s['status']];fputcsv($out,array_map(fn($x)=>preg_match('/^[\s]*[=+@\-]/u',(string)$x)?"'".$x:$x,$row));}fclose($out);},'employee-payroll-'.$data['filters']['month'].'.csv',['Content-Type'=>'text/csv; charset=UTF-8','X-Content-Type-Options'=>'nosniff']);
    }
}
