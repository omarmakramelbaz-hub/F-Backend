<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartnerMailDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'partner_auth.mailer' => 'partner_smtp',
            'mail.from.address' => 'sender@example.test',
            'mail.mailers.partner_smtp' => [
                'transport' => 'smtp', 'host' => 'smtp.example.test', 'port' => 465,
                'encryption' => 'ssl', 'username' => 'private-user@example.test',
                'password' => 'private-fixture-password',
                'stream' => ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]],
            ],
        ]);
    }

    public function test_default_check_renders_template_without_connecting_or_exposing_credentials(): void
    {
        Mail::shouldReceive('mailer')->never();
        $this->assertSame(0, Artisan::call('go-partner:check-mail'));
        $report = $this->report();
        $this->assertSame('ok', $report['template']);
        $this->assertSame('not_attempted', $report['smtp_login']);
    }

    public function test_missing_credentials_and_insecure_tls_are_rejected_before_connecting(): void
    {
        Mail::shouldReceive('mailer')->never();
        config(['mail.mailers.partner_smtp.password' => null]);
        $this->assertSame(1, Artisan::call('go-partner:check-mail', ['--connect' => true]));
        $this->assertSame('smtp_credentials_missing', $this->report()['result']);
        config(['mail.mailers.partner_smtp.password' => 'private-fixture-password',
            'mail.mailers.partner_smtp.stream.ssl.verify_peer' => false]);
        $this->assertSame(1, Artisan::call('go-partner:check-mail', ['--connect' => true]));
        $this->assertSame('secure_tls_required', $this->report()['result']);
    }

    public function test_connect_only_authenticates_and_closes_without_sending_mail(): void
    {
        $transport = $this->transport();
        $transport->shouldReceive('start')->once();
        $this->assertSame(0, Artisan::call('go-partner:check-mail', ['--connect' => true]));
        $this->assertSame('ok', $this->report()['smtp_login']);
    }

    /** @dataProvider connectionFailures */
    public function test_smtp_failures_return_safe_categories_and_close_the_connection(string $detail, string $category): void
    {
        $transport = $this->transport();
        $transport->shouldReceive('start')->once()->andThrow(new \Swift_TransportException(
            $detail.' private-user@example.test private-fixture-password'
        ));
        $this->assertSame(1, Artisan::call('go-partner:check-mail', ['--connect' => true]));
        $this->assertSame($category, $this->report()['result']);
    }

    public function connectionFailures(): array
    {
        return [
            ['535 Authentication failed', 'smtp_authentication_failed'],
            ['SSL operation failed: certificate verify failed', 'tls_validation_failed'],
            ['php_network_getaddresses: getaddrinfo failed', 'smtp_dns_failed'],
            ['Connection timed out', 'smtp_connection_timeout'],
            ['Connection refused', 'smtp_connection_refused'],
            ['Unexpected connection error', 'smtp_connection_failed'],
        ];
    }

    private function transport()
    {
        $transport = \Mockery::mock(\Swift_SmtpTransport::class);
        $transport->shouldReceive('setTimeout')->once()->with(20)->andReturnSelf();
        $transport->shouldReceive('stop')->atLeast()->once();
        $transport->shouldReceive('send')->never();
        $mailer = \Mockery::mock(\Illuminate\Mail\Mailer::class);
        $mailer->shouldReceive('getSwiftMailer')->once()->andReturn(new \Swift_Mailer($transport));
        Mail::shouldReceive('mailer')->once()->with('partner_smtp')->andReturn($mailer);
        return $transport;
    }

    private function report(): array
    {
        $output = Artisan::output();
        $this->assertStringNotContainsString('private-user@example.test', $output);
        $this->assertStringNotContainsString('private-fixture-password', $output);
        $this->assertStringNotContainsString('000000', $output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($report['email_sent']);
        return $report;
    }
}
