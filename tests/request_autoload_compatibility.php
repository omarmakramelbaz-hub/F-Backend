<?php
// Uses the original application's Composer loader and FormRequest, without
// booting providers, loading an environment file or connecting to a database.

$cases = ['canonical-first', 'legacy-first', 'discovery-canonical-first',
    'discovery-legacy-first', 'stale-classmap', 'stale-classmap-discovery', 'legacy-install-transition'];
$child = ($argv[1] ?? '') === '--case';
$case = $child ? ($argv[2] ?? '') : null;
$application = realpath($argv[$child ? 3 : 1] ?? dirname(__DIR__));
if (!$application || !is_file($application.'/vendor/autoload.php')
    || !is_file($application.'/bootstrap/app.php')) {
    throw new RuntimeException('Usage: request_autoload_compatibility.php original-application-directory');
}
if ($child && !in_array($case, $cases, true)) {
    throw new RuntimeException('Unknown request-autoload case.');
}

if (!$child) {
    foreach ($cases as $scenario) {
        $process = proc_open([PHP_BINARY, __FILE__, '--case', $scenario, $application],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start isolated autoload test.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || trim($output) !== 'PASS '.$scenario || $error !== '') {
            throw new RuntimeException('Autoload compatibility failed for '.$scenario.': '.$output.$error);
        }
        echo $output;
    }
    echo 'REQUEST_AUTOLOAD_COMPATIBILITY_COMPLETE '.count($cases).PHP_EOL;
    exit(0);
}

$loader = require $application.'/vendor/autoload.php';
$app = require $application.'/bootstrap/app.php';
$directory = $application.'/app/Http/Requests/Dashboard/QuestionAnswer';
$canonicalFile = $directory.'/StoreQuestionAnswerRequest.php';
$legacyFile = $directory.'/StoreQuestionAnswrRequest.php';
$canonical = 'App\\Http\\Requests\\Dashboard\\QuestionAnswer\\StoreQuestionAnswerRequest';
$legacy = 'App\\Http\\Requests\\Dashboard\\QuestionAnswer\\StoreQuestionAnswrRequest';
$formRequest = Illuminate\Foundation\Http\FormRequest::class;
if (!$loader instanceof Composer\Autoload\ClassLoader) {
    throw new RuntimeException('The original Composer loader was not returned.');
}
// Reproduce the production map made before the filename correction. Composer
// optimization still permits PSR-4 discovery of the legacy filename.
$loader->setClassMapAuthoritative(false);
if (str_starts_with($case, 'stale-classmap')) $loader->addClassMap([$canonical => $legacyFile]);
$transitionBase = null;
if ($case === 'legacy-install-transition') {
    $transitionBase = sys_get_temp_dir().'/fasakhansta-request-transition-'.bin2hex(random_bytes(16));
    $directory = $transitionBase.'/app/Http/Requests/Dashboard/QuestionAnswer';
    if (!mkdir($directory, 0700, true)) throw new RuntimeException('Cannot create private request transition fixture.');
    $canonicalSource = $canonicalFile;
    $canonicalFile = $directory.'/StoreQuestionAnswerRequest.php';
    $legacyTarget = $directory.'/StoreQuestionAnswrRequest.php';
    if (!copy($legacyFile, $legacyTarget)) throw new RuntimeException('Cannot copy request compatibility bridge.');
    $legacyFile = $legacyTarget;
    register_shutdown_function(function () use ($transitionBase, $canonicalFile, $legacyFile, $directory) {
        foreach ([$canonicalFile, $legacyFile] as $path) if (is_file($path)) unlink($path);
        $path = $directory;
        while ($path !== $transitionBase) { rmdir($path); $path = dirname($path); }
        rmdir($transitionBase);
    });
    $loader->setPsr4('App\\Http\\Requests\\Dashboard\\QuestionAnswer\\', $directory);
    $loader->addClassMap([$canonical => $legacyFile, $legacy => $legacyFile]);
}

switch ($case) {
    case 'canonical-first':
        if (!class_exists($canonical)) throw new RuntimeException('Canonical PSR-4 lookup failed.');
        require $legacyFile;
        require $legacyFile;
        break;
    case 'legacy-first':
        require $legacyFile;
        require_once $canonicalFile;
        break;
    case 'discovery-canonical-first':
        if (!is_subclass_of($canonical, $formRequest)) throw new RuntimeException('Canonical discovery failed.');
        if (is_subclass_of($legacy, $formRequest)) throw new RuntimeException('Legacy path declared another request.');
        break;
    case 'discovery-legacy-first':
        if (is_subclass_of($legacy, $formRequest)) throw new RuntimeException('Legacy path declared another request.');
        if (!is_subclass_of($canonical, $formRequest)) throw new RuntimeException('Canonical discovery failed.');
        break;
    case 'stale-classmap':
        if (!class_exists($canonical)) throw new RuntimeException('Stale Composer classmap lookup failed.');
        require $legacyFile;
        break;
    case 'stale-classmap-discovery':
        if (!is_subclass_of($canonical, $formRequest)) throw new RuntimeException('Stale classmap discovery failed.');
        if (is_subclass_of($legacy, $formRequest)) throw new RuntimeException('Legacy path declared another request.');
        break;
    case 'legacy-install-transition':
        if (is_file($canonicalFile) || !class_exists($canonical)
            || realpath((new ReflectionClass($canonical))->getFileName()) !== realpath($legacyFile)) {
            throw new RuntimeException('Early compatibility bridge did not preserve the original request.');
        }
        if (!copy($canonicalSource, $canonicalFile)) throw new RuntimeException('Cannot publish corrected request in private fixture.');
        // A worker that loaded the legacy fallback can encounter the new path
        // during settings discovery. Class-aware loading must keep its class.
        require $legacyFile;
        if (!is_subclass_of($canonical, $formRequest) || is_subclass_of($legacy, $formRequest)) {
            throw new RuntimeException('Publishing the corrected request broke the existing worker.');
        }
        break;
}

// The production settings package derives class names from both filenames.
// Execute that same package against this focused directory after each order.
$discovery = (new Spatie\LaravelSettings\Support\DiscoverSettings)->within([$directory]);
if ($transitionBase !== null) $discovery->useBasePath($transitionBase.'/app')->useRootNamespace('App\\');
$settings = $discovery->discover();
if ($settings !== []) throw new RuntimeException('Request files were discovered as settings.');
if (class_exists($legacy, false)) throw new RuntimeException('Legacy path introduced a second class.');
$reflection = new ReflectionClass($canonical);
$expectedDefinition = $transitionBase === null ? $canonicalFile : $legacyFile;
if (realpath($reflection->getFileName()) !== realpath($expectedDefinition)
    || !$reflection->isSubclassOf($formRequest)) {
    throw new RuntimeException('The canonical request no longer owns the original class.');
}
$request = $reflection->newInstanceWithoutConstructor();
$expected = ['added_by' => 'required|exists:users,id',
    'question_ar' => 'required|string|min:3|max:1200',
    'question_en' => 'required|string|min:3|max:1200',
    'answer_ar' => 'required|string|min:3|max:1200',
    'answer_en' => 'required|string|min:3|max:1200'];
if ($request->authorize() !== true || $request->rules() !== $expected) {
    throw new RuntimeException('The original authorization or validation contract changed.');
}
echo 'PASS '.$case.PHP_EOL;
