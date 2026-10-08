// runs again on every visit (pages load in place), so everything stays inside this function
(function(){

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
    fetch_webpage: ["Reading the page", "Read a page"],
    web_search: ["Searching the web", "Searched the web"],
    remember: ["Saving to memory", "Saved to memory"],
    update_memory: ["Updating a memory", "Updated a memory"],
    forget_memory: ["Forgetting", "Forgot something"],
    search_past_chats: ["Looking through past chats", "Looked through past chats"],
    create_theme: ["Making a theme", "Made a theme"],
    edit_theme: ["Changing the theme", "Changed the theme"],
    use_theme: ["Switching themes", "Switched themes"]
};

// a theme the ai just made or switched to: redraw the page in it without reloading
function applyTheme(event){
    let html = document.documentElement;
    let style = document.getElementById("customTheme");

    if(event.css){
        if(!style){
            style = document.createElement("style");
            style.id = "customTheme";
            document.head.append(style);
        }
        style.textContent = event.css;
    }

    html.dataset.theme = event.id;
    if(event.effect){
        html.dataset.effect = event.effect;
    } else {
        delete html.dataset.effect;
    }

    let meta = document.querySelector('meta[name="theme-color"]');
    if(meta && event.color){
        meta.setAttribute("content", event.color);
    }
}

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

// code blocks get their bar (language, copy) and highlighting on every render, not just at the end,
// so they look the same while they're still streaming in. final adds the preview button for html / svg
function decorateCode(target, final){
    target.querySelectorAll("pre > code").forEach(function(code) {
        let pre = code.parentElement;
        if(pre.parentElement.classList.contains("aiCode")){
            return;
        }

        let language = (code.className.match(/language-([\w+#-]+)/) || [])[1] || "";

        if(window.hljs && (!language || hljs.getLanguage(language))){
            hljs.highlightElement(code);
        }

        let wrap = el("div", "aiCode");
        let bar = el("div", "aiCodeBar");
        bar.append(el("span", "aiCodeLang", language || "code"));

        let buttons = el("span", "aiCodeButtons");
        let previewType = codePreviewType(language, code.textContent);
        if(final && previewType){
            let preview = el("button", "aiCopy", "Preview");
            preview.type = "button";
            preview.addEventListener("click", function() {
                openArtifact({
                    title: previewType === "svg" ? "SVG preview" : "HTML preview",
                    type: previewType, content: code.textContent, complete: true, transient: true
                });
            });
            buttons.append(preview);
        }

        let copy = el("button", "aiCopy", "Copy");
        copy.type = "button";
        copy.addEventListener("click", function() {
            navigator.clipboard.writeText(code.textContent).then(function() {
                copy.textContent = "Copied";
                setTimeout(function() { copy.textContent = "Copy"; }, 1500);
            });
        });
        buttons.append(copy);
        bar.append(buttons);

        pre.replaceWith(wrap);
        wrap.append(bar, pre);
    });
}

function finishMarkdown(target){
    decorateCode(target, true);
}

function codePreviewType(language, text){
    language = language.toLowerCase();
    if(language === "html" || ((language === "xml" || language === "") && /^\s*(<!doctype html|<html)/i.test(text))){
        return "html";
    }
    if(language === "svg" || (language === "xml" && /<svg\b/i.test(text))){
        return "svg";
    }
    return null;
}

// ---------- artifacts ----------
// the model writes <artifact id=".." type=".." title="..">content</artifact> into its answer.
// the text gets split into markdown and artifact parts, and each artifact becomes a card that opens the side panel

const artifactIcons = { html: "ph-globe", svg: "ph-shapes", markdown: "ph-file-text", code: "ph-code" };
const artifactNames = { html: "Web page", svg: "SVG", markdown: "Document", code: "Code" };

// same rules as artifacts::slug on the server
function artifactSlug(text){
    let slug = String(text || "").replace(/[^a-zA-Z0-9]+/g, "-").replace(/^-+|-+$/g, "").toLowerCase().slice(0, 64);
    return slug || "artifact";
}

function artifactAttrs(raw){
    let attrs = {};
    let decode = el("textarea");
    raw.replace(/([a-zA-Z_-]+)\s*=\s*("([^"]*)"|'([^']*)')/g, function(_, name, __, double, single) {
        decode.innerHTML = double !== undefined ? double : single;
        attrs[name.toLowerCase()] = decode.value;
    });
    return attrs;
}

function artifactType(attrs, content){
    let type = (attrs.type || "").toLowerCase();
    let language = (attrs.language || "").toLowerCase();
    if(artifactIcons[type]){
        return type;
    }
    if(["text/html", "page", "website"].includes(type) || language === "html" || /^\s*(<!doctype html|<html)/i.test(content)){
        return "html";
    }
    if(type === "image/svg+xml" || language === "svg" || /^\s*(<\?xml[^>]*>\s*)?<svg\b/i.test(content)){
        return "svg";
    }
    if(["md", "document", "text/markdown"].includes(type) || ["md", "markdown"].includes(language)){
        return "markdown";
    }
    return "code";
}

// drop a code fence the model wrapped around the content anyway (even half written)
function artifactContent(content, complete){
    content = content.replace(/^\r?\n/, "");
    // half of an opening fence, wait for the rest of the line
    if(!complete && /^\s*`{1,3}[\w+#-]*[ \t]*$/.test(content)){
        return "";
    }
    let open = content.match(/^\s*```[\w+#-]*[ \t]*\r?\n/);
    if(open){
        content = content.slice(open[0].length);
        content = content.replace(/\r?\n?\s*`{1,3}\s*$/, "");
    }
    return complete ? content.replace(/\r?\n$/, "") : content;
}

// while streaming, don't show the start of a tag before we know what it is
function holdBack(text, token){
    for(let k = Math.min(token.length - 1, text.length); k > 0; k--){
        if(text.endsWith(token.slice(0, k))){
            return text.slice(0, -k);
        }
    }
    return text;
}

// the start of a tag we don't know the end of yet, so it isn't shown as text while it streams
function cutPartialTag(rest){
    let partial = Math.max(rest.lastIndexOf("<artifact"), rest.lastIndexOf("<plan"));
    if(partial !== -1 && rest.indexOf(">", partial) === -1){
        return rest.slice(0, partial);
    }
    return holdBack(holdBack(rest, "<artifact"), "<plan");
}

// same format as artifacts::EDIT_PATTERN on the server
const editPattern = /^<<<<<<< SEARCH[ \t]*\n([\s\S]*?)\n?^=======[ \t]*\n([\s\S]*?)\n?^>>>>>>> REPLACE[ \t]*$/gm;

function parseEdits(body){
    let edits = [];
    body.replace(/\r\n/g, "\n").replace(editPattern, function(_, search, replace) {
        edits.push([search, replace]);
    });
    return edits;
}

// the browser's copy of artifacts::applyEdits, so an edit can be previewed while it streams in.
// the server's result is the one that's kept
function applyEdits(content, edits){
    content = content.replace(/\r\n/g, "\n");

    for(let i = 0; i < edits.length; i++){
        let [search, replace] = edits[i];
        if(!search.trim()){
            return { content: null, error: "empty SEARCH" };
        }

        let at = content.indexOf(search);
        if(at !== -1){
            if(content.indexOf(search, at + 1) !== -1){
                return { content: null, error: "matches more than one place" };
            }
            content = content.slice(0, at) + replace + content.slice(at + search.length);
            continue;
        }

        let lines = content.split("\n");
        let found = null;
        for(let clean of [l => l.replace(/\s+$/, ""), l => l.trim()]){
            let want = search.split("\n").map(clean);
            while(want.length > 1 && want[want.length - 1] === ""){ want.pop(); }
            while(want.length > 1 && want[0] === ""){ want.shift(); }

            let starts = [];
            for(let a = 0; a + want.length <= lines.length; a++){
                if(want.every((w, j) => clean(lines[a + j]) === w)){
                    starts.push(a);
                }
            }
            if(starts.length > 1){
                return { content: null, error: "matches more than one place" };
            }
            if(starts.length === 1){
                found = { at: starts[0], size: want.length };
                break;
            }
        }
        if(!found){
            return { content: null, error: "not found" };
        }
        lines.splice(found.at, found.size, ...(replace === "" ? [] : replace.split("\n")));
        content = lines.join("\n");
    }

    return { content: content, error: null };
}

function splitArtifacts(raw, live){
    let parts = [];
    let pattern = /<(artifact|plan)\b([^>]*)>/g;
    let pos = 0;

    while(true){
        pattern.lastIndex = pos;
        let match = pattern.exec(raw);

        if(!match){
            let rest = raw.slice(pos);
            parts.push({ kind: "md", text: live ? cutPartialTag(rest) : rest });
            break;
        }

        parts.push({ kind: "md", text: raw.slice(pos, match.index) });

        let closeTag = "</" + match[1] + ">";
        let bodyStart = match.index + match[0].length;
        let close = raw.indexOf(closeTag, bodyStart);
        let complete = close !== -1;
        let content = complete ? raw.slice(bodyStart, close) : raw.slice(bodyStart);
        if(!complete && live){
            content = holdBack(content, closeTag);
        }
        let attrs = artifactAttrs(match[2]);

        if(match[1] === "plan"){
            parts.push({
                kind: "plan",
                title: (attrs.title || "").trim(),
                text: content.replace(/^\r?\n/, ""),
                complete: complete,
                writing: !complete && live
            });
        } else {
            let edit = (attrs.mode || "").toLowerCase() === "edit";
            if(!edit){
                content = artifactContent(content, complete);
            }
            let title = (attrs.title || "").trim();
            let type = edit ? (attrs.type || "") : artifactType(attrs, content);

            parts.push({
                kind: "artifact",
                ref: artifactSlug(attrs.id || title || "Untitled"),
                title: title || "Untitled",
                titleGiven: !!title,
                type: type,
                language: type === "code" || edit ? (attrs.language || "").toLowerCase() : "",
                version: attrs.version ? parseInt(attrs.version, 10) : null,
                content: edit ? "" : content,
                edit: edit,
                editBody: edit ? content : "",
                edits: attrs.edits ? parseInt(attrs.edits, 10) : 0,
                failed: !!attrs.failed,
                complete: complete,
                writing: !complete && live
            });
        }

        if(!complete){
            break;
        }
        pos = close + closeTag.length;
    }

    return parts;
}

// an edit's content is the latest version before it with the edits applied: the server's copy once it's
// saved, a preview from the edits that have finished streaming before that
function resolveEdit(part, own){
    if(own && own.finalContent !== undefined){
        part.content = own.finalContent;
        part.edits = own.finalEdits || part.edits;
    }
    if(own && own.editFailed){
        part.failed = true;
    }

    let base = null;
    for(let card of log.querySelectorAll(".aiArtifact")){
        if(card === own){
            break;
        }
        if(card.artifact && card.artifact.ref === part.ref && !card.artifact.failed && !card.artifact.writing && card.artifact.content){
            base = card.artifact;
        }
    }

    if(base){
        part.type = part.type || base.type;
        part.language = part.language || base.language;
        if(!part.titleGiven){
            part.title = base.title;
        }
    }
    part.type = artifactIcons[part.type] ? part.type : "code";

    let edits = parseEdits(part.editBody);
    part.edits = part.edits || edits.length;
    if(own && own.finalContent !== undefined){
        return;
    }
    if(!base){
        part.content = "";
        return;
    }

    // the last block may still be streaming in, those wait until they're whole
    let result = applyEdits(base.content, edits);
    part.content = result.content !== null ? result.content : base.content;
    if(result.error && !part.writing && !part.version){
        part.previewMissed = true;
    }
}

function artifactMeta(part){
    let kind = part.type === "code" && part.language ? part.language : artifactNames[part.type];
    let lines = part.content ? part.content.split("\n").length : 0;
    let changes = part.edits + " change" + (part.edits === 1 ? "" : "s");
    if(part.edit){
        if(part.failed){
            return "Edit didn't line up, nothing changed";
        }
        if(part.writing){
            return "Editing… " + changes;
        }
    }
    if(part.writing){
        return "Writing… " + lines + " line" + (lines === 1 ? "" : "s");
    }
    if(part.edits && part.version){
        return kind + " · v" + part.version + " · " + changes;
    }
    if(!part.complete && !part.transient){
        return kind + " · unfinished";
    }
    return kind + (part.version ? " · v" + part.version : "");
}

function setIfChanged(node, key, value){
    if(node[key] !== value){
        node[key] = value;
    }
}

// cards are reused between renders so a click while it's still streaming isn't lost.
// the artifact lives on card.artifact (card.part is taken, it's the DOM's own property for ::part())
function artifactCard(part, existing){
    let card = existing;
    if(!card){
        card = el("button", "aiArtifact");
        card.type = "button";
        let icon = el("span", "aiArtifactIcon");
        icon.append(el("i"));
        let text = el("span", "aiArtifactText");
        text.append(el("span", "aiArtifactTitle"), el("span", "aiArtifactMeta"));
        card.append(icon, text, el("span", "aiArtifactOpen", "Open"));
        card.addEventListener("click", function() {
            openArtifact(card.artifact, card);
        });
    }

    // a version the server told us about after the text was written
    if(!part.version && card.artifact && card.artifact.version && card.artifact.ref === part.ref){
        part.version = card.artifact.version;
    }

    card.artifact = part;
    card.dataset.ref = part.ref;
    card.classList.toggle("writing", part.writing);
    card.classList.toggle("edit", !!part.edit);
    card.classList.toggle("failed", !!part.failed);
    setIfChanged(card.querySelector(".aiArtifactIcon"), "className", "aiArtifactIcon " + part.type);
    setIfChanged(card.querySelector(".aiArtifactIcon i"), "className", part.writing ? "ph-bold ph-circle-notch spin" : part.failed ? "ph-bold ph-warning" : "ph-bold " + (part.edit ? "ph-pencil-simple" : artifactIcons[part.type]));
    setIfChanged(card.querySelector(".aiArtifactTitle"), "textContent", part.title);
    setIfChanged(card.querySelector(".aiArtifactMeta"), "textContent", artifactMeta(part));
    card.setAttribute("aria-label", "Open " + part.title);

    if(artifactPanel.card === card){
        showArtifact(part, card, true);
    }
    return card;
}

// a plan the model wrote before building: a checklist that gets ticked off once what it planned is built
function planCard(part, existing){
    let card = existing;
    if(!card){
        card = el("details", "aiPlan");
        card.open = true;
        let summary = el("summary");
        summary.append(el("i", "ph-bold ph-list-checks"), el("span", "aiPlanLabel"), el("span", "aiPlanTitle"));
        card.append(summary, el("div", "aiText aiPlanBody"));
    }

    card.classList.toggle("writing", part.writing);
    setIfChanged(card.querySelector(".aiPlanLabel"), "textContent", part.writing ? "Planning…" : "Plan");
    setIfChanged(card.querySelector(".aiPlanTitle"), "textContent", part.title);
    if(card.planText !== part.text){
        card.planText = part.text;
        renderMarkdown(card.querySelector(".aiPlanBody"), part.text);
        if(card.classList.contains("done")){
            tickPlans(card.parentElement);
        }
    }
    return card;
}

// once a turn has built something, its plans are done
function tickPlans(scope){
    if(!scope || !scope.querySelector(".aiArtifact:not(.failed):not(.writing)")){
        return;
    }
    scope.querySelectorAll(".aiPlan").forEach(function(plan) {
        plan.classList.add("done");
        plan.querySelectorAll("input[type=checkbox]").forEach(box => box.checked = true);
    });
}

// markdown and artifact cards for one text segment. once there's an artifact in it, each part gets its
// own slot that's updated in place: the cards never leave the page, so their spinner doesn't restart and a
// click while it's streaming isn't lost, and markdown that hasn't changed isn't re-rendered
function renderSegment(segment, live){
    let parts = splitArtifacts(segment.raw, live);

    if(parts.length === 1 && !segment.slots){
        renderMarkdown(segment.el, parts[0].text);
        decorateCode(segment.el, !live);
        return;
    }

    if(!segment.slots){
        segment.slots = [];
        segment.el.replaceChildren();
    }

    parts.forEach(function(part, i) {
        let slot = segment.slots[i];

        // parts only ever get added at the end, but if the shape changed start the slots over
        if(slot && slot.kind !== part.kind){
            segment.slots.splice(i).forEach(s => s.el.remove());
            slot = null;
        }

        if(part.kind === "artifact" && part.edit){
            resolveEdit(part, slot ? slot.el : null);
        }

        if(!slot){
            slot = { kind: part.kind, el: part.kind === "md" ? el("div", "aiMdPart") : null, text: null, final: false };
            if(part.kind === "artifact"){
                slot.el = artifactCard(part, null);
            } else if(part.kind === "plan"){
                slot.el = planCard(part, null);
            }
            segment.el.append(slot.el);
            segment.slots[i] = slot;
        }

        if(part.kind === "plan"){
            planCard(part, slot.el);
            return;
        }

        if(part.kind === "md"){
            // the final render adds the preview buttons, so it always runs once
            if(slot.text !== part.text || (!live && !slot.final)){
                slot.text = part.text;
                slot.final = !live;
                renderMarkdown(slot.el, part.text);
                decorateCode(slot.el, !live);
            }
            return;
        }

        artifactCard(part, slot.el);

        // the first time a new one starts writing, open it on screens with room for the panel
        if(part.writing && !slot.el.autoOpened){
            slot.el.autoOpened = true;
            if(window.matchMedia("(min-width: 1100px)").matches && !artifactPanel.pinnedByUser){
                openArtifact(part, slot.el, true);
            }
        }
    });

    // text that was held back (half a tag) can make the list shorter again
    segment.slots.splice(parts.length).forEach(s => s.el.remove());
}

// "copy answer" gets the artifacts as normal code blocks
function plainAnswer(raw){
    raw = raw.replace(/<plan\b([^>]*)>([\s\S]*?)(<\/plan>|$)/g, function(_, attrs, content) {
        let title = artifactAttrs(attrs).title;
        return "**Plan" + (title ? ": " + title : "") + "**\n" + content.trim();
    });
    return raw.replace(/<artifact\b([^>]*)>([\s\S]*?)(<\/artifact>|$)/g, function(_, attrs, content) {
        let parsed = artifactAttrs(attrs);
        let type = artifactType(parsed, content);
        let language = type === "code" ? (parsed.language || "") : (type === "markdown" ? "markdown" : type);
        return "```" + language + "\n" + artifactContent(content, true) + "\n```";
    });
}

// ---------- the artifact panel ----------

const artifactPanel = {
    root: document.getElementById("aiArtifactPanel"),
    frame: document.getElementById("aiArtifactFrame"),
    doc: document.getElementById("aiArtifactDoc"),
    code: document.getElementById("aiArtifactCode"),
    card: null,
    part: null,
    view: null,        // "preview" | "code"
    viewChosen: false, // they picked a tab themselves
    previewed: null,   // content the preview was last built from
    pinnedByUser: false,
    highlightTimer: null
};

function canPreview(part){
    return part.type !== "code";
}

// other cards in this chat with the same id are the other versions
function artifactVersions(part){
    return Array.from(log.querySelectorAll(".aiArtifact")).filter(c => c.artifact && c.artifact.ref === part.ref && !c.artifact.failed && !part.transient);
}

function openArtifact(part, card, automatic){
    if(!automatic){
        artifactPanel.pinnedByUser = false;
    }
    artifactPanel.viewChosen = false;
    artifactPanel.previewed = null;
    artifactPanel.view = null;
    showArtifact(part, card || null, false);
    artifactPanel.root.hidden = false;
    document.querySelector(".aiPage").classList.add("artifactOpen");
    if(!automatic){
        document.getElementById("aiArtifactClose").focus({ preventScroll: true });
    }
}

function closeArtifact(){
    artifactPanel.root.hidden = true;
    artifactPanel.card = null;
    artifactPanel.part = null;
    artifactPanel.frame.srcdoc = "";
    artifactPanel.pinnedByUser = true; // don't pop it open again on this page unless they ask
    document.querySelector(".aiPage").classList.remove("artifactOpen");
}

function setArtifactView(view){
    artifactPanel.view = view;
    document.querySelectorAll("#aiArtifactTabs [data-view]").forEach(function(tab) {
        tab.setAttribute("aria-selected", tab.dataset.view === view ? "true" : "false");
    });
    renderArtifactBody();
}

// update: true when the same artifact got more text (streaming), so keep the scroll and tab
function showArtifact(part, card, update){
    artifactPanel.part = part;
    artifactPanel.card = card;

    document.getElementById("aiArtifactTitle").textContent = part.title;
    document.getElementById("aiArtifactMeta").textContent = artifactMeta(part);

    let previewable = canPreview(part);
    document.getElementById("aiArtifactTabs").hidden = !previewable;

    // still writing: watch the code come in, then flip to the preview once it's done
    let view = artifactPanel.view;
    if(!artifactPanel.viewChosen || !view){
        view = previewable && !part.writing ? "preview" : "code";
    }
    if(!previewable){
        view = "code";
    }

    let open = document.getElementById("aiArtifactNewTab");
    let saved = !!(part.version && ai.chatId && !part.transient);
    open.hidden = !saved || !previewable;
    if(saved){
        open.href = "/ai/artifacts/" + ai.chatId + "/" + part.ref + "/" + part.version;
    }

    let select = document.getElementById("aiArtifactVersion");
    let versions = card ? artifactVersions(part) : [];
    select.replaceChildren();
    select.hidden = versions.length < 2;
    versions.forEach(function(other, i) {
        let option = el("option", "", other.artifact.version ? "v" + other.artifact.version : "Draft " + (i + 1));
        option.value = i;
        option.selected = other === card;
        select.append(option);
    });
    select.versions = versions;

    if(view !== artifactPanel.view){
        setArtifactView(view);
    } else {
        renderArtifactBody(update);
    }
}

function renderArtifactBody(update){
    let part = artifactPanel.part;
    if(!part){
        return;
    }

    let preview = artifactPanel.view === "preview";
    artifactPanel.frame.hidden = !preview || part.type === "markdown";
    artifactPanel.doc.hidden = !preview || part.type !== "markdown";
    artifactPanel.code.hidden = preview;

    if(preview){
        // rebuilding the iframe restarts whatever's running in it, so only when the content changed
        if(artifactPanel.previewed === part.content){
            return;
        }
        artifactPanel.previewed = part.content;

        if(part.type === "markdown"){
            renderMarkdown(artifactPanel.doc, part.content);
            decorateCode(artifactPanel.doc, false);
            artifactPanel.frame.srcdoc = "";
        } else if(part.type === "svg"){
            artifactPanel.frame.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>html,body{margin:0;height:100%;background:#fff}body{display:grid;place-items:center}svg{max-width:100%;max-height:100vh;height:auto}</style></head><body>' + part.content + "</body></html>";
        } else {
            artifactPanel.frame.srcdoc = part.content;
        }
        return;
    }

    // code view: plain text right away, colours a moment later (highlighting every frame is slow for big files)
    let code = artifactPanel.code.querySelector("code");
    let box = artifactPanel.code;
    let stick = box.scrollHeight - box.scrollTop - box.clientHeight < 60;
    code.className = "";
    code.textContent = part.content;
    if(update && stick){
        box.scrollTop = box.scrollHeight;
    } else if(!update){
        box.scrollTop = 0;
    }

    clearTimeout(artifactPanel.highlightTimer);
    artifactPanel.highlightTimer = setTimeout(function() {
        if(!window.hljs || artifactPanel.part !== part || artifactPanel.view !== "code"){
            return;
        }
        let language = part.type === "code" ? part.language : (part.type === "svg" ? "xml" : part.type);
        if(language && hljs.getLanguage(language)){
            code.className = "language-" + language;
            hljs.highlightElement(code);
        }
    }, part.writing ? 400 : 0);
}

document.querySelectorAll("#aiArtifactTabs [data-view]").forEach(function(tab) {
    tab.addEventListener("click", function() {
        artifactPanel.viewChosen = true;
        setArtifactView(tab.dataset.view);
    });
});

document.getElementById("aiArtifactClose").addEventListener("click", closeArtifact);

artifactPanel.root.addEventListener("keydown", function(event) {
    if(event.key === "Escape"){
        closeArtifact();
    }
});

document.getElementById("aiArtifactVersion").addEventListener("change", function() {
    let card = this.versions && this.versions[this.value];
    if(card){
        artifactPanel.previewed = null;
        showArtifact(card.artifact, card, false);
    }
});

document.getElementById("aiArtifactCopy").addEventListener("click", function() {
    let button = this;
    if(!artifactPanel.part){
        return;
    }
    navigator.clipboard.writeText(artifactPanel.part.content).then(function() {
        button.innerHTML = '<i class="ph-bold ph-check"></i>';
        setTimeout(function() { button.innerHTML = '<i class="ph-bold ph-copy"></i>'; }, 1500);
    });
});

// a blob download never runs the file, it only saves it
document.getElementById("aiArtifactDownload").addEventListener("click", function() {
    let part = artifactPanel.part;
    if(!part){
        return;
    }
    let extensions = { html: "html", svg: "svg", markdown: "md" };
    let codeExtensions = { python: "py", javascript: "js", typescript: "ts", css: "css", json: "json", java: "java", c: "c", cpp: "cpp", csharp: "cs", go: "go", rust: "rs", ruby: "rb", bash: "sh", shell: "sh", sql: "sql", lua: "lua", php: "php" };
    let ext = part.type === "code" ? (codeExtensions[part.language] || "txt") : extensions[part.type];
    let mime = { html: "text/html", svg: "image/svg+xml" }[part.type] || "text/plain";
    let url = URL.createObjectURL(new Blob([part.content], { type: mime + ";charset=utf-8" }));
    let link = el("a");
    link.href = url;
    link.download = artifactSlug(part.ref || part.title) + "." + ext;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
});

// the server saved one: give the newest card with that id and no version its number
function artifactSaved(event){
    let cards = Array.from(log.querySelectorAll(".aiArtifact")).filter(c => c.artifact && c.artifact.ref === event.ref && !c.artifact.version && !c.artifact.failed);
    let card = cards[0];
    if(!card){
        return;
    }
    card.artifact.version = event.version;
    if(event.content !== undefined){
        // an edit: the server's result is what was saved
        card.finalContent = event.content;
        card.artifact.content = event.content;
        card.artifact.previewMissed = false;
        artifactPanel.previewed = null;
    }
    card.querySelector(".aiArtifactMeta").textContent = artifactMeta(card.artifact);
    if(artifactPanel.card === card){
        showArtifact(card.artifact, card, true);
    }
}

// an edit that didn't line up: nothing was changed, and the model's been asked to try again
function artifactFailed(event){
    let card = Array.from(log.querySelectorAll(".aiArtifact.edit")).filter(c => c.artifact && c.artifact.ref === event.ref && !c.artifact.version && !c.artifact.failed)[0];
    if(!card){
        return;
    }
    card.editFailed = true;
    card.artifact.failed = true;
    card.title = "Didn't apply: " + event.message;
    artifactCard(card.artifact, card);
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

// ---------- bloop ----------

const aiName = log.dataset.name || "Bloop";
const headerBloop = Bloop.mount(document.getElementById("aiHeaderBloop"), { crop: "head", cs: 1, hop: true });
const introBloop = Bloop.mount(document.getElementById("aiIntroBloop"), { crop: "stage", cs: 2, hop: true });
if(!ai.chatId){
    introBloop.set("hop", "idle");
}

// the header one keeps an eye on you: reads along while you type, dozes off when nothing's happening
let napTimer = null, watchTimer = null;
function headerMood(anim, then){
    clearTimeout(napTimer);
    headerBloop.set(anim, then);
    if(!ai.streaming){
        napTimer = setTimeout(() => headerBloop.set("sleep"), 120000);
    }
}
headerMood("idle");

input.addEventListener("input", function() {
    if(ai.streaming){
        return;
    }
    clearTimeout(watchTimer);
    if(headerBloop.anim === "sleep"){
        headerMood("hop", "watch");
    } else {
        headerMood("watch");
    }
    watchTimer = setTimeout(() => headerMood("idle"), 1500);
});

// stands in for a loading indicator while an answer's on its way: him thinking, typing, using tools or writing
function bloopStatus(){
    let wrap = el("div", "aiBloopStatus");
    wrap.setAttribute("aria-hidden", "true");
    let canvas = el("canvas");
    let label = el("span", "aiBloopLabel");
    wrap.append(canvas, label);
    return { el: wrap, label: label, bloop: Bloop.mount(canvas, { crop: "stage", cs: 2, hop: true }) };
}

// what he's up to in a live answer. anim null hides the status (he's talking in the avatar instead)
function bloopDoing(turn, anim, text){
    let status = turn.status;
    if(anim){
        status.bloop.set(anim);
        status.label.textContent = text;
        // anything new in the turn goes in above him
        if(status.el.nextSibling || !status.el.isConnected){
            turn.body.append(status.el);
        }
        turn.avatar.set("ponder");
        headerBloop.set("ponder");
    } else {
        status.el.remove();
        turn.avatar.set("talk");
        headerBloop.set("talk");
    }
}

// an assistant turn can span several saved messages (text, tool calls, more text)
function newTurn(live){
    let row = el("div", "aiMsg assistant");
    let avatar = el("canvas", "aiTurnBloop");
    avatar.setAttribute("aria-hidden", "true");
    let body = el("div", "aiTurn");
    row.append(avatar, body);

    return {
        row: row,
        body: body,
        avatar: Bloop.mount(avatar, { crop: "head", cs: 1, hop: true, still: !live }),
        status: live ? bloopStatus() : null,
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
        renderSegment(segment, false);
        return;
    }

    // re-render at most once a frame while streaming, less often once it's long (it's re-parsed every time)
    if(!segment.queued){
        segment.queued = true;
        let wait = segment.raw.length > 6000 ? 120 : 0;
        setTimeout(function() {
            requestAnimationFrame(function() {
                segment.queued = false;

                // closeText already did the final render
                if(segment.closed){
                    return;
                }

                let stick = nearBottom();
                renderSegment(segment, true);
                if(stick){
                    scrollDown(true);
                }
            });
        }, wait);
    }
}

// draws text that's still waiting for its frame, so the cards an event talks about exist
function flushText(turn){
    if(turn.text && turn.text.queued){
        renderSegment(turn.text, true);
    }
}

function closeText(turn){
    if(turn.text){
        let segment = turn.text;
        segment.closed = true;
        renderSegment(segment, false);
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
    let icon = el("i", live ? "ph-bold ph-circle-notch spin" : "ph-bold ph-wrench");
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
    chip.icon.className = isError ? "ph-bold ph-warning" : "ph-bold ph-check";
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
    copy.innerHTML = '<i class="ph-bold ph-copy"></i>';
    copy.addEventListener("click", function() {
        let text = turn.allText.map(s => plainAnswer(s.raw)).join("\n\n");
        navigator.clipboard.writeText(text).then(function() {
            copy.innerHTML = '<i class="ph-bold ph-check"></i>';
            setTimeout(function() { copy.innerHTML = '<i class="ph-bold ph-copy"></i>'; }, 1500);
        });
    });
    bar.append(copy);

    if(isLast){
        let again = el("button", "aiIconButton small");
        again.type = "button";
        again.title = "Regenerate";
        again.setAttribute("aria-label", "Regenerate answer");
        again.innerHTML = '<i class="ph-bold ph-arrow-clockwise"></i>';
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
        tickPlans(t.body);
        turnActions(t, lastIsAnswer && i === turns.length - 1);
    });
}

// ---------- sending ----------

function setStreaming(on){
    ai.streaming = on;
    sendButton.classList.toggle("stop", on);
    sendButton.querySelector("i").className = on ? "ph-bold ph-stop" : "ph-bold ph-arrow-up";
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

    let turn = newTurn(true);
    log.append(turn.row);
    bloopDoing(turn, "ponder", aiName + " is thinking...");
    clearTimeout(watchTimer);
    clearTimeout(napTimer);
    scrollDown(true);

    setStreaming(true);
    ai.controller = new AbortController();

    let gotAnything = false;
    let unsent = false;
    let newChat = null;

    function handle(event){
        if(!gotAnything && event.type !== "chat"){
            gotAnything = true;
        }

        switch(event.type){
            case "chat":
                newChat = event.id;
                ai.chatId = event.id;
                history.replaceState(null, "", "/ai/" + event.id);
                setTitle(event.title);
                addChatToList(event.id, event.title);
                document.getElementById("aiHeaderActions").hidden = false;
                break;
            case "text":
                endThinking(turn);
                addText(turn, event.text, true);
                let writing = turn.body.querySelector(".aiPlan.writing, .aiArtifact.writing");
                let made = writing && writing.artifact;
                if(writing && !made){
                    bloopDoing(turn, "write", aiName + " is planning it out...");
                } else if(made){
                    bloopDoing(turn, made.edit ? "think2" : "think", (made.edit ? "Editing " : "Building ") + made.title + "...");
                } else {
                    bloopDoing(turn, null);
                }
                break;
            case "thinking_start":
                startThinking(turn, true);
                bloopDoing(turn, "think", aiName + " is thinking...");
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
                let doing = (toolLabels[event.name] || ["Using " + event.name])[0];
                bloopDoing(turn, /theme/.test(event.name) ? "write" : "think2", aiName + " is " + doing[0].toLowerCase() + doing.slice(1) + "...");
                break;
            case "tool_result":
                toolResult(turn, event.id, event.content, event.is_error);
                bloopDoing(turn, "thinking", aiName + " is thinking...");
                break;
            case "tool_input":
                toolInput(turn, event.id, event.input);
                break;
            case "notice":
                addNotice(turn, event.text);
                break;
            case "artifact":
                flushText(turn);
                artifactSaved(event);
                break;
            case "artifact_failed":
                flushText(turn);
                artifactFailed(event);
                bloopDoing(turn, "ponder", aiName + " is fixing that edit...");
                break;
            case "theme":
                applyTheme(event);
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
        turn.status.el.remove();
        let failed = !!turn.body.querySelector(".aiNotice.error, .aiNotice.refused");
        tickPlans(turn.body);
        endThinking(turn);
        closeText(turn);

        // tools that never got a result (stopped mid way)
        turn.body.querySelectorAll(".aiTool.running").forEach(function(chip) {
            chip.classList.remove("running");
            chip.querySelector("i").className = "ph-bold ph-x";
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

        // the first answer is in, so the small model can give the chat a proper name
        if(newChat && !failed){
            nameChat(newChat);
        }

        // a little celebration when it went well, a wobble when it didn't, then he settles down
        let mood = failed ? "jiggle" : turn.body.querySelector(".aiNotice.stopped") ? "idle" : "happy";
        turn.avatar.set(mood, "idle");
        headerMood(mood, "idle");
        setTimeout(function() { turn.avatar.still = true; }, 2500);
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

const suggestions = document.getElementById("aiSuggestions");

suggestions.addEventListener("click", function(event) {
    let button = event.target.closest("button[data-prompt]");
    if(!button || ai.streaming){
        return;
    }
    input.value = button.dataset.prompt;
    autoSize();
    updateSendState();
    send();
});

// the small model writes new starter prompts every few minutes, picked up while the empty chat is showing
function refreshSuggestions(){
    if(document.getElementById("aiIntro").hidden){
        return;
    }

    $.getJSON("/api/v1/ai/suggestions").done(function(data) {
        if(!data.prompts || !data.prompts.length || document.getElementById("aiIntro").hidden){
            return;
        }

        suggestions.replaceChildren(...data.prompts.map(function(prompt) {
            let button = el("button", null, prompt);
            button.type = "button";
            button.dataset.prompt = prompt;
            return button;
        }));
    });
}

const suggestionTimer = setInterval(refreshSuggestions, 5 * 60 * 1000);

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
    remove.innerHTML = '<i class="ph-bold ph-x"></i>';
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

function nameChat(id){
    $.post("/api/v1/ai/chats/" + id + "/name").done(function(data) {
        if(!data.title){
            return;
        }
        let link = document.querySelector("#aiChatList a[data-id='" + id + "']");
        if(link){
            link.textContent = data.title;
        }
        // only if they're still looking at it and haven't started renaming it themselves
        if(ai.chatId === id && document.getElementById("aiTitleInput").hidden){
            setTitle(data.title);
        }
    });
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

// leaving the page mid answer: stop reading the stream (the server still saves what it has)
document.addEventListener("watr:leave", function() {
    clearInterval(suggestionTimer);
    if(ai.controller){
        ai.controller.abort();
    }
}, { once: true });

// deferred scripts run in order, so marked / dompurify / hljs are loaded by now
if(aiData.messages.length){
    renderSaved(aiData.messages);
    scrollDown(true);
}

updateHint();
updateSendState();
refreshSuggestions();
if(!touchInput){
    input.focus();
}

})();
