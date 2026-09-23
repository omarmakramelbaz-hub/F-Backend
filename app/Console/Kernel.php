<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */

    protected $commands = [
        \App\Console\Commands\CouponWheelCommand::class,
        \App\Console\Commands\ScheduleOrder::class,
        \App\Console\Commands\DelegateNotificationCommand::class,
        \App\Console\Commands\SendPercentageCommand::class,
        \App\Console\Commands\SendVendorBalanceCommand::class,
         \App\Console\Commands\SendDelegateBalanceCommand::class,
         \App\Console\Commands\SendDailyReportCommand::class,
          \App\Console\Commands\SendMinWalletBalnaceCommand::class,
          \App\Console\Commands\OpenCloseResturantCommand::class,
          \App\Console\Commands\SendMonthlyReportCommand::class,
          \App\Console\Commands\SendYearlyReportCommand::class,
          \App\Console\Commands\SendUserMoneybackCommand::class,
          \App\Console\Commands\CancelledShippingOrderCommand::class,
          \App\Console\Commands\SendDailyDelegateReportCommand::class,
          \App\Console\Commands\SendMonthlyDelegateReportCommand::class,
          \App\Console\Commands\SendYearlyDelegateReportCommand::class,
              \App\Console\Commands\ResetOrderNumbers::class,

    ];

    protected function schedule(Schedule $schedule)
    {
        // One-time approved menu release through the existing application scheduler.
        // The migration receipt prevents overwriting later dashboard price edits.
        $schedule->command('menu:apply-approved-20260923')->everyMinute()->withoutOverlapping();
        // Check every minute so the home screen can switch to the winner state
        // as soon as an ended competition becomes eligible for the draw.
        $schedule->command('notify:coupon')->everyMinute();
        $schedule->command('notify:schedule')->daily();
        $schedule->command('notify:delete')->daily();
        $schedule->command('notify:percentage')->everyMinute();
        $schedule->command('notify:vendor_balance')->daily();
        $schedule->command('notify:delegate_balance')->daily();
        $schedule->command('notify:resturantreportclosed')->everyMinute();
        $schedule->command('notify:min_wallet_balance')->daily();
        $schedule->command('notify:open_close_resturant')->everyMinute();
        $schedule->command('notify:resturantreportmonthly')->monthlyOn(1); // Runs on the 1st of every month at midnight
        $schedule->command('reports:resturantreportyearly')->yearlyOn(1); // Runs on January 1st at midnight
        $schedule->command('notify:moneyback')->everyMinute();
        $schedule->command('notify:cancelledOrder')->everyMinute();
        $schedule->command('notify:delegatedailyreport')->daily();
        $schedule->command('notify:delegatemonthlyreport')->daily();
        $schedule->command('notify:delegateyearlyreport')->daily();

        // $schedule->command('inspire')->hourly();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
