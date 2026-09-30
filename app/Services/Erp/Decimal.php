<?php

namespace App\Services\Erp;

class Decimal
{
    public static function scaled($value, int $places, int $max = 100000000): int
    {
        $value = trim((string) $value);
        abort_unless(preg_match('/^\d{1,9}(?:\.\d{1,'.$places.'})?$/D', $value), 422, 'أدخل قيمة موجبة بدقة صحيحة.');
        $parts = explode('.', $value);
        $n = (int) $parts[0] * (10 ** $places) + (int) str_pad($parts[1] ?? '', $places, '0');
        abort_if($n > $max, 422, 'القيمة تتجاوز الحد المسموح للعملية.');
        return $n;
    }

    public static function money($value): int
    {
        return self::scaled($value, 2);
    }

    public static function quantity($value, string $unit): int
    {
        $n = self::scaled($value, 3);
        abort_if($unit === 'piece' && $n % 1000 !== 0, 422, 'الصنف المباع بالقطعة يحتاج عددًا صحيحًا.');
        return $n;
    }

    public static function format(int $value, int $places = 2): string
    {
        $factor = 10 ** $places;
        return ($value < 0 ? '-' : '').intdiv(abs($value), $factor).'.'.str_pad((string) (abs($value) % $factor), $places, '0', STR_PAD_LEFT);
    }
}
