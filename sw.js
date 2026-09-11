const CACHE='naa-v7-6-0-cross-modal-20260911';
const PUSH_SEEN='naa-v6-7-push-seen';
const STATIC=[
  './manifest.webmanifest','./xlsx.full.min.js','./sabe-seed.js','./sabe-pdf-premium.js','./sabe-premium-v650.js','./naa-cross-modal-compare.js','./modelos_upload/SABE_LEIA-ME.txt','./modelos_upload/MODELO_ITENS_SABE.csv',
  './logo_prefeitura.png','./logo_prefeitura_report.jpg','./logo_nucleo.png','./logo_nucleo_icon.png','./logo_nucleo_report.jpg',
  './naa-icon-192.png','./naa-icon-512.png'
];

self.addEventListener('install',event=>{
  event.waitUntil((async()=>{
    const cache=await caches.open(CACHE);
    // O shell offline é buscado ignorando cache HTTP/intermediário.
    try{
      const fresh=await fetch(new URL('./index.html?naa_build=7600',self.registration.scope).href,{cache:'reload'});
      if(fresh.ok)await cache.put(new URL('./index.html',self.registration.scope).href,fresh.clone());
    }catch{}
    for(const asset of STATIC){
      try{
        const url=new URL(asset,self.registration.scope).href;
        const response=await fetch(url,{cache:'reload'});
        if(response.ok)await cache.put(url,response.clone());
      }catch{}
    }
    await self.skipWaiting();
  })());
});

self.addEventListener('activate',event=>{
  event.waitUntil(
    caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE&&key!==PUSH_SEEN).map(key=>caches.delete(key))))
      .then(()=>self.clients.claim())
  );
});

self.addEventListener('fetch',event=>{
  const req=event.request;
  const url=new URL(req.url);
  if(req.method!=='GET'||url.pathname.includes('/api/')) return;
  const isHtml=req.mode==='navigate'||url.pathname.endsWith('.html')||url.pathname.endsWith('/');
  if(isHtml){
    // ONLINE: nunca devolve HTML antigo do Cache Storage.
    // OFFLINE: todos os segmentos, inclusive SABE, usam o mesmo shell principal.
    event.respondWith(
      fetch(req,{cache:'no-store'}).catch(async()=>{
        const cache=await caches.open(CACHE);
        return (await cache.match(new URL('./index.html',self.registration.scope).href)) || Response.error();
      })
    );
    return;
  }
  // Arquivos estáticos: online busca primeiro no servidor para assumir a publicação nova;
  // offline cai no cache da versão instalada.
  event.respondWith((async()=>{
    try{
      const response=await fetch(req,{cache:'no-store'});
      if(response&&response.ok){const copy=response.clone();caches.open(CACHE).then(cache=>cache.put(req,copy)).catch(()=>{});}
      return response;
    }catch{
      const cached=await caches.match(req);
      return cached||Response.error();
    }
  })());
});

async function alreadySeenPush(id){
  if(!id) return false;
  const cache=await caches.open(PUSH_SEEN);
  const key=new Request(new URL('./__push_seen__/'+encodeURIComponent(String(id)),self.location).href);
  if(await cache.match(key)) return true;
  await cache.put(key,new Response(String(Date.now()),{headers:{'content-type':'text/plain'}}));
  return false;
}

async function confirmMessageDelivery(data){
  if((data.kind||data.data?.kind)!=='message')return false;
  const messageId=String(data.messageId||data.data?.messageId||'');
  const userId=String(data.receiptUserId||data.data?.receiptUserId||'');
  const receipt=String(data.deliveryToken||data.data?.deliveryToken||'');
  if(!messageId||!userId||!receipt)return false;
  const endpoint=new URL('./api/naa-platform.php',self.registration.scope).href;
  for(let attempt=0;attempt<3;attempt++){
    try{
      const response=await fetch(endpoint,{
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({action:'push_receipt',messageId,userId,receipt}),
        credentials:'same-origin',
        cache:'no-store'
      });
      if(response.ok)return true;
    }catch{}
    await new Promise(resolve=>setTimeout(resolve,350*(attempt+1)));
  }
  return false;
}

function absoluteAsset(value,fallback='./naa-icon-192.png'){
  try{return new URL(value||fallback,self.registration.scope).href}catch{return new URL(fallback,self.registration.scope).href}
}

async function refreshNotificationBadge(){
  try{
    const visible=await self.registration.getNotifications();
    const count=visible.length;
    if(self.navigator&&'setAppBadge' in self.navigator){
      if(count>0)await self.navigator.setAppBadge(count);else await self.navigator.clearAppBadge();
    }
  }catch{}
}

self.addEventListener('push',event=>{
  event.waitUntil((async()=>{
    let data={};
    try{data=event.data?event.data.json():{};}catch{data={body:event.data?event.data.text():''};}

    // O recibo vem antes da exibição/deduplicação: receber o Push já significa
    // que o aparelho destinatário recebeu a mensagem (dois tiques cinza).
    await confirmMessageDelivery(data);

    const id=String(data.eventId||data.id||data.notificationId||'');
    if(await alreadySeenPush(id))return;
    const kind=String(data.kind||data.data?.kind||'notice');
    const title=data.title||'NAA — Núcleo de Avaliação';
    const url=data.url||data.data?.url||'./index.html';
    const senderPhoto=data.senderPhoto||data.data?.senderPhoto||'';
    const messageImage=data.messageImage||data.data?.messageImage||'';
    const callTag=kind==='direct_call'&&String(data.callId||data.data?.callId||'')?`naa-direct-call-${String(data.callId||data.data?.callId||'')}`:'';
    const options={
      body:data.body||data.message||'',
      icon:absoluteAsset(senderPhoto||data.icon,'./naa-icon-192.png'),
      badge:absoluteAsset(data.badge,'./naa-icon-192.png'),
      tag:callTag||data.tag||('naa-'+kind+'-'+(id||Date.now())),
      renotify:false,
      requireInteraction:!!data.requireInteraction,
      data:{
        ...(data.data||{}),
        url,eventId:id,kind,
        contactId:String(data.contactId||data.data?.contactId||''),
        messageId:String(data.messageId||data.data?.messageId||''),
        mode:String(data.mode||data.data?.mode||''),
        callId:String(data.callId||data.data?.callId||''),
        callActionToken:String(data.callActionToken||data.data?.callActionToken||''),
        receiptUserId:String(data.receiptUserId||data.data?.receiptUserId||'')
      },
      actions:Array.isArray(data.actions)?data.actions:[]
    };
    // Mensagem com foto: avatar do remetente fica no ícone e a mídia enviada
    // ocupa a imagem grande. Chamadas continuam usando a foto do contato.
    if(kind==='message'&&messageImage)options.image=absoluteAsset(messageImage,'./naa-icon-192.png');
    else if(kind==='direct_call'&&senderPhoto)options.image=absoluteAsset(senderPhoto,'./naa-icon-192.png');
    if(kind==='direct_call'&&options.tag){
      try{const oldCalls=await self.registration.getNotifications({tag:options.tag});oldCalls.forEach(n=>n.close())}catch{}
    }
    await self.registration.showNotification(title,options);
    await refreshNotificationBadge();
  })());
});


async function claimCallNotificationAction(callId,action){
  if(!callId)return true;
  try{
    const cache=await caches.open(PUSH_SEEN);
    const key=new Request(new URL('./__call_action__/'+encodeURIComponent(callId)+'/'+encodeURIComponent(action||'open'),self.location).href);
    const old=await cache.match(key);
    if(old){
      const at=Number(await old.text())||0;
      if(Date.now()-at<8000)return false;
    }
    await cache.put(key,new Response(String(Date.now()),{headers:{'content-type':'text/plain'}}));
  }catch{}
  return true;
}

self.addEventListener('notificationclick',event=>{
  const data=event.notification?.data||{};
  const rawAction=String(event.action||'').toLowerCase();
  const action=['reject','decline','recusar'].includes(rawAction)?'reject':(['answer','accept','atender'].includes(rawAction)?'answer':rawAction);
  event.notification.close();
  event.waitUntil((async()=>{
    const callId=String(data.callId||'');
    if(data.kind==='direct_call'&&callId){
      try{
        const tag=`naa-direct-call-${callId}`;
        const same=await self.registration.getNotifications({tag});same.forEach(n=>n.close());
      }catch{}
    }
    if(data.kind==='direct_call'&&(action==='reject'||action==='answer')){
      const claimed=await claimCallNotificationAction(callId,action);
      if(claimed){
        try{
          const endpoint=new URL('./api/naa-platform.php',self.registration.scope).href;
          await fetch(endpoint,{
            method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},
            body:JSON.stringify({
              action:'direct_call_push_action',callId,
              userId:String(data.receiptUserId||''),token:String(data.callActionToken||''),
              decision:action==='reject'?'declined':'accepted'
            }),credentials:'same-origin',cache:'no-store'
          });
        }catch{}
      }
      if(action==='reject'){await refreshNotificationBadge();return}
    }
    let target=data.url||'./index.html';
    if(data.kind==='direct_call'){
      const urlObj=new URL(target,self.registration.scope);
      if(callId)urlObj.searchParams.set('callId',callId);
      if(data.mode)urlObj.searchParams.set('call',String(data.mode));
      if(action==='answer')urlObj.searchParams.set('answer','1');
      target=urlObj.href;
    }
    const absolute=new URL(target,self.location.origin).href;
    const list=await clients.matchAll({type:'window',includeUncontrolled:true});
    if(list.length){
      const client=list.find(c=>c.visibilityState==='visible')||list[0];
      /* App já aberto: não navega/recarrega. Isso preserva a sessão WebRTC e evita pagehide. */
      try{await client.focus()}catch{}
      try{client.postMessage({type:'NAA_DEEP_LINK',url:absolute,contactId:data.contactId||'',messageId:data.messageId||'',kind:data.kind||'',callId,mode:data.mode||'',action})}catch{}
      await refreshNotificationBadge();return;
    }
    if(clients.openWindow)await clients.openWindow(absolute);
    await refreshNotificationBadge();
  })());
});

self.addEventListener('notificationclose',event=>{
  event.waitUntil(refreshNotificationBadge());
});

self.addEventListener('message',event=>{
  const data=event.data||{};
  if(data.type==='SKIP_WAITING'){
    event.waitUntil(self.skipWaiting());
  }

  if(data.type==='CLOSE_CHAT_NOTIFICATIONS'){
    const contactId=String(data.contactId||'');
    event.waitUntil((async()=>{
      const list=await self.registration.getNotifications();
      for(const n of list){
        const d=n.data||{};
        if(!contactId||String(d.contactId||'')===contactId||String(d.url||'').includes('peer='+encodeURIComponent(contactId)))n.close();
      }
      await refreshNotificationBadge();
    })());
  }
  if(data.type==='UPDATE_BADGE'){
    const count=Math.max(0,Number(data.count)||0);
    event.waitUntil((async()=>{try{if(self.navigator&&'setAppBadge'in self.navigator){if(count>0)await self.navigator.setAppBadge(count);else await self.navigator.clearAppBadge();}}catch{}})());
  }
});

self.addEventListener('pushsubscriptionchange',event=>{
  // A renovação autenticada é concluída quando o NAA voltar a ser aberto.
  event.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(list=>{
    for(const client of list)client.postMessage({type:'NAA_PUSH_RESUBSCRIBE'});
  }));
});
