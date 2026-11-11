<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\Order;
use Mail;
use Notification;
// use App\Mail\VendorEmail;
class ScheduleOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:schedule';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Note: Order will delivered tommorow !';

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

        $orders = Order::whereIn('order_type',['schedule','another_zone'])->whereDate('schedule_date', now()->addDay()->toDateString())
        ->where('status', 'pending')
        ->get();
                // \Log::info($orders);

        foreach($orders as $order){
         if($order->resturant_id){
            $to_email = $order->resturant?->user?->email;
         }
                //  \Log::info($to_email);
            if($to_email){
            $mail=Mail::send('emails.resturant_schedule_order', ['cart' => $order], function($message) use ( $to_email) {
                 $message->to($to_email);
                 $message->subject('reminder schedule order');
            });
            }
            $resturant_owner= $order->resturant?->user;
            if($resturant_owner){
             Notification::send($resturant_owner,new \App\Notifications\NotifySheduleOrderNotification($order));
            }
            
        }
//        return 0;
    }
}
