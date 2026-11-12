<?php

namespace Tests\Feature;

use App\Services\App\AppUpdatePolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** The customer app's forced update: set on the dashboard, read by the app at GET /api/app-version. */
class AppUpdatePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite'); Schema::clearResolvedInstance('db.schema');
        \App\Database\SchemaMemory::forgetEverything();
        Schema::create('settings', function (Blueprint $t) { $t->id(); $t->string('group'); $t->string('name'); $t->boolean('locked')->default(false); $t->longText('payload'); $t->timestamps(); });
    }

    public function test_nothing_set_forces_no_update(): void
    {
        $this->getJson('/api/app-version')->assertOk()->assertJsonPath('data.android.min_version', null)
            ->assertJsonPath('data.ios.min_version', null)->assertJsonPath('data.message', AppUpdatePolicy::DEFAULT_MESSAGE);
    }

    public function test_the_dashboard_sets_the_lowest_version_and_the_app_reads_it(): void
    {
        app(AppUpdatePolicy::class)->save(['android_min_version' => '1.0.55', 'ios_min_version' => '2.0.7',
            'android_store_url' => 'https://play.google.com/store/apps/details?id=com.faskhaninja.clients',
            'ios_store_url' => 'https://apps.apple.com/eg/app/fasakhansta/id123', 'message' => '']);
        $this->getJson('/api/app-version')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.android', ['min_version' => '1.0.55', 'store_url' => 'https://play.google.com/store/apps/details?id=com.faskhaninja.clients'])
            ->assertJsonPath('data.ios.min_version', '2.0.7');
        // Saved again: the same rows, changed.
        app(AppUpdatePolicy::class)->save(['android_min_version' => '1.0.56', 'message' => 'حدّث دلوقتي']);
        $this->assertSame(5, DB::table('settings')->where('group', 'app_update')->count());
        $this->getJson('/api/app-version')->assertJsonPath('data.android.min_version', '1.0.56')->assertJsonPath('data.message', 'حدّث دلوقتي')
            ->assertJsonPath('data.ios.min_version', null);
    }

    /** @dataProvider refused */
    public function test_a_wrong_version_or_store_link_is_refused(array $input): void
    {
        $this->expectException(ValidationException::class);
        app(AppUpdatePolicy::class)->save($input);
    }

    public function refused(): array
    {
        return [
            'not a version' => [['android_min_version' => 'latest']],
            'letters in it' => [['ios_min_version' => '2.0.x']],
            'another site' => [['android_store_url' => 'https://evil.example/app']],
            'not https' => [['ios_store_url' => 'http://apps.apple.com/eg/app/x']],
            'look-alike host' => [['android_store_url' => 'https://play.google.com.evil.example/x']],
        ];
    }

    public function test_the_dashboard_page_renders(): void
    {
        // The page's own content (the dashboard layout around it needs the whole general settings).
        $source = file_get_contents(resource_path('views/admin/app_update.blade.php'));
        $section = substr($source, strpos($source, "@section('content')") + strlen("@section('content')"));
        $section = str_replace(["@include('admin.layouts.alerts')", '@endsection'], '', $section);
        $compiled = app('blade.compiler')->compileString($section);
        $values = app(AppUpdatePolicy::class)->get(); $errors = new \Illuminate\Support\ViewErrorBag(); $__env = app('view');
        ob_start(); eval('?>'.$compiled); $html = ob_get_clean();
        $this->assertStringContainsString('name="android_min_version"', $html);
        $this->assertStringContainsString('name="ios_store_url"', $html);
    }
}
