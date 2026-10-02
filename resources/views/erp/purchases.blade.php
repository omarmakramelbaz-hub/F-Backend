@extends('erp.layout')
@section('title','الموردون والمشتريات')
@section('subtitle','فواتير مستلمة ترتبط بالمخزون ومستحقات الموردين')
@section('content')
<section class="card"><h2>حسابات الموردين</h2>
<div class="table-wrap"><table><thead><tr><th>المورد</th><th>الهاتف</th><th>المستحق بالجنيه</th><th>الحالة</th></tr></thead><tbody>
@forelse($suppliers as $s)<tr><td>{{ $s->name }}</td><td>{{ $s->phone }}</td><td class="number">{{ \App\Services\Erp\Decimal::format((int)($owed[$s->id]??0)) }}</td><td><span class="badge {{ $s->active ? 'green':'red' }}">{{ $s->active ? 'نشط':'متوقف' }}</span></td></tr>@empty<tr><td colspan="4" class="empty">أضف أول مورد لبدء تسجيل المشتريات.</td></tr>@endforelse
</tbody></table></div><p class="note">المستحق يشمل الفواتير المسجلة هنا بعد خصم السداد. سداد المورد يتم من شاشة الحسابات والكاش ويخص إجمالي حسابه.</p>
</section>
<details class="card"><summary><strong>إضافة مورد أو تحديث بياناته</strong></summary><div class="details-body">
<form method="post" action="{{ route('erp.suppliers.save') }}">@csrf<div class="fields">
<div class="field"><label>المورد<select name="id" data-supplier-picker><option value="">مورد جديد</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" data-supplier="{{ json_encode($s) }}">{{ $s->name }}</option>@endforeach</select></label></div>
<div class="field"><label>الاسم<input name="name" required maxlength="120"></label></div><div class="field"><label>الهاتف<input name="phone" maxlength="32"></label></div>
<div class="field"><label>الحالة<select name="active"><option value="1">نشط</option><option value="0">متوقف</option></select></label></div>
<div class="field full"><label>العنوان<input name="address" maxlength="300"></label></div></div><div class="actions"><button>حفظ المورد</button></div></form>
</div></details>
@if($actor->can('inventory.manage'))<section class="card"><h2>تسجيل فاتورة واستلام البضاعة</h2>
<form method="post" action="{{ route('erp.purchases.receive') }}" data-confirm="هل تؤكد استلام جميع أصناف الفاتورة فعليًا؟ سيتم تحديث المخزون والمستحق للمورد.">@csrf
<input type="hidden" name="request_key" value="{{ old('request_key',(string) \Illuminate\Support\Str::uuid()) }}"><div class="fields">
<div class="field"><label>المورد<select name="supplier_id" required><option value="">اختر المورد</option>@foreach($suppliers->where('active',true) as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label></div>
<div class="field"><label>مخزن الاستلام<select name="warehouse_id" required>@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></label></div>
<div class="field"><label>رقم فاتورة المورد<input name="invoice_number" required maxlength="80" value="{{ old('invoice_number') }}"></label></div>
<div class="field"><label>تاريخ الفاتورة<input name="invoice_date" type="date" value="{{ now(config('erp.timezone'))->format('Y-m-d') }}" max="{{ now(config('erp.timezone'))->format('Y-m-d') }}" required></label></div>
</div><h3 class="subheading">الأصناف المستلمة</h3>@include('erp.line-editor',['cost'=>true])
<div class="field"><label>ملاحظات الاستلام<textarea name="notes" required maxlength="500">{{ old('notes') }}</textarea></label></div>
<p class="note">التكلفة هي تكلفة المخزون للوحدة. القيد بتاريخ الترحيل الحالي، مع حفظ تاريخ فاتورة المورد للمرجع. الفاتورة تثبت الاستلام الكامل؛ الضرائب والخصومات والمرتجعات ليست محسوبة تلقائيًا.</p>
<div class="actions"><button {{ $suppliers->where('active',true)->isEmpty() || $items->isEmpty() || $warehouses->isEmpty() ? 'disabled':'' }}>ترحيل الفاتورة والاستلام</button></div></form></section>@endif
<section class="card"><h2>الفواتير المسجلة</h2>@forelse($purchases as $p)<details class="details"><summary><strong>#{{ $p->id }} — {{ $p->supplier_name }}</strong> <span class="badge">{{ \App\Services\Erp\Decimal::format($p->total_minor) }} ج.م</span> • فاتورة {{ $p->invoice_number }}</summary><div class="details-body">
<p>{{ $p->warehouse_name }} • {{ $p->invoice_date }}</p><div class="table-wrap"><table><thead><tr><th>الصنف</th><th>الكمية</th><th>تكلفة الوحدة</th><th>الإجمالي</th></tr></thead><tbody>
@foreach($lines[$p->id]??[] as $line)<tr><td>{{ $line->item_name }}</td><td>{{ \App\Services\Erp\Decimal::format($line->quantity_milli,3) }} {{ $line->unit==='kg'?'كجم':'قطعة' }}</td><td>{{ \App\Services\Erp\Decimal::format($line->unit_cost_minor) }}</td><td>{{ \App\Services\Erp\Decimal::format($line->total_minor) }}</td></tr>@endforeach
</tbody></table></div><p class="note">{{ $p->notes }}</p></div></details>@empty<div class="empty">لم تُرحل فواتير بعد.</div>@endforelse
@include('erp.pagination',['paginator'=>$purchases])</section>
@endsection
