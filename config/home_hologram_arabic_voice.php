<?php

return [
    // This private helper and its engine are installed by the bounded package.
    'command' => ['/usr/bin/python3', '-I', '-B', '/usr/local/lib/go-hologram-arabic/synthesize.py'],
    'lock_path' => '/var/lib/go-hologram-arabic/speech.lock',
    'timeout_seconds' => 8,
];
