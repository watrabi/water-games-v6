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
