<?php

namespace App\Services\Site;

use App\Models\Resturant;
use App\Models\User;
use App\Services\GoServices\Money;
use App\Services\Dashboard\{BranchCustomers, PhoneDelivery, PhoneMapProvider, PosServiceTicket, TakeawayAccess, TakeawayCatalog};
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB, Schema, Validator};

/** Public checkout exposes a narrow, session-bound command; never a dashboard login. */
class WebStorefront
{
    public function publicInfo(): array
    {
        $settings = app(\App\Models\GeneralSettings::class);
        return ['phone'=>(string)$settings->phone,'email'=>(string)$settings->email,
            'terms'=>trim(strip_tags(preg_replace('/<\/(?:p|div|li)>|<br\s*\/?\s*>/i',"\n",(string)$settings->terms_ar))),
            'policy'=>trim(strip_tags(preg_replace('/<\/(?:p|div|li)>|<br\s*\/?\s*>/i',"\n",(string)$settings->policy_ar)))];
    }

    public function discover(Request $request): array
    {
        $previous = $request->session()->get('web_storefront.delivery_context');
        // Replacing an address invalidates the former context even when the new input is invalid.
        $request->session()->forget('web_storefront.delivery_context');
        $values = Validator::make($request->all(), [
            'customer_phone'=>'required|string|max:30', 'address'=>'required|string|min:8|max:500',
            'area'=>'nullable|string|max:150', 'latitude'=>'required|numeric|between:22,32',
            'longitude'=>'required|numeric|between:24,37', 'location_confirmed'=>'required|accepted',
        ])->validate();
        $context = $this->normalizeContext($values);
        abort_unless(preg_match('/^01[0125][0-9]{8}$/D', $context['customer_phone']),422,'اكتب رقم موبايل مصري صحيحًا من ١١ رقمًا.');
        abort_unless(mb_strlen($context['address'])>=8,422,'أكمل عنوان التوصيل بالتفصيل.');
        // Recalling the same saved address in another tab must preserve its pending review.
        $same = is_array($previous) && is_string($previous['nonce'] ?? null)
            && preg_match('/\A[a-f0-9]{32}\z/i', $previous['nonce']) === 1
            && array_diff_key($previous,['nonce'=>true]) === $context;
        $context['nonce'] = $same ? $previous['nonce'] : bin2hex(random_bytes(16));
        $request->session()->put('web_storefront.delivery_context', $context);
        return ['success'=>true, 'branches'=>$this->branches($request),
            'context'=>array_diff_key($context,['nonce'=>true]) + ['location_confirmed'=>true]];
    }

    /** No directory or customer information is embedded in the public page bootstrap. */
    public function branches(?Request $request=null): array
    {
        if ($request===null) return [];
        $context = $this->deliveryContext($request);
        $areas = $this->coverageAreas();
        $result = [];
        foreach ($this->publicBranchesQuery()->orderBy('id')->get() as $branch) {
            if (!$this->covered($branch, $context, $areas[(int)$branch->id] ?? [])) continue;
            $result[] = ['id'=>(int)$branch->id, 'name'=>(string)$branch->name, 'address'=>(string)$branch->address,
                'phone'=>(string)$branch->phone, 'open'=>$branch->effective_status === 'opened',
                'status'=>$branch->effective_status, 'latitude'=>is_numeric($branch->lat)?(float)$branch->lat:null,
                'longitude'=>is_numeric($branch->lng)?(float)$branch->lng:null,
                'logo'=>method_exists($branch, 'getFirstMediaUrl') ? (string)$branch->getFirstMediaUrl('logo', 'thumb') : '',
                'distance_km'=>$this->distance((float)$context['latitude'],(float)$context['longitude'],(float)$branch->lat,(float)$branch->lng)];
        }
        usort($result, fn($a,$b)=>[$a['distance_km'],$a['id']] <=> [$b['distance_km'],$b['id']]);
        foreach ($result as &$branch) $branch['distance_km']=number_format($branch['distance_km'],3,'.','');
        return $result;
    }

    /** The app's public Fasakhansta restaurant directory is authoritative, including its stores. */
    protected function publicBranchesQuery()
    {
        $operators = User::withoutGlobalScopes()->select('id');
        if (Schema::hasColumn('users', 'app_scope')) {
            // Legacy Fasakhansta operators pre-date app_scope; GO stores have their own catalog/table.
            $operators->where(function ($query) {
                $query->whereNull('app_scope')->orWhere('app_scope', '')->orWhere('app_scope', 'fasakhansta');
            });
        }
        return Resturant::withoutGlobalScopes()->whereIn('status', ['opened', 'busy', 'closed'])
            ->whereIn('user_id', $operators);
    }

    public function catalog(int $id, Request $request): array
    {
        $context = $this->deliveryContext($request);
        [$branch, $actor] = $this->branch($id);
        $this->assertCovered($branch, $context);
        $catalog = app(TakeawayCatalog::class)->listing(['branch'=>'f:'.$id,'per_page'=>500,'page'=>1], $actor);
        // Cost, recipes, balances, internal actors and administrative permissions are private.
        $products = [];
        foreach ($catalog['items'] as $item) {
            if (!$item['available']) continue;
            $products[] = array_intersect_key($item, array_flip(['id','name','price','image_url','unit','quantity_mode','category_id','options']));
        }
        return ['products'=>$products, 'categories'=>$catalog['categories'], 'open'=>$branch->effective_status==='opened'];
    }

    public function suggestions(string $query): array
    {
        return app(PhoneMapProvider::class)->publicSuggestions($query);
    }

    public function quote(Request $request): array
    {
        $context = $this->deliveryContext($request);
        $values = Validator::make($request->all(), [
            'branch_id'=>'required|integer|min:1', 'customer_name'=>'required|string|min:2|max:100',
            'customer_phone'=>'required|string|max:30', 'address'=>'required|string|min:8|max:500',
            'area'=>'required|string|min:2|max:150', 'notes'=>'nullable|string|max:350',
            'latitude'=>'required|numeric|between:22,32', 'longitude'=>'required|numeric|between:24,37',
            'location_confirmed'=>'required|accepted', 'items'=>'required|array|min:1|max:40',
            'items.*.product_id'=>'required|integer|min:1', 'items.*.quantity'=>['required','string','regex:/^(?:[1-9][0-9]?(?:\.[0-9]{1,3})?|0\.[0-9]{1,3})$/D'],
            'items.*.quantity_mode'=>'required|in:piece,weight', 'items.*.option_id'=>'nullable|string|max:80',
        ])->validate();
        $phone = BranchCustomers::phoneKey($values['customer_phone']);
        abort_unless(preg_match('/^01[0125][0-9]{8}$/D', $phone), 422, 'اكتب رقم موبايل مصري صحيحًا من ١١ رقمًا.');
        $values['customer_phone'] = $phone;
        foreach (['customer_name','address','area','notes'] as $key) $values[$key] = trim($values[$key] ?? '');
        abort_unless(mb_strlen($values['customer_name'])>=2 && mb_strlen($values['address'])>=8 && mb_strlen($values['area'])>=2,422,'أكمل الاسم والعنوان والمنطقة.');
        abort_unless($this->normalizeContext($values)===array_diff_key($context,['nonce'=>true]),409,'تغيّر عنوان التوصيل. حدّد موقعك واعرض الفروع المتاحة مرة أخرى.');
        $values = array_replace($values,array_diff_key($context,['nonce'=>true]));
        [$branch, $actor] = $this->branch((int)$values['branch_id'], true);
        $this->assertCovered($branch, $context);
        $values['branch'] = 'f:'.$branch->id;
        // Strip all nested input except the four permitted cart fields.
        $values['items'] = array_map(fn($line)=>array_intersect_key($line,array_flip(['product_id','quantity','quantity_mode','option_id'])), $values['items']);
        $values['discount'] = '0.00';
        $delivery = app(PhoneDelivery::class)->quote($values, $actor)['delivery'];
        $radius = config('web_storefront.max_delivery_km');
        if (is_numeric($radius) && $radius > 0) abort_unless((int)$delivery['distance_meters'] <= (float)$radius*1000,422,'العنوان خارج نطاق توصيل الفرع. اختر فرعًا أقرب.');
        $values['delivery_quote_hash'] = $delivery['delivery_quote_hash'];
        $quote = app(PosServiceTicket::class)->quote('phone', $values, $actor);
        $minimum = is_numeric($branch->min_order_price) ? Money::minor((string)$branch->min_order_price) : 0;
        abort_unless($quote['subtotal_cents'] >= $minimum,422,'الحد الأدنى لمنتجات الطلب من الفرع '.Money::decimal($minimum).' جنيه.');
        abort_unless($quote['total_cents'] > 0 && $quote['total_cents'] <= 2000000,422,'إجمالي الطلب يجب أن يكون من ١ جنيه إلى ٢٠ ألف جنيه.');
        $values['quote_hash'] = $quote['quote_hash'];
        $sealed = ['v'=>2, 'session'=>$this->sessionHash($request), 'context'=>$this->contextHash($context), 'expires'=>time()+600, 'values'=>$values];
        return ['success'=>true,'token'=>Crypt::encryptString(json_encode($sealed,JSON_UNESCAPED_UNICODE)),
            'summary'=>$this->summary($quote), 'distance_km'=>$delivery['distance_km']];
    }

    public function checkout(Request $request): array
    {
        $v = Validator::make($request->all(), ['token'=>'required|string|max:32000','idempotency_key'=>'required|uuid'])->validate();
        abort_unless(Schema::hasTable('web_storefront_orders'),503,'استقبال طلبات الموقع غير متاح حاليًا.');
        try { $sealed = json_decode(Crypt::decryptString($v['token']), true, 64, JSON_THROW_ON_ERROR); }
        catch (DecryptException | \JsonException $error) { abort(422,'انتهت صلاحية مراجعة الطلب. راجع الإجمالي مرة أخرى.'); }
        abort_unless(is_array($sealed) && in_array($sealed['v']??null,[1,2],true) && isset($sealed['session'],$sealed['expires'],$sealed['values'])
            && hash_equals($this->sessionHash($request),$sealed['session']),403,'راجع الطلب في نفس المتصفح.');
        $session = $this->sessionHash($request);
        $hash = hash('sha256',$v['token']);
        return DB::transaction(function () use ($sealed,$v,$session,$hash,$request) {
            // Recover only this session's completed command, even after its branch or address changes.
            if ($receipt=$this->replay($session,$v['idempotency_key'],$hash)) return $receipt;
            // The branch row serializes simultaneous first submissions; recheck after acquiring it.
            [$branch,$actor] = $this->branch((int)$sealed['values']['branch_id'],false,true);
            if ($receipt=$this->replay($session,$v['idempotency_key'],$hash,true)) return $receipt;
            $context = $this->deliveryContext($request);
            abort_unless(($sealed['v']??null)===2 && isset($sealed['context']) && hash_equals($this->contextHash($context),(string)$sealed['context']),409,'تغيّر عنوان التوصيل. راجع الفروع والطلب مرة أخرى.');
            abort_unless($sealed['expires']>=time(),409,'انتهت صلاحية السعر. راجع الإجمالي مرة أخرى.');
            abort_unless($branch->effective_status==='opened',409,'الفرع مغلق الآن. اختر فرعًا متاحًا.');
            $this->assertCovered($branch, $context);
            $values = $sealed['values'];
            $values['idempotency_key'] = $this->commandKey($session,$v['idempotency_key']);
            $values['notes'] = 'طلب الموقع الإلكتروني'.($values['notes']!==''?' — '.$values['notes']:'');
            // An unpaid ticket appears in the branch delivery board. Staff accept and send it to the kitchen.
            $values['send_to_kitchen'] = false;
            $result = app(PosServiceTicket::class)->save('phone',$values,$actor);
            $ticket = (int)$result['ticket']['id'];
            DB::table('web_storefront_orders')->insert(['session_hash'=>$session,'request_key'=>$v['idempotency_key'],
                'payload_hash'=>$hash,'branch_id'=>(int)$branch->id,'ticket_id'=>$ticket,'created_at'=>now('UTC')]);
            return $this->confirmation($ticket,(int)$branch->id);
        },3);
    }

    protected function branch(int $id, bool $open=false, bool $lock=false): array
    {
        $query = $this->publicBranchesQuery()->where('id',$id);
        if ($lock) $query->lockForUpdate();
        $branch = $query->first();
        abort_unless($branch && in_array($branch->status,['opened','busy','closed'],true),404);
        if ($open) abort_unless($branch->effective_status==='opened',409,'الفرع غير متاح لاستقبال طلبات الآن.');
        $actor = User::withoutGlobalScopes()->find($branch->user_id);
        // Only the branch's persisted operator, never an owner/global admin or visitor-provided actor.
        abort_unless($actor && ($actor->account_type==='vendor' || ($actor->account_type==='admin' && (int)$actor->owner_resturant_id===$id)),503,'الطلب من هذا الفرع غير متاح حاليًا.');
        app(TakeawayAccess::class)->branch('f:'.$id,$actor,$lock);
        return [$branch,$actor];
    }

    private function normalizeContext(array $values): array
    {
        return ['customer_phone'=>BranchCustomers::phoneKey($values['customer_phone'] ?? ''),
            'address'=>trim($values['address'] ?? ''), 'area'=>trim($values['area'] ?? '') ?: 'العنوان المحدد',
            'latitude'=>round((float)($values['latitude'] ?? 0),7), 'longitude'=>round((float)($values['longitude'] ?? 0),7)];
    }

    private function deliveryContext(Request $request): array
    {
        $context=$request->session()->get('web_storefront.delivery_context');
        abort_unless(is_array($context) && isset($context['nonce'],$context['customer_phone'],$context['address'],$context['area'],$context['latitude'],$context['longitude']),409,'حدّد موقعك واكتب عنوانك ورقم هاتفك لعرض الفروع التي تخدمك.');
        return $context;
    }

    private function contextHash(array $context): string
    {
        return hash_hmac('sha256',json_encode($context,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),(string)config('app.key'));
    }

    /** Same union of delivery circles used by the app's address and canDeliver checks. */
    private function coverageAreas(?int $branch=null): array
    {
        abort_unless(Schema::hasTable('resturant_areas'),503,'تعذر التحقق من مناطق التوصيل حاليًا.');
        $query=DB::table('resturant_areas')->whereNotNull('lat')->whereNotNull('lng')->whereNotNull('expected_delivery');
        if ($branch!==null) $query->where('resturant_id',$branch);
        $result=[];
        foreach ($query->get(['resturant_id','lat','lng','expected_delivery']) as $area) $result[(int)$area->resturant_id][]=$area;
        return $result;
    }

    private function covered($branch, array $context, array $areas): bool
    {
        // A usable branch pin is also necessary for the native delivery quote.
        if (!is_numeric($branch->lat)||!is_numeric($branch->lng)||abs((float)$branch->lat)>90||abs((float)$branch->lng)>180||((float)$branch->lat===0.0&&(float)$branch->lng===0.0)) return false;
        foreach ($areas as $area) {
            if (!is_numeric($area->lat)||!is_numeric($area->lng)||!is_numeric($area->expected_delivery)||abs((float)$area->lat)>90||abs((float)$area->lng)>180||(float)$area->expected_delivery<0) continue;
            if ($this->distance($context['latitude'],$context['longitude'],(float)$area->lat,(float)$area->lng) <= (float)$area->expected_delivery) return true;
        }
        return false;
    }

    private function assertCovered($branch, array $context): void
    {
        $areas=$this->coverageAreas((int)$branch->id);
        abort_unless($this->covered($branch,$context,$areas[(int)$branch->id] ?? []),422,'العنوان خارج نطاق توصيل هذا الفرع. حدّد موقعك واعرض الفروع المتاحة.');
    }

    private function distance(float $lat1,float $lng1,float $lat2,float $lng2): float
    {
        $a=sin(deg2rad($lat2-$lat1)/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin(deg2rad($lng2-$lng1)/2)**2;
        return 6371*2*atan2(sqrt(min(1,$a)),sqrt(max(0,1-$a)));
    }

    private function summary(array $quote): array
    {
        return array_intersect_key($quote,array_flip(['subtotal','tax','delivery','total'])) + ['items'=>array_map(fn($line)=>array_intersect_key($line,array_flip(['name','option_label','quantity','quantity_mode','unit_price','total'])), $quote['items'])];
    }

    private function confirmation(int $id,int $branch): array
    {
        $row = DB::table('pos_service_tickets')->where('id',$id)->where('branch','f:'.$branch)->first();
        abort_unless($row,503,'تعذر قراءة تأكيد الطلب.');
        return ['success'=>true,'order'=>['number'=>'WEB-'.$id,'status'=>$row->status,'total'=>json_decode($row->quote_snapshot,true)['total'],
            'payment'=>'cash_on_delivery','branch_id'=>$branch]];
    }

    private function replay(string $session,string $requestKey,string $hash,bool $fresh=false): ?array
    {
        $query=DB::table('web_storefront_orders')->where('session_hash',$session)->where(function($query) use ($requestKey,$hash) {
            $query->where('request_key',$requestKey)->orWhere('payload_hash',$hash);
        });
        // MySQL's locking read sees a retry committed while waiting for the branch lock.
        if ($fresh) $query->lockForUpdate();
        $old=$query->first();
        if (!$old) return null;
        abort_unless(hash_equals($old->payload_hash,$hash),409,'رقم العملية مستخدم لطلب آخر.');
        return $this->confirmation((int)$old->ticket_id,(int)$old->branch_id);
    }

    private function sessionHash(Request $request): string { return hash_hmac('sha256',$request->session()->getId(),(string)config('app.key')); }
    private function commandKey(string $session,string $key): string
    {
        $hex = hash('sha256','web-storefront:'.$session.':'.$key);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-a'.substr($hex,17,3).'-'.substr($hex,20,12);
    }
}
