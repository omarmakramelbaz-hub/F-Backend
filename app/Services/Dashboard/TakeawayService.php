<?php

namespace App\Services\Dashboard;

use App\Services\GoServices\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Finalized counter sales and the cash register are independent of delivery orders and app wallets. */
class TakeawayService
{
    private TakeawayAccess $access;
    private TakeawayCatalog $catalog;
    private const METHODS = ['cash', 'card', 'mobile_wallet', 'other'];

    public function __construct(TakeawayAccess $access, TakeawayCatalog $catalog)
    {
        $this->access = $access; $this->catalog = $catalog;
    }

    public function canAccess($actor): bool { return $this->access->canAccess($actor); }
    public function canonicalCart(array $values): array {return $this->cart($values);}

    public function quote(array $values, $actor, array $context = [], bool $lock=false): array
    {
        $cart = $this->cart($values);
        $branch = $this->access->branch($cart['branch'], $actor);
        $this->requireReady();
        $permissions = $this->access->permissions($actor);
        $till = $this->till($branch['value']);
        return $this->price($cart, $branch, $permissions, (int) $till->tax_bps, $lock, $context);
    }

    public function checkout(array $values, $actor, array $context = []): array
    {
        $cart = $this->cart($values);
        $payment = Validator::make($values, [
            'idempotency_key'=>'required|uuid', 'quote_hash'=>'required|string|size:64|regex:/^[a-f0-9]+$/D',
            'payment_method'=>'required|in:cash,card,mobile_wallet,wallet,other,mixed',
            'cash_received'=>'nullable|string|max:14', 'payment_confirmed'=>'nullable|boolean',
            'payment_reference'=>'nullable|string|max:150', 'notes'=>'nullable|string|max:500',
            'tenders'=>'nullable|array|min:2|max:3', 'tenders.*.method'=>'required|in:cash,card,mobile_wallet',
            'tenders.*.amount'=>'required|string|max:14', 'tenders.*.payment_confirmed'=>'nullable|boolean',
            'tenders.*.reference'=>'nullable|string|max:150',
        ])->validate();
        $payment['payment_method'] = $payment['payment_method'] === 'wallet' ? 'mobile_wallet' : $payment['payment_method'];
        $mixed = $payment['payment_method'] === 'mixed';
        abort_unless(!$mixed || ($context['channel'] ?? '') === 'dine',422,'الدفع المختلط متاح لفاتورة الصالة فقط.');
        $payment['cash_received_cents'] = in_array($payment['payment_method'],['cash','mixed'],true) ? $this->money($payment['cash_received'] ?? '0', 'cash_received') : 0;
        $payment['payment_reference'] = trim($payment['payment_reference'] ?? '');
        $payment['notes'] = trim($payment['notes'] ?? '');
        if (!in_array($payment['payment_method'],['cash','mixed'],true) && !($payment['payment_confirmed'] ?? false)) {
            throw ValidationException::withMessages(['payment_confirmed'=>'أكد تحصيل الدفع خارج النظام قبل تسجيل الفاتورة.']);
        }
        $tenders=[];
        if ($mixed) {
            abort_unless(!empty($payment['tenders']),422,'أدخل توزيع الدفع المختلط.');
            foreach ($payment['tenders'] as $tender) {
                $method=$tender['method'];
                abort_unless(!isset($tenders[$method]),422,'وسيلة الدفع مكررة.');
                $amount=$this->money($tender['amount'],'tenders');
                abort_unless($amount>0,422,'مبلغ كل وسيلة دفع يجب أن يكون أكبر من صفر.');
                $confirmed=$method==='cash'||(bool)($tender['payment_confirmed']??$payment['payment_confirmed']??false);
                abort_unless($confirmed,422,'أكد تحصيل الجزء غير النقدي خارج النظام.');
                $tenders[$method]=['method'=>$method,'amount_cents'=>$amount,'confirmed'=>true,'reference'=>trim($tender['reference']??'')];
            }
            ksort($tenders);
        }
        $requestHash = $this->hash([$cart, $payment['quote_hash'], $payment['payment_method'], $payment['cash_received_cents'],
            $payment['payment_reference'], $payment['notes']]);
        if ($context) $requestHash=$this->hash([$requestHash,$context,$tenders]);
        $this->requireReady();
        if ($context) abort_unless($this->access->has('takeaway_orders','channel'),503,'قنوات نقطة البيع لم تُجهز بعد.');
        return DB::transaction(function () use ($cart, $payment, $requestHash, $actor, $context, $tenders) {
            $actor = $this->access->actor($actor);
            $branch = $this->access->branch($cart['branch'], $actor, true);
            $permissions = $this->access->permissions($actor);
            abort_unless($permissions['can_checkout'], 403);
            $till = $this->till($branch['value'], true);
            $existing = DB::table('takeaway_orders')->where('branch', $branch['value'])->where('actor_id', $actor->id)
                ->where('request_key', $payment['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $requestHash), 409, 'تم استخدام رقم العملية لفاتورة مختلفة.');
                return $this->checkoutResult($existing, $actor, true);
            }
            // Old receipts remain replayable; new POS collections accept cash only.
            abort_unless($payment['payment_method']==='cash'&&empty($payment['tenders']),422,'الدفع متاح كاش فقط.');
            // A movement/setting key cannot be reused to create a sale.
            abort_unless(!DB::table('takeaway_till_entries')->where('till_id', $till->id)->where('actor_id', $actor->id)
                ->where('request_key', $payment['idempotency_key'])->exists(), 409, 'رقم العملية مستخدم بالفعل.');
            $quote = $this->price($cart, $branch, $permissions, (int) $till->tax_bps, true, $context);
            abort_unless(hash_equals($quote['quote_hash'], $payment['quote_hash']), 409, 'تغير سعر أو ضريبة أو وصفة الفاتورة. راجع الإجمالي وأعد المحاولة.');
            $total = $quote['total_cents'];
            if ($payment['payment_method']==='mixed') {
                abort_unless(array_sum(array_column($tenders,'amount_cents'))===$total,422,'مجموع وسائل الدفع لا يساوي إجمالي الفاتورة.');
                $delta=$tenders['cash']['amount_cents']??0;
            } else {
                $delta=$payment['payment_method']==='cash'?$total:0;
                $tenders=[$payment['payment_method']=>['method'=>$payment['payment_method'],'amount_cents'=>$total,'confirmed'=>true,'reference'=>$payment['payment_reference']]];
            }
            if ($delta>0 || $payment['payment_method']==='cash') {
                if ($payment['cash_received_cents']<$delta) throw ValidationException::withMessages(['cash_received'=>'المبلغ المستلم أقل من الجزء النقدي للفاتورة.']);
                $change=$payment['cash_received_cents']-$delta;
            } else { $change=0; $payment['cash_received_cents']=0; }
            $when = now('UTC');
            $businessDate = !empty($context['trusted_offline']) ? $context['business_date'] : $this->businessDate($when);
            $orderValues = [
                'till_id'=>$till->id, 'branch'=>$branch['value'], 'actor_id'=>$actor->id,
                'request_key'=>$payment['idempotency_key'], 'request_hash'=>$requestHash, 'quote_hash'=>$quote['quote_hash'],
                'business_date'=>$businessDate, 'payment_method'=>$payment['payment_method'],
                'payment_reference'=>$payment['payment_reference'] ?: null, 'payment_confirmed'=>true,
                'subtotal_cents'=>$quote['subtotal_cents'], 'discount_cents'=>$quote['discount_cents'],
                'tax_cents'=>$quote['tax_cents'], 'tax_bps'=>Money::rate($quote['tax_rate']), 'total_cents'=>$total,
                'discount_reason'=>$cart['discount_reason'] ?: null,
                'cash_received_cents'=>$payment['cash_received_cents'], 'change_cents'=>$change,
                'notes'=>$payment['notes'] ?: null, 'branch_snapshot'=>json_encode($context['branch_snapshot']??$branch, JSON_UNESCAPED_UNICODE),
                'cashier_snapshot'=>json_encode(['id'=>(int) $actor->id, 'name'=>(string) $actor->name], JSON_UNESCAPED_UNICODE),
                'created_at'=>$when, 'updated_at'=>$when,
            ];
            if ($this->access->has('takeaway_orders','channel')) $orderValues += [
                'channel'=>$context['channel']??'takeaway','ticket_id'=>$context['ticket_id']??null,
                'service_bps'=>$quote['service_bps'],'service_cents'=>$quote['service_cents'],'delivery_cents'=>$quote['delivery_cents'],
                'context_snapshot'=>json_encode($context['snapshot']??[],JSON_UNESCAPED_UNICODE), 'tenders_snapshot'=>json_encode(array_values($tenders),JSON_UNESCAPED_UNICODE),
            ];
            $id=DB::table('takeaway_orders')->insertGetId($orderValues);
            foreach ($quote['items'] as $line) DB::table('takeaway_order_items')->insert([
                'order_id'=>$id, 'product_id'=>$line['product_id'], 'name'=>$line['name'],
                'option_id'=>$line['option_id'] ?: null, 'option_label'=>$line['option_label'] ?: null,
                'unit'=>$line['unit'] ?: null, 'quantity_mode'=>$line['quantity_mode'], 'quantity_millis'=>$line['quantity_millis'],
                'unit_price_cents'=>$line['unit_price_cents'], 'total_cents'=>$line['total_cents'],
            ]);
            app(BranchStock::class)->posSale($branch,$id,$quote['items'],(int)$actor->id);
            $newBalance = (int) $till->balance_cents + $delta;
            abort_unless($newBalance <= 100000000000, 422, 'رصيد الخزنة أكبر من الحد المسموح.');
            DB::table('takeaway_tills')->where('id', $till->id)->update(['balance_cents'=>$newBalance, 'revision'=>(int) $till->revision + 1, 'updated_at'=>$when]);
            DB::table('takeaway_till_entries')->insert([
                'till_id'=>$till->id, 'branch'=>$branch['value'], 'actor_id'=>$actor->id, 'order_id'=>$id,
                'request_key'=>$payment['idempotency_key'], 'request_hash'=>$requestHash, 'kind'=>'sale',
                'amount_cents'=>$delta, 'balance_cents'=>$newBalance, 'business_date'=>$businessDate, 'note'=>['dine'=>'فاتورة صالة','phone'=>'فاتورة دليفري بالتليفون'][$context['channel']??'']??'فاتورة تيك أواي',
                'metadata'=>json_encode(['payment_method'=>$payment['payment_method'], 'total_cents'=>$total,
                    'cash_received_cents'=>$payment['cash_received_cents'], 'change_cents'=>$change,'channel'=>$context['channel']??'takeaway','tenders'=>array_values($tenders)]), 'created_at'=>$when, 'updated_at'=>$when,
            ]);
            return $this->checkoutResult(DB::table('takeaway_orders')->where('id', $id)->first(), $actor, false);
        }, 3);
    }

    public function receipt(int $id, $actor): array
    {
        $this->requireReady();
        $row = DB::table('takeaway_orders')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->access->branch($row->branch, $actor);
        return $this->presentReceipt($row);
    }

    public function receipts(array $values, $actor): array
    {
        $this->requireReady();
        $branch = $this->access->branch($values['branch'], $actor);
        $date = $values['date'] ?? $this->businessDate();
        $query = DB::table('takeaway_orders')->where('branch', $branch['value']);
        if (!empty($values['idempotency_key'])) {
            // Recovery of an uncertain POST returns only this cashier's exact key.
            $query->where('actor_id', $this->access->actor($actor)->id)->where('request_key', $values['idempotency_key']);
        } else $query->where('business_date', $date);
        $total = (clone $query)->count(); $perPage = (int) ($values['per_page'] ?? 20);
        $last = max(1, (int) ceil($total / $perPage)); $page = min($last, (int) ($values['page'] ?? 1));
        $items = [];
        foreach ($query->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get() as $row) $items[] = $this->presentReceipt($row);
        return ['success'=>true, 'branch'=>$branch, 'today'=>$this->daily($branch['value'], $date), 'items'=>$items,
            'receipt'=>!empty($values['idempotency_key']) ? ($items[0] ?? null) : null,
            'pagination'=>['page'=>$page, 'last_page'=>$last, 'per_page'=>$perPage, 'total'=>$total]];
    }

    public function register(string $value, $actor, string $date = null): array
    {
        $this->requireReady();
        $branch = $this->access->branch($value, $actor);
        $date = $date ?? $this->businessDate();
        $permissions = $this->access->permissions($actor);
        $entries = DB::table('takeaway_till_entries')->where('branch', $branch['value'])->where('business_date', $date)->whereNotIn('kind', ['shift_close','expense','expense_refund'])->orderByDesc('id')->limit(100)->get();
        return ['success'=>true, 'branch'=>$branch, 'register'=>$this->presentTill($this->till($branch['value']), $actor),
            'permissions'=>$permissions, 'today'=>$this->daily($branch['value'], $date),
            'entries'=>$entries->map(function ($entry) use ($permissions) {
                return ['id'=>(int) $entry->id, 'kind'=>$entry->kind, 'amount'=>Money::decimal((int) $entry->amount_cents),
                    'note'=>$entry->note, 'actor_id'=>(int) $entry->actor_id,
                    'order_id'=>$entry->order_id ? (int) $entry->order_id : null, 'created_at'=>$this->iso($entry->created_at),
                    'metadata'=>json_decode($entry->metadata ?? 'null', true)];
            })->all()];
    }

    /** Opening cash, withdrawals and rate changes are auditable and replay-safe. */
    public function changeRegister(array $values, $actor, bool $setting): array
    {
        $rules = ['branch'=>['required','string','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'], 'idempotency_key'=>'required|uuid',
            'expected_revision'=>'required|integer|min:1', 'note'=>'required|string|max:500'];
        $rules += $setting ? ['tax_rate'=>'required|string|max:6'] : ['direction'=>'required|in:in,out', 'amount'=>'required|string|max:14'];
        $values = Validator::make($values, $rules)->validate();
        $values['note'] = trim($values['note']);
        if ($values['note'] === '') throw ValidationException::withMessages(['note'=>'اكتب سبب العملية.']);
        if ($setting) {
            try { $amount = Money::rate($values['tax_rate']); }
            catch (\InvalidArgumentException $error) { throw ValidationException::withMessages(['tax_rate'=>'الضريبة من صفر إلى ١٠٠ وبحد أقصى منزلتين عشريتين.']); }
        } else {
            $amount = $this->money($values['amount'], 'amount');
            if ($amount <= 0) throw ValidationException::withMessages(['amount'=>'المبلغ يجب أن يكون أكبر من صفر.']);
        }
        $hash = $this->hash([$values['branch'], $setting ? 'tax_setting' : $values['direction'], $amount, $values['note']]);
        $this->requireReady();
        return DB::transaction(function () use ($values, $actor, $setting, $amount, $hash) {
            $actor = $this->access->actor($actor);
            $branch = $this->access->branch($values['branch'], $actor, true);
            abort_unless($this->access->permissions($actor)['can_manage'], 403);
            $till = $this->till($branch['value'], true);
            $existing = DB::table('takeaway_till_entries')->where('till_id', $till->id)->where('actor_id', $actor->id)->where('request_key', $values['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'رقم العملية مستخدم لعملية مختلفة.');
                return $this->register($branch['value'], $actor) + ['replayed'=>true];
            }
            abort_unless((int) $till->revision === (int) $values['expected_revision'], 409, 'تغير رصيد أو إعداد الخزنة. حدّث البيانات.');
            $delta = $setting ? 0 : ($values['direction'] === 'in' ? $amount : -$amount);
            $balance = (int) $till->balance_cents + $delta;
            abort_unless($balance >= -100000000000 && $balance <= 100000000000
                && ($setting || $values['direction'] === 'in' || $balance >= 0), 409, 'الرصيد النقدي غير كافٍ أو المبلغ غير مسموح.');
            $when = now('UTC');
            $changes = ['balance_cents'=>$balance, 'revision'=>(int) $till->revision + 1, 'updated_at'=>$when];
            if ($setting) $changes['tax_bps'] = $amount;
            DB::table('takeaway_tills')->where('id', $till->id)->update($changes);
            DB::table('takeaway_till_entries')->insert([
                'till_id'=>$till->id, 'branch'=>$branch['value'], 'actor_id'=>$actor->id, 'order_id'=>null,
                'request_key'=>$values['idempotency_key'], 'request_hash'=>$hash, 'kind'=>$setting ? 'tax_setting' : 'cash_'.$values['direction'],
                'amount_cents'=>$delta, 'balance_cents'=>$balance, 'business_date'=>$this->businessDate($when), 'note'=>$values['note'],
                'metadata'=>$setting ? json_encode(['previous_tax_bps'=>(int) $till->tax_bps, 'tax_bps'=>$amount]) : null,
                'created_at'=>$when, 'updated_at'=>$when,
            ]);
            return $this->register($branch['value'], $actor) + ['replayed'=>false];
        }, 3);
    }

    public function summary(string $value, $actor): array
    {
        $branch = $this->access->branch($value, $actor);
        if (!$this->access->ready()) return ['ready'=>false, 'register'=>['revision'=>1,'tax_rate'=>'0.00'], 'today'=>['date'=>$this->businessDate(),'count'=>0,'total'=>'0.00']];
        $permissions = $this->access->permissions($actor);
        $till = $this->presentTill($this->till($branch['value']), $actor);
        return ['ready'=>true, 'register'=>$till, 'today'=>$this->daily($branch['value'], $this->businessDate()), 'permissions'=>$permissions,
            'policy'=>['tax_rate'=>$till['tax_rate'], 'service_rate'=>'0.00', 'can_discount'=>$permissions['can_manage'],
                'payment_methods'=>['cash'], 'currency'=>'EGP']];
    }

    private function cart(array $values): array
    {
        $values = Validator::make($values, [
            'branch'=>['required','string','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'], 'items'=>'required|array|min:1|max:100',
            'items.*.product_id'=>'required|integer|min:1', 'items.*.quantity'=>'required|string|regex:/^[0-9]{1,4}(?:\.[0-9]{1,3})?$/D',
            'items.*.quantity_mode'=>'required|in:piece,weight', 'items.*.option_id'=>'nullable|string|max:80',
            'items.*.feature_id'=>'nullable|integer|min:0', 'items.*.product_clean'=>'nullable|in:extra_clear,extra_clean,extra_vacuim',
            'discount'=>'nullable|string|max:14', 'discount_reason'=>'nullable|string|max:500',
        ])->validate();
        $items = [];
        foreach ($values['items'] as $item) {
            $parts = explode('.', $item['quantity']);
            $millis = (int) $parts[0] * 1000 + (int) str_pad($parts[1] ?? '', 3, '0');
            if ($millis < 1 || $millis > 1000000 || ($item['quantity_mode'] === 'piece' && $millis % 1000 !== 0)) {
                throw ValidationException::withMessages(['items'=>'العدد يجب أن يكون صحيحًا، والوزن بحد أقصى ٣ منازل عشرية، والكمية من ٠٫٠٠١ إلى ١٠٠٠.']);
            }
            $line = ['product_id'=>(int) $item['product_id'], 'quantity_mode'=>$item['quantity_mode'],
                'option_id'=>(string) ($item['option_id'] ?? ''), 'feature_id'=>(int) ($item['feature_id'] ?? 0),
                'product_clean'=>(string) ($item['product_clean'] ?? '')];
            $key = $this->hash($line);
            if (isset($items[$key])) $items[$key]['quantity_millis'] += $millis;
            else $items[$key] = $line + ['quantity_millis'=>$millis];
            if ($items[$key]['quantity_millis'] > 1000000) throw ValidationException::withMessages(['items'=>'كمية الصنف أكبر من الحد المسموح.']);
        }
        ksort($items);
        return ['branch'=>$values['branch'], 'items'=>array_values($items), 'discount_cents'=>$this->money($values['discount'] ?? '0', 'discount'),
            'discount_reason'=>trim($values['discount_reason'] ?? '')];
    }

    private function price(array $cart, array $branch, array $permissions, int $taxBps, bool $lock, array $context=[]): array
    {
        if(isset($context['saved_quote']))return $this->savedPrice($cart,$context);
        app(BranchStock::class)->validateQuantities($branch['value'],$cart['items']);
        abort_unless($cart['discount_cents'] === 0 || $permissions['can_manage'], 403, 'الخصم متاح للمالك أو الإدارة المخولة فقط.');
        if ($cart['discount_cents'] > 0 && $cart['discount_reason'] === '') throw ValidationException::withMessages(['discount_reason'=>'اكتب سبب الخصم.']);
        $rows = $this->catalog->rows($branch, array_column($cart['items'], 'product_id'), $lock);
        $lines = []; $subtotal = 0;
        foreach ($cart['items'] as $item) {
            abort_unless(isset($rows[$item['product_id']]), 422, 'أحد الأصناف لا ينتمي إلى الفرع.');
            $line = $this->catalog->line($branch, $rows[$item['product_id']], $item, $lock);
            $lines[] = $line; $subtotal += $line['total_cents'];
        }
        $lines=app(BranchStock::class)->quoteLines($branch['value'],$lines);
        abort_unless($subtotal <= 100000000 && $cart['discount_cents'] <= $subtotal, 422, 'الخصم أو إجمالي الفاتورة غير صالح.');
        abort_unless($taxBps >= 0 && $taxBps <= 10000, 503, 'ضريبة الخزنة غير صالحة.');
        $serviceBps=(int)($context['service_bps']??0);$delivery=(int)($context['delivery_cents']??0);
        abort_unless($serviceBps>=0&&$serviceBps<=10000&&$delivery>=0&&$delivery<=100000000,422);
        $net=$subtotal-$cart['discount_cents'];$service=Money::commission($net,$serviceBps);
        // The configured POS tax applies to the charged merchandise, service and delivery fee.
        abort_unless($net+$service+$delivery<=100000000,422);
        $tax = Money::commission($net+$service+$delivery, $taxBps);
        $total = $net+$service+$tax+$delivery;
        abort_unless($total <= 100000000, 422, 'إجمالي الفاتورة أكبر من الحد المسموح.');
        $hash = $this->hash([$branch['value'], $lines, $cart['discount_cents'], $cart['discount_reason'], $taxBps, $tax, $total]);
        if ($context) $hash=$this->hash([$hash,$context['channel']??'takeaway',$serviceBps,$service,$delivery]);
        return ['success'=>true, 'quote_hash'=>$hash, 'branch'=>$branch, 'items'=>$lines,
            'subtotal_cents'=>$subtotal, 'discount_cents'=>$cart['discount_cents'], 'tax_cents'=>$tax, 'total_cents'=>$total,
            'subtotal'=>Money::decimal($subtotal), 'discount'=>Money::decimal($cart['discount_cents']),
            'tax_bps'=>$taxBps,'tax_rate'=>Money::decimal($taxBps), 'tax'=>Money::decimal($tax),'service_bps'=>$serviceBps,'service_cents'=>$service,'delivery_cents'=>$delivery,
            'service_rate'=>Money::decimal($serviceBps),'service'=>Money::decimal($service),'delivery'=>Money::decimal($delivery), 'total'=>Money::decimal($total)];
    }

    /** Only locked persisted service tickets or the authenticated offline importer supply this context. */
    private function savedPrice(array $cart,array $context): array
    {
        $offline = !empty($context['trusted_offline']) && !empty($context['offline_operation']);
        abort_unless($offline || (in_array($context['channel']??'',['dine','phone'],true)&&!empty($context['ticket_id'])),422);
        $q=$context['saved_quote'];$wanted=[];$actual=[];$subtotal=0;
        foreach($cart['items'] as $item)$wanted[]=$this->hash([$item['product_id'],$item['option_id'],$item['quantity_mode'],$item['quantity_millis']]);
        foreach($q['items']??[] as $line){
            foreach(['product_id','quantity_millis','unit_price_cents','total_cents'] as $key)abort_unless(isset($line[$key])&&is_int($line[$key])&&$line[$key]>=0,409,'الفاتورة المحفوظة غير صالحة.');
            abort_unless($line['quantity_millis']>0&&$line['quantity_millis']<=1000000&&$line['unit_price_cents']<=100000000
                &&intdiv($line['unit_price_cents']*$line['quantity_millis']+500,1000)===$line['total_cents'],409);
            $actual[]=$this->hash([$line['product_id'],$line['option_id']??'',$line['quantity_mode'],$line['quantity_millis']]);$subtotal+=$line['total_cents'];
        }
        sort($wanted);sort($actual);abort_unless($wanted===$actual&&count($actual)>0&&$subtotal===$q['subtotal_cents']&&$cart['discount_cents']===$q['discount_cents'],409);
        $net=$subtotal-$q['discount_cents'];$service=Money::commission($net,(int)$q['service_bps']);$delivery=(int)$q['delivery_cents'];
        abort_unless($net>=0&&$net+$service+$delivery<=100000000&&$service===$q['service_cents'],409);
        $tax=Money::commission($net+$service+$delivery,Money::rate($q['tax_rate']));
        abort_unless($tax===$q['tax_cents']&&$net+$service+$delivery+$tax===$q['total_cents']&&$q['total_cents']<=100000000,409);
        return $q;
    }

    private function till(string $branch, bool $lock = false): object
    {
        if ($lock) DB::table('takeaway_tills')->insertOrIgnore(['branch'=>$branch,'balance_cents'=>0,'tax_bps'=>0,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        $query = DB::table('takeaway_tills')->where('branch', $branch);
        if ($lock) $query->lockForUpdate();
        return $query->first() ?? (object) ['id'=>null, 'branch'=>$branch, 'balance_cents'=>0, 'tax_bps'=>0, 'revision'=>1];
    }

    private function presentTill(object $till, $actor): array
    {
        $result = ['id'=>$till->id ? (int) $till->id : null, 'branch'=>$till->branch,
            'revision'=>(int) $till->revision, 'tax_rate'=>Money::decimal((int) $till->tax_bps), 'currency'=>'EGP'];
        // Re-read persisted identity: a manager or a client-side permission flag is not the owner.
        $actor = $this->access->actor($actor);
        if ((int)$actor->id === 1 && $actor->account_type === 'admin' && empty($actor->owner_resturant_id)) {
            $balance = (int)$till->balance_cents;
            if (str_starts_with($till->branch, 'f:') && \Illuminate\Support\Facades\Schema::hasTable('branch_shift_sources')) {
                $balance = app(BranchShiftClosing::class)->ownerBalances([$till->branch], $actor)[$till->branch]['expected_cents'];
            }
            $result['balance'] = Money::decimal($balance);
        }
        return $result;
    }

    private function checkoutResult(object $order, $actor, bool $replayed): array
    {
        $receipt = $this->presentReceipt($order);
        return ['success'=>true, 'replayed'=>$replayed, 'receipt'=>$receipt, 'receipt_url'=>$receipt['receipt_url'],
            'stock_balances'=>app(BranchStock::class)->saleBalances($order->branch,array_column($receipt['items'],'product_id')),
            'register'=>$this->presentTill($this->till($order->branch), $actor), 'today'=>$this->daily($order->branch, $this->businessDate())];
    }

    private function presentReceipt(object $row): array
    {
        $items = DB::table('takeaway_order_items')->where('order_id', $row->id)->orderBy('id')->get()->map(fn ($line) => [
            'product_id'=>(int) $line->product_id, 'name'=>$line->name, 'option_id'=>$line->option_id, 'option_label'=>$line->option_label,
            'unit'=>$line->unit ?? '', 'quantity_mode'=>$line->quantity_mode, 'quantity'=>$this->catalog->quantity((int) $line->quantity_millis),
            'unit_price'=>Money::decimal((int) $line->unit_price_cents), 'total'=>Money::decimal((int) $line->total_cents),
        ])->all();
        $receipt = ['id'=>(int) $row->id, 'number'=>'TA-'.strtoupper(str_replace(':', '-', $row->branch)).'-'.str_pad((string) $row->id, 7, '0', STR_PAD_LEFT),
            'idempotency_key'=>$row->request_key, 'branch'=>json_decode($row->branch_snapshot, true), 'cashier'=>json_decode($row->cashier_snapshot, true),
            'business_date'=>$row->business_date, 'created_at'=>$this->iso($row->created_at), 'items'=>$items,
            'payment_method'=>$row->payment_method, 'payment_confirmed'=>(bool) $row->payment_confirmed,
            'payment_reference'=>$row->payment_reference ?? '', 'notes'=>$row->notes ?? '', 'tax_rate'=>Money::decimal((int) $row->tax_bps),
            'discount_reason'=>$row->discount_reason ?? '',
            'currency'=>'EGP', 'service'=>Money::decimal((int)($row->service_cents??0)), 'service_rate'=>Money::decimal((int)($row->service_bps??0)),
            'delivery'=>Money::decimal((int)($row->delivery_cents??0)), 'channel'=>$row->channel??'takeaway','ticket_id'=>isset($row->ticket_id)?(int)$row->ticket_id:null,
            'context'=>json_decode($row->context_snapshot??'[]',true)?:[],
            'payment_breakdown'=>array_map(fn($t)=>['method'=>$t['method'],'amount'=>Money::decimal((int)$t['amount_cents']),'confirmed'=>(bool)$t['confirmed'],'reference'=>$t['reference']??''],json_decode($row->tenders_snapshot??'[]',true)?:[]),
            'status'=>'completed', 'details_url'=>route('takeaway.details', ['id'=>$row->id]), 'receipt_url'=>route('takeaway.print', ['id'=>$row->id])];
        foreach (['subtotal','discount','tax','total','cash_received','change'] as $name) $receipt[$name] = Money::decimal((int) $row->{$name.'_cents'});
        return $receipt;
    }

    private function daily(string $branch, string $date): array
    {
        $base = DB::table('takeaway_orders')->where('branch', $branch)->where('business_date', $date);
        $result = ['date'=>$date, 'count'=>(clone $base)->count(), 'total'=>Money::decimal((int) (clone $base)->sum('total_cents'))];
        foreach (array_merge(self::METHODS,['mixed']) as $method) $result[$method] = Money::decimal((int) (clone $base)->where('payment_method', $method)->sum('total_cents'));
        if ($this->access->has('takeaway_orders','tenders_snapshot')) {
            $parts=[];
            foreach ((clone $base)->where('payment_method','mixed')->get(['tenders_snapshot']) as $row) foreach (json_decode($row->tenders_snapshot??'[]',true)?:[] as $part) {
                $method=$part['method'];$parts[$method]=($parts[$method]??0)+(int)$part['amount_cents'];
            }
            foreach ($parts as $method=>$cents) if (in_array($method,self::METHODS,true)) $result[$method]=Money::decimal(Money::minor($result[$method])+$cents);
        }
        return $result;
    }

    private function money($value, string $field): int
    {
        try { $cents = Money::minor($value); }
        catch (\InvalidArgumentException $error) { throw ValidationException::withMessages([$field=>'اكتب مبلغًا صحيحًا بحد أقصى منزلتين عشريتين.']); }
        if ($cents < 0 || $cents > 100000000) throw ValidationException::withMessages([$field=>'المبلغ خارج الحد المسموح.']);
        return $cents;
    }

    private function requireReady(): void { abort_unless($this->access->ready(), 503, 'نقطة البيع لم تُجهز بعد.'); }
    private function businessDate(?Carbon $when = null): string { return ($when ? $when->copy() : now('UTC'))->setTimezone(config('app.timezone', 'Africa/Cairo'))->toDateString(); }
    private function iso(string $time): string { return Carbon::parse($time, 'UTC')->setTimezone(config('app.timezone', 'Africa/Cairo'))->toIso8601String(); }
    private function hash(array $values): string { return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
}
