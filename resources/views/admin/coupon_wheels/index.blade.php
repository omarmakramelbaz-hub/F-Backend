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
                        <h1 class="m-0 text-dark">@lang('main.ShowAllcoupon_wheels') <small class="countModule">( {{$dash_coupon_wheels->total()}} )</small></h1>
                    </div><!-- /.col -->
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-left">
                            <li class="breadcrumb-item"><a href="{{ route('coupon_wheels.create') }}"
                                    class="btn btn-primary">@lang('main.Addcoupon_wheel')</a></li>
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
                            @lang('main.coupon_wheels')  <span class="count-sp">( {{$dash_coupon_wheels->count()}} )</span>
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            {{-- Buttons part --}}
                            @can('coupon_wheel-delete')
                            <div class="btn-group flex-wrap float-left mb-4">
                                @include('admin.partials.button_group', [
                                    'url' => url('admin/coupon_wheelsDeleteAll'),
                                ])
                            </div>
                            @endcan
                            {{-- search part --}}
                            <div class="float-right mb-4">
                                @include('admin.partials.search_part', [
                                    'route' => route('coupon_wheels.index'),
                                ])
                            </div>

                            <table class="table table-bordered table-hover">
                                <thead>
                                    <th width="50px"><input type="checkbox" id="master"></th>
                                    <th>#</th>
                                    <th>@lang('main.coupon_wheelImage')</th>
                                    <th>@lang('main.title')</th>
                                    <th>@lang('main.created_at')</th>
                                    <th>@lang('main.actions')</th>
                                </thead>
                                <tbody>
                                    @forelse ($dash_coupon_wheels as $coupon_wheel)
                                        <tr>
                                            <td><input type="checkbox" class="sub_chk" data-id="{{ $coupon_wheel->id }}">
                                            </td>
                                            <td>{{ $loop->iteration }}</td>
                                            <td>@if ($coupon_wheel->getFirstMediaUrl('coupon_wheel_image','thumb'))
                                                    <img src="{{ $coupon_wheel->getFirstMediaUrl('coupon_wheel_image','thumb') }}" width="100px">
                                                    @include('admin.components.modal_photo', [
                                                        'image' => $coupon_wheel->getFirstMediaUrl('coupon_wheel_image','thumb'),
                                                        'id' => '1'.$coupon_wheel->id,
                                                    ])
                                                @else
                                                    <span> @lang('main.NoOfferImage')</span>
                                                @endif
                                            </td>
                                                        
                                            <td>
                                                {{ $coupon_wheel->name }}
                                            </td>
                                         
                                            <td>
                                                {{ $coupon_wheel->created_at->diffForHumans() }}
                                            </td>
                                            <td width="250px">
                                                @can('coupon_wheel-list')
                                                    <a class="btn btn-info"
                                                        href="{{ route('coupon_wheels.show', $coupon_wheel->id) }}">@lang('main.show')</a>
                                                @endcan
                                                @can('coupon_wheel-edit')
                                                    <a class="btn btn-warning"
                                                        href="{{ route('coupon_wheels.edit', $coupon_wheel->id) }}">@lang('main.edit')</a>
                                                @endcan
                                                @can('coupon_wheel-delete')
                                                    {!! \App\Support\NativeDeleteForm::open([
                                                        'method' => 'DELETE',
                                                        'route' => ['coupon_wheels.destroy', $coupon_wheel->id],
                                                        'style' => 'display:inline',
                                                    ]) !!}
                                                    <button type="submit"
                                                        class="btn btn-danger show_confirm">@lang('main.delete')</button>
                                                    {!! \App\Support\NativeDeleteForm::close() !!}
                                                @endcan

                                            </td>
                                        </tr>
                                    @empty
                                        <td class="text-center text-muted" style="font-size: 25px" colspan="6">
                                            {{ trans('main.Nocoupon_wheels') }}
                                        </td>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                {{ $dash_coupon_wheels->withQueryString()->links() }}
            </div>
        </section>
    </div>
@endsection
