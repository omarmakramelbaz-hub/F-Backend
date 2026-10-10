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
                        <h1 class="m-0 text-dark">@lang('main.ShowAllAdmins') <small class="countModule">( {{$admins->count()}} )</small></h1>
                    </div><!-- /.col -->
                    <div class="col-auto">
                        @can('admin-create')
                            <ol class="breadcrumb float-sm-left">
                                <li class="breadcrumb-item"><a href="{{ route('admins.create') }}"
                                        class="btn btn-primary">@lang('main.AddAdmin')</a></li>
                            </ol>
                        @endcan
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
                            @lang('main.Admins')
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            {{-- Buttons part --}}
                            @can('admin-delete')
                            <div class="btn-group flex-wrap float-left mb-4">
                                @include('admin.partials.button_group', [
                                    'url' => url('admin/adminsDeleteAll'),
                                ])
                            </div>
                            @endcan
                            {{-- search part --}}
                            <div class="float-right mb-4">
                                @include('admin.partials.search_part', ['route' => route('admins.index')])
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <th width="50px"><input type="checkbox" id="master"></th>
                                        <th>#</th>
                                        <th>@lang('main.AdminName')</th>
                                        <th>@lang('main.email')</th>
                                        <th>@lang('main.created_at')</th>
                                        <th>@lang('main.actions')</th>
    
                                    </thead>
                                    <tbody>
                                        @forelse ($admins as $key => $admin)
                                            <tr>
                                                @if($admin->id != 1)
                                                <td><input type="checkbox" class="sub_chk" data-id="{{ $admin->id }}"></td>
                                                @else
                                                <td></td>
                                                @endif
                                                <td>{{ ++$i }}</td>
                                                <td>{{ $admin->name }}</td>
                                                <td>{{ $admin->email }}</td>
                                                <td>{{ $admin->created_at->diffForHumans() }}</td>
                                                <td width="250px">
                                                    @can('admin-list')
                                                        <a class="btn btn-info"
                                                            href="{{ route('admins.show', $admin->id) }}">@lang('main.show')</a>
                                                    @endcan
                                                    @can('admin-edit')
                                                        <a class="btn btn-warning"
                                                            href="{{ route('admins.edit', $admin->id) }}">@lang('main.edit')</a>
                                                    @endcan
                                                    @if ($admin->id != 1)
                                                        @can('admin-delete')
                                                            {!! \App\Support\NativeDeleteForm::open(['method' => 'DELETE', 'route' => ['admins.destroy', $admin->id], 'style' => 'display:inline']) !!}
                                                            <button type="submit"
                                                                class="btn btn-danger show_confirm">@lang('main.delete')</button>
                                                            {!! \App\Support\NativeDeleteForm::close() !!}
                                                        @endcan
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <td class="text-center text-muted" style="font-size: 25px" colspan="5">
                                                {{ trans('main.NoAdmins') }}
                                            </td>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                {{ $admins->withQueryString()->links() }}
            </div>
        </section>
    </div>
@endsection
