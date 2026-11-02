<?php

namespace Tests\Feature;

use App\Models\User;
use App\Scopes\AdminScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** AdminScope as it was before it stopped reading the account for requests it never scopes (the reference). */
class LegacyAdminScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        $id_user = (session()->get('id_user')) ?? null;
        $user = DB::table('users')->where('id', $id_user)->first();
        if (request()->wantsJson() || request()->is('api/*') || request()->is('admin/chat/*')) {
            if ($user && $user->account_type == 'resturant_owner') {
                $resturants = DB::table('resturants')->where('id', $user->owner_resturant_id)->orWhere('parent_id', $user->owner_resturant_id)->pluck('user_id')->toArray();
                $builder->where(function ($query) use ($user, $resturants) {
                    if (!empty($resturants)) {
                        $query->whereIn('id', $resturants);
                    }
                    $query->orWhere('id', $user->id)->orWhere('added_by', $user->id);
                });
            } elseif ($user && $user->account_type != 'admin') {
                $builder->where('added_by', $user->id)->when($user->added_by != null, function ($q) use ($user) {
                    $q->orWhere('added_by', $user->added_by);
                })->orWhere('id', $user->id)->orWhere('id', 1);
            }
        }
    }
}

class AdminScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('account_type')->nullable();
            $table->unsignedBigInteger('owner_resturant_id')->nullable();
            $table->unsignedBigInteger('added_by')->nullable();
        });
        Schema::create('resturants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
        });
        foreach ([[1, 'admin', null, null], [2, 'admin', null, null], [10, 'vendor', null, 2], [11, 'vendor', null, 10], [12, 'delegate', null, 10],
                  [20, 'resturant_owner', 100, null], [30, 'vendor', null, 20], [40, 'user', null, null], [41, 'vendor', null, 99]] as [$id, $type, $owner, $by]) {
            DB::table('users')->insert(['id' => $id, 'name' => 'u'.$id, 'account_type' => $type, 'owner_resturant_id' => $owner, 'added_by' => $by]);
        }
        foreach ([[100, 30, null], [101, 41, 100], [102, 12, 999]] as [$id, $user, $parent]) {
            DB::table('resturants')->insert(['id' => $id, 'user_id' => $user, 'parent_id' => $parent]);
        }
    }

    private function request(string $uri, bool $json, $account): void
    {
        $request = Request::create($uri, 'GET', [], [], [], $json ? ['HTTP_ACCEPT' => 'application/json'] : []);
        $this->app->instance('request', $request);
        session()->flush();
        if ($account !== null) session()->put('id_user', $account);
    }

    /** [ids the query returns, queries it took] */
    private function read(callable $query): array
    {
        $count = 0;
        DB::listen(function () use (&$count) { $count++; });
        $ids = $query()->sort()->values()->all();
        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');
        return [$ids, $count];
    }

    private function contexts(): array
    {
        $found = [];
        foreach ([null, 1, 10, 20, 999] as $account) {
            $found[] = ['/admin/users', false, $account];
            $found[] = ['/admin/users', true, $account];
            $found[] = ['/api/v1/orders', false, $account];
            $found[] = ['/admin/chat/rooms', false, $account];
        }
        return $found;
    }

    public function test_every_request_sees_exactly_the_users_it_saw_before(): void
    {
        foreach ($this->contexts() as [$uri, $json, $account]) {
            foreach ([fn ($users) => $users->pluck('id'), fn ($users) => $users->where('account_type', 'vendor')->pluck('id')] as $read) {
                $this->request($uri, $json, $account);
                [$now] = $this->read(fn () => $read(User::query()));
                $this->request($uri, $json, $account);
                [$before] = $this->read(fn () => $read(User::withoutGlobalScope(AdminScope::class)->withGlobalScope('legacy', new LegacyAdminScope)));
                $this->assertSame($before, $now, json_encode([$uri, $json, $account]));
            }
        }
    }

    public function test_the_account_is_read_only_when_a_request_is_scoped_by_it(): void
    {
        // A dashboard page (not JSON) is never scoped: no lookup at all, whoever is signed in.
        $this->request('/admin/users', false, 10);
        $this->assertSame([[1, 2, 10, 11, 12, 20, 30, 40, 41], 1], $this->read(fn () => User::query()->pluck('id')));
        // A JSON or API request without a dashboard account: nothing to read.
        $this->request('/api/v1/orders', false, null);
        $this->assertSame(1, $this->read(fn () => User::query()->pluck('id'))[1]);
        // A JSON request by a branch account: read once per query, scoped as before.
        $this->request('/admin/users', true, 10);
        $this->assertSame([[1, 10, 11, 12], 2], $this->read(fn () => User::query()->pluck('id')));
        // A restaurant owner: its restaurants' users, itself and the users it added.
        $this->request('/admin/chat/rooms', false, 20);
        $this->assertSame([[20, 30, 41], 3], $this->read(fn () => User::query()->pluck('id')));
    }
}
