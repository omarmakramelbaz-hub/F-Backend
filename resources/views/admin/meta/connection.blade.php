@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('css/dashboard-meta-connection.css') }}?v={{ filemtime(public_path('css/dashboard-meta-connection.css')) }}">
@endpush
@section('content')
@php
$ar = app()->getLocale() === 'ar';
$labels = $ar ? [
    'title'=>'ربط حساب ميتا', 'subtitle'=>'اربط صفحة فسخانستا وحساب إنستجرام لاستقبال الرسائل والرد عليها من الداشبورد.',
    'back'=>'العودة إلى الرسائل', 'owner'=>'إدارة الاتصال للمالك فقط', 'start'=>'الربط باستخدام فيسبوك',
    'start_note'=>'سجّل الدخول بحساب فيسبوك الذي يدير صفحة فسخانستا، ثم اختر الصفحة وحساب إنستجرام المطلوبين.',
    'callback'=>'رابط الرجوع للداشبورد', 'callback_note'=>'أضف هذا الرابط كما هو في إعدادات Facebook Login for Business لدى ميتا.',
    'not_ready'=>'إعداد الربط لم يكتمل بعد. يجب استكمال إعدادات اتصال ميتا على السيرفر أولًا.',
    'saved_accounts'=>'الحسابات المحفوظة', 'none_saved'=>'لم تُحفظ حسابات عبر تسجيل الدخول بعد.',
    'credentials_saved'=>'بيانات الاتصال محفوظة', 'review_title'=>'راجع الحسابات قبل حفظ الاتصال',
    'review_note'=>'اختر الحسابات التي تريد تفعيلها. لا يتم حفظ الاتصال حتى تضغط على تأكيد وحفظ. تنتهي هذه المراجعة بعد 10 دقائق.',
    'confirm'=>'تأكيد وحفظ الاتصال', 'channel'=>'القناة', 'id'=>'رقم الحساب',
] : [
    'title'=>'Connect Meta', 'subtitle'=>'Connect the Fasakhansta Page and Instagram account to receive and reply to messages in your dashboard.',
    'back'=>'Back to inbox', 'owner'=>'Connection management is restricted to the Owner', 'start'=>'Connect using Facebook',
    'start_note'=>'Sign in with the Facebook account that manages the Fasakhansta Page, then choose the Page and Instagram account.',
    'callback'=>'Dashboard callback URL', 'callback_note'=>'Add this exact URL to your Facebook Login for Business settings in Meta.',
    'not_ready'=>'Connection setup is incomplete. Complete the Meta connection settings on the server first.',
    'saved_accounts'=>'Saved accounts', 'none_saved'=>'No accounts have been saved through Facebook Login yet.',
    'credentials_saved'=>'Connection credentials saved', 'review_title'=>'Review accounts before saving',
    'review_note'=>'Choose the accounts to activate. Nothing is saved until you confirm. This review expires after 10 minutes.',
    'confirm'=>'Confirm and save connection', 'channel'=>'Channel', 'id'=>'Account ID',
];
$notices = $ar ? [
    'failed'=>'تعذّر إكمال اتصال ميتا. حاول الربط مرة أخرى.',
    'expired'=>'انتهت صلاحية محاولة الربط أو لم تتطابق مع حسابك. ابدأ الربط مرة أخرى.',
    'cancelled'=>'تم إلغاء تسجيل الدخول. يمكنك بدء الربط مرة أخرى.',
    'no_accounts'=>'لم تظهر حسابات مسموح بها للربط. راجع اختيار صفحة فسخانستا والصلاحيات، ثم حاول مرة أخرى.',
    'review'=>'تم تسجيل الدخول. راجع الحسابات أدناه ثم أكّد حفظ الاتصال.',
    'selection_required'=>'يجب اختيار حساب واحد على الأقل. ابدأ الربط مرة أخرى لاختيار الحسابات.',
    'saved'=>'تم التحقق من الحسابات وحفظ الاتصال بنجاح.',
] : [
    'failed'=>'Unable to complete the Meta connection. Please connect again.',
    'expired'=>'The connection attempt expired or did not match your account. Start again.',
    'cancelled'=>'Facebook Login was cancelled. You can start again.',
    'no_accounts'=>'No permitted accounts were found. Check your Page selection and permissions, then reconnect.',
    'review'=>'Signed in. Review the accounts below and confirm to save the connection.',
    'selection_required'=>'Select at least one account. Start the connection again to make your selection.',
    'saved'=>'Accounts verified and the connection saved successfully.',
];
@endphp
<div class="content-wrapper meta-connection-wrapper">
<main class="meta-connection" dir="{{ $ar ? 'rtl' : 'ltr' }}" aria-labelledby="meta-connection-title">
    <header class="meta-connection-heading">
        <div><h1 id="meta-connection-title"><i class="fas fa-link" aria-hidden="true"></i> {{ $labels['title'] }}</h1><p>{{ $labels['subtitle'] }}</p></div>
        <a href="{{ $metaConnection['inbox_url'] }}" class="meta-connection-secondary">{{ $labels['back'] }}</a>
    </header>
    <p class="meta-connection-owner"><i class="fas fa-lock" aria-hidden="true"></i> {{ $labels['owner'] }}</p>
    @if(isset($notices[$metaConnection['notice'] ?? '']))
    <div class="meta-connection-notice {{ $metaConnection['notice'] === 'saved' ? 'is-success' : '' }}" role="status" aria-live="polite">{{ $notices[$metaConnection['notice']] }}</div>
    @endif
    <section class="meta-connection-card" aria-labelledby="meta-connection-login-title">
        <h2 id="meta-connection-login-title">{{ $labels['start'] }}</h2>
        <p>{{ $labels['start_note'] }}</p>
        @if($metaConnection['configured'])
        <form action="{{ $metaConnection['start_url'] }}" method="POST">@csrf
            <button class="meta-connection-primary" type="submit"><i class="fab fa-facebook" aria-hidden="true"></i> {{ $labels['start'] }}</button>
        </form>
        <div class="meta-connection-callback"><h3>{{ $labels['callback'] }}</h3><input type="text" readonly value="{{ $metaConnection['callback_url'] }}" dir="ltr" aria-label="{{ $labels['callback'] }}"><p>{{ $labels['callback_note'] }}</p></div>
        @else
        <div class="meta-connection-notice" role="status">{{ $labels['not_ready'] }}</div>
        @endif
    </section>
    @if($metaConnection['has_pending'])
    <section class="meta-connection-card meta-connection-review" aria-labelledby="meta-connection-review-title">
        <h2 id="meta-connection-review-title">{{ $labels['review_title'] }}</h2><p>{{ $labels['review_note'] }}</p>
        <form action="{{ $metaConnection['confirm_url'] }}" method="POST">@csrf
            <div class="meta-connection-accounts">
            @foreach($metaConnection['pending_accounts'] as $account)
                <label class="meta-connection-account"><input type="checkbox" name="accounts[]" value="{{ $account['key'] }}" checked><span class="meta-connection-channel"><i class="{{ $account['channel'] === 'instagram' ? 'fab fa-instagram' : 'fab fa-facebook-messenger' }}" aria-hidden="true"></i> {{ $account['channel'] === 'instagram' ? 'Instagram' : 'Messenger' }}</span><span class="meta-connection-account-name">{{ $account['label'] }}</span><span class="meta-connection-account-id">{{ $labels['id'] }}: <bdi>{{ $account['account_id'] }}</bdi></span></label>
            @endforeach
            </div>
            <button class="meta-connection-primary" type="submit"><i class="fas fa-check" aria-hidden="true"></i> {{ $labels['confirm'] }}</button>
        </form>
    </section>
    @endif
    <section class="meta-connection-card" aria-labelledby="meta-connection-saved-title">
        <h2 id="meta-connection-saved-title">{{ $labels['saved_accounts'] }}</h2>
        @if($metaConnection['credentials_available'] && count($metaConnection['accounts']))
        <p class="meta-connection-saved-status"><i class="fas fa-check-circle" aria-hidden="true"></i> {{ $labels['credentials_saved'] }}</p>
        <ul class="meta-connection-saved-list">
            @foreach($metaConnection['accounts'] as $account)
            <li><span class="meta-connection-channel">{{ $account['channel'] === 'instagram' ? 'Instagram' : 'Messenger' }}</span><strong>{{ $account['label'] }}</strong><span>{{ $labels['id'] }}: <bdi>{{ $account['account_id'] }}</bdi></span></li>
            @endforeach
        </ul>
        @else
        <p>{{ $labels['none_saved'] }}</p>
        @endif
    </section>
</main>
</div>
@endsection
