{{-- CKEditor (~650 KB) loads only on the pages that have a textarea.js-ckeditor, not with every dashboard page.
     It also runs after the dashboard's in-place navigation, where CKEditor's own auto-replace (on its first load) never ran. --}}
@push('custom-css')
<style>.cke_notification_warning{display:none!important}</style>
@endpush
@push('custom-js')
<script>
(function () {
    function start() {
        document.querySelectorAll('textarea.js-ckeditor').forEach(function (textarea) {
            if (textarea.dataset.ckeditorReady) return;
            textarea.dataset.ckeditorReady = '1';
            window.CKEDITOR.replace(textarea);
        });
    }
    if (window.CKEDITOR && window.CKEDITOR.replace) { start(); return; }
    var loader = document.querySelector('script[data-ckeditor-loader]');
    if (!loader) {
        loader = document.createElement('script');
        loader.src = @json(App\Services\Dashboard\DesktopDashboardAssets::url('//cdn.ckeditor.com/4.14.0/standard/ckeditor.js'));
        loader.setAttribute('data-ckeditor-loader', '');
        document.head.appendChild(loader);
    }
    loader.addEventListener('load', start);
})();
</script>
@endpush
