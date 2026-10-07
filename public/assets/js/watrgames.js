const mobileQuery = window.matchMedia("(max-width: 800px)");

function readPref(key){
    try { return localStorage.getItem(key); } catch (e) { return null; }
}

function writePref(key, value){
    try { localStorage.setItem(key, value); } catch (e) {}
}

// desktop remembers if you collapsed the sidebar, mobile uses it as a drawer
if(readPref("sidebarClosed") === "1"){
    $("body").addClass("sidebar-closed");
}

$("#sidebarToggle").on("click", function() {
    if(mobileQuery.matches){
        $("body").toggleClass("sidebar-open");
    } else {
        $("body").toggleClass("sidebar-closed");
        writePref("sidebarClosed", $("body").hasClass("sidebar-closed") ? "1" : "0");
    }
});

$("#sidebarScrim").on("click", function() {
    $("body").removeClass("sidebar-open");
});

// user menu
$("#userMenu .navUser").on("click", function(event) {
    event.stopPropagation();
    let menu = $("#userMenu").toggleClass("open");
    $(this).attr("aria-expanded", menu.hasClass("open"));
});

$(document).on("click", function(event) {
    if(!$(event.target).closest("#userMenu").length){
        $("#userMenu").removeClass("open");
        $("#userMenu .navUser").attr("aria-expanded", "false");
    }
});

$(document).on("keydown", function(event) {
    if(event.key === "Escape"){
        $("#userMenu").removeClass("open");
        $("body").removeClass("sidebar-open");
    }
});

// shows a message in a .notice box
function showNotice(element, message, type){
    element.removeClass("danger success").addClass(type || "danger").text(message).prop("hidden", false);
}

// pulls the message out of a failed api call
function apiMessage(xhr){
    if(xhr && xhr.responseJSON && xhr.responseJSON.message){
        return xhr.responseJSON.message;
    }
    return "Something went wrong. Try again in a bit.";
}

function parseEmoji(node){
    if(window.twemoji){
        // twemoji's default cdn (maxcdn) is gone, so point it somewhere that still works
        twemoji.parse(node, {
            base: "https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/",
            folder: "svg",
            ext: ".svg"
        });
    }
}

$(document).ready(function(){
    parseEmoji(document.body);
});

// ---------- themes ----------

function saveTheme(data){
    return $.post("/api/v1/theme", data);
}

$(document).on("change", "#footerTheme", function() {
    saveTheme({ theme: this.value }).done(function() {
        location.reload();
    });
});

$(document).on("click", "#siteBannerClose", function() {
    let banner = $("#siteBanner");
    document.cookie = "wg_dismissed=" + banner.data("id") + "; path=/; max-age=31536000; samesite=lax";
    banner.remove();
});

// falling snow for the christmas theme. a canvas behind nothing, clicks go straight through it
function startSnow(){
    if(window.matchMedia("(prefers-reduced-motion: reduce)").matches){
        return;
    }

    let canvas = document.createElement("canvas");
    canvas.id = "snow";
    canvas.setAttribute("aria-hidden", "true");
    document.body.append(canvas);

    let ctx = canvas.getContext("2d");
    let flakes = [];
    let width, height;

    function resize(){
        let ratio = window.devicePixelRatio || 1;
        width = window.innerWidth;
        height = window.innerHeight;
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    }

    resize();
    window.addEventListener("resize", resize);

    let count = Math.round(Math.min(90, width / 14));
    for(let i = 0; i < count; i++){
        flakes.push({
            x: Math.random() * width,
            y: Math.random() * height,
            r: 1 + Math.random() * 2.2,
            speed: 0.3 + Math.random() * 0.9,
            drift: Math.random() * Math.PI * 2
        });
    }

    function frame(){
        ctx.clearRect(0, 0, width, height);
        ctx.fillStyle = "rgba(255, 255, 255, 0.8)";

        flakes.forEach(function(f) {
            f.y += f.speed;
            f.drift += 0.01;
            f.x += Math.sin(f.drift) * 0.4;

            if(f.y > height + 5){
                f.y = -5;
                f.x = Math.random() * width;
            }

            ctx.beginPath();
            ctx.arc(f.x, f.y, f.r, 0, Math.PI * 2);
            ctx.fill();
        });

        // the canvas goes away when you switch to a theme without snow
        if(!canvas.isConnected){
            return;
        }

        // pause in background tabs, one loop at a time
        if(document.hidden){
            running = false;
        } else {
            requestAnimationFrame(frame);
        }
    }

    let running = true;
    document.addEventListener("visibilitychange", function() {
        if(!document.hidden && !running){
            running = true;
            requestAnimationFrame(frame);
        }
    });

    requestAnimationFrame(frame);
}

if(document.documentElement.dataset.effect === "snow"){
    startSnow();
}

// ---------- in-page navigation ----------
// links and GET forms fetch the next page and swap #main, so the header, sidebar, music player and chat
// tray stay put. anything odd (a different site build, a file, a non-html answer, an error) falls back to a
// normal page load, so the worst case is the old behaviour

const nav = {
    controller: null,
    build: siteBuild(document),
    page: location.pathname + location.search // what's on screen, hashes aside
};

// the base stylesheet's ?t= changes on deploys; if it differs, the new page needs the new assets
function siteBuild(doc){
    let link = doc.querySelector('link[href*="/assets/css/base.css"]');
    return link ? link.getAttribute("href") : "";
}

function canNavigate(url, link){
    if(url.origin !== location.origin){
        return false;
    }
    if(link && ((link.target && link.target !== "_self") || link.hasAttribute("download") || link.hasAttribute("data-reload"))){
        return false;
    }
    // files, api calls and sign out need real requests
    if(/^\/(api|assets|uploads|game-files|music-files|chat\/images|ai\/attachments|auth\/logout)(\/|$)/.test(url.pathname)){
        return false;
    }
    return !/\.[a-z0-9]{2,5}$/i.test(url.pathname);
}

function waitFor(element){
    return new Promise(function(resolve) {
        element.addEventListener("load", resolve, { once: true });
        element.addEventListener("error", resolve, { once: true });
        setTimeout(resolve, 4000);
    });
}

// stylesheets and scripts the new page needs that this one hasn't loaded yet
function loadHead(doc){
    let waits = [];

    doc.head.querySelectorAll('link[rel="stylesheet"]').forEach(function(link) {
        let href = link.getAttribute("href");
        if(!document.head.querySelector('link[rel="stylesheet"][href="' + CSS.escape(href) + '"]')){
            let copy = link.cloneNode();
            document.head.append(copy);
            waits.push(waitFor(copy));
        }
    });

    doc.head.querySelectorAll("script[src]").forEach(function(script) {
        let src = script.getAttribute("src");
        if(!document.head.querySelector('script[src="' + CSS.escape(src) + '"]')){
            let copy = document.createElement("script");
            Array.from(script.attributes).forEach(a => copy.setAttribute(a.name, a.value));
            copy.async = false;
            document.head.append(copy);
            waits.push(waitFor(copy));
        }
    });

    return Promise.all(waits);
}

// scripts inside the new content, run in order (innerHTML alone never runs them)
async function runScripts(container){
    for(let old of Array.from(container.querySelectorAll("script"))){
        let type = old.getAttribute("type");
        if(type && type !== "text/javascript" && type !== "module"){
            continue; // json data blocks and the like
        }

        let script = document.createElement("script");
        Array.from(old.attributes).forEach(function(a) {
            if(a.name !== "defer" && a.name !== "async"){
                script.setAttribute(a.name, a.value);
            }
        });
        script.async = false;

        if(old.src){
            let loaded = waitFor(script);
            old.replaceWith(script);
            await loaded;
        } else {
            script.textContent = old.textContent;
            old.replaceWith(script);
        }
    }
}

function swap(doc){
    let main = document.getElementById("main");
    let newMain = doc.getElementById("main");

    document.title = doc.title;
    main.innerHTML = newMain.innerHTML;

    let sidebar = doc.getElementById("sidebar");
    if(sidebar){
        document.getElementById("sidebar").innerHTML = sidebar.innerHTML;
    }

    let search = doc.getElementById("navSearchInput");
    if(search && document.getElementById("navSearchInput")){
        document.getElementById("navSearchInput").value = search.value;
    }

    // theme (a ?theme= preview link changes it)
    let html = document.documentElement;
    html.dataset.theme = doc.documentElement.dataset.theme || "deep";
    let effect = doc.documentElement.dataset.effect;
    if(effect){
        html.dataset.effect = effect;
    } else {
        delete html.dataset.effect;
    }
    let themeColor = doc.querySelector('meta[name="theme-color"]');
    let ourColor = document.querySelector('meta[name="theme-color"]');
    if(themeColor && ourColor){
        ourColor.setAttribute("content", themeColor.getAttribute("content"));
    }

    // page classes (like the ai page's) come from the new page, the ones scripts set stay
    let keep = ["sidebar-closed", "has-player", "navigating"].filter(c => document.body.classList.contains(c));
    document.body.className = doc.body.className;
    keep.forEach(c => document.body.classList.add(c));

    if(html.dataset.effect === "snow" && !document.getElementById("snow")){
        startSnow();
    } else if(html.dataset.effect !== "snow" && document.getElementById("snow")){
        document.getElementById("snow").remove();
    }
}

async function go(href, options){
    options = options || {};

    if(nav.controller){
        nav.controller.abort();
    }
    let controller = new AbortController();
    nav.controller = controller;

    let slow = setTimeout(() => document.body.classList.add("navigating"), 150);
    let target = new URL(href, location.href);

    try {
        let response = await fetch(target.href, {
            signal: controller.signal,
            credentials: "same-origin",
            headers: { "X-Watr-Nav": "1" }
        });

        if(!(response.headers.get("content-type") || "").includes("text/html")){
            location.href = target.href;
            return;
        }

        let doc = new DOMParser().parseFromString(await response.text(), "text/html");

        if(controller.signal.aborted){
            return;
        }

        if(!doc.getElementById("main") || siteBuild(doc) !== nav.build){
            location.href = response.url || target.href;
            return;
        }

        await loadHead(doc);
        if(controller.signal.aborted){
            return;
        }

        // remember where we were on the page we're leaving, for the back button
        let main = document.getElementById("main");
        history.replaceState(Object.assign({}, history.state, { scroll: main.scrollTop }), "");

        document.dispatchEvent(new CustomEvent("watr:leave"));
        swap(doc);

        // redirects (like /home -> /auth/sign-in) end up at a different url
        let finalUrl = (response.url || target.href).split("#")[0] + target.hash;
        nav.page = new URL(finalUrl).pathname + new URL(finalUrl).search;
        if(options.push){
            history.pushState({ scroll: 0 }, "", finalUrl);
        } else {
            history.replaceState({ scroll: options.scroll || 0 }, "", finalUrl);
        }

        main.scrollTop = options.scroll || 0;
        if(target.hash){
            let anchor = document.getElementById(decodeURIComponent(target.hash.slice(1)));
            if(anchor){
                anchor.scrollIntoView();
            }
        }

        $("body").removeClass("sidebar-open");
        $("#userMenu").removeClass("open");

        parseEmoji(main);
        await runScripts(main);

        if(window.turnstile){
            main.querySelectorAll(".cf-turnstile:empty").forEach(el => turnstile.render(el));
        }

        // move focus to the new content so screen readers start there
        main.setAttribute("tabindex", "-1");
        main.focus({ preventScroll: true });

        document.dispatchEvent(new CustomEvent("watr:load", { detail: { url: finalUrl } }));
    } catch (error) {
        if(error.name !== "AbortError"){
            location.href = target.href;
        }
    } finally {
        clearTimeout(slow);
        if(nav.controller === controller){
            nav.controller = null;
            document.body.classList.remove("navigating");
        }
    }
}

document.addEventListener("click", function(event) {
    if(event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey){
        return;
    }

    let link = event.target.closest("a[href]");
    if(!link){
        return;
    }

    let url = new URL(link.href, location.href);
    if(!canNavigate(url, link)){
        return;
    }

    // a link to somewhere on this same page just scrolls
    if(url.pathname === location.pathname && url.search === location.search && url.hash){
        return;
    }

    event.preventDefault();
    go(url.href, { push: true });
});

// search boxes and filters (GET forms). POST forms, like the admin panel's, still load normally
document.addEventListener("submit", function(event) {
    let form = event.target;
    if(event.defaultPrevented || (form.method || "get").toLowerCase() !== "get" || form.hasAttribute("data-reload")){
        return;
    }

    let url = new URL(form.getAttribute("action") || location.pathname, location.href);
    if(!canNavigate(url, null)){
        return;
    }

    event.preventDefault();
    url.search = new URLSearchParams(new FormData(form, event.submitter)).toString();
    go(url.href, { push: true });
});

window.addEventListener("popstate", function(event) {
    // back/forward between #anchors on the same page: the browser already scrolled
    if(location.pathname + location.search === nav.page){
        return;
    }
    go(location.href, { scroll: event.state ? event.state.scroll : 0 });
});

history.replaceState(Object.assign({ scroll: 0 }, history.state), "");

window.watrNav = {
    go: (href) => go(href, { push: true }),
    reload: () => go(location.href, { scroll: document.getElementById("main").scrollTop })
};
