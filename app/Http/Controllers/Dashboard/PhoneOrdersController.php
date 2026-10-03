<?php
namespace App\Http\Controllers\Dashboard;
use App\Services\Dashboard\PosServicePhone;
use Illuminate\Http\Request;
class PhoneOrdersController extends PosServiceController
{
    protected string $channel='phone';protected string $prefix='phone-orders';protected string $view='admin.phone_orders.index';protected string $variable='phonePos';
    public function customers(Request $request,PosServicePhone $phone){return response()->json($phone->customers($request->all(),auth('admin')->user()));}
}
