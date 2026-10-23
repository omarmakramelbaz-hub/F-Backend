@extends('admin.index')
@section('content')
<div class="content-wrapper"><section class="content-header"><h1>{{ \App\Services\Dashboard\EmployeeRegulation::TITLE }}</h1>
<p class="text-muted mb-0">نسخة {{ \App\Services\Dashboard\EmployeeRegulation::VERSION }}</p></section>
<section class="content" dir="rtl">
<div class="card"><div class="card-body employee-regulation">
@foreach(\App\Services\Dashboard\EmployeeRegulation::sections() as $section)
<h2 class="h5 mt-3">{{ $section['title'] }}</h2>
<ul>
@foreach($section['items'] as $item)
<li>{{ $item }}</li>
@endforeach
</ul>
@endforeach
<p class="mt-4 font-weight-bold">موافقة الموظف بتتسجل من كارته في «متابعة الموظفين»: صورة البطاقة وش وظهر، وعلامة صح على: «{{ \App\Services\Dashboard\EmployeeRegulation::ACCEPTANCE }}»</p></div></div>
</section></div>
@endsection
