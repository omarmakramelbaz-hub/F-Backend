@extends('admin.index')
@section('content')
<div class="content-wrapper"><section class="content p-4" dir="rtl">
    <a href="{{ route('go-stores.index') }}">متاجر GO</a>
    @if(!empty($store['logo_url']))<img src="{{ $store['logo_url'] }}" alt="لوجو المتجر" style="width:96px;height:96px;object-fit:contain">@endif
    <h1>{{ $store['name'] ?? 'بيانات المتجر' }}</h1><p>صاحب الحساب: {{ $account->name }} — رقم الدخول: <bdi>{{ $account->mobile }}</bdi></p>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form class="card card-body" method="POST" action="{{ route('go-stores.update', $account->id) }}">
        @csrf
        <input type="hidden" name="revision" value="{{ old('revision', $store['revision'] ?? 0) }}">
        <div class="row">
            <div class="col-md-6 form-group"><label for="store-name">اسم المتجر</label><input id="store-name" class="form-control" name="name" required maxlength="150" value="{{ old('name', $store['name'] ?? '') }}"></div>
            <div class="col-md-6 form-group"><label for="store-kind">نوع النشاط</label><select id="store-kind" class="form-control" name="kind" required>
                <option value="">اختر النشاط</option>@foreach(\App\Services\GoStores\Catalog::KINDS as $key => $label)<option value="{{ $key }}" {{ old('kind', $store['kind'] ?? '') === $key ? 'selected' : '' }}>{{ $label }}</option>@endforeach
            </select></div>
            <div class="col-md-8 form-group"><label for="store-address">العنوان بالتفصيل</label><input id="store-address" class="form-control" name="address" required maxlength="500" value="{{ old('address', $store['address'] ?? '') }}"></div>
            <div class="col-md-4 form-group"><label for="store-commission">نسبة خدمة التطبيق لحساب المتجر %</label><input id="store-commission" class="form-control" name="commission_rate" type="number" min="0" max="100" step="0.01" required value="{{ old('commission_rate', $account->delegate_fees ?? 0) }}"></div>
        </div><button class="btn btn-primary" type="submit">حفظ بيانات المتجر</button>
    </form>
    <div class="d-flex justify-content-between align-items-center my-4"><h2>المنتجات</h2>
        @if($store)<a class="btn btn-success" href="{{ route('go-stores.products.create', $account->id) }}">إضافة منتج</a>@endif</div>
    <div class="row">@forelse($products as $product)
        <div class="col-md-4 col-lg-3"><div class="card h-100"><img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" style="height:180px;object-fit:contain;background:#f6f7f9">
            <div class="card-body"><h3 class="h5">{{ $product['name'] }}</h3><p>{{ $product['price'] }} ج / {{ $product['unit'] }}</p>
                <p>{{ $product['available'] ? 'متوفر' : 'غير متوفر حاليًا' }}</p>
                @foreach($product['options'] as $option)<div>{{ $option['label'] }} — {{ $option['price'] }} ج</div>@endforeach
                <a class="btn btn-outline-primary mt-3" href="{{ route('go-stores.products.edit', [$account->id, $product['id']]) }}">تعديل المنتج</a>
            </div></div></div>
    @empty<div class="col-12"><p>أضف أول منتج بعد حفظ بيانات المتجر.</p></div>@endforelse</div>
    <div class="mt-4">{{ $products->links() }}</div>
</section></div>
@endsection
