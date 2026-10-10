<?php
// Shared test-only source extraction and DOM normalization. No form renderer.
function nativeDeleteFormCases(string $application): array
{
    $cases = [];
    $root = $application.'/resources/views/';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'admin', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) continue;
        $source = file_get_contents($file->getPathname());
        if (preg_match('/\b(?:Form|Html)::/', $source)) {
            throw new RuntimeException('An active admin view still depends on the removed form/HTML facade.');
        }
        preg_match_all('/NativeDeleteForm::open\((.*?)\)\s*!!}/s', $source, $matches);
        if (!$matches[1]) continue;
        if (count($matches[1]) !== substr_count($source, 'NativeDeleteForm::close()')) {
            throw new RuntimeException('Original delete wrapper open/close count changed.');
        }
        $view = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
        foreach ($matches[1] as $index => $expression) {
            $cases[$view.'#'.($index + 1)] = [
                'view' => $view, 'ordinal' => $index + 1, 'expression' => $expression,
                'expressionSha256' => hash('sha256', str_replace("\r\n", "\n", $expression)),
            ];
        }
    }
    ksort($cases);
    return $cases;
}

function nativeDeleteFormOptions(string $expression): array
{
    // Trusted packaged Blade expressions; synthetic IDs, no controller calls.
    preg_match_all('/\$(\w+)/', $expression, $variables);
    $evaluate = static function (string $expression, array $names): array {
        foreach (array_unique($names) as $name) ${$name} = (object) ['id' => 42];
        return eval('return '.$expression.';');
    };
    return $evaluate($expression, $variables[1]);
}

function nativeDeleteFormDom(string $html, string $origin, string $token): array
{
    if ($token === '') throw new RuntimeException('The form comparison requires a current session CSRF token.');
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $forms = $document->getElementsByTagName('form');
    if ($forms->length !== 1) throw new RuntimeException('Expected exactly one original delete form.');
    $attributes = static function (DOMElement $element): array {
        $values = [];
        foreach ($element->attributes as $attribute) $values[$attribute->name] = $attribute->value;
        ksort($values);
        return $values;
    };
    $form = $forms->item(0);
    $formAttributes = $attributes($form);
    if (!str_starts_with($formAttributes['action'] ?? '', $origin.'/')) {
        throw new RuntimeException('The original delete route no longer belongs to the current application origin.');
    }
    // Preserve the complete path/query. Only the current local origin varies.
    $formAttributes['action'] = '{{LOCAL_ORIGIN}}'.substr($formAttributes['action'], strlen($origin));
    $inputs = [];
    $tokenCount = 0;
    foreach ($form->getElementsByTagName('input') as $input) {
        $values = $attributes($input);
        if (($values['name'] ?? '') === '_token') {
            if (($values['value'] ?? null) !== $token) throw new RuntimeException('The form does not contain the current session CSRF token.');
            $values['value'] = '{{CURRENT_CSRF_TOKEN}}';
            $tokenCount++;
        }
        $inputs[] = $values;
    }
    if ($tokenCount !== 1) throw new RuntimeException('Expected exactly one current-session CSRF field.');
    return ['attributes' => $formAttributes, 'inputs' => $inputs];
}
