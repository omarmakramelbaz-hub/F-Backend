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
                        <h1 class="m-0 text-dark">@lang('main.showAll') @lang('main.products')
            <small class="countModule">( {{$products->count()}} ) </small>
                        </h1>
                        <a href="{{url('/admin/preview-menu')}}" class="mt-2 btn btn-outline-info">@lang('main.general menu') (@lang('main.tree view'))</a>
                    </div><!-- /.col -->
                    @if(request('type') != 'parent')
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-left">
            @can('product-create')
           <li class="breadcrumb-item"><a href="{{ url('admin/products/create') }}" class="btn btn-primary">@lang('main.add') @lang('main.product')  
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
                        {{ __('main.products' ) }}
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            {{-- Buttons part --}}
                            @can('product-delete')
                            <div class="btn-group flex-wrap float-left mb-4">
                                @include('admin.partials.button_group', [
                                    'url' => url('admin/productsDeleteAll'),
                                ])
                            </div>
                            @endcan
                            {{-- search part --}}
                            <div class="float-right mb-4">
                                @include('admin.partials.search_part', [
                                    'route' => route('products.index'),
                                ])
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <th width="50px"><input type="checkbox" id="master"></th>
                                        <th>#</th>
                                        <th>@lang('main.category')</th>
                                        <th>@lang('main.product_name')</th>
                                        <th>@lang('main.product_features')</th>
                                        <th>@lang('main.created_at')</th>
                                        <th>@lang('main.actions')</th>
    
                                    </thead>
                                    <tbody>
                                        @forelse ($products as $product)
                                            <tr>
                                                <td><input type="checkbox" class="sub_chk" data-id="{{ $product->id }}">
                                                </td>
                                                <td>{{ $loop->iteration }}</td>
                                                <td>
                                                    {{ $product->category?->name }}  
                                                    @if($product->subcategory)
                                                    /  {{ $product->subcategory?->name }}
                                                    @endif
                                                </td>
                                                <td>
                                                    {{ $product->name }}
                                                </td>
                                                <td>
                                                    @foreach($product->product_features as $val)
                                                        {{ __('main.'.$val->name) }} ,
                                                    @endforeach
                                                </td>
                                                <td>
                                                    {{$product->created_at->diffForHumans()}}
                                                </td>
                                                <td width="250px">
                                                    @can('product-list')
                                                        <a class="btn btn-info"
                                                            href="{{ route('products.show',[$product->id]) }}">@lang('main.show')</a>
                                                    @endcan
                                                    @can('product-edit')
                                                        <a class="btn btn-warning"
                                                            href="{{ route('products.edit',[$product->id]) }}">@lang('main.edit')</a>
                                                    @endcan
                                                    @can('product-delete')
                                                        {!! \App\Support\NativeDeleteForm::open([
                                                            'method' => 'DELETE',
                                                            'route' => ['products.destroy', $product->id],
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
                                                {{ trans('main.Noproducts') }}
                                            </td>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                {{ $products->withQueryString()->links() }}
            </div>
        </section>
    </div>
@endsection
