@php
    $workLat = old('work_lat', $pending_vendor?->lat);
    $workLng = old('work_lng', $pending_vendor?->lng);
    $workRadius = old('work_radius_km', $pending_vendor?->work_radius_km);
@endphp
<div class="form-group col-12" id="partner-work-area" data-maps-key="{{ config('partner_work_area.maps_key') }}">
    <label id="partner-work-area-label">منطقة العمل <span class="text-danger">*</span></label>
    <p id="partner-work-area-help" class="text-muted mb-2">اضغط على الخريطة لتحديد موقع الشريك، أو اسحب الدبوس لتغيير مركز منطقة العمل.</p>
    <div id="partner-work-map" tabindex="0" role="region" aria-labelledby="partner-work-area-label" aria-describedby="partner-work-area-help" style="height:340px;width:100%;border:1px solid #ced4da;border-radius:16px;overflow:hidden;"></div>
    <div id="partner-work-map-error" class="alert alert-warning mt-2" role="alert" hidden></div>
    <input type="hidden" name="work_lat" id="partner-work-lat" value="{{ $workLat }}">
    <input type="hidden" name="work_lng" id="partner-work-lng" value="{{ $workLng }}">
    <input type="hidden" name="location" value="{{ old('location', $pending_vendor?->location) }}">
    <div class="mt-3">
        <label for="partner-work-radius">حدود منطقة العمل بالكيلومتر <span class="text-danger">*</span></label>
        <div class="input-group" style="max-width:420px;">
            <input type="text" inputmode="numeric" pattern="[0-9٠-٩۰-۹]{1,3}" maxlength="3" required
                name="work_radius_km" id="partner-work-radius" value="{{ $workRadius }}"
                class="form-control @error('work_radius_km') is-invalid @enderror"
                placeholder="مثال: 10" aria-describedby="partner-work-radius-help" autocomplete="off">
            <span class="input-group-text">كم</span>
        </div>
        <small id="partner-work-radius-help" class="text-muted d-block mt-2">أدخل عددًا صحيحًا من 1 إلى 255 كم. الرقم هو نصف قطر الدائرة من الدبوس، وليس قطرها أو مسافة الطريق.</small>
    </div>
    <p id="partner-work-summary" class="mt-2" aria-live="polite"></p>
    <div id="partner-work-validation" class="text-danger" role="alert" hidden></div>
    @foreach(['work_lat', 'work_lng', 'work_radius_km'] as $workField)
        @error($workField)<div class="text-danger">{{ $message }}</div>@enderror
    @endforeach
    <noscript><div class="alert alert-warning">يجب تفعيل JavaScript لاختيار الدبوس ورؤية دائرة منطقة العمل.</div></noscript>
</div>
@once
    @push('custom-js')
        <script src="{{ asset('dashboard/js/partner-work-area.js') }}?v=20260926-1"></script>
    @endpush
@endonce
