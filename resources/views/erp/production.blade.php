@extends('erp.layout')
@section('title','التصنيع والوصفات')
@section('subtitle','تحويل الخامات والتغليف إلى منتج جاهز بتكلفة موثقة')
@section('content')
<section class="card"><h2>الوصفات المسجلة</h2>@forelse($recipes as $recipe)<details class="details"><summary><strong>#{{ $recipe->id }} — {{ $recipe->name }}</strong> <span class="badge">{{ \App\Services\Erp\Decimal::format($recipe->output_milli,3) }} {{ $recipe->unit==='kg'?'كجم':'قطعة' }} {{ $recipe->item_name }}</span></summary><div class="details-body"><div class="table-wrap"><table><thead><tr><th>المكون</th><th>لكل وصفة</th></tr></thead><tbody>
@foreach($components[$recipe->id]??[] as $component)<tr><td>{{ $component->item_name }}</td><td>{{ \App\Services\Erp\Decimal::format($component->quantity_milli,3) }} {{ $component->unit==='kg'?'كجم':'قطعة' }}</td></tr>@endforeach
</tbody></table></div><p class="note">{{ $recipe->notes }}</p></div></details>@empty<div class="empty">سجل وصفة بها الخامات والتغليف وكمية المنتج الناتج.</div>@endforelse</section>
<section class="card"><h2>ترحيل دفعة تصنيع</h2><form method="post" action="{{ route('erp.production.post') }}" data-confirm="هل تؤكد تنفيذ الدفعة فعليًا؟ سيتم خصم مكونات الوصفة وإضافة الناتج بتكلفتها.">@csrf<input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<div class="fields"><div class="field"><label>الوصفة<select name="recipe_id" required>@foreach($recipes as $recipe)<option value="{{ $recipe->id }}">#{{ $recipe->id }} — {{ $recipe->name }} ({{ \App\Services\Erp\Decimal::format($recipe->output_milli,3) }} {{ $recipe->unit==='kg'?'كجم':'قطعة' }})</option>@endforeach</select></label></div>
<div class="field"><label>مخزن الخامات والناتج<select name="warehouse_id" required>@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></label></div>
<div class="field"><label>عدد مرات الوصفة<input name="factor" type="number" value="1" min="0.001" max="100" step="0.001" required></label></div>
<div class="field"><label>كمية الناتج الفعلي بوحدة الصنف<input name="actual_output" type="number" min="0.001" step="0.001" required></label></div>
<div class="field full"><label>ملاحظات الدفعة وفروق الناتج<textarea name="notes" required maxlength="500"></textarea></label></div></div>
<p class="note">المكونات تخصم بمقادير الوصفة × عدد مرات تنفيذها، ويحمل الناتج كامل تكلفتها. سجل أي اختلاف فعلي في استهلاك المكونات بحركة مخزون مستقلة. الدفعة تنفذ مرة واحدة، بدون مراحل تحت التشغيل أو إضافة عمالة وتكاليف غير مباشرة.</p>
<div class="actions"><button {{ $recipes->isEmpty() || $warehouses->isEmpty() ? 'disabled':'' }}>ترحيل دفعة التصنيع</button></div></form></section>
@if($actor->allBranches())<details class="card"><summary><strong>وصفة جديدة / إصدار جديد</strong></summary><div class="details-body"><form method="post" action="{{ route('erp.recipes.save') }}">@csrf<div class="fields">
<div class="field"><label>اسم الوصفة والإصدار<input name="name" required maxlength="120" placeholder="سلطة رنجة — إصدار 1"></label></div>
<div class="field"><label>المنتج الناتج<select name="output_item_id" required>@foreach($items->where('category','finished') as $item)<option value="{{ $item->id }}">{{ $item->name }} — {{ $item->unit==='kg'?'كجم':'قطعة' }}</option>@endforeach</select></label></div>
<div class="field"><label>الناتج المتوقع من وصفة واحدة<input name="output_quantity" type="number" min="0.001" step="0.001" required></label></div>
<div class="field"><label>ملاحظات<input name="notes" maxlength="500"></label></div></div><h3 class="subheading">الخامات ومواد التغليف</h3>
@include('erp.line-editor',['cost'=>false])<p class="note">الوصفات ثابتة للحفاظ على تاريخ التشغيل؛ أي تغيير يُسجل كوصفة جديدة بإصدار مختلف.</p><div class="actions"><button {{ $items->where('category','finished')->isEmpty() ? 'disabled':'' }}>حفظ الوصفة</button></div></form></div></details>@endif
<section class="card"><h2>دفعات الإنتاج</h2><div class="table-wrap"><table><thead><tr><th>الدفعة</th><th>الوصفة / المخزن</th><th>المتوقع</th><th>الفعلي</th><th>فرق الناتج</th><th>تكلفة الدفعة</th></tr></thead><tbody>
@forelse($batches as $b)<tr><td>#{{ $b->id }}<small>{{ $b->created_at }}</small></td><td>{{ $b->recipe_name }}<small>{{ $b->warehouse_name }}</small></td><td>{{ \App\Services\Erp\Decimal::format($b->expected_output_milli,3) }}</td><td>{{ \App\Services\Erp\Decimal::format($b->actual_output_milli,3) }} {{ $b->unit==='kg'?'كجم':'قطعة' }}</td><td class="number">{{ \App\Services\Erp\Decimal::format($b->actual_output_milli-$b->expected_output_milli,3) }}</td><td>{{ \App\Services\Erp\Decimal::format($b->total_minor) }} ج.م</td></tr>@empty<tr><td colspan="6" class="empty">لا توجد دفعات مرحّلة.</td></tr>@endforelse
</tbody></table></div>@include('erp.pagination',['paginator'=>$batches])</section>
@endsection
