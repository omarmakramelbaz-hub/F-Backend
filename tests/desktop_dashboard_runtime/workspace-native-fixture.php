<?php
/** Shared private boundary for the disposable native-to-real-server acceptance fixture. */
final class WorkspaceNativeServerFixture
{
    public const MARKER="GO_ERP_DISPOSABLE_SHARED_WORKSPACE_ONLY\n";
    public const MARKER_FILE='disposable-workspace-native.marker';

    public static function need(bool $ok,string $code='WORKSPACE_NATIVE_FIXTURE_INVALID'): void
    {
        if(!$ok)throw new RuntimeException($code);
    }
    public static function path(string $path): string {return str_replace('\\','/',$path);}
    public static function root(string $input): string
    {
        $root=realpath($input);self::need(is_string($root)&&is_dir($root)&&!is_link($input));$root=self::path($root);
        self::need((bool)preg_match('/^go-erp-workspace-native-[a-f0-9]{16}$/D',basename($root)));
        $marker=$root.'/'.self::MARKER_FILE;
        self::need(is_file($marker)&&!is_link($marker)&&filesize($marker)===strlen(self::MARKER)
            &&file_get_contents($marker)===self::MARKER);
        if(DIRECTORY_SEPARATOR==='/')self::need((fileperms($root)&0077)===0);
        return $root;
    }
    public static function origin(string $origin): array
    {
        $parts=parse_url($origin);
        self::need(is_array($parts)&&($parts['scheme']??null)==='https'&&in_array($parts['host']??null,['127.0.0.1','localhost'],true)
            &&isset($parts['port'])&&$parts['port']>=1024&&$parts['port']<=65535
            &&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['query'])&&!isset($parts['fragment'])
            &&(!isset($parts['path'])||$parts['path']===''));
        return $parts;
    }
    public static function config(string $file): array
    {
        self::need(basename(self::path($file))==='server-config.json'&&is_file($file)&&!is_link($file)&&filesize($file)<=65536);
        $root=self::root(dirname($file));self::need(self::path((string)realpath($file))===$root.'/server-config.json');
        $config=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
        self::need(is_array($config)&&($config['format']??null)===2&&($config['kind']??null)==='go-erp-workspace-native-server'
            &&($config['root']??null)===$root&&($config['markerSha256']??null)===hash('sha256',self::MARKER)
            &&($config['database']??null)==='desktop_workspace_native_'.substr(basename($root),-16)
            &&is_int($config['dbPort']??null)&&$config['dbPort']>=1024&&$config['dbPort']<=65535
            &&($config['serverStorage']??null)===$root.'/server-storage'
            &&is_string($config['application']??null)&&self::path((string)realpath($config['application']))===$config['application']
            &&is_file($config['application'].'/desktop/bootstrap.php')&&is_array($config['environment']??null)
            &&is_string($config['sourceRevision']??null)&&(bool)preg_match('/^[a-f0-9]{40}$/D',$config['sourceRevision']));
        self::origin($config['origin']??'');
        self::need(($config['environment']['DB_DATABASE']??null)===$config['database']
            &&($config['environment']['DB_HOST']??null)==='127.0.0.1'
            &&($config['environment']['DB_PORT']??null)===(string)$config['dbPort']
            &&($config['environment']['DESKTOP_DASHBOARD_STORAGE']??null)===$config['serverStorage']
            &&($config['environment']['APP_URL']??null)===$config['origin']);
        return $config;
    }
    public static function environment(array $config): void
    {
        $allowed=['DESKTOP_DASHBOARD_LOCAL','DESKTOP_DASHBOARD_ENABLED','DESKTOP_DASHBOARD_WORKSPACE_ENABLED',
            'DESKTOP_DASHBOARD_STORAGE','DESKTOP_DASHBOARD_WORKSPACE_LOCAL','APP_ENV','APP_DEBUG','APP_KEY','APP_URL',
            'DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','CACHE_DRIVER','SESSION_DRIVER',
            'SESSION_SECURE_COOKIE','SESSION_DOMAIN','QUEUE_CONNECTION','MAIL_MAILER','APP_CONFIG_CACHE','APP_PACKAGES_CACHE',
            'APP_SERVICES_CACHE','APP_ROUTES_CACHE','VIEW_COMPILED_PATH'];
        self::need(array_diff(array_keys($config['environment']),$allowed)===[]);
        foreach($config['environment'] as $key=>$value){self::need(is_string($value)&&!str_contains($value,"\0"));putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
    }
    public static function boot(array $config)
    {
        self::environment($config);$app=require $config['application'].'/desktop/bootstrap.php';
        config(['desktop_dashboard.local'=>false,'auth.providers.admins.driver'=>'eloquent','app.url'=>$config['origin']]);
        auth()->forgetGuards();return $app;
    }
    public static function pdo(array $config,bool $database=true): PDO
    {
        return new PDO('mysql:host=127.0.0.1;port='.$config['dbPort'].($database?';dbname='.$config['database']:'').';charset=utf8mb4',
            $config['environment']['DB_USERNAME'],$config['environment']['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    }
    public static function assertDatabase(PDO $pdo,array $config): void
    {
        $row=$pdo->query('SELECT root_sha256,marker_sha256,source_revision FROM go_erp_workspace_native_fixture')->fetch(PDO::FETCH_ASSOC);
        self::need(is_array($row)&&$row===['root_sha256'=>hash('sha256',$config['root']),
            'marker_sha256'=>hash('sha256',self::MARKER),'source_revision'=>$config['sourceRevision']]);
    }
    public static function removeStorage(array $config): void
    {
        $path=$config['serverStorage'];if(!file_exists($path))return;
        self::need(!is_link($path)&&self::path((string)realpath($path))===$config['root'].'/server-storage');
        $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($entries as $entry){self::need(!$entry->isLink());if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($path);
    }
}
