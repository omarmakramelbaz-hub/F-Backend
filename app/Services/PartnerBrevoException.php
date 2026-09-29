<?php

namespace App\Services;

/** Only fixed internal categories and HTTP status; never attach the original request. */
class PartnerBrevoException extends \RuntimeException
{
    private ?int $httpStatus;

    public function __construct(string $category, ?int $httpStatus = null)
    {
        parent::__construct($category);
        $this->httpStatus = $httpStatus;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
