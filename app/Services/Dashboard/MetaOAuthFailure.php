<?php

namespace App\Services\Dashboard;

/** Fixed public reasons only: never carry Graph bodies, URLs, tokens or a previous exception. */
final class MetaOAuthFailure extends \RuntimeException
{
    private const REASONS = [
        'OAUTH_NOT_CONFIGURED', 'INVALID_AUTHORIZATION', 'META_UNAVAILABLE', 'META_REJECTED',
        'NO_ALLOWED_ACCOUNTS', 'ACCOUNT_CHANGED', 'INVALID_SELECTION', 'SUBSCRIPTION_FAILED',
        'CREDENTIALS_SAVE_FAILED',
    ];

    public function __construct(string $reason)
    {
        parent::__construct(in_array($reason, self::REASONS, true) ? $reason : 'META_UNAVAILABLE');
    }

    public function reason(): string { return $this->getMessage(); }
}
