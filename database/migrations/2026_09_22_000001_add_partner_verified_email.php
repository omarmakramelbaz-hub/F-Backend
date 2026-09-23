<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPartnerVerifiedEmail extends Migration
{
    public function up()
    {
        Schema::table('pending_vendors', function (Blueprint $table) {
            if (!Schema::hasColumn('pending_vendors', 'email')) $table->string('email')->nullable();
            if (!Schema::hasColumn('pending_vendors', 'email_verified_at')) $table->timestamp('email_verified_at')->nullable();
            if (!Schema::hasColumn('pending_vendors', 'partner_activated_at')) $table->timestamp('partner_activated_at')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            // Kept separate from editable contact email: recovery must target the verified mailbox.
            if (!Schema::hasColumn('users', 'partner_auth_email')) $table->string('partner_auth_email')->nullable();
        });
    }

    public function down()
    {
        Schema::table('pending_vendors', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'partner_activated_at']);
        });
        Schema::table('users', function (Blueprint $table) { $table->dropColumn('partner_auth_email'); });
    }
}
