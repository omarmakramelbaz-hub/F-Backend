@extends('admin.index')
@section('content')
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="m-0 text-dark">@lang('main.send notifications')</h1>
        </div><!-- /.col -->
        <div class="col-sm-6">
        </div><!-- /.col -->
      </div><!-- /.row -->
    </div><!-- /.container-fluid -->
  </div>
  <!-- /.content-header -->

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">
      <div class="row">
        <div class="col-lg-12 col-md-12 card">
          <div class="card-body">
            <!--<h2>Send notification</h2>-->
            <div id="dashboard-push" data-audience-url="{{ route('dashboard-push.audience') }}" data-status-url="{{ route('dashboard-push.status', ['campaign' => 0]) }}" data-step-url="{{ route('dashboard-push.step', ['campaign' => 0]) }}" data-resume-url="{{ route('dashboard-push.resume', ['campaign' => 0]) }}">
            <p class="col-sm-10 font-weight-bold">{{ __('dashboard_push.total_users', ['total' => $totalUsers]) }}</p>
            <div class="alert alert-light border col-sm-10" data-push-audience role="status" aria-live="polite" style="white-space:pre-line">{{ __('dashboard_push.audience_choose') }}</div>
            <p class="text-muted col-sm-10">{{ __('dashboard_push.audience_note') }}</p>
            <form data-push-form method="post" action="{{route('fcm_notifications.store')}}">
              @csrf
              <input type="hidden" name="durable" value="1">
              <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
              <input type="hidden" name="account_type" value="{{request()->account_type??'user'}}"/>
              <div class="form-group col-sm-10">
                <label for="title"> @lang('main.notify_title')</label>
                <input type="text" name ="title" value="{{old('title')}}" maxlength="150" required class="form-control" id="title" placeholder="@lang('main.enter title')">
              </div>

              <div class="form-group col-sm-10">
                <label for="body">@lang('main.notify_body')</label>
                <textarea name="body" maxlength="1500" required rows="4" class="form-control" id="body" placeholder="@lang('main.enter body')">{{old('body')}}</textarea>
              </div>
            <div class="form-group col-sm-10">
                <label for="send_by">@lang('main.send_by')</label><br/>
                <input type="radio" checked name="send_by" value="0" class=""> @lang('main.send by zone')
            </div>
            <div class="form-group col-sm-10">
                <input type="radio" name="send_by" value="1" class=""> @lang('main.send specific users')
                
            </div>
              <div class="form-group col-sm-10 zone"  >
                <label for="zone_id">@lang('main.choose zone')</label>
                <select name="zone_id[]" multiple class="form-control" id="zone_id">
                    <option value="">@lang('main.choose')</option>
                    @foreach(\App\Models\Area::whereNotNull('parent_id')->get() as $val)
                    <option value="{{$val->id}}">{{$val->title}}</option>
                    @endforeach
                </select>
              </div>
              
              <div class="form-group col-sm-10" id="user-choose" >
                <label for="choose_user">@lang('main.choose_user')</label><br/>
                <div class="form-group col-sm-10">
                    <input type="radio" checked name="choose_user" value="0" class=""> @lang('main.select all users')
                
                </div>
                <div class="form-group col-sm-10">
                    
                <input type="radio" name="choose_user" value="1" class=""> @lang('main.select specific users')
                </div>
                <select name="user_id[]" multiple class="form-control " id="show-case">  
                  @foreach($users as $user)
                  <option value="{{$user->id}}"> @lang('main.name'): {{$user->name}} / @lang('main.mobile'): {{$user->mobile}}</option> 
                  @endforeach
                </select>
              </div>

              <div class="form-group col-sm-10">
                <button type="submit" class="btn btn-success">@lang('main.send')</button>
              </div>
            </form>
            <div class="form-group col-sm-10">
              <label for="push-history">{{ __('dashboard_push.history') }}</label>
              <select id="push-history" class="form-control" data-push-history>
                <option value="">{{ __('main.choose') }}</option>
                @foreach($campaigns as $campaign)
                <option value="{{ $campaign->id }}">{{ $campaign->created_at }} · {{ $campaign->title }}</option>
                @endforeach
              </select>
            </div>
            <div class="alert alert-info col-sm-10" data-push-progress role="status" aria-live="polite" hidden></div>
            <div class="col-sm-10 mb-3">
              <button type="button" class="btn btn-warning" data-push-resume hidden>{{ __('dashboard_push.resume') }}</button>
              <button type="button" class="btn btn-outline-secondary" data-push-new hidden>{{ __('dashboard_push.new') }}</button>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

@endsection
@push('custom-js')
<script id="dashboard-push-labels" type="application/json">@json(trans('dashboard_push'))</script>
<script src="{{ asset('dashboard/js/dashboard-push.js') }}?v={{ filemtime(public_path('dashboard/js/dashboard-push.js')) }}"></script>
@endpush
