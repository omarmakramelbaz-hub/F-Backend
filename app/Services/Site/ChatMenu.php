<?php

namespace App\Services\Site;

use App\Models\User;
use App\Services\Dashboard\{MetaInboxAccess, MetaReplyService, TakeawayCatalog, WhatsAppInboxAccess, WhatsAppReplyService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB, Schema, Validator};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * «إرسال المنيو»: the employee sends a personal link in the chat; the customer picks products (+/-) and fills the
 * delivery details; the order summary is then sent back into the same chat in the «ملخص الطلب النهائي» form the
 * order analysis already reads, for the employee to review and send to the branch.
 */
class ChatMenu extends WebStorefront
{
    private const TTL_HOURS = 24;
    private const MAX_TEXT = 1000;

    public function ready(): bool { return Schema::hasTable('chat_menu_links'); }

    /** Dashboard: a new link for this conversation. */
    public function create($actor, string $channel, int $conversation): array
    {
        abort_unless($this->ready(), 503, 'CHAT_MENU_UNAVAILABLE');
        abort_unless(in_array($channel, ['whatsapp', 'meta'], true), 422);
        $actor = $channel === 'whatsapp' ? app(WhatsAppInboxAccess::class)->actor($actor) : app(MetaInboxAccess::class)->actor($actor);
        $state = $this->replies($channel)->state($actor, $conversation);
        abort_unless(($state['available'] ?? false) === true, 409, 'REPLIES_UNAVAILABLE');
        $token = Str::random(40);
        DB::table('chat_menu_links')->insert(['token_hash' => hash('sha256', $token), 'channel' => $channel,
            'conversation_id' => $conversation, 'actor_id' => (int) $actor->id,
            'expires_at' => now()->addHours(self::TTL_HOURS), 'created_at' => now(), 'updated_at' => now()]);
        $url = route('chat-menu.show', $token);
        return ['success' => true, 'url' => $url,
            'text' => "اتفضل منيو فسخانستا 🐟\nاختار الأصناف والكميات بنفسك من هنا، وبعد التأكيد هيوصلك ملخص الطلب هنا في الشات:\n".$url];
    }

    public function link(string $token): object
    {
        abort_unless($this->ready() && preg_match('/\A[A-Za-z0-9]{40}\z/', $token), 404);
        $link = DB::table('chat_menu_links')->where('token_hash', hash('sha256', $token))->first();
        abort_unless($link, 404);
        return $link;
    }

    public function expired(object $link): bool { return now()->greaterThan($link->expires_at); }

    /** Public branch list for the menu page; no customer or internal data. */
    public function branchList(): array
    {
        $result = [];
        foreach ($this->publicBranchesQuery()->orderBy('id')->get() as $branch) {
            $result[] = ['id' => (int) $branch->id, 'name' => (string) $branch->name, 'address' => (string) $branch->address,
                'open' => $branch->effective_status === 'opened'];
        }
        return $result;
    }

    public function menu(int $branchId): array
    {
        [$branch, $actor] = $this->branch($branchId);
        $products = [];
        $catalog = $this->catalogFor($branchId, $actor);
        foreach ($this->sellable($catalog) as $item) {
            $product = array_intersect_key($item, array_flip(['id', 'name', 'price', 'image_url', 'category_id', 'options']));
            $product['mode'] = $this->mode($item);
            $products[] = $product;
        }
        return ['products' => $products, 'categories' => $catalog['categories'] ?? [], 'open' => $branch->effective_status === 'opened'];
    }

    /** Customer confirms: the summary goes into the chat as the employee who sent the link. */
    public function submit(object $link, Request $request): array
    {
        abort_if($this->expired($link), 410, 'انتهت صلاحية الرابط، اطلب رابط منيو جديد من الشات.');
        abort_if($link->submitted_at !== null, 409, 'الطلب ده اتبعت قبل كده. لو عايز تعدّل اكتبلنا في الشات.');
        $digits = fn($v) => strtr(trim((string) $v), ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $request->merge(['customer_phone' => preg_replace('/[\s-]+/', '', $digits($request->input('customer_phone')))]);
        $v = Validator::make($request->all(), [
            'branch' => 'required|integer|min:1', 'customer_name' => 'required|string|min:2|max:80',
            'customer_phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/D'],
            'address' => 'required|string|min:8|max:250', 'area' => 'required|string|min:2|max:80', 'notes' => 'nullable|string|max:200',
            'items' => 'required|array|min:1|max:20', 'items.*.product_id' => 'required|integer|min:1',
            'items.*.option_id' => 'nullable|string|max:80', 'items.*.quantity' => 'required|numeric|min:0.25|max:99',
        ], ['customer_phone.regex' => 'اكتب رقم موبايل مصري صحيح من ١١ رقم.'])->validate();
        [$branch, $actor] = $this->branch((int) $v['branch']);
        $products = [];
        foreach ($this->listing((int) $v['branch'], $actor) as $item) $products[(int) $item['id']] = $item;
        $lines = []; $total = 0; $seen = [];
        foreach ($v['items'] as $line) {
            $product = $products[(int) $line['product_id']] ?? null;
            abort_unless($product, 409, 'أحد الأصناف مش متاح دلوقتي. حدّث المنيو.');
            $optionId = (string) ($line['option_id'] ?? ''); $price = $product['price']; $label = null;
            if ($optionId !== '') {
                $option = collect($product['options'] ?? [])->firstWhere('id', $optionId);
                abort_unless($option, 409, 'أحد الاختيارات مش متاح دلوقتي. حدّث المنيو.');
                $price = $option['price']; $label = $this->clean((string) $option['label']);
            }
            $key = $product['id'].'|'.$optionId;
            abort_if(isset($seen[$key]), 422); $seen[$key] = true;
            $mode = $this->mode($product); $quantity = round((float) $line['quantity'], 3);
            $step = $mode === 'weight' ? 0.25 : 1.0;
            abort_unless(abs($quantity / $step - round($quantity / $step)) < 0.0001, 422, 'كمية غير صحيحة.');
            $total += (int) round((float) $price * 100) * $quantity;
            $lines[] = '- '.$this->clean((string) $product['name']).' | الكمية: '.$this->number($quantity).' '
                .($mode === 'weight' ? 'كيلو' : 'قطعة').($label !== null && $label !== '' ? ' | الاختيار: '.$label : '');
        }
        $notes = $this->clean((string) ($v['notes'] ?? ''));
        $text = implode("\n", array_merge(['ملخص الطلب النهائي:',
            'الاسم: '.$this->clean($v['customer_name']), 'رقم الهاتف: '.$v['customer_phone'], 'نوع الطلب: دليفري',
            'الفرع: '.$this->clean((string) $branch->name), 'العنوان: '.$this->clean($v['address']), 'المنطقة: '.$this->clean($v['area']),
            'ملاحظات: '.($notes === '' ? 'لا يوجد' : $notes), 'الأصناف:'], $lines,
            ['الإجمالي التقريبي: '.$this->number(round($total / 100, 2)).' جنيه', 'للموافقة على هذه البيانات اكتب: موافق على الطلب']));
        abort_if(mb_strlen($text, 'UTF-8') > self::MAX_TEXT, 422, 'الطلب طويل جدًا، قلل عدد الأصناف أو كمّل الباقي في الشات.');

        // Claim once; a failed send releases the claim so the customer can retry.
        $claimed = DB::table('chat_menu_links')->where('id', $link->id)->whereNull('submitted_at')
            ->update(['submitted_at' => now(), 'updated_at' => now()]);
        abort_unless($claimed === 1, 409, 'الطلب ده اتبعت قبل كده.');
        try {
            $employee = User::withoutGlobalScopes()->find($link->actor_id);
            abort_unless($employee, 503);
            $service = $this->replies($link->channel);
            $state = $service->state($employee, (int) $link->conversation_id);
            if (($state['can_reply'] ?? false) !== true || !$state['latest_inbound_id']) {
                throw new HttpException(409, 'مهلة المحادثة انتهت. ابعتلنا أي رسالة في الشات وبعدها اضغط تأكيد تاني.');
            }
            $service->send($employee, (int) $link->conversation_id, ['client_request_id' => (string) Str::uuid(),
                'expected_inbound_id' => (int) $state['latest_inbound_id'], 'text' => $text]);
        } catch (\Throwable $error) {
            DB::table('chat_menu_links')->where('id', $link->id)->update(['submitted_at' => null, 'updated_at' => now()]);
            if ($error instanceof HttpException && preg_match('/\p{Arabic}/u', $error->getMessage())) throw $error;
            report($error);
            throw new HttpException(503, 'معرفناش نبعت الطلب للشات دلوقتي. جرّب تاني بعد شوية أو اكتب طلبك في الشات.');
        }
        DB::table('chat_menu_links')->where('id', $link->id)->update(['summary' => Crypt::encryptString($text), 'updated_at' => now()]);
        return ['success' => true, 'total' => $this->number(round($total / 100, 2))];
    }

    private function catalogFor(int $branchId, $actor): array
    {
        app()->setLocale('ar'); // Product, option and category names as the customer reads them.
        return app(TakeawayCatalog::class)->listing(['branch' => 'f:'.$branchId, 'per_page' => 500, 'page' => 1], $actor);
    }

    /** Website rules: only available, priced products; staff-only items stay at the branch. */
    private function sellable(array $catalog): array
    {
        return array_values(array_filter($catalog['items'] ?? [], fn($item) => $item['available'] && $item['price'] !== null
            && !\App\Support\PosOnlyProducts::is($item['id'])));
    }

    private function listing(int $branchId, $actor): array { return $this->sellable($this->catalogFor($branchId, $actor)); }

    /** Same rule as the website cart: by the kilo when the product is sold by weight. */
    private function mode(array $item): string
    {
        $mode = $item['quantity_mode'] ?? 'select';
        return $mode === 'weight' || ($mode === 'select' && preg_match('/كيلو|كجم/u', (string) $item['name'])) ? 'weight' : 'piece';
    }

    private function replies(string $channel) { return $channel === 'whatsapp' ? app(WhatsAppReplyService::class) : app(MetaReplyService::class); }
    private function clean(string $value): string { return trim(preg_replace('/[\p{Z}\s|]+/u', ' ', strip_tags($value))); }
    private function number(float $value): string { return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.'); }
}
