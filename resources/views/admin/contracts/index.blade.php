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
                        <h1 class="m-0 text-dark">@lang('main.showAll') @lang('main.contracts')
            <small class="countModule">( {{$contracts->count()}} ) </small>
                        </h1>
                                                            <small>@lang('main.contract to apply with us')</small>

                    </div><!-- /.col -->
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-left">
            @can('contract-create')
           <li class="breadcrumb-item"><a href="{{ url('admin/contracts/create') }}" class="btn btn-primary">@lang('main.add') @lang('main.contracts')  
        </a></li>  
        @endcan    
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
                        {{ __('main.contracts' ) }}
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            {{-- Buttons part --}}

                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <th>#</th>
                                        <th>@lang('main.type')</th>
                                        <th>@lang('main.created_at')</th>
                                        <th>@lang('main.actions')</th>
    
                                    </thead>
                                    <tbody>
                                        @forelse ($contracts as $contract)
                                            <tr>
                                                </td>
                                                <td>{{ $loop->iteration }}</td>
                                                <td>
                                                    {{ __('main.'.$contract->type) }}
                                                </td>
                                                <td>
                                                    {{$contract->created_at->diffForHumans()}}
                                                </td>
                                                <td width="250px">
                                                    @can('contract-list')
                                                        <a class="btn btn-info"
                                                            href="{{ route('contracts.show',[$contract->id]) }}">@lang('main.show')</a>
                                                    @endcan
                                                    @can('contract-edit')
                                                        <a class="btn btn-warning"
                                                            href="{{ route('contracts.edit',[$contract->id]) }}">@lang('main.edit')</a>
                                                    @endcan
                                                    @can('contract-delete')
                                                        {!! \App\Support\NativeDeleteForm::open([
                                                            'method' => 'DELETE',
                                                            'route' => ['contracts.destroy', $contract->id],
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
                                                {{ trans('main.Nocontracts') }}
                                            </td>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                {{ $contracts->links() }}
            </div>
        </section>
    </div>
@endsection
