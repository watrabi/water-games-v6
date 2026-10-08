// the bell in the top bar. loaded once (the header doesn't get swapped between pages), so state lives here
// for the whole visit. new notifications arrive over the chat websocket (chat.js passes them along as
// "watr:live" events), or by polling when that isn't running

const notify = {
    open: false,
    loaded: false,
    unread: Number($("#notifyBadge").text()) || 0,
    timer: null,
    live: false
};

function notifyTimeAgo(time){
    let diff = Math.floor(Date.now() / 1000) - time;
    if(diff < 60) return "just now";
    if(diff < 3600) return Math.floor(diff / 60) + "m ago";
    if(diff < 86400) return Math.floor(diff / 3600) + "h ago";
    if(diff < 604800) return Math.floor(diff / 86400) + "d ago";
    return new Date(time * 1000).toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

function setUnread(count){
    notify.unread = count;
    $("#notifyBadge").text(count > 9 ? "9+" : count).prop("hidden", !count);
    $("#notifyLabel").text(count + " unread notification" + (count === 1 ? "" : "s"));

    // the browser tab shows it too
    let title = document.title.replace(/^\(\d+\+?\) /, "");
    document.title = count ? "(" + (count > 9 ? "9+" : count) + ") " + title : title;
}

function notifyItem(item){
    let li = $("<li>", { class: "notifyItem" + (item.read ? "" : " unread"), "data-id": item.id });

    if(item.actor){
        let avatar = $("<span>", { class: "avatar" });
        if(item.actor.avatar){
            avatar.append($("<img>", { src: item.actor.avatar, alt: "" }));
        } else {
            avatar.text(item.actor.username.charAt(0).toUpperCase());
        }
        li.append(avatar);
    } else {
        li.append($("<span>", { class: "notifyIcon" }).append($("<i>", { class: "ph-bold " + item.icon })));
    }

    let body = $("<div>", { class: "notifyBody" });
    if(item.link){
        body.append($("<a>", { href: item.link, text: item.text, "data-notify-read": item.id }));
    } else {
        body.append($("<p>", { text: item.text }));
    }
    body.append($("<span>", { class: "muted notifyTime", text: notifyTimeAgo(item.created) }));

    return li.append(body);
}

function loadNotifications(){
    return $.getJSON("/api/v1/notifications").done(function(data) {
        notify.loaded = true;
        let list = $("#notifyList").empty();

        if(!data.items.length){
            list.append($("<li>", { class: "notifyEmpty muted", text: "Nothing yet. Friend requests, mentions and achievements show up here." }));
        }
        data.items.forEach(item => list.append(notifyItem(item)));
        setUnread(data.unread);
    });
}

function markRead(id){
    return $.post("/api/v1/notifications/read", id ? { id: id } : {}).done(function() {
        if(id){
            $('.notifyItem[data-id="' + id + '"]').removeClass("unread");
        } else {
            $(".notifyItem").removeClass("unread");
        }
    });
}

function openNotifications(){
    notify.open = true;
    $("#notifyPanel").prop("hidden", false);
    $("#notifyButton").attr("aria-expanded", "true");
    loadNotifications();
}

function closeNotifications(){
    notify.open = false;
    $("#notifyPanel").prop("hidden", true);
    $("#notifyButton").attr("aria-expanded", "false");
}

$("#notifyButton").on("click", function(event) {
    event.stopPropagation();
    $("#userMenu").removeClass("open");
    notify.open ? closeNotifications() : openNotifications();
});

$(document).on("click", function(event) {
    if(notify.open && !$(event.target).closest("#notifyMenu").length){
        closeNotifications();
    }
});

$(document).on("keydown", function(event) {
    if(event.key === "Escape" && notify.open){
        closeNotifications();
        $("#notifyButton").trigger("focus");
    }
});

$("#notifyReadAll").on("click", function() {
    markRead().done(() => setUnread(0));
});

// clicking one marks it read on the way to wherever it links
$(document).on("click", "[data-notify-read]", function() {
    let item = $(this).closest(".notifyItem");
    if(item.hasClass("unread")){
        markRead($(this).data("notify-read"));
        setUnread(Math.max(0, notify.unread - 1));
    }
    closeNotifications();
});

// /notifications page buttons (the page can come and go, so these are delegated)
$(document).on("click", "#notifyPageReadAll", function() {
    markRead().done(() => setUnread(0));
});

$(document).on("click", "#notifyPageMore", function() {
    let button = $(this).prop("disabled", true);
    $.getJSON("/api/v1/notifications", { before: button.data("before") }).done(function(data) {
        data.items.forEach(item => $("#notifyPageList").append(notifyItem(item)));
        if(data.more && data.items.length){
            button.data("before", data.items[data.items.length - 1].id).prop("disabled", false);
        } else {
            button.remove();
        }
    });
});

// pushed from the websocket
document.addEventListener("watr:live", function(event) {
    let data = event.detail || {};
    if(data.type === "hello"){
        notify.live = true;
    }
    if(data.type === "notification"){
        refreshCount();
    }
});

// chat.js's poll includes the count, so without a websocket this still stays fresh
document.addEventListener("watr:poll", function(event) {
    if(event.detail && typeof event.detail.notifications === "number" && event.detail.notifications !== notify.unread){
        setUnread(event.detail.notifications);
        if(notify.open){
            loadNotifications();
        }
    }
});

function refreshCount(){
    $.getJSON("/api/v1/notifications/count").done(function(data) {
        if(data.unread !== notify.unread){
            setUnread(data.unread);
            if(notify.open){
                loadNotifications();
            }
        }
    });
}

// page changes rewrite the title, so put the count back
document.addEventListener("watr:load", () => setUnread(notify.unread));
setUnread(notify.unread);
