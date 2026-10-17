<?php
/** Reuses the real SQLite editor fixtures, then exercises both HTTP print entry points. */
namespace App\Http\Controllers { class Controller { public function middleware(...$arguments) {} } }
namespace {
    function auth($guard = null) { return new class { public function user() { return $GLOBALS['print_actor']; } }; }
    function request($key = null) { $request = $GLOBALS['print_request']; return $key === null ? $request : $request->input($key); }
    function view($name = null, $data = []) {
        if ($name !== null) return ['view'=>$name, 'data'=>$data];
        return new class { public function share($key, $value) { $GLOBALS['shared_print'][$key] = $value; } };
    }
    require __DIR__.'/order_board_items_runtime.php';
    class FixtureRestaurant extends \Illuminate\Database\Eloquent\Model { protected $table = 'resturants'; }
    class_alias(FixtureRestaurant::class, 'App\\Models\\Resturant');
    class FixturePrintResponse extends \ArrayObject {
        public $headers;
        public function __construct(array $values) { parent::__construct($values); $this->headers = new \Symfony\Component\HttpFoundation\ResponseHeaderBag(); }
        public function header($name,$value) { $this->headers->set($name,$value); return $this; }
    }
    function response() { return new class { public function view($name,$data=[]) { return new FixturePrintResponse(['view'=>$name,'data'=>$data]); } }; }
    class PDF { public static function loadView($name) { return new class { public function download($name) { return new FixturePrintResponse(['download'=>$name]); } }; } }

    use App\Services\Dashboard\OrderBoardService;
    use App\Services\Dashboard\OrderBoardPrinting;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;

    $priorChecks = $checks;
    function printing(): OrderBoardPrinting { return app(OrderBoardPrinting::class); }
    function board(): OrderBoardService { return app(OrderBoardService::class); }
    function issue($source, $id, $actorId): void { printing()->issue($source, $id, actor($actorId), board()); }
    function printHttp($source, $id, $actorId) {
        $GLOBALS['print_actor'] = actor($actorId);
        return (new \App\Http\Controllers\Dashboard\OrderBoardController())->print($source, $id, board());
    }
    function legacyPrintHttp($id, $actorId, bool $download = false) {
        $GLOBALS['print_actor'] = actor($actorId);
        $GLOBALS['print_request'] = \Illuminate\Http\Request::create('/admin/print-pdf', 'GET', ['id'=>$id] + ($download ? ['download'=>'1'] : []));
        return (new \App\Http\Controllers\Dashboard\OrderController())->print_fatora();
    }
    function legacyDownloadHttp($id, $actorId, bool $download = false) {
        $GLOBALS['print_actor'] = actor($actorId);
        $GLOBALS['print_request'] = \Illuminate\Http\Request::create('/admin/download-pdf', 'GET', ['id'=>$id] + ($download ? ['download'=>'1'] : []));
        return (new \App\Http\Controllers\Dashboard\OrderController())->download_fatora();
    }
    class RecordingBoard extends OrderBoardService {
        public array $rowLocks = [];
        public function scopedRow(string $source, int $id, $actor, bool $lock = false): object {
            $this->rowLocks[] = $lock;
            return parent::scopedRow($source, $id, $actor, $lock);
        }
    }
    class RecordingPrinting extends OrderBoardPrinting {
        public array $markerLocks = [];
        public function printed(string $source, object $row, bool $lock = false): bool {
            $this->markerLocks[] = $lock;
            return parent::printed($source, $row, $lock);
        }
    }

    seedLegacy(10);
    $before = editor()->read('legacy', 10, actor(10));
    $card = board()->detail('legacy', 10, actor(10));
    same([100,101], array_column($card['items'], 'line_id'), 'invoice lines use real saved cart IDs');
    same(true, isset($card['urls']['items']), 'unprinted invoice exposes quantity editor');
    same(true, $card['can_print'], 'active unprinted branch invoice can print');
    same(0, DB::table('branch_operation_commands')->count(), 'details preview does not issue invoice');
    $financialBefore = [DB::table('wallets')->get()->toJson(), DB::table('settings')->get()->toJson(),
        DB::table('users')->get()->toJson(), DB::table('orders')->where('id',10)->first(),
        DB::table('carts')->where('order_id',10)->get()->toJson(), DB::table('branch_stock')->get()->toJson()];
    $first = printHttp('legacy',10,10);
    same('admin.orders.board_detail', $first['view'], 'main issuance route delivers invoice');
    same('max-age=0, no-store, private', $first->headers->get('Cache-Control'), 'board print response cannot be reused from HTTP cache');
    same('no-referrer', $first->headers->get('Referrer-Policy'), 'board print response suppresses referrer');
    same(true, $first['data']['printing'], 'issued invoice is read only');
    same(1, DB::table('branch_operation_commands')->count(), 'first issue creates one durable marker');
    same('app.order.print', DB::table('branch_operation_commands')->value('kind'), 'marker kind is distinct from money operations');
    same(10, (int) DB::table('branch_operation_commands')->value('actor_id'), 'audit preserves original cashier');
    $financialAfter = [DB::table('wallets')->get()->toJson(), DB::table('settings')->get()->toJson(),
        DB::table('users')->get()->toJson(), DB::table('orders')->where('id',10)->first(),
        DB::table('carts')->where('order_id',10)->get()->toJson(), DB::table('branch_stock')->get()->toJson()];
    same(serialize($financialBefore), serialize($financialAfter), 'printing has no finance, stock, order or cart side effects');
    blocked(fn()=>editor()->save('legacy',10,payload($before),actor(10)),409,'print wins against previously opened edit');
    blocked(fn()=>editor()->read('legacy',10,actor(42)),409,'printed invoice blocks owner quantity edit too');
    blocked(fn()=>printHttp('legacy',10,10),403,'branch repeat denied by main route');
    blocked(fn()=>printHttp('legacy',10,41),403,'print marker applies to another cashier in same branch');
    blocked(fn()=>legacyPrintHttp(10,10),403,'legacy print-pdf cannot bypass repeat restriction');
    blocked(fn()=>legacyDownloadHttp(10,10,true),403,'invoice PDF export cannot bypass repeat restriction');
    blocked(fn()=>printHttp('legacy',10,11),404,'another branch cannot print invoice');
    same('admin.orders.board_detail', printHttp('legacy',10,42)['view'], 'owner can reprint main invoice');
    $legacy=legacyPrintHttp(10,40);
    same('printReceipt', $legacy['view'], 'central order-list administrator can reprint legacy invoice');
    same('max-age=0, no-store, private', $legacy->headers->get('Cache-Control'), 'legacy HTML print response cannot be cached');
    same('no-referrer', $legacy->headers->get('Referrer-Policy'), 'legacy HTML print response suppresses referrer');
    $pdf=legacyPrintHttp(10,40,true);
    same('max-age=0, no-store, private', $pdf->headers->get('Cache-Control'), 'legacy downloaded PDF cannot be cached');
    same('no-referrer', $pdf->headers->get('Referrer-Policy'), 'legacy downloaded PDF suppresses referrer');
    same(1, DB::table('branch_operation_commands')->count(), 'reprints reuse first marker without extra entries');
    $card = board()->detail('legacy',10,actor(41));
    same(null, $card['urls']['print'], 'branch card and detail do not expose repeat URL');
    same(false, isset($card['urls']['items']), 'printed invoice does not expose editor');
    same(true, board()->detail('legacy',10,actor(42))['can_print'], 'owner keeps reprint URL');

    // Existing ended orders are protected even when they predate print tracking.
    seedLegacy(11);
    foreach (['completed','cancelled','declined','rejected','expired',null,'old_unknown'] as $status) {
        DB::table('orders')->where('id',11)->update(['status'=>$status]);
        blocked(fn()=>printHttp('legacy',11,10),403,'ended legacy invoice cannot gain first branch print');
        blocked(fn()=>legacyPrintHttp(11,41),403,'ended legacy invoice cannot bypass through print-pdf');
        same(false, board()->detail('legacy',11,actor(10))['can_print'], 'ended branch card hides print');
    }
    same(1, DB::table('branch_operation_commands')->count(), 'denied ended prints do not create markers');
    same('printReceipt', legacyPrintHttp(11,42)['view'], 'owner may issue old ended invoice');
    same(2, DB::table('branch_operation_commands')->count(), 'old ended owner issue is tracked once');

    seedLegacy(16); DB::table('orders')->where('id',16)->update(['status'=>'completed']);
    blocked(fn()=>legacyDownloadHttp(16,10),403,'ended branch cannot open untracked invoice export HTML');
    blocked(fn()=>legacyDownloadHttp(16,41,true),403,'ended branch cannot download untracked invoice PDF');
    $count=DB::table('branch_operation_commands')->count();
    $export=legacyDownloadHttp(16,42);
    same('pdfOrder',$export['view'],'owner may export completed invoice HTML');
    same('max-age=0, no-store, private',$export->headers->get('Cache-Control'),'invoice export HTML is not cached');
    same('no-referrer',$export->headers->get('Referrer-Policy'),'invoice export HTML suppresses referrer');
    $export=legacyDownloadHttp(16,42,true);
    same(true,isset($export['download']),'owner may download completed invoice PDF');
    same('max-age=0, no-store, private',$export->headers->get('Cache-Control'),'invoice export PDF is not cached');
    same('no-referrer',$export->headers->get('Referrer-Policy'),'invoice export PDF suppresses referrer');
    same($count+1,DB::table('branch_operation_commands')->count(),'repeated PDF export reuses the same durable invoice marker');

    // A saved edit is the sole canonical snapshot issued immediately afterwards.
    seedLegacy(12);
    $read=editor()->read('legacy',12,actor(10)); $data=payload($read); $data['items'][0]['quantity']='1.700';
    editor()->save('legacy',12,$data,actor(10));
    $issued=printHttp('legacy',12,10);
    same(1.7,(float)$issued['data']['card']['items'][0]['quantity'],'edit-before-print snapshot includes saved native quantity');
    same('0.850',$issued['data']['card']['items'][0]['display_quantity'],'known kilogram portion prints actual entered weight');
    same('كجم',$issued['data']['card']['items'][0]['quantity_unit'],'actual printed weight has explicit kilogram unit');
    same('102.00',$issued['data']['card']['items'][0]['line_total'],'saved price is retained while canonical total changes');
    same(50,(int)DB::table('carts')->where('id',120)->value('product_feature'),'print preserves fractional portion identity');
    same('extra_clean',DB::table('carts')->where('id',120)->value('product_clean'),'print preserves cleaning identity');
    DB::table('branch_stock')->where('product_id',1)->update(['unit'=>'piece']);
    same(false,isset(board()->detail('legacy',12,actor(42))['items'][0]['display_quantity']),'fraction feature on a known piece product does not invent kilogram units');
    DB::table('branch_stock')->where('product_id',1)->update(['unit'=>'kg']);
    DB::table('branch_stock')->where('product_id',1)->delete();
    same(false,isset(board()->detail('legacy',12,actor(42))['items'][0]['display_quantity']),'unknown product unit preserves native portion display');
    DB::table('branch_stock')->insert(['branch'=>'f:100','product_id'=>1,'unit'=>'kg','quantity_units'=>2500000,'revision'=>2]);

    // The legacy HTTP endpoint is also an issuance source and locks the main editor.
    seedLegacy(13); $read=editor()->read('legacy',13,actor(10));
    same('printReceipt',legacyPrintHttp(13,10)['view'],'legacy first print is issued');
    blocked(fn()=>editor()->save('legacy',13,payload($read),actor(10)),409,'legacy print locks previously opened editor');
    blocked(fn()=>printHttp('legacy',13,10),403,'legacy issue also prevents main-route repeat');

    DB::table('go_store_orders')->where('id',200)->update(['status'=>'ready']);
    $read=editor()->read('store',200,actor(30));
    same([0],array_column(board()->detail('store',200,actor(30))['items'],'line_id'),'store invoice uses zero-based snapshot identity');
    issue('store',200,30);
    blocked(fn()=>editor()->save('store',200,payload($read),actor(30)),409,'printed store invoice locks quantity edits');
    blocked(fn()=>issue('store',200,30),403,'store branch repeat denied');
    DB::table('go_store_orders')->where('id',200)->update(['status'=>'completed']);
    issue('store',200,40);
    same(true,board()->detail('store',200,actor(40))['can_print'],'central administrator may reprint completed store invoice');

    // Persisted actor roles are re-read; an old authenticated object grants no rights.
    seedLegacy(14); $staleActor=actor(10);
    DB::table('users')->where('id',10)->update(['account_type'=>'user']);
    blocked(fn()=>printing()->issue('legacy',14,$staleActor,board()),403,'revoked actor cannot issue invoice');
    DB::table('users')->where('id',10)->update(['account_type'=>'vendor']);

    // Failure to store issuance never permits a later untracked edit or document delivery.
    DB::statement("CREATE TRIGGER fail_invoice_marker BEFORE INSERT ON branch_operation_commands BEGIN SELECT RAISE(ABORT, 'fixture marker unavailable'); END");
    $count=DB::table('branch_operation_commands')->count();
    try { printHttp('legacy',14,10); throw new \RuntimeException('expected marker failure'); }
    catch (\Illuminate\Database\QueryException $error) { same($count,DB::table('branch_operation_commands')->count(),'marker failure rolls back issuance'); }
    DB::statement('DROP TRIGGER fail_invoice_marker');
    same(true,board()->detail('legacy',14,actor(10))['can_print'],'failed issuance can safely retry before document delivery');

    // Verify the real write paths acquire the same row lock and use a current marker read.
    // SQLite cannot schedule MySQL row-lock contention, so also check emitted MySQL locking SQL.
    seedLegacy(15); $read=editor()->read('legacy',15,actor(10));
    $recordingBoard = new RecordingBoard();
    $recordingPrint = new RecordingPrinting(app(\App\Services\Dashboard\TakeawayAccess::class));
    $recordingEditor = new \App\Services\Dashboard\OrderBoardItems($recordingBoard,
        app(\App\Services\Dashboard\TakeawayCatalog::class), app(\App\Services\Dashboard\TakeawayAccess::class));
    $property=new \ReflectionProperty($recordingEditor,'printing'); $property->setAccessible(true); $property->setValue($recordingEditor,$recordingPrint);
    $recordingEditor->save('legacy',15,payload($read),actor(10));
    same([true],$recordingBoard->rowLocks,'editor write locks actual source order row');
    same([true],$recordingPrint->markerLocks,'editor checks marker with current locking read');
    $recordingPrint->issue('legacy',15,actor(10),$recordingBoard);
    same([true,true],$recordingBoard->rowLocks,'issuance locks same scoped source order row as editor');
    same([true,true],$recordingPrint->markerLocks,'issuance checks marker with current locking read');
    $connection=DB::connection(); $grammar=$connection->getQueryGrammar();
    $connection->setQueryGrammar(new \Illuminate\Database\Query\Grammars\MySqlGrammar());
    $row=DB::table('orders')->where('id',15)->first();
    $queries=$connection->pretend(fn()=>$recordingPrint->printed('legacy',$row,true));
    same(true,str_contains($queries[0]['query'],'for update'),'MySQL marker read cannot use stale repeatable-read snapshot');
    $admin=actor(40);
    $sqlBoard=board(); $sqlBoard->scopedRow('legacy',15,$admin);
    $queries=$connection->pretend(function() use ($admin,$sqlBoard) { try { $sqlBoard->scopedRow('legacy',15,$admin,true); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {} });
    same(true,count(array_filter($queries,fn($query)=>str_contains($query['query'],'from `orders`')
        && str_contains($query['query'],'for update')))===1,'MySQL order row lookup serializes edit versus print');
    $connection->setQueryGrammar($grammar);

    issue('legacy',14,10);
    $marker=DB::table('branch_operation_commands')->where('kind','app.order.print')->orderByDesc('id')->first();
    DB::table('branch_operation_commands')->where('id',$marker->id)->update(['result'=>'{}']);
    blocked(fn()=>issue('legacy',14,40),503,'corrupt marker is not treated as authorization');
    blocked(fn()=>editor()->read('legacy',14,actor(10)),503,'corrupt marker fails closed for edits');
    Schema::rename('branch_operation_commands','missing_branch_operation_commands');
    same(false,board()->detail('legacy',13,actor(10))['can_print'],'missing audit storage hides print');
    blocked(fn()=>issue('legacy',13,40),503,'missing audit storage denies all print issuance');
    blocked(fn()=>editor()->read('legacy',13,actor(10)),409,'missing audit storage denies editor');
    echo 'Order board printing runtime: '.($checks-$priorChecks)." focused assertions passed.\n";
}
