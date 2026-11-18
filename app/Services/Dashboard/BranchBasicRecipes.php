<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB, Schema, Validator};

/** Frozen Mahalla recipes, mapped to branch-local SKUs without touching stock or prices. */
class BranchBasicRecipes
{
    private const SOURCE_ID = 94;
    private const SOURCE_BRANCH = 'f:94';
    private const FEATURE_NAMES = ['half', 'quarter', 'combo', 'large', 'medium'];
    private BranchOperations $ops;

    public function __construct(BranchOperations $ops) { $this->ops = $ops; }

    public function canApply($actor): bool
    {
        $actor = $this->ops->access->actor($actor);
        return in_array($actor->account_type, ['admin', 'resturant_owner'], true);
    }

    private function ready(): void
    {
        foreach (['resturants', 'resturant_products', 'branch_stock_recipes', 'stock_ingredients'] as $table) {
            abort_unless(Schema::hasTable($table), 503, 'الوصفات الأساسية تحتاج تحديث قاعدة البيانات.');
        }
    }

    private function normalized(string $name): string
    {
        $name = strtr($name, ['أ'=>'ا', 'إ'=>'ا', 'آ'=>'ا', 'ة'=>'ه', 'ى'=>'ي', '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4', '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9']);
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[ـ\x{064B}-\x{065F}]/u', '', $name)));
    }

    private function encode($data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        abort_unless(is_string($json), 503, 'تعذر قراءة بيانات الوصفات الأساسية.');
        return $json;
    }

    private function contentHash(array $payload): string
    {
        // Repeating a read-only preflight must not change the reviewed recipe digest with the clock.
        unset($payload['frozen_at']);
        return PosServiceTicket::fingerprint($payload);
    }

    private function expectedContent(array $payload, ?string $expected): string
    {
        $hash = $this->contentHash($payload);
        abort_unless($expected === null || hash_equals($hash, $expected), 409,
            'وصفات المحلة تغيرت منذ الفحص؛ أعد فحص التثبيت قبل تسجيل النسخة الأساسية.');
        return $hash;
    }

    private function stored(): ?array
    {
        if (!Schema::hasTable('branch_basic_recipes')) return null;
        $row = DB::table('branch_basic_recipes')->where('id', 1)->first();
        if (!$row) return null;
        $payload = json_decode($row->snapshot, true);
        abort_unless(is_array($payload) && ($payload['version'] ?? null) === 1
            && ($payload['source_branch'] ?? '') === self::SOURCE_BRANCH
            && is_array($payload['recipes'] ?? null) && count($payload['recipes']) > 0
            && hash_equals((string) $row->snapshot_hash, hash('sha256', (string) $row->snapshot)),
            503, 'النسخة الأساسية المسجلة غير صالحة؛ راجع الإدارة.');
        return ['payload'=>$payload, 'hash'=>(string) $row->snapshot_hash,
            'source'=>['branch'=>self::SOURCE_BRANCH, 'name'=>(string) $row->source_name,
                'frozen_at'=>\Carbon\Carbon::parse($row->frozen_at, 'UTC')->setTimezone('Africa/Cairo')->format('Y-m-d H:i'),
                'recipe_count'=>count($payload['recipes'])]];
    }

    /** --check also works before the additive singleton migration has been installed. */
    public function freeze(bool $check = false, ?string $expectedContentHash = null): array
    {
        $this->ready();
        abort_unless($expectedContentHash === null || preg_match('/^[a-f0-9]{64}$/D', $expectedContentHash),
            422, 'بصمة فحص الوصفة الأساسية غير صالحة.');
        $stored = $this->stored();
        if ($stored) return ['success'=>true, 'check'=>$check, 'frozen'=>true,
            'content_hash'=>$this->expectedContent($stored['payload'], $expectedContentHash), 'source'=>$stored['source']];
        if ($check) {
            $payload = $this->capture(false);
            return ['success'=>true, 'check'=>true, 'frozen'=>false,
                'content_hash'=>$this->expectedContent($payload, $expectedContentHash),
                'source'=>['branch'=>self::SOURCE_BRANCH, 'name'=>$payload['source_name'], 'frozen_at'=>null, 'recipe_count'=>count($payload['recipes'])]];
        }
        abort_unless(Schema::hasTable('branch_basic_recipes'), 503, 'ثبت جدول الوصفة الأساسية أولًا.');
        return DB::transaction(function () use ($expectedContentHash) {
            // Recipe writers hold this same restaurant lock, including a simultaneous freeze.
            $payload = $this->capture(true);
            $stored = $this->stored();
            if (!$stored) {
                // Compare the reviewed source after acquiring every source catalog/recipe lock.
                $this->expectedContent($payload, $expectedContentHash);
                $json = $this->encode($payload);
                DB::table('branch_basic_recipes')->insert(['id'=>1, 'source_branch'=>self::SOURCE_BRANCH,
                    'source_name'=>$payload['source_name'], 'snapshot'=>$json, 'snapshot_hash'=>hash('sha256', $json),
                    'frozen_at'=>$payload['frozen_at'], 'created_at'=>now('UTC'), 'updated_at'=>now('UTC')]);
                $stored = $this->stored();
            }
            return ['success'=>true, 'check'=>false, 'frozen'=>true,
                'content_hash'=>$this->expectedContent($stored['payload'], $expectedContentHash), 'source'=>$stored['source']];
        }, 3);
    }

    private function capture(bool $lock): array
    {
        $query = DB::table('resturants')->where('id', self::SOURCE_ID);
        if ($lock) $query->lockForUpdate();
        $source = $query->first();
        abort_unless($source && preg_match('/(?:^|[^\p{L}])المحله(?:$|[^\p{L}])/u', $this->normalized((string) $source->name)),
            409, 'تعذر التحقق من فرع المحلة رقم 94؛ لم تُثبت أي وصفة.');
        $context = $this->context(self::SOURCE_ID, $lock);
        abort_unless(count($context['recipes']) > 0, 409, 'لا توجد وصفات محفوظة في فرع المحلة لتثبيتها.');
        return ['version'=>1, 'source_branch'=>self::SOURCE_BRANCH, 'source_name'=>(string) $source->name,
            'frozen_at'=>now('UTC')->format('Y-m-d H:i:s'), 'products'=>$context['products'],
            'features'=>$context['features'], 'recipes'=>$context['recipes']];
    }

    private function context(int $branchId, bool $lock = false): array
    {
        $productQuery = DB::table('resturant_products')->where('resturant_id', $branchId)->orderBy('id');
        if ($lock) $productQuery->lockForUpdate();
        $products = $productQuery->get()->map(function ($row) {
            return ['id'=>(int) $row->id, 'name'=>(string) $row->product_name,
                'template_id'=>(int) ($row->product_id ?? 0), 'status'=>(string) ($row->status ?? '')];
        })->all();
        $templateIds = array_values(array_unique(array_filter(array_column($products, 'template_id'))));
        $features = [];
        if (Schema::hasTable('product_features') && $templateIds) {
            $featureQuery = DB::table('product_features')->whereIn('product_id', $templateIds)->orderBy('id');
            if ($lock) $featureQuery->lockForUpdate();
            $features = $featureQuery->get()->map(fn($r)=>['id'=>(int) $r->id, 'template_id'=>(int) $r->product_id, 'name'=>(string) $r->name])->all();
        }
        $recipeQuery = DB::table('branch_stock_recipes')->where('branch', 'f:'.$branchId)->orderBy('product_id');
        if ($lock) $recipeQuery->lockForUpdate();
        $recipes = $recipeQuery->get()->map(fn($r)=>[
            'id'=>(int) $r->id, 'product_id'=>(int) $r->product_id, 'unit'=>(string) $r->unit,
            'revision'=>(int) $r->revision, 'variants'=>(string) $r->variants, 'updated_at'=>(string) ($r->updated_at ?? '')])->all();
        return ['products'=>$products, 'features'=>$features, 'recipes'=>$recipes];
    }

    private function ingredients(bool $lock = false): array
    {
        $query = DB::table('stock_ingredients')->orderBy('id');
        if ($lock) $query->lockForUpdate();
        return $query->get()->map(fn($r)=>[
            'id'=>(int) $r->id, 'name'=>(string) $r->name, 'unit'=>(string) $r->unit])->keyBy('id')->all();
    }

    public function status(array $values, $actor): array
    {
        $this->ready();
        $v = Validator::make($values, ['branch'=>'required|string|max:30'])->validate();
        $branch = $this->ops->access->branch($v['branch'], $actor);
        abort_unless($branch['kind'] === 'f', 404);
        $canApply = $this->canApply($actor);
        $stored = $this->stored();
        if (!$stored) return ['success'=>true, 'branch'=>$branch, 'can_apply'=>$canApply, 'available'=>false,
            'source'=>null, 'preview_hash'=>null, 'summary'=>['matched'=>0, 'changed'=>0, 'unchanged'=>0, 'skipped'=>0], 'skipped'=>[]];
        $plan = $this->plan($branch, $stored);
        return $this->publicPlan($branch, $stored, $plan, $canApply);
    }

    private function publicPlan(array $branch, array $stored, array $plan, bool $canApply): array
    {
        return ['success'=>true, 'branch'=>$branch, 'can_apply'=>$canApply, 'available'=>true,
            'source'=>$stored['source'], 'preview_hash'=>$plan['hash'], 'summary'=>$plan['summary'], 'skipped'=>$plan['skipped']];
    }

    private function plan(array $branch, array $stored, bool $lock = false): array
    {
        $context = $this->context($branch['id'], $lock);
        $ingredients = $this->ingredients($lock);
        $sourceProducts = array_column($stored['payload']['products'] ?? [], null, 'id');
        $existing = array_column($context['recipes'], null, 'product_id');
        $targetByName = [];
        foreach ($context['products'] as $product) $targetByName[$this->normalized($product['name'])][] = $product;
        // Count candidate destinations first: every colliding source is skipped, including the first.
        $destinationCounts = [];
        foreach ($stored['payload']['recipes'] as $index=>$recipe) {
            $product = $sourceProducts[$recipe['product_id'] ?? 0] ?? null;
            if (!$product) continue;
            $candidates = $targetByName[$this->normalized($product['name'])] ?? [];
            $sameTemplate = array_values(array_filter($candidates, fn($p)=>$product['template_id'] > 0 && $p['template_id'] === $product['template_id']));
            $target = count($sameTemplate) === 1 ? $sameTemplate[0]
                : (count($sameTemplate) === 0 && count($candidates) === 1 ? $candidates[0] : null);
            if ($target) $destinationCounts[$target['id']] = ($destinationCounts[$target['id']] ?? 0) + 1;
        }
        $changes = []; $skipped = []; $summary = ['matched'=>0, 'changed'=>0, 'unchanged'=>0, 'skipped'=>0];
        foreach ($stored['payload']['recipes'] as $index=>$recipe) {
            $product = $sourceProducts[$recipe['product_id'] ?? 0] ?? null;
            $name = $product['name'] ?? 'صنف #'.($recipe['product_id'] ?? '?');
            $reason = null; $target = null; $variants = null;
            if (!$product) $reason = 'صنف المصدر غير موجود في المينيو المثبت.';
            else {
                $candidates = $targetByName[$this->normalized($name)] ?? [];
                $sameTemplate = array_values(array_filter($candidates, fn($p)=>$product['template_id'] > 0 && $p['template_id'] === $product['template_id']));
                if (count($sameTemplate) === 1) $target = $sameTemplate[0];
                elseif (count($sameTemplate) > 1 || count($candidates) > 1) $reason = 'يوجد أكثر من صنف مطابق؛ يلزم الربط يدويًا.';
                elseif (count($candidates) === 1) $target = $candidates[0];
                else $reason = 'لا يوجد صنف بنفس الاسم في هذا الفرع.';
                if ($target && ($destinationCounts[$target['id']] ?? 0) > 1) $reason = 'أكثر من وصفة أساسية تطابق الصنف نفسه؛ يلزم الربط يدويًا.';
                if (!$reason) $variants = $this->mapVariants($recipe, $product, $target, $stored['payload']['features'] ?? [], $context['features'], $ingredients, $reason);
            }
            if ($reason) { $skipped[] = ['name'=>$name, 'reason'=>$reason]; ++$summary['skipped']; continue; }
            $old = $existing[$target['id']] ?? null;
            $oldVariants = $old ? json_decode($old['variants'], true) : null;
            $same = $old && $old['unit'] === $recipe['unit'] && is_array($oldVariants)
                && PosServiceTicket::fingerprint($oldVariants) === PosServiceTicket::fingerprint($variants);
            ++$summary['matched']; ++$summary[$same ? 'unchanged' : 'changed'];
            $changes[] = ['product_id'=>$target['id'], 'unit'=>$recipe['unit'], 'variants'=>$variants, 'old'=>$old, 'changed'=>!$same];
        }
        return ['changes'=>$changes, 'skipped'=>$skipped, 'summary'=>$summary,
            'hash'=>PosServiceTicket::fingerprint(['version'=>1, 'branch'=>$branch['value'], 'baseline'=>$stored['hash'], 'context'=>$context, 'ingredients'=>$ingredients])];
    }

    private function mapVariants(array $recipe, array $source, array $target, array $sourceFeatures, array $targetFeatures, array $ingredients, ?string &$reason): ?array
    {
        $variants = json_decode($recipe['variants'] ?? '', true);
        if (!in_array($recipe['unit'] ?? '', ['kg', 'piece'], true) || !is_array($variants) || !$variants) {
            $reason = 'بيانات الوصفة الأساسية غير صالحة.'; return null;
        }
        $sourceById = array_column(array_filter($sourceFeatures, fn($f)=>$f['template_id'] === $source['template_id']), null, 'id');
        $targetByName = [];
        foreach ($targetFeatures as $f) if ($f['template_id'] === $target['template_id']) $targetByName[$f['name']][] = $f['id'];
        $mapped = [];
        foreach ($variants as $featureId=>$components) {
            if (!preg_match('/^(0|[1-9][0-9]*)$/D', (string) $featureId)) { $reason = 'حجم الوصفة الأساسية غير صالح.'; return null; }
            $targetFeature = 0;
            if ((int) $featureId !== 0) {
                $feature = $sourceById[$featureId] ?? null;
                $name = $feature['name'] ?? '';
                if (!$feature || !in_array($name, self::FEATURE_NAMES, true) || count($targetByName[$name] ?? []) !== 1) {
                    $reason = 'أحد أحجام الوصفة غير موجود أو غير واضح في هذا الفرع.'; return null;
                }
                $targetFeature = $targetByName[$name][0];
            }
            if (isset($mapped[(string) $targetFeature]) || !is_array($components) || !$components || count($components) > count($ingredients)) {
                $reason = 'مكونات أو أحجام الوصفة الأساسية غير صالحة.'; return null;
            }
            $clean = []; $ids = [];
            foreach ($components as $component) {
                if (!is_array($component)) { $reason = 'أحد مكونات الوصفة الأساسية غير صالح.'; return null; }
                $id = $component['ingredient_id'] ?? 0; $ingredient = is_int($id) ? ($ingredients[$id] ?? null) : null;
                $measure = $component['measure'] ?? ''; $quantity = $component['quantity'] ?? null; $units = $component['quantity_units'] ?? null;
                $validQuantity = is_string($quantity) && preg_match('/^[0-9]{1,7}(?:\.[0-9]{1,3})?$/D', $quantity);
                if (!$ingredient || isset($ids[$id]) || ($component['unit'] ?? '') !== $ingredient['unit']
                    || !in_array($ingredient['unit'], ['kg', 'piece'], true)
                    || !in_array($measure, $ingredient['unit'] === 'kg' ? ['kg','g'] : ['piece'], true)
                    || !is_int($units) || $units <= 0 || $units > 1000000000 || !$validQuantity) {
                    $reason = 'أحد مكونات الوصفة غير متاح أو وحدته أو مقداره غير صالح.'; return null;
                }
                $parts = explode('.', $quantity);
                $quantityUnits = (int) $parts[0] * 1000000 + (int) str_pad($parts[1] ?? '', 6, '0');
                if ($measure === 'g') $quantityUnits = intdiv($quantityUnits, 1000);
                if ($quantityUnits !== $units) { $reason = 'مقدار أحد المكونات لا يطابق الوصفة المسجلة.'; return null; }
                $ids[$id] = true;
                $clean[] = ['ingredient_id'=>(int) $id, 'name'=>$ingredient['name'], 'unit'=>$ingredient['unit'], 'quantity_units'=>$units, 'measure'=>$measure, 'quantity'=>$quantity];
            }
            usort($clean, fn($a,$b)=>$a['ingredient_id'] <=> $b['ingredient_id']);
            $mapped[(string) $targetFeature] = $clean;
        }
        ksort($mapped, SORT_NUMERIC);
        return $mapped;
    }

    public function apply(array $values, $actor): array
    {
        $this->ready();
        abort_unless($this->canApply($actor), 403, 'استخدام الوصفة الأساسية متاح للإدارة والمالك فقط.');
        $v = Validator::make($values, $this->ops->rules() + ['preview_hash'=>'required|string|size:64|regex:/^[a-f0-9]{64}$/D'])->validate();
        return $this->ops->write('inventory.basic-recipes', $v, $actor, function ($branch, $freshActor) use ($v) {
            abort_unless($branch['kind'] === 'f' && $this->canApply($freshActor), 403);
            $stored = $this->stored();
            abort_unless($stored, 503, 'لم تُثبت الوصفة الأساسية بعد.');
            $plan = $this->plan($branch, $stored, true);
            abort_unless(hash_equals($plan['hash'], $v['preview_hash']), 409, 'الوصفات أو أصناف الفرع تغيرت؛ حدّث المعاينة وأعد المحاولة.');
            foreach ($plan['changes'] as $change) {
                if (!$change['changed']) continue;
                $old = $change['old'];
                $data = ['unit'=>$change['unit'], 'variants'=>$this->encode((object) $change['variants']),
                    'revision'=>$old ? $old['revision'] + 1 : 1, 'updated_by'=>(int) $freshActor->id, 'updated_at'=>now('UTC')];
                $query = DB::table('branch_stock_recipes')->where('branch', $branch['value'])->where('product_id', $change['product_id']);
                if ($old) $query->update($data);
                else $query->insert($data + ['branch'=>$branch['value'], 'product_id'=>$change['product_id'], 'created_at'=>now('UTC')]);
            }
            return ['basic_recipes'=>['source'=>$stored['source'], 'summary'=>$plan['summary'], 'skipped'=>$plan['skipped']],
                'operation'=>['branch'=>$branch['value'], 'idempotency_key'=>$v['idempotency_key'], 'preview_hash'=>$v['preview_hash']]];
        });
    }
}
