// reactions on the activity feed (home, profiles), "add friend" on suggestions, and sharing a game
// with friends from its page. loaded once in the page head, so everything is delegated or redone on watr:load
(function(){

function el(tag, className, text){
    let node = document.createElement(tag);
    if(className){
        node.className = className;
    }
    if(text !== undefined && text !== null){
        node.textContent = text;
    }
    return node;
}

function api(method, url, data){
    let options = { method: method, headers: {} };
    if(data){
        options.body = new URLSearchParams(data);
    }
    return fetch(url, options).then(function(response) {
        return response.json().catch(() => ({})).then(function(json) {
            if(!response.ok || json.status === "error"){
                throw new Error(json.message || "Something went wrong.");
            }
            return json;
        });
    });
}

// ---------- feed reactions ----------

let bars = {}; // activity id -> reaction bar, for live updates

function hydrateFeed(){
    bars = {};
    document.querySelectorAll(".feedList[data-emoji]").forEach(function(list) {
        let me = list.dataset.me ? Number(list.dataset.me) : null;
        let emoji = JSON.parse(list.dataset.emoji || "[]");

        list.querySelectorAll(".feedReact[data-id]").forEach(function(slot) {
            if(!window.watrReactions || slot.dataset.ready){
                return;
            }
            slot.dataset.ready = "1";
            let id = Number(slot.dataset.id);
            let bar = window.watrReactions.bar(JSON.parse(slot.dataset.reactions || "[]"), me, emoji, function(choice) {
                return api("POST", "/api/v1/social/activity/" + id + "/react", { emoji: choice }).then(r => r.reactions, function(error) {
                    alert(error.message);
                    return null;
                });
            });
            bars[id] = bar;
            slot.append(bar);
        });
    });
}

document.addEventListener("watr:live", function(event) {
    let data = event.detail || {};
    if(data.type === "reaction" && data.kind === "activity" && bars[data.id]){
        bars[data.id].update(data.reactions);
    }
});

// ---------- people you may know ----------

document.addEventListener("click", function(event) {
    let button = event.target.closest("[data-add-friend]");
    if(!button){
        return;
    }

    button.disabled = true;
    api("POST", "/api/v1/social/friends/" + Number(button.dataset.addFriend) + "/request").then(function(data) {
        button.textContent = "Requested";
        button.classList.add("quiet");
    }).catch(function(error) {
        alert(error.message);
        button.disabled = false;
    });
});

// ---------- sharing a game ----------

let share = null; // the open dialog's bits

function shareSetup(){
    let dialog = document.getElementById("shareDialog");
    if(!dialog){
        share = null;
        return;
    }

    share = {
        dialog: dialog,
        list: document.getElementById("shareList"),
        body: document.getElementById("shareBody"),
        error: document.getElementById("shareError"),
        send: document.getElementById("shareSend"),
        game: dialog.dataset.game,
        loaded: false
    };
}

function shareError(text){
    share.error.textContent = text || "";
    share.error.hidden = !text;
}

function shareRow(value, label, meta, picture, icon){
    let row = el("label", "checkRow shareRow");
    let box = el("input");
    box.type = "checkbox";
    box.name = "to";
    box.value = value;

    let face = el("span", "avatar small");
    if(icon){
        face.append(el("i", "ph-bold " + icon));
    } else if(picture){
        let img = el("img");
        img.src = picture;
        img.alt = "";
        face.append(img);
    } else {
        face.textContent = label.charAt(0).toUpperCase();
    }

    let text = el("span", "shareRowText");
    text.append(el("span", "shareName", label));
    if(meta){
        text.append(el("span", "muted", meta));
    }
    row.append(box, face, text);
    return row;
}

function shareLoad(){
    share.list.replaceChildren(el("li", "muted", "Loading…"));
    api("GET", "/api/v1/social/state").then(function(data) {
        share.loaded = true;
        share.list.replaceChildren();

        // online people first, then by name
        let friends = (data.friends || []).slice().sort((a, b) => (b.online - a.online) || a.username.localeCompare(b.username));
        let groups = data.groups || [];

        if(!friends.length && !groups.length){
            share.list.append(el("li", "muted", "Add some friends from the chat tray first, then you can send them games."));
            share.send.disabled = true;
            return;
        }

        groups.forEach(function(group) {
            let item = el("li");
            item.append(shareRow("group:" + group.id, group.name, group.members.length + " people", null, "ph-users-three"));
            share.list.append(item);
        });
        friends.forEach(function(friend) {
            let item = el("li");
            let meta = friend.playing ? "Playing " + friend.playing.name : (friend.online ? "Online" : "");
            item.append(shareRow("dm:" + friend.id, friend.username, meta, friend.avatar));
            share.list.append(item);
        });
        share.send.disabled = false;
    }).catch(function(error) {
        share.list.replaceChildren(el("li", "muted", error.message));
    });
}

function copyLink(button){
    let url = location.origin + location.pathname;
    let done = function() {
        let label = button.querySelector("span") || button;
        let before = label.textContent;
        label.textContent = "Link copied";
        setTimeout(() => { label.textContent = before; }, 2000);
    };

    if(navigator.clipboard){
        navigator.clipboard.writeText(url).then(done, () => prompt("Copy this link:", url));
    } else {
        prompt("Copy this link:", url);
    }
}

document.addEventListener("click", function(event) {
    let target = event.target.closest("#shareButton, #shareCopy, #shareCancel");
    if(!target){
        return;
    }

    if(target.id === "shareCopy" || (target.id === "shareButton" && !document.getElementById("shareDialog"))){
        copyLink(target);
        return;
    }

    if(!share){
        shareSetup();
    }

    if(target.id === "shareCancel"){
        share.dialog.close();
        return;
    }

    shareError("");
    share.body.value = "";
    if(!share.loaded){
        shareLoad();
    } else {
        share.list.querySelectorAll("input:checked").forEach(box => { box.checked = false; });
    }
    share.dialog.showModal();
});

document.addEventListener("change", function(event) {
    if(!share || !event.target.matches("#shareList input[type=checkbox]")){
        return;
    }
    // ten at a time, same as the server
    let checked = share.list.querySelectorAll("input:checked");
    if(checked.length > 10){
        event.target.checked = false;
        shareError("Ten at a time is the most.");
    }
});

document.addEventListener("submit", function(event) {
    if(event.target.id !== "shareForm"){
        return;
    }
    event.preventDefault();

    let to = Array.from(share.list.querySelectorAll("input:checked")).map(box => box.value);
    if(!to.length){
        shareError("Pick at least one friend or group.");
        return;
    }

    shareError("");
    share.send.disabled = true;
    api("POST", "/api/v1/social/share", { game: share.game, to: to.join(","), body: share.body.value }).then(function(data) {
        share.dialog.close();
        let note = document.getElementById("playMsg");
        if(note){
            note.textContent = "Sent to " + (data.sent === 1 ? "1 chat" : data.sent + " chats") + ".";
            note.hidden = false;
            setTimeout(() => { note.hidden = true; }, 4000);
        }
    }).catch(function(error) {
        shareError(error.message);
    }).finally(function() {
        share.send.disabled = false;
    });
});

// ---------- every page ----------

function setup(){
    share = null;
    hydrateFeed();
}

document.addEventListener("watr:load", setup);
setup();

})();
