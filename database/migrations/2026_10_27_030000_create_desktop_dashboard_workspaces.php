<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Protocol 2 lives beside legacy enrollments and never rewrites their actor or journal. */
class CreateDesktopDashboardWorkspaces extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_dashboard_workspaces', function (Blueprint $t) {
            $t->engine='InnoDB'; $t->uuid('id')->primary(); $t->unsignedBigInteger('custodian_actor_id');
            $t->string('name',100); $t->char('enrollment_hash',64); $t->char('token_hash',64)->unique();
            $t->boolean('enabled')->default(true); $t->unsignedBigInteger('manifest_revision')->default(0);
            $t->char('membership_fingerprint',64)->nullable(); $t->longText('manifest_json')->nullable();
            $t->timestamp('enrolled_at'); $t->timestamps();
        });
        Schema::create('desktop_dashboard_workspace_members', function (Blueprint $t) {
            $t->engine='InnoDB'; $t->uuid('workspace_id'); $t->unsignedBigInteger('actor_id');
            $t->boolean('enabled'); $t->unsignedBigInteger('manifest_revision');
            $t->char('grant_hash',64); $t->longText('member_json'); $t->timestamps();
            $t->primary(['workspace_id','actor_id'],'desktop_workspace_member_primary');
            $t->index(['actor_id','enabled'],'desktop_workspace_member_actor');
        });
        Schema::create('desktop_dashboard_workspace_manifests', function (Blueprint $t) {
            $t->engine='InnoDB'; $t->uuid('workspace_id'); $t->unsignedBigInteger('revision');
            $t->char('roster_sha256',64); $t->char('signature',64); $t->longText('manifest_json'); $t->timestamps();
            $t->primary(['workspace_id','revision'],'desktop_workspace_manifest_primary');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('desktop_dashboard_workspace_manifests');
        Schema::dropIfExists('desktop_dashboard_workspace_members');
        Schema::dropIfExists('desktop_dashboard_workspaces');
    }
}
