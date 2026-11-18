<?php

return [
    // Existing dashboard WhatsApp credentials/account are reused; no second sender or AI bot.
    'enabled' => env('CRM_WHATSAPP_ENABLED', false),
    'feedback_enabled' => false,
    'emergency_disabled' => env('CRM_WHATSAPP_EMERGENCY_DISABLED', false),
    'feedback_template_name' => (string) env('CRM_WHATSAPP_FEEDBACK_TEMPLATE', ''),
    'feedback_language' => (string) env('CRM_WHATSAPP_FEEDBACK_LANGUAGE', 'ar'),
    'feedback_body' => "رأيك يهمنا في المنتج والخدمة المقدمة، يُرجى تقديم ملاحظات حضرتك في هذه الرسالة لتحسين جودة المنتج والخدمة.\nطلب رقم {{1}} – فرع {{2}}",
    'feedback_parameters' => ['source_id', 'branch'],
    // These are local throttle bounds, not claims about Meta account messaging limits.
    'batch_size' => 25,
    'per_minute' => 25,
    'campaign_max_recipients' => 50000,
    'campaign_expiry_hours' => 72,
    'feedback_expiry_hours' => 168,
    'marketing_cooldown_hours' => 24,
    'rate_limit_backoff_seconds' => 300,
    'max_rate_limit_attempts' => 3,
];
