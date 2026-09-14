/* SPOTCOMM GLOBAL HRIS - frontend scripts */
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('show');
}
// auto-dismiss alerts
setTimeout(function(){
  document.querySelectorAll('.alert').forEach(function(a){ a.style.transition='opacity .4s'; a.style.opacity='0'; setTimeout(function(){a.remove();},400); });
},5000);
// confirm actions
document.addEventListener('click',function(e){
  var t=e.target.closest('[data-confirm]');
  if(t){ if(!confirm(t.getAttribute('data-confirm')||'Are you sure?')) e.preventDefault(); }
});
// modal helpers
function openModal(id){ document.getElementById(id).classList.add('show'); }
function closeModal(id){ document.getElementById(id).classList.remove('show'); }
// clock (live) for attendance widgets
function liveClock(sel){
  var el=document.querySelector(sel); if(!el) return;
  function tick(){ var d=new Date();
    el.textContent = d.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  } tick(); setInterval(tick,1000);
}
// get browser location (for attendance clock-in)
function getLocation(cb){
  if(!navigator.geolocation){ cb(null); return; }
  navigator.geolocation.getCurrentPosition(
    function(p){ cb(p.coords.latitude+','+p.coords.longitude); },
    function(){ cb(null); },{timeout:8000, enableHighAccuracy:true}
  );
}
// Clock-in/out from dashboard: captures location FIRST, then submits form
function doClock(act, formId){
  var form=document.getElementById(formId||'clockForm');
  if(!form) return;
  var actInput=form.querySelector('[name=action]');
  var locInput=form.querySelector('[name=location]');
  if(actInput) actInput.value=act;
  if(locInput) locInput.value='location-unavailable';
  var btn=event?event.target.closest('button'):null;
  if(btn){ btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Getting location...'; }
  getLocation(function(loc){
    if(locInput) locInput.value=loc||'location-unavailable';
    form.submit();
  });
}
// Notification bell dropdown
function toggleNotifs(){
  var dd=document.getElementById('notifDropdown');
  if(dd.style.display==='none'||!dd.style.display){
    dd.style.display='block';
  } else {
    dd.style.display='none';
  }
}
// Mark all notifications as read
function markAllRead(){
  fetch('../notifications-api.php?action=mark_read',{method:'GET'})
    .then(function(r){return r.json()})
    .then(function(data){
      if(data.ok){
        var dot=document.querySelector('.topbar-icon .dot');
        if(dot) dot.remove();
        document.querySelectorAll('.notif-item.unread').forEach(function(el){el.classList.remove('unread');});
      }
    })
    .catch(function(){
      // fallback if relative path fails
      fetch('notifications-api.php?action=mark_read',{method:'GET'})
        .then(function(r){return r.json()})
        .then(function(data){
          if(data.ok){
            var dot=document.querySelector('.topbar-icon .dot');
            if(dot) dot.remove();
            document.querySelectorAll('.notif-item.unread').forEach(function(el){el.classList.remove('unread');});
          }
        });
    });
}
// Close dropdown when clicking outside
document.addEventListener('click',function(e){
  var dd=document.getElementById('notifDropdown');
  var bell=document.getElementById('notifBell');
  if(dd && dd.style.display==='block' && bell && !bell.contains(e.target) && !dd.contains(e.target)){
    dd.style.display='none';
  }
});
