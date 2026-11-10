<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Some branch devices were refused with a correct password, now and then: two dashboard accounts shared an email (or a
 * mobile) and the sign-in tried whichever row the database returned first.
 */
class DashboardSignInTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('password')->nullable();
            $table->string('account_type');
            $table->string('app_scope')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('owner_resturant_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        DB::table('users')->insert([
            ['id' => 5, 'name' => 'Main', 'email' => 'shared@example.com', 'mobile' => '1001112223', 'password' => Hash::make('main-secret'), 'account_type' => 'vendor'],
            ['id' => 9, 'name' => 'Branch', 'email' => 'shared@example.com', 'mobile' => null, 'password' => Hash::make('branch-secret'), 'account_type' => 'vendor'],
            ['id' => 12, 'name' => 'Customer', 'email' => 'shared@example.com', 'mobile' => null, 'password' => Hash::make('customer-secret'), 'account_type' => 'user'],
        ]);
    }

    public function test_a_shared_email_signs_in_the_account_whose_password_matches(): void
    {
        $this->post('/admin/signin', ['email' => 'shared@example.com', 'password' => 'branch-secret'])->assertRedirect(url('admin/dashboard'));
        $this->assertSame(9, (int) auth('admin')->id());
        auth('admin')->logout();
        $this->post('/admin/signin', ['email' => 'shared@example.com', 'password' => 'main-secret'])->assertRedirect(url('admin/dashboard'));
        $this->assertSame(5, (int) auth('admin')->id());
        auth('admin')->logout();
        $this->post('/admin/signin', ['email' => '01001112223', 'password' => 'main-secret'])->assertRedirect(url('admin/dashboard'));
        $this->assertSame(5, (int) auth('admin')->id());
    }

    public function test_a_wrong_password_or_a_customer_account_is_still_refused(): void
    {
        $this->from('/admin/login')->post('/admin/signin', ['email' => 'shared@example.com', 'password' => 'nope'])->assertRedirect('/admin/login')->assertSessionHas('error');
        $this->assertFalse(auth('admin')->check());
        $this->from('/admin/login')->post('/admin/signin', ['email' => 'shared@example.com', 'password' => 'customer-secret'])->assertRedirect('/admin/login');
        $this->assertFalse(auth('admin')->check());
    }
}
