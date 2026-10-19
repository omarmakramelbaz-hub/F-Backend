<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class BranchStockMinimumAlert extends Mailable
{
    public array $alert;

    public function __construct(array $alert) { $this->alert = $alert; }

    public function build()
    {
        return $this->subject('تنبيه الحد الأدنى للبضاعة - '.$this->alert['branch_name'])
            ->view('emails.branch_stock_minimum');
    }
}
