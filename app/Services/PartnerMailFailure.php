<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/** Short-lived operator diagnostics; no addresses, codes, credentials or raw replies. */
class PartnerMailFailure
{
    private const KEY = 'partner-email:last-delivery-failure';

    public static function record(\Throwable $error): void
    {
        // A diagnostic write must never replace the original safe API response.
        try {
            Cache::put(self::KEY, self::describe($error) + ['occurred_at' => now()->toIso8601String()], 900);
        } catch (\Throwable $ignored) {}
    }

    public static function latest(): ?array
    {
        return Cache::get(self::KEY);
    }

    public static function describe(\Throwable $error): array
    {
        $message = strtolower($error->getMessage());
        $smtpCode = null;
        if (preg_match('/got (?:code )?["\x27]?([45][0-9]{2})\b/', $message, $match)
            || preg_match('/\A\s*([45][0-9]{2})(?:\s|[.-])/', $message, $match)) {
            $smtpCode = (int) $match[1];
        }
        $category = $error instanceof \Swift_TransportException ? 'smtp_delivery_failed' : 'application_mail_failed';
        if (preg_match('/not (?:yet )?activated|sending platform.*disabled|account.*suspend/', $message)) {
            $category = 'smtp_account_inactive';
        } elseif (preg_match('/(?:sender|from address).*(?:invalid|not valid|not verified|not authorized|unauthor|not allowed|rejected)/', $message)) {
            $category = 'smtp_sender_rejected';
        } elseif (preg_match('/quota|credit|daily.*limit|sending.*limit|rate.?limit/', $message)) {
            $category = 'smtp_sending_limit';
        } elseif (preg_match('/unauthorized ip|ip address.*not.*allowed/', $message)) {
            $category = 'smtp_ip_rejected';
        } elseif (preg_match('/certificate|verify failed|peer name|crypto|ssl operation/', $message)) {
            $category = 'tls_validation_failed';
        } elseif (preg_match('/authenticat|\b53[045]\b/', $message)) {
            $category = 'smtp_authentication_failed';
        } elseif (preg_match('/getaddrinfo|php_network_getaddresses/', $message)) {
            $category = 'smtp_dns_failed';
        } elseif (preg_match('/timed out|timeout/', $message)) {
            $category = 'smtp_connection_timeout';
        } elseif (preg_match('/(?:recipient|mailbox).*(?:reject|unavailable|invalid|blocked)/', $message)) {
            $category = 'smtp_recipient_rejected';
        }
        return ['category' => $category, 'smtp_code' => $smtpCode];
    }
}
