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
                        <h1 class="m-0 text-dark">@lang('main.showAll') @lang('main.question_answers') <small class="countModule">( {{$question_answers->count()}} )</small></h1>
                    </div><!-- /.col -->
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-left">
                            @can('question_answer-create')
                               <li class="breadcrumb-item"><a href="{{ url('admin/question_answers/create') }}" class="btn btn-primary">@lang('main.add') @lang('main.question_answers')
                            </a></li>  
                            @endcan                          </ol>
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
                            @lang('main.question_answers')
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            {{-- Buttons part --}}
                            @can('question_answer-delete')
                            <div class="btn-group flex-wrap float-left mb-4">
                                @include('admin.partials.button_group', [
                                    'url' => url('admin/question_answersDeleteAll'),
                                ])
                            </div>
                            @endcan
                            {{-- search part --}}
                            
                            <div class="float-right mb-4">
                                @include('admin.partials.search_part', [
                                    'route' => route('question_answers.index'),
                                ])
                                {{-- <a class="btn btn-info" href="{{route('export-question_answers')}}">@lang('main.export excel')</a> --}}
                            </div>
                            
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <th width="50px"><input type="checkbox" id="master"></th>
                                    <th>#</th>
                                    <th>@lang('main.faqqAr')</th>
                                    <th>@lang('main.created_at')</th>
                                    <th>@lang('main.actions')</th>
                                </thead>
                                <tbody>
                                    @forelse ($question_answers as $question_answer)
                                        <tr>
                                            <td><input type="checkbox" class="sub_chk" data-id="{{ $question_answer->id }}">
                                            </td>
                                            <td>{{ $loop->iteration }}</td>
                                            
                                            <td>
                                                {{mb_strimwidth($question_answer->question, 0, 60, '.........')}}
                                            </td>
                                            <td>
                                                {{ $question_answer->created_at->diffForHumans() }}
                                            </td>
                                            
                                            <td width="250px">
                                                @can('question_answer-list')
                                                    <a class="btn btn-info"
                                                        href="{{ route('question_answers.show',[$question_answer->id,'type'=> request('type')]) }}">@lang('main.show')</a>
                                                @endcan
                                                @can('question_answer-edit')
                                                    <a class="btn btn-warning"
                                                        href="{{ route('question_answers.edit',[$question_answer->id,'type'=> request('type')]) }}">@lang('main.edit')</a>
                                                @endcan
                                                @can('question_answer-delete')
                                                    {!! \App\Support\NativeDeleteForm::open([
                                                        'method' => 'DELETE',
                                                        'route' => ['question_answers.destroy', $question_answer->id],
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
                                            {{ trans('main.Noquestion_answers') }}
                                        </td>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                {{ $question_answers->withQueryString()->links() }}
            </div>
        </section>
    </div>
@endsection
