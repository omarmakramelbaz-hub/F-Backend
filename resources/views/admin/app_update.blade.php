@extends('admin.index')
@section('content')
<div class="content-wrapper">
  <section class="content-header"><div class="container-fluid"><h1>تحديث التطبيق الإجباري</h1></div></section>
  <section class="content">
    <div class="container-fluid">
      @include('admin.layouts.alerts')
      <div class="card">
        <div class="card-body">
          <p class="text-muted">أي عميل نسخة تطبيقه أقدم من الرقم اللي هنا، هيظهرله في الرئيسية رسالة تحديث مايقدرش يقفلها، فيها زرار «حدّث التطبيق» بيفتح المتجر. سيب الرقم فاضي علشان توقف التحديث الإجباري على المنصة دي.</p>
          <form method="post" action="{{ route('app-update.update') }}">
            @csrf
            @method('PUT')
            <div class="row">
              <div class="form-group col-sm-6">
                <label for="android_min_version">أقل نسخة مسموحة على أندرويد</label>
                <input type="text" dir="ltr" name="android_min_version" id="android_min_version" class="form-control @error('android_min_version') is-invalid @enderror" placeholder="1.0.55" value="{{ old('android_min_version', $values['android_min_version']) }}">
                @error('android_min_version')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="form-group col-sm-6">
                <label for="ios_min_version">أقل نسخة مسموحة على الآيفون</label>
                <input type="text" dir="ltr" name="ios_min_version" id="ios_min_version" class="form-control @error('ios_min_version') is-invalid @enderror" placeholder="2.0.6" value="{{ old('ios_min_version', $values['ios_min_version']) }}">
                @error('ios_min_version')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="form-group col-sm-6">
                <label for="android_store_url">رابط التطبيق على جوجل بلاي</label>
                <input type="url" dir="ltr" name="android_store_url" id="android_store_url" class="form-control @error('android_store_url') is-invalid @enderror" placeholder="https://play.google.com/store/apps/details?id=..." value="{{ old('android_store_url', $values['android_store_url']) }}">
                @error('android_store_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="form-group col-sm-6">
                <label for="ios_store_url">رابط التطبيق على آب ستور</label>
                <input type="url" dir="ltr" name="ios_store_url" id="ios_store_url" class="form-control @error('ios_store_url') is-invalid @enderror" placeholder="https://apps.apple.com/eg/app/..." value="{{ old('ios_store_url', $values['ios_store_url']) }}">
                @error('ios_store_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="form-group col-sm-12">
                <label for="message">نص الرسالة (اختياري)</label>
                <textarea name="message" id="message" rows="2" maxlength="300" class="form-control @error('message') is-invalid @enderror" placeholder="{{ \App\Services\App\AppUpdatePolicy::DEFAULT_MESSAGE }}">{{ old('message', $values['message']) }}</textarea>
                @error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
            </div>
            <button type="submit" class="btn btn-success">@lang('main.save')</button>
          </form>
        </div>
      </div>
    </div>
  </section>
</div>
@endsection
