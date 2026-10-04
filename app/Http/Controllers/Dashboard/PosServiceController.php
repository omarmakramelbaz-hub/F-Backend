<?php
namespace App\Http\Controllers\Dashboard;
use App\Http\Controllers\Controller;
use App\Services\Dashboard\TakeawayAccess;
use App\Services\Dashboard\TakeawayCatalog;
use App\Services\Dashboard\PosServiceTicket;
use Illuminate\Http\Request;
abstract class PosServiceController extends Controller
{
    protected string $channel;protected string $prefix;protected string $view;protected string $variable;
    public function __construct(){ $this->middleware(function($request,$next){abort_unless(app(TakeawayAccess::class)->canAccess(auth('admin')->user()),403);return $next($request);}); }
    public function index(Request $request,TakeawayAccess $access,PosServiceTicket $service)
    {
        $v=$request->validate(['branch'=>['nullable','string','regex:/^(f|gs):[1-9][0-9]{0,18}$/D']]);$actor=auth('admin')->user();$branches=$access->branches($actor);abort_unless(count($branches),403);
        $selected=$v['branch']??$branches[0]['value'];$access->branch($selected,$actor);$urls=[];
        foreach(['catalog','quote','tickets','save','recover'] as $name)$urls[$name]=route($this->prefix.'.'.$name);
        foreach(['show','action','settle','print'] as $name)$urls[$name]=str_replace('991771', '__TICKET__',route($this->prefix.'.'.$name,['id'=>991771]));
        $urls['kitchen']=str_replace('991772','__KITCHEN__',route($this->prefix.'.kitchen',['id'=>991772]));
        if($this->channel==='dine')foreach(['tables','table-save','settings'] as $name)$urls[$name]=route($this->prefix.'.'.$name);
        else { $urls['customers']=route($this->prefix.'.customers'); foreach(['print-jobs','print-claim','print-complete'] as $name)$urls[$name]=route($this->prefix.'.'.$name); }
        $urls['table_save']=$urls['table-save']??null;$urls['till']=$urls['register']=route('takeaway.till');$urls['receipts']=$urls['daily']=route('takeaway.receipts');
        $permissions=$access->permissions($actor);$permissions['can_operate']=$permissions['can_checkout'];
        $boot=$service->summary($this->channel,$selected,$actor)+['branches'=>$branches,'selected_branch'=>$selected,'cashier'=>['id'=>(int)$actor->id,'name'=>$actor->name],'urls'=>$urls];$boot['permissions']=$permissions; $boot['receiver_branch']=$access->receiverBranch($actor); $boot['can_choose_branch']=count($branches)>1;
        return view($this->view,[$this->variable=>$boot]);
    }
    public function catalog(Request $request,TakeawayCatalog $catalog,PosServiceTicket $service){$v=$request->validate($this->branchRules()+['search'=>'nullable|string|max:100','category_id'=>'nullable|integer|min:1','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:100']);return response()->json(array_merge($catalog->listing($v,auth('admin')->user()),$service->summary($this->channel,$v['branch'],auth('admin')->user())));}
    public function quote(Request $request,PosServiceTicket $service){$v=$request->validate($this->branchRules()+['ticket_id'=>'nullable|integer|min:1']);return response()->json($service->quote($this->channel,$request->all(),auth('admin')->user()));}
    public function tickets(Request $request,PosServiceTicket $service){$v=$request->validate($this->branchRules()+['status'=>'nullable|string|max:30','search'=>'nullable|string|max:100','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:40']);return response()->json($service->listing($this->channel,$v,auth('admin')->user()));}
    public function show(int $id,PosServiceTicket $service){return response()->json($service->show($this->channel,$id,auth('admin')->user()));}
    public function save(Request $request,PosServiceTicket $service){return response()->json($service->save($this->channel,$request->all(),auth('admin')->user()));}
    public function action(Request $request,int $id,PosServiceTicket $service){return response()->json($service->action($this->channel,$id,$request->all(),auth('admin')->user()));}
    public function settle(Request $request,int $id,PosServiceTicket $service){return response()->json($service->settle($this->channel,$id,$request->all(),auth('admin')->user()));}
    public function recover(Request $request,PosServiceTicket $service){return response()->json($service->recover($this->channel,$request->all(),auth('admin')->user()));}
    public function print(int $id,PosServiceTicket $service){$ticket=$service->show($this->channel,$id,auth('admin')->user())['ticket'];if($ticket['payment_status']==='paid'){return view('admin.takeaway.receipt',['receipt'=>app(\App\Services\Dashboard\TakeawayService::class)->receipt((int)\Illuminate\Support\Facades\DB::table('pos_service_tickets')->where('id',$id)->value('paid_order_id'),auth('admin')->user())]);}abort_unless($ticket['bill_locked']&&$ticket['status']!=='cancelled',409,'أصدر فاتورة الدفع أولًا.');return view('admin.pos_service.ticket',compact('ticket'));}
    public function details(int $id,PosServiceTicket $service){$ticket=$service->show($this->channel,$id,auth('admin')->user())['ticket'];if($ticket['payment_status']==='paid')return view('admin.takeaway.receipt',['preview'=>true,'receipt'=>app(\App\Services\Dashboard\TakeawayService::class)->receipt((int)\Illuminate\Support\Facades\DB::table('pos_service_tickets')->where('id',$id)->value('paid_order_id'),auth('admin')->user())]);return view('admin.pos_service.ticket',['ticket'=>$ticket,'preview'=>true]);}
    public function kitchen(int $id,PosServiceTicket $service){return view('admin.pos_service.kitchen',['kitchen'=>$service->kitchen($this->channel,$id,auth('admin')->user())]);}
    protected function branchRules(): array {return ['branch'=>['required','string','regex:/^(f|gs):[1-9][0-9]{0,18}$/D']];}
}
