<?php

// Installation switches only. Runtime channel reply authority remains in MetaAiControl.
// The live comment adapter uses this model and ANTHROPIC_API_KEY, verified locally.
return [
    'comments_enabled' => true,
    'messages_enabled' => true,
    'messages_model' => env('FASAKHANSTA_CLAUDE_MODEL', 'claude-haiku-5-5'),
    'messages_api_key' => env('FASAKHANSTA_CLAUDE_API_KEY',
        env('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: null)),
];
