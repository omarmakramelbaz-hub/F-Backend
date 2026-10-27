<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardWorkspaceAuthority as Authority,DesktopDashboardWorkspaceMedia as Media};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Crypt,DB,Schema};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Persisted authority and original media engine; isolated SQL and real temporary image/PDF bytes. */
class DesktopDashboardWorkspaceMediaTest extends TestCase
{
    private string $connection='sqlite';
    private string $originalStorage;
    private string $mediaStorage;
    private const WORKSPACE='11111111-1111-4111-8111-111111111111';
    private const SNAPSHOT='22222222-2222-4222-8222-222222222222';
    private const TABLES=['desktop_dashboard_workspace_members','desktop_dashboard_workspace_manifests',
        'desktop_dashboard_workspaces','role_has_permissions','model_has_roles','model_has_permissions','roles','permissions',
        'branch_expenses','media','resturants','users'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection=env('TAKEAWAY_TEST_CONNECTION','sqlite');
        if(!in_array($this->connection,['sqlite','mysql'],true))throw new \RuntimeException('Unsupported isolated database');
        if($this->connection==='mysql'&&config('database.connections.mysql.database')!=='takeaway_test')
            throw new \RuntimeException('Requires isolated takeaway_test');
        config(['database.default'=>$this->connection,'cache.default'=>'array','app.key'=>'base64:'.base64_encode(str_repeat('m',32)),
            'desktop_dashboard.enabled'=>true,'desktop_dashboard.workspace_enabled'=>true,'desktop_dashboard.local'=>false,
            'media-library.path_generator'=>\Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator::class]);
        if($this->connection==='sqlite')config(['database.connections.sqlite.database'=>':memory:']);
        DB::purge($this->connection);Schema::clearResolvedInstance('db.schema');
        if($this->connection==='mysql')$this->dropFixtures();
        $this->originalStorage=storage_path();$this->mediaStorage=sys_get_temp_dir().'/go-erp-workspace-media-feature-'.bin2hex(random_bytes(8));
        app()->useStoragePath($this->mediaStorage);
        mkdir(storage_path('app/public'),0700,true);mkdir(storage_path('app/branch-expenses'),0700,true);
        config(['filesystems.disks.public.driver'=>'local','filesystems.disks.public.root'=>storage_path('app/public'),
            'filesystems.disks.local.driver'=>'local','filesystems.disks.local.root'=>storage_path('app')]);
        Schema::create('users',function(Blueprint $t){
            $t->id();$t->string('name');$t->string('email')->nullable();$t->string('mobile')->nullable();$t->string('password');
            $t->string('remember_token')->nullable();$t->string('account_type');$t->string('app_scope')->nullable();
            $t->string('status')->default('accepted');$t->unsignedBigInteger('owner_resturant_id')->nullable();
            $t->unsignedBigInteger('pending_vendor_id')->nullable();$t->timestamps();
        });
        Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');});
        Schema::create('media',function(Blueprint $t){$t->id();$t->string('model_type');$t->unsignedBigInteger('model_id');$t->string('file_name');$t->string('disk');$t->string('conversions_disk')->nullable();});
        Schema::create('branch_expenses',function(Blueprint $t){$t->id();$t->string('branch');$t->string('attachment_path')->nullable();$t->char('attachment_hash',64)->nullable();$t->string('attachment_mime')->nullable();});
        require_once database_path('migrations/2022_08_05_174522_create_permission_tables.php');
        (new \CreatePermissionTables)->up();
        require_once database_path('migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php');
        (new \CreateDesktopDashboardWorkspaces)->up();
        foreach([[10,'accepted'],[11,'accepted'],[40,'disabled']] as [$id,$status])DB::table('users')->insert([
            'id'=>$id,'name'=>'Vendor '.$id,'email'=>'vendor'.$id.'@example.test','mobile'=>(string)(1000000000+$id),
            'password'=>password_hash('synthetic-password-'.$id,PASSWORD_BCRYPT,['cost'=>4]),'account_type'=>'vendor',
            'app_scope'=>'fasakhansta','status'=>$status,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        DB::table('resturants')->insert([
            ['id'=>100,'user_id'=>10,'name'=>'A'],['id'=>101,'user_id'=>11,'name'=>'B'],['id'=>999,'user_id'=>40,'name'=>'Disabled C'],
        ]);
        foreach([[1,100,'a.png'],[2,101,'b.png'],[3,999,'c.png']] as [$id,$owner,$name]){
            DB::table('media')->insert(['id'=>$id,'model_type'=>'App\\Models\\Resturant','model_id'=>$owner,'file_name'=>$name,'disk'=>'public','conversions_disk'=>'public']);
            $this->putAsset('app/public/'.$id.'/'.$name,$this->png());
        }
        $this->putAsset('app/public/2/conversions/thumb.png',$this->png());
        $this->putAsset('app/branch-expenses/b.pdf',$this->pdf());
        DB::table('branch_expenses')->insert(['id'=>27,'branch'=>'f:101','attachment_path'=>'branch-expenses/b.pdf',
            'attachment_hash'=>hash('sha256',$this->pdf()),'attachment_mime'=>'application/pdf']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        if(isset($this->mediaStorage)&&is_dir($this->mediaStorage))$this->erase($this->mediaStorage);
        if(isset($this->originalStorage))app()->useStoragePath($this->originalStorage);
        if($this->connection==='mysql'){$this->dropFixtures();DB::disconnect('mysql');}
        parent::tearDown();
    }
    private function dropFixtures(): void
    {
        Schema::disableForeignKeyConstraints();foreach(self::TABLES as $table)Schema::dropIfExists($table);Schema::enableForeignKeyConstraints();
    }
    private function png(): string {return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB5kAAAAASUVORK5CYII=');}
    private function pdf(): string {return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";}
    private function putAsset(string $path,string $bytes): void {$name=storage_path($path);if(!is_dir(dirname($name)))mkdir(dirname($name),0700,true);file_put_contents($name,$bytes);}
    private function erase(string $path): void
    {
        if(is_link($path)||is_file($path)){unlink($path);return;}
        foreach(new \DirectoryIterator($path) as $entry)if(!$entry->isDot())$this->erase($entry->getPathname());rmdir($path);
    }
    private function root(): object
    {
        $authority=app(Authority::class);$enrolled=$authority->enroll(['device_id'=>self::WORKSPACE,'name'=>'Shared native PC','nonce'=>str_repeat('a',64)],User::withoutGlobalScopes()->findOrFail(10));
        return $authority->authenticate($enrolled['token']);
    }
    private function manifest(object $root,array $dataset): array
    {
        return DB::transaction(function()use($root,$dataset){
            $audience=app(Authority::class)->snapshotAudience($root);
            return app(Media::class)->manifestForAudience($dataset,$audience,self::SNAPSHOT);
        });
    }
    private function refused(callable $work,int $status): void
    {
        try{$work();$this->fail('Invalid workspace media authority was accepted.');}
        catch(HttpException $error){$this->assertSame($status,$error->getStatusCode());}
    }

    public function test_vendor_custodian_media_covers_other_valid_member_and_denies_disabled_foreign_branch(): void
    {
        $root=$this->root();$this->assertSame(10,(int)$root->custodian_actor_id);
        $rows=DB::table('media')->whereIn('id',[1,2])->get()->map(fn($row)=>(array)$row)->all();
        $result=$this->manifest($root,['media'=>$rows]);$files=array_column($result['files'],null,'path');
        $this->assertTrue($result['complete']);
        $this->assertSame(['1/a.png','2/b.png','2/conversions/thumb.png'],array_keys($files));
        $this->assertSame($this->png(),app(Media::class)->downloadWorkspace($root,$files['2/b.png']['ticket'])['bytes']);
        $payload=json_decode(Crypt::decryptString($files['2/b.png']['ticket']),true,32,JSON_THROW_ON_ERROR);
        $this->assertSame(2,$payload['format']);$this->assertSame('desktop-workspace-media-ticket',$payload['kind']);
        $this->assertSame(self::WORKSPACE,$payload['workspace_id']);$this->assertSame(self::SNAPSHOT,$payload['snapshot_id']);
        foreach(['actor_id','grant','password_binding','members'] as $private)$this->assertArrayNotHasKey($private,$payload);
        $foreign=(array)DB::table('media')->where('id',3)->first();$denied=$this->manifest($root,['media'=>[$foreign]]);
        $this->assertFalse($denied['complete']);$this->assertSame([],$denied['files']);
        DB::table('users')->where('id',11)->update(['status'=>'disabled']);
        $this->refused(fn()=>app(Media::class)->downloadWorkspace($root,$files['2/b.png']['ticket']),403);
    }

    public function test_private_b_attachment_requires_fresh_union_rows_exact_bytes_and_physical_root(): void
    {
        $root=$this->root();$expense=(array)DB::table('branch_expenses')->where('id',27)->first();
        $result=$this->manifest($root,['branch_expenses'=>[$expense]]);$this->assertTrue($result['complete']);
        $this->assertCount(1,$result['files']);$file=$result['files'][0];$this->assertSame('private',$file['area']);
        $this->assertSame($this->pdf(),app(Media::class)->downloadWorkspace($root,$file['ticket'])['bytes']);
        DB::table('branch_expenses')->where('id',27)->update(['branch'=>'f:999']);
        $this->refused(fn()=>app(Media::class)->downloadWorkspace($root,$file['ticket']),403);
        DB::table('branch_expenses')->where('id',27)->update(['branch'=>'f:101']);
        $this->putAsset('app/branch-expenses/b.pdf',$this->pdf().' changed');
        $this->refused(fn()=>app(Media::class)->downloadWorkspace($root,$file['ticket']),409);
        $this->putAsset('app/branch-expenses/b.pdf',$this->pdf());
        DB::table('desktop_dashboard_workspaces')->where('id',self::WORKSPACE)->update(['enrollment_hash'=>str_repeat('c',64)]);
        $this->refused(fn()=>app(Media::class)->downloadWorkspace($root,$file['ticket']),401);
    }
}
