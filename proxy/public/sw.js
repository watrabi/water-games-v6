// the proxy's service worker, scoped to /proxy/ (the site's own /sw.js keeps everything else).
// every request a proxied page makes comes through here, and Scramjet rewrites it.
// __VERSION__ is filled in by server.js, so the big script below can be cached forever
importScripts("/proxy/s/scram/scramjet.all.js?v=__VERSION__");

// Scramjet's worker opens its database the moment it starts, without making the tables (only the page's
// controller.init() makes them). when the worker gets there first (a slow chromebook does) the database is left
// empty at version 1, and init() then fails with "One of the specified object stores was not found" forever.
// so whoever opens it here makes the tables, and lets go when the page needs to delete a broken one
const STORES = ["config", "cookies", "redirectTrackers", "referrerPolicies", "publicSuffixList"];
const openDb = indexedDB.open.bind(indexedDB);
indexedDB.open = function(name, version) {
    let request = version === undefined ? openDb(name) : openDb(name, version);
    if(name === "$scramjet"){
        request.addEventListener("upgradeneeded", function() {
            for(const store of STORES){
                if(!request.result.objectStoreNames.contains(store)){
                    request.result.createObjectStore(store);
                }
            }
        });
        request.addEventListener("success", function() {
            request.result.addEventListener("versionchange", () => request.result.close());
        });
    }
    return request;
};

const { ScramjetServiceWorker } = $scramjetLoadWorker();
const scramjet = new ScramjetServiceWorker();

// when a script, stylesheet or image sets a cookie, Scramjet tells the page and waits for it to answer before
// handing back the response. a page that's still being parsed doesn't get messages from here until it finishes, and
// it can't finish without that response, so any site that sets cookies on its assets (wikipedia, most big ones)
// froze half loaded. our copy of the cookie is saved before the response goes back either way, so tell the page
// without waiting; it catches up as soon as it can read messages
const dispatch = scramjet.dispatch.bind(scramjet);
scramjet.dispatch = function(client, message) {
    let reply = dispatch(client, message);
    return message && message.scramjet$type === "cookie" ? Promise.resolve() : reply;
};

// take over straight away, so the first page someone opens is already proxied instead of needing a reload
self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", event => event.waitUntil(self.clients.claim()));

// Scramjet's database is made by the page (ScramjetController.init). opening it here first would make an empty one
// it can't upgrade, so wait until it exists
let haveDb = false;
async function dbExists(){
    if(!haveDb){
        haveDb = (await indexedDB.databases()).some(db => db.name === "$scramjet");
    }
    return haveDb;
}

async function handle(event){
    if(await dbExists()){
        await scramjet.loadConfig();
        if(scramjet.config && scramjet.route(event)){
            return scramjet.fetch(event);
        }
    }
    // no config yet means /proxy hasn't been opened in this browser: the boot page explains
    return fetch(event.request);
}

// every proxied page loads the rewriter with <script src="...wasm">, and gets back a script that sets
// self.WASM to the wasm as base64. Scramjet builds that ~700KB string again for every page, one character at a
// time. it never changes, so build it once and hand out the same text
const WASM = "/proxy/s/scram/scramjet.wasm.wasm";
let wasmScript = null;
function rewriterScript(){
    if(!wasmScript){
        wasmScript = fetch(WASM).then(r => r.arrayBuffer()).then(function(buffer) {
            let bytes = new Uint8Array(buffer);
            let binary = "";
            for(let i = 0; i < bytes.length; i += 0x8000){
                binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
            }
            return "if ('document' in self && document.currentScript) { document.currentScript.remove(); }\nself.WASM = '" + btoa(binary) + "';";
        });
        wasmScript.catch(() => { wasmScript = null; });
    }
    return wasmScript.then(text => new Response(text, { headers: { "Content-Type": "text/javascript" } }));
}

self.addEventListener("fetch", function(event) {
    // only proxied addresses and the rewriter. the proxy's own files (and the bare-mux worker, which lives under
    // /proxy/ too) go straight to the network without a detour through here
    let path = new URL(event.request.url).pathname;
    if(path.startsWith("/proxy/~/")){
        event.respondWith(handle(event));
    } else if(path === WASM && event.request.destination === "script"){
        event.respondWith(rewriterScript());
    }
});
