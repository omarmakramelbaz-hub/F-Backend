<?php
// Always compare with the frozen old rendered contract, without Collective.
// Passing this under Laravel 8 is not Laravel 12 bootstrap acceptance.
use App\Support\NativeDeleteForm;

require_once __DIR__.'/native-delete-form-contract-support.php';
$formContractBaseline = json_decode(file_get_contents(__DIR__.'/fixtures/native-delete-form-collective-6.4.1.json'), true, 512, JSON_THROW_ON_ERROR);
if (($formContractBaseline['format'] ?? null) !== 1 || count($formContractBaseline['cases'] ?? []) !== 22) {
    throw new RuntimeException('The reviewed original delete form baseline is missing or malformed.');
}
$formContractRequest = app('request');
$formContractQuery = $formContractRequest->query->all();
// Console bootstrap does not run HTTP StartSession. Use a genuine current token.
$formContractSession = app('session.store');
$formContractToken = $formContractSession->get('_token');
$formContractSession->regenerateToken();
$formContractRequest->query->replace($formContractBaseline['requestQuery']);
try {
    $cases = nativeDeleteFormCases($application);
    verify(array_keys($cases) === array_keys($formContractBaseline['cases'])
        && count(array_unique(array_column($cases, 'view'))) === 19,
        'all 44 original live admin Form calls retain their 22 reviewed route expressions');
    $formContractOrigin = rtrim(app('url')->to('/'), '/');
    foreach ($cases as $key => $case) {
        $expected = $formContractBaseline['cases'][$key];
        verify($case['expressionSha256'] === $expected['expressionSha256'],
            'the original delete route expression remains bound to its old rendered baseline: '.$key);
        $options = nativeDeleteFormOptions($case['expression']);
        $actual = nativeDeleteFormDom((string) NativeDeleteForm::open($options).(string) NativeDeleteForm::close(), $formContractOrigin, csrf_token());
        verify($actual === $expected['dom'],
            'native delete wrapper matches the frozen original path, query, attributes, method and CSRF contract: '.$key);
    }
    verify((string) NativeDeleteForm::close() === $formContractBaseline['closeHtml'], 'the native delete wrapper retains the original closing tag');
    foreach ([['method' => 'POST', 'route' => ['roles.destroy', 42]],
        ['method' => 'DELETE', 'route' => ['roles.destroy', 42], 'files' => true]] as $unsupported) {
        $rejected = false;
        try { NativeDeleteForm::open($unsupported); } catch (InvalidArgumentException $error) { $rejected = true; }
        verify($rejected, 'unreviewed general or file forms cannot silently use the scoped delete wrapper');
    }
} finally {
    $formContractRequest->query->replace($formContractQuery);
    if ($formContractToken === null) $formContractSession->forget('_token');
    else $formContractSession->put('_token', $formContractToken);
}
