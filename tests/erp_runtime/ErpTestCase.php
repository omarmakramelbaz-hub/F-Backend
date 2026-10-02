<?php

namespace ErpTests;

use App\Models\Erp\StaffUser;
use App\Services\Erp\Actor;
use App\Services\Erp\Decimal;
use App\Services\Erp\People;
use App\Services\Erp\Stock;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

abstract class ErpTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $root = dirname(__DIR__, 2);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('erp', ['enabled'=>true,'legacy_owner_id'=>1,'timezone'=>'Africa/Cairo']);
        $app['config']->set('auth.defaults.guard', 'admin');
        $app['config']->set('auth.guards.admin', ['driver'=>'session','provider'=>'legacy']);
        $app['config']->set('auth.providers.legacy', ['driver'=>'eloquent','model'=>LegacyOwner::class]);
        $app['config']->set('auth.guards.erp', ['driver'=>'session','provider'=>'erp_staff']);
        $app['config']->set('auth.providers.erp_staff', ['driver'=>'eloquent','model'=>StaffUser::class]);
        $app['config']->set('view.paths', [$root.'/resources/views']);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        // Existing non-ERP error templates read site branding from the settings package.
        $app->instance('App\\Models\\GeneralSettings', (object) ['site_name'=>'ERP Test','favicon'=>'']);
        if (getenv('ERP_TEST_MYSQL') === '1') {
            $app['config']->set('database.default', 'mysql');
            $app['config']->set('database.connections.mysql', ['driver'=>'mysql','host'=>'127.0.0.1','port'=>getenv('ERP_TEST_MYSQL_PORT') ?: 3306,'database'=>'erp_test','username'=>'root','password'=>getenv('ERP_TEST_MYSQL_PASSWORD') ?: '', 'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true]);
        } else {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite', ['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
        }
    }

    protected function defineRoutes($router)
    {
        Route::middleware('web')->group(function () { require dirname(__DIR__, 2).'/routes/erp.php'; });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 12:00:00', 'UTC'));
        // The MySQL runtime is deliberately fixed to an isolated CI-only database.
        $this->assertTrue(DB::connection()->getDriverName() === 'sqlite' || DB::connection()->getDatabaseName() === 'erp_test');
        Schema::dropAllTables();
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('account_type'); $t->string('password')->nullable(); $t->rememberToken(); $t->timestamps(); });
        Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->string('name'); });
        Schema::create('orders', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('resturant_id'); $t->string('order_no'); $t->string('status'); $t->string('type'); $t->string('payment_type'); $t->timestamp('created_at'); });
        require_once dirname(__DIR__, 2).'/database/migrations/2026_09_30_180000_create_erp_foundation.php';
        (new \CreateErpFoundation)->up();
        require_once dirname(__DIR__,2).'/database/migrations/2026_09_30_193000_create_erp_operations.php';
        (new \CreateErpOperations)->up();
        (new \App\Services\Erp\Ledger)->initialize(new Actor('legacy:1','المالك','owner',null,Actor::CAPABILITIES),'0',true);
        DB::table('users')->insert([['id'=>1,'name'=>'المالك','account_type'=>'admin'],['id'=>2,'name'=>'صاحب مطعم خارجي','account_type'=>'resturant_owner']]);
        DB::table('resturants')->insert([['id'=>1,'name'=>'فرع مدينة نصر'],['id'=>2,'name'=>'فرع المعادي'],['id'=>999,'name'=>'مطعم خارج ERP']]);
        DB::table('erp_branches')->insert([['id'=>1,'restaurant_id'=>1,'name'=>'مدينة نصر','active'=>1],['id'=>2,'restaurant_id'=>2,'name'=>'المعادي','active'=>1]]);
        DB::table('erp_warehouses')->insert([['id'=>1,'name'=>'المخزن المركزي','branch_id'=>null],['id'=>2,'name'=>'مخزن مدينة نصر','branch_id'=>1],['id'=>3,'name'=>'مخزن المعادي','branch_id'=>2]]);
        DB::table('erp_items')->insert([['id'=>1,'sku'=>'RAW-001','name'=>'فسيخ خام','unit'=>'kg','category'=>'raw','minimum_milli'=>5000,'active'=>1],['id'=>2,'sku'=>'PACK-001','name'=>'علبة تغليف','unit'=>'piece','category'=>'packaging','minimum_milli'=>10000,'active'=>1]]);
        foreach ([1,2] as $id) {
            DB::table('erp_employees')->insert(['id'=>$id,'branch_id'=>$id,'name'=>$id === 1 ? 'أحمد محمد':'محمود علي','phone'=>'01000000000','job_title'=>'مسؤول تجهيز','hired_on'=>'2026-08-15','salary_minor'=>600000,'active'=>1]);
            DB::table('erp_salary_rates')->insert(['employee_id'=>$id,'effective_month'=>'2026-08','salary_minor'=>600000]);
        }
        foreach (['pending','accepted','shipped','completed','cancelled','declined'] as $index=>$status) {
            DB::table('orders')->insert(['resturant_id'=>$index % 2 + 1,'order_no'=>'FS-'.(100+$index),'status'=>$status,'type'=>'current','payment_type'=>'cash','created_at'=>now()]);
        }
        DB::table('orders')->insert(['resturant_id'=>999,'order_no'=>'EXTERNAL-SECRET','status'=>'pending','type'=>'current','payment_type'=>'cash','created_at'=>now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function actor(string $role = 'deputy_manager', ?int $branch = null): Actor
    {
        return new Actor('staff:1','أدمن إداري',$role,$branch,Actor::defaults($role));
    }

    protected function staff(string $role = 'deputy_manager', ?int $branch = null): StaffUser
    {
        return StaffUser::create(['name'=>'أدمن إداري','email'=>'deputy@example.test','password'=>Hash::make('Test-only-password-123'),'role'=>$role,'branch_id'=>$branch,'permissions'=>Actor::defaults($role),'active'=>1]);
    }

    protected function stock(array $changes = []): array
    {
        return array_replace(['request_key'=>(string) \Illuminate\Support\Str::uuid(),'type'=>'opening','item_id'=>1,'warehouse_id'=>1,'quantity'=>'10.000','unit_cost'=>'200.00','reason'=>'رصيد بداية الفترة'], $changes);
    }

    protected function rejects(int $status, callable $fn): void
    {
        try { $fn(); $this->fail('Expected HTTP '.$status); }
        catch (HttpExceptionInterface $e) { $this->assertSame($status, $e->getStatusCode(), $e->getMessage()); }
    }

    protected function race(callable $operation, array $requests): array
    {
        DB::disconnect();
        $workers=[];
        foreach($requests as $request) {
            $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
            $pid=pcntl_fork();
            if ($pid === -1) { throw new \RuntimeException('Cannot fork concurrency test'); }
            if ($pid === 0) {
                fclose($pair[0]);
                foreach($workers as $worker) { fclose($worker['socket']); }
                fread($pair[1],1);
                DB::purge();
                try {
                    DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
                    $result=['status'=>'ok','id'=>$operation($request)];
                } catch (HttpExceptionInterface $e) { $result=['status'=>(string)$e->getStatusCode()]; }
                catch (\Throwable $e) { $result=['status'=>'error','message'=>$e->getMessage()]; }
                fwrite($pair[1],json_encode($result));fclose($pair[1]);exit(0);
            }
            fclose($pair[1]);stream_set_timeout($pair[0],15);
            $workers[]=['pid'=>$pid,'socket'=>$pair[0]];
        }
        foreach($workers as $worker) { fwrite($worker['socket'],'1'); }
        $results=[];
        foreach($workers as $worker) {
            $results[]=json_decode(stream_get_contents($worker['socket']),true) ?? ['status'=>'timeout'];
            fclose($worker['socket']);pcntl_waitpid($worker['pid'],$status);
            $this->assertSame(0,pcntl_wexitstatus($status));
        }
        DB::purge();
        return $results;
    }
}
