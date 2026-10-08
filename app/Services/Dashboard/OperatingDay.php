<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use DateTimeInterface;

/** Dashboard accounting days use Cairo wall time, from 06:00 inclusive to 06:00 exclusive. */
class OperatingDay
{
    public const TIMEZONE='Africa/Cairo';
    public const START_HOUR=6;

    public static function date(?DateTimeInterface $when=null): string
    {
        $local=$when?Carbon::instance($when)->setTimezone(self::TIMEZONE):now(self::TIMEZONE);
        if($local->hour<self::START_HOUR)$local->subDay();
        return $local->toDateString();
    }

    public static function start(?string $day=null): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d H:i:s',($day??self::date()).' 06:00:00',self::TIMEZONE);
    }

    public static function end(?string $day=null): Carbon {return self::start($day)->addDay();}

    public static function at(string $day,string $time): Carbon
    {
        $when=Carbon::createFromFormat('!Y-m-d H:i',$day.' '.substr($time,0,5),self::TIMEZONE);
        if($when->hour<self::START_HOUR)$when->addDay();
        return $when;
    }

    public static function metadata(): array
    {
        $day=self::date();
        return ['date'=>$day,'starts_at'=>self::start($day)->toIso8601String(),'ends_at'=>self::end($day)->toIso8601String(),
            'start_hour'=>self::START_HOUR,'timezone'=>self::TIMEZONE];
    }
}
