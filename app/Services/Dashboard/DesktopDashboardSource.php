<?php
namespace App\Services\Dashboard;

/** Bind imported business data to the application code that will interpret it. */
class DesktopDashboardSource
{
    public const ROOTS=['app','bootstrap','config','database','desktop','resources','routes','public/dashboard/js','public/dashboard/css','public/dashboard/vendor/desktop-external'];
    public function fingerprint(): array
    {
        $files=[];$cases=[];$bytes=0;$base=realpath(base_path());
        foreach(self::ROOTS as $root){
            $directory=$base.'/'.$root;
            if(!is_dir($directory))continue;
            abort_if(is_link($directory),503,'مصدر البرنامج يحتاج التحقق قبل تجهيز الجهاز.');
            $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS));
            foreach($iterator as $file){
                $name=str_replace('\\','/',substr($file->getPathname(),strlen($base)+1));
                if(str_starts_with($name,'bootstrap/cache/')||preg_match('#(?:^|/)(?:\.env(?:\..*)?|firebase(?:_credentials)?\.json|service[-_]account[^/]*\.json|[^/]+\.(?:key|pem)|error_log)$#i',$name))continue;
                abort_if($file->isLink(),503,'مصدر البرنامج يحتوي رابطًا غير متوافق.');
                if(!preg_match('/\.(?:php|js|css|json|html|scss|vue)$/i',$name))continue;
                abort_unless($file->isFile()&&!$file->isLink()&&preg_match('#^[a-zA-Z0-9_.@/-]+$#D',$name)
                    &&!isset($cases[strtolower($name)]),503,'مصدر البرنامج يحتوي مسارًا غير متوافق.');
                $cases[strtolower($name)]=true;
                $content=file_get_contents($file->getPathname());abort_unless(is_string($content),503);
                $bytes+=strlen($content);abort_if($bytes>64*1024*1024||count($files)>=20000,503,'مصدر البرنامج تجاوز حد التحقق.');
                $files[$name]=hash('sha256',str_replace("\r\n","\n",$content));
            }
        }
        if(is_file($base.'/artisan')){
            abort_if(is_link($base.'/artisan'),503);$content=file_get_contents($base.'/artisan');abort_unless(is_string($content),503);
            $bytes+=strlen($content);abort_if($bytes>64*1024*1024||count($files)>=20000,503);
            $files['artisan']=hash('sha256',str_replace("\r\n","\n",$content));
        }
        abort_unless(count($files)>0,503);ksort($files,SORT_STRING);
        $digest=hash_init('sha256');hash_update($digest,"desktop-dashboard-source:1\n");
        foreach($files as $name=>$hash)hash_update($digest,$name."\0".$hash."\n");
        return ['format'=>1,'files'=>count($files),'sha256'=>hash_final($digest),'framework'=>app()->version()];
    }
    public function matches($value): bool
    {
        if(!is_array($value)||array_diff(array_keys($value),['format','files','sha256','framework'])
            ||($value['format']??null)!==1||!is_int($value['files']??null)||$value['files']<1||$value['files']>20000
            ||!is_string($value['framework']??null)||!is_string($value['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$value['sha256']))return false;
        $expected=$this->fingerprint();
        return $value['files']===$expected['files']&&$value['framework']===$expected['framework']&&hash_equals($expected['sha256'],$value['sha256']);
    }
}
