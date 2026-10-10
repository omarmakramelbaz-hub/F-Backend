@extends('admin.index')
@push('custom-css')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" type="text/css" href="{{ App\Services\Dashboard\DesktopDashboardAssets::url('https://cdn.datatables.net/v/dt/dt-1.10.12/datatables.min.css') }}"/>
    <link rel="stylesheet" type="text/css" href="{{ App\Services\Dashboard\DesktopDashboardAssets::url('https://stackpath.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css') }}"/>

@endpush
@section('content')
    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 text-dark">@lang('main.showAll') @lang('main.categorys')
            <small class="countModule">( {{$categorys->total()}} ) </small>
                        </h1>
                    </div><!-- /.col -->
                    @if(request('parent'))
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-left">
                            @can('category-create')
                                 <li class="breadcrumb-item">
                                       <a href="{{ url('admin/categorys/create?parent='.request('parent')) }}" class="btn btn-primary">@lang('main.add') {{__('main.'.request('parent'))}}   </a>
                                 </li>  
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
                        {{ __('main.categorys' ) }} <small class="countModule">( {{$categorys->count()}} ) </small>
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            {{-- Buttons part --}}
                            @can('category-delete')
                            <div class="btn-group flex-wrap float-left mb-4">
                                @include('admin.partials.button_group', [
                                    'url' => url('admin/categorysDeleteAll'),
                                ])
                            </div>
                            @endcan
                            {{-- search part --}}
                            <div class="float-right mb-4">
                                @include('admin.partials.search_part', [
                                    'route' => route('categorys.index',['parent' => request('parent')]),
                                ])
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <th width="50px"><input type="checkbox" id="master"></th>
                                        <th>#</th>
                                        <th>@lang('main.category')</th>
                                        <th>@lang('main.subcategory')</th>
                                        <th>@lang('main.created_at')</th>
                                        <th>@lang('main.actions')</th>
    
                                    </thead>
                                   <tbody id="tablecontents" data-desktop-category-generation="{{ app(\App\Services\Dashboard\DesktopDashboardNotificationReads::class)->generation(auth('admin')->user()) }}">
                                        @forelse ($categorys as $category)
                                            <tr class="row1" data-id="{{ $category->id }}">
                                                <td><input type="checkbox" class="sub_chk" data-id="{{ $category->id }}">
                                                </td>
                                                <td>{{ $loop->iteration }}</td>
                                                <td>
                                                    {{ $category->name }}
                                                </td>
                                                <td>@if($category->parent) {{$category->parent?->name}} @else - @endif </td>
                                                <td>
                                                    {{$category->created_at->diffForHumans()}}
                                                </td>
                                                <td width="250px">
                                                    @if($category->parent_id == null)
                                                    @can('category-list')
                                                        <a class="btn btn-info"
                                                            href="{{ route('categorys.show',[$category->id,'parent' => 'parent']) }}">@lang('main.show')</a>
                                                    @endcan
                                                    @can('category-edit')
                                                        <a class="btn btn-warning"
                                                            href="{{ route('categorys.edit',[$category->id,'parent' => 'parent']) }}">@lang('main.edit')</a>
                                                    @endcan
                                                    @can('category-delete')
                                                        {!! \App\Support\NativeDeleteForm::open([
                                                            'method' => 'DELETE',
                                                            'route' => ['categorys.destroy', $category->id,'parent' => 'parent'],
                                                            'style' => 'display:inline',
                                                        ]) !!}
                                                        <button type="submit"
                                                            class="btn btn-danger show_confirm">@lang('main.delete')</button>
                                                        {!! \App\Support\NativeDeleteForm::close() !!}
                                                    @endcan   
                                                    @else
                                                    @can('category-list')
                                                        <a class="btn btn-info"
                                                            href="{{ route('categorys.show',[$category->id,'parent' => 'sub']) }}">@lang('main.show')</a>
                                                    @endcan
                                                    @can('category-edit')
                                                        <a class="btn btn-warning"
                                                            href="{{ route('categorys.edit',[$category->id,'parent' => 'sub']) }}">@lang('main.edit')</a>
                                                    @endcan
                                                    @can('category-delete')
                                                        {!! \App\Support\NativeDeleteForm::open([
                                                            'method' => 'DELETE',
                                                            'route' => ['categorys.destroy', $category->id,'parent' => request('parent')],
                                                            'style' => 'display:inline',
                                                        ]) !!}
                                                        <button type="submit"
                                                            class="btn btn-danger show_confirm">@lang('main.delete')</button>
                                                        {!! \App\Support\NativeDeleteForm::close() !!}
                                                    @endcan  
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <td class="text-center text-muted" style="font-size: 25px" colspan="7">
                                                {{ trans('main.Nocategorys') }}
                                            </td>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                {{ $categorys->withQueryString()->links() }}
            </div>
        </section>
    </div>
@endsection
@push('custom-js')
    <script type="text/javascript" src="{{ App\Services\Dashboard\DesktopDashboardAssets::url('https://cdn.datatables.net/v/dt/dt-1.10.12/datatables.min.js') }}"></script>
    <script type="text/javascript">
      $(function () {
        $("#table").DataTable();

        $( "#tablecontents" ).sortable({
          items: "tr",
          cursor: 'move',
          opacity: 0.6,
          update: function() {
              sendOrderToServer();
          }
        });

        function sendOrderToServer() {
          var order = [];
          var token = $('meta[name="csrf-token"]').attr('content');
          $('tr.row1').each(function(index,element) {
            order.push({
              id: $(this).attr('data-id'),
              position: index+1
            });
          });

          $.ajax({
            type: "POST", 
            dataType: "json", 
            url: "{{ url('admin/post-sortable') }}",
                data: {
              order: order,
              _token: token
            },
            success: function(response) {
                if (response.status == "success") {
                  console.log(response);
                } else {
                  console.log(response);
                }
            }
          });
        }
      });
    </script>

@endpush
