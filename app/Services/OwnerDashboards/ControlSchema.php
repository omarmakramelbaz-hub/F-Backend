<?php
namespace App\Services\OwnerDashboards;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class ControlSchema
{
    public static function ensure(): void
    {
        if(Runtime::tenant())throw new \RuntimeException('GO_OWNER_CONTROL_PLANE_FORBIDDEN');
        if(!Schema::hasTable('owner_dashboards'))Schema::create('owner_dashboards',function(Blueprint $t){
            $t->engine='InnoDB';$t->uuid('id')->primary();$t->string('slug',16)->unique();
            $t->string('name',130);$t->string('email',191)->unique();$t->string('mobile',32)->nullable();
            $t->string('password_hash',255);$t->unsignedBigInteger('created_by');
            $t->string('desired_state',16)->default('active');$t->string('state',16)->default('pending');
            $t->unsignedBigInteger('revision')->default(1);$t->unsignedBigInteger('applied_revision')->default(0);
            $t->string('error_code',80)->nullable();$t->timestamps();$t->index(['state','revision']);
        });
        $required=['id','slug','name','email','mobile','password_hash','created_by','desired_state','state',
            'revision','applied_revision','error_code','created_at','updated_at'];
        if(array_diff($required,Schema::getColumnListing('owner_dashboards')))throw new \RuntimeException('GO_OWNER_CONTROL_SCHEMA_MISMATCH');
    }
}
