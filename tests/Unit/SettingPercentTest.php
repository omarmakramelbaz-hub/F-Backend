<?php

namespace Tests\Unit;

use App\Support\SettingPercent;
use PHPUnit\Framework\TestCase;

class SettingPercentTest extends TestCase
{
    public function test_whole_percentages_read_as_before(): void
    {
        foreach (['14' => 14.0, '"14"' => 14.0, '14%' => 14.0, ' 5 ' => 5.0, '0' => 0.0] as $payload => $rate) {
            $this->assertSame($rate, SettingPercent::parse($payload), $payload);
        }
        $this->assertSame(10.0, SettingPercent::parse(10));
    }

    public function test_a_decimal_keeps_its_point(): void
    {
        // FILTER_SANITIZE_NUMBER_INT read "2.5" as 25.
        $this->assertSame(2.5, SettingPercent::parse('"2.5"'));
        $this->assertSame(2.5, SettingPercent::parse('2.5%'));
    }

    public function test_nothing_reads_zero(): void
    {
        $this->assertSame(0.0, SettingPercent::parse(null));
        $this->assertSame(0.0, SettingPercent::parse(''));
        $this->assertSame(0.0, SettingPercent::parse('بدون'));
    }
}
