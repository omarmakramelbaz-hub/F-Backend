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
                    <div class="col-6">
                        <h1 class="m-0 text-dark">@lang('main.ShowAllRoles') <small class="countModule">( {{$roles->count()}} )</small></h1>
                    </div><!-- /.col -->
                    <div class="col-auto">
                        @can('role-create')
                            <ol class="breadcrumb float-sm-left">
                                <li class="breadcrumb-item"><a href="{{ route('roles.create') }}"
                                        class="btn btn-primary">@lang('main.AddRole')</a></li>
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
                            @lang('main.Roles') <span class="count-sp">( {{$roles->count()}} )</span>
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            {{-- Buttons part --}}
                            @can('role-delete')
                            <div class="btn-group flex-wrap float-left mb-4">
                                @include('admin.partials.button_group', [
                                    'url' => url('admin/rolesDeleteAll'),
                                ])
                            </div>
                            @endcan
                            {{-- search part --}}
                            
                            <div class="float-right mb-4">
                                @include('admin.partials.search_part', ['route' => route('roles.index')])
                            </div>
                            
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <th width="50px"><input type="checkbox" id="master"></th>
                                    <th>#</th>
                                    <th>@lang('main.RoleName')</th>
                                    <th>@lang('main.actions')</th>

                                </thead>
                                <tbody>
                                    @foreach ($roles as $key => $role)
                                        <tr>
                                            @if($role->id == 11 || $role->id == 2 || $role->id == 10 || $role->id == 13)
                                            <td></td>
                                            @else
                                            <td><input type="checkbox" class="sub_chk" data-id="{{ $role->id }}"></td>
                                            @endif
                                            <td>{{ ++$i }}</td>
                                            <td>{{ $role->name }}</td>
                                            <td width="250px">
                                                <a class="btn btn-info"
                                                    href="{{ route('roles.show', $role->id) }}">@lang('main.show')</a>
                                                @can('role-edit')
                                                    <a class="btn btn-warning"
                                                        href="{{ route('roles.edit', $role->id) }}">@lang('main.edit')</a>
                                                @endcan
                                                @if($role->id != 11 && $role->id != 2 && $role->id != 10 || $role->id != 13)
                                                @can('role-delete')
                                                    {!! \App\Support\NativeDeleteForm::open(['method' => 'DELETE', 'route' => ['roles.destroy', $role->id], 'style' => 'display:inline']) !!}
                                                    <button type="submit"
                                                        class="btn btn-danger show_confirm">@lang('main.delete')</button>
                                                    {!! \App\Support\NativeDeleteForm::close() !!}
                                                @endcan
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    {{ $roles->withQueryString()->links() }}
                </div>
            </div>
        </section>
    </div>
@endsection
