<?php
// Inspect PHP source as tokens only. Never include, evaluate, or print its contents.
if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "METHOD_SOURCE_REQUIRED\n");
    exit(1);
}

$source = file_get_contents($argv[1]);
$raw = token_get_all($source);
$tokens = [];
$offset = 0;
foreach ($raw as $token) {
    $text = is_array($token) ? $token[1] : $token;
    $tokens[] = ['id' => is_array($token) ? $token[0] : null, 'text' => $text, 'start' => $offset];
    $offset += strlen($text);
}
$ignored = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
$modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT];
$methods = [];
for ($i = 0, $size = count($tokens); $i < $size; ++$i) {
    if ($tokens[$i]['id'] !== T_FUNCTION) {
        continue;
    }
    $nameAt = $i + 1;
    while ($nameAt < $size && (in_array($tokens[$nameAt]['id'], $ignored, true) || $tokens[$nameAt]['text'] === '&')) {
        ++$nameAt;
    }
    if (($tokens[$nameAt]['id'] ?? null) !== T_STRING) {
        continue; // Anonymous closure.
    }
    $name = $tokens[$nameAt]['text'];
    $first = $i;
    for ($before = $i - 1; $before >= 0; --$before) {
        if (in_array($tokens[$before]['id'], $ignored, true)) {
            continue;
        }
        if (in_array($tokens[$before]['id'], $modifiers, true)) {
            $first = $before;
            continue;
        }
        break;
    }
    $open = $nameAt + 1;
    while ($open < $size && $tokens[$open]['text'] !== '{' && $tokens[$open]['text'] !== ';') {
        ++$open;
    }
    if ($open >= $size || $tokens[$open]['text'] !== '{') {
        continue;
    }
    $depth = 1;
    $last = $open;
    while ($depth > 0 && ++$last < $size) {
        $token = $tokens[$last];
        if (($token['id'] === null && $token['text'] === '{')
            || in_array($token['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            ++$depth;
        } elseif ($token['id'] === null && $token['text'] === '}') {
            --$depth;
        }
    }
    if ($depth !== 0 || isset($methods[$name])) {
        fwrite(STDERR, "METHOD_BOUNDARY_AMBIGUOUS\n");
        exit(1);
    }
    $significant = [];
    for ($part = $first; $part <= $last; ++$part) {
        if (!in_array($tokens[$part]['id'], $ignored, true)) {
            $significant[] = [$tokens[$part]['id'] === null ? 'literal' : token_name($tokens[$part]['id']), $tokens[$part]['text']];
        }
    }
    $start = $tokens[$first]['start'];
    $line = strrpos(substr($source, 0, $start), "\n");
    $line = $line === false ? 0 : $line + 1;
    if (trim(substr($source, $line, $start - $line)) === '') {
        $start = $line;
    }
    $methods[$name] = [
        'start' => $start,
        'end' => $tokens[$last]['start'] + strlen($tokens[$last]['text']),
        'hash' => hash('sha256', json_encode($significant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
    ];
    $i = $last;
}
echo json_encode(['methods' => $methods], JSON_THROW_ON_ERROR);
