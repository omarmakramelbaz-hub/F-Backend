<?php
namespace App\Services\GoServices;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
class Payments {
 public function checkout(int $jobId,int $actor):array {
  $market=new Marketplace();$user=$market->actor($actor,'customer');
  abort_unless(filter_var($user->email,FILTER_VALIDATE_EMAIL),422,'أضف بريدًا إلكترونيًا صحيحًا إلى حسابك للدفع.');
  $payment=DB::transaction(function()use($jobId,$actor,$market){
   $j=DB::table('go_service_jobs')->where('id',$jobId)->lockForUpdate()->first();abort_unless($j&&(int)$j->customer_id===$actor,403);
   abort_unless($j->status==='booked'&&$j->payment_status==='unpaid',409,'الدفع غير مطلوب أو تم تأكيده بالفعل.');
   abort_if(!$j->payment_due_at||Carbon::parse($j->payment_due_at)->lte(now()),409,'انتهت مهلة الدفع.');
   abort_unless(in_array($j->payment_method,$market->paymentMethods(),true)&&!in_array($j->payment_method,['cash','wallet'],true),422,'الدفع الإلكتروني غير مفعل لهذه الطريقة.');
   $old=DB::table('go_service_payments')->where('job_id',$jobId)->first();if($old&&$old->status==='pending')return $old;
   abort_if($old&&$old->status==='creating'&&Carbon::parse($old->updated_at)->gt(now()->subMinutes(2)),409,'جارٍ تجهيز صفحة الدفع؛ أعد المحاولة بعد قليل.');
   $data=['reference'=>$old?->reference?:(string)Str::uuid(),'job_id'=>$jobId,'integration_id'=>(int)config('go_services.paymob.methods.'.$j->payment_method),'amount_cents'=>$j->price_cents,'expires_at'=>$j->payment_due_at,'status'=>'creating','updated_at'=>now()];
   if($old){DB::table('go_service_payments')->where('id',$old->id)->update($data);$id=$old->id;}else{$id=DB::table('go_service_payments')->insertGetId($data+['created_at'=>now()]);}
   return DB::table('go_service_payments')->where('id',$id)->first();
  },3);
  if($payment->status==='pending')return $this->link($payment);
  $names=preg_split('/\s+/',trim($user->name),2);
  try{
   $response=Http::timeout(20)->acceptJson()->withHeaders(['Authorization'=>'Token '.preg_replace('/^Token\s+/i','',(string)config('go_services.paymob.secret_key'))])->post('https://accept.paymob.com/v1/intention/',[
    'amount'=>(int)$payment->amount_cents,'currency'=>'EGP','payment_methods'=>[(int)$payment->integration_id],'special_reference'=>$payment->reference,'expiration'=>max(60,now()->diffInSeconds(Carbon::parse($payment->expires_at),false)),
    'notification_url'=>route('go-services.paymob-webhook'),'redirection_url'=>route('go-services.payment-return'),
    'metadata'=>['go_service_job_id'=>$jobId,'go_service_payment_reference'=>$payment->reference],
    'billing_data'=>['first_name'=>$names[0]?:'GO','last_name'=>$names[1]??$names[0],'email'=>$user->email,'phone_number'=>'+20'.ltrim((string)$user->mobile,'0'),'apartment'=>'NA','floor'=>'NA','street'=>'NA','building'=>'NA','shipping_method'=>'NA','postal_code'=>'NA','city'=>'NA','state'=>'NA','country'=>'EG'],
   ])->throw()->json();
   if(empty($response['client_secret'])||empty($response['intention_order_id']))throw new \RuntimeException('Incomplete gateway response');
   DB::transaction(function()use($response,$jobId,$payment){
    $j=DB::table('go_service_jobs')->where('id',$jobId)->lockForUpdate()->first();
    DB::table('go_service_payments')->where('id',$payment->id)->update(['gateway_order_id'=>(string)$response['intention_order_id'],'checkout_secret'=>Crypt::encryptString($response['client_secret']),'status'=>$j->status==='booked'?'pending':'cancelled','updated_at'=>now()]);
   },3);
  }catch(\Throwable $e){
   // Never log keys, client secrets, customer data, or gateway responses.
   DB::table('go_service_payments')->where('id',$payment->id)->where('status','creating')->update(['status'=>'failed','updated_at'=>now()]);
   abort(502,'تعذر تجهيز الدفع. لم يتم تأكيد دفع إلكتروني؛ أعد المحاولة أو ألغِ الحجز قبل بدء العمل.');
  }
  $payment=DB::table('go_service_payments')->where('id',$payment->id)->first();abort_unless($payment->status==='pending',409,'تم إغلاق الطلب.');return $this->link($payment);
 }
 private function link(object $p):array {
  abort_if(Carbon::parse($p->expires_at)->lte(now()),409,'انتهت مهلة الدفع.');
  return ['url'=>'https://accept.paymob.com/unifiedcheckout/?'.http_build_query(['publicKey'=>config('go_services.paymob.public_key'),'clientSecret'=>Crypt::decryptString($p->checkout_secret)]),'expires_at'=>Carbon::parse($p->expires_at)->toIso8601String()];
 }
 public function callback(array $object,string $hmac):void {
  abort_unless(PaymobHmac::valid($object,$hmac,(string)config('go_services.paymob.hmac_secret')),403,'Invalid signature');
  $payment=DB::table('go_service_payments')->where('gateway_order_id',(string)($object['order']['id']??''))->first();abort_unless($payment,404,'Payment not found');
  abort_unless((string)($object['integration_id']??'')===(string)$payment->integration_id&&(string)($object['currency']??'')==='EGP'&&(string)($object['amount_cents']??'')===(string)$payment->amount_cents&&array_key_exists('is_live',$object)&&PaymobHmac::truth($object['is_live'])===(bool)config('go_services.paymob.is_live'),422,'Payment attributes mismatch');
  $transaction=(string)($object['id']??'');abort_unless(preg_match('/^\d{1,30}$/D',$transaction),422,'Invalid transaction');
  $reversed=PaymobHmac::truth($object['is_refunded']??false)||PaymobHmac::truth($object['is_voided']??false);
  if(!$reversed&&(!PaymobHmac::truth($object['success']??false)||PaymobHmac::truth($object['pending']??true)||PaymobHmac::truth($object['is_auth']??false)||PaymobHmac::truth($object['error_occured']??true)||(!PaymobHmac::truth($object['is_capture']??false)&&!PaymobHmac::truth($object['is_standalone_payment']??false))))return;
  DB::transaction(function()use($object,$payment,$transaction,$reversed){
   $j=DB::table('go_service_jobs')->where('id',$payment->job_id)->lockForUpdate()->first();$receipt=DB::table('go_service_payment_receipts')->where('transaction_id',$transaction)->first();$market=new Marketplace();
   if($reversed){
    if($receipt&&(int)$receipt->job_id===(int)$j->id){DB::table('go_service_payment_receipts')->where('id',$receipt->id)->update(['status'=>'reversal_review']);DB::table('go_service_jobs')->where('id',$j->id)->update(['payment_status'=>'review','updated_at'=>now()]);$market->notify((int)$j->id,(int)$j->customer_id,'payment-review','حدث تعديل على عملية الدفع. المبلغ تحت مراجعة الدعم ولن يصرف تلقائيًا.');}return;
   }
   if($receipt)return;
   $valid=$j->status==='booked'&&$j->payment_status==='unpaid'&&Carbon::parse($j->payment_due_at)->gt(now());
   DB::table('go_service_payment_receipts')->insert(['transaction_id'=>$transaction,'job_id'=>$j->id,'amount_cents'=>$payment->amount_cents,'status'=>$valid?'held':'refund_due','created_at'=>now()]);
   DB::table('go_service_ledger')->insert(['job_id'=>$j->id,'event_key'=>'gateway:'.$transaction,'kind'=>$valid?'gateway_receipt':'gateway_refund_due','amount_cents'=>$payment->amount_cents,'created_at'=>now()]);
   if(!$valid){$market->notify((int)$j->id,(int)$j->customer_id,'late-payment:'.$transaction,'وصل دفع لطلب مغلق أو سبق دفعه. سجل المبلغ للاسترداد؛ لن يعاد حجز الطلب أو خصم العمولة.');return;}
   DB::table('go_service_payments')->where('id',$payment->id)->update(['status'=>'succeeded','updated_at'=>now()]);DB::table('go_service_jobs')->where('id',$j->id)->update(['payment_status'=>'held','held_cents'=>$payment->amount_cents,'updated_at'=>now()]);
   $market->notify((int)$j->id,(int)$j->customer_id,'paid','تم تأكيد الدفع. يصرف المبلغ للصنايعي بعد تأكيد إتمام العمل.');$market->notify((int)$j->id,(int)$j->partner_id,'paid','تم تأكيد دفع العميل ويمكن بدء العمل. لم تخصم عمولة إضافية.');
  },3);
 }
}
