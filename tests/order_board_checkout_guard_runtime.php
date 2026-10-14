<?php
/** Replayed gateway callbacks cannot reopen cancelled, accepted or delivered food orders.
 * ORDER_BOARD_TEST_VENDOR=/path/to/laravel8/vendor php tests/order_board_checkout_guard_runtime.php
 */
namespace {
    $vendor = getenv('ORDER_BOARD_TEST_VENDOR');
    require rtrim($vendor ?: '', '/').'/autoload.php';
    spl_autoload_register(function ($class) {
        if (str_starts_with($class, 'App\\')) {
            $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($file)) require $file;
        }
    });
    function redirect() { return new class { public function route($name) { return $name; } }; }
}
namespace App\Models {
    // Isolate unrelated global listing scopes and payment transport from the real controller guard.
    class Order extends \Illuminate\Database\Eloquent\Model { protected $guarded = []; }
    class Payment extends \Illuminate\Database\Eloquent\Model { protected $guarded = []; }
}
namespace App\Http\Controllers {
    // HTTP middleware is covered separately; the isolated vendor omits Foundation auth traits.
    class Controller {}
}
namespace {
    use App\Models\Order;
    use App\Models\Payment;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\Schema;

    $app = new Container(); Container::setInstance($app);
    $db = new Manager($app);
    $db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
    $db->setAsGlobal(); $db->bootEloquent();
    $app->instance('db', $db->getDatabaseManager());
    $app->bind('db.schema', fn () => $db->getConnection()->getSchemaBuilder());
    Facade::setFacadeApplication($app);
    Schema::create('orders', function (Blueprint $t) {
        $t->id(); $t->string('type'); $t->string('status')->nullable();
        $t->string('accepted_notify')->nullable(); $t->string('transfer_price_by')->nullable(); $t->timestamps();
    });
    Schema::create('payments', function (Blueprint $t) {
        $t->id(); $t->integer('order_id'); $t->string('intention_order_id')->unique();
        $t->boolean('status')->default(false); $t->integer('transaction_id')->nullable(); $t->timestamps();
    });
    $controller = new class extends \App\Http\Controllers\CheckoutController {
        public int $retainedCalls = 0;
        public array $levels = [];
        protected function checkoutDoneRetained($id, $payment) {
            $this->retainedCalls++; $this->levels[] = DB::transactionLevel();
            $record = Payment::where('intention_order_id', $id)->firstOrFail();
            $record->update(['status'=>true,'transaction_id'=>901]);
            Order::whereKey($record->order_id)->update(['status'=>'pending']);
            return 'retained';
        }
    };
    $checks = 0;
    function same($expected, $actual, $label) {
        global $checks; $checks++;
        if ($expected !== $actual) throw new \RuntimeException($label.': '.var_export($actual, true));
    }
    foreach (['cancelled','declined','completed','accepted','shipped','pending','another_delegate','new_order'] as $index => $status) {
        $id = $index + 1;
        Order::create(['id'=>$id,'type'=>'current','status'=>$status,'accepted_notify'=>'yes']);
        Payment::create(['order_id'=>$id,'intention_order_id'=>'intent-'.$id,'status'=>true,'transaction_id'=>900]);
        $beforeOrder = Order::findOrFail($id)->getRawOriginal();
        $beforePayment = Payment::where('order_id', $id)->firstOrFail()->getRawOriginal();
        same('paySuccess', $controller->checkout_done('intent-'.$id, '{"success":true,"id":901}'), 'active/terminal callback acknowledged');
        same($beforeOrder, Order::findOrFail($id)->getRawOriginal(), 'delivery status and timestamps preserved');
        same($beforePayment, Payment::where('order_id', $id)->firstOrFail()->getRawOriginal(), 'original paid evidence preserved');
    }
    same(0, $controller->retainedCalls, 'no repeat payment or new-order transport side effects');
    foreach ([['yes',null],[null,'admin']] as $index => [$accepted,$settlement]) {
        $id = 20 + $index;
        Order::create(['id'=>$id,'type'=>'current','status'=>null,'accepted_notify'=>$accepted,'transfer_price_by'=>$settlement]);
        Payment::create(['order_id'=>$id,'intention_order_id'=>'intent-'.$id]);
        same('paySuccess', $controller->checkout_done('intent-'.$id, '{}'), 'accepted/settled draft cannot reactivate');
        same(null, Order::findOrFail($id)->status, 'inconsistent accepted/settled draft left for reconciliation');
    }
    Order::create(['id'=>30,'type'=>'current','status'=>null]);
    Payment::create(['order_id'=>30,'intention_order_id'=>'intent-30']);
    same('retained', $controller->checkout_done('intent-30', '{}'), 'first draft callback retains established checkout');
    same('pending', Order::findOrFail(30)->status, 'first valid activation occurs once');
    same([1], $controller->levels, 'current activation executes inside the shared order transaction');
    same('paySuccess', $controller->checkout_done('intent-30', '{}'), 'duplicate activation acknowledged');
    same(1, $controller->retainedCalls, 'duplicate activation invokes no retained side effects');
    foreach (['wallet','shipping'] as $index => $type) {
        $id = 40 + $index;
        Order::create(['id'=>$id,'type'=>$type,'status'=>null]);
        Payment::create(['order_id'=>$id,'intention_order_id'=>'intent-'.$id]);
        same('retained', $controller->checkout_done('intent-'.$id, '{}'), 'other retained checkout flows preserved');
        same(0, $controller->levels[count($controller->levels)-1], 'other checkout flows receive no new transaction wrapper');
    }
    echo 'Order board checkout guard: '.$checks." assertions passed.\n";
}
