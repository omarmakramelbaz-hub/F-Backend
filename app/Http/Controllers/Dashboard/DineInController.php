<?php
namespace App\Http\Controllers\Dashboard;
use App\Services\Dashboard\PosServiceTable;
use Illuminate\Http\Request;
class DineInController extends PosServiceController
{
    protected string $channel='dine';protected string $prefix='dining';protected string $view='admin.dining.index';protected string $variable='dining';
    public function tables(Request $request,PosServiceTable $tables){$v=$request->validate($this->branchRules());return response()->json($tables->listing($v['branch'],auth('admin')->user()));}
    public function tableSave(Request $request,PosServiceTable $tables){return response()->json($tables->configure($request->all(),auth('admin')->user()));}
    public function settings(Request $request,PosServiceTable $tables){return response()->json($tables->configure($request->all(),auth('admin')->user(),true));}
}
