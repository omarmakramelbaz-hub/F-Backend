<?php

namespace App\Http\Controllers\Dashboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Auth;
use App\Models\GeneralSettings;
use App\Models\User;
use App\Models\Wallet;
use Carbon\Carbon;
use DB;
use Notification;
class WalletController extends Controller
{
    public function __construct() 
    {
        $this->middleware('permission:wallet-list', ['only' => ['index']]);
        $this->middleware('permission:wallet-create', ['only' => ['create']]);
    }

    public function index(GeneralSettings $settings)
    {
        $wallets = Wallet::whereNull('from_user')->where('type','transfer')->where('payment','wallet')->where('status','completed')->orderBy('created_at','desc')->paginate(30);
        return view('admin.wallets.index',compact('wallets','settings'));
    }
    
    public function store(Request $request, GeneralSettings $settings)
    {
        $result=app(\App\Services\Dashboard\WalletCashOperations::class)->apply('wallets.transfer',
            $request->except('_token','_desktop_command'),auth('admin')->user());
        $request->attributes->set('desktop_wallet_result',$result);
        return redirect()->back()->with('success',trans('messages.transferDone'));
        
    }
    
    public function wallet_transactions(){
        return view('admin.wallets.transactions');
    }
    
       public function withdraw(GeneralSettings $settings)
    {
        $wallets = Wallet::whereNull('to_user')->where('type','withdraw')->where('payment','wallet')->where('status','completed')->paginate(30);
        return view('admin.wallets.withdraw',compact('wallets','settings'));
    }
    
    public function store_withdraw(Request $request, GeneralSettings $settings)
    {
        
        $result=app(\App\Services\Dashboard\WalletCashOperations::class)->apply('wallets.withdraw.store',
            $request->except('_token','_desktop_command'),auth('admin')->user());
        $request->attributes->set('desktop_wallet_result',$result);
        return redirect()->back()->with('success',trans('messages.transferDone'));
        
    }

}
