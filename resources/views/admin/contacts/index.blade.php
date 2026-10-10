@extends('admin.index')
@push('custom-css')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style type="text/css"></style>
@endpush
@section('content')
    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 text-dark">@lang('main.showAll') @lang('main.contacts')  <small class="countModule">({{$contacts->total()}}) </small></h1>
                    </div><!-- /.col -->
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            
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
                            @lang('main.contacts')  <span class="count-sp">( {{$contacts->count()}} )</span>
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            {{-- Buttons part --}}
                            @can('contact-delete')
                            <div class="btn-group flex-wrap float-left mb-4">
                                @include('admin.partials.button_group', [
                                    'url' => url('admin/contactsDeleteAll'),
                                ])
                            </div>
                            @endcan
                            {{-- search part --}}
                            <div class="float-right mb-4">
                                @include('admin.partials.search_part', [
                                    'route' => route('contacts.index'),
                                ])
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                <thead>
                                    <th width="50px"><input type="checkbox" id="master"></th>
                                    <th>#</th>
                                    <th>@lang('main.name')</th>
                                    <th>@lang('main.email')</th>
                                    <th>@lang('main.account_type')</th>
                                    <th>@lang('main.message')</th>
                                    <th>@lang('main.actions')</th>

                                </thead>
                                <tbody>
                                    @forelse ($contacts as $contact)
                                        <tr id="td-{{$contact->id}}">
                                            <td><input type="checkbox" class="sub_chk" data-id="{{ $contact->id }}">
                                            </td>
                                            <td>{{ $loop->iteration }}</td>
                                            <td>
                                                {{ $contact->name }}
                                            </td>
                                            
                                            <td>
                                                {{ $contact->email }}
                                            </td>
                                            <td>
                                                {{ __('main.'.$contact->user?->account_type )}}
                                            </td>
                                            <td>
                                                {{ $contact->message }}
                                            </td>
                                            <td width="250px">
                                                @can('contact-delete')
                                                    {!! \App\Support\NativeDeleteForm::open([
                                                        'method' => 'DELETE',
                                                        'route' => ['contacts.destroy', $contact->id],
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
                                            {{ trans('main.Nocontacts') }}
                                        </td>
                                    @endforelse
                                </tbody>
                            </table>
                            </div>
                        </div>
                    </div>
                </div>
                {{ $contacts->withQueryString()->links() }}
            </div>
        </section>
    </div>
@endsection
