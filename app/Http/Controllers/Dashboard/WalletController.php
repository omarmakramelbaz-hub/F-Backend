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
        $authority=app(\App\Services\Dashboard\DesktopDashboardWorkspaceReadAuthority::class);$authority->central(['wallet-list']);
        $walletUsers=$authority->selected()?User::withoutGlobalScopes()->where('account_type','!=','admin')->get():User::where('account_type','!=','admin')->get();
        $wallets = Wallet::whereNull('from_user')->where('type','transfer')->where('payment','wallet')->where('status','completed')->orderBy('created_at','desc')->paginate(30);
        return view('admin.wallets.index',compact('wallets','settings','walletUsers'));
    }
    
    public function store(Request $request, GeneralSettings $settings)
    {
        $result=app(\App\Services\Dashboard\WalletCashOperations::class)->apply('wallets.transfer',
            $request->except('_token','_desktop_command'),auth('admin')->user());
        $request->attributes->set('desktop_wallet_result',$result);
        return redirect()->back()->with('success',trans('messages.transferDone'));
        
    }
    
    public function wallet_transactions(){
        app(\App\Services\Dashboard\DesktopDashboardWorkspaceReadAuthority::class)->central(['wallet-list']);
        return view('admin.wallets.transactions');
    }
    
       public function withdraw(GeneralSettings $settings)
    {
        $authority=app(\App\Services\Dashboard\DesktopDashboardWorkspaceReadAuthority::class);$authority->central(['wallet-list']);
        $walletUsers=$authority->selected()?User::withoutGlobalScopes()->where('account_type','!=','admin')->get():User::where('account_type','!=','admin')->get();
        $wallets = Wallet::whereNull('to_user')->where('type','withdraw')->where('payment','wallet')->where('status','completed')->paginate(30);
        return view('admin.wallets.withdraw',compact('wallets','settings','walletUsers'));
    }
    
    public function store_withdraw(Request $request, GeneralSettings $settings)
    {
        
        $result=app(\App\Services\Dashboard\WalletCashOperations::class)->apply('wallets.withdraw.store',
            $request->except('_token','_desktop_command'),auth('admin')->user());
        $request->attributes->set('desktop_wallet_result',$result);
        return redirect()->back()->with('success',trans('messages.transferDone'));
        
    }

}
