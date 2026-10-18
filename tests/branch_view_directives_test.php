<?php
/**
 * Compile real Blade views and render branch selectors without booting the application.
 * BLADE_TEST_AUTOLOAD=/path/to/vendor/autoload.php php tests/branch_view_directives_test.php
 * BLADE_TEST_VIEW_ROOT may point to an Illuminate View 8 package when it is not in vendor.
 */
$autoload = getenv('BLADE_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) throw new RuntimeException('Set BLADE_TEST_AUTOLOAD to an Illuminate compatible Composer autoloader.');
require $autoload;
$viewRoot = getenv('BLADE_TEST_VIEW_ROOT');
if ($viewRoot) {
    spl_autoload_register(function ($class) use ($viewRoot) {
        $prefix = 'Illuminate\\View\\';
        if (strpos($class, $prefix) !== 0) return;
        $path = rtrim($viewRoot, '/').'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) require $path;
    }, true, true);
}
if (!class_exists(\Illuminate\View\Compilers\BladeCompiler::class)) {
    throw new RuntimeException('Illuminate View is required; set BLADE_TEST_VIEW_ROOT if it is installed separately.');
}
if (!function_exists('__')) {
    function __($key, $replace = [], $locale = null) { return $key; }
}
// Laravel's existing translation helper resolves this binding; standalone packages use the fallback above.
$container = new \Illuminate\Container\Container;
\Illuminate\Container\Container::setInstance($container);
$container->instance('translator', new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'ar'));

class BranchSelectorEnvironment
{
    use \Illuminate\View\Concerns\ManagesLoops;
}

$checks = 0;
function checkBranchView($condition, $message)
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException('BRANCH_VIEW_DIRECTIVES: '.$message);
}
function lintBranchView($path)
{
    $process = proc_open([PHP_BINARY, '-n', '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to start PHP syntax check.');
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}
function renderBranchSelector($compiled, $boot)
{
    $__env = new BranchSelectorEnvironment;
    ob_start();
    try {
        eval('?>'.$compiled);
        return ob_get_clean();
    } catch (\Throwable $error) {
        ob_end_clean();
        throw $error;
    }
}

$temporary = sys_get_temp_dir().'/fasakhansta-branch-blade-'.bin2hex(random_bytes(8));
if (!mkdir($temporary, 0700)) throw new RuntimeException('Unable to create private Blade test directory.');
register_shutdown_function(function () use ($temporary) {
    foreach (glob($temporary.'/*') ?: [] as $path) if (is_file($path)) unlink($path);
    rmdir($temporary);
});
$blade = new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem, $temporary);
$root = dirname(__DIR__);
$manifest = file_get_contents($root.'/deployment/apply_dashboard_all_branches.py');
preg_match_all("~'((?:resources/views/)[^']+\\.blade\\.php)'~", $manifest, $matches);
$views = array_values(array_unique($matches[1]));
checkBranchView(count($views) >= 8, 'the rollout view manifest is available');
foreach ($views as $index => $relative) {
    $source = file_get_contents($root.'/'.$relative);
    $compiled = $blade->compileString($source);
    $path = $temporary.'/view-'.$index.'.php';
    file_put_contents($path, $compiled);
    [$status, $output] = lintBranchView($path);
    checkBranchView($status === 0, 'complete template compiles: '.$relative.'; '.$output);
}

$branches = [['value' => 'f:100', 'name' => 'شبرا'], ['value' => 'f:101', 'name' => 'المنصورة']];
$contexts = [
    'admin-all' => ['can_all' => true, 'allow_all' => true, 'branch' => 'all', 'selected_branch' => 'all', 'branches' => $branches],
    'admin-explicit' => ['can_all' => true, 'allow_all' => true, 'branch' => 'f:101', 'selected_branch' => 'f:101', 'branches' => $branches],
    'admin-single-all' => ['can_all' => true, 'allow_all' => true, 'branch' => 'all', 'selected_branch' => 'all', 'branches' => [$branches[0]]],
    'manager-single' => ['can_all' => false, 'allow_all' => false, 'branch' => 'f:100', 'selected_branch' => 'f:100', 'branches' => [$branches[0]]],
];
foreach (['branch_stock' => 'stock', 'branch_shifts' => 'sh', 'expenses' => 'expense'] as $view => $attribute) {
    $source = file_get_contents($root.'/resources/views/admin/'.$view.'/index.blade.php');
    preg_match('~<select data-'.preg_quote($attribute, '~').'-branch\\b.*?</select>~s', $source, $selector);
    checkBranchView(!empty($selector), 'branch selector is available: '.$view);
    $compiled = $blade->compileString($selector[0]);
    foreach ($contexts as $case => $boot) {
        $rendered = renderBranchSelector($compiled, $boot);
        checkBranchView(!preg_match('/@(?:if|endif|foreach|endforeach)\\b/', $rendered), 'no Blade directives leak: '.$view.' '.$case);
        preg_match_all('~<option\\b([^>]*)>~', $rendered, $options);
        $selected = [];
        $values = [];
        foreach ($options[1] as $attributes) {
            preg_match('~value="([^"]*)"~', $attributes, $value);
            checkBranchView(isset($value[1]), 'option has a branch value');
            $values[] = $value[1];
            if (preg_match('~\\bselected\\b~', $attributes)) $selected[] = $value[1];
        }
        checkBranchView($selected === [$boot['selected_branch']], 'selected branch survives: '.$view.' '.$case);
        checkBranchView(in_array('all', $values, true) === $boot['can_all'], 'all option follows actor access: '.$view.' '.$case);
        $expected = array_column($boot['branches'], 'value');
        if ($boot['can_all']) array_unshift($expected, 'all');
        checkBranchView($values === $expected, 'every allowed branch appears once: '.$view.' '.$case);
        preg_match('~<select\\b([^>]*)>~', $rendered, $select);
        $disabled = (bool)preg_match('~\\bdisabled\\b~', $select[1]);
        $expectDisabled = $view !== 'branch_shifts' && !$boot['can_all'] && count($boot['branches']) === 1;
        checkBranchView($disabled === $expectDisabled, 'selector disabled state survives: '.$view.' '.$case);
    }
    if ($view !== 'expenses') {
        $broken = preg_replace('/@endif\\s+@foreach/', '@endif@foreach', $source, 1, $count);
        checkBranchView($count === 1, 'original adjacent directives can be reconstructed: '.$view);
        $path = $temporary.'/'.$view.'-original.php';
        file_put_contents($path, $blade->compileString($broken));
        [$status, $output] = lintBranchView($path);
        checkBranchView($status !== 0 && strpos($output, 'endforeach') !== false, 'original bug fails with unexpected endforeach: '.$view);
    }
}
echo 'BRANCH_VIEW_DIRECTIVES_OK views='.count($views).' checks='.$checks.PHP_EOL;
