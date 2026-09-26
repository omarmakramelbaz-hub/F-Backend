<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponses;
use App\Services\GoServices\Marketplace;
use App\Services\GoServices\Payments;
use App\Services\GoServices\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Intervention\Image\Facades\Image;
class GoServiceMarketplaceController extends Controller {
 use ApiResponses;
 public function __construct(private Marketplace $market){}
 private function ready(bool $write=false):void {abort_unless(Schema::hasTable('go_service_jobs'),503,'تحديث نظام عروض الخدمات لم يفعل على الخادم بعد.');if($write)abort_unless(config('go_services.enabled'),503,'استقبال طلبات وعروض جديدة متوقف مؤقتًا.');}
 private function actor():int {$this->ready();$id=(int)auth('api')->id();$this->market->actor($id);return $id;}
 private function decorate(array $j):array {$j['photos']=[];for($i=0;$i<$j['photo_count'];$i++)$j['photos'][]=URL::temporarySignedRoute('go-services.photo',now()->addMinutes(10),['job'=>$j['id'],'index'=>$i]);unset($j['photo_count']);return $j;}
 private function result(int $id){try{(new Outbox())->flush(10);}catch(\Throwable $e){report($e);}return $this->successResponse($this->decorate($this->market->read($id,$this->actor())));}
 public function capabilities(){return $this->successResponse(['schema_ready'=>Schema::hasTable('go_service_jobs'),'enabled'=>(bool)config('go_services.enabled'),'version'=>1,'payment_methods'=>$this->market->paymentMethods(),'currency'=>'EGP']);}
 public function index(Request $r){$r->validate(['scope'=>'nullable|in:open,new,current,history,all','page'=>'nullable|integer|min:1|max:10000']);$d=$this->market->listing($this->actor(),$r->input('scope','open'),(int)$r->input('page',1));$d['items']=array_map(fn($j)=>$this->decorate($j),$d['items']);return $this->successResponse($d);}
 public function show(int $job){return $this->successResponse($this->decorate($this->market->read($job,$this->actor())));}
 public function store(Request $r){
  $this->ready(true);$actor=$this->actor();$this->market->actor($actor,'customer');$professions=array_diff(array_keys(PartnerApplicationController::professions()),['delivery_courier','store_owner']);
  $d=$r->validate(['request_key'=>'required|string|alpha_dash|min:16|max:64','profession_key'=>'required|in:'.implode(',',$professions),'description'=>'required|string|min:10|max:2000','area'=>'required|string|min:2|max:150','address'=>'required|string|min:5|max:500','phone'=>'required|string|regex:/^\+?[0-9]{10,15}$/','lat'=>'required|numeric|between:-90,90','lng'=>'required|numeric|between:-180,180','scheduled_at'=>'nullable|date|after:now','photos'=>'nullable|array|max:5','photos.*'=>'image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=4096,max_height=4096']);
  $paths=[];foreach($r->file('photos',[]) as $photo){$bytes=(string)Image::make($photo->getRealPath())->orientate()->resize(1800,1800,function($c){$c->aspectRatio();$c->upsize();})->encode('jpg',85);$path='go-service-jobs/'.$actor.'/'.$d['request_key'].'/'.hash('sha256',$bytes).'.jpg';Storage::disk('local')->put($path,$bytes);$paths[]=$path;}
  $d['photos']=$paths;if(!empty($d['scheduled_at']))$d['scheduled_at']=\Carbon\Carbon::parse($d['scheduled_at'])->setTimezone(config('app.timezone','UTC'))->format('Y-m-d H:i:s');$d['lat']=number_format((float)$d['lat'],7,'.','');$d['lng']=number_format((float)$d['lng'],7,'.','');ksort($d);
  return $this->result($this->market->create($actor,$d,hash('sha256',json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))));
 }
 public function quote(Request $r,int $job){$this->ready(true);$d=$r->validate(['price'=>['required','regex:/^\d{1,7}(\.\d{1,2})?$/'],'scope'=>'required|string|min:5|max:2000','materials_included'=>'required|boolean','arrival_minutes'=>'required|integer|min:5|max:10080','duration_minutes'=>'required|integer|min:5|max:43200']);$this->market->quote($job,$this->actor(),$d);return $this->result($job);}
 public function accept(Request $r,int $job,int $offer){$this->ready(true);$r->validate(['payment_method'=>'required|string']);$this->market->accept($job,$offer,$this->actor(),$r->payment_method);return $this->result($job);}
 public function reject(int $job,int $offer){$this->market->reject($job,$offer,$this->actor());return $this->result($job);}
 public function skip(int $job){$this->market->skip($job,$this->actor());return $this->result($job);}
 public function status(Request $r,int $job){
  $r->validate(['status'=>'required|in:in_progress,awaiting_confirmation,completed,cancelled,disputed','reason'=>'required_if:status,cancelled,disputed|nullable|string|min:3|max:500','cash_paid'=>'nullable|boolean']);$actor=$this->actor();
  if($r->status==='completed'){$d=$this->market->read($job,$actor);if($d['payment_method']==='cash')abort_unless($r->boolean('cash_paid'),422,'أكد دفع المبلغ المتفق عليه للصنايعي نقدًا.');}
  $this->market->transition($job,$actor,$r->status,$r->input('reason','')?:'');return $this->result($job);
 }
 public function checkout(int $job){return $this->successResponse((new Payments())->checkout($job,$this->actor()));}
 public function webhook(Request $r){$this->ready();$r->validate(['obj'=>'required|array']);(new Payments())->callback($r->input('obj'),(string)$r->query('hmac'));try{(new Outbox())->flush(5);}catch(\Throwable $e){report($e);}return response()->json(['received'=>true]);}
 public function photo(int $job,int $index){$p=json_decode(DB::table('go_service_jobs')->where('id',$job)->value('photos')?:'[]',true);abort_unless(isset($p[$index])&&str_starts_with($p[$index],'go-service-jobs/')&&Storage::disk('local')->exists($p[$index]),404);return Storage::disk('local')->response($p[$index],null,['Cache-Control'=>'private, max-age=300','X-Content-Type-Options'=>'nosniff']);}
 public function paymentReturn(){return response('<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GO</title><body><h2>ارجع إلى التطبيق لمتابعة حالة الدفع</h2><p>هذه الصفحة ليست تأكيدًا للدفع. يتم التحقق من العملية على الخادم.</p></body></html>')->header('Content-Security-Policy',"default-src 'none'; frame-ancestors 'none'");}
}
