<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\Order;
use Notification;
use App\Models\Resturant;
use Mail;
class OpenCloseResturantCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:open_close_resturant';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Note: open_close_resturant !';

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

        $currentTime = now()->format('H:i'); 

        $open_resturants = Resturant::where('open_at', $currentTime)->get();
        foreach($open_resturants as $value){
            // The branch opens at its time whatever happens to the reminder emails below.
            $value->update(['status' => 'opened']);
            $orders = Order::where('resturant_id', $value->id)->whereIn('order_type',['schedule','another_zone'])->whereDate('schedule_date', now()->toDateString())
                ->where('status', 'pending')
                ->get();
            foreach($orders as $order){
                $to_email = $order->resturant?->user?->email;
                if(!$to_email) continue;
                try {
                    Mail::send('emails.reminder_today_orders_email', ['cart' => $order], function($message) use ($to_email) {
                        $message->to($to_email);
                        $message->subject('reminder schedule order today');
                    });
                } catch (\Throwable $error) {
                    \Log::warning('GO_TODAY_ORDERS_REMINDER_EMAIL_FAILED', ['order' => $order->id, 'error' => class_basename($error)]);
                }
            }
        }
        // $open_resturants->update(['status' => 'opened']);
            
        $closed_resturants = Resturant::where('close_at',$currentTime)->update(['status' => 'closed']);

            
    }
}
