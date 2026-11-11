<header class="f-header site-header motion-header">
    <div class="f-wrap f-header-row">
        <a href="#" class="f-brand" aria-label="فسخانستا الرئيسية"><img src="{{ asset('storefront/motion-logo.webp') }}" alt="" width="54" height="62"><span><strong>فسخانستا</strong><small lang="en" dir="ltr">FASAKHANSTA</small></span></a>
        <nav class="f-nav" aria-label="القائمة الرئيسية"><button type="button" data-scroll="f-stores">الإستورز</button><button type="button" data-scroll="f-products">منتجاتنا</button><button type="button" data-scroll="f-branches">فروعنا</button><button type="button" data-scroll="f-app">التطبيق</button><button type="button" data-scroll="f-contact">تواصل معانا</button></nav>
        <button type="button" class="f-header-action" data-scroll="f-stores">اختار متجرك <span aria-hidden="true"><svg class="motion-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg></span></button>
        <button type="button" class="f-menu-toggle" aria-label="افتح القائمة" aria-expanded="false" aria-controls="motion-mobile-menu" data-toggle-menu><svg class="motion-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
    </div>
    <nav class="f-mobile-menu" id="motion-mobile-menu" aria-label="قائمة الموبايل" data-mobile-menu hidden><button type="button" data-scroll="f-stores">الإستورز</button><button type="button" data-scroll="f-products">منتجاتنا</button><button type="button" data-scroll="f-branches">فروعنا</button><button type="button" data-scroll="f-app">التطبيق</button><button type="button" data-scroll="f-contact">تواصل معانا</button></nav>
    <div class="f-wrap header-actions">
        <div class="header-destinations">
            <label id="saved-customer" class="saved-customer" hidden><span>⌖ عنوان التوصيل</span><select id="saved-address-select" aria-label="اختار عنوان التوصيل المحفوظ"></select></label>
            <button type="button" id="location-shortcut" class="branch-shortcut" data-action="choose-branch"><span aria-hidden="true"><svg class="motion-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2.4"/></svg></span><span id="header-branch">حدد عنوانك</span></button>
            <label class="branch-picker header-store-picker"><span>المتجر المناسب لعنوانك</span><select id="branch-select" aria-label="اختر المتجر أو الفرع" disabled><option value="">حدد عنوانك الأول</option></select></label>
        </div>
        <button type="button" class="cart-trigger" data-action="cart" aria-label="افتح سلة الطلب"><span aria-hidden="true"><svg class="motion-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 7h14l1 14H4L5 7ZM8 7V5a4 4 0 0 1 8 0v2"/></svg></span><span class="cart-label">سلة الطلب</span><b id="cart-count">0</b></button>
    </div>
</header>
