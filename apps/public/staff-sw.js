'use strict';
self.addEventListener('install',()=>self.skipWaiting());
self.addEventListener('activate',event=>event.waitUntil(self.clients.claim()));
self.addEventListener('push',event=>{
  let data={};try{data=event.data?event.data.json():{}}catch(_){data={body:event.data?event.data.text():''}}
  const n=data.notification||data||{};
  const title=String(n.title||'سکنا');
  const body=String(n.body||'');
  const target=String(n.url||'staff?model=notifications');
  event.waitUntil(self.registration.showNotification(title,{body,tag:String(n.tag||'sokna-staff'),renotify:false,icon:'assets/favicon-192.png',badge:'assets/favicon-192.png',data:{url:target}}));
});
self.addEventListener('notificationclick',event=>{
  event.notification.close();
  const raw=String(event.notification.data?.url||'staff?model=notifications');
  const target=new URL(raw,self.registration.scope);
  if(target.origin!==self.location.origin)return;
  event.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(async list=>{
    for(const client of list){if('navigate' in client&&'focus' in client){await client.navigate(target.href);return client.focus();}}
    return self.clients.openWindow?self.clients.openWindow(target.href):undefined;
  }));
});
