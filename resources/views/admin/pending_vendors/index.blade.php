@extends('admin.index')
@push('custom-css')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush

@section('content')
<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2 align-items-center">
                <div class="col-sm-8">
                    <h1 class="m-0 text-dark">
                        طلبات انضمام الشركاء
                        <small class="countModule">( {{ $pending_vendors->total() }} )</small>
                    </h1>
                    <p class="text-muted mb-0 mt-1">كل طلبات الانضمام من FASAKHANSTA و GO في مكان واحد. بعد الموافقة أو الرفض يختفي الطلب من هذه القائمة.</p>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-body">
                    @can('pending_vendor-delete')
                    <div class="btn-group flex-wrap float-left mb-4">
                        @include('admin.partials.button_group', [
                            'url' => url('admin/pending_vendorsDeleteAll'),
                        ])
                    </div>
                    @endcan

                    <div class="float-right mb-4">
                        @include('admin.partials.search_part', [
                            'route' => route('pending_vendors.index'),
                        ])
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle">
                            <thead>
                                <tr>
                                    <th width="50"><input type="checkbox" id="master"></th>
                                    <th>#</th>
                                    <th>التطبيق</th>
                                    <th>الاسم</th>
                                    <th>نوع الطلب</th>
                                    <th>المهنة</th>
                                    <th>نطاق العمل</th>
                                    <th>الحالة</th>
                                    <th>تاريخ الطلب</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($pending_vendors as $pending_vendor)
                                @php
                                    $isPartner = $pending_vendor->application_kind === 'partner';
                                    $profession = $isPartner
                                        ? ($professions[$pending_vendor->profession_key]['ar'] ?? $pending_vendor->profession_key)
                                        : null;
                                @endphp
                                <tr>
                                    <td><input type="checkbox" class="sub_chk" data-id="{{ $pending_vendor->id }}"></td>
                                    <td>{{ $pending_vendor->id }}</td>
                                    <td>
                                        @php $sourceApp = strtoupper($pending_vendor->source_app ?: 'fasakhansta'); @endphp
                                        <span class="badge {{ $sourceApp === 'GO' ? 'bg-dark' : 'bg-warning text-dark' }}">{{ $sourceApp }}</span>
                                    </td>
                                    <td>
                                        <strong>{{ $pending_vendor->full_name }}</strong>
                                        @if($isPartner)
                                            <div class="text-muted small">{{ $pending_vendor->mobile }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isPartner)
                                            <span class="badge bg-primary">{{ $pending_vendor->partner_type === 'vendor' ? 'صاحب مطعم / تاجر' : ($pending_vendor->partner_type === 'delegate' ? 'مندوب' : 'صاحب مهنة') }}</span>
                                        @else
                                            {{ __('main.'.$pending_vendor->type) }}
                                        @endif
                                    </td>
                                    <td>{{ $profession ?: '—' }}</td>
                                    <td>
                                        @if($isPartner && $pending_vendor->work_radius_km)
                                            {{ $pending_vendor->work_radius_km }} كم
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        @if($pending_vendor->status === 'accepted')
                                            <span class="badge bg-success">مقبول</span>
                                        @elseif($pending_vendor->status === 'declined')
                                            <span class="badge bg-danger">مرفوض</span>
                                        @else
                                            <span class="badge bg-warning text-dark">قيد المراجعة</span>
                                        @endif
                                    </td>
                                    <td>{{ $pending_vendor->created_at->diffForHumans() }}</td>
                                    <td style="min-width:260px">
                                        @can('pending_vendor-list')
                                            <a class="btn btn-info btn-sm" href="{{ route('pending_vendors.show',[$pending_vendor->id]) }}">
                                                عرض
                                            </a>
                                        @endcan

                                        @if($isPartner && $pending_vendor->status === 'pending')
                                            <form method="POST"
                                                  action="{{ route('pending_vendors.approvePartner', $pending_vendor) }}"
                                                  style="display:inline">
                                                @csrf
                                                <button type="submit" class="btn btn-success btn-sm">
                                                    قبول الشريك
                                                </button>
                                            </form>
                                        @endif

                                        @can('pending_vendor-delete')
                                            {!! Form::open([
                                                'method' => 'DELETE',
                                                'route' => ['pending_vendors.destroy', $pending_vendor->id],
                                                'style' => 'display:inline',
                                            ]) !!}
                                            <button type="submit" class="btn btn-danger btn-sm show_confirm">حذف</button>
                                            {!! Form::close() !!}
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="text-center text-muted" style="font-size:20px" colspan="10">
                                        لا توجد طلبات حالياً
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{ $pending_vendors->withQueryString()->links() }}
        </div>
    </section>
</div>
@endsection
