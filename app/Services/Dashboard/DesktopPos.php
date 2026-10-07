<?php
namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Schema, Validator};
use Illuminate\Support\Str;

class DesktopPos
{
    private TakeawayAccess $access;
    public function __construct(TakeawayAccess $access) { $this->access = $access; }
    public function ready(): void
    {
        abort_unless(config('desktop_pos.enabled') && Schema::hasTable('desktop_pos_operations') && $this->access->ready(), 503, 'برنامج الكمبيوتر لم يتم تفعيله بعد.');
    }
    public function issue(string $branch, string $name, $actor): string
    {
        $this->ready(); $actor = $this->access->actor($actor); $b = $this->access->branch($branch, $actor);
        abort_unless($b['kind'] === 'f' && $this->access->permissions($actor)['can_checkout'], 403);
        $code = strtoupper(bin2hex(random_bytes(8)));
        DB::table('desktop_pos_devices')->insert(['id'=>(string)Str::uuid(), 'branch'=>$branch, 'actor_id'=>$actor->id,
            'name'=>$name, 'pair_hash'=>hash('sha256',$code), 'pair_expires_at'=>now('UTC')->addMinutes(10), 'enabled'=>true,
            'created_at'=>now('UTC'), 'updated_at'=>now('UTC')]);
        return $code;
    }
    public function pair(string $code): array
    {
        $this->ready();
        return DB::transaction(function () use ($code) {
            $device = DB::table('desktop_pos_devices')->where('pair_hash', hash('sha256',strtoupper(str_replace([' ', '-'], '', $code))))->lockForUpdate()->first();
            abort_unless($device && $device->enabled && !$device->token_hash && Carbon::parse($device->pair_expires_at,'UTC')->isFuture(), 401, 'كود الربط غير صالح أو انتهت مدته.');
            $actor = $this->access->actor((object)['id'=>$device->actor_id]); $this->access->branch($device->branch,$actor);
            abort_unless($this->access->permissions($actor)['can_checkout'],403);
            $token = bin2hex(random_bytes(32));
            DB::table('desktop_pos_devices')->where('id',$device->id)->update(['token_hash'=>hash('sha256',$token), 'pair_hash'=>null, 'pair_expires_at'=>null, 'last_seen_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return ['device_id'=>$device->id, 'token'=>$token, 'branch'=>$device->branch, 'name'=>$device->name];
        },3);
    }
    public function device(string $token): object
    {
        $this->ready(); abort_unless(preg_match('/^[a-f0-9]{64}$/D',$token),401);
        $d = DB::table('desktop_pos_devices')->where('token_hash',hash('sha256',$token))->where('enabled',true)->first();
        abort_unless($d,401,'تم إيقاف الجهاز أو يحتاج إعادة ربط.');
        $a = $this->access->actor((object)['id'=>$d->actor_id]); $this->access->branch($d->branch,$a);
        abort_unless($this->access->permissions($a)['can_checkout'],403); return $d;
    }
    public function snapshot(object $device): array
    {
        return DB::transaction(function () use ($device) {
            $actor = $this->access->actor((object)['id'=>$device->actor_id]); $branch = $this->access->branch($device->branch,$actor,true);
            $summary = app(TakeawayService::class)->summary($device->branch,$actor); $products=[];
            for ($page=1; ; $page++) {
                $listing = app(TakeawayCatalog::class)->listing(['branch'=>$device->branch,'page'=>$page,'per_page'=>100],$actor);
                $rows = app(TakeawayCatalog::class)->rows($branch,array_column($listing['items'],'id'),false);
                foreach ($listing['items'] as $p) {
                    if (!$p['available']) continue; $variants=[];
                    foreach (array_merge([['id'=>'','label'=>'الأساسي']],$p['options']) as $o) {
                        try {
                            $mode=$p['quantity_mode']==='select'?'piece':$p['quantity_mode'];
                            $line=app(TakeawayCatalog::class)->line($branch,$rows[$p['id']],['quantity_millis'=>1000,'quantity_mode'=>$mode,'option_id'=>$o['id']]);
                            $line=app(BranchStock::class)->quoteLines($device->branch,[$line])[0];
                            $inventory=$line['inventory']??['version'=>1,'branch'=>$device->branch,'product_id'=>$p['id'],'feature_id'=>0,'recipe_id'=>null,'revision'=>0,'unit'=>null,'components'=>[]];
                            if (empty($inventory['recipe_id']) && empty($inventory['components']) && empty($inventory['stock_source'])) $inventory['stock_source']='unconfigured';
                            $mode=($inventory['unit']??'')==='kg'?'weight':((($inventory['unit']??'')==='piece')?'piece':$p['quantity_mode']);
                            $variants[]=['option_id'=>$line['option_id'],'label'=>$line['option_label']?:'الأساسي','unit_price_cents'=>$line['unit_price_cents'],'quantity_mode'=>$mode,'inventory'=>$inventory];
                        } catch (\Symfony\Component\HttpKernel\Exception\HttpException | \Illuminate\Validation\ValidationException $e) { /* Unconfigured variants remain unavailable offline. */ }
                    }
                    if ($variants) $products[]=['id'=>$p['id'],'name'=>$p['name'],'unit'=>$p['unit'],'category_id'=>$p['category_id'],'stock'=>$p['stock']??null,'variants'=>$variants];
                }
                if ($page >= $listing['pagination']['last_page']) break;
            }
            $id=(string)Str::uuid(); $payload=['id'=>$id,'branch'=>$branch,'actor'=>['id'=>(int)$actor->id,'name'=>$actor->name],
                'generated_at'=>now('UTC')->toIso8601String(),'tax_bps'=>\App\Services\GoServices\Money::rate($summary['register']['tax_rate']),
                'service_bps'=>app(PosServiceTable::class)->ready()?app(PosServiceTable::class)->policy($device->branch)['service_bps']:0,
                'can_discount'=>$summary['permissions']['can_manage'],'products'=>$products,'categories'=>$listing['categories']];
            DB::table('desktop_pos_snapshots')->insert(['id'=>$id,'device_id'=>$device->id,'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            $this->seen($device); return $payload;
        },3);
    }
    public function ingest(object $device, array $event): array
    {
        $v=Validator::make($event, ['id'=>'required|uuid','order_id'=>'required|uuid','snapshot_id'=>'required|uuid',
            'kind'=>'required|in:save,kitchen,bill,sale,cancel','revision'=>'required|integer|min:1','occurred_at'=>'required|date',
            'data'=>'required|array','data.channel'=>'required|in:takeaway,dine,phone','data.items'=>'required|array|min:1|max:100',
            'data.customer_name'=>'nullable|string|max:100','data.customer_phone'=>'nullable|string|max:30','data.address'=>'nullable|string|max:500',
            'data.table_name'=>'nullable|string|max:100','data.notes'=>'nullable|string|max:500','data.delivery_cents'=>'required|integer|min:0|max:100000000',
            'data.discount'=>'nullable|string|max:14','data.discount_reason'=>'nullable|string|max:500', 'data.cash_received'=>'nullable|string|max:14',
            'data.total_cents'=>'required|integer|min:0|max:100000000','data.cancel_reason'=>'nullable|string|max:500'])->validate();
        // Fingerprint the entire input, including unknown keys, so a replay never changes meaning.
        $hash=PosServiceTicket::fingerprint($event); $when=Carbon::parse($v['occurred_at'])->setTimezone('UTC');
        abort_unless($when->lte(now('UTC')->addMinutes(5)),422,'ساعة الكمبيوتر غير صحيحة.');
        return DB::transaction(function () use ($device,$v,$hash,$when) {
            $actor=$this->access->actor((object)['id'=>$device->actor_id]); $branch=$this->access->branch($device->branch,$actor,true);
            $locked=DB::table('desktop_pos_devices')->where('id',$device->id)->lockForUpdate()->first(); abort_unless($locked && $locked->enabled,401);
            $old=DB::table('desktop_pos_operations')->where('device_id',$device->id)->where('request_key',$v['id'])->first();
            if ($old) { abort_unless(hash_equals($old->request_hash,$hash),409,'رقم العملية مستخدم لبيانات مختلفة.'); return json_decode($old->result,true)+['replayed'=>true]; }
            $stored=DB::table('desktop_pos_snapshots')->where('id',$v['snapshot_id'])->where('device_id',$device->id)->first(); abort_unless($stored,409,'نسخة المينيو غير معروفة لهذا الجهاز.');
            $snapshot=json_decode($stored->payload,true); abort_unless($snapshot['branch']['value']===$device->branch,403);
            abort_unless($when->gte(Carbon::parse($snapshot['generated_at'])->subMinutes(5)),422,'تاريخ العملية يسبق تنزيل المينيو.');
            $data=$v['data']; $cart=app(TakeawayService::class)->canonicalCart(['branch'=>$device->branch]+$data);
            if ($cart['discount_cents']>0) abort_unless($this->access->permissions($actor)['can_manage'],403);
            $quote=app(DesktopPosQuote::class)->build($snapshot,$cart,$data['channel'],(int)$data['delivery_cents']);
            abort_unless($quote['total_cents']===(int)$data['total_cents'],409,'إجمالي الفاتورة لا يطابق المينيو المحفوظ.');
            if ($data['channel']==='phone') abort_unless(trim($data['customer_name']??'')!=='' && preg_match('/^[+0-9 ()-]{6,30}$/D',$data['customer_phone']??'') && trim($data['address']??'')!=='',422,'أكمل اسم العميل ورقمه وعنوانه.');
            if ($data['channel']==='dine') abort_unless(trim($data['table_name']??'')!=='' && trim($data['customer_name']??'')!=='',422,'أدخل الطاولة واسم العميل.');
            $row=DB::table('desktop_pos_orders')->where('device_id',$device->id)->where('local_id',$v['order_id'])->lockForUpdate()->first();
            abort_unless($v['revision']===($row?(int)$row->revision+1:1),409,'ترتيب عمليات الفاتورة غير صحيح.');
            $status=$row?$row->status:'draft';
            abort_unless(!in_array($status,['paid','cancelled'],true),409,'الفاتورة منتهية.');
            if ($row) {
                abort_unless($row->snapshot_id===$v['snapshot_id'],409,'نسخة المينيو الخاصة بالفاتورة ثابتة.');
                $before=json_decode($row->data,true);
                abort_unless($before['channel']===$data['channel'],409);
                // A bill locks its contents; cash_received is the only settlement field allowed to change.
                if ($status==='bill') { unset($before['cash_received']); $after=$data; unset($after['cash_received']); abort_unless(PosServiceTicket::fingerprint($before)===PosServiceTicket::fingerprint($after),409,'تمت طباعة الحساب؛ محتويات الفاتورة ثابتة.'); }
            }
            $receipt=null;
            if ($v['kind']==='cancel') { abort_unless($status==='draft' && trim($data['cancel_reason']??'')!=='',409,'لا يمكن إلغاء فاتورة أرسلت للمطبخ أو صدر حسابها.'); $status='cancelled'; }
            elseif ($v['kind']==='bill') { abort_unless(in_array($status,['draft','kitchen'],true),409); $status='bill'; }
            elseif ($v['kind']==='kitchen') { abort_unless(in_array($status,['draft','kitchen'],true),409); $status='kitchen'; }
            elseif ($v['kind']==='save') { abort_unless($status!=='bill',409); }
            elseif ($v['kind']==='sale') {
                $context=['channel'=>$data['channel'],'trusted_offline'=>true,'offline_operation'=>$v['id'],'saved_quote'=>$quote,
                    'business_date'=>$when->copy()->setTimezone('Africa/Cairo')->toDateString(),'branch_snapshot'=>$snapshot['branch'],
                    'snapshot'=>array_intersect_key($data,array_flip(['customer_name','customer_phone','address','table_name','notes']))+
                        ['desktop_device'=>$device->id,'local_order'=>$v['order_id'],'occurred_at'=>$when->toIso8601String(),'synced_at'=>now('UTC')->toIso8601String()]];
                $payment=app(TakeawayService::class)->checkout(['branch'=>$device->branch,'idempotency_key'=>$v['id'],'quote_hash'=>$quote['quote_hash'],
                    'payment_method'=>'cash','cash_received'=>$data['cash_received']??'0','payment_confirmed'=>true]+$data,$actor,$context);
                $receipt=$payment['receipt']; $status='paid';
            }
            $changes=['status'=>$status,'revision'=>$v['revision'],'data'=>json_encode($data,JSON_UNESCAPED_UNICODE),'updated_at'=>now('UTC')];
            if ($receipt) $changes['paid_order_id']=$receipt['id'];
            if ($row) DB::table('desktop_pos_orders')->where('id',$row->id)->update($changes);
            else DB::table('desktop_pos_orders')->insert($changes+['device_id'=>$device->id,'local_id'=>$v['order_id'],'snapshot_id'=>$v['snapshot_id'],'branch'=>$device->branch,'channel'=>$data['channel'],'occurred_at'=>$when,'created_at'=>now('UTC')]);
            $result=['id'=>$v['id'],'order_id'=>$v['order_id'],'revision'=>$v['revision'],'status'=>$status,'receipt'=>$receipt];
            DB::table('desktop_pos_operations')->insert(['device_id'=>$device->id,'request_key'=>$v['id'],'request_hash'=>$hash,'local_id'=>$v['order_id'],
                'branch'=>$device->branch,'kind'=>$v['kind'],'revision'=>$v['revision'],'result'=>json_encode($result,JSON_UNESCAPED_UNICODE),'occurred_at'=>$when,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            $this->seen($device); return $result;
        },3);
    }
    private function seen(object $device): void { DB::table('desktop_pos_devices')->where('id',$device->id)->update(['last_seen_at'=>now('UTC'),'updated_at'=>now('UTC')]); }
}
