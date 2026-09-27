<?php
namespace Tests\Feature;

use App\Http\Middleware\GoWalletMinimum;
use App\Models\User;
use App\Services\GoServices\WalletPolicy;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

class GoWalletMinimumTest extends TestCase
{
    public function test_customer_courier_professional_and_store_are_blocked_below_fifty_only_for_new_orders(): void
    {
        $actions = [
            ['user', 'ShippingController@store', []],
            ['delegate', 'Delegate\\DelegateOrderController@submitShippingOffer', []],
            ['delegate', 'PartnerServiceRequestController@updateStatus', ['status'=>'accepted']],
            ['vendor', 'Vendor\\OrderController@acceptOrder', []],
            ['vendor', 'Vendor\\OrderController@updateOrder', ['type'=>'in_resturant']],
        ];
        foreach ($actions as [$role, $action, $payload]) {
            foreach (['49.99', '0.00', '-10.00'] as $balance) {
                $user = (new User())->forceFill(['id'=>1, 'account_type'=>$role, 'balance'=>$balance]);
                auth('api')->setUser($user);
                $request = Request::create('/api/fixture', 'POST', $payload, [], [], ['HTTP_X_APP_SCOPE'=>$role==='user'?'go':'go_partner']);
                $route = new Route('POST', '/api/fixture', ['controller'=>'App\\Http\\Controllers\\Api\\V1\\'.$action]);
                $route->bind($request); $request->setRouteResolver(fn()=> $route);
                try {(new GoWalletMinimum())->handle($request, fn()=>response('allowed')); $this->fail('Underfunded new order was allowed: '.$action.' '.$balance.' '.$request->route()->getActionName());}
                catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {$this->assertSame(409, $e->getStatusCode());}
            }
            auth('api')->setUser((new User())->forceFill(['id'=>1, 'account_type'=>$role, 'balance'=>'50.00']));
            $this->assertSame('allowed', (new GoWalletMinimum())->handle($request, fn()=>response('allowed'))->getContent());
        }
    }

    public function test_debt_warning_reports_recharge_needed_without_blocking_existing_work_or_topup(): void
    {
        $user = (new User())->forceFill(['id'=>1, 'account_type'=>'delegate', 'balance'=>'-10.00']);
        auth('api')->setUser($user);
        $summary = WalletPolicy::summary($user);
        $this->assertSame('-10.00', $summary['balance']);
        $this->assertSame('60.00', $summary['top_up_required']);
        $this->assertFalse($summary['can_accept_orders']);
        foreach (['Delegate\\DelegateOrderController@orderCompleted', 'User\\WalletController@charging_wallet', 'GoServiceMarketplaceController@status'] as $action) {
            $request = Request::create('/api/fixture', 'POST', ['status'=>'cancelled'], [], [], ['HTTP_X_APP_SCOPE'=>'go_partner']);
            $route = new Route('POST', '/api/fixture', ['controller'=>'App\\Http\\Controllers\\Api\\V1\\'.$action]);
            $route->bind($request); $request->setRouteResolver(fn()=> $route);
            $this->assertSame('allowed', (new GoWalletMinimum())->handle($request, fn()=>response('allowed'))->getContent());
        }
    }
    public function test_checkout_checks_the_store_wallet_from_the_unsubmitted_cart(): void
    {
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:']);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        $schema = \Illuminate\Support\Facades\DB::connection()->getSchemaBuilder();
        $schema->create('users', function ($t) {$t->id(); $t->decimal('balance',14,2);});
        $schema->create('resturants', function ($t) {$t->id(); $t->unsignedBigInteger('user_id');});
        $schema->create('carts', function ($t) {$t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('resturant_id'); $t->boolean('is_order');});
        \Illuminate\Support\Facades\DB::table('users')->insert(['id'=>2,'balance'=>'49.99']);
        \Illuminate\Support\Facades\DB::table('resturants')->insert(['id'=>8,'user_id'=>2]);
        \Illuminate\Support\Facades\DB::table('carts')->insert(['id'=>1,'user_id'=>1,'resturant_id'=>8,'is_order'=>false]);
        auth('api')->setUser((new User())->forceFill(['id'=>1,'account_type'=>'user','balance'=>'50.00']));
        $request = Request::create('/api/user/order/payment', 'POST', [], [], [], ['HTTP_X_APP_SCOPE'=>'go']);
        $route = app('router')->getRoutes()->match($request);
        $route->bind($request); $request->setRouteResolver(fn()=> $route);
        try {(new GoWalletMinimum())->handle($request, fn()=>response('allowed')); $this->fail('Underfunded store accepted a checkout.');}
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {$this->assertSame(409, $e->getStatusCode());}
        \Illuminate\Support\Facades\DB::table('users')->where('id',2)->update(['balance'=>'50.00']);
        $this->assertSame('allowed', (new GoWalletMinimum())->handle($request, fn()=>response('allowed'))->getContent());
    }

}
