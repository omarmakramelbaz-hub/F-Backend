@include('admin.layouts.header')
@if(\Request::route()->getName() != 'chooseType')
@include('admin.layouts.menu')
@endif
@include('admin.layouts.navbar')

<div id="dashboard-page" data-dashboard-page style="display:contents">
@yield('content')
</div>

@include('admin.layouts.footer')
