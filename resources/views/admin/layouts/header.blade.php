<!DOCTYPE html>
<html lang="en" @if(App::getLocale() == 'ar') dir="rtl" @elseif (App::getLocale() == 'en') dir="ltr" @endif>

<head>
    	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ app(App\Models\GeneralSettings::class)->site_name }}</title>
    <!-- Tell the browser to be responsive to screen width -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Font Awesome -->
    <link rel="icon" type="image/x-icon"
        href="{{ url('/storage/' . app(App\Models\GeneralSettings::class)->favicon) }}">
    <meta name="csrf-token" content="{{ csrf_token() }}" />
  <meta name="description" content="لوحة التحكم في تطبيق "فسخانجي" مصممة لتلبية احتياجات أصحاب المطاعم والمناديب بفعالية. توفر النظام واجهة متقدمة لإدارة الطلبات حيث يمكن للمطاعم تلقي وتنظيم الطلبات من المستخدمين وتحديث حالتها بسهولة. يمكن لأصحاب المطاعم تتبع الطلبات المرسلة للطيارين، مع إمكانية إرسال إشعارات فورية للعملاء حول حالة الطلبات. كما تتيح لوحة التحكم تحليل أداء الطلبات وتنظيم وجبات الطيارين، مما يسهم في تحسين جودة الخدمة وتسهيل التواصل بين جميع الأطراف المشاركة في عملية الطلب والتوصيل.">

    <link rel="stylesheet" href="{{ url('/dashboard') }}/plugins/fontawesome-free/css/all.min.css">
    <!-- Ionicons -->
    <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
       <!-- Bootstrap 4 RTL -->
    <!--<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">-->
    @if (App::getLocale() == 'ar')
    <link rel="stylesheet" href="{{ url('/dashboard') }}/dist/css/bootstrap.rtl.min.css">
    @endif
    @if (App::getLocale() == 'en')
    <link rel="stylesheet" href="{{ url('/dashboard') }}/dist/css/bootstrap.min.css">
    @endif
    <link rel="stylesheet" href="{{ url('/dashboard') }}/dist/css/selectize.bootstrap5.css">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote.min.js"></script>
    <link rel="stylesheet" href="{{ url('/dashboard') }}/dist/css/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
        integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    


    <!--<link rel="stylesheet" href="{{ url('/dashboard') }}/dist/css/custom.css">-->
    <link rel="stylesheet" href="{{ url('/dashboard') }}/dist/css/bootstrap-tagsinput.css">

    <!-- Theme style -->
    @if (App::getLocale() == 'ar')
        <link rel="stylesheet" href="{{ url('/dashboard') }}/dist/css/adminrtl.css">
    @endif

    @if (App::getLocale() == 'en')
        <link rel="stylesheet" href="{{ url('/dashboard') }}/dist/css/adminlte.min.css">
    @endif

    <!-- overlayScrollbars -->
    <link rel="stylesheet" href="{{ url('/dashboard') }}/plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
    <link rel="stylesheet" href="{{ url('/dashboard') }}/plugins/summernote/summernote-bs4.css">
   <!-- Custom style for RTL -->
    <link href="{{ url('/dashboard/') }}/dist/css/toastr.css" rel="stylesheet" />
    @stack('custom-css')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/rateYo/2.3.2/jquery.rateyo.min.css">
     <link rel="stylesheet" href="{{ url('/dashboard') }}/dist/css/select2.min.css"> 
       <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.2.1/themes/default/style.min.css" />
        <link rel="stylesheet" href="{{ url('/dashboard') }}/dist/css/my-custom.css">
        @if($fasV3Actor)
        <link rel="stylesheet" href="{{ asset('dashboard-v3/dashboard-v3.css') }}?v=20261003-002">
        @endif
        
    <style>
    .cke_notification_warning{
        display:none;
    }
        .addons{
            font-size:20px;
            margin: 20px auto;
            font-weight: bolder;
            text-decoration: underline;
        }

  .jstree-icon.jstree-themeicon {
    background-image: url('{{ asset("storage/" . app(App\Models\GeneralSettings::class)->favicon) }}') !important;
    background-repeat: no-repeat !important;
    background-position: center !important;
    background-size: contain !important;
    width: 16px !important;
    height: 16px !important;
  }


.treeitem{
    margin-bottom:1.5rem !important;
}
 .jstree-children{
    margin-top:1rem;
}
.jstree-default.jstree-rtl .jstree-node {

    margin-bottom: 1rem;
}

                /* Toggle Button */
.cm-toggle {
    -webkit-appearance: none;
    -webkit-tap-highlight-color: transparent;
    position: relative;
    border: 0;
    outline: 0;
    cursor: pointer;
    margin: 10px;
}


/* To create surface of toggle button */
.cm-toggle:after {
    content: '';
    width: 60px;
    height: 28px;
    display: inline-block;
    background: rgba(196, 195, 195, 0.55);
    border-radius: 18px;
    clear: both;
}


/* Contents before checkbox to create toggle handle */
.cm-toggle:before {
    content: '';
    width: 32px;
    height: 32px;
    display: block;
    position: absolute;
    left: 0;
    top: -3px;
    border-radius: 50%;
    background: rgb(255, 255, 255);
    box-shadow: 1px 1px 3px rgba(0, 0, 0, 0.6);
}
/* Transition for smoothness */
.cm-toggle,
.cm-toggle:before,
.cm-toggle:after,
.cm-toggle:checked:before,
.cm-toggle:checked:after {
    transition: ease .3s;
    -webkit-transition: ease .3s;
    -moz-transition: ease .3s;
    -o-transition: ease .3s;
}

/* Shift the handle to left on check event */
.cm-toggle:checked:before {
    left: 32px;
    box-shadow: -1px 1px 3px rgba(0, 0, 0, 0.6);
}
/* Background color when toggle button will be active */
.cm-toggle:checked:after {
    background: #16a085;
}

    </style>
     <script src="https://www.gstatic.com/firebasejs/7.9.1/firebase-app.js"></script>
     <script src="https://www.gstatic.com/firebasejs/7.9.1/firebase-firestore.js"></script>
    <script src="https://www.gstatic.com/firebasejs/7.9.1/firebase-messaging.js"></script>

    <script >
const firebaseConfig = {
     apiKey: "AIzaSyCXd5OLKdIutyA4qsidhBwQCRJt3SsFHEE",

  authDomain: "fasakhaninjatest.firebaseapp.com",

  projectId: "fasakhaninjatest",

  storageBucket: "fasakhaninjatest.firebasestorage.app",

  messagingSenderId: "224648167390",

  appId: "1:224648167390:web:13c997f338325b4b56274f",

  measurementId: "G-ECZ1880953"
};
// Initialize Firebase
firebase.initializeApp(firebaseConfig);





    </script>
 
<script src="https://cdn.jsdelivr.net/npm/axios@1.6.7/dist/axios.min.js"></script>
</head>

<body class="hold-transition sidebar-mini layout-fixed {{ $fasV3Actor ? 'fas-v3-shell' : '' }}">
    <div class="wrapper">
