<?php

namespace App\Services;

use App\Mail\PartnerVerificationCode;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** GO verification only. The legacy/default mailer is never changed. */
class PartnerBrevoApi
{
    private const BASE_URL = 'https://api.brevo.com/v3';

    public function send(string $email, string $code): void
    {
        $sender = (string) config('mail.from.address');
        if (!filter_var($sender, FILTER_VALIDATE_EMAIL) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PartnerBrevoException('mail_settings_incomplete');
        }
        $response = $this->request('POST', '/smtp/email', [
            'sender' => ['email' => $sender, 'name' => PartnerVerificationCode::SENDER_NAME],
            'to' => [['email' => $email]],
            'subject' => PartnerVerificationCode::SUBJECT,
            'htmlContent' => view('emails.partner_verification_code', ['code' => $code])->render(),
        ]);
        $id = $response->json('messageId');
        if ($response->status() !== 201 || !is_string($id) || trim($id) === '') {
            throw new PartnerBrevoException('brevo_invalid_response', $response->status());
        }
    }

    /** Read-only authentication check. Never calls the send endpoint. */
    public function checkConnection(): void
    {
        $response = $this->request('GET', '/account');
        if ($response->status() !== 200 || !is_string($response->json('email'))) {
            throw new PartnerBrevoException('brevo_invalid_response', $response->status());
        }
    }

    private function request(string $method, string $path, array $data = []): Response
    {
        $key = trim((string) config('partner_auth.brevo_api_key'));
        if ($key === '') {
            throw new PartnerBrevoException('brevo_api_key_missing');
        }
        if (preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new PartnerBrevoException('brevo_api_key_invalid');
        }
        try {
            // No redirects (credentials), no retries/fallback (duplicate OTP delivery).
            $client = Http::withHeaders(['api-key' => $key])->acceptJson()->asJson()
                ->withOptions(['verify' => true, 'allow_redirects' => false, 'connect_timeout' => 10])
                ->timeout(20);
            $response = $method === 'GET'
                ? $client->get(self::BASE_URL.$path)
                : $client->post(self::BASE_URL.$path, $data);
        } catch (\Throwable $error) {
            $message = strtolower($error->getMessage());
            $category = 'brevo_connection_failed';
            if (preg_match('/certificate|curl error (?:60|77)\b|verify failed|ssl operation/', $message)) {
                $category = 'tls_validation_failed';
            } elseif (preg_match('/curl error 28\b|timed out|timeout/', $message)) {
                $category = 'brevo_connection_timeout';
            }
            throw new PartnerBrevoException($category);
        }
        if (!$response->successful()) {
            $categories = [401 => 'brevo_authentication_failed', 403 => 'brevo_access_denied',
                429 => 'brevo_sending_limit'];
            $category = $categories[$response->status()] ?? ($response->serverError()
                ? 'brevo_service_unavailable' : 'brevo_request_rejected');
            // Do not throw the HTTP response: its body may contain addresses or keys.
            throw new PartnerBrevoException($category, $response->status());
        }
        return $response;
    }
}
