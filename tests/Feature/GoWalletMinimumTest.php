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
        ];
        foreach ($actions as [$role, $action, $payload]) {
            foreach (['49.99', '0.00', '-10.00'] as $balance) {
                $user = (new User())->forceFill(['id'=>1, 'account_type'=>$role, 'balance'=>$balance]);
                auth('api')->setUser($user);
                $request = Request::create('/api/fixture', 'POST', $payload, [], [], ['HTTP_X_APP_SCOPE'=>$role==='user'?'go':'go_partner']);
                $route = new Route('POST', '/api/fixture', ['controller'=>'App\\Http\\Controllers\\Api\\V1\\'.$action]);
                $request->setRouteResolver(fn()=> $route);
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
            $request->setRouteResolver(fn()=> $route);
            $this->assertSame('allowed', (new GoWalletMinimum())->handle($request, fn()=>response('allowed'))->getContent());
        }
    }
}
