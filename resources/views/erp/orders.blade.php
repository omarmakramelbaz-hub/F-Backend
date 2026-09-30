@extends('erp.layout')
@section('title','طلبات التطبيق')
@section('subtitle','متابعة الطلبات الحالية للفروع المسجلة في ERP')
@section('content')
@include('erp.filter')
@php
$labels = ['new'=>'جديد','preparing'=>'قيد التجهيز','delivery'=>'مع المندوب','done'=>'منتهي'];
$statuses = ['pending'=>'بانتظار القبول','another_delegate'=>'بانتظار مندوب','accepted'=>'مقبول','new_order'=>'تجهيز الطلب','shipped'=>'خرج للتوصيل','completed'=>'مكتمل','cancelled'=>'ملغي','declined'=>'مرفوض'];
@endphp
<div class="board">@foreach($columns as $key=>$column)<section class="board-column"><div class="section-header"><h2>{{ $labels[$key] }}</h2><span class="badge">{{ $column['count'] }}</span></div>@forelse($column['rows'] as $order)<article class="order-card"><div class="section-header"><strong>#{{ $order->order_no ?: $order->id }}</strong><small>{{ \Carbon\Carbon::parse($order->created_at)->timezone(config('erp.timezone'))->format('H:i') }}</small></div><p>{{ optional($branches->firstWhere('restaurant_id',$order->resturant_id))->name }}</p><span class="badge {{ $order->status === 'completed' ? 'green' : (in_array($order->status,['cancelled','declined']) ? 'red':'') }}">{{ $statuses[$order->status] ?? $order->status }}</span><p class="note">الدفع: {{ $order->payment_type }}</p></article>@empty<div class="empty">لا توجد طلبات</div>@endforelse @if($column['count'] > 30)<p class="note">يظهر آخر 30 طلبًا من {{ $column['count'] }}.</p>@endif</section>@endforeach</div><p class="note">شاشة متابعة. تنفيذ الطلب وتغيير حالته من لوحة التشغيل الحالية. حدّث الصفحة لعرض أحدث حالة.</p>
@endsection
