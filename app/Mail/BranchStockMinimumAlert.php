<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class BranchStockMinimumAlert extends Mailable
{
    public array $alert;

    public function __construct(array $alert) { $this->alert = $alert; }

    public function build()
    {
        return $this->subject('تقرير رصيد البضاعة — تنبيه الحد الأدنى')
            ->view('emails.branch_stock_minimum');
    }
}
