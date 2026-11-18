/* Social pages stay live in a separate native window; the main dashboard stays on the device. */
(() => {
    const native=window.FasakhanstaDesktop;
    if(!native||typeof native.openLive!=='function'||document.body?.dataset.dashboardLocal!=='1')return;
    window.addEventListener('click',event=>{
        const link=event.target.closest?.('a[href]');if(!link)return;
        let url;try{url=new URL(link.href,location.href);}catch{return;}
        if(url.origin!==location.origin||url.username||url.password)return;
        const path=url.pathname.replace(/\/$/,''),kind=path==='/admin/meta/comments'?'comments'
            :['/admin/meta','/admin/meta/whatsapp','/admin/whatsapp'].includes(path)?'messages':null;
        if(!kind)return;
        event.preventDefault();event.stopImmediatePropagation();
        native.openLive(kind).catch(error=>{
            let note=document.getElementById('desktop-live-error');
            if(!note){note=document.createElement('p');note.id='desktop-live-error';note.className='alert alert-warning';note.setAttribute('role','alert');
                (document.querySelector('.content-wrapper')||document.body).prepend(note);}
            note.textContent=error.message;
        });
    },true);
})();
