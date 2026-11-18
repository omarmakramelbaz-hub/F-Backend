<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Order;
use Mail;
class SendMonthlyDelegateReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:delegatemonthlyreport';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Note: delegate monthly report!';

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
            $to_email = $delegate->email;
            if ($to_email) {
                // Get completed orders for the current month
                $orders = Order::where('delegate_id', $delegate->id)
                    ->where('type', '!=', 'wallet')
                    ->where('status', 'completed')
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->get();
        
                if ($orders->count() > 0) {
                    try {
                        Mail::send('emails.send_monthly_vendor_orders', [
                            'orders' => $orders,
                            'vendor' => $delegate->user
                        ], function($message) use ($to_email) {
                            $message->to($to_email);
                            $message->subject('Monthly Report');
                        });
                    } catch (\Exception $e) {
                        \Log::error('Email sending failed for user ID: ' . $delegate->id . ' - Error: ' . $e->getMessage());
                    }
                }
            }
        }
    }

}
