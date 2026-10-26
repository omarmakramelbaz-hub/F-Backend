<?php
// Build-time only. The native runtime hashes this whole archive before use.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 3 || !class_exists(Phar::class)) {
    throw new RuntimeException('VENDOR_PHAR_ARGUMENTS_INVALID');
}
$vendor = realpath($argv[1]);
$output = $argv[2];
if (!is_string($vendor) || !is_dir($vendor) || is_link($argv[1])
    || file_exists($output) || is_link($output) || !is_dir(dirname($output))) {
    throw new RuntimeException('VENDOR_PHAR_PATH_INVALID');
}
$scope = 'vendor/google/apiclient-services/src/';
$source = $vendor . DIRECTORY_SEPARATOR . 'google' . DIRECTORY_SEPARATOR . 'apiclient-services' . DIRECTORY_SEPARATOR . 'src';
if (!is_dir($source) || is_link($source)) throw new RuntimeException('VENDOR_PHAR_GOOGLE_SOURCE_MISSING');
$files = []; $cases = []; $total = 0;
fwrite(STDERR, "GO_ERP_VENDOR_PHAR_SCAN count=0\n");
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $entry) {
    if ($entry->isLink() || !$entry->isFile()) throw new RuntimeException('VENDOR_PHAR_FILE_INVALID');
    $name = str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1));
    if (!preg_match('#^[a-zA-Z0-9_.@/+ -]+$#D', $name)
        || preg_match('#(?:^|/)\.{1,2}(?:/|$)#D', $name) || isset($cases[strtolower($name)])) {
        throw new RuntimeException('VENDOR_PHAR_NAME_INVALID');
    }
    // Native vendor executables cannot execute from a PHAR. This prototype
    // refuses such a package rather than silently removing a capability.
    if (preg_match('/\.(?:exe|dll|so|dylib|bat|cmd|ps1)$/iD', $name)) {
        throw new RuntimeException('VENDOR_PHAR_NATIVE_PACKAGE_UNSUPPORTED');
    }
    $size = $entry->getSize(); $total += $size;
    if ($size < 0 || $total > 4 * 1024 ** 3 || count($files) >= 100000) {
        throw new RuntimeException('VENDOR_PHAR_LIMIT');
    }
    $cases[strtolower($name)] = true;
    $hash = hash_file('sha256', $entry->getPathname());
    if (!is_string($hash)) throw new RuntimeException('VENDOR_PHAR_READ_FAILED');
    $files[$scope . $name] = $hash;
    if (count($files) % 5000 === 0) fwrite(STDERR, 'GO_ERP_VENDOR_PHAR_SCAN count=' . count($files) . "\n");
}
if (!$files) throw new RuntimeException('VENDOR_PHAR_EMPTY');
ksort($files, SORT_STRING);
$archive = new Phar($output, 0, 'go-erp-google-services.phar');
$archive->startBuffering();
$archive->setStub("<?php __HALT_COMPILER();\n");
$entries = [];
foreach ($files as $name => $hash) {
    $relative = substr($name, strlen($scope));
    $file = $source . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $entries[$name] = $file;
}
fwrite(STDERR, 'GO_ERP_VENDOR_PHAR_BUILD count=' . count($files) . "\n");
$archive->buildFromIterator(new ArrayIterator($entries));
$archive->setSignatureAlgorithm(Phar::SHA256);
$archive->stopBuffering();
// Independently read every archived entry after closing its write transaction.
$verified = 0;
fwrite(STDERR, "GO_ERP_VENDOR_PHAR_VERIFY count=0\n");
foreach ($files as $name => $hash) {
    $read = file_get_contents('phar://' . $output . '/' . $name);
    if (!is_string($read) || !hash_equals($hash, hash('sha256', $read))) {
        throw new RuntimeException('VENDOR_PHAR_ARCHIVE_CHANGED');
    }
    if (++$verified % 5000 === 0) fwrite(STDERR, 'GO_ERP_VENDOR_PHAR_VERIFY count=' . $verified . "\n");
}
unset($archive);
$size = filesize($output); $hash = hash_file('sha256', $output);
if (!is_int($size) || $size < 1 || !is_string($hash)) throw new RuntimeException('VENDOR_PHAR_OUTPUT_INVALID');
fwrite(STDERR, 'GO_ERP_VENDOR_PHAR_COMPLETE count=' . count($files) . "\n");
echo json_encode(['format' => 1, 'kind' => 'go-erp-google-services-phar', 'scope' => $scope, 'archive' => [
    'file' => 'google-services.phar', 'sha256' => $hash, 'bytes' => $size,
], 'files' => $files], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
