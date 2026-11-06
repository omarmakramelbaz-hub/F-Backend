<?php
// Isolated in-memory SQLite integration; never connect to the application database.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$root=realpath($argv[1] ?? dirname(__DIR__,2));
require $argv[2];
spl_autoload_register(static function($class) use($root) {
    if(strncmp($class,'App\\',4)===0) {
        $path=$root.'/app/'.str_replace('\\','/',substr($class,4)).'.php';
        if(is_file($path))require $path;
    }
});

use App\Services\Dashboard\BranchOperations;
use App\Services\Dashboard\PhoneDeliveryBoard;
use App\Services\Dashboard\PosServiceTicket;
use App\Services\Dashboard\TakeawayAccess;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

$container=new class extends Illuminate\Container\Container {
    public function abort($code,$message='',$headers=[]) {throw new HttpException($code,$message,null,$headers);}
};
Illuminate\Container\Container::setInstance($container);
$db=new Manager($container);
$db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
$db->setAsGlobal();
$container->instance('db',$db->getDatabaseManager());
$container->bind('db.schema',static fn()=>DB::connection()->getSchemaBuilder());
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
DB::statement('CREATE TABLE phone_delivery_batches (id INTEGER PRIMARY KEY, branch TEXT, snapshot TEXT)');
DB::statement('CREATE TABLE phone_delivery_batch_items (id INTEGER PRIMARY KEY)');
DB::statement('CREATE TABLE phone_delivery_dispatches (id INTEGER PRIMARY KEY)');
DB::statement('CREATE TABLE takeaway_orders (id INTEGER PRIMARY KEY, branch TEXT, channel TEXT, ticket_id INTEGER, total_cents INTEGER, delivery_cents INTEGER)');
$batch=['branch'=>['value'=>'f:94'],'items'=>[
    ['ticket_id'=>101,'order_id'=>501,'total'=>'550.00'],['ticket_id'=>102,'order_id'=>502,'total'=>'325.35'],
],'total'=>'875.35','cash_received'=>'900.00','change'=>'24.65'];
DB::table('phone_delivery_batches')->insert(['id'=>17,'branch'=>'f:94','snapshot'=>json_encode($batch)]);
DB::table('takeaway_orders')->insert([
    ['id'=>501,'branch'=>'f:94','channel'=>'phone','ticket_id'=>101,'total_cents'=>55000,'delivery_cents'=>5000],
    ['id'=>502,'branch'=>'f:94','channel'=>'phone','ticket_id'=>102,'total_cents'=>32535,'delivery_cents'=>2535],
    ['id'=>999,'branch'=>'f:94','channel'=>'phone','ticket_id'=>999,'total_cents'=>90000,'delivery_cents'=>90000],
]);
$access=new class extends TakeawayAccess {
    public function branch(string $value,$actor,bool $lock=false): array {
        if($actor->branch!==$value)throw new HttpException(403);
        return ['value'=>$value];
    }
};
$tickets=new class extends PosServiceTicket {public function __construct(){}};
$board=new PhoneDeliveryBoard(new BranchOperations($access),$tickets);
$actor=(object)['branch'=>'f:94'];
$checks=0;
function checkDb($condition,$message) {global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}

// The successful reprint must be possible on a read-only connection with no live ticket table.
DB::statement('PRAGMA query_only = ON');
$result=$board->receipt(17,$actor);
checkDb($result['id']===17 && $result['delivery_total']==='75.35' && $result['orders_total']==='800.00','original receipts only');
checkDb($board->receipt(17,$actor)===$result,'repeat printing stable');
checkDb(DB::table('phone_delivery_batches')->value('snapshot')===json_encode($batch),'stored batch unchanged');
DB::enableQueryLog();DB::flushQueryLog();
try{$board->receipt(17,(object)['branch'=>'f:95']);throw new RuntimeException('unauthorized receipt allowed');}
catch(HttpException $error){checkDb($error->getStatusCode()===403,'branch access enforced');}
checkDb(!array_filter(DB::getQueryLog(),static fn($entry)=>str_contains($entry['query'],'takeaway_orders')),'authorize before receipt lookup');
try{$board->receipt(99,$actor);throw new RuntimeException('missing batch allowed');}
catch(HttpException $error){checkDb($error->getStatusCode()===404,'missing batch rejected');}

foreach (['branch'=>'f:95','channel'=>'dine','ticket_id'=>123] as $field=>$value) {
    DB::statement('PRAGMA query_only = OFF');
    DB::table('takeaway_orders')->where('id',502)->update([$field=>$value]);
    DB::statement('PRAGMA query_only = ON');
    $unknown=$board->receipt(17,$actor);
    checkDb($unknown['delivery_total']===null && $unknown['items'][1]['delivery']===null,'unrelated source excluded '.$field);
    checkDb($unknown['total']==='875.35','original collection retained '.$field);
    DB::statement('PRAGMA query_only = OFF');
    DB::table('takeaway_orders')->where('id',502)->update(['branch'=>'f:94','channel'=>'phone','ticket_id'=>102]);
}
DB::table('takeaway_orders')->where('id',502)->delete();
DB::statement('PRAGMA query_only = ON');
checkDb($board->receipt(17,$actor)['delivery_total']===null,'deleted original not replaced by another order');
echo json_encode(['status'=>'COURIER_RECEIPT_DATABASE_VERIFIED','checks'=>$checks])."\n";
