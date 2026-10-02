<?php
namespace ErpTests;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class BackupTest extends ErpTestCase
{
    public function test_operator_backup_is_private_complete_and_read_only(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') { $this->markTestSkipped('Real dump verification runs against isolated MySQL only.'); }
        require_once dirname(__DIR__,2).'/deployment/erp_backup.php';
        $base=sys_get_temp_dir().'/erp-backup-test-'.bin2hex(random_bytes(8));
        $root=$base.'/app';
        mkdir($root.'/vendor',0700,true);
        mkdir($root.'/storage/app/public',0700,true);
        mkdir($root.'/storage/logs',0700,true);
        file_put_contents($root.'/artisan','synthetic CLI fixture');
        file_put_contents($root.'/.env','TEST_ONLY=synthetic-backup-secret');
        file_put_contents($root.'/vendor/autoload.php','synthetic dependency fixture');
        file_put_contents($root.'/storage/app/public/upload.txt','synthetic upload');
        file_put_contents($root.'/storage/logs/test.log','transient log');
        $run=static function (array $args) use($root) {
            $p=new Process($args,$root);$p->mustRun();return trim($p->getOutput());
        };
        try {
            $run(['git','init','--quiet']);$run(['git','add','.']);
            $run(['git','-c','user.name=ERP CI','-c','user.email=erp@example.test','commit','--quiet','-m','backup fixture']);
            file_put_contents($root.'/artisan','preserved uncommitted local edit');
            $before=DB::table('erp_journals')->count();
            $result=\Fasakhansta\ErpRelease\createBackup($root);
            $directory=$result['backup_directory'];
            $this->assertTrue($result['tracked_local_edits']);
            $this->assertFileExists($directory.'/backup.ok');
            $this->assertFileDoesNotExist($directory.'/mysql-client.cnf');
            $this->assertSame(0700,fileperms($directory)&0777);
            $this->assertSame(0600,fileperms($directory.'/database.sql.gz')&0777);
            $this->assertSame($before,DB::table('erp_journals')->count());
            $this->assertSame($result['database_sha256'],hash_file('sha256',$directory.'/database.sql.gz'));
            $this->assertSame($result['application_sha256'],hash_file('sha256',$directory.'/application.tar.gz'));
            $this->assertSame('preserved uncommitted local edit',$run(['tar','-xOzf',$directory.'/application.tar.gz','./artisan']));
            $this->assertSame('synthetic upload',$run(['tar','-xOzf',$directory.'/application.tar.gz','./storage/app/public/upload.txt']));
            $sql=$run(['gzip','-dc',$directory.'/database.sql.gz']);
            $this->assertStringContainsString('CREATE TABLE `erp_stock_documents`',$sql);
            $this->assertStringContainsString('INSERT INTO `erp_branches`',$sql);
            $manifest=file_get_contents($directory.'/manifest.json');
            $this->assertStringNotContainsString('synthetic-backup-secret',$manifest);
            $this->assertStringNotContainsString('test-only',$manifest);
            $this->assertSame('synthetic CLI fixture',$run(['git','show','HEAD:artisan']));
            symlink(sys_get_temp_dir(),$root.'/external');
            try {
                \Fasakhansta\ErpRelease\createBackup($root);
                $this->fail('External data must not be silently excluded');
            } catch (\Fasakhansta\ErpRelease\BackupFailure $e) {
                $this->assertStringContainsString('symbolic links',$e->getMessage());
            }
            $this->assertCount(1,glob($base.'/erp-release-backups/*/backup.ok'));
        } finally {
            // Only this generated test workspace; never a supplied application path.
            $cleanup=new Process(['rm','-rf','--',$base]);$cleanup->mustRun();
        }
    }
}
