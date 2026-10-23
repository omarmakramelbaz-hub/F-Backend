<?php

/** A bounded support report. Exception messages, paths and trace arguments are private by default. */
final class DesktopImportDiagnostic
{
    // Only reviewed literal abort messages may cross the native support boundary.
    // Keep this map in sync with the defensive native parser in local-runtime.cjs.
    private const MESSAGES = [
        'DesktopDashboardImport.php' => [
            409 => [
                'نسخة برنامج الجهاز لا تطابق بيانات السيرفر؛ النسخة الحالية وسجلاتها محفوظة.',
                'قاعدة التجهيز ليست فارغة؛ بيانات الجهاز محفوظة.',
                'جدول مرتبط غير موجود في نسخة الحساب.',
                'نسخة الحساب تحتوي على مرجع ناقص؛ لم تُفعّل قاعدة التجهيز.',
            ],
            422 => [
                'مخطط قاعدة التجهيز غير مقبول.',
                'بيانات التجهيز غير مكتملة.',
                'بصمة مخطط التجهيز مختلفة.',
                'وصف تغطية نطاقات الكتالوج غير صالح.',
                'بيانات نطاقات الكتالوج أو فروع الجهاز غير متطابقة.',
                'فروع نسخة التجهيز أو نطاقاتها غير متطابقة.',
                'بيانات حساب مستخدم الجهاز مفقودة أو مكررة.',
            ],
        ],
    ];

    private static function normalized(string $path): ?string
    {
        $path=str_replace(chr(92),'/',$path);
        if(str_contains($path,chr(0))||!preg_match('~^(?:/|[a-zA-Z]:/)~',$path))return null;
        foreach(explode('/',$path) as $part)if($part==='.'||$part==='..')return null;
        return rtrim($path,'/');
    }

    private static function portableFile(string $path): ?string
    {
        $name=basename(str_replace(chr(92),'/',$path));
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,119}$/D',$name)?$name:null;
    }

    /** Trace order identifies the Dashboard caller of abort(), before its vendor throw site. */
    public static function location(string $file,int $line,array $trace,string $applicationRoot): array
    {
        $root=self::normalized($applicationRoot);
        if($root!==null){
            $prefix=$root.'/app/Services/Dashboard/';
            $windows=preg_match('~^(?:[a-zA-Z]:/|//)~',$root)===1;
            foreach($trace as $frame){
                if(!is_array($frame)||!is_string($frame['file']??null)||!is_int($frame['line']??null)
                    ||$frame['line']<1||$frame['line']>10000000)continue;
                $path=self::normalized($frame['file']);
                if($path===null||strlen($path)<=strlen($prefix)
                    ||($windows?strncasecmp($path,$prefix,strlen($prefix)):strncmp($path,$prefix,strlen($prefix)))!==0)continue;
                $name=self::portableFile($path);
                if($name!==null)return ['file'=>$name,'line'=>$frame['line']];
            }
        }
        return ['file'=>self::portableFile($file)??'unknown.php','line'=>$line>0&&$line<=10000000?$line:1];
    }

    private static function phpProfile($value,string $scope,array $sapis): ?array
    {
        if(!is_array($value)||($value['capture_scope']??null)!==$scope
            ||!in_array($value['php_sapi']??null,$sapis,true)
            ||!is_string($value['php_version']??null)
            ||!preg_match('/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}[-A-Za-z0-9.]{0,24}$/D',$value['php_version']))return null;
        foreach(['serialize_precision','precision'] as $name)
            if(!is_string($value[$name]??null)||!preg_match('/^(?:-1|0|[1-9][0-9]{0,3})$/D',$value[$name]))return null;
        return ['capture_scope'=>$scope,'php_sapi'=>$value['php_sapi'],'php_version'=>$value['php_version'],
            'serialize_precision'=>$value['serialize_precision'],'precision'=>$value['precision']];
    }

    /** Safe request/import settings shared by successful and failed imports. */
    public static function profiles($snapshot=null): array
    {
        // Server settings originate in the real snapshot export request. Never
        // infer the website's settings from a separate CLI or private FPM probe.
        $server=self::phpProfile(is_array($snapshot)?($snapshot['transport_php']??null):null,'snapshot_export',
            ['fpm-fcgi','cgi-fcgi','apache2handler','litespeed','cli']);
        $desktop=self::phpProfile(['capture_scope'=>'desktop_import','php_sapi'=>PHP_SAPI,'php_version'=>PHP_VERSION,
            'serialize_precision'=>ini_get('serialize_precision'),'precision'=>ini_get('precision')],'desktop_import',['cli']);
        // A missing or rejected server profile must not hide the actual desktop
        // settings. Statuses are fixed codes; rejected values remain private.
        $profiles=[
            'server_profile_status'=>$server!==null?'valid':(is_array($snapshot)&&array_key_exists('transport_php',$snapshot)?'invalid':'missing'),
            'desktop_profile_status'=>$desktop!==null?'valid':'invalid',
        ];
        if($server!==null)$profiles['server']=$server;
        if($desktop!==null)$profiles['desktop']=$desktop;
        return $profiles;
    }

    public static function report(Throwable $error,string $phase,string $applicationRoot,$snapshot=null): array
    {
        $location=self::location($error->getFile(),$error->getLine(),$error->getTrace(),$applicationRoot);
        $type=get_class($error);
        $characters='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_'.chr(92);
        if(strlen($type)<1||strlen($type)>160||strspn($type,$characters)!==strlen($type))$type='Throwable';
        $status=$error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$error->getStatusCode():null;
        if(!is_int($status)||$status<400||$status>599)$status=null;
        $report=['phase'=>in_array($phase,['input','json','bootstrap','import'],true)?$phase:'import',
            'type'=>$type,'file'=>$location['file'],'line'=>$location['line'],'status'=>$status];
        if($status!==null&&in_array($error->getMessage(),self::MESSAGES[$location['file']][$status]??[],true))
            $report['message']=$error->getMessage();
        $report['php_profiles']=self::profiles($snapshot);
        $server=$report['php_profiles']['server']??null;$desktop=$report['php_profiles']['desktop']??null;
        // Preserve the previous diagnostic contract for paired valid profiles.
        if($server!==null&&$desktop!==null)$report['php_comparison']=[
            'server'=>$server,'desktop'=>$desktop,
            'server_web_request'=>$server['php_sapi']!=='cli',
            'same_serialize_precision'=>$server['serialize_precision']===$desktop['serialize_precision']];
        return $report;
    }
}
