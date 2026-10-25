@unless(config('desktop_dashboard.local'))
<!--<script src="https://js.pusher.com/7.2/pusher.min.js"></script>-->
@endunless

<script>
    // Pusher.logToConsole = true;

    // var pusher = new Pusher("0f818db2b7622218a22a", {
    //     cluster: "mt1",
    //     forceTLS: true
    // });

    // var channel = pusher.subscribe("order.1503"); 
    // channel.bind("status-updated", function(data) {
    //     console.log("Received:", data);
    //     alert("Status: " + data.order.status);
    // });
</script>

<footer class="main-footer">
  <!--<button id="enable-sound">Enable Sound</button>-->
  <!--<button id="stop-sound" style="display:none;">Stop Alert</button>-->
  <style>
    .skew-shake-y {
        position: fixed;
        bottom: 2rem;
        border-radius: 8px;
        inset-inline-start: 25%;
        width: 50%;
        background: linear-gradient(41deg, var(--main) 0.31%, var(--main-light) 119.6%) !important;
        color: white !important;
        padding: 1rem 2rem;
        font-size: 1rem;
        font-weight: bold;
        direction: ltr;
        display: flex;
        align-items: center;
        justify-content: space-between;
        animation: skew-y-shake 1.3s infinite;
    }
    @keyframes skew-y-shake {
      0% { transform: skewY(-4deg); }
      5% { transform: skewY(4deg); }
      10% { transform: skewY(-4deg); }
      15% { transform: skewY(4deg); }
      20% { transform: skewY(0deg); }
      100% { transform: skewY(0deg); }  
    }
  </style>
  <div id="notifications">
    <!--<h3>Notifications:</h3>-->
    <!--<ul id="notificationsList"></ul>-->
  </div>
  
    <strong class="text-center">Copyright &copy; {{ date('Y') }} . @lang('main.allrights')</strong>
</footer>

<!-- Control Sidebar -->
<aside class="control-sidebar control-sidebar-dark">
    <!-- Control sidebar content goes here -->
</aside>
<!-- /.control-sidebar -->
</div>
<!-- ./wrapper -->

<!-- jQuery -->
<script src="{{ url('/dashboard') }}/plugins/jquery/jquery.min.js"></script>
<!-- jQuery UI 1.11.4 -->
<script src="{{ url('/dashboard') }}/plugins/jquery-ui/jquery-ui.min.js"></script>
<!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->
<script>
    $.widget.bridge('uibutton', $.ui.button)
</script>
<!-- Bootstrap 4 rtl -->
<!--<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ka7Sk0Gln4gmtz2MlQnikT1wXgYsOg+OMhuP+IlRH9sENBO0LRn5q+8nbTov4+1p" crossorigin="anonymous"></script>-->
<!-- Summernote -->
<script src="{{ url('/dashboard') }}/dist/js/bootstrap.bundle.min.js"></script>
<script>if (window.DashboardSPA) window.DashboardSPA.attachJQuery();</script>
<script src="{{ App\Services\Dashboard\DesktopDashboardAssets::url('//cdn.ckeditor.com/4.14.0/standard/ckeditor.js') }}"></script>
<script src="{{ url('/dashboard') }}/dist/js/bootstrap-tagsinput.js"></script>



<script data-dashboard-page-init>
$(document).ready(function() {
    // if ($("#show-case").next().length > 0) {
    //     $("#show-case").next().hide();

    //     $("input[name='choose_user']").change(()=> {
    //         const checkedInput = $("input[name='choose_user']:checked").val();
            
    //         if(Number(checkedInput) === 1){
    //             $("#show-case").next().show();
    //         }else{
    //             $("#show-case").next().hide();
    //         }
            

    //     });
    // }
    
    
    const showInputCase = (selectId , inputName)=> {
        
        const select = selectId;
        const input = inputName;
        
        if (select == '#user-choose'){
            if ($(select).length > 0) {
        $(select).hide();
        $(".zone").show();

        $(`input[name=${input}]`).change(()=> {
            const checkedInput = $(`input[name=${input}]:checked`).val();
            
            if(Number(checkedInput) === 1){
                $(select).show(200);
                
            }else{
                $(select).hide(200);
                
            }
            
            if(Number(checkedInput) === 0){
                $(".zone").show(200);
            }else{
                $(".zone").hide(200);
            }
            


        });
    }
        }else{
        
        if ($(select).next().length > 0) {
        $(select).next().hide();

        $(`input[name=${input}]`).change(()=> {
            const checkedInput = $(`input[name=${input}]:checked`).val();
            
            if(Number(checkedInput) === 1){
                $(select).next().show(200);
            }else{
                $(select).next().hide(200);
            }
            

        });
    }
    
        }
        
    }
    
    showInputCase("#show-case" , 'choose_user' )
    showInputCase("#user-choose" , 'send_by' )
    
});


</script>

<script type="text/javascript" data-dashboard-page-init>




  
    $(document).ready(function() {
    CKEDITOR.replace( 'ckeditor',{
        height: 1000 // Set height in pixels
    });
    
            // Wait for the CKEditor script to initialize
        setTimeout(function() {
            $('.cke_notification_warning').addClass('d-none');
            // Hide the CKEditor alert message
            $('div.cke-editor__top').each(function() {
                if ($(this).text().includes('This CKEditor 4.14.0 version is not secure. Consider upgrading to the latest one, 4.24.0-lts')) {
                    $(this).hide();
                }
            });
        }, 1000); // Adjust the delay if necessary
});
    </script>
<!-- overlayScrollbars -->
<script src="{{ url('/dashboard') }}/plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
<script src="{{ asset('dashboard/js/dashboard-order-alerts.js') }}?v={{ filemtime(public_path('dashboard/js/dashboard-order-alerts.js')) }}" data-sound-url="{{ asset('sounds/mixkit-correct-answer-reward-952.wav') }}" data-sound-actor="{{ auth('admin')->user()->id }}" data-sound-feed="{{ route('getOrders', ['alerts_only' => 1]) }}"></script>
<div data-dashboard-page-scripts hidden>
<script src="{{ asset('dashboard/js/dashboard-invoice-details.js') }}?v={{ filemtime(public_path('dashboard/js/dashboard-invoice-details.js')) }}"></script>
@stack('custom-js')
</div>
@unless(config('desktop_dashboard.local'))
  <script src="{{ App\Services\Dashboard\DesktopDashboardAssets::url('https://js.pusher.com/8.2.0/pusher.min.js') }}"></script>
@endunless

<!-- AdminLTE App -->
<script src="{{ url('/dashboard') }}/dist/js/selectize.min.js"></script>
<script src="{{ url('/dashboard') }}/dist/js/select2.min.js"></script>
<script src="{{ url('/dashboard') }}/dist/js/flatpickr.min.js"></script>
<script src="{{ url('/dashboard') }}/dist/js/adminlte.js?v={{ filemtime(public_path('dashboard/dist/js/adminlte.js')) }}"></script>
<script src="{{ asset('dashboard/js/dashboard-navigation.js') }}?v={{ filemtime(public_path('dashboard/js/dashboard-navigation.js')) }}"></script>
<script src="{{ asset('dashboard/js/dashboard-idle.js') }}?v={{ filemtime(public_path('dashboard/js/dashboard-idle.js')) }}" data-home-url="{{ url('/admin/dashboard') }}"></script>
<script src="{{ asset('dashboard/js/dashboard-operating-day.js') }}?v={{ filemtime(public_path('dashboard/js/dashboard-operating-day.js')) }}"></script>
<script src="{{ asset('dashboard/js/dashboard-expense-badge.js') }}?v={{ filemtime(public_path('dashboard/js/dashboard-expense-badge.js')) }}"></script>
<script src="{{ asset('dashboard/js/dashboard-fit.js') }}?v={{ filemtime(public_path('dashboard/js/dashboard-fit.js')) }}"></script>
<!-- AdminLTE dashboard demo (This is only for demo purposes) -->

<!-- AdminLTE for demo purposes -->
<script src="{{ url('/dashboard') }}/dist/js/demo.js"></script>
<script src="{{ App\Services\Dashboard\DesktopDashboardAssets::url('https://cdnjs.cloudflare.com/ajax/libs/toastr.js/2.1.0/js/toastr.js') }}"></script>
<!-- Latest compiled and minified JavaScript -->
<script src="{{ App\Services\Dashboard\DesktopDashboardAssets::url('https://cdnjs.cloudflare.com/ajax/libs/rateYo/2.3.2/jquery.rateyo.min.js') }}"></script>
<script src="{{ App\Services\Dashboard\DesktopDashboardAssets::url('https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js') }}"></script>
<script src="{{ App\Services\Dashboard\DesktopDashboardAssets::url('https://cdnjs.cloudflare.com/ajax/libs/jstree/3.2.1/jstree.min.js') }}"></script>

@unless(config('desktop_dashboard.local'))
<script>
console.log('fgdfgdfdbfgdfgfgdfgdfgdfgdgdf',firebaseConfig)
// // Ensure Service Worker is supported
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('{{url("firebase-messaging-sw.js")}}')
        .then((registration) => {
            console.log('Service Worker registered with scope:', registration.scope);
            // Now, initialize Firebase Messaging with the registered service worker
            messaging.useServiceWorker(registration);
            requestNotificationPermission();
        })
        .catch((error) => {
            console.error('Service Worker registration failed:', error);
        });

//       let audioReady = true;
        
// let audioInterval = null; // Store the interval ID
//   // Flag to ensure audio playback is allowed
// const audio = new Audio('{{url('/')}}/sounds/mixkit-correct-answer-reward-952.wav');



// // Listen for messages from the service worker
navigator.serviceWorker.addEventListener('message', (event) => {

    if (event.data && event.data.type === 'PLAY_SOUND') {
        if (window.DashboardOrderAlerts) window.DashboardOrderAlerts.retry();
        if (typeof window.reloadOrderSections === 'function') window.reloadOrderSections();
    }

    if (event.data && event.data.type === 'STOP_SOUND' && typeof window.reloadOrderSections === 'function') {
        window.reloadOrderSections();
    }

});
            // }
          // Check if the page is in the background
            // if (document.visibilityState === 'hidden' && clickAction) {
            //     const newWindow = window.open(clickAction, '_blank');
            //     if (newWindow) {
            //         console.log('Window opened in background:', newWindow);
            //     } else {
            //         console.error('Failed to open window. It may be blocked by the browser.');
            //     }
            // } else {
            //     console.log('User is active on the page. Window not opened.');
            // } 
            
//     if (event.data && event.data.type === 'play-audio-loop' ) {
      
// console.log(clickAction);
                
//         if (audioReady) {
//             // Start the audio loop
//             if (!audioInterval) {
              
//                 audioInterval = setInterval(() => {
                    
//                     audio.play().catch((error) => {
//                         console.error('Audio playback failed:', error);
//                     });
//                 }, audio.duration * 1000 || 3000); // Use audio duration or default to 3 seconds
                
                
//             }
//         } else {
//             console.warn('Audio not ready. Waiting for user interaction.');
//         }

//         // Stop the audio loop when the window is focused
//         window.addEventListener('focus', () => {
//             if (audioInterval) {
//                 clearInterval(audioInterval);
//                 audioInterval = null;
//                 console.log('Audio loop stopped. Window is in focus.');
//             }
//         });

//         // Open the window if the user clicks the notification
//         navigator.serviceWorker.addEventListener('message', (event) => {
//             if (event.data && event.data.type === 'stop-audio') {
//                 if (audioInterval) {
//                     clearInterval(audioInterval);
//                     audioInterval = null;
//                 }
//             }
          
//         });
//     }
// });

}


// Initialize Firebase Messaging
const messaging = firebase.messaging();

// Request notification permission and get the token
function requestNotificationPermission() {
    (document.body.dataset.dashboardLocal === '1' ? Promise.resolve('denied') : Notification.requestPermission()).then((permission) => {
        if (permission === 'granted') {
            console.log('Notification permission granted.');
            // Get the token and send it to the server
            getToken();
        } else {
            console.log('Unable to get permission to notify.');
        }
    });
}
let audio;
$(document).ready(function(){
    getToken();
    if(audio){
                audio.pause(); // Stops the audio
                audio.currentTime = 0; // Resets the audio to the start
            }
})
// Retrieve token and handle FCM messages
function getToken() {
    messaging.getToken({ vapidKey: 'BCQ4Essgps5zSojP4_gZVOYxiujNYCClxiFZ9wjI7qAnXMg1JWYcNcTi6h3OIP5DWgNZVz69gjT-datbauWD7PU' }).then((currentToken) => {
        if (currentToken) {
            console.log('FCM Token:', currentToken);
            // Send the token to your server
            sendTokenToServer(currentToken);
        } else {
            console.log('No registration token available.');
        }
    }).catch((err) => {
        console.log('An error occurred while retrieving token: ', err);
    });
}




// Handle foreground messages
// messaging.onMessage((payload) => {
//     console.log('Message received in foreground:', payload);
// //   const audio = new Audio('{{url('/')}}/sounds/mixkit-correct-answer-reward-952.wav');
// //     audio.loop = true;
// //     audio.play();
// const title = payload.notification.title || 'Default Title'; // Fallback title
//     const body = payload.notification.body;
//     const icon = payload.data.icon || '{{url("/storage/".app(App\Models\GeneralSettings::class)->logo)}}';
//     const clickAction = payload.data.click_action || '{{url('/')}}';

//     const options = {
//         body: body,
//         icon: icon,
//         vibrate: [1000, 200, 1000, 200, 1000], // Long vibration pattern
//           silent: false,
//         data: {
//             click_action: clickAction // Store click action in data
//         }
//     };
//     // Show notification
//     // return self.registration.showNotification(title, options);

//     // Display the notification if the user has granted permission
//     if (Notification.permission === 'granted') {
//         new Notification(title, options);
//         // Notification.addEventListener('click', () => {
//         //     if(audio){
//         //         audio.pause(); // Stops the audio
//         //         audio.currentTime = 0; // Resets the audio to the start
//         //     }
//         // });
//     }
// });









  
function sendTokenToServer(token){
    console.log("token"+token);
    const user_id="{{auth('admin')->user()->id}}";
    axios.post("{{url('/admin/save-token')}}",{
    token,user_id
    }).then(res=>{
      console.log("success:"+res);
    }).catch(ress=>{
      console.log("cash"+ress);
    })
 }

</script>
@else
<script>
// A previous preview may retain its old FCM worker in this local profile.
if ('serviceWorker' in navigator && navigator.serviceWorker.getRegistrations) {
    navigator.serviceWorker.getRegistrations().then(function (registrations) {
        var localOrigin = new URL(window.location.href).origin;
        registrations.forEach(function (registration) {
            var messagingWorker = [registration.active, registration.waiting, registration.installing].some(function (worker) {
                if (!worker) return false;
                var source = new URL(worker.scriptURL, window.location.href);
                return source.origin === localOrigin && source.pathname === '/firebase-messaging-sw.js';
            });
            if (messagingWorker) registration.unregister().catch(function () {});
        });
    }).catch(function () {});
}
</script>
@endunless
<!-- Page JS -->
<script data-dashboard-page-init>
    $(document).ready(function() {
$('.selectize').selectize()
// $('select').select2();
        
        toastr.options.timeOut = 10000;
        @if (Session::has('error'))
            toastr.error('{{ Session::get('error') }}');
        @endif
        @if (count($errors))
              @foreach ($errors->all() as $error)
                  toastr.error('{{ $error}}');
              @endforeach
       @endif

    
});
window.changeLanguage = function(lang) {
        window.location = '{{ url('/change-language') }}/' + lang;
    };
    $(function () {
 
  $(".rateYo").rateYo({
    starWidth: "13px",readOnly: true
  });
 
});
</script>

<script src="{{ App\Services\Dashboard\DesktopDashboardAssets::url('https://unpkg.com/sweetalert/dist/sweetalert.min.js') }}"></script>
<script type="text/javascript" data-dashboard-page-init>
    $(function() {
    // Multiple images preview in browser
    var imagesPreview = function(input, placeToInsertImagePreview) {

        if (input.files) {
            var filesAmount = input.files.length;

            for (i = 0; i < filesAmount; i++) {
                var reader = new FileReader();

                reader.onload = function(event) {
                    $($.parseHTML('<img>')).attr('src', event.target.result).appendTo(placeToInsertImagePreview);
                }

                reader.readAsDataURL(input.files[i]);
            }
        }

    };

    $('#gallery-photo-add').on('change', function() {
        imagesPreview(this, 'div.gallery');
    });
    $('#gallery-photo-add2').on('change', function() {
        imagesPreview(this, 'div.gallery2');
    });
});
$(document).ready(function(){
    $(".show-pass").click(function () {
        $(this).find('i').toggleClass("fa-eye-slash fa-eye");
        var input = $($(this).attr("toggle"));
        if (input.attr("type") == "password") {
          input.attr("type", "text");
        } else {
          input.attr("type", "password");
        }
        $(this).toggleClass('active');
     });

        $(".card-body").css("opacity", 1);
         $('select[name="other_products[]"]').select2();
    });
    
    $('.show_confirm').click(function(event) {

        var form = $(this).closest("form");

        var name = $(this).data("name");

        event.preventDefault();

        swal({
                title: `هل انت متاكد من حذف هذا العنصر ؟`,
                text: "أذا قمت بحذف هذا العنصر لن تتمكن من استرجاعه مره اخرى !",
                icon: "warning",
                buttons: ['لا', 'نعم'],
                dangerMode: true,
            })

            .then((willDelete) => {

                if (willDelete) {
                    form.submit();
                }
            });
    });

$(function() {
    $(window).on("load", function() {
        $('.card-body').css('opacity',1);
    });
    $( "form" ).not("[data-wa-reply-form]").submit(function() {
        $(this).find("button[type='submit']").html('<i class="fa fa-spinner fa-spin"></i>@lang('main.under click')');
    });
});
$(document).ready(function () {


        $('#master').on('click', function(e) {
         if($(this).is(':checked',true))  
         {
            $(".sub_chk").prop('checked', true);  
         } else {  
            $(".sub_chk").prop('checked',false);  
         }  
        });


        $('.send_email_all').on('click', function(e) {


            var allMails = [];  
            $(".sub_chk:checked").each(function() {  
                allMails.push($(this).attr('data-id'));
            });  


            if(allMails.length <=0)  
            {  
                alert("Please select row.");  
            }  else {  
                if(check == true){  

                    var join_selected_values = allMails.join(","); 
                    $.ajax({
                        url: $(this).prop('action'),
                        type: 'POST',
                        headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')},
                        data: 'ids='+join_selected_values,
                        success: function (data) {
                            if (data['success']) {
                                $(".sub_chk:checked").each(function() {  
                                    // $(this).parents("tr").remove();
                                });
                                alert(data['success']);
                            } else if (data['error']) {
                                alert(data['error']);
                            } else {
                                alert('Whoops Something went wrong!!');
                            }
                        },
                        error: function (data) {
                            alert(data.responseText);
                        }
                    });
                }  
            }  
        });


        $('.delete_all').on('click', function(e) {


            var allVals = [];  
            $(".sub_chk:checked").each(function() {  
                allVals.push($(this).attr('data-id'));
            });  


            if(allVals.length <=0)  
            {  
                alert("Please select row.");  
            }  else {  


                var check = confirm("Are you sure you want to delete this row?");  
                if(check == true){  


                    var selectedIds = Object.freeze(allVals.slice());
                    var join_selected_values = selectedIds.join(",");


                    $.ajax({
                        url: $(this).data('url'),
                        type: 'DELETE',
                        headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')},
                        data: 'ids='+join_selected_values,
                        success: function (data) {
                            if (data['success']) {
                                $(".sub_chk").filter(function() {
                                    return selectedIds.includes($(this).attr('data-id'));
                                }).each(function() {
                                    $(this).parents("tr").remove();
                                });
                                alert(data['success']);
                                if (window.DashboardSPA) window.DashboardSPA.reload();
                                else window.location.reload();
                            } else if (data['error']) {
                                alert(data['error']);
                            } else {
                                alert('Whoops Something went wrong!!');
                            }
                        },
                        error: function (data) {
                            alert(data.responseText);
                        }
                    });


                  @unless(config('desktop_dashboard.local'))
                  $.each(selectedIds, function( index, value ) {
                      $('table tr').filter("[data-row-id='" + value + "']").remove();
                      
                  });
                  @endunless
                //   $('tbody').html(' <tr><td colspan="4"><h4>@lang('main.no data to show')</h4><td></tr>');
                }  
            }  
        });
    });

// image upload multipl
 $(document).ready(function() {
  var fileArr = [];
   $("#images").change(function(){
      // check if fileArr length is greater than 0
       if (fileArr.length > 0) fileArr = [];
     
        $('#image_preview').html("");
        var total_file = document.getElementById("images").files;
        if (!total_file.length) return;
        for (var i = 0; i < total_file.length; i++) {
          if (total_file[i].size > 1048576) {
            return false;
          } else {
            fileArr.push(total_file[i]);
            $('#image_preview').append("<div class='img-div' id='img-div"+i+"'><img src='"+URL.createObjectURL(event.target.files[i])+"' class='img-responsive image img-thumbnail' title='"+total_file[i].name+"'><div class='middle'><button id='action-icon' value='img-div"+i+"' class='btn btn-danger' role='"+total_file[i].name+"'><i class='fa fa-trash'></i></button></div></div>");
          }
        }
   });
  
  $('body').on('click', '#action-icon', function(evt){
      var divName = this.value;
      var fileName = $(this).attr('role');
      $(`#${divName}`).remove();
    
      for (var i = 0; i < fileArr.length; i++) {
        if (fileArr[i].name === fileName) {
          fileArr.splice(i, 1);
        }
      }
    document.getElementById('images').files = FileListItem(fileArr);
      evt.preventDefault();
  });
  
   function FileListItem(file) {
            file = [].slice.call(Array.isArray(file) ? file : arguments)
            for (var c, b = c = file.length, d = !0; b-- && d;) d = file[b] instanceof File
            if (!d) throw new TypeError("expected argument to FileList is File or array of File objects")
            for (b = (new ClipboardEvent("")).clipboardData || new DataTransfer; c--;) b.items.add(file[c])
            return b.files
        }
});
</script>

<script data-dashboard-page-init>
     $('.select-component').select2();
      $('#jstree').jstree();
</script>

<!--<script src="{{ App\Services\Dashboard\DesktopDashboardAssets::url('//cdn.ckeditor.com/4.14.0/standard/ckeditor.js') }}"></script>-->
<!--</script>-->
  <!--<script-->
  <!--  type="text/javascript"-->
  <!--  src="//translate.google.com/translate_a/element.js?cb=googleTranslateInit"-->
  <!--</script>-->
  <!--<script type="text/javascript">-->
  <!--  function googleTranslateInit() {-->
  <!--    new google.translate.TranslateElement(-->
  <!--      { pageLanguage: 'en' },-->
  <!--      'google_translate_button'-->
  <!--    );-->
  <!--  }-->
  <!--</script>-->
<script>
(function () {
    var alerts = window.DashboardOrderAlerts;
    var boardUrl = @json(url('admin/applies-orders'));
    function refreshOrders() { if (typeof window.reloadOrderSections === 'function') window.reloadOrderSections(); }
    function renderBanner() {
        if (!alerts) return;
        var keys = alerts.state().pending.filter(function (key) { return key.indexOf('phone:') !== 0; });
        var container = document.getElementById('notifications');
        if (!container) return;
        if (!keys.length) { container.replaceChildren(); return; }
        var banner = document.getElementById('alertBanner');
        if (!banner) {
            banner = document.createElement('a'); banner.id = 'alertBanner';
            banner.className = 'bg-transparent skew-shake-y alert-notification border-0';
            banner.href = boardUrl; container.replaceChildren(banner);
        }
        banner.textContent = keys.length + ' طلبات جديدة · عرض الطلبات';
        container.hidden = false; container.style.display = '';
    }
    document.addEventListener('dashboard:order-alert-state', renderBanner);
    renderBanner();
    // Audio works independently of external SDKs; polling remains available when Pusher fails.
    if (document.body.dataset.dashboardLocal === '1' || typeof window.Pusher !== 'function' || window.DashboardOrderPusher) return;
    var pusher;
    try {
        pusher = new window.Pusher('0f818db2b7622218a22a', {
            cluster: 'mt1', authEndpoint: @json(url('/pusher/auth')),
            auth: { headers: { 'X-CSRF-TOKEN': @json(csrf_token()) } }
        });
    } catch (_) { return; }
    window.DashboardOrderPusher = pusher;
    var channel = pusher.subscribe('private-user.' + @json((string)auth('admin')->user()->id));
    channel.bind('order.updated', function (data) {
        if (!data || !data.order_id) { refreshOrders(); return; }
        var key = 'legacy:' + data.order_id;
        if (data.action === 'accepted' || data.action === 'declined') {
            if (alerts) alerts.acknowledge(key);
            refreshOrders(); return;
        }
        if (data.action && data.action !== 'new') { refreshOrders(); return; }
        if (alerts) alerts.notify(key);
        refreshOrders();
        if (typeof window.Notification === 'function' && Notification.permission === 'granted') {
            try {
                var notification = new Notification('طلب جديد', { body: 'طلب رقم ' + (data.order_no || data.order_id), icon: @json(url('/storage/'.app(App\Models\GeneralSettings::class)->logo)) });
                notification.onclick = function () { window.focus(); window.location.href = boardUrl; notification.close(); };
            } catch (_) {}
        }
    });
    channel.bind('user.updated', function (data) {
        // Other user/account events never acknowledge unrelated pending orders.
        if (alerts && data && data.order_id && ['accepted', 'declined'].indexOf(data.action) !== -1) alerts.acknowledge('legacy:' + data.order_id);
        refreshOrders();
    });
}());
$(document).on('click', '.btn-close', function () {
    var modal = document.getElementById('product-details');
    if (!modal) return;
    modal.classList.remove('show'); modal.style.display = 'none'; document.body.classList.remove('modal-open');
    var backdrop = document.querySelector('.modal-backdrop'); if (backdrop) backdrop.remove();
});
</script>

<script src="{{ asset('dashboard/js/dashboard-print.js') }}?v={{ filemtime(public_path('dashboard/js/dashboard-print.js')) }}"></script>

@include('admin.pos_service.receiver')
<script src="{{ asset('dashboard/js/dashboard-inbox.js') }}?v={{ filemtime(public_path('dashboard/js/dashboard-inbox.js')) }}"></script>
<script src="{{ asset('dashboard/js/dashboard-support-chat.js') }}?v={{ filemtime(public_path('dashboard/js/dashboard-support-chat.js')) }}"></script>
</body>

</html>
