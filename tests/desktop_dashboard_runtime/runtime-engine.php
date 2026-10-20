<?php

/** Read the actual installed engine independently of the captured source contract. */
final class DesktopRuntimeEngineProof
{
    public static function expected(string $application): string
    {
        $lock=json_decode(file_get_contents($application.'/composer.lock'),true,512,JSON_THROW_ON_ERROR);
        $installed=json_decode(file_get_contents($application.'/vendor/composer/installed.json'),true,512,JSON_THROW_ON_ERROR);
        $version=function(array $packages): string {
            foreach($packages as $package)if(($package['name']??null)==='laravel/framework')return ltrim($package['version']??'','v');
            throw new RuntimeException('Installed Laravel framework metadata is missing.');
        };
        $locked=$version($lock['packages']);$actual=$version($installed['packages']??$installed);
        if($locked!==$actual||!in_array($actual,['8.83.29','12.70.0'],true))throw new RuntimeException('The actual installed framework does not match the reviewed engine lock.');
        return $actual;
    }
}
