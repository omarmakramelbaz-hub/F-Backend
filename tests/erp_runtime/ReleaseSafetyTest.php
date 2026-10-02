<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ReleaseSafetyTest extends TestCase
{
    private function root(): string { return dirname(__DIR__, 2); }
    private function installer(): string { return file_get_contents($this->root().'/deployment/erp_install.sh'); }

    public function test_homepage_has_no_physical_asset_directory_collision(): void
    {
        $this->assertDirectoryDoesNotExist($this->root().'/public/erp');
        foreach (['css','js'] as $ext) {
            $this->assertFileExists($this->root().'/public/erp-assets/workspace.'.$ext);
        }
        foreach (['resources/views/erp/layout.blade.php','resources/views/erp/login.blade.php','tests/erp_runtime/demo/views/trial.blade.php'] as $file) {
            $text = file_get_contents($this->root().'/'.$file);
            $this->assertStringContainsString("asset('erp-assets/workspace.css')", $text);
            $this->assertStringNotContainsString("asset('erp/workspace.", $text);
        }
    }

    public function test_installer_is_valid_shell_and_runs_only_the_named_migrations(): void
    {
        $p = new Process(['bash','-n',$this->root().'/deployment/erp_install.sh']);
        $p->mustRun();
        $this->assertSame(0,$p->getExitCode());
        preg_match_all('/^safe_command php artisan migrate[^\r\n]*/m',$this->installer(),$matches);
        $this->assertSame([
            'safe_command php artisan migrate --force --path=database/migrations/2026_09_30_180000_create_erp_foundation.php',
            'safe_command php artisan migrate --force --path=database/migrations/2026_09_30_193000_create_erp_operations.php',
        ],$matches[0]);
        $this->assertStringNotContainsString('migrate:rollback',$this->installer());
        $this->assertStringNotContainsString('migrate:fresh',$this->installer());
        $this->assertStringNotContainsString('db:seed',$this->installer());
        $this->assertStringNotContainsString('reset --hard',$this->installer());
    }

    public function test_generated_php_helpers_have_valid_syntax(): void
    {
        preg_match_all("/<<'PHP'\n(.*?)\nPHP\n/s",$this->installer(),$matches);
        $this->assertCount(2,$matches[1]);
        foreach($matches[1] as $source) {
            $file=tempnam(sys_get_temp_dir(),'erp-release-lint-');
            try {
                file_put_contents($file,$source); chmod($file,0600);
                $p=new Process([PHP_BINARY,'-l',$file]); $p->mustRun();
                $this->assertSame(0,$p->getExitCode());
            } finally { unlink($file); }
        }
    }

    public function test_environment_transform_preserves_payment_settings_and_refuses_duplicates(): void
    {
        preg_match("/<<'PHP'\n(.*?)\nPHP\n/s",$this->installer(),$match);
        $helper=tempnam(sys_get_temp_dir(),'erp-release-env-');
        try {
            file_put_contents($helper,$match[1]); chmod($helper,0600);
            $code= <<<'CODE'
require $argv[1];
$values=['APP_DEBUG'=>'false','ERP_ENABLED'=>'true','ERP_OWNER_USER_ID'=>'1','SESSION_SECURE_COOKIE'=>'true'];
foreach(["\n","\r\n"] as $eol) {
    $original="APP_KEY=unchanged{$eol}PAYMOB_KEY=unchanged{$eol}GO_SERVICES_ENABLED=true{$eol}APP_DEBUG=true{$eol}";
    $out=patchErpEnvironment($original,$values);
    foreach(['APP_KEY=unchanged','PAYMOB_KEY=unchanged','GO_SERVICES_ENABLED=true','APP_DEBUG=false','ERP_OWNER_USER_ID=1'] as $line) {
        if(!str_contains($out,$line.$eol)) exit(1);
    }
    if(patchErpEnvironment($out,$values)!==$out) exit(2);
    if($eol==="\r\n" && preg_match('/(?<!\r)\n/',$out)) exit(3);
}
foreach(["APP_DEBUG=true\nAPP_DEBUG=false\n","APP_DEBUG=true\n export APP_DEBUG=false\n"] as $bad) {
    $rejected=false;try{patchErpEnvironment($bad,$values);}catch(RuntimeException $e){$rejected=true;}
    if(!$rejected) exit(4);
}
$rejected=false;try{patchErpEnvironment('', ['APP_KEY'=>'false']);}catch(RuntimeException $e){$rejected=true;}
if(!$rejected) exit(5);
echo 'ENVIRONMENT_SCOPE_OK';
CODE;
            $p=new Process([PHP_BINARY,'-r',$code,$helper]); $p->mustRun();
            $this->assertSame('ENVIRONMENT_SCOPE_OK',$p->getOutput());
        } finally { unlink($helper); }
    }
}
