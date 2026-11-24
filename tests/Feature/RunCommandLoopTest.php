<?php
namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RunCommandLoopTest extends TestCase
{
    public static array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$runs = [];
        Artisan::command('loop-fixture:tick {--limit=1}', function () {
            RunCommandLoopTest::$runs[] = (int) $this->option('limit');
            if (count(RunCommandLoopTest::$runs) === 2) {
                throw new \RuntimeException('second run fails');
            }
            $this->line('tick');
        });
    }

    public function test_repeats_the_target_with_its_options_and_survives_a_failed_run(): void
    {
        $this->artisan('loop:run', ['target' => 'loop-fixture:tick --limit=10', '--sleep' => 0, '--max-runs' => 3])
            ->assertExitCode(0);

        $this->assertSame([10, 10, 10], self::$runs);
    }

    public function test_stops_after_a_deploy_restart_signal(): void
    {
        Cache::forever('illuminate:queue:restart', 1);
        Artisan::command('loop-fixture:deploy', function () {
            RunCommandLoopTest::$runs[] = 1;
            Cache::forever('illuminate:queue:restart', 2);
        });

        $this->artisan('loop:run', ['target' => 'loop-fixture:deploy', '--sleep' => 0, '--max-runs' => 50])
            ->assertExitCode(0);

        $this->assertCount(1, self::$runs);
    }

    public function test_refuses_to_loop_itself(): void
    {
        $this->artisan('loop:run', ['target' => 'loop:run x', '--max-runs' => 1])->assertExitCode(1);
    }
}
