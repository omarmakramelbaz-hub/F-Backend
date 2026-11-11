<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Order;
use Mail;
class SendYearlyDelegateReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:delegateyearlyreport';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Note: delegate yearly report!';

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
            $to_email = $delegate->user?->email;
            if ($to_email) {
                // Get completed orders for the current year
                $orders = Order::where('delegate_id', $delegate->id)
                    ->where('type', '!=', 'wallet')
                    ->where('status', 'completed')
                    ->whereYear('created_at', now()->year)
                    ->get();

                if ($orders->count() > 0) {
                    try {
                        Mail::send('emails.send_yearly_vendor_orders', [
                            'orders' => $orders,
                            'vendor' => $delegate->user
                        ], function($message) use ($to_email) {
                            $message->to($to_email);
                            $message->subject('Yearly Report');
                        });
                    } catch (\Exception $e) {
                        \Log::error('Email sending failed for delegate ID: ' . $delegate->id . ' - Error: ' . $e->getMessage());
                    }
                }
            }
        }

        $this->info('Yearly reports sent successfully!');
    }

}
