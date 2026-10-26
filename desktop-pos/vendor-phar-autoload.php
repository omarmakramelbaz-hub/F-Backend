<?php
// Native compact-runtime adapter: the same Google API class bytes, one file.
declare(strict_types=1);
return (static function () {
    $archive = realpath(dirname(__DIR__) . '/google-services.phar');
    $authorized = getenv('DESKTOP_DASHBOARD_VENDOR_PHAR');
    if (getenv('DESKTOP_DASHBOARD_LOCAL') !== 'true' || !is_string($archive)
        || !is_string($authorized) || realpath($authorized) !== $archive || is_link($authorized)) {
        throw new RuntimeException('COMPACT_VENDOR_NATIVE_AUTHORITY_REQUIRED');
    }
    $key = __DIR__ . '|' . $archive;
    if (isset($GLOBALS['__go_erp_compact_vendor_loaders'][$key])) {
        return $GLOBALS['__go_erp_compact_vendor_loaders'][$key];
    }
    // This is the original, receipt-verified Composer entry point, kept in the
    // same directory so all original app and package paths retain their meaning.
    $loader = require __DIR__ . '/autoload.original.php';
    if (!$loader instanceof Composer\Autoload\ClassLoader) {
        throw new RuntimeException('COMPACT_VENDOR_COMPOSER_LOADER_INVALID');
    }
    $prefix = str_replace('\\', '/', __DIR__) . '/google/apiclient-services/src/';
    $virtual = 'phar://' . str_replace('\\', '/', $archive) . '/vendor/google/apiclient-services/src/';
    $classmap = require __DIR__ . '/composer/autoload_classmap.php';
    $mapped = [];
    foreach ($classmap as $class => $file) {
        $normalized = str_replace('\\', '/', $file);
        // Composer's generated map normally contains composer/../. Resolve
        // these lexical segments without requiring a now-packed file to exist.
        do {
            $previous = $normalized;
            $normalized = preg_replace('#/[^/]+/\.\.(?=/|$)#', '', $normalized);
            $normalized = str_replace('/./', '/', $normalized);
        } while ($normalized !== $previous);
        $left = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
        $right = DIRECTORY_SEPARATOR === '\\' ? strtolower($prefix) : $prefix;
        if (str_starts_with($left, $right)) $mapped[$class] = $virtual . substr($normalized, strlen($prefix));
    }
    $loader->addClassMap($mapped);
    $loader->setPsr4('Google\\Service\\', rtrim($virtual, '/'));
    // Keep the package's documented legacy aliases and long-class filename
    // mapping. Its unchanged physical autoload.php was loaded by Composer.
    spl_autoload_register(static function (string $class) use ($virtual): void {
        if (str_starts_with($class, 'Google_Service_')) {
            class_exists(str_replace('_', '\\', $class));
            return;
        }
        if (!str_starts_with($class, 'Google\\Service\\')) return;
        $relative = substr($class, strlen('Google\\Service\\'));
        if (!preg_match('/^[a-zA-Z0-9_\\\\]+$/D', $relative)) return;
        $parts = explode('\\', $relative); $leaf = array_pop($parts);
        if (strlen($leaf) > 139) $leaf = substr($leaf, 0, 80) . '_' . strtoupper(substr(md5($leaf), 0, 8));
        $file = $virtual . ($parts ? implode('/', $parts) . '/' : '') . $leaf . '.php';
        if (is_file($file)) require_once $file;
    }, true, true);
    $GLOBALS['__go_erp_compact_vendor_loaders'][$key] = $loader;
    return $loader;
})();
