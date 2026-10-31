@extends('admin.index')
@section('content')
    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row justify-content-between align-items-center gy-2  mb-2">
                    <div class="col-auto">
                        <h1 class="m-0 text-dark">@lang('main.edit') {{__('main.'.request('account_type'))}}</h1>
                    </div><!-- /.col -->
                    <div class="col-auto">
                        <ol class="breadcrumb float-sm-left">
                           @can(request('account_type').'-list')
                            <li class="breadcrumb-item"><a
                                    href="{{ url('admin/users?account_type='.request('account_type'))}}"
                                    class="btn btn-primary">@lang('main.showAll') {{__('main.'.request('account_type'))}} </a></li>
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
                <div class="row">
                    <div class="col-lg-12 col-md-12">
                        @include('admin.layouts.alerts')
                        <div class="card">
                            <div class="card-body" style="opacity: 1">
                                <form method="post" action="{{ route('users.update', ['account_type' => request('account_type'),$user->id]) }}"
                                    enctype="multipart/form-data">
                                    @csrf
                                    @method('PUT')
                                    @include('admin.users.form')
                                </form>
                                
                                <!-- Modal -->
                                <div class="modal fade" id="changeMobile" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
                                  <div class="modal-dialog modal-dialog-centered" role="document">
                                    <div class="modal-content">
                                      <div class="modal-header">
                                        <h5 class="modal-title" id="exampleModalLabel">{{ (int)$user->id === 1 && !$user->mobile ? trans('main.add').' '.trans('main.mobile') : trans('main.change mobile') }}</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        <!--<button type="button" class="close" data-dismiss="modal" aria-label="Close">-->
                                        <!--  <span aria-hidden="true">&times;</span>-->
                                        <!--</button>-->
                                      </div>
                                      <div class="modal-body">
                                        <form method="post" action="{{ (int)$user->id === 1 ? route('owner.mobile.update') : route('vendor.UpdatePhone') }}">
                                            @csrf
                                            <input type="hidden" name="user_id" value="{{$user->id}}"/>
                                        <div class="row gy-3 p-md-4">
                                            <div class="col-md-12">
                                                <label for="change-mobile"> @lang('main.mobile')</label><span class="text-danger">*</span>
                                                <span class="country_code">20+</span>
                                                <input type="tel" inputmode="tel" autocomplete="tel-national" name="mobile" value="{{ old('mobile', $user->mobile) }}" class="form-control @error('mobile', (int)$user->id === 1 ? 'ownerMobile' : 'default') is-invalid @enderror"
                                                    id="change-mobile" placeholder="@lang('main.enter') @lang('main.mobile')">
                                                @error('mobile', (int)$user->id === 1 ? 'ownerMobile' : 'default')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                            </div>
                                            <div class="form-group col-md-12">
                                                <label for="password"> @lang('main.current_password')</label><span class="text-danger">*</span>
                                                <input type="password" name="current_password" value=""
                                                    class="form-control @error('current_password', (int)$user->id === 1 ? 'ownerMobile' : 'default') is-invalid @enderror" id="oldPassword" autocomplete="current-password" required
                                                    placeholder="@lang('main.EnterPassword')">
                                                <button type="button" class="show-pass" toggle="#oldPassword">
                                                    <i class="fa fa-eye-slash"></i>
                                                </button>
                                                @error('current_password', (int)$user->id === 1 ? 'ownerMobile' : 'default')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                            </div>
                                            
                                          
                                            <div class="col-sm-6">
                                                <button type="sumbit" class="btn btn-primary">@lang('main.Save changes')</button>
                                            </div>
                                            </form>
                                        </div>
                                      </div>
                                    </div>
                                  </div>
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
<script>
        $(document).ready(function () {
             @if(session('owner_mobile_open'))
             if (window.bootstrap && document.getElementById('changeMobile')) {
                 bootstrap.Modal.getOrCreateInstance(document.getElementById('changeMobile')).show();
             }
             @endif
             $(document).on('change','#category-dd',function(e){
                var idcategory = $(e.target).val();
                $("#gate-dd").html('');
                $.ajax({
                    url: "{{url('admin/user-fetch-gate')}}",
                    type: "POST",
                    data: {
                        category_id: idcategory,
                        _token: '{{csrf_token()}}'
                    },
                    dataType: 'json',
                    success: function (data) {
                        $(".gates").html('');
                        $(".gates").html(data.options);
                    }
                });
            });
        });
</script>
@endpush
