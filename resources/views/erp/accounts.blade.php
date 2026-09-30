@extends('erp.layout')
@section('title','الحسابات والصلاحيات')
@section('subtitle','حسابات مستقلة للفريق ونائب المدير مع نطاق واضح لكل دور')
@section('content')
<div class="notice">نائب المدير يدير جميع الفروع والمخزون والموظفين وفق الصلاحيات المختارة. إنشاء الحسابات وتعديل صلاحياتها متاح للمالك فقط. تسجيل دخول الفريق من <a href="{{ route('erp.login') }}">بوابة ERP</a>.</div>
<section class="card"><h2>إضافة حساب للفريق</h2>@include('erp.account-form',['account'=>null])</section>
<section class="card"><h2>الحسابات الحالية</h2>@forelse($accounts as $account)<details class="details"><summary><strong>{{ $account->name }}</strong> <span dir="ltr">{{ $account->email }}</span> <span class="badge {{ $account->active ? 'green':'red' }}">{{ $account->active ? 'نشط':'موقوف' }}</span></summary><div class="details-body">@include('erp.account-form',['account'=>$account])</div></details>@empty<div class="empty">أنشئ حساب نائب المدير وحدد بيانات دخوله من النموذج أعلاه.</div>@endforelse</section>
@endsection
