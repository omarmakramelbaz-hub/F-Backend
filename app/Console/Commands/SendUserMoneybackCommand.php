<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\Order;
use App\Models\Wallet;
use Notification;
class SendUserMoneybackCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:moneyback';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Note: moneyback for user !';

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
             $twoMinutesAgo = now()->subHours(2);

            $orders=Order::where('status','cancelled')->whereIn('type',['current','shipping'])->whereNull('transfer_price_by')->whereIn('payment_type',['online','v_cash','wallet'])->where('updated_at','<=', $twoMinutesAgo)->get();
           foreach($orders as $order){

                (new \App\Http\Controllers\Dashboard\OrderController)->transferCancelledOrderPrice($order->id);
           }
    }
}
