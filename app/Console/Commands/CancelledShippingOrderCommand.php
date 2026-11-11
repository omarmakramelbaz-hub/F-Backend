<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\Order;
use App\Models\Wallet;
use Notification;
use App\Events\CancelledShippingUpdated;

class CancelledShippingOrderCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:cancelledOrder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Note: cancelledOrder for user !';

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
        $fiveMinutesAgo = now()->subMinutes(5);
        $orders=    Order::where(function ($query) {
                    $query->where('status', 'pending')
                          ->orWhereNull('status');
                })
                ->where('type', 'shipping')
                ->whereNull('transfer_price_by')
                ->whereIn('payment_type', ['online', 'v_cash', 'wallet', 'cash'])
                ->where('updated_at', '<=', $fiveMinutesAgo)
                ->get();             

           foreach($orders as $order){
                $order->update([
                    'status' => 'cancelled',
                    ]);
                    
                broadcast(new CancelledShippingUpdated($order,$order->user_id));
           }
    }
}
