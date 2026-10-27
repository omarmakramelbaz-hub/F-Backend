{{-- Render the real page template while isolating unrelated global header/settings fixtures. --}}
@stack('custom-css')
<main data-workspace-read-test>@yield('content')</main>
@stack('custom-js')
