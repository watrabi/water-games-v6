// friends + chat tray (bottom left). messages come in by polling: fast while a chat
// is open, slower otherwise, and barely at all in a background tab

(function(){

const tray = {
    panel: document.getElementById("chatPanel"),
    toggle: document.getElementById("chatToggle"),
    badge: document.getElementById("chatBadge"),
    home: document.getElementById("chatHome"),
    convoView: document.getElementById("chatConvo"),
    messages: document.getElementById("chatMessages"),
    input: document.getElementById("chatInput"),
    report: document.getElementById("chatReport")
};

let state = {
    me: null,
    friends: {},        // id -> {id, username, online, lastSeen, unread}
    order: [],          // friend ids in display order
    requests: { incoming: [], outgoing: [] },
    blocked: [],
    reasons: {},
    lastId: 0,
    groups: {},         // id -> {id, name, owner, members, unread, lastMessage}
    groupOrder: [],
    lastGroupId: 0,
    kind: "dm",         // what's open: "dm" (convo is a friend id) or "group" (convo is a group id)
    convo: null,        // id of the friend (or group) whose chat is open
    convoIds: new Set(),
    oldestId: null,
    hasMore: false,
    seen: {},
    loaded: false,
    attachment: null,   // {id, url, uploading}
    pollTimer: null,
    lastRequests: 0,
    lastStateLoad: 0,
    realtime: null,     // {url, token} when the websocket server is set up
    live: false         // websocket connected
};

// ---------- helpers ----------

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
    if(data instanceof FormData){
        options.body = data;
    } else if(data){
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

function remember(){
    try {
        sessionStorage.setItem("wgChat", JSON.stringify({ open: !tray.panel.hidden, convo: state.convo, kind: state.kind }));
    } catch (e) {}
}

function recall(){
    try { return JSON.parse(sessionStorage.getItem("wgChat")) || {}; } catch (e) { return {}; }
}

function timeAgo(seconds){
    let diff = Math.floor(Date.now() / 1000) - seconds;
    if(diff < 60) return "just now";
    if(diff < 3600) return Math.floor(diff / 60) + "m ago";
    if(diff < 86400) return Math.floor(diff / 3600) + "h ago";
    return Math.floor(diff / 86400) + "d ago";
}

function clock(seconds){
    let date = new Date(seconds * 1000);
    let today = new Date();
    let time = date.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
    if(date.toDateString() === today.toDateString()){
        return time;
    }
    return date.toLocaleDateString([], { month: "short", day: "numeric" }) + ", " + time;
}

// profile picture if they have one, otherwise their first letter
function avatar(name, online, picture){
    let wrap = el("span", "chatAvatar", picture ? "" : name.charAt(0).toUpperCase());
    if(picture){
        let img = el("img");
        img.src = picture;
        img.alt = "";
        wrap.append(img);
    }
    if(online !== undefined){
        wrap.append(el("span", "chatDot" + (online ? " on" : "")));
    }
    return wrap;
}

function button(label, className, onClick){
    let b = el("button", className || "chatSmall", label);
    b.type = "button";
    b.addEventListener("click", onClick);
    return b;
}

// ---------- badge ----------

function updateBadge(){
    let unread = Object.values(state.friends).reduce((sum, f) => sum + (f.unread || 0), 0)
        + Object.values(state.groups).reduce((sum, g) => sum + (g.unread || 0), 0);
    let total = unread + state.requests.incoming.length;
    tray.badge.hidden = total === 0;
    tray.badge.textContent = total > 99 ? "99+" : total;
    tray.toggle.setAttribute("aria-label", "Chat" + (total ? ", " + total + " new" : ""));
}

// ---------- home: requests, friends, search ----------

// "Playing Slope", "Online", "Seen 5m ago"
function friendStatus(friend){
    if(friend.playing){
        return "Playing " + friend.playing.name;
    }
    return friend.online ? "Online" : (friend.lastSeen ? "Seen " + timeAgo(friend.lastSeen) : "Offline");
}

function groupAvatar(group){
    let wrap = el("span", "chatAvatar chatGroupAvatar");
    wrap.append(el("i", "ph-bold ph-users-three"));
    return wrap;
}

function renderGroups(){
    let list = document.getElementById("chatGroups");
    list.replaceChildren();

    if(!state.groupOrder.length){
        return;
    }

    list.append(el("h4", "chatHeading", "Groups"));
    state.groupOrder.forEach(function(id) {
        let group = state.groups[id];
        let row = el("button", "chatRow chatFriend" + (group.unread ? " unread" : ""));
        row.type = "button";
        row.append(groupAvatar(group));

        let text = el("span", "chatRowText");
        text.append(el("span", "chatName", group.name));
        text.append(el("span", "chatMeta", group.members.map(m => m.username).filter(n => n !== state.me.username).join(", ")));
        row.append(text);

        if(group.unread){
            row.append(el("span", "chatCount", group.unread > 99 ? "99+" : group.unread));
        }

        row.addEventListener("click", () => openGroup(id));
        list.append(row);
    });
}

function renderHome(){
    let requests = document.getElementById("chatRequests");
    requests.replaceChildren();

    if(state.requests.incoming.length){
        requests.append(el("h4", "chatHeading", "Friend requests"));
        state.requests.incoming.forEach(function(person) {
            let row = el("div", "chatRow");
            row.append(avatar(person.username, undefined, person.avatar), el("span", "chatName", person.username));
            let actions = el("span", "chatRowActions");
            actions.append(
                button("Accept", "chatSmall primary", () => friendAction(person.id, "accept")),
                button("Decline", "chatSmall", () => friendAction(person.id, "decline"))
            );
            row.append(actions);
            requests.append(row);
        });
    }

    let list = document.getElementById("chatFriends");
    list.replaceChildren();

    renderGroups();

    let online = state.order.filter(id => state.friends[id].online).length;
    let heading = el("h4", "chatHeading", "Friends" + (state.order.length ? " · " + online + " online" : ""));
    if(state.order.length >= 2){
        heading.append(button("New group", "chatSmall chatHeadingAction", () => openPicker("create")));
    }
    list.append(heading);

    if(!state.order.length){
        let empty = el("p", "chatEmpty", "No friends yet. Search for someone's username above to send a request.");
        list.append(empty);
    }

    state.order.forEach(function(id) {
        let friend = state.friends[id];
        let row = el("button", "chatRow chatFriend" + (friend.unread ? " unread" : ""));
        row.type = "button";
        row.append(avatar(friend.username, friend.online, friend.avatar));

        let text = el("span", "chatRowText");
        text.append(el("span", "chatName", friend.username));
        text.append(el("span", "chatMeta" + (friend.playing ? " playing" : ""), friendStatus(friend)));
        row.append(text);

        if(friend.unread){
            row.append(el("span", "chatCount", friend.unread > 99 ? "99+" : friend.unread));
        }

        row.addEventListener("click", () => openConvo(id));
        list.append(row);
    });

    if(state.requests.outgoing.length){
        let pending = el("details", "chatPending");
        pending.append(el("summary", "", state.requests.outgoing.length + " request" + (state.requests.outgoing.length === 1 ? "" : "s") + " you sent"));
        state.requests.outgoing.forEach(function(person) {
            let row = el("div", "chatRow");
            row.append(avatar(person.username, undefined, person.avatar), el("span", "chatName", person.username));
            let actions = el("span", "chatRowActions");
            actions.append(button("Cancel", "chatSmall", () => friendAction(person.id, "cancel")));
            row.append(actions);
            pending.append(row);
        });
        list.append(pending);
    }

    if(state.blocked.length){
        let blocked = el("details", "chatPending");
        blocked.append(el("summary", "", state.blocked.length + " blocked"));
        state.blocked.forEach(function(person) {
            let row = el("div", "chatRow");
            row.append(avatar(person.username, undefined, person.avatar), el("span", "chatName", person.username));
            let actions = el("span", "chatRowActions");
            actions.append(button("Unblock", "chatSmall", () => friendAction(person.id, "unblock")));
            row.append(actions);
            blocked.append(row);
        });
        list.append(blocked);
    }

    updateBadge();
}

function applyState(data){
    state.me = data.me;
    state.reasons = data.reasons;
    state.requests = data.requests;
    state.blocked = data.blocked;
    state.lastRequests = data.requests.incoming.length;
    state.friends = {};
    state.order = [];
    data.friends.forEach(function(friend) {
        state.friends[friend.id] = friend;
        state.order.push(friend.id);
    });
    applyGroups(data.groups || []);
    if(!state.loaded){
        state.lastId = data.lastId;
        state.lastGroupId = data.lastGroupId || 0;
    }
    state.loaded = true;
    state.lastStateLoad = Date.now();
    state.realtime = data.realtime || null;
    renderHome();
    connect();

    if(state.convo && (state.kind === "group" ? state.groups[state.convo] : state.friends[state.convo])){
        updateConvoHeader();
    }

    // you were removed from the group that's open
    if(state.kind === "group" && state.convo && !state.groups[state.convo]){
        showHome();
    }
}

function applyGroups(groups){
    state.groups = {};
    state.groupOrder = [];
    groups.forEach(function(group) {
        state.groups[group.id] = group;
        state.groupOrder.push(group.id);
    });
}

function loadState(){
    return api("GET", "/api/v1/social/state").then(applyState);
}

function friendAction(id, action){
    return api("POST", "/api/v1/social/friends/" + id + "/" + action).then(function(data) {
        return loadState().then(() => data);
    }).catch(function(error) {
        alert(error.message);
    });
}

let searchTimer = null;
document.getElementById("chatSearch").addEventListener("input", function() {
    clearTimeout(searchTimer);
    let query = this.value.trim();
    let box = document.getElementById("chatResults");

    if(query.length < 2){
        box.hidden = true;
        return;
    }

    searchTimer = setTimeout(function() {
        api("GET", "/api/v1/social/search?q=" + encodeURIComponent(query)).then(function(data) {
            box.replaceChildren();
            box.hidden = false;

            if(!data.results.length){
                box.append(el("p", "chatEmpty", "Nobody with a username starting with “" + query + "”."));
                return;
            }

            data.results.forEach(function(person) {
                let row = el("div", "chatRow");
                let link = el("a", "chatName", person.username);
                link.href = "/users/" + encodeURIComponent(person.username.toLowerCase());
                row.append(avatar(person.username, undefined, person.avatar), link);

                let actions = el("span", "chatRowActions");
                let refresh = () => document.getElementById("chatSearch").dispatchEvent(new Event("input"));

                switch(person.relation){
                    case "friends":
                        actions.append(button("Message", "chatSmall primary", () => { box.hidden = true; openConvo(person.id); }));
                        break;
                    case "outgoing":
                        actions.append(el("span", "chatMeta", "Requested"));
                        break;
                    case "incoming":
                        actions.append(button("Accept", "chatSmall primary", () => friendAction(person.id, "accept").then(refresh)));
                        break;
                    case "blocked":
                        actions.append(button("Unblock", "chatSmall", () => friendAction(person.id, "unblock").then(refresh)));
                        break;
                    default:
                        actions.append(button("Add", "chatSmall primary", () => friendAction(person.id, "request").then(refresh)));
                }

                row.append(actions);
                box.append(row);
            });
        }).catch(function() {});
    }, 250);
});

document.getElementById("chatSearchForm").addEventListener("submit", e => e.preventDefault());

// ---------- conversation ----------

function updateConvoHeader(){
    if(state.kind === "group"){
        let group = state.groups[state.convo];
        document.getElementById("chatTitle").textContent = group ? group.name : "Group";
        document.getElementById("chatSub").textContent = group ? group.members.length + " people" : "";
        return;
    }

    let friend = state.friends[state.convo];
    let name = friend ? friend.username : (state.convoName || "Chat");
    document.getElementById("chatTitle").textContent = name;
    document.getElementById("chatSub").textContent = friend ? friendStatus(friend) : "";
    document.getElementById("chatProfileLink").href = "/users/" + encodeURIComponent(name.toLowerCase());
}

function showView(view){
    [tray.home, tray.convoView, document.getElementById("chatPicker"), document.getElementById("chatMembers")].forEach(v => v.hidden = v !== view);
}

function showHome(){
    state.convo = null;
    state.kind = "dm";
    showView(tray.home);
    document.getElementById("chatBack").hidden = true;
    document.getElementById("chatMenu").hidden = true;
    document.getElementById("chatTitle").textContent = "Friends";
    document.getElementById("chatSub").textContent = "";
    closeReport();
    renderHome();
    remember();
}

function openConvo(id, name){
    id = Number(id);

    // clicked "Message" on a profile before the tray finished loading
    if(!state.loaded){
        loadState().then(() => openConvo(id, name));
        return;
    }

    openPanel();

    state.kind = "dm";
    state.convo = id;
    state.convoName = name || (state.friends[id] && state.friends[id].username);
    document.getElementById("chatDmMenu").hidden = false;
    document.getElementById("chatGroupMenu").hidden = true;
    state.convoIds = new Set();
    state.oldestId = null;
    state.hasMore = false;

    showView(tray.convoView);
    document.getElementById("chatBack").hidden = false;
    document.getElementById("chatMenu").hidden = false;
    document.getElementById("chatMenu").open = false;
    tray.messages.replaceChildren(el("p", "chatEmpty", "Loading..."));
    document.getElementById("chatSub").classList.remove("typing");
    updateConvoHeader();
    clearAttachment();
    remember();

    api("GET", "/api/v1/social/messages/" + id).then(function(data) {
        if(state.convo !== id || state.kind !== "dm"){
            return;
        }

        tray.messages.replaceChildren();
        setComposer(data.relation);

        if(!data.messages.length){
            tray.messages.append(el("p", "chatEmpty chatStart", data.relation === "friends" ? "This is the start of your chat with " + state.convoName + ". Say hi!" : "No messages."));
        }

        state.hasMore = data.more;
        addMessages(data.messages, false);
        scrollToEnd();

        if(state.friends[id]){
            state.friends[id].unread = 0;
            updateBadge();
        }
        schedulePoll(0);
    }).catch(function(error) {
        tray.messages.replaceChildren(el("p", "chatEmpty", error.message));
    });

    if(!touchInput()){
        tray.input.focus();
    }
}

function openGroup(id){
    id = Number(id);

    if(!state.loaded){
        loadState().then(() => openGroup(id));
        return;
    }
    if(!state.groups[id]){
        showHome();
        return;
    }

    openPanel();

    state.kind = "group";
    state.convo = id;
    state.convoName = state.groups[id].name;
    state.convoIds = new Set();
    state.oldestId = null;
    state.hasMore = false;

    showView(tray.convoView);
    document.getElementById("chatBack").hidden = false;
    document.getElementById("chatMenu").hidden = false;
    document.getElementById("chatMenu").open = false;
    document.getElementById("chatDmMenu").hidden = true;
    document.getElementById("chatGroupMenu").hidden = false;
    tray.messages.replaceChildren(el("p", "chatEmpty", "Loading..."));
    document.getElementById("chatSub").classList.remove("typing");
    updateConvoHeader();
    clearAttachment();
    setComposer("friends");
    remember();

    api("GET", "/api/v1/social/groups/" + id + "/messages").then(function(data) {
        if(state.convo !== id || state.kind !== "group"){
            return;
        }

        tray.messages.replaceChildren();
        state.hasMore = data.more;
        addMessages(data.messages, false);
        scrollToEnd();

        state.groups[id].unread = 0;
        updateBadge();
    }).catch(function(error) {
        tray.messages.replaceChildren(el("p", "chatEmpty", error.message));
    });

    if(!touchInput()){
        tray.input.focus();
    }
}

function setComposer(relation){
    let notice = document.getElementById("chatNotice");
    let composer = document.getElementById("chatComposer");
    let canSend = relation === "friends";
    composer.hidden = !canSend;
    notice.hidden = canSend;
    notice.textContent = {
        blocked: "You blocked this person.",
        blockedby: "You can't message this person.",
        none: "You're not friends anymore, so you can't send new messages.",
        outgoing: "You're not friends anymore, so you can't send new messages.",
        incoming: "You're not friends anymore, so you can't send new messages."
    }[relation] || "";
}

function touchInput(){
    return window.matchMedia("(pointer: coarse)").matches;
}

function nearBottom(){
    return tray.messages.scrollHeight - tray.messages.scrollTop - tray.messages.clientHeight < 80;
}

function scrollToEnd(){
    tray.messages.scrollTop = tray.messages.scrollHeight;
}

function messageNode(message, showName){
    // "sam added alex" and the like
    if(message.system){
        let note = el("p", "chatSystem", message.body);
        note.dataset.id = message.id;
        note.dataset.created = message.created;
        return note;
    }

    let mine = message.from === state.me.id;
    let row = el("div", "chatMsg " + (mine ? "mine" : "theirs"));

    // in groups, who said it (once per run of messages from the same person)
    if(state.kind === "group" && !mine && showName){
        row.classList.add("named");
        row.append(el("span", "chatSender", message.fromName || "Someone"));
    }
    row.dataset.id = message.id;
    row.dataset.from = message.from;
    row.dataset.created = message.created;

    let bubble = el("div", "chatBubble");
    bubble.title = clock(message.created);

    if(message.deleted){
        bubble.classList.add("removed");
        bubble.textContent = "Removed by a moderator";
    } else {
        if(message.image){
            let link = el("a", "chatImage");
            link.href = message.image;
            link.target = "_blank";
            link.rel = "noopener";
            let img = el("img");
            img.src = message.image;
            img.alt = "Image from " + (mine ? "you" : (message.fromName || state.convoName));
            img.loading = "lazy";
            // images change the height once they load, keep the view pinned if you were near the end
            img.addEventListener("load", function() {
                if(tray.messages.scrollHeight - tray.messages.scrollTop - tray.messages.clientHeight < img.height + 120){
                    scrollToEnd();
                }
            });
            link.append(img);
            bubble.append(link);
        }
        if(message.body){
            bubble.append(el("p", "chatText", message.body));
        }
    }

    row.append(bubble);

    if(!mine && !message.deleted){
        let flag = el("button", "chatFlag");
        flag.type = "button";
        flag.title = "Report";
        flag.setAttribute("aria-label", "Report this message");
        flag.innerHTML = '<i class="ph-bold ph-flag"></i>';
        flag.addEventListener("click", () => openReport(message, flag));
        row.append(flag);
    }

    return row;
}

// adds messages in order, with a time label whenever there's a gap
function addMessages(messages, atTop){
    let fragment = document.createDocumentFragment();
    let all = tray.messages.querySelectorAll(".chatMsg");
    let previous = atTop ? null : all[all.length - 1];
    let prevTime = previous ? Number(previous.dataset.created) : 0;
    let prevFrom = previous ? Number(previous.dataset.from) : null;

    messages.forEach(function(message) {
        if(state.convoIds.has(message.id)){
            return;
        }
        state.convoIds.add(message.id);

        if(state.oldestId === null || message.id < state.oldestId){
            state.oldestId = message.id;
        }

        let gap = message.created - prevTime > 900;
        if(gap){
            fragment.append(el("p", "chatTime", clock(message.created)));
        }
        prevTime = message.created;

        fragment.append(messageNode(message, gap || message.from !== prevFrom));
        prevFrom = message.system ? null : message.from;
    });

    let start = tray.messages.querySelector(".chatStart");
    if(start && messages.length){
        start.remove();
    }

    if(atTop){
        let height = tray.messages.scrollHeight;
        let marker = tray.messages.querySelector(".chatMore");
        tray.messages.insertBefore(fragment, marker ? marker.nextSibling : tray.messages.firstChild);
        tray.messages.scrollTop += tray.messages.scrollHeight - height;
    } else {
        tray.messages.append(fragment);
    }

    updateMoreButton();
    updateSeen();
}

function updateMoreButton(){
    let existing = tray.messages.querySelector(".chatMore");
    if(existing){
        existing.remove();
    }
    if(state.hasMore){
        let more = button("Load older messages", "chatMore", loadOlder);
        tray.messages.prepend(more);
    }
}

function loadOlder(){
    let id = state.convo;
    let url = state.kind === "group"
        ? "/api/v1/social/groups/" + id + "/messages?before=" + state.oldestId
        : "/api/v1/social/messages/" + id + "?before=" + state.oldestId;
    api("GET", url).then(function(data) {
        if(state.convo !== id){
            return;
        }
        state.hasMore = data.more;
        addMessages(data.messages, true);
    });
}

// "Seen" under the last thing you sent, once they've read it
function updateSeen(){
    tray.messages.querySelectorAll(".chatSeen").forEach(n => n.remove());
    if(state.kind === "group"){
        return;
    }
    let mine = tray.messages.querySelectorAll(".chatMsg.mine");
    let last = mine[mine.length - 1];
    let all = tray.messages.querySelectorAll(".chatMsg");
    if(!last || last !== all[all.length - 1]){
        return;
    }
    if((state.seen[state.convo] || 0) >= Number(last.dataset.id)){
        last.after(el("p", "chatSeen", "Seen"));
    }
}

// ---------- sending ----------

function autoSize(){
    tray.input.style.height = "auto";
    tray.input.style.height = Math.min(tray.input.scrollHeight, 120) + "px";
}

tray.input.addEventListener("input", autoSize);

tray.input.addEventListener("keydown", function(event) {
    if(event.key === "Enter" && !event.shiftKey && !event.isComposing && !touchInput()){
        event.preventDefault();
        sendMessage();
    }
});

document.getElementById("chatComposer").addEventListener("submit", function(event) {
    event.preventDefault();
    sendMessage();
});

let sending = false;

function sendMessage(){
    let body = tray.input.value.trim();
    let image = state.attachment;

    if(sending || (!body && !image) || (image && image.uploading)){
        return;
    }

    sending = true;
    let id = state.convo;
    let kind = state.kind;
    let url = kind === "group" ? "/api/v1/social/groups/" + id + "/messages" : "/api/v1/social/messages/" + id;

    api("POST", url, { body: body, image: image ? image.id : "" }).then(function(data) {
        tray.input.value = "";
        autoSize();
        clearAttachment();
        if(state.convo === id && state.kind === kind){
            addMessages([data.message], false);
            scrollToEnd();
        }
        if(kind === "group"){
            state.lastGroupId = Math.max(state.lastGroupId, data.message.id);
            if(state.groups[id]){
                state.groups[id].lastMessage = data.message.id;
            }
            return;
        }
        state.lastId = Math.max(state.lastId, data.message.id);
        if(state.friends[id]){
            state.friends[id].lastMessage = data.message.id;
        }
    }).catch(function(error) {
        flash(error.message);
    }).finally(function() {
        sending = false;
    });
}

function flash(text){
    let notice = document.getElementById("chatNotice");
    notice.textContent = text;
    notice.hidden = false;
    notice.classList.add("error");
    clearTimeout(flash.timer);
    flash.timer = setTimeout(function() {
        notice.hidden = true;
        notice.classList.remove("error");
    }, 4000);
}

// ---------- images ----------

function shrink(file){
    if(file.type === "image/gif"){
        return Promise.resolve(file);
    }
    return createImageBitmap(file).then(function(bitmap) {
        let scale = Math.min(1, 1280 / Math.max(bitmap.width, bitmap.height));
        if(scale === 1 && file.size < 1200000){
            return file;
        }
        let canvas = document.createElement("canvas");
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext("2d").drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        return new Promise(resolve => canvas.toBlob(blob => resolve(blob || file), file.type === "image/png" ? "image/png" : "image/jpeg", 0.85));
    }).catch(() => file);
}

function attach(file){
    if(!file || !/^image\/(png|jpeg|gif|webp)$/.test(file.type)){
        flash("Images have to be JPEG, PNG, GIF or WebP.");
        return;
    }

    let item = { id: null, url: URL.createObjectURL(file), uploading: true };
    state.attachment = item;
    showAttachment(item);

    shrink(file).then(function(blob) {
        let form = new FormData();
        form.append("image", blob, file.name || "image");
        return api("POST", "/api/v1/social/images", form);
    }).then(function(data) {
        item.id = data.id;
        item.uploading = false;
        if(state.attachment === item){
            showAttachment(item);
        }
    }).catch(function(error) {
        flash(error.message);
        if(state.attachment === item){
            clearAttachment();
        }
    });
}

function showAttachment(item){
    let box = document.getElementById("chatAttachment");
    box.replaceChildren();
    box.hidden = false;
    let img = el("img");
    img.src = item.url;
    img.alt = "";
    let remove = button("", "chatRemoveImage", clearAttachment);
    remove.setAttribute("aria-label", "Remove image");
    remove.innerHTML = '<i class="ph-bold ph-x"></i>';
    box.append(img, el("span", "chatMeta", item.uploading ? "Uploading..." : "Ready to send"), remove);
}

function clearAttachment(){
    state.attachment = null;
    let box = document.getElementById("chatAttachment");
    box.replaceChildren();
    box.hidden = true;
}

document.getElementById("chatImageButton").addEventListener("click", () => document.getElementById("chatFile").click());
document.getElementById("chatFile").addEventListener("change", function() {
    attach(this.files[0]);
    this.value = "";
});
tray.input.addEventListener("paste", function(event) {
    let file = Array.from(event.clipboardData.files || [])[0];
    if(file){
        event.preventDefault();
        attach(file);
    }
});

// ---------- reporting ----------

let reporting = null;

function openReport(message, flagButton){
    reporting = { message: message, button: flagButton };

    let quote = document.getElementById("chatReportQuote");
    quote.replaceChildren();
    if(message.image){
        let img = el("img");
        img.src = message.image;
        img.alt = "";
        quote.append(img);
    }
    if(message.body){
        quote.append(el("span", "", message.body));
    }

    let reasons = document.getElementById("chatReportReasons");
    reasons.querySelectorAll("label").forEach(n => n.remove());
    Object.entries(state.reasons).forEach(function([key, label], i) {
        let row = el("label", "chatReason");
        let radio = el("input");
        radio.type = "radio";
        radio.name = "reason";
        radio.value = key;
        radio.required = true;
        row.append(radio, el("span", "", label));
        reasons.append(row);
    });

    document.getElementById("chatReportDetails").value = "";
    tray.report.hidden = false;
    reasons.querySelector("input").focus();
}

function closeReport(){
    tray.report.hidden = true;
    reporting = null;
}

document.getElementById("chatReportCancel").addEventListener("click", closeReport);

tray.report.addEventListener("submit", function(event) {
    event.preventDefault();
    if(!reporting){
        return;
    }

    let reason = tray.report.querySelector("input[name=reason]:checked");
    if(!reason){
        return;
    }

    let current = reporting;
    let sendButton = document.getElementById("chatReportSend");
    sendButton.disabled = true;

    api("POST", (state.kind === "group" ? "/api/v1/social/group-report/" : "/api/v1/social/report/") + current.message.id, {
        reason: reason.value,
        details: document.getElementById("chatReportDetails").value
    }).then(function(data) {
        closeReport();
        current.button.classList.add("reported");
        current.button.disabled = true;
        current.button.title = "Reported";
        current.button.innerHTML = '<i class="ph-fill ph-flag"></i>';
        flash(data.message);
    }).catch(function(error) {
        alert(error.message);
    }).finally(function() {
        sendButton.disabled = false;
    });
});

// ---------- conversation menu ----------

document.querySelectorAll("[data-convo-act]").forEach(function(item) {
    item.addEventListener("click", function() {
        let action = item.dataset.convoAct;
        let name = state.convoName;
        let question = action === "block"
            ? "Block " + name + "? They won't be able to message you or send you friend requests."
            : "Unfriend " + name + "?";

        document.getElementById("chatMenu").open = false;
        if(!confirm(question)){
            return;
        }

        let id = state.convo;
        friendAction(id, action).then(function() {
            showHome();
        });
    });
});

// ---------- incoming messages (shared by the websocket and polling) ----------

function resort(){
    state.order.sort((a, b) =>
        (state.friends[b].lastMessage || 0) - (state.friends[a].lastMessage || 0)
        || (state.friends[b].online - state.friends[a].online)
        || state.friends[a].username.localeCompare(state.friends[b].username)
    );
}

function refreshLists(){
    if(!tray.home.hidden){
        renderHome();
    } else {
        updateBadge();
    }
}

function viewingConvo(){
    return state.convo && !tray.panel.hidden && !document.hidden;
}

// new messages: add the ones for the open chat, bump unread counts for the rest.
// countUnread is for the websocket, where nothing else tells us the counts
function receive(messages, countUnread){
    if(!messages.length){
        return;
    }

    let stick = nearBottom();
    let forConvo = [];

    messages.forEach(function(message) {
        let fresh = message.id > state.lastId;
        state.lastId = Math.max(state.lastId, message.id);

        let other = message.from === state.me.id ? message.to : message.from;
        let friend = state.friends[other];
        if(friend){
            friend.lastMessage = message.id;
        }

        if(state.kind === "dm" && state.convo === other){
            forConvo.push(message);
            if(message.from === other){
                hideTyping();
            }
        }

        if(countUnread && fresh && friend && message.from === other && !(viewingConvo() && state.kind === "dm" && state.convo === other)){
            friend.unread = (friend.unread || 0) + 1;
        }
    });

    if(forConvo.length){
        addMessages(forConvo, false);
        if(stick){
            scrollToEnd();
        }
    }

    // reading it now counts as read
    if(viewingConvo() && state.kind === "dm" && forConvo.some(m => m.from === state.convo)){
        if(state.friends[state.convo]){
            state.friends[state.convo].unread = 0;
        }
        api("POST", "/api/v1/social/read/" + state.convo).catch(() => {});
    }

    resort();
    refreshLists();
}

function receiveGroup(messages, countUnread){
    if(!messages.length){
        return;
    }

    let stick = nearBottom();
    let forConvo = [];
    let unknown = false;

    messages.forEach(function(message) {
        let fresh = message.id > state.lastGroupId;
        state.lastGroupId = Math.max(state.lastGroupId, message.id);

        let group = state.groups[message.group];
        if(!group){
            unknown = true; // a group we were just added to
            return;
        }
        group.lastMessage = message.id;

        if(state.kind === "group" && state.convo === message.group){
            forConvo.push(message);
        } else if(fresh && message.from !== state.me.id && !message.system){
            group.unread = (group.unread || 0) + 1;
        }
    });

    if(forConvo.length){
        addMessages(forConvo, false);
        if(stick){
            scrollToEnd();
        }
        if(viewingConvo()){
            api("POST", "/api/v1/social/groups/" + state.convo + "/read").catch(() => {});
        } else if(state.groups[state.convo]){
            state.groups[state.convo].unread += forConvo.filter(m => m.from !== state.me.id && !m.system).length;
        }
    }

    state.groupOrder.sort((a, b) => (state.groups[b].lastMessage || 0) - (state.groups[a].lastMessage || 0));

    if(unknown){
        loadState();
    } else {
        refreshLists();
    }
}

// ---------- realtime (websocket) ----------

let socket = null;
let socketTries = 0;
let typingTimer = null;
let lastTypingSent = 0;

function connect(){
    if(!state.realtime || socket){
        return;
    }

    let url = state.realtime.url + (state.realtime.url.includes("?") ? "&" : "?") + "token=" + encodeURIComponent(state.realtime.token);

    try {
        socket = new WebSocket(url);
    } catch (e) {
        socket = null;
        return;
    }

    socket.addEventListener("open", function() {
        socketTries = 0;
        state.live = true;
        // catch anything sent while we were connecting, then relax the polling
        schedulePoll(0);
    });

    socket.addEventListener("message", function(event) {
        let data;
        try { data = JSON.parse(event.data); } catch (e) { return; }
        handleLive(data);
    });

    socket.addEventListener("close", function() {
        socket = null;
        state.live = false;
        schedulePoll(0);

        // back off: 1s, 2s, 4s ... up to 30s. after a few failures the token may have expired, get a new one
        socketTries++;
        let wait = Math.min(30000, 1000 * Math.pow(2, socketTries - 1));
        setTimeout(function() {
            if(socketTries >= 3){
                loadState().then(connect).catch(() => {});
            } else {
                connect();
            }
        }, wait);
    });

    socket.addEventListener("error", () => {});
}

function handleLive(data){
    // other scripts (the notification bell) listen for these too
    document.dispatchEvent(new CustomEvent("watr:live", { detail: data }));

    switch(data.type){
        case "message":
            receive([data.message], true);
            break;

        case "group_message":
            receiveGroup([data.message], true);
            break;

        case "groups":
            loadState();
            break;

        case "group_read":
            if(state.groups[data.group]){
                state.groups[data.group].unread = 0;
                refreshLists();
            }
            break;

        case "group_deleted":
            if(state.kind === "group" && state.convo === data.group){
                markRemoved(data.id);
            }
            break;

        // a friend started or stopped playing something. a few at once only reload once
        case "presence":
            clearTimeout(state.presenceTimer);
            state.presenceTimer = setTimeout(loadState, 800);
            break;

        case "read":
            if(data.of === state.me.id){
                // they read what you sent
                state.seen[data.by] = Math.max(state.seen[data.by] || 0, data.lastId);
                if(state.convo === data.by){
                    updateSeen();
                }
            } else if(data.by === state.me.id && state.friends[data.of]){
                // you read it in another tab
                state.friends[data.of].unread = 0;
                refreshLists();
            }
            break;

        case "friends":
            loadState();
            break;

        case "deleted":
            if(state.kind === "dm"){
                markRemoved(data.id);
            }
            break;

        case "typing":
            if(state.kind === "dm" && state.convo === data.from && state.friends[data.from]){
                showTyping();
            }
            break;
    }
}

function markRemoved(id){
    let row = tray.messages.querySelector('.chatMsg[data-id="' + Number(id) + '"]');
    if(row){
        let bubble = row.querySelector(".chatBubble");
        bubble.className = "chatBubble removed";
        bubble.textContent = "Removed by a moderator";
        let flag = row.querySelector(".chatFlag");
        if(flag){
            flag.remove();
        }
    }
}

function showTyping(){
    document.getElementById("chatSub").textContent = "typing...";
    document.getElementById("chatSub").classList.add("typing");
    clearTimeout(typingTimer);
    typingTimer = setTimeout(hideTyping, 4000);
}

function hideTyping(){
    clearTimeout(typingTimer);
    let sub = document.getElementById("chatSub");
    if(sub.classList.contains("typing")){
        sub.classList.remove("typing");
        updateConvoHeader();
    }
}

tray.input.addEventListener("input", function() {
    if(!socket || socket.readyState !== WebSocket.OPEN || !state.convo || state.kind !== "dm" || !tray.input.value.trim()){
        return;
    }
    let now = Date.now();
    if(now - lastTypingSent > 2000){
        lastTypingSent = now;
        socket.send(JSON.stringify({ type: "typing", to: state.convo }));
    }
});

// ---------- polling (the fallback, and presence) ----------

function pollDelay(){
    // with the websocket up, polling only keeps online dots and counts fresh
    if(state.live) return document.hidden ? 120000 : 60000;
    if(document.hidden) return 45000;
    if(tray.panel.hidden) return 15000;
    if(state.convo) return 3000;
    return 6000;
}

function schedulePoll(delay){
    clearTimeout(state.pollTimer);
    state.pollTimer = setTimeout(poll, delay === undefined ? pollDelay() : delay);
}

function poll(){
    if(!state.loaded){
        return loadState().then(() => schedulePoll()).catch(() => schedulePoll(30000));
    }

    api("GET", "/api/v1/social/poll?since=" + state.lastId + "&groupSince=" + state.lastGroupId).then(function(data) {
        document.dispatchEvent(new CustomEvent("watr:poll", { detail: data }));

        state.seen = data.seen || {};
        let online = new Set(data.online);
        let playing = data.playing || {};

        Object.values(state.friends).forEach(function(friend) {
            friend.online = online.has(friend.id);
            friend.unread = data.unread[friend.id] || 0;
            friend.playing = playing[friend.id] || null;
        });

        receive(data.messages, false);
        receiveGroup(data.groupMessages || [], true);

        if(state.convo){
            updateSeen();
            if(!document.getElementById("chatSub").classList.contains("typing")){
                updateConvoHeader();
            }
        }

        // new friend requests (or friends accepting yours) need the full lists, so does a stale list
        if(data.requests !== state.lastRequests || Date.now() - state.lastStateLoad > 60000){
            loadState();
        } else {
            refreshLists();
        }
    }).catch(function() {}).finally(function() {
        schedulePoll();
    });
}

document.addEventListener("visibilitychange", function() {
    if(!document.hidden){
        schedulePoll(state.live ? undefined : 0);
    }
});

// ---------- opening and closing ----------

function openPanel(){
    tray.panel.hidden = false;
    tray.toggle.setAttribute("aria-expanded", "true");
    document.getElementById("chatTray").classList.add("open");
    remember();
}

function closePanel(){
    tray.panel.hidden = true;
    tray.toggle.setAttribute("aria-expanded", "false");
    document.getElementById("chatTray").classList.remove("open");
    closeReport();
    remember();
    schedulePoll();
}

tray.toggle.addEventListener("click", function() {
    if(tray.panel.hidden){
        openPanel();
        if(state.convo){
            state.kind === "group" ? openGroup(state.convo) : openConvo(state.convo);
        } else {
            loadState();
        }
        schedulePoll(0);
    } else {
        closePanel();
    }
});

document.getElementById("chatClose").addEventListener("click", closePanel);
document.getElementById("chatBack").addEventListener("click", showHome);

document.addEventListener("keydown", function(event) {
    if(event.key !== "Escape" || tray.panel.hidden){
        return;
    }
    if(!tray.report.hidden){
        closeReport();
    } else if(!document.getElementById("chatPicker").hidden || !document.getElementById("chatMembers").hidden){
        state.convo && state.kind === "group" ? openGroup(state.convo) : showHome();
    } else if(tray.panel.contains(document.activeElement)){
        closePanel();
        tray.toggle.focus();
    }
});

// profile pages: add friend / accept / message buttons. delegated, since profiles can arrive by in-page navigation
document.addEventListener("click", function(event) {
    let target = event.target.closest("#friendActions [data-act], #friendActions [data-chat]");
    if(!target){
        return;
    }

    let box = target.closest("#friendActions");

    if(target.hasAttribute("data-chat")){
        openConvo(box.dataset.id, box.dataset.username);
        return;
    }

    if(target.dataset.confirm && !confirm(target.dataset.confirm)){
        return;
    }

    target.disabled = true;
    api("POST", "/api/v1/social/friends/" + box.dataset.id + "/" + target.dataset.act).then(function() {
        loadState();
        window.watrNav ? window.watrNav.reload() : location.reload();
    }).catch(function(error) {
        alert(error.message);
        target.disabled = false;
    });
});

// ---------- making groups, adding people, members ----------

let picker = { mode: "create" };

function openPicker(mode){
    picker.mode = mode;
    let group = mode === "add" ? state.groups[state.convo] : null;
    let inGroup = new Set(group ? group.members.map(m => m.id) : []);

    showView(document.getElementById("chatPicker"));
    document.getElementById("chatBack").hidden = false;
    document.getElementById("chatMenu").hidden = true;
    document.getElementById("chatTitle").textContent = mode === "add" ? "Add people" : "New group";
    document.getElementById("chatSub").textContent = "";
    document.getElementById("chatPickerNameRow").hidden = mode === "add";
    document.getElementById("chatPickerName").value = "";
    document.getElementById("chatPickerHeading").textContent = mode === "add" ? "Pick friends to add" : "Pick at least two friends";
    document.getElementById("chatPickerSubmit").textContent = mode === "add" ? "Add" : "Make group";
    document.getElementById("chatPickerError").hidden = true;

    let list = document.getElementById("chatPickerList");
    list.replaceChildren();
    let choices = state.order.filter(id => !inGroup.has(id));

    if(!choices.length){
        list.append(el("p", "chatEmpty", mode === "add" ? "All your friends are already in here." : "You need friends to make a group."));
    }

    choices.forEach(function(id) {
        let friend = state.friends[id];
        let row = el("label", "chatRow chatPick");
        let box = el("input");
        box.type = "checkbox";
        box.value = id;
        row.append(box, avatar(friend.username, friend.online, friend.avatar), el("span", "chatName", friend.username));
        list.append(row);
    });
}

document.getElementById("chatPickerCancel").addEventListener("click", function() {
    picker.mode === "add" && state.convo ? openGroup(state.convo) : showHome();
});

document.getElementById("chatPicker").addEventListener("submit", function(event) {
    event.preventDefault();

    let members = Array.from(document.querySelectorAll("#chatPickerList input:checked")).map(b => b.value);
    let error = document.getElementById("chatPickerError");
    let submit = document.getElementById("chatPickerSubmit");
    let form = new FormData();
    members.forEach(id => form.append("members[]", id));

    let request;
    if(picker.mode === "add"){
        request = api("POST", "/api/v1/social/groups/" + state.convo + "/add", form);
    } else {
        form.append("name", document.getElementById("chatPickerName").value);
        request = api("POST", "/api/v1/social/groups", form);
    }

    submit.disabled = true;
    request.then(function(data) {
        let id = picker.mode === "add" ? state.convo : data.id;
        return loadState().then(() => openGroup(id));
    }).catch(function(e) {
        error.textContent = e.message;
        error.hidden = false;
    }).finally(function() {
        submit.disabled = false;
    });
});

function showMembers(){
    let group = state.groups[state.convo];
    if(!group){
        return;
    }

    showView(document.getElementById("chatMembers"));
    document.getElementById("chatMenu").hidden = true;
    document.getElementById("chatTitle").textContent = group.name;
    document.getElementById("chatSub").textContent = group.members.length + " people";

    let list = document.getElementById("chatMembersList");
    list.replaceChildren();
    let back = button("Back to the chat", "chatSmall chatMembersBack", () => openGroup(group.id));
    list.append(back);

    group.members.forEach(function(member) {
        let row = el("div", "chatRow");
        let link = el("a", "chatName", member.username);
        link.href = "/users/" + encodeURIComponent(member.username.toLowerCase());
        row.append(avatar(member.username, undefined, member.avatar), link);

        let actions = el("span", "chatRowActions");
        if(member.id === group.owner){
            actions.append(el("span", "chatMeta", "Owner"));
        } else if(group.owner === state.me.id){
            actions.append(button("Remove", "chatSmall", function() {
                if(!confirm("Remove " + member.username + " from the group?")){
                    return;
                }
                let form = new FormData();
                form.append("user", member.id);
                api("POST", "/api/v1/social/groups/" + group.id + "/remove", form).then(() => loadState()).then(showMembers).catch(e => alert(e.message));
            }));
        }
        row.append(actions);
        list.append(row);
    });
}

document.querySelectorAll("[data-group-act]").forEach(function(item) {
    item.addEventListener("click", function() {
        let group = state.groups[state.convo];
        document.getElementById("chatMenu").open = false;
        if(!group){
            return;
        }

        switch(item.dataset.groupAct){
            case "members":
                showMembers();
                break;
            case "add":
                openPicker("add");
                break;
            case "rename":
                let name = prompt("New name for the group", group.name);
                if(name && name.trim() && name.trim() !== group.name){
                    let form = new FormData();
                    form.append("name", name.trim());
                    api("POST", "/api/v1/social/groups/" + group.id + "/rename", form).then(() => loadState()).catch(e => alert(e.message));
                }
                break;
            case "leave":
                if(confirm("Leave " + group.name + "? You'll need someone to add you back.")){
                    api("POST", "/api/v1/social/groups/" + group.id + "/leave").then(function() {
                        showHome();
                        loadState();
                    }).catch(e => alert(e.message));
                }
                break;
        }
    });
});

// pick up where the last page left off
window.watrChat = { open: openConvo, openGroup: openGroup, openPanel: function() {
    if(tray.panel.hidden){
        tray.toggle.click();
    }
    document.getElementById("chatSearch").focus();
} };

loadState().then(function() {
    let saved = recall();
    if(saved.open){
        openPanel();
        if(saved.convo && saved.kind === "group" && state.groups[saved.convo]){
            openGroup(saved.convo);
        } else if(saved.convo && saved.kind !== "group" && state.friends[saved.convo]){
            openConvo(saved.convo);
        }
    }
    schedulePoll();
}).catch(function() {
    schedulePoll(30000);
});

})();
