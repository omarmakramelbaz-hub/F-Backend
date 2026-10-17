@extends('admin.index')
@section('content')
<div class="content-wrapper"><section class="content pt-4"><div class="card mx-auto" style="max-width:850px"><div class="card-header"><h1 class="h4 mb-0">{{ __('printing.title') }}</h1></div><div class="card-body">
<p>{{ __('printing.intro') }}</p>
<ol class="pl-4 pr-4"><li class="mb-3">{{ __('printing.step_printer') }}</li><li class="mb-3">{{ __('printing.step_download') }}</li><li class="mb-3">{{ __('printing.step_launch') }}</li><li>{{ __('printing.step_test') }}</li></ol>
<a class="btn btn-success mb-3" href="{{ asset('dashboard/start-branch-pos-printing.cmd') }}" download="start-branch-pos-printing.cmd" data-spa-off>{{ __('printing.download') }}</a>
<button class="btn btn-outline-primary mb-3" type="button" data-test-pos-print data-url="{{ route('print-settings.test') }}">{{ __('printing.test') }}</button>
<p class="alert alert-info">{{ __('printing.note') }}</p><p>{{ __('printing.devices') }}</p><p role="status" data-print-setup-status></p>
</div></div></section></div>
@endsection
@push('custom-js')<script src="{{ asset('dashboard/js/print-settings.js') }}?v={{ filemtime(public_path('dashboard/js/print-settings.js')) }}"></script>@endpush
