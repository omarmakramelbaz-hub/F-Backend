@include('admin.layouts.header')
@if(request()->header('X-Dashboard-SPA') === '1')
{{-- The persistent shell already owns these controls. Keep the authenticated
     header/actor, page content and complete footer; do not rebuild the unused
     menu scopes and notification list for every in-app navigation. --}}
<nav class="main-header" hidden aria-hidden="true"></nav>
@else
@if(\Request::route()->getName() != 'chooseType')
@include('admin.layouts.menu')
@endif
@include('admin.layouts.navbar')
@endif

<div id="dashboard-page" data-dashboard-page style="display:contents">
@yield('content')
</div>

@include('admin.layouts.footer')
