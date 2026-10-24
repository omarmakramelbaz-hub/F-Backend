@extends('admin.index')
@section('content')
<div class="content-wrapper">
    <section class="content-header"><h1><i class="fas fa-desktop ml-2" aria-hidden="true"></i>برنامج الكمبيوتر</h1></section>
    <section class="content">
        <div class="card"><div class="card-body">
            <h2 class="h5">تحميل وتحديث GO ERP</h2>
            <p>تجهيز نسخة جديدة على السيرفر يبدأ عند الضغط على «تحديث التطبيق». بعد نشرها، يتلقى البرنامج المثبّت النسخة عند الاتصال بالإنترنت ويثبتها بعد انتهاء العمليات والمزامنة.</p>
            @if(!$local && ($manualOwner ?? false))
                <div class="border rounded p-3 mb-3" data-go-erp-release
                    data-status-url="{{ route('desktop-dashboard.release.status') }}"
                    data-request-url="{{ route('desktop-dashboard.release.request') }}">
                    <p class="mb-2">تجهيز نسخة جديدة من تعديلات السيرفر يبدأ بطلبك. بعد اكتمال الفحص والنشر يظهر ملف التنزيل هنا.</p>
                    <button type="button" class="btn btn-primary" data-go-erp-release-request>تحديث التطبيق</button>
                    <p class="text-muted mt-2 mb-0" data-go-erp-release-status role="status" aria-live="polite"></p>
                    <p class="text-danger mt-2 mb-0" data-go-erp-release-error role="alert" hidden></p>
                </div>
            @endif
            <div data-go-erp-release-download>
            @if(!$local && $release)
                <p>نسخة التثبيت للأجهزة الجديدة: <bdi>{{ $release['version'] }}</bdi></p>
                <a class="btn btn-warning" data-spa-off download href="{{ route('desktop-dashboard.download') }}"><i class="fas fa-download ml-1" aria-hidden="true"></i>تحميل GO ERP لجهاز جديد</a>
            @elseif(!$local && ($manualOwner ?? false))
                <p class="text-muted mb-0" role="status">اضغط «تحديث التطبيق» لتجهيز نسخة متوافقة، وسيظهر ملف التنزيل بعد اكتمال الفحص والنشر.</p>
            @elseif(!$local)
                <p class="text-muted mb-0" role="status">ملف التنزيل غير متاح حاليًا. يظهر هنا بعد نشر نسخة متوافقة وفحصها.</p>
            @endif
            </div>
        </div></div>
        <div class="card"><div class="card-body">
            <h2 class="h5">إعداد العمل على الجهاز بدون إنترنت</h2>
            <p>جهّز الجهاز مرة واحدة وأنت متصل بالإنترنت. بعد نجاح التجهيز يفتح البرنامج على نسخة الجهاز تلقائيًا، وتُحفظ العمليات على القرص الصلب للجهاز وتُزامن تلقائيًا كل ٣٠ ثانية عند توفر الاتصال.</p>
            <p>تتحدث بيانات الجهاز من السيرفر تلقائيًا بعد اكتمال مزامنة العمليات، عندما تكون الصفحة مستقرة ولا يوجد تعديل جارٍ.</p>
            <p class="text-muted">أقسام الرسائل وتعليقات السوشيال تحتاج الإنترنت وتتعامل مع السيرفر مباشرةً. إذا كانت نتيجة عملية متصلة غير محسومة، يعرض البرنامج المراجعة المطلوبة قبل العودة للنسخة المحلية.</p>
            <div data-desktop-program>
                <p data-desktop-browser-note class="alert alert-info mb-0">افتح هذا القسم من برنامج GO ERP على الكمبيوتر لتجهيز هذا الجهاز ومراجعة حالته.</p>
                <div data-desktop-controls hidden>
                    <p data-desktop-description role="status" aria-live="polite"></p>
                    <p data-desktop-connection class="text-muted" role="status" aria-live="polite"></p>
                    <p data-desktop-refreshed class="text-muted" role="status" aria-live="polite"></p>
                    <progress data-desktop-progress max="100" style="width:100%" aria-label="نسبة تجهيز الجهاز" hidden></progress>
                    <p data-desktop-error class="text-danger" role="alert"></p>
                    <div class="d-flex flex-wrap" style="gap:8px">
                        <button type="button" class="btn btn-warning" data-desktop-action disabled>تجهيز الجهاز أول مرة</button>
                        <button type="button" class="btn btn-outline-warning" data-desktop-review hidden>مراجعة المزامنة</button>
                    </div>
                    <p data-desktop-update class="text-muted mt-3 mb-0" role="status" aria-live="polite"></p>
                </div>
            </div>
        </div></div>
    </section>
</div>
@if(!$local && ($manualOwner ?? false))
<script src="{{ asset('dashboard/js/go-erp-releases.js') }}?v={{ filemtime(public_path('dashboard/js/go-erp-releases.js')) }}"></script>
@endif
@endsection
