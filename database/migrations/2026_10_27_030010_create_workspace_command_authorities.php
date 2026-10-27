<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Immutable acceptance evidence is retained when membership or the physical snapshot changes. */
class CreateWorkspaceCommandAuthorities extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_dashboard_workspace_command_authorities',function(Blueprint $t) {
            $t->engine='InnoDB'; $t->uuid('workspace_id'); $t->uuid('command_id');
            $t->unsignedBigInteger('actor_id'); $t->char('root_fingerprint',64);
            $t->unsignedBigInteger('manifest_revision'); $t->char('grant_hash',64);
            $t->char('request_hash',64); $t->text('proof_json'); $t->char('proof_sha256',64);
            $t->char('proof_mac',64); $t->string('accepted_database',100); $t->timestamp('created_at');
            $t->primary(['workspace_id','command_id'],'desktop_workspace_command_authority_primary');
        });
    }
    public function down(): void { Schema::dropIfExists('desktop_dashboard_workspace_command_authorities'); }
}
