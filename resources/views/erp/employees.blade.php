@extends('erp.layout')
@section('title','الموظفون والحضور')
@section('subtitle','ملفات العاملين وتسجيل الحضور اليومي حسب الفرع')
@section('content')
@php($attendanceNames = ['present'=>'حاضر','absent'=>'غائب','paid_leave'=>'إجازة مدفوعة','unpaid_leave'=>'إجازة بدون أجر'])
@include('erp.filter')
<section class="card"><div class="section-header"><h2>فريق العمل</h2><span class="badge">{{ $employees->total() }} موظف</span></div>
@forelse($employees as $e)<details class="details"><summary><strong>{{ $e->name }}</strong> <span>{{ $e->job_title }} • {{ $e->branch_name }}</span> <span class="badge {{ $e->attendance_status === 'present' ? 'green':'' }}">{{ $attendanceNames[$e->attendance_status] ?? 'لم يسجل الحضور' }}</span>@if(!$e->active)<span class="badge red">غير نشط</span>@endif</summary><div class="details-body grid2"><div><h3>الحضور — {{ $day }}</h3><form method="post" action="{{ route('erp.attendance',$e->id) }}">@csrf<input type="hidden" name="day" value="{{ $day }}"><div class="fields"><div class="field full"><label>الحالة<select name="status">@foreach($attendanceNames as $value=>$label)<option value="{{ $value }}" {{ $e->attendance_status === $value ? 'selected':'' }}>{{ $label }}</option>@endforeach</select></label></div><div class="field full"><label>ملاحظات<textarea name="notes" maxlength="500">{{ $e->attendance_notes }}</textarea></label></div></div><div class="actions"><button class="small">حفظ الحضور</button></div></form></div><div><h3>بيانات الموظف</h3>@include('erp.employee-form',['employee'=>$e])</div></div></details>@empty<div class="empty">لا يوجد موظفون في النطاق المحدد. أضف أول موظف أدناه.</div>@endforelse
@include('erp.pagination',['paginator'=>$employees])</section><section class="card"><h2>إضافة موظف</h2>@include('erp.employee-form',['employee'=>null])</section>
@endsection
