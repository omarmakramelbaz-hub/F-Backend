<?php
declare(strict_types=1);

require __DIR__.'/report.php';

use Fasakhansta\DailyDigest\Report;

// Connect and authenticate only. No message, recipients, or inventory queries are submitted.
$result = ['format'=>1, 'ready'=>false, 'code'=>'DAILY_REPORT_PROBE_FAILED', 'smtp_reply'=>null, 'no_mail_sent'=>true];
$transport = null;
try {
    Report::bootstrap();
    $mailer = Report::mailer();
    $transport = $mailer->getSwiftMailer()->getTransport();
    if (!$transport instanceof \Swift_SmtpTransport) throw new \RuntimeException('DAILY_REPORT_PROBE_REQUIRES_SMTP');
    $result['host'] = (string)$transport->getHost();
    $result['port'] = (int)$transport->getPort();
    $result['encryption'] = $transport->getEncryption();
    $result['tls_peer_verified'] = in_array($transport->getEncryption(), ['ssl', 'tls'], true);
    if (!$result['tls_peer_verified']) throw new \RuntimeException('DAILY_REPORT_PROBE_REQUIRES_TLS');
    $transport->start();
    $result['ready'] = true;
    $result['code'] = 'DAILY_REPORT_SMTP_AUTH_OK';
} catch (\Throwable $error) {
    $result = array_replace($result, Report::safeFailure($error));
} finally {
    if ($transport) { try { $transport->stop(); } catch (\Throwable $ignored) {} }
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
exit($result['ready'] ? 0 : 2);
