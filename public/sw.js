// Water Games service worker. deliberately small:
// - site assets (/assets/...) are served from cache first, they're versioned with ?t= so stale isn't a worry
// - pages always go to the network. if that fails (offline), the offline page shows instead
// - everything else (api calls, uploads, games, other sites) isn't touched at all
const CACHE = "watr-v1";
const OFFLINE = "/offline";

self.addEventListener("install", function(event) {
    event.waitUntil(caches.open(CACHE).then(cache => cache.addAll([OFFLINE, "/assets/images/BlobEmoji/blobsadrain.png"])).then(() => self.skipWaiting()));
});

self.addEventListener("activate", function(event) {
    event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});

// old ?t= versions pile up after deploys, so keep the newest 200 entries
function trim(cache){
    cache.keys().then(function(keys) {
        keys.slice(0, Math.max(0, keys.length - 200)).forEach(key => cache.delete(key));
    });
}

self.addEventListener("fetch", function(event) {
    let request = event.request;
    if(request.method !== "GET"){
        return;
    }

    let url = new URL(request.url);
    if(url.origin !== location.origin){
        return;
    }

    if(request.mode === "navigate"){
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
        return;
    }

    if(url.pathname.startsWith("/assets/")){
        event.respondWith(caches.open(CACHE).then(function(cache) {
            return cache.match(request).then(function(hit) {
                return hit || fetch(request).then(function(response) {
                    if(response.ok){
                        cache.put(request, response.clone()).then(() => trim(cache));
                    }
                    return response;
                });
            });
        }));
    }
});
