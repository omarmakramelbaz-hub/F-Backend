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
                <div class="row justify-content-between align-items-center gy-2 mb-2">
                    <div class="col-auto">
                        <h1 class="m-0 text-dark">@if(request('parent')) @lang('main.showAllCitys') @else @lang('main.showAllareas') @endif <small class="countModule">( {{$areas->count()}} )</small></h1>
                        <small>(@lang('main.citys of app'))</small>
                    </div><!-- /.col -->
                    @if(request('parent'))
                    <div class="col-auto">
                        <ol class="breadcrumb float-sm-left">
                            <li class="breadcrumb-item"><a href="{{ route('areas.create' ,['parent'=>request()->parent]) }}"
                                    class="btn btn-primary">@if(request('parent')) @lang('main.addCity') @else @lang('main.addArea') {{$mainParent?__('main.in').$mainParent->title:''}} @endif</a></li>
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
                            @if(request('parent')) @lang('main.citys') @else @lang('main.areas') @endif 
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            @if(request('parent'))
                            {{-- Buttons part --}}
                            @can('areas-delete')
                            <div class="btn-group flex-wrap float-left mb-4">
                                @include('admin.partials.button_group', [
                                    'url' => url('admin/areasDeleteAll'),
                                ])
                            </div>
                            @endcan
                            @endif
                            {{-- search part --}}
                            <div class="float-right mb-4">
                                @include('admin.partials.search_part', [
                                    'route' => route('areas.index'),
                                ])
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <th width="50px"><input type="checkbox" id="master"></th>
                                        <th>#</th>
                                        <th>@lang('main.title_ar')</th>
                                        <th>@lang('main.title_en')</th>
                                        <th>@lang('main.actions')</th>
    
                                    </thead>
                                    <tbody>
                                        @forelse ($areas as $area)
                                            <tr>
                                                <td><input type="checkbox" class="sub_chk" data-id="{{ $area->id }}">
                                                </td>
                                                <td>
                                                    {{ $loop->iteration }}
                                                </td>
                                                <td>
                                                    {{ $area->title_ar }}
                                                </td>
                                                 <td>
                                                    {{ $area->title_en }}
                                                </td>
                                               
                                                <td>
                                                    @can('areas-list')
                                                       @if(!request()->has('parent'))
                                                        <a class="btn btn-info"
                                                            href="{{ route('areas.index', ['parent'=>$area->id]) }}">@lang('main.all_in') {{$area->title_ar}}</a>
                                                       @endif 
                                                    @endcan
                                                    @can('areas-edit')
                                                        <a class="btn btn-warning"
                                                            href="{{ route('areas.edit', $area->id) }}">@lang('main.edit')</a>
                                                    @endcan
                                                    @can('areas-delete')
                                                        {!! \App\Support\NativeDeleteForm::open([
                                                            'method' => 'DELETE',
                                                            'route' => ['areas.destroy', $area->id],
                                                            'style' => 'display:inline',
                                                        ]) !!}
                                                        <button type="submit"
                                                            class="btn btn-danger show_confirm">@lang('main.delete')</button>
                                                        {!! \App\Support\NativeDeleteForm::close() !!}
                                                    @endcan
    
                                                </td>
                                            </tr>
                                        @empty
                                            <td class="text-center text-muted" style="font-size: 25px" colspan="5">
                                                {{ trans('main.Noareas') }}
                                            </td>
                                        @endforelse
                                    </tbody>
                                </table>
                                
                            </div>
                        </div>
                    </div>
                </div>
                {{ $areas->withQueryString()->links() }}
            </div>
        </section>
    </div>
@endsection
