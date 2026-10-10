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
                        <h1 class="m-0 text-dark">@lang('main.showAll') @lang('main.advertisings') <small class="countModule">( {{$dash_advertisings->total()}} )</small></h1>
                    </div><!-- /.col -->
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-left">
                            <li class="breadcrumb-item"><a href="{{ route('advertisings.create') }}"
                                    class="btn btn-primary">@lang('main.add') @lang('main.advertising')</a></li>
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
                            @lang('main.advertisings')  <span class="count-sp">( {{$dash_advertisings->count()}} )</span>
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            {{-- Buttons part --}}
                            @can('slidear-delete')
                            <div class="btn-group flex-wrap float-left mb-4">
                                @include('admin.partials.button_group', [
                                    'url' => url('admin/advertisingsDeleteAll'),
                                ])
                            </div>
                            @endcan
                            {{-- search part --}}
                            <div class="float-right mb-4">
                                @include('admin.partials.search_part', [
                                    'route' => route('advertisings.index'),
                                ])
                            </div>

                            <table class="table table-bordered table-hover">
                                <thead>
                                    <th width="50px"><input type="checkbox" id="master"></th>
                                    <th>#</th>
                                    <th>@lang('main.slidearImage')</th>
                                    <th>@lang('main.from_date_to_date')</th>
                                    <th>@lang('main.created_at')</th>
                                    <th>@lang('main.actions')</th>
                                </thead>
                                <tbody>
                                    @forelse ($dash_advertisings as $advertising)
                                        <tr>
                                            <td><input type="checkbox" class="sub_chk" data-id="{{ $advertising->id }}">
                                            </td>
                                            <td>{{ $loop->iteration }}</td>
                                            <td>@if ($advertising->getFirstMediaUrl('advertising_image','thumb'))
                                                    <img src="{{ $advertising->getFirstMediaUrl('advertising_image','thumb') }}" width="100px">
                                                    @include('admin.components.modal_photo', [
                                                        'image' => $advertising->getFirstMediaUrl('advertising_image','thumb'),
                                                        'id' => '1'.$advertising->id,
                                                    ])
                                                @else
                                                    <span> @lang('main.NoOfferImage')</span>
                                                @endif
                                            </td>
                                                        
                                            <td>
                                                {{ $advertising->from_date }}  - {{ $advertising->to_date }}
                                            </td>
                                         
                                            <td>
                                                {{ $advertising->created_at->diffForHumans() }}
                                            </td>
                                            <td width="250px">
                                                @can('slidear-edit')
                                                    <a class="btn btn-warning"
                                                        href="{{ route('advertisings.edit', $advertising->id) }}">@lang('main.edit')</a>
                                                @endcan
                                                @can('slidear-delete')
                                                    {!! \App\Support\NativeDeleteForm::open([
                                                        'method' => 'DELETE',
                                                        'route' => ['advertisings.destroy', $advertising->id],
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
                                            {{ trans('main.Noadvertisings') }}
                                        </td>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                {{ $dash_advertisings->withQueryString()->links() }}
            </div>
        </section>
    </div>
@endsection
