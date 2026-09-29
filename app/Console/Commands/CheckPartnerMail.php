<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** Operator-only diagnostics: no recipient, message delivery, or configuration writes. */
class CheckPartnerMail extends Command
{
    protected $signature = 'go-partner:check-mail {--connect : Test encrypted SMTP login without sending a message}';
    protected $description = 'Check GO email verification settings and optionally SMTP login, without exposing secrets';

    public function handle(): int
    {
        $mailer = (string) config('partner_auth.mailer');
        $settings = (array) config('mail.mailers.'.$mailer, []);
        $host = (string) ($settings['host'] ?? '');
        $encryption = $settings['encryption'] ?? null;
        $ssl = $settings['stream']['ssl'] ?? [];
        $report = [
            'smtp_host' => preg_match('/\A[a-zA-Z0-9.-]+\z/', $host) ? $host : '[invalid host]',
            'smtp_port' => (int) ($settings['port'] ?? 0),
            'encryption' => in_array($encryption, ['tls', 'ssl'], true) ? $encryption : '[not configured]',
            'username_configured' => !empty($settings['username']),
            'password_configured' => !empty($settings['password']),
            'sender_valid' => (bool) filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL),
            'tls_verification' => ($ssl['verify_peer'] ?? true) === true
                && ($ssl['verify_peer_name'] ?? true) === true
                && ($ssl['allow_self_signed'] ?? false) === false,
            'template' => 'not_checked',
            'smtp_login' => 'not_attempted',
            'email_sent' => false,
        ];
        try {
            view('emails.partner_verification_code', ['code' => '000000'])->render();
            $report['template'] = 'ok';
        } catch (\Throwable $e) {
            return $this->finish($report, 'template_render_failed');
        }
        if (($settings['transport'] ?? null) !== 'smtp') {
            return $this->finish($report, 'smtp_transport_not_selected');
        }
        if ($report['smtp_host'] === '[invalid host]' || !$report['smtp_port'] || !$report['sender_valid']) {
            return $this->finish($report, 'mail_settings_incomplete');
        }
        if (!$report['username_configured'] || !$report['password_configured']) {
            return $this->finish($report, 'smtp_credentials_missing');
        }
        if (!$report['tls_verification'] || !in_array($encryption, ['ssl', 'tls'], true)) {
            return $this->finish($report, 'secure_tls_required');
        }
        if (!$this->option('connect')) {
            return $this->finish($report);
        }
        $transport = null;
        try {
            $transport = Mail::mailer($mailer)->getSwiftMailer()->getTransport();
            if (!$transport instanceof \Swift_Transport_EsmtpTransport) {
                return $this->finish($report, 'smtp_transport_not_supported');
            }
            $transport->setTimeout(20);
            // EHLO, TLS and authentication only; never MAIL FROM, RCPT TO or DATA.
            $transport->start();
            $report['smtp_login'] = 'ok';
        } catch (\Throwable $e) {
            $report['smtp_login'] = 'failed';
            return $this->finish($report, $this->failureCategory($e));
        } finally {
            if ($transport instanceof \Swift_Transport_EsmtpTransport) {
                try { $transport->stop(); } catch (\Throwable $ignored) {}
            }
        }
        return $this->finish($report);
    }

    private function failureCategory(\Throwable $error): string
    {
        // Inspect locally, but never output provider replies, credentials or traces.
        $message = strtolower($error->getMessage());
        if (preg_match('/certificate|verify failed|peer name|crypto|ssl operation/', $message)) return 'tls_validation_failed';
        if (preg_match('/authenticat|\b53[045]\b|\b235\b/', $message)) return 'smtp_authentication_failed';
        if (preg_match('/getaddrinfo|name or service not known|php_network_getaddresses/', $message)) return 'smtp_dns_failed';
        if (preg_match('/timed out|timeout/', $message)) return 'smtp_connection_timeout';
        if (strpos($message, 'refused') !== false) return 'smtp_connection_refused';
        return 'smtp_connection_failed';
    }

    private function finish(array $report, ?string $failure = null): int
    {
        $report['result'] = $failure ?? 'ok';
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $failure ? 1 : 0;
    }
}
