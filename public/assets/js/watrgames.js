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

$(document).ready(function(){
    if(window.twemoji){
        // twemoji's default cdn (maxcdn) is gone, so point it somewhere that still works
        twemoji.parse(document.body, {
            base: "https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/",
            folder: "svg",
            ext: ".svg"
        });
    }
});

// ---------- themes ----------

function saveTheme(data){
    return $.post("/api/v1/theme", data);
}

$("#footerTheme").on("change", function() {
    saveTheme({ theme: this.value }).done(function() {
        location.reload();
    });
});

$("#siteBannerClose").on("click", function() {
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
