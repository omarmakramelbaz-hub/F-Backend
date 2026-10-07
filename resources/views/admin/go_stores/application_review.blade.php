@if($draft)
<div class="col-12 mb-4" dir="rtl">
    <div class="card card-body">
        <h2 class="h4">المتجر والمنتجات المرفقة بطلب الانضمام</h2>
        <div class="d-flex align-items-center mb-3" style="gap:16px">
            <img src="{{ $draft['logo_url'] }}" alt="لوجو المتجر" style="width:88px;height:88px;object-fit:contain">
            <div><strong>{{ $draft['name'] }}</strong><p class="mb-1">{{ \App\Services\GoStores\Catalog::KINDS[$draft['kind']] ?? $draft['kind'] }}</p><p class="mb-0">{{ $draft['address'] }}</p></div>
        </div>
        <p>تنتقل بيانات المتجر ومنتجاته إلى حساب الشريك عند الموافقة، ويستطيع إدارتها بعد تفعيل الحساب.</p>
        <div class="row">
            @foreach($draft['products'] as $product)
            <div class="col-md-4 col-lg-3 mb-3"><div class="border rounded p-3 h-100">
                @if(!empty($product['image_url']))
                    <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" style="width:100%;height:150px;object-fit:contain">
                @else
                    <div class="bg-light rounded d-flex align-items-center justify-content-center text-muted" style="height:150px">صورة المنتج قيد التجهيز</div>
                @endif
                @if(isset($product['image_status']))
                    @if($product['image_status'] === 'ready')
                        <span class="badge badge-success mt-2">صورة بدون براند</span>
                    @elseif($product['image_status'] === 'no_match')
                        <span class="badge badge-warning mt-2">لم نعثر على صورة مناسبة بدون براند</span>
                    @elseif($product['image_status'] === 'failed')
                        <span class="badge badge-danger mt-2">تعذر تجهيز الصورة</span>
                    @elseif(in_array($product['image_status'], ['pending', 'processing']))
                        <span class="badge badge-info mt-2">جارٍ تجهيز الصورة بالذكاء الاصطناعي</span>
                    @endif
                @endif
                <h3 class="h5 mt-2">{{ $product['name'] }}</h3>
                <p>{{ number_format($product['price_cents'] / 100, 2) }} ج / {{ $product['unit'] }}</p>
                @foreach($product['options'] as $option)<div>{{ $option['label'] }} — {{ number_format($option['price_cents'] / 100, 2) }} ج</div>@endforeach
            </div></div>
            @endforeach
        </div>
    </div>
</div>
@endif
