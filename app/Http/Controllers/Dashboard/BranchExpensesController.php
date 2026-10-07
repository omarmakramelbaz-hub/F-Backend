<?php
namespace App\Http\Controllers\Dashboard;
use App\Http\Controllers\Controller;
use App\Services\Dashboard\BranchExpenses;
use App\Services\Dashboard\TakeawayAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BranchExpensesController extends Controller
{
    public function __construct(){ $this->middleware(function($request,$next){abort_unless(app(TakeawayAccess::class)->canAccess(auth('admin')->user()),403);return $next($request);}); }
    public function index(Request $request,BranchExpenses $expenses,TakeawayAccess $access)
    {
        $actor=auth('admin')->user();$branches=$access->branches($actor);$selected=$request->input('branch',$branches[0]['value']);
        $data=$expenses->listing($request->all()+['branch'=>$selected],$actor);
        $boot=['actor_id'=>(int)$actor->id,'selected_branch'=>$selected,'branches'=>$branches,'allow_all'=>$actor->account_type==='admin'&&empty($actor->owner_resturant_id),'permissions'=>$expenses->permissions($actor),'initial'=>$data,'today'=>now('Africa/Cairo')->toDateString(),'urls'=>[]];
        foreach(['data','save','recover','export','report','categorySave'] as $name)$boot['urls'][$name]=route('branch-expenses.'.$name);
        foreach(['show','review'] as $name)$boot['urls'][$name]=str_replace('991770','__EXPENSE__',route('branch-expenses.'.$name,['id'=>991770]));
        return view('admin.expenses.index',compact('boot'));
    }
    public function categorySave(Request $request,\App\Services\Dashboard\ExpenseCategories $categories){return response()->json($categories->save($request->all(),auth('admin')->user()));}
    public function data(Request $request,BranchExpenses $expenses){return response()->json($expenses->listing($request->all(),auth('admin')->user()));}
    public function save(Request $request,BranchExpenses $expenses){return response()->json($expenses->save($request->except('attachment'),auth('admin')->user(),$request->file('attachment')));}
    public function show(int $id,BranchExpenses $expenses){return response()->json($expenses->show($id,auth('admin')->user()));}
    public function review(int $id,Request $request,BranchExpenses $expenses){return response()->json($expenses->review($id,$request->all(),auth('admin')->user()));}
    public function recover(Request $request,BranchExpenses $expenses,TakeawayAccess $access)
    {
        $v=$request->validate(['branch'=>'required|string|max:40','idempotency_key'=>'required|uuid']);$actor=auth('admin')->user();$expenses->ready();$access->branch($v['branch'],$actor);
        $command=\Illuminate\Support\Facades\DB::table('branch_expense_commands')->where('branch',$v['branch'])->where('actor_id',$actor->id)->where('request_key',$v['idempotency_key'])->first();
        return response()->json($command?$expenses->show($command->expense_id,$actor)+['found'=>true]:['success'=>true,'found'=>false]);
    }
    public function attachment(int $id,BranchExpenses $expenses){$file=$expenses->attachment($id,auth('admin')->user());return Storage::disk('local')->download($file['path'],$file['name'],['Content-Type'=>$file['mime'],'X-Content-Type-Options'=>'nosniff']);}
    public function print(int $id,BranchExpenses $expenses){$expenses->show($id,auth('admin')->user());abort(410,'طباعة المصروفات غير متاحة.');}
    public function report(Request $request,BranchExpenses $expenses){$data=$expenses->listing($request->all(),auth('admin')->user(),true);return view('admin.expenses.report',compact('data'));}
    public function export(Request $request,BranchExpenses $expenses)
    {
        $data=$expenses->listing($request->all(),auth('admin')->user(),true);
        return response()->streamDownload(function()use($data){
            $stream=fopen('php://output','w');fwrite($stream,"\xEF\xBB\xBF");
            fputcsv($stream,array_map(fn($k)=>__('expenses.'.$k),['number','branch','date','category','description','amount','payment_method','actor','status','supplier','cost_center','notes']));
            foreach($data['items'] as $item){$row=[$item['number'],$item['branch_name'],$item['occurred_on'],$item['category_name'],$item['description'],$item['amount'],__('expenses.method_'.$item['payment_method']),$item['actor_name'],__('expenses.status_'.$item['status']),$item['supplier'],$item['cost_center'],$item['notes']];
                fputcsv($stream,array_map(fn($value)=>preg_match('/^[\s]*[=+@\-]/u',(string)$value)?"'".$value:$value,$row));}
            fclose($stream);
        },'branch-expenses-'.$data['filters']['from'].'-'.$data['filters']['to'].'.csv',['Content-Type'=>'text/csv; charset=UTF-8','X-Content-Type-Options'=>'nosniff']);
    }
}
