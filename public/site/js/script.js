


$(document).ready(function(){
    // dir
    var bodyDir = $('body').css('direction')
    var dirAr
    if(bodyDir == "rtl"){
      dirAr= true
    }
    else{
      dirAr = false
    }



    const navToggler = $("#nav-toggler");
    const collapse = $(".navbar-collapse");
    const close = $("#close");
    
    navToggler.on("click", function(){
      collapse.toggleClass("active");
    });
    close.on("click", function(){
      collapse.toggleClass("active");
    });


    
    
    // loader
    $('#loading').fadeOut(500);

    new WOW().init();

    $('select').niceSelect();




     const swiper = new Swiper('.swiper', {
      
      spaceBetween: 10 ,
      slidesPerView: 5,
      centeredSlides: true,
      breakpoints: {
        320: {
          slidesPerView: 1,
        },
        640: {
          slidesPerView: 3,
        },
        1024: {
          slidesPerView: 5,
        },
      },
      mousewheel: {
        forceToAxis: true,
      },
      // Optional parameters
      direction: 'horizontal',
      loop: true,

      // Autoplay settings
      autoplay: {
        delay: 2000, 
        disableOnInteraction: false, 
      },

      rtl: dirAr,

      // If we need pagination
      pagination: {
        el: '.swiper-pagination',
        clickable: true,
      },
    });




    const myModal = new bootstrap.Modal($('#delivery-services'));
    if(myModal !== null) {
      myModal.show();
    }


    

// function showScrollTopBtn() {
//   let scroll = window.scrollY;
//   let isNavTop = scroll < 250;
//   let scrollBtn = document.querySelector('.scrollTop');
//   if ( isNavTop) {
//     scrollBtn.classList.remove('show');
    
//   } else {
//     scrollBtn.classList.add('show');
//   }
// }
// let scrollBtn = document.querySelector('.scrollTop');

// if(scrollBtn != null){
//   window.addEventListener('scroll', showScrollTopBtn);
//   scrollBtn.addEventListener("click", ()=>{
//     window.scrollTo({top:0, left:0 });
//   })
// }








    //end
});







