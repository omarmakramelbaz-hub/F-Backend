<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\Order;
use App\Models\Wallet;
use Notification;
class SendPercentageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:percentage';

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
            $orders=Order::whereIn('status',['completed','declined'])->whereNull('transfer_price_by')->whereIn('payment_type',['online','v_cash','wallet','cash'])->get();
           foreach($orders as $order){
              if($order->status=='completed'){
                   if($order->payment_type=='online'||$order->payment_type=='v_cash' || $order->payment_type=='wallet'){
                    $x=(new \App\Http\Controllers\Dashboard\OrderController)->transferPrice($order->id);
                    //  \Log::info("Cron is Order!".$x); 
                   }
                   elseif($order->payment_type=='cash' && $order->delegate_id==null){
                     $m=(new \App\Http\Controllers\Api\V1\Vendor\OrderController)->transfer_order_price($order->id);
                    //   \Log::info("Cron is Order!".$m); 
                   }elseif($order->payment_type=='cash' && $order->delegate_id!=null && $order->reason == null){
                     (new \App\Http\Controllers\Api\V1\Delegate\DelegateOrderController)->transfer_order_price($order->id);
                     }
              }elseif($order->status=='declined' && ($order->declined_by!='vendor' && $order->declined_by!='admin')){
                  $x=(new \App\Http\Controllers\Dashboard\OrderController)->transferPrice($order->id);
              }
                
           }
    }
}
