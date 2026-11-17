<?php

namespace App\Support;

/**
 * A percentage setting (service_fees, tax) as the number written on the dashboard: "14", "14%", "\"2.5\"" read 14, 14 and
 * 2.5. It used to be read with FILTER_SANITIZE_NUMBER_INT, which drops the decimal point (2.5 became 25) while the
 * customer app showed 2.5.
 */
class SettingPercent
{
    public static function parse($payload): float
    {
        if (is_int($payload) || is_float($payload)) return max(0.0, (float) $payload);
        if (!preg_match('/\d+(?:\.\d+)?/', (string) $payload, $match)) return 0.0;
        return (float) $match[0];
    }
}
