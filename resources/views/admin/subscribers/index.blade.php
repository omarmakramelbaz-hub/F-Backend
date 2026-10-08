@extends('admin.index')
@push('custom-css')
    <link href="{{ App\Services\Dashboard\DesktopDashboardAssets::url('https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css') }}" rel="stylesheet">

@endpush
@section('content')
    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 text-dark">@lang('main.showAllSubscribers')</h1>
                    </div><!-- /.col -->
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <!-- Button trigger modal -->
{{--<button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#exampleModal">
  @lang('main.send email for all subscribers')
</button>--}}

<!-- Modal -->
<div class="modal fade" id="exampleModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" class="send_email_all" action="{{route('sendSubscriberEmail')}}">
          @csrf
      <div class="modal-header">
        <h5 class="modal-title" id="exampleModalLabel">@lang('main.send a message for subscribers')</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
          <label for="message">@lang('main.send message')</label>
          <textarea name="message" rows="20" id="message" class="form-control summernote"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary">Save changes</button>
      </div>
      </form>
    </div>
  </div>
</div>
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
                            @lang('main.subscribers')
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            {{-- Buttons part --}}
                            @can('contact-delete')
                            <div class="btn-group flex-wrap float-left mb-4">
                                @include('admin.partials.button_group', [
                                    'url' => url('admin/subscribersDeleteAll'),
                                ])
                            </div>
                            @endcan
                            {{-- search part --}}
                            <div class="float-right mb-4">
                                @include('admin.partials.search_part', [
                                    'route' => route('subscribers.index'),
                                ])
                            </div>

                            <table class="table table-bordered table-hover">
                                <thead>
                                    <th width="50px"><input type="checkbox" id="master"></th>
                                    <th>#</th>
                                    <th>@lang('main.email')</th>
                                    <th>@lang('main.actions')</th>

                                </thead>
                                <tbody>
                                    @forelse ($subscribers as $subscribe)
                                        <tr>
                                            <td><input type="checkbox" class="sub_chk" data-id="{{ $subscribe->id }}">
                                            </td>
                                            <td>{{ $loop->iteration }}</td>
                                            
                                            <td>
                                                {{ $subscribe->email }}
                                            </td>
                                          
                                            <td width="250px">
                                                @can('contact-delete')
                                                    {!! Form::open([
                                                        'method' => 'DELETE',
                                                        'route' => ['subscribers.destroy', $subscribe->id],
                                                        'style' => 'display:inline',
                                                    ]) !!}
                                                    <button type="submit"
                                                        class="btn btn-danger show_confirm">@lang('main.delete')</button>
                                                    {!! Form::close() !!}
                                                @endcan

                                            </td>
                                        </tr>
                                    @empty
                                        <td class="text-center text-muted" style="font-size: 25px" colspan="5">
                                            {{ trans('main.Nosubscribers') }}
                                        </td>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                {{ $subscribers->withQueryString()->links() }}
            </div>
        </section>
    </div>
@endsection
@push('custom-js')
    <script src="{{ App\Services\Dashboard\DesktopDashboardAssets::url('https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js') }}"></script>
<script type="text/javascript">
$(document).ready(function() {
   $('.summernote').summernote({
 placeholder: 'Hello stand alone ui',
        tabsize: 2,
        height: 200,
        toolbar: [
          ['style', ['style']],
          ['font', ['bold', 'underline', 'clear']],
          ['color', ['color']],
          ['para', ['ul', 'ol', 'paragraph']],
          ['table', ['table']],
          ['insert', ['link', 'picture', 'video']],
          ['view', ['fullscreen', 'codeview', 'help']]
        ]  });
});
</script>
@endpush
