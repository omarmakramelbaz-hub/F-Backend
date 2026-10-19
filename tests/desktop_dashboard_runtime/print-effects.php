<?php
// Continues the disposable original-service fixture after intentional generation replacement.
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardPrintEffects,DesktopDashboardPrintOwnership,DesktopDashboardReconciliation,TakeawayService};
$effects=app(DesktopDashboardPrintEffects::class);
DB::table('desktop_dashboard_local_state')->where('device_id',$device)->update(['coverage'=>'{"print_protocol":1}']);
$central='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));
$pdo->exec('CREATE DATABASE `'.$central.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$central.'`'));
foreach($pdo->query('SHOW TABLES FROM `'.$other.'`')->fetchAll(PDO::FETCH_COLUMN) as $table){$pdo->exec('CREATE TABLE `'.$central.'`.`'.$table.'` LIKE `'.$other.'`.`'.$table.'`');$pdo->exec('INSERT INTO `'.$central.'`.`'.$table.'` SELECT * FROM `'.$other.'`.`'.$table.'`');}
$automatic=$sale();$automatic['customer_phone']='01099999999';$automatic['quote_hash']=app(TakeawayService::class)->quote($automatic,$actor)['quote_hash'];$automaticResult=$journal->execute($device,$automatic['idempotency_key'],1,'takeaway.checkout',['values'=>$automatic],[],fn()=>app(TakeawayService::class)->checkout($automatic,$actor,[],true));
$automaticIntents=$ledger->read($device,$automatic['idempotency_key'],1,'f:100');$effect=$automaticIntents[0];
check(count($automaticIntents)===1&&$effect['local_references']['receipt']===$automaticResult['receipt']['id'],'original journal automatically captures immutable receipt within its original business transaction');
$list=$effects->listing($device,['include_manual'=>true]);check(in_array($effect['effect_id'],array_column($list['effects'],'effect_id'),true),'native listing advertises an unclaimed original receipt');
$claim=['effect_id'=>$effect['effect_id'],'claim_token'=>(string)Str::uuid(),'native_session_id'=>(string)Str::uuid()];
$prepared=$effects->prepare($device,$claim);
check(hash('sha256',$prepared['html'])===$prepared['render_sha256']&&str_contains($prepared['html'],'data:image/png;base64,')&&!preg_match('~<script\b~i',$prepared['html']),'original Arabic receipt renders from immutable snapshot with embedded branding and no script or network image');
check($effects->prepare($device,$claim)===$prepared,'lost prepare reply can recover the exact claimed render without creating another permission');
$otherClaim=$claim;$otherClaim['claim_token']=(string)Str::uuid();denied(fn()=>$effects->prepare($device,$otherClaim),409,'another renderer or supervisor cannot steal an already claimed effect');
$invoke=$claim+array_intersect_key($prepared,array_flip(['enrollment_id','origin_command_id','actor_id','branch','revision','snapshot_sha256','render_sha256']));$invoke['invocation_id']=(string)Str::uuid();
$changed=$invoke;$changed['snapshot_sha256']=str_repeat('0',64);denied(fn()=>$effects->burn($device,$changed),409,'native burn refuses a changed snapshot binding');
$burn=$effects->burn($device,$invoke);
check(strlen($burn['permit'])===64&&$burn['invocation_id']===$invoke['invocation_id'],'one committed burn returns one volatile native invocation permit');
denied(fn()=>$effects->burn($device,$invoke),409,'a lost burn response never reissues a permit even for the identical native attempt');
$metadata=json_decode(DB::table('takeaway_till_entries')->where('id',$effect['local_references']['sale_till_entry.primary'])->value('metadata'),true);
check($metadata['receipt_print']['consumed_at']!==null,'original receipt grant is consumed atomically with the effect burn');
$outcome=array_diff_key($invoke,array_flip(['snapshot_sha256','render_sha256']))+['permit_hash'=>hash('sha256',$burn['permit']),'state'=>'invoked','reason'=>'accepted'];
$ack=$effects->outcome($device,$outcome);check($effects->outcome($device,$outcome)===$ack,'immutable native outcome retries idempotently');
$changed=$outcome;$changed['state']='review';denied(fn()=>$effects->outcome($device,$changed),409,'a later callback cannot rewrite a committed invocation outcome');
check(count($effects->pendingOutcomes($device)['outcomes'])===1,'outcome remains durable pending central acknowledgement');
$serverAck=array_intersect_key($ack,array_flip(['effect_id','invocation_id','state','receipt_sha256']));$bad=$serverAck;$bad['receipt_sha256']=str_repeat('f',64);
denied(fn()=>$effects->acknowledge($device,$bad),409,'native acknowledgement refuses a changed server receipt hash');
check($effects->acknowledge($device,$serverAck)===$serverAck&&$effects->pendingOutcomes($device)['outcomes']===[],'exact central acknowledgement clears only its durable matching outcome');
$manual=$effects->resolve($device,['path'=>'/admin/takeaway/receipts/'.$automaticResult['receipt']['id'].'/print','query'=>[],'request_id'=>(string)Str::uuid()]);
check($manual['effect_id']!==$effect['effect_id'],'management reprint creates a separate audited effect and retains the original burn');
$reprint=$effects->prepare($device,['effect_id'=>$manual['effect_id'],'claim_token'=>(string)Str::uuid(),'native_session_id'=>(string)Str::uuid()]);
check($reprint['snapshot_sha256']===$prepared['snapshot_sha256'],'controlled reprint retains the original immutable receipt contents');
DB::table('desktop_dashboard_local_state')->where('device_id',$device)->update(['state'=>'held']);
denied(fn()=>$effects->prepare($device,$claim),409,'refresh fence blocks new print claims');
check($effects->pendingOutcomes($device)['outcomes']===[],'outcome recovery remains readable while generation is held');
DB::table('desktop_dashboard_local_state')->where('device_id',$device)->update(['state'=>'ready']);
DB::table('users')->where('id',1)->update(['status'=>'disabled']);denied(fn()=>$effects->listing($device),403,'persisted disabled actor cannot enumerate local print data');DB::table('users')->where('id',1)->update(['status'=>'accepted']);
DB::table('desktop_dashboard_devices')->where('id',$device)->update(['enrollment_hash'=>str_repeat('d',64)]);denied(fn()=>$effects->listing($device),409,'fresh enrollment revocation overrides private descriptor authority');DB::table('desktop_dashboard_devices')->where('id',$device)->update(['enrollment_hash'=>str_repeat('b',64)]);
// Prove automatic intent failure rolls back the original financial journal, not just a print promise.
$rollbackBefore=[DB::table('takeaway_orders')->count(),DB::table('desktop_dashboard_commands')->count(),DB::table('takeaway_tills')->value('balance_cents')];
DB::statement("CREATE TRIGGER fasakhansta_dashboard_effects.reject_intent BEFORE INSERT ON fasakhansta_dashboard_effects.print_ledger_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test atomic automatic intent failure'");
try{$v=$sale();$journal->execute($device,$v['idempotency_key'],1,'takeaway.checkout',['values'=>$v],[],fn()=>app(TakeawayService::class)->checkout($v,$actor,[],true));throw new RuntimeException('Expected automatic intent rollback.');}catch(\Illuminate\Database\QueryException $error){}finally{DB::statement('DROP TRIGGER fasakhansta_dashboard_effects.reject_intent');}
check($rollbackBefore===[DB::table('takeaway_orders')->count(),DB::table('desktop_dashboard_commands')->count(),DB::table('takeaway_tills')->value('balance_cents')],'automatic intent failure rolls back cash, sale and original journal together');

// Separate original central replay commits ownership with business work and typed ID drift.
$command=DB::table('desktop_dashboard_commands')->where('device_id',$device)->where('command_id',$automatic['idempotency_key'])->first();
$saved=json_decode(Crypt::decryptString($command->local_result_cipher),true,512,JSON_THROW_ON_ERROR);
$envelope=['command_id'=>$command->command_id,'actor_id'=>1,'route_name'=>$command->route_name,'payload'=>json_decode(Crypt::decryptString($command->command_cipher),true,512,JSON_THROW_ON_ERROR),
    'local_result'=>$saved['result'],'local_references'=>$saved['references'],'dependencies'=>json_decode($command->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($command->created_at,'UTC')->toIso8601String(),
    'print_ownership'=>$ledger->ownership($device,$command->command_id)];
config(['database.connections.mysql.database'=>$central,'desktop_dashboard.local'=>false]);DB::purge();
require $application.'/database/migrations/2026_10_20_000001_create_desktop_dashboard_print_ownership.php';(new \CreateDesktopDashboardPrintOwnership)->up();
DB::statement('ALTER TABLE takeaway_orders AUTO_INCREMENT=20000');DB::statement('ALTER TABLE takeaway_till_entries AUTO_INCREMENT=30000');
$centralDevice=DB::table('desktop_dashboard_devices')->where('id',$device)->first();
$receipt=app(DesktopDashboardReconciliation::class)->ingest($centralDevice,$envelope);
$ownership=DB::table('desktop_dashboard_print_ownership')->where('effect_id',$effect['effect_id'])->first();
check($receipt['committed']===true&&$ownership!==null,'central original replay atomically commits exact native print ownership');
$mapping=json_decode($ownership->server_references,true);check($mapping['receipt']>=20000&&$mapping['receipt']!==$effect['local_references']['receipt']&&$mapping['sale_till_entry.primary']>=30000,'central print mapping binds exact typed original outputs across deliberate integer drift');
$metadata=json_decode(DB::table('takeaway_till_entries')->where('id',$mapping['sale_till_entry.primary'])->value('metadata'),true);
check($metadata['receipt_print']['consumed_at']!==null&&$receipt['result']['first_print_url']===null,'central replay creates no second usable first receipt grant');
check(app(DesktopDashboardReconciliation::class)->ingest($centralDevice,$envelope)===$receipt&&DB::table('desktop_dashboard_print_ownership')->where('effect_id',$effect['effect_id'])->count()===1,'lost central replay response returns the exact saved result without another ownership row or sale');
foreach(['revision','claim_token','native_session_id','permit_hash','reason'] as $field){$bad=$outcome;unset($bad[$field]);
    try{app(DesktopDashboardPrintOwnership::class)->outcome($centralDevice,$bad);throw new RuntimeException('Expected missing print receipt field.');}
    catch(\Illuminate\Validation\ValidationException $error){check(true,'central outcome requires native receipt field '.$field);}
    catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error){check($error->getStatusCode()===422,'central outcome requires exact receipt field '.$field);}
    check(DB::table('desktop_dashboard_print_ownership')->where('effect_id',$effect['effect_id'])->value('outcome')===null,'missing '.$field.' cannot occupy the first immutable central outcome');
}
$bad=$outcome;$bad['revision']=2;denied(fn()=>app(DesktopDashboardPrintOwnership::class)->outcome($centralDevice,$bad),403,'central outcome binds immutable intent revision before accepting a first receipt');
$bad=$outcome;$bad['unreviewed']='extra';denied(fn()=>app(DesktopDashboardPrintOwnership::class)->outcome($centralDevice,$bad),422,'central outcome rejects unreviewed extra receipt fields');
$centralAck=app(DesktopDashboardPrintOwnership::class)->outcome($centralDevice,$outcome);check($centralAck===$serverAck,'central immutable outcome acknowledgement matches native canonical receipt hash');
check(app(DesktopDashboardPrintOwnership::class)->outcome($centralDevice,$outcome)===$centralAck,'central outcome exact retries remain idempotent');
$changed=$outcome;$changed['state']='review';denied(fn()=>app(DesktopDashboardPrintOwnership::class)->outcome($centralDevice,$changed),409,'central outcome cannot overwrite an acknowledged invocation');
$changed=$envelope;$changed['print_ownership'][0]['effect_id']=str_repeat('0',64);denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($centralDevice,$changed),409,'replay envelope cannot replace device print ownership after commit');
config(['database.connections.mysql.database'=>$other,'desktop_dashboard.local'=>true]);DB::purge();
// Lost burn reply: private review recovers durable evidence but cannot reconstruct permission.
$reviewSale=$sale();$journal->execute($device,$reviewSale['idempotency_key'],1,'takeaway.checkout',['values'=>$reviewSale],[],fn()=>app(TakeawayService::class)->checkout($reviewSale,$actor,[],true));
$reviewIntent=$ledger->read($device,$reviewSale['idempotency_key'],1,'f:100')[0];$reviewClaim=['effect_id'=>$reviewIntent['effect_id'],'claim_token'=>(string)Str::uuid(),'native_session_id'=>(string)Str::uuid()];
$reviewPrepare=$effects->prepare($device,$reviewClaim);$reviewInvoke=$reviewClaim+array_intersect_key($reviewPrepare,array_flip(['enrollment_id','origin_command_id','actor_id','branch','revision','snapshot_sha256','render_sha256']))+['invocation_id'=>(string)Str::uuid()];
$effects->burn($device,$reviewInvoke);$reviewRequest=array_diff_key($reviewInvoke,array_flip(['snapshot_sha256','render_sha256']))+['reason'=>'lost-burn-reply'];
$reviewAck=$effects->review($device,$reviewRequest);check($reviewAck['state']==='review'&&!isset($reviewAck['permit'])&&$reviewAck['receipt']['permit_hash']!==str_repeat('0',64),'lost burn reply recovers a review receipt without issuing native invocation permission');
denied(fn()=>$effects->burn($device,$reviewInvoke),409,'reviewed uncertain burn remains permanently non-replayable');
check($effects->review($device,$reviewRequest)===$reviewAck,'native review receipt survives an exact recovery retry');

// Dining original action outputs and unpaid bill use the same one-use native protocol.
$table=app(\App\Services\Dashboard\PosServiceTable::class)->configure(['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'name'=>'طاولة طباعة','capacity'=>4,'active'=>true],$actor)['table'];
$dine=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'table_id'=>$table['id'],'items'=>[['product_id'=>1,'quantity_mode'=>'weight','quantity'=>'0.250']],'discount'=>'0.00','waiter_name'=>'الكاشير','guest_count'=>2];
$dine['quote_hash']=app(\App\Services\Dashboard\PosServiceTicket::class)->quote('dine',$dine,$actor)['quote_hash'];
$dineSaved=$journal->execute($device,$dine['idempotency_key'],1,'dining.save',['values'=>$dine],[],fn()=>app(\App\Services\Dashboard\PosServiceTicket::class)->save('dine',$dine,$actor));
$send=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'expected_revision'=>$dineSaved['ticket']['revision'],'action'=>'send_kitchen'];
$dineSent=$journal->execute($device,$send['idempotency_key'],1,'dining.action',['parameters'=>['id'=>$dineSaved['ticket']['id']],'values'=>$send],[],fn()=>app(\App\Services\Dashboard\PosServiceTicket::class)->action('dine',$dineSaved['ticket']['id'],$send,$actor));
$dineIntent=$ledger->read($device,$send['idempotency_key'],1,'f:100')[0];
check($dineIntent['kind']==='dining_kitchen'&&!isset($dineIntent['local_references']['print_job.primary']),'original dining kitchen action captures exact immutable revision without fabricating a phone receiver job');
$dinePrepare=$effects->prepare($device,['effect_id'=>$dineIntent['effect_id'],'claim_token'=>(string)Str::uuid(),'native_session_id'=>(string)Str::uuid()]);
check(str_contains($dinePrepare['html'],'data-dashboard-receipt="pos-kitchen"'),'original dining kitchen view renders from its captured snapshot');
$bill=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'expected_revision'=>$dineSent['ticket']['revision'],'action'=>'request_bill'];
$billResult=$journal->execute($device,$bill['idempotency_key'],1,'dining.action',['parameters'=>['id'=>$dineSaved['ticket']['id']],'values'=>$bill],[],fn()=>app(\App\Services\Dashboard\PosServiceTicket::class)->action('dine',$dineSaved['ticket']['id'],$bill,$actor));
$billIntent=$ledger->read($device,$bill['idempotency_key'],1,'f:100')[0];check($billIntent['kind']==='bill_receipt'&&$billIntent['revision']===$billResult['ticket']['revision'],'request-bill original transaction freezes its unpaid bill revision');
$resolved=$effects->resolve($device,['path'=>'/admin/dining/tickets/'.$dineSaved['ticket']['id'].'/print','query'=>[],'request_id'=>(string)Str::uuid()]);
check($resolved['effect_id']===$billIntent['effect_id'],'original unpaid dining URL resolves to the exact immutable bill effect');
$billPrepare=$effects->prepare($device,['effect_id'=>$billIntent['effect_id'],'claim_token'=>(string)Str::uuid(),'native_session_id'=>(string)Str::uuid()]);
check(str_contains($billPrepare['html'],'data-dashboard-receipt='),'original unpaid bill view renders without opening its mutable GET route');
// Root-approved initial shift owner authority retains the original management permission.
$review=app(\App\Services\Dashboard\BranchShiftClosing::class)->data(['branch'=>'f:100'],$actor);
$closing=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'previous_closing_id'=>$review['previous_closing_id'],'review_token'=>$review['review_token'],'counted_cash'=>'25.00'];
$facts=app(\App\Services\Dashboard\BranchShiftClosing::class)->desktopReview($closing,$actor);
$closeResult=$journal->execute($device,$closing['idempotency_key'],1,'branch-shifts.close',['values'=>$closing,'facts'=>['shift'=>$facts]],[],fn()=>app(\App\Services\Dashboard\BranchShiftClosing::class)->close($closing,$actor));
$closeIntent=$ledger->read($device,$closing['idempotency_key'],1,'f:100')[0];check($closeIntent['kind']==='shift_report','the authorized original closing actor captures an initial immutable shift report');
$closePrepare=$effects->prepare($device,['effect_id'=>$closeIntent['effect_id'],'claim_token'=>(string)Str::uuid(),'native_session_id'=>(string)Str::uuid()]);
check(str_contains($closePrepare['html'],'قفلة الوردية')&&str_contains($closePrepare['html'],'SHIFT-'),'original shift view includes frozen report number and sequence');
// Old session recovery does not fabricate a permit and does not strand effect 501 onward.
$list=$effects->listing($device,['native_session_id'=>(string)Str::uuid()]);check($list['attention']>=3,'restart listing moves old claims and burns to review without automatic reuse');
check(count($effects->pendingOutcomes($device)['outcomes'])>=3,'restart-generated review receipts remain durable for later central reconciliation');

// Real branch-device kitchen ownership suppresses the corresponding central queue row.
config(['desktop_dashboard.device_id'=>$cashierDevice]);DB::table('desktop_dashboard_local_state')->where('device_id',$cashierDevice)->update(['coverage'=>'{"print_protocol":1}']);
$cashier=\App\Models\User::withoutGlobalScopes()->findOrFail(10);
$branchPhone=$phone;$branchPhone['idempotency_key']=(string)Str::uuid();$branchPhone['customer_phone']='01011111111';
$branchPhone['delivery_quote_hash']=app(\App\Services\Dashboard\PhoneDelivery::class)->quote($branchPhone,$cashier)['delivery']['delivery_quote_hash'];$branchPhone['quote_hash']=app(\App\Services\Dashboard\PosServiceTicket::class)->quote('phone',$branchPhone,$cashier)['quote_hash'];
$queueCentral='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));$pdo->exec('CREATE DATABASE `'.$queueCentral.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$queueCentral.'`'));
foreach($pdo->query('SHOW TABLES FROM `'.$other.'`')->fetchAll(PDO::FETCH_COLUMN) as $table){$pdo->exec('CREATE TABLE `'.$queueCentral.'`.`'.$table.'` LIKE `'.$other.'`.`'.$table.'`');$pdo->exec('INSERT INTO `'.$queueCentral.'`.`'.$table.'` SELECT * FROM `'.$other.'`.`'.$table.'`');}
$branchSaved=$journal->execute($cashierDevice,$branchPhone['idempotency_key'],10,'phone-orders.save',['values'=>$branchPhone],[],fn()=>app(\App\Services\Dashboard\PosServiceTicket::class)->save('phone',$branchPhone,$cashier,true));
$branchIntent=$ledger->read($cashierDevice,$branchPhone['idempotency_key'],10,'f:100')[0];check($branchIntent['kind']==='phone_kitchen','the original enrolled branch receiver captures its phone kitchen output');
$branchPrepare=$effects->prepare($cashierDevice,['effect_id'=>$branchIntent['effect_id'],'claim_token'=>(string)Str::uuid(),'native_session_id'=>(string)Str::uuid()]);check($branchPrepare['actor_id']===10,'a current exact branch receiver can prepare its original kitchen copy');
$row=DB::table('desktop_dashboard_commands')->where('command_id',$branchPhone['idempotency_key'])->first();$saved=json_decode(Crypt::decryptString($row->local_result_cipher),true);
$branchEnvelope=['command_id'=>$row->command_id,'actor_id'=>10,'route_name'=>$row->route_name,'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true),'local_result'=>$saved['result'],
    'local_references'=>$saved['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String(),'print_ownership'=>$ledger->ownership($cashierDevice,$row->command_id)];
config(['database.connections.mysql.database'=>$queueCentral,'desktop_dashboard.local'=>false]);DB::purge();(new \CreateDesktopDashboardPrintOwnership)->up();
DB::statement('ALTER TABLE pos_service_tickets AUTO_INCREMENT=40000');DB::statement('ALTER TABLE pos_service_kitchen_tickets AUTO_INCREMENT=50000');DB::statement('ALTER TABLE pos_branch_print_jobs AUTO_INCREMENT=60000');
$queueDevice=DB::table('desktop_dashboard_devices')->where('id',$cashierDevice)->first();$queueReceipt=app(DesktopDashboardReconciliation::class)->ingest($queueDevice,$branchEnvelope);
$queueOwnership=DB::table('desktop_dashboard_print_ownership')->where('effect_id',$branchIntent['effect_id'])->first();$queueMap=json_decode($queueOwnership->server_references,true);
check($queueMap['ticket']>=40000&&$queueMap['kitchen.primary']>=50000&&$queueMap['print_job.primary']>=60000,'phone ownership maps each typed immutable ticket, kitchen and queue identity across deliberate ID drift');
check(DB::table('pos_branch_print_jobs')->where('id',$queueMap['print_job.primary'])->value('status')==='device_owned','central branch queue is suppressed inside the original replay transaction before any receiver can observe pending');
check(app(DesktopDashboardReconciliation::class)->ingest($queueDevice,$branchEnvelope)===$queueReceipt,'central phone retry preserves one exact shadow job and immutable response');
config(['database.connections.mysql.database'=>$other,'desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>$device]);DB::purge();

// Original combined courier collection freezes one batch copy and all named receipt slots.
$company=app(\App\Services\Dashboard\DeliveryCompanies::class)->save(['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'name'=>'شركة الطباعة','phone'=>'01022222222','active'=>true],$actor)['company'];
$batchPhone=$phone;$batchPhone['idempotency_key']=(string)Str::uuid();$batchPhone['send_to_kitchen']=false;$batchPhone['customer_phone']='01033333333';
$batchPhone['delivery_quote_hash']=app(\App\Services\Dashboard\PhoneDelivery::class)->quote($batchPhone,$actor)['delivery']['delivery_quote_hash'];$batchPhone['quote_hash']=app(\App\Services\Dashboard\PosServiceTicket::class)->quote('phone',$batchPhone,$actor)['quote_hash'];
$batchTicket=app(\App\Services\Dashboard\PosServiceTicket::class)->save('phone',$batchPhone,$actor,true)['ticket'];
$dispatch=app(\App\Services\Dashboard\PhoneDeliveryBoard::class)->dispatch(['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'ticket_id'=>$batchTicket['id'],'expected_revision'=>$batchTicket['revision'],'company_id'=>$company['id']],$actor);
$batchValues=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'items'=>[['id'=>$batchTicket['id'],'revision'=>$dispatch['revision'],'quote_hash'=>$batchPhone['quote_hash']]],'courier_name'=>'مندوب الاختبار','total'=>$batchTicket['total'],'payment_method'=>'cash','cash_received'=>'100.00','payment_confirmed'=>true];
$batchCentral='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));$pdo->exec('CREATE DATABASE `'.$batchCentral.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$batchCentral.'`'));
foreach($pdo->query('SHOW TABLES FROM `'.$other.'`')->fetchAll(PDO::FETCH_COLUMN) as $table){$pdo->exec('CREATE TABLE `'.$batchCentral.'`.`'.$table.'` LIKE `'.$other.'`.`'.$table.'`');$pdo->exec('INSERT INTO `'.$batchCentral.'`.`'.$table.'` SELECT * FROM `'.$other.'`.`'.$table.'`');}
$batchResult=$journal->execute($device,$batchValues['idempotency_key'],1,'phone-orders.finish-batch',['values'=>$batchValues],[],fn()=>app(\App\Services\Dashboard\PhoneDeliveryBoard::class)->finish($batchValues,$actor));
$batchIntent=$ledger->read($device,$batchValues['idempotency_key'],1,'f:100')[0];check($batchIntent['kind']==='delivery_batch'&&$batchIntent['local_references']['delivery_batch']===$batchResult['batch']['id']&&isset($batchIntent['local_references']['receipt.0']),'original combined collection captures one immutable batch and its exact named sale receipt slots');
$batchResolved=$effects->resolve($device,['path'=>'/admin/phone-orders/batches/'.$batchResult['batch']['id'].'/print','query'=>[],'request_id'=>(string)Str::uuid()]);check($batchResolved['effect_id']===$batchIntent['effect_id'],'original courier batch print URL resolves without reading a mutable print GET');
$batchPrepare=$effects->prepare($device,['effect_id'=>$batchIntent['effect_id'],'claim_token'=>(string)Str::uuid(),'native_session_id'=>(string)Str::uuid()]);check(str_contains($batchPrepare['html'],'مندوب الاختبار'),'original combined collection view renders its frozen courier snapshot');

// Combined replay binds the batch and disables every independently generated central sale grant.
$row=DB::table('desktop_dashboard_commands')->where('command_id',$batchValues['idempotency_key'])->first();$saved=json_decode(Crypt::decryptString($row->local_result_cipher),true);
$batchEnvelope=['command_id'=>$row->command_id,'actor_id'=>1,'route_name'=>$row->route_name,'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true),'local_result'=>$saved['result'],
    'local_references'=>$saved['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String(),'print_ownership'=>$ledger->ownership($device,$row->command_id)];
config(['database.connections.mysql.database'=>$batchCentral,'desktop_dashboard.local'=>false]);DB::purge();(new \CreateDesktopDashboardPrintOwnership)->up();
DB::statement('ALTER TABLE takeaway_orders AUTO_INCREMENT=70000');DB::statement('ALTER TABLE phone_delivery_batches AUTO_INCREMENT=80000');
$batchDevice=DB::table('desktop_dashboard_devices')->where('id',$device)->first();$batchReceipt=app(DesktopDashboardReconciliation::class)->ingest($batchDevice,$batchEnvelope);
$batchOwnership=DB::table('desktop_dashboard_print_ownership')->where('effect_id',$batchIntent['effect_id'])->first();$batchMap=json_decode($batchOwnership->server_references,true);
check($batchMap['delivery_batch']>=80000&&$batchMap['receipt.0']>=70000,'combined collection central ownership maps exact batch and submitted-order receipt slots across ID drift');
$nestedEntry=DB::table('takeaway_till_entries')->where('order_id',$batchMap['receipt.0'])->where('kind','sale')->first();$nestedGrant=json_decode($nestedEntry->metadata,true)['receipt_print'];
check($nestedGrant['consumed_at']!==null,'device-owned combined collection creates no independent usable central first-sale grants');
check(app(DesktopDashboardReconciliation::class)->ingest($batchDevice,$batchEnvelope)===$batchReceipt,'combined collection central replay returns one immutable result after a lost response');
// Fresh persisted enrollment/actor facts override an earlier accepted print ownership receipt.
DB::table('desktop_dashboard_devices')->where('id',$device)->update(['enrollment_hash'=>str_repeat('e',64)]);
$oldOutcome=$outcome;$oldOutcome['effect_id']=$batchIntent['effect_id'];$oldOutcome['origin_command_id']=$batchValues['idempotency_key'];
denied(fn()=>app(DesktopDashboardPrintOwnership::class)->outcome($batchDevice,$oldOutcome),409,'central print outcome rejects changed persisted enrollment even for a previously valid ownership tuple');
config(['database.connections.mysql.database'=>$other,'desktop_dashboard.local'=>true]);DB::purge();

// Real native HTTP outbox advertises print capability separately from financial counts.
$reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$controlPort=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);
$controlOrigin='http://127.0.0.1:'.$controlPort;$browserToken=bin2hex(random_bytes(32));$controlToken=bin2hex(random_bytes(32));
$controlEnv=getenv();$controlEnv['DB_DATABASE']=$other;$controlEnv['DESKTOP_DASHBOARD_DEVICE_ID']=$device;$controlEnv['DESKTOP_DASHBOARD_ORIGIN']=$controlOrigin;
$controlEnv['DESKTOP_DASHBOARD_TOKEN']=$browserToken;$controlEnv['DESKTOP_DASHBOARD_CONTROL_TOKEN']=$controlToken;
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$controlPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/print-control.log','a'],['file',$profile.'/print-control.log','a']],$webPipes,$application,$controlEnv);
$controlRequest=function()use($controlOrigin,$browserToken,$controlToken){$context=stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/json'."\r\n".'X-Fasakhansta-Desktop: '.$browserToken."\r\n".'X-Fasakhansta-Control: '.$controlToken,'content'=>'{"action":"pending"}','ignore_errors'=>true,'timeout'=>5]]);$body=@file_get_contents($controlOrigin.'/_desktop/control',false,$context);return json_decode($body?:'null',true);};
try{
    for($wait=0;$wait<100;$wait++){$state=$controlRequest();if(is_array($state))break;usleep(50000);}
    check(($state['print_protocol']??null)===1,'the actual private native HTTP outbox explicitly advertises active print protocol one');
    check(array_keys($state['counts'])===['pending','conflicts','acknowledged'],'print capability never changes the established financial count contract');
    DB::table('desktop_dashboard_local_state')->where('device_id',$device)->update(['coverage'=>'{}']);$legacyState=$controlRequest();
    check(($legacyState['print_protocol']??null)===0&&$legacyState['counts']===$state['counts'],'legacy snapshots explicitly advertise protocol zero so native upgrades can refresh before print activation');
}finally{DB::table('desktop_dashboard_local_state')->where('device_id',$device)->update(['coverage'=>'{"print_protocol":1}']);fclose($webPipes[0]);proc_terminate($web);proc_close($web);}

// Owner race: an original URL never becomes a new copy after automatic native claim/burn.
$beforeCopies=DB::table('fasakhansta_dashboard_effects.print_ledger_reprints')->count();
$dineOriginal=$effects->resolve($device,['path'=>'/admin/dining/kitchen/'.$dineIntent['local_references']['kitchen.primary'].'/print','query'=>[],'request_id'=>(string)Str::uuid(),'explicit_reprint'=>false]);
check($dineOriginal['effect_id']===$dineIntent['effect_id']&&DB::table('fasakhansta_dashboard_effects.print_ledger_reprints')->count()===$beforeCopies,'owner original kitchen URL after a native claim still resolves the same effect and never manufactures a second copy');
$manualKitchen=$effects->resolve($device,['path'=>'/admin/dining/kitchen/'.$dineIntent['local_references']['kitchen.primary'].'/print','query'=>[],'request_id'=>(string)Str::uuid(),'explicit_reprint'=>true]);
check($manualKitchen['effect_id']!==$dineIntent['effect_id'],'only a distinct explicit management reprint request creates another kitchen copy');
$manualClaim=['effect_id'=>$manualKitchen['effect_id'],'claim_token'=>(string)Str::uuid(),'native_session_id'=>(string)Str::uuid()];$manualPrepare=$effects->prepare($device,$manualClaim);
$manualInvoke=$manualClaim+array_intersect_key($manualPrepare,array_flip(['enrollment_id','origin_command_id','actor_id','branch','revision','snapshot_sha256','render_sha256']))+['invocation_id'=>(string)Str::uuid()];$manualBurn=$effects->burn($device,$manualInvoke);
$manualOutcome=array_diff_key($manualInvoke,array_flip(['snapshot_sha256','render_sha256']))+['permit_hash'=>hash('sha256',$manualBurn['permit']),'state'=>'review','reason'=>'printer-uncertain'];$effects->outcome($device,$manualOutcome);
$manualAudit=collect($effects->audit($device)['audit'])->firstWhere('effect_id',$manualKitchen['effect_id']);
check($manualAudit['reprint']===true&&$manualAudit['state']==='review'&&$manualAudit['outcome']['invocation_id']===$manualInvoke['invocation_id'],'manual reprint retains an explicit durable uncertain-outcome audit without fabricating a central business receipt');
check(!in_array($manualKitchen['effect_id'],array_column($effects->pendingOutcomes($device)['outcomes'],'effect_id'),true),'manual copy audit is retained locally and never sent as a nonexistent central business owner');
$nextGeneration='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));$pdo->exec('CREATE DATABASE `'.$nextGeneration.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$nextGeneration.'`'));
foreach($pdo->query('SHOW TABLES FROM `'.$other.'`')->fetchAll(PDO::FETCH_COLUMN) as $table){$pdo->exec('CREATE TABLE `'.$nextGeneration.'`.`'.$table.'` LIKE `'.$other.'`.`'.$table.'`');$pdo->exec('INSERT INTO `'.$nextGeneration.'`.`'.$table.'` SELECT * FROM `'.$other.'`.`'.$table.'`');}
config(['database.connections.mysql.database'=>$nextGeneration]);DB::purge();DB::table('desktop_dashboard_local_state')->where('device_id',$device)->update(['snapshot_id'=>(string)Str::uuid()]);$pdo->exec('DROP DATABASE `'.$other.'`');
$afterAudit=collect($effects->audit($device)['audit'])->firstWhere('effect_id',$manualKitchen['effect_id']);check($afterAudit===$manualAudit,'manual reprint audit and uncertain outcome survive generation replacement and deletion of the original business database');
denied(fn()=>$effects->burn($device,$manualInvoke),409,'retained manual reprint burn never becomes usable again after dataset replacement');

require __DIR__.'/print-authority.php';
