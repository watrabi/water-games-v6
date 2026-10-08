// runs again on every visit (pages load in place), so everything stays inside this function
(function(){

// whatever the last game page left running (timers, listeners) stops before this one starts
if(window.watrPlayCleanup){
    window.watrPlayCleanup();
}

let cleanups = [];
function onCleanup(fn){
    cleanups.push(fn);
}

let favoriteButton = $("#favoriteButton");
let playError = $("#playError");
let playMsg = $("#playMsg");
let data = {};
try {
    data = JSON.parse(document.getElementById("playData").textContent);
} catch (e) {}

const gameId = data.game;
const frame = document.getElementById("gameFrame");

function api(path){
    return "/api/v1/play/" + gameId + path;
}

// ---------- fullscreen + theater ----------

function fullscreen(){
    let player = document.getElementById("player");

    if(document.fullscreenElement){
        document.exitFullscreen();
    } else if(player.requestFullscreen){
        player.requestFullscreen();
    } else if(player.webkitRequestFullscreen){
        player.webkitRequestFullscreen();
    }
}

function setTheater(on){
    document.body.classList.toggle("theater", on);
    $("#theaterButton").attr("aria-pressed", on).toggleClass("on", on);
    try { localStorage.setItem("watrTheater", on ? "1" : "0"); } catch (e) {}
}

$("#fullscreenButton").on("click", fullscreen);
$("#theaterButton").on("click", function() {
    setTheater(!document.body.classList.contains("theater"));
});

try {
    if(localStorage.getItem("watrTheater") === "1"){
        setTheater(true);
    }
} catch (e) {}

onCleanup(() => document.body.classList.remove("theater"));

// F and T, unless you're typing somewhere
function keys(event){
    if(event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || /^(INPUT|TEXTAREA|SELECT)$/.test(event.target.tagName) || event.target.isContentEditable){
        return;
    }
    if(event.key === "f" || event.key === "F"){
        event.preventDefault();
        fullscreen();
    } else if(event.key === "t" || event.key === "T"){
        event.preventDefault();
        setTheater(!document.body.classList.contains("theater"));
    }
}
document.addEventListener("keydown", keys);
onCleanup(() => document.removeEventListener("keydown", keys));

// ---------- favorite ----------

function setFavorited(favorited){
    favoriteButton.toggleClass("on", favorited).attr("aria-pressed", favorited);
    favoriteButton.find("i").attr("class", (favorited ? "ph-fill" : "ph-bold") + " ph-heart");
    favoriteButton.find("span").text(favorited ? "Favorited" : "Favorite");
}

favoriteButton.on("click", function() {
    favoriteButton.prop("disabled", true);
    playError.prop("hidden", true);

    $.post("/api/v1/games/favorite", { id: favoriteButton.data("id") }).done(function(result) {
        setFavorited(result.favorited);
        $("#favoriteCount").text(Number(result.favorites).toLocaleString());
        $("#favoriteLabel").text(result.favorites == 1 ? "favorite" : "favorites");
    }).fail(function(xhr) {
        showNotice(playError, apiMessage(xhr));
    }).always(function() {
        favoriteButton.prop("disabled", false);
    });
});

// ---------- thumbs up / down ----------

function compact(n){
    n = Number(n) || 0;
    if(n < 1000){
        return String(n);
    }
    return (n >= 1e6 ? Math.floor(n / 1e5) / 10 + "m" : Math.floor(n / 100) / 10 + "k");
}

function showVote(vote){
    [["#voteUp", 1, "ph-thumbs-up"], ["#voteDown", -1, "ph-thumbs-down"]].forEach(function([id, value, icon]) {
        let on = vote === value;
        $(id).toggleClass("on", on).attr("aria-pressed", on).find("i").attr("class", (on ? "ph-fill " : "ph-bold ") + icon);
    });
}

$("#voteUp, #voteDown").on("click", function() {
    let button = $(this);
    let wanted = Number(button.data("vote"));
    // pressing the one that's already on takes your vote back
    let vote = button.hasClass("on") ? 0 : wanted;

    $("#voteUp, #voteDown").prop("disabled", true);
    $.post(api("/vote"), { vote: vote }).done(function(result) {
        showVote(result.vote);
        $("#likeCount").text(compact(result.likes));
        $("#ratingText").text(result.rating === null ? "" : (result.rating + "% liked"));
    }).fail(function(xhr) {
        showNotice(playError, apiMessage(xhr));
    }).always(function() {
        $("#voteUp, #voteDown").prop("disabled", false);
    });
});

// ---------- report a broken game ----------

let reportDialog = document.getElementById("reportGameDialog");

$("#reportGameButton").on("click", function() {
    $("#reportGameError").prop("hidden", true);
    reportDialog.showModal();
});

$("#reportGameCancel").on("click", function() {
    reportDialog.close();
});

$("#reportGameForm").on("submit", function(event) {
    event.preventDefault();
    let reason = $(this).find("input[name=reason]:checked").val();
    if(!reason){
        showNotice($("#reportGameError"), "Pick what's wrong.");
        return;
    }

    $("#reportGameSend").prop("disabled", true);
    $.post(api("/report"), { reason: reason, details: $("#reportGameDetails").val() }).done(function(result) {
        reportDialog.close();
        showNotice(playMsg, result.message, "success");
        $("#reportGameForm")[0].reset();
    }).fail(function(xhr) {
        showNotice($("#reportGameError"), apiMessage(xhr));
    }).always(function() {
        $("#reportGameSend").prop("disabled", false);
    });
});

onCleanup(function() {
    if(reportDialog && reportDialog.open){
        reportDialog.close();
    }
});

// ---------- playtime ----------
// a second only counts if this tab is the visible one AND the window has focus. clicking into the game
// moves focus into the iframe, which fires "blur" on this window even though you're still playing, so this
// checks document.hasFocus() (true while the focus is anywhere inside this page, iframe included) every
// second instead of trusting focus/blur events

let tracker = null;

function formatPlaytime(seconds){
    if(seconds < 60){
        return seconds + "s";
    }
    let hours = Math.floor(seconds / 3600);
    let minutes = Math.floor((seconds % 3600) / 60);
    if(!hours){
        return minutes + "m";
    }
    return minutes ? hours + "h " + minutes + "m" : hours + "h";
}

function startTracker(){
    tracker = {
        total: data.seconds || 0, // what the server has
        pending: 0,               // focused seconds not sent yet
        sending: false,
        sinceBeat: 0,
        active: false
    };

    let text = $("#playtimeText");
    let icon = '<i class="ph-bold ph-clock"></i>';

    function render(){
        let shown = tracker.total + tracker.pending;
        text.html(icon).append(document.createTextNode(shown ? formatPlaytime(shown) + " played" : "Not played yet"));
        text.toggleClass("counting", tracker.active);
    }

    function focused(){
        return document.visibilityState === "visible" && document.hasFocus();
    }

    function beat(){
        if(tracker.sending || tracker.pending <= 0){
            return;
        }

        let seconds = tracker.pending;
        tracker.sending = true;
        tracker.sinceBeat = 0;

        $.post(api("/beat"), { seconds: seconds }).done(function(result) {
            tracker.pending -= seconds;
            tracker.total = result.seconds;
        }).fail(function(xhr) {
            // signed out in another tab, or the game was removed: stop counting
            if(xhr.status === 401 || xhr.status === 404){
                stopTracker(false);
            }
        }).always(function() {
            if(tracker){
                tracker.sending = false;
                render();
            }
        });
    }

    tracker.tick = setInterval(function() {
        let was = tracker.active;
        tracker.active = focused();
        tracker.sinceBeat++;

        if(tracker.active){
            tracker.pending++;
        } else if(was){
            beat(); // just lost focus, send what we have
        }

        if(tracker.sinceBeat >= (data.beatEvery || 15)){
            beat();
        }

        render();
    }, 1000);

    tracker.flush = function() {
        if(document.visibilityState === "hidden"){
            beat();
        }
    };
    document.addEventListener("visibilitychange", tracker.flush);

    render();
}

// leaving the page: whatever's left goes in a beacon, which survives the page going away
function stopTracker(send){
    if(!tracker){
        return;
    }

    clearInterval(tracker.tick);
    document.removeEventListener("visibilitychange", tracker.flush);

    if(send !== false){
        let form = new FormData();
        form.append("seconds", Math.max(0, tracker.pending));
        form.append("stop", "1");
        navigator.sendBeacon(api("/beat"), form);
    }

    tracker = null;
}

if(data.signedIn && gameId){
    startTracker();
    onCleanup(() => stopTracker());
}

// ---------- cloud saves ----------
// games in /game-files share this site's localStorage. when the game writes to it, this page gets a
// "storage" event (they fire in every other window on the same origin, and the iframe is another window),
// so that's how we learn which keys are the game's. those keys get copied up to the account, and copied
// back down before the game loads on another device

let cloud = null;

function siteKey(key){
    return !key || (data.siteKeys || []).indexOf(key) !== -1 || key.indexOf("wg_") === 0 || key.indexOf("watr") === 0;
}

function readLocal(key){
    try { return localStorage.getItem(key); } catch (e) { return null; }
}

function writeLocal(key, value){
    try { localStorage.setItem(key, value); return true; } catch (e) { return false; }
}

function cloudStatus(text, state){
    let box = $("#cloudStatus").prop("hidden", false).attr("data-state", state || "");
    box.find("span").text(text);
}

function startCloud(){
    const markerKey = "watrCloud:" + gameId; // when this device last synced, so newer local progress isn't overwritten

    cloud = {
        keys: new Set(),
        dirty: false,
        timer: null,
        lastUpload: 0,
        uploading: false
    };

    function snapshot(){
        let out = {};
        cloud.keys.forEach(function(key) {
            let value = readLocal(key);
            if(value !== null){
                out[key] = value;
            }
        });
        return out;
    }

    function upload(){
        if(!cloud || cloud.uploading || !cloud.dirty){
            return;
        }

        let payload = snapshot();
        if(!Object.keys(payload).length){
            return;
        }

        cloud.uploading = true;
        cloud.dirty = false;
        cloudStatus("Saving…", "saving");

        $.ajax({
            url: api("/save"),
            method: "POST",
            contentType: "application/json",
            data: JSON.stringify({ data: payload })
        }).done(function(result) {
            writeLocal(markerKey, String(result.updated));
            cloud && (cloud.lastUpload = Date.now());
            cloudStatus("Saved to your account", "saved");
        }).fail(function(xhr) {
            if(cloud){
                cloud.dirty = xhr.status !== 413; // too big won't get better by retrying
            }
            cloudStatus(xhr.status === 413 ? "Too big to save to your account" : "Couldn't save, will try again", "error");
        }).always(function() {
            if(cloud){
                cloud.uploading = false;
            }
        });
    }

    // a few seconds after the game stops writing, and at least every 15 seconds while it keeps going
    function schedule(){
        cloud.dirty = true;
        clearTimeout(cloud.timer);
        let wait = Date.now() - cloud.lastUpload > 15000 ? 3000 : 6000;
        cloud.timer = setTimeout(upload, wait);
    }

    cloud.onStorage = function(event) {
        if(event.storageArea !== localStorage || siteKey(event.key)){
            return;
        }
        cloud.keys.add(event.key);
        schedule();
    };
    window.addEventListener("storage", cloud.onStorage);

    cloud.finish = function() {
        clearTimeout(cloud.timer);
        window.removeEventListener("storage", cloud.onStorage);

        // small saves can go in a beacon as the page closes. bigger ones were uploaded along the way
        if(cloud.dirty){
            let body = JSON.stringify({ data: snapshot() });
            if(body.length < 60000){
                navigator.sendBeacon(api("/save"), new Blob([body], { type: "application/json" }));
            }
        }
        cloud = null;
    };

    function loadGame(){
        if(frame && frame.dataset.src && !frame.getAttribute("src")){
            frame.setAttribute("src", frame.dataset.src);
        }
    }

    // the game waits for this, but never more than a few seconds
    let fallback = setTimeout(loadGame, 4000);

    $.getJSON(api("/save")).done(function(result) {
        let save = result.save;
        if(!save || !save.data){
            cloudStatus("Progress saves to your account", "idle");
            return;
        }

        Object.keys(save.data).forEach(key => cloud && cloud.keys.add(key));

        let lastSync = Number(readLocal(markerKey) || 0);
        if(save.updated > lastSync){
            Object.keys(save.data).forEach(key => writeLocal(key, save.data[key]));
            writeLocal(markerKey, String(save.updated));
            cloudStatus("Loaded your save", "saved");
        } else {
            cloudStatus("Progress saves to your account", "idle");
        }
    }).fail(function() {
        cloudStatus("Cloud save is unavailable right now", "error");
    }).always(function() {
        clearTimeout(fallback);
        loadGame();
    });
}

if(data.cloud && gameId){
    startCloud();
    onCleanup(() => cloud && cloud.finish());
} else if(frame && frame.dataset.src && !frame.getAttribute("src")){
    frame.setAttribute("src", frame.dataset.src);
}

// ---------- leaderboard ----------
// games send scores with watr.submitScore() from /assets/js/watr-sdk.js, which posts a message to this page.
// only messages from this game's own frame count, and the answer goes back to it so it can say "new best!"

let period = "all";
let myBest = null;

function escapeText(text){
    let node = document.createElement("span");
    node.textContent = text;
    return node.innerHTML;
}

function loadScores(){
    let list = $("#scoreList");
    if(!list.length){
        return;
    }

    $.getJSON(api("/scores"), { period: period }).done(function(result) {
        list.empty();
        if(!result.rows.length){
            list.append($("<li class='muted scoreEmpty'>").text(period === "friends" ? "None of your friends have a score yet." : "No scores yet. Be the first!"));
        }
        result.rows.forEach(function(row) {
            let user = escapeText(row.username);
            let item = $("<li>").toggleClass("mine", row.me).html(
                '<span class="scoreRank">' + row.rank + '</span>' +
                '<a class="scoreName" href="/users/' + encodeURIComponent(row.username.toLowerCase()) + '">' + user + '</a>' +
                '<span class="levelBadge" title="Level ' + row.level + '">' + row.level + '</span>' +
                '<span class="scoreValue">' + escapeText(row.text) + '</span>'
            );
            list.append(item);
        });

        let mine = $("#scoreMine");
        if(result.me){
            myBest = result.me.text;
            mine.text("Your best" + (period === "week" ? " this week" : "") + ": " + result.me.text + " (#" + result.me.rank + ")");
        } else {
            mine.text(data.signedIn && period !== "friends" ? "You don't have a score " + (period === "week" ? "this week" : "yet") + "." : "");
        }
        if(period === "all"){
            $("#challengeButton").prop("hidden", !result.me);
        }
    }).fail(function() {
        list.html("<li class='muted'>Couldn't load the leaderboard.</li>");
    });
}

if(data.scores){
    loadScores();

    $("#scores .sortTabs a").on("click", function(event) {
        event.preventDefault();
        period = $(this).data("period");
        $("#scores .sortTabs a").removeClass("active").removeAttr("aria-current");
        $(this).addClass("active").attr("aria-current", "true");
        loadScores();
    });
}

function onGameMessage(event){
    let message = event.data;
    if(!frame || event.source !== frame.contentWindow || !message || message.source !== "watr-sdk" || message.type !== "score"){
        return;
    }

    function answer(result){
        try {
            event.source.postMessage({ source: "watr", id: message.id, result: result }, "*");
        } catch (e) {}
    }

    if(!data.scores){
        return answer({ ok: false, message: "This game doesn't have a leaderboard." });
    }
    if(!data.signedIn){
        showNotice(playMsg, "Sign in to put your scores on the leaderboard.", "success");
        return answer({ ok: false, message: "Not signed in." });
    }

    $.post(api("/score"), { score: message.score }).done(function(result) {
        if(result.personalBest){
            showNotice(playMsg, "New best: " + result.bestText + " (#" + result.rank + " all time)", "success");
            loadScores();
        }
        answer({ ok: true, personalBest: result.personalBest, best: result.best, rank: result.rank, text: result.text });
    }).fail(function(xhr) {
        answer({ ok: false, message: apiMessage(xhr) });
    });
}
window.addEventListener("message", onGameMessage);
onCleanup(() => window.removeEventListener("message", onGameMessage));

// ---------- challenge a friend ----------

let challengeDialog = document.getElementById("challengeDialog");

$("#challengeButton").on("click", function() {
    $("#challengeError").prop("hidden", true);
    $("#challengeScore").text(myBest || "");
    let select = $("#challengeFriend").html("<option value=''>Loading…</option>");
    challengeDialog.showModal();

    $.getJSON("/api/v1/social/state").done(function(state) {
        select.empty();
        if(!state.friends.length){
            select.append("<option value=''>Add some friends first (chat tray, bottom left)</option>");
            return;
        }
        select.append("<option value=''>Pick a friend</option>");
        state.friends.forEach(function(friend) {
            select.append($("<option>").val(friend.id).text(friend.username));
        });
    });
});

$("#challengeCancel").on("click", () => challengeDialog.close());

$("#challengeForm").on("submit", function(event) {
    event.preventDefault();
    let friend = $("#challengeFriend").val();
    if(!friend){
        showNotice($("#challengeError"), "Pick who to challenge.");
        return;
    }

    $("#challengeSend").prop("disabled", true);
    $.post(api("/challenge"), { friend: friend }).done(function(result) {
        challengeDialog.close();
        showNotice(playMsg, result.message, "success");
    }).fail(function(xhr) {
        showNotice($("#challengeError"), apiMessage(xhr));
    }).always(function() {
        $("#challengeSend").prop("disabled", false);
    });
});

onCleanup(function() {
    if(challengeDialog && challengeDialog.open){
        challengeDialog.close();
    }
});

// ---------- add to a collection ----------

let collectDialog = document.getElementById("collectDialog");

function renderCollections(collections){
    let list = $("#collectList").empty();
    if(!collections.length){
        list.append("<li class='muted'>You don't have any collections yet.</li>");
    }
    collections.forEach(function(collection) {
        let box = $("<input type='checkbox'>").prop("checked", collection.has).on("change", function() {
            let input = $(this).prop("disabled", true);
            $.post("/api/v1/collections/" + collection.id + "/" + (input.prop("checked") ? "add" : "remove"), { game: gameId }).fail(function(xhr) {
                input.prop("checked", !input.prop("checked"));
                showNotice($("#collectError"), apiMessage(xhr));
            }).always(() => input.prop("disabled", false));
        });
        let label = $("<label class='checkRow'>").append(box, $("<span>").text(collection.name), $("<span class='muted'>").text(collection.public ? "" : " (private)"));
        list.append($("<li>").append(label));
    });
}

function loadCollections(){
    return $.getJSON("/api/v1/collections", { game: gameId }).done(result => renderCollections(result.collections));
}

$("#collectButton").on("click", function() {
    $("#collectError").prop("hidden", true);
    collectDialog.showModal();
    loadCollections();
});

$("#collectDone").on("click", () => collectDialog.close());

$("#collectCreate").on("click", function() {
    let name = $("#collectNew").val().trim();
    if(!name){
        $("#collectNew").trigger("focus");
        return;
    }
    $.post("/api/v1/collections", { name: name, game: gameId }).done(function() {
        $("#collectNew").val("");
        loadCollections();
    }).fail(function(xhr) {
        showNotice($("#collectError"), apiMessage(xhr));
    });
});

onCleanup(function() {
    if(collectDialog && collectDialog.open){
        collectDialog.close();
    }
});

// ---------- cleanup ----------

function cleanup(){
    cleanups.splice(0).reverse().forEach(function(fn) {
        try { fn(); } catch (e) {}
    });
    document.removeEventListener("watr:leave", cleanup);
    window.removeEventListener("pagehide", cleanup);
    if(window.watrPlayCleanup === cleanup){
        window.watrPlayCleanup = null;
    }
}

window.watrPlayCleanup = cleanup;
document.addEventListener("watr:leave", cleanup);
window.addEventListener("pagehide", cleanup);

})();
