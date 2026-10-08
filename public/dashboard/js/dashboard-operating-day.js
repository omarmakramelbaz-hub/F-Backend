(function(){
    'use strict';
    if(window.DashboardOperatingDay)return;
    var clock=document.querySelector('[data-dashboard-clock]'),previous;
    var server=clock&&Number(clock.dataset.clockServer),offset=server?server-Date.now():0;
    var calendar=new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Cairo',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',hourCycle:'h23'});
    var time=new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Cairo',hour:'numeric',minute:'2-digit',hour12:true});
    function date(at){
        var parts={};calendar.formatToParts(at).forEach(function(p){parts[p.type]=p.value;});
        var day=new Date(Date.UTC(Number(parts.year),Number(parts.month)-1,Number(parts.day)-(Number(parts.hour)<6?1:0)));
        return day.toISOString().slice(0,10);
    }
    function current(){return date(new Date(Date.now()+offset));}
    function update(){
        var at=new Date(Date.now()+offset),day=date(at);
        if(clock){clock.querySelector('[data-dashboard-operating-date]').textContent=day;clock.querySelector('[data-dashboard-local-time]').textContent=time.format(at);}
        if(previous!==day){var old=previous;previous=day;window.dispatchEvent(new CustomEvent('dashboard:operating-day',{detail:{date:day,previous:old}}));}
    }
    window.DashboardOperatingDay={date:date,current:current};
    update();setInterval(update,1000);document.addEventListener('visibilitychange',function(){if(!document.hidden)update();});
}());
