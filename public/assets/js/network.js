// /network. runs again on every visit (pages load in place), so everything stays inside this function. the expensive
// parts (scripts, service worker, transport) live on window.watrNetwork and are only set up once per tab.
//
// how it's fast:
// - setup starts the moment the page opens, not when you press Go, so by the time you've typed it's done
// - the transport's wisp socket and wasm are warmed with a tiny request to your search engine, and while you type an
//   address that site gets one too, so its DNS, TCP and TLS are already done when you press enter
// - the files come from the node service as brotli with a versioned url, so after the first visit they're never
//   downloaded again
(function(){

if(window.watrNetworkCleanup){
    window.watrNetworkCleanup();
}

let dataEl = document.getElementById("networkData");
if(!dataEl){
    return;
}

let config = {};
try {
    config = JSON.parse(dataEl.textContent);
} catch (e) {}

const V = "?v=" + encodeURIComponent(config.version || "");
const PREFIX = "/network/~/";

// opened addresses are base64url (/network/~/aHR0cHM6Ly9lbi53aWtpcGVkaWEub3Jn) instead of readable
// (/network/~/https%3A%2F%2Fen.wikipedia.org), so filters that read paths don't see the site. Scramjet turns these
// into text and rebuilds them in the service worker and every page, so they can't use anything from out here
const codec = {
    encode: function(url) {
        if(!url){
            return url;
        }
        let bytes = new TextEncoder().encode(url);
        let binary = "";
        for(let i = 0; i < bytes.length; i++){
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
    },
    decode: function(text) {
        if(!text){
            return text;
        }
        // a #part can follow the encoded address (Scramjet adds it unencoded)
        let fragment = "";
        let pound = text.indexOf("#");
        if(pound !== -1){
            fragment = text.slice(pound);
            text = text.slice(0, pound);
        }
        // a form that submits with GET puts its fields after the address, like ?q=cats. they replace the
        // address's own query, the same as on the real site
        let query = null;
        let mark = text.indexOf("?");
        if(mark !== -1){
            query = text.slice(mark);
            text = text.slice(0, mark);
        }
        let url;
        if(/^[A-Za-z0-9_-]+$/.test(text)){
            try {
                let binary = atob(text.replace(/-/g, "+").replace(/_/g, "/"));
                url = new TextDecoder().decode(Uint8Array.from(binary, c => c.charCodeAt(0)));
            } catch (e) {}
        }
        if(url === undefined){
            // the old readable kind, from before the switch (history, bookmarks)
            try {
                url = decodeURIComponent(text);
            } catch (e) {
                url = text;
            }
        }
        if(query !== null){
            let hash = url.indexOf("#");
            let base = hash === -1 ? url : url.slice(0, hash);
            url = base.split("?")[0] + query;
        }
        if(fragment){
            let hash = url.indexOf("#");
            url = (hash === -1 ? url : url.slice(0, hash)) + fragment;
        }
        return url;
    },
};

const engines = {
    ddg: "https://duckduckgo.com/?q=%s",
    google: "https://www.google.com/search?q=%s",
    bing: "https://www.bing.com/search?q=%s",
    brave: "https://search.brave.com/search?q=%s",
};

const transports = {
    fast: "/network/s/fast.mjs" + V,
    compat: "/network/s/compat.mjs" + V,
};

let page = document.getElementById("networkPage");
let home = document.getElementById("networkHome");
let browser = document.getElementById("networkBrowser");
let form = document.getElementById("networkForm");
let input = document.getElementById("networkInput");
let address = document.getElementById("networkAddress");
let frameBox = document.getElementById("networkFrame");
let loading = document.getElementById("networkLoading");
let errorBox = document.getElementById("networkError");
let engineSelect = document.getElementById("networkEngine");
let transportSelect = document.getElementById("networkTransport");

let cleanups = [];
function on(target, type, fn, options){
    target.addEventListener(type, fn, options);
    cleanups.push(() => target.removeEventListener(type, fn, options));
}

window.watrNetworkCleanup = function(){
    cleanups.forEach(fn => fn());
    cleanups = [];
    closeFrame();
    window.watrNetworkCleanup = null;
};

// ---------- settings (just this browser) ----------

let settings = { engine: "ddg", transport: "fast" };
try {
    Object.assign(settings, JSON.parse(localStorage.getItem("wg_network") || "{}"));
} catch (e) {}
if(!engines[settings.engine]){ settings.engine = "ddg"; }
// the setting used to be called by the libraries' names
if(settings.transport === "libcurl"){ settings.transport = "compat"; }
if(!transports[settings.transport]){ settings.transport = "fast"; }
engineSelect.value = settings.engine;
transportSelect.value = settings.transport;

function saveSettings(){
    try {
        localStorage.setItem("wg_network", JSON.stringify(settings));
    } catch (e) {}
}

// ---------- setup, once per tab ----------

let state = window.watrNetwork || (window.watrNetwork = { warmed: new Set() });

function loadScript(src){
    return new Promise(function(resolve, reject) {
        let existing = document.querySelector('script[data-network-src="' + CSS.escape(src) + '"]');
        if(existing){
            return existing.dataset.loaded ? resolve() : existing.addEventListener("load", () => resolve(), { once: true });
        }
        let script = document.createElement("script");
        script.src = src;
        script.dataset.networkSrc = src;
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
        throw new Error(location.protocol === "https:" ? "This browser can't open sites here (no service workers)." : "This only works over https.");
    }

    // all at once: the two scripts download while the service worker installs
    let registering = navigator.serviceWorker.register("/network/sw.js", { scope: "/network/", updateViaCache: "none" });
    await Promise.all([loadScript("/network/s/link.js" + V), loadScript("/network/s/core.js" + V)]);

    if(!state.controller){
        const { ScramjetController } = $scramjetLoadController();
        state.controller = new ScramjetController({
            prefix: PREFIX,
            codec: codec,
            files: {
                // no ?v= on this one: Scramjet compares it to the bare path
                wasm: "/network/s/engine.wasm",
                all: "/network/s/core.js" + V,
                sync: "/network/s/sync.js" + V,
            },
        });
        await repairDb();
        await state.controller.init();
        state.connection = new BareMux.BareMuxConnection("/network/s/link-worker.js" + V);
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

// an older network service worker could leave Scramjet's database without its tables (see the service worker), and
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
        await state.connection.setTransport(transports[settings.transport], [settings.transport === "fast" ? { wisp: wispUrl() } : { websocket: wispUrl() }]);
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
        frame.frame.title = "Opened page";
        frame.frame.setAttribute("allow", "fullscreen; autoplay; clipboard-write; encrypted-media; picture-in-picture; gamepad");
        frame.frame.setAttribute("allowfullscreen", "");
        frame.frame.addEventListener("load", function() {
            setLoading(false);
            try {
                let title = frame.frame.contentDocument.title;
                if(title){
                    document.title = title + " - Network";
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

on(document.getElementById("networkAddressForm"), "submit", function(event) {
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

document.querySelectorAll(".networkLink").forEach(function(link) {
    on(link, "click", () => open(link.dataset.url));
    // the pointer is on its way, so is the connection
    on(link, "pointerenter", () => warm(link.dataset.url));
    on(link, "focus", () => warm(link.dataset.url));
});

on(document.getElementById("networkBack"), "click", () => frame && frame.back());
on(document.getElementById("networkForward"), "click", () => frame && frame.forward());
on(document.getElementById("networkReload"), "click", function() {
    if(frame){
        setLoading(true);
        frame.reload();
    }
});
on(document.getElementById("networkHomeButton"), "click", goHome);
on(document.getElementById("networkNewTab"), "click", function() {
    if(current && state.controller){
        window.open(state.controller.encodeUrl(current), "_blank", "noopener");
    }
});
on(document.getElementById("networkFullscreen"), "click", function() {
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
