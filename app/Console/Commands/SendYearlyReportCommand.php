<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\Resturant;
use App\Models\Order;
use Mail;
class SendYearlyReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:resturantreportyearly';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Note: Resturant yearly report!';

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

        $resturants = Resturant::where('status', '!=', 'disabled')->get();

        foreach ($resturants as $resturant) {
            $to_email = $resturant->user?->email;
            if ($to_email) {
                // Get completed orders for the current year
                $orders = Order::where('resturant_id', $resturant->id)
                    ->where('type', '!=', 'wallet')
                    ->where('status', 'completed')
                    ->whereYear('created_at', now()->year)
                    ->get();

                if ($orders->count() > 0) {
                    try {
                        Mail::send('emails.send_yearly_vendor_orders', [
                            'orders' => $orders,
                            'vendor' => $resturant->user
                        ], function($message) use ($to_email) {
                            $message->to($to_email);
                            $message->subject('Yearly Report');
                        });
                    } catch (\Exception $e) {
                        \Log::error('Email sending failed for resturant ID: ' . $resturant->id . ' - Error: ' . $e->getMessage());
                    }
                }
            }
        }

        $this->info('Yearly reports sent successfully!');
    }

}
