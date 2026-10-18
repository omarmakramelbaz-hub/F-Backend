<?php

namespace App\Services\Dashboard;

use RuntimeException;

/** A fixed reason only. Never attach a customer/event value or previous exception. */
class WhatsAppInboxQuarantinedEvent extends RuntimeException
{
    private $reasonCode;

    public function __construct(string $reasonCode = 'INVALID_EVENT')
    {
        $this->reasonCode = in_array($reasonCode, ['INVALID_EVENT', 'CONFLICTING_MESSAGE'], true)
            ? $reasonCode : 'INVALID_EVENT';
        parent::__construct($this->reasonCode);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
