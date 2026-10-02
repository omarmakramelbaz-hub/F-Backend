@php($fasV3Actor = \App\Services\Erp\Access::actor())
@include('admin.layouts.header')
@if(\Request::route()->getName() != 'chooseType')
@include('admin.layouts.menu')
@endif
@include('admin.layouts.navbar')

@yield('content')

@include('admin.layouts.footer')