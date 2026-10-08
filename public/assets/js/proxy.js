// /network. runs again on every visit (pages load in place), so everything stays inside this function. the expensive
// parts (scripts, service worker, transport) live on window.watrProxy and are only set up once per tab.
//
// how it's fast:
// - setup starts the moment the page opens, not when you press Go, so by the time you've typed it's done
// - the transport's wisp socket and wasm are warmed with a tiny request to your search engine, and while you type an
//   address that site gets one too, so its DNS, TCP and TLS are already done when you press enter
// - the files come from proxy/server.js as brotli with a versioned url, so after the first visit they're never
//   downloaded again
(function(){

if(window.watrProxyCleanup){
    window.watrProxyCleanup();
}

let dataEl = document.getElementById("proxyData");
if(!dataEl){
    return;
}

let config = {};
try {
    config = JSON.parse(dataEl.textContent);
} catch (e) {}

const V = "?v=" + encodeURIComponent(config.version || "");
const PREFIX = "/network/~/";

const engines = {
    ddg: "https://duckduckgo.com/?q=%s",
    google: "https://www.google.com/search?q=%s",
    bing: "https://www.bing.com/search?q=%s",
    brave: "https://search.brave.com/search?q=%s",
};

const transports = {
    epoxy: "/network/s/epoxy/index.mjs" + V,
    libcurl: "/network/s/libcurl/index.mjs" + V,
};

let page = document.getElementById("proxyPage");
let home = document.getElementById("proxyHome");
let browser = document.getElementById("proxyBrowser");
let form = document.getElementById("proxyForm");
let input = document.getElementById("proxyInput");
let address = document.getElementById("proxyAddress");
let frameBox = document.getElementById("proxyFrame");
let loading = document.getElementById("proxyLoading");
let errorBox = document.getElementById("proxyError");
let engineSelect = document.getElementById("proxyEngine");
let transportSelect = document.getElementById("proxyTransport");

let cleanups = [];
function on(target, type, fn, options){
    target.addEventListener(type, fn, options);
    cleanups.push(() => target.removeEventListener(type, fn, options));
}

window.watrProxyCleanup = function(){
    cleanups.forEach(fn => fn());
    cleanups = [];
    closeFrame();
    window.watrProxyCleanup = null;
};

// ---------- settings (just this browser) ----------

let settings = { engine: "ddg", transport: "epoxy" };
try {
    Object.assign(settings, JSON.parse(localStorage.getItem("wg_proxy") || "{}"));
} catch (e) {}
if(!engines[settings.engine]){ settings.engine = "ddg"; }
if(!transports[settings.transport]){ settings.transport = "epoxy"; }
engineSelect.value = settings.engine;
transportSelect.value = settings.transport;

function saveSettings(){
    try {
        localStorage.setItem("wg_proxy", JSON.stringify(settings));
    } catch (e) {}
}

// ---------- setup, once per tab ----------

let state = window.watrProxy || (window.watrProxy = { warmed: new Set() });

function loadScript(src){
    return new Promise(function(resolve, reject) {
        let existing = document.querySelector('script[data-proxy-src="' + CSS.escape(src) + '"]');
        if(existing){
            return existing.dataset.loaded ? resolve() : existing.addEventListener("load", () => resolve(), { once: true });
        }
        let script = document.createElement("script");
        script.src = src;
        script.dataset.proxySrc = src;
        script.onload = function() { script.dataset.loaded = "1"; resolve(); };
        script.onerror = () => reject(new Error("couldn't load " + src));
        document.head.appendChild(script);
    });
}

function wispUrl(){
    return (location.protocol === "https:" ? "wss://" : "ws://") + location.host + config.wisp;
}

async function setup(){
    if(!navigator.serviceWorker){
        throw new Error(location.protocol === "https:" ? "This browser can't run the proxy (no service workers)." : "The proxy only works over https.");
    }

    // all at once: the two scripts download while the service worker installs
    let registering = navigator.serviceWorker.register("/network/sw.js", { scope: "/network/", updateViaCache: "none" });
    await Promise.all([loadScript("/network/s/baremux/index.js" + V), loadScript("/network/s/scram/scramjet.all.js" + V)]);

    if(!state.controller){
        const { ScramjetController } = $scramjetLoadController();
        state.controller = new ScramjetController({
            prefix: PREFIX,
            files: {
                // no ?v= on this one: Scramjet compares it to the bare path
                wasm: "/network/s/scram/scramjet.wasm.wasm",
                all: "/network/s/scram/scramjet.all.js" + V,
                sync: "/network/s/scram/scramjet.sync.js" + V,
            },
        });
        await repairDb();
        await state.controller.init();
        state.connection = new BareMux.BareMuxConnection("/network/s/baremux/worker.js" + V);
    }

    // a fresh token each visit (they last 12 hours), and whichever transport is picked
    await setTransport();

    // the first page needs the worker running, not just installed
    let registration = await registering;
    if(!registration.active){
        await new Promise(function(resolve) {
            let worker = registration.installing || registration.waiting;
            worker.addEventListener("statechange", () => worker.state === "activated" && resolve());
        });
    }
}

// an older proxy service worker could leave Scramjet's database without its tables (see proxy/public/sw.js), and
// init() can't fix that. delete it so init() makes it again. the new worker lets go of it when asked, the old one
// when it's replaced, so give it a few seconds
const STORES = ["config", "cookies", "redirectTrackers", "referrerPolicies", "publicSuffixList"];
async function repairDb(){
    if(!indexedDB.databases || !(await indexedDB.databases()).some(db => db.name === "$scramjet")){
        return;
    }
    let broken = await new Promise(function(resolve) {
        let request = indexedDB.open("$scramjet");
        request.onsuccess = function() {
            let db = request.result;
            let missing = STORES.some(store => !db.objectStoreNames.contains(store));
            db.close();
            resolve(missing);
        };
        request.onerror = () => resolve(false);
    });
    if(broken){
        await new Promise(function(resolve) {
            let request = indexedDB.deleteDatabase("$scramjet");
            request.onsuccess = request.onerror = resolve;
            setTimeout(resolve, 10000);
        });
    }
}

async function setTransport(){
    let key = settings.transport + "|" + config.wisp;
    if(state.transportKey !== key){
        await state.connection.setTransport(transports[settings.transport], [settings.transport === "epoxy" ? { wisp: wispUrl() } : { websocket: wispUrl() }]);
        state.transportKey = key;
        state.warmed.clear();
    }
}

let ready = setup();
ready.catch(showError);

// opens the wisp socket and the TLS connection to a site before it's needed. HEAD changes nothing on the site
function warm(url){
    let origin;
    try {
        origin = new URL(url).origin;
    } catch (e) {
        return;
    }
    if(state.warmed.has(origin) || state.warmed.size > 20){
        return;
    }
    state.warmed.add(origin);
    ready.then(() => new BareMux.BareClient().fetch(origin + "/", { method: "HEAD", redirect: "manual" })).catch(() => {});
}

ready.then(() => warm(engines[settings.engine].replace("%s", "")));

// ---------- what was typed -> a url ----------

function toUrl(text){
    text = text.trim();
    if(!text){
        return null;
    }
    if(/^https?:\/\//i.test(text)){
        return text;
    }
    // something.tld, localhost-free, no spaces: an address
    if(!/\s/.test(text) && /^[^/?#]+\.[a-z]{2,}(:\d+)?([/?#].*)?$/i.test(text)){
        return "https://" + text;
    }
    return engines[settings.engine].replace("%s", encodeURIComponent(text));
}

function looksLikeAddress(text){
    let url = toUrl(text);
    return url && !url.startsWith(engines[settings.engine].split("%s")[0]) ? url : null;
}

// ---------- the browser ----------

let frame = null;
let current = "";
const homeTitle = document.title;

function showError(error){
    errorBox.textContent = (error && error.message) || String(error);
    errorBox.hidden = false;
}

function setLoading(on){
    loading.hidden = !on;
}

async function open(text){
    let url = toUrl(text);
    if(!url){
        return;
    }
    errorBox.hidden = true;

    home.hidden = true;
    browser.hidden = false;
    page.classList.add("browsing");
    address.value = url;
    setLoading(true);

    try {
        await ready;
    } catch (error) {
        setLoading(false);
        return showError(error);
    }

    if(!frame){
        frame = state.controller.createFrame();
        frame.frame.title = "Proxied page";
        frame.frame.setAttribute("allow", "fullscreen; autoplay; clipboard-write; encrypted-media; picture-in-picture; gamepad");
        frame.frame.setAttribute("allowfullscreen", "");
        frame.frame.addEventListener("load", function() {
            setLoading(false);
            try {
                let title = frame.frame.contentDocument.title;
                if(title){
                    document.title = title + " - Proxy";
                }
            } catch (e) {}
        });
        frame.addEventListener("urlchange", function(event) {
            current = event.url;
            if(document.activeElement !== address){
                address.value = current;
            }
        });
        frame.addEventListener("navigate", () => setLoading(true));
        frameBox.appendChild(frame.frame);
    }

    current = url;
    frame.go(url);
}

function closeFrame(){
    if(frame){
        frame.frame.remove();
        frame = null;
    }
    current = "";
    setLoading(false);
    if(document.fullscreenElement){
        document.exitFullscreen().catch(() => {});
    }
}

function goHome(){
    closeFrame();
    browser.hidden = true;
    home.hidden = false;
    page.classList.remove("browsing");
    document.title = homeTitle;
    input.focus();
}

on(form, "submit", function(event) {
    event.preventDefault();
    open(input.value);
});

on(document.getElementById("proxyAddressForm"), "submit", function(event) {
    event.preventDefault();
    address.blur();
    open(address.value);
});

// preconnect to an address once typing pauses
let typingTimer = null;
on(input, "input", function() {
    clearTimeout(typingTimer);
    typingTimer = setTimeout(function() {
        let url = looksLikeAddress(input.value);
        if(url){
            warm(url);
        }
    }, 350);
});
cleanups.push(() => clearTimeout(typingTimer));

document.querySelectorAll(".proxyLink").forEach(function(link) {
    on(link, "click", () => open(link.dataset.url));
    // the pointer is on its way, so is the connection
    on(link, "pointerenter", () => warm(link.dataset.url));
    on(link, "focus", () => warm(link.dataset.url));
});

on(document.getElementById("proxyBack"), "click", () => frame && frame.back());
on(document.getElementById("proxyForward"), "click", () => frame && frame.forward());
on(document.getElementById("proxyReload"), "click", function() {
    if(frame){
        setLoading(true);
        frame.reload();
    }
});
on(document.getElementById("proxyHomeButton"), "click", goHome);
on(document.getElementById("proxyNewTab"), "click", function() {
    if(current && state.controller){
        window.open(state.controller.encodeUrl(current), "_blank", "noopener");
    }
});
on(document.getElementById("proxyFullscreen"), "click", function() {
    if(document.fullscreenElement){
        document.exitFullscreen();
    } else if(frameBox.requestFullscreen){
        frameBox.requestFullscreen();
    }
});

on(address, "focus", () => address.select());

on(engineSelect, "change", function() {
    settings.engine = engineSelect.value;
    saveSettings();
    warm(engines[settings.engine].replace("%s", ""));
});

on(transportSelect, "change", function() {
    settings.transport = transportSelect.value;
    saveSettings();
    ready = ready.catch(() => {}).then(setup);
    ready.catch(showError);
});

if(config.start){
    open(config.start);
}

})();
