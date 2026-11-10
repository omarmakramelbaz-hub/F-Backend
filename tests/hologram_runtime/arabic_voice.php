<?php
/** Standalone real-process checks; no Laravel dependencies, network or live database. */
namespace App\Services\Dashboard {
    class DashboardLoginIdentity { public const ACCOUNT_TYPES = ['admin', 'vendor', 'resturant_owner']; }
}
namespace {
    $settings = [];
    function config($key, $default = null) { global $settings; return $settings[$key] ?? $default; }
    function storage_path($path = '') { return sys_get_temp_dir().'/unused-arabic-voice/'.$path; }
    require dirname(__DIR__, 2).'/app/Services/Dashboard/HomeHologramArabicVoice.php';

    class ArabicFixtureVoice extends \App\Services\Dashboard\HomeHologramArabicVoice
    {
        public $fixtureRoot;
        protected function root(): string { return $this->fixtureRoot; }
        public function fixtureCommand(): ?array { return parent::command(); }
    }
    $checks = 0;
    function check($condition, $message) { global $checks; if (!$condition) throw new \RuntimeException($message); $checks++; }
    function same($expected, $actual, $message) { check($expected === $actual, $message.'; expected '.var_export($expected,true).', got '.var_export($actual,true)); }
    function removeFixture($directory) {
        if (is_link($directory) || !is_dir($directory)) { @unlink($directory); return; }
        foreach (new \FilesystemIterator($directory) as $entry) removeFixture($entry->getPathname());
        rmdir($directory);
    }
    function copyEngineFixture($source, $target) {
        if (is_link($source) || !is_dir($source)) throw new \RuntimeException('Engine fixture must be an ordinary directory');
        mkdir($target,0700);
        foreach (new \FilesystemIterator($source) as $entry) {
            if ($entry->isLink()) throw new \RuntimeException('Engine fixture must not contain symbolic links');
            $destination=$target.'/'.$entry->getFilename();
            if ($entry->isDir()) copyEngineFixture($entry->getPathname(),$destination);
            else { copy($entry->getPathname(),$destination); chmod($destination,0600); }
        }
    }
    $temporary = sys_get_temp_dir().'/go-arabic-voice-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700); $voice = new ArabicFixtureVoice(); $voice->fixtureRoot = $temporary.'/spool';
    $lock = $temporary.'/speech.lock'; file_put_contents($lock, ''); chmod($lock,0600);
    $script = $temporary.'/synth.py'; $python = '/usr/bin/python3';
    $settings = ['home_hologram_arabic_voice.command' => [$python, '-I', '-B', $script],
        'home_hologram_arabic_voice.lock_path' => $lock, 'home_hologram_arabic_voice.timeout_seconds' => .25];
    $actor = (object) ['id' => 1, 'account_type' => 'admin'];
    $valid = "import sys,io,wave,pathlib\ntext=sys.stdin.buffer.read()\npathlib.Path(__file__).with_suffix('.input').write_bytes(text)\nb=io.BytesIO()\nwith wave.open(b,'wb') as w:\n w.setnchannels(1);w.setsampwidth(2);w.setframerate(22050);w.writeframes(b'\\x11\\x01'*4410)\nsys.stdout.buffer.write(b.getvalue())\n";
    $write = function($code) use ($script) { file_put_contents($script,$code); chmod($script,0600); };
    $files = function() use ($voice) { return glob($voice->fixtureRoot.'/speech.*.wav') ?: []; };
    try {
        $write($valid);
        $command = $voice->fixtureCommand();
        check(is_array($command) && $command[0] === realpath($python), 'Trusted OS Python symlink resolves to an executable regular file');
        $result = $voice->synthesize($actor, '  العملاء، 50,803 عميل.  ');
        same('ready',$result['state'],'Actual private subprocess produces a verified standard WAV');
        same('العملاء، 50,803 عميل.',file_get_contents($temporary.'/synth.input'),'The helper receives only canonical text on stdin');
        same(0600,fileperms($result['path']) & 0777,'Audio remains private');
        same(0700,fileperms($voice->fixtureRoot) & 0777,'Runtime spool remains private');
        unlink($result['path']);
        $injection = 'قول $(touch '.$temporary.'/shell-injection)';
        $result = $voice->synthesize($actor,$injection);
        same('ready',$result['state'],'Shell metacharacters are passed as text, never a command');
        same($injection,file_get_contents($temporary.'/synth.input'),'Stdin text is not interpolated by a shell');
        check(!file_exists($temporary.'/shell-injection'),'Shell substitution has no effect'); unlink($result['path']);
        foreach ([null,[], '',"\xff",str_repeat('ع',401),'<speak>مرحبا</speak>','[[phonemes]]',"مرحبا\0"] as $text) {
            same('invalid',$voice->synthesize($actor,$text)['state'],'Invalid, oversized or markup text is refused');
        }
        same('unavailable',$voice->synthesize(null,'مرحبا')['state'],'No anonymous synthesis');
        same('unavailable',$voice->synthesize((object)['id'=>1,'account_type'=>'customer'],'مرحبا')['state'],'Only dashboard account types may synthesize');
        same('unavailable',$voice->synthesize((object)['id'=>'../../x','account_type'=>'admin'],'مرحبا')['state'],'Invalid actor identity is refused');
        $held = fopen($lock,'r+b'); flock($held,LOCK_EX); $started=microtime(true);
        same('busy',$voice->synthesize($actor,'مرحبا')['state'],'Global inference lock refuses overlap');
        check(microtime(true)-$started<.2,'Busy inference does not wait for another request');
        flock($held,LOCK_UN); fclose($held);
        $write("import sys\nsys.exit(7)\n");
        same('unavailable',$voice->synthesize($actor,'مرحبا')['state'],'Nonzero synthesis exit is not published');same([],$files(),'Failed private output is removed');
        $write("import sys\nsys.stdout.buffer.write(b'not a wave')\n");
        same('unavailable',$voice->synthesize($actor,'مرحبا')['state'],'Invalid waveform is not served');same([],$files(),'Malformed output is removed');
        $write("import sys\nsys.stdout.buffer.write(b'x'*(3*1024*1024))\n");
        same('unavailable',$voice->synthesize($actor,'مرحبا')['state'],'Oversized process output is refused');same([],$files(),'Oversized output is removed');
        $write("import time\ntime.sleep(30)\n"); $started=microtime(true);
        same('unavailable',$voice->synthesize($actor,'مرحبا')['state'],'Overdue speech process is terminated');
        check(microtime(true)-$started<2,'Process wall timeout is bounded');same([],$files(),'Timed out output is removed');
        $write($valid);
        rmdir($voice->fixtureRoot); mkdir($temporary.'/outside',0700); symlink($temporary.'/outside',$voice->fixtureRoot);
        same('unavailable',$voice->synthesize($actor,'مرحبا')['state'],'A symlinked private spool is refused');
        same([],glob($temporary.'/outside/*')?:[],'Refused spool writes nothing outside its runtime');
        unlink($voice->fixtureRoot); mkdir($voice->fixtureRoot,0700);
        $stale=$voice->fixtureRoot.'/speech.'.str_repeat('a',32).'.wav';file_put_contents($stale,'stale');touch($stale,time()-180);
        $unrelated=$voice->fixtureRoot.'/unrelated.txt';file_put_contents($unrelated,'retain');touch($unrelated,time()-180);
        $result=$voice->synthesize($actor,'مرحبا');same('ready',$result['state'],'Subsequent inference recovers after timeout and refused spool');
        check(!file_exists($stale),'Stale generated output is cleaned');same('retain',file_get_contents($unrelated),'Cleanup preserves unrelated files');unlink($result['path']);
        $routes=file_get_contents(dirname(__DIR__,2).'/routes/hologram_arabic_voice.php');
        check(str_contains($routes,"Route::post('dashboard/hologram-arabic/speak'")&&str_contains($routes,"['web', 'lang', 'IsAdmin']")&&str_contains($routes,"throttle:12,1"),'Standalone endpoint retains POST/CSRF session/authentication/throttle contract');
        if ($engine=getenv('GO_ARABIC_ENGINE_DIR')) {
            copyEngineFixture($engine,$temporary.'/engine');
            copy(dirname(__DIR__,2).'/scripts/hologram_arabic/synthesize.py',$script);chmod($script,0600);
            $settings['home_hologram_arabic_voice.timeout_seconds']=8;
            $result=$voice->synthesize($actor,'العملاء 50,803 عميل. رصيد الفروع -39,421.05 ج.م');
            same('ready',$result['state'],'Actual PHP service invokes the pinned Arabic helper successfully');
            check(filesize($result['path'])>4410,'Actual Arabic speech contains a substantive verified waveform');
            unlink($result['path']);same([],$files(),'Actual response private audio can be deleted after delivery');
        }
        echo "GO Arabic voice PHP assertions passed: $checks\n";
    } finally { removeFixture($temporary); }
}
