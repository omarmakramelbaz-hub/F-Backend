<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Order;
use Mail;
class SendDailyDelegateReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:delegatedailyreport';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Note: delegate daily report !';

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

        $delegates = User::where('account_type','delegate')->where('status','accepted')->get();
        foreach ($delegates as $delegate) {
                // Generate daily report here
                $to_email = $delegate->email;
                if($to_email){
                    $orders =Order::where('delegate_id',$delegate->id)->where('type','!=','wallet')->where('status','completed')->whereDay('created_at', now()->day)->get();
                    if($orders->count() > 0){
                    try{
                        $mail=Mail::send('emails.send_daily_vendor_orders', ['orders' => $orders, 'vendor' => $delegate], function($message) use ( $to_email) {
                             $message->to($to_email);
                             $message->subject("today's report");
                        });
                    } catch (\Throwable $e) {
                        // One failed email never stops the other reports.
                        \Log::warning('GO_DAILY_REPORT_EMAIL_FAILED', ['error' => class_basename($e)]);
                    }
                    }
                }

        }
        
//        return 0;
    }
}
