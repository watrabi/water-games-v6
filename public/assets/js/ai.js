// the /ai chat page

const aiData = JSON.parse(document.getElementById("aiData").textContent);

let ai = {
    chatId: aiData.chatId,
    remaining: aiData.remaining,
    streaming: false,
    controller: null,
    attachments: [] // {id, url, uploading, el}
};

const log = document.getElementById("aiLog");
const input = document.getElementById("aiInput");
const sendButton = document.getElementById("aiSend");
const modelSelect = document.getElementById("aiModel");
const touchInput = window.matchMedia("(pointer: coarse)").matches;

const toolLabels = {
    get_current_time: ["Checking the time", "Checked the time"],
    calculate: ["Calculating", "Calculated"],
    search_site: ["Searching the site", "Searched the site"],
    get_weather: ["Checking the weather", "Checked the weather"],
    fetch_webpage: ["Reading the page", "Read a page"]
};

// ---------- small helpers ----------

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

function nearBottom(){
    return log.scrollHeight - log.scrollTop - log.clientHeight < 120;
}

function scrollDown(force){
    if(force || nearBottom()){
        log.scrollTop = log.scrollHeight;
    }
}

function readPref(key){
    try { return localStorage.getItem(key); } catch (e) { return null; }
}

function writePref(key, value){
    try { localStorage.setItem(key, value); } catch (e) {}
}

// ---------- markdown ----------

function renderMarkdown(target, text){
    if(!window.marked || !window.DOMPurify){
        target.textContent = text;
        return;
    }

    target.innerHTML = DOMPurify.sanitize(marked.parse(text, { gfm: true, breaks: false }));

    // links off the site open in a new tab
    target.querySelectorAll("a[href]").forEach(function(link) {
        let url;
        try { url = new URL(link.getAttribute("href"), location.href); } catch (e) { return; }
        if(url.origin !== location.origin){
            link.target = "_blank";
            link.rel = "noopener noreferrer";
        }
    });
}

// highlighting + copy buttons, done once a block of text is finished
function finishMarkdown(target){
    target.querySelectorAll("pre > code").forEach(function(code) {
        let pre = code.parentElement;
        if(pre.parentElement.classList.contains("aiCode")){
            return;
        }

        if(window.hljs){
            hljs.highlightElement(code);
        }

        let language = (code.className.match(/language-([\w+#-]+)/) || [])[1] || "code";
        let wrap = el("div", "aiCode");
        let bar = el("div", "aiCodeBar");
        bar.append(el("span", "", language));

        let copy = el("button", "aiCopy", "Copy");
        copy.type = "button";
        copy.addEventListener("click", function() {
            navigator.clipboard.writeText(code.textContent).then(function() {
                copy.textContent = "Copied";
                setTimeout(function() { copy.textContent = "Copy"; }, 1500);
            });
        });
        bar.append(copy);

        pre.replaceWith(wrap);
        wrap.append(bar, pre);
    });
}

// ---------- building messages ----------

function userBubble(content){
    let row = el("div", "aiMsg user");
    let bubble = el("div", "aiBubble");

    let images = content.filter(b => b.type === "image");
    if(images.length){
        let strip = el("div", "aiImages");
        images.forEach(function(image) {
            let link = el("a");
            link.href = image.url;
            link.target = "_blank";
            let img = el("img");
            img.src = image.url;
            img.alt = "Attached image";
            img.loading = "lazy";
            link.append(img);
            strip.append(link);
        });
        bubble.append(strip);
    }

    content.filter(b => b.type === "text").forEach(function(block) {
        bubble.append(el("p", "aiUserText", block.text));
    });

    row.append(bubble);
    return row;
}

// an assistant turn can span several saved messages (text, tool calls, more text)
function newTurn(){
    let row = el("div", "aiMsg assistant");
    let body = el("div", "aiTurn");
    row.append(body);

    return {
        row: row,
        body: body,
        text: null,      // current text segment {el, raw}
        thinking: null,  // current thinking part
        tools: {},       // id -> chip
        allText: []
    };
}

function addText(turn, text, live){
    if(!turn.text){
        let node = el("div", "aiText");
        turn.body.append(node);
        turn.text = { el: node, raw: "", queued: false, closed: false };
        turn.allText.push(turn.text);
    }

    let segment = turn.text;
    segment.raw += text;

    if(!live){
        renderMarkdown(segment.el, segment.raw);
        finishMarkdown(segment.el);
        return;
    }

    // re-render at most once a frame while streaming
    if(!segment.queued){
        segment.queued = true;
        requestAnimationFrame(function() {
            segment.queued = false;

            // closeText already did the final render, rendering again would wipe the code block extras
            if(segment.closed){
                return;
            }

            let stick = nearBottom();
            renderMarkdown(segment.el, segment.raw);
            if(stick){
                scrollDown(true);
            }
        });
    }
}

function closeText(turn){
    if(turn.text){
        let segment = turn.text;
        segment.closed = true;
        renderMarkdown(segment.el, segment.raw);
        finishMarkdown(segment.el);
        turn.text = null;
    }
}

function startThinking(turn, live){
    closeText(turn);

    let details = el("details", "aiThinking");
    let summary = el("summary", "", live ? "Thinking..." : "Thought about it");
    let text = el("div", "aiThinkingText");
    details.append(summary, text);
    turn.body.append(details);

    turn.thinking = { el: details, summary: summary, text: text, started: Date.now(), raw: "" };
    if(live){
        details.classList.add("live");
    }
}

function addThinking(turn, text){
    if(!turn.thinking){
        startThinking(turn, true);
    }
    turn.thinking.raw += text;
    turn.thinking.text.textContent = turn.thinking.raw;
}

function endThinking(turn){
    if(!turn.thinking){
        return;
    }

    let seconds = Math.max(1, Math.round((Date.now() - turn.thinking.started) / 1000));
    turn.thinking.summary.textContent = "Thought for " + seconds + "s";
    turn.thinking.el.classList.remove("live");

    if(!turn.thinking.raw){
        turn.thinking.el.classList.add("blank");
    }

    turn.thinking = null;
}

function toolChip(turn, id, name, live){
    closeText(turn);

    let labels = toolLabels[name] || ["Using " + name, "Used " + name];
    let details = el("details", "aiTool" + (live ? " running" : ""));
    let summary = el("summary");
    let icon = el("i", live ? "fa-solid fa-circle-notch fa-spin" : "fa-solid fa-wrench");
    let label = el("span", "", live ? labels[0] + "..." : labels[1]);
    summary.append(icon, label);

    let body = el("div", "aiToolBody");
    details.append(summary, body);
    turn.body.append(details);

    let chip = { el: details, icon: icon, label: label, body: body, labels: labels };
    turn.tools[id] = chip;
    return chip;
}

function toolInput(turn, id, inputValue){
    let chip = turn.tools[id];
    if(!chip){
        return;
    }
    let pre = el("pre", "aiToolInput", JSON.stringify(inputValue, null, 2));
    chip.body.prepend(pre);
}

function toolResult(turn, id, content, isError){
    let chip = turn.tools[id];
    if(!chip){
        return;
    }

    chip.el.classList.remove("running");
    chip.el.classList.toggle("failed", !!isError);
    chip.icon.className = isError ? "fa-solid fa-triangle-exclamation" : "fa-solid fa-check";
    chip.label.textContent = isError ? chip.labels[1] + " (failed)" : chip.labels[1];
    chip.body.append(el("pre", "aiToolResult", content));
}

function addNotice(turn, text, kind){
    closeText(turn);
    let notice = el("p", "aiNotice " + (kind || ""), text);
    turn.body.append(notice);
    return notice;
}

function turnActions(turn, isLast){
    let bar = el("div", "aiActions");

    let copy = el("button", "aiIconButton small");
    copy.type = "button";
    copy.title = "Copy";
    copy.setAttribute("aria-label", "Copy answer");
    copy.innerHTML = '<i class="fa-regular fa-copy"></i>';
    copy.addEventListener("click", function() {
        let text = turn.allText.map(s => s.raw).join("\n\n");
        navigator.clipboard.writeText(text).then(function() {
            copy.innerHTML = '<i class="fa-solid fa-check"></i>';
            setTimeout(function() { copy.innerHTML = '<i class="fa-regular fa-copy"></i>'; }, 1500);
        });
    });
    bar.append(copy);

    if(isLast){
        let again = el("button", "aiIconButton small");
        again.type = "button";
        again.title = "Regenerate";
        again.setAttribute("aria-label", "Regenerate answer");
        again.innerHTML = '<i class="fa-solid fa-rotate-right"></i>';
        again.addEventListener("click", regenerate);
        bar.append(again);
    }

    turn.row.append(bar);
}

function clearLastActions(){
    log.querySelectorAll("[aria-label='Regenerate answer']").forEach(b => b.remove());
}

// ---------- the saved chat ----------

function renderSaved(messages){
    let turn = null;
    let turns = [];

    messages.forEach(function(message) {
        if(message.role === "user" && message.visible){
            if(turn){
                closeText(turn);
            }
            turn = null;
            log.append(userBubble(message.content));
            return;
        }

        if(!turn){
            turn = newTurn();
            turns.push(turn);
            log.append(turn.row);
        }

        message.content.forEach(function(block) {
            switch(block.type){
                case "text":
                    addText(turn, block.text, false);
                    break;
                case "thinking":
                    startThinking(turn, false);
                    turn.thinking.raw = block.text;
                    turn.thinking.text.textContent = block.text;
                    if(!block.text){
                        turn.thinking.el.classList.add("blank");
                    }
                    turn.thinking = null;
                    break;
                case "tool_use":
                    toolChip(turn, block.id, block.name, false);
                    toolInput(turn, block.id, block.input);
                    break;
                case "tool_result":
                    toolResult(turn, block.tool_use_id, block.content, block.is_error);
                    break;
                case "notice":
                    addNotice(turn, block.text, block.kind);
                    break;
            }
        });

        // a new text block after anything else starts a new segment
        closeText(turn);
    });

    let lastIsAnswer = messages.length && !(messages[messages.length - 1].role === "user" && messages[messages.length - 1].visible);
    turns.forEach(function(t, i) {
        closeText(t);
        turnActions(t, lastIsAnswer && i === turns.length - 1);
    });
}

// ---------- sending ----------

function setStreaming(on){
    ai.streaming = on;
    sendButton.classList.toggle("stop", on);
    sendButton.querySelector("i").className = on ? "fa-solid fa-stop" : "fa-solid fa-arrow-up";
    sendButton.querySelector("span").textContent = on ? "Stop" : "Send";
    updateSendState();
}

function updateSendState(){
    let uploading = ai.attachments.some(a => a.uploading);
    let empty = !input.value.trim() && !ai.attachments.length;
    sendButton.disabled = !ai.streaming && (uploading || empty);
}

function updateHint(){
    let hint = document.getElementById("aiHint");
    if(ai.remaining === null || ai.remaining === undefined){
        hint.textContent = "";
    } else {
        hint.textContent = ai.remaining + " message" + (ai.remaining === 1 ? "" : "s") + " left today";
    }
}

function send(options){
    options = options || {};

    let text = options.regenerate ? "" : input.value.trim();
    let sent = options.regenerate ? [] : ai.attachments.filter(a => a.id);
    let userRow = null;

    if(!options.regenerate){
        if(!text && !sent.length){
            return;
        }

        document.getElementById("aiIntro").hidden = true;

        let content = sent.map(a => ({ type: "image", url: a.url }));
        if(text){
            content.push({ type: "text", text: text });
        }
        userRow = userBubble(content);
        log.append(userRow);

        input.value = "";
        autoSize();
        clearAttachments();
    }

    clearLastActions();

    let turn = newTurn();
    let waiting = el("div", "aiWaiting");
    waiting.append(el("span"), el("span"), el("span"));
    turn.body.append(waiting);
    log.append(turn.row);
    scrollDown(true);

    setStreaming(true);
    ai.controller = new AbortController();

    let gotAnything = false;
    let unsent = false;

    function handle(event){
        if(!gotAnything && event.type !== "chat"){
            gotAnything = true;
            waiting.remove();
        }

        switch(event.type){
            case "chat":
                ai.chatId = event.id;
                history.replaceState(null, "", "/ai/" + event.id);
                setTitle(event.title);
                addChatToList(event.id, event.title);
                document.getElementById("aiHeaderActions").hidden = false;
                break;
            case "text":
                endThinking(turn);
                addText(turn, event.text, true);
                break;
            case "thinking_start":
                startThinking(turn, true);
                break;
            case "thinking":
                addThinking(turn, event.text);
                break;
            case "thinking_end":
                endThinking(turn);
                break;
            case "tool_start":
                endThinking(turn);
                toolChip(turn, event.id, event.name, true);
                break;
            case "tool_input":
                toolInput(turn, event.id, event.input);
                break;
            case "tool_result":
                toolResult(turn, event.id, event.content, event.is_error);
                break;
            case "notice":
                addNotice(turn, event.text);
                break;
            case "refused":
                // the partial answer from a declined response doesn't count
                turn.body.querySelectorAll(".aiText").forEach(n => n.remove());
                turn.allText = [];
                turn.text = null;
                addNotice(turn, event.text, "refused");
                break;
            case "error":
                // the server turned the message down before saving it: hand it back so it can be fixed and resent
                if(event.unsent && userRow){
                    unsent = true;
                    userRow.remove();
                    input.value = text;
                    autoSize();
                    sent.forEach(restoreAttachment);
                }

                let notice = addNotice(turn, event.message, "error");
                if(event.retry){
                    let retry = el("button", "aiRetry", "Try again");
                    retry.type = "button";
                    retry.addEventListener("click", regenerate);
                    notice.append(" ", retry);
                }
                break;
        }

        scrollDown();
    }

    fetch("/api/v1/ai/send", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            chatId: ai.chatId,
            model: modelSelect.value,
            text: text,
            attachments: sent.map(a => a.id),
            regenerate: !!options.regenerate
        }),
        signal: ai.controller.signal
    }).then(function(response) {
        if(!response.ok || !response.body){
            return response.json().catch(() => ({})).then(function(data) {
                handle({ type: "error", message: data.message || "Couldn't reach the server.", unsent: true });
            });
        }

        let reader = response.body.getReader();
        let decoder = new TextDecoder();
        let buffer = "";

        function pump(){
            return reader.read().then(function(result) {
                if(result.done){
                    return;
                }

                buffer += decoder.decode(result.value, { stream: true });

                let split;
                while((split = buffer.indexOf("\n\n")) !== -1){
                    let chunk = buffer.slice(0, split);
                    buffer = buffer.slice(split + 2);

                    chunk.split("\n").forEach(function(line) {
                        if(line.startsWith("data: ")){
                            try { handle(JSON.parse(line.slice(6))); } catch (e) {}
                        }
                    });
                }

                return pump();
            });
        }

        return pump();
    }).catch(function(error) {
        if(error.name === "AbortError"){
            addNotice(turn, "Stopped.", "stopped");
        } else {
            handle({ type: "error", message: "Lost the connection." });
        }
    }).finally(function() {
        waiting.remove();
        endThinking(turn);
        closeText(turn);

        // tools that never got a result (stopped mid way)
        turn.body.querySelectorAll(".aiTool.running").forEach(function(chip) {
            chip.classList.remove("running");
            chip.querySelector("i").className = "fa-solid fa-xmark";
        });

        if(!turn.body.children.length){
            addNotice(turn, "No answer came back.", "error");
        }

        if(!options.regenerate && !unsent && ai.remaining !== null && ai.remaining !== undefined){
            ai.remaining = Math.max(0, ai.remaining - 1);
            updateHint();
        }

        if(!unsent){
            turnActions(turn, true);
        }

        ai.controller = null;
        setStreaming(false);
        if(!touchInput){
            input.focus();
        }
    });
}

function regenerate(){
    if(ai.streaming || !ai.chatId){
        return;
    }

    // drop the last answer from the page, the server does the same
    let rows = Array.from(log.querySelectorAll(".aiMsg"));
    for(let i = rows.length - 1; i >= 0; i--){
        if(rows[i].classList.contains("user")){
            break;
        }
        rows[i].remove();
    }

    send({ regenerate: true });
}

document.getElementById("aiComposer").addEventListener("submit", function(event) {
    event.preventDefault();

    if(ai.streaming){
        ai.controller.abort();
        return;
    }

    send();
});

input.addEventListener("keydown", function(event) {
    // enter sends on keyboards, makes a new line on phones
    if(event.key === "Enter" && !event.shiftKey && !event.isComposing && !touchInput){
        event.preventDefault();
        if(!ai.streaming && !sendButton.disabled){
            send();
        }
    }
});

function autoSize(){
    input.style.height = "auto";
    input.style.height = Math.min(input.scrollHeight, 220) + "px";
}

input.addEventListener("input", function() {
    autoSize();
    updateSendState();
});

document.querySelectorAll(".aiSuggestions button").forEach(function(button) {
    button.addEventListener("click", function() {
        input.value = button.dataset.prompt;
        autoSize();
        updateSendState();
        send();
    });
});

// ---------- images ----------

const MAX_IMAGES = 4;
const MAX_SIDE = 1568;

// big photos get scaled down before upload, models don't use the extra pixels anyway
function shrinkImage(file){
    if(file.type === "image/gif"){
        return Promise.resolve(file);
    }

    return createImageBitmap(file).then(function(bitmap) {
        let scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));

        if(scale === 1 && file.size < 1500000){
            return file;
        }

        let canvas = document.createElement("canvas");
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext("2d").drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        let type = file.type === "image/png" ? "image/png" : "image/jpeg";
        return new Promise(function(resolve) {
            canvas.toBlob(function(blob) { resolve(blob || file); }, type, 0.88);
        });
    }).catch(function() {
        return file;
    });
}

// the little preview above the text box
function makeThumb(item){
    let thumb = el("div", "aiThumb" + (item.uploading ? " uploading" : ""));
    let img = el("img");
    img.src = item.url;
    img.alt = "";
    let remove = el("button", "aiThumbRemove");
    remove.type = "button";
    remove.setAttribute("aria-label", "Remove image");
    remove.innerHTML = '<i class="fa-solid fa-xmark"></i>';
    remove.addEventListener("click", function() {
        ai.attachments = ai.attachments.filter(a => a !== item);
        thumb.remove();
        refreshAttachments();
    });
    thumb.append(img, remove);
    document.getElementById("aiAttachments").append(thumb);

    item.el = thumb;
    ai.attachments.push(item);
    refreshAttachments();
    return thumb;
}

// puts an already uploaded image back in the composer
function restoreAttachment(item){
    makeThumb({ id: item.id, url: item.url, uploading: false });
}

function addFiles(files){
    let images = Array.from(files).filter(f => /^image\/(png|jpeg|gif|webp)$/.test(f.type));
    let room = MAX_IMAGES - ai.attachments.length;

    if(images.length > room){
        flashHint("You can attach up to " + MAX_IMAGES + " images.");
        images = images.slice(0, Math.max(0, room));
    }

    images.forEach(function(file) {
        let item = { id: null, url: URL.createObjectURL(file), uploading: true };
        let thumb = makeThumb(item);

        shrinkImage(file).then(function(blob) {
            let form = new FormData();
            form.append("image", blob, file.name || "image");
            return fetch("/api/v1/ai/upload", { method: "POST", body: form });
        }).then(function(response) {
            return response.json().then(function(data) {
                if(!response.ok){
                    throw new Error(data.message || "Upload failed.");
                }
                item.id = data.id;
                item.url = data.url;
            });
        }).catch(function(error) {
            flashHint(error.message || "Upload failed.");
            ai.attachments = ai.attachments.filter(a => a !== item);
            thumb.remove();
        }).finally(function() {
            item.uploading = false;
            thumb.classList.remove("uploading");
            refreshAttachments();
        });
    });
}

function refreshAttachments(){
    document.getElementById("aiAttachments").hidden = ai.attachments.length === 0;
    updateSendState();
}

function clearAttachments(){
    ai.attachments = [];
    document.getElementById("aiAttachments").innerHTML = "";
    refreshAttachments();
}

function flashHint(text){
    let hint = document.getElementById("aiHint");
    hint.textContent = text;
    hint.classList.add("warn");
    setTimeout(function() {
        hint.classList.remove("warn");
        updateHint();
    }, 4000);
}

document.getElementById("aiAttach").addEventListener("click", function() {
    document.getElementById("aiFile").click();
});

document.getElementById("aiFile").addEventListener("change", function() {
    addFiles(this.files);
    this.value = "";
});

input.addEventListener("paste", function(event) {
    let files = Array.from(event.clipboardData.files || []);
    if(files.length){
        event.preventDefault();
        addFiles(files);
    }
});

let composer = document.getElementById("aiComposer");
composer.addEventListener("dragover", function(event) {
    event.preventDefault();
    composer.classList.add("dragging");
});
composer.addEventListener("dragleave", function() {
    composer.classList.remove("dragging");
});
composer.addEventListener("drop", function(event) {
    event.preventDefault();
    composer.classList.remove("dragging");
    addFiles(event.dataTransfer.files);
});

// ---------- chat list, title, model ----------

function setTitle(title){
    document.getElementById("aiTitle").textContent = title;
    document.title = title + " - " + document.title.split(" - ").pop();
}

function addChatToList(id, title){
    let list = document.getElementById("aiChatList");
    let empty = document.getElementById("aiNoChats");
    if(empty){
        empty.remove();
    }

    list.querySelectorAll("a.active").forEach(a => { a.classList.remove("active"); a.removeAttribute("aria-current"); });

    let link = el("a", "active", title);
    link.href = "/ai/" + id;
    link.dataset.id = id;
    link.setAttribute("aria-current", "page");
    list.prepend(link);
}

document.getElementById("aiRename").addEventListener("click", function() {
    let title = document.getElementById("aiTitle");
    let field = document.getElementById("aiTitleInput");
    field.value = title.textContent;
    title.hidden = true;
    field.hidden = false;
    field.focus();
    field.select();
});

function finishRename(save){
    let title = document.getElementById("aiTitle");
    let field = document.getElementById("aiTitleInput");
    if(field.hidden){
        return;
    }

    let value = field.value.trim();
    field.hidden = true;
    title.hidden = false;

    if(!save || !value || value === title.textContent){
        return;
    }

    $.post("/api/v1/ai/chats/" + ai.chatId + "/rename", { title: value }).done(function(data) {
        setTitle(data.title);
        let link = document.querySelector("#aiChatList a[data-id='" + ai.chatId + "']");
        if(link){
            link.textContent = data.title;
        }
    });
}

document.getElementById("aiTitleInput").addEventListener("keydown", function(event) {
    if(event.key === "Enter"){
        event.preventDefault();
        finishRename(true);
    } else if(event.key === "Escape"){
        finishRename(false);
    }
});
document.getElementById("aiTitleInput").addEventListener("blur", function() {
    finishRename(true);
});

document.getElementById("aiDelete").addEventListener("click", function() {
    if(!ai.chatId || !confirm("Delete this chat? This can't be undone.")){
        return;
    }

    $.post("/api/v1/ai/chats/" + ai.chatId + "/delete").done(function() {
        window.location.href = "/ai";
    });
});

let chatsPanel = document.getElementById("aiChats");
document.getElementById("aiChatsToggle").addEventListener("click", function() {
    chatsPanel.classList.toggle("open");
});
document.getElementById("aiChatsScrim").addEventListener("click", function() {
    chatsPanel.classList.remove("open");
});

// model: the chat's own, else the last one picked, else the default
(function pickModel(){
    let available = aiData.models.map(m => m.id);
    let saved = readPref("aiModel");
    let choice = aiData.chatId ? aiData.model : (saved && available.includes(saved) ? saved : aiData.model);
    if(available.includes(choice)){
        modelSelect.value = choice;
    }
    if(available.length < 2){
        modelSelect.hidden = true;
    }
})();

modelSelect.addEventListener("change", function() {
    writePref("aiModel", modelSelect.value);
});

// ---------- start ----------

// deferred scripts run in order, so marked / dompurify / hljs are loaded by now
if(aiData.messages.length){
    renderSaved(aiData.messages);
    scrollDown(true);
}

updateHint();
updateSendState();
if(!touchInput){
    input.focus();
}
