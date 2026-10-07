@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard/css/dashboard-support-chat.css') }}?v={{ filemtime(public_path('dashboard/css/dashboard-support-chat.css')) }}">
@endpush
@section('content')
<div class="content-wrapper">
    <section class="content-header"><div class="container-fluid"><h1>{{ trans('dashboard_inbox.support') }}</h1></div></section>
    <section class="content"><div class="container-fluid">
        <div class="ds-chat card" id="dashboard-support-chat"
             data-base-url="{{ url('/admin/dashboard-inbox/support') }}"
             data-inbox-id="{{ $inboxId }}" data-selected="{{ $user ? $user->id : '' }}"
             data-loading="{{ trans('dashboard_inbox.loading') }}"
             data-empty="{{ trans('dashboard_inbox.empty') }}"
             data-error="{{ trans('dashboard_inbox.unavailable') }}"
             data-send-error="{{ trans('dashboard_inbox.send_failed') }}">
            <aside class="ds-users">
                <label for="support-user-search" class="sr-only">{{ trans('dashboard_inbox.search') }}</label>
                <input type="search" id="support-user-search" class="form-control" placeholder="{{ trans('dashboard_inbox.search') }}">
                <ul id="support-user-list">
                    @foreach($users as $partner)
                    <li><button type="button" class="ds-user" data-support-partner="{{ $partner->id }}" data-support-name="{{ $partner->name ?: $partner->mobile }}">
                        <i class="fas fa-user-circle" aria-hidden="true"></i>
                        <span><strong>{{ $partner->name ?: $partner->mobile }}</strong><small>{{ $partner->account_type }}</small></span>
                        <span class="badge dashboard-support-badge" data-support-partner-count="{{ $partner->id }}" hidden></span>
                    </button></li>
                    @endforeach
                </ul>
            </aside>
            <div class="ds-conversation">
                <header>
                    @if(count($inboxIds) > 1)
                    <label for="support-inbox-choice" class="sr-only">{{ trans('dashboard_inbox.inbox') }}</label>
                    <select id="support-inbox-choice" class="form-control form-control-sm mb-2" data-support-inbox>
                        @foreach($inboxIds as $scope)
                        <option value="{{ $scope }}" @if($scope === $inboxId) selected @endif>{{ $scope === (int) auth('admin')->id() ? trans('dashboard_inbox.own_inbox') : trans('dashboard_inbox.central_inbox') }}</option>
                        @endforeach
                    </select>
                    @endif
                    <strong data-support-title>{{ trans('dashboard_inbox.choose') }}</strong></header>
                <p class="ds-status" data-support-status role="status">{{ trans('dashboard_inbox.choose') }}</p>
                <div class="ds-messages-scroll" data-support-scroll>
                    <button type="button" class="btn btn-sm btn-light ds-older" data-support-older hidden>{{ trans('dashboard_inbox.older') }}</button>
                    <ul id="support-messages" aria-live="polite"></ul>
                </div>
                <form data-support-send hidden>
                    <label for="support-message-input" class="sr-only">@lang('main.enter your message here')</label>
                    <input type="text" id="support-message-input" class="form-control" maxlength="5000" required placeholder="@lang('main.enter your message here')" autocomplete="off">
                    <button type="submit" class="btn btn-primary">{{ trans('dashboard_inbox.send') }}</button>
                </form>
            </div>
        </div>
    </div></section>
</div>
@endsection
@push('custom-js')
<script src="{{ asset('dashboard/js/dashboard-support-chat.js') }}?v={{ filemtime(public_path('dashboard/js/dashboard-support-chat.js')) }}"></script>
@endpush
