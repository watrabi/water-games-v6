// keyboard shortcuts for the whole site, plus the "?" list of them. loaded once (it lives in the head),
// page specific keys (F and T on game pages) are in that page's script. also registers the service worker

const shortcutKeys = [
    [["/"], "Search games"],
    [["g", "h"], "Go home"],
    [["g", "g"], "Go to games"],
    [["g", "m"], "Go to music"],
    [["g", "n"], "Go to notifications"],
    [["g", "c"], "Go to collections"],
    [["g", "r"], "Random game"],
    [["g", "p"], "Go to the proxy"],
    [["c"], "Open chat"],
    [["f"], "Fullscreen (on a game)"],
    [["t"], "Theater mode (on a game)"],
    [["Esc"], "Close whatever's open"],
    [["?"], "Show this list"]
];

let shortcutPrefix = null;
let shortcutPrefixTimer = null;

function typingInto(target){
    return /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName) || target.isContentEditable;
}

function shortcutGo(path){
    window.watrNav ? window.watrNav.go(path) : (location.href = path);
}

function shortcutDialog(){
    let dialog = document.getElementById("shortcutDialog");
    if(dialog){
        return dialog;
    }

    dialog = document.createElement("dialog");
    dialog.id = "shortcutDialog";
    dialog.className = "modal";
    dialog.setAttribute("aria-labelledby", "shortcutTitle");

    let title = document.createElement("h2");
    title.id = "shortcutTitle";
    title.textContent = "Keyboard shortcuts";

    let list = document.createElement("dl");
    list.className = "shortcutList";
    shortcutKeys.forEach(function([keys, label]) {
        let dt = document.createElement("dt");
        keys.forEach(function(key, i) {
            if(i){
                dt.append(" then ");
            }
            let kbd = document.createElement("kbd");
            kbd.textContent = key;
            dt.append(kbd);
        });
        let dd = document.createElement("dd");
        dd.textContent = label;
        list.append(dt, dd);
    });

    let actions = document.createElement("div");
    actions.className = "modalActions";
    let close = document.createElement("button");
    close.type = "button";
    close.className = "button";
    close.textContent = "Got it";
    close.addEventListener("click", () => dialog.close());
    actions.append(close);

    dialog.append(title, list, actions);
    dialog.addEventListener("click", function(event) {
        if(event.target === dialog){
            dialog.close(); // clicked the backdrop
        }
    });
    document.body.append(dialog);
    return dialog;
}

document.addEventListener("keydown", function(event) {
    if(event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || typingInto(event.target)){
        return;
    }
    if(document.querySelector("dialog[open]") && event.key !== "Escape"){
        return;
    }

    let key = event.key;

    if(shortcutPrefix === "g"){
        shortcutPrefix = null;
        clearTimeout(shortcutPrefixTimer);
        let path = { h: document.querySelector('#sidebar a[href="/home"]') ? "/home" : "/", g: "/discover", m: "/music", n: "/notifications", c: "/collections", r: "/play/random", p: document.querySelector('#sidebar a[href="/proxy"]') ? "/proxy" : null }[key];
        if(path){
            event.preventDefault();
            shortcutGo(path);
        }
        return;
    }

    if(key === "/"){
        let search = document.getElementById("navSearchInput");
        if(search){
            event.preventDefault();
            search.focus();
            search.select();
        }
    } else if(key === "?"){
        event.preventDefault();
        let dialog = shortcutDialog();
        dialog.open ? dialog.close() : dialog.showModal();
    } else if(key === "g"){
        shortcutPrefix = "g";
        shortcutPrefixTimer = setTimeout(() => shortcutPrefix = null, 1200);
    } else if(key === "c" && window.watrChat && window.watrChat.openPanel){
        event.preventDefault();
        window.watrChat.openPanel();
    }
});

// "/" in the search box blurs it again with Escape, like most sites
document.addEventListener("keydown", function(event) {
    if(event.key === "Escape" && event.target.id === "navSearchInput"){
        event.target.blur();
    }
});

// ---------- installable app ----------
// the worker only caches the look of the site (css, js, fonts, blobs) and an offline page. pages and the
// api always come from the network, so nobody ever sees a stale page or someone else's data

if("serviceWorker" in navigator && (location.protocol === "https:" || location.hostname === "localhost")){
    window.addEventListener("load", function() {
        navigator.serviceWorker.register("/sw.js").catch(function() {});
    });
}
