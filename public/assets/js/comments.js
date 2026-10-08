// comments under a game. runs again on every visit (pages load in place), so everything stays inside this function
(function(){

let dataEl = document.getElementById("commentData");
if(!dataEl){
    return;
}

let data = JSON.parse(dataEl.textContent);
let list = document.getElementById("commentList");
let empty = document.getElementById("commentEmpty");
let more = document.getElementById("commentMore");
let countEl = document.getElementById("commentCount");
let msg = $("#commentMsg");
let count = Number(countEl.textContent.replace(/[^0-9]/g, "")) || 0;
let oldest = null;

function el(tag, className, text){
    let node = document.createElement(tag);
    if(className){
        node.className = className;
    }
    if(text !== undefined){
        node.textContent = text;
    }
    return node;
}

function ago(seconds){
    let diff = Math.max(0, Date.now() / 1000 - seconds);
    if(diff < 60){
        return "just now";
    }
    if(diff < 3600){
        return Math.floor(diff / 60) + "m ago";
    }
    if(diff < 86400){
        return Math.floor(diff / 3600) + "h ago";
    }
    if(diff < 86400 * 7){
        return Math.floor(diff / 86400) + "d ago";
    }
    let date = new Date(seconds * 1000);
    let sameYear = date.getFullYear() === new Date().getFullYear();
    return date.toLocaleDateString(undefined, sameYear ? { month: "short", day: "numeric" } : { month: "short", day: "numeric", year: "numeric" });
}

function setCount(n){
    count = Math.max(0, n);
    countEl.textContent = count.toLocaleString();
}

function refreshEmpty(){
    empty.hidden = list.children.length > 0;
}

function avatar(user){
    let span = el("span", "avatar");
    if(user.avatar){
        let img = el("img");
        img.src = user.avatar;
        img.alt = "";
        span.append(img);
    } else {
        span.textContent = (user.username || "?").charAt(0).toUpperCase();
    }
    return span;
}

function profileLink(user, className, child){
    let link = el("a", className);
    link.href = "/users/" + encodeURIComponent(user.username.toLowerCase());
    link.append(child);
    return link;
}

function reportForm(comment, li){
    let form = el("form", "commentReport");
    form.hidden = true;

    let select = el("select");
    select.setAttribute("aria-label", "Why are you reporting this?");
    select.append(new Option("Why are you reporting this?", ""));
    Object.entries(data.reasons).forEach(([key, label]) => select.append(new Option(label, key)));

    let details = el("input");
    details.maxLength = 300;
    details.placeholder = "Anything else? (optional)";
    details.setAttribute("aria-label", "More details");

    let send = el("button", "button small", "Send report");
    send.type = "submit";
    let cancel = el("button", "button quiet small", "Cancel");
    cancel.type = "button";
    cancel.addEventListener("click", () => form.hidden = true);

    form.append(select, details, send, cancel);
    form.addEventListener("submit", function(event) {
        event.preventDefault();
        if(!select.value){
            select.focus();
            return;
        }
        send.disabled = true;
        $.post("/api/v1/games/comments/" + comment.id + "/report", { reason: select.value, details: details.value }).done(function() {
            form.replaceWith(el("p", "commentReported muted", "Thanks, a moderator will take a look."));
        }).fail(function(xhr) {
            send.disabled = false;
            showNotice(msg, apiMessage(xhr));
        });
    });

    return form;
}

function render(comment){
    let li = el("li", "comment");
    li.dataset.id = comment.id;

    let body = el("div", "commentBody");
    let head = el("div", "commentHead");
    head.append(profileLink(comment.user, "commentAuthor", document.createTextNode(comment.user.username)));
    let time = el("time", "muted", ago(comment.created));
    time.dateTime = new Date(comment.created * 1000).toISOString();
    time.title = new Date(comment.created * 1000).toLocaleString();
    head.append(time);

    body.append(head, el("p", "commentText", comment.body));

    let actions = el("div", "commentActions");
    if(comment.canReport){
        let report = el("button", "commentAction", "Report");
        report.type = "button";
        let form = reportForm(comment, li);
        report.addEventListener("click", () => { form.hidden = !form.hidden; });
        actions.append(report);
        body.append(actions, form);
    }
    if(comment.canDelete){
        let del = el("button", "commentAction", "Delete");
        del.type = "button";
        del.addEventListener("click", function() {
            // second click confirms, no browser dialog
            if(!del.classList.contains("really")){
                del.classList.add("really");
                del.textContent = "Really delete?";
                setTimeout(() => { del.classList.remove("really"); del.textContent = "Delete"; }, 4000);
                return;
            }
            del.disabled = true;
            $.post("/api/v1/games/comments/" + comment.id + "/delete").done(function() {
                li.remove();
                setCount(count - 1);
                refreshEmpty();
            }).fail(function(xhr) {
                del.disabled = false;
                showNotice(msg, apiMessage(xhr));
            });
        });
        actions.append(del);
    }
    if(!body.contains(actions) && actions.children.length){
        body.append(actions);
    }

    li.append(profileLink(comment.user, "commentAvatar", avatar(comment.user)), body);
    return li;
}

function addPage(page){
    page.comments.forEach(function(comment) {
        list.append(render(comment));
        oldest = comment.id;
    });
    more.hidden = !page.more;
    refreshEmpty();
}

addPage(data);

more.addEventListener("click", function() {
    more.disabled = true;
    $.get("/api/v1/games/" + data.game + "/comments", { before: oldest }).done(addPage).fail(function(xhr) {
        showNotice(msg, apiMessage(xhr));
    }).always(function() {
        more.disabled = false;
    });
});

let form = document.getElementById("commentForm");
if(form){
    let text = document.getElementById("commentText");
    let chars = document.getElementById("commentChars");
    let send = document.getElementById("commentSend");
    let max = Number(text.maxLength);

    text.addEventListener("input", function() {
        let left = max - text.value.length;
        chars.textContent = left <= 100 ? left + " left" : "";
    });

    // enter posts, shift+enter adds a line
    text.addEventListener("keydown", function(event) {
        if(event.key === "Enter" && !event.shiftKey && !event.isComposing){
            event.preventDefault();
            form.requestSubmit();
        }
    });

    form.addEventListener("submit", function(event) {
        event.preventDefault();
        let body = text.value.trim();
        if(!body){
            text.focus();
            return;
        }

        send.disabled = true;
        msg.prop("hidden", true);
        $.post("/api/v1/games/" + data.game + "/comments", { body: body }).done(function(result) {
            list.prepend(render(result.comment));
            text.value = "";
            chars.textContent = "";
            setCount(count + 1);
            refreshEmpty();
        }).fail(function(xhr) {
            showNotice(msg, apiMessage(xhr));
        }).always(function() {
            send.disabled = false;
        });
    });
}

})();
