<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class PartnerVerificationCode extends Mailable
{
    public $code;

    public function __construct(string $code)
    {
        $this->code = $code;
    }

    public function build()
    {
        return $this->from(config('mail.from.address'), 'GO Partner')
            ->subject('كود تأكيد حساب GO Partner')
            ->view('emails.partner_verification_code');
    }
}
