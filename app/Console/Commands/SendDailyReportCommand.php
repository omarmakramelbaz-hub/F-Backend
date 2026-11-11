<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\Resturant;
use App\Models\Order;
use Mail;
class SendDailyReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:resturantreportclosed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Note: Resturant closed now !';

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

        $resturants = Resturant::where('status','!=','disabled')->where('close_at', now()->format('H:i'))->get();
                // \Log::info($resturants);
        foreach ($resturants as $resturant) {
            // if (now()->format('H:i') === $resturant->close_at) {
                // Generate daily report here
                $to_email = $resturant->user?->email;
                if($to_email){
                    $orders =Order::where('resturant_id',$resturant->id)->where('type','!=','wallet')->where('status','completed')->whereDay('created_at', now()->day)->get();
                    if($orders->count() > 0){
                    try{
                        $mail=Mail::send('emails.send_daily_vendor_orders', ['orders' => $orders, 'vendor' => $resturant->user], function($message) use ( $to_email) {
                             $message->to($to_email);
                             $message->subject("today's report");
                        });
                    } catch (\Throwable $e) {
                        // One failed email never stops the other reports.
                        \Log::warning('GO_DAILY_REPORT_EMAIL_FAILED', ['error' => class_basename($e)]);
                    }
                    }
                }
            // }
        }
        
//        return 0;
    }
}
