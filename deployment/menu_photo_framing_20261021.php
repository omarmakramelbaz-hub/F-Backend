<?php
/** Preserve live styles and append only full-product framing for menu-card photographs. */
try {
    $options = getopt('', ['check', 'apply', 'root:', 'backup:']);
    if (isset($options['check']) === isset($options['apply'])) throw new RuntimeException('Choose --check or --apply.');
    $root = realpath($options['root'] ?? '');
    if (!$root) throw new RuntimeException('Invalid project root.');
    $apply = isset($options['apply']);
    $backup = $options['backup'] ?? '';
    if ($apply && (!is_dir($backup) || is_link($backup))) throw new RuntimeException('A private framing backup directory is required.');
    $plans = [];
    foreach (['takeaway'=>'tp', 'dining'=>'dp'] as $name=>$prefix) {
        $path = $root.'/public/dashboard/css/'.$name.'-pos.css';
        if (!is_file($path) || is_link($path) || realpath($path) !== $path || !is_readable($path) || !is_writable($path)) throw new RuntimeException('Menu framing stylesheet is unavailable: '.$name);
        $before = file_get_contents($path);
        if (strpos($before, '.'.$prefix.'-product-image') === false) throw new RuntimeException('Expected menu image selector is missing: '.$name);
        $marker = '/* menu-photo-framing-20261021 */';
        $rule = "\n".$marker."\nimg.".$prefix."-product-image { object-fit: contain; background-color: #fff; }\n";
        $already = strpos($before, $marker) !== false;
        if ($already && strpos($before, trim($rule)) === false) throw new RuntimeException('A conflicting framing marker requires review: '.$name);
        $plans[] = ['name'=>$name, 'path'=>$path, 'before'=>$before, 'after'=>$already ? $before : $before.$rule, 'stat'=>stat($path), 'already'=>$already];
    }
    if (!$apply) { echo "MENU_PHOTO_FRAMING_CHECK_READY\n"; exit(0); }
    $done = [];
    try {
        foreach ($plans as $plan) {
            if ($plan['already']) continue;
            if (file_get_contents($plan['path']) !== $plan['before']) throw new RuntimeException('A stylesheet changed during framing publication.');
            $copy = $backup.'/'.$plan['name'].'-pos.css.before';
            if (is_link($copy) || file_exists($copy)) throw new RuntimeException('Framing backup already exists.');
            if (file_put_contents($copy, $plan['before'], LOCK_EX) === false) throw new RuntimeException('Cannot save framing backup.');
            chmod($copy, 0600);
            $temp = tempnam(dirname($plan['path']), '.menu-photo-framing-');
            if ($temp === false || file_put_contents($temp, $plan['after'], LOCK_EX) === false) throw new RuntimeException('Cannot stage menu photo framing.');
            if (!chmod($temp, $plan['stat']['mode'] & 0777) || !chown($temp, $plan['stat']['uid']) || !chgrp($temp, $plan['stat']['gid']) || !rename($temp, $plan['path'])) { @unlink($temp); throw new RuntimeException('Cannot publish menu photo framing.'); }
            $done[] = $plan;
        }
    } catch (Throwable $error) {
        foreach (array_reverse($done) as $plan) {
            $temp = tempnam(dirname($plan['path']), '.menu-photo-framing-restore-');
            if ($temp !== false && file_put_contents($temp, $plan['before'], LOCK_EX) !== false) {
                chmod($temp, $plan['stat']['mode'] & 0777); chown($temp, $plan['stat']['uid']); chgrp($temp, $plan['stat']['gid']);
                if (!rename($temp, $plan['path'])) @unlink($temp);
            }
        }
        throw $error;
    }
    echo "MENU_PHOTO_FRAMING_READY\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'MENU_PHOTO_FRAMING_STOPPED: '.$error->getMessage()."\n");
    exit(1);
}
