<?php

namespace App\Support;

/**
 * A quantity as people type it, as the site reads it: Arabic or Persian digits, "." or "٫" for the fraction ("," and
 * "،" too, unless the number could be thousands, like 1,500), and a fraction without its leading zero (".5" is half a
 * kilo). Anything else is left as it is, for the caller's own check to refuse.
 */
final class Quantity
{
    public static function text($value): string
    {
        $text = trim((string) $value);
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.']);
        if (preg_match('/^\d{0,7}[,،]\d{1,3}$/uD', $text) && !preg_match('/^[1-9]\d{0,2}[,،]\d{3}$/uD', $text)) $text = preg_replace('/[,،]/u', '.', $text, 1);
        return preg_match('/^\.\d/', $text) ? '0'.$text : $text;
    }
}
