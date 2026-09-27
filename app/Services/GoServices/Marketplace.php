<?php
namespace App\Services\GoServices;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
/** Lock order: job, sorted user rows, application wallet. Money/events commit together. */
class Marketplace {
 public const ACTIVE=['booked','in_progress','awaiting_confirmation','disputed'];
 private function fail(string $message,int $status=422):void {abort($status,$message);}
 public function actor(int $id,?string $role=null,bool $lock=false):object {
  $q=DB::table('users')->where('id',$id);$u=($lock?$q->lockForUpdate():$q)->first();
  if(!$u||$u->status!=='accepted')$this->fail('الحساب غير متاح.',403);
  $customer=$u->account_type==='user'&&$u->app_scope==='go';
  $partner=$u->account_type==='delegate'&&$u->app_scope==='go_partner';
  if((!$customer&&!$partner)||($role==='customer'&&!$customer)||($role==='partner'&&!$partner))$this->fail('هذه العملية غير متاحة لهذا الحساب.',403);
  return $u;
 }
 private function job(int $id):object {$j=DB::table('go_service_jobs')->where('id',$id)->lockForUpdate()->first();if(!$j)$this->fail('الطلب غير موجود.',404);return $j;}
 private function searching(object $j):void {if($j->status!=='searching'||Carbon::parse($j->search_until)->lte(now()))$this->fail('انتهى البحث أو تم الاتفاق على هذا الطلب. حدّث الصفحة.',409);}
 private function owner(object $j,int $actor):void {if((int)$j->customer_id!==$actor)$this->fail('غير مسموح بهذا الطلب.',403);}
 private function rate(object $p):int {try{return Money::rate($p->delegate_fees??null);}catch(\InvalidArgumentException $e){$this->fail('يجب ضبط نسبة عمولة الصنايعي في الداشبورد أولًا.');}}
 public function matches(object $j,object $u):bool {
  if($u->connected!=='active')return false;
  $p=DB::table('pending_vendors')->where('id',$u->pending_vendor_id)->where('application_kind','partner')->where('status','accepted')->where('profession_key',$j->profession_key)->first();
  if(!$p||$p->lat===null||$p->lng===null)return false;
  $radius=(int)($p->work_radius_km??5);
  return ($radius>=1&&$radius<=255)&&Money::distance((float)$j->lat,(float)$j->lng,(float)$p->lat,(float)$p->lng)<=$radius;
 }
 public function create(int $actor,array $data,string $hash):int {
  $id=DB::transaction(function()use($actor,$data,$hash){
   $this->actor($actor,'customer',true);
   $old=DB::table('go_service_jobs')->where('customer_id',$actor)->where('request_key',$data['request_key'])->first();
   if($old){if(!hash_equals($old->payload_hash,$hash))$this->fail('مفتاح الطلب مستخدم لبيانات مختلفة.',409);return (int)$old->id;}
   if(DB::table('go_service_jobs')->where('customer_id',$actor)->whereIn('status',array_merge(['searching'],self::ACTIVE))->count()>=(int)config('go_services.max_open_jobs',5))$this->fail('أكمل أو ألغِ الطلبات المفتوحة أولًا.');
   return (int)DB::table('go_service_jobs')->insertGetId(['customer_id'=>$actor,'request_key'=>$data['request_key'],'payload_hash'=>$hash,'profession_key'=>$data['profession_key'],'description'=>trim($data['description']),'area'=>trim($data['area']),'address'=>trim($data['address']),'phone'=>$data['phone'],'lat'=>$data['lat'],'lng'=>$data['lng'],'photos'=>json_encode($data['photos']??[]),'scheduled_at'=>$data['scheduled_at']??null,'status'=>'searching','search_until'=>now()->addMinutes((int)config('go_services.search_minutes',60)),'next_dispatch_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
  },3);$this->distribute($id);return $id;
 }
 public function distribute(int $id,bool $force=false):void {
  DB::transaction(function()use($id,$force){
   $j=$this->job($id);if($j->status!=='searching')return;
   if(Carbon::parse($j->search_until)->lte(now())){
    DB::table('go_service_jobs')->where('id',$id)->update(['status'=>'expired','updated_at'=>now()]);
    DB::table('go_service_offers')->where('job_id',$id)->where('status','offered')->update(['status'=>'expired','updated_at'=>now()]);
    DB::table('go_service_recipients')->where('job_id',$id)->whereIn('status',['invited','quoted'])->update(['status'=>'closed','updated_at'=>now()]);
    $this->notify($id,(int)$j->customer_id,'expired','انتهت مدة البحث دون اتفاق. يمكنك إنشاء طلب جديد.');return;
   }
   DB::table('go_service_offers')->where('job_id',$id)->where('status','offered')->where('expires_at','<=',now())->update(['status'=>'expired','updated_at'=>now()]);
   if(!$force&&Carbon::parse($j->next_dispatch_at)->gt(now()))return;
   $count=DB::table('go_service_recipients')->where('job_id',$id)->count();
   $take=min((int)config('go_services.batch_size',5),max(0,(int)config('go_services.max_recipients',100)-$count));$candidates=[];
   if($take>0){
    $q=DB::table('users as u')->join('pending_vendors as p','p.id','=','u.pending_vendor_id')->where('u.account_type','delegate')->where('u.app_scope','go_partner')->where('u.status','accepted')->where('u.connected','active')->where('p.application_kind','partner')->where('p.status','accepted')->where('p.profession_key',$j->profession_key)->whereNotNull('p.lat')->whereNotNull('p.lng')
     ->whereNotExists(function($q)use($id){$q->selectRaw('1')->from('go_service_recipients as r')->whereColumn('r.partner_id','u.id')->where('r.job_id',$id);})
     ->whereNotExists(function($q){$q->selectRaw('1')->from('go_service_assignments as a')->whereColumn('a.partner_id','u.id');})->select(['u.id','u.delegate_fees','p.lat','p.lng','p.work_radius_km']);
    foreach($q->cursor() as $p){
     try{Money::rate($p->delegate_fees);}catch(\InvalidArgumentException $e){continue;}
     $radius=(int)($p->work_radius_km??5);$d=Money::distance((float)$j->lat,(float)$j->lng,(float)$p->lat,(float)$p->lng);
     if(($radius>=1&&$radius<=255)&&$d<=$radius){$candidates[]=['id'=>(int)$p->id,'distance'=>$d];usort($candidates,fn($a,$b)=>[$a['distance'],$a['id']]<=>[$b['distance'],$b['id']]);$candidates=array_slice($candidates,0,$take);}
    }
   }
   $round=(int)$j->dispatch_round+1;
   foreach($candidates as $p){DB::table('go_service_recipients')->insert(['job_id'=>$id,'partner_id'=>$p['id'],'status'=>'invited','round'=>$round,'created_at'=>now(),'updated_at'=>now()]);$this->notify($id,$p['id'],'invited','شغلانة جديدة في نطاقك. راجع الوصف والصور وأرسل عرض المصنعية.');}
   DB::table('go_service_jobs')->where('id',$id)->update(['dispatch_round'=>$round,'next_dispatch_at'=>now()->addSeconds((int)config('go_services.batch_seconds',120)),'updated_at'=>now()]);
  },3);
 }
 public function quote(int $id,int $actor,array $data):int {
  return DB::transaction(function()use($id,$actor,$data){
   $j=$this->job($id);$this->searching($j);$p=$this->actor($actor,'partner',true);
   $r=DB::table('go_service_recipients')->where('job_id',$id)->where('partner_id',$actor)->first();
   if(!$r||!in_array($r->status,['invited','quoted'],true))$this->fail('هذا الطلب غير متاح لإرسال عرض.',403);
   if(!$this->matches($j,$p)||DB::table('go_service_assignments')->where('partner_id',$actor)->exists())$this->fail('يجب أن تكون متاحًا وداخل نطاق الخدمة لإرسال عرض.');
   $price=Money::minor($data['price']);$bps=$this->rate($p);if($price<100||$price>100000000)$this->fail('قيمة العرض غير صالحة.');
   if(Money::minor($p->balance)<Money::commission($price,$bps))$this->fail('اشحن محفظتك بقيمة العمولة المطلوبة. لن تخصم إلا عند قبول العميل.');
   $old=DB::table('go_service_offers')->where('job_id',$id)->where('partner_id',$actor)->first();
   if($old){
    if($old->status!=='offered'||(int)$old->price_cents!==$price||$old->scope!==trim($data['scope'])||(int)$old->arrival_minutes!==(int)$data['arrival_minutes']||(int)$old->duration_minutes!==(int)$data['duration_minutes']||(bool)$old->materials_included!==(bool)$data['materials_included'])$this->fail('العرض مسجل بالفعل ولا يمكن تغييره من طرف واحد.',409);
    return (int)$old->id;
   }
   $offer=(int)DB::table('go_service_offers')->insertGetId(['job_id'=>$id,'partner_id'=>$actor,'price_cents'=>$price,'commission_bps'=>$bps,'scope'=>trim($data['scope']),'materials_included'=>(bool)$data['materials_included'],'arrival_minutes'=>$data['arrival_minutes'],'duration_minutes'=>$data['duration_minutes'],'status'=>'offered','expires_at'=>now()->addMinutes((int)config('go_services.offer_minutes',30))->min(Carbon::parse($j->search_until)),'created_at'=>now(),'updated_at'=>now()]);
   DB::table('go_service_recipients')->where('id',$r->id)->update(['status'=>'quoted','updated_at'=>now()]);$this->notify($id,(int)$j->customer_id,'offer:'.$offer,'وصلك عرض سعر جديد. راجع السعر ونطاق الشغل قبل الموافقة.');return $offer;
  },3);
 }
 public function accept(int $id,int $offerId,int $actor,string $method):void {
  DB::transaction(function()use($id,$offerId,$actor,$method){
   $j=$this->job($id);$this->owner($j,$actor);
   if(in_array($j->status,array_merge(self::ACTIVE,['completed']),true)&&(int)$j->accepted_offer_id===$offerId&&$j->payment_method===$method)return;
   $this->searching($j);if(!in_array($method,$this->paymentMethods(),true))$this->fail('طريقة الدفع غير مفعلة حاليًا.');
   $o=DB::table('go_service_offers')->where('id',$offerId)->where('job_id',$id)->lockForUpdate()->first();
   if(!$o||$o->status!=='offered'||Carbon::parse($o->expires_at)->lte(now()))$this->fail('هذا العرض انتهى أو لم يعد متاحًا.',409);
   DB::table('users')->whereIn('id',[$actor,$o->partner_id])->orderBy('id')->lockForUpdate()->get();$this->actor($actor,'customer');$p=$this->actor((int)$o->partner_id,'partner');
   if(!$this->matches($j,$p))$this->fail('الصنايعي غير متاح الآن. اختر عرضًا آخر.',409);
   $bps=$this->rate($p);if($bps!==(int)$o->commission_bps)$this->fail('تغيرت عمولة الصنايعي بعد إرسال العرض. اختر عرضًا آخر أو أنشئ طلبًا جديدًا.',409);
   if(DB::table('go_service_assignments')->where('partner_id',$p->id)->exists())$this->fail('الصنايعي مرتبط بطلب آخر. اختر عرضًا آخر.',409);
   $fee=Money::commission((int)$o->price_cents,$bps);if(Money::minor($p->balance)<$fee)$this->fail('رصيد الصنايعي لا يغطي العمولة. اختر عرضًا آخر أو انتظر شحن محفظته.',409);
   DB::table('go_service_assignments')->insert(['partner_id'=>$p->id,'job_id'=>$id,'created_at'=>now()]);$this->move($id,'commission',(int)$p->id,null,$fee);
   $payment=$method==='cash'?'cash_due':'unpaid';$held=0;
   if($method==='wallet'){$this->move($id,'customer_hold',$actor,null,(int)$o->price_cents);$payment='held';$held=(int)$o->price_cents;}
   DB::table('go_service_jobs')->where('id',$id)->update(['status'=>'booked','partner_id'=>$p->id,'accepted_offer_id'=>$offerId,'price_cents'=>$o->price_cents,'commission_bps'=>$bps,'commission_cents'=>$fee,'payment_method'=>$method,'payment_status'=>$payment,'held_cents'=>$held,'payment_due_at'=>$payment==='unpaid'?now()->addMinutes((int)config('go_services.payment_minutes',10)):null,'accepted_at'=>now(),'updated_at'=>now()]);
   DB::table('go_service_offers')->where('job_id',$id)->where('status','offered')->update(['status'=>'closed','updated_at'=>now()]);DB::table('go_service_offers')->where('id',$offerId)->update(['status'=>'accepted']);
   DB::table('go_service_recipients')->where('job_id',$id)->whereIn('status',['invited','quoted'])->update(['status'=>'closed','updated_at'=>now()]);
   foreach(DB::table('go_service_recipients')->where('job_id',$id)->pluck('partner_id') as $pid)$this->notify($id,(int)$pid,'booked',(int)$pid===(int)$p->id?'وافق العميل على عرضك. تم خصم العمولة مرة واحدة؛ راجع حالة الدفع قبل بدء العمل.':'تم الاتفاق مع صنايعي آخر وأغلق هذا الطلب.');
  },3);
 }
 public function reject(int $id,int $offerId,int $actor):void {
  $changed=DB::transaction(function()use($id,$offerId,$actor){
   $j=$this->job($id);$this->owner($j,$actor);$this->searching($j);$o=DB::table('go_service_offers')->where('id',$offerId)->where('job_id',$id)->first();
   if(!$o)$this->fail('العرض غير موجود.',404);if($o->status==='rejected')return false;if(!in_array($o->status,['offered','expired'],true))$this->fail('العرض لم يعد متاحًا.',409);
   DB::table('go_service_offers')->where('id',$offerId)->update(['status'=>'rejected','updated_at'=>now()]);DB::table('go_service_recipients')->where('job_id',$id)->where('partner_id',$o->partner_id)->update(['status'=>'declined','updated_at'=>now()]);$this->notify($id,(int)$o->partner_id,'rejected','لم يقبل العميل عرضك. لم يتم خصم أي عمولة.');return true;
  },3);if($changed)$this->distribute($id,true);
 }
 public function skip(int $id,int $actor):void {
  $changed=DB::transaction(function()use($id,$actor){
   $j=$this->job($id);$this->searching($j);$this->actor($actor,'partner');$r=DB::table('go_service_recipients')->where('job_id',$id)->where('partner_id',$actor)->first();
   if(!$r)$this->fail('الطلب غير متاح.',403);if($r->status==='declined')return false;
   DB::table('go_service_recipients')->where('id',$r->id)->update(['status'=>'declined','updated_at'=>now()]);DB::table('go_service_offers')->where('job_id',$id)->where('partner_id',$actor)->where('status','offered')->update(['status'=>'withdrawn','updated_at'=>now()]);return true;
  },3);if($changed)$this->distribute($id,true);
 }
 public function transition(int $id,int $actor,string $state,string $reason='',bool $system=false):void {
  DB::transaction(function()use($id,$actor,$state,$reason,$system){
   $j=$this->job($id);
   if($system){if($actor!==0||$state!=='cancelled')throw new \LogicException('Invalid system transition');if($j->status!=='booked'||$j->payment_status!=='unpaid'||Carbon::parse($j->payment_due_at)->gt(now()))return;}else{$this->actor($actor);}
   $owner=$system||(int)$j->customer_id===$actor;$partner=(int)$j->partner_id===$actor;if(!$owner&&!$partner)$this->fail('غير مسموح بهذا الطلب.',403);if($j->status===$state)return;
   if($state==='cancelled'){
    if(!in_array($j->status,['searching','booked'],true))$this->fail('بعد بدء العمل استخدم الاعتراض بدل الإلغاء.');
    if($j->status==='booked'){
     DB::table('users')->whereIn('id',[$j->customer_id,$j->partner_id])->orderBy('id')->lockForUpdate()->get();$this->move($id,'commission_refund',null,(int)$j->partner_id,(int)$j->commission_cents);
     if($j->payment_method==='wallet'&&(int)$j->held_cents>0)$this->move($id,'customer_refund',null,(int)$j->customer_id,(int)$j->held_cents);
    }
    $refund=!in_array($j->payment_method,[null,'cash','wallet'],true)&&(int)$j->held_cents>0;
    DB::table('go_service_jobs')->where('id',$id)->update(['status'=>'cancelled','close_reason'=>$reason,'payment_status'=>$refund?'refund_pending':($j->payment_method==='wallet'?'refunded':'cancelled'),'held_cents'=>$refund?$j->held_cents:0,'updated_at'=>now()]);
    DB::table('go_service_offers')->where('job_id',$id)->where('status','offered')->update(['status'=>'closed','updated_at'=>now()]);DB::table('go_service_recipients')->where('job_id',$id)->whereIn('status',['invited','quoted'])->update(['status'=>'closed','updated_at'=>now()]);DB::table('go_service_assignments')->where('job_id',$id)->delete();
   }elseif($state==='in_progress'&&$partner&&$j->status==='booked'){
    if(!in_array($j->payment_status,['cash_due','held'],true))$this->fail('يجب تأكيد الدفع على الخادم قبل بدء العمل.');DB::table('go_service_jobs')->where('id',$id)->update(['status'=>$state,'started_at'=>now(),'updated_at'=>now()]);
   }elseif($state==='awaiting_confirmation'&&$partner&&$j->status==='in_progress'){
    DB::table('go_service_jobs')->where('id',$id)->update(['status'=>$state,'updated_at'=>now()]);
   }elseif($state==='completed'&&$owner&&$j->status==='awaiting_confirmation'){
    if(!in_array($j->payment_status,['cash_due','held'],true))$this->fail('الدفع تحت المراجعة. لا يمكن صرف المبلغ الآن.');
    if($j->payment_method!=='cash'){
     if((int)$j->held_cents!==(int)$j->price_cents)$this->fail('مبلغ التسوية غير مطابق. يلزم مراجعة الدعم.',409);
     DB::table('users')->where('id',$j->partner_id)->lockForUpdate()->first();
     // Gross payout: the commission was already charged at agreement.
     $this->move($id,'payout',null,(int)$j->partner_id,(int)$j->held_cents);
    }
    DB::table('go_service_jobs')->where('id',$id)->update(['status'=>'completed','payment_status'=>'paid','held_cents'=>0,'completed_at'=>now(),'updated_at'=>now()]);DB::table('go_service_assignments')->where('job_id',$id)->delete();
   }elseif($state==='disputed'&&in_array($j->status,['in_progress','awaiting_confirmation'],true)){
    DB::table('go_service_jobs')->where('id',$id)->update(['status'=>'disputed','close_reason'=>$reason,'updated_at'=>now()]);
   }else{$this->fail('لا يمكن تنفيذ هذا الإجراء في حالة الطلب الحالية.',409);}
   $this->notify($id,(int)$j->customer_id,$state,'تم تحديث طلب الخدمة: '.$state);if($j->partner_id)$this->notify($id,(int)$j->partner_id,$state,'تم تحديث طلب الخدمة: '.$state);
  },3);
 }
 /** Caller holds job/user locks. Unique ledger keys make retries harmless. */
 private function move(int $job,string $kind,?int $from,?int $to,int $amount):void {
  $key='job:'.$job.':'.$kind;if(DB::table('go_service_ledger')->where('event_key',$key)->exists())return;if($amount<0)throw new \LogicException('Negative transfer');$decimal=Money::decimal($amount);
  if($from&&$amount>0&&!DB::table('users')->where('id',$from)->where('balance','>=',$decimal)->decrement('balance',$decimal))$this->fail('رصيد المحفظة غير كافٍ. لم يتم تأكيد الاتفاق أو خصم العمولة.');
  if($to&&$amount>0&&!DB::table('users')->where('id',$to)->increment('balance',$decimal))throw new \RuntimeException('Wallet owner missing');
  if(in_array($kind,['commission','commission_refund'],true)){
   if(config('settings.cache.enabled',false)||config('settings.default_repository','database')!=='database')throw new \RuntimeException('GO services require the uncached database settings repository for atomic app balance updates.');
   $s=DB::table('settings')->where('group','general')->where('name','app_balance')->lockForUpdate()->first();if(!$s)throw new \RuntimeException('Main application wallet setting missing');
   $current=Money::minor(json_decode($s->payload,true,512,JSON_THROW_ON_ERROR));$next=$current+($kind==='commission'?$amount:-$amount);DB::table('settings')->where('id',$s->id)->update(['payload'=>json_encode(Money::decimal($next))]);
  }
  $wallet=$amount>0?DB::table('wallets')->insertGetId(['from_user'=>$from,'to_user'=>$to,'status'=>'completed','payment'=>'wallet','type'=>'transfer','amount'=>$decimal,'created_at'=>now(),'updated_at'=>now()]):null;
  DB::table('go_service_ledger')->insert(['job_id'=>$job,'event_key'=>$key,'kind'=>$kind,'from_user'=>$from,'to_user'=>$to,'amount_cents'=>$amount,'wallet_id'=>$wallet,'created_at'=>now()]);
 }
 public function paymentMethods():array {
  $methods=['cash','wallet'];$c=config('go_services.paymob',[]);
  if(!empty($c['enabled'])&&!empty($c['secret_key'])&&!empty($c['public_key'])&&!empty($c['hmac_secret']))foreach($c['methods']??[] as $m=>$id)if(filter_var($id,FILTER_VALIDATE_INT)&&(int)$id>0)$methods[]=$m;
  return $methods;
 }
 public function notify(int $job,int $user,string $event,string $message):void {DB::table('go_service_outbox')->insertOrIgnore(['event_key'=>'job:'.$job.':'.$event.':user:'.$user,'user_id'=>$user,'job_id'=>$job,'message'=>$message,'available_at'=>now(),'created_at'=>now()]);}
 public function read(int $id,int $actor):array {
  $this->actor($actor);$j=DB::table('go_service_jobs')->where('id',$id)->first();if(!$j)$this->fail('الطلب غير موجود.',404);
  $owner=(int)$j->customer_id===$actor;$selected=(int)$j->partner_id===$actor;$invited=DB::table('go_service_recipients')->where('job_id',$id)->where('partner_id',$actor)->first();
  if(!$owner&&!$selected&&!$invited)$this->fail('غير مسموح بهذا الطلب.',403);$private=$owner||$selected;
  $offers=DB::table('go_service_offers as o')->join('users as u','u.id','=','o.partner_id')->where('o.job_id',$id)->when(!$owner,fn($q)=>$q->where('o.partner_id',$actor))->select('o.*','u.name')->orderBy('o.price_cents')->orderBy('o.id')->get();
  return ['id'=>(int)$j->id,'profession_key'=>$j->profession_key,'status'=>$j->status,'description'=>$j->description,'area'=>$j->area,'scheduled_at'=>$j->scheduled_at?Carbon::parse($j->scheduled_at)->toIso8601String():null,
   'location'=>$private?['lat'=>(float)$j->lat,'lng'=>(float)$j->lng,'address'=>$j->address]:null,'phone'=>$private?$j->phone:null,
   'partner_id'=>$j->partner_id?(int)$j->partner_id:null,'partner_name'=>$j->partner_id?DB::table('users')->where('id',$j->partner_id)->value('name'):null,'partner_phone'=>$private&&$j->partner_id?DB::table('users')->where('id',$j->partner_id)->value('mobile'):null,
   'accepted_offer_id'=>$j->accepted_offer_id?(int)$j->accepted_offer_id:null,'price'=>Money::decimal((int)$j->price_cents),'commission'=>$selected?Money::decimal((int)$j->commission_cents):null,
   'payment_method'=>$j->payment_method,'payment_status'=>$j->payment_status,'payment_due_at'=>$j->payment_due_at?Carbon::parse($j->payment_due_at)->toIso8601String():null,'search_until'=>Carbon::parse($j->search_until)->toIso8601String(),'dispatch_round'=>(int)$j->dispatch_round,'recipient_status'=>$invited?->status,'photo_count'=>count(json_decode($j->photos?:'[]',true)),
   'offers'=>$offers->map(fn($o)=>['id'=>(int)$o->id,'partner_id'=>(int)$o->partner_id,'name'=>$o->name,'price'=>Money::decimal((int)$o->price_cents),'scope'=>$o->scope,'materials_included'=>(bool)$o->materials_included,'arrival_minutes'=>(int)$o->arrival_minutes,'duration_minutes'=>(int)$o->duration_minutes,'status'=>$o->status==='offered'&&Carbon::parse($o->expires_at)->lte(now())?'expired':$o->status,'expires_at'=>Carbon::parse($o->expires_at)->toIso8601String(),'commission'=>(int)$o->partner_id===$actor?Money::decimal(Money::commission((int)$o->price_cents,(int)$o->commission_bps)):null])->all()];
 }
 public function listing(int $actor,string $scope='open',int $page=1):array {
  $u=$this->actor($actor);$partner=$u->account_type==='delegate';$q=DB::table('go_service_jobs as j');
  if($partner){
   $q->join('go_service_recipients as r','r.job_id','=','j.id')->where('r.partner_id',$actor);
   if($scope==='new')$q->where('j.status','searching')->whereIn('r.status',['invited','quoted']);
   elseif($scope==='current')$q->where('j.partner_id',$actor)->whereIn('j.status',self::ACTIVE);
   elseif($scope==='history')$q->where(function($q)use($actor){$q->whereIn('j.status',['completed','cancelled','expired'])->orWhere('r.status','declined')->orWhere(function($q)use($actor){$q->whereNotNull('j.partner_id')->where('j.partner_id','!=',$actor);});});
   else $q->where(function($q)use($actor){$q->where(function($q){$q->where('j.status','searching')->whereIn('r.status',['invited','quoted']);})->orWhere(function($q)use($actor){$q->where('j.partner_id',$actor)->whereIn('j.status',self::ACTIVE);});});
  }else{$q->where('j.customer_id',$actor);if($scope!=='all')$q->whereIn('j.status',$scope==='history'?['completed','cancelled','expired']:array_merge(['searching'],self::ACTIVE));}
  $ids=$q->orderByDesc('j.id')->offset(($page-1)*20)->limit(21)->pluck('j.id');$rate=null;if($partner){try{$rate=Money::decimal($this->rate($u));}catch(\Throwable $e){}}
  return ['items'=>$ids->take(20)->map(fn($id)=>$this->read((int)$id,$actor))->all(),'next_page'=>$ids->count()>20?$page+1:null,'balance'=>(string)$u->balance,'commission_rate'=>$rate,'payment_methods'=>$this->paymentMethods()];
 }
}
