<?php
namespace App\Http\Controllers\Dashboard;
use App\Http\Controllers\Controller;
use App\Services\Dashboard\{BranchStock,BranchInventory,TakeawayAccess};
use Illuminate\Http\Request;

class BranchStockController extends Controller
{
    public function __construct(){ $this->middleware(function($r,$next){abort_unless(app(TakeawayAccess::class)->canAccess(auth('admin')->user()),403);return $next($r);}); }
    public function index(Request $r,BranchStock $s)
    {
        $actor=auth('admin')->user();$branches=$s->branches($actor);abort_unless(count($branches),403);$branch=$r->input('branch',$branches[0]['value']);
        $boot=['actor_id'=>(int)$actor->id,'branches'=>$branches,'branch'=>$branch,'initial'=>$s->listing(['branch'=>$branch],$actor),'urls'=>['data'=>route('branch-stock.data'),'receive'=>route('branch-stock.receive'),'recover'=>route('branch-operations.recover'),'recipes'=>route('branch-stock.recipes'),'recipe_save'=>route('branch-stock.recipe-save')]];
        return view('admin.branch_stock.index',compact('boot'));
    }
    public function data(Request $r,BranchStock $s){return response()->json($s->listing($r->all(),auth('admin')->user()))->header('Cache-Control','private, no-store');}
    public function recipes(Request $r,BranchInventory $s){return response()->json($s->recipes($r->all(),auth('admin')->user()))->header('Cache-Control','private, no-store');}
    public function saveRecipe(Request $r,BranchInventory $s){return response()->json($s->saveRecipe($r->all(),auth('admin')->user()));}
    public function receive(Request $r,BranchStock $s){return response()->json($s->receive($r->all(),auth('admin')->user()));}
}
