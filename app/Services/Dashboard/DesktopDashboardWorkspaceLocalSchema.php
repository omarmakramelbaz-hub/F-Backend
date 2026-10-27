<?php
namespace App\Services\Dashboard;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

/** Private staging tables. A web request can never create or replace local authority. */
final class DesktopDashboardWorkspaceLocalSchema
{
    public static function assertNativeCli(): void
    {
        abort_unless(PHP_SAPI==='cli' && config('desktop_dashboard.local')===true
            && config('desktop_dashboard.workspace_enabled')===true
            && getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL')==='true',403);
        $driver=DB::connection()->getDriverName();
        if ($driver==='sqlite' && app()->environment('testing')) return;
        if ($driver==='mysql' && app()->environment('testing') && DB::connection()->getDatabaseName()==='takeaway_test') return;
        abort_unless($driver==='mysql' && DB::connection()->getConfig('host')==='127.0.0.1'
            && preg_match('/^fasakhansta_dashboard(?:_stage_[a-f0-9]{16})?$/D',DB::connection()->getDatabaseName()),403);
    }
    public static function ensure(): void
    {
        self::assertNativeCli();
        if (!Schema::hasTable('desktop_dashboard_workspace_local_authority')) Schema::create('desktop_dashboard_workspace_local_authority',function(Blueprint $t) {
            $t->engine='InnoDB'; $t->uuid('workspace_id')->primary(); $t->longText('record_json');
            $t->longText('manifest_json'); $t->char('local_mac',64); $t->timestamps();
        });
        if (!Schema::hasTable('desktop_dashboard_workspace_local_members')) Schema::create('desktop_dashboard_workspace_local_members',function(Blueprint $t) {
            $t->engine='InnoDB'; $t->uuid('workspace_id'); $t->unsignedBigInteger('actor_id');
            $t->unsignedBigInteger('manifest_revision'); $t->longText('member_json'); $t->char('local_mac',64); $t->timestamps();
            $t->primary(['workspace_id','actor_id'],'desktop_workspace_local_member_primary');
        });
        if (!Schema::hasTable('desktop_dashboard_ws_authority_history')) Schema::create('desktop_dashboard_ws_authority_history',function(Blueprint $t) {
            $t->engine='InnoDB'; $t->uuid('workspace_id'); $t->char('record_sha256',64);
            $t->longText('record_json'); $t->longText('manifest_json'); $t->char('local_mac',64); $t->timestamps();
            $t->primary(['workspace_id','record_sha256'],'desktop_workspace_local_history_primary');
        });
        if (!Schema::hasTable('desktop_dashboard_ws_command_auth')) {
            require_once database_path('migrations/2026_10_27_030010_create_workspace_command_authorities.php');
            (new \CreateWorkspaceCommandAuthorities)->up();
        }
    }
}
