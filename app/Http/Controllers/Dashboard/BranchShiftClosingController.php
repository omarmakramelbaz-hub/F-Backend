<?php
namespace App\Http\Controllers\Dashboard;
use App\Http\Controllers\Controller;
use App\Services\Dashboard\{BranchShiftClosing,TakeawayAccess};
use Illuminate\Http\Request;
class BranchShiftClosingController extends Controller
{
    public function __construct(){$this->middleware(function($r,$next){abort_unless(app(TakeawayAccess::class)->canAccess(auth('admin')->user()),403);return $next($r);});}
    public function index(Request $r,BranchShiftClosing $shifts){$actor=auth('admin')->user();$branches=$shifts->branches($actor);abort_unless($branches,403);$selected=$r->input('branch',$branches[0]['value']);$boot=['branches'=>$branches,'selected_branch'=>$selected,'initial'=>$shifts->data(['branch'=>$selected],$actor),'urls'=>[]];foreach(['data','close','recover'] as $name)$boot['urls'][$name]=route('branch-shifts.'.$name);return view('admin.branch_shifts.index',compact('boot'));}
    public function data(Request $r,BranchShiftClosing $shifts){return response()->json($shifts->data($r->all(),auth('admin')->user()));}
    public function close(Request $r,BranchShiftClosing $shifts){return response()->json($shifts->close($r->all(),auth('admin')->user()));}
    public function recover(Request $r,BranchShiftClosing $shifts){return response()->json($shifts->recover($r->all(),auth('admin')->user()));}
    public function print(int $id,BranchShiftClosing $shifts){$report=$shifts->receipt($id,auth('admin')->user());return response()->view('admin.branch_shifts.receipt',compact('report'))->header('Cache-Control','private, no-store');}
}
