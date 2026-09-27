<?php

namespace Tests\Feature;

use App\Http\Middleware\CustomJwtAuth;
use App\Models\User;
use App\Services\GoStores\Catalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GoStoreCatalogTest extends TestCase
{
    private $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:', 'cache.default'=>'array']);
        DB::purge('sqlite');
        $this->withoutMiddleware([CustomJwtAuth::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            foreach (['name','account_type','app_scope','status','mobile','password','email'] as $key) $t->string($key)->nullable();
            $t->decimal('balance', 12, 2)->default(0);
            $t->decimal('delegate_fees', 6, 2)->default(10);
            $t->unsignedBigInteger('pending_vendor_id')->nullable();
            $t->timestamps();
        });
        Schema::create('pending_vendors', function (Blueprint $t) { $t->id(); $t->string('profession_key'); });
        require_once base_path('database/migrations/2026_09_27_180000_create_go_store_catalog.php');
        (new \CreateGoStoreCatalog())->up();
        Storage::fake('public');
        $this->owner = User::forceCreate(['name'=>'متجر أول', 'account_type'=>'vendor', 'app_scope'=>'go_partner', 'status'=>'accepted', 'balance'=>-10]);
        $this->actingAs($this->owner, 'api');
        $this->withHeaders(['X-App-Scope'=>'go_partner', 'Accept'=>'application/json']);
    }

    private function profile(string $kind = 'supermarket')
    {
        return $this->postJson('/api/go-stores/profile', ['name'=>'متجر تجريبي', 'kind'=>$kind, 'address'=>'شارع الاختبار ١، القاهرة', 'revision'=>0]);
    }

    private function product(array $overrides = []): array
    {
        return array_replace(['name'=>'أرز', 'unit'=>'كيلو', 'price'=>'80.50', 'description'=>'وصف المنتج', 'available'=>true,
            'request_key'=>(string) Str::uuid(), 'options'=>[['label'=>'نصف كيلو','price'=>'42.75'], ['label'=>'ربع كيلو','price'=>'23.25']],
            'image'=>UploadedFile::fake()->image('rice.png', 300, 300)], $overrides);
    }

    public function test_store_types_and_catalog_remain_manageable_with_negative_balance(): void
    {
        foreach (array_keys(Catalog::KINDS) as $kind) {
            DB::table('go_stores')->delete();
            $this->profile($kind)->assertOk()->assertJsonPath('data.store.kind', $kind);
        }
        $this->getJson('/api/go-stores/catalog')->assertOk()->assertJsonPath('data.products', []);
        $this->assertSame(-10.0, (float) DB::table('users')->value('balance'));
    }

    public function test_products_keep_exact_prices_images_options_and_retry_without_duplicates(): void
    {
        $this->profile()->assertOk();
        $payload = $this->product();
        $item = $this->post('/api/go-stores/products', $payload)->assertOk()->json('data.product');
        $this->assertSame('80.50', $item['price']);
        $this->assertSame('42.75', $item['options'][0]['price']);
        $this->assertSame('23.25', $item['options'][1]['price']);
        $this->assertNotEmpty($item['options'][0]['id']);
        Storage::disk('public')->assertExists(DB::table('go_store_products')->value('image_path'));
        $retry = $payload; unset($retry['image']);
        $this->postJson('/api/go-stores/products', $retry)->assertOk()->assertJsonPath('data.product.id', $item['id']);
        $this->assertSame(1, DB::table('go_store_products')->count());
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $update = $this->product(['revision'=>1,'price'=>'90.00','available'=>false, 'options'=>$item['options']]);
        $update['options'][0]['price'] = '47.25';
        unset($update['image']);
        $this->postJson('/api/go-stores/products/'.$item['id'], $update)->assertOk()
            ->assertJsonPath('data.product.options.0.id', $item['options'][0]['id'])
            ->assertJsonPath('data.product.options.0.price', '47.25')
            ->assertJsonPath('data.product.available', false)->assertJsonPath('data.product.revision', 2)
            ->assertJsonPath('data.product.image_url', $item['image_url']);
        $this->postJson('/api/go-stores/products/'.$item['id'], $update)->assertStatus(409);
        $this->getJson('/api/go-stores/catalog?search=أرز')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/go-stores/catalog?search=لبن')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_ownership_scope_roles_and_status_cannot_be_bypassed(): void
    {
        $this->profile()->assertOk();
        $this->postJson('/api/vendor/items', ['product_name'=>'must not touch F'])->assertForbidden();
        $this->getJson('/api/delegate/orders')->assertForbidden();
        $item = $this->post('/api/go-stores/products', $this->product())->assertOk()->json('data.product');
        $other = User::forceCreate(['name'=>'متجر ثان', 'account_type'=>'vendor', 'app_scope'=>'go_partner', 'status'=>'accepted']);
        $this->actingAs($other, 'api');
        $this->profile()->assertOk();
        $this->getJson('/api/go-stores/catalog')->assertOk()->assertJsonPath('data.products', []);
        $this->post('/api/go-stores/products/'.$item['id'], $this->product(['revision'=>1, 'user_id'=>$this->owner->id]))->assertNotFound();
        $this->assertSame('أرز', DB::table('go_store_products')->value('name'));
        foreach ([['user','go','accepted'], ['vendor','fasakhansta','accepted'], ['delegate','go_partner','accepted'], ['vendor','go_partner','disabled']] as [$type,$scope,$status]) {
            $other->forceFill(['account_type'=>$type, 'app_scope'=>$scope, 'status'=>$status])->save();
            $this->actingAs($other->fresh(), 'api');
            $response = $this->getJson('/api/go-stores/catalog');
            $this->assertContains($response->getStatusCode(), [401,403]);
        }
    }

    public function test_server_rejects_invalid_prices_options_files_and_stale_store_edits(): void
    {
        $this->profile()->assertOk();
        $this->profile()->assertStatus(409);
        foreach (['-1','0','1.001','1e2','1000000.01'] as $price) {
            $this->post('/api/go-stores/products', $this->product(['price'=>$price]))->assertStatus(422);
        }
        $this->post('/api/go-stores/products', $this->product(['options'=>[['label'=>'نصف','price'=>'20'],['label'=>' نصف ','price'=>'30']]]))->assertStatus(422);
        $this->post('/api/go-stores/products', $this->product(['options'=>[['label'=>'ربع','price'=>'-1']]]))->assertStatus(422);
        $this->post('/api/go-stores/products', $this->product(['image'=>UploadedFile::fake()->create('image.svg', 10, 'image/svg+xml')]))->assertStatus(422);
        $this->post('/api/go-stores/products', $this->product(['image'=>null]))->assertStatus(422);
        $this->assertSame(0, DB::table('go_store_products')->count());
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->post('/api/go-stores/products', $this->product(['options'=>'[]', 'price'=>'0.01']))->assertOk()->assertJsonPath('data.product.options', []);
    }

    public function test_legacy_go_store_identity_and_admin_use_same_catalog(): void
    {
        DB::table('pending_vendors')->insert(['id'=>10, 'profession_key'=>'store_owner']);
        $this->owner->forceFill(['account_type'=>'delegate','pending_vendor_id'=>10])->save();
        $this->actingAs($this->owner->fresh(), 'api');
        $this->profile('pharmacy')->assertOk();
        $item = $this->post('/api/go-stores/products', $this->product())->assertOk()->json('data.product');
        $admin = User::forceCreate(['id'=>100, 'name'=>'أدمن غير مخول', 'account_type'=>'vendor', 'app_scope'=>'fasakhansta']);
        $this->actingAs($admin, 'admin');
        $this->get('/admin/go-stores')->assertForbidden();
        // Root admin fixture uses the established id=1 authorization.
        $this->owner->forceFill(['account_type'=>'admin'])->save();
        $this->actingAs($this->owner->fresh(), 'admin');
        $other = User::forceCreate(['name'=>'متجر', 'account_type'=>'vendor','app_scope'=>'go_partner','status'=>'accepted']);
        DB::table('go_stores')->where('user_id',$this->owner->id)->update(['user_id'=>$other->id]);
        DB::table('go_store_products')->where('id',$item['id'])->update(['user_id'=>$other->id]);
        $payload = $this->product(['revision'=>1,'options'=>[], 'price'=>'65.00']); unset($payload['image']);
        $this->post('/admin/go-stores/'.$other->id.'/products/'.$item['id'], $payload)->assertRedirect();
        $this->assertSame('65.00', app(Catalog::class)->product($other->id, $item['id'])['price']);
        $this->assertSame([], app(Catalog::class)->product($other->id, $item['id'])['options']);
    }
}
