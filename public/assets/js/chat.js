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
    convo: null,        // id of the friend whose chat is open
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
        sessionStorage.setItem("wgChat", JSON.stringify({ open: !tray.panel.hidden, convo: state.convo }));
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

function avatar(name, online){
    let wrap = el("span", "chatAvatar", name.charAt(0).toUpperCase());
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
    let unread = Object.values(state.friends).reduce((sum, f) => sum + (f.unread || 0), 0);
    let total = unread + state.requests.incoming.length;
    tray.badge.hidden = total === 0;
    tray.badge.textContent = total > 99 ? "99+" : total;
    tray.toggle.setAttribute("aria-label", "Chat" + (total ? ", " + total + " new" : ""));
}

// ---------- home: requests, friends, search ----------

function renderHome(){
    let requests = document.getElementById("chatRequests");
    requests.replaceChildren();

    if(state.requests.incoming.length){
        requests.append(el("h4", "chatHeading", "Friend requests"));
        state.requests.incoming.forEach(function(person) {
            let row = el("div", "chatRow");
            row.append(avatar(person.username), el("span", "chatName", person.username));
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

    let online = state.order.filter(id => state.friends[id].online).length;
    list.append(el("h4", "chatHeading", "Friends" + (state.order.length ? " · " + online + " online" : "")));

    if(!state.order.length){
        let empty = el("p", "chatEmpty", "No friends yet. Search for someone's username above to send a request.");
        list.append(empty);
    }

    state.order.forEach(function(id) {
        let friend = state.friends[id];
        let row = el("button", "chatRow chatFriend" + (friend.unread ? " unread" : ""));
        row.type = "button";
        row.append(avatar(friend.username, friend.online));

        let text = el("span", "chatRowText");
        text.append(el("span", "chatName", friend.username));
        text.append(el("span", "chatMeta", friend.online ? "Online" : (friend.lastSeen ? "Seen " + timeAgo(friend.lastSeen) : "Offline")));
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
            row.append(avatar(person.username), el("span", "chatName", person.username));
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
            row.append(avatar(person.username), el("span", "chatName", person.username));
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
    if(!state.loaded){
        state.lastId = data.lastId;
    }
    state.loaded = true;
    state.lastStateLoad = Date.now();
    state.realtime = data.realtime || null;
    renderHome();
    connect();

    if(state.convo && state.friends[state.convo]){
        updateConvoHeader();
    }
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
                row.append(avatar(person.username), link);

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
    let friend = state.friends[state.convo];
    let name = friend ? friend.username : (state.convoName || "Chat");
    document.getElementById("chatTitle").textContent = name;
    document.getElementById("chatSub").textContent = friend ? (friend.online ? "Online" : (friend.lastSeen ? "Seen " + timeAgo(friend.lastSeen) : "Offline")) : "";
    document.getElementById("chatProfileLink").href = "/users/" + encodeURIComponent(name.toLowerCase());
}

function showHome(){
    state.convo = null;
    tray.convoView.hidden = true;
    tray.home.hidden = false;
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

    state.convo = id;
    state.convoName = name || (state.friends[id] && state.friends[id].username);
    state.convoIds = new Set();
    state.oldestId = null;
    state.hasMore = false;

    tray.home.hidden = true;
    tray.convoView.hidden = false;
    document.getElementById("chatBack").hidden = false;
    document.getElementById("chatMenu").hidden = false;
    document.getElementById("chatMenu").open = false;
    tray.messages.replaceChildren(el("p", "chatEmpty", "Loading..."));
    document.getElementById("chatSub").classList.remove("typing");
    updateConvoHeader();
    clearAttachment();
    remember();

    api("GET", "/api/v1/social/messages/" + id).then(function(data) {
        if(state.convo !== id){
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

function messageNode(message){
    let mine = message.from === state.me.id;
    let row = el("div", "chatMsg " + (mine ? "mine" : "theirs"));
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
            img.alt = "Image from " + (mine ? "you" : state.convoName);
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
        flag.innerHTML = '<i class="fa-regular fa-flag"></i>';
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

    messages.forEach(function(message) {
        if(state.convoIds.has(message.id)){
            return;
        }
        state.convoIds.add(message.id);

        if(state.oldestId === null || message.id < state.oldestId){
            state.oldestId = message.id;
        }

        if(message.created - prevTime > 900){
            fragment.append(el("p", "chatTime", clock(message.created)));
        }
        prevTime = message.created;

        fragment.append(messageNode(message));
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
    api("GET", "/api/v1/social/messages/" + id + "?before=" + state.oldestId).then(function(data) {
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

    api("POST", "/api/v1/social/messages/" + id, { body: body, image: image ? image.id : "" }).then(function(data) {
        tray.input.value = "";
        autoSize();
        clearAttachment();
        if(state.convo === id){
            addMessages([data.message], false);
            scrollToEnd();
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
    remove.innerHTML = '<i class="fa-solid fa-xmark"></i>';
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

    api("POST", "/api/v1/social/report/" + current.message.id, {
        reason: reason.value,
        details: document.getElementById("chatReportDetails").value
    }).then(function(data) {
        closeReport();
        current.button.classList.add("reported");
        current.button.disabled = true;
        current.button.title = "Reported";
        current.button.innerHTML = '<i class="fa-solid fa-flag"></i>';
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

        if(state.convo === other){
            forConvo.push(message);
            if(message.from === other){
                hideTyping();
            }
        }

        if(countUnread && fresh && friend && message.from === other && !(viewingConvo() && state.convo === other)){
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
    if(viewingConvo() && forConvo.some(m => m.from === state.convo)){
        if(state.friends[state.convo]){
            state.friends[state.convo].unread = 0;
        }
        api("POST", "/api/v1/social/read/" + state.convo).catch(() => {});
    }

    resort();
    refreshLists();
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
    switch(data.type){
        case "message":
            receive([data.message], true);
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
            let row = tray.messages.querySelector('.chatMsg[data-id="' + Number(data.id) + '"]');
            if(row){
                let bubble = row.querySelector(".chatBubble");
                bubble.className = "chatBubble removed";
                bubble.textContent = "Removed by a moderator";
                let flag = row.querySelector(".chatFlag");
                if(flag){
                    flag.remove();
                }
            }
            break;

        case "typing":
            if(state.convo === data.from && state.friends[data.from]){
                showTyping();
            }
            break;
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
    if(!socket || socket.readyState !== WebSocket.OPEN || !state.convo || !tray.input.value.trim()){
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

    api("GET", "/api/v1/social/poll?since=" + state.lastId).then(function(data) {
        state.seen = data.seen || {};
        let online = new Set(data.online);

        Object.values(state.friends).forEach(function(friend) {
            friend.online = online.has(friend.id);
            friend.unread = data.unread[friend.id] || 0;
        });

        receive(data.messages, false);

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
            openConvo(state.convo);
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

// pick up where the last page left off
window.watrChat = { open: openConvo };

loadState().then(function() {
    let saved = recall();
    if(saved.open){
        openPanel();
        if(saved.convo && state.friends[saved.convo]){
            openConvo(saved.convo);
        }
    }
    schedulePoll();
}).catch(function() {
    schedulePoll(30000);
});

})();
