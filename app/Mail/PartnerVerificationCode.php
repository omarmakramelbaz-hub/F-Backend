<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class PartnerVerificationCode extends Mailable
{
    public const SENDER_NAME = 'GO Partner';
    public const SUBJECT = 'كود تأكيد حساب GO Partner';

    public $code;

    public function __construct(string $code)
    {
        $this->code = $code;
    }

    public function build()
    {
        return $this->from(config('mail.from.address'), self::SENDER_NAME)
            ->subject(self::SUBJECT)
            ->view('emails.partner_verification_code');
    }
}
