'use strict';
// DOM-only fixtures. No iframe resources or live Meta accounts are accessed.
const assert=require('assert');
const {fixture,settle,key,post}=require('./meta_comments_timeline_test');
async function selected(permalink) {
    const rows=[Object.assign(post('1','البوست الأصلي'),{permalink}),Object.assign(post('2','بوست آخر'),{permalink:'https://www.facebook.com/page/posts/456'})];
    const f=fixture(false,{postRows:rows}); await settle();
    assert.equal(f.host.querySelectorAll('iframe').length,0,'Only selected posts load platform embeds');
    f.host.querySelector('[data-mc-post="'+key('1')+'"]').click(); await settle(); return f;
}
(async()=>{
    let f=await selected('https://facebook.com/fasakhansta/posts/pfbid123?utm_source=ignored#ignored');
    let frame=f.host.querySelector('.mc-original-frame'), src=new URL(frame.src), panel=f.host.querySelector('.mc-comments-panel');
    assert.equal(src.origin,'https://www.facebook.com'); assert.equal(src.pathname,'/plugins/post.php');
    assert.equal(src.searchParams.get('href'),'https://www.facebook.com/fasakhansta/posts/pfbid123');
    assert.equal(src.searchParams.get('show_text'),'true'); assert.equal(frame.parentElement,panel.firstElementChild);
    assert.equal(frame.getAttribute('referrerpolicy'),'no-referrer'); assert(frame.hasAttribute('sandbox'));
    assert(!frame.getAttribute('sandbox').includes('top-navigation'));
    const first=f.host.querySelector('[data-mc-posts]').firstElementChild;
    f.w.document.dispatchEvent(new f.w.Event('visibilitychange')); await settle();
    assert.equal(f.host.querySelector('[data-mc-posts]').firstElementChild,first,'Unchanged live data does not rebuild the iframe parent');
    assert.equal(f.host.querySelector('iframe'),frame);
    f.host.querySelector('.mc-original-expand').click(); assert.equal(f.host.querySelector('.mc-original-expand').getAttribute('aria-expanded'),'true');
    Object.defineProperty(f.host,'clientWidth',{value:400,configurable:true});
    f.w.dispatchEvent(new f.w.Event('resize')); await new Promise(resolve=>setTimeout(resolve,190)); await settle();
    assert.equal(new URL(f.host.querySelector('iframe').src).searchParams.get('width'),'352','Resize adjusts the original plugin width');
    frame=f.host.querySelector('iframe');
    f.host.querySelector('[data-mc-post="'+key('2')+'"]').click(); await settle();
    assert.equal(f.host.querySelectorAll('iframe').length,1); assert.equal(frame.src,'about:blank');
    assert.equal(new URL(f.host.querySelector('iframe').src).searchParams.get('href'),'https://www.facebook.com/page/posts/456');
    f.w.DashboardMetaComments.destroy(); assert.equal(f.host.querySelectorAll('iframe').length,0); f.dom.window.close();
    for(const permalink of ['https://www.instagram.com/p/Abc_-12/?utm=x','https://instagram.com/reel/Abc_-12/','https://instagram.com/tv/Abc_-12/']) {
        f=await selected(permalink); src=new URL(f.host.querySelector('iframe').src);
        assert.equal(src.href,'https://www.instagram.com/p/Abc_-12/embed/captioned/'); f.dom.window.close();
    }
    for(const permalink of ['https://www.facebook.com/permalink.php?id=123&story_fbid=pfbid123', 'https://www.facebook.com/123_456', 'https://www.facebook.com/photo.php?fbid=456&type=3','https://www.facebook.com/page/photos/a.123/456/']) {
        f=await selected(permalink); assert(f.host.querySelector('iframe'),'Common Facebook public permalink shapes are supported'); f.dom.window.close();
    }
    for(const permalink of ['https://www.facebook.com/page/videos/123/','https://www.facebook.com/reel/123/','https://www.facebook.com/watch/?v=123']) {
        f=await selected(permalink); assert.equal(new URL(f.host.querySelector('iframe').src).pathname,'/plugins/video.php'); f.dom.window.close();
    }
    for(const permalink of [null,'javascript:alert(1)','https://facebook.com.evil.test/page/posts/123','https://evil.facebook.com/page/posts/123','http://www.facebook.com/page/posts/123','https://user:pass@www.facebook.com/page/posts/123','https://www.facebook.com:8443/page/posts/123','https://www.facebook.com/page/posts/123?access_token=SECRET','https://www.facebook.com/login.php','https://www.instagram.com/accounts/login/']) {
        f=await selected(permalink); assert.equal(f.host.querySelectorAll('iframe').length,0); assert(!f.host.querySelector('.mc-original-post').textContent.includes('SECRET'));
        assert(f.host.querySelector('[data-mc-reply]'),'Embedding limits never disable dashboard comment replies'); f.dom.window.close();
    }
    console.log('META_ORIGINAL_POST_PASS Facebook/Instagram/video embeds, canonical public URLs, selected-only loading, stable live updates, switching/cleanup, unsafe URL rejection, and reply availability; rendered=false; liveMeta=false');
})().catch(error=>{console.error(error);process.exitCode=1;});
