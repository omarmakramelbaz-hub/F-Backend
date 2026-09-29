<?php

namespace Tests\Feature;

use App\Services\PartnerBrevoApi;
use App\Services\PartnerBrevoException;
use App\Services\PartnerEmailVerification;
use App\Services\PartnerMailFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PartnerBrevoApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array', 'app.key' => '12345678901234567890123456789012',
            'partner_auth.delivery' => 'brevo_api', 'partner_auth.brevo_api_key' => 'private-api-fixture',
            'mail.from.address' => 'sender@example.test',
        ]);
        Cache::flush();
        Mail::fake();
    }

    public function test_https_delivery_acceptance_produces_a_verifiable_single_use_challenge(): void
    {
        $code = null;
        Http::fake(function ($request, $options) use (&$code) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://api.brevo.com/v3/smtp/email', $request->url());
            $this->assertTrue($request->hasHeader('api-key', 'private-api-fixture'));
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(20, $options['timeout']);
            $this->assertSame(10, $options['connect_timeout']);
            $this->assertSame([['email' => 'recipient@example.test']], $request['to']);
            $this->assertSame(['email' => 'sender@example.test', 'name' => 'GO Partner'], $request['sender']);
            $this->assertSame('كود تأكيد حساب GO Partner', $request['subject']);
            $this->assertMatchesRegularExpression('/\b[1-9][0-9]{5}\b/', $request['htmlContent']);
            preg_match('/\b[1-9][0-9]{5}\b/', $request['htmlContent'], $match);
            $code = $match[0];
            return Http::response(['messageId' => '<accepted@example.test>'], 201);
        });
        $service = app(PartnerEmailVerification::class);
        $challenge = $this->issue();
        $this->assertArrayNotHasKey('code', $challenge);
        $this->assertStringNotContainsString($code, json_encode($challenge));
        $this->assertStringNotContainsString($code, json_encode(Cache::get('partner-email:challenge:'.$challenge['challenge_id'])));
        $this->assertNotEmpty($service->verify($challenge['challenge_id'], $code));
        $this->assertNull(Cache::get('partner-email:challenge:'.$challenge['challenge_id']));
        Http::assertSentCount(1);
        Mail::assertNothingSent();
    }

    /** @dataProvider rejectedResponses */
    public function test_rejection_does_not_retry_fallback_or_issue_a_challenge(int $status, array $body, string $category): void
    {
        Http::fake(['*' => Http::response($body + ['private' => 'private-api-fixture recipient@example.test 654321'], $status)]);
        try {
            $this->issue();
            $this->fail('Rejected delivery must not create a challenge');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
            $this->assertSame('تعذر إرسال كود البريد الآن. حاول لاحقًا.', $error->getMessage());
        }
        $this->assertNull(Cache::get('partner-email:active:'.hash('sha256', 'application|1012345678')));
        $failure = PartnerMailFailure::latest();
        $this->assertSame($category, $failure['category']);
        $this->assertSame($status, $failure['http_code']);
        $this->assertNull($failure['smtp_code']);
        $this->assertSafe(json_encode($failure));
        Http::assertSentCount(1);
        Mail::assertNothingSent();
    }

    public function rejectedResponses(): array
    {
        return [
            [401, [], 'brevo_authentication_failed'], [403, [], 'brevo_access_denied'],
            [429, [], 'brevo_sending_limit'], [400, [], 'brevo_request_rejected'],
            [500, [], 'brevo_service_unavailable'], [302, [], 'brevo_request_rejected'],
            [201, [], 'brevo_invalid_response'], [201, ['messageId' => ''], 'brevo_invalid_response'],
            [200, ['messageId' => '<unexpected>'], 'brevo_invalid_response'],
        ];
    }

    /** @dataProvider connectionFailures */
    public function test_network_errors_never_expose_request_secrets_or_retry(string $detail, string $category): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts, $detail) {
            $attempts++;
            throw new ConnectionException($detail.' private-api-fixture recipient@example.test 654321');
        });
        try {
            $this->issue();
            $this->fail('Network failure must reject the challenge');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
            $this->assertSafe($error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame(1, $attempts);
        $this->assertSame($category, PartnerMailFailure::latest()['category']);
        $this->assertSafe(json_encode(PartnerMailFailure::latest()));
        Mail::assertNothingSent();
    }

    public function connectionFailures(): array
    {
        return [['cURL error 60: certificate failed', 'tls_validation_failed'],
            ['cURL error 28: timed out', 'brevo_connection_timeout'],
            ['Connection refused', 'brevo_connection_failed']];
    }

    public function test_missing_key_stops_before_network_and_never_falls_back_to_smtp(): void
    {
        Http::fake();
        config(['partner_auth.brevo_api_key' => null]);
        try { $this->issue(); $this->fail('Missing key must fail'); }
        catch (HttpException $error) { $this->assertSame(503, $error->getStatusCode()); }
        $this->assertSame('brevo_api_key_missing', PartnerMailFailure::latest()['category']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_sender_must_be_valid_and_unknown_delivery_cannot_succeed(): void
    {
        Http::fake();
        config(['mail.from.address' => 'invalid']);
        try { app(PartnerBrevoApi::class)->send('recipient@example.test', '654321'); $this->fail('Invalid sender'); }
        catch (PartnerBrevoException $error) { $this->assertSame('mail_settings_incomplete', $error->getMessage()); }
        config(['partner_auth.delivery' => 'typo']);
        try { $this->issue(); $this->fail('Unknown delivery'); }
        catch (HttpException $error) { $this->assertSame(503, $error->getStatusCode()); }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_diagnostics_only_fetch_account_and_hide_all_response_details(): void
    {
        Http::fake(['*' => Http::response(['email' => 'recipient@example.test', 'private' => 'private-api-fixture'], 200)]);
        $this->assertSame(0, Artisan::call('go-partner:check-mail'));
        Http::assertNothingSent();
        $this->assertSame('not_attempted', $this->report()['api_authentication']);
        $this->assertSame(0, Artisan::call('go-partner:check-mail', ['--connect' => true]));
        $this->assertSame('ok', $this->report()['api_authentication']);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://api.brevo.com/v3/account');
        Http::assertSentCount(1);
        Mail::assertNothingSent();
    }

    public function test_diagnostics_report_missing_or_rejected_credentials_and_recent_api_failures(): void
    {
        Http::fake(['*' => Http::response(['message' => 'private-api-fixture recipient@example.test'], 401)]);
        config(['partner_auth.brevo_api_key' => '']);
        $this->assertSame(1, Artisan::call('go-partner:check-mail', ['--connect' => true]));
        $this->assertSame('brevo_api_key_missing', $this->report()['result']);
        Http::assertNothingSent();
        config(['partner_auth.brevo_api_key' => 'private-api-fixture']);
        $this->assertSame(1, Artisan::call('go-partner:check-mail', ['--connect' => true]));
        $this->assertSame('brevo_authentication_failed', $this->report()['result']);
        $this->assertSame(401, $this->report()['http_code']);
        PartnerMailFailure::record(new PartnerBrevoException('brevo_authentication_failed', 401));
        $this->assertSame(0, Artisan::call('go-partner:check-mail', ['--last-failure' => true]));
        $this->assertSame(401, $this->report()['recent_failure']['http_code']);
        Http::assertSentCount(1);
        Mail::assertNothingSent();
    }

    private function issue(): array
    {
        return app(PartnerEmailVerification::class)->issue('application', '01012345678', 'recipient@example.test', null, '127.0.0.1');
    }

    private function report(): array
    {
        $output = Artisan::output();
        $this->assertSafe($output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($report['email_sent']);
        return $report;
    }

    private function assertSafe(string $value): void
    {
        foreach (['private-api-fixture', 'recipient@example.test', '654321'] as $secret) {
            $this->assertStringNotContainsString($secret, $value);
        }
    }
}
