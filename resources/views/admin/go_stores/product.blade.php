@extends('admin.index')
@section('content')
<div class="content-wrapper"><section class="content p-4" dir="rtl">
    <a href="{{ route('go-stores.show', $account->id) }}">العودة للمتجر</a><h1>{{ $item ? 'تعديل المنتج' : 'إضافة منتج' }}</h1>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form class="card card-body" method="POST" enctype="multipart/form-data" action="{{ $item ? route('go-stores.products.update', [$account->id, $item['id']]) : route('go-stores.products.store', $account->id) }}">
        @csrf
        <input type="hidden" name="revision" value="{{ old('revision', $item['revision'] ?? '') }}">
        <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
        <div class="form-group"><label for="product-name">اسم المنتج</label><input id="product-name" class="form-control" name="name" maxlength="150" required value="{{ old('name', $item['name'] ?? '') }}"></div>
        <div class="form-group"><label for="product-description">الوصف</label><textarea id="product-description" class="form-control" name="description" maxlength="2000">{{ old('description', $item['description'] ?? '') }}</textarea></div>
        <div class="row"><div class="col-md-6 form-group"><label for="product-unit">الوحدة الأساسية (كيلو / عبوة / قطعة)</label><input id="product-unit" class="form-control" name="unit" maxlength="40" required value="{{ old('unit', $item['unit'] ?? 'قطعة') }}"></div>
            <div class="col-md-6 form-group"><label for="product-price">سعر الوحدة بالجنيه</label><input id="product-price" class="form-control" name="price" type="number" step="0.01" min="0.01" max="1000000" required value="{{ old('price', $item['price'] ?? '') }}"></div></div>
        @if($item)<img src="{{ $item['image_url'] }}" alt="الصورة الحالية" style="width:140px;height:140px;object-fit:contain">@endif
        <div class="form-group"><label for="product-image">صورة المنتج (JPG / PNG / WEBP، بحد أقصى 5 ميجا)</label><input id="product-image" class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp" {{ $item ? '' : 'required' }}></div>
        <input type="hidden" name="available" value="0"><label><input type="checkbox" name="available" value="1" {{ old('available', $item['available'] ?? true) ? 'checked' : '' }}> المنتج متوفر</label>
        <h2 class="h4 mt-4">خيارات المنتج</h2><p>السعر الكامل للاختيار، مثل نصف كيلو أو ربع كيلو. اتركها فارغة للبيع بالوحدة الأساسية فقط.</p>
        <div id="options"></div><button class="btn btn-outline-secondary mb-3" id="add-option" type="button">إضافة اختيار</button>
        <input type="hidden" name="options" id="options-json" value="[]">
        <button class="btn btn-primary" type="submit">حفظ المنتج</button>
    </form>
</section></div>
<script>
(() => {
    const holder = document.getElementById('options');
    const hidden = document.getElementById('options-json');
    const initial = @json(old('options', $item['options'] ?? []));
    function add(option = {}) {
        if (holder.children.length >= 20) return;
        const row = document.createElement('div'); row.className = 'd-flex mb-2'; row.dataset.id = option.id || '';
        const label = document.createElement('input'); label.className = 'form-control'; label.placeholder = 'الاختيار: نصف كيلو'; label.setAttribute('aria-label', 'اسم الاختيار'); label.maxLength = 60; label.required = true; label.value = option.label || '';
        const price = document.createElement('input'); price.className = 'form-control mx-2'; price.type = 'number'; price.step = '0.01'; price.min = '0.01'; price.max = '1000000'; price.required = true; price.placeholder = 'السعر بالجنيه'; price.setAttribute('aria-label', 'سعر الاختيار'); price.value = option.price || '';
        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-outline-danger'; remove.textContent = 'حذف الاختيار'; remove.onclick = () => row.remove();
        row.append(label, price, remove); holder.append(row);
    }
    (typeof initial === 'string' ? JSON.parse(initial || '[]') : initial).forEach(add);
    document.getElementById('add-option').onclick = () => add();
    hidden.form.addEventListener('submit', () => {
        hidden.value = JSON.stringify(Array.from(holder.children).map(row => ({ ...(row.dataset.id ? {id: row.dataset.id} : {}), label: row.children[0].value, price: row.children[1].value })));
    });
})();
</script>
@endsection
