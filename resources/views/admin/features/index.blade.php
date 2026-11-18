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
                        <h1 class="m-0 text-dark">@lang('main.showAll') @lang('main.features')
           
            <small class="countModule">( {{$features->count()}} ) </small>
                        </h1>
                    </div><!-- /.col -->
                    @if(request('type') != 'feature_us' && request('type') != 'ceo')
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-left">
            @can('feature-create')
           <li class="breadcrumb-item"><a href="{{ url('admin/features/create') }}" class="btn btn-primary">@lang('main.add')  
                       @lang('main.feature')
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
                            @lang('main.features')
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            {{-- Buttons part --}}
                            @can('feature-delete')
                            <div class="btn-group flex-wrap float-left mb-4">
                                @include('admin.partials.button_group', [
                                    'url' => url('admin/featuresDeleteAll'),
                                ])
                            </div>
                            @endcan
                            {{-- search part --}}
                            <div class="float-right mb-4">
                                @include('admin.partials.search_part', [
                                    'route' => route('features.index'),
                                ])
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <th width="50px"><input type="checkbox" id="master"></th>
                                        <th>#</th>
                                        <th>@lang('main.title')</th>
                                        <th>@lang('main.featureStatus')</th>
                                        <th>@lang('main.created_at')</th>
                                        <th>@lang('main.actions')</th>
    
                                    </thead>
                                    <tbody>
                                        @forelse ($features as $feature)
                                            <tr>
                                                <td><input type="checkbox" class="sub_chk" data-id="{{ $feature->id }}">
                                                </td>
                                                <td>{{ $loop->iteration }}</td>
                                                <td>
                                                    {!! ($feature->title) ?$feature->title : $feature->description !!}
                                                </td>
                                                <td>
                                                    {{__('main.'.$feature->status)}}
                                                </td>
                                                <td>
                                                    {{$feature->created_at->diffForHumans()}}
                                                </td>
                                                <td width="250px">
                                                    
                                                    @can('feature-edit')
                                                        <a class="btn btn-warning"
                                                            href="{{ route('features.edit',[$feature->id]) }}">@lang('main.edit')</a>
                                                    @endcan
                                                    @can('feature-delete')
                                                        {!! \App\Support\NativeDeleteForm::open([
                                                            'method' => 'DELETE',
                                                            'route' => ['features.destroy', $feature->id],
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
                                                {{ trans('main.Nofeatures') }}
                                            </td>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                {{ $features->links() }}
            </div>
        </section>
    </div>
@endsection
