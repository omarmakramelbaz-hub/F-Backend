@extends('admin.index')
@push('custom-css')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush
@section('content')
    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 text-dark">@lang('main.showAll') @lang('main.pendingvendors')
           
            <small class="countModule">( {{$pending_vendors->total()}} ) </small>
                        </h1>
                    </div><!-- /.col -->
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-left">
         
                        </ol>
                    </div><!-- /.col -->
                </div><!-- /.row -->
            </div><!-- /.container-fluid -->
        </div>
        <!-- /.content-header -->

        <!-- Main content -->
        <section class="content">
            <div class="container-fluid">
                <div class="">
                    <div class="card">
                        @push('card_title')
                            @lang('main.pendingvendors')  <small class="countModule">( {{$pending_vendors->count()}} ) </small>
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            {{-- Buttons part --}}
                            @can('pending_vendor-delete')
                            <div class="btn-group flex-wrap float-left mb-4">
                                @include('admin.partials.button_group', [
                                    'url' => url('admin/pending_vendorsDeleteAll'),
                                ])
                            </div>
                            @endcan
                            {{-- search part --}}
                            <div class="float-right mb-4">
                                @include('admin.partials.search_part', [
                                    'route' => route('pending_vendors.index'),
                                ])
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <th width="50px"><input type="checkbox" id="master"></th>
                                        <th>#</th>
                                        <th>@lang('main.full_name')</th>
                                        <th>@lang('main.type')</th>
                                        <th>المهنة</th>
                                        <th>نطاق العمل</th>
                                        <th>@lang('main.status')</th>
                                        <th>@lang('main.created_at')</th>
                                        <th>@lang('main.actions')</th>
    
                                    </thead>
                                    <tbody>
                                        @forelse ($pending_vendors as $pending_vendor)
                                            <tr>
                                                <td><input type="checkbox" class="sub_chk" data-id="{{ $pending_vendor->id }}">
                                                </td>
                                                <td>{{ $loop->iteration }}</td>
                                                <td>
                                                    {{$pending_vendor->full_name}}
                                                </td>
                                                <td>
                                                    @if($pending_vendor->application_kind === 'partner')
                                                        <span class="badge bg-primary">GO شريك</span>
                                                    @else
                                                        {{__('main.'.$pending_vendor->type)}}
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($pending_vendor->application_kind === 'partner' && $pending_vendor->profession_key)
                                                        @php
                                                            $profession = \App\Http\Controllers\Api\V1\PartnerApplicationController::professions()[$pending_vendor->profession_key] ?? null;
                                                        @endphp
                                                        {{ $profession['ar'] ?? $pending_vendor->profession_key }}
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($pending_vendor->application_kind === 'partner' && $pending_vendor->work_radius_km)
                                                        {{ $pending_vendor->work_radius_km }} كم
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                                <td style="@if($pending_vendor->status == 'accepted') background:#14ff00; @elseif($pending_vendor->status == 'pending') background:#e8e520;  @else background:#ff0030; @endif">
                                                    {{__('main.Vendor'.$pending_vendor->status)}}
                                                </td>
                                                <td>
                                                    {{$pending_vendor->created_at->diffForHumans()}}
                                                </td>
                                                <td width="250px">
                                                    @can('pending_vendor-list')
                                                        <a class="btn btn-info"
                                                            href="{{ route('pending_vendors.show',[$pending_vendor->id]) }}">@lang('main.show')</a>
                                                    @endcan
                                                    @can('pending_vendor-edit')
                                                       @if($pending_vendor->status=='accepted')
                                                           <a class="btn btn-warning"
                                                            href="{{ route('pending_vendors.edit',[$pending_vendor->id]) }}">@lang('main.edit')</a>
                                                       
                                                       @endif
                                                    @endcan
                                                    @can('pending_vendor-delete')
                                                        {!! Form::open([
                                                            'method' => 'DELETE',
                                                            'route' => ['pending_vendors.destroy', $pending_vendor->id],
                                                            'style' => 'display:inline',
                                                        ]) !!}
                                                        <button type="submit"
                                                            class="btn btn-danger show_confirm">@lang('main.delete')</button>
                                                        {!! Form::close() !!}
                                                    @endcan
                                                </td>
                                            </tr>
                                        @empty
                                            <td class="text-center text-muted" style="font-size: 25px" colspan="9">
                                                {{ trans('main.Nopending_vendors') }}
                                            </td>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                {{ $pending_vendors->withQueryString()->links() }}
            </div>
        </section>
    </div>
@endsection
