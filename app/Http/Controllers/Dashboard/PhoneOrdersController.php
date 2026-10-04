<?php
namespace App\Http\Controllers\Dashboard;
use App\Services\Dashboard\PosServicePhone;
use Illuminate\Http\Request;
class PhoneOrdersController extends PosServiceController
{
    protected string $channel='phone';protected string $prefix='phone-orders';protected string $view='admin.phone_orders.index';protected string $variable='phonePos';
    public function printJobs(Request $request,\App\Services\Dashboard\PosBranchPrinting $printing){return response()->json($printing->listing($request->all(),auth('admin')->user()));}
    public function printClaim(Request $request,\App\Services\Dashboard\PosBranchPrinting $printing){return response()->json($printing->claim($request->all(),auth('admin')->user()));}
    public function printComplete(Request $request,\App\Services\Dashboard\PosBranchPrinting $printing){return response()->json($printing->complete($request->all(),auth('admin')->user()));}
    public function deliverySettings(Request $request,\App\Services\Dashboard\PhoneDelivery $delivery){$v=$request->validate(['branch'=>'required|string|max:30']);return response()->json(['success'=>true,'settings'=>$delivery->settings($v['branch'],auth('admin')->user())]);}
    public function deliveryQuote(Request $request,\App\Services\Dashboard\PhoneDelivery $delivery){return response()->json($delivery->quote($request->all(),auth('admin')->user()));}
    public function addressSuggestions(Request $request,\App\Services\Dashboard\PhoneMapProvider $maps){return response()->json($maps->suggestions($request->all(),auth('admin')->user()))->header('Cache-Control','private, no-store');}
    public function customers(Request $request,PosServicePhone $phone){return response()->json($phone->customers($request->all(),auth('admin')->user()));}
}
