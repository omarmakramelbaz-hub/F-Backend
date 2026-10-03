<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>{{$settings->site_name}}</title>
  <!-- Tell the browser to be responsive to screen width -->
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="{{ asset('dashboard/branding/fasakhansta-logo.png') }}">
  <meta name="description" content="لوحة التحكم في تطبيق "فسخانجي" مصممة لتلبية احتياجات أصحاب المطاعم والمناديب بفعالية. توفر النظام واجهة متقدمة لإدارة الطلبات حيث يمكن للمطاعم تلقي وتنظيم الطلبات من المستخدمين وتحديث حالتها بسهولة. يمكن لأصحاب المطاعم تتبع الطلبات المرسلة للطيارين، مع إمكانية إرسال إشعارات فورية للعملاء حول حالة الطلبات. كما تتيح لوحة التحكم تحليل أداء الطلبات وتنظيم وجبات الطيارين، مما يسهم في تحسين جودة الخدمة وتسهيل التواصل بين جميع الأطراف المشاركة في عملية الطلب والتوصيل.">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="{{url('dashboard')}}/plugins/fontawesome-free/css/all.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="{{url('dashboard')}}/dist/css/adminlte.min.css">
  <link href="{{url('/dashboard/')}}/dist/css/toastr.css" rel="stylesheet" />
  <!-- Google Font: Source Sans Pro -->
<link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300&display=swap" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300&display=swap" rel="stylesheet">

  <style type="text/css">
    .login-page, .register-page {
        background: #f4f5f6 !important;
    }   
    .btn-flat{
        border-radius: 60px !important;
        padding: 0.5rem .5rem;
        background: linear-gradient(41deg, #fd7201 0.31%, #ff9d4d 119.6%) !important;
        border: 1px solid #fd7201 !important;
        color: #fff;
    }
    .login-box a img{
        width: 250px;
    }
    .input-group{
      width: 80%;
    margin: 0 auto;
    }
    body{
      font-family: 'Almarai', sans-serif;
      height: 100vh;
    }
    .login-box{
      width:100% ;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 !important;
    } 
    .card{
      width:86% !important;
      height: 100%;
      /*padding: 5rem 45px;*/
        border-radius: 7px;
        box-shadow: 3px 1px 9px #9c8e8e;
    }
    .register-box {
        width: 400px;
    }
    .login-logo, .register-logo{
    /*  background: #BBBBBB10;*/
        padding: 2rem 0;
        margin: 0;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .login-card-body, .register-card-body ,.card{
        padding:0;
        border-radius: 20px !important;
        background: linear-gradient(41deg, #ffffff 0.31%, #ff9d4d 119.6%);
        overflow: hidden;
    }
    .login-box-msg{
      color: #fd7201;
        font-weight: bold;
    }
    .form-data{
      background: #F8F8F8;
      padding: 5rem 0px;
      border-radius: 20px !important;
    }
    
    @media (max-width: 576px) {
        html{
            overflow: hidden;
        }
        body {
            height: 100vh;
            width: 100%;
            overflow: hidden;
        }
        .login-card-body, .register-card-body{
            border-radius: 0px !important;
            background: transparent !important;
        }
        .login-page, .register-page {
            background: #fff !important;
        }
        .form-data{
            background: transparent !important;
        }
        .login-box a img {
            width: 170px;
        }
        .form-data {
            padding: 30px;
            z-index: 1;
        }
        .container{
            height: 100vh !important;
            padding: 0;
        }
        .login-box{
            width: 100%;
            height: 100%;
        }
        .card {
            width: 100% !important;
            height: 100%;
            padding: 0;
            border-radius: 0;
            box-shadow: none;
            background: transparent;
            border-radius: 0 !important;
        }
        .login-logo, .register-logo {
            padding: 0;
            padding-top: 15%;
        }
        .login-box-msg {
            color: #fd7201;
            font-weight: bolder;
            font-size: 22px;
            text-align: right;
            padding: 32px 0;
        }
        .input-group {
            width: 100% !important;
            margin-bottom: 2rem !important;
        }

        .form-control {
            padding: .75rem 1rem !important;
            height: unset;
            font-size: 1rem;
            border-radius: 35px;
        }
        .login-card-body .input-group .input-group-text, .register-card-body .input-group .input-group-text {
            border-bottom-right-radius: 35px !important;
            border-top-right-radius: 35px !important;
            padding: .75rem 1.2rem;
        }
        .btn-flat {
            border-radius: 60px !important;
            padding: 0.75rem .5rem;
            background: linear-gradient(41deg, #fd7201 0.31%, #ff9d4d 119.6%) !important;
            border: 1px solid #fd7201 !important;
            color: #fff;
        }
    }
    

  </style>
  <link rel="stylesheet" href="{{ asset('dashboard/branding/dashboard-brand.css') }}?v=20261003">
</head>
<body class="hold-transition login-page">
<div class="container h-100" style="display:flex;align-items: center;">
  <div class="login-box">  
      <!-- /.login-logo -->
      <div class="card">
        <div class="card-body login-card-body">
          <div class="row flex-column-reverse flex-md-row justify-content-center">
            
            <div class="col-md-6 col-12">
              <div class="form-data">
                <p class="login-box-msg">@lang('main.sign in to dashboard')</p>
              @if(count($errors))
                <div class="alert alert-danger">
                    <ul>
                        @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
                @endif
              <form action="{{route('admin.login')}}" method="post">
                @csrf
                <div class="input-group mb-3">
                  <input type="text" name="email" value="{{old('email')}}" class="form-control" placeholder="@lang('main.enter email') @lang('main.or mobile')">
                  <div class="input-group-append">
                    <div class="input-group-text">
                      <span class="fas fa-envelope"></span>
                    </div>
                  </div>
                </div>
                <div class="input-group mb-3">
                  <input type="password" name="password" class="form-control" placeholder="@lang('main.enter password')">
                  <div class="input-group-append">
                    <div class="input-group-text">
                      <span class="fas fa-lock"></span>
                    </div>
                  </div>
                </div>
                <div class="input-group row align-items-center">
                  <div class="col-md-6 col-5">
                    <div class="icheck-primary">
                      <input type="checkbox" id="remember">
                      <label for="remember">
                        @lang('main.remember me')
                      </label>
                    </div>
                  </div>
                  <!-- /.col -->
                  <div class="col-md-6 col-7">
                    <button type="submit" class="btn btn-secondary btn-block btn-flat">@lang('main.login')</button>
                  </div>
                  <!-- /.col -->
                </div>
              </form>
              </div>
            </div>
            <div class="col-md-6 col-12">
              <div class="login-logo">
                <a href="{{url('/')}}"><img class="dashboard-login-logo" src="{{ asset('dashboard/branding/fasakhansta-logo.png') }}" alt="فسخانستا" width="300" height="300"></a>
              </div>
            </div>
          </div>
        </div>
        <!-- /.login-card-body -->
      </div>
  </div> 
</div>

<!-- /.login-box -->

<!-- jQuery -->
<script src="{{url('dashboard')}}/plugins/jquery/jquery.min.js"></script>
<!-- Bootstrap 4 -->
<script src="{{url('dashboard')}}/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/2.1.0/js/toastr.js"></script>

    <!-- Page JS -->
        <script>
        $(document).ready(function() {
            toastr.options.timeOut = 10000;
            @if(Session::has('error'))
                toastr.error('{{ Session::get('error') }}');
            @endif
            @if(Session::has('success'))
                toastr.success('{{ Session::get('success') }}');
            @endif
        });
</script>

</body>
</html>
