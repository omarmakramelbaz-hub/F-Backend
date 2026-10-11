@php
$waOrderLabels = app()->getLocale() === 'ar' ? [
    'title'=>'مراجعة طلب واتساب', 'intro'=>'راجع بيانات العميل والأصناف والفرع، ثم احسب السعر والتوصيل قبل إرسال الطلب.',
    'analyze'=>'تحليل المحادثة', 'reload'=>'تحديث المراجعة', 'loading'=>'جارٍ التحميل…',
    'unavailable'=>'مراجعة الطلبات غير متاحة الآن.', 'not_configured'=>'تحليل المحادثات يحتاج إعداد خدمة الذكاء الاصطناعي على السيرفر.',
    'denied'=>'تغيّرت صلاحية الحساب. أُغلقت بيانات الطلب.', 'offline'=>'مراجعة الطلب تحتاج اتصالًا بالإنترنت.',
    'empty'=>'لا توجد مسودة طلب لهذه المحادثة. اضغط تحليل المحادثة للبدء.',
    'pending'=>'وصلت رسائل جديدة. حلّل المحادثة من جديد قبل اعتماد الطلب.',
    'pending_auto'=>'وصلت رسائل جديدة. التحليل والإرسال التلقائي مفعّلان؛ ستظهر النتيجة بعد المعالجة.',
    'empty_auto'=>'التحليل والإرسال التلقائي مفعّلان. لا توجد مسودة طلب مؤكّد لهذه المحادثة بعد.',
    'changed'=>'تغيّرت المراجعة أو المحادثة. حدّث البيانات واحسب السعر مرة أخرى.',
    'review'=>'المسودة تحتاج مراجعة وتأكيد البيانات.', 'none'=>'لم يُكتشف طلب في هذه المحادثة.',
    'cancelled'=>'الطلب المقترح ملغي.', 'failed'=>'تعذّر تحليل الطلب. يمكن إعادة التحليل.',
    'ready'=>'السعر جاهز. راجع الإجمالي ثم أرسل للفرع.', 'dispatched'=>'تم إرسال الطلب للفرع.',
    'customer_name'=>'اسم العميل', 'customer_phone'=>'رقم الهاتف', 'address'=>'العنوان', 'area'=>'المنطقة',
    'delivery_notes'=>'ملاحظات التوصيل', 'branch'=>'الفرع المستلم', 'choose_branch'=>'اختر الفرع',
    'latitude'=>'خط العرض', 'longitude'=>'خط الطول', 'location_confirmed'=>'راجعت موقع العميل وتأكدت من الإحداثيات',
    'location_note'=>'اختر عنوان العميل المحفوظ أو اقتراحًا من البحث، ثم راجع الدبوس. تُحسب الخدمة بنفس منطق طلبات الهاتف والدليفري.',
    'lookup_customer'=>'بيانات العميل المحفوظة', 'lookup_empty'=>'لا توجد بيانات محفوظة لهذا الرقم.',
    'saved_addresses'=>'العناوين المحفوظة لهذا العميل', 'location_title'=>'موقع العميل وخدمة التوصيل',
    'address_search'=>'بحث العنوان', 'address_options'=>'اقتراحات العنوان', 'confirm_pin'=>'تأكيد دبوس العميل',
    'pin_required'=>'حدد موقع العميل على الخريطة أو اختر عنوانًا ثم أكد الدبوس.',
    'pin_review'=>'راجع موقع مدخل العميل ثم أكد الدبوس.', 'map_unavailable'=>'الخريطة غير متاحة الآن. أعد المحاولة لاحقًا.',
    'address_empty'=>'لا توجد اقتراحات مطابقة. جرّب الشارع والمنطقة والمدينة أو حدد الدبوس على الخريطة.',
    'route_distance'=>'مسافة الطريق', 'direct_distance'=>'المسافة المباشرة', 'km'=>'كم',
    'branch_policy_missing'=>'اضبط دبوس الفرع وسعر الكيلومتر في إعدادات المطعم أولًا.',
    'proposed'=>'المعلومات المقترحة من المحادثة', 'approximate'=>'قيمة تقريبية مذكورة في المحادثة',
    'proposed_branch'=>'الفرع المذكور', 'items'=>'الأصناف المعتمدة من قائمة الفرع', 'add_item'=>'إضافة صنف',
    'choose_product'=>'اختر صنفًا', 'choose_mode'=>'اختر وحدة الكمية', 'piece'=>'قطعة', 'weight'=>'كيلو',
    'quantity'=>'الكمية', 'option'=>'الخيار', 'base'=>'بدون إضافات', 'remove'=>'حذف',
    'search'=>'بحث في أصناف الفرع', 'search_button'=>'بحث', 'more'=>'أصناف أخرى',
    'quote'=>'حساب السعر والتوصيل', 'dispatch'=>'تأكيد وإرسال للفرع', 'total'=>'إجمالي الطلب',
    'delivery'=>'خدمة التوصيل', 'currency'=>'جنيه', 'invalid'=>'أكمل بيانات العميل والفرع والموقع والأصناف المتاحة أولًا.',
    'quote_expired'=>'تغيّرت البيانات. احسب السعر والتوصيل مرة أخرى.', 'ticket'=>'رقم الطلب',
    'hint'=>'الوصف الوارد في المحادثة', 'unavailable_product'=>'غير متاح',
] : [
    'title'=>'WhatsApp order review', 'intro'=>'Review the customer, products and receiving branch, then calculate the order and delivery before dispatch.',
    'analyze'=>'Analyze conversation', 'reload'=>'Refresh review', 'loading'=>'Loading…',
    'unavailable'=>'Order review is temporarily unavailable.', 'not_configured'=>'Conversation analysis requires an AI service configured on the server.',
    'denied'=>'Your access has changed. Order information was cleared.', 'offline'=>'Order review requires an internet connection.',
    'empty'=>'No order draft for this conversation. Choose Analyze conversation to start.',
    'pending'=>'New messages have arrived. Analyze the conversation again before confirming.',
    'pending_auto'=>'New messages arrived. Automatic analysis and dispatch are enabled; the result appears after processing.',
    'empty_auto'=>'Automatic analysis and dispatch are enabled. No confirmed order draft exists for this conversation yet.',
    'changed'=>'The review or conversation has changed. Refresh and calculate the price again.',
    'review'=>'Review and confirm the draft details.', 'none'=>'No order was found in this conversation.',
    'cancelled'=>'The proposed order is cancelled.', 'failed'=>'Order analysis failed. You can try again.',
    'ready'=>'The quote is ready. Review the total before dispatch.', 'dispatched'=>'The order was sent to the branch.',
    'customer_name'=>'Customer name', 'customer_phone'=>'Phone', 'address'=>'Address', 'area'=>'Area',
    'delivery_notes'=>'Delivery notes', 'branch'=>'Receiving branch', 'choose_branch'=>'Choose a branch',
    'latitude'=>'Latitude', 'longitude'=>'Longitude', 'location_confirmed'=>'I checked the customer location and confirmed these coordinates',
    'location_note'=>'Choose a saved customer address or a search suggestion, then check the pin. Delivery uses the phone order rules.',
    'lookup_customer'=>'Saved customer details', 'lookup_empty'=>'No saved details for this phone.',
    'saved_addresses'=>'Saved customer addresses', 'location_title'=>'Customer location and delivery',
    'address_search'=>'Search address', 'address_options'=>'Address suggestions', 'confirm_pin'=>'Confirm customer pin',
    'pin_required'=>'Choose the customer pin on the map or select an address, then confirm the pin.',
    'pin_review'=>'Check the customer entrance, then confirm the pin.', 'map_unavailable'=>'The map is unavailable. Try again later.',
    'address_empty'=>'No matching suggestions. Try the street, area and city, or select a pin on the map.',
    'route_distance'=>'Road distance', 'direct_distance'=>'Direct distance', 'km'=>'km',
    'branch_policy_missing'=>'Set the branch pin and kilometer rate in the restaurant settings first.',
    'proposed'=>'Suggested conversation details', 'approximate'=>'Approximate amount stated in the conversation',
    'proposed_branch'=>'Mentioned branch', 'items'=>'Approved products from the branch catalog', 'add_item'=>'Add product',
    'choose_product'=>'Choose a product', 'choose_mode'=>'Choose quantity unit', 'piece'=>'Piece', 'weight'=>'Kilogram',
    'quantity'=>'Quantity', 'option'=>'Option', 'base'=>'No extras', 'remove'=>'Remove',
    'search'=>'Search branch products', 'search_button'=>'Search', 'more'=>'More products',
    'quote'=>'Calculate price and delivery', 'dispatch'=>'Confirm and send to branch', 'total'=>'Order total',
    'delivery'=>'Delivery fee', 'currency'=>'EGP', 'invalid'=>'Complete the customer, branch, location and available products first.',
    'quote_expired'=>'Details changed. Calculate the price and delivery again.', 'ticket'=>'Order number',
    'hint'=>'Conversation description', 'unavailable_product'=>'Unavailable',
];
$waOrderConfig = ['meta_url'=>route('whatsapp-orders.meta'), 'catalog_url'=>route('whatsapp-orders.catalog'),
    'conversations_base_url'=>url('/admin/whatsapp/conversations'), 'drafts_base_url'=>url('/admin/whatsapp/orders'),
    'csrf'=>csrf_token(), 'labels'=>$waOrderLabels];
@endphp
<section class="wa-orders" data-wa-orders hidden aria-labelledby="wa-order-review-title">
    <header class="wa-orders-heading"><div><h2 id="wa-order-review-title">{{ $waOrderLabels['title'] }}</h2><p>{{ $waOrderLabels['intro'] }}</p></div><div class="wa-orders-actions"><button type="button" class="wa-inbox-button" data-wa-order-reload>{{ $waOrderLabels['reload'] }}</button><button type="button" class="wa-inbox-button wa-orders-primary" data-wa-order-analyze>{{ $waOrderLabels['analyze'] }}</button></div></header>
    <p class="wa-orders-status" data-wa-order-status role="status" aria-live="polite"></p>
    <label class="wa-orders-draft-picker" data-wa-order-draft-label hidden>{{ $waOrderLabels['title'] }}<select data-wa-order-draft></select></label>
    <div class="wa-orders-proposed" data-wa-order-proposed hidden></div>
    <form data-wa-order-form hidden autocomplete="off">
        <div class="wa-orders-fields">
            <label>{{ $waOrderLabels['customer_name'] }}<input type="text" maxlength="100" data-wa-order-field="customer_name" required></label>
            <label>{{ $waOrderLabels['customer_phone'] }}<div class="wa-orders-phone-lookup"><input type="tel" maxlength="30" dir="ltr" data-wa-order-field="customer_phone" required><button type="button" class="wa-inbox-button" data-wa-order-customer-lookup>{{ $waOrderLabels['lookup_customer'] }}</button></div></label>
            <div class="wa-orders-wide wa-orders-address-list" data-wa-order-customer-results aria-label="{{ $waOrderLabels['saved_addresses'] }}" hidden></div>
            <label class="wa-orders-wide">{{ $waOrderLabels['address'] }}<input type="text" maxlength="500" data-wa-order-field="address" required></label>
            <label>{{ $waOrderLabels['area'] }}<input type="text" maxlength="150" data-wa-order-field="area"></label>
            <label>{{ $waOrderLabels['branch'] }}<select data-wa-order-field="branch" required></select></label>
            <label class="wa-orders-wide">{{ $waOrderLabels['delivery_notes'] }}<input type="text" maxlength="500" data-wa-order-field="delivery_notes"></label>
            <input type="hidden" data-wa-order-field="latitude"><input type="hidden" data-wa-order-field="longitude">
            <input type="checkbox" data-wa-order-field="location_confirmed" hidden aria-hidden="true" tabindex="-1">
        </div>
        <section class="wa-orders-location" aria-label="{{ $waOrderLabels['location_title'] }}">
            <header><h3>{{ $waOrderLabels['location_title'] }}</h3><button type="button" class="wa-inbox-button" data-wa-order-address-search>{{ $waOrderLabels['address_search'] }}</button></header>
            <div class="wa-orders-address-list" data-wa-order-address-results aria-label="{{ $waOrderLabels['address_options'] }}" hidden></div>
            <p class="wa-orders-help" data-wa-order-location-status role="status" aria-live="polite">{{ $waOrderLabels['location_note'] }}</p>
            <div class="wa-orders-map" data-wa-order-map></div>
            <p class="wa-orders-map-attribution">@if(config('services.maps.phone_open_enabled', false))OpenStreetMap · Photon · OSRM / FOSSGIS @else Google Maps · OpenStreetMap @endif</p>
            <button type="button" class="wa-inbox-button wa-orders-primary" data-wa-order-confirm-pin>{{ $waOrderLabels['confirm_pin'] }}</button>
            <p class="wa-orders-distance" data-wa-order-distance role="status" aria-live="polite"></p>
        </section>
        <div class="wa-orders-catalog-tools"><h3>{{ $waOrderLabels['items'] }}</h3><label><span class="sr-only">{{ $waOrderLabels['search'] }}</span><input type="search" maxlength="100" data-wa-order-search placeholder="{{ $waOrderLabels['search'] }}"></label><button type="button" class="wa-inbox-button" data-wa-order-search-button>{{ $waOrderLabels['search_button'] }}</button><button type="button" class="wa-inbox-button" data-wa-order-more hidden>{{ $waOrderLabels['more'] }}</button></div>
        <div class="wa-orders-item-list" data-wa-order-items></div>
        <button type="button" class="wa-inbox-button" data-wa-order-add>{{ $waOrderLabels['add_item'] }}</button>
        <div class="wa-orders-quote" data-wa-order-quote hidden></div>
        <footer class="wa-orders-footer"><button type="button" class="wa-inbox-button" data-wa-order-calculate>{{ $waOrderLabels['quote'] }}</button><button type="button" class="wa-inbox-button wa-orders-primary" data-wa-order-dispatch disabled>{{ $waOrderLabels['dispatch'] }}</button></footer>
    </form>
</section>
<script type="application/json" id="whatsapp-orders-bootstrap">@json($waOrderConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
@push('custom-js')
@include('admin.maps.assets')
@endpush
