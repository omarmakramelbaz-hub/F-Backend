<?php
// Synthetic central fixture: serve unchanged public assets and the real HTTP kernel.
// Storage stays in the disposable profile; private photographs have no public URL.
$application=getenv('DESKTOP_TEST_APPLICATION');
if(!$application||getenv('APP_ENV')!=='desktop'){http_response_code(403);exit;}
$root=realpath($application.'/public');$path=rawurldecode(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH));
$file=$root?realpath($root.$path):false;
if($file&&is_file($file)&&str_starts_with($file,$root.DIRECTORY_SEPARATOR)
    &&in_array(strtolower(pathinfo($file,PATHINFO_EXTENSION)),['js','css','png','jpg','jpeg','svg','ico','gif','webp','woff','woff2','ttf','eot','mp3','wav'],true))return false;
require __DIR__.'/server-router.php';
