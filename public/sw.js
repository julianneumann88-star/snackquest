const VERSION='sq-v1.1.0',CACHE_PREFIX='sq-',STATIC=VERSION+'-static',PUBLIC=VERSION+'-public',SCOPE='/snackquest';
const OFFLINE_SHELL=SCOPE+'/offline.html';
const PRECACHE=[OFFLINE_SHELL,SCOPE+'/assets/css/app.css',SCOPE+'/assets/js/app.js',SCOPE+'/assets/brand/snackquest-mark-256.png',SCOPE+'/assets/icons/favicon-v2-32.png',SCOPE+'/assets/icons/icon-v2-192.png',SCOPE+'/assets/icons/icon-v2-512.png',SCOPE+'/manifest.webmanifest?v=1.1.0'];
const PRIVATE_PATH=/^\/snackquest\/(app|api|auth|login|register|verify|forgot-password|reset-password|media|s)(\/|$)/;
const isSensitive=(url)=>PRIVATE_PATH.test(url.pathname)||['token','code','state'].some(key=>url.searchParams.has(key));
const mayStore=(response)=>{
  if(!response||!response.ok)return false;
  const policy=(response.headers.get('Cache-Control')||'').toLowerCase();
  return !policy.includes('no-store')&&!policy.includes('private');
};

self.addEventListener('install',event=>event.waitUntil(
  caches.open(STATIC).then(cache=>cache.addAll(PRECACHE)).then(()=>self.skipWaiting())
));
self.addEventListener('activate',event=>event.waitUntil(
  caches.keys()
    .then(keys=>Promise.all(keys
      .filter(key=>key.startsWith(CACHE_PREFIX)&&key!==STATIC&&key!==PUBLIC)
      .map(key=>caches.delete(key))))
    .then(()=>self.clients.claim())
));
self.addEventListener('fetch',event=>{
  const request=event.request;
  if(request.method!=='GET')return;
  const url=new URL(request.url);
  if(url.origin!==location.origin||!url.pathname.startsWith(SCOPE+'/'))return;

  if(isSensitive(url)){
    if(request.mode==='navigate')event.respondWith(fetch(request).catch(()=>caches.match(OFFLINE_SHELL)));
    return;
  }

  if(request.destination==='style'||request.destination==='script'||request.destination==='font'||request.destination==='image'||url.pathname.endsWith('.webmanifest')){
    event.respondWith(caches.open(STATIC).then(async cache=>{
      const hit=await cache.match(request);
      const network=fetch(request).then(response=>{
        if(mayStore(response))cache.put(request,response.clone());
        return response;
      }).catch(()=>hit);
      return hit||network;
    }));
    return;
  }

  if(request.mode==='navigate'){
    event.respondWith(fetch(request).then(response=>{
      if(mayStore(response))caches.open(PUBLIC).then(cache=>cache.put(request,response.clone()));
      return response;
    }).catch(()=>caches.match(request).then(hit=>hit||caches.match(OFFLINE_SHELL))));
  }
});
self.addEventListener('message',event=>{if(event.data==='SKIP_WAITING')self.skipWaiting()});
