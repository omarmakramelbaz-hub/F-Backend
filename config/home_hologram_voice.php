<?php
return [
    'ticket_ttl_seconds' => 1800,
    // Exact-display-bound phonetic overrides, never a different speaker/model.
    // 'central' => ['123' => ['display_name' => 'أحمد محمد', 'spoken_name' => 'Ahmed Mohamed']]
    // Tenant scopes are 'tenant:'.$trustedRuntimeId. The same structure may live
    // privately in storage/app/go-hologram-voice/pronunciations.json.
    'pronunciations' => [],
];
