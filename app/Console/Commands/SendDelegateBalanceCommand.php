<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\Order;
use Notification;
use App\Models\User;
class SendDelegateBalanceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:delegate_balance';

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
            $delegates=User::where('account_type','delegate')->where('status','accepted')->whereNotNull('min_wallet')
            ->whereRaw('balance <= min_wallet/2')->get();
            // ->where('balance','<=',500)->get();
           foreach($delegates as $delegate){
                $fdate = now();
                $tdate = $delegate->expiration_date;
                $datetime1 = new \DateTime($fdate);
                $datetime2 = new \DateTime($tdate);
                $interval = $datetime1->diff($datetime2);
                $diff = $interval->format('%a');//now do whatever you like with $days
               if($delegate->expiration_date == null){
                    $up = $delegate->update(['expiration_date' => now()->addDays(3)]);
                    Notification::send($delegate,new \App\Notifications\NotifyDelegateMinBalanceNotification($delegate));
               }elseif($diff <= 3 && $diff > 0 ){
                    Notification::send($delegate,new \App\Notifications\NotifyDelegateMinBalanceNotification($delegate));
               }
               elseif($tdate < now()){
                    $up = $delegate->update(['status' => 'disabled','connected'=>'inactive','decline_reason' => 'حساب غير مفعل بسبب رصيد المحفظة اصبح أقل من الحد الأدني']);                   
                    Notification::send($delegate,new \App\Notifications\NotifyDelegateStatusNotification($delegate));
               
                    $admins = User::where('account_type','admin')->get();
                    foreach ($admins as $key => $value) {   
                        if($value->hasPermissionTo('delegate-list')){
                            Notification::send($value,new \App\Notifications\NotifyDelegateStatusNotification($delegate));
                        }
                    }
               }
               
           }
    }
}
