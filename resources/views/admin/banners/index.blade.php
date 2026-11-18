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
                        <h1 class="m-0 text-dark">@lang('main.showAll') @lang('main.banners')
           
            <small class="countModule">( {{$banners->count()}} ) </small>
                        </h1>
                    </div><!-- /.col -->
                    @if(request('type') != 'banner_us' && request('type') != 'ceo')
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-left">
            @can('banner-create')
           <li class="breadcrumb-item"><a href="{{ url('admin/banners/create') }}" class="btn btn-primary">@lang('main.add')  
                       @lang('main.banners')
        </a></li>  
        @endcan    
                        </ol>
                    </div><!-- /.col -->
                    @endif
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
                            @lang('main.banners')
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            {{-- Buttons part --}}
                            @can('banner-delete')
                            <div class="btn-group flex-wrap float-left mb-4">
                                @include('admin.partials.button_group', [
                                    'url' => url('admin/bannersDeleteAll'),
                                ])
                            </div>
                            @endcan
                            {{-- search part --}}
                            <div class="float-right mb-4">
                                @include('admin.partials.search_part', [
                                    'route' => route('banners.index'),
                                ])
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <th width="50px"><input type="checkbox" id="master"></th>
                                        <th>#</th>
                                        <th>@lang('main.title')</th>
                                        <th>@lang('main.status')</th>
                                        <th>@lang('main.created_at')</th>
                                        <th>@lang('main.actions')</th>
    
                                    </thead>
                                    <tbody>
                                        @forelse ($banners as $banner)
                                            <tr>
                                                <td><input type="checkbox" class="sub_chk" data-id="{{ $banner->id }}">
                                                </td>
                                                <td>{{ $loop->iteration }}</td>
                                                <td>
                                                    {!! ($banner->title) ?$banner->title : $banner->description !!}
                                                </td>
                                                <td>
                                                    {{__('main.'.$banner->status)}}
                                                </td>
                                                <td>
                                                    {{$banner->created_at->diffForHumans()}}
                                                </td>
                                                <td width="250px">
                                                    @can('banner-list')
                                                        <a class="btn btn-info"
                                                            href="{{ route('banners.show',[$banner->id]) }}">@lang('main.show')</a>
                                                    @endcan
                                                    @can('banner-edit')
                                                        <a class="btn btn-warning"
                                                            href="{{ route('banners.edit',[$banner->id]) }}">@lang('main.edit')</a>
                                                    @endcan
                                                    @can('banner-delete')
                                                        {!! \App\Support\NativeDeleteForm::open([
                                                            'method' => 'DELETE',
                                                            'route' => ['banners.destroy', $banner->id],
                                                            'style' => 'display:inline',
                                                        ]) !!}
                                                        <button type="submit"
                                                            class="btn btn-danger show_confirm">@lang('main.delete')</button>
                                                        {!! \App\Support\NativeDeleteForm::close() !!}
                                                    @endcan
                                                </td>
                                            </tr>
                                        @empty
                                            <td class="text-center text-muted" style="font-size: 25px" colspan="7">
                                                {{ trans('main.Nobanners') }}
                                            </td>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                {{ $banners->withQueryString()->links() }}
            </div>
        </section>
    </div>
@endsection
