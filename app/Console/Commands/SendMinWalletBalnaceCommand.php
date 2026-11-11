<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\Order;
use Notification;
use App\Models\User;
class SendMinWalletBalnaceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:min_wallet_balance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Note: MinWalletNotification will deleted !';

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
         \Log::info("Cron is SendMinWalletBalanceCommand!");
            $users=User::where('status','accepted')->whereNotNull('balance')
            ->where('balance','<',0)->get();
            \Log::info("count".$users->count());
           foreach($users as $user){
                //  \Log::info("Cron is SendMinWalletBalanceCommand!".$user->id);
                $fdate = now();
                $tdate = $user->expiration_date;
                $datetime1 = new \DateTime($fdate);
                $datetime2 = new \DateTime($tdate);
                $interval = $datetime1->diff($datetime2);
                $diff  = ($interval->days * 24) + $interval->h + ($interval->i / 60);  // get diff with hours
\Log::info("count".$users->count().'$diff'.$diff.'$tdate'.$tdate.'now()'.now());
               if($user->expiration_date == null){
                    $up = $user->update(['expiration_date' => now()->addHours(2)]);
                    Notification::send($user,new \App\Notifications\NotifyMinWalletBalanceNotification($user));
               }elseif($diff <= 2 && $diff > 0 ){
                            \Log::info($diff);
                    Notification::send($user,new \App\Notifications\NotifyMinWalletBalanceNotification($user));
               }
               elseif($tdate < now()){
                    $up = $user->update(['status' => 'disabled','connected'=>'inactive']);                   
                    Notification::send($user,new \App\Notifications\NotifyDelegateStatusNotification($user));
               
                   $admins = User::where('account_type','admin')->get();
                    foreach ($admins as $key => $value) {   
                        if($value->hasPermissionTo('delegate-list')){
                            Notification::send($value,new \App\Notifications\NotifyDelegateStatusNotification($user));
                        }
                    }
                   
               }
               
           }
    }
}
