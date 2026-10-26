<?php
// Build-time independent verification. Never include an archived PHP file.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 3) throw new RuntimeException('VENDOR_PHAR_VERIFY_ARGUMENTS_INVALID');
$archive = realpath($argv[1]); $index = json_decode(file_get_contents($argv[2]), true, 16, JSON_THROW_ON_ERROR);
if (!is_string($archive) || is_link($argv[1]) || !is_array($index['files'] ?? null)
    || !$index['files'] || count($index['files']) > 100000) throw new RuntimeException('VENDOR_PHAR_VERIFY_INPUT_INVALID');
$phar = new Phar($archive);
$signature = $phar->getSignature();
if (!is_array($signature) || ($signature['hash_type'] ?? null) !== 'SHA-256') throw new RuntimeException('VENDOR_PHAR_VERIFY_SIGNATURE_INVALID');
$prefix = 'phar://' . str_replace('\\', '/', $archive) . '/';
$files = []; $count = 0;
fwrite(STDERR, "GO_ERP_VENDOR_PHAR_VERIFY count=0\n");
foreach (new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::LEAVES_ONLY) as $entry) {
    if (!$entry->isFile() || $entry->isLink() || $entry->getSize() > 64 * 1024 ** 2) {
        throw new RuntimeException('VENDOR_PHAR_VERIFY_ENTRY_INVALID');
    }
    $path = str_replace('\\', '/', $entry->getPathname());
    $candidate = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    $expected = DIRECTORY_SEPARATOR === '\\' ? strtolower($prefix) : $prefix;
    if (!str_starts_with($candidate, $expected)) throw new RuntimeException('VENDOR_PHAR_VERIFY_PATH_INVALID');
    $name = substr($path, strlen($prefix));
    if (!isset($index['files'][$name]) || isset($files[$name])) throw new RuntimeException('VENDOR_PHAR_VERIFY_ROSTER_INVALID');
    $content = $entry->getContent();
    if (!is_string($content) || !hash_equals($index['files'][$name], hash('sha256', $content))) {
        throw new RuntimeException('VENDOR_PHAR_VERIFY_CONTENT_INVALID');
    }
    $files[$name] = true;
    if (++$count % 5000 === 0) fwrite(STDERR, 'GO_ERP_VENDOR_PHAR_VERIFY count=' . $count . "\n");
}
if (count($files) !== count($index['files'])) throw new RuntimeException('VENDOR_PHAR_VERIFY_ROSTER_INVALID');
echo json_encode(['format' => 1, 'kind' => 'go-erp-google-services-phar-verification',
    'files' => $count, 'verified' => true], JSON_THROW_ON_ERROR) . "\n";
