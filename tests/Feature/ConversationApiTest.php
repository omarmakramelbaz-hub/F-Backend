<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The app's chat (/api/user/conversation/{id}, /api/user/send_message): only the two people in a conversation read it
 * or write in it, and a message always goes to the conversation's other person. A saved search is deleted by its owner.
 */
class ConversationApiTest extends TestCase
{
    use Concerns\CustomerAppSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerAppSchema();
        Schema::create('conversations', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('receiver'); $t->text('message')->nullable(); $t->boolean('read')->default(false); $t->timestamps(); });
        Schema::create('message_conversations', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('sender'); $t->unsignedBigInteger('reciever'); $t->unsignedBigInteger('conv_id'); $t->text('message'); $t->boolean('read')->default(false); $t->timestamps(); });
        if (!Schema::hasTable('last_searches')) Schema::create('last_searches', function (Blueprint $t) { $t->id(); $t->string('search')->nullable(); $t->string('searchable_type')->nullable(); $t->unsignedBigInteger('user_id')->nullable(); $t->unsignedBigInteger('resturant_id')->nullable(); $t->timestamps(); });
        DB::table('users')->insert(['id' => 9, 'name' => 'عميل تالت', 'mobile' => '1000000009', 'app_scope' => 'fasakhansta', 'status' => 'accepted', 'balance' => 0]);
        // A conversation between customers 7 and 8.
        DB::table('conversations')->insert(['id' => 1, 'user_id' => 7, 'receiver' => 8]);
        DB::table('message_conversations')->insert(['id' => 1, 'sender' => 7, 'reciever' => 8, 'conv_id' => 1, 'message' => 'رسالة خاصة']);
    }

    private function uri(string $action, array $parameters = []): string
    {
        $route = collect(app('router')->getRoutes())->first(fn ($route) => str_ends_with($route->getActionName(), $action));
        return '/'.preg_replace_callback('/\{(\w+)\}/', fn ($m) => $parameters[$m[1]], $route->uri());
    }

    public function test_only_the_two_people_read_a_conversation(): void
    {
        $this->withHeaders($this->appHeaders(9))->getJson($this->uri('ConversationController@conversation', ['id' => 1]))
            ->assertJson(['status' => 'Error'])->assertDontSee('رسالة خاصة');
        $this->withHeaders($this->appHeaders(8))->getJson($this->uri('ConversationController@conversation', ['id' => 1]))
            ->assertJson(['status' => 'Success'])->assertJsonPath('data.0.message', 'رسالة خاصة');
        $this->assertSame(1, (int) DB::table('message_conversations')->where('id', 1)->value('read'), 'Read by its receiver');
    }

    public function test_a_message_goes_into_ones_own_conversation_to_its_other_person(): void
    {
        $send = fn (int $user, array $values) => $this->withHeaders($this->appHeaders($user))->postJson($this->uri('ConversationController@send_message'), $values);
        $send(9, ['conv_id' => 1, 'receiver' => 8, 'message' => 'دخيل'])->assertJson(['status' => 'Error']);
        $this->assertSame(0, DB::table('message_conversations')->where('message', 'دخيل')->count());
        // The receiver sent is not trusted: the message goes to the conversation's other person.
        $send(8, ['conv_id' => 1, 'receiver' => 9, 'message' => 'رد'])->assertJson(['status' => 'Success']);
        $this->assertSame([8, 7], array_map('intval', array_values((array) DB::table('message_conversations')->where('message', 'رد')->first(['sender', 'reciever']))));
    }

    public function test_a_saved_search_is_deleted_by_its_owner_only(): void
    {
        DB::table('last_searches')->insert([['id' => 1, 'search' => 'فسيخ', 'user_id' => 7], ['id' => 2, 'search' => 'رنجة', 'user_id' => 8]]);
        $this->withHeaders($this->appHeaders(7))->deleteJson($this->uri('CategoryController@deleteSearch', ['id' => 2]));
        $this->assertSame(1, DB::table('last_searches')->where('id', 2)->count());
        $this->withHeaders($this->appHeaders(7))->deleteJson($this->uri('CategoryController@deleteSearch', ['id' => 1]));
        $this->assertSame(0, DB::table('last_searches')->where('id', 1)->count());
    }
}
