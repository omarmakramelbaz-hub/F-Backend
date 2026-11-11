<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\Order;
use Notification;
use App\Models\User;
class SendVendorBalanceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:vendor_balance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Note: DelegateNotification will deleted !';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
            $vendors=User::where('account_type','vendor')->whereNotNull('min_wallet')->whereRaw('balance <= min_wallet / 2')->whereHas('base_resturant',function($q){
                $q->whereNotNull(['lat','lng'])->has('resturant_products');
                
            })->get();
           foreach($vendors as $vendor){
                $fdate = now();
                $tdate = $vendor->expiration_date;
                $datetime1 = new \DateTime($fdate);
                $datetime2 = new \DateTime($tdate);
                $interval = $datetime1->diff($datetime2);
                $diff = $interval->format('%a');//now do whatever you like with $days
               if($vendor->expiration_date == null){
                    $up = $vendor->update(['expiration_date' => now()->addDays(3)]);
                    Notification::send($vendor,new \App\Notifications\NotifyVendorMinBalanceNotification($vendor));
               }elseif($diff <= 3 && $diff > 0 ){
                    Notification::send($vendor,new \App\Notifications\NotifyVendorMinBalanceNotification($vendor));
               }
               elseif($tdate < now()){
                    $up = $vendor->base_resturant?->update(['status' => 'disabled']);  
                    $vendor->update(['status'=>'disabled','decline_reason' => 'حساب غير مفعل بسبب رصيد المحفظة اصبح أقل من الحد الأدني']);
                    Notification::send($vendor,new \App\Notifications\NotifyResturantStatusNotification($vendor));
               
                   $admins = User::where('account_type','admin')->get();
                    foreach ($admins as $key => $value) {   
                        if($value->hasPermissionTo('vendor-list')){
                            Notification::send($value,new \App\Notifications\NotifyResturantStatusNotification($vendor));
                        }
                    }
               }
               
           }
    }
}
