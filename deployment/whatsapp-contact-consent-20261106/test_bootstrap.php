<?php
declare(strict_types=1);

/** Exercise installer startup with a bootstrap that eagerly loads the old class. */
define('WCP_LIBRARY', true);
require __DIR__.'/install.php';

function expect_bootstrap(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function bootstrap_case(string $case): void
{
    $root = sys_get_temp_dir().'/wcp-bootstrap-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root.'/vendor');
    mkdir($root.'/bootstrap');
    $source = realpath(dirname(__DIR__, 2).'/app/Services/Dashboard/WhatsAppCustomerDirectory.php');
    expect_bootstrap(is_string($source), 'Verified policy source must exist');
    $GLOBALS['wcp_bootstrap_fixture_root'] = $root;
    $GLOBALS['wcp_bootstrap_fixture_source'] = $source;
    file_put_contents($root.'/old.php', '<?php namespace App\\Services\\Dashboard; class WhatsAppCustomerDirectory {}');
    file_put_contents($root.'/vendor/autoload.php', <<<'VENDOR'
<?php
$root = $GLOBALS['wcp_bootstrap_fixture_root'];
file_put_contents($root.'/trace', "vendor\n", FILE_APPEND);
spl_autoload_register(function (string $class) use ($root): void {
    if ($class === 'App\\Services\\Dashboard\\WhatsAppCustomerDirectory') require $root.'/old.php';
});
// Composer files may also request the legacy class before Laravel starts.
class_exists('App\\Services\\Dashboard\\WhatsAppCustomerDirectory');
if (!method_exists('App\\Services\\Dashboard\\WhatsAppCustomerDirectory', 'consentDefaults')) {
    throw new RuntimeException('FIXTURE_OLD_CLASS_LOADED_DURING_COMPOSER');
}
VENDOR
    );
    file_put_contents($root.'/bootstrap/app.php', <<<'APPLICATION'
<?php
$root = $GLOBALS['wcp_bootstrap_fixture_root'];
file_put_contents($root.'/trace', "application\n", FILE_APPEND);
// Production console/provider discovery can load the class before bootstrap returns.
class_exists('App\\Services\\Dashboard\\WhatsAppCustomerDirectory');
if (!method_exists('App\\Services\\Dashboard\\WhatsAppCustomerDirectory', 'consentDefaults')) {
    throw new RuntimeException('FIXTURE_OLD_CLASS_LOADED_DURING_BOOTSTRAP');
}
return new class {
    public function make(string $contract): object
    {
        if ($contract !== 'Illuminate\\Contracts\\Console\\Kernel') throw new RuntimeException('Unexpected kernel contract');
        return new class {
            public function bootstrap(): void
            {
                $root = $GLOBALS['wcp_bootstrap_fixture_root'];
                file_put_contents($root.'/trace', "kernel\n", FILE_APPEND);
                $class = new ReflectionClass('App\\Services\\Dashboard\\WhatsAppCustomerDirectory');
                if (realpath((string)$class->getFileName()) !== $GLOBALS['wcp_bootstrap_fixture_source']) {
                    throw new RuntimeException('FIXTURE_WRONG_CANDIDATE_SOURCE');
                }
                $changes = \App\Services\Dashboard\WhatsAppCustomerDirectory::consentDefaults([], '2026-11-06 00:00:00');
                if (($changes['service_consent'] ?? false) !== true || ($changes['marketing_consent'] ?? false) !== true) {
                    throw new RuntimeException('FIXTURE_POLICY_METHOD_UNAVAILABLE');
                }
            }
        };
    }
};
APPLICATION
    );

    if ($case === 'valid') {
        $app = wcp_bootstrap($root, $source);
        expect_bootstrap(is_object($app), 'Installer bootstrap must return the application');
        expect_bootstrap(file_get_contents($root.'/trace') === "vendor\napplication\nkernel\n",
            'The verified candidate must precede eager application and console bootstrap');
        expect_bootstrap(hash_equals(WCP_NEW, hash_file('sha256', $source)), 'Bootstrap must not mutate candidate source');
    } else {
        if ($case === 'bad-hash') {
            $candidate = $root.'/untrusted.php';
            file_put_contents($candidate, '<?php file_put_contents('.var_export($root.'/untrusted-executed', true).', "unexpected");');
        } elseif ($case === 'symlink') {
            $candidate = $root.'/linked.php';
            expect_bootstrap(symlink($source, $candidate), 'Fixture symlink must be created');
        } else {
            throw new RuntimeException('Unknown test case');
        }
        $rejected = false;
        try { wcp_bootstrap($root, $candidate); } catch (WcpException $error) { $rejected = true; }
        expect_bootstrap($rejected, 'Installer must reject an invalid candidate');
        expect_bootstrap(!file_exists($root.'/trace'), 'Candidate validation must precede vendor/application bootstrap');
        expect_bootstrap(!file_exists($root.'/untrusted-executed'), 'An invalid candidate must never execute');
        expect_bootstrap(!class_exists('App\\Services\\Dashboard\\WhatsAppCustomerDirectory', false),
            'Rejecting invalid source must not load the installed class');
    }
    echo 'BOOTSTRAP_CASE_PASSED:'.$case."\n";
}

if (isset($argv[1])) {
    bootstrap_case($argv[1]);
    exit(0);
}

foreach (['valid', 'bad-hash', 'symlink'] as $case) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, $case], [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
    expect_bootstrap(is_resource($process), 'A fresh PHP process is needed for each autoload case');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    expect_bootstrap($status === 0 && str_contains($stdout, 'BOOTSTRAP_CASE_PASSED:'.$case),
        'Installer bootstrap case '.$case.' failed: '.$stderr.$stdout);
}

echo "WHATSAPP_CONSENT_BOOTSTRAP_TESTS_PASSED\n";
