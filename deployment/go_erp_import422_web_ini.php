<?php
// Private FastCGI script. It is never placed in a public document root.
if (PHP_SAPI !== 'fpm-fcgi'
    || !is_string($_SERVER['GO_ERP_PROBE_NONCE'] ?? null)
    || !preg_match('/^[a-f0-9]{64}$/D', $_SERVER['GO_ERP_PROBE_NONCE'])) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode([
    'kind' => 'go-erp-private-fpm-ini',
    'nonce' => $_SERVER['GO_ERP_PROBE_NONCE'],
    'sapi' => PHP_SAPI,
    'php_version' => PHP_VERSION,
    'effective_uid' => function_exists('posix_geteuid') ? posix_geteuid() : null,
    'precision' => ini_get('precision'),
    'serialize_precision' => ini_get('serialize_precision'),
    'user_ini_filename' => ini_get('user_ini.filename'),
], JSON_THROW_ON_ERROR);
exit;
