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
    public function customers(Request $request,PosServicePhone $phone){return response()->json($phone->customers($request->all(),auth('admin')->user()));}
}
