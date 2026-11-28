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
        // The customer sees a button, not a raw link; it opens the menu over the chat.
        $title = 'منيو فسخانستا 🐟'; $subtitle = 'اختار أصنافك والكميات بنفسك، وبعد التأكيد هيوصلك ملخص الطلب هنا في الشات.';
        $button = '🛒 اطلب من المنيو';
        abort_unless(($state['can_reply'] ?? false) === true && $state['latest_inbound_id'], 409, 'مهلة الرد على العميل ده انتهت (٢٤ ساعة من آخر رسالة ليه).');
        if ($channel === 'whatsapp') {
            $sent = app(WhatsAppReplyService::class)->sendButton($actor, $conversation, ['client_request_id' => (string) Str::uuid(),
                'expected_inbound_id' => (int) $state['latest_inbound_id'], 'text' => $title."\n".$subtitle], $button, $url);
            abort_unless(($sent['success'] ?? false) === true, 502, 'واتساب مقبلش رسالة المنيو، جرّب تاني.');
        } else {
            app(MetaReplyService::class)->sendMenuCard($actor, $conversation, ['title' => $title, 'subtitle' => $subtitle,
                'button' => $button, 'url' => $url, 'image' => url('/dashboard/branding/fasakhansta-logo-transparent.png')]);
        }
        return ['success' => true, 'message' => 'اتبعت المنيو للعميل ✓'];
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
        $lines = []; $total = 0; $seen = []; $orderItems = [];
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
            $orderItems[] = ['product_id' => (int) $product['id'], 'option_id' => $optionId === '' ? null : $optionId,
                'quantity' => $this->number($quantity), 'quantity_mode' => $mode, 'name' => $this->clean((string) $product['name']),
                'option_hint' => $label === '' ? null : $label];
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
            $sent = $service->send($employee, (int) $link->conversation_id, ['client_request_id' => (string) Str::uuid(),
                'expected_inbound_id' => (int) $state['latest_inbound_id'], 'text' => $text]);
            if (($sent['success'] ?? false) !== true) throw new \RuntimeException('CHAT_MENU_SUMMARY_'.($sent['state'] ?? 'FAILED'));
        } catch (\Throwable $error) {
            DB::table('chat_menu_links')->where('id', $link->id)->update(['submitted_at' => null, 'updated_at' => now()]);
            if ($error instanceof HttpException && preg_match('/\p{Arabic}/u', $error->getMessage())) throw $error;
            report($error);
            throw new HttpException(503, 'معرفناش نبعت الطلب للشات دلوقتي. جرّب تاني بعد شوية أو اكتب طلبك في الشات.');
        }
        // The exact order is kept beside its text, so the employee's analysis fills the same products and branch.
        $order = ['branch_id' => (int) $branch->id, 'branch_name' => $this->clean((string) $branch->name),
            'customer' => ['name' => $this->clean($v['customer_name']), 'phone' => $v['customer_phone'], 'address' => $this->clean($v['address']),
                'area' => $this->clean($v['area']), 'notes' => $notes === '' ? null : $notes],
            'items' => $orderItems, 'total' => $this->number(round($total / 100, 2))];
        DB::table('chat_menu_links')->where('id', $link->id)->update(['summary' => Crypt::encryptString(json_encode(['text' => $text, 'order' => $order],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'updated_at' => now()]);
        return ['success' => true, 'total' => $this->number(round($total / 100, 2))];
    }

    /**
     * Employee analysis: the latest menu order whose summary is in this chat, as a draft for review (never automatic).
     * $transcript rows are the analysis snapshot; the summary must be one of our own messages in it.
     */
    public function draftFor(string $channel, int $conversation, array $transcript): ?array
    {
        if (!$this->ready()) return null;
        $norm = fn($text) => trim(preg_replace('/[\p{Z}\t]+/u', ' ', str_replace("\r\n", "\n", (string) $text)));
        $sent = [];
        foreach ($transcript as $row) {
            if (($row['speaker'] ?? null) === 'business' && is_string($row['text'] ?? null)
                && str_starts_with(trim($row['text']), 'ملخص الطلب النهائي:')) $sent[$norm($row['text'])] = $row['id'];
        }
        if ($sent === []) return null;
        $links = DB::table('chat_menu_links')->where('channel', $channel)->where('conversation_id', $conversation)
            ->whereNotNull('submitted_at')->whereNotNull('summary')->orderByDesc('id')->limit(10)->get();
        foreach ($links as $link) {
            try { $stored = Crypt::decryptString($link->summary); } catch (\Throwable $error) { continue; }
            $json = json_decode($stored, true);
            $text = is_array($json) ? (string) ($json['text'] ?? '') : $stored;
            $id = $sent[$norm($text)] ?? null;
            if ($id === null) continue;
            $order = is_array($json['order'] ?? null) ? $json['order'] : $this->parseSummary($text);
            return $order ? $this->draftData($order, $id) : null;
        }
        return null;
    }

    /** Summaries sent before the order was stored beside them: read back from the text this page wrote. */
    private function parseSummary(string $text): ?array
    {
        $fields = []; $items = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if (preg_match('/\A- (.+?) \| الكمية: ([0-9.]+) (كيلو|قطعة)(?: \| الاختيار: (.+))?\z/u', $line, $m)) {
                $items[] = ['product_id' => null, 'option_id' => null, 'quantity' => $m[2], 'quantity_mode' => $m[3] === 'كيلو' ? 'weight' : 'piece',
                    'name' => $m[1], 'option_hint' => $m[4] ?? null];
            } elseif (preg_match('/\A(الاسم|رقم الهاتف|الفرع|العنوان|المنطقة|ملاحظات|الإجمالي التقريبي):\s*(.+)\z/u', $line, $m)) {
                $fields[$m[1]] = $m[2];
            }
        }
        if ($items === [] || !isset($fields['الفرع'])) return null;
        $notes = $fields['ملاحظات'] ?? null;
        return ['branch_id' => null, 'branch_name' => $fields['الفرع'],
            'customer' => ['name' => $fields['الاسم'] ?? null, 'phone' => $fields['رقم الهاتف'] ?? null, 'address' => $fields['العنوان'] ?? null,
                'area' => $fields['المنطقة'] ?? null, 'notes' => $notes === 'لا يوجد' ? null : $notes],
            'items' => $items, 'total' => isset($fields['الإجمالي التقريبي']) ? preg_replace('/\s*جنيه\z/u', '', $fields['الإجمالي التقريبي']) : null];
    }

    private function draftData(array $order, $summaryId): array
    {
        $branchId = (int) ($order['branch_id'] ?? 0);
        if ($branchId < 1) {
            foreach ($this->branchList() as $branch) {
                if ($this->clean($branch['name']) === $this->clean((string) $order['branch_name'])) { $branchId = $branch['id']; break; }
            }
        }
        $items = $order['items'];
        if ($branchId > 0 && collect($items)->contains(fn($item) => empty($item['product_id']))) {
            try {
                [, $actor] = $this->branch($branchId);
                $byName = collect($this->listing($branchId, $actor))->groupBy(fn($product) => $this->clean((string) $product['name']));
                foreach ($items as &$item) {
                    if (!empty($item['product_id'])) continue;
                    $matches = $byName->get($this->clean((string) $item['name']));
                    if (!$matches || $matches->count() !== 1) continue;
                    $product = $matches->first(); $item['product_id'] = (int) $product['id'];
                    if ($item['option_hint'] !== null) {
                        $option = collect($product['options'] ?? [])->first(fn($o) => $this->clean((string) $o['label']) === $this->clean((string) $item['option_hint']));
                        $item['option_id'] = $option['id'] ?? null;
                    }
                }
                unset($item);
            } catch (\Throwable $error) {
                // The employee picks the products; the names and quantities stay as hints.
            }
        }
        return ['decision' => 'DRAFT', 'fulfillment' => 'DELIVERY', 'source' => 'chat_menu', 'employee_review' => true,
            'customer' => $order['customer'], 'branch_hint' => $order['branch_name'], 'branch' => $branchId > 0 ? 'f:'.$branchId : null,
            'items' => array_values($items), 'approximate_total' => $order['total'], 'issues' => [],
            'evidence' => ['confirmation_ids' => [$summaryId]]];
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
