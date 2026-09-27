@extends('admin.index')
@section('content')
<div class="content-wrapper"><section class="content p-4" dir="rtl">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3" style="gap:12px">
        <h1 class="mb-0">متاجر GO</h1>
        @if((int) auth('admin')->id() === 1 || auth('admin')->user()->can('resturant-create'))
            <a href="{{ route('go-stores.create') }}" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i> إضافة متجر</a>
        @endif
    </div>
    <p>سوبر ماركت • مطاعم • صيدليات</p>
    <p>إدارة المتاجر المضافة من الأدمن والمتاجر المقبولة من طلبات انضمام شركاء GO.</p>
    <div class="card"><div class="card-body table-responsive"><table class="table">
        <thead><tr><th>المتجر</th><th>النشاط</th><th>صاحب الحساب</th><th>الحالة</th><th>خدمة التطبيق</th><th></th></tr></thead>
        <tbody>@forelse($stores as $account)
            @php($profile = $profiles->get($account->id))
            <tr><td>{{ $profile->name ?? 'لم تكتمل بيانات المتجر' }}</td>
                <td>{{ \App\Services\GoStores\Catalog::KINDS[$profile->kind ?? ''] ?? '—' }}</td>
                <td>{{ $account->name }}</td><td>{{ __('main.'.$account->status) }}</td><td>{{ $account->delegate_fees ?? 0 }}%</td>
                <td><a class="btn btn-primary" href="{{ route('go-stores.show', $account->id) }}">إدارة المتجر والمنتجات</a></td></tr>
        @empty<tr><td colspan="6">لا توجد حسابات متاجر GO بعد.</td></tr>@endforelse</tbody>
    </table>{{ $stores->links() }}</div></div>
</section></div>
@endsection
