(function(){
    'use strict';
    if(window.DashboardSPA&&!window.DashboardSPA.isCurrentPage())return;
    var button=document.querySelector('[data-test-pos-print]'),status=document.querySelector('[data-print-setup-status]');if(!button)return;
    async function test(){button.disabled=true;status.textContent='';try{await window.DashboardPrint.print(button.dataset.url);}catch(error){status.textContent=error.message;}finally{button.disabled=false;}}
    button.addEventListener('click',test);
    if(window.DashboardSPA)window.DashboardSPA.onCleanup(function(){button.removeEventListener('click',test);});
})();
